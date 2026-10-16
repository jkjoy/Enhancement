<?php

require_once __DIR__ . '/QqCommandSettingsHelper.php';

class Enhancement_QqNotifyHelper
{
    public const OFFICIAL_QUEUE_PREFIX = '__ENHANCEMENT_QQ_OFFICIAL_V1__:';
    private static $queueProcessed = false;
    private static $queueEnsured = false;

    private static function runtimeSettings()
    {
        return Enhancement_Plugin::runtimeSettings();
    }

    public static function normalizeSettings(array $settings): array
    {
        // Old installations and backups must never select a replacement transport automatically.
        unset($settings['qq'], $settings['qqboturl'], $settings['qq_async_queue'],
            $settings['qq_agent_url'], $settings['qq_agent_token'], $settings['qq_agent_target'],
            $settings['qq_agent_account'], $settings['qq_agent_include_links']);
        if (!isset($settings['qq_notify_provider']) || in_array($settings['qq_notify_provider'], array('legacy', 'openclaw', 'hermes'), true)) {
            $settings['qq_notify_provider'] = 'none';
            $settings['qq_agent_commands'] = '0';
        }
        return $settings;
    }

    public static function validateSettings(array $settings)
    {
        $settings = self::normalizeSettings($settings);
        foreach (array('qq_notify_provider', 'qq_official_url', 'qq_official_token', 'qq_official_targets', 'qq_official_include_links') as $key) {
            if (isset($settings[$key]) && !is_scalar($settings[$key])) {
                throw new Typecho_Widget_Exception(_t('QQ Bot 配置项必须是文本或开关值'), 400);
            }
        }
        $provider = (string)$settings['qq_notify_provider'];
        if (!in_array($provider, array('none', 'official', 'standalone'), true)) {
            throw new Typecho_Widget_Exception(_t('QQ 通知方式无效'), 400);
        }
        Enhancement_QqCommandSettingsHelper::validateSettings($settings);
        if (!in_array($provider, array('official', 'standalone'), true)) {
            return;
        }
        $error = self::officialConfigurationError((object)$settings);
        if ($error !== '') {
            throw new Typecho_Widget_Exception($error, 400);
        }
    }

    private static function provider($settings): string
    {
        return isset($settings->qq_notify_provider) && in_array($settings->qq_notify_provider, array('official', 'standalone'), true)
            ? $settings->qq_notify_provider : 'none';
    }

    private static function officialUrl($settings): string
    {
        return isset($settings->qq_official_url) && is_scalar($settings->qq_official_url)
            ? rtrim(trim((string)$settings->qq_official_url), '/') : '';
    }

    private static function officialTargets($settings): array
    {
        $value = isset($settings->qq_official_targets) && is_scalar($settings->qq_official_targets) ? trim((string)$settings->qq_official_targets) : '';
        return $value === '' ? array() : array_values(array_unique(array_map('trim', preg_split('/\r\n|\r|\n/', $value))));
    }

    private static function officialConfigurationError($settings): string
    {
        $url = self::officialUrl($settings);
        $parts = parse_url($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return _t('请填写有效的官方 QQ Bot 服务地址（http/https，不含用户名、密码、查询参数或片段）');
        }
        $token = isset($settings->qq_official_token) && is_scalar($settings->qq_official_token) ? (string)$settings->qq_official_token : '';
        if (!preg_match('/\A[\x21-\x7e]{32,1024}\z/D', $token)) {
            return _t('官方 QQ Bot 服务访问令牌须为 32 至 1024 位非空白 ASCII 字符');
        }
        $targets = self::officialTargets($settings);
        if (count($targets) < 1 || count($targets) > 20) {
            return _t('请填写 1 至 20 个已绑定的 QQ Bot 接收别名，每行一个');
        }
        foreach ($targets as $target) {
            if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $target)) {
                return _t('QQ Bot 接收别名仅支持 1 至 64 位英文字母、数字、下划线和连字符');
            }
        }
        return '';
    }

    public static function sendTestMessage(string $message, $settings = null): array
    {
        $settings = $settings === null ? self::runtimeSettings() : (object)$settings;
        if (in_array(self::provider($settings), array('official', 'standalone'), true)) {
            $error = self::officialConfigurationError($settings);
            if ($error !== '') {
                return array('success' => false, 'error' => $error);
            }
            return self::sendOfficial(self::officialEnvelope($message, $settings), $settings);
        }
        return array('success' => false, 'error' => _t('请先选择并配置独立 QQ 通道或官方 QQ Bot'));
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
            if (self::provider($settings) === 'none') {
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

        if (in_array(self::provider($settings), array('official', 'standalone'), true)) {
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

        if (in_array(self::provider($settings), array('official', 'standalone'), true)) {
            $envelope = self::officialEnvelope($message, $settings);
            $encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return;
            }
            // Persist the idempotency key before contacting the service, then hand off now.
            $qid = self::enqueue(self::OFFICIAL_QUEUE_PREFIX . $encoded);
            $result = self::sendOfficial($envelope, $settings);
            if ($qid > 0) {
                self::recordOfficialResult($qid, $result);
            } elseif (empty($result['success'])) {
                error_log('[Enhancement][QQBot] 服务投递和本地重试队列均失败，请检查数据库及 Bot 服务。');
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
            'targets' => self::officialTargets($settings),
            'serviceURL' => self::officialUrl($settings),
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
            // A later retry uses the same service idempotency key.
        }
    }

    private static function sendOfficial($envelope, $settings): array
    {
        $result = array('success' => false, 'error' => '', 'provider' => self::provider($settings));
        $error = self::officialConfigurationError($settings);
        if ($error !== '') {
            $result['error'] = $error;
            return $result;
        }
        if (!is_array($envelope) || !isset($envelope['provider'], $envelope['message'], $envelope['targets'], $envelope['serviceURL'], $envelope['requestId'])
            || !in_array($envelope['provider'], array('official', 'standalone'), true) || !is_string($envelope['message']) || !is_array($envelope['targets'])
            || !is_string($envelope['serviceURL']) || !is_string($envelope['requestId'])
            || !preg_match('/\A[a-f0-9]{40}\z/D', $envelope['requestId'])) {
            $result['error'] = _t('官方 QQ Bot 本地队列记录无效');
            return $result;
        }
        if ($envelope['provider'] !== self::provider($settings)) {
            $result['error'] = _t('QQ 服务类型已更改，请恢复原类型后重试或清空旧队列');
            return $result;
        }
        if ($envelope['serviceURL'] !== self::officialUrl($settings)) {
            $result['error'] = _t('Bot 服务地址已更改，旧队列不会向原地址发送当前访问令牌；请恢复地址后重试或清空旧队列');
            return $result;
        }
        if (count($envelope['targets']) < 1 || count($envelope['targets']) > 20) {
            $result['error'] = _t('官方 QQ Bot 队列接收别名无效');
            return $result;
        }
        foreach ($envelope['targets'] as $target) {
            if (!is_string($target) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $target)) {
                $result['error'] = _t('官方 QQ Bot 队列接收别名无效');
                return $result;
            }
        }
        if (!function_exists('curl_init')) {
            $result['error'] = _t('当前环境缺少 cURL 扩展');
            return $result;
        }
        $payload = json_encode(array(
            'message' => $envelope['message'],
            'targets' => $envelope['targets'],
            'idempotency_key' => $envelope['requestId']
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $result['error'] = _t('QQ Bot 消息必须是有效的 UTF-8 文本');
            return $result;
        }

        $ch = curl_init();
        $response = '';
        $curlOptions = array(
            CURLOPT_URL => $envelope['serviceURL'] . '/api/notifications',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json',
                'Authorization: Bearer ' . $settings->qq_official_token
            ),
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$response) {
                if (strlen($response) + strlen($chunk) > 65536) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            }
        );
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $curlOptions[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_NOSIGNAL')) {
            $curlOptions[CURLOPT_NOSIGNAL] = true;
        }
        curl_setopt_array($ch, $curlOptions);
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0) {
            $result['error'] = _t('Bot 服务连接失败（cURL %d），请检查服务地址和网络连接', $errno);
            return $result;
        }
        $decoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && isset($decoded['ok'], $decoded['status'], $decoded['id'])
            && $decoded['ok'] === true && in_array($decoded['status'], array('queued', 'sent'), true)
            && is_string($decoded['id']) && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $decoded['id'])) {
            $result['success'] = true;
            $result['status'] = $decoded['status'];
            $result['id'] = $decoded['id'];
            return $result;
        }
        // Never echo or persist the service response body: it may contain secrets.
        $result['error'] = _t('Bot 服务未接受通知（HTTP %d），请检查服务日志、接收别名和访问令牌', $httpCode);
        return $result;
    }
}
