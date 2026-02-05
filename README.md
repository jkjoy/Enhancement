# Enhancement 插件

融合怪插件 —— 友链增强 + 瞬间 + 标签助手 + 站点地图 + 评论增强

### 简介

基于 Links 插件改造，新增游客提交、管理员审核功能。
融合标签助手/评论同步/站点地图等实用功能。

### 功能
1. 使用 `typecho_links` 数据表存储友链信息
2. 输出方式：模板函数调用 + 文章内标签解析
3. 三种输出模式：SHOW_TEXT / SHOW_IMG / SHOW_MIX，可在插件配置中自定义规则
4. 管理面板：分类、拖拽排序、审核通过/驳回
5. 游客提交：前台提交后默认待审核，仅通过审核的记录会输出
6. 瞬间功能：新增 `typecho_moments` 表并提供 JSON API（内容支持 Markdown，Markdown 图片会自动写入 media 并从内容移除）
7. 评论同步：游客/登录用户评论时自动同步历史评论中的网址/昵称/邮箱
8. 标签助手：后台写文章时显示标签快捷选择列表
9. 站点地图：提供 `/sitemap.xml`
10. QQ评论通知：评论通过时通过 QQ 机器人推送
11. 评论邮件提醒：支持 SMTP 推送，支持模板管理
12. 可选`Gravatar`头像镜像加速

### 安装
1. 上传到 `usr/plugins/Enhancement`
2. 后台启用插件

### 后台管理
管理 → Enhancement / 瞬间
外观 → 评论邮件提醒外观（模板列表/编辑）

### 可开关设置
在插件设置页可控制：
- 评论同步
- 标签助手
- Sitemap
- 禁用插件时是否删除数据表（默认否）
- 瞬间图片占位文本（当内容仅包含图片时使用）
- QQ评论通知
- 评论邮件提醒
- `Gravatar`头像镜像加速

### 前台提交
模板或页面中调用：

```php
<?php Enhancement_Plugin::publicForm()->render(); ?>
```

或自定义表单提交到 `/action/enhancement-submit`（需带安全 token，建议用 `Helper::security()->getIndex('/action/enhancement-submit')` 生成），字段：
`name`、`url`、`sort`、`email`、`image`、`description`、`user`

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

### 瞬间 API
接口地址：
`/api/v1/memos`

可选参数：
`limit`（默认 20，最大 100）、`page`（默认 1）

返回示例：
```json
[
  {
    "id": "1",
    "content": "<p>今天很棒</p>",
    "time": "2025-01-01 12:00",
    "tags": ["生活"],
    "media": [{"type":"PHOTO","url":""}]
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

### Sitemap
访问：`/sitemap.xml`

## 鸣谢

感谢 Typecho 官方及所有开源插件作者提供的宝贵资源。
特别感谢以下插件提供的灵感和代码参考：
- [Links 插件](https://github.com)
- [Sitemap 插件](https://github.com)
- [Comment Mail 插件](https://github.com)
- [Tag Helper 插件](https://github.com)
