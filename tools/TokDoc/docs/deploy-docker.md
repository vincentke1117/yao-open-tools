# TokDoc Docker 部署指南

TokDoc 支持用 Docker Compose 一键部署。推荐线上服务器只把容器端口绑定到 `127.0.0.1`，再用 Nginx 或宝塔网站反向代理到域名。

## 服务器要求

- Docker Engine 24+ 或宝塔面板内置 Docker。
- Docker Compose v2。
- 至少 1 CPU、1 GB 内存。Word 转 PDF 会调用 LibreOffice，建议 2 GB 内存以上。
- 至少 2 GB 可用磁盘空间，实际空间取决于上传文档和版本快照数量。

## 一键启动

进入 `tools/TokDoc` 目录：

```bash
cp .env.example .env
docker compose up -d --build
```

线上部署前建议先修改 `.env` 里的 `TOKDOC_INITIAL_PASSWORD`。它只在空数据库首次初始化时生效。

默认访问：

```text
http://127.0.0.1:18082/
http://127.0.0.1:18082/admin
http://127.0.0.1:18082/healthz
```

查看状态：

```bash
docker compose ps
docker compose logs -f tokdoc
```

停止：

```bash
docker compose down
```

## .env 配置

复制 `.env.example` 后按需修改：

```dotenv
TOKDOC_HOST_PORT=18082
TOKDOC_BIND_ADDR=127.0.0.1
TOKDOC_DATA_VOLUME=./data
TOKDOC_WATCH_VOLUME=./html-inbox
TOKDOC_CONTAINER_WATCH_DIRS=/watch/html-inbox
TOKDOC_ALLOW_SOURCE_WRITE=false
TOKDOC_ADMIN_PATH=
TOKDOC_INITIAL_USERNAME=admin
TOKDOC_INITIAL_PASSWORD=tokdoc
TOKDOC_NODE_IMAGE=node:22-bookworm-slim
TOKDOC_IMAGE=tokdoc:latest
TOKDOC_CONTAINER_NAME=tokdoc
```

关键项：

- `TOKDOC_BIND_ADDR=127.0.0.1`：推荐线上默认值，只允许服务器本机反代访问容器端口。
- `TOKDOC_HOST_PORT=18082`：宿主机端口，Nginx 反代到这个端口。
- `TOKDOC_DATA_VOLUME=./data`：SQLite、上传文件、生成文档、版本快照和回收站，必须持久化和备份。
- `TOKDOC_WATCH_VOLUME=./html-inbox`：宿主机监听目录，挂载到容器内 `/watch/html-inbox`。
- `TOKDOC_INITIAL_USERNAME/PASSWORD`：只在空数据库首次初始化时生效，已有数据库里的账号密码不会被覆盖。
- `TOKDOC_ADMIN_PATH`：临时覆盖后台目录，忘记后台地址时可设置为 `/admin` 后重启容器。
- `TOKDOC_NODE_IMAGE`：构建镜像用的 Node 基础镜像，默认使用稳定 Debian LTS。只有在服务器有企业镜像缓存或 Docker Hub 拉取慢时才需要改。

如果需要局域网直接访问容器端口，可以把绑定地址改成：

```dotenv
TOKDOC_BIND_ADDR=0.0.0.0
```

线上域名部署不建议这样做。

## 宝塔面板部署

适合已经把 TokDoc 上传到网站目录的场景，例如：

```text
/www/wwwroot/ai.laoyao.cn/tools/TokDoc
```

操作步骤：

1. 在宝塔文件管理中进入 `tools/TokDoc`。
2. 复制 `.env.example` 为 `.env`。
3. 修改 `.env`，建议保留 `TOKDOC_BIND_ADDR=127.0.0.1`。
4. 打开宝塔 Docker，使用 Compose 项目创建，项目目录选择 `tools/TokDoc`。
5. 启动 Compose。
6. 在网站设置中添加反向代理到 `http://127.0.0.1:18082`。

宝塔反向代理建议：

```nginx
client_max_body_size 200m;

location / {
    proxy_pass http://127.0.0.1:18082;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

`client_max_body_size` 要大于你准备上传的 PDF 或 Word 文件大小。

## 数据目录

容器内：

```text
/app/data
/watch/html-inbox
```

宿主机默认：

```text
./data
./html-inbox
```

`./data` 内包含：

```text
tokdoc.db
uploads/
pages/
trash/
versions/
```

备份时至少备份整个 `data/` 目录。

## 升级

源码部署时：

```bash
docker compose down
docker compose up -d --build
```

如果使用远程镜像：

```bash
docker compose pull
docker compose up -d --no-build
```

升级前建议备份：

```bash
tar -czf tokdoc-data-$(date +%Y%m%d-%H%M%S).tar.gz data
```

## 回滚

如果升级后需要回滚：

1. 停止容器。
2. 切回旧代码或旧镜像。
3. 如果新版本已经写坏数据，恢复升级前的 `data/` 备份。
4. 重新启动 Compose。

```bash
docker compose down
tar -xzf tokdoc-data-YYYYMMDD-HHMMSS.tar.gz
docker compose up -d --build
```

## 健康检查

TokDoc 固定提供：

```text
/healthz
```

这个地址不受后台目录自定义影响，Docker 健康检查和反向代理探活都可以使用它。

```bash
curl http://127.0.0.1:18082/healthz
```

正常返回：

```json
{"ok":true,"name":"tokdoc","time":"2026-06-09T00:00:00.000Z"}
```

## 常见问题

### 访问域名提示 502

先检查容器是否运行：

```bash
docker compose ps
curl http://127.0.0.1:18082/healthz
```

如果本机健康检查正常，问题通常在 Nginx 反向代理配置。

### 上传大文件失败

调大 Nginx 或宝塔站点配置：

```nginx
client_max_body_size 200m;
```

### 忘记后台地址

在 `.env` 临时设置：

```dotenv
TOKDOC_ADMIN_PATH=/admin
```

重启：

```bash
docker compose up -d
```

登录后可以在设置中重新保存后台地址。修复后建议清空 `.env` 里的 `TOKDOC_ADMIN_PATH`，让数据库设置继续生效。

### 忘记密码

目前初始密码环境变量不会覆盖已有数据库。已有数据库忘记密码时，需要通过数据库维护或后续补充重置命令处理。
