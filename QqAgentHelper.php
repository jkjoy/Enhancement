<?php

/** Agent notification transports and shared signed QQ management configuration. */
class Enhancement_QqAgentHelper
{
    public const QUEUE_PREFIX = '__ENHANCEMENT_QQ_AGENT_V1__:';

    public static function enabled($settings): bool
    {
        return isset($settings->qq_notify_provider) && in_array($settings->qq_notify_provider, array('openclaw', 'hermes'), true);
    }

    public static function commandsSupported($settings): bool
    {
        return self::enabled($settings) || ($settings->qq_notify_provider ?? '') === 'standalone';
    }

    public static function validateSettings(array $settings)
    {
        foreach ($settings as $key => $value) {
            if (strpos($key, 'qq_agent_') === 0 && !is_scalar($value)) {
                throw new Typecho_Widget_Exception(_t('Agent 通道配置必须是文本或开关值'), 400);
            }
        }
        if (self::enabled((object)$settings)) {
            $url = isset($settings['qq_agent_url']) ? trim((string)$settings['qq_agent_url']) : '';
            $parts = parse_url($url);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !isset($parts['scheme'], $parts['host'])
                || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new Typecho_Widget_Exception(_t('请填写有效的 Agent 消息接口完整 URL（http/https）'), 400);
            }
            self::validateSecret($settings['qq_agent_token'] ?? '');
            if (!preg_match('/\Aqqbot:(c2c|group):[A-Za-z0-9_-]{1,128}\z/D', $settings['qq_agent_target'] ?? '')) {
                throw new Typecho_Widget_Exception(_t('Agent 接收目标格式为 qqbot:c2c:用户OpenID 或 qqbot:group:群OpenID'), 400);
            }
            if (!preg_match('/\A[A-Za-z0-9_-]{0,64}\z/D', $settings['qq_agent_account'] ?? '')) {
                throw new Typecho_Widget_Exception(_t('OpenClaw 账号 ID 格式无效'), 400);
            }
        }
        if (($settings['qq_agent_commands'] ?? '0') === '1') {
            if (!self::commandsSupported((object)$settings)) {
                throw new Typecho_Widget_Exception(_t('启用 QQ 侧指令时请选择独立 QQ 通道、OpenClaw 或 Hermes'), 400);
            }
            self::validateSecret($settings['qq_agent_command_secret'] ?? '');
            $notificationToken = ($settings['qq_notify_provider'] ?? '') === 'standalone'
                ? ($settings['qq_official_token'] ?? '') : ($settings['qq_agent_token'] ?? '');
            if (hash_equals((string)$notificationToken, (string)$settings['qq_agent_command_secret'])) {
                throw new Typecho_Widget_Exception(_t('指令密钥必须与通知凭据不同'), 400);
            }
            $senders = self::allowedSenders((object)$settings);
            if (count($senders) < 1 || count($senders) > 20) {
                throw new Typecho_Widget_Exception(_t('请填写 1 至 20 个允许操作博客的 QQ 用户 OpenID'), 400);
            }
            foreach ($senders as $sender) {
                if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $sender)) {
                    throw new Typecho_Widget_Exception(_t('QQ 指令用户 OpenID 格式无效，不支持通配符'), 400);
                }
            }
            if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)($settings['qq_agent_admin_uid'] ?? ''))) {
                throw new Typecho_Widget_Exception(_t('请填写执行 QQ 指令的 Typecho 管理员 UID'), 400);
            }
        }
    }

    private static function validateSecret($secret)
    {
        if (!is_string($secret) || !preg_match('/\A[\x21-\x7e]{32,1024}\z/D', $secret)) {
            throw new Typecho_Widget_Exception(_t('QQ 通知凭据和指令密钥须为 32 至 1024 位非空白 ASCII 字符'), 400);
        }
    }

    public static function allowedSenders($settings): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($settings->qq_agent_allowed_senders ?? ''))), 'strlen')));
    }

    public static function envelope(string $message, $settings): array
    {
        return array(
            'provider' => $settings->qq_notify_provider,
            'url' => trim((string)($settings->qq_agent_url ?? '')),
            'target' => (string)($settings->qq_agent_target ?? ''),
            'account' => (string)($settings->qq_agent_account ?? ''),
            'message' => Enhancement_QqNotifyHelper::prepareMessage($message, ($settings->qq_agent_include_links ?? '1') === '1'),
            'requestId' => bin2hex(random_bytes(20))
        );
    }

    public static function send($envelope, $settings, int $attempt = 0): array
    {
        $result = array('success' => false, 'error' => _t('Agent 通道配置或队列记录无效'));
        try {
            self::validateSettings(method_exists($settings, 'toArray') ? $settings->toArray() : (array)$settings);
        } catch (Exception $e) {
            return $result;
        }
        if (!self::enabled($settings) || !is_array($envelope)
            || ($envelope['provider'] ?? '') !== $settings->qq_notify_provider
            || ($envelope['url'] ?? '') !== trim((string)$settings->qq_agent_url)
            || !is_string($envelope['message'] ?? null) || strlen($envelope['message']) > 1800
            || !is_string($envelope['target'] ?? null) || !preg_match('/\Aqqbot:(c2c|group):[A-Za-z0-9_-]{1,128}\z/D', $envelope['target'])
            || !is_string($envelope['account'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{0,64}\z/D', $envelope['account'])
            || !is_string($envelope['requestId'] ?? null) || !preg_match('/\A[a-f0-9]{40}\z/D', $envelope['requestId'])) {
            return $result;
        }
        $result['provider'] = $envelope['provider'];
        if (!function_exists('curl_init')) {
            $result['error'] = _t('当前环境缺少 cURL 扩展');
            return $result;
        }
        $headers = array('Content-Type: application/json; charset=UTF-8', 'Accept: application/json');
        // A retry may duplicate a send after an uncertain network result. Hermes caches
        // even rejected direct deliveries; a fresh attempt ID permits a genuine retry.
        $deliveryId = $envelope['requestId'] . '-' . $attempt;
        if ($envelope['provider'] === 'openclaw') {
            $args = array('action' => 'send', 'channel' => 'qqbot', 'target' => $envelope['target'], 'message' => $envelope['message']);
            if ($envelope['account'] !== '') {
                $args['accountId'] = $envelope['account'];
            }
            $payload = array('tool' => 'message', 'args' => $args, 'idempotencyKey' => $deliveryId);
            $headers[] = 'Authorization: Bearer ' . $settings->qq_agent_token;
        } else {
            $payload = array('event_type' => 'enhancement.notification', 'message' => $envelope['message'],
                'chat_id' => substr($envelope['target'], strrpos($envelope['target'], ':') + 1), 'event_id' => $envelope['requestId']);
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return $result;
        }
        if ($envelope['provider'] === 'hermes') {
            $timestamp = (string)time();
            $headers[] = 'X-Webhook-Timestamp: ' . $timestamp;
            $headers[] = 'X-Webhook-Signature-V2: ' . hash_hmac('sha256', $timestamp . '.' . $json, $settings->qq_agent_token);
            $headers[] = 'X-Request-ID: ' . $deliveryId;
        }
        $response = '';
        $ch = curl_init();
        $options = array(CURLOPT_URL => $envelope['url'], CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$response) {
                if (strlen($response) + strlen($chunk) > 65536) { return 0; }
                $response .= $chunk;
                return strlen($chunk);
            });
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        $body = json_decode($response, true);
        $accepted = false;
        if ($errno === 0 && $code === 200 && is_array($body)) {
            if ($envelope['provider'] === 'openclaw') {
                $accepted = ($body['ok'] ?? null) === true && is_array($body['result'] ?? null) && !empty($body['result'])
                    && empty($body['result']['isError']) && empty($body['result']['error']);
            } else {
                $accepted = in_array($body['status'] ?? '', array('delivered', 'duplicate'), true)
                    && ($body['delivery_id'] ?? '') === $deliveryId;
            }
        }
        $result['success'] = $accepted;
        $result['id'] = $envelope['requestId'];
        $result['error'] = $accepted ? '' : _t('Agent 未确认通知投递（HTTP %d，cURL %d），请检查通道及服务日志', $code, $errno);
        return $result;
    }
}
