# Single-Machine Production Deployment (Docker Compose)

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

This directory contains a production stack for a single server: `nginx + app + init + mysql + redis`,
without Elasticsearch. All persistent data, logs and caches are bind-mounted under `deploy/data`,
so removing containers does not lose data.

## Quick start

```bash
cd deploy
./generate-secrets.sh          # generate .env, RSA keys, self-signed cert, admin password and data dirs
docker compose up -d --build   # build the image and start everything
```

After startup:

- Admin UI / API: `https://<DOMAIN>/` (defaults to `https://localhost`; browsers warn about the self-signed cert)
- Static website: `https://<DOMAIN>/open-admin/`
- Plain HTTP redirects to HTTPS with 301
- Initial admin username is printed by the script (default `admin`); the password is in `deploy/secrets/admin_password.txt`

Change the admin password immediately after the first login.

Common commands (run inside `deploy/`):

```bash
docker compose ps
docker compose logs -f app
docker compose restart nginx
docker compose down
```

## Files and directories

| Path | Contents | Mode |
|------|----------|------|
| `deploy/.env` | All environment variables and generated secrets (including the RSA private key) | 600 |
| `deploy/secrets/rsa_public_key.pem` | RSA public key (pairs with `.env`) | 600 |
| `deploy/secrets/admin_password.txt` | Initial admin password | 600 |
| `deploy/secrets/tls/fullchain.pem`, `privkey.pem` | TLS certificate (self-signed by default) | 600 |
| `deploy/secrets/archive/<timestamp>/` | Old `.env` and `secrets/` archived by `--regenerate` | 700 |
| `deploy/data/mysql` | MySQL data directory | 700 |
| `deploy/data/mysql-logs` | MySQL error log | 700 |
| `deploy/data/redis` | Redis AOF persistence | 700 |
| `deploy/data/runtime` | webman runtime, logs, `install.lock` | 700 |
| `deploy/data/upload` | Admin uploads | 700 |
| `deploy/data/nginx/logs` | Nginx access / error logs | 700 |
| `deploy/data/backups` | Backup output directory | 700 |

> On first start the containers chown `data/mysql`, `data/mysql-logs` and `data/redis` to their
> internal users (standard MySQL/Redis image behaviour). Your host user may no longer read those
> directories directly; this is expected.
> Docker logs rotate at `10m × 3`; MySQL and Nginx write their own logs to the host directories above.

MySQL and Redis are only reachable on the internal compose network; no host ports are published.
Only `HTTP_PORT` (default 80) and `HTTPS_PORT` (default 443) are exposed.

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
overwrites** existing certificates. Certificates are plain files, independent of ACME; if you need
HTTP-01 validation, put the challenge file in `public/.well-known/acme-challenge/` (port 80 does not
redirect that path).

## Regenerating secrets (--regenerate)

```bash
cd deploy
docker compose down
./generate-secrets.sh --regenerate
docker compose up -d --build
```

The script first copies the current `deploy/.env` and the whole `deploy/secrets/` directory to
`deploy/secrets/archive/<timestamp>/`, and only deletes and regenerates after the archive succeeds.
The archive contains the old RSA private key, old database passwords and old certificate — treat it as
key material.

> **Existing database data:** `--regenerate` creates new MySQL root / app-user passwords, but the
> accounts already stored in `deploy/data/mysql` keep the old passwords, so the app cannot connect
> after a plain restart. Options:
> 1. Keep the data: copy the old `DB_PASSWORD` / `MYSQL_ROOT_PASSWORD` from the archived `.env` back
>    into the new `.env`.
> 2. Keep the data: log into MySQL with the old password and run `ALTER USER` to the new password.
> 3. Fresh deployment: delete `deploy/data/mysql` and restart (**all business data is lost**; back up first).

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

## Backup and restore

Backup (written to the host at `deploy/data/backups`, no database port exposure needed):

```bash
cd deploy
docker compose exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --triggers --default-character-set=utf8mb4 "$MYSQL_DATABASE"' \
  | gzip > "data/backups/backup_$(date +%Y%m%d_%H%M%S).sql.gz"
```

Restore:

```bash
gunzip -c deploy/data/backups/backup_YYYYMMDD_HHMMSS.sql.gz \
  | docker compose -f deploy/docker-compose.yml exec -T mysql sh -c \
      'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"'
```

`database/backup/backup.sh` and `restore.sh` target a host-reachable database (they read the root
`.env`) and do not work with this compose stack, where the database host is `mysql` and passwords live
in `deploy/.env`.

## Initialization and operations

- The `init` container runs once after MySQL is healthy: it applies `database/install.sql` when the
  schema is missing, creates the initial admin linked to `super_admin` when no admin exists, writes
  `data/runtime/install.lock` and exits. Re-running it never resets an existing password.
- `app` and `nginx` run with a read-only root filesystem; only `runtime`, the upload directory, Nginx
  logs and `/tmp` are writable.
- Health checks: `GET /health` (app), `/nginx-health` (internal Nginx).
- Show the initial admin password: `cat deploy/secrets/admin_password.txt`.
