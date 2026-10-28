<?php

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/WeixinNotifyHelper.php';

function weixinResponse(array $data, int $status = 200): callable
{
    return static function () use ($data, $status): array { return array('status' => $status, 'data' => $data); };
}

function weixinThrows(callable $operation, string $message): void
{
    $thrown = false;
    try { $operation(); } catch (RuntimeException $exception) { $thrown = true; }
    notifyCheck($thrown, $message);
}

function weixinConnect(string $owner = 'scanner@im.wechat'): void
{
    Enhancement_WeixinNotifyHelper::startLogin(weixinResponse(array('qrcode' => 'qr-private-secret', 'qrcode_img_content' => 'https://ilinkai.weixin.qq.com/qr/login?id=fixture')));
    Enhancement_WeixinNotifyHelper::pollLogin('', weixinResponse(array('status' => 'confirmed', 'bot_token' => 'private-token-fixture',
        'ilink_bot_id' => 'fixture-bot', 'ilink_user_id' => $owner, 'baseurl' => 'https://ilinkai.weixin.qq.com/')));
}

function weixinBindOwner(): void
{
    Enhancement_WeixinNotifyHelper::sync(weixinResponse(array('ret' => 0, 'get_updates_buf' => 'cursor-1', 'msgs' => array(
        array('message_type' => 1, 'from_user_id' => 'scanner@im.wechat', 'context_token' => 'private-context-fixture'),
    ))));
}

notifyReset();

Enhancement_WeixinNotifyHelper::validateSettings(array('enable_comment_by_weixin' => '0'));
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::validateSettings(array('weixin_include_links' => array('1'))); }, 'Weixin rejects array settings');
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::validateSettings(array('weixin_moments_enabled' => array('1'))); }, 'Weixin rejects malformed moment switches');
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::validateSettings(array('enable_comment_by_weixin' => '1')); }, 'Weixin requires a connected account before enabling');
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::validateSettings(array('weixin_moments_enabled' => '1')); }, 'Weixin moment receiving alone requires a connected account');
Enhancement_WeixinNotifyHelper::validateSettings(array('enable_comment_by_weixin' => '1'), array('enable_comment_by_weixin' => '1'));
notifyCheck(true, 'An existing enabled Weixin setting does not block unrelated saves after a session expires');
Enhancement_WeixinNotifyHelper::validateSettings(array('weixin_moments_enabled' => '1'), array('weixin_moments_enabled' => '1'));
notifyCheck(true, 'Existing Weixin moment enablement does not block unrelated saves without a valid connection');
weixinThrows(static function () {
    Enhancement_WeixinNotifyHelper::validateSettings(array('enable_comment_by_weixin' => '1', 'weixin_moments_enabled' => '1'), array('enable_comment_by_weixin' => '1'));
}, 'A previously enabled notification switch does not bypass first-time Weixin moment connection checks');
weixinThrows(static function () {
    Enhancement_WeixinNotifyHelper::validateSettings(array('enable_comment_by_weixin' => '1'), array('enable_comment_by_weixin' => '0'));
}, 'Re-enabling Weixin still requires a valid connected account');
notifyCheck(!Enhancement_WeixinNotifyHelper::send('无连接通知')['success'], 'Weixin send without connection returns a failure');

$startCalls = 0;
$started = Enhancement_WeixinNotifyHelper::startLogin(static function ($method, $url, $headers, $payload, $timeout) use (&$startCalls): array {
    $startCalls++;
    notifyCheck($method === 'POST' && $url === 'https://ilinkai.weixin.qq.com/ilink/bot/get_bot_qrcode?bot_type=3', 'Weixin QR request uses the official API');
    notifyCheck($payload === array('local_token_list' => array()) && $timeout === 12, 'Weixin first login has no local token and bounded timeout');
    notifyCheck(in_array('iLink-App-ClientVersion: 132106', $headers, true), 'Weixin protocol client version is set');
    return array('status' => 200, 'data' => array('qrcode' => 'qr-private-secret', 'qrcode_img_content' => 'https://ilinkai.weixin.qq.com/qr/login'));
});
notifyCheck($startCalls === 1 && $started['status'] === 'wait' && $started['qrcode_url'] === 'https://ilinkai.weixin.qq.com/qr/login', 'Weixin returns official QR content for local rendering');
notifyCheck(Enhancement_WeixinNotifyHelper::status()['login_pending'], 'Weixin status reports the pending login');

foreach (array('http://ilinkai.weixin.qq.com/qr', 'https://evil.example/qr', 'https://ilinkai.weixin.qq.com.evil.example/qr', 'https://user@ilinkai.weixin.qq.com/qr', 'https://ilinkai.weixin.qq.com:443/qr', "https://ilinkai.weixin.qq.com/qr\n") as $unsafe) {
    weixinThrows(static function () use ($unsafe) { Enhancement_WeixinNotifyHelper::startLogin(weixinResponse(array('qrcode' => 'fixture', 'qrcode_img_content' => $unsafe))); }, 'Weixin rejects unsafe QR URLs');
}

$verifyCalls = 0;
weixinThrows(static function () use (&$verifyCalls) {
    Enhancement_WeixinNotifyHelper::pollLogin('12a', static function () use (&$verifyCalls): array { $verifyCalls++; return array(); });
}, 'Weixin rejects non-digit verification codes');
notifyCheck($verifyCalls === 0, 'Invalid verification codes are not sent');
$verification = Enhancement_WeixinNotifyHelper::pollLogin('', weixinResponse(array('status' => 'need_verifycode')));
notifyCheck($verification['status'] === 'need_verifycode' && Enhancement_WeixinNotifyHelper::status()['need_verifycode'], 'Weixin keeps the verification prompt');
Enhancement_WeixinNotifyHelper::pollLogin('123456', static function ($method, $url, $headers, $payload, $timeout): array {
    notifyCheck($method === 'GET' && strpos($url, '&verify_code=123456') !== false && $payload === null && $timeout === 12, 'Weixin submits verification code in the login poll');
    foreach ($headers as $header) { notifyCheck(strpos($header, 'Authorization: Bearer ') !== 0, 'Login polling does not send a bearer token'); }
    return array('status' => 200, 'data' => array('status' => 'scaned'));
});

weixinThrows(static function () { Enhancement_WeixinNotifyHelper::pollLogin('', weixinResponse(array('status' => 'scaned_but_redirect', 'redirect_host' => 'evil.example'))); }, 'Weixin rejects a foreign region host');
Enhancement_WeixinNotifyHelper::pollLogin('', weixinResponse(array('status' => 'scaned_but_redirect', 'redirect_host' => 'region.weixin.qq.com')));
Enhancement_WeixinNotifyHelper::pollLogin('', static function ($method, $url): array {
    notifyCheck(strpos($url, 'https://region.weixin.qq.com/ilink/bot/get_qrcode_status?') === 0, 'Weixin follows a trusted region on the next poll');
    return array('status' => 200, 'data' => array('status' => 'wait'));
});
$wait = Enhancement_WeixinNotifyHelper::pollLogin('', static function (): array { throw new RuntimeException('timeout', 28); });
notifyCheck($wait['status'] === 'wait' && Enhancement_WeixinNotifyHelper::status()['login_pending'], 'A long poll timeout keeps login pending');
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::pollLogin('', weixinResponse(array('status' => 'unknown'))); }, 'Unknown Weixin login states fail');

foreach (array('expired', 'verify_code_blocked', 'binded_redirect') as $terminal) {
    Enhancement_WeixinNotifyHelper::startLogin(weixinResponse(array('qrcode' => 'fixture', 'qrcode_img_content' => 'https://ilinkai.weixin.qq.com/qr')));
    $result = Enhancement_WeixinNotifyHelper::pollLogin('', weixinResponse(array('status' => $terminal)));
    notifyCheck($result['status'] === $terminal && !Enhancement_WeixinNotifyHelper::status()['login_pending'], 'Weixin terminal login status clears the pending QR');
}
Enhancement_NotifyTransportHelper::write('weixin_login', array('qrcode' => 'old', 'started_at' => time() - 301));
$expiredCalls = 0;
$expired = Enhancement_WeixinNotifyHelper::pollLogin('', static function () use (&$expiredCalls): array { $expiredCalls++; return array(); });
notifyCheck($expired['status'] === 'expired' && $expiredCalls === 0, 'Expired Weixin QR codes never reach the network');

notifySettings(array('enable_comment_by_weixin' => '1', 'enable_link_submit_by_weixin' => '1', 'weixin_moments_enabled' => '1', 'unrelated_setting' => 'preserved'));
weixinConnect();
$connected = Enhancement_WeixinNotifyHelper::status();
notifyCheck($connected['logged_in'] && !$connected['expired'] && $connected['account_id'] === 'fixture-bot' && !$connected['login_pending'], 'Weixin saves the connected account');
notifyCheck(Enhancement_Plugin::$settings['enable_comment_by_weixin'] === '0' && Enhancement_Plugin::$settings['enable_link_submit_by_weixin'] === '0' && Enhancement_Plugin::$settings['weixin_moments_enabled'] === '0'
    && Enhancement_Plugin::$settings['unrelated_setting'] === 'preserved', 'A confirmed Weixin login disables notifications until the new recipient is ready');
Enhancement_NotifyTransportHelper::write('weixin_result', array('success' => false, 'message' => '微信凭证已失效', 'time' => 123, 'token' => 'private-status-secret'));
$storedStatus = Enhancement_WeixinNotifyHelper::status();
notifyCheck($storedStatus['last_result'] === array('success' => false, 'message' => '微信凭证已失效', 'time' => 123)
    && strpos(json_encode($storedStatus), 'private-status-secret') === false, 'Weixin only exposes public delivery result fields');
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::validateSettings(array('enable_link_submit_by_weixin' => '1')); }, 'Weixin requires recipient binding before enabling');
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::validateSettings(array('weixin_moments_enabled' => '1')); }, 'Weixin moment receiving alone requires recipient binding');
Enhancement_WeixinNotifyHelper::startLogin(static function ($method, $url, $headers, $payload): array {
    notifyCheck($payload['local_token_list'] === array('private-token-fixture'), 'Weixin relogin supplies its existing token only to the official endpoint');
    return array('status' => 200, 'data' => array('qrcode' => 'new', 'qrcode_img_content' => 'https://ilinkai.weixin.qq.com/qr'));
});

$synced = Enhancement_WeixinNotifyHelper::sync(weixinResponse(array('ret' => 0, 'msgs' => array(
    array('message_type' => 1, 'from_user_id' => 'stranger', 'context_token' => 'foreign-token'),
    array('message_type' => 1, 'from_user_id' => 'scanner@im.wechat', 'context_token' => 'group-token', 'group_id' => 'group'),
    array('message_type' => 2, 'from_user_id' => 'scanner@im.wechat', 'context_token' => 'outgoing-token'),
    array('message_type' => 1, 'from_user_id' => 'fixture-bot', 'context_token' => 'self-token'),
))));
notifyCheck($synced['status'] === 'wait' && Enhancement_WeixinNotifyHelper::status()['candidates'] === array(), 'Only the scanning owner private messages become Weixin candidates');
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::bind('stranger'); }, 'Weixin refuses unrelated recipients');
weixinBindOwner();
$bound = Enhancement_WeixinNotifyHelper::status();
notifyCheck($bound['user_id'] === 'scanner@im.wechat' && $bound['has_context'], 'Weixin auto-binds the scanning owner after a private message');
$statusJson = json_encode($bound);
notifyCheck(strpos($statusJson, 'private-token-fixture') === false && strpos($statusJson, 'private-context-fixture') === false
    && strpos($statusJson, 'qr-private-secret') === false && strpos($statusJson, 'base_url') === false, 'Weixin status omits token, context, QR secret and API base');
Enhancement_WeixinNotifyHelper::validateSettings(array('enable_comment_by_weixin' => '1', 'weixin_include_links' => '0'));
notifyCheck(true, 'Weixin can enable notifications after binding');
Enhancement_WeixinNotifyHelper::validateSettings(array('weixin_moments_enabled' => '1'));
notifyCheck(true, 'Weixin can enable moment receiving alone after binding');

$sentCalls = 0;
$sent = Enhancement_WeixinNotifyHelper::send('<b>通知</b> https://example.test/post', array('weixin_include_links' => '0'), static function ($method, $url, $headers, $payload, $timeout) use (&$sentCalls): array {
    $sentCalls++;
    notifyCheck($method === 'POST' && $url === 'https://ilinkai.weixin.qq.com/ilink/bot/sendmessage' && $timeout === 6, 'Weixin sends to the account API with bounded timeout');
    notifyCheck(in_array('Authorization: Bearer private-token-fixture', $headers, true), 'Weixin sends the account token in an authorization header');
    notifyCheck($payload['msg']['to_user_id'] === 'scanner@im.wechat' && $payload['msg']['context_token'] === 'private-context-fixture', 'Weixin uses the bound recipient context');
    notifyCheck($payload['msg']['item_list'][0]['text_item']['text'] === '通知 [链接已省略]', 'Weixin applies text and link preferences');
    notifyCheck($payload['msg']['message_type'] === 2 && $payload['msg']['message_state'] === 2 && strpos($payload['msg']['client_id'], 'enhancement:') === 0, 'Weixin sends a text message with a unique client ID');
    return array('status' => 200, 'data' => array('ret' => 0, 'message_id' => '12345'));
});
notifyCheck($sentCalls === 1 && $sent['success'] && $sent['id'] === '12345' && $sent['delivery'] === 'accepted', 'Weixin reports server acceptance');
$idOnly = Enhancement_WeixinNotifyHelper::send('通知', array(), weixinResponse(array('message_id' => '12346')));
notifyCheck($idOnly['success'], 'Weixin accepts an explicit server message ID');
$empty = Enhancement_WeixinNotifyHelper::send('通知', array(), weixinResponse(array()));
notifyCheck(!$empty['success'] && strpos($empty['error'], '受理状态') !== false, 'An empty Weixin response is not success');
foreach (array(array('ret' => -2, 'errcode' => 0), array('ret' => 0, 'errcode' => -2), array('ret' => array())) as $failure) {
    $failed = Enhancement_WeixinNotifyHelper::send('通知', array(), weixinResponse($failure));
    notifyCheck(!$failed['success'], 'Every nonzero or malformed Weixin API status fails');
}
$limits = Enhancement_WeixinNotifyHelper::send('通知', array(), weixinResponse(array('ret' => -2)));
notifyCheck(strpos($limits['error'], '主动发送受微信服务端限制') !== false, 'Weixin explains active message restrictions');
$retryCalls = 0;
$timeout = Enhancement_WeixinNotifyHelper::send('通知', array(), static function () use (&$retryCalls): array { $retryCalls++; throw new RuntimeException('timeout', 28); });
notifyCheck(!$timeout['success'] && $retryCalls === 1, 'Weixin never retries an ambiguous send timeout');
class WeixinPrivateRuntimeException extends RuntimeException {}
$privateError = Enhancement_WeixinNotifyHelper::send('通知', array(), static function (): array { throw new WeixinPrivateRuntimeException('SQL private-token-fixture'); });
notifyCheck(!$privateError['success'] && strpos($privateError['error'], 'SQL') === false
    && strpos($privateError['error'], 'private-token-fixture') === false, 'Runtime exception subclasses never expose database or credential details');
notifyCheck(Enhancement_NotifyTransportHelper::read('weixin_result')['error'] === $privateError['error'], 'Weixin stores the sanitized delivery outcome');
$long = Enhancement_WeixinNotifyHelper::send(str_repeat('中文通知', 1000), array(), static function ($method, $url, $headers, $payload): array {
    $text = $payload['msg']['item_list'][0]['text_item']['text'];
    notifyCheck(strlen($text) <= 1800 && preg_match('//u', $text) === 1, 'Weixin truncates text without breaking UTF-8');
    return array('status' => 200, 'data' => array('ret' => 0));
});
notifyCheck($long['success'], 'Weixin accepts a successful API code without a server message ID');

$refresh = Enhancement_WeixinNotifyHelper::sync(static function ($method, $url, $headers, $payload): array {
    notifyCheck($payload['get_updates_buf'] === 'cursor-1', 'Weixin resumes from the last synchronization cursor');
    return array('status' => 200, 'data' => array('ret' => 0, 'get_updates_buf' => 'cursor-2', 'msgs' => array(
        array('message_type' => 1, 'from_user_id' => 'scanner@im.wechat', 'context_token' => 'renewed-context'),
    )));
});
notifyCheck($refresh['status'] === 'bound' && Enhancement_WeixinNotifyHelper::status()['user_id'] === 'scanner@im.wechat', 'Weixin synchronization preserves the bound recipient');
notifyCheck(Enhancement_NotifyTransportHelper::read('weixin_sync')['context_tokens']['scanner@im.wechat'] === 'renewed-context', 'Weixin refreshes conversation context');
$syncWait = Enhancement_WeixinNotifyHelper::sync(static function (): array { throw new RuntimeException('timeout', 28); });
notifyCheck($syncWait['status'] === 'wait' && Enhancement_WeixinNotifyHelper::status()['user_id'] === 'scanner@im.wechat', 'A synchronization timeout keeps binding intact');

notifySettings(array('enable_comment_by_weixin' => '1', 'enable_link_submit_by_weixin' => '1'));
$businessCalls = 0;
$businessMessages = array();
Enhancement_NotifyTransportHelper::$transport = static function ($method, $url, $headers, $payload) use (&$businessCalls, &$businessMessages): array {
    $businessCalls++;
    $businessMessages[] = $payload['msg']['item_list'][0]['text_item']['text'];
    return array('status' => 200, 'data' => array('ret' => 0));
};
$comment = (object)array('coid' => 12, 'status' => 'waiting', 'authorId' => 0, 'ownerId' => 1, 'author' => '读者', 'title' => '测试文章', 'text' => '测试评论', 'permalink' => 'https://example.test/post');
Enhancement_WeixinNotifyHelper::comment($comment);
$ownerComment = clone $comment;
$ownerComment->status = 'approved';
$ownerComment->authorId = '1';
Enhancement_WeixinNotifyHelper::comment($ownerComment);
notifyCheck($businessCalls === 0, 'Unapproved and owner comments do not send Weixin notifications');
$comment->status = 'approved';
Enhancement_WeixinNotifyHelper::comment($comment);
notifyCheck($businessCalls === 1 && strpos($businessMessages[0], '测试评论') !== false, 'Approved reader comments notify Weixin');
Enhancement_WeixinNotifyHelper::mark(array(), $comment, 'waiting');
Enhancement_WeixinNotifyHelper::mark(array(), $comment, 'approved');
notifyCheck($businessCalls === 2, 'Weixin moderation hook sends only approved comments');
notifySettings(array('enable_comment_by_weixin' => '0'));
Enhancement_WeixinNotifyHelper::comment($comment);
notifyCheck($businessCalls === 2, 'Disabled Weixin comment notifications do not send');

if (!class_exists('Typecho_Widget', false)) {
    class Typecho_Widget
    {
        public static function widget($name) { return (object)array('title' => '测试站点', 'adminUrl' => 'https://example.test/admin/'); }
    }
}
if (!class_exists('Typecho_Common', false)) {
    class Typecho_Common
    {
        public static function url($path, $base) { return rtrim($base, '/') . '/' . ltrim($path, '/'); }
    }
}
Enhancement_WeixinNotifyHelper::notifyLinkSubmission(array('lid' => 2, 'name' => '友链', 'url' => 'https://friend.test', 'email' => 'friend@example.test', 'description' => '说明'));
notifyCheck($businessCalls === 3 && strpos($businessMessages[2], '友情链接申请通知') !== false, 'Enabled link submissions notify Weixin');
notifySettings(array('enable_link_submit_by_weixin' => '0'));
Enhancement_WeixinNotifyHelper::notifyLinkSubmission(array());
notifyCheck($businessCalls === 3, 'Disabled link submissions do not send Weixin');
notifySettings(array('enable_comment_by_weixin' => '1'));
Enhancement_NotifyTransportHelper::$transport = static function (): array { throw new RuntimeException('offline'); };
Enhancement_WeixinNotifyHelper::comment($comment);
Enhancement_WeixinNotifyHelper::notifyLinkSubmission(array());
notifyCheck(true, 'Weixin transport errors cannot escape business notification hooks');

foreach (array(array('ret' => -14), array('ret' => 0, 'errcode' => '-14')) as $invalid) {
    weixinConnect();
    weixinBindOwner();
    $invalidResult = Enhancement_WeixinNotifyHelper::send('通知', array(), weixinResponse($invalid));
    notifyCheck(!$invalidResult['success'] && Enhancement_WeixinNotifyHelper::status()['expired'], 'Weixin persists expired credentials from every API code field');
    weixinThrows(static function () { Enhancement_WeixinNotifyHelper::validateSettings(array('enable_comment_by_weixin' => '1')); }, 'Expired Weixin credentials cannot be enabled');
    weixinThrows(static function () { Enhancement_WeixinNotifyHelper::validateSettings(array('weixin_moments_enabled' => '1'), array('weixin_moments_enabled' => '0')); }, 'Expired Weixin credentials cannot newly enable moment receiving');
    Enhancement_WeixinNotifyHelper::validateSettings(array('weixin_moments_enabled' => '1'), array('weixin_moments_enabled' => '1'));
    notifyCheck(true, 'An expired Weixin session preserves unrelated saves when moment receiving was already enabled');
}
weixinConnect();
weixinBindOwner();
$unauthorized = Enhancement_WeixinNotifyHelper::send('通知', array(), weixinResponse(array(), 401));
notifyCheck(!$unauthorized['success'] && Enhancement_WeixinNotifyHelper::status()['expired'], 'HTTP 401 marks Weixin credentials expired');

notifyReset();
weixinConnect('');
$selection = Enhancement_WeixinNotifyHelper::sync(weixinResponse(array('msgs' => array(
    array('message_type' => 1, 'from_user_id' => 'candidate-a', 'context_token' => 'a-context'),
    array('message_type' => 1, 'from_user_id' => 'candidate-b', 'context_token' => 'b-context'),
))));
notifyCheck($selection['status'] === 'select' && count($selection['candidates']) === 2, 'Weixin allows explicit selection when the server provides no owner ID');
Enhancement_WeixinNotifyHelper::bind('candidate-a');
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::bind('candidate-b'); }, 'Weixin cannot replace a recipient without disconnecting');
weixinConnect();
notifyCheck(Enhancement_WeixinNotifyHelper::status()['user_id'] === '' && !Enhancement_WeixinNotifyHelper::status()['has_context'], 'A new confirmed Weixin login clears the previous recipient and context');

notifySettings(array('enable_comment_by_weixin' => '1', 'weixin_moments_enabled' => '1'));
$configBefore = Enhancement_Plugin::$settings;
$disconnected = Enhancement_WeixinNotifyHelper::disconnect();
notifyCheck($disconnected['status'] === 'disconnected' && !Enhancement_WeixinNotifyHelper::status()['logged_in'] && Enhancement_WeixinNotifyHelper::status()['user_id'] === '', 'Disconnect removes Weixin credentials and recipient');
notifyCheck(Enhancement_NotifyTransportHelper::read('weixin_result') === array(), 'Disconnect clears Weixin delivery status');
notifyCheck(Enhancement_Plugin::$settings['enable_comment_by_weixin'] === '0' && Enhancement_Plugin::$settings['enable_link_submit_by_weixin'] === '0' && Enhancement_Plugin::$settings['weixin_moments_enabled'] === '0', 'Disconnect disables Weixin notifications and moment publishing so unrelated configuration can still save');
unset($configBefore['enable_comment_by_weixin'], $configBefore['enable_link_submit_by_weixin'], $configBefore['weixin_moments_enabled']);
$otherSettings = Enhancement_Plugin::$settings;
unset($otherSettings['enable_comment_by_weixin'], $otherSettings['enable_link_submit_by_weixin'], $otherSettings['weixin_moments_enabled']);
notifyCheck($otherSettings === $configBefore, 'Disconnect preserves unrelated settings');
notifySettings(array('enable_comment_by_weixin' => '1'));
Enhancement_WeixinNotifyHelper::comment($comment);
notifyCheck(true, 'Enabled but disconnected Weixin does not break comments');

notifyReset();

function weixinMomentMessage(string $id = 'moment-1', array $overrides = array()): array
{
    return array_replace(array('message_type' => 1, 'message_id' => $id, 'from_user_id' => 'scanner@im.wechat',
        'context_token' => 'moment-context', 'create_time_ms' => 1700000000999,
        'item_list' => array(array('type' => 1, 'text_item' => array('text' => '瞬间 测试内容')))), $overrides);
}

weixinConnect();
notifySettings(array('weixin_moments_enabled' => '1'));
$momentCalls = array();
Enhancement_BotMomentsHelper::$handler = static function ($channel, $message) use (&$momentCalls): ?array {
    $momentCalls[] = array($channel, $message);
    return array('success' => true, 'mid' => 23, 'status' => 'public', 'message' => '瞬间已发布。', 'replayed' => false);
};
$firstBinding = Enhancement_WeixinNotifyHelper::sync(weixinResponse(array('get_updates_buf' => 'first-binding', 'msgs' => array(weixinMomentMessage()))));
notifyCheck($firstBinding['status'] === 'bound' && $momentCalls === array() && $firstBinding['moments_published'] === 0,
    'The first Weixin binding message cannot also publish a moment');

$momentReplyCalls = 0;
$momentSync = Enhancement_WeixinNotifyHelper::sync(static function ($method, $url, $headers, $payload) use (&$momentReplyCalls): array {
    if (strpos($url, '/getupdates') !== false) {
        return array('status' => 200, 'data' => array('ret' => 0, 'get_updates_buf' => 'moments-normalized', 'msgs' => array(
            weixinMomentMessage('moment-normalized', array('context_token' => 'latest-moment-context', 'item_list' => array(
                array('type' => 1, 'text_item' => array('text' => '瞬间 第一段')),
                array('type' => 2, 'text_item' => array('text' => '不得读取图片正文'), 'image_item' => array('media' => 'encrypted-private-media')),
                array('type' => '1', 'text_item' => array('text' => '第二段 ![](https://example.test/a.jpg)')),
                array('type' => 1, 'text_item' => array('text' => array('invalid'))),
            ))),
            weixinMomentMessage('foreign', array('from_user_id' => 'stranger')),
            weixinMomentMessage('group', array('group_id' => 'group')),
            weixinMomentMessage('outgoing', array('message_type' => 2)),
            weixinMomentMessage('self', array('from_user_id' => 'fixture-bot')),
            weixinMomentMessage('another-account', array('to_user_id' => 'another-bot', 'context_token' => 'foreign-account-context')),
            weixinMomentMessage('image-only', array('item_list' => array(array('type' => 2, 'image_item' => array('media' => 'encrypted-private-media'))))),
        )));
    }
    $momentReplyCalls++;
    notifyCheck($payload['msg']['context_token'] === 'moment-context', 'Weixin moment replies use the newest conversation context in the batch');
    notifyCheck($payload['msg']['item_list'][0]['text_item']['text'] === '瞬间已发布。', 'Weixin replies with the shared publisher result');
    return array('status' => 200, 'data' => array('ret' => 0));
});
notifyCheck(count($momentCalls) === 2 && $momentCalls[0] === array('weixin', array('id' => 'moment-normalized', 'sender' => 'scanner@im.wechat',
    'conversation' => 'scanner@im.wechat', 'text' => "瞬间 第一段\n第二段 ![](https://example.test/a.jpg)", 'timestamp' => 1700000000, 'account' => 'fixture-bot',
    'attachments' => array(array('media' => 'encrypted-private-media')))),
    'Weixin normalizes bound-owner text and raw image items while preserving linked Markdown images');
notifyCheck($momentCalls[1][1]['id'] === 'image-only' && $momentCalls[1][1]['text'] === ''
    && $momentCalls[1][1]['attachments'] === array(array('media' => 'encrypted-private-media')),
    'Weixin forwards image-only messages to the shared handler');
notifyCheck($momentReplyCalls === 2 && $momentSync['moments_published'] === 2 && $momentSync['moments_failed'] === 0
    && Enhancement_NotifyTransportHelper::read('weixin_sync')['get_updates_buf'] === 'moments-normalized', 'A handled Weixin moment advances the batch cursor');
$momentStatus = Enhancement_WeixinNotifyHelper::status();
notifyCheck($momentStatus['moments_enabled'] && $momentStatus['moments_last_poll'] > 0
    && strpos(json_encode($momentStatus), 'encrypted-private-media') === false, 'Weixin status exposes moment enablement and polling time without message media');

notifySettings(array('weixin_moments_enabled' => '0'));
Enhancement_WeixinNotifyHelper::sync(weixinResponse(array('msgs' => array(weixinMomentMessage('disabled')))));
notifyCheck(count($momentCalls) === 2 && !Enhancement_WeixinNotifyHelper::status()['moments_enabled'], 'Disabled Weixin moment receiving never calls the publisher');
notifySettings(array('weixin_moments_enabled' => '1'));

$imageItem = array('media' => array('encrypt_query_param' => 'fixture-encrypted-query', 'aes_key' => 'fixture-aes-key', 'encrypt_type' => 1), 'mid_size' => 64);
$draftCalls = array();
Enhancement_BotMomentsHelper::$handler = static function ($channel, $message) use (&$draftCalls): ?array {
    $draftCalls[$message['id']] = $message;
    $result = array('success' => $message['id'] !== 'draft-failed', 'message' => '已处理。');
    if ($message['id'] !== 'legacy-text') { $result['kind'] = $message['id'] === 'image-published' ? 'published' : 'draft'; }
    return $result;
};
$draftReplies = 0;
$draftSync = Enhancement_WeixinNotifyHelper::sync(static function ($method, $url) use ($imageItem, &$draftReplies): array {
    if (strpos($url, '/getupdates') !== false) {
        return array('status' => 200, 'data' => array('get_updates_buf' => 'draft-batch', 'msgs' => array(
            weixinMomentMessage('draft-start', array('item_list' => array(array('type' => 1, 'text_item' => array('text' => '图文瞬间 相册'))))),
            weixinMomentMessage('image-draft', array('item_list' => array(array('type' => '2', 'image_item' => $imageItem)))),
            weixinMomentMessage('image-published', array('item_list' => array(
                array('type' => 1, 'text_item' => array('text' => '瞬间 带图片发布')),
                array('type' => 2, 'image_item' => $imageItem), array('type' => 2, 'image_item' => $imageItem),
                array('type' => 2, 'image_item' => 'invalid'), array('type' => 3, 'image_item' => $imageItem),
            ))), weixinMomentMessage('legacy-text'), weixinMomentMessage('draft-failed'),
        )));
    }
    $draftReplies++;
    return array('status' => 200, 'data' => array('ret' => 0));
});
notifyCheck($draftSync['moments_drafts'] === 2 && $draftSync['moments_published'] === 2 && $draftSync['moments_failed'] === 1 && $draftReplies === 5,
    'Weixin counts accepted drafts separately from published moments and failures while preserving legacy text results');
notifyCheck($draftCalls['image-draft']['text'] === '' && $draftCalls['image-draft']['attachments'] === array($imageItem)
    && $draftCalls['image-published']['attachments'] === array($imageItem, $imageItem) && $draftCalls['image-published']['text'] === '瞬间 带图片发布',
    'Weixin merges raw image items with text and ignores malformed or non-image item types');
notifyCheck(!array_key_exists('attachments', $draftCalls['legacy-text']) && !array_key_exists('attachments', $draftCalls['draft-start']),
    'Weixin text-only messages keep their existing normalized shape');

$momentReplyCalls = 0;
Enhancement_BotMomentsHelper::$handler = static function ($channel, $message): ?array {
    if ($message['id'] === 'ignored') { return null; }
    return array('success' => $message['id'] === 'replayed', 'mid' => 23, 'status' => 'public', 'message' => '命令失败。', 'replayed' => $message['id'] === 'replayed');
};
$failureBatch = Enhancement_WeixinNotifyHelper::sync(static function ($method, $url) use (&$momentReplyCalls): array {
    if (strpos($url, '/getupdates') !== false) {
        return array('status' => 200, 'data' => array('get_updates_buf' => 'moments-failure', 'msgs' => array(
            weixinMomentMessage('ignored'), weixinMomentMessage('replayed'), weixinMomentMessage('rejected'),
        )));
    }
    $momentReplyCalls++;
    return array('status' => 200, 'data' => array('ret' => 0));
});
notifyCheck($failureBatch['moments_failed'] === 1 && $failureBatch['moments_published'] === 0 && $momentReplyCalls === 1
    && Enhancement_NotifyTransportHelper::read('weixin_sync')['get_updates_buf'] === 'moments-failure', 'Ignored and replayed Weixin messages have no receipt while rejected commands still advance the cursor');

Enhancement_BotMomentsHelper::$handler = static function (): ?array {
    return array('success' => true, 'mid' => 24, 'status' => 'private', 'message' => '私密瞬间已发布。', 'replayed' => false);
};
$momentReplyCalls = 0;
$receiptTimeout = Enhancement_WeixinNotifyHelper::sync(static function ($method, $url) use (&$momentReplyCalls): array {
    if (strpos($url, '/getupdates') !== false) {
        return array('status' => 200, 'data' => array('get_updates_buf' => 'receipt-timeout', 'msgs' => array(weixinMomentMessage('receipt-timeout'))));
    }
    $momentReplyCalls++;
    throw new RuntimeException('timeout', 28);
});
notifyCheck($receiptTimeout['moments_published'] === 1 && $momentReplyCalls === 1
    && Enhancement_NotifyTransportHelper::read('weixin_sync')['get_updates_buf'] === 'receipt-timeout', 'A Weixin receipt timeout neither retries publishing nor blocks cursor advancement');

Enhancement_BotMomentsHelper::$handler = static function (): ?array { throw new RuntimeException('database unavailable'); };
$previousSync = Enhancement_NotifyTransportHelper::read('weixin_sync');
weixinThrows(static function () {
    Enhancement_WeixinNotifyHelper::sync(weixinResponse(array('get_updates_buf' => 'must-not-advance', 'msgs' => array(
        weixinMomentMessage('database-failure', array('context_token' => 'context-before-retry')),
    ))));
}, 'Weixin propagates unknown publication storage failures for a later retry');
$afterFailure = Enhancement_NotifyTransportHelper::read('weixin_sync');
notifyCheck($afterFailure['get_updates_buf'] === $previousSync['get_updates_buf'] && $afterFailure['synced_at'] === $previousSync['synced_at']
    && $afterFailure['context_tokens']['scanner@im.wechat'] === 'context-before-retry', 'A failed Weixin publication preserves its cursor and refreshes only reply context');
$leaseAfterFailure = Enhancement_NotifyTransportHelper::acquireLease('weixin_sync_lock', 180);
notifyCheck($leaseAfterFailure !== null, 'Weixin releases its synchronization lease after a publication exception');
$busyNetworkCalls = 0;
$busy = Enhancement_WeixinNotifyHelper::sync(static function () use (&$busyNetworkCalls): array { $busyNetworkCalls++; return array(); });
notifyCheck($busy['status'] === 'busy' && $busyNetworkCalls === 0, 'A concurrent Weixin sync never consumes updates or reaches the network');
Enhancement_NotifyTransportHelper::releaseLease('weixin_sync_lock', $leaseAfterFailure);

$bulkMessages = array();
for ($i = 0; $i < 101; $i++) { $bulkMessages[] = weixinMomentMessage('bulk-' . $i); }
$bulkHandled = 0;
Enhancement_BotMomentsHelper::$handler = static function () use (&$bulkHandled): ?array {
    $bulkHandled++;
    return array('success' => true, 'mid' => $bulkHandled, 'status' => 'public', 'message' => '瞬间已发布。', 'replayed' => false);
};
$bulkReceipts = 0;
$bulk = Enhancement_WeixinNotifyHelper::sync(static function ($method, $url) use ($bulkMessages, &$bulkReceipts): array {
    if (strpos($url, '/getupdates') !== false) {
        return array('status' => 200, 'data' => array('get_updates_buf' => 'all-bulk-handled', 'msgs' => $bulkMessages));
    }
    $bulkReceipts++;
    return array('status' => 200, 'data' => array('ret' => 0));
});
notifyCheck($bulkHandled === 101 && $bulk['moments_published'] === 101 && $bulkReceipts === 10 && $bulk['moments_replies_skipped'] === 91
    && Enhancement_NotifyTransportHelper::read('weixin_sync')['get_updates_buf'] === 'all-bulk-handled', 'Large Weixin batches publish every command and bound receipt work without losing the cursor');

$renewedLeases = array();
Enhancement_BotMomentsHelper::$handler = static function () use (&$renewedLeases): ?array {
    $current = Enhancement_NotifyTransportHelper::read('weixin_sync_lock');
    $renewedLeases[] = ($current['expires_at'] ?? 0) > time() + 170;
    if (count($renewedLeases) === 1) {
        $current['expires_at'] = time() + 5;
        Enhancement_NotifyTransportHelper::write('weixin_sync_lock', $current);
    }
    return null;
};
Enhancement_WeixinNotifyHelper::sync(weixinResponse(array('get_updates_buf' => 'lease-renewed', 'msgs' => array(
    weixinMomentMessage('renew-first'), weixinMomentMessage('renew-second'),
))));
notifyCheck($renewedLeases === array(true, true) && Enhancement_NotifyTransportHelper::read('weixin_sync')['get_updates_buf'] === 'lease-renewed',
    'Weixin renews its synchronization lease before each potentially slow image command');

$lostLeaseCommands = 0;
$replacementLease = str_repeat('f', 32);
Enhancement_BotMomentsHelper::$handler = static function () use (&$lostLeaseCommands, $replacementLease): ?array {
    $lostLeaseCommands++;
    Enhancement_NotifyTransportHelper::write('weixin_sync_lock', array('token' => $replacementLease, 'expires_at' => time() + 180));
    return null;
};
$leaseFailure = '';
try {
    Enhancement_WeixinNotifyHelper::sync(weixinResponse(array('get_updates_buf' => 'lost-lease-must-not-advance', 'msgs' => array(
        weixinMomentMessage('lost-first'), weixinMomentMessage('lost-second'),
    ))));
} catch (RuntimeException $exception) {
    $leaseFailure = $exception->getMessage();
}
notifyCheck($leaseFailure === '微信消息同步锁已失效，请重新同步。' && $lostLeaseCommands === 1
    && Enhancement_NotifyTransportHelper::read('weixin_sync')['get_updates_buf'] === 'lease-renewed'
    && Enhancement_NotifyTransportHelper::read('weixin_sync_lock')['token'] === $replacementLease,
    'Losing a Weixin lease stops the batch, retains the cursor and preserves the replacement worker lock');
Enhancement_NotifyTransportHelper::releaseLease('weixin_sync_lock', $replacementLease);

foreach (array('disconnect', 'relogin-token', 'relogin-account', 'expired') as $accountChange) {
    weixinConnect();
    weixinBindOwner();
    notifySettings(array('weixin_moments_enabled' => '1'));
    $staleCommands = 0;
    Enhancement_BotMomentsHelper::$handler = static function () use (&$staleCommands): ?array { $staleCommands++; return null; };
    $stateAfterChange = array();
    $staleSync = Enhancement_WeixinNotifyHelper::sync(static function () use ($accountChange, &$stateAfterChange): array {
        if ($accountChange === 'disconnect') {
            Enhancement_WeixinNotifyHelper::disconnect();
        } elseif ($accountChange === 'expired') {
            $account = Enhancement_NotifyTransportHelper::read('weixin_account');
            $account['expired'] = true;
            Enhancement_NotifyTransportHelper::write('weixin_account', $account);
        } else {
            Enhancement_WeixinNotifyHelper::startLogin(weixinResponse(array('qrcode' => 'replacement-qr', 'qrcode_img_content' => 'https://ilinkai.weixin.qq.com/qr/login')));
            Enhancement_WeixinNotifyHelper::pollLogin('', weixinResponse(array('status' => 'confirmed', 'bot_token' => 'replacement-token',
                'ilink_bot_id' => $accountChange === 'relogin-token' ? 'fixture-bot' : 'replacement-bot', 'ilink_user_id' => 'replacement-owner',
                'baseurl' => 'https://ilinkai.weixin.qq.com/')));
        }
        foreach (array('weixin_account', 'weixin_sync', 'weixin_recipient') as $key) {
            $stateAfterChange[$key] = Enhancement_NotifyTransportHelper::read($key);
        }
        return array('status' => 200, 'data' => array('get_updates_buf' => 'stale-cursor', 'msgs' => array(
            weixinMomentMessage('stale-command', array('context_token' => 'stale-context')),
        )));
    });
    $stateAfterResponse = array();
    foreach (array('weixin_account', 'weixin_sync', 'weixin_recipient') as $key) {
        $stateAfterResponse[$key] = Enhancement_NotifyTransportHelper::read($key);
    }
    notifyCheck($staleSync['status'] === 'wait' && $staleSync['moments_published'] === 0 && $staleCommands === 0 && $stateAfterChange === $stateAfterResponse,
        'A Weixin account change while polling never restores old state or executes stale commands: ' . $accountChange);
}
Enhancement_BotMomentsHelper::$handler = null;
notifyReset();
