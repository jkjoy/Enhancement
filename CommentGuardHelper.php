<?php

require_once __DIR__ . '/CommentGuardContentHelper.php';
require_once __DIR__ . '/CommentGuardRateHelper.php';

class Enhancement_CommentGuardHelper
{
    public static function filterComment($comment, $post, $last)
    {
        $current = is_array($last) ? $last : $comment;
        $settings = Enhancement_Plugin::runtimeSettings();
        if (!isset($settings->enable_comment_guard) || (string)$settings->enable_comment_guard !== '1') {
            return $current;
        }

        // Typecho may trust X-Forwarded-For by default. Only trust explicitly
        // configured proxies here, so clients cannot rotate a forged header.
        $current['ip'] = self::clientIp(Typecho_Request::getInstance(), $settings);
        Enhancement_CommentGuardRateHelper::check($current['ip'], (string)$current['text'], $settings);
        Enhancement_CommentGuardContentHelper::validate($current, $settings);
        return $current;
    }

    public static function clientIp($request, $settings): string
    {
        $peer = Enhancement_CommentGuardContentHelper::normalizeIp($request->getServer('REMOTE_ADDR', ''));
        if ($peer === '') {
            throw new Typecho_Widget_Exception(_t('无法识别评论来源 IP，请稍后重试。'), 403);
        }
        $rules = preg_split('/\r\n|\r|\n/', isset($settings->comment_guard_trusted_proxies)
            ? (string)$settings->comment_guard_trusted_proxies : '');
        $trusted = function ($ip) use ($rules) {
            foreach ($rules as $rule) {
                if (Enhancement_CommentGuardContentHelper::matchesIpRule($ip, trim($rule))) {
                    return true;
                }
            }
            return false;
        };
        if (!$trusted($peer)) {
            return $peer;
        }

        $header = defined('__TYPECHO_IP_SOURCE__') ? __TYPECHO_IP_SOURCE__ : 'X-Forwarded-For';
        $forwarded = trim((string)$request->getHeader($header));
        if ($forwarded === '') {
            return $peer;
        }
        if (strlen($forwarded) > 2048) {
            throw new Typecho_Widget_Exception(_t('评论来源转发信息过长，请检查代理设置。'), 403);
        }
        $hops = explode(',', $forwarded);
        if (count($hops) > 32) {
            throw new Typecho_Widget_Exception(_t('评论来源转发层数过多，请检查代理设置。'), 403);
        }

        $ip = $peer;
        for ($index = count($hops) - 1; $index >= 0 && $trusted($ip); $index--) {
            $ip = Enhancement_CommentGuardContentHelper::normalizeIp(trim($hops[$index]));
            if ($ip === '') {
                throw new Typecho_Widget_Exception(_t('无法识别代理转发的评论来源 IP。'), 403);
            }
        }
        return $ip;
    }

    public static function validIpRules($value): bool
    {
        return is_string($value) && empty(Enhancement_CommentGuardContentHelper::validateIpRules($value));
    }

    public static function validLimit($value, $min, $max): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int)$value >= $min && (int)$value <= $max;
    }

    public static function validateSettings(array $settings)
    {
        foreach (array('comment_guard_blocked_ips', 'comment_guard_trusted_proxies') as $key) {
            if (isset($settings[$key]) && !self::validIpRules($settings[$key])) {
                throw new Typecho_Widget_Exception(_t('评论防御的 IP 规则格式错误，请使用单个 IP、CIDR 网段或 IPv4 尾部 * 通配，一行一条。'), 400);
            }
        }
        foreach (array('comment_guard_window' => array(10, 86400), 'comment_guard_ip_limit' => array(1, 1000),
            'comment_guard_subnet_limit' => array(1, 10000)) as $key => $range) {
            if (isset($settings[$key]) && !self::validLimit($settings[$key], $range[0], $range[1])) {
                throw new Typecho_Widget_Exception(_t('评论防御的时间窗口或次数限制超出允许范围。'), 400);
            }
        }
    }
}
