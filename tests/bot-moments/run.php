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
function botEvent(string $text, array $changes = [], string $channel = 'qq'): array
{
    return array_replace(['id' => bin2hex(random_bytes(12)), 'sender' => $channel === 'qq' ? 'owner-openid' : 'weixin-owner',
        'conversation' => $channel === 'qq' ? 'owner-openid' : 'weixin-owner', 'text' => $text,
        'timestamp' => time(), 'account' => $channel === 'qq' ? '123456789' : 'weixin-bot', 'conversation_type' => 'c2c'], $changes);
}
function botHandle(array $event, string $channel = 'qq'): array
{
    return qqCall('/bot-moment', ['channel' => $channel, 'event' => $event]);
}
function botUnchanged(array $event, string $label, string $channel = 'qq'): array
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

    $qq = ['qq_notify_provider' => 'direct', 'qq_app_id' => '123456789', 'qq_app_secret' => 'fixture-app-secret',
        'qq_target_type' => 'c2c', 'qq_target_id' => 'owner-openid', 'enable_comment_by_qq' => '0',
        'enable_link_submit_by_qq' => '0', 'qq_moments_enabled' => '0', 'moments_token' => 'fixture-moments-api-token'];
    $response = qqConfig($qq);
    qqCheck($response['status'] === 200, 'QQ credentials can save with bot moment publishing disabled', $response);
    botUnchanged(botEvent('瞬间 关闭时不可发布'), 'Disabled QQ moment switch cannot create content');

    foreach ([['qq_notify_provider' => 'none'], ['qq_target_id' => ''], ['qq_app_id' => ''],
        ['qq_target_type' => 'channel'], ['qq_moments_enabled' => ['1']]] as $invalid) {
        $before = qqSettings();
        $response = qqConfig(array_replace($qq, ['qq_moments_enabled' => '1'], $invalid));
        qqCheck($response['status'] === 400 && qqSettings() === $before,
            'Incomplete or unsupported QQ moment configuration leaves settings unchanged: ' . implode(',', array_keys($invalid)), $response);
    }
    $response = qqConfig(['qq_moments_enabled' => '1']);
    qqCheck($response['status'] === 200, 'Bound QQ private chat can enable bot moment publishing independently of notifications', $response);
    $gate = qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record'] ?? [];
    qqCheck(!empty($gate['qq']['identity']) && (int)($gate['qq']['enabled_at'] ?? 0) > 0,
        'Enabling QQ moment publishing persists a private identity and start time', $gate);
    $response = qqConfig(['moments_image_text' => '图片']);
    qqCheck($response['status'] === 200 && (qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record'] ?? []) === $gate,
        'Saving an unrelated setting preserves the bot moment activation gate');
    botUnchanged(botEvent('瞬间 陌生用户', ['sender' => 'stranger']), 'QQ private messages require the bound sender');
    botUnchanged(botEvent('瞬间 错误会话', ['conversation' => 'another-chat']), 'QQ private messages require the bound conversation');
    botUnchanged(botEvent('瞬间 群伪装私聊', ['conversation_type' => 'group']), 'Group event cannot use an identical OpenID to impersonate the bound QQ private chat');
    botUnchanged(botEvent('瞬间 错误机器人', ['account' => '987654321']), 'QQ moment events cannot cross bot accounts');
    botUnchanged(botEvent('瞬间 启用前旧消息', ['timestamp' => (int)$gate['qq']['enabled_at'] - 1]), 'Messages sent before enabling QQ moments are ignored');
    $oldGate = $gate;
    $oldGate['qq']['enabled_at'] = time() - 90000;
    botState('bot_moments_gate', $oldGate);
    botUnchanged(botEvent('瞬间 昨天旧消息', ['timestamp' => time() - 86401]), 'QQ messages older than 24 hours are ignored even with an older activation gate');
    botState('bot_moments_gate', $gate);
    botUnchanged(botEvent('瞬间 未来消息', ['timestamp' => time() + 600]), 'QQ messages with an implausible future time cannot publish');
    botUnchanged(botEvent('瞬间 无有效时间', ['timestamp' => 'not-a-timestamp']), 'QQ messages with invalid time cannot publish');

    $event = botEvent("瞬间 今天的天空\n![傍晚](https://example.test/evening.jpg)");
    $response = botHandle($event);
    $rows = botMoments();
    qqCheck($response['status'] === 200 && count($rows) === 1 && $rows[0]['source'] === 'qq'
        && $rows[0]['status'] === 'public' && strpos($rows[0]['content'], '今天的天空') !== false,
        'Authorized QQ command writes a real public moment with the QQ source', $response);
    qqCheck((json_decode($rows[0]['media'] ?? '', true)[0]['url'] ?? '') === 'https://example.test/evening.jpg',
        'Bot moment uses existing Markdown image extraction');
    $response = botUnchanged($event, 'Replayed QQ message does not create a second moment');
    botUnchanged(array_replace($event, ['text' => '瞬间 相同消息 ID 的篡改内容']), 'Same QQ message ID with different content cannot create or replace a moment');
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
    $allApi = fixtureRequest($base, '/api/v1/memos?limit=100', 'GET', null, ['Authorization: Bearer ' . $qq['moments_token']]);
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

    $response = qqConfig(['qq_target_type' => 'group', 'qq_target_id' => 'group-openid', 'qq_moments_allowed_senders' => '']);
    qqCheck($response['status'] === 400 && qqSettings()['qq_target_type'] === 'c2c', 'QQ group publishing requires a separate member whitelist', $response);
    $response = qqConfig(['qq_target_type' => 'group', 'qq_target_id' => 'group-openid', 'qq_moments_allowed_senders' => "member-one\nmember-two"]);
    qqCheck($response['status'] === 200, 'Bound QQ group and explicit member OpenIDs can enable publishing', $response);
    foreach ([['sender' => 'outsider', 'conversation' => 'group-openid'], ['sender' => 'member-one', 'conversation' => 'another-group']] as $eventChanges) {
        $eventChanges['conversation_type'] = 'group';
        botUnchanged(botEvent('瞬间 不允许的群发布', $eventChanges), 'QQ group publishing checks both conversation and sender whitelist');
    }
    botUnchanged(botEvent('瞬间 私聊伪装群', ['sender' => 'member-one', 'conversation' => 'group-openid']),
        'Private event cannot use an identical OpenID to impersonate the bound QQ group');
    $before = count(botMoments());
    $response = botHandle(botEvent('瞬间 群成员发布', ['sender' => 'member-one', 'conversation' => 'group-openid', 'conversation_type' => 'group']));
    qqCheck(count(botMoments()) === $before + 1 && botLastMoment()['source'] === 'qq', 'Whitelisted member can publish from the bound QQ group', $response);
    $response = qqConfig(['qq_moments_enabled' => '0']);
    botUnchanged(botEvent('瞬间 再次关闭', ['sender' => 'member-one', 'conversation' => 'group-openid', 'conversation_type' => 'group']), 'Disabling QQ publishing stops previously allowed group members');

    $beforeSettings = qqSettings();
    $response = qqConfig(['weixin_moments_enabled' => '1']);
    qqCheck($response['status'] === 400 && qqSettings() === $beforeSettings, 'Weixin moment publishing cannot enable without login and binding', $response);
    botState('weixin_account', ['token' => 'fixture-private-weixin-token', 'account_id' => 'weixin-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    botState('weixin_sync', ['candidates' => ['weixin-owner'], 'context_tokens' => ['weixin-owner' => 'fixture-private-context']]);
    botState('weixin_recipient', ['user_id' => 'weixin-owner']);
    $response = qqConfig(['weixin_moments_enabled' => '1']);
    qqCheck($response['status'] === 200, 'Bound Weixin recipient can enable publishing independently of notification switches', $response);
    $gate = qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record'] ?? [];
    qqCheck(!empty($gate['weixin']['identity']) && (int)($gate['weixin']['enabled_at'] ?? 0) > 0, 'Weixin enablement persists its own account identity and time gate', $gate);
    botUnchanged(botEvent('瞬间 错误微信接收者', ['sender' => 'another-user'], 'weixin'), 'Weixin publishing requires the bound sender', 'weixin');
    botUnchanged(botEvent('瞬间 错误微信账号', ['account' => 'another-bot'], 'weixin'), 'Weixin publishing requires the connected bot account', 'weixin');
    botUnchanged(botEvent('瞬间 旧微信消息', ['timestamp' => (int)$gate['weixin']['enabled_at'] - 1], 'weixin'), 'Weixin messages predating switch enablement cannot publish', 'weixin');
    $before = count(botMoments());
    $weixinEvent = botEvent('瞬间 微信文字发布', [], 'weixin');
    $weixinEvent['id'] = $event['id'];
    $response = botHandle($weixinEvent, 'weixin');
    qqCheck(count(botMoments()) === $before + 1 && botLastMoment()['source'] === 'weixin', 'Bound Weixin account can publish with an ID already used by the QQ platform', $response);
    botUnchanged($weixinEvent, 'Replayed Weixin message cannot publish twice', 'weixin');
    botState('weixin_account', ['token' => 'fixture-private-weixin-token', 'account_id' => 'weixin-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => true]);
    botUnchanged(botEvent('瞬间 微信凭证失效', [], 'weixin'), 'Expired Weixin login cannot publish new moments', 'weixin');
    botState('weixin_account', ['token' => 'fixture-private-weixin-token', 'account_id' => 'another-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    botUnchanged(botEvent('瞬间 登录身份改变', ['account' => 'another-bot'], 'weixin'), 'Changing Weixin bot identity invalidates the saved enablement gate', 'weixin');

    $form = fixtureRequest($base, '/form');
    qqCheck($form['status'] === 200 && strpos($form['body'], 'name="qq_moments_enabled"') !== false
        && strpos($form['body'], 'name="qq_moments_allowed_senders"') !== false && strpos($form['body'], 'name="weixin_moments_enabled"') !== false,
        'Real settings form exposes all bot moment controls');
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
