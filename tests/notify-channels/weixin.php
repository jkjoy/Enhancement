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
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::validateSettings(array('enable_comment_by_weixin' => '1')); }, 'Weixin requires a connected account before enabling');
Enhancement_WeixinNotifyHelper::validateSettings(array('enable_comment_by_weixin' => '1'), array('enable_comment_by_weixin' => '1'));
notifyCheck(true, 'An existing enabled Weixin setting does not block unrelated saves after a session expires');
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

notifySettings(array('enable_comment_by_weixin' => '1', 'enable_link_submit_by_weixin' => '1', 'unrelated_setting' => 'preserved'));
weixinConnect();
$connected = Enhancement_WeixinNotifyHelper::status();
notifyCheck($connected['logged_in'] && !$connected['expired'] && $connected['account_id'] === 'fixture-bot' && !$connected['login_pending'], 'Weixin saves the connected account');
notifyCheck(Enhancement_Plugin::$settings['enable_comment_by_weixin'] === '0' && Enhancement_Plugin::$settings['enable_link_submit_by_weixin'] === '0'
    && Enhancement_Plugin::$settings['unrelated_setting'] === 'preserved', 'A confirmed Weixin login disables notifications until the new recipient is ready');
Enhancement_NotifyTransportHelper::write('weixin_result', array('success' => false, 'message' => '微信凭证已失效', 'time' => 123, 'token' => 'private-status-secret'));
$storedStatus = Enhancement_WeixinNotifyHelper::status();
notifyCheck($storedStatus['last_result'] === array('success' => false, 'message' => '微信凭证已失效', 'time' => 123)
    && strpos(json_encode($storedStatus), 'private-status-secret') === false, 'Weixin only exposes public delivery result fields');
weixinThrows(static function () { Enhancement_WeixinNotifyHelper::validateSettings(array('enable_link_submit_by_weixin' => '1')); }, 'Weixin requires recipient binding before enabling');
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

notifySettings(array('enable_comment_by_weixin' => '1'));
$configBefore = Enhancement_Plugin::$settings;
$disconnected = Enhancement_WeixinNotifyHelper::disconnect();
notifyCheck($disconnected['status'] === 'disconnected' && !Enhancement_WeixinNotifyHelper::status()['logged_in'] && Enhancement_WeixinNotifyHelper::status()['user_id'] === '', 'Disconnect removes Weixin credentials and recipient');
notifyCheck(Enhancement_NotifyTransportHelper::read('weixin_result') === array(), 'Disconnect clears Weixin delivery status');
notifyCheck(Enhancement_Plugin::$settings['enable_comment_by_weixin'] === '0' && Enhancement_Plugin::$settings['enable_link_submit_by_weixin'] === '0', 'Disconnect disables Weixin notifications so unrelated configuration can still save');
unset($configBefore['enable_comment_by_weixin'], $configBefore['enable_link_submit_by_weixin']);
$otherSettings = Enhancement_Plugin::$settings;
unset($otherSettings['enable_comment_by_weixin'], $otherSettings['enable_link_submit_by_weixin']);
notifyCheck($otherSettings === $configBefore, 'Disconnect preserves unrelated settings');
notifySettings(array('enable_comment_by_weixin' => '1'));
Enhancement_WeixinNotifyHelper::comment($comment);
notifyCheck(true, 'Enabled but disconnected Weixin does not break comments');

notifyReset();
