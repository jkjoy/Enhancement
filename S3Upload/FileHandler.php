<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__DIR__) . '/AttachmentResolverHelper.php';

class Enhancement_S3Upload_FileHandler
{
    const MIN_COMPRESS_SIZE = 102400; // 100KB

    public static function uploadHandle($file)
    {
        if (!is_array($file) || empty($file['name'])) {
            return false;
        }

        if (!self::canPerformRemoteObjectRequests()) {
            if (class_exists('Enhancement_Plugin') && Enhancement_Plugin::s3UploadEnabled()) {
                Enhancement_S3Upload_Utils::log('S3 上传未生效：配置不完整或服务器环境不满足，已回退本地上传', 'warning');
            }
            if (isset($file['bytes']) && is_string($file['bytes'])) { unset($file['tmp_name']); }
            return self::localUploadHandle($file);
        }

        try {
            $name = (string)$file['name'];
            $ext = self::getSafeName($name);
            $file['name'] = $name;

            if (!self::checkFileType($ext)) {
                Enhancement_S3Upload_Utils::log('文件类型不允许上传：' . $ext, 'warning');
                return false;
            }

            $settings = self::pluginSettings();
            $mime = isset($file['type']) && trim((string)$file['type']) !== ''
                ? (string)$file['type']
                : Enhancement_S3Upload_Utils::getMimeType(isset($file['tmp_name']) ? (string)$file['tmp_name'] : '');
            $isImage = self::isImage($mime);

            $tempFile = null;
            $webpName = null;
            $compressEnabled = isset($settings->s3_compress_images) && trim((string)$settings->s3_compress_images) === '1';
            if (
                $compressEnabled
                && $isImage
                && isset($file['tmp_name'])
                && is_string($file['tmp_name'])
                && is_file($file['tmp_name'])
                && filesize($file['tmp_name']) > self::MIN_COMPRESS_SIZE
            ) {
                $quality = isset($settings->s3_compress_quality) ? intval($settings->s3_compress_quality) : 85;
                if ($quality < 1 || $quality > 100) {
                    $quality = 85;
                }

                $tempFile = tempnam(sys_get_temp_dir(), 'enh_s3_');
                if ($tempFile !== false) {
                    $tempFile .= '.webp';
                    if (self::convertToWebp($file['tmp_name'], $tempFile, $mime, $quality)) {
                        $file['tmp_name'] = $tempFile;
                        $file['size'] = filesize($tempFile);
                        $file['type'] = 'image/webp';
                        $webpName = self::replaceExtToWebp($file['name']);
                        $file['name'] = $webpName;
                    } else {
                        @unlink($tempFile);
                        $tempFile = null;
                    }
                }
            }

            $uploader = new Enhancement_S3Upload_StreamUploader();
            $result = $uploader->handleUpload($file);

            if ($tempFile && is_file($tempFile)) {
                @unlink($tempFile);
            }

            if (!$result || !is_array($result)) {
                Enhancement_S3Upload_Utils::log('S3 上传返回结果异常', 'error');
                return false;
            }

            if ($webpName) {
                $result['name'] = $webpName;
                $result['type'] = 'webp';
                $result['mime'] = 'image/webp';
                $result['extension'] = 'webp';
            }

            return array(
                'name' => isset($result['name']) ? $result['name'] : $file['name'],
                'path' => isset($result['path']) ? $result['path'] : '',
                'size' => isset($result['size']) ? intval($result['size']) : 0,
                'type' => isset($result['type']) ? $result['type'] : '',
                'mime' => isset($result['mime']) ? $result['mime'] : 'application/octet-stream',
                'extension' => isset($result['extension']) ? $result['extension'] : '',
                'created' => time(),
                'attachment' => (object) array('path' => isset($result['path']) ? $result['path'] : ''),
                'isImage' => self::isImage(isset($result['mime']) ? (string)$result['mime'] : ''),
                'url' => isset($result['url']) ? $result['url'] : ''
            );
        } catch (Exception $e) {
            Enhancement_S3Upload_Utils::log('上传处理错误: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public static function modifyHandle($content, $file)
    {
        if (!self::canPerformRemoteObjectRequests()) {
            return self::localModifyHandle($content, $file);
        }

        if (!is_array($file) || empty($file['name'])) {
            return false;
        }

        try {
            $result = self::uploadHandle($file);
            if ($result) {
                self::deleteHandle($content);
                return $result;
            }
        } catch (Exception $e) {
            Enhancement_S3Upload_Utils::log('修改文件错误: ' . $e->getMessage(), 'error');
        }

        return false;
    }

    public static function deleteHandle($content)
    {
        if (!self::canPerformRemoteObjectRequests()) {
            return self::localDeleteHandle($content);
        }

        $path = Enhancement_AttachmentResolverHelper::extractPathFromContent($content);
        if ($path === '') {
            return false;
        }
        if (Enhancement_AttachmentResolverHelper::isLocalUploadPath($path)) {
            return self::localDeleteHandle($content);
        }

        try {
            $client = Enhancement_S3Upload_S3Client::getInstance();
            $result = $client->deleteObject($path);

            $settings = self::pluginSettings();
            if (isset($settings->s3_save_local) && trim((string)$settings->s3_save_local) === '1') {
                $localPath = __TYPECHO_ROOT_DIR__ . '/usr/uploads/' . ltrim($path, '/');
                if (is_file($localPath)) {
                    @unlink($localPath);
                }
            }

            return (bool)$result;
        } catch (Exception $e) {
            Enhancement_S3Upload_Utils::log('删除文件错误: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public static function attachmentHandle($content)
    {
        $path = Enhancement_AttachmentResolverHelper::extractPathFromContent($content);
        if ($path === '') {
            return '';
        }
        if (Enhancement_AttachmentResolverHelper::isLocalUploadPath($path)) {
            return self::localAttachmentHandle($content);
        }

        if (self::canBuildRemoteAttachmentUrl()) {
            try {
                $client = Enhancement_S3Upload_S3Client::getInstance();
                $url = $client->getObjectUrl($path);
                if (is_string($url) && trim($url) !== '') {
                    return $url;
                }
            } catch (Exception $e) {
                Enhancement_S3Upload_Utils::log('获取附件URL失败: ' . $e->getMessage(), 'error');
            }
        }

        if (self::hasLocalAttachmentBackup($path)) {
            return Enhancement_AttachmentResolverHelper::buildLocalAttachmentUrlByPath($path);
        }

        return '';
    }

    public static function attachmentDataHandle($content)
    {
        $path = Enhancement_AttachmentResolverHelper::extractPathFromContent($content);
        if ($path === '') {
            return '';
        }
        if (Enhancement_AttachmentResolverHelper::isLocalUploadPath($path)) {
            return self::localAttachmentDataHandle($content);
        }

        if (self::canBuildRemoteAttachmentUrl()) {
            $url = self::attachmentHandle($content);
            if ($url !== '') {
                $data = @file_get_contents($url);
                if (is_string($data)) {
                    return $data;
                }
            }
        }

        if (self::hasLocalAttachmentBackup($path)) {
            return self::localAttachmentDataHandle($content);
        }

        return '';
    }

    private static function canPerformRemoteObjectRequests(): bool
    {
        if (!class_exists('Enhancement_Plugin')) {
            return false;
        }
        if (!Enhancement_Plugin::s3UploadEnabled()) {
            return false;
        }
        if (!Enhancement_Plugin::s3UploadConfigured()) {
            return false;
        }
        if (!function_exists('curl_init')) {
            return false;
        }
        return true;
    }

    private static function pluginSettings()
    {
        if (class_exists('Enhancement_Plugin') && method_exists('Enhancement_Plugin', 'runtimeSettings')) {
            $settings = Enhancement_Plugin::runtimeSettings();
            if (is_object($settings)) {
                return $settings;
            }
        }

        return (object) array();
    }

    private static function localUploadHandle($file)
    {
        if (!is_array($file) || empty($file['name'])) {
            return false;
        }

        $name = (string)$file['name'];
        $ext = self::getSafeName($name);
        $file['name'] = $name;

        if (!self::checkFileType($ext)) {
            return false;
        }

        $date = new Typecho_Date();
        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : '/usr/uploads';
        $rootDir = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
        $path = Typecho_Common::url($uploadDir, $rootDir) . '/' . $date->year . '/' . $date->month;

        if (!is_dir($path) && !self::makeUploadDir($path)) {
            return false;
        }

        $fileName = sprintf('%u', crc32(uniqid((string)mt_rand(), true))) . '.' . $ext;
        $fullPath = $path . '/' . $fileName;

        if (isset($file['tmp_name'])) {
            if (!@move_uploaded_file($file['tmp_name'], $fullPath)) {
                return false;
            }
        } elseif (isset($file['bytes'])) {
            if (!file_put_contents($fullPath, $file['bytes'])) {
                return false;
            }
        } elseif (isset($file['bits'])) {
            if (!file_put_contents($fullPath, $file['bits'])) {
                return false;
            }
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($fullPath);
        }

        $relativePath = $uploadDir . '/' . $date->year . '/' . $date->month . '/' . $fileName;
        $mime = Typecho_Common::mimeContentType($fullPath);

        return array(
            'name' => $file['name'],
            'path' => $relativePath,
            'size' => intval($file['size']),
            'type' => $ext,
            'mime' => $mime
        );
    }

    private static function localModifyHandle($content, $file)
    {
        if (!is_array($file) || empty($file['name'])) {
            return false;
        }

        $meta = Enhancement_AttachmentResolverHelper::extractAttachmentMeta($content);
        if ($meta['path'] === '' || $meta['type'] === '') {
            return false;
        }

        $name = (string)$file['name'];
        $ext = self::getSafeName($name);
        if (strtolower($meta['type']) !== strtolower($ext)) {
            return false;
        }

        $rootDir = defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
        $fullPath = Typecho_Common::url('/' . ltrim($meta['path'], '/'), $rootDir);
        $dir = dirname($fullPath);

        if (!is_dir($dir) && !self::makeUploadDir($dir)) {
            return false;
        }

        if (isset($file['tmp_name'])) {
            @unlink($fullPath);
            if (!@move_uploaded_file($file['tmp_name'], $fullPath)) {
                return false;
            }
        } elseif (isset($file['bytes'])) {
            @unlink($fullPath);
            if (!file_put_contents($fullPath, $file['bytes'])) {
                return false;
            }
        } elseif (isset($file['bits'])) {
            @unlink($fullPath);
            if (!file_put_contents($fullPath, $file['bits'])) {
                return false;
            }
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($fullPath);
        }

        return array(
            'name' => $meta['name'] !== '' ? $meta['name'] : $name,
            'path' => '/' . ltrim($meta['path'], '/'),
            'size' => intval($file['size']),
            'type' => $meta['type'],
            'mime' => $meta['mime']
        );
    }

    private static function localDeleteHandle($content)
    {
        $path = Enhancement_AttachmentResolverHelper::extractPathFromContent($content);
        if ($path === '') {
            return false;
        }

        $fullPath = Enhancement_AttachmentResolverHelper::resolveLocalAttachmentFullPath($path);
        if ($fullPath === '') {
            return false;
        }

        return @unlink($fullPath);
    }

    private static function localAttachmentHandle($content)
    {
        $path = Enhancement_AttachmentResolverHelper::extractPathFromContent($content);
        if ($path === '') {
            return '';
        }

        return Enhancement_AttachmentResolverHelper::buildLocalAttachmentUrlByPath($path);
    }

    private static function localAttachmentDataHandle($content)
    {
        $path = Enhancement_AttachmentResolverHelper::extractPathFromContent($content);
        if ($path === '') {
            return '';
        }

        $fullPath = Enhancement_AttachmentResolverHelper::resolveLocalAttachmentFullPath($path);
        if ($fullPath === '') {
            return '';
        }

        $data = @file_get_contents($fullPath);
        return is_string($data) ? $data : '';
    }

    private static function getSafeName(&$name)
    {
        $name = str_replace(array('"', '<', '>'), '', (string)$name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower((string)$info['extension']) : '';
    }

    private static function checkFileType($ext): bool
    {
        $ext = strtolower(trim((string)$ext));
        if ($ext === '') {
            return false;
        }

        // 与 Typecho 后台「允许上传文件类型」设置保持一致
        if (class_exists('Widget_Upload') && method_exists('Widget_Upload', 'checkFileType')) {
            return Widget_Upload::checkFileType($ext);
        }
        if (class_exists('Widget\\Upload') && method_exists('Widget\\Upload', 'checkFileType')) {
            return \Widget\Upload::checkFileType($ext);
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $allowed = isset($options->allowedAttachmentTypes) ? $options->allowedAttachmentTypes : array();
        if (is_string($allowed)) {
            $allowed = preg_split('/\s*,\s*/', trim($allowed));
        }
        if (!is_array($allowed)) {
            return false;
        }

        $normalized = array();
        foreach ($allowed as $item) {
            $item = strtolower(trim((string)$item));
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return in_array($ext, $normalized, true);
    }

    private static function makeUploadDir($path): bool
    {
        $path = preg_replace("/\\\+/", '/', (string)$path);
        $current = rtrim($path, '/');
        $last = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last = $current;
            $current = dirname($current);
        }

        if ($last === $current) {
            return true;
        }

        if (!@mkdir($last, 0755)) {
            return false;
        }

        return self::makeUploadDir($path);
    }

    private static function isImage($mime): bool
    {
        return strpos(strtolower(trim((string)$mime)), 'image/') === 0;
    }

    private static function convertToWebp($src, $dest, $mime, $quality = 85)
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            return false;
        }
        if (!is_file($src)) {
            return false;
        }

        $mime = strtolower(trim((string)$mime));
        $image = null;
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            $image = @imagecreatefromjpeg($src);
        } elseif ($mime === 'image/png') {
            $image = @imagecreatefrompng($src);
            if ($image) {
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
            }
        } elseif ($mime === 'image/gif') {
            $image = @imagecreatefromgif($src);
        }

        if (!$image) {
            return false;
        }

        $result = @imagewebp($image, $dest, intval($quality));
        imagedestroy($image);
        return $result && is_file($dest);
    }

    private static function replaceExtToWebp($filename)
    {
        $info = pathinfo((string)$filename);
        $dirname = isset($info['dirname']) && $info['dirname'] !== '.' ? $info['dirname'] . '/' : '';
        $base = isset($info['filename']) ? $info['filename'] : (isset($info['basename']) ? $info['basename'] : 'image');
        return $dirname . $base . '.webp';
    }

    private static function canBuildRemoteAttachmentUrl(): bool
    {
        if (!class_exists('Enhancement_Plugin')) {
            return false;
        }
        if (!Enhancement_Plugin::s3UploadEnabled()) {
            return false;
        }
        if (!Enhancement_Plugin::s3UploadConfigured()) {
            return false;
        }

        return class_exists('Enhancement_S3Upload_S3Client');
    }

    private static function hasLocalAttachmentBackup($path): bool
    {
        $settings = self::pluginSettings();
        return Enhancement_AttachmentResolverHelper::hasLocalAttachmentBackup($path, $settings);
    }
}
