<?php

class Enhancement_NotificationConfigHelper
{
    public static function addQqInputs(Typecho_Widget_Helper_Form $form)
    {
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qq_app_id', null, '', _t('直连 QQ AppID'),
            _t('在 QQ 开放平台创建机器人，选择“官方 QQ 机器人直连”后填写正式环境 AppID；此方式无需额外部署 Bot 服务。')
        ));
        $secret = new class(
            'qq_app_secret', null, '', _t('直连 QQ AppSecret'),
            _t('机器人正式环境密钥。保存后不回显，留空保留已保存密钥；修改 AppID 时请同时填写对应的新密钥。')
        ) extends Typecho_Widget_Helper_Form_Element_Password {
            protected function inputValue($value)
            {
                $this->value = '';
                $this->input->removeAttribute('value');
            }
        };
        $secret->input->setAttribute('autocomplete', 'new-password');
        $form->addInput($secret);
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'qq_target_type',
            array('c2c' => _t('私聊（用户 OpenID）'), 'group' => _t('QQ群（群 OpenID）'), 'channel' => _t('QQ频道（子频道 ID）')),
            'c2c', _t('直连 QQ 接收类型'),
            _t('私聊和群聊可通过下方绑定码取得 OpenID；频道请手动填写子频道 ID，且需其他程序保持机器人 WebSocket 在线。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qq_target_id', null, '', _t('直连 QQ 接收 ID'),
            _t('填写机器人事件中的用户 OpenID、群 OpenID 或子频道 ID，不能直接填写 QQ 号或群号。接收人须先添加机器人好友，或将机器人加入目标群。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'qq_direct_include_links', array('1' => _t('包含链接'), '0' => _t('省略链接')), '1',
            _t('直连 QQ 通知中的链接'),
            _t('消息域名可能需要在 QQ 开放平台报备；平台拒绝链接时可选择省略。主动消息受平台权限、额度和频率限制。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'qq_moments_enabled', array('1' => _t('启用'), '0' => _t('禁用')), '0',
            _t('通过 QQ 发布瞬间'),
            _t('仅官方 QQ 直连支持，须先完成事件回调和接收人绑定。私聊仅允许已绑定接收人；群聊仅允许已绑定群内下方白名单中的成员，每次发命令或图片均须 @机器人。频道不支持。只执行启用并保存后的新消息，不读取旧聊天。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'qq_moments_allowed_senders', null, '', _t('QQ 群瞬间发布者 OpenID'),
            _t('每行一个允许在已绑定群中发布瞬间的成员 OpenID；不能填写 QQ 号、群号或群 OpenID，不支持通配符。群聊发布必须另行填写此白名单，留空不允许任何群成员发布；私聊仅使用已绑定接收人的身份。')
        ));

        $options = Typecho_Widget::widget('Widget_Options');
        $callback = Typecho_Common::url('/action/enhancement-edit?do=qq-webhook', $options->index);
        self::addPanel($form, 'enhancement_qq_binding_panel',
            '<section id="enhancement-qq-binding"' . self::actionAttributes(array('qq-binding-start', 'qq-binding-cancel', 'qq-binding-status')) . '>'
            . '<h4>QQ 官方事件回调与接收人绑定</h4>'
            . '<label for="enhancement-qq-webhook-url">官方事件回调地址</label>'
            . '<input id="enhancement-qq-webhook-url" type="text" readonly spellcheck="false" style="width:100%;margin:8px 0" value="' . self::escape($callback) . '">'
            . '<p>将此地址填入 <a href="https://q.qq.com/" target="_blank" rel="noopener noreferrer">QQ 开放平台</a>机器人管理的事件订阅，完成回调验证。须为公网可访问的 HTTPS 地址，并保持插件启用。</p>'
            . '<ol><li>先填写 AppID、AppSecret，选择私聊或 QQ 群并保存配置；接收 ID 可暂时留空。</li>'
            . '<li>私聊订阅 <code>C2C_MESSAGE_CREATE</code>；群聊订阅 <code>GROUP_AT_MESSAGE_CREATE</code>。</li>'
            . '<li>生成绑定码，在目标私聊中发送绑定命令；群聊请 @机器人发送。绑定码有效期 10 分钟。</li>'
            . '<li>点击“检查绑定状态”，确认接收 ID 已自动填写，再启用 QQ 通知、保存并发送测试。</li></ol>'
            . '<p data-role="state" aria-live="polite">正在读取绑定状态……</p>'
            . '<div data-role="binding" hidden><label for="enhancement-qq-binding-command">一次性绑定命令</label>'
            . '<input id="enhancement-qq-binding-command" type="text" readonly spellcheck="false" style="width:100%;margin:8px 0">'
            . '<p data-role="binding-hint"></p></div>'
            . '<div class="enhancement-action-row"><button type="button" class="btn" data-action="qq-binding-start" disabled>生成绑定码</button> '
            . '<button type="button" class="btn" data-action="qq-binding-cancel" disabled>取消本次绑定</button> '
            . '<button type="button" class="btn" data-action="qq-binding-status">检查绑定状态</button></div>'
            . '<p data-role="events"></p><p data-role="last-result"></p><p data-role="message" role="status" aria-live="polite"></p>'
            . '<p>自动绑定只填写接收 ID，不会自动启用通知。当前 PHP 需要启用 sodium 扩展以验证官方回调签名；手动填写接收 ID 的发送功能不依赖该扩展。</p>'
            . '<p>开启“通过 QQ 发布瞬间”后，发送 <code>瞬间 内容</code> 或 <code>发瞬间 内容</code> 发布公开瞬间；发送 <code>私密瞬间 内容</code> 发布私密瞬间。正文支持多行、Markdown 图片 URL，以及同条命令携带的图片附件。</p>'
            . '<p>多图分步发布：发送 <code>图文瞬间 正文</code>（公开）或 <code>私密图文瞬间 正文</code>，正文可空；接着连续发送图片，最后发送 <code>发布瞬间</code> 合并发布，或用 <code>取消瞬间</code> 放弃。草稿有效 15 分钟，最多 9 张图片，每张最多 10 MiB；未建立草稿的纯图片消息会忽略。群聊每一步均须 @机器人。</p>'
            . '<p>图片在确认发布时才下载并保存到站点本地或已配置的 S3。下载期间可取消，进入保存阶段后暂时不能取消；若结果待核对，先查看后台瞬间，确认后再取消重建。私密瞬间的正文和 API 按私密处理；图片文件 URL 沿用现有媒体存储的公开规则，不提供额外的文件访问鉴权。</p>'
            . '<noscript>请启用 JavaScript 以生成绑定码和检查绑定状态。</noscript></section>'
        );
    }

    public static function addWeixinInputs(Typecho_Widget_Helper_Form $form)
    {
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_comment_by_weixin', array('1' => _t('启用'), '0' => _t('禁用')), '0',
            _t('<h3 class="enhancement-title">微信通知设置</h3><hr/>微信评论通知'),
            _t('评论通过时通过微信推送通知。先在下方扫码登录并同步接收人，再保存启用。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_link_submit_by_weixin', array('1' => _t('启用'), '0' => _t('禁用')), '0',
            _t('微信友情链接申请通知'), _t('前台提交新的友情链接申请时，通过微信推送通知。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'weixin_include_links', array('1' => _t('包含链接'), '0' => _t('省略链接')), '1',
            _t('微信通知中的链接'), _t('选择是否在微信通知中附上文章、友链及审核地址。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'weixin_moments_enabled', array('1' => _t('启用'), '0' => _t('禁用')), '0',
            _t('通过微信发布瞬间'),
            _t('先登录微信并绑定接收人，仅当前绑定接收人可发布。默认随网站访问自动接收；后台“同步微信接收人”也会处理收到的新命令。只执行启用并保存后的新消息，不读取旧聊天。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'weixin_moments_auto_receive', array('1' => _t('启用'), '0' => _t('禁用')), '1',
            _t('微信自动接收'),
            _t('默认开启，仅“通过微信发布瞬间”启用时生效。随网站访问触发，最多每分钟一次，无需常驻接收器；无人访问或全站静态缓存时会延后，需要服务器能请求站点自身 URL。已运行 BotMoments/worker.php --loop 或系统计划任务时可关闭。')
        ));

        $options = Typecho_Widget::widget('Widget_Options');
        $assetBase = rtrim((string)$options->pluginUrl, '/') . '/Enhancement/assets/';
        $scripts = '';
        foreach (array('vendor/qrcode.js', 'vendor/qrcode_UTF8.js', 'notifications.js') as $asset) {
            $url = Enhancement_Plugin::appendVersionToAssetUrl($assetBase . $asset);
            $scripts .= '<script defer src="' . self::escape($url) . '"></script>';
        }
        self::addPanel($form, 'enhancement_weixin_panel',
            '<section id="enhancement-weixin"' . self::actionAttributes(array('weixin-login-start', 'weixin-login-poll', 'weixin-sync', 'weixin-bind', 'weixin-disconnect', 'weixin-status', 'weixin-test-notify')) . '>'
            . '<h4>微信连接与接收人</h4>'
            . '<p>获取二维码 → 微信扫码并确认 → 检查登录状态 → 给微信机器人发送一条消息 → 同步并绑定接收人 → 保存启用通知。</p>'
            . '<p data-role="state" aria-live="polite">正在读取微信连接状态……</p>'
            . '<p data-role="recipient"></p><p data-role="context"></p><p data-role="auto-receive"></p>'
            . '<div data-role="qr-area" hidden><div data-role="qr" style="max-width:280px"></div>'
            . '<p>请使用微信扫描并在手机上确认；二维码在本机生成。完成后点击“检查登录状态”。</p></div>'
            . '<div data-role="verify" hidden><label for="enhancement-weixin-code">手机验证码</label> '
            . '<input id="enhancement-weixin-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" pattern="[0-9]{1,12}" style="max-width:180px"></div>'
            . '<div class="enhancement-action-row"><button type="button" class="btn" data-action="weixin-login-start">获取登录二维码</button> '
            . '<button type="button" class="btn" data-action="weixin-login-poll" disabled>检查登录状态</button> '
            . '<button type="button" class="btn" data-action="weixin-sync" disabled>同步微信接收人</button> '
            . '<button type="button" class="btn" data-action="weixin-status">刷新连接状态</button></div>'
            . '<div data-role="candidates" hidden style="margin:12px 0"><label for="enhancement-weixin-candidate">选择刚刚发送消息的接收人</label> '
            . '<select id="enhancement-weixin-candidate" style="max-width:100%"></select> '
            . '<button type="button" class="btn" data-action="weixin-bind" disabled>绑定选中的接收人</button></div>'
            . '<div class="enhancement-action-row" style="margin-top:12px"><button type="button" class="btn" data-action="weixin-test-notify" disabled>发送微信通知测试</button> '
            . '<button type="button" class="btn" data-action="weixin-disconnect" disabled>退出微信登录</button></div>'
            . '<p data-role="last-result"></p><p data-role="message" role="status" aria-live="polite"></p>'
            . '<p>连接与自动接收状态使用已保存的设置；表单修改须保存后才生效。测试受理后请在微信中核对实际送达。会话过期或通知失败时，请给机器人发送一条消息，再同步接收人。退出登录会清除本地登录凭证和接收人。</p>'
            . '<p>开启“通过微信发布瞬间”后，发送 <code>瞬间 内容</code> 或 <code>发瞬间 内容</code> 发布公开瞬间；发送 <code>私密瞬间 内容</code> 发布私密瞬间。正文支持多行、Markdown 图片 URL，以及同条命令携带的图片附件。</p>'
            . '<p>多图分步发布：发送 <code>图文瞬间 正文</code>（公开）或 <code>私密图文瞬间 正文</code>，正文可空；接着连续发送图片，最后发送 <code>发布瞬间</code> 合并发布，或用 <code>取消瞬间</code> 放弃。草稿有效 15 分钟，最多 9 张图片，每张最多 10 MiB；没有草稿时忽略纯图片。图片在确认发布时才下载、解密并保存到站点本地或已配置的 S3。</p>'
            . '<p>下载期间可取消，进入保存阶段后暂时不能取消；若结果待核对，先查看后台瞬间，确认后再取消重建。私密瞬间的正文和 API 按私密处理；图片文件 URL 沿用现有媒体存储的公开规则，不提供额外的文件访问鉴权。若关闭自动接收，请运行命令行接收器、配置系统计划任务，或在后台手动同步。</p>'
            . '<noscript>请启用 JavaScript 以扫码登录、同步接收人和发送测试通知。</noscript></section>' . $scripts
        );
    }

    private static function addPanel(Typecho_Widget_Helper_Form $form, $name, $html)
    {
        $panel = new Typecho_Widget_Helper_Form_Element_Fake($name, null);
        $panel->setAttribute('class', 'typecho-option enhancement-option-no-bullet');
        $panel->container((new Typecho_Widget_Helper_Layout('div'))->html($html));
        $panel->container->setAttribute('style', 'list-style:none;margin:0;padding:0;');
        $form->addInput($panel);
    }

    private static function actionAttributes(array $actions)
    {
        $attributes = '';
        foreach ($actions as $action) {
            $url = Helper::security()->getIndex('/action/enhancement-edit?do=' . $action);
            $attributes .= ' data-' . $action . '="' . self::escape($url) . '"';
        }
        return $attributes;
    }

    private static function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
