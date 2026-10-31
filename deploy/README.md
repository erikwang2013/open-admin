# 单机生产部署（Docker Compose）

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

本目录提供面向单台服务器的生产编排：`nginx + app + init + mysql + redis + backup`，不含 Elasticsearch。
所有持久化数据与备份都挂载在 `deploy/data` 下，删除容器不会丢数据。

## 快速开始

```bash
cd deploy
./generate-secrets.sh          # 生成 .env、env/*.env、RSA 密钥、自签证书、管理员密码与数据目录
docker compose up -d --build   # 构建镜像并启动全部服务
```

启动后：

- 管理后台 / API：`https://<DOMAIN>/`（默认 `https://localhost`，自签证书会有浏览器警告）
- 静态官网：`https://<DOMAIN>/open-admin/`
- API 文档 UI：`https://<DOMAIN>/apidoc/`
- HTTP 会自动 301 跳转到 HTTPS
- 初始管理员用户名见脚本输出（默认 `admin`），密码在 `deploy/secrets/admin_password.txt`

首次登录后请立即修改管理员密码。

常用命令（在 `deploy/` 目录内执行）：

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f backup
docker compose restart nginx
docker compose down
```

## 环境文件

密钥按服务拆分，避免应用容器拿到 MySQL root 密码或管理员初始密码：

| 文件 | 内容 | 可见服务 | 权限 |
|------|------|----------|------|
| `deploy/.env` | 域名、端口、宿主机 UID/GID（仅 compose 插值） | compose | 600 |
| `deploy/env/app.env` | `APP_*`、`JWT_*`、`HASHIDS_*`、`SNOWFLAKE_*`、`ENCRYPTION_*`（含 RSA 私钥）、`ENCRYPTABLE_*`、`DB_*`（仅应用用户）、`REDIS_*`、`POSTER_*` | app | 600 |
| `deploy/env/init.env` | `DB_*`（仅应用用户）、`ADMIN_USERNAME`、`ADMIN_PASSWORD`、`SNOWFLAKE_*` | init | 600 |
| `deploy/env/mysql.env` | `MYSQL_ROOT_PASSWORD`、`MYSQL_DATABASE`、`DB_*`、`DB_BACKUP_USERNAME`、`DB_BACKUP_PASSWORD` | mysql | 600 |
| `deploy/env/redis.env` | `REDIS_PASSWORD` | redis | 600 |
| `deploy/env/backup.env` | `DB_BACKUP_USERNAME`、`DB_BACKUP_PASSWORD`、`MYSQL_DATABASE`（不含 root 凭据） | backup | 600 |

`deploy/env/` 目录权限为 700。脚本默认幂等，只补齐缺失项；**自定义键会被保留**，
已有键原地更新，不会整文件覆盖。

> 从旧版单文件 `deploy/.env` 升级：直接执行 `./generate-secrets.sh` 即可，脚本会先把旧
> `.env` 与 `secrets/` 归档到 `deploy/secrets/archive/<时间戳>/`，再把旧值迁移到
> `deploy/env/*.env` 并从主 `.env` 中移除已迁移的密钥。若旧数据目录归其他用户所有，迁移时的
> chown 会报错退出，请按下面的 UID/GID 说明用 `sudo` 或调整 `APP_UID`/`APP_GID`。

## 文件与目录

| 路径 | 内容 | 权限 |
|------|------|------|
| `deploy/.env` | compose 插值配置（域名/端口/UID） | 600 |
| `deploy/env/*.env` | 各服务环境变量（见上表） | 600 |
| `deploy/secrets/rsa_public_key.pem` | RSA 公钥（与 `app.env` 中私钥配对） | 600 |
| `deploy/secrets/admin_password.txt` | 初始管理员密码 | 600 |
| `deploy/secrets/tls/fullchain.pem`、`privkey.pem` | TLS 证书（默认自签） | 600 |
| `deploy/secrets/archive/<时间戳>/` | 轮换时归档的旧 `.env`、`env/` 与 `secrets/` | 700 |
| `deploy/data/mysql` | MySQL 数据目录 | 700 |
| `deploy/data/redis` | Redis AOF 持久化 | 700 |
| `deploy/data/runtime` | webman 运行时、日志、`install.lock` | 700 |
| `deploy/data/upload` | 后台上传文件 | 700 |
| `deploy/data/backups` | 自动备份输出目录（`backup_*.sql.gz`、`upload_*.tar.gz`，保留 30 天） | 700 |

> 容器首次启动后会把 `data/mysql`、`data/redis` 的所有者改为容器内用户
> （MySQL/Redis 镜像的标准行为），宿主机普通用户随后无法直接读取这些目录，属正常现象。

`app`、`init` 与 `backup` 以 `APP_UID:APP_GID` 运行（脚本读取环境变量或 `deploy/.env` 中的
显式设置，未设置时取当前用户的 `id -u`/`id -g`）；脚本会把 `data/runtime`、`data/upload`、
`data/backups` 的所有者改为该用户，保证挂载目录可写，备份文件也不会归 root 所有。
chown 失败会直接报错退出（不再静默忽略）：请用有权限的用户执行（如 `sudo`），或把
`APP_UID`/`APP_GID` 设为当前用户后重试。若解析结果为 0（root）且未显式设置，脚本拒绝执行；
显式设为 0 只给出警告。如需换用户，修改 `deploy/.env` 后重新执行脚本（会重新 chown 这三个目录）。

MySQL、Redis 与 backup 仅在 compose 内部网络运行，不映射宿主机端口；对外只有 `HTTP_PORT`
（默认 80）与 `HTTPS_PORT`（默认 443）。

## 日志

MySQL、Nginx 与备份容器全部输出到 stdout/stderr，由 Docker 的 `json-file` 驱动按
`10m × 3` 轮转，不再向宿主机写日志文件：

```bash
docker compose logs -f mysql
docker compose logs -f nginx
docker compose logs -f backup
```

## 使用真实域名与证书

1. 在 `deploy/.env` 中把 `DOMAIN` 改成真实域名（例如 `admin.example.com`），并按需修改
   `HTTP_PORT` / `HTTPS_PORT`。
2. 用真实证书替换下面两个文件（保持文件名不变）：

   ```bash
   deploy/secrets/tls/fullchain.pem   # 证书链
   deploy/secrets/tls/privkey.pem     # 私钥
   ```

3. `docker compose restart nginx`。

脚本默认只在证书缺失时生成自签证书，**不会覆盖**已有证书，`--regenerate` 也不会；
只有显式 `--rotate-tls` 才会重新生成（见下文）。`fullchain.pem` 与 `privkey.pem` 必须同时存在：
若只剩其中一个，脚本会报错退出并提示补齐缺失文件或使用 `--rotate-tls`，绝不静默替换幸存证书。
证书由证书链 + 私钥组成，不依赖 ACME；如需 HTTP-01 校验，可把校验文件放到
`public/.well-known/acme-challenge/`，HTTP 80 不会重定向该路径。

HSTS（`Strict-Transport-Security`）在 `nginx/default.conf` 中默认注释关闭：自签证书阶段
启用会让浏览器长期强制 HTTPS。配置真实域名证书后再取消注释并重启 nginx。

## 重新生成密钥

### 默认行为

```bash
cd deploy
docker compose down
./generate-secrets.sh --regenerate
docker compose up -d --build
```

`--regenerate` 轮换应用密钥（JWT、传输层加密 `ENCRYPTION_KEY`、RSA、Redis 密码），
但 **保留** Hashids 盐值、`DB_PASSWORD`、`MYSQL_ROOT_PASSWORD`、`ADMIN_PASSWORD`、
`secrets/admin_password.txt`、数据库字段加密密钥 `ENCRYPTABLE_KEY` / `ENCRYPTION_PREVIOUS_KEYS`
以及现有 TLS 证书，因此已有数据库、加密字段、对外 ID 和真实证书都不受影响。

影响范围：

- JWT 轮换：所有已登录会话失效；
- Hashids 盐值默认保留，旧链接中的 ID 仍可解析；确需轮换请用 `--rotate-hashids`（见下文）；
- RSA 轮换：登录页会自动重新获取公钥；仍依赖内置回退公钥的旧客户端需重新构建；
- `ENCRYPTABLE_KEY` 默认不轮换；确需轮换请用 `--rotate-encryptable`（见下文）；
- TLS 证书默认不轮换；确需重签自签证书请用 `--rotate-tls`（见下文）。

脚本先把当前 `deploy/.env`、`deploy/env/` 与整个 `deploy/secrets/` 复制到
`deploy/secrets/archive/<时间戳>/`，归档成功后才写新值。归档目录包含旧 RSA 私钥、
旧数据库密码与旧证书，请按密钥材料妥善保管。

### 轮换 Hashids 盐值（--rotate-hashids）

```bash
./generate-secrets.sh --rotate-hashids   # 可与其他参数组合
```

生成新的 `HASHIDS_SALT` / `HASHIDS_ALT_SALT`。轮换后旧链接、收藏夹以及外部系统中已保存的
Hashids ID 将无法解析（数据库主键不变）。只有确认没有依赖旧 ID 的链接/集成后才应执行。

### 轮换数据库密码（--rotate-db）

```bash
./generate-secrets.sh --rotate-db    # 可与其他参数组合
```

生成新的 `MYSQL_ROOT_PASSWORD` / `DB_PASSWORD` / `DB_BACKUP_PASSWORD` 并写入 `deploy/env/mysql.env` 等文件。
已存在的 MySQL 数据目录不会自动改密码，脚本会打印需要手动执行的 `ALTER USER` 命令
（旧 root 密码见归档中的 `env/mysql.env`）。全新部署无需手动执行。

### 重置管理员密码（--reset-admin）

```bash
./generate-secrets.sh --reset-admin  # 可与其他参数组合
```

生成新的 `ADMIN_PASSWORD` 并覆盖 `deploy/secrets/admin_password.txt`，脚本会明确提示。
已有管理员账号不会自动更新，脚本会打印同步命令（通过 app 容器执行 `password_hash` 更新）。

### 轮换字段加密密钥（--rotate-encryptable）

```bash
./generate-secrets.sh --rotate-encryptable   # 可与其他参数组合
```

生成新的 `ENCRYPTABLE_KEY`，并把旧密钥追加到 `ENCRYPTION_PREVIOUS_KEYS`（逗号分隔、去重）。
`erikwang2013/encryptable` 读取旧数据时会依次尝试历史密钥解密，写回时用新密钥重新加密，
因此轮换后已有数据仍可读取。注意：只读不会自动重写旧数据；确认所有敏感字段都已重新写入后，
才能从 `app.env` 中移除历史密钥（`ENCRYPTION_PREVIOUS_KEYS`）。

### 轮换 TLS 证书（--rotate-tls）

```bash
./generate-secrets.sh --rotate-tls           # 可与其他参数组合
```

删除并重新生成自签证书（CN=`DOMAIN`）。**会替换真实证书**，执行前请确认归档
（`deploy/secrets/archive/<时间戳>/tls/`）或自己的备份可用；放回真实证书后
`docker compose restart nginx`。

### RSA 密钥轮换与客户端

`generate-secrets.sh` 生成的 RSA 密钥对替代了客户端代码中的内置公钥。登录页在加密密码前会调用
`GET /api/v1/auth/public-key` 获取服务端当前公钥，因此：

- 已包含该逻辑的 Flutter 客户端无需重新打包，重启/刷新后自动使用新公钥；
- **不支持该接口的旧客户端**（或离线缓存了旧公钥的客户端）在密钥轮换后无法登录，需要重新构建：

  ```bash
  cd apps/flutter
  flutter build web --dart-define=ENV=prod
  ```

- 服务端未配置 `RSA_PRIVATE_KEY` 时该接口返回 `code: 404`，客户端回退到内置公钥。

## 资源限制

compose 为每个服务设置了 `deploy.resources.limits.memory`，超过上限的容器会被 OOM 杀掉：

| 服务 | 内存上限 | 说明 |
|------|----------|------|
| `app` | 512M | PHP/webman 进程 |
| `init` | 256M | 一次性初始化 |
| `mysql` | 768M | `innodb-buffer-pool-size=256M` |
| `redis` | 256M | `maxmemory 256mb`（另需少量进程开销） |
| `nginx` | 128M | 反向代理与静态文件 |
| `backup` | 256M | `mysqldump`、`gzip` 与 `tar` |

数值按单机小规模部署估算，会随用户量、导入导出和上传文件大小变化。若容器出现 OOM
（`docker inspect <容器> --format '{{.State.OOMKilled}}'` 为 `true`），调高对应服务的
`memory` 后执行 `docker compose up -d` 生效。MySQL 与 Redis 的上限需同时高于各自的
`innodb-buffer-pool-size` / `maxmemory` 配置加进程开销。

## 备份与恢复

`backup` 服务在 MySQL 健康后立即执行一次，之后每 24 小时执行一次：

- 数据库：`mysqldump --single-transaction --no-tablespaces --default-character-set=utf8mb4`
  → gzip → `deploy/data/backups/backup_<时间戳>.sql.gz`；
- 上传文件：`deploy/data/upload` 以只读方式挂载，打包为 `upload_<时间戳>.tar.gz`；
- 两类备份均保留 30 天，容器启动时清理异常退出遗留的临时文件；
- 每轮用备份用户执行一次
  `DELETE FROM erik_operation_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY) LIMIT 10000`，
  每天最多清理 1 万行；清理失败只记录日志，不影响备份。

备份使用专用的最小权限 MySQL 用户（默认 `backup_reader`）：应用库的
`SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES`，外加 `erik_operation_log` 表的 `DELETE`。
`backup.env` 不再保存 root 密码，root 凭据只在 `mysql.env`（仅 mysql 容器可见）中。
备份容器以 `APP_UID:APP_GID` 运行，备份文件归宿主用户所有，无需 root 读取；容器日志按
`10m × 3` 轮转。

> **升级已有部署**：`docker-entrypoint-initdb.d` 只在 MySQL 数据目录为空时执行。已有部署升级后
> 需手动补建备份用户（脚本幂等）：
>
> ```bash
> cd deploy
> ./generate-secrets.sh        # 生成 DB_BACKUP_* 并从 backup.env 移除旧 root 密码
> docker compose up -d         # 用新的 env 重建容器
> docker compose exec -T mysql sh /docker-entrypoint-initdb.d/20-app-user.sh
> docker compose restart backup   # 补建用户后立即做一次备份，而不是等 24 小时
> ```
>
> 之后若用 `--rotate-db` 轮换数据库密码，按脚本输出同时更新备份用户的 `ALTER USER`。

### 异地备份

`deploy/data/backups` 与数据库位于**同一台机器、同一块磁盘**，主机或磁盘故障时备份会一起丢失。
请把备份定期复制到异地，例如宿主机 cron：

```bash
# rsync 到另一台主机（建议使用 SSH 密钥）
rsync -a --delete deploy/data/backups/ backup@backup.example.com:/srv/open-admin/backups/

# 或使用 rclone 同步到 S3 兼容对象存储
rclone sync deploy/data/backups/ remote:open-admin-backups/
```

远端同样要保留 30 天以上的历史（对象存储版本控制或远端轮转），否则误删或勒索软件会同时
影响近端与远端备份。

手动备份：

```bash
cd deploy
docker compose exec -T mysql sh -c \
  'MYSQL_PWD="$DB_BACKUP_PASSWORD" mysqldump -u"$DB_BACKUP_USERNAME" --single-transaction --no-tablespaces --default-character-set=utf8mb4 "$MYSQL_DATABASE"' \
  | gzip > "data/backups/backup_$(date +%Y%m%d_%H%M%S).sql.gz"
```

恢复（需要写权限，使用 root）：

```bash
gunzip -c deploy/data/backups/backup_YYYYMMDD_HHMMSS.sql.gz \
  | docker compose -f deploy/docker-compose.yml exec -T mysql sh -c \
      'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"'
```

`database/backup/backup.sh` 与 `restore.sh` 面向宿主机直连数据库的场景（读取根目录 `.env`），
不适用于本 compose 栈（容器内主机名是 `mysql`，且密码在 `deploy/env/mysql.env`）。

## 初始化与运维说明

- `init` 容器只在 MySQL 健康后运行一次：表缺失时执行 `database/install.sql`，无管理员时创建
  初始管理员并绑定 `super_admin`，随后写入 `data/runtime/install.lock` 并退出。重复执行不会重置密码。
- `app` 与 `nginx` 以只读根文件系统运行，仅 `runtime`、上传目录与 `/tmp` 可写。
- API 文档 UI（`/apidoc/`）不进入镜像，由 nginx 直接读取宿主机挂载的 `../public/apidoc`。
- 健康检查：`GET /health`（应用）、`/nginx-health`（Nginx 内部）。
- 查看初始管理员密码：`cat deploy/secrets/admin_password.txt`。
