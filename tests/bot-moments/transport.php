<?php
/** Exercise real cURL cleanup under the CLI worker's strict error policy. No external network. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/NotifyTransportHelper.php';
require_once dirname(__DIR__, 2) . '/BotMomentsSchedulerHelper.php';
require_once dirname(__DIR__, 2) . '/BotMomentsMediaHelper.php';
if (!function_exists('curl_init')) { throw new RuntimeException('The cURL extension is required for transport regression checks.'); }

$transportChecks = 0;
function transportCheck(bool $condition, string $message): void
{
    global $transportChecks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $transportChecks++;
}

$transportSocket = stream_socket_server('tcp://127.0.0.1:0', $transportErrno, $transportError);
if (!$transportSocket) { throw new RuntimeException('Could not reserve the local transport fixture address.'); }
$transportAddress = stream_socket_get_name($transportSocket, false);
fclose($transportSocket);
$transportReporting = error_reporting(E_ALL);
set_error_handler(static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new RuntimeException('worker_runtime_error');
});
try {
    $transportFailure = null;
    try { Enhancement_NotifyTransportHelper::request('GET', 'https://' . $transportAddress . '/', array(), null, 1); }
    catch (RuntimeException $exception) { $transportFailure = $exception; }
    transportCheck($transportFailure instanceof RuntimeException
        && $transportFailure->getMessage() === '通知接口连接失败或超时，请检查服务器网络和 HTTPS 证书。',
        'Real notification transport reports its normal network error instead of a worker deprecation failure');

    $transportDispatch = new ReflectionMethod('Enhancement_BotMomentsSchedulerHelper', 'dispatch');
    if (PHP_VERSION_ID < 80100) { $transportDispatch->setAccessible(true); }
    transportCheck($transportDispatch->invoke(null, 'http://' . $transportAddress . '/', str_repeat('a', 64)) === false,
        'Real background dispatch releases its cURL handle under the worker error policy');

    $transportImage = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($transportImage);
    $transportPng = ob_get_clean();
    if (PHP_VERSION_ID < 80000) { imagedestroy($transportImage); }
    unset($transportImage);
    $transportMedia = Enhancement_BotMomentsMediaHelper::receive('qq', array(array('url' => 'https://gchat.qpic.cn/fixture.png')),
        static function () use ($transportPng): string { return $transportPng; },
        static function (): string { return 'https://site.example/fixture.png'; });
    transportCheck($transportMedia === array(array('type' => 'PHOTO', 'url' => 'https://site.example/fixture.png')),
        'Raster validation releases its image object under the worker error policy');
} finally {
    restore_error_handler();
    error_reporting($transportReporting);
}
echo 'Real transport checks passed: ' . $transportChecks . PHP_EOL;
