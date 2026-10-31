# 开放管理后台 (open-admin)

基于 webman v2 + Flutter 的全栈管理后台系统。

## 版权声明

```
Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
```

> **不可修改、不可移除、不可逆。** 所有新建文件必须包含上述版权声明作为文件头注释。

## 功能清单

| 域 | 功能 |
|----|------|
| 认证 | 登录/刷新/登出 + 点击验证码 + 账号锁定 + 会话限制 + 刷新轮换 60s 宽限 |
| 仪表盘 | 实时统计/趋势/分布/日志（Redis 5m 缓存）|
| 用户 | CRUD + 批量删除/启禁用 + Excel 导入（5MB/2000 行上限）|
| 角色权限 | CRUD + 权限树 + RBAC method.path 鉴权 |
| 系统配置 | 键值对 CRUD |
| 操作审计 | 日志查询 + 8 平台来源端自动检测 |
| 文件 | 上传 + Excel/PDF 导出（敏感数据脱敏，>5000 行返回 422）|
| 安全 | 18 层纵深防御（XSS/SQL注入/CSRF/限流/CSP...，Redis 故障关闭）|
| 运维 | 健康检查/Prometheus 指标/API 文档（含 /apidoc/）/security.txt + Docker + CI/CD |
| 官网 | `site/` 响应式静态站点：中英文、站内搜索、项目介绍/功能/小安表情/架构图/安装/使用/版本日志 |

## 项目宠物 · 小安

盾形守卫机器人「小安」（Xiao An），取自「**安**全」与「管理后**台**」，守在中间件链的「防护」与「鉴权」两道关卡上。

- **样式单一来源**：`public/img/pet.svg`（纯 SVG，无脚本/无外部依赖，含 `prefers-reduced-motion` 降级）。修改此文件即同时更新站点首页、安装向导与浏览器图标，**不要再复制出第二份**。
- **已接入位置**：
  - `GET /` 站点首页 → `app/view/index/view.html`（路由在 `config/route.php` 顶部，免认证）
  - 安装向导 4 个页面 → `InstallController::layout()` 统一注入
  - 站点图标 → `<link rel="icon" type="image/svg+xml" href="/img/pet.svg">`（首页 + 安装向导 + `apps/flutter/web/index.html`）
- **配色规范**：主色 `#1677FF`、暖色天线 `#FA8C16`、校验绿 `#52C41A`；画布 `240 × 320`。
- **设计图**：`docs/diagrams/architecture.svg`（系统架构）、`features.svg`（功能设计）、`lifecycle.svg`（生命周期）——同为手写 SVG，与宠物保持同一套配色；README 与文档中直接引用。

## 技术栈

### 后端
- PHP 8.3+, webman v2 (workerman/webman)
- 数据库: MySQL 8.0+，表前缀 `erik_`
- 主键: BIGINT 非自增，由 `erikwang2013/snowflake-php` 生成
- API 层 ID 加解密: `erikwang2013/hashids`
- JWT 认证: `erikwang2013/jwt-webman`
- API 敏感数据加解密: `erikwang2013/encryption`
- 数据库敏感字段加解密: `erikwang2013/encryptable`
- 国家旗帜: `erikwang2013/season`

### 前端
- Flutter 3.x，源码目录 `apps/flutter/`
- Web 端按 PC 管理后台风格设计（非移动端 App 风格）
- 支持客户端和管理员端
- HarmonyOS ArkTS，源码目录 `apps/harmonyos/`

## 项目结构

```
open-admin/
├── app/
│   ├── admin/controller/       # 管理端控制器 (14 个)
│   │   ├── BaseController.php      # 基础控制器
│   │   ├── DashboardController.php # 仪表盘（Redis 缓存）
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
│   │   └── MetricsController.php   # Prometheus 监控指标
│   ├── api/v1/controller/      # API v1 控制器（URL 前缀 /api/v1 分发）
│   │   ├── CaptchaController.php
│   │   └── AuthController.php
│   ├── common/                 # 公共工具类
│   │   ├── HashidsService.php
│   │   ├── SnowflakeService.php
│   │   └── EncryptionService.php
│   ├── common/                 # 公共定义（含 Apidoc Definitions）
│   ├── middleware/             # 中间件（7 个）
│   │   ├── Cors.php            # 跨域（全局）
│   │   └── (已迁移至 erikwang2013/security-php 包)  # 31种攻击检测
│   │   ├── RateLimit.php       # Redis 限流（全局，Lua 原子化，故障关闭）
│   │   ├── AdminAuth.php       # JWT 认证 + 黑名单（Redis 故障关闭）
│   │   ├── AdminPermission.php # RBAC 权限校验（Redis 60s 缓存）
│   │   └── OperationLog.php    # 操作日志自动记录（含来源端检测）
│   ├── model/                  # 数据模型
│   ├── view/index/view.html    # 站点首页模板（GET /，项目宠物 + 入口导航）
│   ├── queue/                  # 队列任务
│   └── process/                # 进程 (Http, Monitor)
├── apps/
│   ├── flutter/                # Flutter Web 管理后台
│   │   └── lib/app/
│   │       ├── pages/          # 6 个完整页面
│   │       │   ├── dashboard/  # 仪表盘
│   │       │   ├── login/      # 登录
│   │       │   ├── user/       # 用户管理
│   │       │   ├── role/       # 角色权限
│   │       │   ├── config/     # 系统配置
│   │       │   ├── log/        # 操作日志
│   │       │   └── profile/    # 个人中心
│   │       ├── services/       # ApiService + AuthService
│   │       ├── layouts/        # 响应式布局
│   │       └── theme/          # Material 3 主题
│   └── harmonyos/              # HarmonyOS 客户端
├── config/                     # 配置文件
│   ├── route.php               # 路由 + API 版本策略
│   └── middleware.php           # 全局中间件注册
├── database/
│   ├── install.sql             # 全量安装脚本（合并所有 SQL）
│   └── backup/                 # 数据库备份脚本
│       ├── backup.sh           # mysqldump+gzip，30天保留（宿主机直连）
│       └── restore.sh          # 交互式恢复
├── docs/                       # 文档
│   ├── ARCHITECTURE.md         # Mermaid 架构图
│   ├── DESIGN.md               # 设计文档
│   ├── SECURITY.md             # 安全架构设计
│   ├── API.md                  # API 参考文档
│   ├── nginx-security.conf     # Nginx 安全参考配置
│   ├── diagrams/               # 图表
│   │   ├── architecture.svg    # 系统架构设计图（手写 SVG）
│   │   ├── features.svg        # 功能设计图（手写 SVG）
│   │   ├── lifecycle.svg       # 生命周期图（手写 SVG）
│   │   └── 01..12-*.md         # 分解架构图（Mermaid，中英双语）
│   └── superpowers/            # 规范与计划
│       ├── specs/              # 设计规范
│       └── plans/              # 实现计划
├── public/                     # 公共入口
│   ├── apidoc/index.html       # 轻量 API 调试页（同源 /api/docs）
│   └── img/pet.svg             # 项目宠物「小安」（SVG，兼作站点图标）
├── site/                       # 响应式静态官网（中英文、搜索、安装/架构/版本日志）
├── deploy/                     # 单机生产编排（compose + 密钥生成 + Nginx/TLS）
├── runtime/                    # 运行时文件
├── tests/                      # 测试
├── vendor/                     # Composer 依赖
├── CLAUDE.md                   # 本文件
├── README.md                   # 中文说明
├── docs/translations/          # 英文文档（README.en / CLAUDE.en）
├── .env                        # 环境变量（不纳入版本控制）
├── .env.example                # 环境变量模板
├── .env.docker                 # Docker 环境变量（本地快速开始）
├── composer.json               # PHP 依赖
├── Dockerfile                  # Docker 构建
├── docker-compose.yml          # 本地快速开始编排（4 个服务）
└── .github/
    └── workflows/
        └── ci.yml              # CI/CD 流水线（PHP/Flutter 测试 + Docker/Compose/Nginx 校验）
```

## 中间件执行链

```
全局:  Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → {路由中间件}
/admin: Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → AdminAuth → AdminPermission → OperationLog → Controller
/api/v1: Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → Controller（版本体现在 URL 前缀中）
/health: Cors → Locale(Accept-Language) → SecurityMiddleware(erikwang2013/security-php) → RateLimit → Controller
```

> **注意**: 无需权限校验的管理端接口（如个人中心查看）放到 `/admin` 组外单独注册，仅加 `AdminAuth` 中间件。组内路由由 `AdminPermission` 校验 `method.path` 格式的权限标识。
> 
> **Redis 前缀**: 所有 key 自动加 `open-admin:` 前缀，通过 `.env` 的 `REDIS_PREFIX` 可自定义。

## 安全增强

- **攻击检测**：erikwang2013/security-php 包（31 种检测器：XSS/SQL注入/命令注入/路径遍历/SSRF/XXE/JNDI/反序列化/JWT攻击/CSRF/敏感数据泄漏等 + HTTP方法校验/请求体大小限制/Content-Type校验 + IP攻击升级黑名单）
- **CSP 头**：Content-Security-Policy + X-Permitted-Cross-Domain-Policies 注入所有响应
- **账号锁定**：连续 5 次登录失败，账号锁定 15 分钟
- **并发会话限制**：同一用户最多 3 个有效 Token，超出时最旧 Token 加入黑名单
- **刷新轮换**：refresh token 换取新对后旧 token 加入黑名单，旧 token 在 60 秒宽限期内重放返回同一对 token；HarmonyOS 端并发 401 只发起一次刷新
- **超级管理员保护**：禁止删除当前登录账号或修改其状态；系统必须保留至少一名启用的超级管理员；`super_admin` 角色不可编辑/删除
- **Redis 故障关闭**：`/api/`、`/admin/` 的鉴权与限流在 Redis 不可用时返回 503（健康检查、指标等非 API 路由放行）
- **security.txt**：`/.well-known/security.txt` RFC 9116 端点
- **Nginx 安全配置**：`docs/nginx-security.conf` 反向代理安全加固参考；静态资源在独立 location 重复安全响应头，CI 用 `nginx -t` 校验

## API 版本策略

版本号体现在 URL 前缀中（`/api/v1/...`、`/api/v2/...`），不使用请求头：

```bash
curl http://localhost:8791/api/v1/auth/login
```

新增版本只需创建 `app/api/{version}/controller/` 目录，并在 `config/route.php` 注册对应版本的路由组。

## 限流策略

Redis 滑动窗口（Lua 原子化），默认 60 次/分钟/IP/路由：
- 登录 `/api/v1/auth/login`: 10 次/分钟
- 响应头: `X-RateLimit-Limit/Remaining/Reset`，超限附加 `Retry-After`

> Redis 不可用时，`/api/`、`/admin/` 的限流与鉴权失败关闭并返回 503；健康检查、指标等非 API 路由不受影响。
>
> `RateLimit::$sensitive` 的键必须与 `config/route.php` 中的**完整路径**一致（含 `/api/v{n}` 版本前缀），否则敏感路由会静默退回默认 60 次/分钟。

## 代码规范

### PHP
- 全局函数/类引用不加前置 `\`，使用 `use` 导入
- 配置文件必须包含中文注释说明每个配置项的含义
- 所有新建 `.php` 文件头必须包含版权声明
- **Redis 通过 `support\Redis` 工具类访问**（单例连接池，自动读取 `REDIS_HOST/PORT/PASSWORD/DB` 环境变量），所有 key 自动添加前缀（默认 `open-admin:`，通过 `REDIS_PREFIX` 环境变量可配置）
- **路由权限**: `/admin` 组内路由需 `method.path` 格式的权限（如 `get.admin/dashboard`），无需权限校验的路由放到组外仅加 `AdminAuth` 中间件
- **CORS**: 新增请求头时需同步更新 `Cors.php` 中间件和 `route.php` fallback 的 `Access-Control-Allow-Headers`
- **超级管理员保护**: `RoleController` 的 `update`/`destroy` 禁止操作 `slug == 'super_admin'` 的角色；`UserController` 禁止删除当前登录账号或修改其状态，且系统必须保留至少一名启用的超级管理员
- webman 将 PHP Warning 转为异常，未定义的属性/变量会导致 500 错误

### 数据库
- 表前缀: `erik_`
- 主键 `id`: BIGINT 类型，非自增，由 snowflake 生成
- 敏感字段使用 `erikwang2013/encryptable` trait 自动加解密
- 迁移文件使用 SQL 格式

### Flutter
- Web 端布局使用 PC 管理后台风格（侧边栏 + 顶栏 + 内容区）
- 使用 GetX 状态管理，**所有 API 请求必须通过 `ApiService` 单例**（Dio + JWT 拦截器），禁止创建独立 Dio 实例或硬编码 baseUrl
- Token 持久化使用 `shared_preferences`
- 响应式断点: 移动端 (< 768px) 与桌面端 (>= 768px)
- **页面头部 Row 必须使用 `Wrap`**，防止侧边栏展开时溢出；筛选 ChoiceChip 必须包在 `Obx` 内才能响应式更新
- **DataTable 必须包裹 `SingleChildScrollView(scrollDirection: Axis.horizontal)`** 防止列溢出
- 独立页面（如 ProfilePage）必须包含 `Scaffold`，否则 `TextField` 等 Material 组件会报 "No Material widget found"
- 侧边栏展开/收起时用 `_showCollapsedContent` 延迟切换内容，避免动画期间 RenderFlex 溢出

### HarmonyOS
- 使用 `@ohos.net.http` 原生 HTTP 客户端
- Token 无感刷新：401 时自动调用 `/api/v1/auth/refresh`；并发 401 只发起一次刷新请求（单飞）
- 刷新失败自动重定向登录页

## 部署

### 快速开始（4 个服务）

项目根目录 `docker-compose.yml` 提供本地/单机试跑编排：`nginx`、`app`、`mysql`、`redis`。

```bash
cp .env.docker .env
docker compose up -d
```

> **仅限本地试跑**：`.env.docker` 的密码/密钥是公开且故意无效的占位值，使用前先替换，禁止直接暴露公网；生产使用 `deploy/`。

### 生产部署（单机 6 个服务）

生产编排位于 `deploy/`，单机运行 `nginx`、`app`、`init`（一次性初始化）、`mysql`、`redis`、`backup`（每日自动备份）；不依赖全文检索引擎。

```bash
cd deploy
./generate-secrets.sh          # 生成 master .env、各服务 env、密钥、自签名 TLS 证书与管理员密码
docker compose up -d --build
```

- 环境变量按服务隔离：`deploy/env/{app,init,mysql,redis,backup}.env`（目录 `700`、文件 `600`）；master `deploy/.env` 仅保存 compose 插值（域名、端口、`APP_UID`/`APP_GID`）。应用容器不持有 MySQL root 密码与初始化管理员密码。生成脚本原位更新已知键、追加缺失键，保留自定义项。
- `generate-secrets.sh` 默认幂等：只补齐缺失值。生成 `deploy/secrets/rsa_public_key.pem`、管理员密码 `deploy/secrets/admin_password.txt`；RSA 私钥写入 `app.env` 的 `RSA_PRIVATE_KEY`。
- `--regenerate` 先归档当前 `deploy/.env`、`deploy/env/` 与 `deploy/secrets/` 到 `deploy/secrets/archive/<时间戳>/`，默认只轮换 JWT/传输层加密/RSA/Redis 等应用密钥，并保留 Hashids 盐值、`ENCRYPTABLE_KEY`、现有 TLS 证书、`DB_PASSWORD`、`MYSQL_ROOT_PASSWORD` 与管理员密码，不会破坏已有数据库。需要轮换字段加密密钥用 `--rotate-encryptable`（旧密钥自动加入 `ENCRYPTION_PREVIOUS_KEYS`，旧数据仍可读取；完成数据重加密后再移除旧密钥），轮换 TLS 用 `--rotate-tls`，轮换数据库密码用 `--rotate-db`（脚本打印需手动执行的 `ALTER USER`），重置管理员密码用 `--reset-admin`；确需轮换 Hashids 盐值用 `--rotate-hashids`（旧链接中的 ID 将失效）。
- 宿主机属主：`app`/`init` 以 `${APP_UID:-1000}:${APP_GID:-1000}` 运行，脚本用 `id -u`/`id -g` 写入 master `.env`，保证 `deploy/data/{runtime,upload}` 可写。
- TLS 默认按 `DOMAIN`（默认 `localhost`）生成自签名证书；真实域名证书直接替换 `deploy/secrets/tls/fullchain.pem` 与 `privkey.pem`，脚本默认不会覆盖已有证书（`--rotate-tls` 除外）。HSTS 默认关闭，配置真实域名证书后再启用。
- 端口：Nginx `80/443`（80 仅保留 `/.well-known/acme-challenge/`，其余 301 到 HTTPS）；webman 仅在容器内监听 `8791`。
- 日志：Nginx/MySQL 写 stdout/stderr，Docker 按 `10m × 3` 轮转；用 `docker compose logs -f app` 查看。宿主机持久化目录：`deploy/data/{mysql,redis,runtime,upload,backups}`。
- 资源限制：MySQL 768M、Redis 256M、app 512M、backup/init 256M、Nginx 128M（`deploy/docker-compose.yml` 的 `deploy.resources.limits`）。
- 自动备份：`backup` 服务每天使用最小权限用户 `backup_reader`（应用库只读 + `erik_operation_log` 的 `DELETE`）执行 `mysqldump` 到 `deploy/data/backups`，同时把 `deploy/data/upload` 打包为 tar；备份保留 30 天，并每天清理 180 天前的操作日志。
- 异地备份：备份目录可用 rsync 或 rclone 同步到远端（示例见 `deploy/README.md`）。
- RSA 公钥：`GET /api/v1/auth/public-key` 返回当前公钥，Flutter 登录前动态获取并在失败时回退内置公钥；`--regenerate` 换钥后需确认该端点可达，依赖内置回退的旧客户端需重新构建。

### CI/CD

`.github/workflows/ci.yml` 定义 GitHub Actions 流水线：

- PHP 语法检查 (`php -l`)
- PHPUnit 单元测试
- Flutter 静态分析 (`flutter analyze`)
- Docker 镜像构建与运行依赖检查（`php -m` 的 redis/zip/gd，`gd_info` 的 FreeType/JPEG）
- Compose/Nginx 配置校验：根目录 compose、临时生成的 `deploy/` compose，以及两套 Nginx 配置 `nginx -t`
- `release` 任务依赖 `php`、`flutter`、`docker` 三个任务

### 数据库备份

生产编排的 `backup` 服务每日使用最小权限用户备份数据库与上传文件到 `deploy/data/backups`（保留 30 天），并清理 180 天前的操作日志；异地同步示例见 `deploy/README.md`。
`database/backup/backup.sh` — mysqldump + gzip，自动清理 30 天前旧备份（宿主机直连数据库）。
`database/backup/restore.sh` — 交互式恢复，列出可用备份供选择。

### 监控

`GET /metrics` 端点（`MetricsController`）输出 Prometheus text format，包含 5 个 gauge 指标：
- `openadmin_http_requests_total` — 请求总数
- `openadmin_active_users` — 活跃用户数
- `openadmin_db_connection_status` — 数据库连接状态 (0/1)
- `openadmin_redis_connection_status` — Redis 连接状态 (0/1)
- `openadmin_memory_usage_bytes` — 内存使用量
