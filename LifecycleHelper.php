<?php

class Enhancement_LifecycleHelper
{
    public static function activate($commentNotifierPanel)
    {
        Enhancement_SettingsHelper::ensurePluginConfigOptionExists();

        $info = self::install();
        Helper::addPanel(3, 'Enhancement/manage-enhancement.php', _t('链接'), _t('链接审核与管理'), 'administrator');
        Helper::addPanel(3, 'Enhancement/manage-moments.php', _t('瞬间'), _t('瞬间管理'), 'administrator');
        Helper::addPanel(3, 'Enhancement/manage-ai-summary.php', _t('摘要'), _t('AI 摘要批量生成'), 'administrator');
        Helper::addPanel(1, 'Enhancement/manage-upload.php', _t('上传'), _t('上传管理'), 'administrator');
        Helper::addPanel(1, $commentNotifierPanel, _t('邮件提醒外观'), _t('评论邮件提醒主题列表'), 'administrator');
        Helper::addRoute('sitemap', '/sitemap.xml', 'Enhancement_Sitemap_Action', 'action');
        Helper::addRoute('memos_api', '/api/v1/memos', 'Enhancement_Memos_Action', 'action');
        Helper::addRoute('zemail', '/zemail', 'Enhancement_CommentNotifier_Action', 'action');
        Helper::addRoute('go', '/go/[target]', 'Enhancement_Action', 'goRedirect');
        Helper::addAction('enhancement-edit', 'Enhancement_Action');
        Helper::addAction('enhancement-submit', 'Enhancement_Action');
        Helper::addAction('enhancement-moments-edit', 'Enhancement_Action');
        Typecho_Plugin::factory('Widget_Feedback')->comment_1 = Enhancement_Plugin::callback('turnstileFilterComment');
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = Enhancement_Plugin::callback('finishComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = Enhancement_Plugin::callback('finishComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark = Enhancement_Plugin::callback('commentNotifierMark');
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark_2 = Enhancement_Plugin::callback('commentByQQMark');
        Typecho_Plugin::factory('Widget_Service')->send = Enhancement_Plugin::callback('commentNotifierSend');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = Enhancement_Plugin::callback('handlePostFinishPublish');
        Typecho_Plugin::factory('admin/write-post.php')->bottom = Enhancement_Plugin::callback('writePostBottom');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = Enhancement_Plugin::callback('writePageBottom');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = Enhancement_Plugin::callback('parse');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = Enhancement_Plugin::callback('parse');
        Typecho_Plugin::factory('Widget_Abstract_Comments')->contentEx = Enhancement_Plugin::callback('parse');
        Typecho_Plugin::factory('Widget_Archive')->handleInit = Enhancement_Plugin::callback('applyAvatarPrefix');
        Typecho_Plugin::factory('Widget_Archive')->header = Enhancement_Plugin::callback('archiveHeader');
        Typecho_Plugin::factory('Widget_Archive')->footer = Enhancement_Plugin::callback('turnstileFooter');
        Typecho_Plugin::factory('Widget_Archive')->callEnhancement = Enhancement_Plugin::callback('output_str');
        Enhancement_S3Helper::registerHooks();

        return _t($info);
    }

    public static function deactivate($commentNotifierPanel, $settings)
    {
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
        Helper::removePanel(3, 'Enhancement/manage-ai-summary.php');
        Helper::removePanel(1, 'Enhancement/manage-upload.php');
        Helper::removePanel(1, $commentNotifierPanel);

        self::dropTables($deleteLinksTable, $deleteMomentsTable, $deleteQqQueueTable);
    }

    public static function install()
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
            }

            throw new Typecho_Plugin_Exception(_t('数据表建立失败，插件启用失败。错误号：') . $code);
        }
    }

    private static function dropTables($deleteLinksTable, $deleteMomentsTable, $deleteQqQueueTable)
    {
        if (!$deleteLinksTable && !$deleteMomentsTable && !$deleteQqQueueTable) {
            return;
        }

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
                return;
            }

            if ($deleteLinksTable) {
                $db->query('DROP TABLE IF EXISTS `' . $prefix . 'links`');
            }
            if ($deleteMomentsTable) {
                $db->query('DROP TABLE IF EXISTS `' . $prefix . 'moments`');
            }
            if ($deleteQqQueueTable) {
                $db->query('DROP TABLE IF EXISTS `' . $prefix . 'qq_notify_queue`');
            }
        } catch (Exception $e) {
            // ignore drop errors on deactivate
        }
    }
}
