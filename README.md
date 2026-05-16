# 开放管理后台 (open-admin)

基于 webman v2 + Flutter 的全栈管理后台系统。

> [English version](README_EN.md)

## 版权

```
Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
```

本版权声明不可修改、不可移除、不可逆。所有项目文件均受此版权保护。

## 技术栈

| 层 | 技术 | 说明 |
|---|------|------|
| 后端框架 | webman v2 (workerman) | 超高性能 PHP 常驻进程框架 |
| PHP 版本 | 8.3+ | |
| 数据库 | MySQL 8.0+ | 表前缀 `erik_`，BIGINT 非自增主键 |
| 搜索引擎 | Elasticsearch | 通过 `webman-scout` 同步与查询 |
| 前端 | Flutter 3.x | Web 端为 PC 管理后台风格 |

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
| `phpoffice/phpspreadsheet` | Excel 导出 |
| `barryvdh/laravel-dompdf` | PDF 导出（基于 Dompdf） |

## 项目结构

```
open-admin/
├── app/                        # 应用目录
│   ├── admin/controller/       # 管理端控制器
│   ├── api/controller/         # 客户端 API 控制器（预留）
│   ├── common/                 # 公共工具类
│   │   ├── HashidsService.php  # ID 编解码
│   │   ├── SnowflakeService.php# Snowflake ID 生成
│   │   └── EncryptionService.php # 数据加解密 + 脱敏
│   ├── middleware/             # 中间件
│   │   ├── AdminAuth.php       # JWT 认证
│   │   └── AdminPermission.php # RBAC 权限校验
│   └── model/                  # 数据模型
├── apps/                       # Flutter 管理后台前端
│   └── admin_app/
├── config/                     # 配置文件（含中文注释）
│   ├── snowflake.php           # Snowflake 配置
│   ├── hashids.php             # Hashids 配置
│   ├── jwt.php                 # JWT 配置
│   ├── encryption.php          # API 加解密配置
│   ├── encryptable.php         # 数据库加解密配置
│   ├── scout.php               # ES 搜索引擎配置
│   └── plugin/                 # 插件自动生成的配置
├── database/migrations/        # SQL 迁移文件
├── public/                     # 公共入口
├── runtime/                    # 运行时文件（日志、缓存、临时导出文件）
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

执行 `database/migrations/` 下的 SQL 文件：

```bash
mysql -u root -p < database/migrations/2026_05_16_000000_init_tables.sql
```

### 4. 启动服务

```bash
php start.php start
```

默认监听 `http://0.0.0.0:8787`。

### 5. 启动前端（可选）

```bash
cd apps/admin_app
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

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

| 错误码 | 含义 |
|-------|------|
| `0` | 成功 |
| `400` | 请求参数错误 |
| `401` | 未登录（Token 无效或过期） |
| `403` | 无权限 |
| `404` | 资源不存在 |
| `422` | 参数验证失败 |
| `500` | 服务器内部错误 |

### ID 处理

- **请求/响应中的 ID**: 使用 hashids 加密为字符串，不暴露真实数据库 ID
- **接口路径**: `GET /admin/user/{hashid}` — 路径中的 `{id}` 为 hashid 字符串
- **数据库存储**: BIGINT 原值，由 snowflake 生成

### 认证

管理端所有接口需要 JWT 认证：

```http
Authorization: Bearer <token>
```

登录成功后返回 access_token，有效期 2 小时；另返回 refresh_token，有效期 14 天。

## 管理端 API 列表

| 方法 | 路径 | 说明 |
|-----|------|------|
| `GET` | `/admin/dashboard` | 仪表盘数据（统计卡片、趋势图、分布图） |
| `GET` | `/admin/user` | 用户列表（分页 + 搜索） |
| `POST` | `/admin/user` | 创建用户 |
| `GET` | `/admin/user/{id}` | 用户详情 |
| `PUT` | `/admin/user/{id}` | 更新用户 |
| `DELETE` | `/admin/user/{id}` | 删除用户（软删除） |
| `GET` | `/admin/role` | 角色列表 |
| `POST` | `/admin/role` | 创建角色 |
| `PUT` | `/admin/role/{id}` | 更新角色 |
| `DELETE` | `/admin/role/{id}` | 删除角色 |
| `GET` | `/admin/permission` | 权限树 |
| `POST` | `/admin/permission` | 创建权限 |
| `PUT` | `/admin/permission/{id}` | 更新权限 |
| `DELETE` | `/admin/permission/{id}` | 删除权限（级联子权限） |
| `POST` | `/admin/export/excel` | 导出 Excel |
| `POST` | `/admin/export/pdf` | 导出 PDF |

## 前端说明

Flutter 应用采用了 PC 管理后台风格设计：

- **布局**: 侧边栏（可折叠 64px/240px）+ 顶栏 + 内容区
- **仪表盘**: 统计卡片、趋势折线图、饼图、最近操作日志
- **导出**: 支持 Excel 和 PDF 导出，PDF 文件含不可移除版权信息
- **主题**: Material 3 浅色/深色双主题

Web 端与移动端 App 的差异：
- Web 使用侧边栏导航，App 使用底部导航栏
- Web 表格密度高，支持多选批量操作
- Web 交互以鼠标悬停和右键菜单为主

## 开发规范

- 全局函数/类引用不加前置 `\`，统一使用 `use` 导入
- 所有 PHP 文件头部必须包含版权声明
- 所有配置文件必须包含中文注释说明
- 数据库主键必须由应用层 snowflake 生成，禁止自增
- API 层所有参数和响应中的 ID 必须通过 hashids 加解密

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
