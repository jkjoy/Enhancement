<?php
/** Official service -> signed HTTP callback -> isolated Typecho/SQLite publishing. */
declare(strict_types=1);
define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

function qmEvent(string $text = '瞬间 QQ发布瞬间测试!~', array $changes = []): array
{
    return array_replace(['id' => bin2hex(random_bytes(12)), 'account' => '123456789',
        'sender' => 'private-owner', 'conversation' => 'private-owner', 'conversation_type' => 'private',
        'timestamp' => time(), 'text' => $text, 'aliases' => ['owner'], 'attachments' => []], $changes);
}
function qmSend($payload, ?string $secret = null, ?string $timestamp = null, array $headers = []): array
{
    global $base, $commandSecret;
    $raw = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = $timestamp ?? (string)time();
    return fixtureRequest($base, '/action/enhancement-edit?do=qq-moments-webhook', 'POST', $raw,
        array_merge(['Content-Type: application/json', 'X-Enhancement-Timestamp: ' . $timestamp,
            'X-Enhancement-Signature: ' . hash_hmac('sha256', $timestamp . "\n" . $raw, $secret ?? $commandSecret)], $headers));
}
function qmRows(): array
{
    global $pdo;
    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='typecho_moments'")->fetchColumn()) { return []; }
    return $pdo->query('SELECT * FROM typecho_moments ORDER BY mid')->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $env = getenv();
    $env['ENHANCEMENT_QQ_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_QQ_TEST_ROOT'] = $root;
    qqStartServer($address, dirname(__DIR__) . '/qq-notify/router.php', $env, $directory . '/typecho.log');
    $commandSecret = 'fixture-commands-' . str_repeat('c', 32);
    $settings = ['qq_notify_provider' => 'official', 'qq_official_url' => 'http://127.0.0.1:8080',
        'qq_official_token' => 'fixture-notifications-' . str_repeat('n', 32), 'qq_official_targets' => 'owner',
        'qq_moments_enabled' => '1', 'qq_moments_secret' => $commandSecret, 'qq_moments_targets' => 'owner'];
    $response = qqConfig($settings);
    qqCheck($response['status'] === 200, 'Official QQ service moment publishing can be enabled', $response);

    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-moments-webhook');
    qqCheck($response['status'] === 405 && ($response['headers']['allow'] ?? '') === 'POST', 'Callback requires POST');
    $event = qmEvent();
    $payload = ['version' => 1, 'message' => $event];
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-moments-webhook', 'POST', $payload);
    qqCheck($response['status'] === 401 && qmRows() === [], 'Unsigned callback cannot publish');
    foreach (['wrong-secret', $settings['qq_official_token']] as $wrongSecret) {
        $response = qmSend($payload, $wrongSecret);
        qqCheck($response['status'] === 401 && qmRows() === [], 'Other credentials cannot authenticate the publishing callback');
    }
    foreach ([(string)(time() - 301), (string)(time() + 301), 'invalid-time'] as $timestamp) {
        $response = qmSend($payload, null, $timestamp);
        qqCheck($response['status'] === 401 && qmRows() === [], 'Expired, future or malformed signature timestamp is rejected');
    }
    $raw = json_encode($payload);
    $signedTime = (string)time();
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-moments-webhook', 'POST', $raw . ' ',
        ['Content-Type: application/json', 'X-Enhancement-Timestamp: ' . $signedTime,
            'X-Enhancement-Signature: ' . hash_hmac('sha256', $signedTime . "\n" . $raw, $commandSecret)]);
    qqCheck($response['status'] === 401 && qmRows() === [], 'Signature covers exact request bytes');

    foreach (['{', 'null', '[]', '{"version":2,"message":{}}'] as $invalid) {
        $response = qmSend($invalid);
        qqCheck($response['status'] === 400 && qmRows() === [], 'Signed malformed payload cannot publish', $response);
    }
    foreach ([['id' => ''], ['account' => '../123'], ['sender' => []], ['conversation' => 'other'],
        ['conversation_type' => 'group'], ['timestamp' => (string)time()], ['text' => []],
        ['aliases' => ['bad alias']], ['aliases' => ['name' => 'owner']], ['attachments' => 'bad']] as $invalid) {
        $response = qmSend(['version' => 1, 'message' => qmEvent('瞬间 无效字段', $invalid)]);
        qqCheck($response['status'] === 400 && qmRows() === [], 'Invalid normalized QQ message is rejected', $response);
    }
    $response = qmSend(str_repeat('x', 65537));
    qqCheck($response['status'] === 413 && qmRows() === [], 'Oversized callback cannot publish');

    foreach ([['aliases' => ['another-owner']], ['aliases' => []], ['timestamp' => time() - 90000]] as $ignored) {
        $response = qmSend(['version' => 1, 'message' => qmEvent('瞬间 不应发布', $ignored)]);
        qqCheck($response['status'] === 200 && ($response['json']['ok'] ?? false) === true
            && ($response['json']['result'] ?? null) === null && ($response['json']['reply'] ?? null) === '' && qmRows() === [],
            'Unauthorized alias or historical message is acknowledged without publishing', $response);
    }
    $response = qmSend($payload);
    $rows = qmRows();
    qqCheck($response['status'] === 200 && ($response['json']['result']['success'] ?? false)
        && count($rows) === 1 && $rows[0]['source'] === 'qq' && $rows[0]['content'] === 'QQ发布瞬间测试!~'
        && $rows[0]['status'] === 'public' && strpos($response['json']['reply'] ?? '', '已发布瞬间 #') === 0,
        'Original user command publishes and returns the QQ reply without a browser session', $response);
    qqCheck(($response['headers']['cache-control'] ?? '') === 'no-store', 'Callback responses cannot be cached');
    $response = qmSend($payload);
    qqCheck(count(qmRows()) === 1 && !empty($response['json']['result']['replayed']), 'Service retry reuses durable publishing receipt');
    $response = qmSend(['version' => 1, 'message' => array_replace($event, ['text' => '瞬间 重试篡改'])]);
    qqCheck(count(qmRows()) === 1 && qmRows()[0]['content'] === 'QQ发布瞬间测试!~', 'Reusing QQ message ID cannot overwrite or duplicate content');

    $response = qmSend(['version' => 1, 'message' => qmEvent("私密瞬间 开心[微笑] 😄\n第二行")]);
    $rows = qmRows();
    qqCheck($response['status'] === 200 && count($rows) === 2 && $rows[1]['status'] === 'private'
        && $rows[1]['content'] === "开心[微笑] 😄\n第二行" && strpos($response['json']['reply'] ?? '', '已发布私密瞬间 #') === 0,
        'QQ expression text, Unicode emoji and multiline private content publish normally', $response);
    $response = qmSend(['version' => 1, 'message' => qmEvent('你好')]);
    qqCheck(($response['json']['reply'] ?? null) === '' && count(qmRows()) === 2, 'Unrelated chat produces no publishing reply');
    $response = qmSend(['version' => 1, 'message' => qmEvent('瞬间 <script>alert(1)</script>')]);
    qqCheck(($response['json']['ok'] ?? false) && empty($response['json']['result']['success'])
        && ($response['json']['reply'] ?? '') !== '' && count(qmRows()) === 2, 'Business validation failure returns a reply without publishing');

    qqConfig(['qq_moments_enabled' => '0']);
    $response = qmSend(['version' => 1, 'message' => qmEvent('瞬间 关闭后')]);
    qqCheck(($response['json']['result'] ?? null) === null && count(qmRows()) === 2, 'Disabling QQ moments immediately stops callback publishing');
    qqConfig(['qq_moments_enabled' => '1', 'qq_moments_secret' => 'rotated-commands-' . str_repeat('r', 32)]);
    $response = qmSend(['version' => 1, 'message' => qmEvent('瞬间 旧密钥')]);
    qqCheck($response['status'] === 401 && count(qmRows()) === 2, 'Rotated secret immediately invalidates old service credentials');
} catch (Throwable $exception) {
    $failures[] = 'Runner exception: ' . $exception->getMessage();
} finally {
    foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
    if ($failures) { echo 'Fixture retained at ' . $directory . "\n"; }
    else {
        $pdo = null;
        foreach (glob($directory . '/*') ?: [] as $file) { @unlink($file); }
        @rmdir($directory);
    }
}
echo $passed . " checks passed\n";
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
