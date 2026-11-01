#!/usr/bin/env bash
# ============================================================
# 开放管理后台 — 备份异地同步（由宿主机 cron 调用）
# Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#
# 读取 deploy/.env 中的 OFFSITE_TARGET（也可用同名环境变量覆盖），
# 把 deploy/data/backups/ 同步到远端：
#   rclone:remote:path    rclone sync（如 rclone:s3:open-admin-backup）
#   user@host:/path/      rsync -a --delete（远端目录必须专用于本备份）
# 未配置 OFFSITE_TARGET 时直接报错退出；不会被任何容器自动调用。
# ============================================================
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="$DEPLOY_DIR/data/backups"
ENV_FILE="$DEPLOY_DIR/.env"

die() { echo "[offsite-sync] 错误: $*" >&2; exit 1; }
log() { echo "[offsite-sync] $(date '+%F %T') $*"; }

# 只解析单个键，不 source .env（避免执行文件内容）
if [ -z "${OFFSITE_TARGET:-}" ] && [ -r "$ENV_FILE" ]; then
    OFFSITE_TARGET="$(sed -n 's/^[[:space:]]*OFFSITE_TARGET[[:space:]]*=[[:space:]]*//p' "$ENV_FILE" | tail -n 1)"
    OFFSITE_TARGET="${OFFSITE_TARGET%\"}"
    OFFSITE_TARGET="${OFFSITE_TARGET#\"}"
fi

[ -n "${OFFSITE_TARGET:-}" ] \
    || die "未配置 OFFSITE_TARGET：请在 $ENV_FILE 中设置 rclone:remote:path 或 user@host:/path/（见 README 异地备份）"
[ -d "$SOURCE_DIR" ] || die "备份目录不存在：$SOURCE_DIR"

# 没有正式备份时不同步：--delete 会用空目录清空远端历史
latest="$(find "$SOURCE_DIR" -maxdepth 1 -type f \
    \( -name 'backup_*.sql.gz' -o -name 'upload_*.tar.gz' \) -print -quit)"
[ -n "$latest" ] || die "备份目录中没有 backup_*.sql.gz / upload_*.tar.gz，已跳过同步（避免清空远端）"

# 点开头的是 backup 服务的临时文件，可能尚未写完
EXCLUDES=(--exclude '.backup_*' --exclude '.upload_*')

case "$OFFSITE_TARGET" in
    rclone:*)
        remote="${OFFSITE_TARGET#rclone:}"
        case "$remote" in
            *:*)
                [ -n "${remote%%:*}" ] || die "rclone remote 名称不能为空（当前：$OFFSITE_TARGET）"
                case "${remote#*:}" in
                    ""|/|.|./|../|..)
                        die "拒绝同步到 rclone 路径 '${remote#*:}'：请指定 remote 下的专用备份目录（当前：$OFFSITE_TARGET）"
                        ;;
                esac
                ;;
            *) die "rclone 目标需为 remote:path（当前：$OFFSITE_TARGET）" ;;
        esac
        command -v rclone >/dev/null 2>&1 || die "未找到 rclone，请先安装并配置 remote"
        log "rclone sync $SOURCE_DIR/ -> $remote"
        rclone sync "$SOURCE_DIR/" "$remote" "${EXCLUDES[@]}" || die "rclone sync 失败（退出码 $?）"
        ;;
    *:*)
        dest_path="${OFFSITE_TARGET#*:}"
        case "$dest_path" in
            ""|/|.|./|../|..|"~"|"~/") die "拒绝同步到 '$dest_path'：请指定目标主机上的专用备份目录（当前：$OFFSITE_TARGET）" ;;
        esac
        command -v rsync >/dev/null 2>&1 || die "未找到 rsync，请先安装"
        dest="${OFFSITE_TARGET%/}/"
        log "rsync $SOURCE_DIR/ -> $dest"
        rsync -a --delete "${EXCLUDES[@]}" "$SOURCE_DIR/" "$dest" || die "rsync 失败（退出码 $?）"
        ;;
    *)
        die "无法识别的 OFFSITE_TARGET（需要 rclone:remote:path 或 user@host:/path/）：$OFFSITE_TARGET"
        ;;
esac

log "同步完成"
