> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
>
> [中文](API.md) | [English](API.en.md)

# API Reference

## 1. Overview

open-admin is built on webman v2 and provides a RESTful JSON API. All admin endpoints require JWT authentication and RBAC permission checks; public endpoints carry the API version in the URL prefix (e.g. `/api/v1/auth/login`) and route directly to the corresponding versioned controllers.

- **Base URL**: `http://localhost:8791`
- **API version**: embedded in the URL prefix (`/api/v1/...`, `/api/v2/...`); no request header is used
- **Language**: switched via the `Accept-Language` header or the `?lang=zh_CN|en` parameter (default zh_CN), auto-detected by the Locale middleware

> **Endpoint overview**: Auth (4) | Dashboard (1) | Users (7) | Roles (5) | Permissions (4) | Config (4) | Logs (1) | Profile (3) | Import/Export (3) | Upload (1) | Ops (4: health/metrics/docs/security.txt) | 37 endpoints in total
- **Authentication**: `Authorization: Bearer <token>` (JWT)
- **Response format**: `{ "code": 0, "message": "success", "data": {...} }`
- **Docs endpoint**: `GET /api/docs` returns the OpenAPI 3.0 JSON specification; `/apidoc/` provides a lightweight same-origin debug UI (a single HTML file with no external assets)

### Request Requirements

- Only `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD` methods are allowed; other HTTP methods (such as TRACE, CONNECT, PATCH) return 405
- All `POST` / `PUT` requests must set `Content-Type: application/json` (except file uploads), otherwise 415 is returned
- The request body must not exceed 10MB, otherwise 413 is returned
- The security filter scans all request inputs for XSS, SQL injection, path traversal, and command injection; hits return 403
- 5 consecutive login failures trigger account lockout (15 minutes); during lockout, login requests return 429
- A single user can hold at most 3 valid tokens concurrently; when exceeded, the oldest token is automatically blacklisted

## 2. Error Codes

| code | Meaning | Trigger |
|------|---------|---------|
| 0 | Success | |
| 400 | Bad request | Incorrect request format |
| 401 | Unauthenticated | Token missing / expired / blacklisted |
| 403 | No permission / security block | Insufficient RBAC permission / beyond grantable or manageable role boundaries / non-super-admin acting on a super-admin account / SecurityFilter hit |
| 404 | Resource not found | Target of query/update/delete does not exist |
| 405 | Method not allowed | Only GET/POST/PUT/DELETE/OPTIONS/HEAD allowed; non-standard methods rejected outright |
| 409 | Conflict | A rotation for the same refresh_token is in progress; a user import is already running |
| 413 | Payload too large | Content-Length exceeds 10MB |
| 415 | Unsupported media type | POST/PUT Content-Type is not JSON and not a file upload |
| 422 | Validation failed | Required fields missing, invalid format, length exceeded, invalid role_ids, invalid export conditions, business validation failed |
| 429 | Too many requests | RateLimit triggered / account locked (5 failed logins lock for 15 min) |
| 500 | Internal server error | |
| 503 | Service unavailable | Redis unavailable, so auth/rate limiting for `/api/` and `/admin/` or login captcha consumption fails closed; super-admin named-lock wait timeout or database error |

## 3. Public Endpoints

### 3.1 Health Check

```
GET /health
```

- **Authentication**: none
- **Rate limit**: none

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "app": "open-admin",
    "version": "1.0",
    "php": "8.3.0",
    "database": "ok",
    "redis": "ok",
    "timestamp": 1716249600
  }
}
```

`database` and `redis` take the values `"ok"` | `"unavailable"`.

### 3.2 API Docs

```
GET /api/docs
```

- **Authentication**: none
- **Rate limit**: global default (60/min)
- **Response**: OpenAPI 3.0.3 JSON specification with all endpoint definitions, parameters, and schemas

Open `/apidoc/` in a browser to call the documented endpoints directly: the page loads the same-origin `GET /api/docs` spec and supports a Bearer token, path/query parameters and a JSON request body, showing the status code and response. It is a single static HTML file with no external CDN or build step.

### 3.3 Generate Captcha

```
POST /api/v1/captcha/generate
```

- **Authentication**: none
- **Rate limit**: global default (60/min)

**Request body**:
```json
{
  "difficulty": "medium"
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| difficulty | string | No | `easy` / `medium` / `hard`, default `medium` |

**Example response** — click type (`type: "click"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "abc123def456",
    "type": "click",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "targets": [
        { "order": 1, "text": "A", "x": 120, "y": 85 },
        { "order": 2, "text": "B", "x": 310, "y": 42 }
      ]
    }
  }
}
```

**Example response** — slider type (`type: "slider"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "def456abc789",
    "type": "slider",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "x": 120,
      "y": 60,
      "puzzle_w": 50,
      "puzzle_h": 50,
      "puzzle": "data:image/png;base64,iVBORw0KGgo..."
    }
  }
}
```

**Example response** — rotate type (`type: "rotate"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "ghi789abc012",
    "type": "rotate",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "angle": 45
    }
  }
}
```

| Field | Type | Description |
|------|------|------|
| key | string | Captcha identifier, echoed back when verifying |
| type | string | Captcha type: `click` / `slider` / `rotate` |
| image | string | base64 data URI image |
| extra | object | Type-specific extra data (see below) |

**`extra` by type**:

| type | extra fields | Type | Description |
|------|-----------|------|------|
| click | targets | array | Click targets with `order` (sequence), `text` (prompt text), `x`, `y` (coordinates) |
| slider | x, y | int | Coordinates of the top-left corner of the gap (based on a 300×200 canvas) |
| slider | puzzle_w, puzzle_h | int | Puzzle piece width and height |
| slider | puzzle | string | Puzzle piece base64 data URI |
| rotate | angle | int | Correct rotation angle (0-359); the image must be rotated by `360-angle` to right itself |

### 3.4 Verify Captcha

```
POST /api/v1/captcha/verify
```

- **Authentication**: none
- **Rate limit**: global default (60/min)

**Request body** — click type (`type: "click"`):
```json
{
  "key": "abc123def456",
  "type": "click",
  "clicks": [
    { "x": 120, "y": 85 },
    { "x": 310, "y": 42 }
  ]
}
```

**Request body** — slider type (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**Request body** — rotate type (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| key | string | Yes | Captcha key returned by generate |
| type | string | Yes | Captcha type, must match the `type` returned by generate |
| clicks | variant | Yes | Answer data; format varies by type (see below) |

**`clicks` by type**:

| type | clicks type | Description | Error tolerance |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | Array of click coordinates, in `order` sequence | 18px radius |
| slider | `int` | Slider X-axis offset | ±4px |
| rotate | `int` | Rotation angle (0-359) | ±5° |

**Example response**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

After a successful verification, the backend writes `captcha_verified:{key}` to Redis (TTL 300s), which the login endpoint checks to allow login. Login consumes the marker with an atomic `DEL`: only the request that gets `DEL` returning 1 proceeds, so concurrent reuse of the same key lets exactly one login through.
On failure, `code` is 422, `message` is `"验证失败，请重试"`, and `data.valid` is `false`.

### 3.5 Login

```
POST /api/v1/auth/login
```

- **Authentication**: none
- **Rate limit**: 10/min (per IP + path)

**Request body**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| Field | Type | Required | Validation | Description |
|------|------|------|---------|------|
| username | string | Yes | min:3, max:50 | Username |
| password | string | Yes | min:6, max:32 (plaintext) | RSA-2048 PKCS1v1.5 encrypted then Base64 encoded (AES/plaintext fallback accepted) |
| captcha_key | string | Yes | | Captcha key (must first pass `/api/v1/captcha/verify`) |

### Password Encryption Protocol

Uses **RSA-2048 asymmetric encryption**; the public key is embedded in the frontend code (safe to expose), while the private key is held only by the server.

```
Encryption flow (client):
  RSA public key (PEM) → PKCS1v1.5 encryption → Base64 encode → transmit

Decryption flow (server, stepwise fallback):
  1. RSA private key decrypt → success and valid UTF-8 → use the decrypted result
  2. AES-256-CBC-HMAC decrypt → success → use the decrypted result (legacy client compatibility)
  3. Plaintext fallback → use the raw input directly
```

The public key is fetched via `GET /api/v1/auth/public-key` before login; the Flutter client keeps an embedded copy as a fallback when the request fails. The private key lives only in the `RSA_PRIVATE_KEY` environment variable (`.env` for a direct install, `env/app.env` under `deploy/`) and must not be leaked.

> AES symmetric encryption is a legacy compatibility scheme and will be removed once all clients migrate to RSA.

#### Login Public Key

```
GET /api/v1/auth/public-key
```

- **Authentication**: none
- **Rate limit**: global default (60/min)

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "public_key": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----\n"
  }
}
```

Returns `code: 404` when `RSA_PRIVATE_KEY` is not configured. `deploy/generate-secrets.sh --regenerate` rotates the RSA keypair: after rotation, verify this endpoint is reachable and rebuild old clients that still rely on the embedded fallback key.

**Example response**:
```json
{
  "code": 0,
  "message": "登录成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "a1b2c3d4",
      "username": "admin",
      "real_name": "管理员"
    }
  }
}
```

| Field | Type | Description |
|------|------|------|
| access_token | string | JWT access token |
| refresh_token | string | JWT refresh token |
| expires_in | int | Access token validity (seconds), default 7200 |
| user.id | string | Hashid-encrypted user ID |
| user.username | string | Username |
| user.real_name | string | Real name |

When the password length is valid, login always performs one bcrypt check: a fixed cost-10 dummy hash is used when the user does not exist or the stored hash is empty, preventing account enumeration through response timing; invalid lengths are rejected as bad credentials without running bcrypt. The captcha marker is consumed atomically before password verification; if Redis is unavailable the endpoint returns 503 and does not allow login.

**Possible errors**:
- 422: Parameter validation failed (missing required fields, invalid format)
- 422: Complete the captcha verification first (captcha_key has not passed `/api/v1/captcha/verify`, or the marker was consumed by a concurrent request)
- 401: Incorrect username or password
- 403: Account has been disabled
- 429: Account is locked, try again in 15 minutes (triggered by 5 consecutive login failures)
- 503: Redis unavailable while consuming the captcha marker (fails closed)

### 3.6 Refresh Token

```
POST /api/v1/auth/refresh
```

- **Authentication**: none
- **Rate limit**: global default (60/min)

**Request body**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| refresh_token | string | Yes | The refresh_token obtained at login |

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200
  }
}
```

A successful refresh returns new access_token and refresh_token, and the old tokens automatically become invalid. The refresh also updates the user's last login time and IP. Before refreshing, the server checks the password-change watermark `user_pwd_changed:<userId>`: if the token was issued before the watermark (set by password change, disable, delete, etc.), it returns 401 even when the refresh_token has not expired.

To tolerate concurrent requests and client retries, a rotated refresh_token keeps a 60-second grace period: the server caches the new token pair for 60 seconds under the md5 of the old token, and resubmitting the same old token within that window returns the cached pair unchanged instead of rotating again. After the grace period the old token is treated as blacklisted and returns 401. If either token in the cached pair has been blacklisted, the server deletes the cache and returns 401.

Concurrent rotation of the same old token is serialized by `refresh_lock:<md5>` (`SET NX EX 5`, released by a Lua compare-and-delete): a request that fails to acquire the lock briefly waits for the cache, reuses the cached pair on a hit, and returns 409 on a miss so the client can retry later. Rotated keys are tracked in `user_refresh_rotations:<userId>` (TTL 120 seconds); logout and password change blacklist the cached pair and clear them. Clients should refresh serially; the HarmonyOS client uses single-flight refresh for concurrent 401s so all requests share one refresh result.

**Possible errors**:
- 422: Refresh token missing
- 401: Refresh token invalid, expired, blacklisted, or the cached token pair was revoked
- 409: A rotation for the same refresh_token is in progress; retry later

### 3.7 Prometheus Metrics

```
GET /metrics
```

- **Authentication**: none
- **Rate limit**: none
- **Response format**: Prometheus text format (`text/plain; version=0.0.4`)

Public Prometheus metrics endpoint for scraping by Grafana/Prometheus.

**Example response**:
```
# HELP openadmin_http_requests_total Total number of HTTP requests.
# TYPE openadmin_http_requests_total gauge
openadmin_http_requests_total 15234

# HELP openadmin_active_users Number of active users.
# TYPE openadmin_active_users gauge
openadmin_active_users 156

# HELP openadmin_db_connection_status Database connection status (1=ok, 0=fail).
# TYPE openadmin_db_connection_status gauge
openadmin_db_connection_status 1

# HELP openadmin_redis_connection_status Redis connection status (1=ok, 0=fail).
# TYPE openadmin_redis_connection_status gauge
openadmin_redis_connection_status 1

# HELP openadmin_memory_usage_bytes Memory usage in bytes.
# TYPE openadmin_memory_usage_bytes gauge
openadmin_memory_usage_bytes 18874368
```

| Metric | Type | Description |
|------|------|------|
| `openadmin_http_requests_total` | gauge | Total cumulative HTTP requests |
| `openadmin_active_users` | gauge | Current active users (logged in within 24 hours) |
| `openadmin_db_connection_status` | gauge | Database connection status, 1=ok, 0=fail |
| `openadmin_redis_connection_status` | gauge | Redis connection status, 1=ok, 0=fail |
| `openadmin_memory_usage_bytes` | gauge | Current memory usage of the PHP process (bytes) |

## 4. Dashboard

All admin endpoints are mounted under the `/admin` group and pass through three middleware: `AdminAuth` (JWT authentication), `AdminPermission` (RBAC permission check), and `OperationLog` (operation logging).

### 4.1 Dashboard Data

```
GET /admin/dashboard
```

- **Authentication**: JWT + RBAC
- **Cache**: Redis 5 minutes

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "stats": [
      {
        "label": "用户总数",
        "value": "1280",
        "icon": "people",
        "color": "#1677FF",
        "trend": 12.5
      },
      {
        "label": "今日新增",
        "value": "23",
        "icon": "person_add",
        "color": "#52C41A"
      },
      {
        "label": "活跃用户",
        "value": "156",
        "icon": "bolt",
        "color": "#FA8C16"
      },
      {
        "label": "操作日志",
        "value": "432",
        "icon": "description",
        "color": "#722ED1"
      }
    ],
    "trends": {
      "dates": ["2026-04-22", "2026-04-23", "..."],
      "series": [
        { "name": "累计用户", "data": [1200, 1210, "..."], "color": "#1677FF" },
        { "name": "操作日志", "data": [45, 52, "..."], "color": "#52C41A" }
      ]
    },
    "distribution": {
      "user_status": [
        { "name": "启用", "value": 1250 },
        { "name": "禁用", "value": 30 }
      ]
    },
    "recent_logs": [
      {
        "id": "hashid...",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/v1/auth/login",
        "ip": "192.168.1.1",
        "user_name": "admin",
        "created_at": "2026-05-21 10:30:00"
      }
    ]
  }
}
```

| stats field | Type | Description |
|------|------|------|
| label | string | Metric name |
| value | string | Metric value (string type) |
| icon | string | Material icon name |
| color | string | Card color value |
| trend | float? | Day-over-day growth rate (percentage); only present on "total users" |

| trends field | Type | Description |
|------|------|------|
| dates | array{string} | Date series of the last 30 days |
| series | array{object} | Trend line data; each entry contains name, data (value array), color |

## 5. User Management

All `id` values returned by user management endpoints are hashid-encrypted strings. The password field is excluded from responses. Phone and email are masked in list endpoints and returned in plaintext in detail endpoints (encrypted database fields are decrypted automatically by the Encryptable trait).

Users can be assigned roles via the optional `role_ids` (hashid array). A non-super-admin can only grant roles within their own permission set and cannot assign `super_admin`; they also cannot manage users holding higher-permission roles (including disabled roles). Boundary violations return 403.

### 5.1 User List

```
GET /admin/user
```

- **Authentication**: JWT + RBAC

**Query parameters**:

| Parameter | Type | Required | Default | Description |
|------|------|------|------|------|
| page | int | No | 1 | Page number |
| limit | int | No | 15 | Items per page |
| keyword | string | No | | Search keyword, matches username and real name |
| status | int | No | | Status filter, 0=disabled, 1=enabled |

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "a1b2c3d4",
        "username": "admin",
        "real_name": "管理员",
        "phone": "138****5678",
        "email": "a***@example.com",
        "status": 1,
        "last_login_at": "2026-05-21 10:30:00",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 100,
    "page": 1,
    "limit": 15
  }
}
```

| Field | Type | Description |
|------|------|------|
| id | string | Hashid-encrypted user ID |
| username | string | Username |
| real_name | string | Real name |
| phone | string | Masked phone number (`138****5678` format) |
| email | string | Masked email (`a***@example.com` format) |
| status | int | 1=enabled, 0=disabled |
| last_login_at | string | Last login time (datetime) |
| created_at | string | Creation time (datetime) |

### 5.2 Create User

```
POST /admin/user
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "username": "newuser",
  "password": "123456",
  "real_name": "新用户",
  "phone": "13800138000",
  "email": "newuser@example.com",
  "status": 1,
  "role_ids": ["r1r2r3r4"]
}
```

| Field | Type | Required | Validation | Description |
|------|------|------|---------|------|
| username | string | Yes | min:3, max:50 | Username (unique) |
| password | string | Yes | min:6, max:32 | Password (stored as bcrypt hash) |
| real_name | string | Yes | max:50 | Real name |
| phone | string | No | max:20 | Phone number (stored encrypted via Encryptable) |
| email | string | No | max:254 | Email (stored encrypted via Encryptable) |
| status | int | No | in:0,1 | Status, default 1 (enabled) |
| role_ids | array{string} | No | | Role IDs (hashid array); a non-super-admin can only grant roles within their own permission set and cannot assign `super_admin` |

**Example response**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "e5f6g7h8",
    "username": "newuser",
    "real_name": "新用户",
    "phone": "13800138000",
    "email": "newuser@example.com",
    "status": 1,
    "created_at": "2026-05-21 12:00:00"
  }
}
```

The user row and role assignments are written in one database transaction; either failure rolls the whole operation back, so no user is left without roles.

**Possible errors**:
- 403: Only a super admin may assign the super admin role / cannot grant roles beyond your own permissions
- 422: Username already exists
- 422: Parameter validation failed (missing required fields, phone over 20 characters, email over 254 characters, invalid or nonexistent role_ids)

### 5.3 User Detail

```
GET /admin/user/{id}
```

- **Authentication**: JWT + RBAC
- **Path parameter**: `{id}` is the hashid-encrypted user ID

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "管理员",
    "phone": "13800138000",
    "email": "admin@example.com",
    "status": 1,
    "role_ids": ["r1r2r3r4"],
    "last_login_at": "2026-05-21 10:30:00",
    "last_login_ip": "192.168.1.1",
    "created_at": "2026-01-01 00:00:00",
    "updated_at": "2026-05-20 08:00:00"
  }
}
```

In the detail endpoint, `phone` and `email` are returned in plaintext (stored encrypted in the database, decrypted automatically by the Encryptable cast), unmasked. `password` and `id_card` are never included in responses. `role_ids` is the user's current role set (hashid array), empty when the user has no roles.

**Possible errors**:
- 404: User does not exist

### 5.4 Update User

```
PUT /admin/user/{id}
```

- **Authentication**: JWT + RBAC
- **Path parameter**: `{id}` is the hashid-encrypted user ID

**Request body**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1,
  "role_ids": ["r1r2r3r4"]
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| real_name | string | No | Real name, max 50 characters; keeps the original value when omitted |
| password | string | No | New password, 6-32 plaintext characters (decrypted first when the client encrypts it); empty string or omitted means unchanged |
| phone | string | No | Phone number, max 20 characters |
| email | string | No | Email, max 254 characters |
| status | int | No | Must be exactly 0 (disabled) or 1 (enabled) |
| role_ids | array{string} | No | Role IDs (hashid array); when provided, replaces the current roles. Only newly added roles are checked; a non-super-admin cannot grant roles beyond their own permissions or assign `super_admin` |

The current logged-in account cannot be disabled (422). Disable operations are serialized with the "last enabled super admin" check under a MySQL named lock; a lock timeout or database error returns 503 so the caller can retry. Updates by a non-super-admin always repeat the permission-boundary check inside the named lock, so concurrent promotion cannot bypass it.

A submitted `phone`/`email` equal to the masked form of the current value (e.g. `138****1234`) or matching the mask shape is treated as unchanged, so masked list values are never written back. Role changes clear the user's permission cache; a `password` change or `status=0` revokes all of that user's sessions (access/refresh tokens blacklisted; the password-change watermark rejects old refresh tokens).

**Example response**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13900139000",
    "email": "new@example.com",
    "status": 1,
    "updated_at": "2026-05-21 12:30:00"
  }
}
```

**Possible errors**:
- 403: Cannot manage this user / only a super admin may assign the super admin role / cannot grant roles beyond your own permissions
- 404: User does not exist
- 422: Password must be 6-32 characters / real name exceeds 50 characters / phone exceeds 20 characters / email exceeds 254 characters / status is not 0 or 1
- 422: Cannot disable the current account; at least one enabled super admin must remain
- 422: Invalid role_ids or nonexistent role
- 503: Named-lock wait timeout or database error during the last-super-admin check; retry later

### 5.5 Delete User

```
DELETE /admin/user/{id}
```

- **Authentication**: JWT + RBAC
- **Path parameter**: `{id}` is the hashid-encrypted user ID
- **Sensitive operation**: requires password confirmation

**Request body**:
```json
{
  "password": "admin_password"
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| password | string | Yes | Current logged-in user's password (confirmation) |

**Example response**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Soft deletion is performed (Eloquent SoftDeletes): rows are marked with deleted_at rather than physically deleted. All sessions of the deleted user are revoked (access/refresh tokens blacklisted).

**Possible errors**:
- 403: Cannot manage this user / only a super admin may delete a super admin account
- 404: User does not exist
- 422: Sensitive operations require password confirmation (password empty)
- 422: Password verification failed (password mismatch); after 5 consecutive failures, confirmation is rejected for 5 minutes
- 422: Cannot delete the currently logged-in account
- 422: At least one enabled super administrator must remain

### 5.6 Batch Delete Users

```
POST /admin/user/batch/destroy
```

- **Authentication**: JWT + RBAC
- **Sensitive operation**: requires password confirmation

**Request body**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| ids | array{string} | Yes | Array of hashid-encrypted user IDs |
| password | string | Yes | Current logged-in user's password (confirmation) |

**Example response**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

Soft deletion is performed; `data.count` is the number of users actually deleted. Every deleted user's sessions are revoked.

**Possible errors**:
- 403: Cannot manage this user / only a super admin may delete a super admin account
- 422: Select users to delete (ids empty)
- 422: Invalid ID (hashid decode failed)
- 422: Password verification failed; after 5 consecutive failures, confirmation is rejected for 5 minutes
- 422: Cannot delete the currently logged-in account (ids include the current user)
- 422: At least one enabled super administrator must remain

### 5.7 Batch Enable/Disable Users

```
POST /admin/user/batch/status
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| ids | array{string} | Yes | Array of hashid-encrypted user IDs |
| status | int | Yes | 0=disabled, 1=enabled |

**Example response**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

message dynamically changes based on status to `"批量启用成功"` (batch enable succeeded) or `"批量禁用成功"` (batch disable succeeded). Enable and disable both run under the same MySQL named lock, rechecking the target's permission boundary and super-admin protection inside the lock; disable revokes all target sessions, enable does not restore old tokens.

**Possible errors**:
- 403: Cannot manage this user / only a super admin may modify a super admin account
- 422: Select users (ids empty)
- 422: Invalid status value (status is not 0 or 1)
- 422: Cannot change the status of the currently logged-in account
- 422: At least one enabled super administrator must remain (when disabling the last enabled super administrator)
- 503: Named-lock wait timeout or database error; retry later

## 6. Role Management

### 6.1 Role List

```
GET /admin/role
```

- **Authentication**: JWT + RBAC

**Query parameters**:

| Parameter | Type | Required | Default | Description |
|------|------|------|------|------|
| page | int | No | 1 | Page number |
| limit | int | No | 15 | Items per page |

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "r1r2r3r4",
        "name": "超级管理员",
        "slug": "super_admin",
        "description": "拥有所有权限",
        "status": 1,
        "users_count": 3,
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 5,
    "page": 1,
    "limit": 15
  }
}
```

| Field | Type | Description |
|------|------|------|
| id | string | Hashid-encrypted role ID |
| name | string | Role name |
| slug | string | Role identifier (unique, used for permission checks) |
| description | string | Role description |
| status | int | 1=enabled, 0=disabled |
| users_count | int | Number of users with this role |

### 6.2 Create Role

```
POST /admin/role
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| Field | Type | Required | Validation | Description |
|------|------|------|---------|------|
| name | string | Yes | max:50 | Role name |
| slug | string | Yes | max:50 | Role identifier |
| description | string | No | | Role description, default empty string |
| status | int | No | | Status, default 1 |
| permission_ids | array{int} | No | | Array of permission IDs (raw INT IDs, not hashids) |

**Example response**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "编辑员",
    "slug": "editor",
    "description": "内容编辑角色",
    "status": 1
  }
}
```

### 6.3 Update Role

```
PUT /admin/role/{id}
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| name | string | No | Role name |
| description | string | No | Description |
| status | int | No | 0=disabled, 1=enabled |
| permission_ids | array{int} | No | Array of permission IDs; when provided, role permissions are synced (overwritten) |

**Example response**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "内容编辑",
    "slug": "editor",
    "description": "更新后的描述",
    "status": 1
  }
}
```

The built-in `super_admin` role cannot be updated; the request returns 403 (`超级管理员不可编辑`).

### 6.4 Delete Role

```
DELETE /admin/role/{id}
```

- **Authentication**: JWT + RBAC
- **Sensitive operation**: requires password confirmation

**Request body**:
```json
{
  "password": "admin_password"
}
```

**Example response**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Deletion automatically detaches the role from all its permissions and users, then physically deletes the role record. The built-in `super_admin` role cannot be deleted; the request returns 403 (`超级管理员不可删除`).

### 6.5 Role Options

```
GET /admin/role/options
```

- **Authentication**: JWT only (`AdminAuth`, no RBAC permission required)

Returns the enabled roles the current admin can assign, for user-form dropdowns. Non-super-admins do not see roles beyond their own permission scope; super admins see every enabled role.

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": [
    { "id": "r1r2r3r4", "name": "超级管理员", "slug": "super_admin" },
    { "id": "r5r6r7r8", "name": "编辑员", "slug": "editor" }
  ]
}
```

| Field | Type | Description |
|------|------|------|
| id | string | Hashid-encrypted role ID |
| name | string | Role name |
| slug | string | Role slug |

An admin holding the enabled `super_admin` role has the wildcard permission `*` and can assign any role and manage any user.

## 7. Permission Management

Permissions use a tree structure (self-referencing parent_id) and come in three types. The list endpoint returns the full permission tree.

### 7.1 Permission Tree

```
GET /admin/permission
```

- **Authentication**: JWT + RBAC

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": [
    {
      "id": "p1p2p3p4",
      "parent_id": "0",
      "name": "用户管理",
      "slug": "/admin/user",
      "type": 1,
      "icon": "people",
      "path": "/user",
      "sort": 1,
      "created_at": "2026-01-01 00:00:00",
      "children": [
        {
          "id": "p5p6p7p8",
          "parent_id": "p1p2p3p4",
          "name": "用户列表",
          "slug": "/admin/user/index",
          "type": 2,
          "icon": "",
          "path": "/user/index",
          "sort": 1
        }
      ]
    }
  ]
}
```

| Field | Type | Description |
|------|------|------|
| id | string | Hashid encrypted |
| parent_id | string | Parent permission hashid; "0" denotes the root node |
| name | string | Permission name |
| slug | string | Permission identifier (route/button identifier) |
| type | int | 1=menu, 2=button, 3=API |
| icon | string | Menu icon (Material icon name) |
| path | string | Frontend route path |
| sort | int | Sort value (ascending) |
| children | array? | Child permission list (recursive); omitted when there are no children |

### 7.2 Create Permission

```
POST /admin/permission
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "parent_id": 0,
  "name": "系统设置",
  "slug": "/admin/config",
  "type": 1,
  "icon": "settings",
  "path": "/config",
  "sort": 99
}
```

| Field | Type | Required | Validation | Description |
|------|------|------|---------|------|
| parent_id | int | No | | Parent permission ID (raw INT type), default 0 |
| name | string | Yes | max:50 | Permission name |
| slug | string | Yes | max:100 | Permission identifier |
| type | int | Yes | in:1,2,3 | 1=menu, 2=button, 3=API |
| icon | string | No | | Menu icon, default empty |
| path | string | No | | Frontend route path, default empty |
| sort | int | No | | Sort value, default 0 |

**Example response**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "p9p0a1b2",
    "parent_id": "0",
    "name": "系统设置",
    "slug": "/admin/config",
    "type": 1,
    "icon": "settings",
    "path": "/config",
    "sort": 99
  }
}
```

### 7.3 Update Permission

```
PUT /admin/permission/{id}
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| name | string | No | Permission name |
| icon | string | No | Icon |
| path | string | No | Route path |
| sort | int | No | Sort value |

### 7.4 Delete Permission

```
DELETE /admin/permission/{id}
```

- **Authentication**: JWT + RBAC
- **Sensitive operation**: requires password confirmation

**Request body**:
```json
{
  "password": "admin_password"
}
```

**Example response**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Deletion cascades to all child permissions (records where `parent_id` equals the current permission ID) and detaches all role associations.

## 8. System Config

System configs are unique per `group` + `key` combination.

### 8.1 Config List

```
GET /admin/config
```

- **Authentication**: JWT + RBAC

**Query parameters**:

| Parameter | Type | Required | Default | Description |
|------|------|------|------|------|
| page | int | No | 1 | Page number |
| limit | int | No | 15 | Items per page |
| group | string | No | | Filter by config group |

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "c1c2c3c4",
        "group": "system",
        "key": "site_name",
        "value": "开放管理后台",
        "type": "string",
        "description": "站点名称",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 20,
    "page": 1,
    "limit": 15
  }
}
```

| Field | Type | Description |
|------|------|------|
| id | string | hashid |
| group | string | Config group (e.g. `system`, `email`, `storage`) |
| key | string | Config key |
| value | string | Config value |
| type | string | Value type hint (`string`, `integer`, `boolean`, `json`, etc.) |
| description | string | Config description |

### 8.2 Create Config

```
POST /admin/config
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| Field | Type | Required | Validation | Description |
|------|------|------|---------|------|
| group | string | Yes | max:100 | Config group |
| key | string | Yes | max:100 | Config key (unique within a group) |
| value | string | Yes | | Config value |
| type | string | No | | Value type, default `string` |
| description | string | No | | Config description, default empty |

**Example response**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "c5c6c7c8",
    "group": "email",
    "key": "smtp_host",
    "value": "smtp.example.com",
    "type": "string",
    "description": "SMTP 服务器地址"
  }
}
```

**Possible errors**:
- 422: Config item already exists (same group + key)

### 8.3 Update Config

```
PUT /admin/config/{id}
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| value | string | No | Updated config value |
| type | string | No | Updated value type |
| description | string | No | Updated description text |

### 8.4 Delete Config

```
DELETE /admin/config/{id}
```

- **Authentication**: JWT + RBAC
- **Sensitive operation**: requires password confirmation

**Request body**:
```json
{
  "password": "admin_password"
}
```

Physically deletes the config record.

## 9. Operation Logs

Operation logs are a read-only interface, automatically written by the `OperationLog` middleware on every POST/PUT/DELETE request. Stored fields include `user_id`, `action`, `method`, `path`, `ip`, `source`, `input`.

### 9.1 Operation Log List

```
GET /admin/log
```

- **Authentication**: JWT + RBAC

**Query parameters**:

| Parameter | Type | Required | Default | Description |
|------|------|------|------|------|
| page | int | No | 1 | Page number |
| limit | int | No | 15 | Items per page |
| user_id | int | No | | Exact filter by user ID (raw INT type) |
| action | string | No | | Exact filter by operation action |
| path | string | No | | Fuzzy filter by request path |
| start_date | string | No | | Start date (Y-m-d format) |
| end_date | string | No | | End date (Y-m-d format) |

**Example response**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "l1l2l3l4",
        "user_name": "admin",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/v1/auth/login",
        "ip": "192.168.1.1",
        "source": "web",
        "input": "{\"username\":\"admin\"}",
        "created_at": "2026-05-21 10:30:00"
      }
    ],
    "total": 500,
    "page": 1,
    "limit": 15
  }
}
```

| Field | Type | Description |
|------|------|------|
| id | string | hashid |
| user_name | string | Operating username (via the user relation; "系统" (system) for unauthenticated operations) |
| action | string | Operation description |
| method | string | HTTP method (POST/PUT/DELETE) |
| path | string | Request path |
| ip | string | Client IP |
| source | string | Request source |
| input | string | Request parameter JSON string (files excluded); `phone`, `email`, `id_card`, `mobile`, `address` are recursively masked before storage |
| created_at | string | Operation time (datetime) |

## 10. Profile

Profile endpoints require only JWT authentication (no RBAC check — the `AdminPermission` middleware should whitelist them).

### 10.1 Update Profile

```
PUT /admin/profile
```

- **Authentication**: JWT

**Request body**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| real_name | string | No | Real name, max 50 characters |
| phone | string | No | Phone number, max 20 characters (stored encrypted via Encryptable) |
| email | string | No | Email, max 254 characters (stored encrypted via Encryptable) |

**Example response**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13800138000",
    "email": "me@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00"
  }
}
```

`phone` and `email` are returned in plaintext; `password` and `id_card` are removed. Values exceeding the length limits return 422.

### 10.2 Change Password

```
PUT /admin/profile/password
```

- **Authentication**: JWT

**Request body**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| Field | Type | Required | Validation | Description |
|------|------|------|---------|------|
| old_password | string | Yes | | Current password |
| new_password | string | Yes | min:6, max:32 | New password |

**Example response**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

On success, all sessions of the user are revoked: the password-change watermark `user_pwd_changed:<id>` is written, registered access/refresh tokens are blacklisted, and rotation caches are cleared. Every device (including the current session) must log in again; the client should discard its local tokens.

**Possible errors**:
- 422: Please fill in both the old and new password
- 422: Incorrect old password
- 422: New password must be 6-32 characters

### 10.3 Logout

```
POST /admin/profile/logout
```

- **Authentication**: JWT

**Request body**: none (no requestBody; the token is read from the Authorization header)

**Example response**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

Logout logic: decode the JWT to get the remaining validity (exp - now), write the token's md5 hash to the Redis blacklist `jwt_blacklist:{md5}` with TTL = remaining validity. If the supplied refresh_token has a rotation cache entry, the cached access_token and refresh_token are blacklisted too and the cache key is removed. Blacklisted tokens are blocked in the `AdminAuth` middleware with a 401 response; if Redis cannot be read, the request returns 503 (fail closed).

Returns 401 when no token is provided. An expired/invalid token (decode throws an exception) is still treated as a successful logout.

## 11. Import & Export

### 11.1 Export Excel

```
POST /admin/export/excel
```

- **Authentication**: JWT + RBAC
- **Response type**: file download (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**Request body**:
```json
{
  "table": "admin_user",
  "columns": ["username", "real_name", "phone", "status", "created_at"],
  "conditions": {
    "status": 1
  },
  "title": "用户列表导出"
}
```

| Field | Type | Required | Default | Description |
|------|------|------|------|------|
| table | string | No | `admin_user` | Table to export. Supported: `admin_user`, `operation_log`, `admin_role`, `system_config` |
| columns | array{string} | No | | Field names of columns to export; empty exports all columns of the table |
| conditions | object | No | `{}` | Filter conditions; keys must be available columns of the target table and values must be scalar (string/number/bool/null), otherwise 422 |
| title | string | No | `数据导出` | Excel title (shown as the sheet name) |

**Supported tables and columns**:

| table | Available columns |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

Filter keys must be available columns of the target table and values must be scalar; unknown fields or array/object values return 422. Sensitive fields `phone`, `email`, and `id_card` are automatically masked on export.

Every cell is written as text via `setValueExplicit(..., TYPE_STRING)`, so values starting with `=`, `+`, `-` or `@` (such as usernames) are never executed as Excel formulas (formula-injection protection); numeric/date columns display as text in the exported file. Header and data borders are written once.

A single export is capped at 5,000 rows (the query uses `LIMIT 5001` as a probe); exceeding the cap returns HTTP 422. At 5,000 rows × 9 columns the export uses about 65MiB in a 128M worker, close to the safe ceiling; do not raise the row and column limits together. The first Excel row is frozen and auto-filter is enabled.

**Possible errors**:
- 422: Conditions must be an object / unsupported filter field / condition values must be scalar
- 422: Export exceeds 5,000 rows; narrow the filter (the HTTP status is also 422)

### 11.2 Export PDF

```
POST /admin/export/pdf
```

- **Authentication**: JWT + RBAC
- **Response type**: file download (`application/pdf`, A4 landscape)

**Request body**:
```json
{
  "type": "dashboard",
  "title": "管理仪表盘报告",
  "data": {
    "stats": [
      { "label": "用户总数", "value": "1280" }
    ]
  }
}
```

Or table mode:
```json
{
  "type": "table",
  "title": "用户列表",
  "data": {
    "columns": ["用户名", "真实姓名", "状态"],
    "rows": [
      ["admin", "管理员", "启用"],
      ["editor", "编辑员", "启用"]
    ]
  }
}
```

| Field | Type | Required | Default | Description |
|------|------|------|------|------|
| type | string | No | `table` | Export type: `table` / `dashboard` |
| title | string | No | `数据导出` | PDF title |
| data | object | No | `{}` | Export data |

With `type=dashboard`, `data` must contain a `stats` array (rendered as cards); with `type=table`, `data` must contain `columns` and `rows` arrays.

The PDF template includes copyright information and an export timestamp.

### 11.3 Import Users (Excel)

```
POST /admin/import/users
```

- **Authentication**: JWT + RBAC
- **Request type**: `multipart/form-data` (file upload)

**Form fields**:

| Field | Type | Required | Description |
|------|------|------|------|
| file | file | Yes | `.xlsx` or `.xls` format, max 5MB |
| role_ids | array{string} | No | Role hashid array assigned to every imported user (send `role_ids[]` in multipart); a non-super-admin can only assign roles within their own permission set and cannot assign `super_admin` |

**Excel column requirements**:

| Column | Required | Validation | Description |
|------|------|---------|------|
| username | Yes | min:3, max:50 | Username (unique; deduplicated case-insensitively within the file and against the database) |
| password | Yes | min:6, max:32 | Password (stored as bcrypt hash) |
| real_name | Yes | max:50 | Real name |
| phone | No | max:20 | Phone number |
| email | No | max:254 | Email |
| status | No | in:0,1 | Status, default 1 |

Row 1 contains column headers (case-insensitive); data starts at row 2. Files are limited to 5MB and 2,000 data rows (2,001 including the header). Existing usernames are prefetched once; a row that fails validation is recorded and the remaining rows continue. Database write errors are logged server-side only and return a generic failure reason, never SQL/exception details. Each row's user record and role assignments are written in one transaction; a failure rolls back that row, so no user is left without roles.

Before loading the workbook the server runs size guards: for `.xlsx` (a ZIP container), if the uncompressed total of `xl/**.xml` entries exceeds 8MB or the worksheet cell count exceeds 32,000 it returns 422, blocking zip bombs and OOM in the 128M worker. For both `.xls` and `.xlsx` it reads worksheet metadata before `load()` and rejects any sheet over 2,001 rows or with rows × columns above 32,000 cells, keeping the whole workbook out of memory; the actual data-row count is checked again after loading. Corrupt, unreadable or unparseable files return 422 with a readable message and never expose exception internals.

The whole import is serialized by the Redis lock `import_users_lock` (`SET NX EX 1800`, released by a Lua compare-and-delete). If an import is already running the request returns 409. Hashing 2,000 passwords with bcrypt is CPU-intensive and can take minutes in the worst case, hence the lock TTL; an abnormally terminated process releases it via TTL. If Redis is unavailable the import degrades to lock-free (fail-open).

**Possible errors**:
- 403: Cannot assign the super admin role / cannot assign the selected roles
- 409: Another import is already running; retry later
- 422: Only .xlsx or .xls files are supported
- 422: File size must not exceed 5MB
- 422: Excel content is too large or has too many cells to import (ZIP-expanded `xl/**.xml` exceeds 8MB or cells exceed 32,000)
- 422: Excel file cannot be read/parsed; confirm it is not corrupt and has the right format
- 422: Data rows must not exceed 2,000 (excluding the header)
- 422: Invalid role parameter format / role does not exist

**Example response**:
```json
{
  "code": 0,
  "message": "导入完成",
  "data": {
    "total": 10,
    "success": 8,
    "failed": 2,
    "errors": [
      { "row": 3, "reason": "用户名为空" },
      { "row": 7, "reason": "用户名 zhangsan 已存在" }
    ]
  }
}
```

| Field | Type | Description |
|------|------|------|
| total | int | Total rows (excluding the header row) |
| success | int | Successfully imported count |
| failed | int | Failed count |
| errors | array | Failure details; each entry contains row (Excel row number) and reason (failure reason) |

## 12. File Upload

```
POST /admin/upload
```

- **Authentication**: JWT + RBAC
- **Request type**: `multipart/form-data`

**Form fields**:

| Field | Type | Required | Description |
|------|------|------|------|
| file | file | Yes | File to upload |

**Allowed file types**: `jpg`, `jpeg`, `png`, `gif`, `pdf`, `xlsx`, `docx`
**Max file size**: 10MB

**Example response**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

Files are stored in date-based directories under `public/upload/{Y-m-d}/`, with filenames of `md5(uniqid) + original extension`. `url` is a relative path from the site root.

**Possible errors**:
- 422: Please select a file (none uploaded)
- 422: Unsupported file type
- 422: File size must not exceed 10MB
- 500: File upload failed (invalid file)

## 13. Response Headers

All endpoints (injected at the global middleware layer) include the following response headers:

| Header | Description |
|----|------|
| `X-RateLimit-Limit` | Rate limit cap (count) |
| `X-RateLimit-Remaining` | Remaining request count |
| `X-RateLimit-Reset` | Rate limit window reset timestamp |
| `Retry-After` | Only returned when rate limited; suggested seconds to wait |
| `X-Content-Type-Options` | `nosniff` (webman default, disables MIME sniffing) |
| `X-Frame-Options` | `DENY` (provided by webman's CORS middleware/base config) |

Rate limit details:
- Default global limit: 60/min per IP + path
- Login endpoint `/api/v1/auth/login`: 10/min
- Uses the Redis atomic sliding window algorithm (Lua ZSET) to avoid TOCTOU races
- Fails closed when Redis is unavailable: `/api/` and `/admin/` requests return 503 (JSON `code: 503`), while non-API paths such as `/health` and `/metrics` pass through so monitoring and probes keep working. `AdminAuth` also returns 503 when the blacklist read fails

Nginx static assets (`/assets/`, `/open-admin/assets/`, etc.) repeat `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` and `Cache-Control` at location level: an `add_header` inside a location replaces inherited headers, so each one must be repeated.

## 14. Authentication Flow

The complete authentication sequence:

```
1. Client requests POST /api/v1/captcha/generate
    ↓
   Server returns: key + type(click|slider|rotate) + base64 image + extra(type-specific data)
   
2. The user completes the captcha interaction (click/drag/rotate), and the client collects the answer
   
3. Client requests POST /api/v1/captcha/verify
   Request body: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // coordinate array
   - type=slider: clicks = 120                   // X offset
   - type=rotate: clicks = 315                   // rotation angle
    ↓
   Server:
   a. Reads captcha:key data from storage (TTL 300s)
   b. Validates the answer by type (click: Euclidean distance ≤18px / slider: ±4px / rotate: ±5°)
   c. On success → writes Redis `captcha_verified:{key}` = 1 (TTL 300s)
   d. On failure → returns 422, counter +1, key invalidated after 3 failures
    ↓
   Server returns: { valid: true/false }

4. Client requests POST /api/v1/auth/login
   Request body: { username, password(encrypted), captcha_key }
    ↓
   Server:
   a. Parameter validation → 422
   b. Atomically deletes captcha_verified:{key}; proceeds only when DEL returns 1; missing or concurrently consumed → 422
   c. Decrypts password: EncryptionService::decrypt(password) → plaintext
   d. Validates user credentials (password_verify) → 401; when the length is valid but the user does not exist or the hash is empty, a fixed cost-10 dummy hash is verified, flattening the timing difference
   e. Checks account status → 403/429
   g. Issues JWT (access + refresh) → 200
   h. Updates last_login_at / last_login_ip
    ↓
   Client stores: access_token, refresh_token, expires_in

5. Subsequent requests carry the JWT
   Request header: Authorization: Bearer <access_token>
    ↓
   AdminAuth middleware:
   a. Extracts the Bearer token
   b. Checks the blacklist (Redis jwt_blacklist:{md5}) → 401; Redis read failure → 503 (fail closed)
   c. Decodes the JWT, validates expiry → 401
   d. Sets $request->adminId = sub field
    ↓
   AdminPermission middleware:
   a. Resolves the permission identifier for resource routes
   b. Queries the user's enabled roles → role permissions (cache key `perm:<id>`, 60 seconds), performs matching
   c. An enabled super_admin role holder has wildcard permission `*` and is allowed through
   d. No permission → 403
    ↓
   Controller processes the request
    ↓
   Response + X-RateLimit-* headers

6. Refresh before the access token expires
   Client requests POST /api/v1/auth/refresh
   Request body: { refresh_token: "..." }
    ↓
   Server: validates token type, user status and password-change watermark → rotates tokens
   - Writes the new pair to refresh_rotation:{md5(old refresh_token)} with a 60-second TTL
   - Blacklists the old refresh_token immediately (TTL ≥ 60 seconds)
   - Retrying the same old token within 60 seconds → returns the cached pair unchanged (idempotent grace)
   - If either cached token is blacklisted → delete the cache and return 401
   - Rotation is serialized by refresh_lock:{md5(old refresh_token)} (SET NX EX 5 + Lua release);
     a concurrent request that misses the cache returns 409 and should retry later
   - Rotation keys are tracked in user_refresh_rotations:{userId} (TTL 120s) and cleared on logout/password change
   - HarmonyOS client: single-flight refresh for concurrent 401s; all requests await the same result
    ↓
   Client updates local tokens

7. Logout
   Client requests POST /admin/profile/logout
   Request header: Authorization: Bearer <access_token>
    ↓
   Server:
   a. Decodes the JWT to get the remaining TTL
   b. Writes to the Redis blacklist: jwt_blacklist:{md5(token)} = 1, TTL = remaining validity
   c. If a rotation cache exists for the supplied refresh_token, blacklists its cached tokens and deletes the key
   d. Returns success
```

### JWT Structure

- **access_token**: `{ sub: <user_id>, username: "<name>" }`, default TTL 7200 seconds (controlled by JWT config `default_expire`)
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`, default TTL 1209600 seconds (controlled by JWT config `refresh_expire`, i.e. 14 days)

### Security Management

- Passwords are stored as `PASSWORD_BCRYPT` hashes; login password transport uses RSA-2048 PKCS1v1.5 first, with AES-256-CBC-HMAC and plaintext fallback for legacy clients
- The login captcha marker `captcha_verified:{key}` is consumed atomically with `DEL`, so concurrent reuse of the same marker lets exactly one request through; an unknown username still performs a fixed cost-10 bcrypt check, preventing account enumeration
- Sensitive fields (phone, email, id_card) are transparently encrypted/decrypted at the database layer with `erikwang2013/encryptable`
- API-layer IDs are encrypted in transit with `erikwang2013/hashids` to avoid exposing the raw snowflake ID sequence
- SecurityFilter globally scans for XSS, SQL injection, path traversal, and command injection; 5 hits per IP in 60 seconds trigger a 15-minute temporary blacklist
- Sensitive operations (deleting users/roles/permissions/configs, batch delete, etc.) require password confirmation from the currently logged-in user; failed confirmations are counted under `pwd_confirm_fail:<id>` (5 per 300 seconds), after which confirmation is rejected for 5 minutes
- Session revocation (`SessionService`): password change, disable, delete, batch disable/delete write the `user_pwd_changed:<id>` watermark (TTL = refresh lifetime + 60 seconds), blacklist access/refresh tokens registered in `user_tokens:<id>` / `user_refresh_tokens:<id>` via `jwt_blacklist:<md5>`, and clear `user_refresh_rotations:<id>`, rotation caches and `perm:<id>`; the refresh endpoint checks the watermark so old refresh_tokens stop working after a password change
- Concurrent session limit: at most 3 valid tokens per user; when a 4th device logs in, the oldest token is forcibly blacklisted
- Account lockout: 5 consecutive login failures trigger a 15-minute lock; 429 is returned while locked. Existing users are counted under `account_lock:u:<userId>` / `login_fail:u:<userId>`; unknown usernames under `account_lock:n:<md5(lower(trim(username)))>` / `login_fail:n:<same>`; every key has a 900-second TTL
- Refresh rotation: replaying the old refresh_token within the 60-second grace returns the same pair; rotation is serialized by `refresh_lock:<md5>`, a blacklisted cached token deletes the cache and returns 401, and a concurrent cache miss returns 409; logout and password changes blacklist cached pairs and clear `user_refresh_rotations:<userId>`
- RBAC grant boundaries: an enabled `super_admin` role holder has wildcard permission `*`; when a non-super-admin assigns roles, every permission of the proposed roles (including disabled roles) must be in their own permission set, and `super_admin` cannot be assigned; managing a user requires all of the target's roles (including disabled ones) to be grantable, otherwise 403; `GET /admin/role/options` only returns assignable roles
- Super-admin protection: you cannot delete or change the status of the currently logged-in account; role changes and delete/disable/batch enable/disable all recheck the target boundary and the "last enabled super admin" inside the MySQL named lock `open_admin:last_super_admin`, returning 503 on timeout; the built-in `super_admin` role cannot be edited or deleted

### Middleware Architecture

Global middleware applies to all requests, executed in order:

```
Cors（跨域预处理 + 响应头）
  → Locale（Accept-Language 语言检测 / ?lang=zh_CN|en）
  → SecurityFilter（HTTP方法限制/请求体大小/Content-Type校验/XSS/SQL注入/路径遍历/命令注入/CSRF 攻击拦截）
  → RateLimit（Redis 滑动窗口限流 + 账号锁定：5次登录失败锁定15分钟）
  → AdminAuth（JWT 认证 + 黑名单，/admin 路由组）
  → AdminPermission（RBAC 鉴权 / Redis 60s 缓存，/admin 路由组）
  → OperationLog（POST/PUT/DELETE 自动记录，含来源端检测，/admin 路由组）
```

`/health` and `/api/docs` are public endpoints, passing only `Cors → SecurityFilter → RateLimit`.

Security enhancements:
- **Single-use captcha**: the login marker is consumed atomically with `DEL`, so concurrent reuse of the same key lets only one request through; unknown usernames run a dummy bcrypt check to flatten timing
- **Account lockout**: 5 consecutive login failures lock the account for 15 minutes; logins return 429 during the lock
- **Concurrent session limit**: at most 3 valid tokens per user; when exceeded, the oldest token is automatically blacklisted
- **Session revocation**: password change, disable, delete and batch disable/delete use a watermark plus blacklist plus rotation-cache clearing, invalidating old tokens immediately
- **RBAC boundaries**: `super_admin` has wildcard permission `*`; role grants and user management are checked against the actor's permission subset, including disabled roles
- **Refresh rotation lock**: `refresh_lock:<md5>` serializes rotation, a blacklisted cached token is rejected, and a concurrent cache miss returns 409
- **security.txt**: `GET /.well-known/security.txt` serves RFC 9116 standard security contact information
- **Nginx security config**: see `docs/nginx-security.conf` for a complete reverse-proxy security hardening example, including per-location CSP for `/open-admin/`, `/open-admin/en/` and `/apidoc/`

### Operation Source Detection

The OperationLog middleware auto-detects the client platform and writes it to the `source` field of the operation log:

| Platform | Detection method |
|------|---------|
| `ipados` | UA contains iPad |
| `macos` | UA contains Macintosh/Mac OS |
| `windows` | UA contains Windows |
| `linux` | UA contains Linux (not Android) |
| `ios` | UA contains iPhone / iOS / CFNetwork |
| `android` | UA contains Android |
| `harmonyos` | UA contains HarmonyOS / OpenHarmony, or explicitly declared via the `X-Client-Platform` header |
| `web` | Default (none of the above matched) |

> Two-level detection: `X-Client-Platform` request header (declared by native apps) → User-Agent auto-inference (fallback). The `source` field in the operation log query `GET /admin/log` is the client platform.

## 15. Deployment & Ops

### Quick Start (4 services)

The `docker-compose.yml` at the project root orchestrates Nginx, webman app, MySQL and Redis for local or single-host trials:

```bash
cp .env.docker .env
docker compose up -d
# Install wizard: http://localhost/install (DB host: mysql)
```

> **Local trial only**: the default passwords/keys in `.env.docker` are public, deliberately invalid placeholders; replace them before use and never expose this stack directly to the public internet. Use `deploy/` for production.

### Production (single machine, 6 services)

The production stack lives in `deploy/` and runs `nginx`, `app`, `init` (one-shot bootstrap), `mysql`, `redis` and `backup` (daily automatic backups) on one machine. No full-text search cluster is used. PHP is built via `Dockerfile` (based on `php:8.3-cli-alpine` with OPcache and the redis/zip/gd extensions; GD has FreeType and JPEG enabled); webman listens on 8791 inside the container network only, and Nginx exposes 80/443.

```bash
cd deploy
./generate-secrets.sh
docker compose up -d --build
```

- Per-service env isolation: `deploy/env/{app,init,mysql,redis,backup}.env` (directory `700`, files `600`); the master `deploy/.env` only holds compose interpolation values such as domain, ports and `APP_UID`/`APP_GID`. The app container never holds the MySQL root password or the bootstrap admin password; `app`/`init` run as that UID/GID so `deploy/data/{runtime,upload}` stays writable.
- `generate-secrets.sh` creates the RSA keypair, a self-signed TLS certificate and the admin password file `deploy/secrets/admin_password.txt`; the RSA private key goes into `app.env`. It is idempotent by default; `--regenerate` archives the old `.env`, `env/` and `secrets/` to `deploy/secrets/archive/<timestamp>/` and rotates application secrets only, preserving `ENCRYPTABLE_KEY`, the Hashids salt, the existing TLS certificate, database and admin passwords. Rotate the Hashids salt explicitly with `--rotate-hashids` (IDs in old links stop resolving); rotate the field-encryption key, TLS, database passwords and admin password explicitly with `--rotate-encryptable` (the old key is appended to `ENCRYPTION_PREVIOUS_KEYS`, so existing data remains readable; remove the old key only after re-encrypting the data), `--rotate-tls`, `--rotate-db` and `--reset-admin`.
- Replace `deploy/secrets/tls/fullchain.pem` and `privkey.pem` with real-domain certificates; port 80 only serves the ACME challenge path and redirects everything else to HTTPS; HSTS is commented out by default and should be enabled after a real certificate is configured.
- Logs: Nginx/MySQL write to stdout/stderr and Docker rotates at `10m × 3`; persistent directories: `deploy/data/{mysql,redis,runtime,upload,backups}`.
- Resource limits: `deploy/docker-compose.yml` sets `deploy.resources.limits` CPU, memory and pids per service (app 1.0/512M/256, init 0.5/256M/128, mysql 1.5/768M/512, redis 0.5/384M/128, nginx 0.5/128M/128, backup 0.5/256M/128); adjust for the host capacity. The Redis container's 384M pairs with `redis-server --maxmemory 256mb`, leaving 128M of headroom for AOF rewrite/fork, client buffers and fragmentation; change both values together (e.g. `--maxmemory 512mb` with a 640M container).
- The `backup` service uses the least-privilege `backup_reader` user (`SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES` plus `DELETE` on `erik_operation_log`) to run `mysqldump --single-transaction --no-tablespaces` daily into `deploy/data/backups`, and archives `deploy/data/upload` as `upload_<timestamp>.tar.gz`; both are kept for 30 days, and operation logs older than 180 days are deleted daily (at most 10,000 rows per run).
- Log cleanup: the app also registers a non-listening `log-cleanup` webman process (`count=1`) that performs a best-effort cleanup at startup and then daily deletes `operation_log` rows older than 180 days in batches of 10,000; failures are logged and do not conflict with the backup service.
- Offsite backup: backups stay on the DB host disk by default. `deploy/offsite-sync.sh` reads `OFFSITE_TARGET` from `deploy/.env` and supports rsync (`user@host:/path/`) and rclone (`rclone:remote:path`); it refuses an unset target, an empty backup directory or an unrecognized target, and excludes in-progress temp files. Host cron schedules it; no container calls it automatically.

### CI/CD

`.github/workflows/ci.yml` defines the GitHub Actions CI pipeline:
- `php -l` syntax check
- PHPUnit unit tests
- `flutter analyze` static analysis
- Docker image build + PHP extension checks (redis/zip/gd, GD FreeType/JPEG) + root and `deploy/` Compose config validation + `nginx -t` for both Nginx configs

### Database Backup

The production stack's `backup` service writes daily database and upload backups to `deploy/data/backups` (30-day retention) and deletes operation logs older than 180 days; the in-app `log-cleanup` process performs the same daily purge. Use `deploy/offsite-sync.sh` (reads `OFFSITE_TARGET`, supports rsync/rclone, scheduled by host cron) for offsite copies. The scripts in `database/backup/` are for a host with direct database access:
- `backup.sh` — mysqldump + gzip compressed backup, auto-clears backups older than 30 days
- `restore.sh` — interactive restore, lists existing backups for selection

### Nginx Security Config

For production deployments, refer to `docs/nginx-security.conf` for reverse-proxy security hardening.
