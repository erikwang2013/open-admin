> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
>
> [中文](DESIGN.md) | [English](DESIGN.en.md)

# Open Admin — Design Document

> For detailed Mermaid architecture diagrams, see [ARCHITECTURE.en.md](ARCHITECTURE.en.md) (renders automatically on GitHub/GitLab/VS Code).
>
> Static design diagrams (SVG): [System Architecture](diagrams/architecture.svg) · [Feature Design](diagrams/features.svg) · [Lifecycle](diagrams/lifecycle.svg)

## 1. System Architecture

> **Feature list**: auth (login/refresh/logout + account lockout + session limit) | dashboard (Redis cached) | user CRUD + batch + async import (queue/worker/status polling) | roles & permissions (RBAC) | system config | operation audit (8 platform sources) | files (upload + export + masking) | security (18 layers of defense) | ops (health/metrics/docs/Docker/CI)

```
┌──────────────────────────────────────────────────────────────┐
│                        客户端层                               │
│  ┌──────────────────────┐  ┌──────────────────────────────┐  │
│  │  Flutter Web (PC)    │  │  HarmonyOS ArkTS (Mobile)    │  │
│  │  管理后台 (桌面风格)   │  │  客户端 (手机/平板/2in1)      │  │
│  └──────────┬───────────┘  └──────────────┬───────────────┘  │
└─────────────┼──────────────────────────────┼─────────────────┘
              │        HTTPS / JSON          │
              │   Authorization: Bearer JWT  │
┌─────────────┼──────────────────────────────┼─────────────────┐
│             ▼                              ▼                  │
│  ┌──────────────────────────────────────────────────────┐    │
│  │                   API 网关层                          │    │
│  │  AdminAuth(认证) → AdminPermission(授权) → Controller │    │
│  └──────────────────────────┬───────────────────────────┘    │
│                             │                                  │
│  ┌──────────────────────────┼───────────────────────────┐    │
│  │              业务逻辑层 (Controller/Service)           │    │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐ │    │
│  │  │Dashboard │ │  User    │ │  Role    │ │ Export  │ │    │
│  │  │Controller│ │Controller│ │Controller│ │Controller│ │    │
│  │  └────┬─────┘ └────┬─────┘ └────┬─────┘ └────┬────┘ │    │
│  └───────┼────────────┼─────────────┼────────────┼──────┘    │
│          │            │             │            │            │
│  ┌───────┼────────────┼─────────────┼────────────┼──────┐    │
│  │       ▼            ▼             ▼            ▼       │    │
│  │                   Model 层                            │    │
│  │  ┌──────────────────────────────────────────────┐    │    │
│  │  │  Snowflake ID ← encryptable → Encryption     │    │    │
│  │  │  (主键生成)     (DB字段加密)   (API传输加密)    │    │    │
│  │  └──────────────────────────────────────────────┘    │    │
│  └──────────────────────────┬───────────────────────────┘    │
│                             │                                  │
│  ┌──────────────────────────┼───────────────────────────┐    │
│  │              数据存储层                                │    │
│  │  ┌──────────┐  ┌──────────┐                          │    │
│  │  │  MySQL   │  │  Redis   │                          │    │
│  │  │ (主存储)  │  │ (缓存)   │                          │    │
│  │  └──────────┘  └──────────┘                          │    │
│  └──────────────────────────────────────────────────────┘    │
│                       webman v2                               │
└──────────────────────────────────────────────────────────────┘
```

## 2. Backend Architecture

### 2.1 Layered Design

| Layer | Directory | Responsibilities |
|---|------|------|
| Routing | `config/route.php` | URL-to-controller mapping, middleware binding, versioned routes |
| Middleware | `app/middleware/` | Attack blocking (SecurityFilter), rate limiting (RateLimit), authentication (JWT), authorization (RBAC) |
| Controllers | 14 total: Dashboard/User/Role/Permission/Config/Log/Profile/Export/Import/Upload/Health/Docs (admin) + Captcha/Auth (API v1) | Request parameter validation, business logic calls, response formatting |
| Business services | `app/service/` | Reusable business logic (reserved) |
| Data models | `app/model/` | ORM mapping, relations, field encryption |
| Common utilities | `app/common/` | Hashids, Snowflake, Encryption services |

### 2.2 Request Lifecycle

```
客户端请求
  │
  ▼
webman HTTP Server (workerman)
  │
  ▼
Route 匹配
  │
  ▼
中间件链:
  Locale ──────────────► Accept-Language / ?lang= 语言检测
  │
  ▼
  SecurityFilter ──────► HTTP方法检查 → 405 (仅允许 GET/POST/PUT/DELETE/OPTIONS/HEAD)
  │                     XSS/SQL注入/路径遍历/命令注入/CSRF 攻击拦截 (403)
  ▼
  RateLimit ───────────► Redis 滑动窗口限流
  │ (失败返回 429 + Retry-After 头)
  ▼
  路由分发 ───────────► 版本号体现在 URL 前缀（/api/v1/...、/api/v2/...）
  │                     /api/v1 请求 → 静态注册路由组直连控制器
  │                     /admin 请求 → 继续下方中间件链
  ▼
  AdminAuth ──────────► JWT 验证，注入 $request->adminId
  │ (失败返回 401)
  ▼
  AdminPermission ────► RBAC 权限校验（Redis 60s 缓存）
  │ (失败返回 403)
  ▼
  OperationLog ───────► 操作日志记录 (POST/PUT/DELETE)，自动检测来源端
  │
  ▼
Controller::method()
  │
  ├─► 参数验证 (validator)
  ├─► 敏感操作确认 (confirmPassword)
  ├─► decodeId() — hashid → BIGINT
  ├─► Model 操作 (自动 encryptable 加解密)
  ├─► encodeId() — BIGINT → hashid
  └─► Response JSON
```

### 2.3 ID Lifecycle

```
生成 (Snowflake) → 存储 (MySQL BIGINT) → 传输 (Hashids 编码) → 外部 (hash 字符串)
                                                                    │
                            HashidsService::decode() ←──────────────┘
```

### 2.4 Data Encryption System

```
传输层 (encryption)     — AES-256-CBC，独立密钥
存储层 (encryptable)    — AES-128-ECB，独立密钥，Model $casts 自动处理
展示层 (mask)           — 手机号: 138****1234，邮箱: a***@example.com
```

## 3. Database Design

### 3.1 ER Relationships

```
erik_admin_user ──┬── erik_admin_user_role ──┬── erik_admin_role
  (用户)           │    (用户-角色关联)         │     (角色)
                  │                          │
                  │                    erik_admin_role_permission
                  │                     (角色-权限关联)
                  │                          │
                  │                          ▼
                  │                    erik_admin_permission
                  │                      (权限/菜单)
                  │
                  ▼
           erik_operation_log
             (操作日志)

erik_system_config (系统配置) — 独立表
```

### 3.2 Core Table Structures

| Table | Field count | Description |
|------|-------|------|
| `erik_admin_user` | 14 | Admin users; phone/email/id_card stored encrypted; soft delete supported |
| `erik_admin_role` | 7 | Roles; slug unique |
| `erik_admin_permission` | 10 | Permission tree (parent_id self-reference); type: 1=menu 2=button 3=API |
| `erik_admin_user_role` | 2 | User-role many-to-many pivot table |
| `erik_admin_role_permission` | 2 | Role-permission many-to-many pivot table |
| `erik_system_config` | 8 | Key-value config; group+key jointly unique |
| `erik_operation_log` | 9 | Operation audit logs (incl. client source) |

### 3.3 Primary Key Conventions

- Type: `BIGINT UNSIGNED NOT NULL`
- Property: **non-auto-increment**, generated at the application layer by the Snowflake algorithm
- Benefits: globally unique, distributed-friendly, monotonically increasing for index-friendly access, does not expose business volume
- Config: datacenter_id(0-31) + worker_id(0-31), supports 1024 concurrent nodes

## 4. API Design

### 4.1 URL Conventions

```
公开接口:  /api/v1/captcha/{generate|verify}
           /api/v1/auth/{login|refresh}

管理端:   /admin/{resource}[/{hashid}]
          /admin/export/{excel|pdf}

资源路由:
  GET    /admin/user          → 列表
  POST   /admin/user          → 创建
  GET    /admin/user/{hashid} → 详情
  PUT    /admin/user/{hashid} → 更新
  DELETE /admin/user/{hashid} → 删除（需密码确认）

系统配置:  /admin/config[/{hashid}]
操作日志:  /admin/log
个人中心:  /admin/profile[/password|/logout]
Import:   POST /admin/import/users (async, 202 + job_id)
Status:   GET  /admin/import/status/{job} (job creator only)
Upload:   /admin/upload
批量:     /admin/user/batch/{destroy|status}
文档:     /api/docs     (OpenAPI 3.0)
调试页:   /apidoc/      (单文件同源调试界面，读取 /api/docs)
健康:     /health
```

### 4.2 API Versioning Strategy

The API version is embedded in the URL prefix (`/api/v1/...`, `/api/v2/...`); **no request header is used**. `config/route.php` statically registers a route group for each version that maps directly to the versioned controllers; version dispatch goes through no middleware.

| Mechanism | Description |
|------|------|
| URL prefix | The version is fixed as the first path segment: `/api/v1/...`, `/api/v2/...` |
| Routing | `config/route.php` statically registers `Route::group('/api/v1', ...)` mapping directly to controllers |
| Directory | Controllers organized by version: `app/api/{version}/controller/` |
| Ops endpoints | `/api/docs`, `/health`, `/metrics`, etc. carry no version prefix |

Extension example — adding a v2 API:
1. Create `app/api/v2/controller/AuthController.php`
2. Register a `Route::group('/api/v2', ...)` route group in `config/route.php`

```bash
# v1
curl http://localhost:8791/api/v1/auth/login

# v2 (after adding the new version)
curl http://localhost:8791/api/v2/auth/login
```

### 4.3 Rate Limiting Strategy

Based on the Redis Sorted Set sliding window algorithm, executed as atomic Lua scripts:

| Endpoint | Limit |
|------|------|
| Default | 60/min per IP/route |
| POST /api/v1/auth/login | 10/min |

Exceeding the limit returns 429; response headers include X-RateLimit-Limit / Remaining / Reset / Retry-After. When Redis fails, `/api/` and `/admin/` return 503 (fail closed), while non-API paths such as `/health` and `/metrics` pass through so monitoring stays available.

### 4.4 Unified Response

```json
{
  "code": 0,
  "message": "success",
  "data": { ... }
}
```

| code | Meaning | Trigger |
|------|---------|------|
| 0 | Success | Normal response |
| 400 | Bad request | Incorrect request format |
| 401 | Unauthenticated | Token missing/expired/invalid |
| 403 | Forbidden | User role lacks the required permission |
| 404 | Not found | Resource not found |
| 422 | Validation failed | Form params violate rules / password confirmation failed / export over 5,000 rows |
| 500 | Server error | Unexpected exception |
| 503 | Service unavailable | Redis failure causes auth or rate limiting to fail closed for `/api/` and `/admin/` |

### 4.5 Authentication Flow (with Click Captcha)

```
客户端                               服务端
  │                                    │
  │  ① POST /api/v1/captcha/generate     │ captcha_create('click')
  │◄── {key, image(base64), targets}  │
  │                                    │
  │  ② 用户点击图中文字位置              │
  │                                    │
  │  ③ POST /api/v1/captcha/verify       │ captcha_verify()
  │     {key, type, clicks}           │ 成功 → SET captcha_verified:{key}
  │◄── {valid: true}                  │        TTL 300s
  │                                    │
  │  ④ POST /api/v1/auth/login           │ 原子 DEL captcha_verified:{key}
  │     {username, password,          │ 返回 1 才继续（并发仅一次成功）
  │      captcha_key}                 │ 长度合法后 password_verify()（未知用户
  │────────────────────────────────►  │ 用固定 cost-10 占位哈希抹平时间差）
  │                                    │ jwt()->create()
  │◄── {access_token, refresh_token}  │
  │                                    │
  │  ⑤ GET /admin/dashboard           │
  │     Authorization: Bearer xxx     │
  │────────────────────────────────►  │ AdminAuth → AdminPermission
  │◄── 200 {dashboard data}           │
```

Refresh: `POST /api/v1/auth/refresh` rotates the token pair, immediately blacklists the old refresh_token, and caches the new pair for 60 seconds under `refresh_rotation:<md5>`; resubmitting the old token within the grace period returns the same pair. Concurrent rotation is serialized by `refresh_lock:<md5>` (`SET NX EX 5` + Lua release); a concurrent cache miss returns 409, and a blacklisted cached token deletes the cache and returns 401. Before refreshing, the password-change watermark `user_pwd_changed:<userId>` is checked. Password change, disable, delete and batch disable/delete call `SessionService::revokeUser()` to write the watermark, blacklist access/refresh tokens and clear rotation and permission caches; role changes clear only the permission cache. Logout blacklists cached tokens and cleans up via `user_refresh_rotations:<userId>`; the HarmonyOS client uses single-flight refresh for concurrent 401s.

### 4.6 Permission Model (RBAC)

```
  用户 ──┬── 角色 ──┬── 权限
  User     Role      Permission
                 │
                 ├── type=1: 菜单 (控制侧边栏可见)
                 ├── type=2: 按钮 (控制页面内操作)
                 └── type=3: API  (控制接口访问)

  权限标识格式: {method}.{path}
  例: get.admin/user  post.admin/user  delete.admin/user
  超级管理员: 持有启用 super_admin 角色 → RbacService 返回 ['*']（跳过权限检查）
```

Grant boundaries: when a non-super-admin assigns roles, every permission of the granted roles (including disabled roles) must be a subset of their own permissions, and `super_admin` cannot be assigned; when managing a user, all roles held by the target (including disabled ones) must be grantable, otherwise 403. `GET /admin/role/options` returns only the enabled roles the current admin can assign.

Super-admin protection: the currently logged-in account cannot be deleted or have its status changed; non-super-admins cannot modify/disable/delete a super-admin target or assign `super_admin`. After delete/disable/role changes at least one enabled super administrator must remain (otherwise 422). The check and write are serialized by the MySQL named lock `open_admin:last_super_admin` (`GET_LOCK` 3s / released in `finally`), covering update (status/roles), delete, batch delete and batch enable/disable; target roles are re-read and boundaries re-checked inside the lock, and a timeout or database error returns 503. The built-in `super_admin` role cannot be edited or deleted (403).

### 4.7 Password Re-Confirmation for Sensitive Operations

Sensitive operations such as deleting users, roles, permissions, config and batch deletes require the current user's password in the request body for identity re-verification. Failures are counted in `pwd_confirm_fail:<id>` at 5 per 300 seconds; after that, confirmation is refused for 5 minutes:

```
客户端                           服务端
  │                                │
  │  DELETE /admin/user/{hashid}  │
  │  { password: "******" }       │
  │────────────────────────────►  │
  │                                │ confirmPassword(adminId, password)
  │                                │ → 密码错误返回 422
  │                                │ → 密码正确继续执行
  │◄── 200 { code: 0 }           │
```

The frontend shows a confirmation dialog before triggering a delete operation, collects the user's password, then sends the request.

## 5. Frontend Design

### 5.1 Flutter Web Admin Panel

```
┌────────────────────────────────────────────────┐
│  Header (56px)                                 │
│  ☰ 菜单按钮           🔔 消息  👤 管理员  ▼    │
├──────────┬─────────────────────────────────────┤
│ Sidebar  │  Content Area                       │
│ (64/240) │                                     │
│          │  ┌──────────────┐ ┌──────────┐     │
│ 📊 仪表盘│  │ 统计卡片×4    │ │ 趋势图   │     │
│ 👥 用户  │  └──────────────┘ └──────────┘     │
│ 🔒 角色  │  ┌──────┐ ┌────────────────┐       │
│ ⚙ 配置  │  │饼图  │ │ 最近操作日志    │       │
│ 📋 日志  │  └──────┘ └────────────────┘       │
└──────────┴─────────────────────────────────────┘
```

Features: collapsible sidebar, Material 3 dual theme, high-density data tables, dialog popups, hover interactions

### 5.2 HarmonyOS Mobile Client

Page routing:

| Page | Route | Description |
|------|------|------|
| LoginPage | `pages/LoginPage` | Username/password + click captcha login |
| DashboardPage | `pages/DashboardPage` | Stats cards + recent operations |
| UserListPage | `pages/UserListPage` | User list with search + pull-to-refresh + scroll-to-load-more |
| UserDetailPage | `pages/UserDetailPage` | Add/Edit/View/Delete (AlertDialog confirmation) |
| ProfilePage | `pages/ProfilePage` | Profile, logout (AlertDialog confirmation) |

Data flow: Page ← DataService ← ApiService (JWT Bearer) ← HTTP ← webman

## 6. Security Design

### 6.1 Defense in Depth

| Layer | Measure |
|------|------|
| Method restriction | SecurityFilter HTTP method whitelist; only GET/POST/PUT/DELETE/OPTIONS/HEAD allowed, non-standard methods return 405 |
| Attack blocking | SecurityFilter middleware detects/blocks XSS, SQL injection, path traversal, command injection, CSRF |
| Human verification | Click captcha, mandatory for login; the marker is consumed by an atomic `DEL`, so only one concurrent request succeeds |
| Account lockout | 5 consecutive login failures lock the account for 15 min; 429 returned while locked |
| Login timing | When the password length is valid, bcrypt always runs; a fixed cost-10 placeholder hash is used for unknown users or empty hashes, flattening the account-enumeration timing difference |
| Session limit | Max 3 concurrent tokens per user; oldest token auto-blacklisted when exceeded |
| Session revocation | Password change/disable/delete/batch disable-delete: revoke before the database write — watermark + access/refresh token blacklist + rotation and permission cache cleanup; a revocation failure aborts the write with 503 |
| Rate limiting | RateLimit middleware, Redis sliding window, atomic Lua; `/api/` and `/admin/` fail closed (503) when Redis fails |
| Auth state | AdminAuth verifies that the account exists with `status=1` and rejects tokens issued at or before the password-change watermark; any blacklist, account-query or watermark read failure returns 503, never allowing a token whose state cannot be confirmed |
| Super-admin protection | The current account cannot be targeted; non-super-admins cannot assign/target super admins; delete/disable/role changes keep at least one enabled super admin; the built-in role cannot be edited or deleted |
| Grant boundaries | Role grants and target management are checked against the permission subset, disabled roles included; `/admin/role/options` returns only assignable roles |
| Import/export | Import 5MB / 2,000 rows / 32,000 cells / ZIP 8MB precheck / Redis lock 409 / async queue + single worker / status polling / per-row transaction, fail-closed when Redis is down; export 5,000 rows, PDF caps of 30,000 cells / 5,000 rows / 200 cards, scalar condition validation, all cells written as strings against formula injection |
| CSP | Content-Security-Policy header restricts resource origins, prevents XSS and data injection |
| Operation confirmation | Sensitive ops such as delete require the current user's password, throttled at 5 failures per 300 seconds |
| Transport | HTTPS + JWT Bearer Token; login password over RSA-2048 (AES/plaintext legacy fallback) |
| API IDs | Hashids encrypted, real IDs cannot be reverse-engineered externally |
| Request body | AES-256-CBC sensitive field encryption |
| Database | BIGINT primary keys (no auto-increment exposed) |
| Database | AES-128-ECB encrypted storage of sensitive fields |
| Authentication | JWT HS256, 2h expiry + refresh token 14d, refresh rotation with 60s grace |
| Authorization | RBAC with method.path granularity; enabled super_admin role grants wildcard `*` |
| Audit | OperationLog records all operations (incl. automatic client source detection), with recursive PII masking of phone/email/id_card and similar |

### 6.2 Key Management

```
JWT_SECRET_KEY      → injected via environment, 64-character random string
HASHIDS_SALT        → unique salt; rotate globally if leaked
ENCRYPTION_KEY      → API transport encryption key, 32 bytes
ENCRYPTABLE_KEY     → DB storage encryption key, independent of the transport key
RSA_PRIVATE_KEY     → private key for the login-password transport public key (generated by the deploy script)
```

### 6.3 Sensitive Data Protection

| Scenario | Field | Measure |
|------|------|------|
| List display | phone | Masked: 138****1234; 7-10 digit all-numeric landlines keep first/last two (e.g. 1****67) |
| List display | email | Masked: a***@example.com |
| Detail view | phone/email | Requires decryption endpoint |
| Excel export | phone/email | Exported masked |
| PDF export | All fields | Masked + non-removable copyright watermark |
| Storage | phone/email/id_card | Encrypted to ciphertext via encryptable |

## 7. Export Design

### 7.1 Excel Export

```
请求: POST /admin/export/excel { table, columns, conditions, title }
  → 校验 conditions：对象、已知列、标量值，否则 HTTP 422
  → fetchExportData() 查询数据 (LIMIT 5001 探测)
  → 超过 5000 行 → HTTP 422（不生成文件）
  → 脱敏敏感字段
  → PhpSpreadsheet 构建（setValueExplicit TYPE_STRING 防公式注入，
    蓝底白字表头 + 边框只写一次 + 冻结首行 + 自动筛选）
  → 写入 runtime/tmp/ → download 响应
```

5,000 rows × 9 columns take about 65MiB under the 128M worker limit, already close to the safe ceiling; the row and column caps must not both be raised.

Import: `POST /admin/import/users` is limited to 5MB, 2,000 rows and 32,000 cells, with optional uniform `role_ids` assignment. After validation the endpoint takes the Redis lock `import_users_lock` (`SET NX EX 1800`), stores the file under `runtime/imports/` with mode `0600`, writes job metadata and enqueues the job, returning HTTP 202 `{job_id, status:'queued'}`; a running import returns 409. A single `import-worker` process (`count=1`) consumes the Redis queue serially; `GET /admin/import/status/{job}` (job creator only) returns `queued`/`processing`/`completed`/`failed` plus the result. A Redis failure returns 503 while taking the lock and 500 on enqueue failure, cleaning up the file/metadata/lock — imports are never run without the lock; on restart the worker recovers unfinished jobs from the processing list, a 60-second grace period prevents duplicate consumption, and the lock TTL is the final backstop. `.xlsx` files get a ZIP decompression precheck first (`xl/**.xml` > 8MB or more than 32,000 cells returns 422); before `IOFactory::load()`, `ImportController` checks every sheet with `createReaderForFile` + `setReadDataOnly(true)` + `listWorksheetInfo()` and returns 422 immediately if any sheet exceeds 2,001 rows or row × column exceeds 32,000 cells; after loading it re-checks with `getHighestDataRow()`. Each row validates username 3-50, password 6-32, real_name ≤50, phone ≤20, email ≤254 and status 0/1, with the row number and a generic reason recorded on failure; user and role links are written in one transaction and rolled back per row. Bcrypt over 2,000 rows is CPU-intensive, so the lock TTL is sized for the worst case.

### 7.2 PDF Export

```
请求: POST /admin/export/pdf { type: table|dashboard, title, data }
  → buildPdfHtml() HTML + 内联CSS + 页头版权 + 页脚不可移除版权
  → Dompdf 渲染 A4 横向
  → 写入 runtime/tmp/ → download 响应
```

Before rendering, the PDF payload is validated: `columns`/`rows`/`stats` must be arrays, every row must be an array, and columns/cells must be scalar (`null` renders as empty), with caps of 30,000 columns, 30,000 header + cell values, 5,000 rows and 200 stats entries; exceeding any cap returns 422 so a large payload cannot exhaust the 128M worker.

## 8. Deployment Architecture

### 8.1 Recommended Topology

```
Nginx (:80/:443, TLS + static site, starts after the app health check passes) → app (webman :8791) → MySQL + Redis
init (one-shot): schema + admin user; backup (daily): mysqldump + upload tar into deploy/data/backups, deletes operation logs older than 180 days
Data persists to deploy/data/; logs go to stdout/stderr and are rotated by Docker
```

### 8.2 Docker Compose (Single-Machine Production)

The production stack lives in `deploy/` and runs 6 services on one machine. No full-text search cluster is used.

| Service | Image/Build | Description |
|------|----------|------|
| `nginx` | nginx:alpine | 80/443, TLS, static site, reverse proxy + Gzip; waits for the app health check; logs to stdout/stderr |
| `app` | Local `Dockerfile` build | 8791 (container network only), PHP 8.3 + OPcache + redis/zip/gd (FreeType/JPEG) + webman, runs as host UID/GID |
| `init` | Same app image | One-shot schema + admin creation, then exits |
| `mysql` | mysql:8.0 | Primary database with data on a host bind mount; error/slow logs to stderr |
| `redis` | redis:7-alpine | Cache / rate limiting / captcha, AOF persistence |
| `backup` | mysql:8.0 | Least-privilege daily `mysqldump` + upload tar into `deploy/data/backups`, 30-day retention; deletes operation logs older than 180 days |

First deployment:

```bash
cd deploy
./generate-secrets.sh          # master .env, per-service env files, keys, self-signed cert and admin password
docker compose up -d --build
```

- Per-service env isolation: `deploy/env/{app,init,mysql,redis,backup}.env` (directory `700`, files `600`); the master `deploy/.env` only holds domain, ports and `APP_UID`/`APP_GID`. The app container never holds the MySQL root password or the bootstrap admin password.
- `generate-secrets.sh` generates all keys; it is idempotent by default. `--regenerate` archives the old `.env`, `env/` and `secrets/` to `deploy/secrets/archive/<timestamp>/` and rotates application secrets (JWT/ENCRYPTION/RSA/Redis) by default, preserving `HASHIDS_SALT`, `ENCRYPTABLE_KEY`, the existing TLS certificate, database and admin passwords; rotate Hashids explicitly with `--rotate-hashids` (IDs in old links stop resolving), and use `--rotate-encryptable`, `--rotate-tls`, `--rotate-db` and `--reset-admin` to rotate those explicitly.
- `app`/`init` run as `${APP_UID:-1000}:${APP_GID:-1000}`; the script writes `id -u`/`id -g` into the master `.env`, keeping `deploy/data/{runtime,upload}` writable.
- Replace `deploy/secrets/tls/fullchain.pem` and `privkey.pem` with real-domain certificates; the admin password is in `deploy/secrets/admin_password.txt`; HSTS is off by default and should be enabled after a real certificate is configured.
- Persistent directories: `deploy/data/{mysql,redis,runtime,upload,backups}`; Docker rotates logs at `10m × 3`.
- Per-service CPU/memory/pids limits: app 1.0/512M/256, init 0.5/256M/128, mysql 1.5/768M/512, redis 0.5/384M/128, nginx 0.5/128M/128, backup 0.5/256M/128; the Redis container's 384M pairs with `--maxmemory 256mb --maxmemory-policy noeviction`, leaving 128M for AOF rewrite/fork and fragmentation. `noeviction` keeps security keys (blacklist, watermark, permission cache) from being evicted under memory pressure; when memory is full, dependent paths fail closed and alerting fires.
- `nginx` waits for the app `/health` check via `depends_on.app.condition: service_healthy` before starting, avoiding 502s during container startup.
- An in-app `log-cleanup` process (no listening port, `count=1`) performs a best-effort cleanup at startup and then daily deletes `operation_log` rows older than 180 days, 10,000 rows per batch and at most 10 batches (100,000 rows) per run; it and the backup-service cleanup are fallbacks for each other. The `import-worker` process (no listening port, `count=1`) consumes the Redis import queue serially, recovers unfinished jobs on startup and removes expired job files.
- Nginx repeats `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` and `Cache-Control` inside the static asset locations; CI runs `nginx -t` on both `deploy/nginx/default.conf` and `docs/nginx-security.conf`.
- `backup` uses the least-privilege `backup_reader` user (`SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES` on the database plus `DELETE` on `erik_operation_log`). Backups default to the MySQL host/disk; offsite copies are run by host cron via `deploy/offsite-sync.sh` (reads `OFFSITE_TARGET` in `deploy/.env`, supports rsync and rclone).

The root `docker-compose.yml` is only the 4-service (nginx/app/mysql/redis) local quick-start stack; its `.env.docker` defaults are public, deliberately invalid placeholders, so replace them before use and never expose it publicly — use `deploy/` for production.

### 8.3 CI/CD

GitHub Actions CI is defined in `.github/workflows/ci.yml`:
- PHP syntax check (`php -l`)
- PHPUnit unit tests
- Flutter static analysis (`flutter analyze`)
- Docker image build + PHP extension checks (redis/zip/gd, GD FreeType/JPEG) + root and `deploy/` Compose config validation + `nginx -t` for both Nginx configs (release depends on the php/flutter/docker jobs)

### 8.4 Database Backup

The production stack's `backup` service writes daily database and upload backups to `deploy/data/backups` (30-day retention) and deletes operation logs older than 180 days; the in-app `log-cleanup` process performs the same daily purge (at most 10 batches × 10,000 rows per run). Offsite copies are synced by host cron via `deploy/offsite-sync.sh` (reads `OFFSITE_TARGET`).
`database/backup/backup.sh` — mysqldump + gzip backup, auto-clears backups older than 30 days (host with direct database access).
`database/backup/restore.sh` — interactive selection and restore of backups.

### 8.5 Monitoring

The `GET /metrics` endpoint (`MetricsController`) exposes 5 gauge metrics in Prometheus text format: total HTTP requests, active users, database/Redis connection status, and memory usage.

### 8.6 Environment Requirements

| Component | Minimum version | Recommended config |
|------|---------|---------|
| PHP | 8.3+ | 8.3+ OPcache enabled |
| MySQL | 8.0+ | Single-machine 8.0 with host-directory persistence |
| Redis | 6.x | 7.x with AOF persistence |
| Nginx | 1.20+ | Reverse proxy + gzip + SSL |
| Flutter SDK | 3.41+ | Latest stable |
| HarmonyOS | API 12 | DevEco Studio 5.x |
