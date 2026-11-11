<?php

class Enhancement_CommentNotifier_TemplateHelper
{
    public static function directory($theme)
    {
        if (!is_string($theme) || !preg_match('/^[A-Za-z0-9_-]+$/D', $theme)) {
            throw new RuntimeException('邮件模板名称无效');
        }
        $base = realpath(__DIR__ . '/template');
        $path = $base . DIRECTORY_SEPARATOR . $theme;
        if ($base === false || is_link($path) || !is_dir($path) || dirname((string)realpath($path)) !== $base) {
            throw new RuntimeException('邮件模板不存在');
        }
        return $path;
    }

    public static function resolve($theme, $file, $fallback = false)
    {
        if (!is_string($file) || !preg_match('/^[A-Za-z0-9_ .-]+\.html$/Di', $file) || basename($file) !== $file) {
            throw new RuntimeException('邮件模板文件无效');
        }
        $directory = self::directory($theme);
        $path = $directory . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) && $fallback && $theme !== 'default') {
            return self::resolve('default', $file);
        }
        if (is_link($path) || !is_file($path) || dirname((string)realpath($path)) !== realpath($directory)) {
            throw new RuntimeException('邮件模板文件不存在');
        }
        return $path;
    }

    public static function read($theme, $file, $fallback = false)
    {
        $path = self::resolve($theme, $file, $fallback);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('无法读取邮件模板');
        }
        return $content;
    }

    public static function write($theme, $file, $content)
    {
        $path = self::resolve($theme, $file);
        if (!is_string($content) || strlen($content) > 1048576) {
            throw new RuntimeException('邮件模板内容过大或格式无效');
        }
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
            throw new RuntimeException('无法保存邮件模板');
        }
    }
}
