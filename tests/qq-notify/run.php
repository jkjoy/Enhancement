<?php
/** Isolated PHP/SQLite/HTTP regression tests. Usage: php tests/qq-notify/run.php [Typecho root]. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ENHANCEMENT_REST_API_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/rest-api/run.php';
$root = realpath($argv[1] ?? dirname(__DIR__, 5));
if (!$root || !is_file($root . '/var/Typecho/Common.php') || !is_file($root . '/install/SQLite.sql')) {
    fwrite(STDERR, "Supply a Typecho root containing var/Typecho/Common.php and install/SQLite.sql.\n");
    exit(2);
}
$directory = sys_get_temp_dir() . '/enhancement-qq-notify-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$processes = [];
$passed = 0;
$failures = [];
$pdo = null;

function qqCheck(bool $condition, string $label, $details = null): void
{
    global $passed, $failures;
    if ($condition) { $passed++; return; }
    $failures[] = $label . ($details === null ? '' : ': ' . substr(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1000));
    echo 'FAIL ' . end($failures) . "\n";
}
function qqAddress(): string
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) { throw new RuntimeException($error); }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    return $address;
}
function qqStartServer(string $address, string $router, array $env, string $log): void
{
    global $processes;
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=32767', '-S', $address, $router],
        [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, __DIR__, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Could not start fixture server.'); }
    $processes[] = $process;
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection) { fclose($connection); return; }
        usleep(100000);
    }
    throw new RuntimeException('Fixture server did not start.');
}
function qqRespond(int $status = 202, $body = null, ?string $location = null): void
{
    global $directory;
    if ($body === null) { $body = ['ok' => true, 'status' => 'queued', 'id' => 'fixture-notification']; }
    $value = ['status' => $status, 'body' => is_string($body) ? $body : json_encode($body)];
    if ($location !== null) { $value['location'] = $location; }
    file_put_contents($directory . '/bot-response.json', json_encode($value), LOCK_EX);
}
function qqRequests(): array
{
    global $directory;
    return array_map(static function ($line) { return json_decode($line, true); },
        is_file($directory . '/bot-requests.jsonl') ? file($directory . '/bot-requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []);
}
function qqLastRequest(): array { $requests = qqRequests(); return end($requests) ?: []; }
function qqCall(string $path, array $body = []): array
{
    global $base;
    return fixtureRequest($base, $path, 'POST', $body);
}
function qqConfig(array $changes): array { return qqCall('/config', $changes); }
function qqSettings(): array
{
    global $pdo;
    return unserialize($pdo->query("SELECT value FROM typecho_options WHERE name = 'plugin:Enhancement' AND user = 0")->fetchColumn(), ['allowed_classes' => false]);
}
function qqQueue(): array
{
    global $pdo;
    return $pdo->query('SELECT * FROM typecho_qq_notify_queue ORDER BY qid')->fetchAll(PDO::FETCH_ASSOC);
}
function qqEnvelope(array $row): array
{
    $prefix = '__ENHANCEMENT_QQ_OFFICIAL_V1__:';
    return strpos($row['message'], $prefix) === 0 ? json_decode(substr($row['message'], strlen($prefix)), true) : [];
}
function qqClearQueue(): void { global $pdo; $pdo->exec('DELETE FROM typecho_qq_notify_queue'); }

if (defined('ENHANCEMENT_QQ_FIXTURE_LIBRARY') && ENHANCEMENT_QQ_FIXTURE_LIBRARY === true) { return; }

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $botAddress = qqAddress();
    $botBase = 'http://' . $botAddress;
    $env = getenv();
    $env['ENHANCEMENT_QQ_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_QQ_TEST_ROOT'] = $root;
    qqRespond();
    qqStartServer($botAddress, __DIR__ . '/fake-bot.php', $env, $directory . '/bot.log');
    qqStartServer($address, __DIR__ . '/router.php', $env, $directory . '/typecho.log');

    // Exercise unsaved old installations, including those with no provider field.
    $oldSettings = ['qqboturl' => $botBase . '/legacy', 'qq' => '12345678', 'qq_async_queue' => '1',
        'enable_comment_by_qq' => '1', 'enable_link_submit_by_qq' => '1'];
    foreach ([[], ['qq_notify_provider' => 'legacy']] as $provider) {
        $pdo->prepare("UPDATE typecho_options SET value=? WHERE name='plugin:Enhancement' AND user=0")
            ->execute([serialize(array_merge($oldSettings, $provider))]);
        $response = qqCall('/test', ['message' => '已移除的通知方式']);
        qqCheck(($response['json']['success'] ?? true) === false && count(qqRequests()) === 0,
            'Missing or legacy provider never contacts old Bot API', $response);
        qqCall('/dispatch');
        qqCall('/comment');
        qqCall('/process');
        qqCheck(count(qqRequests()) === 0 && qqQueue() === [], 'Old business switches cannot send or enqueue raw messages');
        $form = fixtureRequest($base, '/form')['body'];
        qqCheck(preg_match('/<input[^>]*id="qq_notify_provider-none"[^>]*checked=/', $form) === 1,
            'Old installation form selects unconfigured provider');
    }
    $response = qqConfig(['enable_comment_by_qq' => '0', 'enable_link_submit_by_qq' => '0']);
    $migrated = qqSettings();
    qqCheck($response['status'] === 200 && ($migrated['qq_notify_provider'] ?? '') === 'none'
        && !isset($migrated['qq']) && !isset($migrated['qqboturl']) && !isset($migrated['qq_async_queue']),
        'Unrelated save migrates old provider and removes obsolete settings without requiring new credentials', $migrated);
    $response = qqCall('/restore', $oldSettings + ['qq_notify_provider' => 'legacy']);
    $migrated = qqSettings();
    qqCheck($response['status'] === 200 && ($migrated['qq_notify_provider'] ?? '') === 'none'
        && !isset($migrated['qq']) && !isset($migrated['qqboturl']) && !isset($migrated['qq_async_queue']),
        'Restoring old settings cannot revive obsolete transport', $response);
    qqConfig(['enable_comment_by_qq' => '0', 'enable_link_submit_by_qq' => '0']);
    $before = count(qqRequests());
    qqCall('/comment');
    qqCheck(count(qqRequests()) === $before, 'Disabled comment switch suppresses business notifications');

    $token = 'fixture-bot-token-' . str_repeat('x', 32);
    $official = ['qq_notify_provider' => 'official', 'qq_official_url' => $botBase . '/official', 'qq_official_token' => $token,
        'qq_official_targets' => "owner\nadmins", 'qq_official_include_links' => '1'];
    $response = qqConfig($official);
    qqCheck($response['status'] === 200, 'Official configuration saves', $response);
    qqRespond();
    $response = qqCall('/test', ['message' => '私聊与群聊主动消息']);
    $request = qqLastRequest();
    qqCheck(($response['json']['success'] ?? false) === true && ($response['json']['provider'] ?? '') === 'official', 'Test sender works with both business switches disabled', $response);
    qqCheck(($response['json']['id'] ?? '') === 'fixture-notification', 'Test sender returns the service receipt for delivery lookup', $response);
    qqCheck($request['path'] === '/official/api/notifications' && $request['method'] === 'POST'
        && $request['authorization'] === 'Bearer ' . $token && strpos($request['content_type'], 'application/json') === 0, 'Official POST sends JSON and Bearer credentials', $request);
    qqCheck(($request['payload']['targets'] ?? []) === ['owner', 'admins'] && ($request['payload']['message'] ?? '') === '私聊与群聊主动消息'
        && preg_match('/^[a-f0-9]{40}$/D', $request['payload']['idempotency_key'] ?? '') === 1, 'Private/group aliases and idempotency key reach service', $request);

    foreach ([
        [200, '', 'Empty HTTP 200'], [200, 'not-json', 'Malformed HTTP 200'],
        [200, ['ok' => false, 'status' => 'queued', 'id' => 'x'], 'HTTP 200 business failure'],
        [200, ['ok' => true, 'status' => 'queued'], 'HTTP 200 missing receipt'],
        [200, ['ok' => true, 'status' => 'failed', 'id' => 'x'], 'HTTP 200 failed receipt'],
        [503, ['ok' => true, 'status' => 'queued', 'id' => 'x'], 'HTTP 503'],
    ] as [$status, $body, $label]) {
        qqRespond($status, $body);
        $response = qqCall('/test');
        qqCheck(($response['json']['success'] ?? null) === false, $label . ' cannot be reported as accepted', $response);
    }
    qqRespond(302, '', $botBase . '/credential-trap');
    $before = count(qqRequests());
    $response = qqCall('/test');
    qqCheck(($response['json']['success'] ?? null) === false && count(qqRequests()) === $before + 1
        && qqLastRequest()['path'] !== '/credential-trap', 'Redirect is rejected without sending credentials to its destination', $response);

    qqConfig(['qq_official_include_links' => '0']);
    qqRespond();
    qqCall('/test', ['message' => "作者 www.author.example.test 友链 https://links.example.test 审核 https://review.example.test\n" . str_repeat('中文评论🙂', 400)]);
    $message = qqLastRequest()['payload']['message'];
    qqCheck(strlen($message) <= 1800 && preg_match('//u', $message) === 1 && substr($message, -3) === '…', 'Long Chinese/emoji notification truncates within 1800 UTF-8 bytes');
    qqCheck(strpos($message, '.example.test') === false && strpos($message, 'https://') === false && strpos($message, '[链接已省略]') !== false, 'Disabled link setting removes author, friendship and review URLs');

    qqConfig(['enable_comment_by_qq' => '1']);
    $before = count(qqRequests());
    $response = qqCall('/dispatch', ['message' => '立即交付持久服务']);
    $rows = qqQueue();
    $envelope = qqEnvelope(end($rows));
    qqCheck($response['status'] === 200 && count(qqRequests()) === $before + 1 && (int)end($rows)['status'] === 1, 'Official dispatch reaches service immediately', $response);
    qqCheck(($envelope['provider'] ?? '') === 'official' && ($envelope['serviceURL'] ?? '') === $official['qq_official_url']
        && ($envelope['requestId'] ?? '') === qqLastRequest()['payload']['idempotency_key']
        && strpos(end($rows)['message'], $token) === false, 'Local official record contains service/target snapshot and stable key without token', $envelope);
    qqClearQueue();

    qqRespond(503, ['ok' => false, 'error' => 'temporary']);
    qqCall('/dispatch', ['message' => '失败后重试']);
    $firstRequest = qqLastRequest();
    $row = qqQueue()[0];
    qqCheck((int)$row['status'] === 0 && $row['last_error'] !== '', 'Failed immediate handoff remains in durable local retry queue', $row);
    qqConfig(['qq_official_targets' => 'new-owner']);
    qqRespond();
    qqCall('/process');
    $retryRequest = qqLastRequest();
    qqCheck((int)qqQueue()[0]['status'] === 1 && $retryRequest['payload']['idempotency_key'] === $firstRequest['payload']['idempotency_key'], 'Retry reuses the original idempotency key');
    qqCheck($retryRequest['payload']['targets'] === ['owner', 'admins'], 'Retry retains original aliases after configured targets change');
    qqClearQueue();

    qqRespond(503, ['ok' => false]);
    qqCall('/dispatch', ['message' => '地址变更保护']);
    $before = count(qqRequests());
    qqConfig(['qq_official_url' => $botBase . '/moved']);
    qqRespond();
    qqCall('/process');
    $row = qqQueue()[0];
    qqCheck(count(qqRequests()) === $before && (int)$row['status'] === 0 && strpos($row['last_error'], '地址已更改') !== false, 'Changed service URL refuses old queued credential destination', $row);
    qqConfig(['qq_official_url' => $official['qq_official_url']]);
    qqCall('/process');
    qqCheck((int)qqQueue()[0]['status'] === 1, 'Restoring service URL permits safe retry');
    qqClearQueue();

    // Historical raw jobs remain visible, but cannot send or block modern jobs.
    foreach (['模式切换之前的旧通知', '', '__ENHANCEMENT_QQ_OFFICIAL_V1X:invalid-prefix'] as $message) {
        fixtureInsert($pdo, 'qq_notify_queue', ['message' => $message, 'status' => 0, 'retries' => 0]);
    }
    fixtureInsert($pdo, 'qq_notify_queue', ['message' => '旧版已发送记录', 'status' => 1, 'retries' => 0]);
    qqRespond(503, ['ok' => false]);
    qqCall('/dispatch', ['message' => '现代通道正常重试']);
    $before = count(qqRequests());
    qqRespond();
    qqCall('/process');
    $rows = qqQueue();
    foreach (array_slice($rows, 0, 3) as $row) {
        qqCheck((int)$row['status'] === 2 && (int)$row['retries'] === 5 && strpos($row['last_error'], '已移除') !== false,
            'Obsolete raw record is retained as failed with an explicit reason', $row);
    }
    qqCheck((int)$rows[3]['status'] === 1 && $rows[3]['message'] === '旧版已发送记录', 'Old successful history remains intact');
    qqCheck(count(qqRequests()) === $before + 1 && qqLastRequest()['path'] === '/official/api/notifications'
        && (int)$rows[4]['status'] === 1, 'Old records never reach a transport or block modern queue in same request');
    $pdo->exec('UPDATE typecho_qq_notify_queue SET status=2 WHERE qid=' . (int)$rows[4]['qid']);
    $response = fixtureRequest($base, '/retry', 'POST', [], ['X-Requested-With: XMLHttpRequest']);
    $rows = qqQueue();
    qqCheck(($response['json']['success'] ?? false) === true && (int)$rows[0]['status'] === 2
        && (int)$rows[4]['status'] === 0, 'Manual retry requeues modern jobs and skips retired transport records', $response);
    qqConfig(['qq_notify_provider' => 'none']);
    $before = count(qqRequests());
    qqCall('/process');
    qqCall('/dispatch');
    qqCheck((qqCall('/test')['json']['success'] ?? true) === false && count(qqRequests()) === $before
        && count(qqQueue()) === 5 && (int)qqQueue()[4]['status'] === 0, 'Unconfigured provider stops tests, business sends and modern queue delivery');
    qqConfig(['qq_notify_provider' => 'official']);
    qqClearQueue();

    qqConfig(['qq_official_token' => '']);
    qqCheck((qqSettings()['qq_official_token'] ?? '') === $token, 'Empty password save preserves stored token');
    $newToken = 'replacement-fixture-token-' . str_repeat('y', 32);
    qqConfig(['qq_official_token' => $newToken]);
    qqCheck((qqSettings()['qq_official_token'] ?? '') === $newToken, 'New password save replaces stored token');
    qqRespond();
    qqCall('/test');
    qqCheck(qqLastRequest()['authorization'] === 'Bearer ' . $newToken, 'Replacement token authenticates next request');
    $response = fixtureRequest($base, '/form');
    qqCheck($response['status'] === 200 && strpos($response['body'], 'name="qq_official_token"') !== false
        && strpos($response['body'], $newToken) === false && strpos($response['body'], $token) === false,
        'Real Typecho settings form never renders saved token', ['status' => $response['status'], 'body' => substr($response['body'], 0, 200)]);
    foreach (['qq', 'qqboturl', 'qq_async_queue'] as $name) {
        qqCheck(strpos($response['body'], 'name="' . $name . '"') === false, 'Obsolete setting removed from actual form: ' . $name);
    }
    qqCheck(strpos($response['body'], 'value="legacy"') === false && strpos($response['body'], '153985848') === false,
        'Obsolete provider option and bot contact removed from actual form');

    foreach ([
        ['qq_notify_provider' => 'unknown'], ['qq_notify_provider' => ['official']], ['qq_official_url' => 'ftp://127.0.0.1/bot'],
        ['qq_official_url' => 'https://user:password@example.test'], ['qq_official_url' => 'https://example.test?token=x'],
        ['qq_official_token' => 'short'], ['qq_official_token' => str_repeat('x', 32) . "\r\nInjected: x"],
        ['qq_official_targets' => ''], ['qq_official_targets' => 'bad alias'],
        ['qq_official_targets' => implode("\n", array_map(static function ($i) { return 'alias' . $i; }, range(1, 21)))],
        ['qq_official_targets' => ['array-is-invalid']],
    ] as $invalid) {
        $response = qqCall('/validate', array_replace($official, $invalid));
        qqCheck($response['status'] === 400, 'Invalid official setting rejected: ' . implode(',', array_keys($invalid)), $response);
    }
    $beforeSettings = qqSettings();
    $response = qqConfig(['qq_official_token' => 'short']);
    qqCheck($response['status'] === 400 && qqSettings() === $beforeSettings, 'Invalid saved configuration leaves previous settings intact', $response);

    foreach (['typecho.log', 'bot.log'] as $file) {
        $log = file_get_contents($directory . '/' . $file);
        qqCheck(preg_match('/PHP (?:Warning|Fatal error|Parse error|Deprecated|Notice)/i', $log) !== 1, 'Fixture log has no PHP warnings or errors: ' . $file, $log);
    }
} catch (Throwable $exception) {
    qqCheck(false, 'Runner exception', $exception->getMessage());
} finally {
    foreach ($processes as $process) {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
    $pdo = null;
    if (!$failures) {
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
}
echo $passed . ' assertions passed; ' . count($failures) . " failed.\n";
if ($failures) { echo 'Fixture logs retained: ' . $directory . "\n"; }
exit($failures ? 1 : 0);
