#!/usr/bin/env bash
# ============================================================
# 开放管理后台 — MySQL 初始化：创建应用数据库与最小权限用户
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 由 mysql 官方镜像的 docker-entrypoint-initdb.d 机制在首次初始化时执行
# （排在 10-install.sql 之前）。仅授予应用库权限。
# ============================================================
set -euo pipefail

: "${DB_DATABASE:?缺少 DB_DATABASE}"
: "${DB_USERNAME:?缺少 DB_USERNAME}"
: "${DB_PASSWORD:?缺少 DB_PASSWORD}"
: "${MYSQL_ROOT_PASSWORD:?缺少 MYSQL_ROOT_PASSWORD}"

# 库名/用户名只允许安全标识符，避免拼接 SQL 时被注入
for value in "$DB_DATABASE" "$DB_USERNAME"; do
    if ! [[ "$value" =~ ^[A-Za-z0-9_]+$ ]]; then
        echo "[init-app-user] 非法标识符: $value" >&2
        exit 1
    fi
done

# 密码按 MySQL 字符串字面量转义（反斜杠、单引号），避免拼接 SQL 被截断或注入
SQL_PASSWORD="${DB_PASSWORD//\\/\\\\}"
SQL_PASSWORD="${SQL_PASSWORD//\'/\\\'}"

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'%' IDENTIFIED BY '${SQL_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'%';
FLUSH PRIVILEGES;
SQL

echo "[init-app-user] 已创建数据库 ${DB_DATABASE} 与应用用户 ${DB_USERNAME}"
