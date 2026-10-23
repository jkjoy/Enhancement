# QQ / 微信官方通道离线验证

在插件目录运行：

```console
php tests/notify-channels/run.php
```

测试使用内存状态和模拟 HTTP 响应，默认禁止网络，不读取生产配置或数据库，不向 QQ/微信发送消息。需要 PHP sodium 以验证 QQ 官方签名与绑定流程。

覆盖 QQ 官方凭证缓存、401 刷新、私聊/群聊/频道消息、Webhook 验签、官方 challenge、一次性绑定及错误响应；微信扫码、验证码、跨区跳转、扫码人绑定、会话同步、发送受理、登录失效和通知触发隔离。

Typecho 实际表单、密钥保存、数据库状态、管理员权限和旧服务队列另运行 `php tests/qq-notify/run.php`；独立 QQ 通道的管理指令配置运行 `php tests/qq-commands/run.php`。
