<?php
/** Offline attachment validation, decryption and storage-boundary checks. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/BotMomentsMediaHelper.php';

$mediaChecks = 0;
function mediaCheck(bool $condition, string $message): void
{
    global $mediaChecks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $mediaChecks++;
}
function mediaFails(callable $operation, string $message): void
{
    $error = null;
    try { $operation(); } catch (Throwable $exception) { $error = $exception; }
    mediaCheck($error instanceof RuntimeException && $error->getMessage() === '图片接收或保存失败，请检查图片大小、格式和上传设置后重试。'
        && $error->getPrevious() === null, $message);
}
function mediaFixture(string $format = 'png'): string
{
    $image = imagecreatetruecolor(2, 2);
    imagesetpixel($image, 0, 0, imagecolorallocate($image, 130, 40, 70));
    ob_start();
    call_user_func('image' . $format, $image);
    $bytes = ob_get_clean();
    imagedestroy($image);
    return $bytes;
}

$wxUrl = 'https://novac2c.cdn.weixin.qq.com/c2c/download?encrypted_query_param=fixture-private-query';
$wx = array(array('media' => array('full_url' => $wxUrl)));
mediaCheck(Enhancement_BotMomentsMediaHelper::normalize('weixin', $wx) === $wx, 'Plain Weixin descriptors are idempotent');
mediaCheck(Enhancement_BotMomentsMediaHelper::normalize('weixin', array(array('media' => array('full_url' => $wxUrl),
    'filename' => 'untrusted.php', 'unknown' => array('nested' => str_repeat('x', 10000))))) === $wx,
    'Normalization keeps bounded download descriptors rather than arbitrary message metadata');
foreach (array('http://novac2c.cdn.weixin.qq.com/image', 'https://127.0.0.1/image', 'https://2130706433/image', 'https://[::1]/image',
    'https://localhost/image', 'https://novac2c.cdn.weixin.qq.com.evil.test/image', 'https://evil.test/image',
    'https://novac2c.cdn.weixin.qq.com:443/image', 'https://user:secret@novac2c.cdn.weixin.qq.com/image',
    'https://novac2c.cdn.weixin.qq.com/image#fragment', 'https://novac2c.cdn.weixin.qq.com./image', "https://novac2c.cdn.weixin.qq.com/image\n",
    'https://novac2c.cdn.weixin.qq.com\\@evil.test/image', 'https://novac2c.cdn.weixin.qq.com/' . str_repeat('x', 2048),
    array('https://novac2c.cdn.weixin.qq.com/image')) as $url) {
    mediaFails(static function () use ($url) { Enhancement_BotMomentsMediaHelper::normalize('weixin', array(array('media' => array('full_url' => $url)))); },
        'Unsafe or malformed image URLs fail with a fixed error');
}
foreach (array(array('media' => array('full_url' => $wxUrl)), array(1 => $wx[0]), array('nested' => $wx[0]), array_fill(0, 10, $wx[0])) as $invalid) {
    mediaFails(static function () use ($invalid) { Enhancement_BotMomentsMediaHelper::normalize('weixin', $invalid); }, 'Attachments must be a list containing at most nine images');
}
mediaCheck(count(Enhancement_BotMomentsMediaHelper::normalize('weixin', array_fill(0, 9, $wx[0]))) === 9, 'Exactly nine attachments are accepted');
mediaFails(static function () use ($wx) { Enhancement_BotMomentsMediaHelper::normalize('other', $wx); }, 'Unknown channels cannot receive attachments');

$qqUrl = 'https://gchat.qpic.cn/fixture/image?token=private-query';
$qq = array(array('url' => $qqUrl));
mediaCheck(Enhancement_BotMomentsMediaHelper::normalize('qq', array(array('url' => '//gchat.qpic.cn/fixture/image?token=private-query',
    'content_type' => 'image/jpeg', 'filename' => 'untrusted.php'))) === $qq,
    'Official QQ protocol-relative image URLs become bounded HTTPS descriptors');
mediaCheck(Enhancement_BotMomentsMediaHelper::normalize('qq', $qq) === $qq, 'QQ image descriptors remain idempotent after canonicalization');
foreach (array('multimedia.nt.qq.com', 'multimedia.nt.qq.com.cn', 'qpic.cn', 'c2cpicdw.qpic.cn', 'gchat.qpic.cn') as $host) {
    mediaCheck(Enhancement_BotMomentsMediaHelper::normalize('qq', array(array('url' => '//' . $host . '/image')))[0]['url'] === 'https://' . $host . '/image',
        'Official QQ image CDN hosts accept protocol-relative URLs: ' . $host);
}
foreach (array('http://gchat.qpic.cn/image', '//gchat.qpic.cn.evil.test/image', '//evil.test/image', '//127.0.0.1/image',
    '///gchat.qpic.cn/image', '//user:secret@gchat.qpic.cn/image', '//gchat.qpic.cn:443/image', '//gchat.qpic.cn/image#fragment',
    "//gchat.qpic.cn/image\n", '//gchat.qpic.cn\\@evil.test/image') as $url) {
    mediaFails(static function () use ($url) { Enhancement_BotMomentsMediaHelper::normalize('qq', array(array('url' => $url))); },
        'QQ URL canonicalization retains host, protocol and credential restrictions');
}
mediaFails(static function () use ($qqUrl) { Enhancement_BotMomentsMediaHelper::normalize('weixin', array(array('media' => array('full_url' => $qqUrl)))); },
    'Weixin image receiving cannot use QQ CDN hosts');
foreach (array('image/svg+xml', 'application/pdf', array('image/jpeg')) as $mime) {
    mediaFails(static function () use ($qqUrl, $mime) { Enhancement_BotMomentsMediaHelper::normalize('qq', array(array('url' => $qqUrl, 'content_type' => $mime))); },
        'QQ declared image types must use supported raster MIME types');
}
mediaFails(static function () use ($qq) { Enhancement_BotMomentsMediaHelper::normalize('qq', array_fill(0, 10, $qq[0])); },
    'QQ images retain the nine-image attachment limit');

$keyHex = '00112233445566778899aabbccddeeff';
foreach (array(base64_encode(hex2bin($keyHex)), base64_encode(strtoupper($keyHex))) as $encodedKey) {
    $normalized = Enhancement_BotMomentsMediaHelper::normalize('weixin', array(array('media' => array('encrypt_query_param' => 'query+/=', 'aes_key' => $encodedKey, 'encrypt_type' => 1))));
    mediaCheck($normalized === array(array('media' => array('full_url' => 'https://novac2c.cdn.weixin.qq.com/c2c/download?encrypted_query_param=query%2B%2F%3D'), 'aeskey' => $keyHex)),
        'Weixin accepts both official Base64 AES key encodings and escapes download parameters');
    mediaCheck(Enhancement_BotMomentsMediaHelper::normalize('weixin', $normalized) === $normalized, 'Encrypted Weixin descriptors remain idempotent');
}
$preferred = Enhancement_BotMomentsMediaHelper::normalize('weixin', array(array('aeskey' => strtoupper($keyHex),
    'media' => array('full_url' => $wxUrl, 'encrypt_query_param' => 'ignored-query', 'aes_key' => base64_encode(str_repeat('a', 16))))));
mediaCheck($preferred === array(array('media' => array('full_url' => $wxUrl), 'aeskey' => $keyHex)), 'Weixin prefers the complete URL and image-level hexadecimal AES key');
mediaCheck(Enhancement_BotMomentsMediaHelper::normalize('weixin', array(array('aeskey' => $keyHex,
    'media' => array('full_url' => $wxUrl, 'aes_key' => 'unused-invalid-key')))) === $preferred,
    'A valid preferred image-level key does not depend on the unused lower-priority key encoding');
foreach (array('invalid-private-key', str_repeat('a', 31), str_repeat('z', 32), array('key')) as $badKey) {
    mediaFails(static function () use ($wxUrl, $badKey) { Enhancement_BotMomentsMediaHelper::normalize('weixin', array(array('aeskey' => $badKey, 'media' => array('full_url' => $wxUrl)))); }, 'Invalid image-level AES keys are rejected');
}
foreach (array('!!invalid-base64!!', base64_encode(str_repeat('a', 15)), base64_encode(str_repeat('z', 32)), array('key')) as $badKey) {
    mediaFails(static function () use ($wxUrl, $badKey) { Enhancement_BotMomentsMediaHelper::normalize('weixin', array(array('media' => array('full_url' => $wxUrl, 'aes_key' => $badKey)))); }, 'Invalid media-level AES keys are rejected');
}
mediaFails(static function () use ($wxUrl) { Enhancement_BotMomentsMediaHelper::normalize('weixin', array(array('media' => array('full_url' => $wxUrl, 'encrypt_type' => 2)))); }, 'Unknown Weixin encryption modes are rejected');

$downloaded = 0;
$stored = array();
$png = mediaFixture();
$download = static function ($url, $limit, $timeout) use (&$downloaded, $png, $wxUrl): string {
    $downloaded++;
    mediaCheck($url === $wxUrl && $limit === 10485760 && $timeout >= 1 && $timeout <= 8, 'Injected downloads receive only a URL and bounded size/time settings');
    return $png;
};
$store = static function ($file) use (&$stored, &$downloaded, $png): string {
    mediaCheck($downloaded === 2, 'Every image is downloaded and validated before the first permanent store');
    mediaCheck($file['type'] === 'image/png' && $file['size'] === strlen($png) && $file['bytes'] === $png
        && preg_match('/\Abot-moment-[0-9a-f]{24}\.png\z/D', $file['name']) && is_file($file['tmp_name'])
        && $file['timeout'] >= 1 && $file['timeout'] <= 20, 'Storage receives verified bytes, generated filename and a bounded S3 timeout');
    $stored[] = $file['name'];
    return 'https://site.example/uploads/' . $file['name'];
};
$media = Enhancement_BotMomentsMediaHelper::receive('weixin', array_merge($wx, $wx), $download, $store);
mediaCheck(count($media) === 2 && $media[0]['type'] === 'PHOTO' && strpos($media[0]['url'], 'https://site.example/uploads/') === 0, 'Received images become stable PHOTO media URLs');
foreach (array('jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp') as $format => $mime) {
    $bytes = mediaFixture($format);
    $result = Enhancement_BotMomentsMediaHelper::receive('weixin', $wx, static function () use ($bytes): string { return $bytes; }, static function ($file) use ($mime): string {
        mediaCheck($file['type'] === $mime, 'Actual decoded image type controls the stored MIME type');
        return 'https://site.example/image';
    });
    mediaCheck(count($result) === 1, 'A valid supported image format is accepted: ' . $format);
}

$cipher = openssl_encrypt($png, 'aes-128-ecb', hex2bin($keyHex), OPENSSL_RAW_DATA);
$decrypted = Enhancement_BotMomentsMediaHelper::receive('weixin', $preferred, static function ($url, $limit, $timeout) use ($cipher, $wxUrl): string {
    mediaCheck($url === $wxUrl && $limit === 10485776, 'Encrypted image downloads reserve at most one AES padding block');
    return $cipher;
}, static function ($file) use ($png): string {
    mediaCheck($file['bytes'] === $png, 'The saved Weixin image contains plaintext rather than encrypted CDN bytes');
    return 'https://site.example/weixin.png';
});
mediaCheck($decrypted === array(array('type' => 'PHOTO', 'url' => 'https://site.example/weixin.png')), 'Weixin AES-128-ECB images are decrypted and persisted');
$plainWeixin = Enhancement_BotMomentsMediaHelper::receive('weixin', $wx, static function () use ($png): string { return $png; },
    static function (): string { return 'https://site.example/weixin-plain.png'; });
mediaCheck($plainWeixin[0]['url'] === 'https://site.example/weixin-plain.png', 'Weixin images without an encryption key are validated as plaintext images');
$qqReceived = Enhancement_BotMomentsMediaHelper::receive('qq', array(array('url' => '//gchat.qpic.cn/fixture/image?token=private-query')),
    static function ($url, $limit) use ($png, $qqUrl): string {
        mediaCheck($url === $qqUrl && $limit === 10485760, 'QQ downloads receive the canonical HTTPS URL and raster byte limit');
        return $png;
    }, static function ($file) use ($png): string {
        mediaCheck($file['bytes'] === $png && $file['type'] === 'image/png', 'QQ image bytes are verified without Weixin decryption');
        return 'https://site.example/qq.png';
    });
mediaCheck($qqReceived === array(array('type' => 'PHOTO', 'url' => 'https://site.example/qq.png')), 'QQ service images become stable site media URLs');
$decryptMethod = new ReflectionMethod('Enhancement_BotMomentsMediaHelper', 'decrypt');
$decryptMethod->setAccessible(true);
$nistCipher = hex2bin('3ad77bb40d7a3660a89ecaf32466ef97a254be88e037ddd9d79fb6411c3f9df8');
mediaCheck($decryptMethod->invoke(null, $nistCipher, array('aeskey' => '2b7e151628aed2a6abf7158809cf4f3c')) === hex2bin('6bc1bee22e409f96e93d7e117393172a'),
    'AES decoding matches a fixed NIST plaintext/ciphertext block with PKCS7 padding');
mediaFails(static function () use ($preferred) { Enhancement_BotMomentsMediaHelper::receive('weixin', $preferred,
    static function (): string { return str_repeat('x', 16); }, static function (): string { return 'https://site.example/never'; }); }, 'Invalid AES padding cannot reach image storage');

foreach (array('<html>redirect to https://evil.test</html>', '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
    "\x89PNG\r\n\x1a\n" . str_repeat("\0", 30), str_repeat('x', 10485761)) as $badBytes) {
    $badStores = 0;
    mediaFails(static function () use ($wx, $badBytes, &$badStores) { Enhancement_BotMomentsMediaHelper::receive('weixin', $wx,
        static function () use ($badBytes): string { return $badBytes; }, static function () use (&$badStores): string { $badStores++; return 'https://site.example/never'; }); },
        'HTML, SVG, corrupt image headers and oversized bytes are rejected');
    mediaCheck($badStores === 0, 'Rejected bytes never reach permanent storage');
}
$hugePng = substr_replace($png, pack('NN', 10000, 10000), 16, 8);
mediaFails(static function () use ($wx, $hugePng) { Enhancement_BotMomentsMediaHelper::receive('weixin', $wx,
    static function () use ($hugePng): string { return $hugePng; }, static function (): string { return 'https://site.example/never'; }); }, 'Oversized image dimensions are rejected before raster decoding');
$sequence = array($png, '<html>not an image</html>');
$badStores = 0;
mediaFails(static function () use ($wx, &$sequence, &$badStores) { Enhancement_BotMomentsMediaHelper::receive('weixin', array_merge($wx, $wx),
    static function () use (&$sequence): string { return array_shift($sequence); }, static function () use (&$badStores): string { $badStores++; return 'https://site.example/never'; }); }, 'A later invalid image rejects the complete image batch');
mediaCheck($badStores === 0, 'A partially downloaded batch never writes the earlier valid image');
mediaFails(static function () use ($wx) { Enhancement_BotMomentsMediaHelper::receive('weixin', $wx,
    static function (): string { throw new RuntimeException('https://novac2c.cdn.weixin.qq.com/?private-download-token AES-private-key'); }); }, 'Downloader exceptions never expose signed URLs or keys');
mediaFails(static function () use ($wx, $png) { Enhancement_BotMomentsMediaHelper::receive('weixin', $wx,
    static function () use ($png): string { return $png; }, static function (): string { throw new RuntimeException('S3 credential-private-secret'); }); }, 'Storage exceptions never expose credentials');
mediaFails(static function () use ($wx, $png) { Enhancement_BotMomentsMediaHelper::receive('weixin', $wx,
    static function () use ($png): string { return $png; }, static function (): string { return 'javascript:alert(1)'; }); }, 'Storage must return an HTTP(S) media URL');

$ipMethod = new ReflectionMethod('Enhancement_BotMomentsMediaHelper', 'publicIp');
$ipMethod->setAccessible(true);
foreach (array('127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0',
    '192.0.2.1', '192.88.99.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '240.0.0.1', '255.255.255.255', '::1', '::ffff:127.0.0.1') as $ip) {
    mediaCheck(!$ipMethod->invoke(null, $ip), 'Private, special and unsupported IP resolutions are rejected: ' . $ip);
}
mediaCheck($ipMethod->invoke(null, '8.8.8.8') && $ipMethod->invoke(null, '1.1.1.1'), 'Public IPv4 resolutions pass the download policy');
$source = file_get_contents(dirname(__DIR__, 2) . '/BotMomentsMediaHelper.php');
mediaCheck(strpos($source, 'CURLOPT_FOLLOWLOCATION => false') !== false && strpos($source, 'CURLOPT_MAXREDIRS => 0') !== false
    && strpos($source, 'CURLOPT_RESOLVE =>') !== false && strpos($source, "CURLOPT_PROXY => ''") !== false,
    'The binary transport pins verified DNS results and disables redirects and proxy overrides');
mediaCheck(strpos($source, 'CURLOPT_SSL_VERIFYPEER => true') !== false && strpos($source, 'CURLOPT_SSL_VERIFYHOST => 2') !== false
    && strpos($source, 'Authorization:') === false && strpos($source, 'Bearer ') === false, 'Binary downloads retain TLS verification and never send bot credentials');

class Widget_Upload
{
    public static $seen;
    public static $result = array('url' => 'https://site.example/native-upload.png');
    public static function uploadHandle($file)
    {
        self::$seen = $file;
        return self::$result;
    }
}
class Enhancement_S3Helper
{
    public static $enabled = false;
    public static $seen;
    public static $result = array('url' => 'https://objects.example/s3-upload.png');
    public static function enabled(): bool { return self::$enabled; }
    public static function uploadHandle($file)
    {
        self::$seen = $file;
        mediaCheck(is_file($file['tmp_name']) && file_get_contents($file['tmp_name']) === $file['bytes'], 'Default S3 upload receives an existing verified temporary file');
        return self::$result;
    }
    public static function resolveAttachmentUrl($file): string { return 'https://objects.example/' . $file['path']; }
}
class Enhancement_AttachmentResolverHelper
{
    public static function buildLocalAttachmentUrlByPath($path): string { return 'https://site.example/' . ltrim($path, '/'); }
}
$local = Enhancement_BotMomentsMediaHelper::receive('weixin', $wx, static function () use ($png): string { return $png; });
mediaCheck($local[0]['url'] === 'https://site.example/native-upload.png' && !isset(Widget_Upload::$seen['tmp_name'])
    && Widget_Upload::$seen['bytes'] === $png, 'Native uploads use bytes without pretending temporary files are HTTP uploads');
Widget_Upload::$result = array('path' => '/usr/uploads/fixture.png');
$localPath = Enhancement_BotMomentsMediaHelper::receive('weixin', $wx, static function () use ($png): string { return $png; });
mediaCheck($localPath[0]['url'] === 'https://site.example/usr/uploads/fixture.png', 'Native upload paths are resolved through the existing upload URL helper');
Widget_Upload::$result = false;
mediaFails(static function () use ($wx, $png) { Enhancement_BotMomentsMediaHelper::receive('weixin', $wx,
    static function () use ($png): string { return $png; }); }, 'A rejected default upload fails with a safe message');
Enhancement_S3Helper::$enabled = true;
$remote = Enhancement_BotMomentsMediaHelper::receive('weixin', $wx, static function () use ($png): string { return $png; });
clearstatcache(true, Enhancement_S3Helper::$seen['tmp_name']);
mediaCheck($remote[0]['url'] === 'https://objects.example/s3-upload.png' && Enhancement_S3Helper::$seen['timeout'] >= 1
    && Enhancement_S3Helper::$seen['timeout'] <= 20 && !is_file(Enhancement_S3Helper::$seen['tmp_name']), 'S3 uses the remaining batch timeout and temporary buffers are removed after storage');
Enhancement_S3Helper::$result = array('path' => 'images/fixture.png');
$remotePath = Enhancement_BotMomentsMediaHelper::receive('weixin', $wx, static function () use ($png): string { return $png; });
mediaCheck($remotePath[0]['url'] === 'https://objects.example/images/fixture.png', 'S3 results without an inline URL use the existing attachment URL resolver');

echo 'Bot moment media checks passed: ' . $mediaChecks . PHP_EOL;
