# Enhancement 插件

融合怪插件 —— 友链增强 + 朋友文章 + 瞬间 + REST API + AI摘要 + 标签助手 + 站点地图 + 评论增强 + 外链跳转 + 视频链接解析 + QQ/微信通知 + 邮件提醒 + 主题插件在线上传/删除等多功能于一体的 Typecho 插件。

### 简介

基于 Links 插件改造，新增游客提交、管理员审核功能。
融合标签助手/评论同步/站点地图等实用功能。

### 功能
1. 使用 `typecho_links` 数据表存储友链信息
2. 输出方式：模板函数调用 + 文章内标签解析
3. 三种输出模式：SHOW_TEXT / SHOW_IMG / SHOW_MIX，可在插件配置中自定义规则
4. 管理面板：分类、拖拽排序、审核通过/驳回
5. 游客提交：前台提交后默认待审核，仅通过审核的记录会输出
6. 瞬间功能：新增 `typecho_moments` 表并提供 JSON API
7. 评论同步：游客/登录用户评论时自动同步历史评论中的网址/昵称/邮箱
8. 标签助手：后台写文章时显示标签快捷选择列表
9. 站点地图：提供 `/sitemap.xml`
10. QQ/微信通知：支持官方 QQ Bot 直连、微信扫码登录，以及独立 QQ 双向通道和官方 Webhook Bot 服务
11. 评论邮件提醒：支持 SMTP 推送，支持模板管理
12. 可选`Gravatar`头像镜像加速
13. 可选视频链接解析：自动将 YouTube / Bilibili / 优酷链接替换为播放器
14. 可选音乐链接解析：自动将网易云音乐 / QQ音乐 / 酷狗音乐链接替换为 APlayer 播放器
15. 禁用插件时可选择删除友情链接表/说说表/QQ通知队列表
16. 可选外链新窗口打开：自动为 a 标签补充 target 与安全 rel
17. 外链跳转提醒：文章/评论内外链及评论者网站地址统一走 `/go/<base64>` 中转提醒页
18. 可选 Turnstile 人机验证：统一保护评论与友情链接提交
19. 插件设置支持一键备份与一键恢复（数据库快照）
20. 支持在线上传安装 ZIP 插件/主题，支持删除未启用插件和未启用主题
21. AI 摘要：发布文章时自动生成摘要并写入自定义字段（默认 `summary`）
22. AI 摘要后台批量生成：支持“仅空字段生成 / 强制覆盖重生成”
23. 摘要管理页支持状态筛选与分页（公开/私密/待审核/隐藏）
24. 评论表情面板：评论框支持展开表情列表，点击自动插入短代码并渲染为图片
25. 瞬间定位：后台一键获取经纬度并调用腾讯地图逆地址解析后入库缓存，避免重复查询
26. 瞬间状态：支持公开/私密，默认 API 仅返回公开数据，携带有效 Token 可返回全部
27. 瞬间定位地址解析改为浏览器端 JS 直连腾讯地图 API（无需服务端转发）
28. AI 生成slug
29. 友情链接申请支持独立开启 QQ、微信与邮件通知
30. 可选 REST API：集成 Typecho-RestAPI 的 WordPress `wp/v2` 兼容接口，支持公开内容读取与评论提交，默认关闭
31. 可选评论防御：XSS 特征、HTML、中文、关键词、IP/IP 段黑名单、同网段限频及重复评论拦截
32. 朋友文章：自动发现已审核友链的 RSS / Atom 订阅，缓存并汇总最近文章，提供独立页面 API 和后台逐站刷新
33. 机器人发布瞬间：官方 QQ 直连和微信均可通过文字命令发布公开或私密瞬间，支持多行正文与 Markdown 图片 URL，默认关闭

### 安装
1. 上传到 `usr/plugins/Enhancement`
2. 后台启用插件

### 后台管理

- 管理 → 链接 / 朋友文章 / 瞬间 / 摘要

- 控制台 → 评论邮件提醒外观（模板列表/编辑）

- 控制台 → 上传（在线上传 ZIP 插件/主题，删除未启用插件/主题）

### 可开关设置
在插件设置页可控制：
- 评论同步
- 评论表情（评论框表情面板 + 评论短代码解析）
- 标签助手
- Sitemap
- 朋友文章（默认开启，可设置缓存有效期、每位朋友的文章数、自定义订阅及排除站点）
- REST API（默认关闭，含独立的网站标题、描述、头部 HTML、页脚文本及备案号设置）
- 视频链接解析
- 音乐链接解析（APlayer + Meting）
- 附件预览增强（后台附件预览与批量插入，默认关闭）
- AI 摘要（发布文章自动生成）
- AI API 地址（支持 OpenAI 兼容接口）
- AI API Token
- AI 模型
- AI 摘要 Prompt
- AI 摘要存储字段名（默认 `summary`）
- AI 摘要更新策略（仅空字段生成 / 每次发布覆盖）
- AI 摘要最大长度
- AI 摘要送审内容最大长度
- Meting API 地址（默认本地接口 `action/enhancement-edit?do=meting-api&server=:server&type=:type&id=:id&r=:r`）
- 外链新窗口打开
- 外链跳转提醒（文章、评论、评论者网站链接统一中转）
- 外链 go 跳转开关（可整体启用/禁用）
- 外链跳转白名单（白名单域名不走 go）
- Turnstile 人机验证（评论/友链提交）
- 仅游客评论启用 Turnstile（登录用户评论可免验证）
- 评论防御（默认关闭，普通评论和 REST API 共用规则，含单独的内容/IP/限频开关）
- QQ 通知方式（官方直连 / 独立 QQ 通道 / 官方 Bot 服务），直连支持用户/群 OpenID 绑定及子频道 ID；独立 QQ 通道另有 QQ 侧管理开关
- 微信扫码登录、会话同步和接收人绑定，评论与友链申请可独立开启微信通知
- 通过 QQ / 微信发布瞬间（各自默认关闭；QQ 群须另配成员 OpenID 白名单，微信须运行接收器或在后台手动同步）
- 禁用插件时是否删除友情链接表（links，默认否）
- 禁用插件时是否删除说说表（moments，默认否）
- 禁用插件时是否删除 QQ 通知队列表（qq_notify_queue，默认否）
- 插件设置备份与恢复（配置页内一键写入数据库/一键恢复）
- 瞬间图片占位文本（当内容仅包含图片时使用）
- 腾讯地图 API Key（用于瞬间定位逆地址解析）
- QQ/微信评论通知
- 评论邮件提醒
- `Gravatar`头像镜像加速（可开关）

### 朋友文章（1.6.0）

自动从已审核的友情链接发现 RSS 2.0 / Atom 订阅，将最近文章按发布时间合并排序。默认启用，每位朋友保留 5 篇，缓存有效期 60 分钟。插件设置的「朋友文章」分组可调整有效期（5–1440 分钟）和每位朋友的文章数（1–20 篇）。设置保存后立即生效；升级后打开一次插件设置即可注册「管理 → 朋友文章」面板，无需停用或重新启用插件。

访问朋友文章页面时，每次轮换更新一个到期站点，每站抓取最多 8 秒。首次使用时可到「管理 → 朋友文章」点击「依次刷新全部站点」预热缓存，也可单独刷新某个站点。全部刷新按站点顺序执行，某站失败会继续处理其他站点；管理页显示缓存条数、最近检查时间和失败原因。抓取失败保留旧文章，未审核、已删除或被排除的友链会立即从输出中移除。关闭朋友文章功能后停止抓取与前台输出。

缓存保存在服务器临时目录，需保证 PHP 可写；无需建表或修改友链表。临时缓存被系统清理后会在后续访问或后台刷新时重建。此功能通过页面访问和手动刷新更新，无需配置定时任务。

通常无需填写订阅地址；站点未公开订阅发现信息时，可在「自定义订阅地址 / 排除站点」中覆盖，一行一条，左侧填写友链中保存的站点地址：

```text
https://friend.example/|https://friend.example/feed/
https://another.example/|https://another.example/atom.xml
https://excluded.example/|-
```

最后一行排除该站，空行忽略。仅审核通过的友链参与获取，填写自定义地址不会额外创建友链。

Nebula 主题已提供 `page-friends.php` 独立页面模板。在后台新建独立页面（例如「朋友文章」，缩略名 `friends`），选择「朋友文章」模板并发布即可。

其他主题可调用 `Enhancement_Plugin::friendPosts($pageSize = 20, $page = 1)`，返回 `enabled`、`items`、`total`、`page`、`pageSize`、`pages` 和 `updated`。每条文章包含 `title`、`url`、`published`（时间戳，未知时为 0）、`summary`、`author` 及 `friend`（`lid`、`name`、`url`、`image`）；文字字段为纯文本，输出时仍需 HTML 转义。例如：

```php
<?php $friends = Enhancement_Plugin::friendPosts(20, 1); ?>
<?php foreach ($friends['items'] as $article): ?>
    <article>
        <a href="<?php echo htmlspecialchars($article['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
            <?php echo htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <span><?php echo htmlspecialchars($article['friend']['name'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php if ($article['published'] > 0): ?>
            <time><?php echo date('Y-m-d', $article['published']); ?></time>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
```

### AI 摘要

启用后，发布文章时会自动调用 AI 生成摘要并写入自定义字段（默认 `summary`）。

- 自动生成入口：文章发布 `finishPublish` 钩子
- 批量生成入口：`管理 -> 摘要`
- 批量页支持：
  - 选中项批量生成
  - 强制重生成（覆盖已有摘要）
  - 状态筛选（全部/公开/私密/待审核/隐藏）
  - 分页浏览
- 字段写入规则：
  - 默认“仅字段为空时生成”
  - 可在设置改为“每次发布覆盖”

### 插件设置备份/恢复
- 进入插件配置页，在“设置备份”区域点击 `一键备份到数据库`，会把当前配置保存为数据库快照。
- 点击 `一键从数据库恢复` 会使用最近一次快照恢复，并覆盖当前插件配置。
- 支持在“最近 5 条备份”中选择指定快照进行恢复或删除。
- 插件默认保留最近 20 份备份快照，旧快照会自动清理。

### 前台提交
模板或页面中调用：

```php
<?php Enhancement_Plugin::publicForm()->render(); ?>
```

或自定义表单提交到 `/action/enhancement-submit`（需带安全 token，建议用 `Helper::security()->getIndex('/action/enhancement-submit')` 生成），字段：
`name`、`url`、`sort`、`email`、`image`、`description`、`user`

安全说明（已内置）：
- 可选 Turnstile 人机验证：启用后评论和友链提交都需要通过 Cloudflare 验证。
- 请求频率限制：同一 IP 在 5 分钟内最多提交 5 次，超限返回 429。
- 蜜罐字段拦截：表单隐藏 `homepage` 字段，若被填写将拒绝提交。
- URL 归一化去重：同站点链接会按规范化地址判重，避免重复入库。
- 链接协议限制：`url` / `image` 仅允许 `http://` 与 `https://`。

### 输出
模板中调用：

```php
<?php $this->enhancement('SHOW_TEXT', 0, null, 32); ?>
```

文章内容中标签：

```html
<links 0 sort 32>SHOW_TEXT</links>
```

说明：仅 `state = 1` 的记录会输出，主键字段为 `lid`。

1.5.5 评论表情选择器及表情样式改为在所有前台页面输出，兼容 Swup 等无刷新跳转主题：从其他页面切换进入文章或独立页面时，评论框上方的表情按钮也会正常显示。

1.5.4 重构后台瞬间管理页与插件设置页样式：瞬间列表改为卡片排版，支持公开/私密筛选、搜索与分页；设置页加宽，改为左侧分组导航、左右两栏设置项。

1.5.3 修复后台瞬间管理页因找不到建表脚本、未自动补建 `moments` 表而出现 HTTP 500（Database Query Error）的问题；读取失败时页面改为显示具体数据库错误。

### 瞬间 API
接口地址：
`/api/v1/memos`

可选参数：
`limit`（默认 20，最大 100）、`page`（默认 1）

访问范围说明：
- 默认（不带 Token）：仅返回 `status=public` 的瞬间
- 携带 `Authorization: Bearer <token>`：返回全部瞬间（含私密）

返回示例：
```json
[
  {
    "id": "1",
    "content": "<p>今天很棒</p>",
    "time": "2025-01-01 12:00",
    "tags": ["生活"],
    "media": [{"type":"PHOTO","url":""}],
    "status": "public",
    "latitude": "39.984104",
    "longitude": "116.307503"
  }
]
```

### 瞬间发布 API
在插件设置中填写 `瞬间 API Token`，然后使用：
`/api/v1/memos`

请求头：
`Authorization: Bearer <token>`

参数（JSON 或表单）：
`content`（必填，支持 Markdown）、`tags`（可选）、`media`（可选，若不填则从内容自动解析）、`created`（可选，时间戳或时间字符串）
`status`（可选，`public` 或 `private`）、`latitude` / `longitude`（可选，经纬度；也支持 `lat` / `lng`）

### QQ 官方直连与微信通知（1.7.0）

参考 SBlog 的 `plugins/comment-push` 实现官方 QQ Bot 和微信 iLink 通道，直接由 PHP 请求腾讯 HTTPS 接口。服务器需启用 PHP cURL；QQ 自动绑定还需 sodium，手动填写接收 ID 的发送功能不需要 sodium。已有独立 QQ 通道和官方 Bot 服务配置继续可用，升级不会自动切换通知方式。

QQ 配置步骤：

1. 在 [QQ 开放平台](https://q.qq.com/) 创建机器人并开通相应权限，在插件「QQ」分组选择「官方 QQ Bot 直连」，填写 AppID、AppSecret 和私聊/群聊/频道类型，先关闭通知并保存。
2. 已有用户/群 OpenID 或子频道 ID 时可手动填写；用户和群不能使用真实 QQ 号或群号代替。
3. 自动绑定：复制设置页显示的完整回调地址到开放平台的 Webhook 事件订阅。回调须通过公网 HTTPS 访问，私聊订阅 `C2C_MESSAGE_CREATE`，群聊订阅 `GROUP_AT_MESSAGE_CREATE`。插件使用 AppSecret 对原始请求做 Ed25519 验签和平台验证应答。
4. 点击「生成绑定码」，在目标私聊中发送完整的「绑定通知 …」命令；群聊请 @机器人后发送。绑定码有效 10 分钟且只能消费一次，修改凭证或接收类型后需重新生成。
5. 点击「检查绑定状态」确认接收 ID，启用所需的评论/友链通知并保存，再发送 QQ 测试。

AppSecret 不回显，留空保存保留原值。访问凭证按 AppID/密钥身份缓存，提前 60 秒续期；只有 HTTP 401 会刷新并重试一次。消息受 QQ 主动推送权限、频率、域名报备及平台审核限制，可关闭通知中的链接。频道推送需要已有程序保持机器人 WebSocket 在线。接口未返回消息 ID 或消息进入平台审核时，不会显示为已成功发送。

微信配置步骤：

1. 打开「微信」分组，点击「获取登录二维码」，使用微信扫码确认；如需验证码，在后台输入后点击「检查登录状态」。二维码在浏览器本地生成。
2. 向微信机器人发送一条消息，例如「绑定通知」，点击「同步微信接收人」。优先绑定扫码账号；如平台未提供扫码账号信息，从已同步候选人中选择并绑定。
3. 开启「微信评论通知」和/或「微信友情链接申请通知」，保存并点击「发送微信通知测试」。QQ 与微信可同时启用。
4. 会话上下文不可用时，再向机器人发送消息并同步；登录失效时重新扫码。退出微信登录会清除本地凭证、会话和绑定。

两个渠道沿用本插件行为：评论通过后通知，跳过文章作者本人；待审核评论通过后台审核时通知，垃圾和待审核状态不发送。友链申请在提交成功后通知。一个渠道失败不会影响另一个渠道，也不会撤销已提交的评论或友链。直连 QQ 和微信进行有超时限制的同步发送，不加入旧 QQ 服务队列、不对普通失败或超时自动重发；切换到直连时旧队列暂停投递。状态中的「接口已接受」表示服务端受理，请在接收端核对送达。

微信使用腾讯 iLink 协议，其主动发送依赖已建立的会话和平台规则。登录/同步/绑定/测试等操作要求管理员身份和 Typecho 安全令牌；QQ 公共回调使用官方签名认证。临时凭证、会话和最近一次发送结果独立存入数据库，不写入配置表单或设置备份。协议来源和二维码库许可见 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。

离线验证：`php tests/notify-channels/run.php` 覆盖 QQ/微信协议、签名及绑定，`php tests/qq-notify/run.php` 验证 Typecho 实际配置和原有队列。测试使用模拟接口与隔离数据库，不向真实 QQ/微信发送消息。

### 通过 QQ / 微信发布瞬间（1.8.0）

在插件设置中分别开启「通过 QQ 发布瞬间」或「通过微信发布瞬间」并保存。两个开关默认关闭，独立于评论和友链通知；只执行开关启用并保存后的新消息，不读取旧聊天。

发布命令：

| 命令 | 瞬间状态 |
| --- | --- |
| `瞬间 内容` | 公开 |
| `发瞬间 内容` | 公开 |
| `私密瞬间 内容` | 私密 |

命令后的正文可以包含换行和 Markdown 图片 URL。例如下面一条消息会发布包含文字和图片的公开瞬间：

```text
瞬间 今天的天空
![傍晚](https://example.com/evening.jpg)
```

图片 URL 使用已有瞬间内容解析流程；聊天中直接发送的图片附件不会自动上传或作为瞬间图片接收。

QQ 使用「官方 QQ Bot 直连」和上文配置的官方事件回调。私聊仅允许已绑定的接收人发送命令；群聊仅接受已绑定群内、同时列入「QQ 群瞬间发布者 OpenID」白名单的成员，须 @机器人后发送。白名单每行填写一个成员 OpenID，不能使用 QQ 号、群号或群 OpenID；留空不允许任何群成员发布。QQ 频道及原有独立 QQ / 官方 Bot 服务通道不支持此入口。

微信仅允许当前已登录并绑定的接收人发布。微信使用主动拉取消息的接收器，需要通过 PHP CLI 单独运行；在插件目录执行：

```sh
# 单次接收，可由计划任务定时运行
php BotMoments/worker.php --once

# 持续接收，运行期间保持该进程
php BotMoments/worker.php --loop
```

后台点击「同步微信接收人」也会处理收到的新命令；打开设置页或仅启用开关不会持续接收微信消息。接收器停止期间不会自动发布，恢复接收或手动同步后才会处理符合条件的新消息。重新登录或退出微信后，请先完成接收人绑定，再按需重新开启微信发布开关。

离线验证：`php tests/bot-moments/run.php` 使用隔离 Typecho / SQLite 数据库，验证发布权限、公开与私密状态、消息去重、历史消息过滤及接收器互斥；`php tests/bot-moments/worker.php` 验证命令行启动、关闭条件及持续接收配置刷新。测试不连接真实 QQ / 微信服务。

### 原有 QQ 双向通道与通知

**独立 QQ 通道支持通知推送与固定管理指令。** 配套服务位于 [dockerfile/qq-channel](https://github.com/jkjoy/dockerfile/tree/main/qq-channel)，使用腾讯 SDK 通过 WebSocket 接收 QQ 私聊及群聊消息，提供通知推送和固定的评论查询、审核、回复与友链审核指令。无需模型或公网事件回调地址，Docker 部署及插件配置见 [独立 QQ 通道说明](integrations/qq-channel/README.md)。

**1.5.2 移除「复用 OpenClaw QQ 通道」和「复用 Hermes QQ 通道」。** 升级后旧通道立即停止发送及接收管理指令，设置页显示「未配置 / 关闭通知」和关闭的管理开关；保存设置或恢复旧备份时会清理这两种通道的接口地址、通知凭据、接收目标等专用配置。旧待发记录保留为失败记录并注明停止投递原因，不能通过手动重试恢复。原用户需明确选择并配置下述受支持通道；已有独立 QQ 通道的通知、绑定和管理配置继续可用。

除上述官方直连外，「QQ」设置中的「通知方式」仍可选择独立 QQ 通道或官方 QQ Bot 服务（Webhook）；默认「未配置 / 关闭通知」，评论通过通知与友链申请通知使用各自开关。QQ 侧管理仅限独立 QQ 通道，具有独立开关，默认关闭。选择关闭通知时，本地待发记录也暂停投递。

从 1.4.1 起移除旧 go-cqhttp / OneBot 推送接口、接收 QQ 号和旧异步发送开关。原用户升级后需重新选择并配置上述通道；保存设置或恢复旧备份时会清理废弃配置。旧接口的待发队列保留为失败记录并注明停止投递原因，手动重试仅处理独立 QQ / 官方 Bot 服务记录。

1.4.2 修复旧配置残留导致插件设置页 HTTP 500 的问题。已升级到 1.4.1 且无法打开设置页时，下载 1.4.2 或更新版本，将解压后的插件文件覆盖到 `usr/plugins/Enhancement` 后重新访问设置页即可；配置和内容保存在数据库中。兼容处理接收 Typecho 对历史字段的绑定，旧 QQ 设置不会重新显示或发送。

官方模式配套使用 `dockerfile` 仓库中的 `qq-official-bot` 服务。Bot 服务保存 AppID / AppSecret，负责官方事件验签、接收目标绑定、访问凭证刷新和持久队列投递。插件只保存服务地址、服务访问令牌及接收目标别名，不需要填写 AppSecret，也不能用 QQ 号或群号代替 OpenID。

1. 在 QQ 开放平台创建机器人，配置相应的私聊/群聊权限。按 Bot 服务的 README 使用 Docker Compose 启动，并配置公网 HTTPS 回调 `/qq/webhook`。
2. 使用服务的鉴权管理接口创建一次性绑定口令，分别在机器人私聊或群内 @ 机器人发送。绑定后得到你指定的目标别名，例如 `owner`、`blog-group`。
3. 在插件 QQ 设置中选择「官方 QQ Bot」，填写 Bot 服务地址和与服务一致的访问令牌；接收目标一行一个别名，可同时填写私聊与群聊别名。
4. 开启需要的评论/友链通知并保存，点击「发送QQ通知测试」。官方模式显示已进入 Bot 服务队列和通知 ID；最终投递结果使用该 ID 在服务的通知状态接口中查询。

官方模式先将通知写入本地重试记录，并立即提交 Bot 服务的持久队列，不必等待下一次页面访问。临时连接失败的本地记录仍通过 QQ 队列重试入口处理；已经交给服务的通知由常驻服务重试。重试沿用同一幂等标识，目标和服务地址保留提交时的快照，避免更改设置后把旧通知发送给新的接收方。

「官方通知包含链接」可关闭，适用于尚未完成消息 URL 报备的机器人。关闭后移除消息中的链接，过长正文自动截短。发送仍受官方接口权限、好友/入群关系、主动接收设置和频控限制，需按 QQ 开放平台的实际配置完成出口 IP / 消息域名等白名单设置。

服务访问令牌输入框不回显已保存的值，留空保存时保留原令牌。修改令牌时同时更新插件与 Bot 服务。远程访问 Bot 服务请使用 HTTPS；同机或 Docker 内网可使用 HTTP。当前仅支持 QQ 私聊与群聊。

开发验证：通知协议和旧配置迁移使用 `php tests/qq-notify/run.php`，签名管理指令使用 `php tests/qq-commands/run.php`，独立服务联调见 [tests/qq-channel](tests/qq-channel/README.md)。官方 Webhook Bot 服务使用其目录中的 `python -m unittest discover -s tests`。测试使用隔离状态与模拟接口，不向真实 QQ 发送消息。

### 评论防御

在插件设置的「防御」分组开启「启用评论防御」并保存。已有安装直接保存即可生效，无需重新启用插件。规则适用于游客和登录用户的新评论，覆盖普通评论表单与 REST API；后台编辑已有评论不触发提交拦截。总开关关闭时，其下所有规则停止检查。

| 设置 | 行为与默认值 |
| --- | --- |
| 拦截 XSS 特征 | 默认启用；拒绝危险标签、HTML 事件属性、可执行链接等，检查常见实体/编码混淆；Typecho 原有的输出过滤继续生效 |
| 禁止评论包含 HTML | 默认关闭；开启后安全标签也会被拒绝，允许普通文字和 Markdown |
| 中文要求 | 默认不限制；开启后正文可见文字至少包含一个汉字，标签属性中的中文不计入 |
| 禁止字符或关键词 | 一行一条，包含任意一条即拒绝；按字面匹配，不执行正则表达式，不区分大小写 |
| 必须包含的字符或关键词 | 一行一条，正文可见文字至少包含其中一条；留空不限制，请在主题评论区说明填写要求 |
| IP / IP 段黑名单 | 一行一条，支持 IPv4、IPv6、CIDR 与 IPv4 尾部 `*` 通配；留空不限制 |
| IP 与同网段限频 | 默认启用；默认 300 秒内单 IP 最多 5 次、同 IPv4 `/24` 或 IPv6 `/64` 网段最多 20 次，跨文章累计 |
| 拦截重复评论 | 默认启用；同 IP 在时间窗口内重复提交相同正文时拒绝，忽略首尾/重复空白与常见零宽字符；可单独关闭 |

以上「默认启用」的子规则只在评论防御总开关开启后生效。内容命中规则返回 403；超频或重复提交返回 429 并提供 `Retry-After`，对于尚未支持 429 状态码的 Typecho 版本兼容返回 403。被内容规则拦截的有效提交尝试也会消耗频率额度。被拦截的评论不会入库、增加评论数或触发完成通知。Turnstile 与 Typecho 原有评论验证仍按各自设置执行。

IP 规则示例（请替换为需要限制的实际地址）：

```text
192.0.2.10
192.0.2.0/24
198.51.100.*
2001:db8::/32
```

防御默认以服务器连接来源 `REMOTE_ADDR` 识别 IP，避免任意伪造 `X-Forwarded-For` 绕过黑名单或限流。使用 CDN / 反向代理时，将实际代理的 IP / 网段填入「可信代理 IP / IP 段」；仅当连接来源可信时才解析转发链，并从右向左选择首个非可信来源。如 Typecho 已配置 `__TYPECHO_IP_SOURCE__`，则读取该配置指定的转发头。请仅填写实际代理范围。

防刷计数使用服务器临时目录中的站点独立文件，记录 IP/内容的 HMAC 摘要，文件锁保护并发计数，过期记录自动清理。服务器临时目录必须可写；记录损坏、写入失败或容量耗尽时返回 503，不会跳过限流。计数属于临时状态，系统清理临时文件后会重新计数。

开发验证：`php tests/comment-guard/run.php`；原 REST API 验证仍为 `php tests/rest-api/run.php`。验证使用临时 SQLite 与隔离的本地服务器。

### REST API（兼容 WordPress）

在插件设置的「API」分组中开启「启用 REST API」并保存即可使用；升级已有安装时也只需保存设置，无需重新启用整个插件。关闭后移除本插件注册的 REST 路由，接口内部也会检查开关。请先禁用独立的 RestAPI 插件，避免相同路径冲突。

接口前缀为 `/wp-json/wp/v2`，支持结尾 `/`；未启用地址重写时可使用 `/index.php/wp-json/wp/v2`。子目录安装需在前面加上站点目录。

| 接口 | 功能 |
| --- | --- |
| `GET /wp-json`、`GET /wp-json/wp/v2` | 站点与接口发现 |
| `GET /wp-json/wp/v2/posts`、`posts/{cid}` | 文章列表与单篇文章 |
| `GET /wp-json/wp/v2/posts/slug/{slug}`、`posts/tag/{slug}` | 按文章 slug 或标签 slug 查询 |
| `GET /wp-json/wp/v2/pages`、`pages/{cid}`、`pages/slug/{slug}` | 页面列表与单页 |
| `GET /wp-json/wp/v2/categories`、`categories/{mid}` | 分类列表与详情 |
| `GET /wp-json/wp/v2/tags`、`tags/{mid}` | 标签列表与详情 |
| `GET /wp-json/wp/v2/settings` | 站点标题、描述、地址及 API 自定义设置 |
| `GET /wp-json/wp/v2/links`、`link-categories` | 已审核友链与友链分类 |
| `GET /wp-json/wp/v2/comments` | 已审核的公开评论 |
| `POST /wp-json/wp/v2/comments` | 提交评论 |
| `GET /wp-json/wp/v2/users`、`users/{uid}` | 用户公开资料 |

列表通过 `page`、`per_page` 分页（每页最多 100 条），响应头 `X-WP-Total` 与 `X-WP-TotalPages` 提供总数和总页数。支持跨域读取、`OPTIONS` 预检与 `HEAD`；文章、页面等资源仅供读取。

文章/页面支持 `search`、`slug`、`author`、`include`、`exclude`、`categories`、`tags`、`tag`、`orderby`、`order`、`_embed`；摘要取自 `summary` 自定义字段，正文沿用原 RestAPI 的输出方式并移除 `<!--markdown-->` 标记。分类/标签支持 `search`、`slug`、`include`、`exclude`、`hide_empty`、`post` 等筛选。

示例：

```text
GET /wp-json/wp/v2/posts?page=1&per_page=10&_embed=1
GET /wp-json/wp/v2/links?sort=friend&per_page=50
GET /wp-json/wp/v2/comments?post=4&orderby=date&order=asc
```

`settings` 返回 `site_title`、`site_description`、`siteurl`、`head_html`、`site_footer_text`、`site_icp`，对应「API」分组中的设置，标题与描述留空时使用站点配置。这里的 HTML 与页脚内容供 API 客户端使用。

接口仅公开已到发布时间、无密码的已发布文章/页面，以及其已审核评论和审核通过的友链。`comments?status=any/hold/spam` 不提供未审核内容。

评论提交支持 JSON 或表单，字段为 `post`、`author_name`、`author_email`、`author_url`（可选）、`content`、`parent`（可选）。评论沿用 Typecho 的评论开关、校验、审核、频率限制及插件通知流程；启用 Turnstile 时还需传入 `cf-turnstile-response`。开启评论来源检查时，请携带文章页面的 `Referer`；携带登录 Cookie 提交时，还需传入文章评论表单中的 `_` 安全令牌。匿名评论无需额外的 API Token。

```json
{
  "post": 4,
  "author_name": "Alice",
  "author_email": "alice@example.com",
  "content": "文章很有帮助",
  "parent": 0
}
```

开发验证：在插件目录运行 `php tests/rest-api/run.php`（需要 PHP 8 和 PDO SQLite），使用临时数据库与本地测试服务器，不读取站点数据库；也可将 Typecho 根目录作为第一个参数传入。

### Sitemap
访问：`/sitemap.xml`

### 内容短代码
- 下载卡片：

```text
[download file='AIHelper_NnneT.tar.gz' size='16.5kb']https://file.imsun.org/upload/2025-12/AIHelper_NnneT.tar.gz[/download]
```

- 参数说明：
  - `file`：显示的文件名（可选，不填时自动从下载链接提取）
  - `size`：显示的文件大小（可选）
  - 标签体内容：下载地址（支持 `http://`、`https://`、`//`）
- 前端渲染为下载卡片，包含文件名、来源域名、大小和扩展名标签。

## 鸣谢

感谢 Typecho 官方及所有开源插件作者提供的宝贵资源。
特别感谢以下插件提供的灵感和代码参考：
- [Links 插件](https://github.com)
- [Sitemap 插件](https://github.com)
- [Comment Mail 插件](https://github.com)
- [Tag Helper 插件](https://github.com)
- [Upload Plugin 插件](https://github.com)
- Typecho-RestAPI（jkjoy，原项目版本 1.0.3，提供 WordPress REST API 兼容功能）
