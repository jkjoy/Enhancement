<?php

/** Official QQ Bot API and signed Webhook, hosted by this plugin. */
class Enhancement_QqOfficialHelper
{
    private const API_BASE = 'https://api.bot.qq.com';
    private static $transport = null;

    private static function settings($settings = null): array
    {
        $settings = $settings ?? Enhancement_Plugin::runtimeSettings();
        if (is_object($settings) && method_exists($settings, 'toArray')) { $settings = $settings->toArray(); }
        $settings = (array)$settings;
        $config = array('qq_notify_provider' => 'none', 'qq_app_id' => '', 'qq_app_secret' => '',
            'qq_official_include_links' => '1', 'qq_moments_enabled' => '0');
        foreach ($config as $key => $default) {
            if (isset($settings[$key]) && !is_scalar($settings[$key])) {
                throw new Typecho_Widget_Exception(_t('QQ 官方机器人配置项必须是文本或开关值'), 400);
            }
            if (isset($settings[$key])) { $config[$key] = trim((string)$settings[$key]); }
        }
        return $config;
    }

    public static function credentialsIdentity($settings = null): string
    {
        $config = self::settings($settings);
        return hash('sha256', $config['qq_app_id'] . "\n" . $config['qq_app_secret']);
    }

    public static function validateSettings(array $settings): void
    {
        $config = self::settings($settings);
        if (!in_array($config['qq_notify_provider'], array('none', 'official'), true)) {
            throw new Typecho_Widget_Exception(_t('QQ 通知方式无效'), 400);
        }
        if ($config['qq_app_id'] !== '' && !preg_match('/\A[0-9]{1,32}\z/D', $config['qq_app_id'])) {
            throw new Typecho_Widget_Exception(_t('QQ AppID 只能填写 1 至 32 位数字'), 400);
        }
        if ($config['qq_app_secret'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $config['qq_app_secret'])) {
            throw new Typecho_Widget_Exception(_t('QQ AppSecret 格式无效'), 400);
        }
        foreach (array('qq_official_include_links', 'qq_moments_enabled') as $key) {
            if (!in_array($config[$key], array('0', '1'), true)) {
                throw new Typecho_Widget_Exception(_t('QQ 功能开关值无效'), 400);
            }
        }
        if ($config['qq_notify_provider'] === 'official' && ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '')) {
            throw new Typecho_Widget_Exception(_t('请填写 QQ AppID 和 AppSecret'), 400);
        }
        if ($config['qq_moments_enabled'] === '1' && $config['qq_notify_provider'] !== 'official') {
            throw new Typecho_Widget_Exception(_t('发布瞬间需要启用官方 QQ 机器人'), 400);
        }
    }

    public static function binding($settings = null): array
    {
        $config = self::settings($settings);
        $record = Enhancement_NotifyTransportHelper::read('qq_binding');
        if ($config['qq_notify_provider'] !== 'official' || $config['qq_app_id'] === '' || $config['qq_app_secret'] === ''
            || ($record['identity'] ?? '') !== self::credentialsIdentity($config)
            || !in_array($record['target_type'] ?? '', array('c2c', 'group'), true)
            || !is_string($record['target_id'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $record['target_id'])
            || !is_int($record['bound_at'] ?? null) || $record['bound_at'] <= 0) { return array(); }
        return array_intersect_key($record, array_flip(array('identity', 'target_type', 'target_id', 'bound_at')));
    }

    private static function token(array $config, callable $transport, bool $refresh = false): string
    {
        $identity = self::credentialsIdentity($config);
        $cached = Enhancement_NotifyTransportHelper::read('qq_token');
        if (!$refresh && ($cached['identity'] ?? '') === $identity && (int)($cached['expires_at'] ?? 0) > time() + 60
            && is_string($cached['token'] ?? null) && preg_match('/\A[\x21-\x7e]{1,8192}\z/D', $cached['token'])) { return $cached['token']; }
        $response = $transport('POST', self::API_BASE . '/app/getAppAccessToken', array(),
            array('appId' => $config['qq_app_id'], 'clientSecret' => $config['qq_app_secret']));
        $data = $response['data'];
        if ((int)$response['status'] !== 200 || !is_string($data['access_token'] ?? null)
            || !preg_match('/\A[\x21-\x7e]{1,8192}\z/D', $data['access_token'])
            || !is_scalar($data['expires_in'] ?? null) || !preg_match('/\A[0-9]{1,10}\z/D', (string)$data['expires_in'])
            || (int)$data['expires_in'] <= 0 || !in_array(Enhancement_NotifyTransportHelper::apiCode($data), array('', '0'), true)) {
            throw Enhancement_NotifyTransportHelper::apiError('QQ', $response);
        }
        Enhancement_NotifyTransportHelper::write('qq_token', array('identity' => $identity, 'token' => $data['access_token'],
            'expires_at' => time() + min(86400, (int)$data['expires_in'])));
        return $data['access_token'];
    }

    public static function send(string $text, $settings = null, ?callable $transport = null, ?string $replyMessageId = null): array
    {
        try {
            $config = self::settings($settings);
            self::validateSettings($config);
            $binding = self::binding($config);
            if (!$binding) { throw new RuntimeException(_t('请先在后台生成绑定码，再私聊 QQ 机器人完成接收绑定')); }
            $transport = $transport ?? self::$transport ?? array('Enhancement_NotifyTransportHelper', 'request');
            $url = self::API_BASE . ($binding['target_type'] === 'c2c' ? '/v2/users/' : '/v2/groups/')
                . rawurlencode($binding['target_id']) . '/messages';
            $payload = array('content' => Enhancement_NotifyTransportHelper::text($text, $config['qq_official_include_links'] === '1', 1800), 'msg_type' => 0);
            if ($replyMessageId !== null) {
                if (!preg_match('/\A[\x21-\x7e]{1,256}\z/D', $replyMessageId)) { throw new RuntimeException(_t('QQ 回复消息编号无效')); }
                $payload['msg_id'] = $replyMessageId;
                $payload['msg_seq'] = 1;
            }
            $token = self::token($config, $transport);
            $response = $transport('POST', $url, array('Authorization: QQBot ' . $token), $payload);
            if ((int)$response['status'] === 401) {
                $token = self::token($config, $transport, true);
                $response = $transport('POST', $url, array('Authorization: QQBot ' . $token), $payload);
            }
            if ((int)$response['status'] < 200 || (int)$response['status'] >= 300
                || !in_array(Enhancement_NotifyTransportHelper::apiCode($response['data']), array('', '0'), true)
                || isset($response['data']['message_audit']) || isset($response['data']['audit_id'])
                || isset($response['data']['data']['message_audit']) || isset($response['data']['data']['audit_id'])
                || !is_string($response['data']['id'] ?? null) || !preg_match('/\A[\x21-\x7e]{1,256}\z/D', $response['data']['id'])) {
                throw Enhancement_NotifyTransportHelper::apiError('QQ', $response);
            }
            $result = array('success' => true, 'id' => $response['data']['id'], 'delivery' => 'accepted');
        } catch (Throwable $exception) {
            $result = array('success' => false, 'error' => $exception instanceof RuntimeException
                ? $exception->getMessage() : _t('QQ 通知发送失败，请检查数据库和服务器环境'));
        }
        try { Enhancement_NotifyTransportHelper::recordResult('qq', $result); } catch (Throwable $ignored) {}
        return $result;
    }

    private static function keypair(string $secret): string
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) { throw new RuntimeException(_t('QQ Webhook 需要 PHP sodium 扩展')); }
        if ($secret === '') { throw new RuntimeException(_t('请先保存 QQ AppID 和 AppSecret')); }
        $seed = str_repeat($secret, (int)ceil(32 / strlen($secret)));
        return sodium_crypto_sign_seed_keypair(substr($seed, 0, 32));
    }

    public static function status($settings = null): array
    {
        $config = self::settings($settings);
        $identity = self::credentialsIdentity($config);
        $state = Enhancement_NotifyTransportHelper::read('qq_webhook');
        if (($state['identity'] ?? '') !== $identity) { $state = array(); }
        $pending = Enhancement_NotifyTransportHelper::read('qq_binding_pending');
        if (($pending['identity'] ?? '') !== $identity || ($pending['expires_at'] ?? 0) <= time()
            || !is_string($pending['code'] ?? null) || !preg_match('/\A[A-F0-9]{16}\z/D', $pending['code'])
            || !in_array($pending['target_type'] ?? '', array('c2c', 'group'), true)) { $pending = array(); }
        $binding = self::binding($config);
        $stored = Enhancement_NotifyTransportHelper::read('qq_result');
        return array('available' => function_exists('sodium_crypto_sign_verify_detached'),
            'provider' => $config['qq_notify_provider'], 'configured' => $config['qq_app_id'] !== '' && $config['qq_app_secret'] !== '',
            'bound' => !empty($binding), 'bound_user' => ($binding['target_type'] ?? '') === 'c2c', 'target_type' => $binding['target_type'] ?? '',
            'moments_enabled' => $config['qq_moments_enabled'] === '1',
            'last_challenge_at' => (int)($state['last_challenge_at'] ?? 0), 'last_event_at' => (int)($state['last_event_at'] ?? 0),
            'binding' => array_intersect_key($pending, array_flip(array('code', 'expires_at', 'target_type'))),
            'last_result' => $stored ? array('success' => !empty($stored['success']), 'message' => (string)($stored['message'] ?? ''), 'time' => (int)($stored['time'] ?? 0)) : array());
    }

    public static function startBinding(string $type = 'c2c'): array
    {
        $config = self::settings();
        self::validateSettings($config);
        if ($config['qq_notify_provider'] !== 'official') { throw new RuntimeException(_t('请先保存官方 QQ 机器人配置')); }
        self::keypair($config['qq_app_secret']);
        if (!in_array($type, array('c2c', 'group'), true)) { throw new RuntimeException(_t('QQ 绑定类型无效')); }
        $pending = array('identity' => self::credentialsIdentity($config), 'code' => strtoupper(bin2hex(random_bytes(8))),
            'target_type' => $type, 'expires_at' => time() + 600);
        Enhancement_NotifyTransportHelper::write('qq_binding_pending', $pending);
        return array('code' => $pending['code'], 'expires_at' => $pending['expires_at'], 'target_type' => $type,
            'command' => '绑定通知 ' . $pending['code']);
    }

    public static function cancelBinding(): array
    {
        Enhancement_NotifyTransportHelper::write('qq_binding_pending', array());
        return array('success' => true);
    }

    public static function disconnect(): array
    {
        self::cancelBinding();
        Enhancement_NotifyTransportHelper::write('qq_binding', array());
        self::syncActivation();
        return array('success' => true);
    }

    private static function syncActivation(): void
    {
        if (!method_exists('Enhancement_BotMomentsHelper', 'syncActivation')) { return; }
        $settings = Enhancement_Plugin::runtimeSettings();
        if (is_object($settings) && method_exists($settings, 'toArray')) { $settings = $settings->toArray(); }
        Enhancement_BotMomentsHelper::syncActivation((array)$settings);
    }

    private static function bindEvent(array $event, array $config): bool
    {
        $type = array('C2C_MESSAGE_CREATE' => 'c2c', 'GROUP_AT_MESSAGE_CREATE' => 'group')[$event['t']] ?? '';
        $data = $event['d'];
        if ($type === '' || !is_string($data['content'] ?? null)) { return false; }
        $text = preg_replace('/\A\s*(?:<@!?[A-Za-z0-9_-]+>\s*)*/u', '', $data['content']);
        if (!is_string($text) || !preg_match('/\A绑定通知\s+([A-Fa-f0-9]{16})\s*\z/u', $text, $match)) { return false; }
        $target = $type === 'c2c' ? ($data['author']['user_openid'] ?? null) : ($data['group_openid'] ?? null);
        if (!is_string($target) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $target)) { return true; }
        $pending = Enhancement_NotifyTransportHelper::read('qq_binding_pending');
        if (($pending['identity'] ?? '') !== self::credentialsIdentity($config) || ($pending['target_type'] ?? '') !== $type
            || ($pending['expires_at'] ?? 0) <= time() || !is_string($pending['code'] ?? null)
            || !hash_equals($pending['code'], strtoupper($match[1]))) { return true; }
        if (!Enhancement_NotifyTransportHelper::compareAndSwap('qq_binding_pending', $pending, array())) { return true; }
        if (self::credentialsIdentity() !== self::credentialsIdentity($config)) { return true; }
        Enhancement_NotifyTransportHelper::write('qq_binding', array('identity' => self::credentialsIdentity($config),
            'target_type' => $type, 'target_id' => $target, 'bound_at' => time()));
        self::syncActivation();
        return true;
    }

    private static function timestamp($value): int
    {
        if (is_int($value)) { return max(0, $value); }
        if (!is_string($value) || strlen($value) > 64 || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $value)) { return 0; }
        return max(0, strtotime($value) ?: 0);
    }

    private static function deliverReply(array &$record, array $config, ?callable $transport): bool
    {
        if (($record['status'] ?? '') === 'sent' || (int)($record['attempts'] ?? 0) >= 5) { return false; }
        $binding = self::binding($config);
        if (!$binding || ($record['binding'] ?? array()) !== $binding) { $record['status'] = 'revoked'; return false; }
        $result = self::send($record['text'], $config, $transport, $record['message_id']);
        $record['attempts'] = (int)($record['attempts'] ?? 0) + 1;
        $record['status'] = !empty($result['success']) ? 'sent' : 'failed';
        $record['updated'] = time();
        return !empty($result['success']);
    }

    public static function retryReplies(?callable $transport = null): array
    {
        $lease = Enhancement_NotifyTransportHelper::acquireLease('qq_webhook_lock', 120);
        if ($lease === null) { return array('success' => false, 'sent' => 0); }
        $sent = 0;
        try {
            $records = Enhancement_NotifyTransportHelper::read('qq_replies');
            foreach ($records as &$record) {
                if (($record['status'] ?? '') !== 'failed') { continue; }
                if (self::deliverReply($record, self::settings(), $transport)) { $sent++; }
                Enhancement_NotifyTransportHelper::write('qq_replies', $records);
            }
            unset($record);
        } finally { Enhancement_NotifyTransportHelper::releaseLease('qq_webhook_lock', $lease); }
        return array('success' => true, 'sent' => $sent);
    }

    private static function momentEvent(array $event, array $config, ?callable $transport): void
    {
        if ($event['t'] !== 'C2C_MESSAGE_CREATE' || $config['qq_moments_enabled'] !== '1') { return; }
        $data = $event['d'];
        $sender = $data['author']['user_openid'] ?? null;
        $binding = self::binding($config);
        if (($binding['target_type'] ?? '') !== 'c2c' || !is_string($sender) || ($binding['target_id'] ?? '') !== $sender
            || !is_string($data['id'] ?? null) || !preg_match('/\A[\x21-\x7e]{1,256}\z/D', $data['id'])
            || !is_string($data['content'] ?? '') || !is_array($data['attachments'] ?? array())) { return; }
        $timestamp = self::timestamp($data['timestamp'] ?? null);
        if ($timestamp <= 0) { return; }
        $text = preg_replace('/\A\s*(?:<@!?[A-Za-z0-9_-]+>\s*)*/u', '', $data['content'] ?? '');
        if (!is_string($text)) { return; }
        $attachments = array();
        foreach ($data['attachments'] ?? array() as $attachment) {
            if (!is_array($attachment)) { continue; }
            $mime = $attachment['content_type'] ?? null;
            if ((is_string($mime) && stripos(trim($mime), 'image/') === 0)
                || (!isset($attachment['content_type']) && is_string($attachment['url'] ?? null))) { $attachments[] = $attachment; }
        }
        $message = array('id' => $data['id'], 'account' => $config['qq_app_id'], 'sender' => $sender, 'conversation' => $sender,
            'conversation_type' => 'private', 'text' => $text, 'timestamp' => $timestamp, 'attachments' => $attachments);
        $lease = Enhancement_NotifyTransportHelper::acquireLease('qq_webhook_lock', 120);
        if ($lease === null) { throw new RuntimeException('QQ webhook busy'); }
        try {
            $records = Enhancement_NotifyTransportHelper::read('qq_replies');
            $key = hash('sha256', $config['qq_app_id'] . "\n" . $data['id']);
            if (!isset($records[$key])) {
                $result = Enhancement_BotMomentsHelper::handle('qq', $message);
                if ($result === null || !is_string($result['message'] ?? null) || $result['message'] === '') { return; }
                $records[$key] = array('binding' => $binding, 'message_id' => $data['id'], 'text' => $result['message'],
                    'status' => 'pending', 'attempts' => 0, 'updated' => time());
                // Persist the completed publishing reply before attempting the QQ API.
                Enhancement_NotifyTransportHelper::write('qq_replies', $records);
            }
            self::deliverReply($records[$key], $config, $transport);
            foreach ($records as $recordKey => $record) {
                if (($record['updated'] ?? 0) < time() - 30 * 86400) { unset($records[$recordKey]); }
            }
            Enhancement_NotifyTransportHelper::write('qq_replies', $records);
        } finally { Enhancement_NotifyTransportHelper::releaseLease('qq_webhook_lock', $lease); }
    }

    public static function webhook(string $body, array $headers, ?callable $transport = null): array
    {
        $failure = static function (int $status): array { return array('status' => $status, 'body' => array('op' => 12, 'd' => 1)); };
        if (strlen($body) > 65536) { return $failure(413); }
        try {
            $config = self::settings();
            self::validateSettings($config);
            if ($config['qq_notify_provider'] !== 'official' || !function_exists('sodium_crypto_sign_verify_detached')) { return $failure(503); }
            $event = json_decode($body, true, 32, JSON_BIGINT_AS_STRING);
            if (!is_array($event) || !is_int($event['op'] ?? null) || !is_array($event['d'] ?? null)) { return $failure(400); }
            $headers = array_change_key_case($headers, CASE_LOWER);
            $appId = $headers['x-bot-appid'] ?? null;
            $timestamp = $headers['x-signature-timestamp'] ?? null;
            $signature = $headers['x-signature-ed25519'] ?? null;
            if ($appId === '') { $appId = null; }
            if ($timestamp === '') { $timestamp = null; }
            if ($signature === '') { $signature = null; }
            $keypair = self::keypair($config['qq_app_secret']);
            if ($appId !== null && (!is_string($appId) || !hash_equals($config['qq_app_id'], $appId))) { return $failure(403); }
            $hasSignature = $timestamp !== null || $signature !== null;
            if ($event['op'] !== 13 || $hasSignature) {
                if (!is_string($timestamp)
                    || !preg_match('/\A[0-9]{1,12}\z/D', $timestamp) || abs(time() - (int)$timestamp) > 300
                    || !is_string($signature) || !preg_match('/\A[a-fA-F0-9]{128}\z/D', $signature)
                    || !sodium_crypto_sign_verify_detached(hex2bin($signature), $timestamp . $body, sodium_crypto_sign_publickey($keypair))) { return $failure(403); }
            }
            $state = Enhancement_NotifyTransportHelper::read('qq_webhook');
            if (($state['identity'] ?? '') !== self::credentialsIdentity($config)) { $state = array('identity' => self::credentialsIdentity($config)); }
            if ($event['op'] === 13) {
                $plain = $event['d']['plain_token'] ?? null;
                $eventTs = $event['d']['event_ts'] ?? null;
                if (!is_string($plain) || !preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $plain)
                    || !is_string($eventTs) || !preg_match('/\A[0-9]{1,20}\z/D', $eventTs)) { return $failure(400); }
                if (!$hasSignature && abs(time() - (int)$eventTs) > 300) { return $failure(403); }
                $state['last_challenge_at'] = time();
                Enhancement_NotifyTransportHelper::write('qq_webhook', $state);
                return array('status' => 200, 'body' => array('plain_token' => $plain,
                    'signature' => bin2hex(sodium_crypto_sign_detached($eventTs . $plain, sodium_crypto_sign_secretkey($keypair)))));
            }
            if ($event['op'] !== 0 || !is_string($event['t'] ?? null)) { return $failure(400); }
            if (!self::bindEvent($event, $config)) { self::momentEvent($event, $config, $transport); }
            $state['last_event_at'] = time();
            Enhancement_NotifyTransportHelper::write('qq_webhook', $state);
            return array('status' => 200, 'body' => array('op' => 12, 'd' => 0));
        } catch (Throwable $exception) { return $failure(500); }
    }
}
