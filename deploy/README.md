# 单机生产部署（Docker Compose）

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

本目录提供面向单台服务器的生产编排：`nginx + app + init + mysql + redis`，不含 Elasticsearch。
所有持久化数据、日志与缓存都挂载在 `deploy/data` 下，删除容器不会丢数据。

## 快速开始

```bash
cd deploy
./generate-secrets.sh          # 生成 .env、RSA 密钥、自签证书、管理员密码与数据目录
docker compose up -d --build   # 构建镜像并启动全部服务
```

启动后：

- 管理后台 / API：`https://<DOMAIN>/`（默认 `https://localhost`，自签证书会有浏览器警告）
- 静态官网：`https://<DOMAIN>/open-admin/`
- HTTP 会自动 301 跳转到 HTTPS
- 初始管理员用户名见脚本输出（默认 `admin`），密码在 `deploy/secrets/admin_password.txt`

首次登录后请立即修改管理员密码。

常用命令（在 `deploy/` 目录内执行）：

```bash
docker compose ps
docker compose logs -f app
docker compose restart nginx
docker compose down
```

## 文件与目录

| 路径 | 内容 | 权限 |
|------|------|------|
| `deploy/.env` | 全部环境变量与生成的密钥（含 RSA 私钥） | 600 |
| `deploy/secrets/rsa_public_key.pem` | RSA 公钥（与 `.env` 中私钥配对） | 600 |
| `deploy/secrets/admin_password.txt` | 初始管理员密码 | 600 |
| `deploy/secrets/tls/fullchain.pem`、`privkey.pem` | TLS 证书（默认自签） | 600 |
| `deploy/secrets/archive/<时间戳>/` | `--regenerate` 归档的旧 `.env` 与 `secrets/` | 700 |
| `deploy/data/mysql` | MySQL 数据目录 | 700 |
| `deploy/data/mysql-logs` | MySQL 错误日志 | 700 |
| `deploy/data/redis` | Redis AOF 持久化 | 700 |
| `deploy/data/runtime` | webman 运行时、日志、`install.lock` | 700 |
| `deploy/data/upload` | 后台上传文件 | 700 |
| `deploy/data/nginx/logs` | Nginx access / error 日志 | 700 |
| `deploy/data/backups` | 备份输出目录 | 700 |

> 容器首次启动后会把 `data/mysql`、`data/mysql-logs`、`data/redis` 的所有者改为容器内用户
> （MySQL/Redis 镜像的标准行为），宿主机普通用户随后无法直接读取这些目录，属正常现象。
> Docker 日志按 `10m × 3` 轮转；MySQL / Nginx 自身日志写入上面的宿主机目录。

MySQL 与 Redis 仅在 compose 内部网络暴露，不映射宿主机端口；对外只有 `HTTP_PORT`（默认 80）与
`HTTPS_PORT`（默认 443）。

## 使用真实域名与证书

1. 在 `deploy/.env` 中把 `DOMAIN` 改成真实域名（例如 `admin.example.com`），并按需修改
   `HTTP_PORT` / `HTTPS_PORT`。
2. 用真实证书替换下面两个文件（保持文件名不变）：

   ```bash
   deploy/secrets/tls/fullchain.pem   # 证书链
   deploy/secrets/tls/privkey.pem     # 私钥
   ```

3. `docker compose restart nginx`。

脚本只在证书缺失时生成自签证书，**不会覆盖**已存在的证书。证书由证书链 + 私钥组成，不依赖 ACME；
如需 HTTP-01 校验，可把校验文件放到 `public/.well-known/acme-challenge/`，HTTP 80 不会重定向该路径。

## 重新生成密钥（--regenerate）

```bash
cd deploy
docker compose down
./generate-secrets.sh --regenerate
docker compose up -d --build
```

脚本先把当前 `deploy/.env` 与整个 `deploy/secrets/` 复制到
`deploy/secrets/archive/<时间戳>/`，归档成功后才删除并重新生成；旧值不会丢失。
归档目录包含旧 RSA 私钥、旧数据库密码与旧证书，请按密钥材料妥善保管。

> **已有数据库数据时注意**：`--regenerate` 会生成新的 MySQL root / 应用用户密码，但
> `deploy/data/mysql` 里已存在的账号密码不会自动改变，直接启动会导致应用连不上数据库。
> 三种处理方式：
> 1. 保留数据：从归档的 `.env` 中取回旧 `DB_PASSWORD` / `MYSQL_ROOT_PASSWORD` 填回新 `.env`；
> 2. 保留数据：用旧密码进入 MySQL 执行 `ALTER USER` 改成新密码；
> 3. 全新部署：删除 `deploy/data/mysql` 后重新启动（**会丢失全部业务数据**，请先备份）。

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

## 备份与恢复

备份（写入宿主机 `deploy/data/backups`，无需暴露数据库端口）：

```bash
cd deploy
docker compose exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --triggers --default-character-set=utf8mb4 "$MYSQL_DATABASE"' \
  | gzip > "data/backups/backup_$(date +%Y%m%d_%H%M%S).sql.gz"
```

恢复：

```bash
gunzip -c deploy/data/backups/backup_YYYYMMDD_HHMMSS.sql.gz \
  | docker compose -f deploy/docker-compose.yml exec -T mysql sh -c \
      'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"'
```

`database/backup/backup.sh` 与 `restore.sh` 面向宿主机直连数据库的场景（读取根目录 `.env`），
不适用于本 compose 栈（容器内主机名是 `mysql`，且密码在 `deploy/.env`）。

## 初始化与运维说明

- `init` 容器只在 MySQL 健康后运行一次：表缺失时执行 `database/install.sql`，无管理员时创建
  初始管理员并绑定 `super_admin`，随后写入 `data/runtime/install.lock` 并退出。重复执行不会重置密码。
- `app` 与 `nginx` 以只读根文件系统运行，仅 `runtime`、上传目录、Nginx 日志与 `/tmp` 可写。
- 健康检查：`GET /health`（应用）、`/nginx-health`（Nginx 内部）。
- 查看初始管理员密码：`cat deploy/secrets/admin_password.txt`。
