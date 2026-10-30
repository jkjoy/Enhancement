<?php
/** Real Typecho/SQLite draft tests; media receiving is injected and never uses the network. */
declare(strict_types=1);

define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

function draftRows(): array
{
    global $pdo;
    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='typecho_bot_moment_drafts'")->fetchColumn()) { return []; }
    $rows = $pdo->query('SELECT * FROM typecho_bot_moment_drafts ORDER BY scope_id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) { $row['draft'] = json_decode($row['value'], true); }
    return array_values(array_filter($rows, static function ($row) { return !empty($row['draft']); }));
}
function draftMoments(): array
{
    global $pdo;
    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='typecho_moments'")->fetchColumn()) { return []; }
    return $pdo->query('SELECT * FROM typecho_moments ORDER BY mid')->fetchAll(PDO::FETCH_ASSOC);
}
function draftReceives(): array
{
    global $directory;
    return array_map(static function ($line) { return json_decode($line, true); },
        is_file($directory . '/media-receive.jsonl') ? file($directory . '/media-receive.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []);
}
function draftAttachment(int $id): array
{
    return ['media' => ['full_url' => 'https://novac2c.cdn.weixin.qq.com/c2c/download?fixture=' . $id]];
}
function draftEvent(string $text, array $changes = [], string $channel = 'weixin'): array
{
    return array_replace(['id' => bin2hex(random_bytes(12)), 'sender' => 'weixin-owner',
        'conversation' => 'weixin-owner', 'conversation_type' => 'c2c', 'text' => $text,
        'timestamp' => time(), 'account' => 'weixin-bot', 'attachments' => []], $changes);
}
function draftCall(array $event, string $channel = 'weixin', bool $fail = false, array $duringReceive = []): array
{
    return qqCall('/bot-moment-media', array_merge(['channel' => $channel, 'event' => $event, 'receive_failure' => $fail], $duringReceive));
}
function draftCommand(string $text, array $changes = [], string $channel = 'weixin'): array
{
    return draftCall(draftEvent($text, $changes, $channel), $channel);
}
function draftMutate(array $changes): void
{
    global $pdo;
    $rows = draftRows();
    if (count($rows) !== 1) { throw new RuntimeException('Expected one fixture draft.'); }
    $value = array_replace($rows[0]['draft'], $changes);
    $pdo->prepare('UPDATE typecho_bot_moment_drafts SET value=? WHERE scope_id=?')->execute([json_encode($value), $rows[0]['scope_id']]);
}
function draftPrivateState(string $key, array $record): void
{
    qqCall('/state', ['key' => $key, 'operation' => 'write', 'record' => $record]);
}

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $env = getenv();
    $env['ENHANCEMENT_QQ_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_QQ_TEST_ROOT'] = $root;
    qqStartServer($address, dirname(__DIR__) . '/qq-notify/router.php', $env, $directory . '/typecho.log');
    draftPrivateState('weixin_account', ['token' => 'fixture-weixin-token', 'account_id' => 'weixin-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    draftPrivateState('weixin_sync', ['candidates' => ['weixin-owner'], 'context_tokens' => ['weixin-owner' => 'fixture-context']]);
    draftPrivateState('weixin_recipient', ['user_id' => 'weixin-owner']);
    $response = qqConfig(['weixin_moments_enabled' => '1', 'moments_token' => 'fixture-api-token']);
    qqCheck($response['status'] === 200, 'Fixture enables authorized Weixin moment publishing', $response);

    draftCommand('', ['attachments' => [draftAttachment(1)]]);
    qqCheck(draftRows() === [] && draftMoments() === [] && draftReceives() === [], 'Image-only message without a draft is ignored');
    $response = draftCommand('图文瞬间 周末照片');
    $rows = draftRows();
    qqCheck(count($rows) === 1 && ($rows[0]['draft']['body'] ?? '') === '周末照片'
        && ($rows[0]['draft']['state'] ?? '') === 'pending' && draftMoments() === [] && draftReceives() === [],
        'Start command saves a pending draft without downloading or publishing', $response);
    draftCommand('', ['attachments' => [draftAttachment(1)]]);
    $secondImage = draftEvent('', ['attachments' => [draftAttachment(2)]]);
    draftCall($secondImage);
    qqCheck(count(draftRows()[0]['draft']['attachments'] ?? []) === 2 && draftReceives() === [] && draftMoments() === [],
        'Separate image messages collect platform metadata without media downloads');
    $before = draftRows();
    draftCall($secondImage);
    qqCheck(draftRows() === $before, 'Replayed image event does not duplicate draft attachments');
    $response = draftCall(array_replace($secondImage, ['attachments' => [draftAttachment(3)]]));
    qqCheck(draftRows() === $before && empty($response['json']['success']), 'Same message ID with changed attachment is a conflict', $response);
    $publish = draftEvent('发布瞬间');
    $response = draftCall($publish);
    $moments = draftMoments();
    qqCheck(count($moments) === 1 && count(json_decode($moments[0]['media'], true)) === 2 && $moments[0]['content'] === '周末照片'
        && $moments[0]['source'] === 'weixin' && $moments[0]['status'] === 'public' && draftRows() === [],
        'Final publish creates one moment containing the draft text and both stored photos', $response);
    qqCheck(count(draftReceives()) === 1 && count(draftReceives()[0]['attachments']) === 2, 'Final publish invokes image receiving once for the complete collection');
    draftCall($publish);
    qqCheck(count(draftMoments()) === 1 && count(draftReceives()) === 1, 'Replayed final command cannot download or publish again');

    $response = draftCommand('瞬间 同条图文', ['attachments' => [draftAttachment(4)]]);
    qqCheck(count(draftMoments()) === 2 && count(draftReceives()) === 2 && draftRows() === [],
        'Existing moment command and an image in the same message publish directly', $response);
    draftCommand('图文瞬间 即将取消');
    draftCommand('', ['attachments' => [draftAttachment(5)]]);
    $downloads = count(draftReceives());
    draftCommand('取消瞬间');
    draftCommand('发布瞬间');
    qqCheck(draftRows() === [] && count(draftReceives()) === $downloads && count(draftMoments()) === 2,
        'Cancel removes the draft without downloading, and later publish finds nothing');

    draftCommand('图文瞬间 旧草稿');
    draftCommand('', ['attachments' => [draftAttachment(6)]]);
    draftCommand('图文瞬间 新草稿');
    qqCheck(count(draftRows()) === 1 && (draftRows()[0]['draft']['body'] ?? '') === '新草稿'
        && empty(draftRows()[0]['draft']['attachments']), 'A new start replaces the pending draft and its old attachments');
    draftCommand('', ['attachments' => [draftAttachment(7)]]);
    draftMutate(['created' => time() - 901]);
    draftCommand('发布瞬间');
    qqCheck(count(draftReceives()) === $downloads && count(draftMoments()) === 2, 'Expired draft cannot download or publish');
    draftCommand('取消瞬间');

    draftCommand('图文瞬间');
    draftCommand('', ['attachments' => array_map('draftAttachment', range(10, 18))]);
    qqCheck(count(draftRows()[0]['draft']['attachments'] ?? []) === 9, 'A draft accepts nine images');
    draftCommand('', ['attachments' => [draftAttachment(19)]]);
    qqCheck(count(draftRows()[0]['draft']['attachments'] ?? []) === 9 && count(draftReceives()) === $downloads,
        'A tenth image is rejected before receiving or storing media');
    draftCommand('取消瞬间');

    draftCommand('图文瞬间 下载失败可重试');
    draftCommand('', ['attachments' => [draftAttachment(20)]]);
    $failedPublish = draftEvent('发布瞬间');
    $response = draftCall($failedPublish, 'weixin', true);
    qqCheck(($response['json']['success'] ?? true) === false && (draftRows()[0]['draft']['state'] ?? '') === 'pending'
        && count(draftMoments()) === 2, 'Known media receive failure restores the draft to pending without creating a moment', $response);
    $downloads = count(draftReceives());
    draftCall($failedPublish);
    qqCheck(count(draftReceives()) === $downloads && count(draftMoments()) === 2, 'Redelivering failed final event does not silently repeat downloads');
    $response = draftCommand('发布瞬间');
    qqCheck(count(draftMoments()) === 3 && draftRows() === [], 'A new final command may retry a pending draft after known download failure', $response);

    draftCommand('图文瞬间 不确定发布');
    draftCommand('', ['attachments' => [draftAttachment(21)]]);
    draftMutate(['state' => 'uncertain']);
    $downloads = count(draftReceives());
    draftCommand('发布瞬间');
    draftCommand('图文瞬间 不可覆盖正在发布');
    qqCheck(count(draftReceives()) === $downloads && count(draftMoments()) === 3
        && (draftRows()[0]['draft']['body'] ?? '') === '不确定发布', 'Uncertain publishing state cannot be replayed or replaced by a new draft');
    draftCommand('取消瞬间');
    qqCheck(draftRows() === [], 'Explicit cancellation clears an uncertain draft after the operator checks its result');

    draftCommand('图文瞬间 授权改变前');
    draftCommand('', ['attachments' => [draftAttachment(22)]]);
    draftPrivateState('weixin_account', ['token' => 'replacement-fixture-token', 'account_id' => 'weixin-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    qqConfig(['weixin_moments_enabled' => '1']);
    draftCommand('发布瞬间');
    qqCheck(count(draftReceives()) === $downloads && count(draftMoments()) === 3, 'Changing bot credentials invalidates a pending draft');
    draftCommand('取消瞬间');
    draftPrivateState('weixin_account', ['token' => 'fixture-weixin-token', 'account_id' => 'weixin-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    draftPrivateState('weixin_sync', ['candidates' => ['weixin-owner'], 'context_tokens' => ['weixin-owner' => 'fixture-context']]);
    draftPrivateState('weixin_recipient', ['user_id' => 'weixin-owner']);
    qqCheck(qqConfig(['weixin_moments_enabled' => '1'])['status'] === 200, 'Fixture enables the bound Weixin account for private image drafts');
    draftCommand('私密图文瞬间 私密正文', [], 'weixin');
    $weixinImage = ['media' => ['encrypt_query_param' => 'fixture-query', 'aes_key' => base64_encode(str_repeat('a', 16))]];
    draftCommand('', ['attachments' => [$weixinImage]], 'weixin');
    $response = draftCommand('发布瞬间', [], 'weixin');
    $moments = draftMoments();
    $last = end($moments);
    qqCheck(count($moments) === 4 && $last['source'] === 'weixin' && $last['status'] === 'private' && $last['content'] === '私密正文',
        'Weixin private image collection preserves source and private moment status', $response);
    qqCheck((json_decode($last['media'], true)[0]['url'] ?? '') === 'https://fixture.invalid/photo-1.png',
        'Private moment retains the storage URL without inventing media access authentication');
    $public = fixtureRequest($base, '/api/v1/memos?limit=100')['json'] ?? [];
    $authenticated = fixtureRequest($base, '/api/v1/memos?limit=100', 'GET', null, ['Authorization: Bearer fixture-api-token'])['json'] ?? [];
    qqCheck(!in_array((string)$last['mid'], array_column($public, 'id'), true)
        && in_array((string)$last['mid'], array_column($authenticated, 'id'), true), 'Private image draft content is isolated from the public moments API');

    draftCommand('图文瞬间', [], 'weixin');
    draftCommand('', ['attachments' => [$weixinImage]], 'weixin');
    $response = draftCommand('发布瞬间', [], 'weixin');
    $moments = draftMoments();
    $last = end($moments);
    qqCheck(count($moments) === 5 && $last['content'] === '图片' && count(json_decode($last['media'], true)) === 1,
        'An empty draft body publishes an image moment with the normal image placeholder', $response);

    draftCommand('图文瞬间 重登前草稿', [], 'weixin');
    draftCommand('', ['attachments' => [$weixinImage]], 'weixin');
    $downloads = count(draftReceives());
    draftPrivateState('weixin_account', ['token' => 'new-login-weixin-token', 'account_id' => 'weixin-bot',
        'owner_id' => 'weixin-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    qqConfig(['weixin_moments_enabled' => '1']);
    draftCommand('发布瞬间', [], 'weixin');
    qqCheck(count(draftMoments()) === 5 && count(draftReceives()) === $downloads,
        'Reconnecting the same Weixin account with a new login token invalidates its old draft');
    draftCommand('取消瞬间', [], 'weixin');

    draftCommand('图文瞬间 数据库结果待核对');
    draftCommand('', ['attachments' => [draftAttachment(30)]]);
    $pdo->exec("CREATE TRIGGER fixture_uncertain_moment BEFORE INSERT ON typecho_moments BEGIN SELECT RAISE(FAIL, 'fixture interrupted moment insert'); END");
    $uncertain = draftEvent('发布瞬间');
    $response = draftCall($uncertain);
    qqCheck(($response['json']['success'] ?? true) === false && (draftRows()[0]['draft']['state'] ?? '') === 'uncertain'
        && count(draftMoments()) === 5, 'A database failure after media receiving keeps the draft claimed for review', $response);
    $pdo->exec('DROP TRIGGER fixture_uncertain_moment');
    $downloads = count(draftReceives());
    draftCall($uncertain);
    draftCommand('发布瞬间');
    qqCheck(count(draftMoments()) === 5 && count(draftReceives()) === $downloads,
        'Neither a redelivered nor a new final command re-executes an uncertain database publication');
    draftCommand('取消瞬间');
    draftCommand('图文瞬间 核对结果后新建');
    qqCheck(count(draftRows()) === 1 && (draftRows()[0]['draft']['body'] ?? '') === '核对结果后新建',
        'Cancellation after checking an uncertain result allows a fresh draft');
    draftCommand('取消瞬间');

    draftCommand('图文瞬间 下载时取消');
    draftCommand('', ['attachments' => [draftAttachment(31)]]);
    $response = draftCall(draftEvent('发布瞬间'), 'weixin', false, ['cancel_during_receive' => true]);
    $cancellations = array_map(static function ($line) { return json_decode($line, true); },
        file($directory . '/media-cancel.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    qqCheck(($cancellations[0]['success'] ?? false) === true && draftRows() === [],
        'A cancellation received while images are downloading successfully clears the draft', $cancellations);
    qqCheck(($response['json']['success'] ?? true) === false && count(draftMoments()) === 5,
        'A final command whose draft was cancelled during receiving cannot insert a moment', $response);

    $sameSecond = time();
    $start = draftEvent('图文瞬间 同秒相同草稿', ['timestamp' => $sameSecond, 'attachments' => [draftAttachment(32)]]);
    draftCall($start);
    $original = draftRows()[0]['draft'];
    $restart = array_replace($start, ['id' => bin2hex(random_bytes(12))]);
    $response = draftCall(draftEvent('发布瞬间', ['timestamp' => $sameSecond]), 'weixin', false,
        ['cancel_during_receive' => true, 'restart_during_receive' => $restart]);
    $restarts = array_map(static function ($line) { return json_decode($line, true); },
        file($directory . '/media-restart.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $replacement = draftRows()[0]['draft'] ?? [];
    qqCheck(($restarts[0]['success'] ?? false) === true && !empty($replacement['draft_id'])
        && ($original['draft_id'] ?? '') !== $replacement['draft_id']
        && array_replace($original, ['draft_id' => $replacement['draft_id']]) === $replacement,
        'Cancelling and recreating identical text, images and timestamp gives the replacement a distinct draft identity', $replacement);
    qqCheck(($response['json']['success'] ?? true) === false && count(draftMoments()) === 5
        && ($replacement['state'] ?? '') === 'pending', 'Old final cannot publish or erase an identical replacement created during its download', $response);
    draftCommand('取消瞬间');

    draftCommand('图文瞬间 保存中不可取消');
    draftCommand('', ['attachments' => [draftAttachment(33)]]);
    draftMutate(['state' => 'committing']);
    $before = draftRows();
    $downloads = count(draftReceives());
    $cancel = draftCommand('取消瞬间');
    $restart = draftCommand('图文瞬间 不可覆盖保存中');
    qqCheck(($cancel['json']['success'] ?? true) === false && ($restart['json']['success'] ?? true) === false
        && draftRows() === $before && count(draftMoments()) === 5 && count(draftReceives()) === $downloads,
        'Cancellation and replacement are rejected while committing without clearing or changing the draft', [$cancel, $restart]);
    draftMutate(['state' => 'uncertain']);
    draftCommand('取消瞬间');

    draftCommand('图文瞬间 下载中关闭微信授权');
    draftCommand('', ['attachments' => [draftAttachment(34)]]);
    $response = draftCall(draftEvent('发布瞬间'), 'weixin', false,
        ['settings_during_receive' => ['weixin_moments_enabled' => '0']]);
    qqCheck(($response['json']['success'] ?? true) === false && (qqSettings()['weixin_moments_enabled'] ?? '') === '0'
        && count(draftMoments()) === 5 && draftRows() === [],
        'Disabling Weixin publishing during image receiving prevents an already claimed draft from being inserted', $response);
    qqConfig(['weixin_moments_enabled' => '1']);

    $response = draftCall(draftEvent('瞬间 同条图片下载中更换微信绑定', ['attachments' => [draftAttachment(35)]]), 'weixin', false,
        ['state_during_receive' => ['weixin_recipient' => ['user_id' => 'replacement-weixin-owner']]]);
    qqCheck(($response['json']['success'] ?? true) === false && count(draftMoments()) === 5,
        'Changing the Weixin binding during direct media receiving prevents publication by the former recipient', $response);
    draftPrivateState('weixin_recipient', ['user_id' => 'weixin-owner']);

    draftCommand('图文瞬间 下载中更换微信绑定', [], 'weixin');
    draftCommand('', ['attachments' => [$weixinImage]], 'weixin');
    $response = draftCall(draftEvent('发布瞬间', [], 'weixin'), 'weixin', false,
        ['state_during_receive' => ['weixin_recipient' => ['user_id' => 'replacement-weixin-owner']]]);
    qqCheck(($response['json']['success'] ?? true) === false && count(draftMoments()) === 5 && draftRows() === [],
        'Changing the Weixin recipient while receiving prevents an already claimed draft from being inserted', $response);
    draftPrivateState('weixin_recipient', ['user_id' => 'weixin-owner']);

    $response = draftCall(draftEvent('瞬间 同条图片下载中关闭微信授权', ['attachments' => [$weixinImage]], 'weixin'), 'weixin', false,
        ['settings_during_receive' => ['weixin_moments_enabled' => '0']]);
    qqCheck(($response['json']['success'] ?? true) === false && (qqSettings()['weixin_moments_enabled'] ?? '') === '0'
        && count(draftMoments()) === 5, 'Disabling Weixin publishing during direct media receiving prevents publication', $response);

    qqConfig(['weixin_moments_enabled' => '1']);
    draftCommand('图文瞬间 同秒关闭并重新开启授权');
    draftCommand('', ['attachments' => [draftAttachment(36)]]);
    $response = draftCall(draftEvent('发布瞬间'), 'weixin', false, ['reactivate_during_receive' => true]);
    $reactivations = array_map(static function ($line) { return json_decode($line, true); },
        file($directory . '/media-reactivate.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $reactivation = $reactivations[0] ?? [];
    qqCheck(!empty($reactivation['before']['activation_id']) && !empty($reactivation['after']['activation_id'])
        && $reactivation['before']['activation_id'] !== $reactivation['after']['activation_id']
        && $reactivation['before']['identity'] === $reactivation['after']['identity']
        && $reactivation['before']['enabled_at'] === $reactivation['after']['enabled_at'] && $reactivation['disabled'] === [],
        'Re-enabling identical settings with the original activation timestamp creates a new activation identity', $reactivation);
    qqCheck(($response['json']['success'] ?? true) === false && (qqSettings()['weixin_moments_enabled'] ?? '') === '1'
        && count(draftMoments()) === 5 && draftRows() === [],
        'A receive started before off and on cannot publish under the replacement authorization even in the same second', $response);

    qqCheck(qqConfig(['weixin_moments_enabled' => '1'])['status'] === 200,
        'Temporal fixture restores Weixin publishing without changing its bound recipient');
    $temporalGate = qqCall('/state', ['key' => 'bot_moments_gate'])['json']['record'] ?? [];
    $temporalGate['weixin']['enabled_at'] = time() - 90000;
    draftPrivateState('bot_moments_gate', $temporalGate);
    $delayedAt = time() - 3600;
    $directCases = [
        ['weixin', 'public', [], '延迟微信公开文字'],
        ['weixin', 'private', [draftAttachment(50)], '延迟微信私密图片'],
        ['weixin', 'public', [$weixinImage], '延迟微信图片'],
        ['weixin', 'private', [], '延迟微信文字'],
    ];
    foreach ($directCases as $index => [$channel, $status, $attachments, $body]) {
        $timestamp = $delayedAt + $index;
        $event = draftEvent(($status === 'private' ? '私密瞬间 ' : '瞬间 ') . $body,
            ['timestamp' => $timestamp, 'attachments' => $attachments], $channel);
        $before = count(draftMoments());
        $response = draftCall($event, $channel);
        $moments = draftMoments();
        $last = end($moments) ?: [];
        qqCheck(($response['json']['success'] ?? false) === true && count($moments) === $before + 1
            && ($last['content'] ?? '') === $body && ($last['source'] ?? '') === $channel && ($last['status'] ?? '') === $status
            && (int)($last['created'] ?? 0) === $timestamp
            && count(json_decode($last['media'] ?? 'null', true) ?: []) === count($attachments),
            'Delayed direct command preserves its original send time, source and visibility: ' . $channel . ' ' . $status, $response);
        $downloads = count(draftReceives());
        $replay = draftCall($event, $channel);
        qqCheck(($replay['json']['replayed'] ?? false) === true && count(draftMoments()) === $before + 1
            && count(draftReceives()) === $downloads, 'Delayed direct command remains idempotent: ' . $channel . ' ' . $status, $replay);
    }

    $channel = 'weixin';
    $attachment = $weixinImage;
    foreach (['public', 'private'] as $status) {
        $body = '离线分批图文-' . $channel . '-' . $status;
        $startedAt = $delayedAt + 10;
        $before = count(draftMoments());
        $downloads = count(draftReceives());
        $start = draftEvent(($status === 'private' ? '私密图文瞬间 ' : '图文瞬间 ') . $body,
            ['timestamp' => $startedAt], $channel);
        $response = draftCall($start, $channel);
        $draft = draftRows()[0]['draft'] ?? [];
        qqCheck(($response['json']['success'] ?? false) === true && ($draft['body'] ?? '') === $body
            && ($draft['state'] ?? '') === 'pending' && ($draft['status'] ?? '') === $status
            && (int)($draft['created'] ?? 0) === $startedAt && count(draftReceives()) === $downloads,
            'A start command delayed by an hour opens a durable draft using its send time: ' . $channel . ' ' . $status, $response);
        $image = draftEvent('', ['timestamp' => $startedAt + 60, 'attachments' => [$attachment]], $channel);
        $response = draftCall($image, $channel);
        $collected = draftRows();
        qqCheck(($response['json']['success'] ?? false) === true
            && count($collected[0]['draft']['attachments'] ?? []) === 1 && count(draftMoments()) === $before
            && count(draftReceives()) === $downloads,
            'A later receive batch appends an offline image without wall-clock expiration: ' . $channel . ' ' . $status, $response);
        draftCall($image, $channel);
        qqCheck(draftRows() === $collected, 'A replayed offline image cannot change the persisted draft: ' . $channel . ' ' . $status);
        $finishedAt = $startedAt + 120;
        $finish = draftEvent('发布瞬间', ['timestamp' => $finishedAt], $channel);
        $response = draftCall($finish, $channel);
        $moments = draftMoments();
        $last = end($moments) ?: [];
        qqCheck(($response['json']['success'] ?? false) === true && count($moments) === $before + 1
            && ($last['content'] ?? '') === $body && ($last['source'] ?? '') === $channel && ($last['status'] ?? '') === $status
            && (int)($last['created'] ?? 0) === $finishedAt && count(json_decode($last['media'] ?? 'null', true) ?: []) === 1
            && count(draftReceives()) === $downloads + 1 && draftRows() === [],
            'Finishing an offline collection uses the final command time across separate batches: ' . $channel . ' ' . $status, $response);
        draftCall($finish, $channel);
        qqCheck(count(draftMoments()) === $before + 1 && count(draftReceives()) === $downloads + 1 && draftRows() === [],
            'A redelivered offline final command cannot publish or download twice: ' . $channel . ' ' . $status);
        draftCommand('取消瞬间', ['timestamp' => $finishedAt + 1], $channel);
    }

    $startedAt = $delayedAt + 20;
    $before = count(draftMoments());
    $downloads = count(draftReceives());
    draftCommand('图文瞬间 发送时间恰好十五分钟', ['timestamp' => $startedAt], $channel);
    draftCommand('', ['timestamp' => $startedAt + 900, 'attachments' => [$attachment]], $channel);
    $response = draftCommand('发布瞬间', ['timestamp' => $startedAt + 900], $channel);
    $moments = draftMoments();
    $last = end($moments) ?: [];
    qqCheck(($response['json']['success'] ?? false) === true && count($moments) === $before + 1
        && (int)($last['created'] ?? 0) === $startedAt + 900 && count(draftReceives()) === $downloads + 1 && draftRows() === [],
        'Image and final command exactly 900 seconds after start remain valid despite delayed receipt: ' . $channel, $response);

    draftCommand('图文瞬间 图片发送超过十五分钟', ['timestamp' => $startedAt], $channel);
    $pending = draftRows();
    $before = count(draftMoments());
    $downloads = count(draftReceives());
    draftCommand('', ['timestamp' => $startedAt + 901, 'attachments' => [$attachment]], $channel);
    $response = draftCommand('发布瞬间', ['timestamp' => $startedAt + 902], $channel);
    qqCheck(draftRows() === $pending && count(draftMoments()) === $before && count(draftReceives()) === $downloads
        && empty($response['json']['success']), 'An image sent after 900 seconds cannot revive an expired collection: ' . $channel, $response);
    draftCommand('取消瞬间', ['timestamp' => $startedAt + 903], $channel);

    draftCommand('私密图文瞬间 最终命令超过十五分钟', ['timestamp' => $startedAt], $channel);
    draftCommand('', ['timestamp' => $startedAt + 899, 'attachments' => [$attachment]], $channel);
    $pending = draftRows();
    $response = draftCommand('发布瞬间', ['timestamp' => $startedAt + 901], $channel);
    qqCheck(count($pending[0]['draft']['attachments'] ?? []) === 1 && draftRows() === $pending
        && count(draftMoments()) === $before && count(draftReceives()) === $downloads && empty($response['json']['success']),
        'A final command sent after 900 seconds cannot publish previously collected images: ' . $channel, $response);
    draftCommand('取消瞬间', ['timestamp' => $startedAt + 902], $channel);

    draftCommand('图文瞬间 图片不能早于创建指令', ['timestamp' => $startedAt], $channel);
    $pending = draftRows();
    draftCommand('', ['timestamp' => $startedAt - 1, 'attachments' => [$attachment]], $channel);
    qqCheck(draftRows() === $pending && count(draftMoments()) === $before && count(draftReceives()) === $downloads,
        'An older offline image cannot attach to a newer collection: ' . $channel);
    draftCommand('取消瞬间', ['timestamp' => $startedAt + 1], $channel);

    $before = count(draftMoments());
    $downloads = count(draftReceives());
    $tooOld = draftEvent('瞬间 超过一天的历史指令', ['timestamp' => time() - 86401, 'attachments' => [$attachment]], $channel);
    draftCall($tooOld, $channel);
    draftCall($tooOld, $channel);
    draftCommand('图文瞬间 超过一天的历史草稿', ['timestamp' => time() - 86401], $channel);
    qqCheck(count(draftMoments()) === $before && count(draftReceives()) === $downloads && draftRows() === [],
        'The 24-hour replay window still rejects older direct and start commands before receiving: ' . $channel);

    $recentGate = $temporalGate;
    $recentGate[$channel]['enabled_at'] = $startedAt;
    draftPrivateState('bot_moments_gate', $recentGate);
    draftCommand('瞬间 授权启用前的延迟指令', ['timestamp' => $startedAt - 1, 'attachments' => [$attachment]], $channel);
    qqCheck(count(draftMoments()) === $before && count(draftReceives()) === $downloads && draftRows() === [],
        'Accepting offline batches does not permit messages sent before publishing was enabled: ' . $channel);
    draftPrivateState('bot_moments_gate', $temporalGate);

    $log = file_get_contents($directory . '/typecho.log');
    qqCheck(preg_match('/PHP (?:Warning|Fatal error|Parse error|Deprecated|Notice)/i', $log) !== 1, 'Image draft fixture reports no PHP warnings or errors', $log);
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
