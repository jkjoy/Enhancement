<?php

require_once __DIR__ . '/AttachmentResolverHelper.php';

class Enhancement_S3Helper
{
    private static $runtimeLoaded = null;
    private static $uploadHookLogged = false;
    private static $uploadResults = array();
    private static $hookTargets = array(
        'Widget\\Upload',
        'Widget_Upload'
    );
    private static $hookComponents = array(
        'uploadHandle',
        'modifyHandle',
        'deleteHandle',
        'attachmentHandle',
        'attachmentDataHandle'
    );

    public static function registerHooks()
    {
        if (!self::enabled()) {
            self::unregisterHooks();
            return;
        }

        $registered = array();
        foreach (self::$hookTargets as $target) {
            $nativeTarget = self::nativeClassName($target);
            if (isset($registered[$nativeTarget])) {
                continue;
            }
            $registered[$nativeTarget] = true;

            $factory = Typecho_Plugin::factory($target);
            self::removeRuntimeCallbacks($factory);
            $factory->uploadHandle = array(__CLASS__, 'uploadHandle');
            $factory->modifyHandle = array(__CLASS__, 'modifyHandle');
            $factory->deleteHandle = array(__CLASS__, 'deleteHandle');
            $factory->attachmentHandle = array(__CLASS__, 'attachmentHandle');
            $factory->attachmentDataHandle = array(__CLASS__, 'attachmentDataHandle');
        }

        self::persistRegisteredHooks();
    }

    public static function unregisterHooks()
    {
        if (!class_exists('Typecho_Plugin') || !method_exists('Typecho_Plugin', 'export')) {
            return;
        }

        $plugins = self::exportPluginHandles();
        if (!is_array($plugins)) {
            return;
        }

        $callbacks = array(
            array(__CLASS__, 'uploadHandle'),
            array(__CLASS__, 'modifyHandle'),
            array(__CLASS__, 'deleteHandle'),
            array(__CLASS__, 'attachmentHandle'),
            array(__CLASS__, 'attachmentDataHandle')
        );

        foreach (self::$hookTargets as $target) {
            $nativeTarget = self::nativeClassName($target);
            foreach (self::$hookComponents as $component) {
                $handle = $nativeTarget . ':' . $component;
                if (isset($plugins['handles'][$handle]) && is_array($plugins['handles'][$handle])) {
                    $plugins['handles'][$handle] = self::removeCallbacks($plugins['handles'][$handle], $callbacks);
                    if (empty($plugins['handles'][$handle])) {
                        unset($plugins['handles'][$handle]);
                    }
                }

                if (
                    isset($plugins['activated']['Enhancement']['handles'][$handle])
                    && is_array($plugins['activated']['Enhancement']['handles'][$handle])
                ) {
                    $plugins['activated']['Enhancement']['handles'][$handle] = self::removeCallbacks(
                        $plugins['activated']['Enhancement']['handles'][$handle],
                        $callbacks
                    );
                    if (empty($plugins['activated']['Enhancement']['handles'][$handle])) {
                        unset($plugins['activated']['Enhancement']['handles'][$handle]);
                    }
                }
            }
        }

        self::initPluginHandles($plugins);
        self::persistPluginHandles($plugins);
    }

    private static function removeCallbacks(array $handles, array $callbacks): array
    {
        foreach ($handles as $key => $callback) {
            foreach ($callbacks as $target) {
                if ($callback === $target) {
                    unset($handles[$key]);
                    break;
                }
            }
        }

        return $handles;
    }

    private static function nativeClassName($className)
    {
        if (class_exists('Typecho_Common') && method_exists('Typecho_Common', 'nativeClassName')) {
            return Typecho_Common::nativeClassName($className);
        }
        if (class_exists('\\Typecho\\Common') && method_exists('\\Typecho\\Common', 'nativeClassName')) {
            return \Typecho\Common::nativeClassName($className);
        }

        return trim(str_replace('\\', '_', (string)$className), '_');
    }

    private static function removeRuntimeCallbacks($factory)
    {
        if (!is_object($factory)) {
            return;
        }

        try {
            $ref = new ReflectionClass($factory);
            if (!$ref->hasProperty('handle')) {
                return;
            }

            $handleProperty = $ref->getProperty('handle');
            $handleProperty->setAccessible(true);
            $handle = $handleProperty->getValue($factory);

            if (!$ref->hasProperty('plugin')) {
                return;
            }

            $pluginProperty = $ref->getProperty('plugin');
            $pluginProperty->setAccessible(true);
            $plugins = $pluginProperty->getValue();
            if (!is_array($plugins)) {
                return;
            }

            $callbacks = array(
                array(__CLASS__, 'uploadHandle'),
                array(__CLASS__, 'modifyHandle'),
                array(__CLASS__, 'deleteHandle'),
                array(__CLASS__, 'attachmentHandle'),
                array(__CLASS__, 'attachmentDataHandle')
            );

            foreach (self::$hookComponents as $component) {
                $componentHandle = $handle . ':' . $component;
                if (isset($plugins['handles'][$componentHandle]) && is_array($plugins['handles'][$componentHandle])) {
                    $plugins['handles'][$componentHandle] = self::removeCallbacks($plugins['handles'][$componentHandle], $callbacks);
                    if (empty($plugins['handles'][$componentHandle])) {
                        unset($plugins['handles'][$componentHandle]);
                    }
                }
            }

            $pluginProperty->setValue(null, $plugins);
        } catch (Exception $e) {
            // Keep registration working even if runtime cleanup fails on older Typecho builds.
        }
    }

    private static function exportPluginHandles()
    {
        if (!class_exists('Typecho_Plugin') || !method_exists('Typecho_Plugin', 'export')) {
            return null;
        }

        try {
            $plugins = Typecho_Plugin::export();
        } catch (Exception $e) {
            return null;
        }

        if (!is_array($plugins)) {
            return null;
        }

        if (!isset($plugins['activated']) || !is_array($plugins['activated'])) {
            $plugins['activated'] = array();
        }
        if (!isset($plugins['handles']) || !is_array($plugins['handles'])) {
            $plugins['handles'] = array();
        }

        return $plugins;
    }

    private static function initPluginHandles(array $plugins)
    {
        if (!class_exists('Typecho_Plugin') || !method_exists('Typecho_Plugin', 'init')) {
            return;
        }

        try {
            Typecho_Plugin::init($plugins);
        } catch (Exception $e) {
            // Keep the persisted plugin table untouched if runtime re-init fails.
        }
    }

    private static function persistPluginHandles(array $plugins)
    {
        $value = json_encode($plugins);
        if (!is_string($value) || $value === '') {
            return;
        }

        try {
            $db = Typecho_Db::get();
            $db->query(
                $db->update('table.options')
                    ->rows(array('value' => $value))
                    ->where('name = ?', 'plugins')
                    ->where('user = ?', 0)
            );
        } catch (Exception $e) {
            // Ignore persistence errors; the in-memory handles are already cleaned for this request.
        }
    }

    private static function persistRegisteredHooks()
    {
        if (!class_exists('Typecho_Plugin') || !method_exists('Typecho_Plugin', 'export')) {
            return;
        }

        $plugins = self::exportPluginHandles();
        if (!is_array($plugins)) {
            return;
        }

        if (!isset($plugins['activated']['Enhancement']) || !is_array($plugins['activated']['Enhancement'])) {
            $plugins['activated']['Enhancement'] = array();
        }
        if (!isset($plugins['activated']['Enhancement']['handles']) || !is_array($plugins['activated']['Enhancement']['handles'])) {
            $plugins['activated']['Enhancement']['handles'] = array();
        }

        $callbacks = self::componentCallbacks();
        $registered = array();
        foreach (self::$hookTargets as $target) {
            $nativeTarget = self::nativeClassName($target);
            if (isset($registered[$nativeTarget])) {
                continue;
            }
            $registered[$nativeTarget] = true;

            foreach ($callbacks as $component => $callback) {
                $handle = $nativeTarget . ':' . $component;
                if (!isset($plugins['handles'][$handle]) || !is_array($plugins['handles'][$handle])) {
                    $plugins['handles'][$handle] = array();
                }
                $plugins['handles'][$handle] = self::removeCallbacks($plugins['handles'][$handle], array($callback));
                $plugins['handles'][$handle] = self::addWeightedCallback($plugins['handles'][$handle], $callback);

                if (!isset($plugins['activated']['Enhancement']['handles'][$handle]) || !is_array($plugins['activated']['Enhancement']['handles'][$handle])) {
                    $plugins['activated']['Enhancement']['handles'][$handle] = array();
                }
                $plugins['activated']['Enhancement']['handles'][$handle] = self::removeCallbacks(
                    $plugins['activated']['Enhancement']['handles'][$handle],
                    array($callback)
                );
                $plugins['activated']['Enhancement']['handles'][$handle][] = $callback;
            }
        }

        self::initPluginHandles($plugins);
        self::persistPluginHandles($plugins);
    }

    private static function componentCallbacks(): array
    {
        return array(
            'uploadHandle' => array(__CLASS__, 'uploadHandle'),
            'modifyHandle' => array(__CLASS__, 'modifyHandle'),
            'deleteHandle' => array(__CLASS__, 'deleteHandle'),
            'attachmentHandle' => array(__CLASS__, 'attachmentHandle'),
            'attachmentDataHandle' => array(__CLASS__, 'attachmentDataHandle')
        );
    }

    private static function addWeightedCallback(array $handles, $callback): array
    {
        $weight = 0.0;
        while (array_key_exists((string)$weight, $handles)) {
            $weight += 0.001;
        }

        $handles[(string)$weight] = $callback;
        ksort($handles, SORT_NUMERIC);
        return $handles;
    }

    public static function enabled(): bool
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        if (!isset($settings->enable_s3_upload)) {
            return false;
        }

        return trim((string)$settings->enable_s3_upload) === '1';
    }

    public static function configured(): bool
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        $required = array('s3_endpoint', 's3_bucket', 's3_region', 's3_access_key', 's3_secret_key');
        foreach ($required as $key) {
            $value = isset($settings->{$key}) ? trim((string)$settings->{$key}) : '';
            if ($value === '') {
                return false;
            }
        }

        return true;
    }

    private static function loadRuntime(): bool
    {
        if (self::$runtimeLoaded !== null) {
            return self::$runtimeLoaded;
        }

        $files = array(
            __DIR__ . '/S3Upload/Utils.php',
            __DIR__ . '/S3Upload/S3Client.php',
            __DIR__ . '/S3Upload/StreamUploader.php',
            __DIR__ . '/S3Upload/FileHandler.php'
        );

        foreach ($files as $file) {
            if (!is_file($file)) {
                self::$runtimeLoaded = false;
                return false;
            }
            require_once $file;
        }

        self::$runtimeLoaded = class_exists('Enhancement_S3Upload_FileHandler');
        return self::$runtimeLoaded;
    }

    public static function uploadHandle($file)
    {
        $uploadKey = self::uploadCacheKey($file);
        if ($uploadKey !== '' && array_key_exists($uploadKey, self::$uploadResults)) {
            return self::$uploadResults[$uploadKey];
        }

        if (!self::loadRuntime()) {
            error_log('[Enhancement S3Upload] 上传钩子触发，但未加载到 S3 运行时文件');
            return false;
        }

        if (!self::$uploadHookLogged && class_exists('Enhancement_S3Upload_Utils')) {
            Enhancement_S3Upload_Utils::log('已进入 Enhancement S3 上传钩子', 'info');
            self::$uploadHookLogged = true;
        }

        $result = Enhancement_S3Upload_FileHandler::uploadHandle($file);
        if ($uploadKey !== '') {
            self::$uploadResults[$uploadKey] = $result;
        }

        return $result;
    }

    public static function modifyHandle($content, $file)
    {
        if (!self::loadRuntime()) {
            return false;
        }

        return Enhancement_S3Upload_FileHandler::modifyHandle($content, $file);
    }

    public static function deleteHandle($content)
    {
        if (!self::loadRuntime()) {
            return false;
        }

        return Enhancement_S3Upload_FileHandler::deleteHandle($content);
    }

    public static function attachmentHandle($content)
    {
        if (!self::loadRuntime()) {
            return '';
        }

        return Enhancement_S3Upload_FileHandler::attachmentHandle($content);
    }

    public static function attachmentDataHandle($content)
    {
        if (!self::loadRuntime()) {
            return '';
        }

        return Enhancement_S3Upload_FileHandler::attachmentDataHandle($content);
    }

    public static function resolveAttachmentUrl($content)
    {
        if (self::loadRuntime()) {
            return Enhancement_S3Upload_FileHandler::attachmentHandle($content);
        }

        $path = '';
        if (is_array($content)) {
            if (isset($content['attachment'])) {
                if (is_object($content['attachment']) && isset($content['attachment']->path)) {
                    $path = (string)$content['attachment']->path;
                } elseif (is_array($content['attachment']) && isset($content['attachment']['path'])) {
                    $path = (string)$content['attachment']['path'];
                }
            }

            if ($path === '' && isset($content['path'])) {
                $path = (string)$content['path'];
            }
        } elseif (is_object($content)) {
            if (isset($content->attachment)) {
                if (is_object($content->attachment) && isset($content->attachment->path)) {
                    $path = (string)$content->attachment->path;
                } elseif (is_array($content->attachment) && isset($content->attachment['path'])) {
                    $path = (string)$content->attachment['path'];
                }
            }

            if ($path === '' && isset($content->path)) {
                $path = (string)$content->path;
            }
        }

        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return '';
        }

        return Enhancement_AttachmentResolverHelper::buildLocalAttachmentUrlByPath($path);
    }

    private static function uploadCacheKey($file)
    {
        if (!is_array($file)) {
            return '';
        }

        $name = isset($file['name']) ? (string)$file['name'] : '';
        $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
        if ($name === '' && $tmpName === '') {
            return '';
        }

        $size = isset($file['size']) ? (string)$file['size'] : '';
        return sha1($name . "\n" . $tmpName . "\n" . $size);
    }
}
