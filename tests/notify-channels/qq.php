<?php

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/QqOfficialHelper.php';

function notifyQqConfig(array $changes = array()): array
{
    return array_replace(array('qq_notify_provider' => 'official', 'qq_app_id' => '123456',
        'qq_app_secret' => 'test-secret', 'qq_official_include_links' => '1', 'qq_moments_enabled' => '0'), $changes);
}
function notifyQqBind(string $type = 'c2c', string $target = 'BOUND_USER'): void
{
    Enhancement_NotifyTransportHelper::write('qq_binding', array('identity' => Enhancement_QqOfficialHelper::credentialsIdentity(),
        'target_type' => $type, 'target_id' => $target, 'bound_at' => time()));
}
function notifyQqCached(): void
{
    Enhancement_NotifyTransportHelper::write('qq_token', array('identity' => Enhancement_QqOfficialHelper::credentialsIdentity(),
        'token' => 'cached-token', 'expires_at' => time() + 7200));
}
function notifyQqTransport(array &$calls, array $responses): callable
{
    return static function ($method, $url, $headers, $payload) use (&$calls, &$responses) {
        $calls[] = compact('method', 'url', 'headers', 'payload');
        if (!$responses) { throw new RuntimeException('Unexpected network request'); }
        $response = array_shift($responses);
        if ($response instanceof Throwable) { throw $response; }
        return isset($response['status']) ? $response : array('status' => 200, 'data' => $response);
    };
}
function notifyQqSigned(array $event, ?string $secret = null, ?string $stamp = null): array
{
    $secret = $secret ?? Enhancement_Plugin::$settings['qq_app_secret'];
    $seed = substr(str_repeat($secret, (int)ceil(32 / strlen($secret))), 0, 32);
    $keypair = sodium_crypto_sign_seed_keypair($seed);
    $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stamp = $stamp ?? (string)time();
    return array($body, array('X-Bot-Appid' => Enhancement_Plugin::$settings['qq_app_id'],
        'X-Signature-Timestamp' => $stamp,
        'X-Signature-Ed25519' => bin2hex(sodium_crypto_sign_detached($stamp . $body, sodium_crypto_sign_secretkey($keypair)))));
}

notifyReset(notifyQqConfig());
$calls = array();
$result = Enhancement_QqOfficialHelper::send('测试', null, notifyQqTransport($calls, array()));
notifyCheck(!$result['success'] && $calls === array(), 'Official QQ cannot notify an unbound recipient');
notifyQqBind();
$result = Enhancement_QqOfficialHelper::send('新评论🙂', null, notifyQqTransport($calls,
    array(array('access_token' => 'new-token', 'expires_in' => 7200), array('id' => 'message-1'))));
notifyCheck($result['success'] && count($calls) === 2, 'AppID and AppSecret directly obtain a QQ access token and send');
notifyCheck($calls[0]['url'] === 'https://api.bot.qq.com/app/getAppAccessToken'
    && $calls[0]['payload'] === array('appId' => '123456', 'clientSecret' => 'test-secret'), 'QQ token request uses only official credentials');
notifyCheck($calls[1]['url'] === 'https://api.bot.qq.com/v2/users/BOUND_USER/messages'
    && $calls[1]['payload'] === array('content' => '新评论🙂', 'msg_type' => 0)
    && $calls[1]['headers'] === array('Authorization: QQBot new-token'), 'QQ uses the verified stored private recipient');
$calls = array();
$result = Enhancement_QqOfficialHelper::send('回复', null, notifyQqTransport($calls, array(array('id' => 'reply'))), 'original.message');
notifyCheck($result['success'] && count($calls) === 1 && $calls[0]['payload']['msg_id'] === 'original.message'
    && $calls[0]['payload']['msg_seq'] === 1, 'Cached token and stable passive reply reference are used');
$calls = array();
$result = Enhancement_QqOfficialHelper::send('过期token', null, notifyQqTransport($calls, array(
    array('status' => 401, 'data' => array('code' => 11241)), array('access_token' => 'renewed', 'expires_in' => 7200), array('id' => 'renewed-message'))));
notifyCheck($result['success'] && count($calls) === 3 && $calls[0]['payload'] === $calls[2]['payload'], 'QQ refreshes an unauthorized token once with identical payload');

foreach (array(array('status' => 403, 'data' => array('code' => 403, 'message' => 'private upstream secret')),
    array('status' => 429, 'data' => array('code' => 40034100)), array('status' => 202, 'data' => array('message_audit' => array('audit_id' => 'pending'))),
    array('status' => 200, 'data' => array('id' => 'pending', 'data' => array('audit_id' => 'pending'))),
    array('status' => 200, 'data' => array()), new RuntimeException('Connection timed out')) as $response) {
    $calls = array();
    $result = Enhancement_QqOfficialHelper::send('测试', null, notifyQqTransport($calls, array($response)));
    notifyCheck(!$result['success'] && count($calls) === 1, 'QQ error, audit and ambiguous response never report delivered or retry generally');
    notifyCheck(strpos($result['error'], 'private upstream secret') === false, 'QQ hides private platform error text');
}
notifyQqBind('group', 'BOUND_GROUP');
$calls = array();
$result = Enhancement_QqOfficialHelper::send('群通知', null, notifyQqTransport($calls, array(array('id' => 'group-message'))));
notifyCheck($result['success'] && $calls[0]['url'] === 'https://api.bot.qq.com/v2/groups/BOUND_GROUP/messages', 'Optional verified group recipient supports official notifications');
notifySettings(array('qq_app_secret' => 'rotated-secret'));
$calls = array();
notifyCheck(Enhancement_QqOfficialHelper::binding() === array()
    && !Enhancement_QqOfficialHelper::send('旧绑定', null, notifyQqTransport($calls, array()))['success'] && $calls === array(), 'Changing credentials immediately invalidates recipient and token identity');

foreach (array(array('qq_app_id' => 'bad'), array('qq_app_secret' => "secret\r\nHeader"), array('qq_app_id' => ''),
    array('qq_app_secret' => array('x')), array('qq_official_include_links' => '2'), array('qq_moments_enabled' => '2')) as $changes) {
    notifyReset(notifyQqConfig($changes));
    $calls = array();
    notifyCheck(!Enhancement_QqOfficialHelper::send('测试', null, notifyQqTransport($calls, array()))['success'] && $calls === array(), 'Malformed credentials and switches fail before HTTP');
}
notifyReset(notifyQqConfig(array('qq_official_include_links' => '0')));
notifyQqBind(); notifyQqCached();
$calls = array();
$result = Enhancement_QqOfficialHelper::send(str_repeat('中文测试', 600) . ' https://example.test/', null,
    notifyQqTransport($calls, array(array('id' => 'limited'))));
notifyCheck($result['success'] && strlen($calls[0]['payload']['content']) <= 1800
    && preg_match('//u', $calls[0]['payload']['content']) === 1 && strpos($calls[0]['payload']['content'], 'https://') === false, 'QQ truncation preserves UTF-8 and can omit links');

if (function_exists('sodium_crypto_sign_seed_keypair')) {
    notifyReset(notifyQqConfig(array('qq_app_secret' => 'DG5g3B4j9X2KOErG')));
    list($body, $headers) = notifyQqSigned(array('op' => 13, 'd' => array('plain_token' => 'Arq0D5A61EgUu4OxUvOp', 'event_ts' => '1725442341')));
    $result = Enhancement_QqOfficialHelper::webhook($body, $headers);
    notifyCheck($result['status'] === 200 && $result['body']['signature'] === '87befc99c42c651b3aac0278e71ada338433ae26fcb24307bdc5ad38c1adc2d01bcfcadc0842edac85e85205028a1132afe09280305f13aa6909ffc2d652c706', 'QQ matches the published official challenge signature vector');
    $unsignedChallenge = json_encode(array('op' => 13, 'd' => array('plain_token' => 'opaque_Challenge-123', 'event_ts' => (string)time())));
    $unsigned = Enhancement_QqOfficialHelper::webhook($unsignedChallenge, array());
    notifyCheck($unsigned['status'] === 200 && $unsigned['body']['plain_token'] === 'opaque_Challenge-123', 'Official first-time unsigned verification challenge works with a fresh opaque token');
    notifyCheck(Enhancement_QqOfficialHelper::webhook($unsignedChallenge, array('X-Bot-Appid' => '', 'X-Signature-Timestamp' => '', 'X-Signature-Ed25519' => ''))['status'] === 200,
        'Empty values from request adapters are treated as absent challenge headers');
    notifyCheck(Enhancement_QqOfficialHelper::webhook($unsignedChallenge, array('X-Signature-Timestamp' => (string)time()))['status'] === 403,
        'A lone signature timestamp requires the matching Ed25519 signature');
    notifyCheck(Enhancement_QqOfficialHelper::webhook($unsignedChallenge, array('X-Signature-Ed25519' => str_repeat('0', 128)))['status'] === 403,
        'A supplied invalid challenge signature cannot bypass verification');
    notifyCheck(Enhancement_QqOfficialHelper::webhook($unsignedChallenge, array('X-Bot-Appid' => 'different-app'))['status'] === 403,
        'A supplied challenge AppID must match the saved official application');
    $jsonChallenge = json_encode(array('op' => 13, 'd' => array('plain_token' => '{"op":0}', 'event_ts' => (string)time())));
    notifyCheck(Enhancement_QqOfficialHelper::webhook($jsonChallenge, array())['status'] === 400, 'Challenge endpoint cannot sign arbitrary JSON event content');
    $expiredChallenge = json_encode(array('op' => 13, 'd' => array('plain_token' => 'opaque', 'event_ts' => (string)(time() - 301))));
    notifyCheck(Enhancement_QqOfficialHelper::webhook($expiredChallenge, array())['status'] === 403, 'Unsigned expired challenge cannot be replayed');
    $before = Enhancement_NotifyTransportHelper::$state;
    $forged = $headers;
    $forged['X-Signature-Ed25519'] = str_repeat('0', 128);
    notifyCheck(Enhancement_QqOfficialHelper::webhook($body, $forged)['status'] === 403 && Enhancement_NotifyTransportHelper::$state === $before, 'Forged signature cannot mutate QQ state');
    notifyCheck(Enhancement_QqOfficialHelper::webhook($body . ' ', $headers)['status'] === 403, 'QQ signs exact raw request bytes');
    foreach (array((string)(time() - 301), (string)(time() + 301), '1725442341') as $stamp) {
        list($oldBody, $oldHeaders) = notifyQqSigned(array('op' => 0, 't' => 'TEST', 'd' => array()), null, $stamp);
        notifyCheck(Enhancement_QqOfficialHelper::webhook($oldBody, $oldHeaders)['status'] === 403, 'Expired or future signatures cannot replay a QQ callback');
    }
    list($eventBody, $eventHeaders) = notifyQqSigned(array('op' => 0, 't' => 'TEST', 'd' => array()));
    $missingAppId = $eventHeaders;
    unset($missingAppId['X-Bot-Appid']);
    notifyCheck(Enhancement_QqOfficialHelper::webhook($eventBody, $missingAppId)['status'] === 200, 'Official signature headers authenticate an event when optional AppID is absent');
    $wrongAppId = $eventHeaders;
    $wrongAppId['X-Bot-Appid'] = 'wrong-app';
    notifyCheck(Enhancement_QqOfficialHelper::webhook($eventBody, $wrongAppId)['status'] === 403, 'A supplied event AppID must match the saved application');
    notifyCheck(Enhancement_QqOfficialHelper::webhook(str_repeat('x', 65537), array())['status'] === 413, 'QQ callback body size is limited');

    notifyReset(notifyQqConfig());
    $pending = Enhancement_QqOfficialHelper::startBinding();
    notifyCheck(preg_match('/\A[A-F0-9]{16}\z/D', $pending['code']) === 1 && $pending['expires_at'] > time() + 590, 'Private binding uses a random ten-minute one-time code');
    $event = array('op' => 0, 't' => 'C2C_MESSAGE_CREATE', 'd' => array('content' => $pending['command'], 'author' => array('user_openid' => 'REAL_PRIVATE_USER')));
    list($body, $headers) = notifyQqSigned($event);
    $result = Enhancement_QqOfficialHelper::webhook($body, $headers);
    notifyCheck($result === array('status' => 200, 'body' => array('op' => 12, 'd' => 0)), 'QQ acknowledges the official signed binding event');
    notifyCheck(Enhancement_QqOfficialHelper::binding()['target_id'] === 'REAL_PRIVATE_USER'
        && Enhancement_NotifyTransportHelper::read('qq_binding_pending') === array(), 'Verified event supplies recipient OpenID and consumes pending binding code');
    notifyCheck(!isset(Enhancement_Plugin::$settings['qq_target_id'], Enhancement_Plugin::$settings['enable_comment_by_qq']), 'Binding requires no manual OpenID setting and does not enable notifications');
    $event['d']['author']['user_openid'] = 'ATTACKER';
    list($body, $headers) = notifyQqSigned($event);
    Enhancement_QqOfficialHelper::webhook($body, $headers);
    notifyCheck(Enhancement_QqOfficialHelper::binding()['target_id'] === 'REAL_PRIVATE_USER', 'Consumed code cannot bind a different QQ sender');

    foreach (array('expired', 'cancelled', 'replaced', 'wrong-type', 'changed-secret', 'wrong-code') as $case) {
        notifyReset(notifyQqConfig()); notifyQqBind();
        $pending = Enhancement_QqOfficialHelper::startBinding();
        if ($case === 'expired') { Enhancement_NotifyTransportHelper::$state['qq_binding_pending']['expires_at'] = time() - 1; }
        if ($case === 'cancelled') { Enhancement_QqOfficialHelper::cancelBinding(); }
        if ($case === 'replaced') { Enhancement_QqOfficialHelper::startBinding(); }
        if ($case === 'wrong-type') { Enhancement_NotifyTransportHelper::$state['qq_binding_pending']['target_type'] = 'group'; }
        if ($case === 'changed-secret') { notifySettings(array('qq_app_secret' => 'new-secret')); }
        $event['d']['content'] = '绑定通知 ' . ($case === 'wrong-code' ? '0000000000000000' : $pending['code']);
        list($body, $headers) = notifyQqSigned($event);
        Enhancement_QqOfficialHelper::webhook($body, $headers);
        notifyCheck((Enhancement_NotifyTransportHelper::read('qq_binding')['target_id'] ?? '') === 'BOUND_USER', 'QQ refuses ' . $case . ' binding');
    }
    notifyReset(notifyQqConfig());
    $pending = Enhancement_QqOfficialHelper::startBinding('group');
    $event = array('op' => 0, 't' => 'GROUP_AT_MESSAGE_CREATE', 'd' => array('content' => '<@123456> ' . $pending['command'], 'group_openid' => 'VERIFIED_GROUP'));
    list($body, $headers) = notifyQqSigned($event);
    Enhancement_QqOfficialHelper::webhook($body, $headers);
    notifyCheck(Enhancement_QqOfficialHelper::binding()['target_id'] === 'VERIFIED_GROUP' && !Enhancement_QqOfficialHelper::status()['bound_user'], 'Optional group binding is distinct from private publishing authorization');
    $status = json_encode(Enhancement_QqOfficialHelper::status());
    notifyCheck(strpos($status, 'test-secret') === false && strpos($status, 'VERIFIED_GROUP') === false, 'Admin QQ status omits secrets and raw recipient identifiers');

    notifyReset(notifyQqConfig(array('qq_moments_enabled' => '1'))); notifyQqBind('c2c', 'PUBLISHING_USER'); notifyQqCached();
    Enhancement_BotMomentsHelper::$handler = static function () { return array('success' => true, 'mid' => 12, 'message' => '已发布瞬间 #12'); };
    $image = array('url' => '//gchat.qpic.cn/image.jpg', 'content_type' => 'image/jpeg');
    $event = array('op' => 0, 'id' => 'outer-event', 't' => 'C2C_MESSAGE_CREATE', 'd' => array('id' => 'original-message',
        'content' => ' <@!123456> 瞬间 QQ发布瞬间测试!~ 🙂', 'timestamp' => date('c'), 'author' => array('user_openid' => 'PUBLISHING_USER'),
        'attachments' => array($image, array('url' => 'https://example.test/a.mp4', 'content_type' => 'video/mp4'))));
    list($body, $headers) = notifyQqSigned($event);
    $calls = array();
    $result = Enhancement_QqOfficialHelper::webhook($body, $headers, notifyQqTransport($calls,
        array(array('status' => 503, 'data' => array('code' => 503, 'message' => 'private upstream secret')))));
    notifyCheck($result['status'] === 200 && count(Enhancement_BotMomentsHelper::$calls) === 1 && count($calls) === 1, 'Reply failure acknowledges completed publishing and saves its independent result');
    $normalized = Enhancement_BotMomentsHelper::$calls[0];
    notifyCheck($normalized[0] === 'qq' && $normalized[1]['conversation_type'] === 'private' && $normalized[1]['account'] === '123456'
        && $normalized[1]['sender'] === 'PUBLISHING_USER' && $normalized[1]['text'] === '瞬间 QQ发布瞬间测试!~ 🙂'
        && is_int($normalized[1]['timestamp']) && $normalized[1]['attachments'] === array($image), 'Signed QQ message preserves real identity, timezone time, emoji and raw image metadata');
    $calls = array();
    $result = Enhancement_QqOfficialHelper::webhook($body, $headers, notifyQqTransport($calls, array(array('id' => 'reply-ok'))));
    notifyCheck($result['status'] === 200 && count(Enhancement_BotMomentsHelper::$calls) === 1 && count($calls) === 1
        && $calls[0]['payload'] === array('content' => '已发布瞬间 #12', 'msg_type' => 0, 'msg_id' => 'original-message', 'msg_seq' => 1), 'Redelivery retries only durable passive reply and cannot publish twice');
    $calls = array();
    Enhancement_QqOfficialHelper::webhook($body, $headers, notifyQqTransport($calls, array()));
    notifyCheck($calls === array() && count(Enhancement_BotMomentsHelper::$calls) === 1, 'Successful publication reply is not resent on another QQ event');
    $event['d']['id'] = 'other-message';
    $event['d']['author']['user_openid'] = 'UNBOUND_USER';
    list($body, $headers) = notifyQqSigned($event);
    Enhancement_QqOfficialHelper::webhook($body, $headers, notifyQqTransport($calls, array()));
    notifyCheck($calls === array() && count(Enhancement_BotMomentsHelper::$calls) === 1, 'A different private sender cannot reach publishing or receive a reply');
    $event['t'] = 'GROUP_AT_MESSAGE_CREATE';
    list($body, $headers) = notifyQqSigned($event);
    Enhancement_QqOfficialHelper::webhook($body, $headers, notifyQqTransport($calls, array()));
    notifyCheck($calls === array() && count(Enhancement_BotMomentsHelper::$calls) === 1, 'QQ group chat cannot publish moments');
    Enhancement_QqOfficialHelper::disconnect();
    notifyCheck(!Enhancement_QqOfficialHelper::status()['bound'], 'Disconnect removes the current verified recipient');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { echo 'QQ official checks passed: ' . $notifyPassed . PHP_EOL; }
