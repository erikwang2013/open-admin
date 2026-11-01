# 开放管理后台 — 设计文档

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

> 详细的 Mermaid 架构图请参阅 [ARCHITECTURE.md](ARCHITECTURE.md)（GitHub/GitLab/VS Code 可自动渲染）。
>
> 静态设计图（SVG）：[系统架构](diagrams/architecture.svg) · [功能设计](diagrams/features.svg) · [生命周期](diagrams/lifecycle.svg)

## 1. 系统架构

> **功能清单**：认证(login/refresh/logout + 账号锁定 + 会话限制) | 仪表盘(Redis缓存) | 用户CRUD+批量+异步导入(队列/worker/状态轮询) | 角色权限(RBAC) | 系统配置 | 操作审计(8平台来源端) | 文件(上传+导出+脱敏) | 安全(18层防御) | 运维(health/metrics/docs/Docker/CI)

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

## 2. 后端架构

### 2.1 分层设计

| 层 | 目录 | 职责 |
|---|------|------|
| 路由 | `config/route.php` | URL 到控制器的映射，中间件绑定，版本化路由 |
| 中间件 | `app/middleware/` | 攻击拦截(SecurityFilter)、限流(RateLimit)、认证(JWT)、授权(RBAC) |
| 控制器 | 14 个：Dashboard/User/Role/Permission/Config/Log/Profile/Export/Import/Upload/Health/Docs (管理端) + Captcha/Auth (API v1) | 请求参数校验、调用业务逻辑、响应格式化 |
| 业务服务 | `app/service/` | 可复用的业务逻辑（预留） |
| 数据模型 | `app/model/` | ORM 映射、关联关系、字段加解密 |
| 公共工具 | `app/common/` | Hashids、Snowflake、Encryption 服务 |

### 2.2 请求生命周期

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

### 2.3 ID 生命周期

```
生成 (Snowflake) → 存储 (MySQL BIGINT) → 传输 (Hashids 编码) → 外部 (hash 字符串)
                                                                    │
                            HashidsService::decode() ←──────────────┘
```

### 2.4 数据加密体系

```
传输层 (encryption)     — AES-256-CBC，独立密钥
存储层 (encryptable)    — AES-128-ECB，独立密钥，Model $casts 自动处理
展示层 (mask)           — 手机号: 138****1234，邮箱: a***@example.com
```

## 3. 数据库设计

### 3.1 ER 关系

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

### 3.2 核心表结构

| 表名 | 字段数 | 说明 |
|------|-------|------|
| `erik_admin_user` | 14 | 管理用户，phone/email/id_card 加密存储，支持软删除 |
| `erik_admin_role` | 7 | 角色，slug 唯一 |
| `erik_admin_permission` | 10 | 权限树（parent_id 自引用），type: 1=菜单 2=按钮 3=API |
| `erik_admin_user_role` | 2 | 用户-角色多对多中间表 |
| `erik_admin_role_permission` | 2 | 角色-权限多对多中间表 |
| `erik_system_config` | 8 | 键值对配置，group+key 联合唯一 |
| `erik_operation_log` | 9 | 操作审计日志（含 source 来源端） |

### 3.3 主键规范

- 类型: `BIGINT UNSIGNED NOT NULL`
- 特性: **非自增**，由 Snowflake 算法在应用层生成
- 优势: 全局唯一、分布式友好、趋势递增利于索引、不暴露业务量
- 配置: datacenter_id(0-31) + worker_id(0-31)，支持 1024 个节点并发

## 4. API 设计

### 4.1 URL 规范

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
导入:     POST /admin/import/users（异步，202 + job_id）
状态:     GET  /admin/import/status/{job}（仅任务创建者）
上传:     /admin/upload
批量:     /admin/user/batch/{destroy|status}
文档:     /api/docs     (OpenAPI 3.0)
调试页:   /apidoc/      (单文件同源调试界面，读取 /api/docs)
健康:     /health
```

### 4.2 API 版本策略

API 版本号体现在 URL 前缀中（`/api/v1/...`、`/api/v2/...`），**不使用请求头**。`config/route.php` 静态注册各版本的路由组直连对应版本的控制器，版本分发不经过任何中间件。

| 机制 | 说明 |
|------|------|
| URL 前缀 | 版本号固定为 URL 第一段路径: `/api/v1/...`、`/api/v2/...` |
| 路由 | `config/route.php` 中静态注册 `Route::group('/api/v1', ...)` 直连控制器 |
| 目录 | 控制器按版本组织: `app/api/{version}/controller/` |
| 运维端点 | `/api/docs`、`/health`、`/metrics` 等不带版本前缀 |

扩展示例——新增 v2 API：
1. 创建 `app/api/v2/controller/AuthController.php`
2. `config/route.php` 注册 `Route::group('/api/v2', ...)` 路由组

```bash
# v1
curl http://localhost:8791/api/v1/auth/login

# v2（新增版本后）
curl http://localhost:8791/api/v2/auth/login
```

### 4.3 限流策略

基于 Redis Sorted Set 滑动窗口算法，原子化 Lua 脚本执行：

| 接口 | 限制 |
|------|------|
| 默认 | 60 次/分钟/IP/路由 |
| POST /api/v1/auth/login | 10 次/分钟 |

超限返回 429，响应头包含 X-RateLimit-Limit / Remaining / Reset / Retry-After。Redis 故障时 `/api/`、`/admin/` 返回 503（失败关闭），`/health`、`/metrics` 等非 API 路径放行以保证监控可用。

### 4.4 统一响应

```json
{
  "code": 0,
  "message": "success",
  "data": { ... }
}
```

| code | 含义 | 触发场景 |
|------|------|---------|
| 0 | 成功 | 正常响应 |
| 400 | 参数错误 | 请求格式不正确 |
| 401 | 未认证 | Token 缺失/过期/无效 |
| 403 | 无权限 | 用户角色不包含所需权限 |
| 404 | 不存在 | 资源未找到 |
| 422 | 验证失败 | 表单参数不符合规则 / 密码确认失败 / 导出超过 5000 行 |
| 500 | 服务端错误 | 未预期异常 |
| 503 | 服务不可用 | Redis 故障导致 `/api/`、`/admin/` 鉴权或限流失败关闭 |

### 4.5 认证流程（含点击验证码）

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

刷新：`POST /api/v1/auth/refresh` 轮换令牌对，旧 refresh_token 立即拉黑，并以 `refresh_rotation:<md5>` 缓存新令牌对 60 秒；宽限期内重复提交旧令牌返回同一对令牌。并发轮换由 `refresh_lock:<md5>`（`SET NX EX 5` + Lua 释放）串行化，并发未命中返回 409；缓存令牌被拉黑时删除缓存并返回 401。刷新前校验密码变更水位 `user_pwd_changed:<userId>`。改密、禁用、删除、批量禁用/删除由 `SessionService::revokeUser()` 写入水位、拉黑访问/刷新令牌并清理轮换缓存与权限缓存；角色变化只清权限缓存。登出拉黑缓存令牌并按 `user_refresh_rotations:<userId>` 清理；HarmonyOS 客户端并发 401 单飞刷新。

### 4.6 权限模型 (RBAC)

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

授权边界：非超管分配角色时，所授角色（含禁用角色）的全部权限必须是自身权限子集，且不能分配 `super_admin`；管理用户时目标持有的所有角色（含禁用）都必须可授予，否则 403。`GET /admin/role/options` 仅返回当前管理员可分配的启用角色。

超级管理员保护：不能删除当前登录账号或修改其状态；非超管不能修改/禁用/删除超管目标或分配 `super_admin`。删除/禁用/角色变更后必须至少保留一名启用的超管（否则 422）。校验与写操作由 MySQL 命名锁 `open_admin:last_super_admin`（`GET_LOCK` 3 秒 / `finally` 释放）串行化，覆盖更新（状态/角色）、删除、批量删除与批量启用/禁用，锁内重新读取目标角色并复核边界，超时或数据库异常返回 503；内置 `super_admin` 角色禁止编辑/删除（403）。

### 4.7 敏感操作二次确认

删除用户、角色、权限、配置及批量删除等敏感操作，需要在请求体中传入当前用户密码进行身份复核。失败计数 `pwd_confirm_fail:<id>` 为 5 次/300 秒，超过后 5 分钟内拒绝确认：

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

前端在触发删除操作前弹出确认对话框，收集用户密码后发送请求。

## 5. 前端设计

### 5.1 Flutter Web 管理后台

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

特性: 侧边栏可折叠、Material 3 双主题、数据表格高密度、弹窗 Dialog、鼠标悬停交互

### 5.2 HarmonyOS 移动端

页面路由:

| 页面 | 路由 | 说明 |
|------|------|------|
| LoginPage | `pages/LoginPage` | 用户名密码 + 点击验证码登录 |
| DashboardPage | `pages/DashboardPage` | 统计卡片 + 最近操作 |
| UserListPage | `pages/UserListPage` | 用户列表，搜索 + 下拉刷新 + 上滑加载 |
| UserDetailPage | `pages/UserDetailPage` | 新增/编辑/查看/删除（AlertDialog 确认） |
| ProfilePage | `pages/ProfilePage` | 个人中心，退出登录（AlertDialog 确认） |

数据流: Page ← DataService ← ApiService (JWT Bearer) ← HTTP ← webman

## 6. 安全设计

### 6.1 纵深防御

| 层面 | 措施 |
|------|------|
| 方法限制 | SecurityFilter HTTP 方法白名单，仅允许 GET/POST/PUT/DELETE/OPTIONS/HEAD，非标准方法返回 405 |
| 攻击拦截 | SecurityFilter 中间件，XSS/SQL注入/路径遍历/命令注入/CSRF 检测拦截 |
| 人机验证 | 点击验证码（Click Captcha），登录强制校验；标记原子 `DEL` 消费，并发仅一次成功 |
| 账号锁定 | 连续 5 次登录失败锁定账号 15 分钟，锁定期间返回 429 |
| 登录计时 | 密码长度合法时始终执行 bcrypt；未知用户/空哈希使用固定 cost-10 占位哈希，抹平账号枚举时间差 |
| 会话限制 | 同一用户最多 3 个并发 Token，超出时最旧 Token 自动黑名单 |
| 会话吊销 | 改密/禁用/删除/批量禁用删除：写库前吊销，水位线 + 访问/刷新令牌黑名单 + 轮换缓存/权限缓存清理；吊销失败中止并返回 503 |
| 限流 | RateLimit 中间件，Redis 滑动窗口，Lua 原子化；Redis 故障时 `/api/`、`/admin/` 失败关闭（503） |
| 鉴权状态 | AdminAuth 校验账号存在且 `status=1`，并拒绝签发时间早于/等于改密水位的 Token；黑名单、账号查询或水位读取任一失败返回 503，不放行无法确认状态的 Token |
| 超管保护 | 不能操作当前账号；非超管不能分配/操作超管；删除/禁用/角色变更保留至少一名启用的超级管理员；内置超管角色禁改删 |
| 授权边界 | 角色授予与目标管理按权限子集校验，禁用角色同样参与；`/admin/role/options` 只返回可分配角色 |
| 导入导出 | 导入 5MB / 2000 行 / 32000 单元格 / ZIP 8MB 预检 / Redis 锁 409 / 异步队列 + 单 worker / 状态轮询 / 逐行事务，Redis 故障失败关闭；导出 5000 行、PDF 30000 单元格/5000 行/200 卡片上限，条件标量校验，单元格统一文本写入防公式注入 |
| CSP | Content-Security-Policy 头限制资源来源，防 XSS 与数据注入 |
| 操作确认 | 删除等敏感操作需输入当前用户密码二次确认，失败 5 次/300 秒限速 |
| 传输 | HTTPS + JWT Bearer Token；登录密码 RSA-2048（AES/明文兼容回退） |
| 接口ID | Hashids 加密，外部不可逆推真实 ID |
| 请求体 | AES-256-CBC 敏感字段加密 |
| 数据库 | BIGINT 主键（不暴露自增量） |
| 数据库 | Encryptable 敏感字段加密存储 |
| 认证 | JWT HS256，2h 过期 + refresh token 14d，刷新轮换 60 秒宽限 |
| 授权 | RBAC，method.path 粒度权限控制；启用 super_admin 通配 `*` |
| 审计 | OperationLog 记录所有操作（含来源端 source 自动检测），手机号/邮箱/身份证等 PII 递归脱敏 |

### 6.2 密钥管理

```
JWT_SECRET_KEY      → 环境变量注入，64位随机字符串
HASHIDS_SALT        → 唯一盐值，泄漏后需全局更换
ENCRYPTION_KEY      → API 传输加密密钥，32字节
ENCRYPTABLE_KEY     → DB 存储加密密钥，与传输密钥独立
RSA_PRIVATE_KEY     → 登录密码传输公钥对应的私钥（deploy 脚本自动生成）
```

### 6.3 敏感数据保护

| 场景 | 字段 | 措施 |
|------|------|------|
| 列表展示 | phone | 脱敏: 138****1234；7-10 位纯数字固话保留首位/末两位（如 1****67） |
| 列表展示 | email | 脱敏: a***@example.com |
| 详情查看 | phone/email | 需解密接口 |
| 导出Excel | phone/email | 脱敏后导出 |
| 导出PDF | 全字段 | 脱敏 + 不可移除版权水印 |
| 存储 | phone/email/id_card | encryptable 加密为密文 |

## 7. 导出设计

### 7.1 Excel 导出

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

5000 行 × 9 列在 128M 工作进程下实测约占 65MiB，已接近安全上限，行数与列数上限不应同时提高。

导入：`POST /admin/import/users` 限制 5MB、2000 行、32000 单元格，可统一分配 `role_ids`。接口完成校验后获取 Redis 锁 `import_users_lock`（`SET NX EX 1800`），将文件以 `0600` 存入 `runtime/imports/`、写入任务元数据并入队，返回 HTTP 202 `{job_id, status:'queued'}`；已有任务返回 409。单进程 `import-worker`（`count=1`）串行消费队列，`GET /admin/import/status/{job}`（仅任务创建者）返回 `queued`/`processing`/`completed`/`failed` 及导入结果。Redis 故障时获取锁返回 503、入队失败返回 500 并清理文件/元数据/锁，不降级执行导入；worker 重启时按 processing 列表恢复未完成任务，60 秒宽限期避免重复消费，锁 TTL 兜底释放。`.xlsx` 先做 ZIP 解压预检（`xl/**.xml` > 8MB 或单元格 > 32000 返回 422）；`ImportController` 在 `IOFactory::load()` 前用 `createReaderForFile` + `setReadDataOnly(true)` + `listWorksheetInfo()` 检查所有工作表，任一工作表超过 2001 行或行 × 列超过 32000 单元格立即返回 422；加载后再用 `getHighestDataRow()` 复核。每行校验用户名 3-50、密码 6-32、姓名 ≤50、手机 ≤20、邮箱 ≤254、状态 0/1，失败行记录行号与通用原因；用户与角色关联同事务写入并按行回滚。2000 行 bcrypt 属 CPU 密集操作，锁 TTL 按最坏耗时设置。

### 7.2 PDF 导出

```
请求: POST /admin/export/pdf { type: table|dashboard, title, data }
  → buildPdfHtml() HTML + 内联CSS + 页头版权 + 页脚不可移除版权
  → Dompdf 渲染 A4 横向
  → 写入 runtime/tmp/ → download 响应
```

PDF 在渲染前校验负载形状：`columns`/`rows`/`stats` 必须是数组，`rows` 元素必须是数组，`columns` 与单元格必须是标量（`null` 按空字符串），并限制 `columns` ≤ 30000、表头+单元格 ≤ 30000、`rows` ≤ 5000、`stats` ≤ 200，超限返回 422，防止 128M 工作进程被大负载撑爆。

## 8. 部署架构

### 8.1 推荐拓扑

```
Nginx (:80/:443，TLS 终止 + 静态官网，等待 app 健康检查后启动) → app (webman :8791) → MySQL + Redis
init（一次性）：建表 + 创建管理员；backup（每日）：mysqldump + upload tar 到 deploy/data/backups，清理 180 天前操作日志
数据持久化到 deploy/data/，日志写 stdout/stderr 由 Docker 轮转
```

### 8.2 Docker Compose（单机生产部署）

生产编排位于 `deploy/`，单机运行 6 个服务，不依赖全文检索引擎：

| 服务 | 镜像/构建 | 说明 |
|------|----------|------|
| `nginx` | nginx:alpine | 80/443，TLS、静态官网、反向代理 + Gzip；日志写 stdout/stderr |
| `app` | 本地 `Dockerfile` 构建 | 8791（仅容器内网），PHP 8.3 + OPcache + redis/zip/gd（FreeType/JPEG）+ webman，以宿主机 UID/GID 运行 |
| `init` | 同 app 镜像 | 一次性建表 + 创建管理员，完成后退出 |
| `mysql` | mysql:8.0 | 主数据库，数据持久化到宿主机；错误/慢日志写 stderr |
| `redis` | redis:7-alpine | 缓存 / 限流 / 验证码，AOF 持久化 |
| `backup` | mysql:8.0 | 最小权限用户每日 `mysqldump` + 上传文件 tar 到 `deploy/data/backups`，保留 30 天；清理 180 天前操作日志 |

首次部署：

```bash
cd deploy
./generate-secrets.sh          # 生成 master .env、各服务 env、密钥、自签名证书与管理员密码
docker compose up -d --build
```

- 环境变量按服务隔离：`deploy/env/{app,init,mysql,redis,backup}.env`（目录 `700`、文件 `600`）；master `deploy/.env` 只保存域名、端口与 `APP_UID`/`APP_GID`。应用容器不持有 MySQL root 密码与初始化管理员密码。
- 密钥由 `generate-secrets.sh` 自动生成；默认幂等，`--regenerate` 先归档旧 `.env`、`env/` 与 `secrets/` 到 `deploy/secrets/archive/<时间戳>/`，默认只轮换 JWT/ENCRYPTION/RSA/Redis 等应用密钥，保留 `HASHIDS_SALT`、`ENCRYPTABLE_KEY`、现有 TLS 证书、数据库与管理员密码；Hashids 用 `--rotate-hashids` 显式轮换（旧链接 ID 失效），字段加密密钥、TLS、数据库密码、管理员密码分别用 `--rotate-encryptable`、`--rotate-tls`、`--rotate-db`、`--reset-admin` 显式轮换。
- `app`/`init` 以 `${APP_UID:-1000}:${APP_GID:-1000}` 运行，脚本用 `id -u`/`id -g` 写入 master `.env`，保证 `deploy/data/{runtime,upload}` 可写。
- 真实域名证书替换 `deploy/secrets/tls/fullchain.pem` 与 `privkey.pem`；管理员密码见 `deploy/secrets/admin_password.txt`；HSTS 默认关闭，配置真实证书后再启用。
- 持久化目录：`deploy/data/{mysql,redis,runtime,upload,backups}`；日志由 Docker 按 `10m × 3` 轮转。
- 各服务设置 CPU/内存/pids 上限：app 1.0/512M/256、init 0.5/256M/128、mysql 1.5/768M/512、redis 0.5/384M/128、nginx 0.5/128M/128、backup 0.5/256M/128；Redis 容器 384M 与 `--maxmemory 256mb --maxmemory-policy noeviction` 配套，预留 128M 给 AOF 重写/fork 与内存碎片。`noeviction` 防止黑名单、水位线、权限缓存等安全键在内存压力下被淘汰，写满时依赖失败关闭并告警。
- `nginx` 通过 `depends_on.app.condition: service_healthy` 等待应用 `/health` 通过后再启动，避免容器启动瞬间返回 502。
- 应用内 `log-cleanup` 进程（不监听端口，`count=1`）启动时先尽力清理一次，之后每日删除 180 天前的 `operation_log`，每批 10000 行、最多 10 批（单轮 100000 行）；与 backup 服务的清理互为兜底。`import-worker` 进程（不监听端口，`count=1`）串行消费 Redis 导入队列，启动时恢复未完成任务并清理过期任务文件。
- Nginx 在静态资源 location 内重复注入 `X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`、`Permissions-Policy` 与 `Cache-Control`；`deploy/nginx/default.conf` 与 `docs/nginx-security.conf` 均由 CI 执行 `nginx -t`。
- `backup` 使用最小权限用户 `backup_reader`（数据表 `SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES` + `erik_operation_log` 的 `DELETE`）；备份默认与 MySQL 同机同盘，异地副本由宿主机 cron 调用 `deploy/offsite-sync.sh`（读取 `deploy/.env` 的 `OFFSITE_TARGET`，支持 rsync 与 rclone）。

项目根目录的 `docker-compose.yml` 仅保留 4 个服务（nginx/app/mysql/redis）的本地快速开始编排；其 `.env.docker` 默认值为公开且故意无效的占位值，使用前先替换，禁止公网暴露，生产请使用 `deploy/`。

### 8.3 CI/CD

GitHub Actions 持续集成定义在 `.github/workflows/ci.yml`：
- PHP 语法检查 (`php -l`)
- PHPUnit 单元测试
- Flutter 静态分析 (`flutter analyze`)
- Docker 镜像构建 + PHP 扩展检查（redis/zip/gd、GD FreeType/JPEG）+ 根目录与 `deploy/` Compose 配置校验 + 两份 Nginx 配置 `nginx -t`（release 依赖 php/flutter/docker 三个 job）

### 8.4 数据库备份

生产编排的 `backup` 服务每日自动备份数据库与上传文件到 `deploy/data/backups`（保留 30 天），并清理 180 天前的操作日志；应用内 `log-cleanup` 进程同样每日清理（单轮最多 10 批 × 10000 行）。异地副本由宿主机 cron 调用 `deploy/offsite-sync.sh`（读取 `OFFSITE_TARGET`）同步。
`database/backup/backup.sh` — mysqldump + gzip 备份，自动清理 30 天前旧备份（宿主机直连数据库）。
`database/backup/restore.sh` — 交互式选择并恢复备份。

### 8.5 监控

`GET /metrics` 端点（`MetricsController`）以 Prometheus text format 暴露 5 个 gauge 指标：HTTP 请求总数、活跃用户数、数据库/Redis 连接状态、内存使用量。

### 8.6 环境要求

| 组件 | 最低版本 | 推荐配置 |
|------|---------|---------|
| PHP | 8.3+ | 8.3+ OPcache enabled |
| MySQL | 8.0+ | 单机 8.0，宿主目录持久化 |
| Redis | 6.x | 7.x，AOF 持久化 |
| Nginx | 1.20+ | 反向代理 + gzip + SSL |
| Flutter SDK | 3.41+ | 最新稳定版 |
| HarmonyOS | API 12 | DevEco Studio 5.x |
