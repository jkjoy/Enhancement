<?php

class Enhancement_BotMomentsMediaHelper
{
    private const MAX_IMAGES = 9;
    private const MAX_BYTES = 10485760;
    private const MAX_PIXELS = 20000000;
    private const ERROR = '图片接收或保存失败，请检查图片大小、格式和上传设置后重试。';

    private static function approvedUrl(string $channel, $url): string
    {
        if ($channel === 'qq' && is_string($url) && strncmp($url, '//', 2) === 0) { $url = 'https:' . $url; }
        if (!is_string($url) || $url === '' || strlen($url) > 2048
            || preg_match('/[\\\\\x00-\x20\x7f-\xff]/', $url)) {
            throw new RuntimeException(self::ERROR);
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        $allowed = $channel === 'qq'
            ? in_array($host, array('multimedia.nt.qq.com', 'multimedia.nt.qq.com.cn', 'qpic.cn'), true)
                || (bool)preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+qpic\.cn\z/D', $host)
            : $host === 'novac2c.cdn.weixin.qq.com';
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !$allowed
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException(self::ERROR);
        }
        return 'https://' . $host . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function key($value): string
    {
        if (!is_string($value) || strlen($value) > 128 || !preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D', $value)) {
            throw new RuntimeException(self::ERROR);
        }
        $decoded = base64_decode($value, true);
        if (is_string($decoded) && strlen($decoded) === 16) { return bin2hex($decoded); }
        if (is_string($decoded) && preg_match('/\A[0-9a-fA-F]{32}\z/D', $decoded)) { return strtolower($decoded); }
        throw new RuntimeException(self::ERROR);
    }

    public static function normalize(string $channel, array $attachments): array
    {
        try {
            if (!in_array($channel, array('qq', 'weixin'), true) || count($attachments) > self::MAX_IMAGES
                || array_values($attachments) !== $attachments) {
                throw new RuntimeException(self::ERROR);
            }
            $normalized = array();
            foreach ($attachments as $item) {
                if (!is_array($item)) { throw new RuntimeException(self::ERROR); }
                if ($channel === 'qq') {
                    if (isset($item['content_type']) && (!is_string($item['content_type'])
                        || !in_array(strtolower(trim($item['content_type'])), array('image/jpeg', 'image/png', 'image/gif', 'image/webp'), true))) {
                        throw new RuntimeException(self::ERROR);
                    }
                    $normalized[] = array('url' => self::approvedUrl($channel, $item['url'] ?? null));
                    continue;
                }
                $media = $item['media'] ?? null;
                if (!is_array($media) || (isset($media['encrypt_type']) && !in_array($media['encrypt_type'], array(1, '1'), true))) {
                    throw new RuntimeException(self::ERROR);
                }
                $key = '';
                if (isset($item['aeskey']) && $item['aeskey'] !== '') {
                    if (!is_string($item['aeskey']) || !preg_match('/\A[0-9a-fA-F]{32}\z/D', $item['aeskey'])) {
                        throw new RuntimeException(self::ERROR);
                    }
                    $key = strtolower($item['aeskey']);
                } elseif (isset($media['aes_key']) && $media['aes_key'] !== '') {
                    $key = self::key($media['aes_key']);
                }
                $query = $media['encrypt_query_param'] ?? '';
                if (!is_string($query) || strlen($query) > 2048 || preg_match('/[\x00-\x20\x7f]/', $query)) {
                    throw new RuntimeException(self::ERROR);
                }
                $url = $media['full_url'] ?? '';
                if ($url === '' && $query !== '') {
                    $url = 'https://novac2c.cdn.weixin.qq.com/c2c/download?encrypted_query_param=' . rawurlencode($query);
                }
                $image = array('media' => array('full_url' => self::approvedUrl($channel, $url)));
                if ($key !== '') { $image['aeskey'] = $key; }
                $normalized[] = $image;
            }
            return $normalized;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::ERROR);
        }
    }

    private static function publicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $value = unpack('Nvalue', inet_pton($ip))['value'];
        foreach (array(array('100.64.0.0', 10), array('192.0.0.0', 24), array('192.0.2.0', 24), array('192.88.99.0', 24),
            array('198.18.0.0', 15), array('198.51.100.0', 24), array('203.0.113.0', 24), array('224.0.0.0', 4)) as $range) {
            $base = unpack('Nvalue', inet_pton($range[0]))['value'];
            $mask = (0xffffffff << (32 - $range[1])) & 0xffffffff;
            if (($value & $mask) === ($base & $mask)) { return false; }
        }
        return true;
    }

    private static function download(string $url, int $limit, int $timeout): string
    {
        if (!function_exists('curl_init') || !defined('CURLOPT_RESOLVE')) { throw new RuntimeException(self::ERROR); }
        $deadline = microtime(true) + $timeout;
        $host = (string)parse_url($url, PHP_URL_HOST);
        $addresses = @gethostbynamel($host);
        if (!is_array($addresses) || !$addresses) { throw new RuntimeException(self::ERROR); }
        foreach ($addresses as $address) {
            if (!is_string($address) || !self::publicIp($address)) { throw new RuntimeException(self::ERROR); }
        }
        $milliseconds = (int)floor(($deadline - microtime(true)) * 1000);
        if ($milliseconds < 1) { throw new RuntimeException(self::ERROR); }
        $handle = curl_init($url);
        $bytes = '';
        $options = array(
            CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => array('Accept: image/jpeg,image/png,image/gif,image/webp,application/octet-stream'),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $milliseconds), CURLOPT_TIMEOUT_MS => $milliseconds,
            CURLOPT_PROXY => '', CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE => array($host . ':443:' . $addresses[0]),
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$bytes, $limit) {
                if (strlen($bytes) + strlen($chunk) > $limit) { return 0; }
                $bytes .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, $header) use ($limit) {
                if (preg_match('/\AContent-Length:\s*([0-9]+)/i', $header, $match) && (float)$match[1] > $limit) { return 0; }
                return strlen($header);
            },
        );
        if (defined('CURLOPT_PROTOCOLS_STR')) { $options[CURLOPT_PROTOCOLS_STR] = 'https'; }
        else { $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS; }
        try {
            curl_setopt_array($handle, $options);
            $ok = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($handle);
        }
        if ($ok === false || $status !== 200 || $bytes === '') { throw new RuntimeException(self::ERROR); }
        return $bytes;
    }

    private static function decrypt(string $bytes, array $image): string
    {
        if (empty($image['aeskey'])) { return $bytes; }
        if (!function_exists('openssl_decrypt') || strlen($bytes) % 16 !== 0) { throw new RuntimeException(self::ERROR); }
        $plain = @openssl_decrypt($bytes, 'aes-128-ecb', hex2bin($image['aeskey']), OPENSSL_RAW_DATA);
        if (!is_string($plain)) { throw new RuntimeException(self::ERROR); }
        return $plain;
    }

    private static function image(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || !function_exists('imagecreatefromstring')) {
            throw new RuntimeException(self::ERROR);
        }
        $info = @getimagesizefromstring($bytes);
        $types = array(IMAGETYPE_JPEG => array('image/jpeg', 'jpg'), IMAGETYPE_PNG => array('image/png', 'png'),
            IMAGETYPE_GIF => array('image/gif', 'gif'), IMAGETYPE_WEBP => array('image/webp', 'webp'));
        if (!is_array($info) || !isset($types[$info[2]]) || $info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000
            || $info[0] * $info[1] > self::MAX_PIXELS) { throw new RuntimeException(self::ERROR); }
        $memory = trim((string)ini_get('memory_limit'));
        if (preg_match('/\A([0-9]+)\s*([KMG]?)\z/i', $memory, $match) && (int)$match[1] > 0) {
            $units = array('' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824);
            $available = (int)$match[1] * $units[strtoupper($match[2])] - memory_get_usage(true);
            if ($info[0] * $info[1] * 8 + 16777216 > $available) { throw new RuntimeException(self::ERROR); }
        }
        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) { throw new RuntimeException(self::ERROR); }
        @imagedestroy($decoded);
        return array('name' => 'bot-moment-' . bin2hex(random_bytes(12)) . '.' . $types[$info[2]][1],
            'type' => $types[$info[2]][0], 'size' => strlen($bytes));
    }

    private static function store(array $file): string
    {
        if (class_exists('Enhancement_S3Helper') && Enhancement_S3Helper::enabled()) {
            $stored = Enhancement_S3Helper::uploadHandle($file);
            if (!is_array($stored)) { throw new RuntimeException(self::ERROR); }
            $url = $stored['url'] ?? '';
            return is_string($url) && $url !== '' ? $url : Enhancement_S3Helper::resolveAttachmentUrl($stored);
        }
        if (!class_exists('Widget_Upload')) { throw new RuntimeException(self::ERROR); }
        unset($file['tmp_name']);
        $stored = Widget_Upload::uploadHandle($file);
        if (!is_array($stored)) { throw new RuntimeException(self::ERROR); }
        if (is_string($stored['url'] ?? null) && $stored['url'] !== '') { return $stored['url']; }
        if (!is_string($stored['path'] ?? null) || $stored['path'] === '') { throw new RuntimeException(self::ERROR); }
        return Enhancement_AttachmentResolverHelper::buildLocalAttachmentUrlByPath($stored['path']);
    }

    public static function receive(string $channel, array $attachments, ?callable $download = null, ?callable $store = null): array
    {
        $pending = array();
        try {
            $attachments = self::normalize($channel, $attachments);
            $download = $download ?? array(__CLASS__, 'download');
            $store = $store ?? array(__CLASS__, 'store');
            $deadline = microtime(true) + 20;
            foreach ($attachments as $image) {
                $timeout = min(8, (int)floor($deadline - microtime(true)));
                if ($timeout < 1) { throw new RuntimeException(self::ERROR); }
                $url = $channel === 'qq' ? $image['url'] : $image['media']['full_url'];
                $limit = self::MAX_BYTES + ($channel === 'weixin' && !empty($image['aeskey']) ? 16 : 0);
                $bytes = call_user_func($download, $url, $limit, $timeout);
                if (!is_string($bytes) || strlen($bytes) > $limit || microtime(true) > $deadline) { throw new RuntimeException(self::ERROR); }
                if ($channel === 'weixin') { $bytes = self::decrypt($bytes, $image); }
                $file = self::image($bytes);
                if (microtime(true) > $deadline) { throw new RuntimeException(self::ERROR); }
                $stream = @tmpfile();
                if ($stream === false) { throw new RuntimeException(self::ERROR); }
                $pending[] = array('stream' => $stream, 'file' => $file);
                if (@fwrite($stream, $bytes) !== strlen($bytes)) { throw new RuntimeException(self::ERROR); }
                unset($bytes);
            }
            $media = array();
            foreach ($pending as $item) {
                $remaining = (int)floor($deadline - microtime(true));
                if ($remaining < 1) { throw new RuntimeException(self::ERROR); }
                if (!@rewind($item['stream'])) { throw new RuntimeException(self::ERROR); }
                $file = $item['file'];
                $file['timeout'] = $remaining;
                $file['deadline'] = $deadline;
                $file['tmp_name'] = stream_get_meta_data($item['stream'])['uri'];
                $file['bytes'] = @stream_get_contents($item['stream']);
                if (!is_string($file['bytes']) || strlen($file['bytes']) !== $file['size']) { throw new RuntimeException(self::ERROR); }
                $url = call_user_func($store, $file);
                if (microtime(true) > $deadline) { throw new RuntimeException(self::ERROR); }
                $parts = is_string($url) && strlen($url) <= 8192 ? parse_url($url) : false;
                if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), array('http', 'https'), true)
                    || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
                    throw new RuntimeException(self::ERROR);
                }
                $media[] = array('type' => 'PHOTO', 'url' => $url);
                unset($file);
            }
            return $media;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::ERROR);
        } finally {
            foreach ($pending as $item) { @fclose($item['stream']); }
        }
    }
}
