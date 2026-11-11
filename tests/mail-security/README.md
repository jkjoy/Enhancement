# 邮件与模板安全检查

```sh
php tests/mail-security/run.php /path/to/typecho
php tests/mail-security/smtp.php
```

使用 PHP 8+、PDO SQLite 和指定 Typecho 源码。测试复制插件到临时站点，创建独立数据库和本地 HTTP 服务，不加载真实站点配置，不修改仓库中的邮件模板。

覆盖管理员登录、预览权限、目录穿越和符号链接拒绝、预览 CSP、编辑器 POST 与 CSRF、模板写入边界、模板启用时的 CSRF/路径校验和其他设置保留、评论邮件的元数据转义和 HTML 净化、SMTP 新旧配置键与真实表单绑定，以及真实邮件 action 的请求方法、空/错误/数组密钥和功能开关。模板启用检查从没有 `act=index` 的真实菜单页读取 token，使用该页作为 Referer 提交实际表单地址。SMTP socket 函数由隔离服务禁用并替换；任何意外连接均会记录并中止，检查不会发送邮件。

通过时清理临时数据；失败时保留 fixture 和 server.log，并输出路径。

`smtp.php` 使用独立的框架替身和仅监听 `127.0.0.1` 的 SMTP 假服务，真实验证 SMTP 测试、友链申请、友链审核和评论邮件四条发送路径，以及密码空白、新旧主机字段、发件人、拒收与日志。假服务只记录测试邮件，不转发或实际投递；测试结束后清理临时代码和日志。
