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

        $enableFriendPosts = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_friend_posts',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('<h3 class="enhancement-title">朋友文章</h3><hr/>获取朋友的最近文章'),
            _t('默认启用。访问朋友文章独立页面时，轮换更新一个到期友链，每站抓取最多 8 秒；管理员可在“管理 → 朋友文章”提前刷新全部站点。关闭后停止抓取与前台输出。')
        );
        $form->addInput($enableFriendPosts);

        $friendPostsInterval = new Typecho_Widget_Helper_Form_Element_Text(
            'friend_posts_interval', null, '60', _t('缓存有效期（分钟）'),
            _t('范围 5–1440 分钟，默认 60 分钟。抓取失败保留旧缓存；未审核、已删除或排除的友链立即从结果中移除。')
        );
        $form->addInput($friendPostsInterval->addRule('isInteger', _t('请填写 5–1440 的整数')));

        $friendPostsPerFriend = new Typecho_Widget_Helper_Form_Element_Text(
            'friend_posts_per_friend', null, '5', _t('每位朋友保留的文章数'),
            _t('范围 1–20，默认 5 篇，所有朋友的文章按发布时间合并排序。')
        );
        $form->addInput($friendPostsPerFriend->addRule('isInteger', _t('请填写 1–20 的整数')));

        $friendPostsFeeds = new Typecho_Widget_Helper_Form_Element_Textarea(
            'friend_posts_feeds', null, '', _t('自定义订阅地址 / 排除站点'),
            _t('默认从已审核友链自动发现 RSS 2.0 / Atom 订阅。一行一条：友链地址|订阅地址，例如 https://friend.example/|https://friend.example/feed/；填写 友链地址|- 可排除该站，空行忽略。缓存保存在服务器临时目录，无需修改数据表。')
        );
        $form->addInput($friendPostsFeeds);

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

        $enableRestApi = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_rest_api',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('<h3 class="enhancement-title">REST API 设置</h3><hr/>启用 REST API'),
            _t('提供兼容 WordPress 的 /wp-json/wp/v2 接口，支持文章、页面、分类、标签、友链、评论与用户。默认关闭，保存后生效；请勿与独立 RestAPI 插件同时启用。')
        );
        $form->addInput($enableRestApi);

        $restApiTitle = new Typecho_Widget_Helper_Form_Element_Text(
            'rest_api_site_title', null, '', _t('API 网站标题'),
            _t('仅用于 REST API 返回值，留空使用站点标题')
        );
        $form->addInput($restApiTitle);

        $restApiDescription = new Typecho_Widget_Helper_Form_Element_Textarea(
            'rest_api_site_description', null, '', _t('API 网站描述'),
            _t('仅用于 REST API 返回值，留空使用站点描述')
        );
        $form->addInput($restApiDescription);

        $restApiHead = new Typecho_Widget_Helper_Form_Element_Textarea(
            'rest_api_head_html', null, '', _t('API 头部 HTML'),
            _t('通过 settings 接口的 head_html 字段返回，供客户端使用')
        );
        $form->addInput($restApiHead);

        $restApiFooter = new Typecho_Widget_Helper_Form_Element_Textarea(
            'rest_api_site_footer_text', null, '', _t('API 页脚文本'),
            _t('通过 settings 接口的 site_footer_text 字段返回')
        );
        $form->addInput($restApiFooter);

        $restApiIcp = new Typecho_Widget_Helper_Form_Element_Text(
            'rest_api_site_icp', null, '', _t('API 备案号'),
            _t('通过 settings 接口的 site_icp 字段返回')
        );
        $form->addInput($restApiIcp);

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

        $enableCommentGuard = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_comment_guard',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('<h3 class="enhancement-title">评论防御</h3><hr/>启用评论防御'),
            _t('统一检查普通评论与 REST API 评论，适用于游客和登录用户。默认关闭；命中规则直接拒绝，超频时提示稍后重试。')
        );
        $form->addInput($enableCommentGuard);

        $commentGuardXss = new Typecho_Widget_Helper_Form_Element_Radio(
            'comment_guard_block_xss', array('1' => _t('启用'), '0' => _t('禁用')), '1',
            _t('拦截 XSS 特征'), _t('拒绝危险 HTML 标签、事件属性和可执行链接协议，识别常见实体与编码混淆；保留 Typecho 原有的输出过滤。')
        );
        $form->addInput($commentGuardXss);

        $commentGuardHtml = new Typecho_Widget_Helper_Form_Element_Radio(
            'comment_guard_block_html', array('1' => _t('禁止'), '0' => _t('允许安全 HTML')), '0',
            _t('禁止评论包含 HTML'), _t('开启后拒绝所有 HTML 标签（包括安全标签），普通文字和 Markdown 仍可提交。')
        );
        $form->addInput($commentGuardHtml);

        $commentGuardChinese = new Typecho_Widget_Helper_Form_Element_Radio(
            'comment_guard_require_chinese', array('1' => _t('必须包含中文'), '0' => _t('不限制')), '0',
            _t('中文要求'), _t('开启后评论正文至少包含一个汉字，HTML 标签属性中的中文不计入。')
        );
        $form->addInput($commentGuardChinese);

        $commentGuardBlockedWords = new Typecho_Widget_Helper_Form_Element_Textarea(
            'comment_guard_blocked_keywords', null, '', _t('禁止字符或关键词'),
            _t('一行一条，正文包含任意一条即拒绝；按字面匹配，不区分大小写，支持单个字符，留空不限制。')
        );
        $form->addInput($commentGuardBlockedWords->addRule('maxLength', _t('关键词最多10000个字符'), 10000));

        $commentGuardRequiredWords = new Typecho_Widget_Helper_Form_Element_Textarea(
            'comment_guard_required_keywords', null, '', _t('必须包含的字符或关键词'),
            _t('一行一条，正文至少包含其中一条才允许提交；按字面匹配，不区分大小写，留空不限制。')
        );
        $form->addInput($commentGuardRequiredWords->addRule('maxLength', _t('关键词最多10000个字符'), 10000));

        $commentGuardBlockedIps = new Typecho_Widget_Helper_Form_Element_Textarea(
            'comment_guard_blocked_ips', null, '', _t('IP / IP 段黑名单'),
            _t('一行一条，支持单个 IPv4 / IPv6、CIDR 或 IPv4 尾部 *，如 192.0.2.10、192.0.2.0/24、2001:db8::/32、192.0.2.*；留空不限制。')
        );
        $form->addInput($commentGuardBlockedIps
            ->addRule('maxLength', _t('IP 规则最多10000个字符'), 10000)
            ->addRule(array('Enhancement_CommentGuardHelper', 'validIpRules'), _t('IP 黑名单格式错误，请检查每一行')));

        $commentGuardTrustedProxies = new Typecho_Widget_Helper_Form_Element_Textarea(
            'comment_guard_trusted_proxies', null, '', _t('可信代理 IP / IP 段'),
            _t('默认使用服务器连接来源 IP，不信任客户端自行填写的转发头。经 CDN 或反向代理访问时，在此填写代理服务器 IP / 网段，才读取转发头中的访客 IP；格式同黑名单，一行一条。')
        );
        $form->addInput($commentGuardTrustedProxies
            ->addRule('maxLength', _t('IP 规则最多10000个字符'), 10000)
            ->addRule(array('Enhancement_CommentGuardHelper', 'validIpRules'), _t('可信代理格式错误，请检查每一行')));

        $commentGuardRate = new Typecho_Widget_Helper_Form_Element_Radio(
            'comment_guard_rate_limit', array('1' => _t('启用'), '0' => _t('禁用')), '1',
            _t('IP 与同网段限频'), _t('对所有文章累计提交次数，同时限制单 IP 和 IPv4 /24、IPv6 /64 网段，防止同段轮换 IP 刷评论；被内容规则拦截的提交也会计数。')
        );
        $form->addInput($commentGuardRate);

        $commentGuardWindow = new Typecho_Widget_Helper_Form_Element_Text(
            'comment_guard_window', null, '300', _t('防刷时间窗口（秒）'),
            _t('范围 10–86400 秒，限频与重复评论共同使用，默认 300 秒')
        );
        $form->addInput($commentGuardWindow->addRule(array('Enhancement_CommentGuardHelper', 'validLimit'), _t('请填写10–86400的整数'), 10, 86400));

        $commentGuardIpLimit = new Typecho_Widget_Helper_Form_Element_Text(
            'comment_guard_ip_limit', null, '5', _t('单个 IP 在窗口内最多提交次数'), _t('范围 1–1000，默认 5 次')
        );
        $form->addInput($commentGuardIpLimit->addRule(array('Enhancement_CommentGuardHelper', 'validLimit'), _t('请填写1–1000的整数'), 1, 1000));

        $commentGuardSubnetLimit = new Typecho_Widget_Helper_Form_Element_Text(
            'comment_guard_subnet_limit', null, '20', _t('同网段在窗口内最多提交次数'),
            _t('范围 1–10000，默认 20 次；同一公司、校园或运营商的访客可能共用网段，请按访问量调整')
        );
        $form->addInput($commentGuardSubnetLimit->addRule(array('Enhancement_CommentGuardHelper', 'validLimit'), _t('请填写1–10000的整数'), 1, 10000));

        $commentGuardDuplicates = new Typecho_Widget_Helper_Form_Element_Radio(
            'comment_guard_block_duplicates', array('1' => _t('启用'), '0' => _t('禁用')), '1',
            _t('拦截重复评论'), _t('同一 IP 在时间窗口内重复提交相同正文时拒绝；此规则可独立于限频开关使用。')
        );
        $form->addInput($commentGuardDuplicates);

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

        $qqNotifyProvider = new class(
            'qq_notify_provider',
            array('none' => _t('未配置 / 关闭通知'), 'standalone' => _t('独立 QQ 通道（无需智能体，推荐）'),
                'official' => _t('官方 QQ Bot 服务（Webhook）')),
            'none',
            _t('QQ 通知方式'),
            _t('独立 QQ 通道使用 qq-channel Docker 服务，通过腾讯 SDK 收发消息及执行固定博客管理指令，无需模型。官方 Webhook 模式使用 qq-official-bot 服务。主动消息受 QQ 平台权限和额度限制。')
        ) extends Typecho_Widget_Helper_Form_Element_Radio {
            protected function inputValue($value)
            {
                parent::inputValue(in_array($value, array('none', 'standalone', 'official'), true) ? $value : 'none');
            }
        };
        $form->addInput($qqNotifyProvider);

        $qqOfficialUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'qq_official_url', null, '', _t('独立 QQ / 官方 Bot 服务地址'),
            _t('独立 QQ 通道和官方 Webhook 模式共用此项。填写服务根地址，例如 http://127.0.0.1:8080；公网部署使用 HTTPS。AppID 与 AppSecret 仅在服务中配置。')
        );
        $form->addInput($qqOfficialUrl);

        $qqOfficialToken = new class(
            'qq_official_token', null, '', _t('独立 QQ / 官方 Bot 服务访问令牌'),
            _t('与 Bot 服务 BOT_API_TOKEN 一致，至少 32 位非空白 ASCII 字符。保存后不显示，留空保留已保存令牌。')
        ) extends Typecho_Widget_Helper_Form_Element_Password {
            protected function inputValue($value)
            {
                $this->value = '';
                $this->input->removeAttribute('value');
            }
        };
        $qqOfficialToken->input->setAttribute('autocomplete', 'new-password');
        $form->addInput($qqOfficialToken);

        $qqOfficialTargets = new Typecho_Widget_Helper_Form_Element_Textarea(
            'qq_official_targets', null, '', _t('独立 QQ / 官方 Bot 接收别名'),
            _t('每行一个已在 Bot 服务绑定的别名，可同时填写私聊与群聊目标；最多 20 个。仅支持 1 至 64 位英文字母、数字、下划线和连字符，不能直接填写 QQ 号或群号。')
        );
        $form->addInput($qqOfficialTargets);

        $qqOfficialIncludeLinks = new Typecho_Widget_Helper_Form_Element_Radio(
            'qq_official_include_links', array('1' => _t('包含链接'), '0' => _t('省略链接')), '1',
            _t('独立 QQ / 官方 Bot 通知中的链接'),
            _t('未在 QQ 开放平台报备消息域名时可选择省略链接，文章、友链和审核地址等 URL 将被移除。官方消息会按 UTF-8 安全截短到 1800 字节。')
        );
        $form->addInput($qqOfficialIncludeLinks);

        $qqCommandSecret = new class(
            'qq_agent_command_secret', null, '', _t('QQ 指令回调密钥'),
            _t('与服务访问令牌使用不同的随机密钥，至少 32 位。独立服务填入相同的 ENHANCEMENT_COMMAND_SECRET。保存后不回显，留空保留。')
        ) extends Typecho_Widget_Helper_Form_Element_Password {
            protected function inputValue($value)
            {
                $this->value = '';
                $this->input->removeAttribute('value');
            }
        };
        $qqCommandSecret->input->setAttribute('autocomplete', 'new-password');
        $form->addInput($qqCommandSecret);
        $qqCommands = new class(
            'qq_agent_commands', array('1' => _t('启用'), '0' => _t('关闭')), '0',
            _t('允许 QQ 侧管理博客'),
            _t('独立 QQ 通道使用固定指令，无需技能或模型。支持评论查询、审核、回复和友链审核；只接受签名且来自白名单用户的指令。')
        ) extends Typecho_Widget_Helper_Form_Element_Radio {
            protected function inputValue($value)
            {
                $settings = Enhancement_Plugin::runtimeSettings();
                parent::inputValue(Enhancement_QqCommandSettingsHelper::commandsSupported($settings) ? $value : '0');
            }
        };
        $form->addInput($qqCommands);
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'qq_agent_allowed_senders', null, '', _t('允许 QQ 指令的用户 OpenID'),
            _t('每行一个站长 QQ 用户 OpenID，最多 20 个，不支持通配符。独立服务还需配置 QQ_ALLOWED_USERS，群指令另配 QQ_ALLOWED_GROUPS。')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qq_agent_admin_uid', null, '1', _t('QQ 指令执行身份 UID'),
            _t('填写 Typecho 管理员的数字 UID。每次指令都会检查该账号仍属于 administrator；回复以该账号署名。')
        ));
        $qqTestNotifyUrl = Helper::security()->getIndex('/action/enhancement-edit?do=qq-test-notify');
        $qqActionRow = new Typecho_Widget_Helper_Form_Element_Fake('qq_action_row', null);
        $qqActionRow->setAttribute('class', 'typecho-option enhancement-option-no-bullet');
        $qqActionRow->input->setAttribute('type', 'hidden');
        $qqActionRow->description(
            '<div class="enhancement-action-row">'
            . '<a class="btn enhancement-action-btn" href="' . htmlspecialchars($qqTestNotifyUrl, ENT_QUOTES, 'UTF-8') . '">' . _t('发送QQ通知测试') . '</a>'
            . '<span class="enhancement-action-note">' . _t('先保存配置再测试；服务确认请求后仍需检查 QQ 实际接收结果') . '</span>'
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
                '本地队列：待投递 %d / 失败 %d / 已投递 %d / 总计 %d（官方模式已投递表示服务已接受）',
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

        $smtpTestUrl = Helper::security()->getIndex('/action/enhancement-edit?do=smtp-test-mail');
        $smtpActionRow = new Typecho_Widget_Helper_Form_Element_Fake('smtp_action_row', null);
        $smtpActionRow->setAttribute('class', 'typecho-option enhancement-option-no-bullet');
        $smtpActionRow->input->setAttribute('type', 'hidden');
        $smtpActionRow->description(
            '<div class="enhancement-action-row">'
            . '<a class="btn enhancement-action-btn enhancement-smtp-test" href="' . htmlspecialchars($smtpTestUrl, ENT_QUOTES, 'UTF-8') . '">' . _t('发送测试邮件') . '</a>'
            . '<span class="enhancement-action-note enhancement-smtp-test-status" aria-live="polite">' . _t('使用当前填写的设置测试，无需先保存；邮件发送到站长收件邮箱，未填写时发送到 SMTP 邮箱地址') . '</span>'
            . '</div>'
        );
        if (isset($smtpActionRow->container)) {
            $smtpActionRow->container->setAttribute('style', 'list-style:none;margin:0;padding:0;');
        }
        $form->addInput($smtpActionRow);

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

        // Typecho binds every saved key after config() returns, including removed fields.
        $inputs = $form->getInputs();
        foreach ($settings as $key => $value) {
            if (isset($inputs[$key])) {
                continue;
            }
            $placeholder = new class((string)$key, null) extends Typecho_Widget_Helper_Form_Element_Fake {
                protected function inputValue($value)
                {
                    // Accept the binding without rendering or submitting obsolete values.
                    $this->value = '';
                }
            };
            $placeholder->input->setAttribute('type', 'hidden');
            $form->addInput($placeholder);
        }
    }
}
