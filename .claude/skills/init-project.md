---
name: init-project
description: Initialize the webman v2 + Flutter admin project with all dependencies and directory structure
skill_type: implementation
---

# 初始化项目

初始化基于 webman v2 + Flutter 的全栈管理后台项目。

## 前提

- PHP 8.3+
- Composer 2.x
- Flutter 3.x
- MySQL 8.0+

## 执行步骤

### 1. 创建 webman v2 项目

```bash
composer create-project workerman/webman:~2.0 --no-interaction ./
```

### 2. 安装后端依赖

```bash
composer require \
  erikwang2013/snowflake-php \
  erikwang2013/hashids \
  erikwang2013/jwt-webman \
  erikwang2013/encryption \
  erikwang2013/encryptable \
  erikwang2013/webman-scout \
  erikwang2013/season \
  phpoffice/phpspreadsheet \
  barryvdh/laravel-dompdf
```

### 3. 目录结构

```
open-admin/
├── app/                    # webman 应用目录
│   ├── admin/             # 管理端控制器
│   ├── api/               # 客户端 API 控制器
│   ├── model/             # 数据模型
│   ├── middleware/        # 中间件
│   ├── service/           # 业务逻辑层
│   └── common/            # 公共工具类
├── config/                # 配置文件（含中文注释）
├── database/
│   └── migrations/        # SQL 迁移文件
├── apps/                  # Flutter 应用
│   └── admin_app/
├── public/                # webman 公共目录
├── runtime/               # 运行时目录
└── vendor/                # Composer 依赖
```

### 4. 创建核心配置

创建 `config/snowflake.php`（含中文注释）:
```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * Snowflake ID 生成器配置
 * 用于生成全局唯一 BIGINT 主键
 * @link https://github.com/erikwang2013/snowflake-php
 */
return [
    // 数据中心 ID，取值范围 0-31
    'datacenter_id' => 1,
    // 工作节点 ID，取值范围 0-31
    'worker_id' => 1,
];
```

创建 `config/hashids.php`（含中文注释）:
```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * Hashids 配置
 * 用于 API 层 ID 加解密，避免暴露真实数据库 ID
 * @link https://github.com/erikwang2013/hashids
 */
return [
    // Hashids 盐值，请修改为随机字符串
    'salt' => 'open-admin-salt-2026',
    // 生成 hash 的最小长度
    'min_length' => 16,
    // 字母表，使用自定义字符集
    'alphabet' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
];
```

创建 `config/jwt.php`（含中文注释）:
```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * JWT 认证配置
 * @link https://github.com/erikwang2013/jwt-webman
 */
return [
    // JWT 签名密钥
    'secret' => 'your-secret-key-change-in-production',
    // 加密算法
    'algorithm' => 'HS256',
    // 访问令牌有效期（秒），默认 2 小时
    'ttl' => 7200,
    // 刷新令牌有效期（秒），默认 14 天
    'refresh_ttl' => 1209600,
];
```

创建 `config/encryption.php`（含中文注释）:
```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * API 敏感数据加解密配置
 * @link https://github.com/erikwang2013/encryption
 */
return [
    // AES 加密密钥（32 字节）
    'key' => 'your-32-byte-encryption-key-here',
    // 加密算法
    'cipher' => 'AES-256-CBC',
];
```

创建 `config/encryptable.php`（含中文注释）:
```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * 数据库敏感字段加解密配置
 * @link https://github.com/erikwang2013/encryptable
 */
return [
    // 加密密钥
    'key' => 'your-db-encryption-key-here',
    // 加密算法
    'cipher' => 'AES-256-CBC',
];
```

创建 `config/scout.php`（含中文注释）:
```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * Elasticsearch 搜索引擎配置
 * @link https://github.com/erikwang2013/webman-scout
 */
return [
    // ES 驱动
    'driver' => 'elasticsearch',
    // ES 主机地址
    'hosts' => ['http://localhost:9200'],
    // 索引名称前缀
    'prefix' => 'erik_',
];
```

### 5. 版权声明

所有新建 PHP 文件头部必须包含:
```php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */
```

### 6. Flutter 初始化

```bash
cd apps
flutter create --org com.erik admin_app
```

修改 `apps/admin_app/lib/main.dart`，使用 PC 管理后台布局。
