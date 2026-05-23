---
name: generate-database
description: Generate SQL migration files with erik_ prefix, BIGINT non-auto-increment IDs, encryptable fields
skill_type: implementation
---

# 生成数据库迁移

生成符合项目规范的 SQL 迁移文件。

## 数据库规范

### 表命名
- 前缀: `erik_`
- 命名: 小写 + 下划线，如 `erik_admin_user`, `erik_system_config`
- 中间表: `erik_{表1}_{表2}`，如 `erik_admin_user_role`

### 主键规范
- 字段名: `id`
- 类型: `BIGINT UNSIGNED NOT NULL`
- **不使用 AUTO_INCREMENT**
- ID 值由 `erikwang2013/snowflake-php` 在应用层生成

### 必备字段

每张表必须包含:
```sql
`id` BIGINT UNSIGNED NOT NULL COMMENT '主键ID，由snowflake生成',
`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
PRIMARY KEY (`id`)
```

### 软删除
需要软删除的表添加:
```sql
`deleted_at` DATETIME DEFAULT NULL COMMENT '删除时间',
```

### 敏感字段
需要加密存储的字段（如手机号、身份证号、银行卡号）使用 `erikwang2013/encryptable` trait，数据库字段类型使用 `TEXT` 或 `VARCHAR(500)` 存储密文。

## 迁移文件格式

迁移文件放在 `database/migrations/` 目录，命名格式: `YYYY_MM_DD_HHmmss_描述.sql`

### 示例迁移

```sql
-- ============================================
-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
-- 迁移: 创建管理用户表
-- ============================================

CREATE TABLE IF NOT EXISTS `erik_admin_user` (
    `id` BIGINT UNSIGNED NOT NULL COMMENT '主键ID，由snowflake生成',
    `username` VARCHAR(50) NOT NULL COMMENT '用户名',
    `password` VARCHAR(255) NOT NULL COMMENT '密码（bcrypt哈希）',
    `real_name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '真实姓名',
    `avatar` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '头像URL',
    `email` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '邮箱（加密存储）',
    `phone` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '手机号（加密存储）',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态 0=禁用 1=启用',
    `last_login_at` DATETIME DEFAULT NULL COMMENT '最后登录时间',
    `last_login_ip` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '最后登录IP',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at` DATETIME DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理用户表';


-- 创建角色表
CREATE TABLE IF NOT EXISTS `erik_admin_role` (
    `id` BIGINT UNSIGNED NOT NULL COMMENT '主键ID，由snowflake生成',
    `name` VARCHAR(50) NOT NULL COMMENT '角色名称',
    `slug` VARCHAR(50) NOT NULL COMMENT '角色标识',
    `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '角色描述',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态 0=禁用 1=启用',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色表';


-- 创建权限表
CREATE TABLE IF NOT EXISTS `erik_admin_permission` (
    `id` BIGINT UNSIGNED NOT NULL COMMENT '主键ID，由snowflake生成',
    `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '父级权限ID',
    `name` VARCHAR(50) NOT NULL COMMENT '权限名称',
    `slug` VARCHAR(100) NOT NULL COMMENT '权限标识',
    `type` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '权限类型 1=菜单 2=按钮 3=接口',
    `icon` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '菜单图标',
    `path` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '前端路由路径',
    `sort` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序，越小越靠前',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `idx_parent_id` (`parent_id`),
    KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限表';


-- 创建用户-角色关联表
CREATE TABLE IF NOT EXISTS `erik_admin_user_role` (
    `user_id` BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
    `role_id` BIGINT UNSIGNED NOT NULL COMMENT '角色ID',
    PRIMARY KEY (`user_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户角色关联表';


-- 创建角色-权限关联表
CREATE TABLE IF NOT EXISTS `erik_admin_role_permission` (
    `role_id` BIGINT UNSIGNED NOT NULL COMMENT '角色ID',
    `permission_id` BIGINT UNSIGNED NOT NULL COMMENT '权限ID',
    PRIMARY KEY (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色权限关联表';


-- 创建系统配置表
CREATE TABLE IF NOT EXISTS `erik_system_config` (
    `id` BIGINT UNSIGNED NOT NULL COMMENT '主键ID，由snowflake生成',
    `group` VARCHAR(50) NOT NULL DEFAULT 'default' COMMENT '配置分组',
    `key` VARCHAR(100) NOT NULL COMMENT '配置键',
    `value` TEXT COMMENT '配置值',
    `type` VARCHAR(20) NOT NULL DEFAULT 'string' COMMENT '值类型 string|int|bool|json|array',
    `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '配置说明',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_group_key` (`group`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';
```

## 常见索引策略
- 主键: `PRIMARY KEY (id)`
- 唯一索引: `UNIQUE KEY uk_字段名`
- 普通索引: `KEY idx_字段名`
- 联合索引: `KEY idx_字段1_字段2 (字段1, 字段2)`
- 软删除查询需要包含 `deleted_at IS NULL` 条件，建议对 `deleted_at` 建索引

## 生成规范

1. 所有 SQL 文件必须包含版权声明注释
2. 每张表必须有 COMMENT
3. 每个字段必须有 COMMENT
4. 使用 `IF NOT EXISTS` 避免重复创建错误
5. 使用 InnoDB 引擎，utf8mb4 字符集
