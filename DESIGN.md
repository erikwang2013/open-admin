# 开放管理后台 — 设计文档

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. 系统架构

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
│  │  ┌──────────┐  ┌──────────────┐                       │    │
│  │  │  MySQL   │  │ Elasticsearch│                       │    │
│  │  │ (主存储)  │  │ (全文检索)    │                       │    │
│  │  └──────────┘  └──────────────┘                       │    │
│  └──────────────────────────────────────────────────────┘    │
│                       webman v2                               │
└──────────────────────────────────────────────────────────────┘
```

## 2. 后端架构

### 2.1 分层设计

| 层 | 目录 | 职责 |
|---|------|------|
| 路由 | `config/route.php` | URL 到控制器的映射，中间件绑定 |
| 中间件 | `app/middleware/` | 认证(JWT)、授权(RBAC) |
| 控制器 | `app/admin/controller/` | 请求参数校验、调用业务逻辑、响应格式化 |
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
  AdminAuth ──────────► JWT 验证，注入 $request->adminId
  │ (失败返回 401)
  ▼
  AdminPermission ────► RBAC 权限校验
  │ (失败返回 403)
  ▼
Controller::method()
  │
  ├─► 参数验证 (validator)
  ├─► decodeId() — hashid → BIGINT
  ├─► Model 操作 (自动 encryptable 加解密)
  ├─► encodeId() — BIGINT → hashid
  └─► Response JSON
```

### 2.3 中间件执行顺序

```
AdminAuth                    AdminPermission
┌──────────┐                ┌──────────────────┐
│ 提取Token │ ──► 验证JWT ──►│ 获取用户角色权限   │ ──► 匹配权限标识
│ from     │    (jwt())    │ getUserPermissions│    method.path
│ Header   │               │                    │
└──────────┘               └──────────────────┘
    401                        403
  未登录                     无权限
```

### 2.4 ID 生命周期

```
┌─────────────────────────────────────────────────────────┐
│                      ID 全生命周期                        │
│                                                         │
│  生成                  存储                 传输         │
│  ┌──────────┐        ┌──────────┐        ┌──────────┐  │
│  │Snowflake │  ──►  │  MySQL   │  ──►  │ Hashids  │  │
│  │::generate│        │ BIGINT   │        │ encode() │  │
│  │  ()      │        │ 原始ID   │        │  hash串  │  │
│  └──────────┘        └──────────┘        └──────────┘  │
│       │                   │                    │        │
│       │              encryptable             │        │
│       │              自动加解密               │        │
│       ▼                   ▼                    ▼        │
│  BIGINT(18)        密文存储              对外暴露        │
│  1750123456789     (不可逆)             aB3xK9mW...     │
│                                                         │
│  反向流程: hashid → HashidsService::decode() → BIGINT   │
└─────────────────────────────────────────────────────────┘
```

### 2.5 数据加密体系

```
       ┌──────────────────────────────────┐
       │          加密分层架构              │
       │                                  │
       │  传输层 (encryption)              │
       │  ┌────────────────────────────┐  │
       │  │ Request 敏感字段加解密       │  │
       │  │ AES-256-CBC + 独立密钥      │  │
       │  └────────────────────────────┘  │
       │              │                   │
       │  存储层 (encryptable)             │
       │  ┌────────────────────────────┐  │
       │  │ Model $casts 自动加解密     │  │
       │  │ AES-128-ECB + 独立密钥      │  │
       │  │ Eloquent CastsAttributes   │  │
       │  └────────────────────────────┘  │
       │                                  │
       │  两层密钥完全独立，不能共用        │
       └──────────────────────────────────┘
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
| `erik_operation_log` | 8 | 操作审计日志 |

### 3.3 主键规范

- 类型: `BIGINT UNSIGNED NOT NULL`
- 特性: **非自增**，由 Snowflake 算法在应用层生成
- 优势: 全局唯一、分布式友好、趋势递增利于索引、不暴露业务量
- 配置: datacenter_id(0-31) + worker_id(0-31)，支持 1024 个节点并发

## 4. API 设计

### 4.1 URL 规范

```
管理端:  /admin/{resource}[/{hashid}]
         /admin/export/{excel|pdf}

认证:    /api/auth/{login|refresh}

资源路由:
  GET    /admin/user          → 列表
  POST   /admin/user          → 创建
  GET    /admin/user/{hashid} → 详情
  PUT    /admin/user/{hashid} → 更新
  DELETE /admin/user/{hashid} → 删除
```

### 4.2 统一响应

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
| 422 | 验证失败 | 表单参数不符合规则 |
| 500 | 服务端错误 | 未预期异常 |

### 4.3 认证流程

```
  客户端                        服务端
    │                             │
    │  POST /api/auth/login      │
    │  {username, password}      │
    │─────────────────────────►  │
    │                             │ 验证凭证
    │                             │ jwt()->create()
    │  {access_token,            │
    │   refresh_token,           │
    │   expires_in: 7200}        │
    │◄─────────────────────────  │
    │                             │
    │  GET /admin/dashboard      │
    │  Authorization: Bearer xxx │
    │─────────────────────────►  │
    │                             │ AdminAuth: jwt()->verify()
    │                             │ → $request->adminId
    │                             │ AdminPermission: RBAC
    │  200 {data: ...}           │
    │◄─────────────────────────  │
    │                             │
    │  [token 快过期]             │
    │  POST /api/auth/refresh    │
    │  {refresh_token}           │
    │─────────────────────────►  │
    │  {新 access_token}         │
    │◄─────────────────────────  │
```

### 4.4 权限模型 (RBAC)

```
  用户 ──┬── 角色 ──┬── 权限
  User     Role      Permission
                 │
                 ├── type=1: 菜单 (控制侧边栏可见)
                 ├── type=2: 按钮 (控制页面内操作)
                 └── type=3: API  (控制接口访问)

  权限标识格式: {method}.{path}
  例: get.admin/user  post.admin/user  delete.admin/user
  超级管理员标识: * (跳过所有权限检查)
```

## 5. 前端设计

### 5.1 Flutter Web 管理后台

```
┌────────────────────────────────────────────────┐
│  Header (56px)                                 │
│  ┌──────────────────────────────────────────┐  │
│  │  ☰ 菜单按钮           🔔 消息  👤 管理员  ▼ │  │
│  └──────────────────────────────────────────┘  │
├──────────┬─────────────────────────────────────┤
│ Sidebar  │  Content Area                       │
│ (64/240) │                                     │
│          │  ┌──────────────────────────────┐   │
│ 📊 仪表盘│  │  Dashboard                    │   │
│ 👥 用户  │  │  ┌────┐┌────┐┌────┐┌────┐   │   │
│ 🔒 角色  │  │  │统计││统计││统计││统计│   │   │
│ ⚙ 配置  │  │  └────┘└────┘└────┘└────┘   │   │
│ 📋 日志  │  │  ┌──────────────────────┐    │   │
│          │  │  │     趋势折线图        │    │   │
│          │  │  └──────────────────────┘    │   │
│          │  │  ┌──────┐ ┌──────────┐      │   │
│          │  │  │饼图  │ │ 最近操作  │      │   │
│          │  │  └──────┘ └──────────┘      │   │
│          │  └──────────────────────────────┘   │
└──────────┴─────────────────────────────────────┘
```

特性:
- 侧边栏可折叠（64px / 240px），鼠标悬停交互
- 数据表格密度高，支持固定列、排序、筛选、批量操作
- 弹窗使用 Dialog（非 BottomSheet）
- Material 3 浅色/深色双主题
- 响应式断点: MOBILE < 768px < DESKTOP

### 5.2 HarmonyOS 移动端

```
┌─────────────────┐
│  顶栏 (56px)    │
│  仪表盘    👤   │
├─────────────────┤
│                 │
│  ┌────┐┌────┐  │
│  │统计││统计│  │
│  └────┘└────┘  │
│  ┌────┐┌────┐  │
│  │统计││统计│  │
│  └────┘└────┘  │
│                 │
│  最近操作       │
│  ┌──────────┐  │
│  │ 操作记录  │  │
│  │ 操作记录  │  │
│  │ 操作记录  │  │
│  └──────────┘  │
│                 │
│  版权信息       │
├─────────────────┤
│ 首页│用户│我的   │  ← 底部Tab导航栏
└─────────────────┘
```

页面路由:

| 页面 | 路由 | 说明 |
|------|------|------|
| LoginPage | `pages/LoginPage` | 启动页，用户名密码登录 |
| DashboardPage | `pages/DashboardPage` | 仪表盘统计卡片+最近操作 |
| UserListPage | `pages/UserListPage` | 用户列表，搜索+下拉刷新+上滑加载更多 |
| UserDetailPage | `pages/UserDetailPage` | 用户新增/编辑/查看，含删除功能 |
| ProfilePage | `pages/ProfilePage` | 个人中心，退出登录 |

数据流:

```
Page ←→ DataService ←→ ApiService ←→ HTTP ←→ webman后端
  │                     │
  │                     ├─ JWT Bearer 自动注入
  │                     └─ 401 自动跳转登录
  │
  └─ @State 数据绑定，自动刷新 UI
```

## 6. 安全设计

### 6.1 纵深防御

| 层面 | 措施 |
|------|------|
| 传输 | HTTPS + JWT Bearer Token |
| 接口ID | Hashids 加密，外部不可逆推真实 ID |
| 请求体 | AES-256-CBC 敏感字段加密 |
| 数据库 | BIGINT 主键（不暴露自增量） |
| 数据库 | AES-128-ECB 敏感字段加密存储 |
| 认证 | JWT HS256，2h 过期 + refresh token |
| 授权 | RBAC，method.path 粒度权限控制 |
| 审计 | OperationLog 记录所有操作 |

### 6.2 密钥管理

```
JWT_SECRET          → 生产环境通过环境变量注入，64位随机字符串
HASHIDS_SALT        → 唯一盐值，泄漏后需全局更换
ENCRYPTION_KEY      → API 传输加密密钥，32字节
ENCRYPTABLE_KEY     → DB 存储加密密钥，与传输密钥独立
SCOUT_HOSTS         → ES 地址，内网部署
```

### 6.3 敏感数据保护

| 场景 | 字段 | 措施 |
|------|------|------|
| 列表展示 | phone | 脱敏: 138****1234 |
| 列表展示 | email | 脱敏: a***@example.com |
| 详情查看 | phone | 需单独解密接口（可留审计） |
| 导出Excel | phone/email | 脱敏后导出 |
| 导出PDF | 全字段 | 脱敏 + 不可移除版权水印 |
| 存储 | phone/email/id_card | encryptable 加密为密文 |

## 7. 导出设计

### 7.1 Excel 导出

```
请求: POST /admin/export/excel
  { table, columns, conditions, title }
       │
       ▼
  ExportController::excel()
       │
       ├► fetchExportData() → 查询数据 (limit 10000)
       ├► 脱敏敏感字段
       ├► PhpSpreadsheet 构建工作簿
       │   ├─ 表头: 蓝底白字加粗
       │   ├─ 数据行: 细边框
       │   ├─ 冻结首行
       │   └─ 自动筛选
       └► 写入 runtime/tmp/ → download 响应
```

### 7.2 PDF 导出

```
请求: POST /admin/export/pdf
  { type: table|dashboard, title, data }
       │
       ▼
  ExportController::pdf()
       │
       ├► buildPdfHtml() → HTML + 内联 CSS
       │   ├─ 页头: 标题 + 版权 + 时间戳
       │   ├─ 内容: 表格或仪表盘卡片
       │   └─ 页脚: 不可移除版权声明
       ├► Dompdf 渲染
       └► 写入 runtime/tmp/ → download 响应
```

## 8. 部署架构

### 8.1 推荐拓扑

```
                   ┌──────────┐
                   │  Nginx   │  ← 反向代理 + HTTPS + 静态文件
                   └────┬─────┘
                        │
            ┌───────────┼───────────┐
            ▼           ▼           ▼
      ┌──────────┐ ┌──────────┐ ┌──────────┐
      │ webman   │ │ webman   │ │ webman   │  ← 多进程（每个CPU核1个）
      │ worker 1 │ │ worker 2 │ │ worker N │
      └────┬─────┘ └────┬─────┘ └────┬─────┘
           │            │            │
           └────────────┼────────────┘
                        │
            ┌───────────┼───────────┐
            ▼           ▼           ▼
      ┌──────────┐ ┌──────────┐ ┌──────────┐
      │  MySQL   │ │   ES     │ │  Redis   │  ← 存储层
      │ (主从)    │ │ (集群)   │ │ (缓存)   │
      └──────────┘ └──────────┘ └──────────┘
```

### 8.2 环境要求

| 组件 | 最低版本 | 推荐配置 |
|------|---------|---------|
| PHP | 8.3+ | 8.3+ OPcache enabled |
| MySQL | 8.0+ | 8.0+ 主从复制 |
| Elasticsearch | 7.x | 8.x 3节点集群 |
| Redis | 6.x | 7.x 哨兵模式 |
| Nginx | 1.20+ | 反向代理 + gzip + SSL |
| Flutter SDK | 3.41+ | 最新稳定版 |
| HarmonyOS | API 12 | DevEco Studio 5.x |
