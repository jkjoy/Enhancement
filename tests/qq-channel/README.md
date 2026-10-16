# 独立 QQ 通道联调

需要 PHP（cURL、PDO SQLite）、Node.js 24+、Typecho 源码和 `dockerfile/qq-channel` 检出；先在服务目录执行 `npm ci --ignore-scripts`。

PowerShell，在插件目录运行：

```powershell
$env:ENHANCEMENT_QQ_CHANNEL_DIR='E:\OneDrive\桌面\都是github\dockerfile\qq-channel'
php tests/qq-channel/run.php
```

Linux 示例：

```bash
ENHANCEMENT_QQ_CHANNEL_DIR=/path/to/dockerfile/qq-channel php tests/qq-channel/run.php /path/to/typecho
```

测试创建临时 SQLite 和私有 PHP/Node HTTP 服务，通过真实插件配置、通知队列、腾讯 SDK 事件标准化、SDK 发消息及签名回调验证私聊和群聊通知、评论审核/回复、友链审核、重复事件、管理开关和模式切换。QQ API 使用本地模拟服务，不发送真实消息，不加载生产 Typecho 配置和数据库。成功后清理临时文件，失败保留日志。

腾讯 SDK WebSocket 握手及队列恢复等服务测试在 `qq-channel` 执行 `npm test`。通知与旧配置迁移测试执行 `php tests/qq-notify/run.php`，签名管理指令测试执行 `php tests/qq-commands/run.php`。
