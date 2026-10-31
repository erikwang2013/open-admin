#!/usr/bin/env bash
# ============================================================
# 开放管理后台 — MySQL 初始化：创建应用数据库、最小权限用户与备份用户
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 由 mysql 官方镜像的 docker-entrypoint-initdb.d 机制在首次初始化时执行。
# 挂载为 20-app-user.sh，排在 10-install.sql 之后：表级 DELETE 授权要求
# erik_operation_log 已存在。脚本幂等，也可在已有部署上手动重跑来补建备份用户。
# ============================================================
set -euo pipefail

: "${DB_DATABASE:?缺少 DB_DATABASE}"
: "${DB_USERNAME:?缺少 DB_USERNAME}"
: "${DB_PASSWORD:?缺少 DB_PASSWORD}"
: "${DB_BACKUP_USERNAME:?缺少 DB_BACKUP_USERNAME}"
: "${DB_BACKUP_PASSWORD:?缺少 DB_BACKUP_PASSWORD}"
: "${MYSQL_ROOT_PASSWORD:?缺少 MYSQL_ROOT_PASSWORD}"

# 库名/用户名只允许安全标识符，避免拼接 SQL 时被注入
for value in "$DB_DATABASE" "$DB_USERNAME" "$DB_BACKUP_USERNAME"; do
    if ! [[ "$value" =~ ^[A-Za-z0-9_]+$ ]]; then
        echo "[init-app-user] 非法标识符: $value" >&2
        exit 1
    fi
done

if [ "$DB_BACKUP_USERNAME" = "$DB_USERNAME" ]; then
    echo "[init-app-user] 备份用户不能与应用用户相同: $DB_BACKUP_USERNAME" >&2
    exit 1
fi

# 密码按 MySQL 字符串字面量转义（反斜杠、单引号），避免拼接 SQL 被截断或注入
sql_literal() {
    local value="$1"
    value="${value//\\/\\\\}"
    printf '%s' "${value//\'/\\\'}"
}
SQL_PASSWORD="$(sql_literal "$DB_PASSWORD")"
SQL_BACKUP_PASSWORD="$(sql_literal "$DB_BACKUP_PASSWORD")"

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'%' IDENTIFIED BY '${SQL_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'%';
CREATE USER IF NOT EXISTS '${DB_BACKUP_USERNAME}'@'%' IDENTIFIED BY '${SQL_BACKUP_PASSWORD}';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON \`${DB_DATABASE}\`.* TO '${DB_BACKUP_USERNAME}'@'%';
GRANT DELETE ON \`${DB_DATABASE}\`.\`erik_operation_log\` TO '${DB_BACKUP_USERNAME}'@'%';
FLUSH PRIVILEGES;
SQL

echo "[init-app-user] 已创建数据库 ${DB_DATABASE}、应用用户 ${DB_USERNAME} 与备份用户 ${DB_BACKUP_USERNAME}"
