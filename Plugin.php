<?php

/**
 * Enhancement 插件
 * 具体功能包含:插件/主题zip上传,友情链接,瞬间,网站地图,编辑器增强,站外链接跳转,评论邮件通知,QQ通知,常见视频链接 音乐链接 解析等
 * @package Enhancement
 * @author jkjoy
 * @version 1.1.4
 * @link HTTPS://IMSUN.ORG
 * @dependence 14.10.10-*
 */

class Enhancement_Plugin implements Typecho_Plugin_Interface
{
    public static $commentNotifierPanel = 'Enhancement/CommentNotifier/console.php';

    private static function settingsBackupNamePrefix()
    {
        return 'plugin:Enhancement:backup:';
    }

    private static function listSettingsBackups($limit = 5)
    {
        $limit = intval($limit);
        if ($limit <= 0) {
            $limit = 5;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        try {
            $db = Typecho_Db::get();
            $prefix = self::settingsBackupNamePrefix();
            $rows = $db->fetchAll(
                $db->select('name')
                    ->from('table.options')
                    ->where('name LIKE ?', $prefix . '%')
                    ->where('user = ?', 0)
                    ->order('name', Typecho_Db::SORT_DESC)
                    ->limit($limit)
            );

            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    private static function pluginSettings($options = null)
    {
        if ($options === null) {
            $options = Typecho_Widget::widget('Widget_Options');
        }

        try {
            $settings = $options->plugin('Enhancement');
            if (is_object($settings)) {
                return $settings;
            }
        } catch (Exception $e) {
            // 配置缺失时返回空配置，避免前台致命错误
        }

        return (object) array();
    }

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return string
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        $info = Enhancement_Plugin::enhancementInstall();
        Helper::addPanel(3, 'Enhancement/manage-enhancement.php', _t('链接'), _t('链接审核与管理'), 'administrator');
        Helper::addPanel(3, 'Enhancement/manage-moments.php', _t('瞬间'), _t('瞬间管理'), 'administrator');
        Helper::addPanel(1, 'Enhancement/manage-upload.php', _t('上传'), _t('上传管理'), 'administrator');
        Helper::addPanel(1, self::$commentNotifierPanel, _t('邮件提醒外观'), _t('评论邮件提醒主题列表'), 'administrator');
        Helper::addRoute('sitemap', '/sitemap.xml', 'Enhancement_Sitemap_Action', 'action');
        Helper::addRoute('memos_api', '/api/v1/memos', 'Enhancement_Memos_Action', 'action');
        Helper::addRoute('zemail', '/zemail', 'Enhancement_CommentNotifier_Action', 'action');
        Helper::addRoute('go', '/go/[target]', 'Enhancement_Action', 'goRedirect');
        Helper::addAction('enhancement-edit', 'Enhancement_Action');
        Helper::addAction('enhancement-submit', 'Enhancement_Action');
        Helper::addAction('enhancement-moments-edit', 'Enhancement_Action');
        Typecho_Plugin::factory('Widget_Feedback')->comment_1 = [__CLASS__, 'turnstileFilterComment'];
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = [__CLASS__, 'finishComment'];
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = [__CLASS__, 'finishComment'];
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark = [__CLASS__, 'commentNotifierMark'];
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark_2 = [__CLASS__, 'commentByQQMark'];
        Typecho_Plugin::factory('Widget_Service')->send = [__CLASS__, 'commentNotifierSend'];
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array(__CLASS__, 'writePostBottom');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = array(__CLASS__, 'writePageBottom');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('Enhancement_Plugin', 'parse');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('Enhancement_Plugin', 'parse');
        Typecho_Plugin::factory('Widget_Abstract_Comments')->contentEx = array('Enhancement_Plugin', 'parse');
        Typecho_Plugin::factory('Widget_Archive')->handleInit = array('Enhancement_Plugin', 'applyAvatarPrefix');
        Typecho_Plugin::factory('Widget_Archive')->footer = array('Enhancement_Plugin', 'turnstileFooter');
        Typecho_Plugin::factory('Widget_Archive')->callEnhancement = array('Enhancement_Plugin', 'output_str');
        return _t($info);
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);
        $legacyDeleteTables = isset($settings->delete_tables_on_deactivate) && $settings->delete_tables_on_deactivate == '1';
        $deleteLinksTable = isset($settings->delete_links_table_on_deactivate) && $settings->delete_links_table_on_deactivate == '1';
        $deleteMomentsTable = isset($settings->delete_moments_table_on_deactivate) && $settings->delete_moments_table_on_deactivate == '1';
        $deleteQqQueueTable = isset($settings->delete_qq_queue_table_on_deactivate)
            ? ($settings->delete_qq_queue_table_on_deactivate == '1')
            : $deleteMomentsTable;

        if ($legacyDeleteTables) {
            if (!isset($settings->delete_links_table_on_deactivate)) {
                $deleteLinksTable = true;
            }
            if (!isset($settings->delete_moments_table_on_deactivate)) {
                $deleteMomentsTable = true;
            }
            if (!isset($settings->delete_qq_queue_table_on_deactivate)) {
                $deleteQqQueueTable = true;
            }
        }

        Helper::removeRoute('sitemap');
        Helper::removeRoute('memos_api');
        Helper::removeRoute('zemail');
        Helper::removeRoute('go');
        Helper::removeAction('enhancement-edit');
        Helper::removeAction('enhancement-submit');
        Helper::removeAction('enhancement-moments-edit');
        Helper::removePanel(3, 'Enhancement/manage-enhancement.php');
        Helper::removePanel(3, 'Enhancement/manage-moments.php');
        Helper::removePanel(3, 'Enhancement/manage-upload.php');
        Helper::removePanel(1, self::$commentNotifierPanel);

        if ($deleteLinksTable || $deleteMomentsTable || $deleteQqQueueTable) {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $type = explode('_', $db->getAdapterName());
            $type = array_pop($type);

            try {
                if ('Pgsql' == $type) {
                    if ($deleteLinksTable) {
                        $db->query('DROP TABLE IF EXISTS "' . $prefix . 'links"');
                    }
                    if ($deleteMomentsTable) {
                        $db->query('DROP TABLE IF EXISTS "' . $prefix . 'moments"');
                    }
                    if ($deleteQqQueueTable) {
                        $db->query('DROP TABLE IF EXISTS "' . $prefix . 'qq_notify_queue"');
                    }
                } else {
                    if ($deleteLinksTable) {
                        $db->query('DROP TABLE IF EXISTS `' . $prefix . 'links`');
                    }
                    if ($deleteMomentsTable) {
                        $db->query('DROP TABLE IF EXISTS `' . $prefix . 'moments`');
                    }
                    if ($deleteQqQueueTable) {
                        $db->query('DROP TABLE IF EXISTS `' . $prefix . 'qq_notify_queue`');
                    }
                }
            } catch (Exception $e) {
                // ignore drop errors on deactivate
            }
        }
    }

    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        echo '<style type="text/css">
    table {
        background: #FFF;
        border: 2px solid #e3e3e3;
        color: #666;
        font-size: .92857em;
        width: 452px;
    }

    th {
        border: 2px solid #e3e3e3;
        padding: 5px;
    }

    table td {
        border-top: 1px solid #e3e3e3;
        padding: 3px;
        text-align: center;
        border-right: 2px solid #e3e3e3;
    }

    .field {
        color: #467B96;
        font-weight: bold;
    }
    .enhancement-title{
        margin:24px 0 8px;
        font-size: 1.2em;
        font-weight: bold;
        color: #270b5b;
    }    
    .enhancement-title::before {
        content: "# ";
        font-size:1em;
        color: #c82609;
    }
    .enhancement-backup-box{
        margin-top: 12px;
        padding: 12px;
        border: 1px solid #e3e3e3;
        border-radius: 6px;
        background: #fafbff;
    }
    .enhancement-backup-actions{
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        margin-top: 8px;
    }
    .enhancement-backup-list{
        margin-top: 10px;
        padding-left: 18px;
    }
    .enhancement-backup-list li{
        margin-bottom: 8px;
    }
    .enhancement-backup-item-actions{
        display: inline-flex;
        gap: 6px;
        margin-left: 8px;
        vertical-align: middle;
    }
    .enhancement-backup-inline-btn{
        display: inline-block;
        padding: 2px 8px;
        font-size: 12px;
        line-height: 1.6;
        border: 1px solid #c9d3f5;
        border-radius: 4px;
        background: #fff;
        color: #334155;
        text-decoration: none;
        cursor: pointer;
    }
    .enhancement-backup-inline-btn:hover{
        background: #f1f5ff;
    }
    .enhancement-backup-inline-btn.danger{
        border-color: #f3c2c2;
        color: #b42318;
    }
    .enhancement-backup-inline-btn.danger:hover{
        background: #fff1f1;
    }
    .enhancement-action-row{
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
        margin-top: 6px;
    }
    .enhancement-action-btn{
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        height: 32px;
        line-height: 32px;
        padding: 0 14px;
        box-sizing: border-box;
        text-decoration: none !important;
        vertical-align: middle;
    }
    .enhancement-action-btn:hover,
    .enhancement-action-btn:focus{
        text-decoration: none !important;
    }
    .enhancement-action-note{
        color: #666;
        line-height: 1.6;
    }
    .enhancement-option-no-bullet,
    .enhancement-option-no-bullet li{
        list-style: none !important;
        margin: 0;
        padding: 0;
    }
    .enhancement-option-no-bullet .description{
        margin: 0;
    }
    .enhancement-option-no-bullet .description:before{
        content: none !important;
        display: none !important;
    }
</style>';
        echo '<div class="typecho-option" style="margin-top:12px;">
            <button type="button" class="btn enhancement-action-btn" id="enhancement-links-help-toggle" style="display:none;">帮助</button>
            <div id="enhancement-links-help" style="display:none; margin-top:10px;">
                <p>【管理】→【友情链接】进入审核页面。</p>
                <p>友链支持后台审核与前台提交。</p>
                <p>前台提交表单：</p>
                <p>前台可使用 <code>Enhancement_Plugin::publicForm()->render();</code> 输出提交表单。</p>
                <p>或自定义表单提交到 <code>/action/enhancement-submit</code>（需带安全 token）。</p>
                <p>文章内容可用标签 <code>&lt;links 0 sort 32&gt;SHOW_TEXT&lt;/links&gt;</code> 输出友链。</p>
                <p>模板可使用 <code>&lt;?php $this-&gt;enhancement(&quot;SHOW_TEXT&quot;, 0, null, 32); ?&gt;</code> 输出。</p>
                <p>仅审核通过（state=1）的友链会被输出。</p>
                <div style="margin-top:10px;">
                    <table>
                        <colgroup>
                            <col width="30%" />
                            <col width="70%" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>字段</th>
                                <th>对应数据</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="field">{url}</td>
                                <td>友链地址</td>
                            </tr>
                            <tr>
                                <td class="field">{title}<br />{description}</td>
                                <td>友链描述</td>
                            </tr>
                            <tr>
                                <td class="field">{name}</td>
                                <td>友链名称</td>
                            </tr>
                            <tr>
                                <td class="field">{image}</td>
                                <td>友链图片</td>
                            </tr>
                            <tr>
                                <td class="field">{size}</td>
                                <td>图片尺寸</td>
                            </tr>
                            <tr>
                                <td class="field">{sort}</td>
                                <td>友链分类</td>
                            </tr>
                            <tr>
                                <td class="field">{user}</td>
                                <td>自定义数据</td>
                            </tr>
                            <tr>
                                <td class="field">{lid}</td>
                                <td>链接的数据表ID</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:10px;">
                    <p>扩展功能：</p>
                    <p>评论同步：游客/登录用户评论时自动同步历史评论中的网址/昵称/邮箱。</p>
                    <p>标签助手：后台写文章时显示标签快捷选择列表。</p>
                    <p>Sitemap：访问 <code>/sitemap.xml</code>。</p>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var btn = document.getElementById("enhancement-links-help-toggle");
            var panel = document.getElementById("enhancement-links-help");
            if (!btn || !panel) return;
            btn.addEventListener("click", function () {
                panel.style.display = panel.style.display === "none" ? "block" : "none";
            });

            var inlineBtn = document.getElementById("enhancement-links-help-trigger-inline");
            if (inlineBtn) {
                inlineBtn.addEventListener("click", function () {
                    btn.click();
                    if (btn.scrollIntoView) {
                        btn.scrollIntoView({behavior: "smooth", block: "center"});
                    }
                });
            }
        })();
        </script>';
        $pattern_text = new Typecho_Widget_Helper_Form_Element_Textarea(
            'pattern_text',
            null,
            '<li><a href="{url}" title="{title}" target="_blank" rel="noopener">{name}</a></li>',
            _t('<h3 class="enhancement-title">友链输出设置</h3>SHOW_TEXT模式源码规则'),
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
            NULL,
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
            _t('<h3 class="enhancement-title">瞬间设置</h3>瞬间 API Token'),
            _t('用于 /api/v1/memos 发布瞬间（Authorization: Bearer <token>）')
        );
        $form->addInput($momentsToken->addRule('maxLength', _t('Token 最多100个字符'), 100));

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
            _t('<h3 class="enhancement-title">功能开关</h3>评论同步'),
            _t('同步游客/登录用户历史评论中的网址、昵称和邮箱')
        );
        $form->addInput($enableCommentSync);

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

        $enableTurnstile = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_turnstile',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('<h3 class="enhancement-title">安全设置</h3>Turnstile 人机验证'),
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

        $enableAvatarMirror = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_avatar_mirror',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('<h3 class="enhancement-title">头像设置</h3>头像镜像加速'),
            _t('启用后使用镜像地址加载邮箱头像，改善国内访问速度')
        );
        $form->addInput($enableAvatarMirror);

        $avatarMirrorUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'avatar_mirror_url',
            null,
            'https://cn.cravatar.com/avatar/',
            _t('镜像地址'),
            _t('示例：https://cn.cravatar.com/avatar/（需以 /avatar/ 结尾；禁用时将使用 Gravatar 官方地址）')
        );
        $form->addInput($avatarMirrorUrl->addRule('maxLength', _t('地址最多200个字符'), 200));

        $enableCommentByQQ = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_comment_by_qq',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('<h3 class="enhancement-title">QQ 通知设置</h3>QQ评论通知'),
            _t('评论通过时通过 QQ 机器人推送通知')
        );
        $form->addInput($enableCommentByQQ);

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

        $qqQueueStats = self::getQqNotifyQueueStats();
        $qqQueueRetryUrl = Helper::security()->getIndex('/action/enhancement-edit?do=qq-queue-retry');
        $qqQueueClearUrl = Helper::security()->getIndex('/action/enhancement-edit?do=qq-queue-clear');
        $qqQueueRow = new Typecho_Widget_Helper_Form_Element_Fake('qq_queue_row', null);
        $qqQueueRow->setAttribute('class', 'typecho-option enhancement-option-no-bullet');
        $qqQueueRow->input->setAttribute('type', 'hidden');
        $qqQueueRow->description(
            '<div class="enhancement-action-row">'
            . '<a class="btn enhancement-action-btn" href="' . htmlspecialchars($qqQueueRetryUrl, ENT_QUOTES, 'UTF-8') . '">' . _t('重试失败队列') . '</a>'
            . '<a class="btn enhancement-action-btn" href="' . htmlspecialchars($qqQueueClearUrl, ENT_QUOTES, 'UTF-8') . '" onclick="return window.confirm(\'确定要清空QQ通知队列吗？\');">' . _t('清空QQ队列') . '</a>'
            . '<span class="enhancement-action-note">' . _t('队列状态：待发送 %d / 失败 %d / 已发送 %d / 总计 %d',
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
            _t('<h3 class="enhancement-title">邮件提醒设置（SMTP）</h3>评论邮件提醒'),
            _t('评论通过/回复时发送邮件提醒')
        );
        $form->addInput($enableCommentNotifier);

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
            _t('避免重复通知站长邮箱')
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

        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);
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
            _t('<h3 class="enhancement-title">维护设置</h3>禁用插件时删除友情链接表（links）'),
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
        echo '<div class="typecho-option">'
            . '<h3 class="enhancement-title">设置备份</h3>'
            . '<div class="enhancement-backup-box">'
            . '<p style="margin:0;">备份本插件的设置内容,将直接保存到数据库。方便下次启用插件时快速恢复设置。</p>'
            . '<div class="enhancement-backup-actions">'
            . '<a class="btn enhancement-action-btn" href="' . htmlspecialchars($backupUrl, ENT_QUOTES, 'UTF-8') . '">' . _t('备份插件设置') . '</a>'
            . '</div>'
            . '</div>'
            . '</div>';

        $backupRows = self::listSettingsBackups(5);
        if (!empty($backupRows)) {
            echo '<div class="typecho-option">'
                . '<div class="enhancement-backup-box">'
                . '<p style="margin:0 0 8px;"><strong>' . _t('最近 5 条备份') . '</strong></p>'
                . '<ol class="enhancement-backup-list">';

            foreach ($backupRows as $row) {
                $backupName = isset($row['name']) ? trim((string)$row['name']) : '';
                if ($backupName === '') {
                    continue;
                }

                $timeText = $backupName;
                if (preg_match('/backup:(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})-/', $backupName, $matches)) {
                    $timeText = $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
                }

                $restoreByNameUrl = Helper::security()->getIndex('/action/enhancement-edit?do=restore-settings&backup_name=' . $backupName);
                $deleteByNameUrl = Helper::security()->getIndex('/action/enhancement-edit?do=delete-backup&backup_name=' . $backupName);

                echo '<li>'
                    . '<code>' . htmlspecialchars($timeText, ENT_QUOTES, 'UTF-8') . '</code>'
                    . '<span class="enhancement-backup-item-actions">'
                    . '<a class="enhancement-backup-inline-btn" href="' . htmlspecialchars($restoreByNameUrl, ENT_QUOTES, 'UTF-8') . '" onclick="return window.confirm(\'确定要恢复这份备份吗？当前设置将被覆盖。\');">' . _t('恢复此份') . '</a>'
                    . '<a class="enhancement-backup-inline-btn danger" href="' . htmlspecialchars($deleteByNameUrl, ENT_QUOTES, 'UTF-8') . '" onclick="return window.confirm(\'确定要删除这份备份吗？\');">' . _t('删除') . '</a>'
                    . '</span>'
                    . '</li>';
            }

            echo '</ol>'
                . '</div>'
                . '</div>';
        }

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

    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function enhancementInstall()
    {
        $installDb = Typecho_Db::get();
        $type = explode('_', $installDb->getAdapterName());
        $type = array_pop($type);
        $prefix = $installDb->getPrefix();
        $scripts = file_get_contents('usr/plugins/Enhancement/sql/' . $type . '.sql');
        $scripts = str_replace('typecho_', $prefix, $scripts);
        $scripts = str_replace('%charset%', 'utf8', $scripts);
        $scripts = explode(';', $scripts);
        try {
            foreach ($scripts as $script) {
                $script = trim($script);
                if ($script) {
                    $installDb->query($script, Typecho_Db::WRITE);
                }
            }
            return _t('建立 links/moments 数据表，插件启用成功');
        } catch (Typecho_Db_Exception $e) {
            $code = $e->getCode();
            if (('Mysql' == $type && (1050 == $code || '42S01' == $code)) ||
                ('SQLite' == $type && ('HY000' == $code || 1 == $code)) ||
                ('Pgsql' == $type && '42P07' == $code)
            ) {
                try {
                    $script = 'SELECT `lid`, `name`, `url`, `sort`, `email`, `image`, `description`, `user`, `state`, `order` from `' . $prefix . 'links`';
                    $installDb->query($script, Typecho_Db::READ);
                    return _t('检测到 links/moments 数据表，插件启用成功');
                } catch (Typecho_Db_Exception $e) {
                    $code = $e->getCode();
                    throw new Typecho_Plugin_Exception(_t('数据表检测失败，插件启用失败。错误号：') . $code);
                }
            } else {
                throw new Typecho_Plugin_Exception(_t('数据表建立失败，插件启用失败。错误号：') . $code);
            }
        }
    }

    public static function form($action = null)
    {
        /** 构建表格 */
        $form = new Typecho_Widget_Helper_Form(
            Helper::security()->getIndex('/action/enhancement-edit'),
            Typecho_Widget_Helper_Form::POST_METHOD
        );

        /** 友链名称 */
        $name = new Typecho_Widget_Helper_Form_Element_Text('name', null, null, _t('友链名称*'));
        $form->addInput($name);

        /** 友链地址 */
        $url = new Typecho_Widget_Helper_Form_Element_Text('url', null, "http://", _t('友链地址*'));
        $form->addInput($url);

        /** 友链分类 */
        $sort = new Typecho_Widget_Helper_Form_Element_Text('sort', null, null, _t('友链分类'), _t('建议以英文字母开头，只包含字母与数字'));
        $form->addInput($sort);

        /** 友链邮箱 */
        $email = new Typecho_Widget_Helper_Form_Element_Text('email', null, null, _t('友链邮箱'), _t('填写友链邮箱'));
        $form->addInput($email);

        /** 友链图片 */
        $image = new Typecho_Widget_Helper_Form_Element_Text('image', null, null, _t('友链图片'),  _t('需要以http://或https://开头，留空表示没有友链图片'));
        $form->addInput($image);

        /** 友链描述 */
        $description =  new Typecho_Widget_Helper_Form_Element_Textarea('description', null, null, _t('友链描述'));
        $form->addInput($description);

        /** 自定义数据 */
        $user = new Typecho_Widget_Helper_Form_Element_Text('user', null, null, _t('自定义数据'), _t('该项用于用户自定义数据扩展'));
        $form->addInput($user);

        /** 审核状态 */
        $list = array('0' => '待审核', '1' => '已通过');
        $state = new Typecho_Widget_Helper_Form_Element_Radio('state', $list, '1', '审核状态');
        $form->addInput($state);

        /** 动作 */
        $do = new Typecho_Widget_Helper_Form_Element_Hidden('do');
        $form->addInput($do);

        /** 主键 */
        $lid = new Typecho_Widget_Helper_Form_Element_Hidden('lid');
        $form->addInput($lid);

        /** 提交按钮 */
        $submit = new Typecho_Widget_Helper_Form_Element_Submit();
        $submit->input->setAttribute('class', 'btn primary');
        $form->addItem($submit);
        $request = Typecho_Request::getInstance();

        if (isset($request->lid) && 'insert' != $action) {
            /** 更新模式 */
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $item = $db->fetchRow($db->select()->from($prefix . 'links')->where('lid = ?', $request->lid));
            if (!$item) {
                throw new Typecho_Widget_Exception(_t('记录不存在'), 404);
            }

            $name->value($item['name']);
            $url->value($item['url']);
            $sort->value($item['sort']);
            $email->value($item['email']);
            $image->value($item['image']);
            $description->value($item['description']);
            $user->value($item['user']);
            $state->value($item['state']);
            $do->value('update');
            $lid->value($item['lid']);
            $submit->value(_t('编辑记录'));
            $_action = 'update';
        } else {
            $do->value('insert');
            $submit->value(_t('增加记录'));
            $_action = 'insert';
        }

        if (empty($action)) {
            $action = $_action;
        }

        /** 给表单增加规则 */
        if ('insert' == $action || 'update' == $action) {
            $name->addRule('required', _t('必须填写友链名称'));
            $url->addRule('required', _t('必须填写友链地址'));
            $url->addRule('url', _t('不是一个合法的链接地址'));
            $url->addRule(array('Enhancement_Plugin', 'validateHttpUrl'), _t('友链地址仅支持 http:// 或 https://'));
            $email->addRule('email', _t('不是一个合法的邮箱地址'));
            $image->addRule('url', _t('不是一个合法的图片地址'));
            $image->addRule(array('Enhancement_Plugin', 'validateOptionalHttpUrl'), _t('友链图片仅支持 http:// 或 https://'));
            $name->addRule('maxLength', _t('友链名称最多包含50个字符'), 50);
            $url->addRule('maxLength', _t('友链地址最多包含200个字符'), 200);
            $sort->addRule('maxLength', _t('友链分类最多包含50个字符'), 50);
            $email->addRule('maxLength', _t('友链邮箱最多包含50个字符'), 50);
            $image->addRule('maxLength', _t('友链图片最多包含200个字符'), 200);
            $description->addRule('maxLength', _t('友链描述最多包含200个字符'), 200);
            $user->addRule('maxLength', _t('自定义数据最多包含200个字符'), 200);
        }
        if ('update' == $action) {
            $lid->addRule('required', _t('记录主键不存在'));
            $lid->addRule(array(new Enhancement_Plugin, 'enhancementExists'), _t('记录不存在'));
        }
        return $form;
    }

    public static function publicForm()
    {
        $form = new Typecho_Widget_Helper_Form(
            Helper::security()->getIndex('/action/enhancement-submit'),
            Typecho_Widget_Helper_Form::POST_METHOD
        );
        $form->setAttribute('class', 'enhancement-public-form');
        $form->setAttribute('data-enhancement-form', 'link-submit');

        $name = new Typecho_Widget_Helper_Form_Element_Text('name', null, null, _t('友链名称*'));
        $form->addInput($name);

        $url = new Typecho_Widget_Helper_Form_Element_Text('url', null, "http://", _t('友链地址*'));
        $form->addInput($url);

        $sort = new Typecho_Widget_Helper_Form_Element_Text('sort', null, null, _t('友链分类'), _t('建议以英文字母开头，只包含字母与数字'));
        $form->addInput($sort);

        $email = new Typecho_Widget_Helper_Form_Element_Text('email', null, null, _t('友链邮箱'), _t('填写友链邮箱'));
        $form->addInput($email);

        $image = new Typecho_Widget_Helper_Form_Element_Text('image', null, null, _t('友链图片'),  _t('需要以http://或https://开头，留空表示没有友链图片'));
        $form->addInput($image);

        $description =  new Typecho_Widget_Helper_Form_Element_Textarea('description', null, null, _t('友链描述'));
        $form->addInput($description);

        $user = new Typecho_Widget_Helper_Form_Element_Text('user', null, null, _t('自定义数据'), _t('该项用于用户自定义数据扩展'));
        $form->addInput($user);

        $honeypot = new Typecho_Widget_Helper_Form_Element_Text('homepage', null, '', _t('网站'), _t('请勿填写此字段'));
        $honeypot->setAttribute('class', 'hidden');
        $honeypot->input->setAttribute('style', 'display:none !important;');
        $honeypot->input->setAttribute('tabindex', '-1');
        $honeypot->input->setAttribute('autocomplete', 'off');
        $form->addInput($honeypot);

        $do = new Typecho_Widget_Helper_Form_Element_Hidden('do');
        $do->value('submit');
        $form->addInput($do);

        $submit = new Typecho_Widget_Helper_Form_Element_Submit();
        $submit->input->setAttribute('class', 'btn primary');
        $submit->value(_t('提交申请'));
        $form->addItem($submit);

        $name->addRule('required', _t('必须填写友链名称'));
        $url->addRule('required', _t('必须填写友链地址'));
        $url->addRule('url', _t('不是一个合法的链接地址'));
        $url->addRule(array('Enhancement_Plugin', 'validateHttpUrl'), _t('友链地址仅支持 http:// 或 https://'));
        $email->addRule('email', _t('不是一个合法的邮箱地址'));
        $image->addRule('url', _t('不是一个合法的图片地址'));
        $image->addRule(array('Enhancement_Plugin', 'validateOptionalHttpUrl'), _t('友链图片仅支持 http:// 或 https://'));
        $name->addRule('maxLength', _t('友链名称最多包含50个字符'), 50);
        $url->addRule('maxLength', _t('友链地址最多包含200个字符'), 200);
        $sort->addRule('maxLength', _t('友链分类最多包含50个字符'), 50);
        $email->addRule('maxLength', _t('友链邮箱最多包含50个字符'), 50);
        $image->addRule('maxLength', _t('友链图片最多包含200个字符'), 200);
        $description->addRule('maxLength', _t('友链描述最多包含200个字符'), 200);
        $user->addRule('maxLength', _t('自定义数据最多包含200个字符'), 200);

        return $form;
    }

    public static function momentsForm($action = null)
    {
        $form = new Typecho_Widget_Helper_Form(
            Helper::security()->getIndex('/action/enhancement-moments-edit'),
            Typecho_Widget_Helper_Form::POST_METHOD
        );

        $content = new Typecho_Widget_Helper_Form_Element_Textarea('content', null, null, _t('内容*'));
        $form->addInput($content);

        $tags = new Typecho_Widget_Helper_Form_Element_Text('tags', null, null, _t('标签'), _t('可填逗号分隔或 JSON 数组'));
        $form->addInput($tags);

        $do = new Typecho_Widget_Helper_Form_Element_Hidden('do');
        $form->addInput($do);

        $mid = new Typecho_Widget_Helper_Form_Element_Hidden('mid');
        $form->addInput($mid);

        $submit = new Typecho_Widget_Helper_Form_Element_Submit();
        $submit->input->setAttribute('class', 'btn primary');
        $form->addItem($submit);

        $request = Typecho_Request::getInstance();

        if (isset($request->mid) && 'insert' != $action) {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $item = $db->fetchRow($db->select()->from($prefix . 'moments')->where('mid = ?', $request->mid));
            if (!$item) {
                throw new Typecho_Widget_Exception(_t('记录不存在'), 404);
            }

            $content->value($item['content']);
            $tags->value($item['tags']);
            $do->value('update');
            $mid->value($item['mid']);
            $submit->value(_t('编辑瞬间'));
            $_action = 'update';
        } else {
            $do->value('insert');
            $submit->value(_t('发布瞬间'));
            $_action = 'insert';
        }

        if (empty($action)) {
            $action = $_action;
        }

        if ('insert' == $action || 'update' == $action) {
            $content->addRule('required', _t('必须填写内容'));
            $tags->addRule('maxLength', _t('标签最多包含200个字符'), 200);
        }
        if ('update' == $action) {
            $mid->addRule('required', _t('记录主键不存在'));
            $mid->addRule(array(new Enhancement_Plugin, 'momentsExists'), _t('记录不存在'));
        }

        return $form;
    }

    public static function enhancementExists($lid)
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $item = $db->fetchRow($db->select()->from($prefix . 'links')->where('lid = ?', $lid)->limit(1));
        return $item ? true : false;
    }

    public static function momentsExists($mid)
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $item = $db->fetchRow($db->select()->from($prefix . 'moments')->where('mid = ?', $mid)->limit(1));
        return $item ? true : false;
    }

    public static function validateHttpUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return false;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, array('http', 'https'), true);
    }

    public static function validateOptionalHttpUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return true;
        }
        return self::validateHttpUrl($url);
    }

    public static function extractMediaFromContent($content, &$cleanedContent = null)
    {
        if (!is_string($content) || $content === '') {
            $cleanedContent = is_string($content) ? $content : '';
            return array();
        }

        $cleanedContent = $content;
        $media = array();
        $seen = array();

        $addUrl = function ($url) use (&$media, &$seen) {
            $url = trim((string)$url);
            if ($url === '' || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;

            $path = parse_url($url, PHP_URL_PATH);
            $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
            $type = in_array($ext, array('mp4', 'webm', 'ogg', 'm4v', 'mov'), true) ? 'VIDEO' : 'PHOTO';

            $media[] = array(
                'type' => $type,
                'url' => $url
            );
        };

        if (preg_match_all('/!\\[[^\\]]*\\]\\(([^)]+)\\)/i', $content, $matches)) {
            foreach ($matches[1] as $raw) {
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }
                if ($raw[0] === '<' && substr($raw, -1) === '>') {
                    $raw = substr($raw, 1, -1);
                }
                $parts = preg_split('/\\s+/', $raw);
                $url = trim($parts[0], "\"'");
                $addUrl($url);
            }
            $cleanedContent = preg_replace('/!\\[[^\\]]*\\]\\(([^)]+)\\)/i', '', $cleanedContent);
        }

        if (preg_match_all('/<img[^>]+src=[\'"]?([^\'"\\s>]+)[\'"]?/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $addUrl($url);
            }
            $cleanedContent = preg_replace('/<img[^>]*>/i', '', $cleanedContent);
        }

        if (preg_match_all('/<video[^>]+src=[\'"]?([^\'"\\s>]+)[\'"]?/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $addUrl($url);
            }
        }

        if (preg_match_all('/<source[^>]+src=[\'"]?([^\'"\\s>]+)[\'"]?/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $addUrl($url);
            }
        }

        if (is_string($cleanedContent)) {
            $cleanedContent = str_replace(array("\r\n", "\r"), "\n", $cleanedContent);
            $cleanedContent = preg_replace("/[ \\t]+\\n/", "\n", $cleanedContent);
            $cleanedContent = preg_replace("/\\n{3,}/", "\n\n", $cleanedContent);
            $cleanedContent = trim($cleanedContent);
            if ($cleanedContent === '' && !empty($media)) {
                $options = Typecho_Widget::widget('Widget_Options');
                $settings = self::pluginSettings($options);
                $fallback = isset($settings->moments_image_text) ? trim((string)$settings->moments_image_text) : '';
                if ($fallback === '') {
                    $fallback = '图片';
                }
                $cleanedContent = $fallback;
            }
        }

        return $media;
    }

    public static function normalizeMomentSource($source, $default = 'web')
    {
        $allowed = array('web', 'mobile', 'api');
        $source = strtolower(trim((string)$source));
        if (!in_array($source, $allowed, true)) {
            $source = strtolower(trim((string)$default));
            if (!in_array($source, $allowed, true)) {
                $source = 'web';
            }
        }

        return $source;
    }

    public static function detectMomentSourceByUserAgent($userAgent = null)
    {
        if ($userAgent === null) {
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
        }

        $userAgent = strtolower(trim((string)$userAgent));
        if ($userAgent === '') {
            return 'web';
        }

        if (preg_match('/mobile|android|iphone|ipad|ipod|windows phone|mobi/i', $userAgent)) {
            return 'mobile';
        }

        return 'web';
    }

    public static function ensureMomentsSourceColumn()
    {
        $db = Typecho_Db::get();
        $type = explode('_', $db->getAdapterName());
        $type = array_pop($type);
        $prefix = $db->getPrefix();
        $table = $prefix . 'moments';

        try {
            if ('Mysql' === $type) {
                $row = $db->fetchRow('SHOW COLUMNS FROM `' . $table . '` LIKE \'source\'');
                if (!is_array($row) || empty($row)) {
                    $db->query('ALTER TABLE `' . $table . '` ADD COLUMN `source` varchar(20) DEFAULT \'web\' AFTER `media`', Typecho_Db::WRITE);
                }
                return;
            }

            if ('Pgsql' === $type) {
                $row = $db->fetchRow(
                    $db->select('column_name')
                        ->from('information_schema.columns')
                        ->where('table_name = ?', $table)
                        ->where('column_name = ?', 'source')
                        ->limit(1)
                );
                if (!is_array($row) || empty($row)) {
                    $db->query('ALTER TABLE "' . $table . '" ADD COLUMN "source" varchar(20) DEFAULT \'web\'', Typecho_Db::WRITE);
                }
                return;
            }

            if ('SQLite' === $type) {
                $rows = $db->fetchAll('PRAGMA table_info(`' . $table . '`)');
                $hasSource = false;
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $name = isset($row['name']) ? strtolower((string)$row['name']) : '';
                        if ($name === 'source') {
                            $hasSource = true;
                            break;
                        }
                    }
                }
                if (!$hasSource) {
                    $db->query('ALTER TABLE `' . $table . '` ADD COLUMN `source` varchar(20) DEFAULT \'web\'', Typecho_Db::WRITE);
                }
                return;
            }
        } catch (Exception $e) {
            // ignore migration errors to avoid blocking runtime
        }
    }

    public static function ensureMomentsTable()
    {
        $db = Typecho_Db::get();
        $type = explode('_', $db->getAdapterName());
        $type = array_pop($type);
        $prefix = $db->getPrefix();

        $scripts = @file_get_contents('usr/plugins/Enhancement/sql/' . $type . '.sql');
        if (!$scripts) {
            return;
        }
        $scripts = str_replace('typecho_', $prefix, $scripts);
        $scripts = str_replace('%charset%', 'utf8', $scripts);
        $scripts = explode(';', $scripts);

        foreach ($scripts as $script) {
            $script = trim($script);
            if ($script && stripos($script, $prefix . 'moments') !== false) {
                try {
                    $db->query($script, Typecho_Db::WRITE);
                } catch (Exception $e) {
                    // ignore create errors
                }
            }
        }

        self::ensureMomentsSourceColumn();
    }

    public static function turnstileEnabled(): bool
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        return isset($settings->enable_turnstile) && $settings->enable_turnstile == '1';
    }

    public static function turnstileSiteKey(): string
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        return isset($settings->turnstile_site_key) ? trim((string)$settings->turnstile_site_key) : '';
    }

    public static function turnstileSecretKey(): string
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        return isset($settings->turnstile_secret_key) ? trim((string)$settings->turnstile_secret_key) : '';
    }

    public static function turnstileReady(): bool
    {
        return self::turnstileEnabled() && self::turnstileSiteKey() !== '' && self::turnstileSecretKey() !== '';
    }

    public static function turnstileCommentGuestOnly(): bool
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        if (!isset($settings->turnstile_comment_guest_only)) {
            return true;
        }
        return $settings->turnstile_comment_guest_only == '1';
    }

    public static function turnstileVerify($token, $remoteIp = ''): array
    {
        if (!self::turnstileEnabled()) {
            return array('success' => true, 'message' => 'disabled');
        }

        $siteKey = self::turnstileSiteKey();
        $secret = self::turnstileSecretKey();
        if ($siteKey === '' || $secret === '') {
            return array('success' => false, 'message' => _t('Turnstile 未配置完整（缺少 Site Key 或 Secret Key）'));
        }

        $token = trim((string)$token);
        if ($token === '') {
            return array('success' => false, 'message' => _t('请完成人机验证后再提交'));
        }

        $postFields = array(
            'secret' => $secret,
            'response' => $token
        );
        $remoteIp = trim((string)$remoteIp);
        if ($remoteIp !== '') {
            $postFields['remoteip'] = $remoteIp;
        }

        $ch = function_exists('curl_init') ? curl_init() : null;
        if (!$ch) {
            return array('success' => false, 'message' => _t('当前环境不支持 Turnstile 校验（缺少 cURL）'));
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded'
            )
        ));

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return array('success' => false, 'message' => _t('人机验证请求失败：%s', $error));
        }
        curl_close($ch);

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            return array('success' => false, 'message' => _t('人机验证返回数据异常'));
        }

        if (!empty($decoded['success'])) {
            return array('success' => true, 'message' => 'ok');
        }

        $codes = array();
        if (isset($decoded['error-codes']) && is_array($decoded['error-codes'])) {
            $codes = $decoded['error-codes'];
        }
        $codeText = !empty($codes) ? implode(', ', $codes) : 'unknown_error';
        return array('success' => false, 'message' => _t('人机验证失败：%s', $codeText));
    }

    public static function turnstileRenderBlock($formId = ''): string
    {
        if (!self::turnstileReady()) {
            return '';
        }

        $formId = trim((string)$formId);
        $formIdAttr = $formId !== '' ? ' data-form-id="' . htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') . '"' : '';
        $siteKey = htmlspecialchars(self::turnstileSiteKey(), ENT_QUOTES, 'UTF-8');

        return '<div class="typecho-option enhancement-turnstile"' . $formIdAttr . '>'
            . '<div class="cf-turnstile" data-sitekey="' . $siteKey . '"></div>'
            . '</div>';
    }

    public static function turnstileFooter($archive = null)
    {
        if (!($archive instanceof Widget_Archive) || !$archive->is('single')) {
            return;
        }

        self::renderCommentAuthorLinkEnhancer($archive);

        if (!self::turnstileReady()) {
            return;
        }

        $siteKey = htmlspecialchars(self::turnstileSiteKey(), ENT_QUOTES, 'UTF-8');
        $commentNeedCaptcha = true;
        if (self::turnstileCommentGuestOnly()) {
            $user = Typecho_Widget::widget('Widget_User');
            $commentNeedCaptcha = !$user->hasLogin();
        }
        $selectorParts = array('form.enhancement-public-form');
        if ($commentNeedCaptcha) {
            $selectorParts[] = 'form[action*="/comment"]';
        }
        $selector = implode(', ', $selectorParts);

        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        echo '<script>(function(){'
            . 'var siteKey=' . json_encode($siteKey) . ';'
            . 'var selector=' . json_encode($selector) . ';'
            . 'var forms=document.querySelectorAll(selector);'
            . 'for(var i=0;i<forms.length;i++){' 
            . 'var form=forms[i];'
            . 'if(form.querySelector(".cf-turnstile")){continue;}'
            . 'var holder=document.createElement("div");'
            . 'holder.className="typecho-option enhancement-turnstile";'
            . 'var widget=document.createElement("div");'
            . 'widget.className="cf-turnstile";'
            . 'widget.setAttribute("data-sitekey", siteKey);'
            . 'holder.appendChild(widget);'
            . 'var submit=form.querySelector("button[type=submit], input[type=submit]");'
            . 'if(submit){'
            . 'var wrap=submit.closest?submit.closest("p,div"):null;'
            . 'if(wrap && wrap.parentNode===form){form.insertBefore(holder, wrap);}'
            . 'else if(submit.parentNode){submit.parentNode.insertBefore(holder, submit);}'
            . 'else{form.appendChild(holder);}'
            . '}else{form.appendChild(holder);}'
            . '}'
            . 'if(window.turnstile && window.turnstile.render){'
            . 'var els=document.querySelectorAll(".cf-turnstile");'
            . 'for(var j=0;j<els.length;j++){' 
            . 'if(!els[j].hasAttribute("data-widget-id")){' 
            . 'var id=window.turnstile.render(els[j]);'
            . 'if(id){els[j].setAttribute("data-widget-id", id);}'
            . '}'
            . '}'
            . '}'
            . '})();</script>';
    }

    private static function renderCommentAuthorLinkEnhancer($archive = null)
    {
        if (!($archive instanceof Widget_Archive) || !$archive->is('single')) {
            return;
        }

        $enableBlankTarget = self::blankTargetEnabled();
        $enableGoRedirect = self::goRedirectEnabled();
        if (!$enableBlankTarget && !$enableGoRedirect) {
            return;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $siteHost = self::normalizeHost(parse_url((string)$options->siteUrl, PHP_URL_HOST));
        $goBase = Typecho_Common::url('go/', $options->index);
        $goPath = (string)parse_url($goBase, PHP_URL_PATH);
        $goPath = '/' . ltrim($goPath, '/');
        $whitelist = array_values(self::parseGoRedirectWhitelist());

        echo '<script>(function(){'
            . 'var enableBlank=' . json_encode($enableBlankTarget) . ';'
            . 'var enableGo=' . json_encode($enableGoRedirect) . ';'
            . 'var siteHost=' . json_encode($siteHost) . ';'
            . 'var goBase=' . json_encode($goBase) . ';'
            . 'var goPath=' . json_encode($goPath) . ';'
            . 'var whitelist=' . json_encode($whitelist) . ';'
            . 'var links=document.querySelectorAll("#comments .comment-author a[href], #comments .comment__author-name a[href], .comment-author a[href], .comment__author-name a[href], .comment-meta .comment-author a[href], .vcard a[href]");'
            . 'if(!links||!links.length){return;}'
            . 'function normalizeHost(host){host=(host||"").toLowerCase().trim();if(host.indexOf("www.")==0){host=host.slice(4);}return host;}'
            . 'function isWhitelisted(host){if(!host){return false;}host=normalizeHost(host);for(var i=0;i<whitelist.length;i++){var domain=normalizeHost(whitelist[i]);if(!domain){continue;}if(host===domain){return true;}if(host.length>domain.length&&host.slice(-1*(domain.length+1))==="."+domain){return true;}}return false;}'
            . 'function isGoHref(url){if(!url){return false;}if(goBase&&url.indexOf(goBase)===0){return true;}try{var parsed=new URL(url,window.location.href);if(!goPath||goPath==="/"){return false;}var path="/"+(parsed.pathname||"").replace(/^\/+/,"");var normalizedGoPath="/"+String(goPath).replace(/^\/+/,"");return path.indexOf(normalizedGoPath)===0;}catch(e){return false;}}'
            . 'function toBase64Url(input){try{var utf8=unescape(encodeURIComponent(input));var b64=btoa(utf8);return b64.replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/g,"");}catch(e){return "";}}'
            . 'for(var i=0;i<links.length;i++){' 
            . 'var link=links[i];'
            . 'var href=(link.getAttribute("href")||"").trim();'
            . 'if(!href){continue;}'
            . 'if(enableGo&&!isGoHref(href)){' 
            . 'try{'
            . 'var lower=href.toLowerCase();'
            . 'if(lower.indexOf("mailto:")!==0&&lower.indexOf("tel:")!==0&&lower.indexOf("javascript:")!==0&&lower.indexOf("data:")!==0&&href.indexOf("#")!==0&&href.indexOf("/")!==0&&href.indexOf("?")!==0){'
            . 'var parsed=new URL(href,window.location.href);'
            . 'var protocol=(parsed.protocol||"").toLowerCase();'
            . 'var host=normalizeHost(parsed.hostname||"");'
            . 'if((protocol==="http:"||protocol==="https:")&&host&&host!==normalizeHost(siteHost)&&!isWhitelisted(host)){'
            . 'var normalized=parsed.href;'
            . 'var token=toBase64Url(normalized);'
            . 'if(token){link.setAttribute("href", String(goBase||"")+token);href=link.getAttribute("href")||href;}'
            . '}'
            . '}'
            . '}catch(e){}'
            . '}'
            . 'if(enableBlank){'
            . 'link.setAttribute("target","_blank");'
            . 'var rel=(link.getAttribute("rel")||"").toLowerCase().trim();'
            . 'var rels=rel?rel.split(/\s+/):[];'
            . 'if(rels.indexOf("noopener")<0){rels.push("noopener");}'
            . 'if(rels.indexOf("noreferrer")<0){rels.push("noreferrer");}'
            . 'link.setAttribute("rel",rels.join(" ").trim());'
            . '}'
            . '}'
            . '})();</script>';
    }

    public static function turnstileFilterComment($comment, $post, $last)
    {
        $current = empty($last) ? $comment : $last;
        if (!self::turnstileEnabled()) {
            return $current;
        }

        if (self::turnstileCommentGuestOnly()) {
            $user = Typecho_Widget::widget('Widget_User');
            if ($user->hasLogin()) {
                return $current;
            }
        }

        $token = Typecho_Request::getInstance()->get('cf-turnstile-response');
        $verify = self::turnstileVerify($token, Typecho_Request::getInstance()->getIp());
        if (empty($verify['success'])) {
            Typecho_Cookie::set('__typecho_remember_text', isset($current['text']) ? (string)$current['text'] : '');
            throw new Typecho_Widget_Exception(isset($verify['message']) ? $verify['message'] : _t('人机验证失败'));
        }

        return $current;
    }

    public static function finishComment($comment)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);
        $user = Typecho_Widget::widget('Widget_User');
        $commentUrl = isset($comment->url) ? trim((string)$comment->url) : '';

        if (!isset($settings->enable_comment_sync) || $settings->enable_comment_sync == '1') {
            $db = Typecho_Db::get();

            if (!$user->hasLogin()) {
                if (!empty($commentUrl)) {
                    $update = $db->update('table.comments')
                        ->rows(array('url' => $commentUrl))
                        ->where('ip =? and mail =? and authorId =?', $comment->ip, $comment->mail, '0');
                    $db->query($update);
                }
            } else {
                $userUrl = isset($user->url) ? trim((string)$user->url) : '';
                $update = $db->update('table.comments')
                    ->rows(array('url' => $userUrl, 'mail' => $user->mail, 'author' => $user->screenName))
                    ->where('authorId =?', $user->uid);
                $db->query($update);
            }
        }

        if (isset($settings->enable_comment_by_qq) && $settings->enable_comment_by_qq == '1') {
            self::commentByQQ($comment);
        }

        if (isset($settings->enable_comment_notifier) && $settings->enable_comment_notifier == '1') {
            self::commentNotifierRefinishComment($comment);
        }

        return $comment;
    }

    public static function commentByQQMark($comment, $edit, $status)
    {
        $status = trim((string)$status);
        if ($status !== 'approved') {
            return;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);
        if (!isset($settings->enable_comment_by_qq) || $settings->enable_comment_by_qq != '1') {
            return;
        }

        self::commentByQQ($edit, 'approved');
    }

    public static function commentByQQ($comment, $statusOverride = null)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);

        $status = $statusOverride !== null
            ? trim((string)$statusOverride)
            : (isset($comment->status) ? trim((string)$comment->status) : '');
        if ($status !== 'approved') {
            return;
        }

        if ($comment->authorId === $comment->ownerId) {
            return;
        }

        $apiUrl = isset($settings->qqboturl) ? trim((string)$settings->qqboturl) : '';
        $qqNum = isset($settings->qq) ? trim((string)$settings->qq) : '';

        if ($apiUrl === '' || $qqNum === '') {
            return;
        }

        $commentText = '';
        if (isset($comment->text)) {
            $commentText = $comment->text;
        } elseif (isset($comment->content)) {
            $commentText = $comment->content;
        }
        $commentText = strip_tags((string)$commentText);

        $message = sprintf(
            "【新评论通知】\n"
            . "📝 评论者：%s\n"
            . "📖 文章标题：《%s》\n"
            . "💬 评论内容：%s\n"
            . "🔗 文章链接：%s",
            $comment->author,
            $comment->title,
            $commentText,
            $comment->permalink
        );

        if (self::commentByQQAsyncQueueEnabled($settings)) {
            self::enqueueCommentByQQ((string)$message);
            return;
        }

        $payload = array(
            'user_id' => (int)$qqNum,
            'message' => $message
        );

        if (!function_exists('curl_init')) {
            return;
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return;
        }

        $endpoint = rtrim($apiUrl, '/') . '/send_msg';
        $lastErrorNo = 0;
        $lastError = '';
        $lastHttpCode = 0;
        $lastResponse = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init();
            $curlOptions = array(
                CURLOPT_URL => $endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json; charset=UTF-8',
                    'Accept: application/json'
                ),
                CURLOPT_SSL_VERIFYPEER => false
            );

            if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
                $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
            if (defined('CURLOPT_NOSIGNAL')) {
                $curlOptions[CURLOPT_NOSIGNAL] = true;
            }

            curl_setopt_array($ch, $curlOptions);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = $errno ? curl_error($ch) : '';
            curl_close($ch);

            if ($errno === 0 && $httpCode >= 200 && $httpCode < 300) {
                return;
            }

            $lastErrorNo = $errno;
            $lastError = $error;
            $lastHttpCode = $httpCode;
            $lastResponse = substr((string) $response, 0, 200);

            if ($errno === CURLE_OPERATION_TIMEDOUT && $attempt < 2) {
                usleep(150000);
                continue;
            }
            break;
        }

        if ($lastErrorNo !== 0) {
            if ($lastErrorNo !== CURLE_OPERATION_TIMEDOUT) {
                error_log('[Enhancement][CommentsByQQ] CURL错误: ' . $lastError);
            }
            return;
        }

        if ($lastHttpCode >= 400) {
            error_log(sprintf('[Enhancement][CommentsByQQ] 响应异常 [HTTP %d]: %s', $lastHttpCode, $lastResponse));
        }
    }

    private static function commentByQQAsyncQueueEnabled($settings = null): bool
    {
        if ($settings === null) {
            $options = Typecho_Widget::widget('Widget_Options');
            $settings = self::pluginSettings($options);
        }

        if (!isset($settings->qq_async_queue)) {
            return true;
        }

        return $settings->qq_async_queue == '1';
    }

    private static function enqueueCommentByQQ(string $message)
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        self::ensureQqNotifyQueueTable();

        try {
            $db = Typecho_Db::get();
            $table = $db->getPrefix() . 'qq_notify_queue';
            $db->query(
                $db->insert($table)->rows(array(
                    'message' => $message,
                    'status' => 0,
                    'retries' => 0,
                    'last_error' => null,
                    'created' => time(),
                    'updated' => time()
                ))
            );
        } catch (Exception $e) {
            self::sendCommentByQQMessage($message, false);
        }
    }

    private static function processQqNotifyQueue()
    {
        static $processed = false;
        if ($processed) {
            return;
        }
        $processed = true;

        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);
        if (!isset($settings->enable_comment_by_qq) || $settings->enable_comment_by_qq != '1') {
            return;
        }
        if (!self::commentByQQAsyncQueueEnabled($settings)) {
            return;
        }

        self::ensureQqNotifyQueueTable();

        try {
            $db = Typecho_Db::get();
            $table = $db->getPrefix() . 'qq_notify_queue';
            $row = $db->fetchRow(
                $db->select()
                    ->from($table)
                    ->where('status = ?', 0)
                    ->where('retries < ?', 5)
                    ->order('qid', Typecho_Db::SORT_ASC)
                    ->limit(1)
            );

            if (!is_array($row) || empty($row)) {
                return;
            }

            $qid = isset($row['qid']) ? intval($row['qid']) : 0;
            $message = isset($row['message']) ? (string)$row['message'] : '';
            $retries = isset($row['retries']) ? intval($row['retries']) : 0;
            if ($qid <= 0 || trim($message) === '') {
                return;
            }

            $result = self::sendCommentByQQMessage($message, true);
            if (!empty($result['success'])) {
                $db->query(
                    $db->update($table)
                        ->rows(array(
                            'status' => 1,
                            'updated' => time(),
                            'last_error' => null
                        ))
                        ->where('qid = ?', $qid)
                );
                return;
            }

            $retries++;
            $error = isset($result['error']) ? trim((string)$result['error']) : '';
            if ($error === '') {
                $error = 'send failed';
            }

            $db->query(
                $db->update($table)
                    ->rows(array(
                        'status' => ($retries >= 5 ? 2 : 0),
                        'retries' => $retries,
                        'updated' => time(),
                        'last_error' => Typecho_Common::subStr($error, 0, 250, '')
                    ))
                    ->where('qid = ?', $qid)
            );
        } catch (Exception $e) {
            // ignore queue errors
        }
    }

    private static function ensureQqNotifyQueueTable()
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        $db = Typecho_Db::get();
        $type = explode('_', $db->getAdapterName());
        $type = array_pop($type);
        $prefix = $db->getPrefix();

        try {
            if ('Pgsql' == $type) {
                $db->query(
                    'CREATE TABLE IF NOT EXISTS "' . $prefix . 'qq_notify_queue" ('
                    . '"qid" serial PRIMARY KEY,'
                    . '"message" text NOT NULL,'
                    . '"status" integer DEFAULT 0,'
                    . '"retries" integer DEFAULT 0,'
                    . '"last_error" varchar(255),'
                    . '"created" integer DEFAULT 0,'
                    . '"updated" integer DEFAULT 0'
                    . ')',
                    Typecho_Db::WRITE
                );
                return;
            }

            if ('Mysql' == $type) {
                $db->query(
                    'CREATE TABLE IF NOT EXISTS `' . $prefix . 'qq_notify_queue` ('
                    . '`qid` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . '`message` text NOT NULL,'
                    . '`status` int(10) DEFAULT 0,'
                    . '`retries` int(10) DEFAULT 0,'
                    . '`last_error` varchar(255) DEFAULT NULL,'
                    . '`created` int(10) DEFAULT 0,'
                    . '`updated` int(10) DEFAULT 0,'
                    . 'PRIMARY KEY (`qid`)'
                    . ') ENGINE=MyISAM DEFAULT CHARSET=utf8',
                    Typecho_Db::WRITE
                );
                return;
            }

            if ('SQLite' == $type) {
                $db->query(
                    'CREATE TABLE IF NOT EXISTS `' . $prefix . 'qq_notify_queue` ('
                    . '`qid` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                    . '`message` text NOT NULL,'
                    . '`status` int(10) DEFAULT 0,'
                    . '`retries` int(10) DEFAULT 0,'
                    . '`last_error` varchar(255) DEFAULT NULL,'
                    . '`created` integer DEFAULT 0,'
                    . '`updated` integer DEFAULT 0'
                    . ')',
                    Typecho_Db::WRITE
                );
            }
        } catch (Exception $e) {
            // ignore queue table errors
        }
    }

    private static function getQqNotifyQueueStats(): array
    {
        self::ensureQqNotifyQueueTable();

        $stats = array(
            'pending' => 0,
            'success' => 0,
            'failed' => 0,
            'total' => 0,
        );

        try {
            $db = Typecho_Db::get();
            $table = $db->getPrefix() . 'qq_notify_queue';
            $rows = $db->fetchAll(
                $db->select('status', array('COUNT(qid)' => 'num'))
                    ->from($table)
                    ->group('status')
            );

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $status = isset($row['status']) ? intval($row['status']) : 0;
                    $num = isset($row['num']) ? intval($row['num']) : 0;
                    if ($status === 1) {
                        $stats['success'] += $num;
                    } elseif ($status === 2) {
                        $stats['failed'] += $num;
                    } else {
                        $stats['pending'] += $num;
                    }
                    $stats['total'] += $num;
                }
            }
        } catch (Exception $e) {
            // ignore queue stat errors
        }

        return $stats;
    }

    private static function sendCommentByQQMessage(string $message, bool $returnResult = false)
    {
        $result = array('success' => false, 'error' => '');

        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);
        $apiUrl = isset($settings->qqboturl) ? trim((string)$settings->qqboturl) : '';
        $qqNum = isset($settings->qq) ? trim((string)$settings->qq) : '';

        if ($apiUrl === '' || $qqNum === '') {
            $result['error'] = 'qq settings missing';
            return $returnResult ? $result : false;
        }

        $payload = array(
            'user_id' => (int)$qqNum,
            'message' => (string)$message
        );

        if (!function_exists('curl_init')) {
            $result['error'] = 'curl extension missing';
            return $returnResult ? $result : false;
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            $result['error'] = 'payload json encode failed';
            return $returnResult ? $result : false;
        }

        $endpoint = rtrim($apiUrl, '/') . '/send_msg';
        $lastErrorNo = 0;
        $lastError = '';
        $lastHttpCode = 0;
        $lastResponse = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init();
            $curlOptions = array(
                CURLOPT_URL => $endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json; charset=UTF-8',
                    'Accept: application/json'
                ),
                CURLOPT_SSL_VERIFYPEER => false
            );

            if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
                $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
            if (defined('CURLOPT_NOSIGNAL')) {
                $curlOptions[CURLOPT_NOSIGNAL] = true;
            }

            curl_setopt_array($ch, $curlOptions);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = $errno ? curl_error($ch) : '';
            curl_close($ch);

            if ($errno === 0 && $httpCode >= 200 && $httpCode < 300) {
                $decoded = json_decode((string)$response, true);
                if (is_array($decoded)) {
                    if (isset($decoded['retcode']) && intval($decoded['retcode']) !== 0) {
                        $lastErrorNo = 0;
                        $lastError = 'retcode=' . intval($decoded['retcode']);
                        $lastHttpCode = $httpCode;
                        $lastResponse = substr((string)$response, 0, 200);
                        break;
                    }
                    if (isset($decoded['status']) && strtolower((string)$decoded['status']) !== 'ok') {
                        $lastErrorNo = 0;
                        $lastError = 'status=' . strtolower((string)$decoded['status']);
                        $lastHttpCode = $httpCode;
                        $lastResponse = substr((string)$response, 0, 200);
                        break;
                    }
                }

                $result['success'] = true;
                return $returnResult ? $result : true;
            }

            $lastErrorNo = $errno;
            $lastError = $error;
            $lastHttpCode = $httpCode;
            $lastResponse = substr((string)$response, 0, 200);

            if ($errno === CURLE_OPERATION_TIMEDOUT && $attempt < 2) {
                usleep(150000);
                continue;
            }
            break;
        }

        if ($lastErrorNo !== 0) {
            if ($lastErrorNo !== CURLE_OPERATION_TIMEDOUT) {
                error_log('[Enhancement][CommentsByQQ] CURL错误: ' . $lastError);
            }
            $result['error'] = $lastError !== '' ? $lastError : ('curl errno=' . $lastErrorNo);
            return $returnResult ? $result : false;
        }

        if ($lastHttpCode >= 400) {
            error_log(sprintf('[Enhancement][CommentsByQQ] 响应异常 [HTTP %d]: %s', $lastHttpCode, $lastResponse));
        }

        $result['error'] = $lastError !== ''
            ? $lastError
            : ($lastResponse !== '' ? $lastResponse : ('http=' . $lastHttpCode));

        return $returnResult ? $result : false;
    }

    public static function commentNotifierGetParent($comment): array
    {
        if (empty($comment->parent)) {
            return [];
        }
        try {
            $parent = Helper::widgetById('comments', $comment->parent);
        } catch (Exception $e) {
            return [];
        }
        if (!$parent) {
            return [];
        }
        return [
            'name' => $parent->author,
            'mail' => $parent->mail,
        ];
    }

    public static function commentNotifierGetAuthor($comment): array
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $plugin = self::pluginSettings($options);
        $db = Typecho_Db::get();
        $ae = $db->fetchRow($db->select()->from('table.users')->where('table.users.uid=?', $comment->ownerId));
        $mail = isset($ae['mail']) ? $ae['mail'] : '';
        if (empty($mail)) {
            $mail = $plugin->adminfrom;
        }
        return [
            'name' => isset($ae['screenName']) ? $ae['screenName'] : '',
            'mail' => $mail,
        ];
    }

    public static function commentNotifierMark($comment, $edit, $status)
    {
        self::commentByQQMark($comment, $edit, $status);

        $options = Typecho_Widget::widget('Widget_Options');
        $plugin = self::pluginSettings($options);
        if (isset($plugin->enable_comment_notifier) && $plugin->enable_comment_notifier != '1') {
            return;
        }

        $recipients = [];
        $from = $plugin->adminfrom;
        if ($status == 'approved') {
            $type = 0;
            if ($edit->parent > 0) {
                $recipients[] = self::commentNotifierGetParent($edit);
                $type = 1;
            } else {
                $recipients[] = self::commentNotifierGetAuthor($edit);
            }

            if (empty($recipients) || empty($recipients[0]['mail'])) {
                return;
            }

            if ($recipients[0]['mail'] == $edit->mail) {
                return;
            }
            if ($recipients[0]['mail'] == $from) {
                return;
            }

            self::commentNotifierSendMail($edit, $recipients, $type);
        }
    }

    public static function commentNotifierRefinishComment($comment)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $plugin = self::pluginSettings($options);
        if (isset($plugin->enable_comment_notifier) && $plugin->enable_comment_notifier != '1') {
            return;
        }

        $from = $plugin->adminfrom;
        $fromName = $plugin->fromName;
        $recipients = [];

        if ($comment->status == 'approved') {
            $type = 0;
            $author = self::commentNotifierGetAuthor($comment);
            if ($comment->authorId != $comment->ownerId && $comment->mail != $author['mail']) {
                $recipients[] = $author;
            }

            if ($comment->parent) {
                $type = 1;
                $parent = self::commentNotifierGetParent($comment);
                if (!empty($parent) && $parent['mail'] != $from && $parent['mail'] != $comment->mail) {
                    $recipients[] = $parent;
                }
            }
            self::commentNotifierSendMail($comment, $recipients, $type);
        } else {
            if (!empty($from)) {
                $recipients[] = ['name' => $fromName, 'mail' => $from];
                self::commentNotifierSendMail($comment, $recipients, 2);
            }
        }
    }

    private static function commentNotifierSendMail($comment, array $recipients, $type)
    {
        if (empty($recipients)) {
            return;
        }
        $options = Typecho_Widget::widget('Widget_Options');
        $plugin = self::pluginSettings($options);
        if (isset($plugin->enable_comment_notifier) && $plugin->enable_comment_notifier != '1') {
            return;
        }

        if ($type == 1) {
            $subject = '你在[' . $comment->title . ']的评论有了新的回复';
        } elseif ($type == 2) {
            $subject = '文章《' . $comment->title . '》有条待审评论';
        } else {
            $subject = '你的《' . $comment->title . '》文章有了新的评论';
        }

        foreach ($recipients as $recipient) {
            if (empty($recipient['mail'])) {
                continue;
            }
            $param = [
                'to' => $recipient['mail'],
                'fromName' => $recipient['name'],
                'subject' => $subject,
                'html' => self::commentNotifierMailBody($comment, $options, $type)
            ];
            self::commentNotifierResendMail($param);
        }
    }

    public static function commentNotifierResendMail($param)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $plugin = self::pluginSettings($options);
        if (isset($plugin->enable_comment_notifier) && $plugin->enable_comment_notifier != '1') {
            return;
        }

        if ($plugin->zznotice == 1 && $param['to'] == $plugin->adminfrom) {
            return;
        }

        if ($plugin->yibu == 1) {
            Helper::requestService('send', $param);
        } else {
            self::commentNotifierSend($param);
        }
    }

    public static function commentNotifierSend($param)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $plugin = self::pluginSettings($options);
        if (isset($plugin->enable_comment_notifier) && $plugin->enable_comment_notifier != '1') {
            return;
        }
        self::commentNotifierZemail($param);
    }

    public static function commentNotifierZemail($param)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $plugin = self::pluginSettings($options);

        $flag = true;
        try {
            if (empty($plugin->from) || empty($plugin->fromName)) {
                return false;
            }

            require_once __DIR__ . '/CommentNotifier/PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/CommentNotifier/PHPMailer/SMTP.php';
            require_once __DIR__ . '/CommentNotifier/PHPMailer/Exception.php';

            $from = $plugin->from;
            $fromName = $plugin->fromName;
            $mail = new \PHPMailer\PHPMailer\PHPMailer(false);
            $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
            $mail->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
            $mail->isSMTP();
            $mail->Host = $plugin->STMPHost;
            $mail->SMTPAuth = true;
            $mail->Username = $plugin->SMTPUserName;
            $mail->Password = $plugin->SMTPPassword;
            $mail->SMTPSecure = $plugin->SMTPSecure;
            $mail->Port = $plugin->SMTPPort;

            $mail->setFrom($from, $fromName);
            $mail->addAddress($param['to'], $param['fromName']);
            $mail->Subject = $param['subject'];
            $mail->isHTML();
            $mail->Body = $param['html'];
            $mail->send();

            if ($mail->isError()) {
                $flag = false;
            }

            if ($plugin->log) {
                $at = date('Y-m-d H:i:s');
                if ($mail->isError()) {
                    $data = $at . ' ' . $mail->ErrorInfo;
                } else {
                    $data = PHP_EOL . $at . ' 发送成功! ';
                    $data .= ' 发件人:' . $fromName;
                    $data .= ' 发件邮箱:' . $from;
                    $data .= ' 接收人:' . $param['fromName'];
                    $data .= ' 接收邮箱:' . $param['to'] . PHP_EOL;
                }
                $fileName = __DIR__ . '/CommentNotifier/log.txt';
                file_put_contents($fileName, $data, FILE_APPEND);
            }
        } catch (Exception $e) {
            $flag = false;
            if ($plugin->log) {
                $fileName = __DIR__ . '/CommentNotifier/log.txt';
                $str = "\nerror time: " . date('Y-m-d H:i:s') . "\n";
                file_put_contents($fileName, $str, FILE_APPEND);
                file_put_contents($fileName, $e, FILE_APPEND);
            }
        }
        return $flag;
    }

    private static function commentNotifierMailBody($comment, $options, $type): string
    {
        $plugin = self::pluginSettings($options);
        $commentAt = new Typecho_Date($comment->created);
        $commentAt = $commentAt->format('Y-m-d H:i:s');
        $commentText = isset($comment->content) ? $comment->content : (isset($comment->text) ? $comment->text : '');
        $html = 'owner';
        if ($type == 1) {
            $html = 'guest';
        } elseif ($type == 2) {
            $html = 'notice';
        }
        $Pmail = '';
        $Pname = '';
        $Ptext = '';
        $Pmd5 = '';
        if ($comment->parent) {
            try {
                $parent = Helper::widgetById('comments', $comment->parent);
                $Pname = $parent->author;
                $Ptext = $parent->content;
                $Pmail = $parent->mail;
                $Pmd5 = md5($parent->mail);
            } catch (Exception $e) {
                // ignore missing parent
            }
        }

        $commentMail = isset($comment->mail) ? $comment->mail : '';
        $avatarUrl = self::buildAvatarUrl($commentMail, 40, 'monsterid');
        $PavatarUrl = self::buildAvatarUrl($Pmail, 40, 'monsterid');

        $postAuthor = '';
        try {
            $post = Helper::widgetById('Contents', $comment->cid);
            $postAuthor = $post->author->screenName;
        } catch (Exception $e) {
            $postAuthor = '';
        }

        if ($plugin->biaoqing && is_callable($plugin->biaoqing)) {
            $parseBiaoQing = $plugin->biaoqing;
            $commentText = $parseBiaoQing($commentText);
            $Ptext = $parseBiaoQing($Ptext);
        }

        $style = 'style="display: inline-block;vertical-align: bottom;margin: 0;" width="30"';
        $commentText = str_replace('class="biaoqing', $style . ' class="biaoqing', $commentText);
        $Ptext = str_replace('class="biaoqing', $style . ' class="biaoqing', $Ptext);

        $content = self::commentNotifierGetTemplate($html);
        $content = preg_replace('#<\\?php#', '<!--', $content);
        $content = preg_replace('#\\?>#', '-->', $content);

        $template = !empty($plugin->template) ? $plugin->template : 'default';
        $status = array(
            "approved" => '通过',
            "waiting" => '待审',
            "spam" => '垃圾',
        );
        $search = array(
            '{title}',
            '{PostAuthor}',
            '{time}',
            '{commentText}',
            '{author}',
            '{mail}',
            '{md5}',
            '{avatar}',
            '{ip}',
            '{permalink}',
            '{siteUrl}',
            '{siteTitle}',
            '{Pname}',
            '{Ptext}',
            '{Pmail}',
            '{Pmd5}',
            '{Pavatar}',
            '{url}',
            '{manageurl}',
            '{status}',
        );
        $replace = array(
            $comment->title,
            $postAuthor,
            $commentAt,
            $commentText,
            $comment->author,
            $comment->mail,
            md5($comment->mail),
            $avatarUrl,
            $comment->ip,
            $comment->permalink,
            $options->siteUrl,
            $options->title,
            $Pname,
            $Ptext,
            $Pmail,
            $Pmd5,
            $PavatarUrl,
            $options->pluginUrl . '/Enhancement/CommentNotifier/template/' . $template . '/',
            $options->adminUrl . '/manage-comments.php',
            isset($status[$comment->status]) ? $status[$comment->status] : $comment->status
        );

        return str_replace($search, $replace, $content);
    }

    private static function commentNotifierGetTemplate($template = 'owner')
    {
        $template .= '.html';
        $templateDir = self::commentNotifierConfigStr('template', 'default');
        $filePath = __DIR__ . '/CommentNotifier/template/' . $templateDir . '/' . $template;

        if (!file_exists($filePath)) {
            $filePath = __DIR__ . '/CommentNotifier/template/default/' . $template;
        }

        return file_get_contents($filePath);
    }

    public static function commentNotifierConfigStr(string $key, $default = '', string $method = 'empty'): string
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        $value = isset($settings->$key) ? $settings->$key : null;
        if ($method === 'empty') {
            return empty($value) ? $default : $value;
        } else {
            return call_user_func($method, $value) ? $default : $value;
        }
    }

    public static function avatarMirrorEnabled(): bool
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        if (!isset($settings->enable_avatar_mirror)) {
            return true;
        }
        return $settings->enable_avatar_mirror == '1';
    }

    public static function avatarBaseUrl(): string
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        $defaultMirror = 'https://cn.cravatar.com/avatar/';
        $defaultGravatar = 'https://secure.gravatar.com/avatar/';
        $enabled = !isset($settings->enable_avatar_mirror) || $settings->enable_avatar_mirror == '1';

        if ($enabled) {
            $base = !empty($settings->avatar_mirror_url) ? $settings->avatar_mirror_url : $defaultMirror;
        } else {
            $base = $defaultGravatar;
        }

        $base = trim((string)$base);
        if ($base === '') {
            $base = $enabled ? $defaultMirror : $defaultGravatar;
        }

        return self::normalizeAvatarBase($base);
    }

    public static function applyAvatarPrefix($archive = null, $select = null)
    {
        self::registerRuntimeCommentFilter();
        self::upgradeLegacyCommentUrls();
        self::processQqNotifyQueue();

        if (!self::avatarMirrorEnabled()) {
            return;
        }
        if (!defined('__TYPECHO_GRAVATAR_PREFIX__')) {
            define('__TYPECHO_GRAVATAR_PREFIX__', self::avatarBaseUrl());
        }
    }

    private static function registerRuntimeCommentFilter()
    {
        static $registered = false;
        if ($registered) {
            return;
        }

        $registered = true;
        Typecho_Plugin::factory('Widget_Abstract_Comments')->filter = array(__CLASS__, 'filterCommentRowUrl');
    }

    public static function filterCommentRowUrl($row, $widget = null, $lastRow = null)
    {
        if (!is_array($row)) {
            return $row;
        }

        $currentUrl = isset($row['url']) ? trim((string)$row['url']) : '';
        if ($currentUrl === '') {
            return $row;
        }

        $row['url'] = self::convertExternalUrlToGo($currentUrl);
        return $row;
    }

    public static function buildAvatarUrl($email, $size = null, $default = null, array $extra = array()): string
    {
        $hash = md5(strtolower(trim((string)$email)));
        $params = array();
        if ($size !== null) {
            $params['s'] = intval($size);
        }
        if ($default !== null && $default !== '') {
            $params['d'] = $default;
        }
        if (!empty($extra)) {
            foreach ($extra as $key => $value) {
                if ($value !== null && $value !== '') {
                    $params[$key] = $value;
                }
            }
        }
        $query = http_build_query($params);
        return self::avatarBaseUrl() . $hash . ($query ? '?' . $query : '');
    }

    private static function normalizeAvatarBase(string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            return 'https://cn.cravatar.com/avatar/';
        }
        if (substr($base, -1) !== '/') {
            $base .= '/';
        }
        return $base;
    }

    public static function writePostBottom()
    {
        AttachmentHelper::addEnhancedFeatures();
        self::tagsList();
    }

    public static function writePageBottom()
    {
        AttachmentHelper::addEnhancedFeatures();
    }

    public static function tagsList()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);
        if (isset($settings->enable_tags_helper) && $settings->enable_tags_helper != '1') {
            return;
        }

?>
<style>
.tagshelper a { cursor: pointer; padding: 0px 6px; margin: 2px 0; display: inline-block; border-radius: 2px; text-decoration: none; }
.tagshelper a:hover { background: #ccc; color: #fff; }
</style>
<script>
$(document).ready(function(){
    $('#tags').after('<div style="margin-top: 35px;" class="tagshelper"><ul style="list-style: none;border: 1px solid #D9D9D6;padding: 6px 12px; max-height: 240px;overflow: auto;background-color: #FFF;border-radius: 2px;"><?php
$i = 0;
Typecho_Widget::widget('Widget_Metas_Tag_Cloud', 'sort=count&desc=1&limit=200')->to($tags);
while ($tags->next()) {
    echo "<a id=".$i." onclick=\"$(\'#tags\').tokenInput(\'add\', {id: \'".$tags->name."\', tags: \'".$tags->name."\'});\">".$tags->name."</a>";
    $i++;
}
?></ul></div>');
});
</script>
<?php
    }

    /**
     * 控制输出格式
     */
    public static function output_str($widget, array $params)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);
        if (!isset($options->plugins['activated']['Enhancement'])) {
            return _t('Enhancement 插件未激活');
        }
        //验证默认参数
        $pattern = !empty($params[0]) && is_string($params[0]) ? $params[0] : 'SHOW_TEXT';
        $items_num = !empty($params[1]) && is_numeric($params[1]) ? $params[1] : 0;
        $sort = !empty($params[2]) && is_string($params[2]) ? $params[2] : null;
        $size = !empty($params[3]) && is_numeric($params[3]) ? $params[3] : $settings->dsize;
        $mode = isset($params[4]) ? $params[4] : 'FUNC';
        if ($pattern == 'SHOW_TEXT') {
            $pattern = $settings->pattern_text . "\n";
        } elseif ($pattern == 'SHOW_IMG') {
            $pattern = $settings->pattern_img . "\n";
        } elseif ($pattern == 'SHOW_MIX') {
            $pattern = $settings->pattern_mix . "\n";
        }
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $nopic_url = Typecho_Common::url('usr/plugins/Enhancement/nopic.png', $options->siteUrl);
        $sql = $db->select()->from($prefix . 'links');
        if ($sort) {
            $sql = $sql->where('sort=?', $sort);
        }
        $sql = $sql->order($prefix . 'links.order', Typecho_Db::SORT_ASC);
        $items_num = intval($items_num);
        if ($items_num > 0) {
            $sql = $sql->limit($items_num);
        }
        $items = $db->fetchAll($sql);
        $str = "";
        foreach ($items as $item) {
            if ($item['image'] == null) {
                $item['image'] = $nopic_url;
                if ($item['email'] != null) {
                    $item['image'] = self::buildAvatarUrl($item['email'], $size, 'mm');
                }
            }
            if ($item['state'] == 1) {
                $safeName = htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8');
                $safeUrl = htmlspecialchars((string)$item['url'], ENT_QUOTES, 'UTF-8');
                $safeSort = htmlspecialchars((string)$item['sort'], ENT_QUOTES, 'UTF-8');
                $safeDescription = htmlspecialchars((string)$item['description'], ENT_QUOTES, 'UTF-8');
                $safeImage = htmlspecialchars((string)$item['image'], ENT_QUOTES, 'UTF-8');
                $safeUser = htmlspecialchars((string)$item['user'], ENT_QUOTES, 'UTF-8');
                $str .= str_replace(
                    array('{lid}', '{name}', '{url}', '{sort}', '{title}', '{description}', '{image}', '{user}', '{size}'),
                    array((int)$item['lid'], $safeName, $safeUrl, $safeSort, $safeDescription, $safeDescription, $safeImage, $safeUser, (int)$size),
                    $pattern
                );
            }
        }

        if ($mode == 'HTML') {
            return $str;
        } else {
            echo $str;
        }
    }

    //输出
    public static function output($pattern = 'SHOW_TEXT', $items_num = 0, $sort = null, $size = 32, $mode = '')
    {
        return Enhancement_Plugin::output_str('', array($pattern, $items_num, $sort, $size, $mode));
    }

    /**
     * 解析
     * 
     * @access public
     * @param array $matches 解析值
     * @return string
     */
    public static function parseCallback($matches)
    {
        return Enhancement_Plugin::output_str('', array($matches[4], $matches[1], $matches[2], $matches[3], 'HTML'));
    }

    public static function videoParserEnabled(): bool
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        if (!isset($settings->enable_video_parser)) {
            return false;
        }
        return $settings->enable_video_parser == '1';
    }

    public static function blankTargetEnabled(): bool
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        if (!isset($settings->enable_blank_target)) {
            return false;
        }
        return $settings->enable_blank_target == '1';
    }

    public static function goRedirectEnabled(): bool
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        if (!isset($settings->enable_go_redirect)) {
            return true;
        }
        return $settings->enable_go_redirect == '1';
    }

    private static function parseGoRedirectWhitelist(): array
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        $raw = isset($settings->go_redirect_whitelist) ? (string)$settings->go_redirect_whitelist : '';
        if ($raw === '') {
            return array();
        }

        $parts = preg_split('/[\r\n,，;；\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || empty($parts)) {
            return array();
        }

        $domains = array();
        foreach ($parts as $part) {
            $domain = strtolower(trim((string)$part));
            if ($domain === '') {
                continue;
            }

            if (strpos($domain, '://') !== false) {
                $parsedHost = parse_url($domain, PHP_URL_HOST);
                if (is_string($parsedHost) && $parsedHost !== '') {
                    $domain = strtolower(trim($parsedHost));
                }
            }

            if (strpos($domain, 'www.') === 0) {
                $domain = substr($domain, 4);
            }
            $domain = trim($domain, '.');
            if ($domain === '') {
                continue;
            }

            $domains[$domain] = true;
        }

        return array_keys($domains);
    }

    private static function isWhitelistedHost($host): bool
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return false;
        }

        $whitelist = self::parseGoRedirectWhitelist();
        if (empty($whitelist)) {
            return false;
        }

        foreach ($whitelist as $domain) {
            $domain = self::normalizeHost($domain);
            if ($domain === '') {
                continue;
            }

            if ($host === $domain) {
                return true;
            }

            if (strlen($host) > strlen($domain) && substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeHost($host)
    {
        $host = strtolower(trim((string)$host));
        if ($host === '') {
            return '';
        }
        if (substr($host, 0, 4) === 'www.') {
            $host = substr($host, 4);
        }
        return $host;
    }

    private static function normalizeExternalUrl($url)
    {
        $url = trim(html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        if (strpos($url, '//') === 0) {
            $options = Typecho_Widget::widget('Widget_Options');
            $siteUrl = isset($options->siteUrl) ? (string)$options->siteUrl : '';
            $siteScheme = (string)parse_url($siteUrl, PHP_URL_SCHEME);
            if ($siteScheme === '') {
                $siteScheme = 'https';
            }
            $url = $siteScheme . ':' . $url;
        } elseif (!preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $url)) {
            $lower = strtolower($url);
            if (
                strpos($lower, 'mailto:') !== 0 &&
                strpos($lower, 'tel:') !== 0 &&
                strpos($lower, 'javascript:') !== 0 &&
                strpos($lower, 'data:') !== 0 &&
                strpos($url, '#') !== 0 &&
                strpos($url, '/') !== 0 &&
                strpos($url, '?') !== 0 &&
                preg_match('/^[^\s\/\?#]+\.[^\s\/\?#]+(?:[\/\?#].*)?$/', $url)
            ) {
                $url = 'http://' . $url;
            }
        }

        return $url;
    }

    private static function shouldUseGoRedirect($url)
    {
        if (!self::goRedirectEnabled()) {
            return false;
        }

        $decoded = self::normalizeExternalUrl($url);
        if ($decoded === '') {
            return false;
        }

        $lower = strtolower($decoded);
        if (strpos($lower, '#') === 0 || strpos($lower, '/') === 0 || strpos($lower, '?') === 0) {
            return false;
        }
        if (
            strpos($lower, 'mailto:') === 0 ||
            strpos($lower, 'tel:') === 0 ||
            strpos($lower, 'javascript:') === 0 ||
            strpos($lower, 'data:') === 0
        ) {
            return false;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $siteUrl = isset($options->siteUrl) ? (string)$options->siteUrl : '';

        $goPrefix = Typecho_Common::url('go/', $options->index);
        if (strpos($decoded, $goPrefix) === 0) {
            return false;
        }

        $parsed = @parse_url($decoded);
        if (!is_array($parsed)) {
            return false;
        }

        $scheme = isset($parsed['scheme']) ? strtolower((string)$parsed['scheme']) : '';
        $host = isset($parsed['host']) ? self::normalizeHost($parsed['host']) : '';
        if (!in_array($scheme, array('http', 'https'), true) || $host === '') {
            return false;
        }

        if (self::isWhitelistedHost($host)) {
            return false;
        }

        $siteHost = self::normalizeHost(parse_url($siteUrl, PHP_URL_HOST));
        if ($siteHost !== '' && $host === $siteHost) {
            return false;
        }

        return true;
    }

    private static function isGoRedirectHref($href): bool
    {
        return self::decodeGoRedirectUrl($href) !== '';
    }

    private static function decodeGoRedirectUrl($href): string
    {
        $href = trim(html_entity_decode((string)$href, ENT_QUOTES, 'UTF-8'));
        if ($href === '') {
            return '';
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $goBase = Typecho_Common::url('go/', $options->index);
        $token = '';

        if (strpos($href, $goBase) === 0) {
            $token = (string)substr($href, strlen($goBase));
        } else {
            $goPath = (string)parse_url($goBase, PHP_URL_PATH);
            $hrefPath = parse_url($href, PHP_URL_PATH);
            if (!is_string($hrefPath) || $hrefPath === '') {
                return '';
            }

            $normalizedGoPath = '/' . ltrim($goPath, '/');
            $normalizedHrefPath = '/' . ltrim($hrefPath, '/');
            if ($normalizedGoPath === '/' || $normalizedGoPath === '') {
                return '';
            }
            if (strpos($normalizedHrefPath, $normalizedGoPath) !== 0) {
                return '';
            }

            $token = (string)substr($normalizedHrefPath, strlen($normalizedGoPath));
        }

        $token = ltrim($token, '/');
        if ($token === '') {
            return '';
        }

        $token = preg_replace('/[#\?].*$/', '', $token);
        if (!is_string($token) || $token === '') {
            return '';
        }

        $decoded = self::decodeGoTarget($token);
        if ($decoded !== '') {
            return $decoded;
        }

        if (preg_match('/^(.*?)(?:-?target=_blank.*)$/i', $token, $matches) && isset($matches[1])) {
            $fallbackToken = rtrim((string)$matches[1], '-_');
            if ($fallbackToken !== '') {
                return self::decodeGoTarget($fallbackToken);
            }
        }

        return '';
    }

    private static function normalizeAnchorTagSpacing($tag)
    {
        if (!is_string($tag) || $tag === '') {
            return $tag;
        }

        $tag = preg_replace('/"(?=[A-Za-z_:][A-Za-z0-9:_.-]*\s*=)/', '" ', $tag);
        $tag = preg_replace('/\'(?=[A-Za-z_:][A-Za-z0-9:_.-]*\s*=)/', '\' ', $tag);

        return is_string($tag) ? $tag : '';
    }

    private static function convertExternalUrlToGo($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return $url;
        }

        $decodedGoUrl = self::decodeGoRedirectUrl($url);

        if (!self::goRedirectEnabled()) {
            return $decodedGoUrl !== '' ? $decodedGoUrl : $url;
        }

        if ($decodedGoUrl !== '') {
            if (!self::shouldUseGoRedirect($decodedGoUrl)) {
                return $decodedGoUrl;
            }

            $rebuildGoUrl = self::buildGoRedirectUrl($decodedGoUrl);
            return $rebuildGoUrl !== '' ? $rebuildGoUrl : $url;
        }

        if (!self::shouldUseGoRedirect($url)) {
            return $url;
        }

        $goUrl = self::buildGoRedirectUrl($url);
        return $goUrl !== '' ? $goUrl : $url;
    }

    private static function upgradeCommentUrlByCoid($coid, $url)
    {
        $coid = intval($coid);
        $url = trim((string)$url);
        if ($coid <= 0 || $url === '') {
            return;
        }

        try {
            $db = Typecho_Db::get();
            $db->query(
                $db->update('table.comments')
                    ->rows(array('url' => $url))
                    ->where('coid = ?', $coid)
            );
        } catch (Exception $e) {
            // ignore url upgrade errors
        }
    }

    private static function upgradeCommentWidgetUrl($widget)
    {
        if (!($widget instanceof Widget_Abstract_Comments)) {
            return;
        }

        $currentUrl = isset($widget->url) ? trim((string)$widget->url) : '';
        if ($currentUrl === '') {
            return;
        }

        $goUrl = self::convertExternalUrlToGo($currentUrl);
        if ($goUrl === $currentUrl) {
            return;
        }

        try {
            $widget->url = $goUrl;
        } catch (Exception $e) {
            // ignore runtime property assignment errors
        }
    }

    private static function upgradeLegacyCommentUrls($limit = 120)
    {
        static $executed = false;
        if ($executed) {
            return;
        }
        $executed = true;

        $limit = intval($limit);
        if ($limit <= 0) {
            $limit = 120;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        try {
            $db = Typecho_Db::get();
            $rows = $db->fetchAll(
                $db->select('coid', 'url')
                    ->from('table.comments')
                    ->where('url <> ?', '')
                    ->order('coid', Typecho_Db::SORT_DESC)
                    ->limit($limit)
            );

            if (!is_array($rows) || empty($rows)) {
                return;
            }

            foreach ($rows as $row) {
                $currentUrl = isset($row['url']) ? trim((string)$row['url']) : '';
                if ($currentUrl === '') {
                    continue;
                }

                $originUrl = self::decodeGoRedirectUrl($currentUrl);
                if ($originUrl === '' || $originUrl === $currentUrl) {
                    continue;
                }

                $coid = isset($row['coid']) ? intval($row['coid']) : 0;
                if ($coid <= 0) {
                    continue;
                }

                $db->query(
                    $db->update('table.comments')
                        ->rows(array('url' => $originUrl))
                        ->where('coid = ?', $coid)
                );
            }
        } catch (Exception $e) {
            // ignore batch repair errors
        }
    }

    public static function encodeGoTarget($url)
    {
        $encoded = base64_encode((string)$url);
        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    public static function decodeGoTarget($token)
    {
        $token = trim((string)$token);
        if ($token === '') {
            return '';
        }

        $token = rawurldecode($token);
        $normalized = strtr($token, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            return '';
        }

        $decoded = trim((string)$decoded);
        if (!self::validateHttpUrl($decoded)) {
            return '';
        }

        return $decoded;
    }

    public static function buildGoRedirectUrl($url)
    {
        $normalized = self::normalizeExternalUrl($url);
        if (!self::validateHttpUrl($normalized)) {
            return '';
        }

        $options = Typecho_Widget::widget('Widget_Options');
        return Typecho_Common::url('go/' . self::encodeGoTarget($normalized), $options->index);
    }

    private static function rewriteExternalLinksByRegex($content)
    {
        if (!is_string($content) || $content === '') {
            return $content;
        }

        return preg_replace_callback(
            '/<a\s+[^>]*>/i',
            function ($matches) {
                $tag = self::normalizeAnchorTagSpacing($matches[0]);
                if (!preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $tag, $hrefMatch)) {
                    return $tag;
                }

                $href = '';
                for ($index = 1; $index <= 3; $index++) {
                    if (isset($hrefMatch[$index]) && $hrefMatch[$index] !== '') {
                        $href = $hrefMatch[$index];
                        break;
                    }
                }

                $targetUrl = self::convertExternalUrlToGo($href);
                if ($targetUrl === '' || $targetUrl === $href) {
                    return $tag;
                }

                $target = htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8');
                $tag = preg_replace('/\bhref\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>"\']+)/i', 'href="' . $target . '"', $tag, 1);
                return self::normalizeAnchorTagSpacing($tag);
            },
            $content
        );
    }

    private static function rewriteExternalLinks($content)
    {
        if (!is_string($content) || $content === '' || stripos($content, '<a') === false) {
            return $content;
        }

        if (!class_exists('DOMDocument')) {
            return self::rewriteExternalLinksByRegex($content);
        }

        $libxmlState = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loadFlags = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) {
            $loadFlags |= LIBXML_HTML_NOIMPLIED;
        }
        if (defined('LIBXML_HTML_NODEFDTD')) {
            $loadFlags |= LIBXML_HTML_NODEFDTD;
        }

        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $content, $loadFlags);
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlState);

        if (!$loaded) {
            return self::rewriteExternalLinksByRegex($content);
        }

        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            $href = trim((string)$link->getAttribute('href'));
            $targetUrl = self::convertExternalUrlToGo($href);
            if ($targetUrl === '' || $targetUrl === $href) {
                continue;
            }
            $link->setAttribute('href', $targetUrl);
        }

        $result = $dom->saveHTML();
        if ($result === false) {
            return self::rewriteExternalLinksByRegex($content);
        }

        return str_replace('<?xml encoding="UTF-8">', '', $result);
    }

    private static function appendBlankTargetByRegex($content)
    {
        return preg_replace_callback(
            '/<a\s+[^>]*>/i',
            function ($matches) {
                $tag = self::normalizeAnchorTagSpacing($matches[0]);
                $href = '';
                if (preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $tag, $hrefMatch)) {
                    for ($index = 1; $index <= 3; $index++) {
                        if (isset($hrefMatch[$index]) && $hrefMatch[$index] !== '') {
                            $href = $hrefMatch[$index];
                            break;
                        }
                    }
                }

                if (preg_match('/\btarget\s*=\s*["\"][^"\"]*["\"]/i', $tag)) {
                    $tag = preg_replace('/\btarget\s*=\s*["\"][^"\"]*["\"]/i', 'target="_blank"', $tag, 1);
                } elseif (preg_match('/\btarget\s*=\s*\'[^\']*\'/i', $tag)) {
                    $tag = preg_replace('/\btarget\s*=\s*\'[^\']*\'/i', 'target="_blank"', $tag, 1);
                } else {
                    $tag = preg_replace('/>$/', ' target="_blank">', $tag, 1);
                }

                if (preg_match('/\brel\s*=\s*["\"]([^"\"]*)["\"]/i', $tag, $relMatch) || preg_match('/\brel\s*=\s*\'([^\']*)\'/i', $tag, $relMatch)) {
                    $rels = preg_split('/\s+/', strtolower(trim(isset($relMatch[1]) ? $relMatch[1] : '')), -1, PREG_SPLIT_NO_EMPTY);
                    $rels = is_array($rels) ? $rels : array();
                    if (!in_array('noopener', $rels, true)) {
                        $rels[] = 'noopener';
                    }
                    if (!in_array('noreferrer', $rels, true)) {
                        $rels[] = 'noreferrer';
                    }
                    $relValue = 'rel="' . implode(' ', $rels) . '"';
                    $tagBeforeRelReplace = $tag;
                    $tag = preg_replace('/\brel\s*=\s*["\"]([^"\"]*)["\"]/i', $relValue, $tag, 1);
                    if ($tag === $tagBeforeRelReplace) {
                        $tag = preg_replace('/\brel\s*=\s*\'([^\']*)\'/i', 'rel="' . implode(' ', $rels) . '"', $tag, 1);
                    }
                } else {
                    $tag = preg_replace('/>$/', ' rel="noopener noreferrer">', $tag, 1);
                }

                return self::normalizeAnchorTagSpacing($tag);
            },
            $content
        );
    }

    private static function addBlankTarget($content)
    {
        if (!is_string($content) || $content === '') {
            return $content;
        }

        if (stripos($content, '<a') === false) {
            return $content;
        }

        if (!class_exists('DOMDocument')) {
            return self::appendBlankTargetByRegex($content);
        }

        $libxmlState = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loadFlags = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) {
            $loadFlags |= LIBXML_HTML_NOIMPLIED;
        }
        if (defined('LIBXML_HTML_NODEFDTD')) {
            $loadFlags |= LIBXML_HTML_NODEFDTD;
        }

        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $content, $loadFlags);
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlState);

        if (!$loaded) {
            return self::appendBlankTargetByRegex($content);
        }

        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            $link->setAttribute('target', '_blank');
            $existingRel = trim((string)$link->getAttribute('rel'));
            $rels = preg_split('/\s+/', strtolower($existingRel), -1, PREG_SPLIT_NO_EMPTY);
            $rels = is_array($rels) ? $rels : array();
            if (!in_array('noopener', $rels, true)) {
                $rels[] = 'noopener';
            }
            if (!in_array('noreferrer', $rels, true)) {
                $rels[] = 'noreferrer';
            }
            $link->setAttribute('rel', implode(' ', $rels));
        }

        $result = $dom->saveHTML();
        if ($result === false) {
            return self::appendBlankTargetByRegex($content);
        }

        return str_replace('<?xml encoding="UTF-8">', '', $result);
    }

    private static function replaceVideoLinks($content)
    {
        if (empty($content)) {
            return $content;
        }

        $content = preg_replace_callback(
            '/<a\s+[^>]*href=["\']([^"\']*)["\'][^>]*>.*?<\/a>/is',
            function ($matches) {
                $url = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
                $videoInfo = self::extractVideoInfo($url);

                if ($videoInfo) {
                    return self::generateVideoPlayer($videoInfo);
                }

                return $matches[0];
            },
            $content
        );

        $content = preg_replace_callback(
            '/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/|bilibili\.com\/video\/|v\.youku\.com\/v_show\/id_)[^\s<]+/i',
            function ($matches) {
                $url = html_entity_decode($matches[0], ENT_QUOTES, 'UTF-8');
                $videoInfo = self::extractVideoInfo($url);

                if ($videoInfo) {
                    return self::generateVideoPlayer($videoInfo);
                }

                return $matches[0];
            },
            $content
        );

        return $content;
    }

    private static function extractVideoInfo($url)
    {
        $url = trim(html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return null;
        }

        $decodedGoUrl = self::decodeGoRedirectUrl($url);
        if ($decodedGoUrl !== '') {
            $url = $decodedGoUrl;
        }

        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([^&\n?#\/]+)/i', $url, $matches)) {
            return array(
                'platform' => 'youtube',
                'videoId' => $matches[1]
            );
        }

        if (preg_match('/bilibili\.com\/video\/(BV[0-9A-Za-z]+)/i', $url, $matches)) {
            return array(
                'platform' => 'bilibili',
                'videoId' => $matches[1],
                'idType' => 'bvid'
            );
        }

        if (preg_match('/bilibili\.com\/video\/av(\d+)/i', $url, $matches)) {
            return array(
                'platform' => 'bilibili',
                'videoId' => $matches[1],
                'idType' => 'aid'
            );
        }

        if (preg_match('/v\.youku\.com\/v_show\/id_([A-Za-z0-9=]+)\.html/i', $url, $matches)) {
            return array(
                'platform' => 'youku',
                'videoId' => $matches[1]
            );
        }

        return null;
    }

    private static function generateVideoPlayer($videoInfo)
    {
        $embedUrl = self::getVideoEmbedUrl($videoInfo);
        if ($embedUrl === '') {
            return '';
        }

        $platform = isset($videoInfo['platform']) ? strtolower((string)$videoInfo['platform']) : '';
        $platformLabelHtml = self::buildVideoPlatformLabelHtml($platform);
        $html = '<div class="enhancement-video-player-wrapper">';
        $html .= '<div class="enhancement-platform-label enhancement-label-' . $platform . '">' . $platformLabelHtml . '</div>';
        $html .= '<div class="enhancement-player-container enhancement-' . $platform . '">';
        $html .= '<iframe src="' . htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') . '" ';
        $html .= 'allowfullscreen ';
        $html .= 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" ';
        $html .= 'style="width: 100%; height: 500px; border: none;">';
        $html .= '</iframe>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function buildVideoPlatformLabelHtml($platform)
    {
        $platform = strtolower(trim((string)$platform));
        $platformLabel = strtoupper($platform);
        $iconSvg = self::getVideoPlatformIconSvg($platform);

        if ($iconSvg !== '') {
            return '<span class="enhancement-platform-icon" title="' . htmlspecialchars($platformLabel, ENT_QUOTES, 'UTF-8') . '" '
                . 'style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;line-height:1;vertical-align:middle;">'
                . $iconSvg
                . '</span>';
        }

        return htmlspecialchars($platformLabel, ENT_QUOTES, 'UTF-8');
    }

    private static function getVideoPlatformIconSvg($platform)
    {
        switch ($platform) {
            case 'bilibili':
                return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 640 640" aria-hidden="true" focusable="false">'
                    . '<path fill="#74C0FC" d="M552.6 168.1C569.3 186.2 577 207.8 575.9 233.8L575.9 436.2C575.5 462.6 566.7 484.3 549.4 501.3C532.2 518.3 510.3 527.2 483.9 528L156 528C129.6 527.2 107.8 518.2 90.7 500.8C73.6 483.4 64.7 460.5 64 432.2L64 233.8C64.8 207.8 73.7 186.2 90.7 168.1C107.8 151.8 129.5 142.8 156 142L185.4 142L160 116.2C154.3 110.5 151.4 103.2 151.4 94.4C151.4 85.6 154.3 78.3 160 72.6C165.7 66.9 173 64 181.9 64C190.8 64 198 66.9 203.8 72.6L277.1 142L365.1 142L439.6 72.6C445.7 66.9 453.2 64 462 64C470.8 64 478.1 66.9 483.9 72.6C489.6 78.3 492.5 85.6 492.5 94.4C492.5 103.2 489.6 110.5 483.9 116.2L458.6 142L487.9 142C514.3 142.8 535.9 151.8 552.6 168.1zM513.8 237.8C513.4 228.2 510.1 220.4 503.1 214.3C497.9 208.2 489.1 204.9 480.4 204.5L160 204.5C150.4 204.9 142.6 208.2 136.4 214.3C130.3 220.4 127 228.2 126.6 237.8L126.6 432.2C126.6 441.4 129.9 449.2 136.4 455.7C142.9 462.2 150.8 465.5 160 465.5L480.4 465.5C489.6 465.5 497.4 462.2 503.7 455.7C510 449.2 513.4 441.4 513.8 432.2L513.8 237.8zM249.5 280.5C255.8 286.8 259.2 294.6 259.6 303.7L259.6 337C259.2 346.2 255.9 353.9 249.8 360.2C243.6 366.5 235.8 369.7 226.2 369.7C216.6 369.7 208.7 366.5 202.6 360.2C196.5 353.9 193.2 346.2 192.8 337L192.8 303.7C193.2 294.6 196.6 286.8 202.9 280.5C209.2 274.2 216.1 270.9 226.2 270.5C235.4 270.9 243.2 274.2 249.5 280.5zM441 280.5C447.3 286.8 450.7 294.6 451.1 303.7L451.1 337C450.7 346.2 447.4 353.9 441.3 360.2C435.2 366.5 427.3 369.7 417.7 369.7C408.1 369.7 400.3 366.5 394.1 360.2C387.1 353.9 384.7 346.2 384.4 337L384.4 303.7C384.7 294.6 388.1 286.8 394.4 280.5C400.7 274.2 408.5 270.9 417.7 270.5C426.9 270.9 434.7 274.2 441 280.5z"/>'
                    . '</svg>';
            default:
                return '';
        }
    }

    private static function getVideoEmbedUrl($videoInfo)
    {
        $platform = isset($videoInfo['platform']) ? strtolower((string)$videoInfo['platform']) : '';
        $videoId = isset($videoInfo['videoId']) ? (string)$videoInfo['videoId'] : '';

        if ($videoId === '') {
            return '';
        }

        switch ($platform) {
            case 'youtube':
                return 'https://www.youtube.com/embed/' . rawurlencode($videoId);
            case 'bilibili':
                $idType = isset($videoInfo['idType']) ? strtolower((string)$videoInfo['idType']) : 'bvid';
                if ($idType === 'aid') {
                    return 'https://player.bilibili.com/player.html?aid=' . rawurlencode($videoId) . '&high_quality=1';
                }
                return 'https://player.bilibili.com/player.html?bvid=' . rawurlencode($videoId) . '&high_quality=1';
            case 'youku':
                return 'https://player.youku.com/embed/' . rawurlencode($videoId);
            default:
                return '';
        }
    }

    public static function parse($text, $widget, $lastResult)
    {
        $text = empty($lastResult) ? $text : $lastResult;
        if (!is_string($text)) {
            return $text;
        }

        $isContentWidget = $widget instanceof Widget_Abstract_Contents;
        $isCommentWidget = $widget instanceof Widget_Abstract_Comments;

        if ($isContentWidget || $isCommentWidget) {
            if ($isCommentWidget) {
                self::upgradeCommentWidgetUrl($widget);
            }

            $text = preg_replace_callback("/<(?:links|enhancement)\\s*(\\d*)\\s*(\\w*)\\s*(\\d*)>\\s*(.*?)\\s*<\\/(?:links|enhancement)>/is", array('Enhancement_Plugin', 'parseCallback'), $text ? $text : '');

            $text = self::rewriteExternalLinks($text);

            if (self::blankTargetEnabled()) {
                $text = self::addBlankTarget($text);
            }

            if ($isContentWidget && self::videoParserEnabled()) {
                $text = self::replaceVideoLinks($text);
            }

            return $text;
        } else {
            return $text;
        }
    }
}

/**
 * Typecho后台附件增强：图片预览、批量插入、保留官方删除按钮与逻辑
 * @author jkjoy
 * @date 2025-04-25
 */
class AttachmentHelper
{
    public static function addEnhancedFeatures()
    {
        ?>
        <style>
        #file-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:15px;padding:15px;list-style:none;margin:0;}
        #file-list li{position:relative;border:1px solid #e0e0e0;border-radius:4px;padding:10px;background:#fff;transition:all 0.3s ease;list-style:none;margin:0;}
        #file-list li:hover{box-shadow:0 2px 8px rgba(0,0,0,0.1);}
        #file-list li.loading{opacity:0.7;pointer-events:none;}
        .att-enhanced-thumb{position:relative;width:100%;height:150px;margin-bottom:8px;background:#f5f5f5;overflow:hidden;border-radius:3px;display:flex;align-items:center;justify-content:center;}
        .att-enhanced-thumb img{width:100%;height:100%;object-fit:contain;display:block;}
        .att-enhanced-thumb .file-icon{display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-size:40px;color:#999;}
        .att-enhanced-finfo{padding:5px 0;}
        .att-enhanced-fname{font-size:13px;margin-bottom:5px;word-break:break-all;color:#333;}
        .att-enhanced-fsize{font-size:12px;color:#999;}
        .att-enhanced-factions{display:flex;justify-content:space-between;align-items:center;margin-top:8px;gap:8px;}
        .att-enhanced-factions button{flex:1;padding:4px 8px;border:none;border-radius:3px;background:#e0e0e0;color:#333;cursor:pointer;font-size:12px;transition:all 0.2s ease;}
        .att-enhanced-factions button:hover{background:#d0d0d0;}
        .att-enhanced-factions .btn-insert{background:#467B96;color:white;}
        .att-enhanced-factions .btn-insert:hover{background:#3c6a81;}
        .att-enhanced-checkbox{position:absolute;top:5px;right:5px;z-index:2;width:18px;height:18px;cursor:pointer;}
        .batch-actions{margin:15px;display:flex;gap:10px;align-items:center;}
        .btn-batch{padding:8px 15px;border-radius:4px;border:none;cursor:pointer;transition:all 0.3s ease;font-size:10px;display:inline-flex;align-items:center;justify-content:center;}
        .btn-batch.primary{background:#467B96;color:white;}
        .btn-batch.primary:hover{background:#3c6a81;}
        .btn-batch.secondary{background:#e0e0e0;color:#333;}
        .btn-batch.secondary:hover{background:#d0d0d0;}
        .upload-progress{position:absolute;bottom:0;left:0;width:100%;height:2px;background:#467B96;transition:width 0.3s ease;}
        </style>
        <script>
        $(document).ready(function() {
            // 批量操作UI按钮
            var $batchActions = $('<div class="batch-actions"></div>')
                .append('<button type="button" class="btn-batch primary" id="batch-insert">批量插入</button>')
                .append('<button type="button" class="btn-batch secondary" id="select-all">全选</button>')
                .append('<button type="button" class="btn-batch secondary" id="unselect-all">取消全选</button>');
            $('#file-list').before($batchActions);

            // 插入格式
            Typecho.insertFileToEditor = function(title, url, isImage) {
                var textarea = $('#text'), 
                    sel = textarea.getSelection(),
                    insertContent = isImage ? '![' + title + '](' + url + ')' : 
                                            '[' + title + '](' + url + ')';
                textarea.replaceSelection(insertContent + '\n');
                textarea.focus();
            };

            // 批量插入
            $('#batch-insert').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var content = '';
                $('#file-list li').each(function() {
                    if ($(this).find('.att-enhanced-checkbox').is(':checked')) {
                        var $li = $(this);
                        var title = $li.find('.att-enhanced-fname').text();
                        var url = $li.data('url');
                        var isImage = $li.data('image') == 1;
                        content += isImage ? '![' + title + '](' + url + ')\n' : '[' + title + '](' + url + ')\n';
                    }
                });
                if (content) {
                    var textarea = $('#text');
                    var pos = textarea.getSelection();
                    var newContent = textarea.val();
                    newContent = newContent.substring(0, pos.start) + content + newContent.substring(pos.end);
                    textarea.val(newContent);
                    textarea.focus();
                }
            });

            $('#select-all').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $('#file-list .att-enhanced-checkbox').prop('checked', true);
                return false;
            });
            $('#unselect-all').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $('#file-list .att-enhanced-checkbox').prop('checked', false);
                return false;
            });

            // 防止复选框冒泡
            $(document).on('click', '.att-enhanced-checkbox', function(e) {e.stopPropagation();});

            // 增强文件列表样式，但不破坏li原结构和官方按钮
            function enhanceFileList() {
                $('#file-list li').each(function() {
                    var $li = $(this);
                    if ($li.hasClass('att-enhanced')) return;
                    $li.addClass('att-enhanced');
                    // 只增强，不清空li
                    // 增加批量选择框
                    if ($li.find('.att-enhanced-checkbox').length === 0) {
                        $li.prepend('<input type="checkbox" class="att-enhanced-checkbox" />');
                    }
                    // 增加图片预览（如已有则不重复加）
                    if ($li.find('.att-enhanced-thumb').length === 0) {
                        var url = $li.data('url');
                        var isImage = $li.data('image') == 1;
                        var fileName = $li.find('.insert').text();
                        var $thumbContainer = $('<div class="att-enhanced-thumb"></div>');
                        if (isImage) {
                            var $img = $('<img src="' + url + '" alt="' + fileName + '" />');
                            $img.on('error', function() {
                                $(this).replaceWith('<div class="file-icon">🖼️</div>');
                            });
                            $thumbContainer.append($img);
                        } else {
                            $thumbContainer.append('<div class="file-icon">📄</div>');
                        }
                        // 插到插入按钮之前
                        $li.find('.insert').before($thumbContainer);
                    }

                });
            }

            // 插入按钮事件
            $(document).on('click', '.btn-insert', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $li = $(this).closest('li');
                var title = $li.find('.att-enhanced-fname').text();
                Typecho.insertFileToEditor(title, $li.data('url'), $li.data('image') == 1);
            });

            // 上传完成后增强新项
            var originalUploadComplete = Typecho.uploadComplete;
            Typecho.uploadComplete = function(attachment) {
                setTimeout(function() {
                    enhanceFileList();
                }, 200);
                if (typeof originalUploadComplete === 'function') {
                    originalUploadComplete(attachment);
                }
            };

            // 首次增强
            enhanceFileList();
        });
        </script>
        <?php
    }
}
