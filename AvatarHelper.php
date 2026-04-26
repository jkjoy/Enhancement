<?php

class Enhancement_AvatarHelper
{
    private static $commentFilterRegistered = false;

    public static function mirrorEnabled(): bool
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        if (!isset($settings->enable_avatar_mirror)) {
            return true;
        }

        return $settings->enable_avatar_mirror == '1';
    }

    public static function baseUrl(): string
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        $defaultMirror = 'https://cn.cravatar.com/avatar/';
        $defaultGravatar = 'https://secure.gravatar.com/avatar/';
        $enabled = !isset($settings->enable_avatar_mirror) || $settings->enable_avatar_mirror == '1';

        if ($enabled) {
            $base = !empty($settings->avatar_mirror_url) ? $settings->avatar_mirror_url : $defaultMirror;
        } else {
            $base = $defaultGravatar;
        }

        $base = trim((string)$base);
        if ($base === '') {
            $base = $enabled ? $defaultMirror : $defaultGravatar;
        }

        return self::normalizeBase($base);
    }

    public static function applyPrefix($archive = null, $select = null)
    {
        self::registerRuntimeCommentFilter();
        Enhancement_GoRedirectHelper::upgradeLegacyCommentUrls();
        Enhancement_QqNotifyHelper::processQueue();

        if (!self::mirrorEnabled()) {
            return;
        }

        if (!defined('__TYPECHO_GRAVATAR_PREFIX__')) {
            define('__TYPECHO_GRAVATAR_PREFIX__', self::baseUrl());
        }
    }

    public static function filterCommentRowUrl($row, $widget = null, $lastRow = null)
    {
        if (!is_array($row)) {
            return $row;
        }

        $currentUrl = isset($row['url']) ? trim((string)$row['url']) : '';
        if ($currentUrl === '') {
            return $row;
        }

        $row['url'] = Enhancement_GoRedirectHelper::convertExternalUrlToGo($currentUrl);
        return $row;
    }

    public static function buildUrl($email, $size = null, $default = null, array $extra = array()): string
    {
        $hash = md5(strtolower(trim((string)$email)));
        $params = array();
        if ($size !== null) {
            $params['s'] = intval($size);
        }
        if ($default !== null && $default !== '') {
            $params['d'] = $default;
        }
        if (!empty($extra)) {
            foreach ($extra as $key => $value) {
                if ($value !== null && $value !== '') {
                    $params[$key] = $value;
                }
            }
        }

        $query = http_build_query($params);
        return self::baseUrl() . $hash . ($query ? '?' . $query : '');
    }

    private static function registerRuntimeCommentFilter()
    {
        if (self::$commentFilterRegistered) {
            return;
        }

        self::$commentFilterRegistered = true;
        Typecho_Plugin::factory('Widget_Abstract_Comments')->filter = Enhancement_Plugin::callback('filterCommentRowUrl');
    }

    private static function normalizeBase(string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            return 'https://cn.cravatar.com/avatar/';
        }
        if (substr($base, -1) !== '/') {
            $base .= '/';
        }

        return $base;
    }
}
