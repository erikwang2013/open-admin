# 安全架构设计文档

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. 纵深防御全景

系统采用 7 层纵深防御模型，从外到内层层过滤恶意请求，确保任意单层失效时仍有后续防线兜底。

整个中间件链按以下顺序执行（见 `config/middleware.php`）：

```
请求 → Cors → Locale(Accept-Language) → SecurityMiddleware (erikwang2013/security-php, 31种检测器) → RateLimit → [路由组中间件: AdminAuth → AdminPermission → OperationLog] → Controller
```

| 层 | 中间件/机制 | 防护目标 |
|----|--------|---------|
| 1 | SecurityMiddleware (erikwang2013/security-php) | 31 种攻击检测 + HTTP 方法校验 + 请求体大小限制 + Content-Type 校验 + CSRF + IP 攻击升级黑名单 |
| 2 | Cors | 跨域安全 + 响应安全头注入 |
| 3 | RateLimit | Redis 滑动窗口限流，防暴力破解 |
| 4 | AdminAuth | JWT 认证 + 黑名单登出 |
| 5 | AdminPermission | RBAC method.path 粒度鉴权 |
| 6 | OperationLog | 操作审计 + 来源端追踪 |
| 7 | 数据加密 | Hashids ID 混淆 + Encryptable DB 加密 + EncryptionService 传输加密 |

前端三层（Flutter）另有独立的输入校验，后端不做信任，每一层独立防御。

---

## 2. 攻击检测引擎

## 2. 攻击检测引擎 (erikwang2013/security-php)

攻击检测已从自研 SecurityMiddleware (erikwang2013/security-php) 迁移至 `erikwang2013/security-php` v1.1+ 专用安全包，提供 **31 种检测器**，覆盖 5 大攻击类别。

### 2.1 检测器分类

**注入攻击 (11种):** XSS、SQL注入、命令注入、NoSQL注入、LDAP注入、XPath注入、JNDI/Log4Shell、SSI服务端包含、GraphQL注入、SSTI模板注入

**协议与请求攻击 (9种):** SSRF、XXE、HTTP响应头注入、Host头攻击、Request Smuggling、Open Redirect、CORS绕过、WebSocket劫持、DNS Rebinding

**HTTP协议层校验 (6种):** HTTP方法校验(405)、请求体大小限制(413)、Content-Type校验(415)、CSRF Origin检查、IP攻击升级黑名单、敏感数据泄露检测

**数据与序列化攻击 (5种):** PHP反序列化、CSV公式注入、邮件头注入、JWT攻击（结构化分析）、JS Prototype Pollution

**文件与路径攻击 (2种):** 路径遍历、恶意文件上传

### 2.2 处理模式

每个检测器独立支持两种模式：
- `block` — 检测到攻击即拦截，返回配置的状态码
- `log` — 仅记录日志不拦截（`header_injection`、`ssti`、`nosql_injection` 默认 log 模式防误报）

### 2.3 IP 攻击升级黑名单

同一 IP 在 60 秒内触发 5 次攻击检测 → 自动封禁 15 分钟。存储后端可选 Redis（分布式）、File（单机JSON）或 Cache（高并发独立文件），当前配置为 Redis 存储。

### 2.4 安全日志

文件位置：`runtime/logs/security.log`（自动轮转，10MB/文件）

---

## 4. 响应安全头

所有头在 `Cors` 中间件中注入，通过 `$response->withHeaders()` 追加到每个响应。

| 头 | 值 | 作用 |
|----|-----|------|
| Access-Control-Allow-Origin | `*` | 允许任意源跨域（内网管理后台场景） |
| Access-Control-Allow-Methods | `GET,POST,PUT,DELETE,OPTIONS` | 允许的方法集合 |
| Access-Control-Allow-Headers | `Authorization,Content-Type,Accept-Language` | 允许的自定义头 |
| Access-Control-Max-Age | `86400` | 预检请求缓存 24 小时 |
| X-Content-Type-Options | `nosniff` | 禁止浏览器 MIME 嗅探 |
| X-Frame-Options | `DENY` | 禁止所有 iframe 嵌入，防点击劫持 |
| X-XSS-Protection | `1; mode=block` | 启用浏览器内置 XSS 过滤器并拦截页面渲染 |
| Referrer-Policy | `strict-origin-when-cross-origin` | 同源发完整 URL，跨域仅发域名 |
| Permissions-Policy | `camera=(), microphone=(), geolocation=()` | 全站禁用摄像头/麦克风/定位 API |

OPTIONS 预检请求直接返回 204 空响应，不进入后续中间件链。

### 4.2 Content-Security-Policy (CSP)

与其他安全头一起在 Cors 中间件中注入，提供深度防御，限制浏览器可加载和执行的资源来源。

| 头 | 值 | 作用 |
|----|-----|------|
| Content-Security-Policy | `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` | 限制脚本/样式/图片/连接/框架/表单等资源来源 |
| X-Permitted-Cross-Domain-Policies | `none` | 禁止 Adobe Flash/PDF 等跨域策略文件加载 |

CSP 策略要点：
- `default-src 'self'`：默认仅允许同源资源
- `script-src 'self' 'unsafe-inline' 'unsafe-eval'`：允许同源脚本 + 内联脚本（Flutter Web 必需）+ eval（Flutter Web 调试必需）
- `frame-ancestors 'none'`：禁止被任何页面 iframe 嵌入，与 X-Frame-Options: DENY 双保险
- `base-uri 'self'`：限制 `<base>` 标签只能指向同源
- `form-action 'self'`：限制表单只能提交到同源

---

## 5. 限流策略

### 算法

Redis Sorted Set 滑动窗口 + Lua 原子化脚本，关键操作：

```lua
-- 1. 清理窗口外的旧记录
redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, windowStart)
-- 2. 检查当前窗口计数
local count = redis.call('ZCARD', KEYS[1])
-- 3. 超限则返回 {0, count}，未超限则 ZADD 并返回 {1, count+1}
if count >= limit then return {0, count} end
redis.call('ZADD', KEYS[1], now, now . '.' . random)  -- 随机后缀避免同毫秒覆盖
redis.call('EXPIRE', KEYS[1], window + 10)
```

Lua 脚本在 Redis 服务端单线程执行，**天然原子化**，消除 TOCTOU（Time-of-check to Time-of-use）竞态条件。

### 限流配置

| 路由 | 限制 | 窗口 | 场景 |
|------|------|------|------|
| 默认（所有路由） | 60 次/分钟 | 60s | 通用 API |
| `/api/v1/auth/login` | 10 次/分钟 | 60s | 登录（防暴力破解） |

### 响应头

触发限流时返回 HTTP 429 及 JSON body：
```json
{"code": 429, "message": "请求过于频繁，请稍后再试", "data": []}
```

所有响应（含正常响应）携带以下头：

| 头 | 说明 |
|----|------|
| X-RateLimit-Limit | 当前窗口允许的最大请求数 |
| X-RateLimit-Remaining | 当前窗口剩余可用请求数 |
| X-RateLimit-Reset | 窗口重置的 Unix 时间戳 |
| Retry-After | 仅限流时携带，建议等待秒数 |

### 降级策略

Redis 异常（连接超时、不可用等）时，`/api/` 与 `/admin/` 请求 **fail-closed**，返回 HTTP 503；`/health`、`/metrics` 等非 API 路径仍放行，保证监控与探针可用：

```php
try {
    $result = Redis::eval($lua, ...);
} catch (\Throwable $e) {
    if (str_starts_with($path, '/api/') || str_starts_with($path, '/admin/')) {
        return json(['code' => 503, 'message' => '服务暂时不可用，请稍后再试', 'data' => []])->withStatus(503);
    }
    return $handler($request); // 非 API 路径放行，保证监控可用
}
```

限流与鉴权失败时以可用性换安全：Redis 故障期间拒绝 API 请求，避免绕过限流与 Token 黑名单。

### 5.4 账号锁定机制

登录接口在速率限制的基础上，额外增加了**账号锁定**机制，防止针对特定用户的定向暴力破解。

**锁定键**（`AuthController::lockKeys()` 是唯一来源）：

| 场景 | 锁定键 | 失败计数键 |
|------|--------|-----------|
| 用户名已存在 | `account_lock:u:<userId>` | `login_fail:u:<userId>` |
| 用户名不存在 | `account_lock:n:<md5(lower(trim(username)))>` | `login_fail:n:<md5(lower(trim(username)))>` |

未知用户按归一化用户名的 md5 计数，避免为不存在的账号建库表记录；大小写与首尾空白变体落到同一组键。两把键 TTL 均为 900 秒（15 分钟），过期自动解除，无需额外清理任务。

**锁定流程**：

```
登录失败 → Redis INCR login_fail:{u|n}:{...}，首次失败设置 TTL=900s
连续 5 次失败 → Redis SETEX account_lock:{u|n}:{...} 900 1
            → 返回 429 "账号已被锁定，请15分钟后再试"
            → 清除计数器 DEL login_fail:{u|n}:{...}
```

**锁定期间行为**：

锁定期间所有登录请求直接返回 429，不进行密码校验，完全阻止暴力破解尝试。登录成功后清除失败计数与锁定键。

**配置常量**：

| 常量 | 值 | 含义 |
|------|-----|------|
| MAX_LOGIN_ATTEMPTS | 5 | 最大连续失败次数 |
| LOCKOUT_DURATION | 900 | 锁定持续时间（秒），即 15 分钟 |

注意：账号锁定基于 `userId` 而非 IP，因此攻击者更换 IP 无法绕过锁定。与 IP 限流（10次/分钟）叠加形成双重防护：
- IP 层面：10 次/分钟限流阻止分布式暴力破解
- 账号层面：5 次失败锁定阻止定向暴力破解

---

## 6. 认证与鉴权

### 6.1 JWT 认证

AdminAuth 中间件实现，挂载在需要认证的路由组上。

**参数配置**（`config/plugin/erikwang2013/jwt/jwt`，由 `.env` 注入）：

| 参数 | 值 | 说明 |
|------|-----|------|
| 算法 | HS256 | HMAC-SHA256 对称签名 |
| 密钥 | `JWT_SECRET_KEY` | 环境变量注入，生产环境需更换 |
| access_token TTL | 7200s (2h) | `JWT_TTL` |
| refresh_token TTL | 1209600s (14d) | `JWT_REFRESH_TTL` |
| 签发者 | `open-admin` | `JWT_ISSUER` |
| 受众 | `open-admin` | `JWT_AUDIENCE` |

**Token 提取**：从 `Authorization: Bearer <token>` 头提取，strip `Bearer ` 前缀得到原始 JWT。

**认证流程**：
1. 空 token → 直接 401 `{"code": 401, "message": "未登录"}`
2. 检查 Redis 黑名单 `jwt_blacklist:{md5(token)}` → 命中 → 401 `Token已失效，请重新登录`
3. JWT decode → 失败（过期/签名不匹配） → 401 `Token已过期或无效`
4. 成功 → 注入 `$request->adminId` 和 `$request->adminUsername`

**黑名单机制**：用户登出时，将 `md5(token)` 写入 Redis，TTL 设为 JWT 剩余有效期。Redis 故障时黑名单读取失败，`AdminAuth` 返回 503（fail-closed），不会放行可能已登出的 Token。

**登录侧防护**：验证码标记 `captcha_verified:{key}` 由登录接口通过原子 `DEL` 消费，只有返回 1 的请求继续，并发复用同一标记时仅一个请求能登录。密码校验前统一做长度检查，长度合法时始终执行一次 `password_verify`；用户不存在或存储哈希为空时使用固定 cost-10 占位 bcrypt 哈希，抹平“用户存在/不存在”的响应时间差，防止账号枚举（长度非法时直接按凭证错误处理，不进入 bcrypt）。验证码消费时 Redis 故障直接返回 503，不降级放行。

**刷新令牌轮换**：`/api/v1/auth/refresh` 每次成功刷新都会签发新的 access/refresh 令牌对，旧 refresh_token 立即加入黑名单。为容忍并发请求与客户端重试，服务端以旧令牌的 md5 为键缓存新令牌对 60 秒（`refresh_rotation:<md5>`）：宽限期内再次提交同一旧令牌返回同一对新令牌，不会重复轮换；超过 60 秒后按黑名单拒绝。刷新前校验密码变更水位 `user_pwd_changed:<userId>`，令牌签发早于水位（改密、禁用、删除等触发）时返回 401。

并发轮换由 `refresh_lock:<md5>`（`SET NX EX 5` + Lua 比较后删除释放）串行化：未抢到锁的请求短暂等待缓存出现，命中则复用同一对令牌，未命中返回 409；持锁请求写缓存后再拉黑旧令牌。读取缓存时若缓存中的任一枚令牌已被拉黑，立即删除缓存并返回 401，防止登出或改密后经缓存换回有效令牌。每个用户用 `user_refresh_rotations:<userId>`（TTL 120 秒）记录自己的轮换键；登出与改密会拉黑缓存令牌并清空轮换缓存/集合。客户端应串行刷新；HarmonyOS 客户端对并发 401 使用单飞（single-flight）刷新，多个请求共享同一次刷新结果。

### 6.2 并发会话限制

为防止 Token 泄露后被多设备滥用，系统限制同一用户同时持有的有效 Token 数量。

**限制逻辑**：

```
登录成功 → 签发新 Token
         → 查询当前用户有效 Token: Redis ZCARD user_tokens:{userId}
           （ZSET 成员为 md5(token)，score 为过期时间戳）
         → 先 ZREMRANGEBYSCORE 清理已过期成员
         → ZADD user_tokens:{userId} <exp> <md5(new_token)>
         → 若 ZCARD > 3（MAX_CONCURRENT_SESSIONS）:
            → ZRANGE 取 score 最小（最早过期）的成员
            → ZREM user_tokens:{userId} <md5(oldest_token)>
            → SETEX jwt_blacklist:{md5(oldest_token)} <remaining_ttl> 1
         → EXPIRE user_tokens:{userId} <access TTL + 3600>
```

refresh_token 同样登记到 `user_refresh_tokens:{userId}` ZSET（score 为过期时间戳），供登出与改密统一吊销。

**配置常量**：

| 常量 | 值 | 含义 |
|------|-----|------|
| MAX_CONCURRENT_SESSIONS | 3 | 同一用户最大并发 Token 数 |

**被挤下线场景**：当用户在第 4 台设备登录时，第 1 台设备的 Token 被强制加入黑名单，后续请求返回 401 "Token已失效，请重新登录"。

登出时，当前 Token 从集合中移除。Token 自然过期时，Redis key 自动失效，集合成员随之减少。

### 6.3 RBAC 权限模型

AdminPermission 中间件实现。

**数据模型**：User -> Role -> Permission 三层关联

- `erik_admin_user` (用户表)
- `erik_admin_user_role` (用户-角色关联表)
- `erik_admin_role` (角色表)
- `erik_admin_role_permission` (角色-权限关联表)
- `erik_admin_permission` (权限表)

**权限类型**：
| type | 含义 | 示例 |
|------|------|------|
| 1 | 菜单权限 | 控制左侧导航可见性 |
| 2 | 按钮权限 | 控制页面内操作按钮 (新增/编辑/删除) |
| 3 | API 权限 | 控制后端接口调用 |

API 权限标识格式：`{method}.{path}`

例如：
- `post.admin/user` — 创建用户
- `put.admin/user` — 编辑用户
- `delete.admin/user` — 删除用户
- `get.admin/user` — 查看用户列表

**鉴权流程**：
1. `$request->adminId` 为空 → 放行（路由未配置认证前置）
2. 获取用户 → 启用角色（跳过 `status=0` 的禁用角色）→ 权限列表（`perm:<id>` 缓存 60 秒）
3. 持有启用 `super_admin` 角色的用户 → 通配权限 `['*']`，直接放行
4. 构造 `strtolower(method) . '.' . trim(path, '/')` → 对比权限列表
5. 匹配失败 → 403 `{"code": 403, "message": "无权限访问"}`

**授权边界（RbacService）**：分配角色时，非超级管理员所授角色的**全部权限（含已禁用角色携带的权限）**必须在其自身权限集合内，且不能分配 `super_admin`；编辑/删除/批量删除/批量启禁用用户时，目标持有的所有角色（含已禁用）都必须可授予给操作者，否则返回 403 `无权管理该用户`。`GET /admin/role/options` 只返回当前管理员可分配的启用角色。禁用角色也参与边界判断，防止先分配禁用高权限角色、之后再启用绕过。

**二次确认**：BaseController 提供 `confirmPassword()` 方法，删除用户/角色/权限/配置、批量删除等敏感操作在 Controller 层额外要求输入当前密码，防止会话劫持后被未授权操作。失败计数 `pwd_confirm_fail:<id>` 为 5 次/300 秒，超过后 5 分钟内拒绝确认（计数非原子，见 12.2）。

**超级管理员保护**：删除/批量删除/批量启禁用会拒绝当前登录账号（422），非超级管理员也不能修改、禁用、删除超级管理员目标或分配 `super_admin`（403）。写入前统计启用状态、`slug = super_admin` 的用户数，若操作后剩余为 0 则返回 422 `系统必须保留至少一名启用的超级管理员`。内置 `super_admin` 角色本身禁止编辑/删除（403）。

“最后一名启用超管”校验与写操作在同一把 MySQL 命名锁内串行化：`GET_LOCK('open_admin:last_super_admin', 3)`，`finally` 中 `RELEASE_LOCK`。覆盖 `update`（状态或角色变化、目标为超管、非超管操作）、`destroy`、`batchDestroy`、`batchStatus` 的启用与禁用，并在锁内重新读取目标角色、复核权限边界与超管保护；等待超时或数据库异常返回 HTTP 503，调用方可重试。命名锁按连接持有，连接断开时由 MySQL 自动释放。

### 6.4 会话吊销（SessionService）

修改密码、禁用用户、删除用户、批量禁用/删除时，`SessionService::revokeUser()` 统一吊销该用户的全部会话：

| 键 | TTL | 作用 |
|----|-----|------|
| `user_pwd_changed:<userId>` | refresh 有效期 + 60 秒 | 密码变更水位；刷新接口拒绝签发时间早于水位的令牌 |
| `user_tokens:<userId>` / `user_refresh_tokens:<userId>` | access/refresh 有效期 | ZSET 登记该用户已签发的访问/刷新令牌，吊销时逐个写入黑名单后删除集合 |
| `jwt_blacklist:<md5(token)>` | 令牌剩余有效期（至少 1 秒） | 访问与刷新令牌黑名单 |
| `user_refresh_rotations:<userId>` | 120 秒 | 该用户 60 秒宽限期轮换缓存键集合，吊销时一并清理 |
| `perm:<userId>` | 60 秒 | 权限缓存，角色变化或吊销时清除 |

角色变化但不改密码/状态时只清除 `perm:<id>`，不吊销会话。Redis 操作全部捕获并记录日志，数据库写入成功后不会因 Redis 故障回滚；但 Redis 故障期间吊销可能未完全生效，属于已知的提交后失败开放（见 12.2）。

---

## 7. 审计日志

### 7.1 操作日志

OperationLog 中间件对 POST / PUT / DELETE 请求自动记录操作日志。GET 请求不记录。

**记录字段**：

| 字段 | 来源 | 说明 |
|------|------|------|
| id | SnowflakeService::generate() | 全局唯一 ID |
| user_id | `$request->adminId` | 操作者 ID，未登录为 0 |
| action | `$request->method()` | 等同于 method |
| method | `$request->method()` | POST / PUT / DELETE |
| path | `$request->path()` | 请求路径 |
| ip | `$request->getRealIp()` | 客户端真实 IP |
| source | detectSource() | 客户端来源平台 |
| input | 请求 body（脱敏后 JSON） | 操作提交数据 |
| created_at | `date('Y-m-d H:i:s')` | 操作时间 |

**敏感字段过滤**：递归遍历请求体，以下字段的值替换为 `***`：

`password`, `old_password`, `new_password`, `new_password_confirmation`, `token`, `secret`, `access_token`, `refresh_token`

**个人信息脱敏**：`OperationLog` 在写入前对 `phone`、`email`、`id_card`、`mobile`、`address` 字段递归脱敏（手机号保留前 3 后 4，邮箱保留首字符与域名，身份证完全遮盖），避免审计日志成为明文个人信息泄露渠道。

**来源端检测**（`detectSource()`）：按优先级：

1. 优先读取 `X-Client-Platform` 自定义头（原生客户端显式声明）
2. 降级到 User-Agent 字符串推断（`detectSource()` 方法检测顺序）：

| 平台 | UA 关键字 |
|------|----------|
| iPadOS | `iPad` |
| macOS | `Macintosh`, `Mac OS` |
| Windows | `Windows` |
| Linux | `Linux` |
| iOS | `iPhone`, `iOS`, `CFNetwork` |
| Android | `Android` |
| HarmonyOS | `HarmonyOS`, `OpenHarmony` |
| Web | 兜底默认值 |

**容错**：日志写入异常不阻塞业务请求（`catch (\Throwable)` 静默吞掉）。

### 7.2 安全日志

**文件位置**：`runtime/logs/security.log`

**记录内容**：
- 攻击拦截日志：攻击类别、IP、路径、字段、来源、payload 片段（前 200 字符）
- IP 封禁通知：被封 IP、触发次数

日志权限为 `FILE_APPEND | LOCK_EX`，确保并发安全写入。

---

## 8. 数据保护

系统采用三层数据保护策略，对应数据流转的三个阶段。

### 8.1 传输层 — EncryptionService

`EncryptionService` 使用 `erikwang2013/encryption` 包，对 API 请求/响应中的敏感字段进行加解密。

**技术细节**：
- 算法：`aes-256-cbc-hmac`（自带 HMAC 签名防篡改）
- 密钥：`ENCRYPTION_KEY` 环境变量，自动对齐到 32 字节
- 用于：客户端与 API 之间传输手机号、身份证号等字段

**脱敏工具方法**：
- `maskPhone('13812341234')` → `138****1234`
- `maskEmail('abc@example.com')` → `a***@example.com`（用户名超 2 字符）或 `a**@example.com`

### 8.2 存储层 — Encryptable Cast

`AdminUser` 模型使用 `Erikwang2013\Encryptable\Encryptable` Eloquent cast，对应字段：

- `email` → cast 为 Encryptable，自动加解密
- `phone` → cast 为 Encryptable，自动加解密  
- `id_card` → cast 为 Encryptable，自动加解密

写入数据库时自动加密为密文，读取时自动解密为明文。数据库存储列类型为 `VARCHAR(500)`，密文以 base64 形式存储。

**密钥体系**：与传输层加密（`ENCRYPTION_KEY`）独立使用 `ENCRYPTABLE_KEY`，一个密钥泄露不会导致另一层失效。

密钥轮换：`ENCRYPTION_PREVIOUS_KEYS` 环境变量支持历史密钥列表（逗号分隔），读取旧数据时尝试历史密钥解密，写回时使用当前密钥重新加密。

### 8.3 展示层 — ID 混淆与脱敏

**Hashids ID 混淆**：`HashidsService` 使用 `erikwang2013/hashids` 包。

- 对外 API 返回的数据库 BIGINT ID 编码为 hash 字符串（如 `xK3mN9qR2pL7wV8b`）
- 客户端请求时传入 hash 字符串，后台自动解码为原始 ID
- 盐值 `HASHIDS_SALT` 环境变量注入，盐值不同则编解码结果完全不同
- hash 最小长度 16 位，使用 62 位字母数字字符集
- BaseController 提供 `encodeId()`, `decodeId()`, `encodeIds()` 便捷方法

**导出脱敏**：Excel/PDF 导出时（ExportController），敏感字段统一脱敏：
- 手机号：`138****1234`
- 邮箱：`a***@example.com`
- 身份证：完全遮盖为 `********`

**Excel 导出防护**：导出筛选条件必须是对象，key 限于目标表可用列，值必须是标量，否则返回 422，防止任意列/数组条件进入查询。所有单元格通过 `setValueExplicit(..., TYPE_STRING)` 以文本写入，杜绝 `=`、`+`、`-`、`@` 开头的值被 Excel 当作公式执行（公式注入）；表头与数据区边框只写一次。单次最多 5000 行（查询 `LIMIT 5001` 探测，超出返回 HTTP 422）；5000 行 × 9 列在 128M 工作进程下实测约占 65MiB，已接近上限，不应同时提高行数与列数。

**用户导入防护**：限制 5MB 文件、2000 行数据（含表头 2001 行）、32,000 个单元格。`.xlsx` 在加载前做 ZIP 解压体积预检（`xl/**.xml` 解压后超过 8MB 或单元格超过 32,000 个即返回 422），防止压缩炸弹；`.xls`/`.xlsx` 都先读取工作表元数据，任一工作表超过 2001 行或行 × 列超过 32,000 单元格即拒绝，避免整本工作簿进入内存；加载后再次校验实际行数。每行独立校验（用户名 3-50、密码 6-32、姓名 ≤50、手机号 ≤20、邮箱 ≤254、状态 0/1），已存在用户名一次性预取，失败行记录行号与通用原因，数据库异常仅写服务端日志。整批导入由 Redis 锁 `import_users_lock`（`SET NX EX 1800`）串行化，已有任务时返回 409，避免并发导入绕过重名检查；2000 行 bcrypt 属 CPU 密集操作，锁 TTL 按最坏耗时设置，进程异常退出由 TTL 兜底释放。每行用户与角色关联同事务，失败回滚该行。文件损坏、无法读取或无法解析时统一返回 422 与可读提示，不泄漏异常内部信息。

---

## 9. 密钥管理

所有密钥通过 `.env` 环境变量注入，配置文件使用 `getenv()` 读取并内置兜底默认值（仅开发环境安全）。

| 环境变量 | 用途 | 包 | 生产要求 |
|----------|------|-----|---------|
| JWT_SECRET_KEY | JWT 签名密钥 | erikwang2013/jwt-webman | 64+ 字符随机字符串 |
| JWT_ALGORITHM | JWT 签名算法 | 同上 | 保持 HS256 |
| HASHIDS_SALT | ID 编码盐值 | erikwang2013/hashids | 随机字符串 |
| SNOWFLAKE_DATACENTER_ID | 数据中心 ID (0-31) | erikwang2013/snowflake-php | 单机房保持默认 |
| ENCRYPTION_KEY | API 传输层加密密钥 | erikwang2013/encryption | 32 字节随机字符串 |
| ENCRYPTABLE_KEY | DB 存储层加密密钥 | erikwang2013/encryptable | 32 字节随机字符串，与传输密钥不同 |
| RSA_PRIVATE_KEY | 登录密码 RSA-2048 私钥（Base64 PEM） | erikwang2013/encryption | 由部署脚本生成，禁止泄露 |

**安全要求**：
- `.env` 文件已加入 `.gitignore`，严禁提交到版本库
- `.env.example` 是公开模板文件，不包含真实密钥
- 生产环境**必须**更换所有默认密钥为随机字符串
- 单机生产部署使用 `deploy/generate-secrets.sh` 生成 `deploy/env/{app,init,mysql,redis,backup}.env`（目录 `700`、文件 `600`）、`deploy/.env` 与 `deploy/secrets/`；`deploy/.env`、`deploy/env/`、`deploy/secrets/` 均已加入 `.gitignore`。环境变量按服务隔离，应用容器不持有 MySQL root 密码与初始化管理员密码
- `--regenerate` 先把旧 `.env`、`env/` 与 `secrets/` 归档到 `deploy/secrets/archive/<时间戳>/`，默认只轮换 JWT/ENCRYPTION/RSA/Redis 等应用密钥，保留 `HASHIDS_SALT`、`ENCRYPTABLE_KEY`、现有 TLS 证书、`DB_PASSWORD`、`MYSQL_ROOT_PASSWORD` 与管理员密码，避免破坏已有数据库。需要轮换 Hashids 用 `--rotate-hashids`（旧链接中的 ID 将失效），轮换字段加密密钥用 `--rotate-encryptable`（旧密钥自动加入 `ENCRYPTION_PREVIOUS_KEYS`，旧数据仍可读取；完成数据重加密后再移除旧密钥），轮换 TLS 用 `--rotate-tls`，轮换数据库密码用 `--rotate-db`，重置管理员密码用 `--reset-admin`
- RSA 公钥通过 `GET /api/v1/auth/public-key` 公开（仅公钥，可安全暴露）；换钥后确认该端点可达，依赖内置回退公钥的旧客户端需重新构建

### 密钥存储隔离

| 层 | 配置键 | 密钥环境变量 |
|----|--------|-------------|
| 传输加密 | `config/encryption.php` → `key` | `ENCRYPTION_KEY` |
| 存储加密 | `config/encryptable.php` → `key` | `ENCRYPTABLE_KEY` |
| ID 混淆 | `config/hashids.php` → `connections.main.salt` | `HASHIDS_SALT` |
| JWT 签名 | `config/plugin/erikwang2013/jwt/jwt` | `JWT_SECRET_KEY` |

### 备份与日志保留

生产 `backup` 服务使用独立的最小权限 MySQL 用户 `backup_reader`（`SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES`，外加 `erik_operation_log` 的 `DELETE`），不持有 root 密码。每日用 `mysqldump --single-transaction --no-tablespaces` 备份数据库，并把 `deploy/data/upload` 打包为 `upload_<时间>.tar.gz`，两类备份保留 30 天；同时每日清理 180 天前的操作日志（每轮最多 10000 行，失败不影响备份）。

应用内另有不监听端口的 `log-cleanup` webman 进程（`count=1`）：启动时先尽力清理一次，之后每日清理 180 天前的 `operation_log`，每批 10000 行、最多 10 批（单轮 100000 行），批次间让出数据库资源，失败只记日志不影响服务；它与 backup 服务的清理互为兜底。

备份与数据库位于同一主机/磁盘，必须复制到异地，避免单机故障同时丢失数据与备份。`deploy/offsite-sync.sh` 读取 `deploy/.env` 的 `OFFSITE_TARGET`，支持 rsync（`user@host:/path/`）与 rclone（`rclone:remote:path`），未配置目标或备份目录为空时拒绝执行，并排除未写完的临时文件；脚本由宿主机 cron 调度，容器不会自动调用。

---

## 10. security.txt (RFC 9116)

系统在 `/.well-known/security.txt` 提供符合 RFC 9116 标准的安全联系信息端点，方便安全研究人员在发现漏洞时快速找到报告渠道。

**访问方式**：

```
GET /.well-known/security.txt
```

**响应内容**：

```text
Contact: mailto:security@erik.xyz
Expires: 2027-05-20T00:00:00.000Z
Preferred-Languages: zh, en
Canonical: https://erik.xyz/.well-known/security.txt
Policy: https://erik.xyz/security-policy
```

**字段说明**：

| 字段 | 说明 |
|------|------|
| Contact | 安全漏洞报告联系方式 |
| Expires | 文件过期时间，需定期更新 |
| Preferred-Languages | 首选沟通语言 |
| Canonical | 此文件的规范 URL |
| Policy | 安全策略/漏洞披露政策链接 |

该端点不受限流、认证等中间件限制，任何人都可直接访问。

---

## 11. Nginx 安全配置

项目提供 `docs/nginx-security.conf` 作为生产环境 Nginx 反向代理的安全加固参考配置。

**包含的安全措施**：

| 配置项 | 作用 |
|--------|------|
| `server_tokens off` | 隐藏 Nginx 版本号 |
| `client_max_body_size 10m` | 限制请求体大小，与 SecurityMiddleware (erikwang2013/security-php) 协同 |
| `limit_req_zone` | Nginx 层面的请求频率限制 |
| `limit_conn_zone` | 并发连接数限制 |
| `add_header` 安全头 | 在 Nginx 层面追加 X-XSS-Protection 等安全头 |
| 静态资源安全头 | 在 assets location 内重复 `X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`、`Permissions-Policy` 与 `Cache-Control`（location 级 `add_header` 会覆盖继承值） |
| 静态页面 CSP | `/open-admin/`、`/open-admin/en/`、`/apidoc/` 各自的 location 注入独立 CSP：`default-src 'self'`、允许内联脚本（页面主题引导与 JSON-LD），官网额外放行 `https://busuanzi.ibruce.info`，`frame-ancestors 'none'`、`form-action 'self'`；这些 location 同样重复安全响应头 |
| `if ($request_method)` | Nginx 层面拒绝非标准 HTTP 方法 |
| SSL/TLS 配置 | 现代 TLS 1.2/1.3 配置，禁用弱加密套件 |
| 隐藏后端头 | `proxy_hide_header` 移除 webman 版本等敏感头 |

**使用方式**：将 `docs/nginx-security.conf` 中的配置合并到您的 Nginx server 块中，根据实际域名和证书路径调整。

单机生产编排使用 `deploy/nginx/default.conf`：80 端口除 `/.well-known/acme-challenge/` 外全部 301 到 HTTPS；证书读取 `deploy/secrets/tls/fullchain.pem` 与 `privkey.pem`。真实域名证书直接替换这两个文件，脚本默认不会覆盖已有证书（`--rotate-tls` 除外）。`Strict-Transport-Security`（HSTS）默认注释关闭：自签证书或尚未确认的域名下开启会把浏览器锁在错误域名上，配置真实域名证书后再启用。Nginx/MySQL 日志写 stdout/stderr，由 Docker 按 `10m × 3` 轮转，不生成无上限的宿主机日志文件。两份配置均在 CI 中用 `nginx -t` 校验。

---

## 12. 威胁模型

### 12.1 已防护威胁

| 威胁类型 | 攻击向量 | 防御层次 |
|----------|---------|---------|
| HTTP 方法滥用 | TRACE/TRACK XST 攻击、CONNECT 隧道代理、WebDAV 方法探测 | SecurityMiddleware http_method 检测器 405 方法白名单 |
| 定向暴力破解 | 针对特定用户反复尝试密码 | 账号锁定 (5次失败锁定15分钟) + RateLimit (登录 10/min) + Captcha |
| 暴力破解 | 分布式 IP 反复尝试用户名/密码 | RateLimit (登录 10/min) + Captcha |
| XSS 跨站脚本 | `<script>`, onerror, javascript: | SecurityMiddleware (erikwang2013/security-php) (5 种模式) + X-XSS-Protection 响应头 + CSP |
| SQL 注入 | UNION SELECT, OR 1=1, 注释绕过 | SecurityMiddleware (erikwang2013/security-php) (6 种模式) + Eloquent ORM 参数化查询 |
| CSRF 跨站请求伪造 | 恶意网站代发请求 | SecurityMiddleware (erikwang2013/security-php) Origin/Referer 校验 |
| 路径遍历 | `../../etc/passwd` | SecurityMiddleware (erikwang2013/security-php) 路径遍历模式 + UploadController 扩展名白名单 |
| 命令注入 | `;ls`, `` `whoami` ``, `$(cat ...)` | SecurityMiddleware (erikwang2013/security-php) (4 种模式) |
| 会话劫持 | 窃取 JWT Token | JWT 短期有效 (2h) + 黑名单登出 + 改密/禁用/删除时全量会话吊销 + 敏感操作二次密码确认 |
| ID 枚举 | 遍历数字 ID 猜测数据量 | Hashids 混淆为随机字符串 |
| 数据泄露 | DB 拖库 / 中间人 / 日志泄露 | 三层加密/脱敏 + OperationLog 敏感字段与个人信息过滤 |
| DoS 攻击 | 超大请求体 / 高频请求 / 压缩炸弹 / 宽表 | 请求体 10MB 限制 + RateLimit 60/min + IP 黑名单 + 导入 5MB/2000 行/32000 单元格/ZIP 8MB 预检 + 导出 5000 行上限 |
| 权限提升 | 低权限用户访问管理接口 / 分配高权限角色 / 管理高权限用户 | RBAC method.path 粒度鉴权 + 授予与目标管理边界（含禁用角色）+ 非超管禁止分配/操作超管 |
| Excel 公式注入 | 用户名等单元格以 `=`、`+`、`-`、`@` 开头 | 导出统一 `TYPE_STRING` 文本写入 |
| 文件上传攻击 | shell.php.png 双扩展名 | SecurityMiddleware (erikwang2013/security-php) 恶意文件检测 |

### 12.2 已知局限

| 局限 | 影响范围 | 缓解措施 |
|------|---------|---------|
| CSRF 保护仅对浏览器有效 | 非浏览器客户端（curl, Postman, 移动 App）可跳过 Origin/Referer 检查 | 非浏览器客户端天然不受 CSRF 攻击；依赖 JWT 认证替代 Cookie |
| Redis 不可用时 `/api/`、`/admin/` 失败关闭（503） | Redis 故障期间管理端与 API 不可用 | `/health`、`/metrics` 等非 API 路径放行；监控 Redis 并告警，尽快恢复 |
| 刷新令牌 60 秒宽限期 | 旧 refresh_token 在轮换后 60 秒内重放可拿到缓存的新令牌对 | 旧令牌已拉黑且缓存仅 60 秒；客户端串行刷新，HarmonyOS 单飞 |
| 无独立 WAF 引擎 | 基于正则匹配的检测，非专用 WAF 规则引擎 | 生产环境建议前置 Nginx ModSecurity 或 Cloudflare WAF |
| JWT 无状态无法主动失效 | Token 未过期前无法从服务端主动吊销（除黑名单外） | 黑名单 + 密码变更水位 + 短期 2h TTL 降低风险窗口 |
| 管理员端点无特殊限流 | 管理员接口与普通接口共用 60/min 默认限制 | 管理员操作频率天然低，暂无需区分 |
| PCRE 回溯限制 | 包内置 1,000,000 回溯上限+finally恢复，极端复杂输入仍有性能风险 | 请求体大小限制 (10MB) 兜底 |
| 二次确认失败计数非原子 | `pwd_confirm_fail:<id>` 的 `INCR` 与首次 `EXPIRE` 分两步执行，极端并发下计数窗口可能略长 | 仅影响猜密码速率限制，不影响密码校验正确性；Redis 故障时不阻断本次确认 |
| 会话吊销在数据库提交后执行 | Redis 故障时，用户/角色已写入但黑名单与水位未写入，旧令牌可能继续有效至自然过期 | Redis 操作均记录日志；管理端可用时监控告警，必要时手工拉黑；令牌 TTL 最长为 access 2h / refresh 14d |
| 导入锁故障开放 | Redis 不可用时 `import_users_lock` 降级为无锁导入 | 管理员低并发场景风险有限；Redis 恢复后锁立即生效 |
| PHP ≥ 8.4 的 bcrypt 默认 cost 变化 | 8.4 起 `PASSWORD_BCRYPT` 默认 cost 由 10 升至 12，新用户哈希更慢；登录占位哈希固定 cost 10，时间差可能重新出现；2000 行导入耗时与 1800 秒锁 TTL 余量下降 | 生产固定 PHP 8.3（见 Dockerfile）；升级 8.4 前评估登录耗时与导入锁 TTL，并把占位哈希 cost 调整到与存储哈希一致 |
| 角色权限编辑不主动清缓存 | `RoleController` 修改 `permission_ids` 后，受影响用户的 `perm:<id>` 缓存最长 60 秒才过期 | 权限收紧有最长 60 秒延迟；敏感操作可等待缓存过期或手工删除 `perm:<id>` |
