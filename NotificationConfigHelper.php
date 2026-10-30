<?php

class Enhancement_NotificationConfigHelper
{
    public static function addQqInputs(Typecho_Widget_Helper_Form $form)
    {
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qq_app_id', null, '', _t('QQ 机器人 AppID'),
            _t('填写 QQ 开放平台机器人正式环境的 AppID。')
        ));
        $secret = new class(
            'qq_app_secret', null, '', _t('QQ 机器人 AppSecret'),
            _t('填写对应机器人的 AppSecret。保存后不回显，留空保留已保存密钥；修改 AppID 时须同时填写新 AppSecret。')
        ) extends Typecho_Widget_Helper_Form_Element_Password {
            protected function inputValue($value)
            {
                $this->value = '';
                $this->input->removeAttribute('value');
            }
        };
        $secret->input->setAttribute('autocomplete', 'new-password');
        $form->addInput($secret);
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'qq_official_include_links', array('1' => _t('包含链接'), '0' => _t('省略链接')), '1',
            _t('QQ 通知中的链接'),
            _t('消息域名未在 QQ 开放平台报备时可选择省略链接；通知正文自动按 UTF-8 安全截短。')
        ));
        $form->addInput(new class(
            'qq_moments_enabled', array('1' => _t('启用'), '0' => _t('禁用')), '0',
            _t('通过 QQ 发布瞬间'),
            _t('保存后可在已绑定的 QQ 私聊发送“瞬间 内容”发布。可以先启用再绑定；仅已绑定私聊用户可以发布，群聊不支持发布。只处理启用并保存后发送、且发送至今不超过 24 小时的消息。')
        ) extends Typecho_Widget_Helper_Form_Element_Radio {
            protected function inputValue($value)
            {
                $settings = Enhancement_Plugin::runtimeSettings();
                parent::inputValue(($settings->qq_notify_provider ?? '') === 'official' ? $value : '0');
            }
        });

        $options = Typecho_Widget::widget('Widget_Options');
        $callback = Typecho_Common::url('/action/enhancement-edit?do=qq-webhook', $options->index);
        self::addPanel($form, 'enhancement_qq_binding_panel',
            '<section id="enhancement-qq-binding"' . self::actionAttributes(array('qq-binding-start', 'qq-binding-cancel', 'qq-binding-status', 'qq-disconnect', 'qq-retry-replies')) . '>'
            . '<h4>QQ 官方 Webhook 与接收人绑定</h4>'
            . '<label for="enhancement-qq-webhook-url">QQ 官方 Webhook 回调地址</label>'
            . '<input id="enhancement-qq-webhook-url" type="text" readonly spellcheck="false" style="width:100%;margin:8px 0" value="' . self::escape($callback) . '">'
            . '<p>将此地址填入 <a href="https://q.qq.com/" target="_blank" rel="noopener noreferrer">QQ 开放平台</a>机器人的 Webhook URL，完成回调验证。须为公网可访问的 HTTPS 地址，并保持插件启用；私聊订阅 <code>C2C_MESSAGE_CREATE</code>，群通知订阅 <code>GROUP_AT_MESSAGE_CREATE</code>。</p>'
            . '<ol><li>选择官方 QQ 机器人，填写 AppID 和 AppSecret 并保存。</li>'
            . '<li>点击“生成私聊绑定码”，在机器人私聊发送绑定命令；绑定码有效期 10 分钟。</li>'
            . '<li>点击“检查绑定状态”确认绑定，随后可发送通知测试或发布瞬间。</li></ol>'
            . '<p data-role="state" aria-live="polite">正在读取绑定状态……</p>'
            . '<div data-role="binding" hidden><label for="enhancement-qq-binding-command">一次性绑定命令</label>'
            . '<input id="enhancement-qq-binding-command" type="text" readonly spellcheck="false" style="width:100%;margin:8px 0">'
            . '<p data-role="binding-hint"></p></div>'
            . '<div class="enhancement-action-row"><button type="button" class="btn" data-action="qq-binding-start" disabled>生成私聊绑定码</button> '
            . '<button type="button" class="btn" data-action="qq-binding-start" data-target-type="group" disabled>生成群通知绑定码</button> '
            . '<button type="button" class="btn" data-action="qq-binding-cancel" disabled>取消本次绑定</button> '
            . '<button type="button" class="btn" data-action="qq-binding-status">检查绑定状态</button> '
            . '<button type="button" class="btn" data-action="qq-disconnect" disabled>解除当前绑定</button> '
            . '<button type="button" class="btn" data-action="qq-retry-replies" disabled>重试发布结果回复</button></div>'
            . '<p data-role="events"></p><p data-role="moments-state"></p><p data-role="last-result"></p>'
            . '<p data-role="message" role="status" aria-live="polite"></p>'
            . '<p>绑定从已验签的 QQ 消息自动取得 OpenID，无需填写 QQ 号或接收 ID。群通知绑定时请在群内 @机器人发送命令；每次绑定替换当前接收人，发布瞬间请绑定私聊。当前 PHP 需启用 sodium 扩展以验证官方签名。</p>'
            . '<p>发送 <code>瞬间 内容</code>、<code>私密瞬间 内容</code> 发布；正文支持多行、QQ 表情文字、Markdown 图片 URL 和同条图片附件。分步多图发送 <code>图文瞬间 正文</code>，接着发图，最后发送 <code>发布瞬间</code>，或发送 <code>取消瞬间</code> 放弃；草稿消息发送时间间隔最多 15 分钟。</p>'
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
            _t('先登录微信并绑定接收人，仅当前绑定接收人可发布。默认随网站访问自动接收；无人访问时需持续接收，可让系统计划任务每分钟运行 BotMoments/worker.php --once。只处理启用并保存后发送、且发送至今不超过 24 小时的消息。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'weixin_moments_auto_receive', array('1' => _t('启用'), '0' => _t('禁用')), '1',
            _t('微信自动接收'),
            _t('默认开启，仅“通过微信发布瞬间”启用时生效。随网站访问触发，最多每分钟一次；无人访问或全站静态缓存时会延后，需要服务器能请求站点自身 URL。要不依赖访问接收，请按下方说明配置宝塔每分钟运行 --once 的计划任务；也可使用 --loop。')
        ));

        $options = Typecho_Widget::widget('Widget_Options');
        $assetBase = rtrim((string)$options->pluginUrl, '/') . '/Enhancement/assets/';
        $scripts = '';
        foreach (array('vendor/qrcode.js', 'vendor/qrcode_UTF8.js', 'notifications.js') as $asset) {
            $url = Enhancement_Plugin::appendVersionToAssetUrl($assetBase . $asset);
            $scripts .= '<script defer src="' . self::escape($url) . '"></script>';
        }
        $workerPath = str_replace('\\', '/', __DIR__) . '/BotMoments/worker.php';
        $workerCommand = '/www/server/php/' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . "/bin/php '" . str_replace("'", "'\\''", $workerPath) . "' --once";
        self::addPanel($form, 'enhancement_weixin_panel',
            '<section id="enhancement-weixin"' . self::actionAttributes(array('weixin-login-start', 'weixin-login-poll', 'weixin-sync', 'weixin-bind', 'weixin-disconnect', 'weixin-status', 'weixin-test-notify')) . '>'
            . '<h4>微信连接与接收人</h4>'
            . '<p>获取二维码 → 微信扫码并确认 → 检查登录状态 → 给微信机器人发送一条消息 → 同步并绑定接收人 → 保存启用通知。</p>'
            . '<p data-role="state" aria-live="polite">正在读取微信连接状态……</p>'
            . '<p data-role="recipient"></p><p data-role="context"></p><p data-role="auto-receive"></p>'
            . '<details><summary>宝塔每分钟接收配置</summary>'
            . '<p>在宝塔“计划任务”中添加“Shell脚本”，执行周期选择“每分钟”。将下方 PHP 路径替换为网站对应的 PHP CLI，须启用 cURL、PDO 及站点数据库所需驱动；worker 路径已按当前插件位置填写。执行用户请选择网站 PHP 运行用户，宝塔通常为 www。</p>'
            . '<textarea id="enhancement-weixin-cron-command" readonly rows="3" spellcheck="false" aria-label="宝塔计划任务接收命令" style="width:100%;box-sizing:border-box;resize:vertical">' . self::escape($workerCommand) . '</textarea>'
            . '<p>保存并启用任务后，由宝塔计划任务服务每分钟运行，无需保持终端或后台页面打开。接收任务会避免并发重复执行；“通过微信发布瞬间”须保持启用，默认的访问触发偏好可以保留。</p></details>'
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
            . '<p>连接与自动接收状态使用已保存的设置，表单修改须保存后才生效。自动接收状态仅记录访问触发任务；系统任务请查看宝塔任务日志。测试受理后请在微信中核对实际送达。会话过期或通知失败时，请给机器人发送一条消息，再同步接收人。退出登录会清除本地登录凭证和接收人。</p>'
            . '<p>开启“通过微信发布瞬间”后，发送 <code>瞬间 内容</code> 或 <code>发瞬间 内容</code> 发布公开瞬间；发送 <code>私密瞬间 内容</code> 发布私密瞬间。正文支持多行、Markdown 图片 URL，以及同条命令携带的图片附件。</p>'
            . '<p>多图分步发布：发送 <code>图文瞬间 正文</code>（公开）或 <code>私密图文瞬间 正文</code>，正文可空；接着连续发送图片，最后发送 <code>发布瞬间</code> 合并发布，或用 <code>取消瞬间</code> 放弃。草稿建立至发布的消息发送时间间隔最多 15 分钟，期间的图片可延迟批量同步；最多 9 张图片，每张最多 10 MiB。没有草稿时忽略纯图片；确认发布时才下载、解密并保存图片。</p>'
            . '<p>普通瞬间的时间取命令消息的发送时间，图文草稿取“发布瞬间”消息的发送时间。图片沿用站点本地或已配置的 S3 存储。</p>'
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
