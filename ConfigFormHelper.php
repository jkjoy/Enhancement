<?php

class Enhancement_ConfigFormHelper
{
    public static function build(Typecho_Widget_Helper_Form $form)
    {
        $pattern_text = new Typecho_Widget_Helper_Form_Element_Textarea(
            'pattern_text',
            null,
            '<li><a href="{url}" title="{title}" target="_blank" rel="noopener">{name}</a></li>',
            _t('<h3 class="enhancement-title">友链输出设置</h3><hr/>SHOW_TEXT模式源码规则'),
            _t('使用SHOW_TEXT(仅文字)模式输出时的源码，可按上表规则替换其中字段')
        );
        $form->addInput($pattern_text);
        $pattern_img = new Typecho_Widget_Helper_Form_Element_Textarea(
            'pattern_img',
            null,
            '<li><a href="{url}" title="{title}" target="_blank" rel="noopener"><img src="{image}" alt="{name}" width="{size}" height="{size}" /></a></li>',
            _t('SHOW_IMG模式源码规则'),
            _t('使用SHOW_IMG(仅图片)模式输出时的源码，可按上表规则替换其中字段')
        );
        $form->addInput($pattern_img);
        $pattern_mix = new Typecho_Widget_Helper_Form_Element_Textarea(
            'pattern_mix',
            null,
            '<li><a href="{url}" title="{title}" target="_blank" rel="noopener"><img src="{image}" alt="{name}" width="{size}" height="{size}" /><span>{name}</span></a></li>',
            _t('SHOW_MIX模式源码规则'),
            _t('使用SHOW_MIX(图文混合)模式输出时的源码，可按上表规则替换其中字段')
        );
        $form->addInput($pattern_mix);
        $dsize = new Typecho_Widget_Helper_Form_Element_Text(
            'dsize',
            null,
            '32',
            _t('默认输出图片尺寸'),
            _t('调用时如果未指定尺寸参数默认输出的图片大小(单位px不用填写)')
        );
        $dsize->input->setAttribute('class', 'w-10');
        $form->addInput($dsize->addRule('isInteger', _t('请填写整数数字')));

        $momentsToken = new Typecho_Widget_Helper_Form_Element_Text(
            'moments_token',
            null,
            '',
            _t('<h3 class="enhancement-title">瞬间设置</h3><hr/>瞬间 API Token'),
            _t('用于 /api/v1/memos 发布瞬间（Authorization: Bearer <token>）')
        );
        $form->addInput($momentsToken->addRule('maxLength', _t('Token 最多100个字符'), 100));

        $tencentMapKey = new Typecho_Widget_Helper_Form_Element_Text(
            'tencent_map_key',
            null,
            '',
            _t('腾讯地图 API Key'),
            _t('用于瞬间“获取定位”后的逆地址解析，建议在腾讯地图控制台限制来源域名')
        );
        $tencentMapKey->input->setAttribute('autocomplete', 'off');
        $form->addInput($tencentMapKey->addRule('maxLength', _t('API Key 最多120个字符'), 120));

        $momentsImageText = new Typecho_Widget_Helper_Form_Element_Text(
            'moments_image_text',
            null,
            '图片',
            _t('瞬间图片占位文本'),
            _t('当内容仅包含图片且自动移除图片标记后为空时，使用此文本作为内容')
        );
        $form->addInput($momentsImageText->addRule('maxLength', _t('占位文本最多50个字符'), 50));

        $enableCommentSync = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_comment_sync',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('<h3 class="enhancement-title">功能开关</h3><hr/>评论同步'),
            _t('同步游客/登录用户历史评论中的网址、昵称和邮箱')
        );
        $form->addInput($enableCommentSync);

        $enableCommentSmiley = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_comment_smiley',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('评论表情'),
            _t('评论框显示表情面板，并自动解析评论内容中的表情短代码')
        );
        $form->addInput($enableCommentSmiley);

        $enableTagsHelper = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_tags_helper',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('标签助手'),
            _t('后台写文章时显示标签快捷选择列表')
        );
        $form->addInput($enableTagsHelper);

        $enableSitemap = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_sitemap',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('Sitemap'),
            _t('访问 /sitemap.xml')
        );
        $form->addInput($enableSitemap);

        $enableVideoParser = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_video_parser',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('视频链接解析'),
            _t('将 YouTube、Bilibili、优酷链接自动替换为播放器')
        );
        $form->addInput($enableVideoParser);

        $enableMusicParser = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_music_parser',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('音乐链接解析'),
            _t('将网易云音乐、QQ音乐、酷狗音乐链接自动替换为 APlayer 播放器')
        );
        $form->addInput($enableMusicParser);

        $defaultMetingApi = Enhancement_MediaHelper::defaultLocalMetingApiTemplate(Typecho_Widget::widget('Widget_Options'));

        $musicMetingApi = new Typecho_Widget_Helper_Form_Element_Text(
            'music_meting_api',
            null,
            $defaultMetingApi,
            _t('Meting API 地址'),
            _t('用于 music 链接解析播放器的数据源，默认本地接口；保留 :server/:type/:id/:r 占位符')
        );
        $form->addInput($musicMetingApi->addRule('maxLength', _t('Meting API 地址最多500个字符'), 500));

        $enableAttachmentPreview = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_attachment_preview',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('附件预览增强'),
            _t('后台写文章/页面时，启用附件预览与批量插入增强（默认关闭）')
        );
        $form->addInput($enableAttachmentPreview);

        $enableBlankTarget = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_blank_target',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('外链新窗口打开'),
            _t('给文章内容中的 a 标签添加 target="_blank" 与 rel="noopener noreferrer"')
        );
        $form->addInput($enableBlankTarget);

        $enableGoRedirect = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_go_redirect',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('外链 go 跳转'),
            _t('启用后文章、评论与评论者网站外链统一使用 /go/xxx 跳转页')
        );
        $form->addInput($enableGoRedirect);

        $goRedirectWhitelist = new Typecho_Widget_Helper_Form_Element_Textarea(
            'go_redirect_whitelist',
            null,
            '',
            _t('外链跳转白名单'),
            _t('白名单域名不使用 go 跳转；支持一行一个或逗号分隔，如 example.com, github.com')
        );
        $form->addInput($goRedirectWhitelist->addRule('maxLength', _t('白名单最多2000个字符'), 2000));

        $enableAvatarMirror = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_avatar_mirror',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('Gravatar镜像加速'),
            _t('启用后使用镜像地址加载Gavatar头像，改善国内访问速度')
        );
        $form->addInput($enableAvatarMirror);

        $avatarMirrorUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'avatar_mirror_url',
            null,
            'https://cn.cravatar.com/avatar/',
            _t('Gavatar镜像地址'),
            _t('示例：https://cn.cravatar.com/avatar/（需以 /avatar/ 结尾；禁用时将使用 Gravatar 官方地址）')
        );
        $form->addInput($avatarMirrorUrl->addRule('maxLength', _t('地址最多200个字符'), 200));

        $enableTurnstile = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_turnstile',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('<h3 class="enhancement-title">安全设置</h3><hr/>Turnstile 人机验证'),
            _t('统一保护评论提交与友情链接提交')
        );
        $form->addInput($enableTurnstile);

        $turnstileCommentGuestOnly = new Typecho_Widget_Helper_Form_Element_Radio(
            'turnstile_comment_guest_only',
            array('1' => _t('是'), '0' => _t('否（所有评论都验证）')),
            '1',
            _t('仅游客评论启用 Turnstile'),
            _t('开启后登录用户评论无需验证，游客评论仍需通过验证')
        );
        $form->addInput($turnstileCommentGuestOnly);

        $turnstileSiteKey = new Typecho_Widget_Helper_Form_Element_Text(
            'turnstile_site_key',
            null,
            '',
            _t('Turnstile Site Key'),
            _t('Cloudflare 控制台中的可公开站点密钥')
        );
        $form->addInput($turnstileSiteKey->addRule('maxLength', _t('Site Key 最多200个字符'), 200));

        $turnstileSecretKey = new Typecho_Widget_Helper_Form_Element_Text(
            'turnstile_secret_key',
            null,
            '',
            _t('Turnstile Secret Key'),
            _t('Cloudflare 控制台中的私钥（仅服务端校验使用）')
        );
        $turnstileSecretKey->input->setAttribute('autocomplete', 'off');
        $form->addInput($turnstileSecretKey->addRule('maxLength', _t('Secret Key 最多200个字符'), 200));

        $enableAiSummary = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_ai_summary',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('<h3 class="enhancement-title">AI 设置</h3><hr/>自动生成文章摘要'),
            _t('发布文章时调用 AI 生成摘要，并写入自定义字段')
        );
        $form->addInput($enableAiSummary);

        $enableAiSlugTranslate = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_ai_slug_translate',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('AI Slug 自动翻译'),
            _t('在编辑页将 slug 输入框清空后，失焦时调用 AI 生成英文 slug 并自动回填')
        );
        $form->addInput($enableAiSlugTranslate);

        $aiSummaryApiUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'ai_summary_api_url',
            null,
            'https://api.deepseek.com',
            _t('AI API 地址'),
            _t('支持 OpenAI 兼容接口；可填完整 chat/completions 地址或仅填基础地址')
        );
        $form->addInput($aiSummaryApiUrl->addRule('maxLength', _t('AI API 地址最多500个字符'), 500));

        $aiSummaryApiToken = new Typecho_Widget_Helper_Form_Element_Text(
            'ai_summary_api_token',
            null,
            '',
            _t('AI API Token'),
            _t('用于调用 AI 接口的 Bearer Token')
        );
        $aiSummaryApiToken->input->setAttribute('autocomplete', 'off');
        $form->addInput($aiSummaryApiToken->addRule('maxLength', _t('Token 最多300个字符'), 300));

        $aiSummaryModel = new Typecho_Widget_Helper_Form_Element_Text(
            'ai_summary_model',
            null,
            'deepseek-chat',
            _t('AI 模型'),
            _t('例如：gpt-4o-mini、deepseek-chat、qwen-plus')
        );
        $form->addInput($aiSummaryModel->addRule('maxLength', _t('模型名称最多120个字符'), 120));

        $aiSummaryPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'ai_summary_prompt',
            null,
            '请基于用户给出的文章内容，生成简体中文摘要。要求：1）不超过 120 字；2）客观准确；3）只输出摘要正文，不要输出标题、标签或解释。',
            _t('AI 摘要 Prompt'),
            _t('系统提示词，可按站点风格自定义')
        );
        $form->addInput($aiSummaryPrompt->addRule('maxLength', _t('Prompt 最多5000个字符'), 5000));

        $aiSummaryField = new Typecho_Widget_Helper_Form_Element_Text(
            'ai_summary_field',
            null,
            'summary',
            _t('摘要存储字段名'),
            _t('自定义字段名，默认 summary；仅支持字母/数字/下划线，且不能以数字开头')
        );
        $form->addInput($aiSummaryField->addRule('maxLength', _t('字段名最多64个字符'), 64));

        $aiSummaryUpdateMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'ai_summary_update_mode',
            array('empty' => _t('仅字段为空时生成（推荐）'), 'always' => _t('每次发布都覆盖')),
            'empty',
            _t('摘要更新策略'),
            _t('避免手动写入的摘要被自动覆盖，可选择“仅字段为空时生成”')
        );
        $form->addInput($aiSummaryUpdateMode);

        $aiSummaryMaxLength = new Typecho_Widget_Helper_Form_Element_Text(
            'ai_summary_max_length',
            null,
            '180',
            _t('摘要最大长度'),
            _t('保存前会进行截断，建议 120-300')
        );
        $aiSummaryMaxLength->input->setAttribute('class', 'w-10');
        $form->addInput($aiSummaryMaxLength->addRule('isInteger', _t('请填写整数数字')));

        $aiSummaryInputLimit = new Typecho_Widget_Helper_Form_Element_Text(
            'ai_summary_input_limit',
            null,
            '6000',
            _t('送审内容最大长度'),
            _t('发送给 AI 的正文最大字符数，避免内容过长导致接口报错或费用增加')
        );
        $aiSummaryInputLimit->input->setAttribute('class', 'w-10');
        $form->addInput($aiSummaryInputLimit->addRule('isInteger', _t('请填写整数数字')));

        $aiSslVerify = new Typecho_Widget_Helper_Form_Element_Radio(
            'ai_ssl_verify',
            array('1' => _t('启用（推荐）'), '0' => _t('禁用')),
            '1',
            _t('AI 请求 SSL 证书验证'),
            _t('建议启用，避免中间人攻击；仅在目标接口证书异常时临时关闭排查')
        );
        $form->addInput($aiSslVerify);

        $aiSlugPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'ai_slug_prompt',
            null,
            '你是 URL Slug 生成助手。请将用户提供的文章标题翻译为简洁自然的英文 slug。要求：1）只输出 slug 本身；2）仅使用小写字母、数字、连字符；3）不要输出空格、下划线、标点或解释。',
            _t('Slug 翻译 Prompt'),
            _t('系统提示词，可按站点风格调整')
        );
        $form->addInput($aiSlugPrompt->addRule('maxLength', _t('Prompt 最多5000个字符'), 5000));

        $aiSlugUpdateMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'ai_slug_update_mode',
            array('empty' => _t('仅 slug 为空时生成（推荐）'), 'always' => _t('每次保存都覆盖')),
            'empty',
            _t('Slug 更新策略'),
            _t('建议选择“仅 slug 为空时生成”，避免覆盖手动设置的 slug')
        );
        $form->addInput($aiSlugUpdateMode);

        $aiSlugMaxLength = new Typecho_Widget_Helper_Form_Element_Text(
            'ai_slug_max_length',
            null,
            '80',
            _t('Slug 最大长度'),
            _t('生成后会进行截断，建议 30-100')
        );
        $aiSlugMaxLength->input->setAttribute('class', 'w-10');
        $form->addInput($aiSlugMaxLength->addRule('isInteger', _t('请填写整数数字')));

        $enableCommentByQQ = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_comment_by_qq',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('<h3 class="enhancement-title">QQ 通知设置</h3><hr/>QQ评论通知'),
            _t('评论通过时通过 QQ 机器人推送通知')
        );
        $form->addInput($enableCommentByQQ);

        $enableLinkSubmitByQQ = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_link_submit_by_qq',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('友情链接申请通知'),
            _t('前台提交新的友情链接申请时，通过 QQ 机器人推送通知')
        );
        $form->addInput($enableLinkSubmitByQQ);

        $defaultQqApi = defined('__TYPECHO_COMMENT_BY_QQ_API_URL__')
            ? __TYPECHO_COMMENT_BY_QQ_API_URL__
            : 'https://bot.asbid.cn';
        $qq = new Typecho_Widget_Helper_Form_Element_Text(
            'qq',
            null,
            '',
            _t('接收通知的QQ号'),
            _t('需要接收通知的QQ号码')
        );
        $form->addInput($qq);

        $qqboturl = new Typecho_Widget_Helper_Form_Element_Text(
            'qqboturl',
            null,
            $defaultQqApi,
            _t('机器人API地址'),
            _t('<p>使用默认API需添加QQ机器人 153985848 为好友</p>默认API：') . $defaultQqApi
        );
        $form->addInput($qqboturl);

        $qqAsyncQueue = new Typecho_Widget_Helper_Form_Element_Radio(
            'qq_async_queue',
            array('1' => _t('启用（推荐）'), '0' => _t('禁用')),
            '1',
            _t('QQ异步队列发送'),
            _t('启用后先写入数据库队列，再由后续页面请求自动异步投递，避免评论提交因网络超时变慢')
        );
        $form->addInput($qqAsyncQueue);

        $qqTestNotifyUrl = Helper::security()->getIndex('/action/enhancement-edit?do=qq-test-notify');
        $qqActionRow = new Typecho_Widget_Helper_Form_Element_Fake('qq_action_row', null);
        $qqActionRow->setAttribute('class', 'typecho-option enhancement-option-no-bullet');
        $qqActionRow->input->setAttribute('type', 'hidden');
        $qqActionRow->description(
            '<div class="enhancement-action-row">'
            . '<a class="btn enhancement-action-btn" href="' . htmlspecialchars($qqTestNotifyUrl, ENT_QUOTES, 'UTF-8') . '">' . _t('发送QQ通知测试') . '</a>'
            . '<span class="enhancement-action-note">' . _t('先保存好 QQ 号与机器人 API 设置后,再点击测试') . '</span>'
            . '</div>'
        );
        if (isset($qqActionRow->container)) {
            $qqActionRow->container->setAttribute('style', 'list-style:none;margin:0;padding:0;');
        }
        $form->addInput($qqActionRow);

        $qqQueueStats = Enhancement_QqNotifyHelper::getQueueStats();
        $qqQueueRetryUrl = Helper::security()->getIndex('/action/enhancement-edit?do=qq-queue-retry');
        $qqQueueClearUrl = Helper::security()->getIndex('/action/enhancement-edit?do=qq-queue-clear');
        $qqQueueRow = new Typecho_Widget_Helper_Form_Element_Fake('qq_queue_row', null);
        $qqQueueRow->setAttribute('class', 'typecho-option enhancement-option-no-bullet');
        $qqQueueRow->input->setAttribute('type', 'hidden');
        $qqQueueRow->description(
            '<div class="enhancement-action-row">'
            . '<a class="btn enhancement-action-btn" href="' . htmlspecialchars($qqQueueRetryUrl, ENT_QUOTES, 'UTF-8') . '">' . _t('重试失败队列') . '</a>'
            . '<a class="btn enhancement-action-btn" href="' . htmlspecialchars($qqQueueClearUrl, ENT_QUOTES, 'UTF-8') . '" onclick="return window.confirm(\'确定要清空QQ通知队列吗？\');">' . _t('清空QQ队列') . '</a>'
            . '<span class="enhancement-action-note">' . _t(
                '队列状态：待发送 %d / 失败 %d / 已发送 %d / 总计 %d',
                intval($qqQueueStats['pending']),
                intval($qqQueueStats['failed']),
                intval($qqQueueStats['success']),
                intval($qqQueueStats['total'])
            ) . '</span>'
            . '</div>'
        );
        if (isset($qqQueueRow->container)) {
            $qqQueueRow->container->setAttribute('style', 'list-style:none;margin:0;padding:0;');
        }
        $form->addInput($qqQueueRow);

        $enableCommentNotifier = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_comment_notifier',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('<h3 class="enhancement-title">邮件提醒设置（SMTP）</h3><hr/>评论邮件提醒'),
            _t('评论通过/回复时发送邮件提醒')
        );
        $form->addInput($enableCommentNotifier);

        $enableLinkApprovalMailNotifier = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_link_approval_mail_notifier',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('友情链接审核通过邮件提醒'),
            _t('开启后，友情链接审核通过时会向该友链填写的邮箱发送提醒（需已配置 SMTP）')
        );
        $form->addInput($enableLinkApprovalMailNotifier);

        $enableLinkSubmitAdminMailNotifier = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_link_submit_admin_mail_notifier',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('新友情链接申请通知管理员'),
            _t('开启后，前台提交新的友情链接申请时会向站长收件邮箱发送审核提醒；若未填写站长收件邮箱，则回退到 SMTP 邮箱地址（需已配置 SMTP）')
        );
        $form->addInput($enableLinkSubmitAdminMailNotifier);

        $fromName = new Typecho_Widget_Helper_Form_Element_Text(
            'fromName',
            null,
            null,
            _t('发件人昵称'),
            _t('邮件显示的发件人昵称')
        );
        $form->addInput($fromName);

        $adminfrom = new Typecho_Widget_Helper_Form_Element_Text(
            'adminfrom',
            null,
            null,
            _t('站长收件邮箱'),
            _t('待审核评论或作者邮箱为空时发送到该邮箱')
        );
        $form->addInput($adminfrom);

        $smtpHost = new Typecho_Widget_Helper_Form_Element_Text(
            'STMPHost',
            null,
            'smtp.qq.com',
            _t('SMTP服务器地址'),
            _t('如: smtp.163.com,smtp.gmail.com,smtp.exmail.qq.com')
        );
        $smtpHost->setAttribute('class', 'typecho-option smtp');
        $form->addInput($smtpHost);

        $smtpUser = new Typecho_Widget_Helper_Form_Element_Text(
            'SMTPUserName',
            null,
            null,
            _t('SMTP登录用户'),
            _t('一般为邮箱地址')
        );
        $smtpUser->setAttribute('class', 'typecho-option smtp');
        $form->addInput($smtpUser);

        $smtpFrom = new Typecho_Widget_Helper_Form_Element_Text(
            'from',
            null,
            null,
            _t('SMTP邮箱地址'),
            _t('一般与SMTP登录用户名一致')
        );
        $smtpFrom->setAttribute('class', 'typecho-option smtp');
        $form->addInput($smtpFrom);

        $smtpPass = new Typecho_Widget_Helper_Form_Element_Text(
            'SMTPPassword',
            null,
            null,
            _t('SMTP登录密码'),
            _t('一般为邮箱登录密码，部分邮箱为授权码')
        );
        $smtpPass->setAttribute('class', 'typecho-option smtp');
        $form->addInput($smtpPass);

        $smtpSecure = new Typecho_Widget_Helper_Form_Element_Radio(
            'SMTPSecure',
            array('' => _t('无安全加密'), 'ssl' => _t('SSL加密'), 'tls' => _t('TLS加密')),
            '',
            _t('SMTP加密模式')
        );
        $smtpSecure->setAttribute('class', 'typecho-option smtp');
        $form->addInput($smtpSecure);

        $smtpPort = new Typecho_Widget_Helper_Form_Element_Text(
            'SMTPPort',
            null,
            '25',
            _t('SMTP服务端口'),
            _t('默认25，SSL为465，TLS为587')
        );
        $smtpPort->setAttribute('class', 'typecho-option smtp');
        $form->addInput($smtpPort);

        $log = new Typecho_Widget_Helper_Form_Element_Radio(
            'log',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('记录日志'),
            _t('启用后在插件目录生成 log.txt（目录需可写）')
        );
        $form->addInput($log);

        $yibu = new Typecho_Widget_Helper_Form_Element_Radio(
            'yibu',
            array('0' => _t('不启用'), '1' => _t('启用')),
            '0',
            _t('异步提交'),
            _t('异步回调可减小评论提交速度影响')
        );
        $form->addInput($yibu);

        $zznotice = new Typecho_Widget_Helper_Form_Element_Radio(
            'zznotice',
            array('0' => _t('通知'), '1' => _t('不通知')),
            '0',
            _t('是否通知站长'),
            _t('开启“通知”后，新评论和待审评论会发送到站长收件邮箱')
        );
        $form->addInput($zznotice);

        $biaoqing = new Typecho_Widget_Helper_Form_Element_Text(
            'biaoqing',
            null,
            null,
            _t('表情重载'),
            _t('填写评论表情解析函数名，留空则不处理')
        );
        $form->addInput($biaoqing);

        $enableS3Upload = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_s3_upload',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('<h3 class="enhancement-title">附件上传（S3）</h3><hr/>S3 远程上传'),
            _t('启用后接管附件上传到 S3 兼容存储；未完整配置时自动回退本地上传')
        );
        $form->addInput($enableS3Upload);

        $s3Endpoint = new Typecho_Widget_Helper_Form_Element_Text(
            's3_endpoint',
            null,
            's3.amazonaws.com',
            _t('S3 Endpoint'),
            _t('例如：s3.amazonaws.com、oss-cn-hangzhou.aliyuncs.com（不要包含 http:// 或 https://）')
        );
        $form->addInput($s3Endpoint->addRule('maxLength', _t('Endpoint 最多200个字符'), 200));

        $s3Bucket = new Typecho_Widget_Helper_Form_Element_Text(
            's3_bucket',
            null,
            '',
            _t('Bucket'),
            _t('存储桶名称')
        );
        $form->addInput($s3Bucket->addRule('maxLength', _t('Bucket 最多120个字符'), 120));

        $s3Region = new Typecho_Widget_Helper_Form_Element_Text(
            's3_region',
            null,
            'us-east-1',
            _t('Region'),
            _t('例如：us-east-1、ap-east-1、cn-north-1')
        );
        $form->addInput($s3Region->addRule('maxLength', _t('Region 最多120个字符'), 120));

        $s3AccessKey = new Typecho_Widget_Helper_Form_Element_Text(
            's3_access_key',
            null,
            '',
            _t('Access Key'),
            _t('S3 访问密钥 ID')
        );
        $s3AccessKey->input->setAttribute('autocomplete', 'off');
        $form->addInput($s3AccessKey->addRule('maxLength', _t('Access Key 最多200个字符'), 200));

        $s3SecretKey = new Typecho_Widget_Helper_Form_Element_Text(
            's3_secret_key',
            null,
            '',
            _t('Secret Key'),
            _t('S3 访问密钥密码')
        );
        $s3SecretKey->input->setAttribute('autocomplete', 'off');
        $form->addInput($s3SecretKey->addRule('maxLength', _t('Secret Key 最多300个字符'), 300));

        $s3CustomDomain = new Typecho_Widget_Helper_Form_Element_Text(
            's3_custom_domain',
            null,
            '',
            _t('自定义域名'),
            _t('可填 CDN 域名，例如 cdn.example.com（不要包含 http:// 或 https://）')
        );
        $form->addInput($s3CustomDomain->addRule('maxLength', _t('自定义域名最多200个字符'), 200));

        $s3UseHttps = new Typecho_Widget_Helper_Form_Element_Radio(
            's3_use_https',
            array('1' => _t('使用'), '0' => _t('不使用')),
            '1',
            _t('使用 HTTPS'),
            _t('上传与生成访问链接时使用 https 协议')
        );
        $form->addInput($s3UseHttps);

        $s3UrlStyle = new Typecho_Widget_Helper_Form_Element_Radio(
            's3_url_style',
            array('path' => _t('路径形式'), 'virtual' => _t('虚拟主机形式')),
            'path',
            _t('URL 访问方式'),
            _t('路径形式：endpoint/bucket/object；虚拟主机形式：bucket.endpoint/object')
        );
        $form->addInput($s3UrlStyle);

        $s3CustomPath = new Typecho_Widget_Helper_Form_Element_Text(
            's3_custom_path',
            null,
            '',
            _t('自定义路径前缀'),
            _t('可选，如 uploads 或 assets/images；不要包含开头和结尾斜杠')
        );
        $form->addInput($s3CustomPath->addRule('maxLength', _t('路径前缀最多200个字符'), 200));

        $s3SaveLocal = new Typecho_Widget_Helper_Form_Element_Radio(
            's3_save_local',
            array('1' => _t('保存'), '0' => _t('不保存')),
            '0',
            _t('保存本地备份'),
            _t('启用后会同时在本地 uploads 目录保留一份文件副本')
        );
        $form->addInput($s3SaveLocal);

        $s3CompressImages = new Typecho_Widget_Helper_Form_Element_Radio(
            's3_compress_images',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('图片压缩'),
            _t('启用后仅对大于 100KB 的图片进行压缩并转 WebP')
        );
        $form->addInput($s3CompressImages);

        $s3CompressQuality = new Typecho_Widget_Helper_Form_Element_Text(
            's3_compress_quality',
            null,
            '85',
            _t('压缩质量'),
            _t('1-100，数值越大质量越高但文件越大')
        );
        $s3CompressQuality->input->setAttribute('class', 'w-10');
        $form->addInput($s3CompressQuality->addRule('isInteger', _t('请填写整数数字')));

        $s3SslVerify = new Typecho_Widget_Helper_Form_Element_Radio(
            's3_ssl_verify',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('SSL 证书验证'),
            _t('若目标存储证书配置异常导致上传失败，可临时关闭排查')
        );
        $form->addInput($s3SslVerify);

        $legacyCommentSmileySize = new Typecho_Widget_Helper_Form_Element_Hidden('comment_smiley_size');
        $legacyCommentSmileySize->value('20px');
        $form->addInput($legacyCommentSmileySize);

        $settings = Enhancement_Plugin::runtimeSettings();
        $legacyDeleteTables = isset($settings->delete_tables_on_deactivate) && $settings->delete_tables_on_deactivate == '1';
        $deleteLinksDefault = $legacyDeleteTables ? '1' : '0';
        $deleteMomentsDefault = $legacyDeleteTables ? '1' : '0';
        $deleteQqQueueDefault = isset($settings->delete_qq_queue_table_on_deactivate)
            ? ($settings->delete_qq_queue_table_on_deactivate == '1' ? '1' : '0')
            : $deleteMomentsDefault;

        $deleteLinksTable = new Typecho_Widget_Helper_Form_Element_Radio(
            'delete_links_table_on_deactivate',
            array('0' => _t('否（不删除）'), '1' => _t('是（删除）')),
            $deleteLinksDefault,
            _t('<h3 class="enhancement-title">维护设置</h3><hr/>禁用插件时删除友情链接表（links）'),
            _t('谨慎开启，会删除 links 表数据')
        );
        $form->addInput($deleteLinksTable);

        $deleteMomentsTable = new Typecho_Widget_Helper_Form_Element_Radio(
            'delete_moments_table_on_deactivate',
            array('0' => _t('否（不删除）'), '1' => _t('是（删除）')),
            $deleteMomentsDefault,
            _t('禁用插件时删除说说表（moments）'),
            _t('谨慎开启，会删除 moments 表数据')
        );
        $form->addInput($deleteMomentsTable);

        $deleteQqQueueTable = new Typecho_Widget_Helper_Form_Element_Radio(
            'delete_qq_queue_table_on_deactivate',
            array('0' => _t('否（不删除）'), '1' => _t('是（删除）')),
            $deleteQqQueueDefault,
            _t('禁用插件时删除QQ通知队列表（qq_notify_queue）'),
            _t('谨慎开启，会删除QQ通知历史与失败重试记录')
        );
        $form->addInput($deleteQqQueueTable);

        $backupUrl = Helper::security()->getIndex('/action/enhancement-edit?do=backup-settings');
        Enhancement_ConfigUiHelper::renderBackupActions($backupUrl);

        $backupRows = Enhancement_SettingsHelper::listSettingsBackups(5);
        Enhancement_ConfigUiHelper::renderBackupHistory($backupRows);

        $checkUpdateUrl = Helper::security()->getIndex('/action/enhancement-edit?do=check-plugin-update');
        $upgradeUrl = Helper::security()->getIndex('/action/enhancement-edit?do=upgrade-plugin');
        $updateState = Enhancement_SettingsHelper::readPluginUpdateState();
        Enhancement_ConfigUiHelper::renderUpdateActions(Enhancement_Plugin::getPluginVersion(), $checkUpdateUrl, $upgradeUrl, $updateState);

        $template = new Typecho_Widget_Helper_Form_Element_Text(
            'template',
            null,
            'default',
            _t('邮件模板选择'),
            _t('请在邮件模板列表页面选择模板')
        );
        $template->setAttribute('class', 'hidden');
        $form->addInput($template);

        $auth = new Typecho_Widget_Helper_Form_Element_Text(
            'auth',
            null,
            Typecho_Common::randString(32),
            _t('* 接口保护'),
            _t('加盐保护 API 接口不被滥用，自动生成禁止自行设置。')
        );
        $auth->setAttribute('class', 'hidden');
        $form->addInput($auth);
    }
}
