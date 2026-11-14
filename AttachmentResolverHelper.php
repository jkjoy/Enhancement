<?php

class Enhancement_AttachmentResolverHelper
{
    public static function extractPathFromContent($content)
    {
        return self::extractAttachmentMeta($content)['path'];
    }

    public static function extractAttachmentMeta($content)
    {
        $meta = array(
            'name' => '',
            'path' => '',
            'size' => 0,
            'type' => '',
            'mime' => ''
        );
        $read = function ($source, $key) {
            if (is_object($source)) {
                return isset($source->{$key}) ? $source->{$key} : null;
            }
            return is_array($source) && isset($source[$key]) ? $source[$key] : null;
        };

        $attachment = $read($content, 'attachment');
        foreach ($meta as $key => $default) {
            $value = $read($attachment, $key);
            if ($value !== null) {
                $meta[$key] = $key === 'size' ? intval($value) : (string)$value;
            }
        }
        if ($meta['path'] === '') {
            $meta['path'] = (string)$read($content, 'path');
        }

        $meta['path'] = ltrim(trim($meta['path']), '/');
        return $meta;
    }

    public static function isLocalUploadPath($path): bool
    {
        $path = ltrim(trim((string)$path), '/');
        return strpos($path, 'usr/uploads/') === 0;
    }

    public static function buildLocalAttachmentUrlByPath($path)
    {
        $path = ltrim(trim((string)$path), '/');
        if ($path === '') {
            return '';
        }

        $uploadDir = trim((string)(defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : '/usr/uploads'), '/');
        $relativeToUploadRoot = $path;
        if ($uploadDir !== '') {
            if ($relativeToUploadRoot === $uploadDir) {
                $relativeToUploadRoot = '';
            } elseif (strpos($relativeToUploadRoot, $uploadDir . '/') === 0) {
                $relativeToUploadRoot = substr($relativeToUploadRoot, strlen($uploadDir) + 1);
            }
        }

        $relativeFromSiteRoot = $uploadDir;
        if ($relativeToUploadRoot !== '') {
            $relativeFromSiteRoot .= ($relativeFromSiteRoot === '' ? '' : '/') . $relativeToUploadRoot;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        if (defined('__TYPECHO_UPLOAD_URL__') && trim((string)__TYPECHO_UPLOAD_URL__) !== '') {
            return Typecho_Common::url('/' . ltrim($relativeToUploadRoot, '/'), __TYPECHO_UPLOAD_URL__);
        }

        return Typecho_Common::url('/' . ltrim($relativeFromSiteRoot, '/'), $options->siteUrl);
    }

    public static function resolveLocalAttachmentFullPath($path)
    {
        $path = ltrim(trim((string)$path), '/');
        if ($path === '') {
            return '';
        }

        if (self::isLocalUploadPath($path)) {
            $rootDir = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
            return Typecho_Common::url('/' . ltrim($path, '/'), $rootDir);
        }

        return rtrim(__TYPECHO_ROOT_DIR__, '/\\') . '/usr/uploads/' . $path;
    }

    public static function hasLocalAttachmentBackup($path, $settings = null): bool
    {
        $path = ltrim(trim((string)$path), '/');
        if ($path === '') {
            return false;
        }

        $fullPath = self::resolveLocalAttachmentFullPath($path);
        if ($fullPath === '' || !is_file($fullPath)) {
            return false;
        }

        if (self::isLocalUploadPath($path)) {
            return true;
        }

        if (!is_object($settings)) {
            $settings = (object) array();
        }

        return isset($settings->s3_save_local) && trim((string)$settings->s3_save_local) === '1';
    }
}
