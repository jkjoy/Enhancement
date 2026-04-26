<?php

/**
 * Enhancement 插件
 * 具体功能包含:插件/主题zip上传,友情链接,瞬间,网站地图,编辑器增强,站外链接跳转,评论邮件通知,QQ通知,常见视频链接 音乐链接 解析,AI摘要生成等
 * @package Enhancement
 * @author 老孙博客
 * @version 1.2.3
 * @link HTTPS://www.IMSUN.ORG
 * @dependence 14.10.10-*
 */

require_once __DIR__ . '/AttachmentHelper.php';
require_once __DIR__ . '/AiHelper.php';
require_once __DIR__ . '/CommentNotifierHelper.php';
require_once __DIR__ . '/GoRedirectHelper.php';
require_once __DIR__ . '/MediaHelper.php';
require_once __DIR__ . '/ShortcodeHelper.php';
require_once __DIR__ . '/CommentSmileyHelper.php';
require_once __DIR__ . '/S3Helper.php';
require_once __DIR__ . '/TurnstileHelper.php';
require_once __DIR__ . '/CommentUiHelper.php';
require_once __DIR__ . '/QqNotifyHelper.php';
require_once __DIR__ . '/EditorUiHelper.php';
require_once __DIR__ . '/LinkOutputHelper.php';
require_once __DIR__ . '/AvatarHelper.php';
require_once __DIR__ . '/MomentsHelper.php';
require_once __DIR__ . '/FormHelper.php';
require_once __DIR__ . '/SettingsHelper.php';
require_once __DIR__ . '/ConfigUiHelper.php';
require_once __DIR__ . '/ConfigFormHelper.php';
require_once __DIR__ . '/LifecycleHelper.php';
require_once __DIR__ . '/ConfigStorageHelper.php';
require_once __DIR__ . '/CommentWorkflowHelper.php';
require_once __DIR__ . '/ContentParseHelper.php';

class Enhancement_Plugin implements Typecho_Plugin_Interface
{
    public static $commentNotifierPanel = 'Enhancement/CommentNotifier/console.php';

    public static function callback($method)
    {
        return array(self::callbackClass(), (string)$method);
    }

    public static function callbackClass(): string
    {
        if (
            class_exists('\\Typecho\\Plugin', false)
            && class_exists('\\TypechoPlugin\\Enhancement\\Plugin', false)
        ) {
            return '\\TypechoPlugin\\Enhancement\\Plugin';
        }

        return 'Enhancement_Plugin';
    }

    private static function settingsBackupNamePrefix()
    {
        return 'plugin:Enhancement:backup:';
    }

    private static function listSettingsBackups($limit = 5)
    {
        return Enhancement_SettingsHelper::listSettingsBackups($limit);
    }

    private static function pluginSettings($options = null)
    {
        return Enhancement_SettingsHelper::pluginSettings($options);
    }

    public static function runtimeSettings()
    {
        return self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
    }

    private static function readPluginSettingsFromDatabase()
    {
        return Enhancement_SettingsHelper::readPluginSettingsFromDatabase();
    }

    private static function decodePluginConfigValue($value)
    {
        return Enhancement_SettingsHelper::decodePluginConfigValue($value);
    }

    private static function encodePluginConfigValue($settings)
    {
        return Enhancement_SettingsHelper::encodePluginConfigValue($settings);
    }

    private static function ensurePluginConfigOptionExists()
    {
        Enhancement_SettingsHelper::ensurePluginConfigOptionExists();
    }

    private static function normalizeOptionRows($optionName, $ensureGlobalRow = false, $defaultValue = null)
    {
        return Enhancement_SettingsHelper::normalizeOptionRows($optionName, $ensureGlobalRow, $defaultValue);
    }

    private static function normalizePluginConfigValue($value)
    {
        return Enhancement_SettingsHelper::normalizePluginConfigValue($value);
    }

    private static function syncOptionCache($optionName, $optionValue, $pluginName = 'Enhancement')
    {
        Enhancement_SettingsHelper::syncOptionCache($optionName, $optionValue, $pluginName);
    }

    private static function patchOptionsWidgetCache($widget, $optionName, $optionValue, $pluginName = 'Enhancement')
    {
        Enhancement_SettingsHelper::patchOptionsWidgetCache($widget, $optionName, $optionValue, $pluginName);
    }

    private static function buildPluginConfigObject($optionValue)
    {
        return Enhancement_SettingsHelper::buildPluginConfigObject($optionValue);
    }

    public static function getPluginVersion(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }

        $version = '';

        try {
            $info = Typecho_Plugin::parseInfo(__FILE__);
            if (is_array($info) && isset($info['version'])) {
                $version = trim((string)$info['version']);
            }
        } catch (Exception $e) {
            $version = '';
        }

        if ($version === '') {
            $mtime = @filemtime(__FILE__);
            if (is_numeric($mtime) && intval($mtime) > 0) {
                $version = (string)intval($mtime);
            }
        }

        return $version;
    }

    public static function appendVersionToAssetUrl($url): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        $fragment = '';
        $fragmentPos = strpos($url, '#');
        if ($fragmentPos !== false) {
            $fragment = substr($url, $fragmentPos);
            $url = substr($url, 0, $fragmentPos);
        }

        if (preg_match('/(?:^|[?&])v=[^&]*/', $url)) {
            return $url . $fragment;
        }

        $version = self::getPluginVersion();
        if ($version === '') {
            return $url . $fragment;
        }

        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . 'v=' . rawurlencode($version) . $fragment;
    }

    private static function isPhpExtensionReady(string $extension): bool
    {
        $extension = strtolower(trim($extension));
        switch ($extension) {
            case 'curl':
                return extension_loaded('curl') || function_exists('curl_init');
            case 'gd':
                return extension_loaded('gd') || function_exists('gd_info');
            case 'zip':
                return extension_loaded('zip') || class_exists('ZipArchive');
            default:
                return extension_loaded($extension);
        }
    }

    public static function getMissingPhpExtensions(array $extensions = array('curl', 'gd', 'zip')): array
    {
        $missing = array();
        foreach ($extensions as $extension) {
            $extension = strtolower(trim((string)$extension));
            if ($extension === '' || in_array($extension, $missing, true)) {
                continue;
            }

            if (!self::isPhpExtensionReady($extension)) {
                $missing[] = $extension;
            }
        }

        return $missing;
    }

    public static function renderPhpExtensionNotice(array $extensions = array('curl', 'gd', 'zip'))
    {
        $missing = self::getMissingPhpExtensions($extensions);
        if (empty($missing)) {
            return;
        }

        static $stylePrinted = false;
        if (!$stylePrinted) {
            echo '<style>
            .enh-php-ext-warning{margin:10px 0 14px;padding:10px 12px;border:1px solid #f5c2c7;border-radius:6px;background:#fff5f5;color:#842029;line-height:1.7;}
            .enh-php-ext-warning strong{font-weight:600;}
            </style>';
            $stylePrinted = true;
        }

        $labels = array();
        foreach ($missing as $extension) {
            $labels[] = strtoupper((string)$extension);
        }

        echo '<div class="enh-php-ext-warning">'
            . '<strong>环境提醒：</strong>'
            . '当前缺少 PHP 扩展 '
            . htmlspecialchars(implode(', ', $labels), ENT_QUOTES, 'UTF-8')
            . '，请在服务器安装并启用后再使用相关功能（如网络请求、图片处理、ZIP 上传）。'
            . '</div>';
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
        return Enhancement_LifecycleHelper::activate(self::$commentNotifierPanel);
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
        Enhancement_LifecycleHelper::deactivate(self::$commentNotifierPanel, self::runtimeSettings());
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
        self::ensurePluginConfigOptionExists();

        $pluginVersion = self::getPluginVersion();
        Enhancement_ConfigUiHelper::renderConfigChrome($pluginVersion);
        self::renderPhpExtensionNotice();
        Enhancement_ConfigUiHelper::renderLinksHelp();
        Enhancement_ConfigFormHelper::build($form);
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

    public static function configHandle($settings, $isInit)
    {
        Enhancement_ConfigStorageHelper::configHandle($settings, $isInit);
    }

    public static function enhancementInstall()
    {
        return Enhancement_LifecycleHelper::install();
    }

    public static function form($action = null)
    {
        return Enhancement_FormHelper::form($action);
    }

    public static function publicForm()
    {
        return Enhancement_FormHelper::publicForm();
    }

    public static function momentsForm($action = null)
    {
        return Enhancement_FormHelper::momentsForm($action);
    }

    public static function enhancementExists($lid)
    {
        return Enhancement_FormHelper::enhancementExists($lid);
    }

    public static function momentsExists($mid)
    {
        return Enhancement_FormHelper::momentsExists($mid);
    }

    public static function validateHttpUrl($url)
    {
        return Enhancement_FormHelper::validateHttpUrl($url);
    }

    public static function validateOptionalHttpUrl($url)
    {
        return Enhancement_FormHelper::validateOptionalHttpUrl($url);
    }

    public static function extractMediaFromContent($content, &$cleanedContent = null)
    {
        return Enhancement_MomentsHelper::extractMediaFromContent($content, $cleanedContent);
    }

    public static function tencentMapKey(): string
    {
        return Enhancement_MomentsHelper::tencentMapKey();
    }

    public static function normalizeMomentLatitude($latitude)
    {
        return Enhancement_MomentsHelper::normalizeLatitude($latitude);
    }

    public static function normalizeMomentLongitude($longitude)
    {
        return Enhancement_MomentsHelper::normalizeLongitude($longitude);
    }

    public static function normalizeMomentLocationAddress($address, $maxLength = 255)
    {
        return Enhancement_MomentsHelper::normalizeLocationAddress($address, $maxLength);
    }

    public static function validateMomentLatitude($latitude)
    {
        return Enhancement_MomentsHelper::validateLatitude($latitude);
    }

    public static function validateMomentLongitude($longitude)
    {
        return Enhancement_MomentsHelper::validateLongitude($longitude);
    }

    public static function normalizeMomentStatus($status, $default = 'public')
    {
        return Enhancement_MomentsHelper::normalizeStatus($status, $default);
    }

    public static function validateMomentStatus($status)
    {
        return Enhancement_MomentsHelper::validateStatus($status);
    }

    public static function normalizeMomentSource($source, $default = 'web')
    {
        return Enhancement_MomentsHelper::normalizeSource($source, $default);
    }

    public static function detectMomentSourceByUserAgent($userAgent = null)
    {
        return Enhancement_MomentsHelper::detectSourceByUserAgent($userAgent);
    }

    public static function ensureMomentsSourceColumn()
    {
        Enhancement_MomentsHelper::ensureSourceColumn();
    }

    public static function ensureMomentsStatusColumn()
    {
        Enhancement_MomentsHelper::ensureStatusColumn();
    }

    public static function ensureMomentsLocationColumns()
    {
        Enhancement_MomentsHelper::ensureLocationColumns();
    }

    public static function ensureMomentsTable()
    {
        Enhancement_MomentsHelper::ensureTable();
    }

    public static function turnstileEnabled(): bool
    {
        return Enhancement_TurnstileHelper::enabled();
    }

    public static function turnstileSiteKey(): string
    {
        return Enhancement_TurnstileHelper::siteKey();
    }

    public static function turnstileSecretKey(): string
    {
        return Enhancement_TurnstileHelper::secretKey();
    }

    public static function turnstileReady(): bool
    {
        return Enhancement_TurnstileHelper::ready();
    }

    public static function turnstileCommentGuestOnly(): bool
    {
        return Enhancement_TurnstileHelper::commentGuestOnly();
    }

    public static function turnstileVerify($token, $remoteIp = ''): array
    {
        return Enhancement_TurnstileHelper::verify($token, $remoteIp);
    }

    public static function turnstileRenderBlock($formId = ''): string
    {
        return Enhancement_TurnstileHelper::renderBlock($formId);
    }

    public static function turnstileFooter($archive = null)
    {
        Enhancement_CommentUiHelper::renderAuthorLinkEnhancer($archive);
        Enhancement_CommentUiHelper::renderSmileyPicker($archive);
        Enhancement_TurnstileHelper::renderFooter($archive);
    }

    public static function turnstileFilterComment($comment, $post, $last)
    {
        return Enhancement_TurnstileHelper::filterComment($comment, $post, $last);
    }

    public static function finishComment($comment)
    {
        return Enhancement_CommentWorkflowHelper::finishComment($comment);
    }

    public static function commentByQQMark($comment, $edit, $status)
    {
        Enhancement_QqNotifyHelper::mark($comment, $edit, $status);
    }

    public static function notifyLinkSubmissionByQQ(array $link)
    {
        Enhancement_QqNotifyHelper::notifyLinkSubmission($link);
    }

    public static function commentByQQ($comment, $statusOverride = null)
    {
        Enhancement_QqNotifyHelper::comment($comment, $statusOverride);
    }

    public static function commentNotifierGetParent($comment): array
    {
        return Enhancement_CommentNotifierHelper::getParent($comment);
    }

    public static function commentNotifierGetAuthor($comment): array
    {
        return Enhancement_CommentNotifierHelper::getAuthor($comment);
    }

    public static function commentNotifierMark($comment, $edit, $status)
    {
        Enhancement_CommentNotifierHelper::mark($comment, $edit, $status);
    }

    public static function commentNotifierRefinishComment($comment)
    {
        Enhancement_CommentNotifierHelper::refinishComment($comment);
    }

    public static function commentNotifierResendMail($param)
    {
        Enhancement_CommentNotifierHelper::resendMail($param);
    }

    public static function commentNotifierSend($param)
    {
        Enhancement_CommentNotifierHelper::send($param);
    }

    public static function commentNotifierZemail($param)
    {
        return Enhancement_CommentNotifierHelper::zemail($param);
    }

    public static function commentNotifierConfigStr(string $key, $default = '', string $method = 'empty'): string
    {
        return Enhancement_CommentNotifierHelper::configStr($key, $default, $method);
    }

    public static function avatarMirrorEnabled(): bool
    {
        return Enhancement_AvatarHelper::mirrorEnabled();
    }

    public static function avatarBaseUrl(): string
    {
        return Enhancement_AvatarHelper::baseUrl();
    }

    public static function applyAvatarPrefix($archive = null, $select = null)
    {
        Enhancement_AvatarHelper::applyPrefix($archive, $select);
    }

    public static function filterCommentRowUrl($row, $widget = null, $lastRow = null)
    {
        return Enhancement_AvatarHelper::filterCommentRowUrl($row, $widget, $lastRow);
    }

    public static function buildAvatarUrl($email, $size = null, $default = null, array $extra = array()): string
    {
        return Enhancement_AvatarHelper::buildUrl($email, $size, $default, $extra);
    }

    private static function writeEditorBottom($includeTagsList = false)
    {
        Enhancement_EditorUiHelper::renderBottom($includeTagsList);
    }

    public static function writePostBottom()
    {
        self::writeEditorBottom(true);
    }

    public static function writePageBottom()
    {
        self::writeEditorBottom(false);
    }

    public static function aiSlugEditorHelper()
    {
        Enhancement_EditorUiHelper::renderAiSlugHelper();
    }

    public static function shortcodesHelper()
    {
        Enhancement_EditorUiHelper::renderShortcodesHelper();
    }

    public static function tagsList()
    {
        Enhancement_EditorUiHelper::renderTagsList();
    }

    /**
     * 控制输出格式
     */
    public static function output_str($widget, array $params)
    {
        return Enhancement_LinkOutputHelper::output($params);
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
        return Enhancement_LinkOutputHelper::parseCallback($matches);
    }

    public static function videoParserEnabled(): bool
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        if (!isset($settings->enable_video_parser)) {
            return false;
        }
        return $settings->enable_video_parser == '1';
    }

    public static function musicParserEnabled(): bool
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        if (!isset($settings->enable_music_parser)) {
            return false;
        }
        return $settings->enable_music_parser == '1';
    }

    public static function commentSmileyEnabled(): bool
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        if (!isset($settings->enable_comment_smiley)) {
            return true;
        }
        return $settings->enable_comment_smiley == '1';
    }

    public static function attachmentPreviewEnabled(): bool
    {
        $settings = self::pluginSettings(Typecho_Widget::widget('Widget_Options'));
        if (!isset($settings->enable_attachment_preview)) {
            return false;
        }
        return $settings->enable_attachment_preview == '1';
    }

    public static function s3UploadEnabled(): bool
    {
        return Enhancement_S3Helper::enabled();
    }

    public static function s3UploadHandle($file)
    {
        return Enhancement_S3Helper::uploadHandle($file);
    }

    public static function s3ModifyHandle($content, $file)
    {
        return Enhancement_S3Helper::modifyHandle($content, $file);
    }

    public static function s3DeleteHandle($content)
    {
        return Enhancement_S3Helper::deleteHandle($content);
    }

    public static function s3AttachmentHandle($content)
    {
        return Enhancement_S3Helper::attachmentHandle($content);
    }

    public static function s3AttachmentDataHandle($content)
    {
        return Enhancement_S3Helper::attachmentDataHandle($content);
    }

    public static function s3UploadConfigured(): bool
    {
        return Enhancement_S3Helper::configured();
    }

    public static function resolveAttachmentUrl($content)
    {
        return Enhancement_S3Helper::resolveAttachmentUrl($content);
    }

    public static function aiSummaryEnabled(): bool
    {
        return Enhancement_AiHelper::summaryEnabled();
    }

    public static function aiSlugTranslateEnabled(): bool
    {
        return Enhancement_AiHelper::slugTranslateEnabled();
    }

    public static function handlePostFinishPublish($contents, $edit)
    {
        Enhancement_AiHelper::handlePostFinishPublish($contents, $edit);
    }

    public static function previewAiSlug(string $title, int $cid = 0): array
    {
        return Enhancement_AiHelper::previewSlug($title, $cid);
    }

    public static function autoGeneratePostSummary($contents, $edit, $force = false)
    {
        return Enhancement_AiHelper::autoGeneratePostSummary($contents, $edit, $force);
    }

    public static function archiveHeader($archive = null)
    {
        Enhancement_ShortcodeHelper::renderStyles();
        Enhancement_MediaHelper::archiveHeader($archive);
    }

    public static function blankTargetEnabled(): bool
    {
        return Enhancement_GoRedirectHelper::blankTargetEnabled();
    }

    public static function goRedirectEnabled(): bool
    {
        return Enhancement_GoRedirectHelper::goRedirectEnabled();
    }

    public static function encodeGoTarget($url)
    {
        return Enhancement_GoRedirectHelper::encodeGoTarget($url);
    }

    public static function decodeGoTarget($token)
    {
        return Enhancement_GoRedirectHelper::decodeGoTarget($token);
    }

    public static function buildGoRedirectUrl($url)
    {
        return Enhancement_GoRedirectHelper::buildGoRedirectUrl($url);
    }

    public static function parse($text, $widget, $lastResult)
    {
        return Enhancement_ContentParseHelper::parse($text, $widget, $lastResult);
    }
}

if (!class_exists('\\TypechoPlugin\\Enhancement\\Plugin', false)) {
    class_alias('Enhancement_Plugin', '\\TypechoPlugin\\Enhancement\\Plugin');
}
