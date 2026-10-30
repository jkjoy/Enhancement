<?php
/** Durable notification checks. Providers and the options database are isolated stubs. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

class Enhancement_Plugin {
    public static $values = array();
    public static function runtimeSettings() { return (object)self::$values; }
}
class Enhancement_NotifyTransportHelper {
    public static $records = array();
    public static $onWrite;
    public static $fail = false;
    public static $failOnceKey;
    public static $maxBytes = 0;
    public static function read(string $key): array {
        if (self::$fail) { throw new RuntimeException('Fixture options database unavailable'); }
        return self::$records[$key] ?? array();
    }
    public static function write(string $key, array $record): void {
        if (self::$fail || !preg_match('/\A[a-z_]{1,40}\z/D', $key)) { throw new RuntimeException('Fixture write rejected'); }
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new RuntimeException('Fixture invalid UTF-8'); }
        self::$maxBytes = max(self::$maxBytes, strlen($json));
        if (strlen($json) >= 65535) { throw new RuntimeException('Fixture MySQL TEXT capacity exceeded'); }
        if (self::$failOnceKey === $key) { self::$failOnceKey = null; throw new RuntimeException('Fixture interrupted write'); }
        self::$records[$key] = json_decode($json, true);
        if (self::$onWrite) { call_user_func(self::$onWrite, $key, $record); }
    }
}
class Enhancement_QqOfficialHelper {
    public static $messages = array();
    public static $fail = false;
    public static function credentialsIdentity($settings = null): string {
        return hash('sha256', $settings['qq_app_id'] . "\n" . $settings['qq_app_secret']);
    }
    public static function binding($settings = null): array {
        $binding = Enhancement_NotifyTransportHelper::read('qq_binding');
        return ($settings['qq_notify_provider'] ?? '') === 'official'
            && ($binding['identity'] ?? '') === self::credentialsIdentity($settings) ? $binding : array();
    }
    public static function send(string $text, $settings = null): array {
        self::$messages[] = $text;
        return self::$fail ? array('success' => false, 'error' => 'Fixture QQ temporarily unavailable') : array('success' => true);
    }
}
class Enhancement_WeixinNotifyHelper {
    public static $messages = array();
    public static $fail = false;
    public static function send(string $text, $settings = null): array {
        self::$messages[] = $text;
        return self::$fail ? array('success' => false, 'error' => 'Fixture Weixin temporarily unavailable') : array('success' => true);
    }
}
require_once dirname(__DIR__, 2) . '/FriendPostsNotifyHelper.php';

$passed = 0;
function check($condition, string $message): void {
    global $passed;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $passed++;
}
function resetNotifications(array $settings = array()): void {
    Enhancement_Plugin::$values = array_replace(array('enable_friend_posts' => '1', 'enable_friend_posts_by_qq' => '1',
        'enable_friend_posts_by_weixin' => '1', 'qq_notify_provider' => 'official', 'qq_app_id' => '123', 'qq_app_secret' => 'fixture-secret'), $settings);
    Enhancement_NotifyTransportHelper::$records = array('qq_binding' => array(
        'identity' => Enhancement_QqOfficialHelper::credentialsIdentity(Enhancement_Plugin::$values),
        'target_type' => 'c2c', 'target_id' => 'fixture-owner', 'bound_at' => 100),
        'weixin_account' => array('account_id' => 'fixture-bot', 'token' => 'fixture-token',
            'owner_id' => 'fixture-recipient', 'base_url' => 'https://fixture.invalid', 'connected_at' => 100),
        'weixin_recipient' => array('user_id' => 'fixture-recipient'));
    Enhancement_NotifyTransportHelper::$fail = false;
    Enhancement_NotifyTransportHelper::$onWrite = null;
    Enhancement_NotifyTransportHelper::$failOnceKey = null;
    Enhancement_NotifyTransportHelper::$maxBytes = 0;
    Enhancement_QqOfficialHelper::$messages = Enhancement_WeixinNotifyHelper::$messages = array();
    Enhancement_QqOfficialHelper::$fail = Enhancement_WeixinNotifyHelper::$fail = false;
}
function feed(string $name, array $items): array {
    return array('key' => hash('sha256', $name), 'friend' => array('name' => '朋友 ' . $name), 'items' => $items);
}
function item(string $path, $published = null, string $title = '文章标题'): array {
    return array('title' => $title, 'url' => 'https://friend.invalid/' . $path, 'published' => $published ?? time() - 1);
}
function observe(array $feeds = array(), array $names = array('a', 'b')): array {
    return Enhancement_FriendPostsNotifyHelper::observe($feeds, array_map(static function ($name) { return hash('sha256', $name); }, $names));
}
function retryDue(string $channel): void {
    foreach (Enhancement_NotifyTransportHelper::$records['friend_posts_' . $channel]['pending'] as &$article) { $article['next_at'] = 0; }
    unset($article);
}
function state(string $channel): array {
    $reader = new ReflectionMethod('Enhancement_FriendPostsNotifyHelper', 'readState');
    $reader->setAccessible(true);
    return $reader->invoke(null, 'friend_posts_' . $channel);
}

resetNotifications(array('enable_friend_posts_by_qq' => '0', 'enable_friend_posts_by_weixin' => '0'));
observe(array(feed('a', array(item('baseline')))));
check(!state('qq') && !state('weixin'), 'both notification switches default to disabled behavior');

resetNotifications();
Enhancement_NotifyTransportHelper::$records['qq_binding'] = array();
$result = observe(array(feed('a', array(item('unbound-baseline')))));
check(!$result['success'] && $result['failed'] === 1 && strpos($result['message'], '绑定') !== false, 'enabled QQ notifications report an unbound recipient instead of silently succeeding');
$result = observe(array(feed('a', array(item('weixin-despite-unbound-qq')))));
check($result['sent'] === 1 && $result['failed'] === 1 && !Enhancement_QqOfficialHelper::$messages
    && count(Enhancement_WeixinNotifyHelper::$messages) === 1, 'unbound QQ does not stop a configured Weixin channel');

resetNotifications();
$baseline = array(item('old'), item('undated-old', 0), item('future', time() + 3600));
observe(array(feed('a', $baseline), feed('b', array(item('b-old')))));
check(!Enhancement_QqOfficialHelper::$messages && !Enhancement_WeixinNotifyHelper::$messages, 'first successful observation only establishes independent baselines');
check(count(state('qq')['feeds'][hash('sha256', 'a')]['seen']) === 2, 'future article remains unseen in the baseline');
$new = item('new-a');
$round = array(feed('a', array_merge(array($new, $new), $baseline)), feed('b', array(item('new-b'), item('b-old'))));
$result = observe($round);
check($result['success'] && $result['sent'] === 2, 'new articles send one merged digest per channel');
check(substr_count(Enhancement_QqOfficialHelper::$messages[0], $new['url']) === 1
    && strpos(Enhancement_QqOfficialHelper::$messages[0], '/new-b') !== false, 'one digest merges feeds and removes repeated URLs');
$changed = $new;
$changed['title'] = '原标题已修改';
$changed['published'] = time();
observe(array(feed('a', array($changed, item('old', time(), '旧文更新时间变了')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 1, 'old URLs do not notify again after title or date changes');
observe(array(feed('b', array($new, item('b-old')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 1, 'an already delivered URL from another feed does not notify twice');
observe(array(feed('a', array(item('future', time() - 1), item('undated-new', 0)))));
check(count(Enhancement_QqOfficialHelper::$messages) === 2, 'future article notifies when due and new undated articles also notify');
$persisted = json_encode(Enhancement_NotifyTransportHelper::$records);
// A refresh with no fetched RSS data models a cleared/missing temporary feed cache.
observe();
Enhancement_NotifyTransportHelper::$records = json_decode($persisted, true);
observe(array(feed('a', array($changed, item('future', time() - 1)))));
check(count(Enhancement_QqOfficialHelper::$messages) === 2, 'notification history survives a missing RSS cache and a persisted state reload');

resetNotifications(array('enable_friend_posts_by_weixin' => '0'));
observe(array(feed('a', array(item('old')))));
observe(array(feed('a', array(item('first')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 1 && !Enhancement_WeixinNotifyHelper::$messages, 'QQ and Weixin switches operate independently');
Enhancement_Plugin::$values['enable_friend_posts_by_weixin'] = '1';
observe(array(feed('a', array(item('second')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 2 && !Enhancement_WeixinNotifyHelper::$messages, 'enabling Weixin creates only its own fresh baseline');
observe(array(feed('a', array(item('third')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 3 && count(Enhancement_WeixinNotifyHelper::$messages) === 1, 'both channels receive updates after their respective baselines');

resetNotifications();
observe(array(feed('a', array(item('old')))));
Enhancement_QqOfficialHelper::$fail = true;
$result = observe(array(feed('a', array(item('retry')))));
check(!$result['success'] && $result['sent'] === 1 && $result['failed'] === 1, 'one failed provider does not block the successful provider');
check(count(state('qq')['pending']) === 1 && !state('weixin')['pending'], 'failed and successful delivery states persist independently');
$pending = current(state('qq')['pending']);
check($pending['attempts'] === 1 && $pending['next_at'] >= time() + 55 && !empty($pending['error']), 'failure persists its retry count, error and cooldown');
observe();
check(count(Enhancement_QqOfficialHelper::$messages) === 1, 'ordinary refreshes cannot bypass retry throttling');
Enhancement_QqOfficialHelper::$fail = false;
retryDue('qq');
$result = observe();
check($result['sent'] === 1 && !state('qq')['pending'] && count(Enhancement_WeixinNotifyHelper::$messages) === 1, 'a later refresh retries the failed channel without resending the successful channel');

resetNotifications(array('enable_friend_posts_by_weixin' => '0'));
observe(array(feed('a', array(item('old')))));
Enhancement_QqOfficialHelper::$fail = true;
observe(array(feed('a', array(item('exhaust')))));
for ($attempt = 0; $attempt < 5; $attempt++) { retryDue('qq'); $result = observe(); }
check(count(Enhancement_QqOfficialHelper::$messages) === 4 && current(state('qq')['pending'])['attempts'] === 4, 'failed notifications stop after four attempts');
check(!state('qq')['last_result']['success'], 'exhausted delivery remains available for diagnosis');
check(!$result['success'] && $result['failed'] === 1 && strpos($result['message'], '停止自动重试') !== false, 'later refreshes keep reporting exhausted notifications without another send');

resetNotifications(array('enable_friend_posts_by_weixin' => '0'));
observe(array(feed('a', array(item('old')))));
Enhancement_QqOfficialHelper::$fail = true;
observe(array(feed('a', array(item('pending-for-old-owner')))));
Enhancement_NotifyTransportHelper::$records['qq_binding']['target_id'] = 'new-owner';
Enhancement_QqOfficialHelper::$fail = false;
observe(array(feed('a', array(item('pending-for-old-owner'), item('new-baseline')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 1 && !state('qq')['pending'], 'a replacement QQ recipient never inherits old pending notifications');
observe(array(feed('a', array(item('after-rebind')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 2, 'a replacement recipient receives only later new URLs');
Enhancement_Plugin::$values['qq_app_secret'] = 'new-fixture-secret';
Enhancement_NotifyTransportHelper::$records['qq_binding']['identity'] = Enhancement_QqOfficialHelper::credentialsIdentity(Enhancement_Plugin::$values);
observe(array(feed('a', array(item('after-credential-change')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 2, 'QQ credential replacement establishes a fresh baseline');
Enhancement_NotifyTransportHelper::$records['qq_binding']['bound_at']++;
observe(array(feed('a', array(item('after-same-owner-rebind')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 2, 'explicitly rebinding the same QQ account also establishes a fresh baseline');

resetNotifications(array('enable_friend_posts_by_qq' => '0'));
observe(array(feed('a', array(item('old')))));
Enhancement_WeixinNotifyHelper::$fail = true;
observe(array(feed('a', array(item('old-weixin-pending')))));
Enhancement_NotifyTransportHelper::$records['weixin_account']['token'] = 'new-fixture-token';
Enhancement_WeixinNotifyHelper::$fail = false;
observe(array(feed('a', array(item('new-weixin-baseline')))));
check(count(Enhancement_WeixinNotifyHelper::$messages) === 1 && !state('weixin')['pending'], 'a new Weixin account session drops the previous pending delivery and starts a baseline');
Enhancement_NotifyTransportHelper::$records['weixin_account']['expired'] = true;
$result = observe(array(feed('a', array(item('during-expired-session')))));
check(count(Enhancement_WeixinNotifyHelper::$messages) === 1 && !state('weixin'), 'expired Weixin accounts do not send');
check(!$result['success'] && $result['failed'] === 1 && strpos($result['message'], '登录已失效') !== false, 'enabled expired Weixin reports a useful login failure');
Enhancement_NotifyTransportHelper::$records['weixin_account']['expired'] = false;
observe(array(feed('a', array(item('after-reconnect')))));
check(count(Enhancement_WeixinNotifyHelper::$messages) === 1, 'reconnecting Weixin creates a new baseline');

resetNotifications();
observe(array(feed('a', array(item('old')))));
Enhancement_NotifyTransportHelper::$records['weixin_account']['expired'] = true;
$result = observe(array(feed('a', array(item('qq-despite-expired-weixin')))));
check(!$result['success'] && $result['sent'] === 1 && $result['failed'] === 1
    && count(Enhancement_QqOfficialHelper::$messages) === 1 && !Enhancement_WeixinNotifyHelper::$messages, 'expired Weixin does not block a valid QQ channel');

resetNotifications(array('enable_friend_posts_by_weixin' => '0'));
observe(array(feed('a', array(item('old'))), feed('b', array(item('b-old')))));
Enhancement_QqOfficialHelper::$fail = true;
observe(array(feed('a', array(item('excluded-pending')))));
retryDue('qq');
Enhancement_QqOfficialHelper::$fail = false;
observe(array(feed('a', array(item('ignored')))), array('b'));
check(count(Enhancement_QqOfficialHelper::$messages) === 1 && !state('qq')['pending']
    && !isset(state('qq')['feeds'][hash('sha256', 'a')]), 'excluded or unapproved feeds stop pending notifications and clear the feed baseline');
observe(array(feed('a', array(item('reapproved-baseline')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 1, 'reapproving a friend establishes a fresh baseline');
Enhancement_Plugin::$values['enable_friend_posts_by_qq'] = '0';
Enhancement_FriendPostsNotifyHelper::syncActivation(Enhancement_Plugin::$values);
check(!state('qq'), 'disabling a channel clears its pending notifications and baseline');
Enhancement_Plugin::$values['enable_friend_posts_by_qq'] = '1';
observe(array(feed('a', array(item('reenable-baseline')))));
check(count(Enhancement_QqOfficialHelper::$messages) === 1, 'reenabling a channel does not send backlog');
Enhancement_Plugin::$values['enable_friend_posts'] = '0';
Enhancement_FriendPostsNotifyHelper::syncActivation(Enhancement_Plugin::$values);
check(!state('qq') && !state('weixin'), 'the master friend-post switch clears all notification state');

resetNotifications(array('enable_friend_posts_by_weixin' => '0'));
observe(array(feed('a', array())));
$many = array();
for ($index = 0; $index < 20; $index++) { $many[] = item(str_repeat('segment-', 65) . sprintf('%03d/end', $index), null, str_repeat('完整中文标题', 50)); }
$result = observe(array(feed('a', $many)));
check($result['sent'] === 3 && count(state('qq')['pending']) > 0, 'large digests send at most three batches and retain whole remaining articles');
for ($round = 0; $round < 10 && state('qq')['pending']; $round++) { observe(); }
$joined = implode("\n", Enhancement_QqOfficialHelper::$messages);
foreach ($many as $article) { check(substr_count($joined, $article['url']) === 1, 'batched article URL stays whole and is delivered exactly once'); }
foreach (Enhancement_QqOfficialHelper::$messages as $text) {
    check(strlen($text) <= 1800 && preg_match('//u', $text) === 1, 'every batch fits 1800 bytes and preserves UTF-8');
}
check(!state('qq')['pending'], 'later refreshes finish the retained digest batches');
$result = observe(array(feed('a', array(item(str_repeat('too-long-', 250))))));
check(!$result['success'] && current(state('qq')['pending'])['attempts'] === 4, 'an impossible URL length is recorded as a terminal failure without cutting the URL');

resetNotifications(array('enable_friend_posts_by_weixin' => '0'));
observe(array(feed('a', array())));
$boundary = array();
foreach (array(0, 1, 2, 3, 200) as $room) {
    $boundary[] = item(str_repeat('x', 1800 - strlen("【朋友文章更新】\n") - 2 - strlen('https://friend.invalid/') - $room));
}
observe(array(feed('a', $boundary)));
observe();
$joined = implode("\n", Enhancement_QqOfficialHelper::$messages);
check(count(Enhancement_QqOfficialHelper::$messages) === 5 && !state('qq')['pending'], 'URLs at the byte boundary are delivered without getting stuck pending');
foreach (Enhancement_QqOfficialHelper::$messages as $text) { check(strlen($text) <= 1800, 'near-limit URL digest stays inside the provider byte limit'); }
foreach ($boundary as $article) { check(strpos($joined, $article['url'] . "\n") !== false, 'near-limit URL is delivered in full'); }

resetNotifications(array('enable_friend_posts_by_weixin' => '0'));
observe(array(feed('a', array(item('old')))));
Enhancement_NotifyTransportHelper::$onWrite = static function ($key, $record) {
    if ($key === 'friend_posts_qq' && !empty($record['pending']) && current($record['pending'])['attempts'] > 0) {
        Enhancement_NotifyTransportHelper::$records['qq_binding']['target_id'] = 'changed-while-queueing';
        Enhancement_NotifyTransportHelper::$onWrite = null;
    }
};
observe(array(feed('a', array(item('must-not-leak')))));
check(!Enhancement_QqOfficialHelper::$messages, 'recipient identity is checked again immediately before provider delivery');
Enhancement_NotifyTransportHelper::$fail = true;
$result = observe(array(feed('a', array(item('database-unavailable')))));
check(!$result['success'] && $result['failed'] === 1, 'notification database errors return a failure result instead of interrupting RSS refresh');

resetNotifications(array('enable_friend_posts_by_weixin' => '0'));
$largeFeeds = $largeNames = array();
for ($friend = 0; $friend < 20; $friend++) {
    $name = 'large-' . $friend;
    $largeNames[] = $name;
    $items = array();
    for ($article = 0; $article < 50; $article++) { $items[] = item("large/baseline/$friend/$article/end"); }
    $largeFeeds[] = feed($name, $items);
}
$result = observe($largeFeeds, $largeNames);
$header = Enhancement_NotifyTransportHelper::$records['friend_posts_qq'];
check($result['success'] && !$result['sent'] && isset($header['parts']) && $header['parts'] > 1,
    'twenty feeds with fifty baseline articles use checked chunks instead of overflowing a MySQL TEXT option');
check(count(state('qq')['feeds']) === 20 && count(state('qq')['feeds'][hash('sha256', $largeNames[0])]['seen']) === 50,
    'all one thousand baseline URLs survive reassembly');
for ($wave = 0; $wave < 2; $wave++) {
    $updates = array();
    foreach ($largeNames as $friend => $name) {
        $items = array();
        for ($article = 0; $article < 50; $article++) { $items[] = item("large/update/$wave/$friend/$article/end", null, '新文章'); }
        $updates[] = feed($name, $items);
    }
    $result = observe($updates, $largeNames);
    check($result['success'], 'a wave of one thousand new articles saves within per-option storage limits');
}
for ($round = 0; $round < 200 && state('qq')['pending']; $round++) { observe(array(), $largeNames); }
$largeState = state('qq');
check(!$largeState['pending'] && count($largeState['delivered']) === 2000, 'multiple refreshes deliver all two thousand new articles and retain their history');
$joined = implode("\n", Enhancement_QqOfficialHelper::$messages);
preg_match_all('~https://friend\.invalid/large/update/[^\s]+~', $joined, $urls);
$counts = array_count_values($urls[0]);
check(count($urls[0]) === 2000 && count($counts) === 2000 && max($counts) === 1,
    'chunked history prevents duplicate delivery throughout two thousand notifications');
check(Enhancement_NotifyTransportHelper::$maxBytes < 65535, 'every options record written remains smaller than MySQL TEXT capacity');
$messageCount = count(Enhancement_QqOfficialHelper::$messages);
Enhancement_NotifyTransportHelper::$records = json_decode(json_encode(Enhancement_NotifyTransportHelper::$records), true);
observe($largeFeeds, $largeNames);
observe($updates, $largeNames);
check(count(Enhancement_QqOfficialHelper::$messages) === $messageCount, 'reloading chunked history and refetching old feeds never republishes historical URLs');
$rowCount = count(Enhancement_NotifyTransportHelper::$records);
for ($round = 0; $round < 3; $round++) { observe(array(), $largeNames); }
check(count(Enhancement_NotifyTransportHelper::$records) === $rowCount, 'fixed chunk banks reuse existing option names across repeated writes');

$before = state('qq');
$beforeHeader = Enhancement_NotifyTransportHelper::$records['friend_posts_qq'];
$inactive = $beforeHeader['bank'] === 'a' ? 'b' : 'a';
Enhancement_NotifyTransportHelper::$failOnceKey = 'friend_posts_qq_' . $inactive . '_b';
$failureFeed = feed($largeNames[0], array(item('large/write-failure/new')));
$result = observe(array($failureFeed), $largeNames);
check(!$result['success'] && state('qq') === $before
    && Enhancement_NotifyTransportHelper::$records['friend_posts_qq'] === $beforeHeader
    && count(Enhancement_QqOfficialHelper::$messages) === $messageCount,
    'an interrupted inactive-bank write preserves the previous complete history and sends nothing');
$result = observe(array($failureFeed), $largeNames);
check($result['success'] && $result['sent'] === 1 && count(state('qq')['delivered']) === 2001,
    'a later refresh recovers an interrupted chunk write without dropping or repeating history');
$messageCount = count(Enhancement_QqOfficialHelper::$messages);
$before = state('qq');
Enhancement_NotifyTransportHelper::$failOnceKey = 'friend_posts_qq';
$failureFeed = feed($largeNames[0], array(item('large/header-failure/new')));
$result = observe(array($failureFeed), $largeNames);
check(!$result['success'] && state('qq') === $before && count(Enhancement_QqOfficialHelper::$messages) === $messageCount,
    'a header commit failure also retains the previous complete history and blocks delivery');
$result = observe(array($failureFeed), $largeNames);
check($result['success'] && $result['sent'] === 1 && count(state('qq')['delivered']) === 2002, 'header commit failures recover on the next refresh');

$savedRecords = Enhancement_NotifyTransportHelper::$records;
$header = $savedRecords['friend_posts_qq'];
$partKey = 'friend_posts_qq_' . $header['bank'] . '_a';
Enhancement_NotifyTransportHelper::$records[$partKey]['data'] = 'corrupt';
$messageCount = count(Enhancement_QqOfficialHelper::$messages);
$result = observe($updates, $largeNames);
check(!$result['success'] && count(Enhancement_QqOfficialHelper::$messages) === $messageCount,
    'invalid chunk checksums fail closed instead of recreating a baseline and sending old articles');
Enhancement_NotifyTransportHelper::$records = $savedRecords;
unset(Enhancement_NotifyTransportHelper::$records[$partKey]);
$result = observe($updates, $largeNames);
check(!$result['success'] && count(Enhancement_QqOfficialHelper::$messages) === $messageCount, 'missing chunks fail closed as an explicit notification error');
Enhancement_NotifyTransportHelper::$records = $savedRecords;
Enhancement_NotifyTransportHelper::$records['qq_binding']['target_id'] = 'large-new-owner';
Enhancement_FriendPostsNotifyHelper::syncActivation(Enhancement_Plugin::$values);
check(!state('qq')['feeds'] && !state('qq')['pending'] && !isset(Enhancement_NotifyTransportHelper::$records['friend_posts_qq']['parts']),
    'identity replacement clears chunked history and restores a small inline state');
$nonemptyParts = array_filter(Enhancement_NotifyTransportHelper::$records, static function ($record, $key) {
    return preg_match('/\Afriend_posts_qq_[ab]_[a-z]+\z/', $key) && $record;
}, ARRAY_FILTER_USE_BOTH);
check(!$nonemptyParts && !Enhancement_NotifyTransportHelper::$records['friend_posts_qq_parts'], 'identity replacement empties old chunk data and its allocation manifest');
observe($largeFeeds, $largeNames);
check(count(Enhancement_QqOfficialHelper::$messages) === $messageCount, 'a new identity establishes a complete large baseline without inheriting old notifications');
observe(array(), array());
check(!state('qq')['feeds'] && !isset(Enhancement_NotifyTransportHelper::$records['friend_posts_qq']['parts']), 'excluding all feeds can shrink a large state back to inline storage');
$nonemptyParts = array_filter(Enhancement_NotifyTransportHelper::$records, static function ($record, $key) {
    return preg_match('/\Afriend_posts_qq_[ab]_[a-z]+\z/', $key) && $record;
}, ARRAY_FILTER_USE_BOTH);
check(!$nonemptyParts, 'shrinking to inline storage empties surplus chunks');

echo "Passed $passed friend article notification checks.\n";
