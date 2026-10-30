<?php
/** Official QQ service publishing with disposable Typecho/SQLite and injected media receiving. */
declare(strict_types=1);

define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

function officialMoments(): array
{
    global $pdo;
    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='typecho_moments'")->fetchColumn()) { return []; }
    return $pdo->query('SELECT * FROM typecho_moments ORDER BY mid')->fetchAll(PDO::FETCH_ASSOC);
}
function officialEvent(string $text, array $changes = []): array
{
    return array_replace(['id' => bin2hex(random_bytes(12)), 'account' => '123456789', 'sender' => 'owner-openid',
        'conversation' => 'owner-openid', 'conversation_type' => 'private', 'aliases' => ['owner'],
        'text' => $text, 'timestamp' => time()], $changes);
}
function officialCall(array $event, array $duringReceive = []): array
{
    return qqCall('/bot-moment-media', array_merge(['channel' => 'qq', 'event' => $event], $duringReceive));
}
function officialIgnored(array $event, string $label): void
{
    $before = officialMoments();
    $response = officialCall($event);
    qqCheck($response['status'] === 200 && $response['body'] === 'null' && officialMoments() === $before, $label, $response);
}
function officialState(string $key, array $record): void
{
    qqCall('/state', ['key' => $key, 'operation' => 'write', 'record' => $record]);
}
function officialReceives(): array
{
    global $directory;
    return array_map(static function ($line) { return json_decode($line, true); },
        is_file($directory . '/media-receive.jsonl') ? file($directory . '/media-receive.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []);
}

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $env = getenv();
    $env['ENHANCEMENT_QQ_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_QQ_TEST_ROOT'] = $root;
    qqStartServer($address, dirname(__DIR__) . '/qq-notify/router.php', $env, $directory . '/typecho.log');
    $config = ['qq_notify_provider' => 'official', 'qq_official_url' => 'https://bot.example.test/api',
        'qq_official_token' => str_repeat('a', 48), 'qq_official_targets' => "owner\nwriter",
        'qq_moments_secret' => str_repeat('b', 48), 'qq_moments_targets' => "owner\nwriter", 'qq_moments_enabled' => '0'];
    $response = qqConfig($config);
    qqCheck($response['status'] === 200, 'Official QQ service configuration saves with publishing disabled', $response);
    officialIgnored(officialEvent('瞬间 QQ发布瞬间测试!~'), 'Disabled QQ publishing ignores authenticated service messages');
    foreach ([['qq_notify_provider' => 'none'], ['qq_official_url' => 'invalid-url'], ['qq_official_token' => 'short'],
        ['qq_moments_secret' => 'short'], ['qq_moments_secret' => $config['qq_official_token']], ['qq_moments_targets' => ''],
        ['qq_moments_targets' => "owner\n\nwriter"], ['qq_moments_targets' => 'invalid alias'],
        ['qq_moments_secret' => ['invalid']], ['qq_moments_targets' => ['owner']], ['qq_moments_enabled' => '2']] as $invalid) {
        $before = qqSettings();
        $response = qqConfig(array_replace($config, ['qq_moments_enabled' => '1'], $invalid));
        qqCheck($response['status'] === 400 && qqSettings() === $before,
            'Invalid QQ publishing configuration is rejected: ' . implode(',', array_keys($invalid)), $response);
    }
    $response = qqConfig(['qq_moments_enabled' => '1']);
    qqCheck($response['status'] === 200, 'Official QQ service can enable private moment publishing', $response);
    $gate = qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record'] ?? [];
    qqCheck(!empty($gate['qq']['identity']) && !empty($gate['qq']['activation_id']), 'QQ service publishing stores an activation identity', $gate);
    foreach ([['aliases' => []], ['aliases' => ['unbound']], ['aliases' => [['owner']]], ['aliases' => ['key' => 'owner']],
        ['conversation' => 'another-openid'], ['conversation_type' => 'group'], ['conversation_type' => 'c2c'], ['account' => '']] as $changes) {
        officialIgnored(officialEvent('瞬间 未授权内容', $changes), 'QQ private publishing checks bound aliases, account and conversation: ' . implode(',', array_keys($changes)));
    }
    officialIgnored(officialEvent('瞬间 启用前消息', ['timestamp' => (int)$gate['qq']['enabled_at'] - 1]), 'QQ ignores messages sent before activation');
    officialIgnored(officialEvent('瞬间 无效时间', ['timestamp' => 'invalid']), 'QQ ignores messages with invalid send time');
    officialIgnored(officialEvent('瞬间 未来消息', ['timestamp' => time() + 600]), 'QQ ignores messages with implausible future time');
    $oldGate = $gate;
    $oldGate['qq']['enabled_at'] = time() - 90000;
    officialState('bot_moments_gate', $oldGate);
    officialIgnored(officialEvent('瞬间 历史消息', ['timestamp' => time() - 86401]), 'QQ ignores messages older than 24 hours');
    officialState('bot_moments_gate', $gate);

    $event = officialEvent('瞬间 QQ发布瞬间测试!~');
    $response = officialCall($event);
    $rows = officialMoments();
    qqCheck(!empty($response['json']['success']) && count($rows) === 1 && $rows[0]['source'] === 'qq'
        && $rows[0]['status'] === 'public' && $rows[0]['content'] === 'QQ发布瞬间测试!~',
        'A bound private alias publishes the reported QQ text command', $response);
    $response = officialCall($event);
    qqCheck(!empty($response['json']['replayed']) && count(officialMoments()) === 1, 'QQ service message replay cannot publish twice', $response);
    $response = officialCall(array_replace($event, ['text' => '瞬间 篡改内容']));
    qqCheck(empty($response['json']['success']) && !empty($response['json']['replayed']) && count(officialMoments()) === 1,
        'QQ service message IDs cannot be reused with different content', $response);
    foreach (['瞬间 今天很好🙂 [微笑]' => ['public', '今天很好🙂 [微笑]'], '私密瞬间：私人记录' => ['private', '私人记录']] as $text => [$visibility, $content]) {
        $response = officialCall(officialEvent($text, ['aliases' => ['unrelated', 'writer']]));
        $rows = officialMoments();
        $last = end($rows);
        qqCheck(!empty($response['json']['success']) && $last['status'] === $visibility && $last['content'] === $content
            && $last['source'] === 'qq', 'QQ alias intersection preserves text, emoji and private visibility', $response);
    }

    foreach (['qq_official_url' => 'https://replacement.example.test/api', 'qq_official_token' => str_repeat('c', 48),
        'qq_moments_secret' => str_repeat('d', 48), 'qq_moments_targets' => 'writer'] as $key => $value) {
        $beforeGate = qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record'] ?? [];
        $response = qqConfig([$key => $value]);
        $afterGate = qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record'] ?? [];
        qqCheck($response['status'] === 200 && $beforeGate['qq']['identity'] !== $afterGate['qq']['identity'],
            'Changing service authorization replaces QQ activation: ' . $key, $response);
        officialState('bot_moments_gate', $beforeGate);
        officialIgnored(officialEvent('瞬间 旧激活不可用', ['aliases' => ['owner', 'writer']]), 'QQ configuration change invalidates old activation: ' . $key);
        officialState('bot_moments_gate', $afterGate);
        qqConfig([$key => $config[$key]]);
    }

    $qqGate = qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record']['qq'] ?? [];
    officialState('weixin_account', ['token' => 'fixture-weixin-token', 'account_id' => 'weixin-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    officialState('weixin_sync', ['candidates' => ['weixin-owner'], 'context_tokens' => ['weixin-owner' => 'fixture-context']]);
    officialState('weixin_recipient', ['user_id' => 'weixin-owner']);
    $response = qqConfig(['weixin_moments_enabled' => '1']);
    $bothGates = qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record'] ?? [];
    qqCheck($response['status'] === 200 && ($bothGates['qq'] ?? null) === $qqGate && !empty($bothGates['weixin']['identity']),
        'Enabling Weixin publishing preserves the official QQ activation', $response);
    $weixinEvent = ['id' => $event['id'], 'account' => 'weixin-bot', 'sender' => 'weixin-owner', 'conversation' => 'weixin-owner',
        'text' => '瞬间 微信共享消息编号', 'timestamp' => time()];
    $before = count(officialMoments());
    $response = qqCall('/bot-moment', ['channel' => 'weixin', 'event' => $weixinEvent]);
    $rows = officialMoments();
    $last = end($rows);
    qqCheck(!empty($response['json']['success']) && count($rows) === $before + 1 && $last['source'] === 'weixin',
        'Weixin remains functional with an incoming message ID already used by QQ', $response);
    qqCall('/bot-moment', ['channel' => 'weixin', 'event' => $weixinEvent]);
    qqCheck(count(officialMoments()) === $before + 1, 'Weixin replay protection remains independent of QQ receipts');

    $image = ['url' => '//gchat.qpic.cn/fixture/image-1.png', 'content_type' => 'image/png'];
    officialIgnored(officialEvent('', ['attachments' => [$image]]), 'QQ image-only messages need an existing draft');
    $before = count(officialMoments());
    $response = officialCall(officialEvent('私密图文瞬间 私密相册', ['attachments' => [$image]]));
    qqCheck(($response['json']['kind'] ?? '') === 'draft' && count(officialMoments()) === $before && officialReceives() === [],
        'QQ private image commands create drafts without downloading', $response);
    $second = officialEvent('', ['attachments' => [['url' => 'https://multimedia.nt.qq.com.cn/download?fixture=2']]]);
    officialCall($second);
    officialCall($second);
    $response = officialCall(officialEvent('发布瞬间', ['sender' => 'another-owner', 'conversation' => 'another-owner']));
    qqCheck(empty($response['json']['success']) && count(officialMoments()) === $before && officialReceives() === [],
        'A separate QQ private sender cannot publish another sender draft', $response);
    $finish = officialEvent('发布瞬间');
    $response = officialCall($finish);
    $rows = officialMoments();
    $last = end($rows);
    $received = officialReceives();
    qqCheck(!empty($response['json']['success']) && count($rows) === $before + 1 && $last['status'] === 'private'
        && $last['content'] === '私密相册' && count(json_decode($last['media'], true)) === 2 && count($received) === 1
        && $received[0]['attachments'][0]['url'] === 'https://gchat.qpic.cn/fixture/image-1.png',
        'QQ private drafts publish all images once and canonicalize official protocol-relative URLs', $response);
    officialCall($finish);
    qqCheck(count(officialMoments()) === $before + 1 && count(officialReceives()) === 1,
        'Replayed QQ final commands cannot publish or receive images twice');
    $response = officialCall(officialEvent('瞬间 同条图片', ['attachments' => [$image]]));
    qqCheck(!empty($response['json']['success']) && count(officialMoments()) === $before + 2,
        'QQ text and image in one command publish directly', $response);

    officialCall(officialEvent('图文瞬间 下载时取消', ['attachments' => [$image]]));
    $response = officialCall(officialEvent('发布瞬间'), ['cancel_during_receive' => true]);
    qqCheck(empty($response['json']['success']) && count(officialMoments()) === $before + 2,
        'Cancelling QQ draft during receiving prevents publication', $response);
    officialCall(officialEvent('图文瞬间 授权更新前', ['attachments' => [$image]]));
    $downloads = count(officialReceives());
    qqConfig(['qq_moments_secret' => str_repeat('e', 48)]);
    $response = officialCall(officialEvent('发布瞬间'));
    qqCheck(empty($response['json']['success']) && count(officialMoments()) === $before + 2 && count(officialReceives()) === $downloads,
        'Changing the QQ shared secret invalidates pending drafts', $response);
    officialCall(officialEvent('取消瞬间'));
    $response = officialCall(officialEvent('瞬间 下载时撤销权限', ['attachments' => [$image]]),
        ['settings_during_receive' => ['qq_moments_enabled' => '0']]);
    qqCheck(empty($response['json']['success']) && count(officialMoments()) === $before + 2,
        'Disabling QQ publishing during direct image receiving prevents publication', $response);
    $log = file_get_contents($directory . '/typecho.log');
    qqCheck(preg_match('/PHP (?:Warning|Fatal error|Parse error|Deprecated|Notice)/i', $log) !== 1,
        'Official QQ moment fixture contains no PHP errors or warnings', $log);
} catch (Throwable $exception) {
    qqCheck(false, 'Official QQ moment runner exception', $exception->getMessage());
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
echo $passed . ' official QQ moment assertions passed; ' . count($failures) . " failed.\n";
if ($failures) { echo 'Fixture logs retained: ' . $directory . "\n"; }
exit($failures ? 1 : 0);
