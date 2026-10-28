<?php

class Enhancement_QqDirectNotifyHelper
{
    private const API_BASE = 'https://api.bot.qq.com';

    private static function settings($settings = null): array
    {
        if ($settings === null) {
            $settings = Enhancement_Plugin::runtimeSettings();
        }
        if (is_object($settings) && method_exists($settings, 'toArray')) {
            $settings = $settings->toArray();
        }
        $settings = is_array($settings) ? $settings : (is_object($settings) ? get_object_vars($settings) : array());
        $config = array('qq_notify_provider' => 'none', 'qq_app_id' => '', 'qq_app_secret' => '', 'qq_target_type' => 'c2c', 'qq_target_id' => '', 'qq_direct_include_links' => '1');
        foreach ($config as $key => $default) {
            if (isset($settings[$key]) && !is_scalar($settings[$key])) {
                throw new RuntimeException(_t('QQ 官方接口配置项必须是文本或开关值'));
            }
            if (isset($settings[$key]) && is_scalar($settings[$key])) {
                $config[$key] = trim((string)$settings[$key]);
            }
        }
        return $config;
    }

    private static function identity(array $config): string
    {
        return hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']);
    }

    public static function validateSettings(array $settings)
    {
        foreach (array('qq_app_id', 'qq_app_secret', 'qq_target_type', 'qq_target_id', 'qq_direct_include_links') as $key) {
            if (isset($settings[$key]) && !is_scalar($settings[$key])) {
                throw new Typecho_Widget_Exception(_t('QQ 官方接口配置项必须是文本或开关值'), 400);
            }
        }
        $config = self::settings($settings);
        if ($config['qq_app_id'] !== '' && !preg_match('/\A[0-9]{1,32}\z/', $config['qq_app_id'])) {
            throw new Typecho_Widget_Exception(_t('QQ AppID 只能填写 1 至 32 位数字'), 400);
        }
        if ($config['qq_app_secret'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,256}\z/', $config['qq_app_secret'])) {
            throw new Typecho_Widget_Exception(_t('QQ AppSecret 格式无效'), 400);
        }
        if (!in_array($config['qq_target_type'], array('c2c', 'group', 'channel'), true)) {
            throw new Typecho_Widget_Exception(_t('QQ 接收类型无效'), 400);
        }
        if ($config['qq_target_id'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $config['qq_target_id'])) {
            throw new Typecho_Widget_Exception(_t('QQ 接收 ID 格式无效，请填写用户或群 OpenID、子频道 ID'), 400);
        }
        if ($config['qq_notify_provider'] === 'direct'
            && ((!empty($settings['enable_comment_by_qq']) && $settings['enable_comment_by_qq'] == '1')
                || (!empty($settings['enable_link_submit_by_qq']) && $settings['enable_link_submit_by_qq'] == '1'))
            && ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '' || $config['qq_target_id'] === '')) {
            throw new Typecho_Widget_Exception(_t('启用 QQ 通知前，请保存 AppID、AppSecret，并填写或绑定接收 ID'), 400);
        }
    }

    private static function token(array $config, callable $transport, bool $refresh = false): string
    {
        $identity = self::identity($config);
        $cached = Enhancement_NotifyTransportHelper::read('qq_token');
        if (!$refresh && ($cached['identity'] ?? '') === $identity && (int)($cached['expires_at'] ?? 0) > time() + 60
            && is_string($cached['token'] ?? null) && preg_match('/\A[\x21-\x7e]{1,8192}\z/', $cached['token'])) {
            return $cached['token'];
        }
        $response = $transport('POST', self::API_BASE . '/app/getAppAccessToken', array(), array(
            'appId' => $config['qq_app_id'], 'clientSecret' => $config['qq_app_secret']
        ));
        $data = $response['data'];
        if ((int)$response['status'] !== 200 || !is_string($data['access_token'] ?? null)
            || !preg_match('/\A[\x21-\x7e]{1,8192}\z/', $data['access_token'])
            || !is_scalar($data['expires_in'] ?? null) || !preg_match('/\A[0-9]{1,10}\z/', (string)$data['expires_in']) || (int)$data['expires_in'] <= 0
            || !in_array(Enhancement_NotifyTransportHelper::apiCode($data), array('', '0'), true)) {
            throw Enhancement_NotifyTransportHelper::apiError('QQ', $response);
        }
        Enhancement_NotifyTransportHelper::write('qq_token', array(
            'identity' => $identity, 'token' => $data['access_token'], 'expires_at' => time() + min(86400, (int)$data['expires_in'])
        ));
        return $data['access_token'];
    }

    public static function send(string $text, $settings = null, ?callable $transport = null, ?string $replyMessageId = null): array
    {
        try {
            $config = self::settings($settings);
            self::validateSettings($config);
            if ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '' || $config['qq_target_id'] === '') {
                throw new RuntimeException(_t('QQ 凭证或接收 ID 尚未配置完整'));
            }
            $transport = $transport ?? array('Enhancement_NotifyTransportHelper', 'request');
            $paths = array('c2c' => '/v2/users/', 'group' => '/v2/groups/', 'channel' => '/channels/');
            $url = self::API_BASE . $paths[$config['qq_target_type']] . rawurlencode($config['qq_target_id']) . '/messages';
            $payload = array('content' => Enhancement_NotifyTransportHelper::text($text, $config['qq_direct_include_links'] === '1', 1800));
            if ($config['qq_target_type'] !== 'channel') {
                $payload['msg_type'] = 0;
            }
            if ($replyMessageId !== null) {
                if (!preg_match('/\A[\x21-\x7e]{1,512}\z/D', $replyMessageId) || $config['qq_target_type'] === 'channel') {
                    throw new RuntimeException(_t('QQ 回复消息 ID 或会话类型无效'));
                }
                $payload['msg_id'] = $replyMessageId;
                $payload['msg_seq'] = 1;
            }
            $token = self::token($config, $transport);
            $response = $transport('POST', $url, array('Authorization: QQBot ' . $token), $payload);
            if ((int)$response['status'] === 401) {
                Enhancement_NotifyTransportHelper::write('qq_token', array());
                $token = self::token($config, $transport, true);
                $response = $transport('POST', $url, array('Authorization: QQBot ' . $token), $payload);
            }
            if ((int)$response['status'] < 200 || (int)$response['status'] >= 300
                || !in_array(Enhancement_NotifyTransportHelper::apiCode($response['data']), array('', '0'), true)
                || !is_string($response['data']['id'] ?? null) || $response['data']['id'] === '') {
                throw Enhancement_NotifyTransportHelper::apiError('QQ', $response);
            }
            $result = array('success' => true, 'id' => $response['data']['id'], 'delivery' => 'accepted');
        } catch (Throwable $exception) {
            $result = array('success' => false, 'error' => get_class($exception) === 'RuntimeException' || $exception instanceof Typecho_Widget_Exception
                ? $exception->getMessage() : _t('QQ 通知发送失败，请检查数据库和服务器环境'));
        }
        try { Enhancement_NotifyTransportHelper::recordResult('qq', $result); } catch (Throwable $ignored) {}
        return $result;
    }

    private static function keypair(string $secret): string
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            throw new RuntimeException(_t('QQ Webhook 需要启用 PHP sodium 扩展'));
        }
        if ($secret === '') {
            throw new RuntimeException(_t('请先保存 QQ AppID 和 AppSecret'));
        }
        $seed = $secret;
        while (strlen($seed) < 32) {
            $seed .= $secret;
        }
        return sodium_crypto_sign_seed_keypair(substr($seed, 0, 32));
    }

    public static function status(): array
    {
        $config = self::settings();
        $identity = self::identity($config);
        $state = Enhancement_NotifyTransportHelper::read('qq_webhook');
        $binding = Enhancement_NotifyTransportHelper::read('qq_binding');
        $storedResult = Enhancement_NotifyTransportHelper::read('qq_result');
        $lastResult = array();
        if ($storedResult !== array()) {
            $lastResult = array('success' => !empty($storedResult['success']),
                'message' => is_string($storedResult['message'] ?? null) ? $storedResult['message'] : '',
                'time' => (int)($storedResult['time'] ?? 0));
        }
        if (($state['identity'] ?? '') !== $identity) {
            $state = array();
        }
        if (($binding['identity'] ?? '') !== $identity || (int)($binding['expires_at'] ?? 0) <= time()
            || ($binding['target_type'] ?? '') !== $config['qq_target_type']
            || !is_string($binding['code'] ?? null) || !preg_match('/\A[A-F0-9]{16}\z/', $binding['code'])
            || !in_array($binding['target_type'] ?? '', array('c2c', 'group'), true)) {
            $binding = array();
        }
        return array(
            'available' => function_exists('sodium_crypto_sign_verify_detached'),
            'provider' => $config['qq_notify_provider'],
            'configured' => $config['qq_app_id'] !== '' && $config['qq_app_secret'] !== '',
            'target_type' => $config['qq_target_type'], 'target_id' => $config['qq_target_id'],
            'last_challenge_at' => (int)($state['last_challenge_at'] ?? 0), 'last_event_at' => (int)($state['last_event_at'] ?? 0),
            'binding' => array_intersect_key($binding, array_flip(array('code', 'expires_at', 'target_type'))),
            'last_result' => $lastResult
        );
    }

    public static function startBinding(): array
    {
        $config = self::settings();
        self::validateSettings($config);
        if ($config['qq_notify_provider'] !== 'direct') {
            throw new RuntimeException(_t('请先选择“官方 QQ Bot 直连”并保存通知方式，再生成绑定码'));
        }
        self::keypair($config['qq_app_secret']);
        if ($config['qq_app_id'] === '') {
            throw new RuntimeException(_t('请先保存 QQ AppID 和 AppSecret'));
        }
        if (!in_array($config['qq_target_type'], array('c2c', 'group'), true)) {
            throw new RuntimeException(_t('自动绑定支持 QQ 私聊和群聊；频道请手动填写子频道 ID'));
        }
        $binding = array('code' => strtoupper(bin2hex(random_bytes(8))), 'expires_at' => time() + 600,
            'target_type' => $config['qq_target_type'], 'identity' => self::identity($config));
        Enhancement_NotifyTransportHelper::write('qq_binding', $binding);
        return array_intersect_key($binding, array_flip(array('code', 'expires_at', 'target_type')));
    }

    public static function cancelBinding(): array
    {
        Enhancement_NotifyTransportHelper::write('qq_binding', array());
        return array('success' => true);
    }

    private static function bindEvent(array $event, array $config)
    {
        $types = array('C2C_MESSAGE_CREATE' => 'c2c', 'GROUP_AT_MESSAGE_CREATE' => 'group');
        $type = is_string($event['t'] ?? null) ? ($types[$event['t']] ?? '') : '';
        $data = $event['d'] ?? null;
        if ($type === '' || $config['qq_target_type'] !== $type || !is_array($data) || !is_string($data['content'] ?? null)) {
            return;
        }
        $target = $type === 'group' ? ($data['group_openid'] ?? null) : ($data['author']['user_openid'] ?? null);
        if (!is_string($target) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $target)) {
            return;
        }
        $content = preg_replace('/\A\s*(?:<@!?[A-Za-z0-9_-]+>\s*)*/u', '', $data['content']);
        if (!is_string($content) || !preg_match('/\A绑定通知\s+([A-Fa-f0-9]{16})\s*\z/u', $content, $matches)) {
            return;
        }
        $pending = Enhancement_NotifyTransportHelper::read('qq_binding');
        if (!is_string($pending['code'] ?? null) || !hash_equals($pending['code'], strtoupper($matches[1]))
            || (int)($pending['expires_at'] ?? 0) <= time() || ($pending['target_type'] ?? '') !== $type
            || ($pending['identity'] ?? '') !== self::identity($config)) {
            return;
        }
        // Consume once before saving: a failed save requires a new code, never a second recipient.
        if (!Enhancement_NotifyTransportHelper::compareAndSwap('qq_binding', $pending, array())) {
            return;
        }
        $current = self::settings();
        if (self::identity($current) !== self::identity($config) || $current['qq_target_type'] !== $type) {
            return;
        }
        Enhancement_ConfigStorageHelper::configHandle(array('qq_target_id' => $target), false);
        if (self::settings()['qq_target_id'] !== $target) {
            throw new RuntimeException(_t('QQ 接收 ID 保存失败，请重新生成绑定码'));
        }
    }

    private static function momentEvent(array $event, array $config, ?callable $transport): void
    {
        $type = array('C2C_MESSAGE_CREATE' => 'c2c', 'GROUP_AT_MESSAGE_CREATE' => 'group')[$event['t']] ?? '';
        $data = $event['d'];
        $content = $data['content'] ?? '';
        if ($type === '' || !is_string($data['id'] ?? null) || !preg_match('/\A[\x21-\x7e]{1,512}\z/D', $data['id'])
            || !is_string($content) || !is_array($data['author'] ?? null)) {
            return;
        }
        $sender = $type === 'group' ? ($data['author']['member_openid'] ?? null) : ($data['author']['user_openid'] ?? null);
        $conversation = $type === 'group' ? ($data['group_openid'] ?? null) : $sender;
        if (!is_string($sender) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $sender)
            || !is_string($conversation) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $conversation)) {
            return;
        }
        $text = preg_replace('/\A\s*(?:<@!?[A-Za-z0-9_-]+>\s*)*/u', '', $content);
        if (!is_string($text)) {
            return;
        }
        $timestamp = $data['timestamp'] ?? null;
        if (is_int($timestamp) || (is_string($timestamp) && preg_match('/\A[0-9]{1,12}\z/D', $timestamp))) {
            $timestamp = (int)$timestamp;
        } else {
            $timestamp = is_string($timestamp) && strlen($timestamp) <= 64 ? (strtotime($timestamp) ?: 0) : 0;
        }
        $message = array('id' => $data['id'], 'sender' => $sender, 'conversation' => $conversation,
            'conversation_type' => $type, 'text' => $text, 'timestamp' => $timestamp, 'account' => $config['qq_app_id']);
        $attachments = array();
        foreach (is_array($data['attachments'] ?? null) ? $data['attachments'] : array() as $attachment) {
            if (!is_array($attachment)) { continue; }
            $mime = $attachment['content_type'] ?? null;
            if ((is_string($mime) && stripos(trim($mime), 'image/') === 0)
                || (!isset($attachment['content_type']) && is_string($attachment['url'] ?? null) && $attachment['url'] !== '')) {
                $attachments[] = $attachment;
            }
        }
        if ($attachments !== array()) { $message['attachments'] = $attachments; }
        $result = Enhancement_BotMomentsHelper::handle('qq', $message);
        if ($result === null || !empty($result['replayed']) || !is_string($result['message'] ?? null) || $result['message'] === '') {
            return;
        }
        $config['qq_target_type'] = $type;
        $config['qq_target_id'] = $conversation;
        // Publishing is complete before the best-effort reply; a reply failure must still acknowledge the event.
        self::send($result['message'], $config, $transport, $data['id']);
    }

    public static function webhook(string $body, array $headers, ?callable $transport = null): array
    {
        $failure = static function (int $status): array { return array('status' => $status, 'body' => array('op' => 12, 'd' => 1)); };
        if (strlen($body) > 65536) {
            return $failure(413);
        }
        try {
            $config = self::settings();
            if ($config['qq_notify_provider'] !== 'direct' || $config['qq_app_id'] === '' || $config['qq_app_secret'] === ''
                || !function_exists('sodium_crypto_sign_verify_detached')) {
                return $failure(503);
            }
            $headers = array_change_key_case($headers, CASE_LOWER);
            $appId = $headers['x-bot-appid'] ?? null;
            $timestamp = $headers['x-signature-timestamp'] ?? null;
            $signature = $headers['x-signature-ed25519'] ?? null;
            if (!is_string($appId) || !hash_equals($config['qq_app_id'], $appId) || !is_string($timestamp)
                || !preg_match('/\A[0-9]{1,20}\z/', $timestamp) || !is_string($signature) || !preg_match('/\A[a-fA-F0-9]{128}\z/', $signature)) {
                return $failure(403);
            }
            $keypair = self::keypair($config['qq_app_secret']);
            if (!sodium_crypto_sign_verify_detached((string)hex2bin($signature), $timestamp . $body, sodium_crypto_sign_publickey($keypair))) {
                return $failure(403);
            }
            $event = json_decode($body, true, 32);
            if (!is_array($event) || !is_int($event['op'] ?? null) || !is_array($event['d'] ?? null)) {
                return $failure(400);
            }
            $state = Enhancement_NotifyTransportHelper::read('qq_webhook');
            if (($state['identity'] ?? '') !== self::identity($config)) {
                $state = array('identity' => self::identity($config));
            }
            if ($event['op'] === 13) {
                $plainToken = $event['d']['plain_token'] ?? null;
                $eventTs = $event['d']['event_ts'] ?? null;
                if (!is_string($plainToken) || $plainToken === '' || strlen($plainToken) > 4096
                    || !is_string($eventTs) || !preg_match('/\A[0-9]{1,20}\z/', $eventTs)) {
                    return $failure(400);
                }
                $signature = bin2hex(sodium_crypto_sign_detached($eventTs . $plainToken, sodium_crypto_sign_secretkey($keypair)));
                $state['last_challenge_at'] = time();
                Enhancement_NotifyTransportHelper::write('qq_webhook', $state);
                return array('status' => 200, 'body' => array('plain_token' => $plainToken, 'signature' => $signature));
            }
            if ($event['op'] !== 0 || !is_string($event['t'] ?? null)) {
                return $failure(400);
            }
            self::bindEvent($event, $config);
            self::momentEvent($event, $config, $transport);
            $state['last_event_at'] = time();
            Enhancement_NotifyTransportHelper::write('qq_webhook', $state);
            return array('status' => 200, 'body' => array('op' => 12, 'd' => 0));
        } catch (Throwable $exception) {
            return $failure(500);
        }
    }
}
