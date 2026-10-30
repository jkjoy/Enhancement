# 微信通知通道离线验证

在插件目录运行：

```console
php tests/notify-channels/run.php
```

测试使用内存状态和模拟 HTTP 响应，默认禁止网络，不读取生产配置或数据库，不向微信发送消息。

覆盖微信扫码、验证码、跨区跳转、扫码人绑定、会话同步、发送受理、登录失效和通知触发隔离。

Typecho 实际表单、密钥保存、数据库状态、管理员权限和官方 QQ 通知队列另运行 `php tests/qq-notify/run.php`。
