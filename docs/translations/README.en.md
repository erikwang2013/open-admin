# Open Admin (open-admin)

![Xiao An — open-admin project pet](../../public/img/pet.svg)

A full-stack admin dashboard built on **webman v2 + Flutter**: authentication and RBAC permissions, 18 layers of defense in depth, Prometheus observability, and multi-platform clients (Flutter Web / HarmonyOS).

> The project pet "**Xiao An**" is a shield-shaped guard robot, standing guard at the "protection" and "authentication" checkpoints of the middleware chain.
> Asset [`public/img/pet.svg`](../../public/img/pet.svg): pure SVG (no scripts, no external dependencies, supports dark backgrounds and `prefers-reduced-motion`), also used as the site icon.
>
> Design diagrams: [System Architecture](../../docs/diagrams/architecture.svg) · [Feature Design](../../docs/diagrams/features.svg) · [Lifecycle](../../docs/diagrams/lifecycle.svg)

> [中文](../../README.md) | [Architecture Diagrams](../ARCHITECTURE.en.md) | [Design Doc](../DESIGN.en.md) | [Security](../SECURITY.en.md) | [API Reference](../API.en.md)

## Features

| Domain | Feature | Notes |
|--------|---------|-------|
| 🔐 Auth | Login/Refresh/Logout | Click captcha + JWT + blacklist |
| | Account lockout | 5 failures → 15 min lock |
| | Concurrent session limit | Max 3 active tokens per user |
| 📊 Dashboard | Real-time stats/trends/distribution/logs | Redis cached 5 min |
| 👥 Users | CRUD + batch delete/toggle status | Soft delete + password confirmation |
| | Excel batch import | Row-level validation + error report |
| 🔒 Roles & Perms | Role CRUD + permission tree | RBAC method.path granularity |
| ⚙ Config | Key-value CRUD | Grouped management |
| 📋 Audit | Log query + source detection | 8 platforms auto-detected |
| 📁 Files | Upload/Excel export/PDF export | Sensitive data auto-masked |
| 🛡 Security | 18-layer defense-in-depth | XSS/SQLi/path traversal/cmd injection/CSRF/rate limit/CSP... |
| 🏥 Ops | Health check/metrics/API docs/security.txt | Prometheus + OpenAPI 3.0 + erikwang2013/apidoc-php interactive docs |
| 🌐 i18n | Chinese/English | Accept-Language header / ?lang= param |

## Project Pet · Xiao An

![Xiao An](../../public/img/pet.svg)

**Xiao An** (小安) is the open-admin project pet, named after 「**安**全」 (security) and 「管理后**台**」 (admin dashboard) — a shield-shaped guard robot.

- **Shield-shaped body** — stands for the 18 layers of defense in depth; the antenna on top stays lit, standing for a service that lives in memory (webman/workerman)
- **Two eyes on the face screen** — they blink on a timer; they watch every request, and they watch the middleware chain
- **Verification badge on the chest** — the checkmark stays lit, standing for a passing `method.path` permission check

**Where it appears**

| Location | Form |
|------|------|
| Site home page `GET /` | Main mascot + project intro and entry navigation ([`app/view/index/view.html`](../../app/view/index/view.html)) |
| Install wizard `/install` | Header mascot of the three-step wizard (`InstallController::layout()`) |
| Browser tab | Site icon `rel="icon" type="image/svg+xml"` |
| This document and the design diagrams | Project avatar + the pet card in the bottom-right corner of the architecture diagram |

> Single source of the asset: [`public/img/pet.svg`](../../public/img/pet.svg). Editing it updates every location above at once.

**Want to draw your own?** Icon spec: primary `#1677FF`, warm antenna `#FA8C16`, verification green `#52C41A`; canvas `240 × 320`, shield centered, minimum usable size 48 px.

## Copyright

```
Copyright (c) 2026 erik, erik@erik.xyz, https://erik.xyz
```

This copyright notice is permanent, must not be modified, removed, or reversed. All project files are protected under this copyright.

## Tech Stack

| Layer | Technology | Notes |
|---|------|------|
| Backend | webman v2 (workerman) | High-performance PHP daemon framework |
| PHP | 8.3+ | |
| Database | MySQL 8.0+ | Table prefix `erik_`, BIGINT non-auto-increment PKs |
| Admin Frontend | Flutter 3.x | Web renders as desktop admin panel (`apps/flutter/`) |
| Mobile | HarmonyOS ArkTS | Native HarmonyOS client (`apps/harmonyos/`), supports phone/tablet/2in1 |

## Core Packages

| Package | Purpose |
|---|------|
| `erikwang2013/snowflake-php` | Globally unique BIGINT primary key generation |
| `erikwang2013/hashids` | API-layer ID encryption to hide real database IDs |
| `erikwang2013/jwt-webman` | JWT token issuance and verification |
| `erikwang2013/encryption` | Transport-layer sensitive data encryption |
| `erikwang2013/encryptable` | Database-layer sensitive field auto encryption |
| `erikwang2013/season` | Country flag data |
| `erikwang2013/poster-php` | Click captcha generation/verification + poster generation |
| `phpoffice/phpspreadsheet` | Excel export |
| `barryvdh/laravel-dompdf` | PDF export (Dompdf-based) |

## Project Structure

```
open-admin/
├── app/
│   ├── admin/controller/       # Admin controllers
│   │   ├── DashboardController.php # Dashboard (Redis cached)
│   │   ├── UserController.php      # User CRUD + batch ops
│   │   ├── RoleController.php      # Role CRUD
│   │   ├── PermissionController.php# Permission CRUD
│   │   ├── ConfigController.php    # System config CRUD
│   │   ├── LogController.php       # Operation log viewer
│   │   ├── ProfileController.php   # Profile + logout
│   │   ├── ExportController.php    # Excel/PDF export
│   │   ├── ImportController.php    # Excel import users
│   │   ├── UploadController.php    # File upload
│   │   ├── HealthController.php    # Health check
│   │   └── DocsController.php      # OpenAPI docs
│   ├── api/
│   │   └── v1/controller/          # API v1 controllers (version reflected in the /api/v1 URL prefix)
│   │       ├── CaptchaController.php
│   │       └── AuthController.php    # Login/Refresh
│   ├── middleware/             # Middleware
│   │   ├── Cors.php            # CORS
│   │   ├── SecurityFilter.php  # Attack detection (HTTP method restriction/XSS/SQLi/path traversal/cmd injection/CSRF)
│   │   ├── RateLimit.php       # Redis rate limiting
│   │   ├── AdminAuth.php       # JWT auth + blacklist
│   │   ├── AdminPermission.php # RBAC authorization
│   │   └── OperationLog.php    # Auto operation logging (with source detection)
│   ├── model/                  # Eloquent models
│   └── view/index/view.html    # Site home page template (GET /, with the project pet and entry navigation)
├── apps/
│   ├── flutter/                # Flutter Web admin panel
│   └── harmonyos/              # HarmonyOS client (auto token refresh)
├── config/                     # Config files
├── database/install.sql        # SQL install script (incl. permission seeds)
├── docs/                       # Documentation
│   ├── diagrams/               # Diagram directory
│   │   ├── architecture.svg    # System architecture design diagram (hand-written SVG)
│   │   ├── features.svg        # Feature design diagram (hand-written SVG)
│   │   ├── lifecycle.svg       # Lifecycle diagram (hand-written SVG)
│   │   └── 01..12-*.md         # Decomposed diagrams (Mermaid, Chinese/English)
│   └── translations/           # English docs (README.en / CLAUDE.en)
├── public/                     # Public entry (Web root)
│   └── img/pet.svg             # Project pet "Xiao An" (SVG, also the site icon)
├── site/                       # Responsive static site (Chinese/English, search, install/architecture/changelog)
├── deploy/                     # Single-machine production stack (compose + secret generation + Nginx/TLS)
└── vendor/                     # Composer dependencies
```

## Architecture Design and Diagrams

All three diagrams are **pure hand-written SVG** (no scripts, no external font dependencies, infinitely scalable). View them directly on GitHub or in a browser, or drop them into slides and documents:

| Diagram | Content | File |
|---|------|------|
| System Architecture | Four-layer topology: client layer → gateway layer → webman application layer (middleware chain / controllers / common services) → storage layer, with security and observability on the right | [`docs/diagrams/architecture.svg`](../../docs/diagrams/architecture.svg) |
| Feature Design | 12 feature domains → controller entry points → key capabilities, with the middleware execution chain and data interface conventions at the bottom | [`docs/diagrams/features.svg`](../../docs/diagrams/features.svg) |
| Lifecycle | Install → startup → request intake → protection → authentication → processing → persistence → response audit, including exception branches and token lifecycle | [`docs/diagrams/lifecycle.svg`](../../docs/diagrams/lifecycle.svg) |

![open-admin system architecture design diagram](../../docs/diagrams/architecture.svg)

![open-admin feature design diagram](../../docs/diagrams/features.svg)

![open-admin lifecycle diagram](../../docs/diagrams/lifecycle.svg)

> For editable source-level diagrams, see [`docs/ARCHITECTURE.en.md`](../ARCHITECTURE.en.md) and [`docs/diagrams/`](../../docs/diagrams/) (Mermaid, can be pasted into [Mermaid Live](https://mermaid.live/) for editing).

## Requirements

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (frontend development only)

## Quick Start

### 1. Install Dependencies

```bash
composer install
```

### 2. Configure Environment

```bash
cp .env.example .env
```

Key environment variables:

| Variable | Description | Default |
|---------|-------------|---------|
| `JWT_SECRET` | JWT signing key | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Hashids salt | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | API encryption key | 32-byte default |
| `SNOWFLAKE_DATACENTER_ID` | Datacenter ID (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | Worker ID (0-31) | `1` |

**Always change all keys to random strings in production.**

### 3. One-Click Install

Start the server, then open the install wizard in your browser to set up the database and create an admin account:

```bash
php start.php start
```

Default: `http://0.0.0.0:8791` (change port in `config/server.php`).

Open **`http://localhost:8791/install`** and follow the wizard:

| Step | Description |
|------|-------------|
| ① Database | Host, port, database name, username, password |
| ② Admin Account | Admin username and password (default: admin / admin888) |

Click "Start Install" — tables are created, permissions seeded, admin account created, and `.env` is updated automatically.

> After installation, `runtime/install.lock` is created to prevent re-installation. Delete this file to re-install.

### 4. Login

Visit `http://localhost:8791` and log in with the admin credentials set during installation.

### 5. Start Frontend (Optional)

**Flutter admin panel (Web):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web (desktop admin panel style)
```

**HarmonyOS client (Mobile):**

Open `apps/harmonyos/` in DevEco Studio and run on a device or emulator.

### 6. Docker Compose Quick Start (local or single-host trial)

The `docker-compose.yml` at the project root provides a 4-service quick-start stack: Nginx, PHP (webman app), MySQL, Redis.

```bash
# 1. Configure Docker environment variables
cp .env.docker .env

# 2. Start all services
docker compose up -d

# 3. Open the install wizard in your browser
# http://localhost/install  (fill in DB and admin info; DB host: mysql)

# 4. Access
# http://localhost  (Nginx: static site + reverse proxy to webman)
# http://localhost/api/v1/...  (API)
```

- `Dockerfile`: PHP 8.3 + OPcache + Composer, based on `php:8.3-cli`
- `docker-compose.yml`: 4 services; app listens on 8791 inside the container network only, exposed through Nginx on port 80
- `.env.docker`: Docker-specific environment variables

> For production, use the single-machine stack in `deploy/` (nginx/app/init/mysql/redis with generated secrets and TLS); see "Deployment" below.

## Database Conventions

- **Prefix**: `erik_`
- **Primary Key**: `id BIGINT UNSIGNED NOT NULL`, **NO AUTO_INCREMENT**
- **ID Generation**: PKs are generated at the application layer via `SnowflakeService::generate()`
- **Required Columns**: Every table must have `id`, `created_at`, `updated_at`
- **Soft Delete**: Add `deleted_at DATETIME DEFAULT NULL` where needed
- **Sensitive Fields**: Phone, email, ID card — stored as ciphertext via the `encryptable` plugin, database column type `VARCHAR(500)`

## API Documentation

The complete API reference (response format, error codes, all endpoint details, auth flow, rate limiting, middleware chain) lives in **[docs/API.en.md](../API.en.md)**. Highlights:

- **Response format**: `{ "code": 0, "message": "success", "data": {...} }`, `code=0` means success
- **Error codes**: `400` bad request / `401` unauthenticated / `403` forbidden / `404` not found / `422` validation failed / `429` rate limited / `500` server error
- **API versioning**: the version is reflected in the URL prefix (e.g. `/api/v1/...`), not in request headers
- **Authentication**: `Authorization: Bearer {token}`; access_token TTL 2h, refresh_token TTL 14d
- **ID handling**: IDs in requests/responses are hashid-encrypted strings, real database IDs are never exposed

## Frontend Notes

### Flutter Admin Panel (Desktop Style)

- **Layout**: Collapsible sidebar (64px/240px) + header + content area, responsive breakpoints (phone/tablet/desktop)
- **Pages**: Login, Dashboard, User Management, Roles & Permissions, System Config, Operation Logs, Profile
- **State**: GetX (`ApiService` singleton + `AuthService` token persistence)
- **Dashboard**: Stats cards, trend line chart (fl_chart), pie chart, recent activity log
- **Export**: Excel/PDF with non-removable copyright info
- **Batch Ops**: Multi-select batch delete, batch enable/disable
- **Theme**: Material 3 light/dark dual theme

### HarmonyOS Mobile Client

- **Pages**: Login, Dashboard, User List/Detail, Profile
- **Auth**: JWT Bearer + silent token refresh on 401, auto-redirect to login on refresh failure
- **Storage**: Token managed via AppStorage

## Development Rules

- No leading `\` on global function/class references — use `use` imports
- All PHP files must include the copyright header
- All config files must include inline comments
- Primary keys must be generated at the application layer via snowflake — no auto-increment
- All IDs in API parameters and responses must be encoded/decoded via hashids
- AdminPermission middleware uses Redis cache for user permissions (TTL=60s), eliminating N+1 query bottlenecks

## Deployment

### Quick Start (4 services)

The root `docker-compose.yml` orchestrates `nginx`, `app`, `mysql` and `redis` for local or single-host trials:

```bash
cp .env.docker .env
docker compose up -d
```

### Production (single machine, 5 services)

The production stack lives in `deploy/` and runs `nginx`, `app`, `init` (one-shot bootstrap), `mysql` and `redis` on one machine. No full-text search cluster is used.

| Service | Description |
|------|------|
| `nginx` | 80/443, TLS termination, static site, reverse proxy to `app:8791` |
| `app` | webman PHP 8.3 application, listens on 8791 inside the container network only |
| `init` | One-shot bootstrap: schema and admin user, then exits |
| `mysql` | MySQL 8.0 with data and logs on host bind mounts |
| `redis` | Redis 7 with AOF persistence on a host bind mount |

```bash
cd deploy
./generate-secrets.sh          # deploy/.env, keys, self-signed TLS cert, admin password
docker compose up -d --build
```

- `generate-secrets.sh` is idempotent and only fills in missing values. It creates `deploy/.env`, `deploy/secrets/rsa_public_key.pem` and `deploy/secrets/admin_password.txt`; the RSA private key goes into `RSA_PRIVATE_KEY` in `.env`.
- `--regenerate` archives the current `deploy/.env` and `deploy/secrets/` to `deploy/secrets/archive/<timestamp>/` before rebuilding everything; old secrets remain in the archive.
- TLS defaults to a self-signed certificate for `DOMAIN` (`localhost` by default). Replace `deploy/secrets/tls/fullchain.pem` and `privkey.pem` with real-domain certificates; existing certificates are not overwritten (`--regenerate` excepted). Port 80 only serves `/.well-known/acme-challenge/` and redirects everything else to HTTPS.
- All persistent data and logs are host bind mounts: `deploy/data/{mysql,mysql-logs,redis,runtime,upload,nginx/logs,backups}`; Docker logs rotate at `10m × 3`.
- RSA public key: `GET /api/v1/auth/public-key` returns the current public key. Flutter fetches it before login and falls back to the embedded key on failure; after `--regenerate` rotates the keypair, verify the endpoint is reachable and rebuild clients that rely on the embedded fallback.

The PHP image is built from `Dockerfile`, based on `php:8.3-cli` with OPcache enabled.

### CI/CD

GitHub Actions CI pipeline: `.github/workflows/ci.yml`

- PHP syntax check (`php -l`)
- PHPUnit tests
- Flutter static analysis (`flutter analyze`)

### Database Backup

`database/backup/` directory:

- `backup.sh` — mysqldump + gzip backup, auto-clears backups older than 30 days
- `restore.sh` — interactive restore, lists available backups for selection

### Nginx Security

See `docs/nginx-security.conf` for production reverse-proxy security hardening.

## Open Source Needs Your Support

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

## 项目授权 / Project License

代码以 [MIT License](../../LICENSE) 开源。

Copyright (c) 2026 erik, erik@erik.xyz, https://erik.xyz

- 可自由使用、复制、修改、合并、发布、分发、再许可和销售本项目代码，需保留版权与许可声明。
- 视觉资产（项目宠物、图片、图标、表情等）不随代码的 MIT 许可开源，适用 [ASSETS-LICENSE.md](../../ASSETS-LICENSE.md)。
- 第三方组件仍遵循其各自原有的许可证。

**English:** The source code is released under the [MIT License](../../LICENSE). Visual assets are not covered by the MIT license and remain under [ASSETS-LICENSE.md](../../ASSETS-LICENSE.md). Third-party components retain their original licenses.
