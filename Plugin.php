<?php

/**
 * Enhancement 插件
 * 具体功能包含:友情链接,瞬间,网站地图,编辑器增强,常见视频链接 音乐链接 解析等
 * @package Enhancement
 * @author jkjoy
 * @version 1.0.7
 * @link HTTPS://IMSUN.ORG
 * @dependence 14.10.10-*
 */

class Enhancement_Plugin implements Typecho_Plugin_Interface
{
    public static $commentNotifierPanel = 'Enhancement/CommentNotifier/console.php';

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
        Helper::addPanel(3, 'Enhancement/manage-enhancement.php', _t('链接管理'), _t('审核管理'), 'administrator');
        Helper::addPanel(3, 'Enhancement/manage-moments.php', _t('瞬间'), _t('瞬间管理'), 'administrator');
        Helper::addPanel(1, self::$commentNotifierPanel, _t('评论邮件提醒外观'), _t('评论邮件提醒主题列表'), 'administrator');
        Helper::addRoute('sitemap', '/sitemap.xml', 'Enhancement_Sitemap_Action', 'action');
        Helper::addRoute('memos_api', '/api/v1/memos', 'Enhancement_Memos_Action', 'action');
        Helper::addRoute('zemail', '/zemail', 'Enhancement_CommentNotifier_Action', 'action');
        Helper::addRoute('go', '/go/[target]', 'Enhancement_Action', 'goRedirect');
        Helper::addAction('enhancement-edit', 'Enhancement_Action');
        Helper::addAction('enhancement-submit', 'Enhancement_Action');
        Helper::addAction('enhancement-moments-edit', 'Enhancement_Action');
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = [__CLASS__, 'finishComment'];
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = [__CLASS__, 'finishComment'];
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark = [__CLASS__, 'commentNotifierMark'];
        Typecho_Plugin::factory('Widget_Service')->send = [__CLASS__, 'commentNotifierSend'];
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array(__CLASS__, 'writePostBottom');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = array(__CLASS__, 'writePageBottom');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('Enhancement_Plugin', 'parse');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('Enhancement_Plugin', 'parse');
        Typecho_Plugin::factory('Widget_Abstract_Comments')->contentEx = array('Enhancement_Plugin', 'parse');
        Typecho_Plugin::factory('Widget_Archive')->handleInit = array('Enhancement_Plugin', 'applyAvatarPrefix');
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

        if ($legacyDeleteTables) {
            if (!isset($settings->delete_links_table_on_deactivate)) {
                $deleteLinksTable = true;
            }
            if (!isset($settings->delete_moments_table_on_deactivate)) {
                $deleteMomentsTable = true;
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
        Helper::removePanel(1, self::$commentNotifierPanel);

        if ($deleteLinksTable || $deleteMomentsTable) {
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
                } else {
                    if ($deleteLinksTable) {
                        $db->query('DROP TABLE IF EXISTS `' . $prefix . 'links`');
                    }
                    if ($deleteMomentsTable) {
                        $db->query('DROP TABLE IF EXISTS `' . $prefix . 'moments`');
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
</style>';
        echo '<div class="typecho-option" style="margin-top:12px;">
            <button type="button" class="btn" id="enhancement-links-help-toggle">帮助</button>
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

        $enableCommentByQQ = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_comment_by_qq',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('QQ评论通知'),
            _t('评论通过时通过 QQ 机器人推送通知')
        );
        $form->addInput($enableCommentByQQ);

        $enableCommentNotifier = new Typecho_Widget_Helper_Form_Element_Radio(
            'enable_comment_notifier',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '0',
            _t('评论邮件提醒'),
            _t('评论通过/回复时发送邮件提醒')
        );
        $form->addInput($enableCommentNotifier);

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

        $defaultQqApi = defined('__TYPECHO_COMMENT_BY_QQ_API_URL__')
            ? __TYPECHO_COMMENT_BY_QQ_API_URL__
            : 'https://bot.asbid.cn';
        $qq = new Typecho_Widget_Helper_Form_Element_Text(
            'qq',
            null,
            '',
            _t('<h3 class="enhancement-title">QQ 通知设置</h3>接收通知的QQ号'),
            _t('需要接收通知的QQ号码')
        );
        $form->addInput($qq);

        $qqboturl = new Typecho_Widget_Helper_Form_Element_Text(
            'qqboturl',
            null,
            $defaultQqApi,
            _t('机器人API地址'),
            _t('默认：') . $defaultQqApi
        );
        $form->addInput($qqboturl);

        $fromName = new Typecho_Widget_Helper_Form_Element_Text(
            'fromName',
            null,
            null,
            _t('<h3 class="enhancement-title">邮件提醒设置（SMTP）</h3>发件人昵称'),
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
    }

    public static function finishComment($comment)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);
        $user = Typecho_Widget::widget('Widget_User');
        $commentUrl = isset($comment->url) ? trim((string)$comment->url) : '';
        $commentUrl = self::convertExternalUrlToGo($commentUrl);
        if ($commentUrl !== '') {
            $comment->url = $commentUrl;
        }

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
                $userUrl = self::convertExternalUrlToGo($userUrl);
                $update = $db->update('table.comments')
                    ->rows(array('url' => $userUrl, 'mail' => $user->mail, 'author' => $user->screenName))
                    ->where('authorId =?', $user->uid);
                $db->query($update);
            }
        } else {
            $coid = isset($comment->coid) ? intval($comment->coid) : 0;
            if ($coid > 0 && $commentUrl !== '') {
                self::upgradeCommentUrlByCoid($coid, $commentUrl);
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

    public static function commentByQQ($comment)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::pluginSettings($options);

        if ($comment->status != 'approved') {
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

        $payload = array(
            'user_id' => (int)$qqNum,
            'message' => $message
        );

        if (!function_exists('curl_init')) {
            return;
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => rtrim($apiUrl, '/') . '/send_msg',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json'
            ),
            CURLOPT_SSL_VERIFYPEER => false
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('[Enhancement][CommentsByQQ] CURL错误: ' . curl_error($ch));
        } else {
            error_log(sprintf('[Enhancement][CommentsByQQ] 响应 [HTTP %d]: %s', $httpCode, substr((string)$response, 0, 200)));
        }
        curl_close($ch);
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
        self::upgradeLegacyCommentUrls();

        if (!self::avatarMirrorEnabled()) {
            return;
        }
        if (!defined('__TYPECHO_GRAVATAR_PREFIX__')) {
            define('__TYPECHO_GRAVATAR_PREFIX__', self::avatarBaseUrl());
        }
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

        $coid = isset($widget->coid) ? intval($widget->coid) : 0;
        if ($coid > 0) {
            self::upgradeCommentUrlByCoid($coid, $goUrl);
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
                    ->where('url IS NOT NULL')
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

                $goUrl = self::convertExternalUrlToGo($currentUrl);
                if ($goUrl === '' || $goUrl === $currentUrl) {
                    continue;
                }

                $coid = isset($row['coid']) ? intval($row['coid']) : 0;
                if ($coid <= 0) {
                    continue;
                }

                $db->query(
                    $db->update('table.comments')
                        ->rows(array('url' => $goUrl))
                        ->where('coid = ?', $coid)
                );
            }
        } catch (Exception $e) {
            // ignore batch upgrade errors
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

                if (self::isGoRedirectHref($href)) {
                    return self::normalizeAnchorTagSpacing($tag);
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
            $href = trim((string)$link->getAttribute('href'));
            if (self::isGoRedirectHref($href)) {
                $link->removeAttribute('target');
                continue;
            }

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
        $platformLabel = strtoupper($platform);
        $html = '<div class="enhancement-video-player-wrapper">';
        $html .= '<div class="enhancement-platform-label enhancement-label-' . $platform . '">' . $platformLabel . '</div>';
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

            if ($isContentWidget) {
                if (self::blankTargetEnabled()) {
                    $text = self::addBlankTarget($text);
                }

                if (self::videoParserEnabled()) {
                    $text = self::replaceVideoLinks($text);
                }
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
