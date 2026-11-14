<?php

class Enhancement_LifecycleHelper
{
    public static function activate($commentNotifierPanel)
    {
        self::repairCorePluginsOption();
        Enhancement_SettingsHelper::ensurePluginConfigOptionExists();
        self::ensurePanelFiles($commentNotifierPanel);

        $info = self::install();
        Helper::addPanel(3, 'Enhancement/manage-enhancement.php', _t('链接'), _t('链接审核与管理'), 'administrator');
        Helper::addPanel(3, 'Enhancement/manage-moments.php', _t('瞬间'), _t('瞬间管理'), 'administrator');
        Helper::addPanel(3, 'Enhancement/manage-ai-summary.php', _t('摘要'), _t('AI 摘要批量生成'), 'administrator');
        self::ensureFriendPostsPanel();
        Helper::addPanel(1, 'Enhancement/manage-upload.php', _t('上传'), _t('上传管理'), 'administrator');
        Helper::addPanel(1, $commentNotifierPanel, _t('邮件提醒外观'), _t('评论邮件提醒主题列表'), 'administrator');
        Helper::addRoute('sitemap', '/sitemap.xml', 'Enhancement_Sitemap_Action', 'action');
        Helper::addRoute('memos_api', '/api/v1/memos', 'Enhancement_Memos_Action', 'action');
        Helper::addRoute('zemail', '/zemail', 'Enhancement_CommentNotifier_Action', 'action');
        Helper::addRoute('go', '/go/[target]', 'Enhancement_Action', 'goRedirect');
        Enhancement_RestApiHelper::syncRoutes();
        Helper::addAction('enhancement-edit', 'Enhancement_Action');
        Helper::addAction('enhancement-submit', 'Enhancement_Action');
        Helper::addAction('enhancement-moments-edit', 'Enhancement_Action');
        Typecho_Plugin::factory('index.php')->begin = Enhancement_Plugin::callback('botMomentsCronTick');
        Typecho_Plugin::factory('admin/common.php')->begin = Enhancement_Plugin::callback('botMomentsCronTick');
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
        self::registerReplyPrivacyHooks(true);
        Typecho_Plugin::factory('Widget_Abstract_Comments')->contentEx = Enhancement_Plugin::callback('parse');
        Typecho_Plugin::factory('Widget_Archive')->handleInit = Enhancement_Plugin::callback('applyAvatarPrefix');
        Typecho_Plugin::factory('Widget_Archive')->header = Enhancement_Plugin::callback('archiveHeader');
        Typecho_Plugin::factory('Widget_Archive')->footer = Enhancement_Plugin::callback('turnstileFooter');
        Typecho_Plugin::factory('Widget_Archive')->callEnhancement = Enhancement_Plugin::callback('output_str');
        if (Enhancement_S3Helper::enabled()) {
            Enhancement_S3Helper::registerHooks();
        }

        return _t($info);
    }

    /** Runtime upgrades must not enter Typecho's pending plugin-activation hooks. */
    public static function registerReplyPrivacyHooks($activating = false)
    {
        $plugins = Typecho_Plugin::export();
        $hooks = array('excerpt' => 'hideReplyForExcerpt', 'excerptEx' => 'parseExcerpt');
        $normalize = function (&$callbacks, $target, $replaceLegacy) {
            $callbacks = is_array($callbacks) ? $callbacks : array();
            $found = false;
            foreach ($callbacks as $position => $existing) {
                $legacy = $replaceLegacy && is_array($existing) && isset($existing[0], $existing[1])
                    && $existing[1] === 'parse' && in_array(strtolower(ltrim((string)$existing[0], '\\')),
                        array('enhancement_plugin', 'typechoplugin\\enhancement\\plugin'), true);
                if ($existing === $target || $legacy) {
                    if ($found) {
                        unset($callbacks[$position]);
                    } else {
                        $callbacks[$position] = $target;
                        $found = true;
                    }
                }
            }
            if (!$found) {
                $weight = $callbacks ? max(array_map('floatval', array_keys($callbacks))) + 0.001 : 0;
                $callbacks[(string)$weight] = $target;
            }
        };
        foreach ($hooks as $hook => $method) {
            $key = 'Widget_Abstract_Contents:' . $hook;
            $target = array('Enhancement_ContentParseHelper', $method);
            $normalize($plugins['handles'][$key], $target, $hook === 'excerptEx');
            if (isset($plugins['activated']['Enhancement'])) {
                $normalize($plugins['activated']['Enhancement']['handles'][$key], $target, $hook === 'excerptEx');
            }
            if ($activating) {
                // Normal activation owns these callbacks through Typecho's tmp
                // record. Remove runtime copies before using the activation API.
                foreach ($plugins['handles'][$key] as $position => $existing) {
                    if ($existing === $target) {
                        unset($plugins['handles'][$key][$position]);
                    }
                }
            }
        }
        Typecho_Plugin::init($plugins);
        if ($activating) {
            foreach ($hooks as $hook => $method) {
                Typecho_Plugin::factory('Widget_Abstract_Contents')->{$hook} = array('Enhancement_ContentParseHelper', $method);
            }
        }
    }

    /** Register the new menu on in-place upgrades without disabling the plugin. */
    public static function ensureFriendPostsPanel()
    {
        $path = 'Enhancement/manage-friend-posts.php';
        $panelTable = Typecho_Widget::widget('Widget_Options')->panelTable;
        $files = is_array($panelTable) && isset($panelTable['file']) && is_array($panelTable['file']) ? $panelTable['file'] : array();
        if (!in_array(urlencode($path), $files, true) && !in_array($path, $files, true)) {
            Helper::addPanel(3, $path, _t('朋友文章'), _t('朋友文章订阅与缓存'), 'administrator');
        }
    }

    private static function repairCorePluginsOption()
    {
        $defaultPlugins = array(
            'activated' => array(),
            'handles' => array()
        );

        try {
            $db = Typecho_Db::get();
            $rows = $db->fetchAll(
                $db->select('user', 'value')
                    ->from('table.options')
                    ->where('name = ?', 'plugins')
            );

            $hasGlobalRow = false;
            foreach (is_array($rows) ? $rows : array() as $row) {
                $userId = isset($row['user']) ? intval($row['user']) : 0;
                $plugins = Enhancement_SettingsHelper::decodePluginConfigValue(isset($row['value']) ? (string)$row['value'] : '');
                foreach (array('activated', 'handles') as $key) {
                    if (!isset($plugins[$key]) || !is_array($plugins[$key])) {
                        $plugins[$key] = array();
                    }
                }

                if ($userId === 0) {
                    $hasGlobalRow = true;
                }

                $encoded = self::encodeCorePluginsOption($plugins);
                if (!isset($row['value']) || (string)$row['value'] !== $encoded) {
                    $db->query(
                        $db->update('table.options')
                            ->rows(array('value' => $encoded))
                            ->where('name = ?', 'plugins')
                            ->where('user = ?', $userId)
                    );
                }
            }

            if (!$hasGlobalRow) {
                $db->query(
                    $db->insert('table.options')->rows(array(
                        'name' => 'plugins',
                        'user' => 0,
                        'value' => self::encodeCorePluginsOption($defaultPlugins)
                    ))
                );
            }
        } catch (Exception $e) {
            // Typecho itself will report plugin option errors if repair is not possible.
        }
    }

    private static function encodeCorePluginsOption(array $plugins)
    {
        if (class_exists('\\Typecho\\Plugin', false)) {
            $encoded = json_encode($plugins);
            return is_string($encoded) && $encoded !== '' ? $encoded : '{"activated":[],"handles":[]}';
        }

        $encoded = @serialize($plugins);
        return is_string($encoded) && $encoded !== '' ? $encoded : 'a:2:{s:9:"activated";a:0:{}s:7:"handles";a:0:{}}';
    }

    private static function ensurePanelFiles($commentNotifierPanel)
    {
        $files = array(
            'manage-enhancement.php',
            'manage-moments.php',
            'manage-ai-summary.php',
            'manage-friend-posts.php',
            'manage-upload.php',
            str_replace('Enhancement/', '', $commentNotifierPanel)
        );

        $missing = array();
        foreach (array_unique($files) as $file) {
            if (!is_file(__DIR__ . '/' . $file)) {
                $missing[] = 'Enhancement/' . $file;
            }
        }

        if (!empty($missing)) {
            throw new Typecho_Plugin_Exception(_t('插件文件不完整，缺少：') . implode(', ', $missing));
        }
    }

    public static function deactivate($commentNotifierPanel, $settings)
    {
        $legacy = isset($settings->delete_tables_on_deactivate) && $settings->delete_tables_on_deactivate == '1';
        $deleteLinksTable = isset($settings->delete_links_table_on_deactivate) ? $settings->delete_links_table_on_deactivate == '1' : $legacy;
        $deleteMomentsTable = isset($settings->delete_moments_table_on_deactivate) ? $settings->delete_moments_table_on_deactivate == '1' : $legacy;
        $deleteQqQueueTable = isset($settings->delete_qq_queue_table_on_deactivate)
            ? $settings->delete_qq_queue_table_on_deactivate == '1'
            : ($deleteMomentsTable || $legacy);

        Helper::removeRoute('sitemap');
        Helper::removeRoute('memos_api');
        Helper::removeRoute('zemail');
        Helper::removeRoute('go');
        Enhancement_RestApiHelper::removeRoutes();
        Helper::removeAction('enhancement-edit');
        Helper::removeAction('enhancement-submit');
        Helper::removeAction('enhancement-moments-edit');
        Helper::removePanel(3, 'Enhancement/manage-enhancement.php');
        Helper::removePanel(3, 'Enhancement/manage-moments.php');
        Helper::removePanel(3, 'Enhancement/manage-ai-summary.php');
        Helper::removePanel(3, 'Enhancement/manage-friend-posts.php');
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
        $scripts = file_get_contents(__DIR__ . '/sql/' . $type . '.sql');
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
        $quote = 'Pgsql' == array_pop($type) ? '"' : '`';

        try {
            foreach (array('links' => $deleteLinksTable, 'moments' => $deleteMomentsTable, 'qq_notify_queue' => $deleteQqQueueTable) as $table => $drop) {
                if ($drop) {
                    $db->query('DROP TABLE IF EXISTS ' . $quote . $prefix . $table . $quote);
                }
            }
        } catch (Exception $e) {
            // ignore drop errors on deactivate
        }
    }
}
