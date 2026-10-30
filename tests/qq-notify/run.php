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
    $command = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=32767'];
    if (!empty($env['ENHANCEMENT_QQ_TEST_NO_SODIUM'])) {
        $command[] = '-d';
        $command[] = 'disable_functions=sodium_crypto_sign_seed_keypair,sodium_crypto_sign_publickey,sodium_crypto_sign_secretkey,sodium_crypto_sign_detached,sodium_crypto_sign_verify_detached';
    }
    $command = array_merge($command, ['-S', $address, $router]);
    $process = proc_open($command,
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
function qqRespond(int $status = 200, $body = null, ?string $location = null): void
{
    qqCall('/state', ['key' => 'fixture_qq_api', 'operation' => 'write', 'record' => ['enabled' => true,
        'responses' => [['status' => $status, 'data' => $body ?? ['id' => 'fixture-notification']]]]]);
}
function qqUseQqApi(): void
{
    qqCall('/state', ['key' => 'fixture_qq_api', 'operation' => 'write', 'record' => ['enabled' => true]]);
}
function qqRequests(): array
{
    global $directory;
    return array_map(static function ($line) { return json_decode($line, true); },
        is_file($directory . '/qq-api.jsonl') ? file($directory . '/qq-api.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []);
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
    $prefix = '__ENHANCEMENT_QQ_API_V2__:';
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
function qqSignedWebhook(array $event, ?string $secret = null, ?string $appId = null): array
{
    global $base;
    $settings = qqSettings();
    $secret = $secret ?? $settings['qq_app_secret'];
    $appId = $appId ?? $settings['qq_app_id'];
    $seed = substr(str_repeat($secret, (int)ceil(32 / strlen($secret))), 0, 32);
    $keypair = sodium_crypto_sign_seed_keypair($seed);
    $raw = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stamp = (string)time();
    return fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', $raw,
        ['Content-Type: application/json', 'X-Bot-Appid: ' . $appId, 'X-Signature-Timestamp: ' . $stamp,
            'X-Signature-Ed25519: ' . bin2hex(sodium_crypto_sign_detached($stamp . $raw, sodium_crypto_sign_secretkey($keypair)))]);
}
function qqBind(array $admin, string $target = 'FIXTURE_PRIVATE_USER', string $type = 'c2c'): array
{
    $pending = qqAction($admin, 'qq-binding-start', ['target_type' => $type]);
    $data = ['id' => 'bind-' . bin2hex(random_bytes(6)), 'author' => ['user_openid' => $target],
        'content' => $pending['json']['data']['command'] ?? '', 'timestamp' => time()];
    if ($type === 'group') { $data['group_openid'] = $target; }
    return qqSignedWebhook(['op' => 0, 't' => $type === 'group' ? 'GROUP_AT_MESSAGE_CREATE' : 'C2C_MESSAGE_CREATE', 'd' => $data]);
}
if (defined('ENHANCEMENT_QQ_FIXTURE_LIBRARY') && ENHANCEMENT_QQ_FIXTURE_LIBRARY === true) { return; }

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $env = getenv();
    $env['ENHANCEMENT_QQ_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_QQ_TEST_ROOT'] = $root;
    qqStartServer($address, __DIR__ . '/router.php', $env, $directory . '/typecho.log');

    $obsolete = ['qq' => '12345678', 'qqboturl' => 'https://old-service.invalid', 'qq_async_queue' => '1',
        'qq_official_url' => 'https://old-official.invalid', 'qq_official_token' => 'obsolete-service-secret',
        'qq_official_targets' => 'private-alias', 'qq_target_type' => 'c2c', 'qq_target_id' => 'OBSOLETE_PRIVATE_ID',
        'qq_moments_secret' => 'obsolete-callback-secret', 'qq_moments_targets' => 'obsolete-writer'];
    foreach ([[], ['qq_notify_provider' => 'legacy'], ['qq_notify_provider' => 'official'],
        ['qq_notify_provider' => 'standalone'], ['qq_notify_provider' => 'openclaw'], ['qq_notify_provider' => 'hermes']] as $provider) {
        $historical = array_merge($obsolete, $provider, ['enable_comment_by_qq' => '1',
            'enable_link_submit_by_qq' => '1', 'qq_moments_enabled' => '1']);
        $pdo->prepare("UPDATE typecho_options SET value=? WHERE name='plugin:Enhancement' AND user=0")->execute([serialize($historical)]);
        $before = count(qqRequests());
        $response = qqCall('/test');
        qqCall('/dispatch'); qqCall('/comment'); qqCall('/process');
        qqCheck(($response['json']['success'] ?? true) === false && count(qqRequests()) === $before && qqQueue() === [],
            'Retired or missing provider cannot send or enqueue: ' . ($provider['qq_notify_provider'] ?? 'missing'));
        $form = fixtureRequest($base, '/form');
        qqCheck($form['status'] === 200 && preg_match('/<input[^>]*id="qq_notify_provider-none"[^>]*checked=/', $form['body']) === 1,
            'Historical provider renders as unconfigured without HTTP 500', $form['status']);
        qqCheck(strpos($form['body'], $obsolete['qq_official_token']) === false
            && strpos($form['body'], $obsolete['qq_moments_secret']) === false
            && strpos($form['body'], $obsolete['qq_target_id']) === false && qqSettings() === $historical,
            'Historical secrets and recipient never leak or change by opening the form');
        $response = qqConfig(['enable_comment_by_qq' => '0', 'enable_link_submit_by_qq' => '0', 'qq_moments_enabled' => '0']);
        $migrated = qqSettings();
        qqCheck($response['status'] === 200 && ($migrated['qq_notify_provider'] ?? '') === 'none'
            && array_intersect(array_keys($obsolete), array_keys($migrated)) === [],
            'Unrelated save retires old transport and removes old configuration', $response);
        $response = qqCall('/restore', $historical);
        qqCheck($response['status'] === 200 && (qqSettings()['qq_notify_provider'] ?? '') === 'none'
            && array_intersect(array_keys($obsolete), array_keys(qqSettings())) === [],
            'Restoring historical settings cannot revive obsolete transport', $response);
    }

    $secret = 'fixture-official-secret-' . str_repeat('s', 32);
    $official = ['qq_notify_provider' => 'official', 'qq_app_id' => '123456789', 'qq_app_secret' => $secret,
        'qq_official_include_links' => '1', 'qq_moments_enabled' => '0',
        'enable_comment_by_qq' => '1', 'enable_link_submit_by_qq' => '1'];
    $direct = array_replace($obsolete, $official, ['qq_notify_provider' => 'direct', 'qq_direct_include_links' => '0', 'qq_moments_enabled' => '1']);
    $pdo->prepare("UPDATE typecho_options SET value=? WHERE name='plugin:Enhancement' AND user=0")->execute([serialize($direct)]);
    $response = qqConfig(['enable_comment_by_qq' => '0']);
    $migrated = qqSettings();
    qqCheck($response['status'] === 200 && ($migrated['qq_notify_provider'] ?? '') === 'official'
        && ($migrated['qq_app_id'] ?? '') === $official['qq_app_id'] && ($migrated['qq_app_secret'] ?? '') === $secret
        && ($migrated['qq_moments_enabled'] ?? '0') === '0' && ($migrated['qq_official_include_links'] ?? '') === '0'
        && array_intersect(array_keys($obsolete), array_keys($migrated)) === [],
        'Legacy direct credentials migrate to official while old recipient and publication authorization are removed', $response);
    $response = qqCall('/restore', array_replace($obsolete, $official));
    qqCheck($response['status'] === 200 && (qqSettings()['qq_notify_provider'] ?? '') === 'official'
        && array_intersect(array_keys($obsolete), array_keys(qqSettings())) === [],
        'New official credentials take precedence over obsolete service fields during restore', $response);
    $before = count(qqRequests());
    $response = qqCall('/test');
    qqCheck(($response['json']['success'] ?? true) === false && count(qqRequests()) === $before,
        'Saved official credentials cannot send before recipient binding', $response);

    foreach ([['qq_notify_provider' => 'unknown'], ['qq_notify_provider' => ['official']], ['qq_app_id' => 'app-id'],
        ['qq_app_id' => ''], ['qq_app_id' => str_repeat('1', 33)], ['qq_app_id' => ['123']], ['qq_app_secret' => ''],
        ['qq_app_secret' => ['secret']], ['qq_app_secret' => "secret\r\nHeader"], ['qq_app_secret' => '中文密钥'],
        ['qq_app_secret' => str_repeat('s', 257)], ['qq_official_include_links' => '2'], ['qq_moments_enabled' => '2'],
        ['qq_moments_enabled' => ['1']], ['qq_notify_provider' => 'none', 'qq_moments_enabled' => '1']] as $invalid) {
        $response = qqCall('/validate', array_replace($official, $invalid));
        qqCheck($response['status'] === 400, 'Malformed official credentials or switches rejected: ' . implode(',', array_keys($invalid)), $response);
    }
    qqCheck(qqCall('/validate', ['qq_notify_provider' => 'none', 'qq_moments_enabled' => '0'])['status'] === 200,
        'Disabled official channel requires no credentials or recipient');
    $beforeSettings = qqSettings();
    $response = qqConfig(['qq_app_secret' => 'bad secret']);
    qqCheck($response['status'] === 400 && qqSettings() === $beforeSettings, 'Invalid save preserves the previous configuration', $response);
    $response = qqConfig(['qq_app_secret' => '']);
    qqCheck($response['status'] === 200 && (qqSettings()['qq_app_secret'] ?? '') === $secret, 'Blank password preserves the saved AppSecret');
    $beforeSettings = qqSettings();
    $response = qqConfig(['qq_app_id' => '987654321', 'qq_app_secret' => '']);
    qqCheck($response['status'] === 400 && qqSettings() === $beforeSettings, 'Changed AppID cannot accidentally reuse the saved AppSecret', $response);
    $response = qqConfig(['qq_moments_enabled' => '1']);
    qqCheck($response['status'] === 200 && qqSettings()['qq_moments_enabled'] === '1', 'Publication can be enabled before private binding');

    $form = fixtureRequest($base, '/form');
    qqCheck($form['status'] === 200 && strpos($form['body'], 'name="qq_app_id"') !== false
        && strpos($form['body'], 'name="qq_app_secret"') !== false && strpos($form['body'], $secret) === false,
        'Settings render AppID and an empty AppSecret without exposing the secret', $form['status']);
    qqCheck(strpos($form['body'], '无需额外启用 PHP 扩展') !== false && strpos($form['body'], '需启用 sodium 扩展') === false,
        'QQ setup describes bundled signature verification without requiring an extension');
    foreach (array_keys($obsolete) as $name) {
        qqCheck(strpos($form['body'], 'name="' . $name . '"') === false, 'Obsolete field is absent from the settings form: ' . $name);
    }
    qqCheck(strpos($form['body'], 'name="qq_target_id"') === false && strpos($form['body'], 'name="qq_moments_allowed_senders"') === false
        && strpos($form['body'], 'id="enhancement-qq-binding"') !== false && substr_count($form['body'], 'data-action="qq-binding-start"') === 2
        && strpos($form['body'], 'data-qq-retry-replies=') !== false && strpos($form['body'], 'data-action="qq-retry-replies"') !== false,
        'Official UI obtains recipient automatically and supports private or group binding');
    if (preg_match('/id="enhancement-qq-webhook-url"[^>]*value="([^"]+)"/', $form['body'], $matches)) {
        qqCheck(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8') === $base . '/action/enhancement-edit?do=qq-webhook',
            'Official QQ callback is public and has no browser CSRF token');
    } else { qqCheck(false, 'Official QQ callback URL appears in the form'); }
    $pdo->exec("UPDATE typecho_options SET value='0' WHERE name='rewrite' AND user=0");
    $form = fixtureRequest($base, '/form');
    qqCheck(strpos($form['body'], $base . '/index.php/action/enhancement-edit?do=qq-webhook') !== false,
        'Official QQ callback includes index.php when rewriting is disabled');
    $pdo->exec("UPDATE typecho_options SET value='1' WHERE name='rewrite' AND user=0");

    $admin = qqSession(1);
    $contributor = qqSession(2);
    foreach (['qq-binding-start', 'qq-binding-status', 'qq-binding-cancel', 'qq-disconnect', 'qq-retry-replies'] as $action) {
        $response = qqAction($contributor, $action);
        qqCheck($response['status'] === 403, 'Contributor cannot administer QQ binding or replies: ' . $action, $response);
    }
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-binding-start', 'POST', '', $admin['headers']);
    qqCheck($response['status'] !== 200, 'QQ binding requires the administrator CSRF token');
    $response = qqAction($admin, 'qq-binding-status', [], 'GET');
    qqCheck($response['status'] === 405, 'QQ binding status requires POST');
    $response = qqAction($admin, 'qq-binding-status');
    qqCheck($response['status'] === 200 && !empty($response['json']['data']['configured'])
        && empty($response['json']['data']['bound']) && !empty($response['json']['data']['moments_enabled'])
        && strpos($response['body'], $secret) === false, 'QQ status shows saved credentials and publication switch without the secret', $response);
    qqCheck(!empty($response['json']['data']['available']) && ($response['json']['data']['crypto_backend'] ?? '') === 'compat',
        'QQ status uses bundled pure PHP signature verification without a native extension', $response);
    $pending = qqAction($admin, 'qq-binding-start');
    qqCheck($pending['status'] === 200 && preg_match('/^[A-F0-9]{16}$/D', $pending['json']['data']['code'] ?? '') === 1
        && ($pending['json']['data']['expires_at'] ?? 0) > time() + 590, 'Administrator creates a ten-minute private binding command', $pending);
    $response = qqAction($admin, 'qq-binding-cancel');
    qqCheck($response['status'] === 200 && (qqCall('/state', ['key' => 'qq_binding_pending'])['json']['record'] ?? null) === [],
        'Administrator can cancel a pending binding');
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', ['op' => 0, 't' => 'C2C_MESSAGE_CREATE', 'd' => ['content' => '瞬间 未验签']]);
    qqCheck($response['status'] === 403, 'Public official callback rejects unsigned message events without admin login', $response);
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', ['op' => 13, 'd' => ['plain_token' => 'bootstrap', 'event_ts' => (string)time()]]);
    qqCheck($response['status'] === 200 && ($response['json']['plain_token'] ?? '') === 'bootstrap',
        'Official initial callback challenge works before signature headers are established', $response);
    $response = qqSignedWebhook(['op' => 13, 'd' => ['plain_token' => 'challenge', 'event_ts' => (string)time()]]);
    qqCheck($response['status'] === 200 && ($response['json']['plain_token'] ?? '') === 'challenge'
        && preg_match('/^[a-f0-9]{128}$/D', $response['json']['signature'] ?? '') === 1, 'Signed official QQ challenge succeeds without browser credentials', $response);
    $response = qqBind($admin);
    $status = qqAction($admin, 'qq-binding-status');
    qqCheck($response['status'] === 200 && !empty($status['json']['data']['bound_user'])
        && ($status['json']['data']['target_type'] ?? '') === 'c2c' && strpos($status['body'], 'FIXTURE_PRIVATE_USER') === false
        && !empty($status['json']['data']['last_event_at']) && !empty($status['json']['data']['last_challenge_at']),
        'Signed private binding stores OpenID privately and updates public readiness', $status);

    qqUseQqApi();
    $before = count(qqRequests());
    $response = qqCall('/test', ['message' => 'QQ 官方通知🙂']);
    $calls = array_slice(qqRequests(), $before);
    qqCheck(($response['json']['success'] ?? false) === true && ($response['json']['delivery'] ?? '') === 'accepted' && count($calls) === 2,
        'Bound official QQ obtains a token and sends through the official API', $response);
    qqCheck(($calls[0]['url'] ?? '') === 'https://api.bot.qq.com/app/getAppAccessToken'
        && ($calls[0]['payload'] ?? []) === ['appId' => $official['qq_app_id'], 'clientSecret' => $secret]
        && ($calls[1]['url'] ?? '') === 'https://api.bot.qq.com/v2/users/FIXTURE_PRIVATE_USER/messages'
        && ($calls[1]['headers'] ?? []) === ['Authorization: QQBot fixture-access-token'], 'App credentials and verified recipient use only the official QQ API', $calls);
    $before = count(qqRequests());
    qqCall('/test');
    qqCheck(count(qqRequests()) === $before + 1, 'Subsequent official notification reuses the saved private token');
    $response = qqSignedWebhook(['op' => 0, 't' => 'C2C_MESSAGE_CREATE', 'd' => ['id' => 'fixture-user-command',
        'author' => ['user_openid' => 'FIXTURE_PRIVATE_USER'], 'content' => '瞬间 QQ发布瞬间测试!~[CQ:face,id=14]', 'timestamp' => time()]]);
    $moment = $pdo->query('SELECT * FROM typecho_moments ORDER BY mid DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    qqCheck($response['status'] === 200 && strpos($moment['content'] ?? '', 'QQ发布瞬间测试!~') !== false,
        'Bound private user command reaches the real signed callback and publishes a moment', $response);
    qqCheck((qqLastRequest()['payload']['msg_id'] ?? '') === 'fixture-user-command', 'QQ publication replies reference the original private message');
    $before = count(qqRequests());
    $response = qqAction($admin, 'qq-retry-replies');
    qqCheck($response['status'] === 200 && ($response['json']['data']['sent'] ?? null) === 0 && count(qqRequests()) === $before,
        'Published result reply retry action does not resend an already accepted reply', $response);

    $response = qqBind($admin, 'FIXTURE_GROUP', 'group');
    $status = qqAction($admin, 'qq-binding-status');
    qqCheck($response['status'] === 200 && ($status['json']['data']['target_type'] ?? '') === 'group'
        && empty($status['json']['data']['bound_user']), 'Group binding request preserves its selected target type without private publication permission', $status);
    $response = qqCall('/test', ['message' => '官方 QQ 群通知']);
    qqCheck(!empty($response['json']['success']) && (qqLastRequest()['url'] ?? '') === 'https://api.bot.qq.com/v2/groups/FIXTURE_GROUP/messages',
        'Verified optional group binding sends through the official group endpoint', $response);
    qqBind($admin);

    qqConfig(['enable_comment_by_qq' => '1']);
    qqClearQueue();
    $before = count(qqRequests());
    qqCall('/comment');
    $rows = qqQueue();
    $envelope = $rows ? qqEnvelope(end($rows)) : [];
    qqCheck(count(qqRequests()) === $before + 1 && count($rows) === 1 && (int)$rows[0]['status'] === 1
        && ($envelope['binding']['target_id'] ?? '') === 'FIXTURE_PRIVATE_USER'
        && preg_match('/^[a-f0-9]{40}$/D', $envelope['requestId'] ?? '') === 1
        && strpos($rows[0]['message'], $secret) === false, 'Business notification persists a secret-free recipient snapshot and records API acceptance', $rows);
    qqRespond(503, ['code' => 503]);
    qqCall('/dispatch', ['message' => '可重试官方通知']);
    $rows = qqQueue();
    qqCheck((int)end($rows)['status'] === 0, 'Failed official API send remains available in the local retry queue', $rows);
    qqUseQqApi();
    qqCall('/process');
    $rows = qqQueue();
    qqCheck((int)end($rows)['status'] === 1, 'A later request retries the pending notification through the official API', $rows);
    qqRespond(503, ['code' => 503]);
    qqCall('/dispatch', ['message' => '不能误投给新接收人']);
    qqUseQqApi();
    qqBind($admin, 'REPLACEMENT_USER');
    $before = count(qqRequests());
    qqCall('/process');
    $rows = qqQueue();
    qqCheck(count(qqRequests()) === $before && (int)end($rows)['status'] === 0 && strpos(end($rows)['last_error'], '绑定已变化') !== false,
        'Changed recipient prevents an old queued notification from reaching the new recipient', $rows);
    qqClearQueue();
    foreach (['raw obsolete notification', '__ENHANCEMENT_QQ_OFFICIAL_V1__:{}', '__ENHANCEMENT_QQ_AGENT_V1__:{}',
        '__ENHANCEMENT_QQ_API_V2__:{"provider":"retired"}'] as $oldMessage) {
        fixtureInsert($pdo, 'qq_notify_queue', ['message' => $oldMessage, 'status' => 0, 'retries' => 0,
            'created' => time(), 'updated' => time(), 'last_error' => null]);
    }
    $before = count(qqRequests());
    qqCall('/process');
    qqCheck(count(qqRequests()) === $before && count(array_filter(qqQueue(), static function ($row) { return (int)$row['status'] === 2; })) === 4,
        'Obsolete raw, service and agent queue records are permanently retired');
    qqClearQueue();

    $replacementSecret = 'replacement-official-secret-' . str_repeat('r', 32);
    $response = qqConfig(['qq_app_secret' => $replacementSecret]);
    $state = qqAction($admin, 'qq-binding-status');
    qqCheck($response['status'] === 200 && (qqSettings()['qq_app_secret'] ?? '') === $replacementSecret
        && empty($state['json']['data']['bound']) && (qqCall('/state', ['key' => 'qq_binding'])['json']['record'] ?? null) === []
        && (qqCall('/state', ['key' => 'qq_token'])['json']['record'] ?? null) === [], 'Credential rotation clears the old recipient, pending code and access token', $response);
    qqConfig(['qq_app_secret' => $secret]);
    qqCheck(empty(qqAction($admin, 'qq-binding-status')['json']['data']['bound']), 'Restoring an old AppSecret cannot revive its previous recipient authorization');
    qqConfig(['qq_app_secret' => $replacementSecret, 'qq_moments_enabled' => '0']);
    qqBind($admin);
    $response = qqAction($admin, 'qq-disconnect');
    qqCheck($response['status'] === 200 && empty(qqAction($admin, 'qq-binding-status')['json']['data']['bound']),
        'Administrator can remove the official QQ recipient binding', $response);

    $response = qqCall('/notify-format', ['message' => '<b>中文通知</b> https://example.test/post', 'include_links' => false]);
    qqCheck(($response['json']['text'] ?? '') === '中文通知 [链接已省略]', 'Weixin formatter strips HTML and optional links');
    $response = qqCall('/notify-format', ['message' => str_repeat('测😀', 400), 'include_links' => true]);
    $formatted = (string)($response['json']['text'] ?? '');
    qqCheck(strlen($formatted) <= 1800 && preg_match('//u', $formatted) === 1 && substr($formatted, -3) === '…',
        'Weixin formatter truncates long Chinese and emoji messages without splitting UTF-8');

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

    $admin = qqSession(1);
    $contributor = qqSession(2);
    $guestForm = fixtureRequest($base, '/form');
    if (preg_match('/data-weixin-bind="([^"]+)"/', $guestForm['body'], $matches)) {
        $guestUrl = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        $response = fixtureRequest($base, substr($guestUrl, strlen($base)), 'POST', '', ['Referer: ' . $base . '/form']);
        qqCheck($response['status'] === 302 && (qqCall('/state', ['key' => 'weixin_recipient'])['json']['record'] ?? null) === [],
            'Anonymous browser with a valid guest CSRF token still cannot bind Weixin recipient', $response['status']);
    } else {
        qqCheck(false, 'Guest form provides a CSRF-protected Weixin binding URL');
    }
    $notificationActions = array_keys($admin['actions']);
    qqCheck(count($notificationActions) === 12 && count(array_filter($notificationActions, static function ($action) {
        return strpos($action, 'weixin-') === 0;
    })) === 7, 'Notification session exposes official QQ and preserved Weixin actions');
    foreach ($notificationActions as $action) {
        $response = fixtureRequest($base, '/action/enhancement-edit?do=' . $action, 'POST', [], ['X-Requested-With: XMLHttpRequest']);
        qqCheck($response['status'] !== 200 && ($response['json']['success'] ?? false) !== true,
            'Anonymous requests cannot use notification action: ' . $action, $response['status']);
    }
    foreach (['qq-binding-start', 'qq-binding-status', 'qq-disconnect', 'weixin-bind', 'weixin-status', 'weixin-disconnect'] as $action) {
        $response = qqAction($contributor, $action);
        qqCheck($response['status'] === 403, 'Logged-in contributor cannot use administrator notification action: ' . $action, $response);
    }
    $response = fixtureRequest($base, '/action/enhancement-edit?do=weixin-bind', 'POST', '', $admin['headers']);
    qqCheck($response['status'] !== 200, 'Administrator request without CSRF token cannot bind Weixin recipient', $response['status']);
    $response = qqAction($admin, 'weixin-status', [], 'GET');
    qqCheck($response['status'] === 405, 'Notification status requires POST even with administrator and valid CSRF', $response);
    $response = qqAction($admin, 'weixin-status');
    qqCheck($response['status'] === 200 && ($response['json']['success'] ?? false) === true
        && ($response['json']['data']['logged_in'] ?? true) === false,
        'Authorized Weixin status reports logged out before login', $response);

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
    qqCheck($form['status'] === 200 && preg_match('/<input[^>]*id="qq_notify_provider-official"[^>]*checked=/', $form['body']) === 1,
        'Real Typecho form keeps the saved official Webhook provider selected');
    qqCheck($form['status'] === 200 && strpos($form['body'], 'name="qq_app_secret"') !== false
        && strpos($form['body'], $replacementSecret) === false && strpos($form['body'], $privateToken) === false
        && strpos($form['body'], $privateContext) === false, 'Real Typecho form renders both channels without saved or private credentials');
    qqCheck(substr_count($form['body'], '/assets/notifications.js') === 1 && substr_count($form['body'], '/assets/vendor/qrcode.js') === 1
        && strpos($form['body'], 'name="enable_comment_by_weixin"') !== false && strpos($form['body'], 'id="enhancement-weixin"') !== false
        && strpos($form['body'], 'id="enhancement-qq-binding"') !== false && strpos($form['body'], 'do=qq-webhook') !== false,
        'Real settings form includes official QQ binding, Weixin controls and local QR scripts once');
    qqCall('/state', ['key' => 'weixin_account', 'operation' => 'write', 'record' => ['token' => $privateToken,
        'account_id' => 'fixture-bot', 'owner_id' => 'fixture-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => true]]);
    $response = qqConfig(['qq_official_include_links' => '0']);
    qqCheck($response['status'] === 200 && qqSettings()['enable_comment_by_weixin'] === '1',
        'An expired Weixin login cannot block unrelated official Webhook settings', $response);
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

    foreach (['typecho.log'] as $file) {
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
