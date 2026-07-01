# TokDoc

TokDoc 是一个本地文档管理器，用 Docker 或本机 Node 启动后，可以上传 HTML、Markdown、PDF、Word、PPT、Keynote 和 Excel，生成本地短 URL。HTML、Markdown 和 Word 可以预览并在页面内像文档一样直接编辑和自动保存；Excel 会生成表格阅读页；PDF 会以阅读器方式打开，PPT 和 Keynote 会先转换成 PDF 阅读版本。

## 本地启动

```bash
npm install
npm run dev
```

打开：

```text
http://127.0.0.1:8080/
http://127.0.0.1:8080/admin
```

## Docker 启动

首次部署建议先复制配置：

```bash
cp .env.example .env
```

线上部署时建议先修改 `.env` 里的 `TOKDOC_INITIAL_PASSWORD`。

```bash
docker compose up -d --build
```

Docker 默认访问地址：

```text
http://127.0.0.1:18082/
http://127.0.0.1:18082/admin
http://127.0.0.1:18082/healthz
```

如果要改宿主机端口：

```bash
TOKDOC_HOST_PORT=8088 docker compose up -d --build
```

如果要上传较大的 PPTX、PDF 或 Office 文件，可以在 `.env` 里调整单文件上传上限，默认是 200MB：

```bash
TOKDOC_UPLOAD_MAX_MB=500
```

默认挂载：

- `./data:/app/data`：SQLite、上传文件、生成页面和版本快照。
- `./html-inbox:/watch/html-inbox`：容器内默认监听目录。

升级时必须继续挂载同一个 `./data` 目录，不能删除、清空或改成新的空目录。TokDoc 启动时只会创建缺失目录、补充 SQLite 新字段和读取已有设置；不会重建数据库，也不会覆盖已有账号、上传源文件、生成页面、回收站或版本快照。升级前建议先备份整个 `data/` 目录。

线上域名部署建议保持 `.env` 里的 `TOKDOC_BIND_ADDR=127.0.0.1`，再用 Nginx 或宝塔反向代理到 `http://127.0.0.1:18082`。如果要局域网直接访问容器端口，可以改成 `TOKDOC_BIND_ADDR=0.0.0.0`。

Docker 镜像内置 LibreOffice Writer、Impress、Calc、Noto CJK 和 Liberation 字体，用于把 Word 转成可编辑 HTML 阅读页，把 PPT 和 Keynote 转成 PDF，并把 Excel 转成 HTML 表格阅读页。若本机 Node 直接启动并需要 Office、Keynote 或 Excel 转换，需要额外安装 LibreOffice，或通过 `TOKDOC_SOFFICE_BIN` 指定 `soffice` 路径。旧部署中的 `TOKHTML_*` 环境变量仍会被读取作为兼容 fallback。

完整服务器和宝塔部署说明见：

```text
docs/deploy-docker.md
```

## 登录

默认登录信息：

```text
用户名：admin
密码：tokdoc
```

新安装默认密码是 `tokdoc`。如果旧数据库已经保存过账号密码，系统会继续使用数据库里的旧设置，不会覆盖已有登录信息。

Docker 首次初始化空数据库时，可以通过 `.env` 里的 `TOKDOC_INITIAL_USERNAME` 和 `TOKDOC_INITIAL_PASSWORD` 修改初始登录信息。它们只在没有任何账号设置时生效，不会覆盖已有数据库。

登录成功后会写入长期会话 Cookie，默认保持登录状态。可以在“设置”里修改登录用户名和密码；密码留空保存时不会覆盖当前密码。

后台管理入口默认是 `/admin`，可在“设置”的“安全管理”里改成自定义单层目录，例如 `/tok-ops`。修改后台访问目录时需要输入当前密码；修改后旧 `/admin` 和固定 `/api/*` 管理接口会返回 404，只能通过新的 `/<后台目录>` 和 `/<后台目录>/api/*` 管理。普通生成页面 `/<slug>` 不需要登录即可访问；在线编辑模式 `/<slug>?edit=1` 仍需要后台登录态。旧格式 `/pages/<slug>.html` 会继续兼容访问。

访问 `/` 会打开公开文档索引页，不需要登录，默认展示全部已发布文档，并支持 `/type/html`、`/type/markdown`、`/type/pdf`、`/type/word`、`/type/presentation`、`/type/keynote`、`/type/spreadsheet` 类型页、搜索、排序和分页。可以在“设置”的“安全管理”里关闭“公开首页”；关闭后 `/` 和 `/public/api/pages` 会返回 404，但单个文档短链接 `/<slug>` 仍可公开访问。

如果忘记了自定义后台目录，可以临时用环境变量覆盖恢复：

```bash
TOKDOC_ADMIN_PATH=/admin npm run dev
```

Docker 场景可在启动时临时附加同名环境变量。注意它是运行时覆盖项，设置后会优先于数据库里保存的后台目录。

## 使用方式

1. 点击“选择文件”上传单个或多个 `.html/.htm/.md/.markdown/.pdf/.doc/.docx/.ppt/.pptx/.pptm/.pps/.ppsx/.key/.xls/.xlsx/.xlsm/.xlsb` 文件。
2. 点击“导入目录”可以通过浏览器批量导入一个目录下的 HTML、Markdown 文件和附件，同时也会识别目录中的 PDF、Word、PPT、Keynote 和 Excel。HTML 和 Markdown 目录上传会同步 CSS、JS、图片等相对路径附件，并自动写入 `/page-assets/<uploadId>/...` 资源根。
3. 在“设置”里填入容器可访问的目录路径，例如 `/watch/html-inbox`，保存后会持久化监听目录并扫描。
4. 在“设置”的“统计代码注入”里填写统计脚本，新上传或新扫描生成的 HTML 会自动注入该代码，并使用 TokDoc 标记包裹。
5. 文档列表里点击“预览”可在管理器内查看 HTML、Markdown、Word、Excel 阅读页或 PDF 阅读器；生成后的 `/<slug>` 可公开访问。
6. HTML、Markdown 和 Word 点击“编辑”会打开 `/<slug>?edit=1`，直接在页面中修改标题、段落、列表、表格等文字，编辑入口需要后台登录态。PDF、PPT、Keynote 和 Excel 作为阅读资产，不进入在线编辑桥。
7. 编辑模式会在鼠标所在的最小可调整模块内部显示一个 `↔` 悬浮手柄和四条边缘拉伸区。拖动 `↔` 可移动模块并写入 `left/top`；拖动上下左右边缘可调整 `width/height`。双击 `↔` 可清除自由定位并回到文档流。
8. 编辑后 600ms 防抖自动保存。保存前会生成版本快照。
9. 页面列表默认每页 20 条，底部翻页条支持上一页、下一页和页码跳转。
10. 访问 `/<slug>` 或编辑地址时，会自动统计访问次数，并显示在列表“目录名称”后。
11. 点击删除会把页面移入“回收站”，对应生成文件会移动到 `data/trash/` 下，原本的 `/<slug>` 不再可访问；在回收站里点击“恢复”可重新展示。
12. 在“设置”的“线上绑定”里填写同类线上程序的 API 地址和 Token 后，页面列表可一键上传当前 HTML 到线上程序。Markdown、PDF、Word、PPT、Keynote 和 Excel 暂不支持线上同步。
13. API 支持版本列表和恢复，默认后台目录下的地址为：

```bash
curl http://127.0.0.1:8080/admin/api/pages/<pageId>/versions
curl -X POST http://127.0.0.1:8080/admin/api/pages/<pageId>/restore/<versionId>
```

上传和目录扫描生成的文档 URL 会统一为：

```text
/f812c6
```

实体文件仍保存在 `data/pages/` 下，文件名会带日期、原始名称和短码。HTML、Markdown、Word 和 Excel 会生成 `.html`，PDF 保留 `.pdf`，PPT 和 Keynote 会转换成 `.pdf`，例如：

```text
data/pages/20260606-yi-xin-geo-report-f812c6.html
data/pages/20260608-contract-a1b2c3.pdf
```

## API 摘要

后台 API 使用 `/<后台目录>/api/*`，默认后台目录是 `/admin`。未自定义后台目录时，旧的 `/api/*` 仍兼容；自定义后台目录后，旧 `/api/*` 会隐藏。

- `GET /admin/api/health`
- `GET /admin/api/session`
- `POST /admin/api/login`
- `POST /admin/api/logout`
- `GET /admin/api/pages`
- `POST /admin/api/pages/upload`
- `POST /admin/api/pages/samples`
- `GET /admin/api/settings`
- `PATCH /admin/api/settings`
- `GET /admin/api/pages/:id`
- `PATCH /admin/api/pages/:id/content`
- `POST /admin/api/pages/:id/sync`
- `DELETE /admin/api/pages/:id`
- `POST /admin/api/pages/:id/restore`
- `GET /admin/api/pages/:id/versions`
- `POST /admin/api/pages/:id/restore/:versionId`
- `GET /admin/api/watch-dirs`
- `POST /admin/api/watch-dirs`
- `DELETE /admin/api/watch-dirs/:id`
- `POST /admin/api/watch-dirs/:id/rescan`
- `GET /public/api/pages`：公开列表 API，只返回公开字段
- `GET /`
- `GET /type/html`
- `GET /type/markdown`
- `GET /type/pdf`
- `GET /type/word`
- `GET /type/presentation`
- `GET /type/keynote`
- `GET /type/spreadsheet`
- `GET /:slug`
- `GET /:slug?edit=1`
- `GET /pages/:slug.html`：旧链接兼容

## 数据目录

```text
data/tokdoc.db        SQLite 数据库；旧安装若已有 data/tokhtml.db 会继续读取旧库
data/uploads/         上传源文件副本，含 HTML/Markdown/PDF/Word/PPT/Keynote/Excel 原文件和目录附件
data/pages/           生成后的本地页面或阅读文件，文件名包含日期、原始名称和短码
data/trash/           回收站文件，未注册为可访问静态目录
data/versions/        自动保存和恢复用的版本快照
page-assets/          运行时静态前缀，映射到 data/uploads/ 内的上传附件
html-inbox/           本地默认监听目录
```

从旧 TokHtml 升级到 TokDoc 时：

- 本机 Node 直接启动会自动探测同级 `../TokHtml/data/tokhtml.db`，前提是新的 `TokDoc/data/` 里还没有数据库。
- Docker Compose 默认只挂载当前 `TokDoc/data`，如果旧数据仍在 `tools/TokHtml/data`，先把旧 `data` 目录移动或复制到 `tools/TokDoc/data`，再启动新容器。

数据安全约定：

- 正常升级只执行 `docker compose up -d --build` 或 `docker compose pull && docker compose up -d --no-build`，不要删除 `data/`。
- `.env` 中的 `TOKDOC_DATA_VOLUME` 必须指向原来的持久化目录；如果改到新目录，旧文档不会丢失，但新容器会看到一个空库。
- SQLite 迁移采用补列方式，已有页面、公开/私有状态、访问次数、后台地址、账号密码和线上绑定设置都会保留。
- 删除文档只会在后台点击删除时进入 `data/trash/`，升级流程不会主动移动或删除页面文件。

## 测试

```bash
npm test
```

## 原型归档

早期单文件 UI 原型保留在：

```text
docs/prototype/tokdoc-prototype.html
```
