#!/usr/bin/env bash
# ============================================================
# 开放管理后台 — 生产部署密钥生成脚本
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 用法:
#   ./generate-secrets.sh                 首次生成 / 补齐缺失项（保留已有值）
#   ./generate-secrets.sh --regenerate    轮换应用密钥（JWT/传输层加密/RSA/Redis），
#                                         保留 Hashids 盐值、DB_PASSWORD、MYSQL_ROOT_PASSWORD、
#                                         ADMIN_PASSWORD、ENCRYPTABLE_KEY（及历史密钥）、TLS 证书
#   ./generate-secrets.sh --rotate-hashids
#                                         轮换 Hashids 盐值（旧链接中的 ID 将无法解析）
#   ./generate-secrets.sh --rotate-db     生成新的数据库密码（会打印手动 ALTER USER 命令）
#   ./generate-secrets.sh --reset-admin   生成新的管理员密码（会打印更新已有管理员的命令）
#   ./generate-secrets.sh --rotate-encryptable
#                                         轮换数据库字段加密密钥，旧密钥自动进入
#                                         ENCRYPTION_PREVIOUS_KEYS（读取旧数据仍可解密）
#   ./generate-secrets.sh --rotate-tls    重新生成自签 TLS 证书（会替换现有证书，含真实证书）
#   以上参数可组合，例如 --regenerate --rotate-db --reset-admin
#
# 生成结果:
#   deploy/.env                        仅 compose 插值（域名/端口/宿主 UID），600
#   deploy/env/app.env                 应用容器环境（600）
#   deploy/env/init.env                初始化容器环境（600）
#   deploy/env/mysql.env               MySQL 容器环境（600）
#   deploy/env/redis.env               Redis 容器环境（600）
#   deploy/env/backup.env              备份容器环境（600）
#   deploy/secrets/rsa_public_key.pem  RSA 公钥（600）
#   deploy/secrets/admin_password.txt  初始管理员密码（600）
#   deploy/secrets/tls/fullchain.pem   自签 TLS 证书（可替换为真实证书）
#   deploy/data/                       MySQL / Redis / 运行时数据目录（700）
# ============================================================
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$DEPLOY_DIR/.env"
ENV_DIR="$DEPLOY_DIR/env"
APP_ENV="$ENV_DIR/app.env"
INIT_ENV="$ENV_DIR/init.env"
MYSQL_ENV="$ENV_DIR/mysql.env"
REDIS_ENV="$ENV_DIR/redis.env"
BACKUP_ENV="$ENV_DIR/backup.env"
SECRETS_DIR="$DEPLOY_DIR/secrets"
DATA_DIR="$DEPLOY_DIR/data"
TLS_DIR="$SECRETS_DIR/tls"
ARCHIVE_DIR="$SECRETS_DIR/archive"

REGENERATE=0
ROTATE_HASHIDS=0
ROTATE_DB=0
RESET_ADMIN=0
ROTATE_ENCRYPTABLE=0
ROTATE_TLS=0

usage() {
    echo "用法: $0 [--regenerate] [--rotate-hashids] [--rotate-db] [--reset-admin] [--rotate-encryptable] [--rotate-tls]"
    echo "  (无参数)              首次生成；已有值保持不变，仅补齐缺失项"
    echo "  --regenerate          轮换应用密钥（JWT/传输层加密/RSA/Redis），保留 Hashids 盐值、数据库与管理员密码、字段加密密钥与 TLS 证书"
    echo "  --rotate-hashids      轮换 Hashids 盐值（旧链接中的 ID 将无法解析）"
    echo "  --rotate-db           同时生成新的数据库密码（需按输出手动 ALTER USER）"
    echo "  --reset-admin         同时生成新的管理员密码（需按输出更新已有管理员）"
    echo "  --rotate-encryptable  轮换数据库字段加密密钥，旧密钥加入 ENCRYPTION_PREVIOUS_KEYS"
    echo "  --rotate-tls          重新生成自签 TLS 证书（会替换现有证书）"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --regenerate) REGENERATE=1 ;;
        --rotate-hashids) ROTATE_HASHIDS=1 ;;
        --rotate-db) ROTATE_DB=1 ;;
        --reset-admin) RESET_ADMIN=1 ;;
        --rotate-encryptable) ROTATE_ENCRYPTABLE=1 ;;
        --rotate-tls) ROTATE_TLS=1 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "未知参数: $1" >&2; usage >&2; exit 1 ;;
    esac
    shift
done

umask 077

# ---------- 工具函数 ----------

rand_hex() { openssl rand -hex "$1"; }

rand_alnum() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-"$1"; }

# 从指定 env 文件读取值（只匹配行首 KEY=，保留值中的 = 号）
env_get() {
    [ -f "$1" ] || return 0
    awk -v key="$2" 'index($0, key "=") == 1 { print substr($0, length(key) + 2); exit }' "$1"
}

# 已有值优先（$1 目标文件，$2 键，$3 缺失时的默认值，$4 强制换新）
# 兼容旧版单文件布局：目标文件缺失该键时回退读取 deploy/.env
pick() {
    local file="$1" key="$2" fresh="$3" force="${4:-0}" value=""
    if [ "$force" = 0 ]; then
        value="$(env_get "$file" "$key")"
        if [ -z "$value" ]; then
            value="$(env_get "$ENV_FILE" "$key")"
        fi
    fi
    printf '%s' "${value:-$fresh}"
}

# 环境变量优先，其次已有主 .env，最后默认值（用于 DOMAIN / 端口）
env_default() {
    local value
    value="$(printenv "$1" || true)"
    if [ -z "$value" ]; then
        value="$(env_get "$ENV_FILE" "$1")"
    fi
    printf '%s' "${value:-$2}"
}

# 合并 env 文件：模板中的键在目标文件内原地更新，缺失的追加到末尾；
# 目标文件中的其他行（含自定义键）原样保留。
merge_env() {
    local target="$1" template="$2" tmp
    if [ ! -f "$target" ]; then
        cp "$template" "$target"
        return 0
    fi
    tmp="$(mktemp)"
    awk -v template="$template" '
        BEGIN {
            while ((getline line < template) > 0) {
                if (line ~ /^[A-Za-z_][A-Za-z0-9_]*=/) {
                    key = line; sub(/=.*$/, "", key)
                    value[key] = substr(line, length(key) + 2)
                    order[++n] = key
                }
            }
            close(template)
        }
        match($0, /^[A-Za-z_][A-Za-z0-9_]*=/) {
            key = substr($0, 1, RLENGTH - 1)
            if (key in value) {
                print key "=" value[key]
                seen[key] = 1
                next
            }
        }
        { print }
        END {
            for (i = 1; i <= n; i++) {
                if (!(order[i] in seen)) {
                    print order[i] "=" value[order[i]]
                }
            }
        }
    ' "$target" > "$tmp"
    mv "$tmp" "$target"
}

# 从主 .env 中移除已迁移到 env/*.env 的键（其余行保持不变）
strip_keys() {
    local file="$1" tmp
    shift
    [ -f "$file" ] || return 0
    tmp="$(mktemp)"
    awk -v keys="$*" '
        BEGIN { n = split(keys, a, " "); for (i = 1; i <= n; i++) drop[a[i]] = 1 }
        match($0, /^[A-Za-z_][A-Za-z0-9_]*=/) {
            key = substr($0, 1, RLENGTH - 1)
            if (key in drop) { next }
        }
        { print }
    ' "$file" > "$tmp"
    mv "$tmp" "$file"
}

archive_existing() {
    local stamp dest entry n
    stamp="$(date +%Y%m%d-%H%M%S)"
    dest="$ARCHIVE_DIR/$stamp"
    n=1
    while [ -e "$dest" ]; do
        dest="$ARCHIVE_DIR/$stamp-$n"
        n=$((n + 1))
    done

    mkdir -p "$dest"
    if [ -f "$ENV_FILE" ]; then
        cp -p "$ENV_FILE" "$dest/.env"
    fi
    if [ -d "$ENV_DIR" ]; then
        cp -a "$ENV_DIR" "$dest/env"
    fi
    for entry in "$SECRETS_DIR"/*; do
        [ -e "$entry" ] || continue
        [ "$(basename "$entry")" = "archive" ] && continue
        cp -a "$entry" "$dest/"
    done
    chmod -R go-rwx "$dest"
    ARCHIVE_PATH="$dest"
}

# 数据目录是否已有 MySQL 数据（决定是否需要手动更新数据库账号密码）
mysql_has_data() {
    [ -d "$DATA_DIR/mysql" ] || return 1
    if [ -r "$DATA_DIR/mysql" ] && [ -x "$DATA_DIR/mysql" ]; then
        [ -n "$(ls -A "$DATA_DIR/mysql" 2>/dev/null)" ]
        return
    fi
    # MySQL 容器会把目录 chown 成容器内 UID（700），宿主机读不到时按“可能已有数据”处理
    return 0
}

# ---------- 归档 / 轮换准备 ----------

# 旧版把全部密钥写在 deploy/.env 中；首次迁移前先归档，避免迁移失败丢配置
LEGACY_LAYOUT=0
if [ -f "$ENV_FILE" ] && grep -q '^JWT_SECRET_KEY=' "$ENV_FILE"; then
    LEGACY_LAYOUT=1
fi

ARCHIVE_PATH=""
if [ "$REGENERATE" = 1 ] || [ "$ROTATE_HASHIDS" = 1 ] || [ "$ROTATE_DB" = 1 ] \
    || [ "$RESET_ADMIN" = 1 ] || [ "$ROTATE_ENCRYPTABLE" = 1 ] \
    || [ "$ROTATE_TLS" = 1 ] || [ "$LEGACY_LAYOUT" = 1 ]; then
    if [ -f "$ENV_FILE" ] || [ -d "$ENV_DIR" ] || [ -d "$SECRETS_DIR" ]; then
        archive_existing
        echo "旧配置已归档: $ARCHIVE_PATH"
    fi
fi

# --regenerate 只轮换 RSA（客户端会重新获取公钥）；TLS 证书可能为真实证书，仅 --rotate-tls 时替换
if [ "$REGENERATE" = 1 ]; then
    rm -f "$SECRETS_DIR/rsa_private_key.pem" "$SECRETS_DIR/rsa_public_key.pem"
fi
if [ "$ROTATE_TLS" = 1 ]; then
    rm -f "$TLS_DIR/fullchain.pem" "$TLS_DIR/privkey.pem"
fi

# ---------- 目录（700） ----------

mkdir -p "$SECRETS_DIR" "$TLS_DIR" "$ARCHIVE_DIR" "$ENV_DIR" \
    "$DATA_DIR/mysql" "$DATA_DIR/redis" \
    "$DATA_DIR/runtime" "$DATA_DIR/upload" "$DATA_DIR/backups"

# 容器首次启动后会把 mysql/redis 数据目录 chown 为容器内 UID，普通用户无法再 chmod，忽略即可
chmod 700 "$SECRETS_DIR" "$TLS_DIR" "$ARCHIVE_DIR" "$ENV_DIR" 2>/dev/null || true
chmod 700 "$DATA_DIR" "$DATA_DIR"/* 2>/dev/null || true

# ---------- 宿主 UID / 端口 / 域名 ----------

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

APP_UID="$(env_default APP_UID "$(id -u)")"
APP_GID="$(env_default APP_GID "$(id -g)")"
if ! [[ "$APP_UID" =~ ^[0-9]+$ && "$APP_GID" =~ ^[0-9]+$ ]]; then
    echo "错误: APP_UID / APP_GID 必须是数字" >&2
    exit 1
fi

APP_UID_SET=0
APP_GID_SET=0
if [ -n "$(printenv APP_UID || true)" ] || [ -n "$(env_get "$ENV_FILE" APP_UID)" ]; then
    APP_UID_SET=1
fi
if [ -n "$(printenv APP_GID || true)" ] || [ -n "$(env_get "$ENV_FILE" APP_GID)" ]; then
    APP_GID_SET=1
fi

check_not_root() {
    local name="$1" value="$2" set_flag="$3"
    [ "$value" -eq 0 ] || return 0
    if [ "$set_flag" = 1 ]; then
        echo "警告: $name 显式为 0，app/init/backup 容器将以 root 运行。" >&2
    else
        echo "错误: $name 解析为 0（root）且未显式设置；容器不得静默以 root 运行。" >&2
        echo "      请把 APP_UID/APP_GID 设为普通用户（当前用户为 $(id -u):$(id -g)），或以普通用户身份运行本脚本。" >&2
        exit 1
    fi
}

check_not_root APP_UID "$APP_UID" "$APP_UID_SET"
check_not_root APP_GID "$APP_GID" "$APP_GID_SET"

if ! chown -R "$APP_UID:$APP_GID" "$DATA_DIR/runtime" "$DATA_DIR/upload" "$DATA_DIR/backups"; then
    echo "错误: 无法把 data/runtime、data/upload、data/backups 的所有者改为 $APP_UID:$APP_GID。" >&2
    echo "      请以有权限的用户运行（如 sudo），或把 deploy/.env 的 APP_UID/APP_GID 设为当前用户 $(id -u):$(id -g)。" >&2
    exit 1
fi
for dir in "$DATA_DIR/runtime" "$DATA_DIR/upload" "$DATA_DIR/backups"; do
    owner="$(stat -c '%u:%g' "$dir" || true)"
    if [ "$owner" != "$APP_UID:$APP_GID" ]; then
        echo "错误: $dir 的所有者为 ${owner:-未知}，期望 $APP_UID:$APP_GID" >&2
        exit 1
    fi
done

# ---------- 生成缺失值（已有值不变，--regenerate / --rotate-db / --reset-admin 强制换新） ----------

JWT_SECRET_KEY="$(pick "$APP_ENV" JWT_SECRET_KEY "$(rand_hex 32)" "$REGENERATE")"
# Hashids 盐值影响已生成的对外 ID，--regenerate 默认保留，仅 --rotate-hashids 显式轮换
HASHIDS_SALT="$(pick "$APP_ENV" HASHIDS_SALT "$(rand_hex 16)" "$ROTATE_HASHIDS")"
HASHIDS_ALT_SALT="$(pick "$APP_ENV" HASHIDS_ALT_SALT "$(rand_hex 16)" "$ROTATE_HASHIDS")"
ENCRYPTION_KEY="$(pick "$APP_ENV" ENCRYPTION_KEY "$(rand_hex 16)" "$REGENERATE")"
# 字段加密密钥默认永不轮换（已有数据用它加密）；只有 --rotate-encryptable 显式换新
ENCRYPTABLE_KEY_EXISTING="$(pick "$APP_ENV" ENCRYPTABLE_KEY "" 0)"
ENCRYPTABLE_KEY="${ENCRYPTABLE_KEY_EXISTING:-$(rand_hex 16)}"
ENCRYPTION_PREVIOUS_KEYS="$(pick "$APP_ENV" ENCRYPTION_PREVIOUS_KEYS "" 0)"
if [ "$ROTATE_ENCRYPTABLE" = 1 ]; then
    # 旧密钥进入历史密钥列表（逗号分隔、去重），旧数据读取时仍可解密，写入时用新密钥重新加密
    ENCRYPTION_PREVIOUS_KEYS="$(
        printf '%s,%s' "$ENCRYPTABLE_KEY_EXISTING" "$ENCRYPTION_PREVIOUS_KEYS" \
            | awk -F, '{ for (i = 1; i <= NF; i++) { k = $i; gsub(/^[ \t]+|[ \t]+$/, "", k); if (k != "" && !seen[k]++) { printf "%s%s", sep, k; sep = "," } } }'
    )"
    ENCRYPTABLE_KEY="$(rand_hex 16)"
fi

DB_DATABASE="$(pick "$MYSQL_ENV" DB_DATABASE open_admin 0)"
DB_USERNAME="$(pick "$MYSQL_ENV" DB_USERNAME open_admin 0)"
for value in "$DB_DATABASE" "$DB_USERNAME"; do
    if ! [[ "$value" =~ ^[A-Za-z0-9_]+$ ]]; then
        echo "错误: 数据库名/用户名只能包含字母、数字和下划线: $value" >&2
        exit 1
    fi
done
DB_PASSWORD="$(pick "$MYSQL_ENV" DB_PASSWORD "$(rand_hex 16)" "$ROTATE_DB")"
# 备份容器只使用最小权限用户（SELECT/SHOW VIEW/TRIGGER/EVENT/LOCK TABLES + 日志表 DELETE）
DB_BACKUP_USERNAME="$(pick "$MYSQL_ENV" DB_BACKUP_USERNAME backup_reader 0)"
if ! [[ "$DB_BACKUP_USERNAME" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "错误: 备份用户名只能包含字母、数字和下划线: $DB_BACKUP_USERNAME" >&2
    exit 1
fi
if [ "$DB_BACKUP_USERNAME" = "$DB_USERNAME" ]; then
    echo "错误: DB_BACKUP_USERNAME 不能与应用用户 DB_USERNAME 相同" >&2
    exit 1
fi
DB_BACKUP_PASSWORD="$(pick "$MYSQL_ENV" DB_BACKUP_PASSWORD "$(rand_hex 16)" "$ROTATE_DB")"
MYSQL_ROOT_PASSWORD="$(pick "$MYSQL_ENV" MYSQL_ROOT_PASSWORD "$(rand_hex 16)" "$ROTATE_DB")"
REDIS_PASSWORD="$(pick "$REDIS_ENV" REDIS_PASSWORD "$(rand_hex 16)" "$REGENERATE")"

ADMIN_USERNAME="$(pick "$INIT_ENV" ADMIN_USERNAME admin 0)"
if [ "$RESET_ADMIN" = 1 ]; then
    ADMIN_PASSWORD="$(rand_alnum 24)"
elif [ -s "$SECRETS_DIR/admin_password.txt" ] && [ -z "$(env_get "$INIT_ENV" ADMIN_PASSWORD)" ]; then
    # 旧版部署的管理员密码只存在于密码文件中
    ADMIN_PASSWORD="$(cat "$SECRETS_DIR/admin_password.txt")"
else
    ADMIN_PASSWORD="$(pick "$INIT_ENV" ADMIN_PASSWORD "$(rand_alnum 24)" 0)"
fi

# ---------- RSA-2048 密钥对 ----------

RSA_PRIVATE_KEY=""
if [ "$REGENERATE" = 0 ]; then
    RSA_PRIVATE_KEY="$(pick "$APP_ENV" RSA_PRIVATE_KEY "" 0)"
fi
if [ -z "$RSA_PRIVATE_KEY" ]; then
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
        -out "$SECRETS_DIR/rsa_private_key.pem"
    chmod 600 "$SECRETS_DIR/rsa_private_key.pem"
    openssl pkey -in "$SECRETS_DIR/rsa_private_key.pem" -pubout \
        -out "$SECRETS_DIR/rsa_public_key.pem"
    RSA_PRIVATE_KEY="$(openssl base64 -A -in "$SECRETS_DIR/rsa_private_key.pem")"
elif [ ! -s "$SECRETS_DIR/rsa_public_key.pem" ]; then
    # 私钥在 env 中但公钥文件丢失时，从私钥重新导出公钥
    printf '%s' "$RSA_PRIVATE_KEY" | openssl base64 -d -A \
        | openssl pkey -pubout -out "$SECRETS_DIR/rsa_public_key.pem"
fi
chmod 600 "$SECRETS_DIR/rsa_public_key.pem" 2>/dev/null || true

# ---------- TLS 证书（缺失时自签；默认不覆盖已有证书） ----------

FULLCHAIN_PRESENT=0
PRIVKEY_PRESENT=0
if [ -s "$TLS_DIR/fullchain.pem" ]; then
    FULLCHAIN_PRESENT=1
fi
if [ -s "$TLS_DIR/privkey.pem" ]; then
    PRIVKEY_PRESENT=1
fi

if [ "$FULLCHAIN_PRESENT" = 0 ] && [ "$PRIVKEY_PRESENT" = 0 ]; then
    openssl req -x509 -newkey rsa:2048 -sha256 -days 825 -nodes \
        -keyout "$TLS_DIR/privkey.pem" -out "$TLS_DIR/fullchain.pem" \
        -subj "/CN=$DOMAIN" \
        -addext "subjectAltName=DNS:$DOMAIN,IP:127.0.0.1"
elif [ "$FULLCHAIN_PRESENT" != "$PRIVKEY_PRESENT" ]; then
    missing="privkey.pem"
    if [ "$FULLCHAIN_PRESENT" = 0 ]; then
        missing="fullchain.pem"
    fi
    echo "错误: TLS 证书不完整，缺少 $TLS_DIR/$missing（另一文件存在）。" >&2
    echo "      请补齐缺失文件，或执行 ./generate-secrets.sh --rotate-tls 重新生成自签证书（会替换现有证书）。" >&2
    exit 1
fi
chmod 600 "$TLS_DIR/fullchain.pem" "$TLS_DIR/privkey.pem" 2>/dev/null || true

# ---------- 管理员密码文件（--reset-admin 显式覆盖并提示） ----------

if [ "$RESET_ADMIN" = 1 ] || [ ! -s "$SECRETS_DIR/admin_password.txt" ]; then
    printf '%s\n' "$ADMIN_PASSWORD" > "$SECRETS_DIR/admin_password.txt"
    chmod 600 "$SECRETS_DIR/admin_password.txt"
    if [ "$RESET_ADMIN" = 1 ]; then
        echo "管理员密码文件已更新: $SECRETS_DIR/admin_password.txt"
    fi
fi

# ---------- 渲染各服务 env 文件并合并 ----------

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

cat > "$TMP_DIR/app.env" <<EOF
# ============================================================
# 开放管理后台 — 应用容器环境（app 服务）
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 由 deploy/generate-secrets.sh 生成，切勿提交到版本库。
# ============================================================

# ── 应用 ──
APP_NAME=开放管理后台
APP_DEBUG=false
APP_URL=https://$DOMAIN

# ── JWT（HS256，64 位十六进制） ──
JWT_SECRET_KEY=$JWT_SECRET_KEY
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
# 历史密钥（逗号分隔），--rotate-encryptable 时自动写入
ENCRYPTION_PREVIOUS_KEYS=$ENCRYPTION_PREVIOUS_KEYS

# ── 验证码（文件缓存写到宿主机挂载目录，不留在容器层） ──
POSTER_IMAGE_DRIVER=auto
POSTER_CAPTCHA_STORAGE=file
POSTER_CAPTCHA_FILE_PATH=/app/runtime/captcha
POSTER_CAPTCHA_TTL=300
POSTER_CAPTCHA_MAX_ATTEMPTS=3
POSTER_CAPTCHA_DIFFICULTY=medium

# ── MySQL（compose 服务名 mysql；仅应用用户，不含 root 密码） ──
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD

# ── Redis（compose 服务名 redis） ──
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=$REDIS_PASSWORD
REDIS_DATABASE=0
REDIS_PREFIX=open-admin
EOF

cat > "$TMP_DIR/init.env" <<EOF
# ============================================================
# 开放管理后台 — 初始化容器环境（init 服务）
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 由 deploy/generate-secrets.sh 生成，切勿提交到版本库。
# ============================================================

# ── MySQL 应用账号（不含 root 密码） ──
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD

# ── 初始管理员（仅首次创建时使用；已有管理员不会被重置） ──
ADMIN_USERNAME=$ADMIN_USERNAME
ADMIN_PASSWORD=$ADMIN_PASSWORD

# ── Snowflake 主键 ──
SNOWFLAKE_DATACENTER_ID=1
SNOWFLAKE_WORKER_ID=1
SNOWFLAKE_START_TIMESTAMP=1700000000000
EOF

cat > "$TMP_DIR/mysql.env" <<EOF
# ============================================================
# 开放管理后台 — MySQL 容器环境
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 由 deploy/generate-secrets.sh 生成，切勿提交到版本库。
# 仅在 mysql 容器内可见，不会传入 app / init。
# ============================================================

MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD
MYSQL_DATABASE=$DB_DATABASE
DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD
# init-app-user.sh 用这两个值创建最小权限备份用户
DB_BACKUP_USERNAME=$DB_BACKUP_USERNAME
DB_BACKUP_PASSWORD=$DB_BACKUP_PASSWORD
EOF

cat > "$TMP_DIR/redis.env" <<EOF
# ============================================================
# 开放管理后台 — Redis 容器环境
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 由 deploy/generate-secrets.sh 生成，切勿提交到版本库。
# ============================================================

REDIS_PASSWORD=$REDIS_PASSWORD
EOF

cat > "$TMP_DIR/backup.env" <<EOF
# ============================================================
# 开放管理后台 — 备份容器环境（backup 服务）
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 由 deploy/generate-secrets.sh 生成，切勿提交到版本库。
# 仅最小权限备份用户，不含 root 凭据。
# ============================================================

DB_BACKUP_USERNAME=$DB_BACKUP_USERNAME
DB_BACKUP_PASSWORD=$DB_BACKUP_PASSWORD
MYSQL_DATABASE=$DB_DATABASE
EOF

merge_env "$APP_ENV" "$TMP_DIR/app.env"
merge_env "$INIT_ENV" "$TMP_DIR/init.env"
merge_env "$MYSQL_ENV" "$TMP_DIR/mysql.env"
merge_env "$REDIS_ENV" "$TMP_DIR/redis.env"
merge_env "$BACKUP_ENV" "$TMP_DIR/backup.env"
# 旧版 backup.env 含有 root 密码；merge_env 会保留模板外的自定义键，这里显式移除
strip_keys "$BACKUP_ENV" MYSQL_ROOT_PASSWORD

# 旧版把所有密钥写在 .env 中；迁移后从主 .env 移除，保留自定义键
MANAGED_KEYS="$(cat "$TMP_DIR/app.env" "$TMP_DIR/init.env" "$TMP_DIR/mysql.env" \
    "$TMP_DIR/redis.env" "$TMP_DIR/backup.env" \
    | sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*\)=.*/\1/p' | sort -u | tr '\n' ' ')"
# shellcheck disable=SC2086
strip_keys "$ENV_FILE" $MANAGED_KEYS

cat > "$TMP_DIR/master.env" <<EOF
# ============================================================
# 开放管理后台 — 单机生产部署主配置
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 由 deploy/generate-secrets.sh 生成，切勿提交到版本库。
# 本文件只保存 compose 插值所需的域名/端口/宿主机 UID；
# 密钥与数据库凭据在 deploy/env/*.env（600），不在此文件中。
# ============================================================

# ── 域名与端口（compose 端口映射） ──
DOMAIN=$DOMAIN
HTTP_PORT=$HTTP_PORT
HTTPS_PORT=$HTTPS_PORT

# ── 宿主机用户（容器以该 UID/GID 运行，挂载目录需归其所有） ──
APP_UID=$APP_UID
APP_GID=$APP_GID
EOF

merge_env "$ENV_FILE" "$TMP_DIR/master.env"

chmod 600 "$ENV_FILE" "$APP_ENV" "$INIT_ENV" "$MYSQL_ENV" "$REDIS_ENV" "$BACKUP_ENV"

# ---------- 完成 ----------

echo
echo "部署配置已生成:"
echo "  主配置:     $ENV_FILE"
echo "  服务环境:   $ENV_DIR/{app,init,mysql,redis,backup}.env"
echo "  管理员:     $ADMIN_USERNAME（密码文件 $SECRETS_DIR/admin_password.txt）"
echo "  TLS 证书:   $TLS_DIR/fullchain.pem (CN=$DOMAIN, 自签)"
echo "  宿主 UID:   $APP_UID:$APP_GID"

if [ "$REGENERATE" = 1 ]; then
    echo
    echo "以下应用密钥已轮换，请确认影响："
    echo "  - JWT：所有登录会话失效"
    echo "  - RSA：已登录客户端需刷新公钥（TLS 证书、字段加密密钥保持不变）"
    echo "  - Hashids 盐值默认保留；确需轮换请用 --rotate-hashids"
fi

if [ "$ROTATE_HASHIDS" = 1 ]; then
    echo
    echo "Hashids 盐值已轮换：旧链接与已保存的 Hashids ID 将无法解析。"
fi

if [ "$ROTATE_ENCRYPTABLE" = 1 ]; then
    echo
    echo "字段加密密钥已轮换：旧密钥已加入 ENCRYPTION_PREVIOUS_KEYS，旧数据读取仍可解密，"
    echo "应用写回时会用新密钥重新加密；确认全部数据已重写后可移除历史密钥。"
fi

if [ "$ROTATE_TLS" = 1 ]; then
    echo
    echo "TLS 证书已重新生成为自签证书（CN=$DOMAIN）；如使用真实证书请重新放回 privkey/fullchain。"
fi

if [ "$ROTATE_DB" = 1 ]; then
    if mysql_has_data; then
        echo
        echo "数据库密码已轮换，但现有数据目录中的账号仍使用旧密码。请用旧 root 密码"
        echo "（见归档的 env/mysql.env）执行以下命令，否则应用无法连接数据库："
        echo
        echo "docker compose exec -T mysql sh -c 'MYSQL_PWD=\"<旧 MYSQL_ROOT_PASSWORD>\" mysql -uroot' <<'SQL'"
        echo "ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '$MYSQL_ROOT_PASSWORD';"
        echo "ALTER USER IF EXISTS 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASSWORD';"
        echo "ALTER USER '$DB_USERNAME'@'%' IDENTIFIED BY '$DB_PASSWORD';"
        echo "ALTER USER IF EXISTS '$DB_BACKUP_USERNAME'@'%' IDENTIFIED BY '$DB_BACKUP_PASSWORD';"
        echo "SQL"
        echo
        echo "若备份用户尚不存在，再执行一次 init 脚本创建并授权（幂等）："
        echo "docker compose exec -T mysql sh /docker-entrypoint-initdb.d/20-app-user.sh"
    else
        echo
        echo "全新部署：MySQL 初始化时会直接使用新的数据库密码，无需手动 ALTER USER。"
    fi
fi

if [ "$RESET_ADMIN" = 1 ] && mysql_has_data; then
    echo
    echo "管理员密码已生成新值，但已有管理员账号不会自动更新。请执行以下命令同步："
    echo
    cat <<EOF
  docker compose run --rm \
    -e NEW_ADMIN_PASSWORD=$(printf '%q' "$ADMIN_PASSWORD") \
    -e RESET_USERNAME=$(printf '%q' "$ADMIN_USERNAME") \
    --entrypoint php app -r '\$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE").";charset=utf8mb4",getenv("DB_USERNAME"),getenv("DB_PASSWORD"));\$s=\$p->prepare("UPDATE erik_admin_user SET password=? WHERE username=?");\$s->execute([password_hash(getenv("NEW_ADMIN_PASSWORD"),PASSWORD_BCRYPT),getenv("RESET_USERNAME")]);echo \$s->rowCount(),PHP_EOL;'
EOF
fi

echo
echo "下一步: cd $DEPLOY_DIR && docker compose up -d --build"
