# 独立 QQ 双向通道

Enhancement 1.5.0 起支持使用独立常驻服务收发 QQ 消息。服务直接使用腾讯 `@tencent-connect/qqbot-nodejs` SDK，通过 WebSocket 连接 QQ 官方机器人；无需部署 OpenClaw、Hermes 或大模型。

服务源码与完整部署说明：[dockerfile/qq-channel](https://github.com/jkjoy/dockerfile/tree/main/qq-channel)。

## 部署

在 QQ 开放平台准备 AppID / AppSecret、私聊与群聊消息权限及主动发送权限，按控制台要求完成测试范围、发布、出口 IP、消息域名等配置。将机器人添加为好友或加入目标群。

在服务器的 `qq-channel` 目录：

```bash
cp .env.example .env
chmod 600 .env
openssl rand -hex 32
```

将 AppID / AppSecret 和生成的随机值填入 `.env` 的 `QQ_APP_ID`、`QQ_APP_SECRET`、`BOT_API_TOKEN`，然后启动：

```bash
docker compose up -d --build
curl http://127.0.0.1:8080/readyz
```

服务使用出站 WebSocket 接收 QQ 事件，无需公网事件回调地址。插件与服务之间仍需 HTTP/HTTPS 可达；默认端口仅绑定宿主机回环地址。PHP 在容器中时，将两者放在同一 Docker 网络，通过 `http://qq-channel:8080` 访问。

## 绑定和通知

使用 `BOT_API_TOKEN` 调用服务的 `POST /api/bindings`：

```json
{"name":"owner","type":"user"}
```

将返回的完整 `command` 私聊发送给机器人，10 分钟内一次有效。群绑定改为 `{"name":"admins","type":"group"}`，在目标群 @机器人发送口令。返回的 OpenID 可用于后续管理授权。完整 curl 示例见服务 README。

在插件 QQ 设置中选择 **独立 QQ 通道（无需智能体，推荐）**，填写服务根地址、`BOT_API_TOKEN` 和每行一个的接收别名（如 `owner`、`admins`），按需开启评论或友链申请通知。AppSecret 只保存在服务端。保存后点击“发送QQ通知测试”，取得通知 ID 后可在服务 `GET /api/notifications/ID` 查看最终状态。

主动消息受 QQ 平台权限、额度及接收方设置限制。未报备消息域名时选择省略链接。此服务不使用旧 go-cqhttp / OneBot。

## 开启固定管理指令

1. 向机器人发送 `我的ID`；群内需 @机器人，得到用户和群 OpenID。
2. 服务 `.env` 填入 `ENHANCEMENT_COMMAND_URL`（从插件 QQ 设置复制完整“QQ 指令接口”）、另一个随机的 `ENHANCEMENT_COMMAND_SECRET`、允许的用户 `QQ_ALLOWED_USERS` 和允许的群 `QQ_ALLOWED_GROUPS`。多个值以逗号分隔；群名单留空时只允许私聊管理。
3. 插件中开启“允许 QQ 侧管理博客”，填写同一指令密钥、每行一个的用户 OpenID 和 Typecho 管理员 UID。指令密钥必须与通知服务令牌不同。
4. 执行 `docker compose up -d --force-recreate`，发送 `博客状态` 验证。

独立模式无需填写 Agent 消息接口、Agent 通知凭据和 Agent 接收目标。只需通知时将服务指令环境变量留空，并保持插件管理开关关闭。

固定指令包括：`帮助`、`我的ID`、`博客状态`、`待审评论 5`、`已审评论 5`、`垃圾评论 5`、`通过评论 123`、`暂缓评论 123`、`垃圾标记 123`、`回复评论 123：谢谢留言`、`待审友链 5`、`通过友链 12`、`驳回友链 12`。查询数量可省略，范围为 1–20。评论回复须先审核父评论，以指定管理员身份发布；驳回友链会隐藏记录。

服务只接受官方事件中的身份，管理操作同时校验服务和插件白名单；群操作还需群白名单。不执行自由文本任务，不调用模型。插件继续验证 HMAC、时间戳、参数、管理员权限和危险内容，按官方消息 ID 去重。QQ 回复失败时重试已保存的结果，不会再次执行已确认的博客操作；结果不确定时到后台核对。

服务持久卷保存通知和指令状态，完成记录保留 30 天。每个目标最多自动投递 5 次，失败后可通过服务 API 手动重试；已成功的目标不重发。备份与升级方法见服务 README。

与复用智能体的差异：OpenClaw/Hermes 方案依赖已有 Agent 网关及其 QQ 通道；本方案只运行独立服务，使用固定指令完成博客管理。
