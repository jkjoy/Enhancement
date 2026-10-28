<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (defined('ENHANCEMENT_NOTIFY_TESTS')) { return; }
define('ENHANCEMENT_NOTIFY_TESTS', true);

function _t($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
class Typecho_Widget_Exception extends RuntimeException {}
class Enhancement_Plugin
{
    public static $settings = array();
    public static function runtimeSettings() { return (object)self::$settings; }
}
class Enhancement_ConfigStorageHelper
{
    public static function configHandle($settings, $isInit)
    {
        Enhancement_Plugin::$settings = array_merge(Enhancement_Plugin::$settings, $settings);
    }
}
class Enhancement_NotifyTransportHelper
{
    public static $state = array();
    public static $transport;
    public static function read(string $key): array { return self::$state[$key] ?? array(); }
    public static function write(string $key, array $record): void { self::$state[$key] = $record; }
    public static function compareAndSwap(string $key, array $expected, array $next): bool
    {
        if (self::read($key) !== $expected) { return false; }
        self::write($key, $next);
        return true;
    }
    public static function acquireLease(string $key, int $seconds): ?string
    {
        $current = self::read($key);
        if (($current['expires_at'] ?? 0) > time()) { return null; }
        $token = bin2hex(random_bytes(16));
        self::write($key, array('token' => $token, 'expires_at' => time() + $seconds));
        return $token;
    }
    public static function releaseLease(string $key, string $token): void
    {
        $current = self::read($key);
        if (($current['token'] ?? '') === $token) { self::write($key, array()); }
    }
    public static function request(string $method, string $url, array $headers = array(), ?array $payload = null, int $timeout = 6): array
    {
        if (!is_callable(self::$transport)) { throw new RuntimeException('Live network is disabled in notification tests.'); }
        return call_user_func(self::$transport, $method, $url, $headers, $payload, $timeout);
    }
    public static function apiCode(array $data): string
    {
        $value = $data['errcode'] ?? $data['err_code'] ?? $data['code'] ?? $data['ret'] ?? '';
        return is_scalar($value) && preg_match('/\A-?[0-9]{1,12}\z/D', (string)$value) ? (string)$value : '';
    }
    public static function apiError(string $provider, array $response): RuntimeException
    {
        return new RuntimeException($provider . '接口失败（HTTP ' . $response['status'] . '，错误码 ' . self::apiCode($response['data']) . '）。');
    }
    public static function text(string $text, bool $includeLinks, int $limit = 1800): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        if (!$includeLinks) { $text = preg_replace('~https?://[^\s<>]+~iu', '[链接已省略]', $text); }
        if (preg_match('//u', $text) !== 1) { throw new RuntimeException('Invalid UTF-8.'); }
        if (strlen($text) > $limit) {
            $text = substr($text, 0, $limit - 3);
            while ($text !== '' && preg_match('//u', $text) !== 1) { $text = substr($text, 0, -1); }
            $text .= '…';
        }
        return trim($text);
    }
    public static function recordResult(string $channel, array $result): void
    {
        self::write($channel . '_result', $result);
    }
}

class Enhancement_BotMomentsHelper
{
    public static $handler;
    public static $calls = array();
    public static function handle(string $channel, array $message): ?array
    {
        self::$calls[] = array($channel, $message);
        return is_callable(self::$handler) ? call_user_func(self::$handler, $channel, $message) : null;
    }
}

function notifyReset(array $settings = array()): void
{
    Enhancement_Plugin::$settings = $settings;
    Enhancement_NotifyTransportHelper::$state = array();
    Enhancement_NotifyTransportHelper::$transport = null;
    Enhancement_BotMomentsHelper::$handler = null;
    Enhancement_BotMomentsHelper::$calls = array();
}
function notifySettings(array $settings): void
{
    Enhancement_Plugin::$settings = array_merge(Enhancement_Plugin::$settings, $settings);
}
$notifyPassed = 0;
function notifyCheck(bool $condition, string $message): void
{
    global $notifyPassed;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $notifyPassed++;
}
