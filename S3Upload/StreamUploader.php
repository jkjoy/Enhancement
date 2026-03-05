<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Enhancement 内置 S3 上传器
 */
class Enhancement_S3Upload_StreamUploader
{
    private $s3Client;
    private $settings;

    public function __construct()
    {
        $this->s3Client = Enhancement_S3Upload_S3Client::getInstance();
        $options = Typecho_Widget::widget('Widget_Options');
        try {
            $this->settings = $options->plugin('Enhancement');
        } catch (Exception $e) {
            $this->settings = (object) array();
        }
    }

    public function handleUpload($file)
    {
        if (!$this->validateFile($file)) {
            throw new Exception('文件验证失败');
        }

        $path = $this->s3Client->generatePath($file);
        $result = $this->s3Client->putObject($path, $file['tmp_name']);

        if ($this->shouldSaveLocal()) {
            $this->saveLocalCopy($file, $path);
        }

        $extension = $this->getFileExt(isset($file['name']) ? (string)$file['name'] : '');
        $mimeType = $this->getMimeType(isset($file['tmp_name']) ? (string)$file['tmp_name'] : '');
        $size = isset($file['size']) ? intval($file['size']) : 0;

        return array(
            'name' => isset($file['name']) ? (string)$file['name'] : basename($path),
            'path' => $path,
            'size' => $size,
            'type' => $extension,
            'mime' => $mimeType,
            'extension' => $extension,
            'url' => isset($result['url']) ? (string)$result['url'] : $this->s3Client->getObjectUrl($path)
        );
    }

    private function validateFile($file)
    {
        if (!is_array($file) || empty($file['name'])) {
            return false;
        }
        if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_readable($file['tmp_name'])) {
            return false;
        }
        if (!isset($file['size']) || intval($file['size']) <= 0) {
            return false;
        }
        return true;
    }

    private function getFileExt($filename)
    {
        $ext = pathinfo((string)$filename, PATHINFO_EXTENSION);
        return $ext ? strtolower((string)$ext) : '';
    }

    private function getMimeType($file)
    {
        return Enhancement_S3Upload_Utils::getMimeType((string)$file);
    }

    private function shouldSaveLocal(): bool
    {
        return isset($this->settings->s3_save_local) && trim((string)$this->settings->s3_save_local) === '1';
    }

    private function saveLocalCopy($file, $path)
    {
        $uploadDir = __TYPECHO_ROOT_DIR__ . '/usr/uploads/';
        $localPath = $uploadDir . ltrim((string)$path, '/');
        $localDir = dirname($localPath);

        if (!is_dir($localDir)) {
            @mkdir($localDir, 0755, true);
        }

        if (!@copy((string)$file['tmp_name'], $localPath)) {
            Enhancement_S3Upload_Utils::log('无法保存本地备份: ' . $localPath, 'warning');
            return false;
        }

        return true;
    }
}
