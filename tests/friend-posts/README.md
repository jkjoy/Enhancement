# 朋友文章检查

```sh
php tests/friend-posts/feed.php
php tests/friend-posts/notifications.php
php tests/friend-posts/cache.php /path/to/typecho
php tests/friend-posts/http.php /path/to/typecho
php tests/friend-posts/worker.php /path/to/typecho
```

- `feed.php`：离线验证 RSS 2.0、Atom、RDF、相对地址、时间排序、去重、XML 和 URL 安全边界。
- `notifications.php`：隔离通知状态与发送通道，验证首次基线、持久链接去重、双通道独立开关、身份变更、合并消息与失败重试；不发送真实 QQ / 微信消息。
- `cache.php`：使用 Typecho 的真实 SQLite 查询构造器和假订阅传输，验证缓存轮换、失败保留、审核/删除/排除过滤、分页、自定义订阅及刷新锁。
- `http.php`：使用临时 Typecho 根目录、独立 SQLite 数据库和离线传输，验证后台刷新接口的登录、权限、CSRF、POST 限制和响应。
- `worker.php`：复制命令行任务到临时站点，用真实 Typecho 初始化和独立 SQLite 验证参数、配置加载、插件与功能开关、QQ / 微信通知开关、最多 100 个到期订阅的调用、关闭瞬间发布时仍可运行，以及异常和启动输出不泄露配置；本地 HTTP 检查确认网页访问返回 404。订阅刷新替身不会访问外网。

这些集成检查只读取指定目录中的 Typecho 程序文件，不加载该站的 `config.inc.php`，不读写实际站点数据库。任务测试仅加载临时站点生成的伪配置。测试数据和缓存位于临时目录，通过后清理。
