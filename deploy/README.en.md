# Single-Machine Production Deployment (Docker Compose)

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

This directory contains a production stack for a single server:
`nginx + app + init + mysql + redis + backup`, without Elasticsearch.
All persistent data and backups are bind-mounted under `deploy/data`, so removing
containers does not lose data.

## Quick start

```bash
cd deploy
./generate-secrets.sh          # generate .env, env/*.env, RSA keys, self-signed cert, admin password and data dirs
docker compose up -d --build   # build the image and start everything
```

After startup:

- Admin UI / API: `https://<DOMAIN>/` (defaults to `https://localhost`; browsers warn about the self-signed cert)
- Static website: `https://<DOMAIN>/open-admin/`
- API docs UI: `https://<DOMAIN>/apidoc/`
- Plain HTTP redirects to HTTPS with 301
- Initial admin username is printed by the script (default `admin`); the password is in `deploy/secrets/admin_password.txt`

Change the admin password immediately after the first login.

Common commands (run inside `deploy/`):

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f backup
docker compose restart nginx
docker compose down
```

## Environment files

Secrets are split per service so the app container never sees the MySQL root password
or the initial admin password:

| File | Contents | Visible to | Mode |
|------|----------|------------|------|
| `deploy/.env` | domain, ports, host UID/GID (compose interpolation only) | compose | 600 |
| `deploy/env/app.env` | `APP_*`, `JWT_*`, `HASHIDS_*`, `SNOWFLAKE_*`, `ENCRYPTION_*` (incl. RSA private key), `ENCRYPTABLE_*`, `DB_*` (app user only), `REDIS_*`, `POSTER_*` | app | 600 |
| `deploy/env/init.env` | `DB_*` (app user only), `ADMIN_USERNAME`, `ADMIN_PASSWORD`, `SNOWFLAKE_*` | init | 600 |
| `deploy/env/mysql.env` | `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `DB_*`, `DB_BACKUP_USERNAME`, `DB_BACKUP_PASSWORD` | mysql | 600 |
| `deploy/env/redis.env` | `REDIS_PASSWORD` | redis | 600 |
| `deploy/env/backup.env` | `DB_BACKUP_USERNAME`, `DB_BACKUP_PASSWORD`, `MYSQL_DATABASE` (no root credentials) | backup | 600 |

The `deploy/env/` directory is mode 700. The script is idempotent and only fills in missing
values; **custom keys are preserved**, known keys are updated in place, and the file is never
rewritten from a fixed list.

> Upgrading from the old single-file `deploy/.env`: just run `./generate-secrets.sh`. The script
> first archives the old `.env` and `secrets/` to `deploy/secrets/archive/<timestamp>/`, then
> migrates legacy values into `deploy/env/*.env` and removes the migrated secrets from the master
> `.env`. If the old data directories belong to another user, the chown step fails with an error;
> use `sudo` or adjust `APP_UID`/`APP_GID` as described below.

## Files and directories

| Path | Contents | Mode |
|------|----------|------|
| `deploy/.env` | compose interpolation config (domain/ports/UID) | 600 |
| `deploy/env/*.env` | per-service environment (see table above) | 600 |
| `deploy/secrets/rsa_public_key.pem` | RSA public key (pairs with the private key in `app.env`) | 600 |
| `deploy/secrets/admin_password.txt` | Initial admin password | 600 |
| `deploy/secrets/tls/fullchain.pem`, `privkey.pem` | TLS certificate (self-signed by default) | 600 |
| `deploy/secrets/archive/<timestamp>/` | Old `.env`, `env/` and `secrets/` archived on rotation | 700 |
| `deploy/data/mysql` | MySQL data directory | 700 |
| `deploy/data/redis` | Redis AOF persistence | 700 |
| `deploy/data/runtime` | webman runtime, logs, `install.lock` | 700 |
| `deploy/data/upload` | Admin uploads | 700 |
| `deploy/data/backups` | Automatic backups (`backup_*.sql.gz`, `upload_*.tar.gz`, kept 30 days) | 700 |

> On first start the containers chown `data/mysql` and `data/redis` to their internal users
> (standard MySQL/Redis image behaviour). Your host user may no longer read those directories
> directly; this is expected.

`app`, `init` and `backup` run as `APP_UID:APP_GID` (read from the environment or an explicit
`deploy/.env` entry; the script defaults to the current user's `id -u` / `id -g`). The script
chowns `data/runtime`, `data/upload` and `data/backups` to that user so the bind mounts are
writable and backup files are not owned by root. A chown failure is a hard error: run the script
with privileges (e.g. `sudo`) or set `APP_UID` / `APP_GID` to the current user. A resolved value of
0 (root) without an explicit setting is rejected; explicitly setting 0 only prints a warning. To
change the user, edit `deploy/.env` and re-run the script (it chowns those three directories again).

MySQL, Redis and backup only run on the internal compose network; no host ports are published.
Only `HTTP_PORT` (default 80) and `HTTPS_PORT` (default 443) are exposed.

## Logs

MySQL, Nginx and the backup container write to stdout/stderr; Docker's `json-file` driver rotates
them at `10m × 3`. No log files are written to the host anymore:

```bash
docker compose logs -f mysql
docker compose logs -f nginx
docker compose logs -f backup
```

## Real domain and certificate

1. Set `DOMAIN` in `deploy/.env` to your domain (e.g. `admin.example.com`) and adjust
   `HTTP_PORT` / `HTTPS_PORT` if needed.
2. Replace these two files with your real certificate (keep the file names):

   ```bash
   deploy/secrets/tls/fullchain.pem   # certificate chain
   deploy/secrets/tls/privkey.pem     # private key
   ```

3. `docker compose restart nginx`.

The script only generates a self-signed certificate when the files are missing; it **never
overwrites** existing certificates, and `--regenerate` does not either — only an explicit
`--rotate-tls` replaces them (see below). Both `fullchain.pem` and `privkey.pem` must be present:
if only one exists the script exits with an error and asks you to restore the missing file or use
`--rotate-tls`; it never silently replaces the surviving certificate. Certificates are plain
files, independent of ACME; if you need HTTP-01 validation, put the challenge file in
`public/.well-known/acme-challenge/` (port 80 does not redirect that path).

HSTS (`Strict-Transport-Security`) is commented out by default in `nginx/default.conf`: enabling
it with a self-signed certificate makes browsers force HTTPS for a long time. Uncomment it after
the real domain certificate is configured, then restart nginx.

## Regenerating secrets

### Default behaviour

```bash
cd deploy
docker compose down
./generate-secrets.sh --regenerate
docker compose up -d --build
```

`--regenerate` rotates application secrets (JWT, transport `ENCRYPTION_KEY`, RSA, Redis password)
but **preserves** the Hashids salts, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `ADMIN_PASSWORD`,
`secrets/admin_password.txt`, the field-encryption key `ENCRYPTABLE_KEY` /
`ENCRYPTION_PREVIOUS_KEYS` and the existing TLS certificates, so an existing database, encrypted
fields, public IDs and real certificates are unaffected.

Impact:

- JWT rotation: all logged-in sessions are invalidated;
- the Hashids salts are preserved by default, so IDs in old links still resolve; use
  `--rotate-hashids` only when you really need to rotate them (see below);
- RSA rotation: the login page re-fetches the public key; older clients that rely on the embedded
  fallback key must be rebuilt;
- `ENCRYPTABLE_KEY` is not rotated by default; use `--rotate-encryptable` if you really need to
  (see below);
- TLS certificates are not rotated by default; use `--rotate-tls` to replace a self-signed
  certificate (see below).

The script first copies the current `deploy/.env`, `deploy/env/` and the whole `deploy/secrets/`
directory to `deploy/secrets/archive/<timestamp>/`, and only writes new values after the archive
succeeds. The archive contains the old RSA private key, old database passwords and old
certificate — treat it as key material.

### Rotating the Hashids salts (--rotate-hashids)

```bash
./generate-secrets.sh --rotate-hashids   # can be combined with other flags
```

Generates new `HASHIDS_SALT` / `HASHIDS_ALT_SALT`. Old links, bookmarks and Hashids IDs stored in
external systems no longer resolve afterwards (database primary keys are unchanged). Only run this
when no integration depends on the old IDs.

### Rotating database passwords (--rotate-db)

```bash
./generate-secrets.sh --rotate-db    # can be combined with other flags
```

Generates new `MYSQL_ROOT_PASSWORD` / `DB_PASSWORD` / `DB_BACKUP_PASSWORD` and writes them to
`deploy/env/mysql.env` and the other env files. An existing MySQL data directory does not change
its account passwords automatically, so the script prints the `ALTER USER` commands to run manually
(the old root password is in the archived `env/mysql.env`). A fresh deployment needs no manual step.

### Resetting the admin password (--reset-admin)

```bash
./generate-secrets.sh --reset-admin  # can be combined with other flags
```

Generates a new `ADMIN_PASSWORD`, overwrites `deploy/secrets/admin_password.txt` and says so
explicitly. Existing admin accounts are not updated automatically; the script prints a command
that updates them through the app container using `password_hash`.

### Rotating the field-encryption key (--rotate-encryptable)

```bash
./generate-secrets.sh --rotate-encryptable   # can be combined with other flags
```

Generates a new `ENCRYPTABLE_KEY` and appends the old key to `ENCRYPTION_PREVIOUS_KEYS`
(comma-separated, deduplicated). `erikwang2013/encryptable` tries the historical keys in turn when
reading old data and re-encrypts with the new key on write, so existing data stays readable. Note
that reads do not rewrite old rows: only after every sensitive field has been written again can the
historical keys be removed from `ENCRYPTION_PREVIOUS_KEYS` in `app.env`.

### Rotating TLS certificates (--rotate-tls)

```bash
./generate-secrets.sh --rotate-tls           # can be combined with other flags
```

Deletes and regenerates the self-signed certificate (CN=`DOMAIN`). This **replaces real
certificates**; make sure the archive (`deploy/secrets/archive/<timestamp>/tls/`) or your own
backup is available first. After putting the real certificate back, run
`docker compose restart nginx`.

### RSA key rotation and clients

The generated RSA keypair replaces the public key embedded in the client. Before encrypting the
password, the login page calls `GET /api/v1/auth/public-key` to fetch the server's current key, so:

- Flutter clients that include this logic need no rebuild; they pick up the new key after a reload;
- **Older clients** without that endpoint (or clients that cached the old key) cannot log in after a
  rotation and must be rebuilt:

  ```bash
  cd apps/flutter
  flutter build web --dart-define=ENV=prod
  ```

- When the server has no `RSA_PRIVATE_KEY`, the endpoint returns `code: 404` and the client falls back
  to the embedded public key.

## Resource limits

Compose sets `deploy.resources.limits.memory` for every service; a container exceeding its limit is
OOM-killed:

| Service | Memory limit | Notes |
|---------|--------------|-------|
| `app` | 512M | PHP/webman processes |
| `init` | 256M | one-shot initialization |
| `mysql` | 768M | `innodb-buffer-pool-size=256M` |
| `redis` | 256M | `maxmemory 256mb` (plus process overhead) |
| `nginx` | 128M | reverse proxy and static files |
| `backup` | 256M | `mysqldump`, `gzip` and `tar` |

The values assume a small single-machine deployment and vary with user count, imports/exports and
upload sizes. If a container is OOM-killed (`docker inspect <container> --format
'{{.State.OOMKilled}}'` reports `true`), raise that service's `memory` and run
`docker compose up -d`. For MySQL and Redis the limit must stay above their
`innodb-buffer-pool-size` / `maxmemory` settings plus process overhead.

## Backup and restore

The `backup` service takes one backup as soon as MySQL is healthy and then every 24 hours:

- database: `mysqldump --single-transaction --no-tablespaces --default-character-set=utf8mb4`
  → gzip → `deploy/data/backups/backup_<timestamp>.sql.gz`;
- uploads: `deploy/data/upload` is mounted read-only and archived as
  `upload_<timestamp>.tar.gz`;
- both kinds are kept for 30 days, and stale temporary files are removed when the container starts;
- each daily loop runs
  `DELETE FROM erik_operation_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY) LIMIT 10000`
  as the backup user, deleting at most 10,000 rows per day; failures are logged and never block the
  backup.

Backups use a dedicated least-privilege MySQL user (default `backup_reader`): `SELECT, SHOW VIEW,
TRIGGER, EVENT, LOCK TABLES` on the app database plus `DELETE` on `erik_operation_log` only.
`backup.env` no longer contains the root password; root credentials live only in `mysql.env`
(visible to the mysql container). The backup container runs as `APP_UID:APP_GID`, so files belong
to the host user and need no root to read; its logs rotate at `10m × 3`.

> **Upgrading an existing deployment**: `docker-entrypoint-initdb.d` only runs when the MySQL data
> directory is empty. Create the backup user manually after upgrading (the script is idempotent):
>
> ```bash
> cd deploy
> ./generate-secrets.sh        # generates DB_BACKUP_* and removes the old root password from backup.env
> docker compose up -d         # recreate containers with the new env files
> docker compose exec -T mysql sh /docker-entrypoint-initdb.d/20-app-user.sh
> docker compose restart backup   # take a backup now instead of waiting 24 hours
> ```
>
> If you later rotate database passwords with `--rotate-db`, run the printed `ALTER USER` for the
> backup user as well.

### Offsite copies

`deploy/data/backups` sits on the **same machine and the same disk** as the database, so a host or
disk failure loses the backups together with the data. Copy them offsite on a schedule, e.g. from
host cron:

```bash
# rsync to another host (prefer SSH keys)
rsync -a --delete deploy/data/backups/ backup@backup.example.com:/srv/open-admin/backups/

# or rclone to an S3-compatible bucket
rclone sync deploy/data/backups/ remote:open-admin-backups/
```

Keep more than 30 days of history at the destination too (object-storage versioning or remote
rotation); otherwise accidental deletion or ransomware hits both copies.

Manual backup:

```bash
cd deploy
docker compose exec -T mysql sh -c \
  'MYSQL_PWD="$DB_BACKUP_PASSWORD" mysqldump -u"$DB_BACKUP_USERNAME" --single-transaction --no-tablespaces --default-character-set=utf8mb4 "$MYSQL_DATABASE"' \
  | gzip > "data/backups/backup_$(date +%Y%m%d_%H%M%S).sql.gz"
```

Restore (needs write privileges, so it uses root):

```bash
gunzip -c deploy/data/backups/backup_YYYYMMDD_HHMMSS.sql.gz \
  | docker compose -f deploy/docker-compose.yml exec -T mysql sh -c \
      'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"'
```

`database/backup/backup.sh` and `restore.sh` target a host-reachable database (they read the root
`.env`) and do not work with this compose stack, where the database host is `mysql` and passwords
live in `deploy/env/mysql.env`.

## Initialization and operations

- The `init` container runs once after MySQL is healthy: it applies `database/install.sql` when the
  schema is missing, creates the initial admin linked to `super_admin` when no admin exists, writes
  `data/runtime/install.lock` and exits. Re-running it never resets an existing password.
- `app` and `nginx` run with a read-only root filesystem; only `runtime`, the upload directory and
  `/tmp` are writable.
- The API docs UI (`/apidoc/`) is not baked into the image; nginx serves it directly from the
  host-mounted `../public/apidoc`.
- Health checks: `GET /health` (app), `/nginx-health` (internal Nginx).
- Show the initial admin password: `cat deploy/secrets/admin_password.txt`.
