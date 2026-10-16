<?php
/** Local fake service: requests are captured, never forwarded to QQ. */
declare(strict_types=1);
$directory = getenv('ENHANCEMENT_QQ_TEST_DIR');
if (PHP_SAPI !== 'cli-server' || !$directory || !is_file($directory . '/fixture.sqlite')) {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
file_put_contents($directory . '/bot-requests.jsonl', json_encode([
    'path' => $path,
    'method' => $_SERVER['REQUEST_METHOD'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'payload' => json_decode($raw, true), 'raw' => $raw,
]) . "\n", FILE_APPEND | LOCK_EX);
$config = json_decode(file_get_contents($directory . '/bot-response.json'), true);
http_response_code((int)$config['status']);
header('Content-Type: application/json');
if (isset($config['location'])) {
    header('Location: ' . $config['location']);
}
echo $config['body'];
