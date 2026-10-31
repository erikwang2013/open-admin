#!/usr/bin/env bash
# ============================================================
# 开放管理后台 — 生产部署密钥生成脚本
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 用法:
#   ./generate-secrets.sh              首次生成（保留已有值，只补齐缺失项）
#   ./generate-secrets.sh --regenerate 归档现有 .env + secrets/ 后全部重新生成
#
# 生成结果:
#   deploy/.env                        环境变量（600）
#   deploy/secrets/rsa_public_key.pem  RSA 公钥（600）
#   deploy/secrets/admin_password.txt  初始管理员密码（600）
#   deploy/secrets/tls/fullchain.pem   自签 TLS 证书（可替换为真实证书）
#   deploy/data/                       MySQL / Redis / 运行日志等持久化目录（700）
# ============================================================
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$DEPLOY_DIR/.env"
SECRETS_DIR="$DEPLOY_DIR/secrets"
DATA_DIR="$DEPLOY_DIR/data"
TLS_DIR="$SECRETS_DIR/tls"
ARCHIVE_DIR="$SECRETS_DIR/archive"
REGENERATE=0

usage() {
    echo "用法: $0 [--regenerate]"
    echo "  (无参数)      首次生成；已有值保持不变，仅补齐缺失项"
    echo "  --regenerate  先归档 deploy/.env 与 deploy/secrets/，再全部重新生成"
}

case "${1:-}" in
    "") ;;
    --regenerate) REGENERATE=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "未知参数: $1" >&2; usage >&2; exit 1 ;;
esac

umask 077

# ---------- 工具函数 ----------

rand_hex() { openssl rand -hex "$1"; }

rand_alnum() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-"$1"; }

# 从已有 .env 读取值（只匹配行首 KEY=，保留值中的 = 号）
env_get() {
    [ -f "$ENV_FILE" ] || return 0
    awk -v key="$1" 'index($0, key "=") == 1 { print substr($0, length(key) + 2); exit }' "$ENV_FILE"
}

# 已有值优先，否则使用传入的默认值
keep() {
    local value
    value="$(env_get "$1")"
    printf '%s' "${value:-$2}"
}

# 环境变量优先，其次已有 .env，最后默认值（用于 DOMAIN / 端口）
env_default() {
    local value
    value="$(printenv "$1" || true)"
    if [ -z "$value" ]; then
        value="$(env_get "$1")"
    fi
    printf '%s' "${value:-$2}"
}

archive_existing() {
    local stamp dest entry
    stamp="$(date +%Y%m%d-%H%M%S)"
    dest="$ARCHIVE_DIR/$stamp"
    local n=1
    while [ -e "$dest" ]; do
        dest="$ARCHIVE_DIR/$stamp-$n"
        n=$((n + 1))
    done

    mkdir -p "$dest"
    if [ -f "$ENV_FILE" ]; then
        cp -p "$ENV_FILE" "$dest/.env"
    fi
    for entry in "$SECRETS_DIR"/*; do
        [ -e "$entry" ] || continue
        [ "$(basename "$entry")" = "archive" ] && continue
        cp -a "$entry" "$dest/"
    done
    chmod -R go-rwx "$dest"
    ARCHIVE_PATH="$dest"
}

# ---------- 归档 / 清理 ----------

ARCHIVE_PATH=""
if [ "$REGENERATE" = 1 ]; then
    if [ -f "$ENV_FILE" ] || [ -d "$SECRETS_DIR" ]; then
        archive_existing
        echo "旧配置已归档: $ARCHIVE_PATH"
    fi
    rm -f "$ENV_FILE"
    if [ -d "$SECRETS_DIR" ]; then
        find "$SECRETS_DIR" -mindepth 1 -maxdepth 1 ! -name archive -exec rm -rf {} +
    fi
fi

# ---------- 目录（700） ----------

mkdir -p "$SECRETS_DIR" "$TLS_DIR" "$ARCHIVE_DIR" \
    "$DATA_DIR/mysql" "$DATA_DIR/mysql-logs" "$DATA_DIR/redis" \
    "$DATA_DIR/runtime" "$DATA_DIR/upload" "$DATA_DIR/nginx/logs" "$DATA_DIR/backups"

# 容器首次启动后会把 mysql/redis 数据目录 chown 为容器内 UID，普通用户无法再 chmod，忽略即可
chmod 700 "$SECRETS_DIR" "$TLS_DIR" "$ARCHIVE_DIR" 2>/dev/null || true
chmod 700 "$DATA_DIR" "$DATA_DIR"/* 2>/dev/null || true

# ---------- 生成缺失值（已有值不变） ----------

DOMAIN="$(env_default DOMAIN localhost)"
if ! [[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]]; then
    echo "错误: DOMAIN 只能包含字母、数字、点和连字符: $DOMAIN" >&2
    exit 1
fi

HTTP_PORT="$(env_default HTTP_PORT 80)"
HTTPS_PORT="$(env_default HTTPS_PORT 443)"
for port in "$HTTP_PORT" "$HTTPS_PORT"; do
    if ! [[ "$port" =~ ^[0-9]+$ ]]; then
        echo "错误: 端口必须是数字: $port" >&2
        exit 1
    fi
done

JWT_SECRET_KEY="$(keep JWT_SECRET_KEY "$(rand_hex 32)")"
JWT_SECRET="$(keep JWT_SECRET "$JWT_SECRET_KEY")"
HASHIDS_SALT="$(keep HASHIDS_SALT "$(rand_hex 16)")"
HASHIDS_ALT_SALT="$(keep HASHIDS_ALT_SALT "$(rand_hex 16)")"
ENCRYPTION_KEY="$(keep ENCRYPTION_KEY "$(rand_hex 16)")"
ENCRYPTABLE_KEY="$(keep ENCRYPTABLE_KEY "$(rand_hex 16)")"
DB_DATABASE="$(keep DB_DATABASE open_admin)"
DB_USERNAME="$(keep DB_USERNAME open_admin)"
DB_PASSWORD="$(keep DB_PASSWORD "$(rand_hex 16)")"
MYSQL_ROOT_PASSWORD="$(keep MYSQL_ROOT_PASSWORD "$(rand_hex 16)")"
REDIS_PASSWORD="$(keep REDIS_PASSWORD "$(rand_hex 16)")"
ADMIN_USERNAME="$(keep ADMIN_USERNAME admin)"
ADMIN_PASSWORD="$(keep ADMIN_PASSWORD "$(rand_alnum 24)")"

# ---------- RSA-2048 密钥对 ----------

RSA_PRIVATE_KEY="$(env_get RSA_PRIVATE_KEY)"
if [ -z "$RSA_PRIVATE_KEY" ]; then
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
        -out "$SECRETS_DIR/rsa_private_key.pem" 2>/dev/null
    chmod 600 "$SECRETS_DIR/rsa_private_key.pem"
    openssl pkey -in "$SECRETS_DIR/rsa_private_key.pem" -pubout \
        -out "$SECRETS_DIR/rsa_public_key.pem" 2>/dev/null
    RSA_PRIVATE_KEY="$(openssl base64 -A -in "$SECRETS_DIR/rsa_private_key.pem")"
elif [ ! -s "$SECRETS_DIR/rsa_public_key.pem" ]; then
    # 私钥在 .env 中但公钥文件丢失时，从私钥重新导出公钥
    printf '%s' "$RSA_PRIVATE_KEY" | openssl base64 -d -A \
        | openssl pkey -pubout -out "$SECRETS_DIR/rsa_public_key.pem" 2>/dev/null
fi
chmod 600 "$SECRETS_DIR/rsa_public_key.pem" 2>/dev/null || true

# ---------- TLS 证书（缺失时自签；已存在则绝不覆盖） ----------

if [ ! -s "$TLS_DIR/fullchain.pem" ] || [ ! -s "$TLS_DIR/privkey.pem" ]; then
    openssl req -x509 -newkey rsa:2048 -sha256 -days 825 -nodes \
        -keyout "$TLS_DIR/privkey.pem" -out "$TLS_DIR/fullchain.pem" \
        -subj "/CN=$DOMAIN" \
        -addext "subjectAltName=DNS:$DOMAIN,IP:127.0.0.1" 2>/dev/null
fi
chmod 600 "$TLS_DIR/fullchain.pem" "$TLS_DIR/privkey.pem" 2>/dev/null || true

# ---------- 管理员密码文件 ----------

printf '%s\n' "$ADMIN_PASSWORD" > "$SECRETS_DIR/admin_password.txt"
chmod 600 "$SECRETS_DIR/admin_password.txt"

# ---------- 写入 .env（仅安全字符） ----------

cat > "$ENV_FILE" <<EOF
# ============================================================
# 开放管理后台 — 单机生产部署环境变量
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 由 deploy/generate-secrets.sh 生成，切勿提交到版本库。
# 重新生成: ./generate-secrets.sh --regenerate
# ============================================================

# ── 域名与端口（compose 端口映射） ──
DOMAIN=$DOMAIN
HTTP_PORT=$HTTP_PORT
HTTPS_PORT=$HTTPS_PORT

# ── 应用 ──
APP_NAME=开放管理后台
APP_DEBUG=false
APP_URL=https://$DOMAIN

# ── JWT（HS256，64 位十六进制） ──
JWT_SECRET_KEY=$JWT_SECRET_KEY
JWT_SECRET=$JWT_SECRET
JWT_ALGORITHM=HS256
JWT_ISSUER=open-admin
JWT_AUDIENCE=open-admin
JWT_DEFAULT_EXPIRE=7200
JWT_REFRESH_EXPIRE=1209600

# ── Hashids ID 加解密盐值 ──
HASHIDS_SALT=$HASHIDS_SALT
HASHIDS_ALT_SALT=$HASHIDS_ALT_SALT

# ── Snowflake 主键 ──
SNOWFLAKE_DATACENTER_ID=1
SNOWFLAKE_WORKER_ID=1
SNOWFLAKE_START_TIMESTAMP=1700000000000

# ── API 传输层加密（32 字符密钥 + RSA-2048 私钥 Base64） ──
ENCRYPTION_KEY=$ENCRYPTION_KEY
ENCRYPTION_CIPHER=AES-256-CBC
ENCRYPTION_IV=
RSA_PRIVATE_KEY=$RSA_PRIVATE_KEY

# ── 数据库字段加密（与传输层密钥独立） ──
ENCRYPTABLE_KEY=$ENCRYPTABLE_KEY
ENCRYPTABLE_CIPHER=AES-256-CBC

# ── 验证码（文件缓存写到宿主机挂载目录，不留在容器层） ──
POSTER_IMAGE_DRIVER=auto
POSTER_CAPTCHA_STORAGE=file
POSTER_CAPTCHA_FILE_PATH=/app/runtime/captcha
POSTER_CAPTCHA_TTL=300
POSTER_CAPTCHA_MAX_ATTEMPTS=3
POSTER_CAPTCHA_DIFFICULTY=medium

# ── MySQL（compose 服务名 mysql；init-app-user.sh 使用 DB_*） ──
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD
MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD

# ── Redis（compose 服务名 redis） ──
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=$REDIS_PASSWORD
REDIS_DATABASE=0
REDIS_PREFIX=open-admin

# ── 初始管理员（仅 init 容器首次创建时使用） ──
ADMIN_USERNAME=$ADMIN_USERNAME
ADMIN_PASSWORD=$ADMIN_PASSWORD
EOF

chmod 600 "$ENV_FILE"

# ---------- 完成 ----------

echo
echo "部署配置已生成: $ENV_FILE"
echo "管理员用户名: $ADMIN_USERNAME"
echo "管理员密码文件: $SECRETS_DIR/admin_password.txt"
echo "TLS 证书: $TLS_DIR/fullchain.pem (CN=$DOMAIN, 自签)"
echo
echo "下一步: cd $DEPLOY_DIR && docker compose up -d --build"
