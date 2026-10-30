<?php

require_once __DIR__ . '/NotifyTransportHelper.php';
require_once __DIR__ . '/QqOfficialHelper.php';

class Enhancement_QqNotifyHelper
{
    public const OFFICIAL_QUEUE_PREFIX = '__ENHANCEMENT_QQ_API_V2__:';
    private static $queueProcessed = false;
    private static $queueEnsured = false;

    private static function runtimeSettings()
    {
        return Enhancement_Plugin::runtimeSettings();
    }

    public static function normalizeSettings(array $settings): array
    {
        $provider = $settings['qq_notify_provider'] ?? 'none';
        $hasCredentials = is_scalar($settings['qq_app_id'] ?? null) && (string)$settings['qq_app_id'] !== ''
            && is_scalar($settings['qq_app_secret'] ?? null) && (string)$settings['qq_app_secret'] !== '';
        $hasService = isset($settings['qq_official_url']) || isset($settings['qq_official_token']) || isset($settings['qq_official_targets']);
        if ($provider === 'direct') {
            $settings['qq_notify_provider'] = $hasCredentials ? 'official' : 'none';
            unset($settings['qq_moments_enabled']);
            if (isset($settings['qq_direct_include_links'])) { $settings['qq_official_include_links'] = $settings['qq_direct_include_links']; }
        } elseif (($provider === 'official' && $hasService && !$hasCredentials)
            || in_array($provider, array('legacy', 'openclaw', 'hermes', 'standalone'), true)) {
            $settings['qq_notify_provider'] = 'none';
            unset($settings['qq_moments_enabled']);
        } elseif (!isset($settings['qq_notify_provider'])) {
            $settings['qq_notify_provider'] = 'none';
            unset($settings['qq_moments_enabled']);
        }
        unset($settings['qq'], $settings['qqboturl'], $settings['qq_async_queue'],
            $settings['qq_agent_url'], $settings['qq_agent_token'], $settings['qq_agent_target'],
            $settings['qq_agent_account'], $settings['qq_agent_include_links'],
            $settings['qq_agent_commands'], $settings['qq_agent_command_secret'], $settings['qq_agent_allowed_senders'], $settings['qq_agent_admin_uid'],
            $settings['qq_target_type'], $settings['qq_target_id'], $settings['qq_direct_include_links'], $settings['qq_moments_allowed_senders'],
            $settings['qq_official_url'], $settings['qq_official_token'], $settings['qq_official_targets'],
            $settings['qq_moments_secret'], $settings['qq_moments_targets']);
        return $settings;
    }

    public static function validateSettings(array $settings)
    {
        $settings = self::normalizeSettings($settings);
        foreach (array('qq_notify_provider', 'qq_app_id', 'qq_app_secret', 'qq_official_include_links', 'qq_moments_enabled') as $key) {
            if (isset($settings[$key]) && !is_scalar($settings[$key])) {
                throw new Typecho_Widget_Exception(_t('QQ Bot 配置项必须是文本或开关值'), 400);
            }
        }
        $provider = (string)$settings['qq_notify_provider'];
        if (!in_array($provider, array('none', 'official'), true)) {
            throw new Typecho_Widget_Exception(_t('QQ 通知方式无效'), 400);
        }
        if (isset($settings['qq_moments_enabled']) && !in_array((string)$settings['qq_moments_enabled'], array('0', '1'), true)) {
            throw new Typecho_Widget_Exception(_t('QQ 发布瞬间的开关值无效'), 400);
        }
        $momentsEnabled = ($settings['qq_moments_enabled'] ?? '0') == '1';
        if ($momentsEnabled && $provider !== 'official') {
            throw new Typecho_Widget_Exception(_t('通过 QQ 发布瞬间需要先配置官方 QQ Bot 的 AppID 和 AppSecret'), 400);
        }
        if ($provider !== 'official') {
            return;
        }
        Enhancement_QqOfficialHelper::validateSettings($settings);
    }

    private static function provider($settings): string
    {
        return ($settings->qq_notify_provider ?? '') === 'official' ? 'official' : 'none';
    }

    private static function officialConfigurationError($settings): string
    {
        $config = is_object($settings) && method_exists($settings, 'toArray') ? $settings->toArray() : (array)$settings;
        try { Enhancement_QqOfficialHelper::validateSettings($config); }
        catch (Throwable $exception) { return $exception->getMessage(); }
        if (Enhancement_QqOfficialHelper::binding($settings) === array()) { return _t('请先在后台生成绑定码，并在 QQ 中发送绑定指令'); }
        return '';
    }

    public static function sendTestMessage(string $message, $settings = null): array
    {
        $settings = $settings === null ? self::runtimeSettings() : (object)$settings;
        if (self::provider($settings) === 'official') {
            $error = self::officialConfigurationError($settings);
            if ($error !== '') {
                return array('success' => false, 'error' => $error);
            }
            return Enhancement_QqOfficialHelper::send($message, $settings);
        }
        return array('success' => false, 'error' => _t('请先选择官方 QQ Bot 并保存 AppID 和 AppSecret'));
    }

    public static function mark($comment, $edit, $status)
    {
        $status = trim((string)$status);
        if ($status !== 'approved') {
            return;
        }

        $settings = self::runtimeSettings();
        if (!isset($settings->enable_comment_by_qq) || $settings->enable_comment_by_qq != '1') {
            return;
        }

        self::comment($edit, 'approved');
    }

    public static function notifyLinkSubmission(array $link)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = self::runtimeSettings();
        if (!isset($settings->enable_link_submit_by_qq) || $settings->enable_link_submit_by_qq != '1') {
            return;
        }
        if (!self::configured($settings)) {
            return;
        }

        $siteTitle = isset($options->title) ? trim((string)$options->title) : '';
        if ($siteTitle === '') {
            $siteTitle = '网站';
        }

        $linkName = isset($link['name']) ? trim((string)$link['name']) : '';
        $linkUrl = isset($link['url']) ? trim((string)$link['url']) : '';
        $linkEmail = isset($link['email']) ? trim((string)$link['email']) : '';
        $linkDescription = isset($link['description']) ? trim((string)$link['description']) : '';
        $reviewUrl = Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $options->adminUrl);

        $message = sprintf(
            "【友情链接申请通知】\n"
            . "编号：%s\n"
            . "🌐 站点：%s\n"
            . "🔖 友链名称：%s\n"
            . "🔗 友链地址：%s\n"
            . "📮 申请邮箱：%s\n"
            . "📝 网站描述：%s\n"
            . "🕒 提交时间：%s\n"
            . "🛠 审核地址：%s",
            isset($link['lid']) ? (string)(int)$link['lid'] : '-',
            $siteTitle,
            $linkName !== '' ? $linkName : '-',
            $linkUrl !== '' ? $linkUrl : '-',
            $linkEmail !== '' ? $linkEmail : '-',
            $linkDescription !== '' ? $linkDescription : '-',
            date('Y-m-d H:i:s'),
            $reviewUrl
        );

        self::dispatchMessage($message, $settings);
    }

    public static function comment($comment, $statusOverride = null)
    {
        $settings = self::runtimeSettings();
        if (!isset($settings->enable_comment_by_qq) || $settings->enable_comment_by_qq != '1') {
            return;
        }

        $status = $statusOverride !== null
            ? trim((string)$statusOverride)
            : (isset($comment->status) ? trim((string)$comment->status) : '');
        if ($status !== 'approved') {
            return;
        }

        if ($comment->authorId === $comment->ownerId) {
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
            . "编号：%s\n"
            . "📝 评论者：%s\n"
            . "📖 文章标题：《%s》\n"
            . "💬 评论内容：%s\n"
            . "🔗 文章链接：%s",
            isset($comment->coid) ? (string)(int)$comment->coid : '-',
            $comment->author,
            $comment->title,
            $commentText,
            $comment->permalink
        );

        self::dispatchMessage((string)$message, $settings);
    }

    public static function processQueue()
    {
        if (self::$queueProcessed) {
            return;
        }
        self::$queueProcessed = true;

        $settings = self::runtimeSettings();
        if (!self::featureEnabled($settings)) {
            return;
        }
        self::ensureQueueTable();

        try {
            $db = Typecho_Db::get();
            $table = $db->getPrefix() . 'qq_notify_queue';
            self::retireObsoleteQueue($db, $table);
            if (self::provider($settings) !== 'official') {
                return;
            }
            $query = $db->select()
                ->from($table)
                ->where('status = ?', 0)
                ->where('retries < ?', 5)
                ->order('qid', Typecho_Db::SORT_ASC)
                ->limit(1);
            $row = $db->fetchRow($query);

            if (!is_array($row) || empty($row)) {
                return;
            }

            $qid = isset($row['qid']) ? intval($row['qid']) : 0;
            $message = isset($row['message']) ? (string)$row['message'] : '';
            $retries = isset($row['retries']) ? intval($row['retries']) : 0;
            if ($qid <= 0 || trim($message) === '') {
                return;
            }

            if (strpos($message, self::OFFICIAL_QUEUE_PREFIX) !== 0) {
                return;
            }
            $result = self::sendOfficial(json_decode(substr($message, strlen(self::OFFICIAL_QUEUE_PREFIX)), true), $settings);
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

    private static function retireObsoleteQueue($db, string $table)
    {
        $db->query($db->update($table)
            ->rows(array(
                'status' => 2,
                'retries' => 5,
                'updated' => time(),
                'last_error' => _t('旧 QQ 机器人推送方式已移除，此通知停止投递')
            ))
            ->where('status = ?', 0)
            ->where('SUBSTR(message, 1, ?) <> ?', strlen(self::OFFICIAL_QUEUE_PREFIX), self::OFFICIAL_QUEUE_PREFIX));
        $rows = $db->fetchAll($db->select('qid', 'message')->from($table)->where('status = ?', 0));
        foreach ($rows as $row) {
            $envelope = json_decode(substr((string)$row['message'], strlen(self::OFFICIAL_QUEUE_PREFIX)), true);
            if (!is_array($envelope) || ($envelope['provider'] ?? '') !== 'official') {
                $db->query($db->update($table)->rows(array('status' => 2, 'retries' => 5, 'updated' => time(),
                    'last_error' => _t('此 QQ 通知方式已移除，停止投递；请配置官方 QQ Bot 的 AppID 和 AppSecret')))
                    ->where('qid = ?', $row['qid'])->where('status = ?', 0));
            }
        }
    }

    public static function getQueueStats(): array
    {
        self::ensureQueueTable();

        $stats = array(
            'pending' => 0,
            'success' => 0,
            'failed' => 0,
            'total' => 0,
        );

        try {
            $db = Typecho_Db::get();
            $table = $db->getPrefix() . 'qq_notify_queue';
            self::retireObsoleteQueue($db, $table);
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

    private static function featureEnabled($settings = null): bool
    {
        if ($settings === null) {
            $settings = self::runtimeSettings();
        }

        return (isset($settings->enable_comment_by_qq) && $settings->enable_comment_by_qq == '1')
            || (isset($settings->enable_link_submit_by_qq) && $settings->enable_link_submit_by_qq == '1');
    }

    private static function configured($settings = null): bool
    {
        if ($settings === null) {
            $settings = self::runtimeSettings();
        }

        if (self::provider($settings) === 'official') {
            return self::officialConfigurationError($settings) === '';
        }
        return false;
    }

    private static function dispatchMessage(string $message, $settings = null)
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        if ($settings === null) {
            $settings = self::runtimeSettings();
        }

        if (!self::configured($settings)) {
            return;
        }

        if (self::provider($settings) === 'official') {
            $envelope = self::officialEnvelope($message, $settings);
            $encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return;
            }
            // Persist the recipient and credential identity before calling the official API.
            $qid = self::enqueue(self::OFFICIAL_QUEUE_PREFIX . $encoded);
            $result = self::sendOfficial($envelope, $settings);
            if ($qid > 0) {
                self::recordOfficialResult($qid, $result);
            } elseif (empty($result['success'])) {
                error_log('[Enhancement][QQBot] 官方接口发送和本地重试队列均失败，请检查数据库及 QQ 配置。');
            }
            return;
        }
    }

    private static function enqueue(string $message): int
    {
        $message = trim($message);
        if ($message === '') {
            return 0;
        }

        self::ensureQueueTable();

        try {
            $db = Typecho_Db::get();
            $table = $db->getPrefix() . 'qq_notify_queue';
            return (int)$db->query(
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
            return 0;
        }
    }

    private static function ensureQueueTable()
    {
        if (self::$queueEnsured) {
            return;
        }
        self::$queueEnsured = true;

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

    public static function prepareMessage(string $message, bool $includeLinks = true): string
    {
        if (!preg_match('//u', $message)) {
            $message = (string)json_decode(json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE), true);
        }
        $message = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $message);
        if (!$includeLinks) {
            $message = preg_replace('~(?:[a-z][a-z0-9+.-]*://|www\.)[^\s<>\x{3000}]+|\bmailto:[^\s<>]+~iu', '[链接已省略]', $message);
        }
        if (strlen($message) > 1800) {
            $message = substr($message, 0, 1797);
            while ($message !== '' && !preg_match('//u', $message)) {
                $message = substr($message, 0, -1);
            }
            $message .= '…';
        }
        return trim($message);
    }

    private static function officialEnvelope(string $message, $settings): array
    {
        return array(
            'provider' => self::provider($settings),
            'message' => self::prepareMessage($message, !isset($settings->qq_official_include_links) || $settings->qq_official_include_links == '1'),
            'identity' => Enhancement_QqOfficialHelper::credentialsIdentity($settings),
            'binding' => Enhancement_QqOfficialHelper::binding($settings),
            'requestId' => bin2hex(random_bytes(20))
        );
    }

    private static function recordOfficialResult(int $qid, array $result)
    {
        try {
            $db = Typecho_Db::get();
            $db->query($db->update($db->getPrefix() . 'qq_notify_queue')
                ->rows(array(
                    'status' => !empty($result['success']) ? 1 : 0,
                    'last_error' => !empty($result['success']) ? null : Typecho_Common::subStr((string)$result['error'], 0, 250, ''),
                    'updated' => time()
                ))
                ->where('qid = ?', $qid));
        } catch (Exception $e) {
            // A later retry keeps the original recipient snapshot.
        }
    }

    private static function sendOfficial($envelope, $settings): array
    {
        $failure = array('success' => false, 'error' => _t('QQ 凭证或接收人绑定已变化，旧通知停止投递'));
        if (self::provider($settings) !== 'official' || !is_array($envelope)
            || ($envelope['provider'] ?? '') !== 'official' || !is_string($envelope['message'] ?? null)
            || !is_array($envelope['binding'] ?? null) || empty($envelope['binding'])
            || !is_string($envelope['identity'] ?? null)
            || !hash_equals(Enhancement_QqOfficialHelper::credentialsIdentity($settings), $envelope['identity'])
            || Enhancement_QqOfficialHelper::binding($settings) !== $envelope['binding']) {
            return $failure;
        }
        return Enhancement_QqOfficialHelper::send($envelope['message'], $settings);
    }
}
