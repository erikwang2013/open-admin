# Open Admin (open-admin)

A full-stack admin dashboard built with webman v2 + Flutter.

> [中文文档](README.md) | [Architecture Diagrams](docs/ARCHITECTURE.md) | [Design Doc](docs/DESIGN.md)

## Copyright

```
Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
```

This copyright notice is permanent, must not be modified, removed, or reversed. All project files are protected under this copyright.

## Tech Stack

| Layer | Technology | Notes |
|---|------|------|
| Backend | webman v2 (workerman) | High-performance PHP daemon framework |
| PHP | 8.3+ | |
| Database | MySQL 8.0+ | Table prefix `erik_`, BIGINT non-auto-increment PKs |
| Search | Elasticsearch | Synced via `webman-scout` |
| Admin Frontend | Flutter 3.x | Web renders as desktop admin panel (`apps/admin_app/`) |
| Mobile | HarmonyOS ArkTS | Native HarmonyOS client (`apps/harmonyos/`), supports phone/tablet/2in1 |

## Core Packages

| Package | Purpose |
|---|------|
| `erikwang2013/snowflake-php` | Globally unique BIGINT primary key generation |
| `erikwang2013/hashids` | API-layer ID encryption to hide real database IDs |
| `erikwang2013/jwt-webman` | JWT token issuance and verification |
| `erikwang2013/encryption` | Transport-layer sensitive data encryption |
| `erikwang2013/encryptable` | Database-layer sensitive field auto encryption |
| `erikwang2013/webman-scout` | Elasticsearch sync and full-text search |
| `erikwang2013/season` | Country flag data |
| `erikwang2013/poster-php` | Click captcha generation/verification + poster generation |
| `phpoffice/phpspreadsheet` | Excel export |
| `barryvdh/laravel-dompdf` | PDF export (Dompdf-based) |

## Project Structure

```
open-admin/
├── app/
│   ├── admin/controller/       # Admin controllers
│   ├── api/                    # Public API
│   │   └── v1/controller/      # API v1 controllers (version via API-Version header)
│   │       ├── CaptchaController.php# Click captcha (generate/verify)
│   │       └── AuthController.php   # Login/register/refresh token
│   ├── common/                 # Shared services
│   │   ├── HashidsService.php  # ID encode/decode
│   │   ├── SnowflakeService.php# Snowflake ID generation
│   │   └── EncryptionService.php # Encrypt/decrypt + masking
│   ├── middleware/             # Middleware
│   │   ├── ApiVersion.php      # API version validation (API-Version header)
│   │   ├── AdminAuth.php       # JWT authentication
│   │   └── AdminPermission.php # RBAC authorization
│   └── model/                  # Eloquent models
├── apps/                       # Frontend applications
│   ├── admin_app/              # Flutter web admin panel (desktop style)
│   └── harmonyos/              # HarmonyOS native mobile client
├── config/                     # Configuration files
│   ├── route.php               # Routes + API version strategy
├── database/migrations/        # SQL migration files
├── public/                     # Web entry point
├── runtime/                    # Runtime files (logs, cache, temp exports)
└── vendor/                     # Composer dependencies
```

## Requirements

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (frontend development only)
- Elasticsearch >= 7.x (optional, for search)

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
| `SCOUT_HOSTS` | ES hosts | `http://localhost:9200` |

**Always change all keys to random strings in production.**

### 3. Initialize Database

```bash
mysql -u root -p < database/migrations/2026_05_16_000000_init_tables.sql
```

### 4. Start Server

```bash
php start.php start
```

Default: `http://0.0.0.0:8787`.

### 5. Start Frontend (Optional)

**Flutter admin panel (Web):**

```bash
cd apps/admin_app
flutter pub get
flutter run -d chrome    # Web (desktop admin panel style)
```

**HarmonyOS client (Mobile):**

Open `apps/harmonyos/` in DevEco Studio and run on a device or emulator.

## Database Conventions

- **Prefix**: `erik_`
- **Primary Key**: `id BIGINT UNSIGNED NOT NULL`, **NO AUTO_INCREMENT**
- **ID Generation**: PKs are generated at the application layer via `SnowflakeService::generate()`
- **Required Columns**: Every table must have `id`, `created_at`, `updated_at`
- **Soft Delete**: Add `deleted_at DATETIME DEFAULT NULL` where needed
- **Sensitive Fields**: Phone, email, ID card — stored as ciphertext via the `encryptable` plugin, database column type `VARCHAR(500)`

## API Conventions

### Response Format

```json
{
    "code": 0,
    "message": "success",
    "data": {}
}
```

### Error Codes

| Code | Meaning |
|------|---------|
| `0` | Success |
| `400` | Bad request |
| `401` | Unauthenticated |
| `403` | Forbidden |
| `404` | Not found |
| `422` | Validation failed |
| `500` | Server error |

### ID Handling

- **API request/response IDs**: Encrypted to hashid strings, real DB IDs never exposed
- **URL paths**: `GET /admin/user/{hashid}` — the `{id}` parameter is a hashid
- **Database storage**: BIGINT raw values generated by snowflake

### API Versioning

The API version is specified via a request header — **not in the URL path**:

```http
API-Version: v1
```

- Defaults to `v1` when the header is absent
- Unsupported versions return `400 Bad Request`
- To add a new version, create `app/api/{version}/controller/` and register it in the middleware

### Authentication

Login and registration require **click captcha** verification:

1. Client requests `POST /api/captcha/generate` to get a captcha image (base64 PNG) and target word list
2. User clicks the corresponding word positions on the image in order
3. Login request includes `captcha_key` and `clicks` array — server verifies captcha before credentials

```http
POST /api/auth/login
Content-Type: application/json

{
  "username": "admin",
  "password": "******",
  "captcha_key": "abc123...",
  "clicks": [{"x": 120, "y": 85}, {"x": 210, "y": 140}, {"x": 95, "y": 170}]
}
```

All admin endpoints require a JWT token:

```http
Authorization: Bearer <token>
```

Login returns an `access_token` (2h TTL) and a `refresh_token` (14d TTL).

### Sensitive Operation Confirmation

Destructive operations (delete user, role, permission) require the current user's `password` in the request body for identity re-verification:

```http
DELETE /admin/user/{id}
Content-Type: application/json
Authorization: Bearer <token>

{ "password": "******" }
```

## API Reference

> All `/api/*` endpoints require the `API-Version: v1` header (defaults to v1 if absent).

### Public Endpoints

| Method | Path | Description |
|-----|------|------|
| `POST` | `/api/captcha/generate` | Generate click captcha (key + base64 image + targets) |
| `POST` | `/api/captcha/verify` | Verify click positions (debugging) |
| `POST` | `/api/auth/login` | Login (requires captcha) |
| `POST` | `/api/auth/register` | Register (requires captcha) |
| `POST` | `/api/auth/refresh` | Refresh token |

### Admin Endpoints (requires JWT + RBAC)

| Method | Path | Description |
|-----|------|------|
| `GET` | `/admin/dashboard` | Dashboard data (stats, trends, distribution) |
| `GET` | `/admin/user` | User list (paginated + search) |
| `POST` | `/admin/user` | Create user |
| `GET` | `/admin/user/{id}` | User detail |
| `PUT` | `/admin/user/{id}` | Update user |
| `DELETE` | `/admin/user/{id}` | Delete user (soft delete, requires password) |
| `GET` | `/admin/role` | Role list |
| `POST` | `/admin/role` | Create role |
| `PUT` | `/admin/role/{id}` | Update role |
| `DELETE` | `/admin/role/{id}` | Delete role (requires password) |
| `GET` | `/admin/permission` | Permission tree |
| `POST` | `/admin/permission` | Create permission |
| `PUT` | `/admin/permission/{id}` | Update permission |
| `DELETE` | `/admin/permission/{id}` | Delete permission (cascades children, requires password) |
| `POST` | `/admin/export/excel` | Export to Excel |
| `POST` | `/admin/export/pdf` | Export to PDF |

## Frontend Notes

The Flutter app is designed as a desktop-style admin panel:

- **Layout**: Collapsible sidebar (64px/240px) + header + content area
- **Dashboard**: Stats cards, line charts, pie charts, recent activity log
- **Export**: Excel and PDF export, PDF files include non-removable copyright info
- **Theme**: Material 3 light/dark dual theme

Differences from mobile app:
- Web uses sidebar navigation instead of bottom navigation bar
- High-density data tables with multi-select batch operations
- Mouse hover and right-click interactions

## Development Rules

- No leading `\` on global function/class references — use `use` imports
- All PHP files must include the copyright header
- All config files must include inline comments
- Primary keys must be generated at the application layer via snowflake — no auto-increment
- All IDs in API parameters and responses must be encoded/decoded via hashids

## 开源不易，欢迎支持

| 微信 | 支付宝 |
|:---:|:---:|
| ![微信](./docs/weixinpay.png "微信") | ![支付宝](./docs/alipay.png "支付宝") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
