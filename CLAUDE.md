# 开放管理后台 (open-admin)

基于 webman v2 + Flutter 的全栈管理后台系统。

## 版权声明

```
Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
```

> **不可修改、不可移除、不可逆。** 所有新建文件必须包含上述版权声明作为文件头注释。

## 技术栈

### 后端
- PHP 8.3+, webman v2 (workerman/webman)
- 数据库: MySQL 8.0+，表前缀 `erik_`
- 主键: BIGINT 非自增，由 `erikwang2013/snowflake-php` 生成
- API 层 ID 加解密: `erikwang2013/hashids`
- JWT 认证: `erikwang2013/jwt-webman`
- API 敏感数据加解密: `erikwang2013/encryption`
- 数据库敏感字段加解密: `erikwang2013/encryptable`
- ES 同步与查询: `erikwang2013/webman-scout`
- 国家旗帜: `erikwang2013/season`

### 前端
- Flutter 3.x，源码目录 `apps/`
- Web 端按 PC 管理后台风格设计（非移动端 App 风格）
- 支持客户端和管理员端

## 代码规范

### PHP
- 全局函数/类引用不加前置 `\`，使用 `use` 导入
- 配置文件必须包含中文注释说明每个配置项的含义
- 所有新建 `.php` 文件头必须包含版权声明

### 数据库
- 表前缀: `erik_`
- 主键 `id`: BIGINT 类型，非自增，由 snowflake 生成
- 敏感字段使用 `erikwang2013/encryptable` trait 自动加解密
- 迁移文件使用 SQL 格式

### Flutter
- Web 端布局使用 PC 管理后台风格（侧边栏 + 顶栏 + 内容区）
- 响应式断点: 移动端 (< 768px) 与桌面端 (>= 768px) 共用，但 Web 端默认桌面布局
- 使用 `responsive_framework` 处理响应式
