<?php

class Enhancement_NotifyTransportHelper
{
    private static function optionName(string $key): string
    {
        if (!preg_match('/\A[a-z_]{1,40}\z/D', $key)) {
            throw new RuntimeException('通知状态名称无效。');
        }
        return 'enh:notify:' . $key;
    }

    public static function read(string $key): array
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('value')->from('table.options')
            ->where('name = ?', self::optionName($key))->where('user = ?', 0)->limit(1));
        $value = is_array($row) && isset($row['value']) ? json_decode($row['value'], true) : null;
        return is_array($value) ? $value : array();
    }

    public static function write(string $key, array $record): void
    {
        $value = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($value === false) {
            throw new RuntimeException('通知状态包含无效文本。');
        }
        $db = Typecho_Db::get();
        $name = self::optionName($key);
        $row = $db->fetchRow($db->select('name')->from('table.options')
            ->where('name = ?', $name)->where('user = ?', 0)->limit(1));
        if ($row) {
            $db->query($db->update('table.options')->rows(array('value' => $value))
                ->where('name = ?', $name)->where('user = ?', 0));
        } else {
            $db->query($db->insert('table.options')->rows(array('name' => $name, 'user' => 0, 'value' => $value)));
        }
    }

    public static function compareAndSwap(string $key, array $expected, array $next): bool
    {
        $db = Typecho_Db::get();
        $before = json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $after = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($before === false || $after === false || $before === $after) {
            return false;
        }
        return (int)$db->query($db->update('table.options')->rows(array('value' => $after))
            ->where('name = ?', self::optionName($key))->where('user = ?', 0)->where('value = ?', $before)) > 0;
    }

    public static function acquireLease(string $key, int $seconds): ?string
    {
        $db = Typecho_Db::get();
        $name = self::optionName($key);
        $current = self::read($key);
        if (($current['expires_at'] ?? 0) > time()) { return null; }
        $token = bin2hex(random_bytes(16));
        $next = array('token' => $token, 'expires_at' => time() + max(1, min(600, $seconds)));
        if (self::compareAndSwap($key, $current, $next)) { return $token; }
        // The options primary key arbitrates the first concurrent receiver startup.
        try {
            $db->query($db->insert('table.options')->rows(array('name' => $name, 'user' => 0,
                'value' => json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))));
            return $token;
        } catch (Exception $exception) {
            $row = $db->fetchRow($db->select('name')->from('table.options')->where('name = ?', $name)->where('user = ?', 0)->limit(1));
            if (!$row) { throw $exception; }
            return null;
        }
    }

    public static function releaseLease(string $key, string $token): void
    {
        $current = self::read($key);
        if (isset($current['token']) && hash_equals((string)$current['token'], $token)) {
            self::compareAndSwap($key, $current, array());
        }
    }

    public static function request(string $method, string $url, array $headers = array(), ?array $payload = null, int $timeout = 6): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('服务器需要启用 PHP cURL 扩展。');
        }
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('官方通知接口必须使用 HTTPS。');
        }
        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload !== null && $body === false) {
            throw new RuntimeException('通知内容必须是有效的 UTF-8 文本。');
        }
        $handle = curl_init($url);
        $response = '';
        $options = array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(array('Accept: application/json', 'Content-Type: application/json; charset=utf-8'), $headers),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$response) {
                if (strlen($response) + strlen($chunk) > 1048576) { return 0; }
                $response .= $chunk;
                return strlen($chunk);
            }
        );
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($body !== null) { $options[CURLOPT_POSTFIELDS] = $body; }
        curl_setopt_array($handle, $options);
        try {
            $ok = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $readTimeout = curl_errno($handle) === CURLE_OPERATION_TIMEDOUT
                && (float)curl_getinfo($handle, CURLINFO_APPCONNECT_TIME) > 0;
        } finally {
            curl_close($handle);
        }
        if ($ok === false) {
            throw new RuntimeException('通知接口连接失败或超时，请检查服务器网络和 HTTPS 证书。', $readTimeout ? 28 : 0);
        }
        $data = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($data)) {
            throw new RuntimeException('通知接口返回了无效的 JSON 响应（HTTP ' . $status . '）。');
        }
        return array('status' => $status, 'data' => $data);
    }

    public static function apiCode(array $data): string
    {
        $value = $data['errcode'] ?? $data['err_code'] ?? $data['code'] ?? $data['ret'] ?? '';
        return is_scalar($value) && preg_match('/\A-?[0-9]{1,12}\z/D', (string)$value) ? (string)$value : '';
    }

    public static function apiError(string $provider, array $response): RuntimeException
    {
        $code = self::apiCode($response['data']);
        $message = $provider . '接口失败（HTTP ' . (int)$response['status'] . ($code !== '' ? '，错误码 ' . $code : '') . '）。';
        if ($provider === 'QQ') {
            $hints = array(
                '40034105' => '请在 QQ 中开启机器人的主动消息权限。',
                '40054004' => '请添加机器人好友或将机器人加入目标群，并检查 OpenID。',
                '40034101' => '请添加机器人好友或将机器人加入目标群，并检查 OpenID。',
                '40054003' => '请添加机器人好友或将机器人加入目标群，并检查 OpenID。',
                '40054013' => '接收人已关闭机器人主动消息。',
                '40054010' => '消息域名未获允许，可关闭通知中的链接后重试。',
                '304003' => '消息域名未获允许，可关闭通知中的链接后重试。',
                '40054016' => '机器人离线；频道推送需要其他程序保持 WebSocket 在线。',
                '304018' => '机器人离线；频道推送需要其他程序保持 WebSocket 在线。',
                '304023' => '消息正在平台审核，请勿重复发送。',
                '304024' => '消息正在平台审核，请勿重复发送。'
            );
            $message .= $hints[$code] ?? ((int)$response['status'] === 429 || $code === '40034100'
                ? '已达到平台频率限制，请稍后再试。' : '请检查机器人凭证、接收 ID 和平台权限。');
        }
        return new RuntimeException($message);
    }

    public static function text(string $message, bool $includeLinks, int $limit = 1800): string
    {
        $message = html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8');
        if (!$includeLinks) {
            $message = preg_replace('~https?://[^\s<>]+~iu', '[链接已省略]', $message);
        }
        if (!is_string($message) || preg_match('//u', $message) !== 1) {
            throw new RuntimeException('通知内容必须是有效的 UTF-8 文本。');
        }
        if (strlen($message) > $limit) {
            $message = substr($message, 0, max(0, $limit - 3));
            while ($message !== '' && preg_match('//u', $message) !== 1) {
                $message = substr($message, 0, -1);
            }
            $message .= '…';
        }
        return trim($message);
    }

    public static function recordResult(string $channel, array $result): void
    {
        self::write($channel . '_result', array('success' => !empty($result['success']),
            'message' => !empty($result['success']) ? '接口已接受通知，请检查接收端。' : (string)($result['error'] ?? '通知发送失败。'),
            'time' => time()));
    }
}
