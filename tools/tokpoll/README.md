# Tokpoll

Tokpoll 是一个面向现场互动的轻量问题征集与投票工具。参与者打开链接即可投票，也可以补充自己关心的问题；管理员可以维护每期主题、候选问题、标签、审核状态和历史结果。

它适合公开课、团队讨论、活动 Q&A 和小型调研。项目使用 PHP 8、SQLite 和原生前端，无需 Node.js、数据库服务或构建流程。

当前目录位于 [`yao-open-tools/tools/tokpoll`](../../README.md)。许可证遵循仓库根目录的 [MIT License](../../LICENSE)。

- PHP 8.0+ / SQLite，**无需登录、无需数据库服务、无需构建工具**
- H5 自适应，移动端优先，自动适配深色模式
- 默认每设备每天 1 票（可改投）+ 1 次提问，期次可以调整投票额度
- 默认提交后直接显示：用户提交的问题会进入列表，管理员可以隐藏不合适的内容

---

## 一分钟跑起来

```bash
php tools/seed.php        # 可选：写入演示数据
bash tools/serve.sh       # 自动挑一个空闲端口启动，并打印地址
```

`serve.sh` 会自己生成 `config.php`、从 7317 开始寻找可用端口，然后打印前台和后台地址。
首次部署需要先在 `config.php` 里填入管理密码哈希：

```bash
php -r 'echo password_hash("你的新密码", PASSWORD_DEFAULT), PHP_EOL;'
```

把输出填到 `admin_password_hash` 后再登录后台。
想固定端口就直接用 PHP 内置服务器：

```bash
php -S 127.0.0.1:7317 -t public
```

后台在 `/admin.php`，管理密码由 `config.php` 的 `admin_password_hash` 设置。
端口被占会看到别的程序返回的内容（比如一段 JSON 报错），这时请换一个端口。
`lsof -nP -iTCP:7317 -sTCP:LISTEN` 可以看是谁占着。

---

## 部署到宝塔 / 虚拟主机

1. 新建站点，PHP 版本选 **8.0 及以上**，需要开启 `pdo_sqlite` 扩展（默认都带）。
2. 把整个 `tokpoll/` 上传到站点目录。
3. **网站设置 → 网站目录 → 运行目录**，选择 `/public`。这一步最关键：
   源码、配置和数据库都在 `public/` 之外，外网碰不到。
4. 给 `data/` 目录写权限（755，属主设为 `www`）：
   ```bash
   chown -R www:www data/ && chmod 755 data/
   ```
5. 复制配置并改密码：
   ```bash
   cp config.php.example config.php
   php -r 'echo password_hash("你的新密码", PASSWORD_DEFAULT), PHP_EOL;'
   # 把输出粘贴到 config.php 的 admin_password_hash
   ```
6. 访问首页，数据库会自动创建建表，不需要手动导入 SQL。

想要 `/history`、`/s/3` 这样的干净链接，把 `config.php` 里的 `pretty_url` 改成 `true`，
再按 `nginx.conf.example` 配一下伪静态（Apache 用户什么都不用做，`public/.htaccess` 已经写好）。
不改也完全能用，默认是 `?p=history` 这种带参数的链接。

---

## 目录结构

```
tokpoll/
├── public/              ← 网站根目录指向这里
│   ├── index.php        前台入口（首页 / 历史 / 往期详情）
│   ├── api.php          前台 JSON 接口（投票 / 提问 / 拉列表）
│   ├── admin.php        后台入口
│   └── assets/          app.css / app.js / admin.css / favicon.svg
├── app/
│   ├── bootstrap.php    配置加载、时区、错误处理、Session
│   ├── Db.php           PDO 连接 + 自动迁移
│   ├── migrations.php   建表语句（按版本号递增）
│   ├── Voter.php        访客识别与每日配额
│   ├── Survey.php       期次 / 问题 / 投票 / 标签的业务逻辑
│   ├── Admin.php        后台鉴权
│   ├── state.php        前端状态组装
│   ├── helpers.php      转义、CSRF、限流、URL
│   └── views/           页面模板（含 admin/）
├── data/                SQLite 数据库，需可写、禁止 Web 访问
├── tools/
│   ├── seed.php         演示数据
│   ├── serve.sh         本地启动（自动避开被占用的端口）
│   └── selftest.sh      自测脚本
├── config.php.example   配置模板
└── nginx.conf.example   Nginx / 宝塔伪静态示例
```

---

## 日常怎么用

1. 后台 → **期次** → 新建一期，填主题、副标题、截止时间，状态选「进行中」。
   同一时间只会有一期在跑，设为进行中时上一期会自动结束。
2. **问题** → 添加几个候选问题（有候选，用户进来就能直接投，参与率高很多）。
3. 把首页链接发到群里。大家投票、补充问题，列表每 8 秒自动刷新。
4. 出现不合适的问题 → 点「隐藏」。前台立刻消失，票数留着可查；
   「彻底删除」才会真的清数据。重复提问用「合并」，票数会并到目标问题上。
5. 开场前 → **数据** → 看排行、导出 CSV。
6. 期次截止时间一到会自动进入「已结束」，出现在前台右上角的历史调研里。

---

## 关于「每人每天一次」

无登录场景下没有绝对可靠的身份，这里用三层判据：

| 层 | 依据 | 默认强度 |
|---|---|---|
| 1 | `tp_uid` Cookie（服务端签发，1 年有效） | **硬限制** |
| 2 | IP + User-Agent 指纹 | 软限制（仅记录） |
| 3 | 同 IP 每分钟写操作次数上限 | 防脚本刷 |

⚠️ **同一间教室或办公室里，大家常连同一个 WiFi，出口 IP 相同。** 第 2 层默认不拦人，
这样同一网络中的不同设备都能参与。如果是线上分散场景、想加强防刷，把 `config.php` 里的
`strict_ip` 改成 `true`，同一 IP 每天就只能投一次了。

清掉浏览器 Cookie 确实可以再投一次。这是这类「无登录、零门槛」工具的固有取舍；
在熟人范围内的调研场景里，降低参与门槛比严防死守更重要。真要杜绝，只能上登录。

---

## 安全与数据边界

- 全部 SQL 走 PDO 预处理，所有输出经 `htmlspecialchars` 转义
- 前后台写操作一律校验 CSRF token
- 后台密码用 `password_hash` 存储，同一 IP 连续失败 5 次锁 10 分钟
- 提问做长度、纯链接、同期重复三重过滤
- `app/`、`data/`、`config.php` 都在网站根目录之外；
  万一运行目录配错了，根目录的 `.htaccess` 还会再挡一层

可选：在 `config.php` 里给 `admin_path_token` 填一个随机串（如 `x7k2`），
后台地址就变成 `/admin.php?k=x7k2`，不带口令直接返回 404，能挡掉大部分扫描器。

直连部署保持 `trust_proxy = false`。站点确认运行在可信反向代理之后时，才把它改成 `true`。

---

## 自测

```bash
bash tools/serve.sh &            # 或 php -S 127.0.0.1:7317 -t public &
bash tools/selftest.sh           # 换了端口就加上：bash tools/selftest.sh http://127.0.0.1:端口
```

覆盖 32 项：页面可访问性、后台越权拦截、CSRF、一人一天一票与改投、
多设备互不阻塞、提问长度与配额、XSS 转义、重复问题识别、后台登录锁定、
隐藏后前台同步、CSV 导出。

回归测试还覆盖了期次从“尚未开始”进入“进行中”时的前台状态刷新。

---

## 备份与迁移

把 `data/` 目录整个复制走即可（包含 `tokpoll.sqlite` 以及可能存在的 `-wal`、`-shm` 文件）。
换服务器时连同源码一起拷过去，改好 `config.php` 就能继续跑。

开源发布时不要提交 `config.php` 和 `data/*.sqlite*`。仓库中的配置模板不包含可直接登录的默认密码，部署前需要自行生成密码哈希。

---

## 常见问题

**数据库目录不可写 / 500 错误**
给 `data/` 加写权限：`chmod 755 data && chown -R www:www data`。

**改了配置没生效**
确认改的是 `config.php` 而不是 `config.php.example`。

**忘了后台密码**
重新生成哈希填回 `config.php` 即可，不影响已有数据。

**想加字段**
在 `app/migrations.php` 里追加一个更大的版本号，里面写 `ALTER TABLE`，
下次访问自动升级，不会动已有数据。
