# Contributing to Tokpoll

感谢你的关注。Tokpoll 是一个无框架、无构建步骤的 PHP + SQLite 小工具，提交改动时请保持部署简单、依赖克制。

## 本地验证

```bash
php tools/seed.php --reset
bash tools/serve.sh
bash tools/selftest.sh http://127.0.0.1:端口
```

也请确认所有 PHP 文件通过 `php -l`，`public/assets/app.js` 通过 `node --check`，Shell 脚本通过 `bash -n`。

## 提交规范

- 不要提交 `config.php`、SQLite 数据库、日志或本地测试产物。
- 用户输入需要经过校验，HTML、JSON 和 CSV 输出分别采用对应的安全转义。
- 涉及投票、提问、后台写操作的改动需要补充可复现的验证步骤。
- 面向用户的文案优先使用清晰、直接的中文表达。
