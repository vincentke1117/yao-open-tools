# TokDoc 前台公开列表页升级方案

## 结论

建议把 TokDoc 的 `/` 从当前的后台跳转入口升级为“公开文档索引页”。这个页面不需要登录，展示管理员已发布的全部非回收站文档，支持 HTML、PDF、Word 三类筛选、搜索、分页和打开阅读。后台仍使用自定义管理地址，例如 `/admin` 或 `/tok-ops`，公开短链接仍保持 `/<slug>`。

这个方案的价值是让 TokDoc 不只是“后台上传后得到零散短链接”，而是自然形成一个可访问、可浏览、可筛选的本地文档门户。

## 建设范围

本次升级建议建设：

- `GET /`：公开列表页，默认展示全部已发布文档。
- `GET /type/:fileType`：类型列表页，支持 `/type/html`、`/type/pdf`、`/type/word`。
- `GET /public/api/pages`：公开列表 API，只返回公开可展示字段。
- 前台列表页 UI：搜索、类型筛选、排序摘要、分页、文档卡片或表格列表。
- 后台管理页内可显示“前台首页”入口，方便管理员跳转查看公开效果。

本次不建议建设：

- 不做前台登录，不做访客权限分组。
- 不公开回收站、源文件路径、监听目录绝对路径、版本历史、编辑入口、线上同步入口。
- 不做全文检索，只做标题、文件名、目录名、短码和本地 URL 的轻量搜索。
- 不新增数据库表，先复用 `pages` 表和现有分页逻辑。
- 不做 SEO 复杂能力，例如 sitemap、结构化数据、分享图。

## 当前系统依据

现有代码已经具备可复用能力：

- `PageStore.listPagesPage(filters)` 已支持分页、搜索、状态、目录筛选。
- `pages.file_type` 已保存 `html`、`pdf`、`word`。
- `rowToPage()` 已返回 `title`、`fileName`、`fileType`、`directoryName`、`size`、`uploadTime`、`accessCount`、`url`。
- 公开文档访问已通过 `GET /:slug` 和 `GET /pages/:slug.html` 支持。
- 后台路径已可自定义，公开首页不会泄露后台路径。

需要新增的是“公开视图模型”和前台页面资源。

## 路由设计

推荐路由：

```text
GET /                     公开文档首页，全部类型
GET /type/html            公开 HTML 列表
GET /type/pdf             公开 PDF 列表
GET /type/word            公开 Word 列表
GET /public/api/pages     公开列表数据
GET /:slug                单个文档公开阅读或 HTML 预览，保持现状
```

后台仍保持：

```text
GET /<adminPath>
GET /<adminPath>/api/*
```

路由冲突规则：

- `/:slug` 只匹配 6 位短码文档，现有规则保留。
- `/type/:fileType` 不会与 6 位短码冲突。
- `/public/api/pages` 为公开 API，不走后台登录态。
- 自定义后台路径如果设置成 `/type` 或 `/public` 应禁止，避免和公开路由冲突。

## 公开 API 设计

### GET `/public/api/pages`

查询参数：

```text
type=all|html|pdf|word
q=<keyword>
page=1
pageSize=20
sort=updated_desc|created_desc|access_desc
```

默认值：

```text
type=all
page=1
pageSize=20
sort=updated_desc
```

响应字段只返回公开字段：

```json
{
  "pages": [
    {
      "slug": "f812c6",
      "title": "GEO 诊断报告",
      "fileName": "geo-report.html",
      "fileType": "html",
      "directoryName": "reports",
      "size": 78848,
      "uploadTime": "06/08 10:21",
      "updatedTime": "06/08 10:30",
      "accessCount": 12,
      "url": "/f812c6"
    }
  ],
  "stats": {
    "all": 18,
    "html": 12,
    "pdf": 4,
    "word": 2
  },
  "pagination": {
    "page": 1,
    "pageSize": 20,
    "total": 18,
    "totalPages": 1,
    "hasPrev": false,
    "hasNext": false
  }
}
```

不返回字段：

- `id`
- `sourcePath`
- `generatedPath`
- `deletedPath`
- `checksum`
- `revision`
- `canEdit`
- `editUrl`
- `canSync`

原因：前台是公开展示，不应该暴露后台管理和本地文件系统信息。

## 筛选与排序

类型筛选：

- 全部
- HTML
- PDF
- Word

搜索范围：

- 页面标题
- 文件名
- 目录名称
- 短码
- 本地 URL

排序第一版建议只做三个：

- 最近更新
- 最近上传
- 访问最多

原因：排序过多会让前台首页变成后台工具。第一版应以浏览和打开为主。

## UI 设计方向

参考成熟产品：

- Notion 公开页面索引：重点是标题、说明、最近更新时间，视觉安静，阅读负担低。
- Google Drive 列表视图：类型图标、名称、大小、时间一眼可扫，适合多格式文档。
- arXiv 列表页：条目密集但清晰，类型和打开动作不抢内容。

视觉方向：

- 白底、浅米色页面底、细边框、8px 以下圆角，延续 TokDoc 当前 Kami 风格。
- 顶部不是营销 hero，而是紧凑的文档索引头部。
- 第一屏要直接看到文档列表，不做大面积宣传文案。
- 宽度建议 `min(1180px, calc(100vw - 48px))`，移动端改为单列卡片。

页面结构：

```text
┌──────────────────────────────────────────────┐
│ TokDoc 文档索引                搜索框         │
│ 已发布文档 18 个 · HTML 12 · PDF 4 · Word 2  │
├──────────────────────────────────────────────┤
│ [全部] [HTML] [PDF] [Word]   排序：最近更新  │
├──────────────────────────────────────────────┤
│ 类型  标题/文件名              目录   时间   │
│ HTML  GEO 诊断报告             reports 06/08 │
│ PDF   合同附件                 clients 06/07 │
│ Word  招生简章                 school  06/05 │
├──────────────────────────────────────────────┤
│ 上一页              第 1 / 3 页        下一页 │
└──────────────────────────────────────────────┘
```

桌面端列表字段：

```text
类型
标题
目录
上传时间
大小
访问次数
打开
```

移动端卡片字段：

```text
类型徽标 + 标题
文件名
目录 · 大小 · 上传时间
访问次数
打开按钮
```

交互细节：

- 点击整行打开文档。
- “打开”按钮使用外链或眼睛图标。
- 类型筛选使用 segmented control，和后台列表保持一致但更轻。
- 搜索框防抖 250ms 请求。
- 空状态显示“暂无公开文档”，只给后台入口提示时必须隐藏真实后台路径，建议文案为“请先在后台上传文档”。

## 安全与隐私边界

公开列表页会让所有非回收站文档可被集中看到。这个行为符合用户需求，但需要在设置里给管理员明确控制。

建议增加一个设置项：

```text
公开首页：开启 / 关闭
```

默认值建议：开启。

理由：

- 用户明确希望访问首页就能看到所有上传信息。
- 现有公开短链接本来不需要登录。
- 本地或自托管使用场景下，公开首页更符合“文档门户”的产品定位。

如果以后部署到公网且需要更强隐私，可以再加：

- 是否隐藏文件名
- 是否隐藏目录名
- 是否隐藏访问次数
- 是否需要访客密码

第一版不做这些扩展。

## 后端实现计划

### 1. 数据层

在 `PageStore.listPages(filters)` 增加 `fileType` 或 `type` 过滤：

```text
type=html|pdf|word
```

增加公开列表方法：

```text
listPublicPagesPage(filters)
```

它应复用 `listPagesPage`，但只返回非回收站、公开字段和类型统计。

### 2. 路由层

新增路由：

```text
GET /
GET /type/:fileType
GET /public/api/pages
```

调整当前 `/` 行为：

- 现在默认 `/` 跳转 `/admin`。
- 升级后 `/` 返回公开列表页。
- 后台入口仍通过 `/<adminPath>` 进入。

`requestAccessState` 需要把 `/`、`/type/*`、`/public/api/pages` 标记为 public。

### 3. 前端资源

新增静态文件：

```text
public/index-public.html
public/public-app.js
```

不建议把前台列表和后台管理塞进同一个 `public/app.js`，原因是：

- 后台 JS 依赖登录、设置、上传、删除、编辑等管理行为。
- 前台页面只需要列表、筛选、搜索、分页和打开。
- 分开后可以避免公开页面加载管理代码。

### 4. 设置中心

后台设置中心增加：

```text
公开首页
开启后，访问 / 会展示所有已发布文档列表。
关闭后，/ 返回 404 或简短空页面，短链接仍可访问。
```

第一版如果为了更快上线，也可以不做关闭开关，但我建议做。因为它能避免公网部署时误公开集中索引。

## 测试计划

新增或更新测试：

- `GET /` 返回公开列表页，不需要登录。
- `GET /public/api/pages` 不需要登录，只返回公开字段。
- 公开 API 不返回 `sourcePath`、`generatedPath`、`editUrl`、`revision`。
- `type=html` 只返回 HTML，`type=pdf` 只返回 PDF，`type=word` 只返回 Word。
- `/type/html` 返回公开列表页。
- 回收站文档不出现在公开列表里。
- 自定义后台路径仍可访问，公开首页不泄露后台路径。
- `/abcdef` 短链接继续优先打开文档。
- `npm test` 全量通过。

视觉验收：

- 桌面端 1440px：第一屏可看到列表前几条。
- 移动端 375px：卡片不溢出，类型筛选可横向滚动或换行。
- 搜索框、筛选、分页按钮在中文长标题下不挤压错位。

## 实施影响范围

预计修改文件：

- `tools/TokDoc/src/routes.js`
- `tools/TokDoc/src/page-store.js`
- `tools/TokDoc/src/admin-path.js`
- `tools/TokDoc/public/index-public.html`
- `tools/TokDoc/public/public-app.js`
- `tools/TokDoc/public/index.html`
- `tools/TokDoc/public/app.js`
- `tools/TokDoc/test/auth.test.js`
- `tools/TokDoc/test/page-store.test.js`
- `tools/TokDoc/test/home-layout.test.js`
- `tools/TokDoc/README.md`

这是 10 个以上文件，属于中等规模前后端联动升级。建议按一次完整迭代实现，但测试要先覆盖路由和公开字段，再做 UI。

## 回滚策略

这个方案不改数据库结构，不删除数据，不改变单文档短链接，因此回滚成本低。

如果上线后不想保留公开首页：

- 可以把 `/` 恢复为后台跳转。
- 保留 `public/index-public.html` 和 `public/public-app.js` 不被引用即可。
- `PageStore` 的 `type` 过滤可以保留，不影响后台。

## 推荐执行顺序

1. 先补后端红灯测试：公开 API、类型筛选、公开字段过滤。
2. 实现 `PageStore` 类型过滤和公开列表方法。
3. 实现 `/`、`/type/:fileType`、`/public/api/pages` 路由。
4. 新增 `index-public.html` 和 `public-app.js`。
5. 增加后台“前台首页”入口和公开首页设置开关。
6. 更新 README。
7. 跑 `npm test`、`node --check`、`git diff --check`、`npm audit --audit-level=high`。
8. 启动本地服务，用 Playwright 验证桌面和移动端公开首页。
9. Review diff，发现问题后修复。

## 执行前确认点

建议确认这三个点后开始实现：

1. `/` 是否直接作为公开列表首页。
2. 公开首页是否默认开启。
3. 前台列表是否只展示公开字段，不展示后台编辑入口。

我的建议是：三项都按上面的默认方案执行。
