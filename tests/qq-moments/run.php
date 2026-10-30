<?php
/** QQ platform -> signed public Typecho HTTP action -> isolated SQLite + mocked QQ replies. */
declare(strict_types=1);
if (!function_exists('sodium_crypto_sign_seed_keypair')) {
    fwrite(STDERR, "PHP sodium is required for official QQ Webhook signature tests.\n");
    exit(2);
}
define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

function qmKeypair(string $secret): string
{
    return sodium_crypto_sign_seed_keypair(substr(str_repeat($secret, (int)ceil(32 / strlen($secret))), 0, 32));
}
function qmEvent(string $text = '瞬间 QQ发布瞬间测试!~', array $changes = []): array
{
    $id = bin2hex(random_bytes(12));
    return ['op' => 0, 'id' => 'event-' . $id, 't' => 'C2C_MESSAGE_CREATE', 'd' => array_replace([
        'id' => $id, 'author' => ['user_openid' => 'private-owner'], 'timestamp' => time(),
        'content' => $text, 'attachments' => []], $changes)];
}
function qmSend($payload, array $options = []): array
{
    global $base, $settings;
    $raw = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stamp = $options['timestamp'] ?? (string)time();
    $signature = bin2hex(sodium_crypto_sign_detached($stamp . $raw,
        sodium_crypto_sign_secretkey(qmKeypair($options['secret'] ?? $settings['qq_app_secret']))));
    $headers = ['Content-Type: application/json', 'X-Signature-Timestamp: ' . $stamp,
        'X-Signature-Ed25519: ' . $signature];
    if (empty($options['omit_app_id'])) { $headers[] = 'X-Bot-Appid: ' . ($options['app_id'] ?? $settings['qq_app_id']); }
    return fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST',
        $raw . (!empty($options['tamper']) ? ' ' : ''), $headers);
}
function qmRows(): array
{
    global $pdo;
    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='typecho_moments'")->fetchColumn()) { return []; }
    return $pdo->query('SELECT * FROM typecho_moments ORDER BY mid')->fetchAll(PDO::FETCH_ASSOC);
}
function qmState(string $key, ?array $record = null): array
{
    $response = qqCall('/state', ['key' => $key, 'operation' => $record === null ? 'read' : 'write', 'record' => $record ?? []]);
    if ($response['status'] !== 200) { throw new RuntimeException('Fixture state request failed: ' . $response['body']); }
    return $response['json']['record'] ?? [];
}
function qmCalls(): array
{
    global $directory;
    return array_map(static function ($line) { return json_decode($line, true); },
        is_file($directory . '/qq-api.jsonl') ? file($directory . '/qq-api.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []);
}
function qmReplies(): array
{
    return array_values(array_filter(qmCalls(), static function ($call) { return strpos($call['url'], '/messages') !== false; }));
}
function qmAck(array $response): bool
{
    return $response['status'] === 200 && $response['json'] === ['op' => 12, 'd' => 0];
}
function qmBind(array $admin, string $sender = 'private-owner'): array
{
    $response = qqAction($admin, 'qq-binding-start', ['target_type' => 'c2c']);
    if ($response['status'] !== 200 || empty($response['json']['data']['command'])) {
        throw new RuntimeException('Binding code creation failed: ' . $response['body']);
    }
    $event = qmEvent($response['json']['data']['command'], ['author' => ['user_openid' => $sender]]);
    $response = qmSend($event);
    if (!qmAck($response)) { throw new RuntimeException('Signed private binding failed: ' . $response['body']); }
    return $event;
}

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $env = getenv();
    $env['ENHANCEMENT_QQ_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_QQ_TEST_ROOT'] = $root;
    qqStartServer($address, dirname(__DIR__) . '/qq-notify/router.php', $env, $directory . '/typecho.log');
    $settings = ['qq_notify_provider' => 'official', 'qq_app_id' => '123456789',
        'qq_app_secret' => 'fixture-official-app-secret', 'qq_official_include_links' => '1', 'qq_moments_enabled' => '1'];
    $response = qqConfig($settings);
    qqCheck($response['status'] === 200, 'Only AppID and AppSecret enable official QQ publishing', $response);
    qmState('fixture_qq_api', ['enabled' => true]);

    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook');
    qqCheck($response['status'] === 405 && ($response['headers']['allow'] ?? '') === 'POST', 'Public QQ callback requires POST');
    $challenge = ['op' => 13, 'd' => ['plain_token' => 'fixture-platform-challenge', 'event_ts' => (string)time()]];
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', $challenge,
        ['X-Bot-Appid: ' . $settings['qq_app_id']]);
    qqCheck($response['status'] === 200 && ($response['json']['plain_token'] ?? '') === $challenge['d']['plain_token'],
        'Official initial unsigned challenge is accepted without website login', $response);
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', $challenge);
    qqCheck($response['status'] === 200 && ($response['json']['plain_token'] ?? '') === $challenge['d']['plain_token'],
        'Unsigned initial challenge also works when the platform omits AppID header', $response);
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', $challenge, ['X-Bot-Appid: 999999']);
    qqCheck($response['status'] === 403, 'Initial challenge rejects a mismatched supplied AppID');
    foreach ([['X-Signature-Timestamp: ' . time()], ['X-Signature-Ed25519: ' . str_repeat('a', 128)]] as $partialHeaders) {
        $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', $challenge, $partialHeaders);
        qqCheck($response['status'] === 403, 'Partially signed challenge cannot bypass signature verification');
    }
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST',
        ['op' => 13, 'd' => ['plain_token' => 'expired-challenge', 'event_ts' => (string)(time() - 600)]]);
    qqCheck($response['status'] === 403, 'Unsigned initial challenge must have a recent event timestamp');
    $response = qmSend($challenge, ['secret' => 'wrong-app-secret']);
    qqCheck($response['status'] === 403, 'Challenge with an invalid supplied signature is rejected');
    $structuredChallenge = ['op' => 13, 'd' => ['plain_token' => '{"op":0,"d":{}}', 'event_ts' => (string)time()]];
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', $structuredChallenge,
        ['X-Bot-Appid: ' . $settings['qq_app_id']]);
    qqCheck($response['status'] === 400, 'Structured challenge token cannot be used as an event signing oracle', $response);
    $response = qmSend($challenge);
    $challengeSignature = hex2bin($response['json']['signature'] ?? '');
    qqCheck($response['status'] === 200 && ($response['json']['plain_token'] ?? '') === $challenge['d']['plain_token']
        && strlen($challengeSignature) === SODIUM_CRYPTO_SIGN_BYTES
        && sodium_crypto_sign_verify_detached($challengeSignature, $challenge['d']['event_ts'] . $challenge['d']['plain_token'],
            sodium_crypto_sign_publickey(qmKeypair($settings['qq_app_secret']))),
        'Official challenge is signed correctly without website login', $response);
    qqCheck(($response['headers']['cache-control'] ?? '') === 'no-store' && qmCalls() === [], 'Challenge cannot be cached and does not call QQ API');

    $event = qmEvent();
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-webhook', 'POST', $event);
    qqCheck($response['status'] === 403 && qmRows() === [], 'Unsigned message cannot publish');
    foreach ([['secret' => 'wrong-app-secret'], ['app_id' => '999999'], ['timestamp' => (string)(time() - 600)],
        ['timestamp' => (string)(time() + 600)], ['timestamp' => 'invalid'], ['tamper' => true]] as $invalidSignature) {
        $response = qmSend($event, $invalidSignature);
        qqCheck($response['status'] === 403 && qmRows() === [], 'Wrong app, secret, old timestamp or tampered bytes cannot publish', $response);
    }
    foreach (['{', 'null', '[]', '{"op":0,"d":{}}', '{"op":"0","t":"C2C_MESSAGE_CREATE","d":{}}'] as $invalid) {
        $response = qmSend($invalid);
        qqCheck($response['status'] === 400 && qmRows() === [], 'Signed malformed official event is rejected', $response);
    }
    $response = qmSend(str_repeat('x', 65537));
    qqCheck($response['status'] === 413 && qmRows() === [], 'Oversized callback cannot publish');
    $response = qmSend($event);
    qqCheck(qmAck($response) && qmRows() === [] && qmReplies() === [], 'Valid but unbound sender is acknowledged without publishing or reply');

    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-binding-start', 'POST');
    qqCheck($response['status'] !== 200 && qmState('qq_binding_pending') === [], 'Anonymous caller cannot create a binding code');
    $admin = qqSession(1);
    $editor = qqSession(2);
    $response = fixtureRequest($base, '/action/enhancement-edit?do=qq-binding-start', 'POST', '', $admin['headers']);
    qqCheck($response['status'] !== 200 && qmState('qq_binding_pending') === [], 'Administrator binding requires CSRF token');
    $response = qqAction($editor, 'qq-binding-start', ['target_type' => 'c2c']);
    qqCheck($response['status'] !== 200 && qmState('qq_binding_pending') === [], 'Non-administrator cannot create binding even with own CSRF token');
    $response = qqAction($admin, 'qq-binding-start', ['target_type' => 'c2c'], 'GET');
    qqCheck($response['status'] === 405 && qmState('qq_binding_pending') === [], 'Binding state changes require POST');
    $response = qqAction($admin, 'qq-binding-start', ['target_type' => 'c2c']);
    $bindingCode = $response['json']['data'] ?? [];
    qqCheck($response['status'] === 200 && preg_match('/\A[A-F0-9]{16}\z/', $bindingCode['code'] ?? '') === 1
        && ($bindingCode['expires_at'] ?? 0) > time() && ($bindingCode['expires_at'] ?? 0) <= time() + 600,
        'Administrator receives a short-lived one-time private binding command', $response);
    $groupBind = ['op' => 0, 'id' => 'wrong-group-binding', 't' => 'GROUP_AT_MESSAGE_CREATE',
        'd' => ['content' => '<@!123456789> ' . ($bindingCode['command'] ?? ''), 'group_openid' => 'other-group']];
    $response = qmSend($groupBind);
    qqCheck(qmAck($response) && qmState('qq_binding') === [] && qmState('qq_binding_pending') !== [], 'Group cannot consume a private binding code');
    $bindEvent = qmEvent($bindingCode['command'] ?? '');
    $response = qmSend($bindEvent);
    $binding = qmState('qq_binding');
    qqCheck(qmAck($response) && ($binding['target_type'] ?? '') === 'c2c' && ($binding['target_id'] ?? '') === 'private-owner'
        && qmState('qq_binding_pending') === [], 'Signed private event completes and consumes the binding without a website session', $response);
    $bindEvent['id'] = 'reused-binding-code';
    $bindEvent['d']['author']['user_openid'] = 'another-owner';
    qmSend($bindEvent);
    qqCheck(qmState('qq_binding') === $binding, 'Reused binding code cannot change receiver');
    $response = qqAction($admin, 'qq-binding-status');
    $status = $response['json']['data'] ?? [];
    qqCheck($response['status'] === 200 && !empty($status['bound_user']) && !empty($status['last_challenge_at']) && !empty($status['last_event_at'])
        && strpos($response['body'], $settings['qq_app_secret']) === false && strpos($response['body'], 'private-owner') === false,
        'Admin status confirms connection without exposing AppSecret or OpenID', $response);

    $event = qmEvent();
    $response = qmSend($event);
    $rows = qmRows();
    $replies = qmReplies();
    qqCheck(qmAck($response) && count($rows) === 1 && $rows[0]['source'] === 'qq'
        && $rows[0]['content'] === 'QQ发布瞬间测试!~' && $rows[0]['status'] === 'public',
        'Original user command inserts a public QQ moment through anonymous HTTP callback', $response);
    qqCheck(count($replies) === 1 && ($replies[0]['payload']['msg_id'] ?? '') === $event['d']['id']
        && ($replies[0]['payload']['msg_seq'] ?? 0) === 1 && strpos($replies[0]['payload']['content'] ?? '', '已发布瞬间 #') === 0
        && strpos($replies[0]['url'], '/v2/users/private-owner/messages') !== false,
        'Publishing sends an official passive reply tied to the original private message', $replies);
    qqCheck(count(qmCalls()) === 2 && strpos(qmCalls()[0]['url'], '/app/getAppAccessToken') !== false,
        'Token and reply API calls use the isolated fixture transport');
    $event['id'] = 'redelivered-platform-event';
    $response = qmSend($event);
    qqCheck(qmAck($response) && count(qmRows()) === 1 && count(qmReplies()) === 1, 'Repeated QQ message ID cannot publish or reply twice');
    $event['d']['content'] = '瞬间 重投篡改正文';
    qmSend($event);
    qqCheck(count(qmRows()) === 1 && qmRows()[0]['content'] === 'QQ发布瞬间测试!~', 'Reusing original message ID cannot overwrite its published content');

    $privateText = "开心[微笑] 😄\n第二行";
    $private = qmEvent('私密瞬间：' . $privateText, ['timestamp' => gmdate('Y-m-d\TH:i:s\Z')]);
    $response = qmSend($private);
    $rows = qmRows();
    $replies = qmReplies();
    qqCheck(qmAck($response) && count($rows) === 2 && $rows[1]['status'] === 'private' && $rows[1]['content'] === $privateText
        && strpos(end($replies)['payload']['content'] ?? '', '已发布私密瞬间 #') === 0,
        'QQ expressions, Unicode emoji, multiline text and ISO message time publish privately', $response);
    $beforeCalls = count(qmReplies());
    foreach ([['author' => ['user_openid' => 'another-owner']], ['timestamp' => time() - 90000],
        ['timestamp' => null], ['timestamp' => 'not-a-date'], ['timestamp' => '2026-10-30T12:00:00'],
        ['id' => ''], ['content' => []], ['attachments' => 'invalid']] as $invalidMessage) {
        $response = qmSend(qmEvent('瞬间 不应发布', $invalidMessage));
        qqCheck(qmAck($response) && count(qmRows()) === 2 && count(qmReplies()) === $beforeCalls,
            'Unbound, historical or malformed message is acknowledged without publication', $response);
    }
    $group = qmEvent('瞬间 群聊不能发布');
    $group['t'] = 'GROUP_AT_MESSAGE_CREATE';
    $group['d']['group_openid'] = 'group-owner';
    $response = qmSend($group);
    qqCheck(qmAck($response) && count(qmRows()) === 2 && count(qmReplies()) === $beforeCalls, 'Group events cannot use private publishing authorization');
    $response = qmSend(qmEvent('你好'));
    qqCheck(qmAck($response) && count(qmRows()) === 2 && count(qmReplies()) === $beforeCalls, 'Unrelated private chat does not publish or reply');
    $response = qmSend(qmEvent('瞬间 <script>alert(1)</script>'));
    qqCheck(qmAck($response) && count(qmRows()) === 2 && count(qmReplies()) === $beforeCalls + 1, 'Business validation failure replies without inserting a moment');

    qmState('fixture_qq_api', ['enabled' => true, 'responses' => [['status' => 503, 'data' => ['code' => 503]]]]);
    $failedReplyEvent = qmEvent('瞬间 发布成功但首次回复失败');
    $response = qmSend($failedReplyEvent);
    qqCheck(qmAck($response) && count(qmRows()) === 3, 'Reply failure acknowledges QQ and preserves the completed publication');
    $replyRecords = qmState('qq_replies');
    $record = $replyRecords[hash('sha256', $settings['qq_app_id'] . "\n" . $failedReplyEvent['d']['id'])] ?? [];
    qqCheck(($record['status'] ?? '') === 'failed' && ($record['attempts'] ?? 0) === 1, 'Failed reply is persisted for retry');
    $response = qmSend($failedReplyEvent);
    qqCheck(qmAck($response) && count(qmRows()) === 3, 'QQ redelivery retries only the saved reply and does not republish');
    $replies = qmReplies();
    $lastTwo = array_slice($replies, -2);
    qqCheck(count($lastTwo) === 2 && $lastTwo[0]['payload'] === $lastTwo[1]['payload'], 'Reply retry reuses original msg_id, msg_seq and saved result');
    qmState('fixture_qq_api', ['enabled' => true, 'responses' => [['status' => 503, 'data' => ['code' => 503]]]]);
    $manualReplyEvent = qmEvent('瞬间 后台重试回复');
    qmSend($manualReplyEvent);
    $response = qqAction($admin, 'qq-retry-replies');
    qqCheck($response['status'] === 200 && ($response['json']['data']['sent'] ?? 0) === 1 && count(qmRows()) === 4,
        'Administrator can retry a failed reply without duplicate publication', $response);

    qqConfig(['qq_moments_enabled' => '0']);
    $beforeCalls = count(qmReplies());
    $response = qmSend(qmEvent('瞬间 关闭后'));
    qqCheck(qmAck($response) && count(qmRows()) === 4 && count(qmReplies()) === $beforeCalls, 'Disabling QQ moments stops publication and replies immediately');
    $oldSecret = $settings['qq_app_secret'];
    $settings['qq_app_secret'] = 'fixture-rotated-app-secret';
    qqConfig(['qq_moments_enabled' => '1', 'qq_app_secret' => $settings['qq_app_secret']]);
    $response = qmSend(qmEvent('瞬间 旧密钥'), ['secret' => $oldSecret]);
    qqCheck($response['status'] === 403 && count(qmRows()) === 4, 'AppSecret rotation invalidates old official signatures');
    $response = qmSend(qmEvent('瞬间 新密钥未重新绑定'));
    qqCheck(qmAck($response) && count(qmRows()) === 4, 'Rotated credentials invalidate old receiver binding');
    qmBind($admin);
    $response = qmSend(qmEvent('发瞬间 新凭据重新绑定后'));
    qqCheck(qmAck($response) && count(qmRows()) === 5, 'Rebinding under new AppSecret restores official publishing');
    qqAction($admin, 'qq-disconnect');
    $response = qmSend(qmEvent('瞬间 解除绑定后'));
    qqCheck(qmAck($response) && count(qmRows()) === 5 && qmState('qq_binding') === [], 'Disconnecting revokes publishing immediately');

    $response = qqAction($admin, 'qq-binding-start', ['target_type' => 'group']);
    $groupCode = $response['json']['data'] ?? [];
    qqCheck($response['status'] === 200 && ($groupCode['target_type'] ?? '') === 'group'
        && (qmState('qq_binding_pending')['target_type'] ?? '') === 'group',
        'Group binding button target_type creates a group notification code', $response);
    $response = qmSend(qmEvent($groupCode['command'] ?? ''));
    qqCheck(qmAck($response) && qmState('qq_binding') === [] && qmState('qq_binding_pending') !== [],
        'Private message cannot consume a group notification code');
    $groupBind['id'] = 'valid-group-binding';
    $groupBind['d']['content'] = '<@!123456789> ' . ($groupCode['command'] ?? '');
    $response = qmSend($groupBind);
    qqCheck(qmAck($response) && (qmState('qq_binding')['target_type'] ?? '') === 'group'
        && (qmState('qq_binding')['target_id'] ?? '') === 'other-group' && qmState('qq_binding_pending') === [],
        'Signed group mention completes only the group notification binding', $response);
    $response = qmSend(qmEvent('瞬间 群通知绑定不授权私聊'));
    qqCheck(qmAck($response) && count(qmRows()) === 5, 'Group notification binding does not grant private publishing access');
    qqAction($admin, 'qq-disconnect');

    qmBind($admin);
    $withoutAppId = qmEvent('瞬间 官方签名事件无需额外 AppID 头');
    $beforeCalls = count(qmReplies());
    $response = qmSend($withoutAppId, ['omit_app_id' => true]);
    $replies = qmReplies();
    qqCheck(qmAck($response) && count(qmRows()) === 6 && count($replies) === $beforeCalls + 1
        && (end($replies)['payload']['msg_id'] ?? '') === $withoutAppId['d']['id'],
        'Valid official signature without optional AppID header publishes once and replies to the original message', $response);
    qqAction($admin, 'qq-disconnect');
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
