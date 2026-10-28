<?php

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/QqDirectNotifyHelper.php';

function notifyQqConfig(array $changes = array()): array
{
    return array_merge(array('qq_notify_provider' => 'direct', 'qq_app_id' => '123456', 'qq_app_secret' => 'test-secret',
        'qq_target_type' => 'c2c', 'qq_target_id' => 'USER_OPENID', 'qq_direct_include_links' => '1'), $changes);
}

function notifyQqTransport(array &$calls, array $responses): callable
{
    return static function ($method, $url, $headers = array(), $payload = null, $timeout = 6) use (&$calls, &$responses) {
        $calls[] = compact('method', 'url', 'headers', 'payload', 'timeout');
        if (!$responses) { throw new LogicException('Unexpected QQ request.'); }
        $response = array_shift($responses);
        if ($response instanceof Throwable) { throw $response; }
        return array('status' => $response['status'] ?? 200, 'data' => $response['data'] ?? $response);
    };
}

function notifyQqCached(array $config, int $expires = 3600): void
{
    Enhancement_NotifyTransportHelper::write('qq_token', array('identity' => hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']),
        'token' => 'cached-token', 'expires_at' => time() + $expires));
}

function notifyQqSigned(array $event, string $secret = 'test-secret', string $appId = '123456'): array
{
    $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $seed = $secret;
    while (strlen($seed) < 32) { $seed .= $secret; }
    $key = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(substr($seed, 0, 32)));
    $timestamp = '1725442341';
    return array($body, array('x-bot-appid' => $appId, 'x-signature-timestamp' => $timestamp,
        'x-signature-ed25519' => bin2hex(sodium_crypto_sign_detached($timestamp . $body, $key))));
}

notifyReset(notifyQqConfig());
$calls = array();
$result = Enhancement_QqDirectNotifyHelper::send('测试通知', null, notifyQqTransport($calls, array(
    array('access_token' => 'issued-token', 'expires_in' => '7200'), array('id' => 'message-1'), array('id' => 'message-2')
)));
notifyCheck($result['success'] && $result['delivery'] === 'accepted' && $result['id'] === 'message-1', 'QQ reports official acceptance');
notifyCheck($calls[0]['url'] === 'https://api.bot.qq.com/app/getAppAccessToken' && $calls[0]['method'] === 'POST', 'QQ uses official token endpoint');
notifyCheck($calls[0]['payload'] === array('appId' => '123456', 'clientSecret' => 'test-secret'), 'QQ token request uses AppID/AppSecret');
notifyCheck($calls[1]['url'] === 'https://api.bot.qq.com/v2/users/USER_OPENID/messages', 'QQ uses official C2C endpoint');
notifyCheck($calls[1]['headers'] === array('Authorization: QQBot issued-token') && $calls[1]['payload']['msg_type'] === 0, 'QQ authenticates with QQBot and text message type');
notifyCheck(!isset($calls[1]['payload']['msg_id'], $calls[1]['payload']['event_id']), 'QQ proactive messages do not fabricate reply references');
notifyCheck(!empty(Enhancement_NotifyTransportHelper::read('qq_result')['success']), 'QQ retains recent result');
$nextCalls = array();
$next = Enhancement_QqDirectNotifyHelper::send('再次测试', null, notifyQqTransport($nextCalls, array(array('id' => 'message-2'))));
notifyCheck($next['success'] && count($nextCalls) === 1, 'QQ reuses current token');

foreach (array('group' => '/v2/groups/', 'channel' => '/channels/') as $type => $path) {
    $config = notifyQqConfig(array('qq_target_type' => $type));
    notifyReset($config);
    notifyQqCached($config);
    $calls = array();
    $result = Enhancement_QqDirectNotifyHelper::send('测试', null, notifyQqTransport($calls, array(array('id' => 'ok'))));
    notifyCheck($result['success'] && $calls[0]['url'] === 'https://api.bot.qq.com' . $path . 'USER_OPENID/messages', 'QQ target uses ' . $type . ' endpoint');
    notifyCheck(isset($calls[0]['payload']['msg_type']) === ($type !== 'channel'), 'QQ channel omits msg_type');
}

foreach (array('expires' => notifyQqConfig(), 'identity' => notifyQqConfig(array('qq_app_secret' => 'different-secret'))) as $case => $config) {
    notifyReset($config);
    notifyQqCached(notifyQqConfig(), $case === 'expires' ? 30 : 3600);
    $calls = array();
    $result = Enhancement_QqDirectNotifyHelper::send('测试', null, notifyQqTransport($calls, array(array('access_token' => 'new-token', 'expires_in' => 7200), array('id' => 'ok'))));
    notifyCheck($result['success'] && count($calls) === 2, 'QQ refreshes ' . $case . ' cache');
}

notifyReset(notifyQqConfig());
notifyQqCached(notifyQqConfig());
$calls = array();
$result = Enhancement_QqDirectNotifyHelper::send('测试', null, notifyQqTransport($calls, array(
    array('status' => 401, 'data' => array('code' => 11241)), array('access_token' => 'renewed-token', 'expires_in' => 7200), array('id' => 'ok')
)));
notifyCheck($result['success'] && count($calls) === 3, 'QQ refreshes only after HTTP 401');
notifyCheck($calls[2]['headers'] === array('Authorization: QQBot renewed-token'), 'QQ retry uses renewed token');

foreach (array(
    array(array('status' => 401, 'data' => array()), array('access_token' => 'renewed-token', 'expires_in' => 7200), array('status' => 401, 'data' => array())),
    array(array('code' => 40034105, 'message' => 'private upstream secret')),
    array(array('err_code' => 304023, 'id' => 'audit-pending')),
    array(array('status' => 202, 'data' => array('message_audit' => array('audit_id' => 'audit')))),
    array(array()), array(new RuntimeException('通知接口连接失败或超时', 28)),
    array(array('status' => 429, 'data' => array('code' => 40034100)))
) as $responses) {
    notifyReset(notifyQqConfig());
    notifyQqCached(notifyQqConfig());
    $calls = array();
    $result = Enhancement_QqDirectNotifyHelper::send('测试', null, notifyQqTransport($calls, $responses));
    notifyCheck(!$result['success'] && count($calls) === count($responses), 'QQ errors and ambiguous delivery never retry generally');
    notifyCheck(strpos($result['error'], 'private upstream secret') === false, 'QQ hides raw platform error');
}

foreach (array(array('qq_target_id' => '../escape'), array('qq_app_id' => 'bad'), array('qq_app_secret' => "secret\r\nInjected:x"),
    array('qq_target_type' => 'unknown'), array('qq_target_id' => ''), array('qq_target_type' => array('group'))) as $changes) {
    notifyReset(notifyQqConfig($changes));
    $calls = array();
    $result = Enhancement_QqDirectNotifyHelper::send('测试', null, notifyQqTransport($calls, array()));
    notifyCheck(!$result['success'] && $calls === array(), 'QQ rejects malformed settings before HTTP');
}

notifyReset(notifyQqConfig(array('qq_direct_include_links' => '0')));
notifyQqCached(notifyQqConfig());
$calls = array();
$result = Enhancement_QqDirectNotifyHelper::send(str_repeat('中文测试', 600) . ' https://example.test/', null, notifyQqTransport($calls, array(array('id' => 'ok'))));
notifyCheck($result['success'] && strlen($calls[0]['payload']['content']) <= 1800 && preg_match('//u', $calls[0]['payload']['content']) === 1, 'QQ message truncation respects UTF-8 and API length');
notifyCheck(strpos($calls[0]['payload']['content'], 'https://') === false, 'QQ can omit article links');

if (function_exists('sodium_crypto_sign_seed_keypair')) {
    // Tencent's published challenge vector tests the protocol independently of fixture signing.
    notifyReset(notifyQqConfig(array('qq_app_secret' => 'DG5g3B4j9X2KOErG')));
    list($body, $headers) = notifyQqSigned(array('op' => 13, 'd' => array('plain_token' => 'Arq0D5A61EgUu4OxUvOp', 'event_ts' => '1725442341')), 'DG5g3B4j9X2KOErG');
    $result = Enhancement_QqDirectNotifyHelper::webhook($body, $headers);
    notifyCheck($result['status'] === 200 && $result['body']['signature'] === '87befc99c42c651b3aac0278e71ada338433ae26fcb24307bdc5ad38c1adc2d01bcfcadc0842edac85e85205028a1132afe09280305f13aa6909ffc2d652c706', 'QQ matches published official challenge signature');
    notifyReset(notifyQqConfig(array('qq_app_secret' => 'naOC0ocQE3shWLAfffVLB1rhYPG7')));
    $body = '{ "op": 0,"d": {}, "t": "GATEWAY_EVENT_NAME"}';
    $headers = array('x-bot-appid' => '123456', 'x-signature-timestamp' => '1725442341',
        'x-signature-ed25519' => '2eb9983ebb8bb209e78fd095942f58e442656656e7975d01e64f9023a84b7c964290fdd40e5500c33867ccfe9563b7e0b6bac0e1d42c13e787b304fd51f71102');
    notifyCheck(Enhancement_QqDirectNotifyHelper::webhook($body, $headers)['status'] === 200, 'QQ accepts independently verified exact-body signature');
    notifyReset(notifyQqConfig(array('qq_target_id' => '')));
    $challenge = array('op' => 13, 'd' => array('plain_token' => 'challenge', 'event_ts' => '1725442341'));
    list($body, $headers) = notifyQqSigned($challenge);
    $result = Enhancement_QqDirectNotifyHelper::webhook($body, $headers);
    notifyCheck($result['status'] === 200 && $result['body']['plain_token'] === 'challenge', 'QQ answers signed official challenge');
    $secret = 'test-secret';
    while (strlen($secret) < 32) { $secret .= 'test-secret'; }
    $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(substr($secret, 0, 32)));
    notifyCheck(sodium_crypto_sign_verify_detached(hex2bin($result['body']['signature']), '1725442341challenge', $public), 'QQ challenge response uses event timestamp and official Ed25519 derivation');
    $before = Enhancement_NotifyTransportHelper::$state;
    $forged = $headers;
    $forged['x-signature-ed25519'] = str_repeat('0', 128);
    notifyCheck(Enhancement_QqDirectNotifyHelper::webhook($body, $forged)['status'] === 403 && Enhancement_NotifyTransportHelper::$state === $before, 'QQ rejects forged signatures without mutation');
    notifyCheck(Enhancement_QqDirectNotifyHelper::webhook($body . ' ', $headers)['status'] === 403, 'QQ signs exact raw bytes');
    $missingAppId = $headers;
    unset($missingAppId['x-bot-appid']);
    notifyCheck(Enhancement_QqDirectNotifyHelper::webhook($body, $missingAppId)['status'] === 403, 'QQ requires matching AppID header');
    notifyCheck(Enhancement_QqDirectNotifyHelper::webhook(str_repeat('x', 65537), array())['status'] === 413, 'QQ limits callback body size');

    $binding = Enhancement_QqDirectNotifyHelper::startBinding();
    notifyCheck(preg_match('/\A[A-F0-9]{16}\z/', $binding['code']) === 1 && $binding['expires_at'] > time() + 590, 'QQ binding has a random ten-minute code');
    $event = array('op' => 0, 't' => 'C2C_MESSAGE_CREATE', 'd' => array('content' => '绑定通知 ' . $binding['code'], 'author' => array('user_openid' => 'BOUND_USER')));
    list($body, $headers) = notifyQqSigned($event);
    $result = Enhancement_QqDirectNotifyHelper::webhook($body, $headers);
    notifyCheck($result === array('status' => 200, 'body' => array('op' => 12, 'd' => 0)), 'QQ acknowledges valid events with official opcode');
    notifyCheck(Enhancement_Plugin::$settings['qq_target_id'] === 'BOUND_USER' && Enhancement_NotifyTransportHelper::read('qq_binding') === array(), 'QQ binds user OpenID and consumes code');
    notifyCheck(!isset(Enhancement_Plugin::$settings['enable_comment_by_qq']), 'QQ binding does not enable notifications');
    $event['d']['author']['user_openid'] = 'OTHER_USER';
    list($body, $headers) = notifyQqSigned($event);
    Enhancement_QqDirectNotifyHelper::webhook($body, $headers);
    notifyCheck(Enhancement_Plugin::$settings['qq_target_id'] === 'BOUND_USER', 'QQ binding code cannot be replayed');

    foreach (array('expired', 'cancelled', 'replaced', 'wrong-type', 'changed-secret', 'wrong-code') as $case) {
        notifyReset(notifyQqConfig(array('qq_target_id' => 'ORIGINAL')));
        $binding = Enhancement_QqDirectNotifyHelper::startBinding();
        if ($case === 'expired') { Enhancement_NotifyTransportHelper::$state['qq_binding']['expires_at'] = time() - 1; }
        if ($case === 'cancelled') { Enhancement_QqDirectNotifyHelper::cancelBinding(); }
        if ($case === 'replaced') { Enhancement_QqDirectNotifyHelper::startBinding(); }
        if ($case === 'wrong-type') { notifySettings(array('qq_target_type' => 'group')); }
        if ($case === 'changed-secret') { notifySettings(array('qq_app_secret' => 'new-secret')); }
        $event = array('op' => 0, 't' => 'C2C_MESSAGE_CREATE', 'd' => array('content' => '绑定通知 ' . ($case === 'wrong-code' ? '0000000000000000' : $binding['code']), 'author' => array('user_openid' => 'ATTEMPT')));
        list($body, $headers) = notifyQqSigned($event, $case === 'changed-secret' ? 'new-secret' : 'test-secret');
        Enhancement_QqDirectNotifyHelper::webhook($body, $headers);
        notifyCheck(Enhancement_Plugin::$settings['qq_target_id'] === 'ORIGINAL', 'QQ refuses ' . $case . ' binding');
    }

    notifyReset(notifyQqConfig(array('qq_target_type' => 'group', 'enable_comment_by_qq' => '0')));
    $binding = Enhancement_QqDirectNotifyHelper::startBinding();
    $event = array('op' => 0, 't' => 'GROUP_AT_MESSAGE_CREATE', 'd' => array('content' => '<@123456> 绑定通知 ' . $binding['code'],
        'group_openid' => 'BOUND_GROUP', 'author' => array('member_openid' => 'MEMBER')));
    list($body, $headers) = notifyQqSigned($event);
    Enhancement_QqDirectNotifyHelper::webhook($body, $headers);
    notifyCheck(Enhancement_Plugin::$settings['qq_target_id'] === 'BOUND_GROUP' && Enhancement_Plugin::$settings['enable_comment_by_qq'] === '0', 'QQ group binding strips mention, chooses group OpenID and preserves switch');
    $status = Enhancement_QqDirectNotifyHelper::status();
    notifyCheck(!empty($status['last_event_at']) && !isset($status['identity'], $status['token']), 'QQ admin status contains no secret credentials');

    notifyReset(notifyQqConfig(array('qq_target_type' => 'channel', 'qq_target_id' => 'NOT_THE_SENDER', 'qq_moments_enabled' => '1')));
    notifyQqCached(notifyQqConfig());
    $normalized = array();
    Enhancement_BotMomentsHelper::$handler = static function ($channel, $message) use (&$normalized) {
        $normalized[] = array('channel' => $channel, 'message' => $message);
        return array('success' => true, 'mid' => 12, 'status' => 'public', 'message' => '瞬间 #12 已发布', 'replayed' => false);
    };
    $event = array('op' => 0, 't' => 'C2C_MESSAGE_CREATE', 'id' => 'outer-event', 'd' => array('id' => 'private.message-1',
        'content' => ' <@!123456> 发瞬间 今天很好🙂', 'timestamp' => '2026-10-28T04:05:06+08:00',
        'author' => array('user_openid' => 'PUBLISHING_USER')));
    list($body, $headers) = notifyQqSigned($event);
    $calls = array();
    $result = Enhancement_QqDirectNotifyHelper::webhook($body, $headers, notifyQqTransport($calls, array(array('id' => 'receipt-1'))));
    notifyCheck($result['status'] === 200 && count($normalized) === 1, 'QQ signed private event reaches shared publishing handler');
    notifyCheck($normalized[0] === array('channel' => 'qq', 'message' => array('id' => 'private.message-1', 'sender' => 'PUBLISHING_USER',
        'conversation' => 'PUBLISHING_USER', 'conversation_type' => 'c2c', 'text' => '发瞬间 今天很好🙂', 'timestamp' => strtotime('2026-10-28T04:05:06+08:00'), 'account' => '123456')),
        'QQ normalizes real message identity, sender, text, timestamp and bot account');
    notifyCheck(count($calls) === 1 && $calls[0]['url'] === 'https://api.bot.qq.com/v2/users/PUBLISHING_USER/messages',
        'QQ moment receipt targets current private sender regardless of notification recipient');
    notifyCheck($calls[0]['payload'] === array('content' => '瞬间 #12 已发布', 'msg_type' => 0, 'msg_id' => 'private.message-1', 'msg_seq' => 1),
        'QQ moment receipt references actual incoming message once');
    $forged = $headers;
    $forged['x-signature-ed25519'] = str_repeat('0', 128);
    $before = count($normalized);
    notifyCheck(Enhancement_QqDirectNotifyHelper::webhook($body, $forged)['status'] === 403 && count($normalized) === $before,
        'Unsigned or forged messages never reach moment publishing');

    $event['t'] = 'GROUP_AT_MESSAGE_CREATE';
    $event['d'] = array('id' => 'group.message-1', 'content' => '<@123456> <@!123456> 私密瞬间 群里的记录', 'timestamp' => 1793131506,
        'group_openid' => 'PUBLISHING_GROUP', 'author' => array('member_openid' => 'PUBLISHING_MEMBER', 'user_openid' => 'WRONG_ID'));
    list($body, $headers) = notifyQqSigned($event);
    $calls = array();
    $result = Enhancement_QqDirectNotifyHelper::webhook($body, $headers, notifyQqTransport($calls, array(array('id' => 'receipt-2'))));
    notifyCheck($result['status'] === 200 && end($normalized)['message']['sender'] === 'PUBLISHING_MEMBER'
        && end($normalized)['message']['conversation'] === 'PUBLISHING_GROUP' && end($normalized)['message']['text'] === '私密瞬间 群里的记录',
        'QQ passes distinct group and member identities to shared authorization');
    notifyCheck($calls[0]['url'] === 'https://api.bot.qq.com/v2/groups/PUBLISHING_GROUP/messages'
        && $calls[0]['payload']['msg_id'] === 'group.message-1', 'QQ replies to actual group conversation and message');

    foreach (array('missing-id', 'missing-member', 'invalid-group', 'unsupported-event') as $case) {
        $invalid = $event;
        if ($case === 'missing-id') { unset($invalid['d']['id']); }
        if ($case === 'missing-member') { unset($invalid['d']['author']['member_openid']); }
        if ($case === 'invalid-group') { $invalid['d']['group_openid'] = '../escape'; }
        if ($case === 'unsupported-event') { $invalid['t'] = 'AT_MESSAGE_CREATE'; }
        list($body, $headers) = notifyQqSigned($invalid);
        $before = count($normalized);
        $calls = array();
        $result = Enhancement_QqDirectNotifyHelper::webhook($body, $headers, notifyQqTransport($calls, array()));
        notifyCheck($result['status'] === 200 && count($normalized) === $before && $calls === array(), 'QQ ignores malformed or unsupported moment event: ' . $case);
    }
    foreach (array(null, array('success' => true, 'message' => '已发布', 'replayed' => true)) as $ignored) {
        Enhancement_BotMomentsHelper::$handler = static function () use ($ignored) { return $ignored; };
        list($body, $headers) = notifyQqSigned($event);
        $calls = array();
        $result = Enhancement_QqDirectNotifyHelper::webhook($body, $headers, notifyQqTransport($calls, array()));
        notifyCheck($result['status'] === 200 && $calls === array(), 'QQ ignores unpublished and replayed results without another reply');
    }

    $executions = 0;
    Enhancement_BotMomentsHelper::$handler = static function () use (&$executions) {
        return array('success' => true, 'message' => '瞬间已发布', 'replayed' => ++$executions > 1);
    };
    $calls = array();
    $result = Enhancement_QqDirectNotifyHelper::webhook($body, $headers, notifyQqTransport($calls,
        array(array('status' => 429, 'data' => array('code' => 40034100, 'message' => 'private upstream secret')))));
    notifyCheck($result['status'] === 200 && empty(Enhancement_NotifyTransportHelper::read('qq_result')['success']) && count($calls) === 1,
        'QQ receipt failure still acknowledges completed publishing without automatic retry');
    notifyCheck(strpos(Enhancement_NotifyTransportHelper::read('qq_result')['error'], 'private upstream secret') === false,
        'QQ receipt errors hide upstream response details');
    $calls = array();
    notifyCheck(Enhancement_QqDirectNotifyHelper::webhook($body, $headers, notifyQqTransport($calls, array()))['status'] === 200 && $calls === array(),
        'QQ replay after failed receipt does not issue another reply');

    Enhancement_BotMomentsHelper::$handler = static function () { return array('success' => false, 'message' => '内容不能为空'); };
    $calls = array();
    $result = Enhancement_QqDirectNotifyHelper::webhook($body, $headers, notifyQqTransport($calls, array(
        array('status' => 401, 'data' => array('code' => 11241)), array('access_token' => 'reply-renewed-token', 'expires_in' => 7200), array('id' => 'receipt-3')
    )));
    notifyCheck($result['status'] === 200 && count($calls) === 3 && $calls[0]['payload'] === $calls[2]['payload']
        && $calls[2]['headers'] === array('Authorization: QQBot reply-renewed-token'), 'QQ failure receipt refreshes expired access token with same reply reference');
    Enhancement_BotMomentsHelper::$handler = null;
}
