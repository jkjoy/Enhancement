<?php
/** Isolated real Typecho/SQLite/HTTP checks for bot-authored moments. */
declare(strict_types=1);

define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

function botMoments(): array
{
    global $pdo;
    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='typecho_moments'")->fetchColumn()) { return []; }
    return $pdo->query('SELECT * FROM typecho_moments ORDER BY mid')->fetchAll(PDO::FETCH_ASSOC);
}
function botLastMoment(): array { $rows = botMoments(); return end($rows) ?: []; }
function botEvent(string $text, array $changes = [], string $channel = 'weixin'): array
{
    return array_replace(['id' => bin2hex(random_bytes(12)), 'sender' => 'weixin-owner',
        'conversation' => 'weixin-owner', 'text' => $text,
        'timestamp' => time(), 'account' => 'weixin-bot', 'conversation_type' => 'c2c'], $changes);
}
function botHandle(array $event, string $channel = 'weixin'): array
{
    return qqCall('/bot-moment', ['channel' => $channel, 'event' => $event]);
}
function botUnchanged(array $event, string $label, string $channel = 'weixin'): array
{
    $before = botMoments();
    $response = botHandle($event, $channel);
    qqCheck(botMoments() === $before, $label, $response);
    return $response;
}
function botState(string $key, array $record): array
{
    return qqCall('/state', ['key' => $key, 'operation' => 'write', 'record' => $record]);
}

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $env = getenv();
    $env['ENHANCEMENT_QQ_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_QQ_TEST_ROOT'] = $root;
    qqStartServer($address, dirname(__DIR__) . '/qq-notify/router.php', $env, $directory . '/typecho.log');

    $lease = qqCall('/state', ['key' => 'fixture_receiver', 'operation' => 'acquire', 'seconds' => 60])['json'] ?? [];
    $oldToken = $lease['token'] ?? '';
    qqCheck(is_string($oldToken) && preg_match('/\A[a-f0-9]{32}\z/', $oldToken) === 1
        && ($lease['record']['token'] ?? '') === $oldToken && ($lease['record']['expires_at'] ?? 0) > time(),
        'Receiver first startup acquires a database lease', $lease);
    $busy = qqCall('/state', ['key' => 'fixture_receiver', 'operation' => 'acquire', 'seconds' => 60])['json'] ?? [];
    qqCheck(($busy['token'] ?? null) === null && ($busy['record']['token'] ?? '') === $oldToken,
        'Busy receiver lease cannot be acquired by a second process', $busy);
    botState('fixture_receiver', ['token' => $oldToken, 'expires_at' => time() - 1]);
    $lease = qqCall('/state', ['key' => 'fixture_receiver', 'operation' => 'acquire', 'seconds' => 60])['json'] ?? [];
    $newToken = $lease['token'] ?? '';
    qqCheck(is_string($newToken) && $newToken !== '' && $newToken !== $oldToken && ($lease['record']['token'] ?? '') === $newToken,
        'Expired receiver lease can be acquired with a fresh owner token', $lease);
    $response = qqCall('/state', ['key' => 'fixture_receiver', 'operation' => 'release', 'token' => $oldToken]);
    qqCheck(($response['json']['record']['token'] ?? '') === $newToken, 'An expired owner cannot release the replacement receiver lease', $response);
    $response = qqCall('/state', ['key' => 'fixture_receiver', 'operation' => 'release', 'token' => $newToken]);
    qqCheck(($response['json']['record'] ?? null) === [], 'Current receiver owner releases its lease', $response);

    $momentsToken = 'fixture-moments-api-token';
    $response = qqConfig(['weixin_moments_enabled' => '1']);
    qqCheck($response['status'] === 400, 'Weixin publishing requires login and recipient binding', $response);
    botState('weixin_account', ['token' => 'fixture-private-weixin-token', 'account_id' => 'weixin-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    botState('weixin_sync', ['candidates' => ['weixin-owner'], 'context_tokens' => ['weixin-owner' => 'fixture-private-context']]);
    botState('weixin_recipient', ['user_id' => 'weixin-owner']);
    $response = qqConfig(['weixin_moments_enabled' => '0', 'moments_token' => $momentsToken]);
    qqCheck($response['status'] === 200, 'Bound Weixin configuration can save with publishing disabled', $response);
    botUnchanged(botEvent('瞬间 关闭时不可发布'), 'Disabled Weixin moment switch cannot create content');
    foreach (['invalid', ['1']] as $invalid) {
        $before = qqSettings();
        $response = qqConfig(['weixin_moments_enabled' => $invalid]);
        qqCheck($response['status'] === 400 && qqSettings() === $before, 'Invalid Weixin publishing switch leaves settings unchanged', $response);
    }
    $response = qqConfig(['weixin_moments_enabled' => '1']);
    qqCheck($response['status'] === 200, 'Bound Weixin can enable publishing independently of notifications', $response);
    $gate = qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record'] ?? [];
    qqCheck(!empty($gate['weixin']['identity']) && (int)($gate['weixin']['enabled_at'] ?? 0) > 0,
        'Enabling Weixin publishing persists the account identity and start time', $gate);
    $response = qqConfig(['moments_image_text' => '图片']);
    qqCheck($response['status'] === 200 && (qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record'] ?? []) === $gate,
        'Saving an unrelated setting preserves the Weixin activation gate');
    botUnchanged(botEvent('瞬间 陌生用户', ['sender' => 'stranger']), 'Weixin messages require the bound sender');
    botUnchanged(botEvent('瞬间 错误会话', ['conversation' => 'another-chat']), 'Weixin messages require the bound private conversation');
    botUnchanged(botEvent('瞬间 错误机器人', ['account' => 'another-bot']), 'Weixin events cannot cross bot accounts');
    botUnchanged(botEvent('瞬间 启用前旧消息', ['timestamp' => (int)$gate['weixin']['enabled_at'] - 1]), 'Messages sent before enabling Weixin publishing are ignored');
    $oldGate = $gate;
    $oldGate['weixin']['enabled_at'] = time() - 90000;
    botState('bot_moments_gate', $oldGate);
    botUnchanged(botEvent('瞬间 昨天旧消息', ['timestamp' => time() - 86401]), 'Messages older than 24 hours are ignored');
    botState('bot_moments_gate', $gate);
    botUnchanged(botEvent('瞬间 未来消息', ['timestamp' => time() + 600]), 'Messages with implausible future time cannot publish');
    botUnchanged(botEvent('瞬间 无有效时间', ['timestamp' => 'not-a-timestamp']), 'Messages with invalid time cannot publish');

    $event = botEvent("瞬间 今天的天空\n![傍晚](https://example.test/evening.jpg)");
    $response = botHandle($event);
    $rows = botMoments();
    qqCheck($response['status'] === 200 && count($rows) === 1 && $rows[0]['source'] === 'weixin'
        && $rows[0]['status'] === 'public' && strpos($rows[0]['content'], '今天的天空') !== false,
        'Authorized Weixin command writes a real public moment with the Weixin source', $response);
    qqCheck((json_decode($rows[0]['media'] ?? '', true)[0]['url'] ?? '') === 'https://example.test/evening.jpg',
        'Bot moment uses existing Markdown image extraction');
    $response = botUnchanged($event, 'Replayed Weixin message does not create a second moment');
    botUnchanged(array_replace($event, ['text' => '瞬间 相同消息 ID 的篡改内容']), 'Same Weixin message ID with different content cannot create or replace a moment');
    qqCheck($pdo->query('SELECT COUNT(*) FROM typecho_bot_moments_receipts')->fetchColumn() == 1,
        'One platform message owns one durable receipt');
    $firstReceipt = $pdo->query('SELECT request_id FROM typecho_bot_moments_receipts LIMIT 1')->fetchColumn();

    $private = botEvent("私密瞬间：仅自己可见\n第二行内容");
    $response = botHandle($private);
    $rows = botMoments();
    qqCheck(count($rows) === 2 && end($rows)['status'] === 'private' && strpos(end($rows)['content'], '第二行内容') !== false,
        'Private command with a colon preserves multiline content and private status', $response);
    $privateMid = (string)end($rows)['mid'];
    $response = botHandle(botEvent("发瞬间\n换行分隔命令"));
    qqCheck(count(botMoments()) === 3 && botLastMoment()['status'] === 'public', 'Publish alias accepts a newline command separator', $response);
    $publicApi = fixtureRequest($base, '/api/v1/memos?limit=100');
    $allApi = fixtureRequest($base, '/api/v1/memos?limit=100', 'GET', null, ['Authorization: Bearer ' . $momentsToken]);
    qqCheck($publicApi['status'] === 200 && count($publicApi['json'] ?? []) === 2 && !in_array($privateMid, array_column($publicApi['json'] ?? [], 'id'), true),
        'Anonymous moments API excludes bot-authored private moments', $publicApi);
    qqCheck($allApi['status'] === 200 && count($allApi['json'] ?? []) === 3 && in_array($privateMid, array_column($allApi['json'] ?? [], 'id'), true),
        'Authorized moments API includes private bot moments', $allApi);

    foreach (['瞬间 ', '瞬间', '普通聊天内容', '瞬间没有分隔符', '瞬间 <script>alert(1)</script>',
        '瞬间 ![危险](javascript:alert(1))', '瞬间 ' . str_repeat('a', 30000)] as $text) {
        botUnchanged(botEvent($text), 'Empty, unrelated, unsafe or oversized message cannot publish: ' . substr($text, 0, 70));
    }
    botUnchanged(botEvent('瞬间 缺少消息ID', ['id' => '']), 'Message without platform ID cannot publish');

    // A claimed receipt represents an interrupted attempt and must never be executed again.
    $pdo->prepare('UPDATE typecho_bot_moments_receipts SET result=? WHERE request_id=?')->execute(['', $firstReceipt]);
    botUnchanged($event, 'Claimed receipt without a final result is never re-executed');

    foreach (['瞬间 微信发布瞬间测试!~' => '微信发布瞬间测试!~', '瞬间 微信发布瞬间测试!~ 🙂 [微笑]' => '微信发布瞬间测试!~ 🙂 [微笑]'] as $text => $expected) {
        $before = count(botMoments());
        $response = botHandle(botEvent($text));
        qqCheck($response['status'] === 200 && !empty($response['json']['success']) && count(botMoments()) === $before + 1
            && botLastMoment()['content'] === $expected && botLastMoment()['source'] === 'weixin',
            'Weixin text and emoji content publish without changing their original text', $response);
    }
    botState('weixin_account', ['token' => 'fixture-private-weixin-token', 'account_id' => 'weixin-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => true]);
    botUnchanged(botEvent('瞬间 微信凭证失效'), 'Expired Weixin login cannot publish new moments');
    botState('weixin_account', ['token' => 'replacement-token', 'account_id' => 'weixin-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    botUnchanged(botEvent('瞬间 登录身份改变'), 'Changing the Weixin login token invalidates the activation gate');
    qqConfig(['weixin_moments_enabled' => '0']);
    botUnchanged(botEvent('瞬间 再次关闭'), 'Disabling Weixin publishing stops the previously allowed sender');

    $form = fixtureRequest($base, '/form');
    qqCheck($form['status'] === 200 && strpos($form['body'], 'name="weixin_moments_enabled"') !== false,
        'Real settings form exposes Weixin moment publishing');
    qqCheck(strpos($form['body'], 'fixture-private-weixin-token') === false && strpos($form['body'], 'fixture-private-context') === false,
        'Bot moment settings do not expose private login or context state');
    $log = file_get_contents($directory . '/typecho.log');
    qqCheck(preg_match('/PHP (?:Warning|Fatal error|Parse error|Deprecated|Notice)/i', $log) !== 1, 'Bot moment fixture log contains no PHP errors or warnings', $log);
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
