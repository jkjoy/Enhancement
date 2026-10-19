# 朋友文章检查

```sh
php tests/friend-posts/feed.php
php tests/friend-posts/cache.php /path/to/typecho
php tests/friend-posts/http.php /path/to/typecho
```

- `feed.php`：离线验证 RSS 2.0、Atom、RDF、相对地址、时间排序、去重、XML 和 URL 安全边界。
- `cache.php`：使用 Typecho 的真实 SQLite 查询构造器和假订阅传输，验证缓存轮换、失败保留、审核/删除/排除过滤、分页、自定义订阅及刷新锁。
- `http.php`：使用临时 Typecho 根目录、独立 SQLite 数据库和离线传输，验证后台刷新接口的登录、权限、CSRF、POST 限制和响应。

后两项只读取指定目录中的 Typecho 程序文件，不加载该站的 `config.inc.php`，不读写实际站点数据库。测试数据和缓存位于临时目录，运行后清理。
