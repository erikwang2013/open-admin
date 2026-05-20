# 开放管理后台 (open-admin)

基于 webman v2 + Flutter 的全栈管理后台系统。

> [English version](README_EN.md) | [架构设计图](docs/ARCHITECTURE.md) | [设计文档](docs/DESIGN.md)


## 技术栈

| 层 | 技术 | 说明 |
|---|------|------|
| 后端框架 | webman v2 (workerman) | 超高性能 PHP 常驻进程框架 |
| PHP 版本 | 8.3+ | |
| 数据库 | MySQL 8.0+ | 表前缀 `erik_`，BIGINT 非自增主键 |
| 搜索引擎 | Elasticsearch | 通过 `webman-scout` 同步与查询 |
| 管理端前端 | Flutter 3.x | Web 端为 PC 管理后台风格（`apps/flutter/`） |
| 移动端 | HarmonyOS ArkTS | 鸿蒙原生客户端（`apps/harmonyos/`），支持手机/平板/2in1 |

## 核心依赖

| 包 | 用途 |
|---|------|
| `erikwang2013/snowflake-php` | Snowflake 算法生成全局唯一 BIGINT 主键 |
| `erikwang2013/hashids` | API 层 ID 加解密，隐藏真实数据库 ID |
| `erikwang2013/jwt-webman` | JWT 认证令牌签发与校验 |
| `erikwang2013/encryption` | 接口传输层敏感数据加解密 |
| `erikwang2013/encryptable` | 数据库存储层敏感字段自动加解密 |
| `erikwang2013/webman-scout` | Elasticsearch 数据同步与全文检索 |
| `erikwang2013/season` | 国家旗帜数据 |
| `erikwang2013/poster-php` | 点击验证码生成与校验 + 海报生成 |
| `phpoffice/phpspreadsheet` | Excel 导出 |
| `barryvdh/laravel-dompdf` | PDF 导出（基于 Dompdf） |

## 项目结构

```
open-admin/
├── app/
│   ├── admin/controller/       # 管理端控制器
│   │   ├── DashboardController.php # 仪表盘（Redis缓存）
│   │   ├── UserController.php      # 用户 CRUD + 批量操作
│   │   ├── RoleController.php      # 角色 CRUD
│   │   ├── PermissionController.php# 权限 CRUD
│   │   ├── ConfigController.php    # 系统配置 CRUD
│   │   ├── LogController.php       # 操作日志查询
│   │   ├── ProfileController.php   # 个人中心 + 登出
│   │   ├── ExportController.php    # Excel/PDF 导出
│   │   ├── ImportController.php    # Excel 导入用户
│   │   ├── UploadController.php    # 文件上传
│   │   ├── HealthController.php    # 健康检查
│   │   ├── DocsController.php      # OpenAPI 文档
│   │   └── BaseController.php      # 基础控制器
│   ├── api/
│   │   └── v1/controller/          # API v1 控制器（版本由请求头 API-Version 控制）
│   │       ├── CaptchaController.php # 点击验证码
│   │       └── AuthController.php    # 登录/注册/刷新令牌
│   ├── common/                 # 公共工具类
│   │   ├── HashidsService.php  # ID 编解码
│   │   ├── SnowflakeService.php# Snowflake ID 生成
│   │   └── EncryptionService.php # 数据加解密 + 脱敏
│   ├── middleware/             # 中间件
│   │   ├── Cors.php            # 跨域
│   │   ├── SecurityFilter.php  # 攻击检测拦截（XSS/SQL注入/路径遍历/命令注入/CSRF）
│   │   ├── RateLimit.php       # Redis 限流（滑动窗口 + 响应头）
│   │   ├── ApiVersion.php      # API 版本校验
│   │   ├── AdminAuth.php       # JWT 认证 + 黑名单
│   │   ├── AdminPermission.php # RBAC 权限校验
│   │   └── OperationLog.php    # 操作日志自动记录（含来源端检测）
│   └── model/                  # 数据模型
├── apps/
│   ├── flutter/                # Flutter Web 管理后台（PC 风格）
│   │   └── lib/app/
│   │       ├── pages/          # 5 个完整页面（仪表盘/用户/角色/配置/日志/个人中心）
│   │       ├── services/       # ApiService（JWT 拦截器）+ AuthService（Token 持久化）
│   │       └── layouts/        # 响应式管理后台布局（侧边栏+顶栏+内容区）
│   └── harmonyos/              # HarmonyOS 原生客户端（Token 无感刷新）
├── config/                     # 配置文件（含中文注释）
│   ├── route.php               # 路由 + API 版本策略
│   ├── middleware.php           # 全局中间件注册
│   └── ...                     # 各组件配置
├── database/migrations/        # SQL 迁移文件（含权限种子数据）
├── public/                     # 公共入口
├── runtime/                    # 运行时文件
└── vendor/                     # Composer 依赖
```

## 环境要求

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41（仅前端开发需要）
- Elasticsearch >= 7.x（可选，搜索功能需要）

## 快速开始

### 1. 安装依赖

```bash
composer install
```

### 2. 配置环境变量

复制并修改环境变量（可选，不配置则使用 `config/*.php` 中的默认值）:

```bash
cp .env.example .env
```

关键配置项：

| 环境变量 | 说明 | 默认值 |
|---------|------|--------|
| `JWT_SECRET` | JWT 签名密钥 | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Hashids 盐值 | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | API 加密密钥 | 32 字节默认值 |
| `SNOWFLAKE_DATACENTER_ID` | 数据中心 ID (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | 工作节点 ID (0-31) | `1` |
| `SCOUT_HOSTS` | ES 地址 | `http://localhost:9200` |

**生产环境务必修改所有密钥为随机字符串。**

### 3. 初始化数据库

按顺序执行 `database/migrations/` 下的 SQL 文件：

```bash
# 建表
mysql -u root -p < database/migrations/2026_05_16_000000_init_tables.sql
# 播种权限数据
mysql -u root -p < database/migrations/2026_05_20_000001_seed_permissions.sql
```

### 4. 启动服务

```bash
php start.php start
```

默认监听 `http://0.0.0.0:8787`。

### 5. 启动前端（可选）

**Flutter 管理后台（Web 端）:**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**HarmonyOS 客户端（手机端）:**

使用 DevEco Studio 打开 `apps/harmonyos/` 目录，连接真机或模拟器运行。


## 数据库规范

- **表前缀**: `erik_`
- **主键**: 所有表主键均为 `id BIGINT UNSIGNED NOT NULL`，**禁用 AUTO_INCREMENT**
- **ID 生成**: 主键 ID 由应用层 `SnowflakeService::generate()` 生成，分布式唯一
- **必备字段**: 每张表必须包含 `id`, `created_at`, `updated_at`
- **软删除**: 需要软删除的表添加 `deleted_at DATETIME DEFAULT NULL`
- **敏感字段**: 手机号、邮箱、身份证号等使用 `encryptable` 插件自动加解密，数据库字段使用 `VARCHAR(500)` 存储密文

## API 规范

### 统一响应格式

```json
{
    "code": 0,
    "message": "success",
    "data": {}
}
```

### 业务错误码

| 错误码 | 含义 | 说明 |
|-------|------|------|
| `0` | 成功 | |
| `400` | 请求参数错误 | |
| `401` | 未登录（Token 无效或过期） | |
| `403` | 无权限 / 安全拦截 | RBAC 鉴权失败 / SecurityFilter 攻击检测 |
| `404` | 资源不存在 | |
| `422` | 参数验证失败 | |
| `413` | 请求体过大 | SecurityFilter 触发，超过 10MB |
| `415` | 不支持的媒体类型 | SecurityFilter 触发，Content-Type 非 JSON |
| `429` | 请求过于频繁 | RateLimit 触发 |
| `500` | 服务器内部错误 | |

### ID 处理

- **请求/响应中的 ID**: 使用 hashids 加密为字符串，不暴露真实数据库 ID
- **接口路径**: `GET /admin/user/{hashid}` — 路径中的 `{id}` 为 hashid 字符串
- **数据库存储**: BIGINT 原值，由 snowflake 生成

### API 版本

API 版本通过请求头控制，**不在 URL 中体现**：

```http
API-Version: v1
```

- 未携带版本号时默认使用 `v1`
- 不支持的版本返回 `400 Bad Request`
- 新增版本时只需创建 `app/api/{version}/controller/` 目录，中间件注册新版本即可

### 限流

基于 Redis 滑动窗口算法，默认 60 次/分钟/IP/路由。敏感接口更严格：
- 登录：10 次/分钟
- 注册：5 次/分钟

响应头包含 `X-RateLimit-Limit`、`X-RateLimit-Remaining`、`X-RateLimit-Reset`。超限返回 429 并附带 `Retry-After`。

### 中间件架构

全局中间件对所有请求生效，按序执行：

```
Cors（跨域预处理 + 响应头）
  → SecurityFilter（XSS/SQL注入/路径遍历/命令注入/CSRF 攻击拦截）
  → RateLimit（Redis 滑动窗口限流）
  → ApiVersion（API 版本校验，/api 路由组）
  → AdminAuth（JWT 认证 + 黑名单，/admin 路由组）
  → AdminPermission（RBAC 鉴权，/admin 路由组）
  → OperationLog（POST/PUT/DELETE 自动记录，含来源端检测，/admin 路由组）
```

`/health` 和 `/api/docs` 为公开端点，仅经过 `Cors → SecurityFilter → RateLimit`。

### 认证

登录与注册需要先通过**点击验证码**校验：

1. 客户端请求 `POST /api/captcha/generate` 获取验证码图片（base64 PNG）和文字目标列表
2. 用户按顺序点击图中对应文字位置，收集点击坐标 `[{x, y}, ...]`
3. 登录时一并提交 `captcha_key` 和 `clicks`，服务端先校验验证码再校验凭证

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

管理端后续接口需要 JWT 认证：

```http
Authorization: Bearer <token>
```

登录成功后返回 access_token，有效期 2 小时；另返回 refresh_token，有效期 14 天。

登出时 Token 加入 Redis 黑名单，有效期内不可复用。POST /admin/profile/logout

### 敏感操作二次确认

删除用户、角色、权限等敏感操作需要在请求体中传入当前登录用户的 `password` 进行身份二次确认：

```http
DELETE /admin/user/{id}
Content-Type: application/json
Authorization: Bearer <token>

{ "password": "******" }
```

## API 列表

> 所有 `/api/*` 接口需要在请求头中携带 `API-Version: v1`（不传则默认 v1）。

### 公开接口

| 方法 | 路径 | 说明 |
|-----|------|------|
| `GET` | `/health` | 健康检查（DB/Redis/ES 状态） |
| `GET` | `/api/docs` | OpenAPI 3.0 规范文档 |
| `POST` | `/api/captcha/generate` | 生成点击验证码 |
| `POST` | `/api/captcha/verify` | 校验点击验证码 |
| `POST` | `/api/auth/login` | 登录（需 captcha） |
| `POST` | `/api/auth/register` | 注册（需 captcha） |
| `POST` | `/api/auth/refresh` | 刷新令牌 |

### 管理端接口（需 JWT + RBAC）

| 方法 | 路径 | 说明 |
|-----|------|------|
| `GET` | `/admin/dashboard` | 仪表盘数据（Redis 缓存 5 分钟） |
| `GET` | `/admin/user` | 用户列表（分页 + 搜索） |
| `POST` | `/admin/user` | 创建用户 |
| `GET` | `/admin/user/{id}` | 用户详情 |
| `PUT` | `/admin/user/{id}` | 更新用户 |
| `DELETE` | `/admin/user/{id}` | 删除用户（软删除，需密码确认） |
| `POST` | `/admin/user/batch/destroy` | 批量删除用户（需密码确认） |
| `POST` | `/admin/user/batch/status` | 批量启用/禁用用户 |
| `GET` | `/admin/role` | 角色列表 |
| `POST` | `/admin/role` | 创建角色 |
| `PUT` | `/admin/role/{id}` | 更新角色 |
| `DELETE` | `/admin/role/{id}` | 删除角色（需密码确认） |
| `GET` | `/admin/permission` | 权限树 |
| `POST` | `/admin/permission` | 创建权限 |
| `PUT` | `/admin/permission/{id}` | 更新权限 |
| `DELETE` | `/admin/permission/{id}` | 删除权限（级联子权限，需密码确认） |
| `GET` | `/admin/config` | 系统配置列表 |
| `POST` | `/admin/config` | 创建配置项 |
| `PUT` | `/admin/config/{id}` | 更新配置项 |
| `DELETE` | `/admin/config/{id}` | 删除配置项（需密码确认） |
| `GET` | `/admin/log` | 操作日志（分页 + 筛选） |
| `PUT` | `/admin/profile` | 更新个人信息 |
| `PUT` | `/admin/profile/password` | 修改密码 |
| `POST` | `/admin/profile/logout` | 登出（JWT 黑名单） |
| `POST` | `/admin/export/excel` | 导出 Excel |
| `POST` | `/admin/export/pdf` | 导出 PDF |
| `POST` | `/admin/import/users` | Excel 导入用户 |
| `POST` | `/admin/upload` | 文件上传（图片/文档，最大 10MB） |

## 前端说明

### Flutter 管理后台（PC 风格）

- **布局**: 侧边栏（可折叠 64px/240px）+ 顶栏 + 内容区，响应式三断点（手机/平板/桌面）
- **页面**: 登录、仪表盘、用户管理、角色权限、系统配置、操作日志、个人中心
- **状态管理**: GetX（`ApiService` 单例 + `AuthService` Token 持久化）
- **仪表盘**: 统计卡片、趋势折线图（fl_chart）、饼图、最近操作日志
- **导出**: Excel/PDF 导出，PDF 含不可移除版权信息
- **批量操作**: 多选批量删除、批量启用/禁用
- **主题**: Material 3 浅色/深色双主题

### HarmonyOS 移动端

- **页面**: 登录、仪表盘、用户列表/详情、个人中心
- **认证**: JWT Bearer + 401 自动无感刷新 Token，刷新失败自动重定向登录页
- **存储**: Token 通过 AppStorage 管理

## 开发规范

- 全局函数/类引用不加前置 `\`，统一使用 `use` 导入
- 所有 PHP 文件头部必须包含版权声明
- 所有配置文件必须包含中文注释说明
- 数据库主键必须由应用层 snowflake 生成，禁止自增
- API 层所有参数和响应中的 ID 必须通过 hashids 加解密

## 开源不易，欢迎支持

| 微信 | 支付宝 |
|:---:|:---:|
| ![微信](./docs/weixinpay.png "微信") | ![支付宝](./docs/alipay.png "支付宝") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
