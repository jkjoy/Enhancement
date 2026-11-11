<?php
/** Real cURL transport with a disposable loopback S3 endpoint; no external service. */
if (PHP_SAPI === 'cli-server') {
    $root = getenv('ENHANCEMENT_S3_TEST_ROOT');
    $path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    file_put_contents($root . '/request.json', json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path,
        'body' => base64_encode(file_get_contents('php://input')), 'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? '', 'sha256' => $_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] ?? '']));
    if (strpos($path, 'fail') !== false) { http_response_code(500); header('x-amz-bucket-region: fixture-region'); echo 'fixture S3 failure'; }
    elseif (strpos($path, 'missing') !== false) { http_response_code(404); }
    else { http_response_code($_SERVER['REQUEST_METHOD'] === 'DELETE' ? 204 : 200); }
    return;
}
if (PHP_SAPI !== 'cli' || !getenv('ENHANCEMENT_S3_TEST_ROOT')) { exit(2); }
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new RuntimeException($message . ' at ' . $file . ':' . $line); });
$root = getenv('ENHANCEMENT_S3_TEST_ROOT');
define('__TYPECHO_ROOT_DIR__', $root);
class Enhancement_Plugin {
    public static $settings;
    public static function runtimeSettings() { return (object)self::$settings; }
}
class Enhancement_S3Upload_Utils {
    public static $logs = [];
    public static function getMimeType($path) { return 'application/octet-stream'; }
    public static function log($message, $level) { self::$logs[] = $message; }
}
// The hash pass succeeds; the upload stream raises an exception during curl_exec().
class FailingS3ReadStream {
    public $context;
    private $stream;
    public static $source;
    public static $opens = 0;
    public static $closes = 0;
    public function url_stat($path, $flags) { return stat(self::$source); }
    public function stream_open($path, $mode, $options, &$openedPath) { $this->stream = fopen(self::$source, 'rb'); self::$opens++; return true; }
    public function stream_stat() { return fstat($this->stream); }
    public function stream_read($count) {
        if (self::$opens > 1) throw new RuntimeException('fixture stream read exception');
        return fread($this->stream, $count);
    }
    public function stream_eof() { return feof($this->stream); }
    public function stream_tell() { return ftell($this->stream); }
    public function stream_seek($offset, $whence = SEEK_SET) { return fseek($this->stream, $offset, $whence) === 0; }
    public function stream_close() { fclose($this->stream); self::$closes++; }
}
require dirname(__DIR__, 2) . '/S3Upload/S3Client.php';
$passed = 0;
function s3Check($condition, $label) { global $passed; if (!$condition) throw new RuntimeException('FAIL ' . $label); $passed++; }
function s3Streams() { return count(get_resources('stream')); }
function s3Failure($callback) {
    try { $callback(); return ''; }
    catch (Throwable $error) { return $error->getMessage(); }
}
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($socket, false); fclose($socket);
Enhancement_Plugin::$settings = ['s3_endpoint' => $address, 's3_bucket' => 'fixture-bucket', 's3_region' => 'us-east-1',
    's3_access_key' => 'fixture-access-key', 's3_secret_key' => 'fixture-secret-key', 's3_use_https' => '0'];
$process = proc_open([PHP_BINARY, '-d', 'error_reporting=-1', '-S', $address, __FILE__],
    [['pipe', 'r'], ['file', $root . '/server.log', 'a'], ['file', $root . '/server.log', 'a']], $pipes, __DIR__, getenv());
fclose($pipes[0]);
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        try {
            $connection = stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($connection) { fclose($connection); $ready = true; break; }
        } catch (RuntimeException $error) {}
        usleep(20000);
    }
    s3Check($ready, 'Loopback S3 server becomes ready');
    $source = $root . '/source.bin';
    $bytes = str_repeat('S3 fixture data', 1000);
    file_put_contents($source, $bytes);
    $client = Enhancement_S3Upload_S3Client::getInstance();
    $before = s3Streams();
    $result = $client->putObject('safe object.bin', $source, 2);
    $request = json_decode(file_get_contents($root . '/request.json'), true);
    s3Check($result['path'] === 'safe object.bin' && strpos($result['url'], 'http://' . $address . '/fixture-bucket/safe%20object.bin') === 0,
        'Real cURL PUT returns the encoded object URL');
    s3Check($request['method'] === 'PUT' && $request['path'] === '/fixture-bucket/safe%20object.bin'
        && base64_decode($request['body']) === $bytes && $request['sha256'] === hash('sha256', $bytes)
        && strpos($request['authorization'], 'AWS4-HMAC-SHA256 Credential=fixture-access-key/') === 0,
        'Real stream upload sends unchanged bytes and signed S3 request headers');
    s3Check(s3Streams() === $before && file_get_contents($source) === $bytes, 'Successful PUT closes its stream and preserves the source');
    s3Check($client->deleteObject('safe object.bin'), 'Real cURL DELETE accepts HTTP 204');
    s3Check($client->deleteObject('missing'), 'Deleting an absent object retains idempotent HTTP 404 success');
    $message = s3Failure(static function () use ($client, $source) { $client->putObject('fail', $source, 2); });
    s3Check(strpos($message, '500') !== false && strpos($message, 'fixture-region') !== false && strpos($message, 'fixture S3 failure') !== false,
        'Failed PUT retains status, region and body diagnostics');
    s3Check(s3Streams() === $before, 'Failed PUT closes its file stream');
    s3Check(!$client->deleteObject('fail') && strpos(end(Enhancement_S3Upload_Utils::$logs), '500') !== false,
        'Failed DELETE retains diagnostics without deprecated cURL calls');

    stream_wrapper_register('s3readfail', 'FailingS3ReadStream');
    FailingS3ReadStream::$source = $source;
    $message = s3Failure(static function () use ($client) { $client->putObject('callback-error', 's3readfail://source', 2); });
    s3Check(strpos($message, 'fixture stream read exception') !== false && FailingS3ReadStream::$opens === 2 && FailingS3ReadStream::$closes === 2,
        'An exception raised inside cURL stream reading closes both hash and upload streams');
    s3Check(s3Streams() === $before && file_get_contents($source) === $bytes, 'Exceptional cURL upload releases its stream and preserves the source');
    stream_wrapper_unregister('s3readfail');

    $endpoint = new ReflectionProperty($client, 'endpoint');
    if (PHP_VERSION_ID < 80100) $endpoint->setAccessible(true);
    $closedSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $closedAddress = stream_socket_get_name($closedSocket, false); fclose($closedSocket);
    $endpoint->setValue($client, $closedAddress);
    $message = s3Failure(static function () use ($client, $source) { $client->putObject('connection-error', $source, 1); });
    s3Check(strpos($message, 'cURL') !== false && s3Streams() === $before, 'Connection failure releases the upload stream and keeps transport diagnostics');
    s3Check(!$client->deleteObject('connection-error'), 'DELETE connection failure returns false cleanly');
    echo "$passed strict S3 cURL transport checks passed.\n";
} finally {
    proc_terminate($process); proc_close($process);
}
