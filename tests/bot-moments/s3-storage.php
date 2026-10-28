<?php
/** Actual S3 upload pipeline with a fake object client and disposable local storage. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$storageFixture = sys_get_temp_dir() . '/enh-bot-s3-' . bin2hex(random_bytes(8));
if (!mkdir($storageFixture, 0700)) { throw new RuntimeException('Cannot create isolated storage fixture.'); }
define('__TYPECHO_ROOT_DIR__', $storageFixture);
$storageChecks = 0;
function storageCheck(bool $condition, string $message): void
{
    global $storageChecks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $storageChecks++;
}
class Enhancement_Plugin
{
    public static $configured = false;
    public static $settings = array('s3_save_local' => '0', 's3_compress_images' => '0');
    public static function s3UploadEnabled(): bool { return true; }
    public static function s3UploadConfigured(): bool { return self::$configured; }
    public static function runtimeSettings() { return (object)self::$settings; }
}
class Typecho_Date { public $year = '2026'; public $month = '10'; }
class Typecho_Widget
{
    public static function widget($name) { return (object)array('siteUrl' => 'https://site.example', 'allowedAttachmentTypes' => array('png', 'jpg', 'gif', 'webp')); }
}
class Typecho_Common
{
    public static function url($path, $base): string { return rtrim($base, '/\\') . '/' . ltrim($path, '/\\'); }
    public static function mimeContentType($path): string { return Enhancement_S3Upload_Utils::getMimeType($path); }
}
class Enhancement_S3Upload_Utils
{
    public static $logs = array();
    public static function log($message, $level) { self::$logs[] = array($message, $level); }
    public static function getMimeType($path): string
    {
        $info = getimagesize($path);
        return $info['mime'] ?? 'application/octet-stream';
    }
}
class Enhancement_S3Upload_S3Client
{
    public static $calls = array();
    public static $pathDelay = 0;
    public static function getInstance() { return new self(); }
    public function generatePath($file): string
    {
        if (self::$pathDelay > 0) { usleep(self::$pathDelay); }
        return 'images/' . $file['name'];
    }
    public function putObject($path, $file, $timeout = 120): array
    {
        storageCheck(is_file($file) && is_readable($file), 'The real stream uploader supplies a readable verified file to object storage');
        self::$calls[] = array('path' => $path, 'file' => $file, 'timeout' => $timeout);
        return array('url' => 'https://objects.example/' . $path);
    }
    public function getObjectUrl($path): string { return 'https://objects.example/' . $path; }
}
require_once dirname(__DIR__, 2) . '/S3Upload/StreamUploader.php';
require_once dirname(__DIR__, 2) . '/S3Upload/FileHandler.php';

$stream = tmpfile();
$compressedTemporaries = array();
try {
    $image = imagecreatetruecolor(2, 2);
    ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
    fwrite($stream, $png);
    $temporaryPath = stream_get_meta_data($stream)['uri'];
    $file = array('name' => 'fixture.png', 'type' => 'image/png', 'size' => strlen($png), 'bytes' => $png, 'tmp_name' => $temporaryPath);

    $local = Enhancement_S3Upload_FileHandler::uploadHandle($file);
    $localFile = is_array($local) ? __TYPECHO_ROOT_DIR__ . '/' . ltrim($local['path'], '/') : '';
    storageCheck(is_array($local) && $local['mime'] === 'image/png' && $local['size'] === strlen($png)
        && is_file($localFile) && file_get_contents($localFile) === $png,
        'Incomplete S3 configuration falls back to local byte uploads rather than move_uploaded_file');
    storageCheck(is_file($temporaryPath) && file_get_contents($temporaryPath) === $png, 'Byte fallback preserves the temporary source for receiver cleanup');
    storageCheck(Enhancement_AttachmentResolverHelper::buildLocalAttachmentUrlByPath($local['path']) === 'https://site.example/' . ltrim($local['path'], '/'),
        'The real attachment resolver produces the correct URL for the local fallback result');
    $httpUpload = $file;
    unset($httpUpload['bytes']);
    storageCheck(Enhancement_S3Upload_FileHandler::uploadHandle($httpUpload) === false,
        'Ordinary HTTP uploads still require a real uploaded file and do not treat arbitrary temporary paths as uploads');

    Enhancement_Plugin::$configured = true;
    $remote = Enhancement_S3Upload_FileHandler::uploadHandle($file);
    $last = end(Enhancement_S3Upload_S3Client::$calls);
    storageCheck(is_array($remote) && $last['timeout'] === 120 && $remote['url'] === 'https://objects.example/images/fixture.png',
        'Existing uploads retain the default 120-second object-store timeout');
    $limited = $file + array('timeout' => 7);
    Enhancement_S3Upload_FileHandler::uploadHandle($limited);
    $last = end(Enhancement_S3Upload_S3Client::$calls);
    storageCheck($last['timeout'] === 7, 'Explicit upload timeouts reach the actual object-store client unchanged');

    Enhancement_S3Upload_S3Client::$pathDelay = 1100000;
    $budgeted = $file + array('timeout' => 20, 'deadline' => microtime(true) + 5);
    $remote = Enhancement_S3Upload_FileHandler::uploadHandle($budgeted);
    $last = end(Enhancement_S3Upload_S3Client::$calls);
    storageCheck(is_array($remote) && $last['timeout'] >= 1 && $last['timeout'] <= 3,
        'Time spent before putObject is deducted from the shared absolute batch deadline');
    Enhancement_S3Upload_S3Client::$pathDelay = 0;
    $before = count(Enhancement_S3Upload_S3Client::$calls);
    $expired = $file + array('timeout' => 20, 'deadline' => microtime(true) + 0.5);
    storageCheck(Enhancement_S3Upload_FileHandler::uploadHandle($expired) === false && count(Enhancement_S3Upload_S3Client::$calls) === $before,
        'Less than one remaining second rejects the upload before any object request');

    Enhancement_Plugin::$settings['s3_compress_images'] = '1';
    $largePng = $png . str_repeat("\0", 110000);
    rewind($stream); ftruncate($stream, 0); fwrite($stream, $largePng);
    clearstatcache(true, $temporaryPath);
    $compressedFile = array_replace($file, array('size' => strlen($largePng), 'bytes' => $largePng,
        'timeout' => 20, 'deadline' => microtime(true) + 5));
    $compressed = Enhancement_S3Upload_FileHandler::uploadHandle($compressedFile);
    $last = end(Enhancement_S3Upload_S3Client::$calls);
    $compressedTemporaries[] = $last['file'];
    storageCheck(is_array($compressed) && $compressed['mime'] === 'image/webp' && $compressed['type'] === 'webp'
        && $last['timeout'] >= 1 && $last['timeout'] <= 4,
        'The existing image compression setting remains active and its result retains the shared upload budget');
    storageCheck(Enhancement_AttachmentResolverHelper::extractPathFromContent($compressed) === $compressed['path']
        && !array_key_exists('bytes', $compressed) && !array_key_exists('tmp_name', $compressed) && !array_key_exists('deadline', $compressed),
        'Stored attachment metadata exposes a stable object path and omits temporary bytes and receiver deadlines');
    echo 'Bot moment S3 storage checks passed: ' . $storageChecks . PHP_EOL;
} finally {
    if (is_resource($stream)) { fclose($stream); }
    foreach ($compressedTemporaries as $path) {
        if (strpos(basename($path), 'enh_s3_') === 0 && substr($path, -5) === '.webp'
            && realpath(dirname($path)) === realpath(sys_get_temp_dir())) {
            @unlink($path);
            @unlink(substr($path, 0, -5));
        }
    }
    $resolvedFixture = realpath($storageFixture);
    if ($resolvedFixture !== false && realpath(dirname($resolvedFixture)) === realpath(sys_get_temp_dir())
        && strpos(basename($resolvedFixture), 'enh-bot-s3-') === 0) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedFixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isDir()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($resolvedFixture);
    }
}
