# API 参考文档

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. 概述

开放管理后台 (open-admin) 基于 webman v2 构建，提供 RESTful JSON API。所有管理端接口需要 JWT 认证与 RBAC 权限校验，公开接口的版本号体现在 URL 前缀中（如 `/api/v1/auth/login`），路由到对应的版本化控制器。

- **基础 URL**: `http://localhost:8791`（本地直连 webman；Docker 部署经 Nginx 访问 `http://localhost/api/v1/...`）
- **API 版本**: 版本号体现在 URL 前缀中（`/api/v1/...`、`/api/v2/...`），不使用请求头
- **语言**: 通过 `Accept-Language` 头或 `?lang=zh_CN|en` 参数切换（默认 zh_CN），Locale 中间件自动检测

> **端点总览**: 认证(4) | 仪表盘(1) | 用户(7) | 角色(4) | 权限(4) | 配置(4) | 日志(1) | 个人中心(3) | 导入导出(3) | 上传(1) | 运维(4: health/metrics/docs/security.txt) | 共 36 端点
- **认证**: `Authorization: Bearer <token>`（JWT）
- **响应格式**: `{ "code": 0, "message": "success", "data": {...} }`
- **文档端点**: `GET /api/docs` 返回 OpenAPI 3.0 JSON 规范；`/apidoc/` 提供轻量同源调试界面（单个 HTML，无外部资源）

### 请求要求

- 仅允许 `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD` 方法，使用其他 HTTP 方法（如 TRACE、CONNECT、PATCH）会返回 405
- 所有 `POST` / `PUT` 请求必须设置 `Content-Type: application/json`（文件上传除外），否则返回 415
- 请求体大小不得超过 10MB，否则返回 413
- 安全过滤器对所有请求输入进行 XSS、SQL 注入、路径遍历、命令注入扫描，命中返回 403
- 连续 5 次登录失败将触发账号锁定（15 分钟），锁定期间登录请求返回 429
- 同一用户最多同时持有 3 个有效 Token，超出时最旧 Token 自动加入黑名单

## 2. 错误码

| code | 含义 | 触发场景 |
|------|------|---------|
| 0 | 成功 | |
| 400 | 请求参数错误 | 请求格式不正确 |
| 401 | 未认证 | Token 缺失 / 过期 / 已在黑名单 |
| 403 | 无权限 / 安全拦截 | RBAC 权限不足 / SecurityFilter 命中 |
| 404 | 资源不存在 | 查询/更新/删除的目标不存在 |
| 405 | 请求方法不允许 | 仅允许 GET/POST/PUT/DELETE/OPTIONS/HEAD，非标准方法直接拒绝 |
| 413 | 请求体过大 | Content-Length 超过 10MB |
| 415 | 不支持的媒体类型 | POST/PUT 请求 Content-Type 非 JSON 且非文件上传 |
| 422 | 参数验证失败 | 必填字段缺失、格式不符、业务校验不通过 |
| 429 | 请求过于频繁 | RateLimit 触发 / 账号锁定（连续5次登录失败锁定15分钟） |
| 500 | 服务器内部错误 | |
| 503 | 服务暂时不可用 | Redis 不可用导致 `/api/`、`/admin/` 鉴权或限流失败关闭 |

## 3. 公开端点


### 3.1 健康检查

```
GET /health
```

- **认证**: 无需
- **限流**: 无

**响应示例**:
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

`database`、`redis` 取值: `"ok"` | `"unavailable"`。

### 3.2 API 文档

```
GET /api/docs
```

- **认证**: 无需
- **限流**: 全局默认 (60次/分钟)
- **响应**: OpenAPI 3.0.3 JSON 规范，包含所有端点定义、参数和 Schema

浏览器打开 `/apidoc/` 可直接调试本文档中的接口：页面从同源 `GET /api/docs` 读取规范，支持填写 Bearer Token、路径/查询参数与 JSON 请求体，并在页面内展示状态码与响应。该界面为单个静态 HTML，不依赖外部 CDN 或构建步骤。

### 3.3 生成验证码

```
POST /api/v1/captcha/generate
```

- **认证**: 无需
- **限流**: 全局默认 (60次/分钟)

**请求体**:
```json
{
  "difficulty": "medium"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| difficulty | string | 否 | `easy` / `medium` / `hard`，默认 `medium` |

**响应示例** — 点击型 (`type: "click"`):
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

**响应示例** — 滑块型 (`type: "slider"`):
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

**响应示例** — 旋转型 (`type: "rotate"`):
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

| 字段 | 类型 | 说明 |
|------|------|------|
| key | string | 验证码标识，校验时回传 |
| type | string | 验证码类型：`click` / `slider` / `rotate` |
| image | string | base64 data URI 图片 |
| extra | object | 类型相关附加数据（见下方） |

**`extra` 按类型说明**:

| type | extra 字段 | 类型 | 说明 |
|------|-----------|------|------|
| click | targets | array | 点击目标，含 `order`(顺序) `text`(提示文字) `x` `y`(坐标) |
| slider | x, y | int | 缺口左上角坐标 (基于 300×200 画布) |
| slider | puzzle_w, puzzle_h | int | 拼图片宽高 |
| slider | puzzle | string | 拼图片 base64 data URI |
| rotate | angle | int | 正确旋转角度 (0-359)，需旋转 `360-angle` 使图片回正 |

### 3.4 校验验证码

```
POST /api/v1/captcha/verify
```

- **认证**: 无需
- **限流**: 全局默认 (60次/分钟)

**请求体** — 点击型 (`type: "click"`):
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

**请求体** — 滑块型 (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**请求体** — 旋转型 (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| key | string | 是 | 验证码 key，由 generate 返回 |
| type | string | 是 | 验证码类型，必须与 generate 返回的 `type` 一致 |
| clicks | 变体 | 是 | 答案数据，格式随 type 变化（见下方） |

**`clicks` 按类型说明**:

| type | clicks 类型 | 说明 | 误差容限 |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | 点击坐标数组，按 order 顺序 | 18px 半径 |
| slider | `int` | 滑块 X 轴偏移量 | ±4px |
| rotate | `int` | 旋转角度 (0-359) | ±5° |

**响应示例**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

验证通过后，后端将 `captcha_verified:{key}` 写入 Redis（TTL 300s），登录接口据此放行。
验证失败时 `code` 为 422，`message` 为 `"验证失败，请重试"`，`data.valid` 为 `false`。

### 3.5 登录

```
POST /api/v1/auth/login
```

- **认证**: 无需
- **限流**: 10 次/分钟（按 IP + 路径）

**请求体**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| 字段 | 类型 | 必填 | 验证规则 | 说明 |
|------|------|------|---------|------|
| username | string | 是 | min:3, max:50 | 用户名 |
| password | string | 是 | min:6, max:32 (明文) | RSA-2048 PKCS1v1.5 加密后 Base64 编码（兼容 AES/明文回退） |
| captcha_key | string | 是 | | 验证码 key（需先通过 `/api/v1/captcha/verify` 校验） |

### 密码加密协议

使用 **RSA-2048 非对称加密**，公钥存放于前端代码（可安全暴露），私钥仅服务端持有。

```
加密流程 (客户端):
  RSA 公钥 (PEM) → PKCS1v1.5 加密 → Base64 编码 → 传输

解密流程 (服务端，逐级回退):
  1. RSA 私钥解密 → 成功且为合法 UTF-8 → 使用解密结果
  2. AES-256-CBC-HMAC 解密 → 成功 → 使用解密结果（旧客户端兼容）
  3. 明文回退 → 直接使用原始输入
```

公钥通过 `GET /api/v1/auth/public-key` 在登录前动态获取，Flutter 客户端同时保留内置公钥作为请求失败时的回退。私钥仅存储在环境变量 `RSA_PRIVATE_KEY` 中（直连部署为 `.env`，`deploy/` 为 `env/app.env`），不可泄露。

> AES 对称加密为旧版兼容方案，待所有客户端迁移至 RSA 后将移除。

#### 登录公钥

```
GET /api/v1/auth/public-key
```

- **认证**: 无需
- **限流**: 全局默认 (60次/分钟)

**响应示例**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "public_key": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----\n"
  }
}
```

服务端未配置 `RSA_PRIVATE_KEY` 时返回 `code: 404`。`deploy/generate-secrets.sh --regenerate` 会轮换 RSA 密钥对：换钥后请确认该端点可达；仍依赖内置回退公钥的旧客户端需重新构建。

**响应示例**:
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

| 字段 | 类型 | 说明 |
|------|------|------|
| access_token | string | JWT 访问令牌 |
| refresh_token | string | JWT 刷新令牌 |
| expires_in | int | 访问令牌有效期（秒），默认 7200 |
| user.id | string | hashid 加密的用户 ID |
| user.username | string | 用户名 |
| user.real_name | string | 真实姓名 |

**可能的错误**:
- 422: 参数验证失败（缺少必填字段、格式不符）
- 422: 请先完成验证码校验（captcha_key 未通过 `/api/v1/captcha/verify`）
- 401: 用户名或密码错误
- 403: 账号已被禁用
- 429: 账号已被锁定，请15分钟后再试（连续5次登录失败触发）

### 3.6 刷新令牌

```
POST /api/v1/auth/refresh
```

- **认证**: 无需
- **限流**: 全局默认 (60次/分钟)

**请求体**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| refresh_token | string | 是 | 登录时获取的 refresh_token |

**响应示例**:
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

刷新成功会同时返回新的 access_token 和 refresh_token，旧令牌自动失效。刷新时会更新用户最后登录时间和 IP。

为容忍并发请求与客户端重试，旧 refresh_token 轮换后有 60 秒宽限期：服务端以旧令牌的 md5 为键缓存新令牌对 60 秒，宽限期内再次提交同一旧令牌会原样返回缓存的新令牌对，不会重复轮换；超过宽限期后旧令牌按黑名单处理并返回 401。客户端应串行刷新；HarmonyOS 客户端对并发 401 采用单飞（single-flight）刷新，多个请求共享同一次刷新结果。

**可能的错误**:
- 422: 缺少刷新令牌
- 401: 刷新令牌无效或已过期

### 3.7 Prometheus 监控指标

```
GET /metrics
```

- **认证**: 无需
- **限流**: 无
- **响应格式**: Prometheus text format (`text/plain; version=0.0.4`)

公开 Prometheus 监控指标端点，供 Grafana/Prometheus 抓取。

**响应示例**:
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

| 指标名 | 类型 | 说明 |
|------|------|------|
| `openadmin_http_requests_total` | gauge | 累计 HTTP 请求总数 |
| `openadmin_active_users` | gauge | 当前活跃用户数（24小时内登录） |
| `openadmin_db_connection_status` | gauge | 数据库连接状态，1=正常, 0=异常 |
| `openadmin_redis_connection_status` | gauge | Redis 连接状态，1=正常, 0=异常 |
| `openadmin_memory_usage_bytes` | gauge | PHP 进程当前内存使用量（bytes） |

## 4. 仪表盘

所有管理端接口挂载在 `/admin` 分组下，经过 `AdminAuth`（JWT 认证）、`AdminPermission`（RBAC 权限校验）、`OperationLog`（操作记录）三个中间件。

### 4.1 仪表盘数据

```
GET /admin/dashboard
```

- **认证**: JWT + RBAC
- **缓存**: Redis 5 分钟

**响应示例**:
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

| stats 字段 | 类型 | 说明 |
|------|------|------|
| label | string | 指标名称 |
| value | string | 指标数值（字符串类型） |
| icon | string | Material 图标名 |
| color | string | 卡片色值 |
| trend | float? | 日环比增长率（百分比），仅"用户总数"有此字段 |

| trends 字段 | 类型 | 说明 |
|------|------|------|
| dates | array{string} | 最近 30 天日期序列 |
| series | array{object} | 趋势线数据，每条含 name（名称）、data（数值数组）、color（颜色） |

## 5. 用户管理

所有用户管理接口返回的 `id` 均为 hashid 加密字符串。密码字段已在响应中排除。手机号和邮箱在列表接口中脱敏展示，在详情接口中返回明文（数据库加密字段由 Encryptable trait 自动解密）。

### 5.1 用户列表

```
GET /admin/user
```

- **认证**: JWT + RBAC

**查询参数**:

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|------|------|
| page | int | 否 | 1 | 页码 |
| limit | int | 否 | 15 | 每页条数 |
| keyword | string | 否 | | 搜索关键词，匹配用户名和真实姓名 |
| status | int | 否 | | 状态筛选，0=禁用，1=启用 |

**响应示例**:
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

| 字段 | 类型 | 说明 |
|------|------|------|
| id | string | hashid 加密的用户 ID |
| username | string | 用户名 |
| real_name | string | 真实姓名 |
| phone | string | 脱敏手机号（`138****5678` 格式） |
| email | string | 脱敏邮箱（`a***@example.com` 格式） |
| status | int | 1=启用, 0=禁用 |
| last_login_at | string | 最后登录时间 (datetime) |
| created_at | string | 创建时间 (datetime) |

### 5.2 创建用户

```
POST /admin/user
```

- **认证**: JWT + RBAC

**请求体**:
```json
{
  "username": "newuser",
  "password": "123456",
  "real_name": "新用户",
  "phone": "13800138000",
  "email": "newuser@example.com",
  "status": 1
}
```

| 字段 | 类型 | 必填 | 验证规则 | 说明 |
|------|------|------|---------|------|
| username | string | 是 | min:3, max:50 | 用户名（唯一） |
| password | string | 是 | min:6, max:32 | 密码（bcrypt 存储） |
| real_name | string | 是 | max:50 | 真实姓名 |
| phone | string | 否 | | 手机号（Encryptable 加密存储） |
| email | string | 否 | | 邮箱（Encryptable 加密存储） |
| status | int | 否 | in:0,1 | 状态，默认 1（启用） |

**响应示例**:
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

**可能的错误**:
- 422: 用户名已存在
- 422: 参数验证失败（必填字段缺失）

### 5.3 用户详情

```
GET /admin/user/{id}
```

- **认证**: JWT + RBAC
- **路径参数**: `{id}` 为 hashid 加密的用户 ID

**响应示例**:
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
    "last_login_at": "2026-05-21 10:30:00",
    "last_login_ip": "192.168.1.1",
    "created_at": "2026-01-01 00:00:00",
    "updated_at": "2026-05-20 08:00:00"
  }
}
```

详情接口中 `phone` 和 `email` 返回明文（数据库中为加密存储，Encryptable cast 自动解密），不脱敏。`password` 和 `id_card` 始终不在响应中。

**可能的错误**:
- 404: 用户不存在

### 5.4 更新用户

```
PUT /admin/user/{id}
```

- **认证**: JWT + RBAC
- **路径参数**: `{id}` 为 hashid 加密的用户 ID

**请求体**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| real_name | string | 否 | 真实姓名，不传保持原值 |
| password | string | 否 | 新密码，为空字符串或不传则不修改 |
| phone | string | 否 | 手机号 |
| email | string | 否 | 邮箱 |
| status | int | 否 | 0=禁用, 1=启用 |

**响应示例**:
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

**可能的错误**:
- 404: 用户不存在

### 5.5 删除用户

```
DELETE /admin/user/{id}
```

- **认证**: JWT + RBAC
- **路径参数**: `{id}` 为 hashid 加密的用户 ID
- **敏感操作**: 需要密码二次确认

**请求体**:
```json
{
  "password": "admin_password"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| password | string | 是 | 当前登录用户密码（二次确认） |

**响应示例**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

执行软删除（Eloquent SoftDeletes），数据标记 deleted_at 而不物理删除。

**可能的错误**:
- 404: 用户不存在
- 422: 敏感操作需要输入密码确认（password 为空）
- 422: 密码验证失败（密码不匹配）
- 422: 不能删除当前登录账号
- 422: 系统必须保留至少一名启用的超级管理员

### 5.6 批量删除用户

```
POST /admin/user/batch/destroy
```

- **认证**: JWT + RBAC
- **敏感操作**: 需要密码二次确认

**请求体**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| ids | array{string} | 是 | hashid 加密的用户 ID 数组 |
| password | string | 是 | 当前登录用户密码（二次确认） |

**响应示例**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

执行软删除，`data.count` 为实际删除数量。

**可能的错误**:
- 422: 请选择要删除的用户（ids 为空）
- 422: 无效的 ID（hashid 解码失败）
- 422: 密码验证失败
- 422: 不能删除当前登录账号（ids 包含当前用户）
- 422: 系统必须保留至少一名启用的超级管理员

### 5.7 批量启用/禁用用户

```
POST /admin/user/batch/status
```

- **认证**: JWT + RBAC

**请求体**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| ids | array{string} | 是 | hashid 加密的用户 ID 数组 |
| status | int | 是 | 0=禁用, 1=启用 |

**响应示例**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

message 根据 status 值动态变化为 `"批量启用成功"` 或 `"批量禁用成功"`。

**可能的错误**:
- 422: 请选择用户（ids 为空）
- 422: 状态值无效（status 不是 0 或 1）
- 422: 不能修改当前登录账号的状态
- 422: 系统必须保留至少一名启用的超级管理员（禁用最后一名启用的超级管理员时）

## 6. 角色管理

### 6.1 角色列表

```
GET /admin/role
```

- **认证**: JWT + RBAC

**查询参数**:

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|------|------|
| page | int | 否 | 1 | 页码 |
| limit | int | 否 | 15 | 每页条数 |

**响应示例**:
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

| 字段 | 类型 | 说明 |
|------|------|------|
| id | string | hashid 加密的角色 ID |
| name | string | 角色名称 |
| slug | string | 角色标识（唯一，用于权限判断） |
| description | string | 角色描述 |
| status | int | 1=启用, 0=禁用 |
| users_count | int | 拥有该角色的用户数量 |

### 6.2 创建角色

```
POST /admin/role
```

- **认证**: JWT + RBAC

**请求体**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| 字段 | 类型 | 必填 | 验证规则 | 说明 |
|------|------|------|---------|------|
| name | string | 是 | max:50 | 角色名称 |
| slug | string | 是 | max:50 | 角色标识 |
| description | string | 否 | | 角色描述，默认空字符串 |
| status | int | 否 | | 状态，默认 1 |
| permission_ids | array{int} | 否 | | 权限 ID 数组（原始 INT ID，非 hashid） |

**响应示例**:
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

### 6.3 更新角色

```
PUT /admin/role/{id}
```

- **认证**: JWT + RBAC

**请求体**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 否 | 角色名称 |
| description | string | 否 | 描述 |
| status | int | 否 | 0=禁用, 1=启用 |
| permission_ids | array{int} | 否 | 权限 ID 数组，传入则同步（覆盖）角色权限 |

**响应示例**:
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

内置 `super_admin` 角色禁止更新，返回 403（`超级管理员不可编辑`）。

### 6.4 删除角色

```
DELETE /admin/role/{id}
```

- **认证**: JWT + RBAC
- **敏感操作**: 需要密码二次确认

**请求体**:
```json
{
  "password": "admin_password"
}
```

**响应示例**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

删除时自动解除角色与所有权限、用户的关联关系，然后物理删除角色记录。内置 `super_admin` 角色禁止删除，返回 403（`超级管理员不可删除`）。

## 7. 权限管理

权限采用树形结构（parent_id 自关联），分为三种类型。列表接口返回完整权限树。

### 7.1 权限树

```
GET /admin/permission
```

- **认证**: JWT + RBAC

**响应示例**:
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

| 字段 | 类型 | 说明 |
|------|------|------|
| id | string | hashid 加密 |
| parent_id | string | 父权限 hashid，"0" 表示根节点 |
| name | string | 权限名称 |
| slug | string | 权限标识（路由/按钮标识） |
| type | int | 1=菜单, 2=按钮, 3=接口 |
| icon | string | 菜单图标（Material 图标名） |
| path | string | 前端路由路径 |
| sort | int | 排序值（升序） |
| children | array? | 子权限列表（递归），无子节点时不包含此字段 |

### 7.2 创建权限

```
POST /admin/permission
```

- **认证**: JWT + RBAC

**请求体**:
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

| 字段 | 类型 | 必填 | 验证规则 | 说明 |
|------|------|------|---------|------|
| parent_id | int | 否 | | 父权限 ID（原始 INT 类型），默认 0 |
| name | string | 是 | max:50 | 权限名称 |
| slug | string | 是 | max:100 | 权限标识 |
| type | int | 是 | in:1,2,3 | 1=菜单, 2=按钮, 3=接口 |
| icon | string | 否 | | 菜单图标，默认空 |
| path | string | 否 | | 前端路由路径，默认空 |
| sort | int | 否 | | 排序值，默认 0 |

**响应示例**:
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

### 7.3 更新权限

```
PUT /admin/permission/{id}
```

- **认证**: JWT + RBAC

**请求体**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 否 | 权限名称 |
| icon | string | 否 | 图标 |
| path | string | 否 | 路由路径 |
| sort | int | 否 | 排序值 |

### 7.4 删除权限

```
DELETE /admin/permission/{id}
```

- **认证**: JWT + RBAC
- **敏感操作**: 需要密码二次确认

**请求体**:
```json
{
  "password": "admin_password"
}
```

**响应示例**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

删除时级联删除所有子权限（`parent_id` = 当前权限 ID 的记录），同时解除与所有角色的关联。

## 8. 系统配置

系统配置以 `group` + `key` 组合唯一。

### 8.1 配置列表

```
GET /admin/config
```

- **认证**: JWT + RBAC

**查询参数**:

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|------|------|
| page | int | 否 | 1 | 页码 |
| limit | int | 否 | 15 | 每页条数 |
| group | string | 否 | | 按配置分组筛选 |

**响应示例**:
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

| 字段 | 类型 | 说明 |
|------|------|------|
| id | string | hashid |
| group | string | 配置分组（如 `system`、`email`、`storage`） |
| key | string | 配置键 |
| value | string | 配置值 |
| type | string | 值类型提示（`string`、`integer`、`boolean`、`json` 等） |
| description | string | 配置说明 |

### 8.2 创建配置

```
POST /admin/config
```

- **认证**: JWT + RBAC

**请求体**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| 字段 | 类型 | 必填 | 验证规则 | 说明 |
|------|------|------|---------|------|
| group | string | 是 | max:100 | 配置分组 |
| key | string | 是 | max:100 | 配置键（同分组内唯一） |
| value | string | 是 | | 配置值 |
| type | string | 否 | | 值类型，默认 `string` |
| description | string | 否 | | 配置说明，默认空 |

**响应示例**:
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

**可能的错误**:
- 422: 配置项已存在（同 group + key）

### 8.3 更新配置

```
PUT /admin/config/{id}
```

- **认证**: JWT + RBAC

**请求体**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| value | string | 否 | 更新配置值 |
| type | string | 否 | 更新值类型 |
| description | string | 否 | 更新说明文字 |

### 8.4 删除配置

```
DELETE /admin/config/{id}
```

- **认证**: JWT + RBAC
- **敏感操作**: 需要密码二次确认

**请求体**:
```json
{
  "password": "admin_password"
}
```

物理删除配置记录。

## 9. 操作日志

操作日志为只读接口，由 `OperationLog` 中间件在每次 POST/PUT/DELETE 请求时自动写入，存储字段包括 `user_id`、`action`、`method`、`path`、`ip`、`source`、`input`。

### 9.1 操作日志列表

```
GET /admin/log
```

- **认证**: JWT + RBAC

**查询参数**:

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|------|------|
| page | int | 否 | 1 | 页码 |
| limit | int | 否 | 15 | 每页条数 |
| user_id | int | 否 | | 按用户 ID 精确筛选（原始 INT 类型） |
| action | string | 否 | | 按操作动作精确筛选 |
| path | string | 否 | | 按请求路径模糊筛选 |
| start_date | string | 否 | | 开始日期 (Y-m-d 格式) |
| end_date | string | 否 | | 结束日期 (Y-m-d 格式) |

**响应示例**:
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

| 字段 | 类型 | 说明 |
|------|------|------|
| id | string | hashid |
| user_name | string | 操作用户名（通过 user 关联获取，未登录操作显示"系统"） |
| action | string | 操作动作描述 |
| method | string | HTTP 方法（POST/PUT/DELETE） |
| path | string | 请求路径 |
| ip | string | 客户端 IP |
| source | string | 请求来源 |
| input | string | 请求参数 JSON 字符串（不包含文件） |
| created_at | string | 操作时间 (datetime) |

## 10. 个人中心

个人中心接口仅需 JWT 认证（不需要 RBAC 权限校验——`AdminPermission` 中间件应将其加入白名单）。

### 10.1 更新个人信息

```
PUT /admin/profile
```

- **认证**: JWT

**请求体**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| real_name | string | 否 | 真实姓名 |
| phone | string | 否 | 手机号（Encryptable 加密存储） |
| email | string | 否 | 邮箱（Encryptable 加密存储） |

**响应示例**:
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

响应中 `phone` 和 `email` 返回明文，`password` 和 `id_card` 已剔除。

### 10.2 修改密码

```
PUT /admin/profile/password
```

- **认证**: JWT

**请求体**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| 字段 | 类型 | 必填 | 验证规则 | 说明 |
|------|------|------|---------|------|
| old_password | string | 是 | | 当前密码 |
| new_password | string | 是 | min:6, max:32 | 新密码 |

**响应示例**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**可能的错误**:
- 422: 请填写旧密码和新密码
- 422: 旧密码错误
- 422: 新密码长度 6-32 位

### 10.3 登出

```
POST /admin/profile/logout
```

- **认证**: JWT

**请求体**: 无（无 requestBody，从 Authorization 头读取 token）

**响应示例**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

登出逻辑：解码 JWT 获取剩余有效期 (exp - now)，将该 token 的 md5 哈希写入 Redis 黑名单 `jwt_blacklist:{md5}`，TTL = 剩余有效期。若请求携带的 refresh_token 存在轮换缓存，同时拉黑缓存中的 access_token 与 refresh_token 并删除缓存键。黑名单中的 token 在 `AdminAuth` 中间件中被拦截，返回 401；Redis 读取失败时返回 503（失败关闭）。

无 token 时返回 401。token 已过期/无效时（解码抛出异常）仍视为登出成功。

## 11. 导入导出

### 11.1 导出 Excel

```
POST /admin/export/excel
```

- **认证**: JWT + RBAC
- **响应类型**: 文件下载（`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`）

**请求体**:
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

| 字段 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|------|------|
| table | string | 否 | `admin_user` | 导出表名。支持: `admin_user`, `operation_log`, `admin_role`, `system_config` |
| columns | array{string} | 否 | | 导出列字段名数组，为空则导出该表全部列 |
| conditions | object | 否 | `{}` | 筛选条件，key-value 对，值不为空时用于 WHERE |
| title | string | 否 | `数据导出` | Excel 标题（显示为 Sheet 名称） |

**支持的表和列**:

| table | 可用列 |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

敏感字段 `phone`、`email`、`id_card` 在导出时自动脱敏处理。单次最多导出 5000 行（查询 `LIMIT 5001` 探测），超过时返回 HTTP 422。Excel 首行冻结、自动筛选。

**可能的错误**:
- 422: 导出数据超过 5000 行，请缩小筛选范围（HTTP 状态码同为 422）

### 11.2 导出 PDF

```
POST /admin/export/pdf
```

- **认证**: JWT + RBAC
- **响应类型**: 文件下载（`application/pdf`，A4 横向）

**请求体**:
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

或者表格模式:
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

| 字段 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|------|------|
| type | string | 否 | `table` | 导出类型：`table` / `dashboard` |
| title | string | 否 | `数据导出` | PDF 标题 |
| data | object | 否 | `{}` | 导出数据 |

`type=dashboard` 时 `data` 需包含 `stats` 数组（卡片形式渲染）；`type=table` 时 `data` 需包含 `columns` 和 `rows` 数组。

PDF 模板包含版权信息和导出时间戳。

### 11.3 导入用户 (Excel)

```
POST /admin/import/users
```

- **认证**: JWT + RBAC
- **请求类型**: `multipart/form-data`（文件上传）

**表单字段**:

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| file | file | 是 | `.xlsx` 或 `.xls` 格式，最大 5MB |

**Excel 列要求**:

| 列名 | 必填 | 说明 |
|------|------|------|
| username | 是 | 用户名（唯一） |
| password | 是 | 密码（bcrypt 哈希存储） |
| real_name | 是 | 真实姓名 |
| phone | 否 | 手机号 |
| email | 否 | 邮箱 |
| status | 否 | 状态，默认 1 |

第 1 行为列标题（大小写不敏感），第 2 行起为数据。单个文件不超过 5MB，数据行（不含表头）最多 2000 行。服务端在加载工作簿前先读取工作表元数据，任一工作表超过 2001 行立即返回 422，避免整本工作簿进入内存；加载后仍保留行数上限作为二次防御。

**可能的错误**:
- 422: 仅支持 .xlsx 或 .xls 文件
- 422: 文件大小不能超过 5MB
- 422: 数据行数不能超过 2000 行（不含表头）

**响应示例**:
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

| 字段 | 类型 | 说明 |
|------|------|------|
| total | int | 总行数（不含标题行） |
| success | int | 成功导入数 |
| failed | int | 失败条数 |
| errors | array | 失败详情，每条含 row（Excel 行号）和 reason（失败原因） |

## 12. 文件上传

```
POST /admin/upload
```

- **认证**: JWT + RBAC
- **请求类型**: `multipart/form-data`

**表单字段**:

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| file | file | 是 | 上传文件 |

**允许的文件类型**: `jpg`, `jpeg`, `png`, `gif`, `pdf`, `xlsx`, `docx`
**最大文件大小**: 10MB

**响应示例**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

文件按日期分目录存储于 `public/upload/{Y-m-d}/`，文件名为 `md5(uniqid) + 原始扩展名`。`url` 为相对于站点根路径的相对路径。

**可能的错误**:
- 422: 请选择文件（未上传）
- 422: 不支持的文件类型
- 422: 文件大小不能超过 10MB
- 500: 文件上传失败（文件无效）

## 13. 响应头

所有接口（全局中间件层注入）均包含以下响应头：

| 头 | 说明 |
|----|------|
| `X-RateLimit-Limit` | 限流上限（次数） |
| `X-RateLimit-Remaining` | 剩余请求次数 |
| `X-RateLimit-Reset` | 限流窗口重置时间戳 |
| `Retry-After` | 仅限流触发时返回，建议等待秒数 |
| `X-Content-Type-Options` | `nosniff`（由 webman 默认，禁止 MIME 嗅探） |
| `X-Frame-Options` | `DENY`（由 webman 的 CORS 中间件/基础配置提供） |

限流详情:
- 默认全局限制: 60 次/分钟 / IP+路径
- 登录端点 `/api/v1/auth/login`: 10 次/分钟
- 使用 Redis 原子化滑动窗口算法（Lua ZSET），避免 TOCTOU 竞态
- Redis 不可用时失败关闭：`/api/`、`/admin/` 请求返回 503（JSON `code: 503`）；`/health`、`/metrics` 等非 API 路径放行，保证监控与探针可用。`AdminAuth` 读取黑名单失败时同样返回 503

Nginx 静态资源（`/assets/`、`/open-admin/assets/` 等）在 location 级重复注入 `X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`、`Permissions-Policy` 与 `Cache-Control`：location 内的 `add_header` 会覆盖继承值，因此必须逐条重复。

## 14. 认证流程

完整的认证时序：

```
1. 客户端请求 POST /api/v1/captcha/generate
    ↓
   服务端返回: key + type(click|slider|rotate) + base64 图片 + extra(类型相关数据)
   
2. 用户交互完成验证码操作（点击/拖拽/旋转），客户端收集答案
   
3. 客户端请求 POST /api/v1/captcha/verify
   请求体: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // 坐标数组
   - type=slider: clicks = 120                   // X 偏移量
   - type=rotate: clicks = 315                   // 旋转角度
    ↓
   服务端:
   a. 从存储读取 captcha:key 数据（TTL 300s）
   b. 按 type 校验答案（click: 欧氏距离 ≤18px / slider: ±4px / rotate: ±5°）
   c. 校验通过 → 写入 Redis `captcha_verified:{key}` = 1 (TTL 300s)
   d. 校验失败 → 返回 422，计数 +1，超过 3 次 key 作废
    ↓
   服务端返回: { valid: true/false }

4. 客户端请求 POST /api/v1/auth/login
   请求体: { username, password(加密), captcha_key }
    ↓
   服务端:
   a. 参数校验 → 422
   b. 检查 captcha_verified:{key} 是否存在 → 422
   c. 删除 captcha_verified:{key}（一次性使用）
   d. 解密密码: EncryptionService::decrypt(password) → 明文
   e. 校验用户凭证 (password_verify) → 401
   f. 检查账号状态 → 403/429
   g. 签发 JWT (access + refresh) → 200
   h. 更新 last_login_at / last_login_ip
    ↓
   客户端保存: access_token, refresh_token, expires_in

5. 后续请求携带 JWT
   请求头: Authorization: Bearer <access_token>
    ↓
   AdminAuth 中间件:
   a. 提取 Bearer token
   b. 检查黑名单 (Redis jwt_blacklist:{md5}) → 401；Redis 读取失败 → 503（失败关闭）
   c. 解码 JWT，校验过期 → 401
   d. 设置 $request->adminId = sub 字段
    ↓
   AdminPermission 中间件:
   a. 对资源路由解析权限标识
   b. 查询用户角色 → 角色权限，进行匹配
   c. 无权限 → 403
    ↓
   Controller 处理请求
    ↓
   Response + X-RateLimit-* 头

6. Access Token 过期前刷新
   客户端请求 POST /api/v1/auth/refresh
   请求体: { refresh_token: "..." }
    ↓
   服务端: 校验令牌类型、用户状态与密码变更水位 → 轮换令牌
   - 新令牌对写入 refresh_rotation:{md5(旧 refresh_token)}，TTL 60 秒
   - 旧 refresh_token 立即加入黑名单（TTL ≥ 60 秒）
   - 60 秒内重试同一旧令牌 → 返回缓存的同一对新令牌（幂等宽限）
   - HarmonyOS 客户端：并发 401 单飞刷新，多个请求等待同一次结果
    ↓
   客户端更新本地令牌

7. 登出
   客户端请求 POST /admin/profile/logout
   请求头: Authorization: Bearer <access_token>
    ↓
   服务端:
   a. 解码 JWT 获取剩余 TTL
   b. 写入 Redis 黑名单: jwt_blacklist:{md5(token)} = 1, TTL = 剩余有效期
   c. 若存在对应 refresh_token 的轮换缓存，同时拉黑缓存中的令牌并删除该键
   d. 返回成功
```

### JWT 结构

- **access_token**: `{ sub: <user_id>, username: "<name>" }`，默认 TTL 7200 秒（由 JWT 配置 `default_expire` 控制）
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`，默认 TTL 1209600 秒（由 JWT 配置 `refresh_expire` 控制，即 14 天）

### 安全管理

- 密码以 `PASSWORD_BCRYPT` 哈希存储
- 密码传输层使用 AES-256-CBC-HMAC 加密（客户端加密 → 服务端解密），兼容明文回退
- 敏感字段（phone, email, id_card）使用 `erikwang2013/encryptable` 在数据库层透明加解密
- API 层 ID 使用 `erikwang2013/hashids` 加密传输，避免暴露原始 snowflake ID 序列
- SecurityFilter 全局扫描 XSS、SQL 注入、路径遍历、命令注入，同 IP 5次/60秒临时黑名单 15 分钟
- 敏感操作（删除用户、角色、权限、配置）需要当前登录用户密码二次确认
- 并发会话限制：同一用户最多 3 个有效 Token，第 4 个设备登录时最旧 Token 被强制加入黑名单
- 账号锁定：连续 5 次登录失败触发 15 分钟账号锁定，锁定期间返回 429
- 超级管理员保护：不能删除当前登录账号或修改其状态；删除/禁用操作不能移除最后一名启用的超级管理员；内置 `super_admin` 角色禁止编辑/删除

### 中间件架构

全局中间件对所有请求生效，按序执行：

```
Cors（跨域预处理 + 响应头）
  → Locale（Accept-Language 语言检测 / ?lang=zh_CN|en）
  → SecurityFilter（HTTP方法限制/请求体大小/Content-Type校验/XSS/SQL注入/路径遍历/命令注入/CSRF 攻击拦截）
  → RateLimit（Redis 滑动窗口限流 + 账号锁定：5次登录失败锁定15分钟）
  → AdminAuth（JWT 认证 + 黑名单，/admin 路由组）
  → AdminPermission（RBAC 鉴权 / Redis 60s 缓存，/admin 路由组）
  → OperationLog（POST/PUT/DELETE 自动记录，含来源端检测，/admin 路由组）
```

`/health` 和 `/api/docs` 为公开端点，仅经过 `Cors → SecurityFilter → RateLimit`。

安全增强：
- **账号锁定**：连续 5 次登录失败，账号自动锁定 15 分钟，期间登录返回 429
- **并发会话限制**：同一用户最多 3 个有效 Token，超出时最旧 Token 自动加入黑名单
- **security.txt**：`GET /.well-known/security.txt` 提供 RFC 9116 标准安全联系信息
- **Nginx 安全配置**：参考 `docs/nginx-security.conf` 提供完整的反向代理安全加固示例

### 操作来源端检测

OperationLog 中间件自动识别客户端平台，写入操作日志 `source` 字段：

| 平台 | 检测方式 |
|------|---------|
| `ipados` | UA 含 iPad |
| `macos` | UA 含 Macintosh/Mac OS |
| `windows` | UA 含 Windows |
| `linux` | UA 含 Linux（非 Android） |
| `ios` | UA 含 iPhone / iOS / CFNetwork |
| `android` | UA 含 Android |
| `harmonyos` | UA 含 HarmonyOS / OpenHarmony 或 `X-Client-Platform` 头显式声明 |
| `web` | 默认（未命中以上所有平台） |

> 两级检测：`X-Client-Platform` 请求头（原生 App 声明）→ User-Agent 自动推断（兜底）。操作日志查询 `GET /admin/log` 的 `source` 字段即来源端。

## 15. 部署运维

### 快速开始（4 个服务）

项目根目录提供 `docker-compose.yml`，编排 Nginx、webman app、MySQL、Redis 4 个服务，适合本地或单机试跑：

```bash
cp .env.docker .env
docker compose up -d
# 安装向导: http://localhost/install（数据库主机填 mysql）
```

> **仅限本地试跑**：`.env.docker` 的默认密码/密钥是公开且故意无效的占位值，使用前先替换，禁止直接暴露公网；生产使用 `deploy/`。

### 生产部署（单机 6 个服务）

生产编排位于 `deploy/`，单机运行 `nginx`、`app`、`init`（一次性初始化）、`mysql`、`redis`、`backup`（每日自动备份），不依赖全文检索引擎。PHP 通过 `Dockerfile` 构建（基于 `php:8.3-cli-alpine`，启用 OPcache，安装 redis/zip/gd 扩展，GD 启用 FreeType 与 JPEG）；webman 仅在容器内网监听 8791，由 Nginx 对外暴露 80/443。

```bash
cd deploy
./generate-secrets.sh
docker compose up -d --build
```

- 环境变量按服务隔离：`deploy/env/{app,init,mysql,redis,backup}.env`（目录 `700`、文件 `600`）；master `deploy/.env` 仅保存域名、端口与 `APP_UID`/`APP_GID` 等 compose 插值。应用容器不持有 MySQL root 密码和初始化管理员密码；`app`/`init` 以该 UID/GID 运行，保证 `deploy/data/{runtime,upload}` 可写。
- `generate-secrets.sh` 生成 RSA 密钥、自签名 TLS 证书与管理员密码 `deploy/secrets/admin_password.txt`，RSA 私钥写入 `app.env`；默认幂等，`--regenerate` 先归档旧 `.env`、`env/` 与 `secrets/` 到 `deploy/secrets/archive/<时间戳>/`，默认只轮换应用密钥并保留 `ENCRYPTABLE_KEY`、Hashids 盐值、现有 TLS 证书、数据库/管理员密码；Hashids 用 `--rotate-hashids` 显式轮换（旧链接中的 ID 将失效），字段加密密钥、TLS、数据库密码、管理员密码分别用 `--rotate-encryptable`（旧密钥自动加入 `ENCRYPTION_PREVIOUS_KEYS`，旧数据仍可读取；完成数据重加密后再移除旧密钥）、`--rotate-tls`、`--rotate-db`、`--reset-admin` 显式轮换。
- 真实域名证书替换 `deploy/secrets/tls/fullchain.pem` 与 `privkey.pem`；80 端口仅保留 ACME 校验路径，其余 301 到 HTTPS；HSTS 默认关闭，配置真实证书后再启用。
- 日志：Nginx/MySQL 写 stdout/stderr，Docker 按 `10m × 3` 轮转；持久化目录：`deploy/data/{mysql,redis,runtime,upload,backups}`。
- 资源上限：`deploy/docker-compose.yml` 为每个服务设置 `deploy.resources.limits.memory`（app 512M、init 256M、mysql 768M、redis 256M、nginx 128M、backup 256M），可按宿主机容量调整。
- `backup` 服务使用最小权限用户 `backup_reader`（`SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES` + `erik_operation_log` 的 `DELETE`）每日 `mysqldump --single-transaction --no-tablespaces` 到 `deploy/data/backups`，同时把 `deploy/data/upload` 打包为 `upload_<时间>.tar.gz`；两类备份保留 30 天，并每日清理 180 天前的操作日志（每轮最多 10000 行）。备份与 MySQL 位于同一主机/磁盘，需用 `rsync` 或 `rclone` 复制到异地（见 `deploy/README.md`）。

### CI/CD

`.github/workflows/ci.yml` 定义了 GitHub Actions 持续集成流水线：
- `php -l` 语法检查
- PHPUnit 单元测试
- `flutter analyze` 静态分析
- Docker 镜像构建 + PHP 扩展检查（redis/zip/gd、GD FreeType/JPEG）+ 根目录与 `deploy/` Compose 配置校验 + 两份 Nginx 配置的 `nginx -t`

### 数据库备份

生产编排的 `backup` 服务每日自动备份数据库与上传文件到 `deploy/data/backups`（保留 30 天），并清理 180 天前的操作日志；异地副本需自行用 `rsync`/`rclone` 同步。`database/backup/` 目录的脚本适用于宿主机直连数据库的场景：
- `backup.sh` — mysqldump + gzip 压缩备份，自动清理 30 天前的旧备份文件
- `restore.sh` — 交互式恢复，列出现有备份供用户选择

### Nginx 安全配置

生产环境部署请参考 `docs/nginx-security.conf` 进行反向代理安全加固配置。
