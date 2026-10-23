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
function qqSession(int $uid): array
{
    $response = qqCall('/session', ['uid' => $uid]);
    $cookies = [];
    foreach ($response['raw_headers'] as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $matches)) { $cookies[] = $matches[1]; }
    }
    if ($response['status'] !== 200 || !$cookies) { throw new RuntimeException('Fixture login failed: ' . $response['body']); }
    return ['headers' => ['Cookie: ' . implode('; ', $cookies), 'Referer: ' . $response['json']['referer'],
        'X-Requested-With: XMLHttpRequest'], 'actions' => $response['json']['actions']];
}
function qqAction(array $session, string $action, array $values = [], string $method = 'POST'): array
{
    global $base;
    $url = $session['actions'][$action];
    $path = substr($url, strlen($base));
    return fixtureRequest($base, $path, $method, http_build_query($values),
        array_merge($session['headers'], ['Content-Type: application/x-www-form-urlencoded']));
}
function qqSignedWebhook(array $event, string $secret, string $appId): array
{
    global $base;
    $seed = $secret;
    while (strlen($seed) < 32) { $seed .= $secret; }
    $keypair = sodium_crypto_sign_seed_keypair(substr($seed, 0, 32));
    $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string)time();
    $signature = bin2hex(sodium_crypto_sign_detached($timestamp . $body, sodium_crypto_sign_secretkey($keypair)));
    return fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', $body,
        ['Content-Type: application/json', 'X-Bot-Appid: ' . $appId, 'X-Signature-Timestamp: ' . $timestamp, 'X-Signature-Ed25519: ' . $signature]);
}

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
        $beforeSettings = qqSettings();
        $response = fixtureRequest($base, '/form');
        $form = $response['body'];
        qqCheck($response['status'] === 200 && strpos($form, 'name="qq_notify_provider"') !== false,
            'Post-upgrade settings page binds every saved field without HTTP 500', $response['status']);
        qqCheck(qqSettings() === $beforeSettings, 'Opening settings page preserves stored configuration');
        qqCheck(strpos($form, $oldSettings['qqboturl']) === false && strpos($form, $oldSettings['qq']) === false
            && strpos($form, 'name="qqboturl"') === false && strpos($form, 'name="qq_async_queue"') === false
            && strpos($form, 'name="qq"') === false,
            'Compatibility placeholders neither display nor submit obsolete QQ settings');
        qqCheck(preg_match('/<input[^>]*id="qq_notify_provider-none"[^>]*checked=/', $form) === 1,
            'Old installation form selects unconfigured provider');
    }
    $beforeSettings = qqSettings();
    $pdo->prepare("UPDATE typecho_options SET value=? WHERE name='plugin:Enhancement' AND user=0")
        ->execute([serialize($beforeSettings + ['removed_fixture_option' => 'private-obsolete-option-value'])]);
    $response = fixtureRequest($base, '/form');
    qqCheck($response['status'] === 200 && strpos($response['body'], 'private-obsolete-option-value') === false
        && strpos($response['body'], 'name="removed_fixture_option"') === false,
        'Other historical saved keys cannot break backend binding or expose obsolete values');
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

    // Upgrade both retired providers with their complete historical configuration.
    $retiredSettings = ['qq_agent_url' => $botBase . '/retired-api', 'qq_agent_token' => 'retired-secret-' . str_repeat('z', 32),
        'qq_agent_target' => 'qqbot:c2c:retired-owner', 'qq_agent_account' => 'retired-account', 'qq_agent_include_links' => '1',
        'qq_agent_commands' => '1', 'qq_agent_command_secret' => 'retained-command-secret-' . str_repeat('y', 32),
        'qq_agent_allowed_senders' => 'retired-owner', 'qq_agent_admin_uid' => '1', 'enable_comment_by_qq' => '1'];
    $retiredKeys = ['qq_agent_url', 'qq_agent_token', 'qq_agent_target', 'qq_agent_account', 'qq_agent_include_links'];
    foreach (['openclaw', 'hermes'] as $provider) {
        $historical = $retiredSettings + ['qq_notify_provider' => $provider];
        $pdo->prepare("UPDATE typecho_options SET value=? WHERE name='plugin:Enhancement' AND user=0")
            ->execute([serialize($historical)]);
        $before = count(qqRequests());
        $response = qqCall('/test');
        qqCall('/dispatch');
        qqCall('/comment');
        qqCall('/process');
        qqCheck(($response['json']['success'] ?? true) === false && count(qqRequests()) === $before && qqQueue() === [],
            'Unsaved retired provider cannot test, enqueue or send: ' . $provider);
        $response = fixtureRequest($base, '/form');
        $form = $response['body'];
        qqCheck($response['status'] === 200 && qqSettings() === $historical,
            'Historical provider fields bind safely without changing stored settings: ' . $provider, $response['status']);
        qqCheck(preg_match('/<input[^>]*id="qq_notify_provider-none"[^>]*checked=/', $form) === 1
            && preg_match('/<input[^>]*id="qq_agent_commands-0"[^>]*checked=/', $form) === 1,
            'Retired provider form selects disabled notification and management: ' . $provider);
        qqCheck(strpos($form, 'value="openclaw"') === false && strpos($form, 'value="hermes"') === false,
            'Real settings form no longer offers either retired provider');
        foreach ($retiredKeys as $key) {
            qqCheck(strpos($form, 'name="' . $key . '"') === false, 'Removed transport field cannot render or submit: ' . $key);
        }
        qqCheck(strpos($form, $historical['qq_agent_url']) === false && strpos($form, $historical['qq_agent_token']) === false
            && strpos($form, $historical['qq_agent_target']) === false && strpos($form, $historical['qq_agent_account']) === false
            && strpos($form, $historical['qq_agent_command_secret']) === false, 'Historical transport values and secrets never appear in HTML');
        $response = qqConfig(['enable_link_submit_by_qq' => '0']);
        $migrated = qqSettings();
        qqCheck($response['status'] === 200 && $migrated['qq_notify_provider'] === 'none' && $migrated['qq_agent_commands'] === '0'
            && array_intersect($retiredKeys, array_keys($migrated)) === []
            && $migrated['qq_agent_command_secret'] === $historical['qq_agent_command_secret'],
            'Unrelated save disables retired provider and drops only obsolete transport settings: ' . $provider, $response);
        $response = qqCall('/restore', $historical);
        $migrated = qqSettings();
        qqCheck($response['status'] === 200 && $migrated['qq_notify_provider'] === 'none' && $migrated['qq_agent_commands'] === '0'
            && array_intersect($retiredKeys, array_keys($migrated)) === [],
            'Restoring old provider backup cannot revive transport or management: ' . $provider, $response);
    }
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

    // Retired Agent envelopes cannot use any credentials or block supported jobs.
    foreach (['openclaw', 'hermes'] as $provider) {
        fixtureInsert($pdo, 'qq_notify_queue', ['message' => '__ENHANCEMENT_QQ_AGENT_V1__:' . json_encode([
            'provider' => $provider, 'url' => $botBase . '/retired-api', 'message' => 'Retired notification',
            'target' => 'qqbot:c2c:retired-owner', 'requestId' => str_repeat('a', 40),
        ]), 'status' => 0, 'retries' => 0]);
    }
    qqRespond(503, ['ok' => false]);
    qqCall('/dispatch', ['message' => 'Supported notification after retired records']);
    $before = count(qqRequests());
    qqRespond();
    qqCall('/process');
    $rows = qqQueue();
    qqCheck(count($rows) === 3 && (int)$rows[0]['status'] === 2 && (int)$rows[1]['status'] === 2
        && (int)$rows[0]['retries'] === 5 && (int)$rows[1]['retries'] === 5
        && strpos($rows[0]['last_error'], '已移除') !== false && strpos($rows[1]['last_error'], '已移除') !== false,
        'Both retired provider queue records are retained with a terminal removal reason', $rows);
    qqCheck(count(qqRequests()) === $before + 1 && qqLastRequest()['path'] === '/official/api/notifications'
        && (int)$rows[2]['status'] === 1, 'Retired records never send or block supported notification in the same request');
    $response = fixtureRequest($base, '/retry', 'POST', [], ['X-Requested-With: XMLHttpRequest']);
    qqCheck(($response['json']['success'] ?? false) === true && (int)qqQueue()[0]['status'] === 2
        && (int)qqQueue()[1]['status'] === 2, 'Manual retry cannot revive retired Agent records');
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

    $response = qqCall('/notify-format', ['message' => '<b>中文通知</b> https://example.test/post', 'include_links' => false]);
    qqCheck(($response['json']['text'] ?? '') === '中文通知 [链接已省略]', 'Direct channel formatter strips HTML and optional links');
    $response = qqCall('/notify-format', ['message' => str_repeat('测😀', 400), 'include_links' => true]);
    $formatted = (string)($response['json']['text'] ?? '');
    qqCheck(strlen($formatted) <= 1800 && preg_match('//u', $formatted) === 1 && substr($formatted, -3) === '…',
        'Direct channel formatter truncates long Chinese and emoji messages without splitting UTF-8');

    // New channels use shared private options without placing credentials in the public plugin form.
    $record = ['token' => 'fixture-private-state-token', 'nested' => ['中文', 'context']];
    qqCall('/state', ['operation' => 'write', 'record' => $record]);
    qqCheck((qqCall('/state')['json']['record'] ?? null) === $record, 'Private notification state persists across Typecho requests');
    $response = qqCall('/state', ['operation' => 'cas', 'expected' => ['token' => 'stale'], 'record' => ['used' => true]]);
    qqCheck(($response['json']['changed'] ?? null) === false && ($response['json']['record'] ?? null) === $record,
        'State compare-and-swap cannot consume changed or stale records', $response);
    $response = qqCall('/state', ['operation' => 'cas', 'expected' => $record, 'record' => ['used' => true]]);
    qqCheck(($response['json']['changed'] ?? false) === true && ($response['json']['record'] ?? null) === ['used' => true],
        'State compare-and-swap consumes an exact record once', $response);
    qqCheck((qqCall('/state', ['operation' => 'cas', 'expected' => $record, 'record' => ['used' => true]])['json']['changed'] ?? null) === false,
        'Consumed private state cannot be consumed a second time');

    $directSecret = 'fixture-direct-app-secret';
    $direct = ['qq_notify_provider' => 'direct', 'qq_app_id' => '123456789', 'qq_app_secret' => $directSecret,
        'qq_target_type' => 'c2c', 'qq_target_id' => '', 'enable_comment_by_qq' => '0', 'enable_link_submit_by_qq' => '0'];
    $response = qqConfig($direct + ['qq_direct_include_links' => '1']);
    qqCheck($response['status'] === 200 && (qqSettings()['qq_notify_provider'] ?? '') === 'direct',
        'Direct QQ credentials save before recipient binding while notification switches are disabled', $response);
    $beforeSettings = qqSettings();
    $response = qqConfig(['enable_comment_by_qq' => '1']);
    qqCheck($response['status'] === 400 && qqSettings() === $beforeSettings, 'Direct QQ cannot be enabled without a recipient', $response);
    qqConfig(['qq_app_secret' => '']);
    qqCheck((qqSettings()['qq_app_secret'] ?? '') === $directSecret, 'Empty direct QQ password preserves AppSecret');

    $admin = qqSession(1);
    $contributor = qqSession(2);
    $guestForm = fixtureRequest($base, '/form');
    if (preg_match('/data-qq-binding-start="([^"]+)"/', $guestForm['body'], $matches)) {
        $guestUrl = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        $response = fixtureRequest($base, substr($guestUrl, strlen($base)), 'POST', '', ['Referer: ' . $base . '/form']);
        qqCheck($response['status'] === 302 && (qqCall('/state', ['key' => 'qq_binding'])['json']['record'] ?? null) === [],
            'Anonymous browser with a valid guest CSRF token still cannot start QQ binding', $response['status']);
    } else {
        qqCheck(false, 'Guest form provides a CSRF-protected binding URL');
    }
    $notificationActions = array_keys($admin['actions']);
    foreach ($notificationActions as $action) {
        $response = fixtureRequest($base, '/action/enhancement-edit?do=' . $action, 'POST', [], ['X-Requested-With: XMLHttpRequest']);
        qqCheck($response['status'] !== 200 && ($response['json']['success'] ?? false) !== true,
            'Anonymous requests cannot use notification action: ' . $action, $response['status']);
    }
    foreach (['qq-binding-start', 'weixin-status', 'weixin-disconnect'] as $action) {
        $response = qqAction($contributor, $action);
        qqCheck($response['status'] === 403, 'Logged-in contributor cannot use administrator notification action: ' . $action, $response);
    }
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-binding-start', 'POST', '', $admin['headers']);
    qqCheck($response['status'] !== 200, 'Administrator request without CSRF token cannot start a QQ binding', $response['status']);
    $response = qqAction($admin, 'qq-binding-status', [], 'GET');
    qqCheck($response['status'] === 405, 'Notification status requires POST even with administrator and valid CSRF', $response);
    $response = qqAction($admin, 'qq-binding-status');
    qqCheck($response['status'] === 200 && ($response['json']['success'] ?? false) === true
        && ($response['json']['data']['configured'] ?? false) === true && ($response['json']['data']['target_id'] ?? null) === '',
        'Authorized status action returns direct QQ configuration without secrets', $response);
    qqCheck(strpos($response['body'], $directSecret) === false, 'Direct QQ status never returns AppSecret');

    if (function_exists('sodium_crypto_sign_seed_keypair')) {
        $response = qqAction($admin, 'qq-binding-start');
        $code = (string)($response['json']['data']['code'] ?? '');
        qqCheck($response['status'] === 200 && preg_match('/\A[A-F0-9]{16}\z/', $code) === 1,
            'Authorized QQ binding action creates a one-time code', $response);
        $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', ['op' => 13, 'd' => []]);
        qqCheck($response['status'] === 403, 'QQ callback rejects unsigned anonymous requests', $response);
        $response = qqSignedWebhook(['op' => 13, 'd' => ['plain_token' => 'fixture-challenge', 'event_ts' => (string)time()]], $directSecret, $direct['qq_app_id']);
        qqCheck($response['status'] === 200 && ($response['json']['plain_token'] ?? '') === 'fixture-challenge'
            && preg_match('/\A[a-f0-9]{128}\z/', $response['json']['signature'] ?? '') === 1,
            'Valid signed QQ callback verifies without browser login or CSRF', $response);
        $event = ['op' => 0, 't' => 'C2C_MESSAGE_CREATE', 'd' => ['content' => '绑定通知 ' . $code, 'author' => ['user_openid' => 'fixture-owner-openid']]];
        $response = qqSignedWebhook($event, $directSecret, $direct['qq_app_id']);
        qqCheck($response['status'] === 200 && (qqSettings()['qq_target_id'] ?? '') === 'fixture-owner-openid'
            && (qqSettings()['qq_app_secret'] ?? '') === $directSecret && qqSettings()['enable_comment_by_qq'] === '0',
            'Signed binding callback saves recipient without losing secret or enabling notifications', $response);
        $event['d']['author']['user_openid'] = 'fixture-other-openid';
        qqSignedWebhook($event, $directSecret, $direct['qq_app_id']);
        qqCheck((qqSettings()['qq_target_id'] ?? '') === 'fixture-owner-openid', 'Used QQ binding code cannot replace the recipient');
        $response = qqAction($admin, 'qq-binding-status');
        qqCheck(empty($response['json']['data']['binding']) && ($response['json']['data']['target_id'] ?? '') === 'fixture-owner-openid'
            && ($response['json']['data']['last_challenge_at'] ?? 0) > 0 && ($response['json']['data']['last_event_at'] ?? 0) > 0,
            'Binding status exposes saved recipient and verified callback timestamps after consumption', $response);
    } else {
        qqConfig(['qq_target_id' => 'fixture-owner-openid']);
    }
    $response = qqConfig(['enable_comment_by_qq' => '1', 'qq_app_secret' => '']);
    qqCheck($response['status'] === 200 && qqSettings()['qq_app_secret'] === $directSecret,
        'Bound direct QQ can be enabled with the blank password form value', $response);

    // Existing failed service jobs remain tied to their old provider after selecting direct QQ.
    qqConfig(['qq_notify_provider' => 'official']);
    qqClearQueue();
    qqRespond(503, ['ok' => false]);
    qqCall('/dispatch', ['message' => '服务通道遗留任务']);
    $queued = qqQueue();
    qqConfig(['qq_notify_provider' => 'direct']);
    $before = count(qqRequests());
    qqCall('/process');
    qqCheck(count($queued) === 1 && count(qqRequests()) === $before && qqQueue()[0]['status'] === $queued[0]['status'],
        'Switching to direct QQ cannot replay an old service queue through either provider');
    qqClearQueue();

    $beforeSettings = qqSettings();
    $response = qqConfig(['enable_comment_by_weixin' => '1']);
    qqCheck($response['status'] === 400 && qqSettings() === $beforeSettings, 'Weixin notification cannot be enabled before login', $response);
    $privateToken = 'fixture-weixin-private-token';
    $privateContext = 'fixture-weixin-private-context';
    qqCall('/state', ['key' => 'weixin_account', 'operation' => 'write', 'record' => ['token' => $privateToken,
        'account_id' => 'fixture-bot', 'owner_id' => 'fixture-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]]);
    $response = qqConfig(['enable_link_submit_by_weixin' => '1']);
    qqCheck($response['status'] === 400 && qqSettings() === $beforeSettings, 'Weixin login without a bound recipient cannot enable notifications', $response);
    qqCall('/state', ['key' => 'weixin_sync', 'operation' => 'write', 'record' => ['candidates' => ['fixture-owner'],
        'context_tokens' => ['fixture-owner' => $privateContext]]]);
    $response = qqAction($admin, 'weixin-bind', ['user_id' => 'fixture-other']);
    qqCheck($response['status'] === 400, 'Weixin bind action rejects a recipient absent from synchronized candidates', $response);
    $response = qqAction($admin, 'weixin-bind', ['user_id' => 'fixture-owner']);
    qqCheck($response['status'] === 200 && ($response['json']['success'] ?? false) === true, 'Weixin bind action accepts the synchronized account owner', $response);
    $response = qqConfig(['enable_comment_by_weixin' => '1', 'enable_link_submit_by_weixin' => '1']);
    qqCheck($response['status'] === 200, 'Weixin business switches save after login and recipient binding', $response);
    $response = qqAction($admin, 'weixin-status');
    qqCheck(($response['json']['data']['logged_in'] ?? false) === true && ($response['json']['data']['has_context'] ?? false) === true
        && ($response['json']['data']['user_id'] ?? '') === 'fixture-owner' && strpos($response['body'], $privateToken) === false
        && strpos($response['body'], $privateContext) === false, 'Weixin status exposes readiness without login token or context token', $response);

    $form = fixtureRequest($base, '/form');
    qqCheck($form['status'] === 200 && preg_match('/<input[^>]*id="qq_notify_provider-direct"[^>]*checked=/', $form['body']) === 1,
        'Real Typecho form keeps the saved direct QQ provider selected');
    qqCheck($form['status'] === 200 && strpos($form['body'], 'name="qq_app_secret"') !== false
        && strpos($form['body'], $directSecret) === false && strpos($form['body'], $privateToken) === false
        && strpos($form['body'], $privateContext) === false, 'Real Typecho form renders both channels without saved or private credentials');
    qqCheck(substr_count($form['body'], '/assets/notifications.js') === 1 && substr_count($form['body'], '/assets/vendor/qrcode.js') === 1
        && strpos($form['body'], 'name="enable_comment_by_weixin"') !== false && strpos($form['body'], 'id="enhancement-qq-binding"') !== false,
        'Real settings form includes both connection controls and local QR scripts exactly once');
    if (preg_match('/id="enhancement-qq-webhook-url"[^>]*value="([^"]+)"/', $form['body'], $matches)) {
        qqCheck(strpos(html_entity_decode($matches[1]), 'do=qq-webhook') !== false && strpos(html_entity_decode($matches[1]), '&_=') === false,
            'Public QQ webhook callback address has no browser CSRF token');
    } else {
        qqCheck(false, 'Public QQ webhook callback address is rendered');
    }
    qqCall('/state', ['key' => 'weixin_account', 'operation' => 'write', 'record' => ['token' => $privateToken,
        'account_id' => 'fixture-bot', 'owner_id' => 'fixture-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => true]]);
    $response = qqConfig(['qq_target_id' => 'fixture-owner-openid']);
    qqCheck($response['status'] === 200 && qqSettings()['enable_comment_by_weixin'] === '1',
        'An expired Weixin login cannot block unrelated settings or a QQ recipient binding', $response);
    qqConfig(['enable_link_submit_by_weixin' => '0']);
    $response = qqConfig(['enable_link_submit_by_weixin' => '1']);
    qqCheck($response['status'] === 400 && qqSettings()['enable_link_submit_by_weixin'] === '0',
        'Re-enabling a Weixin switch requires a current valid login even if another switch remains enabled', $response);
    $response = qqAction($admin, 'weixin-disconnect');
    qqCheck($response['status'] === 200 && (qqCall('/state', ['key' => 'weixin_account'])['json']['record'] ?? null) === []
        && (qqCall('/state', ['key' => 'weixin_sync'])['json']['record'] ?? null) === []
        && (qqCall('/state', ['key' => 'weixin_recipient'])['json']['record'] ?? null) === []
        && qqSettings()['enable_comment_by_weixin'] === '0' && qqSettings()['enable_link_submit_by_weixin'] === '0',
        'Weixin disconnect action clears private login, context and recipient state', $response);

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
