<?php
/** Isolated cache/integration checks using Typecho's real SQLite query builder and a fake transport. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$typechoRoot = $argv[1] ?? dirname(__DIR__, 4) . '/docker/typechotest/data';
if (!is_file($typechoRoot . '/var/Typecho/Common.php')) {
    fwrite(STDERR, "Usage: php tests/friend-posts/cache.php /path/to/typecho\n");
    exit(1);
}
define('__TYPECHO_ROOT_DIR__', realpath($typechoRoot));
define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
require_once $typechoRoot . '/var/Typecho/Common.php';

class Enhancement_Plugin {
    public static $values = [];
    public static function runtimeSettings() { return (object)self::$values; }
}
class Typecho_Widget {
    public static $siteUrl;
    public static function widget($name) { return (object)['siteUrl' => self::$siteUrl]; }
}
class Enhancement_FriendFeedHelper {
    public static $calls = [];
    public static $fail = false;
    public static function normalizeUrl($url) {
        return is_string($url) && preg_match('~^https?://[^/\s]+(?:/[^\s]*)?$~D', $url) ? $url : '';
    }
    public static function fetch($site, $feed = '') {
        self::$calls[] = [$site, $feed];
        if (self::$fail) throw new RuntimeException('Fixture feed temporarily unavailable');
        $id = strpos($site, 'one') !== false ? 1 : 2;
        return ['url' => $feed ?: $site . 'feed/', 'items' => [
            ['title' => 'Article ' . $id, 'url' => $site . 'new', 'published' => time() - 100 + $id, 'summary' => 'Summary', 'author' => 'Author'],
            ['title' => 'Duplicate', 'url' => 'https://shared.test/article', 'published' => 100, 'summary' => '', 'author' => ''],
            ['title' => 'No date', 'url' => $site . 'undated', 'published' => 0, 'summary' => '', 'author' => ''],
            ['title' => 'Future', 'url' => $site . 'future', 'published' => time() + 86400, 'summary' => '', 'author' => ''],
        ]];
    }
}
class Enhancement_FriendPostsNotifyHelper {
    public static $calls = [];
    public static $fail = false;
    public static $result = null;
    public static function observe(array $feeds, array $activeFeeds): array {
        self::$calls[] = [$feeds, $activeFeeds];
        if (self::$fail) throw new RuntimeException('fixture-secret-not-for-output');
        return self::$result ?? ['success' => true, 'sent' => count($feeds), 'failed' => 0];
    }
}
require_once dirname(__DIR__, 2) . '/FriendPostsHelper.php';

$testDirectory = sys_get_temp_dir() . '/enhancement-friends-tests-' . bin2hex(random_bytes(6));
mkdir($testDirectory, 0700);
Typecho_Widget::$siteUrl = 'https://' . basename($testDirectory) . '.invalid/';
$database = new Typecho_Db('Pdo_SQLite', 'fixture_');
$database->addServer(['file' => $testDirectory . '/fixture.sqlite'], Typecho_Db::READ | Typecho_Db::WRITE);
Typecho_Db::set($database);
$database->query('CREATE TABLE fixture_links (lid INTEGER PRIMARY KEY, name TEXT, url TEXT, image TEXT, email TEXT, state INTEGER, "order" INTEGER)');
foreach ([[1, 'one', 1], [2, 'two', 1], [3, 'pending', 0]] as [$id, $name, $state]) {
    $database->query($database->insert('table.links')->rows(['lid' => $id, 'name' => $name, 'url' => 'https://' . $name . '.test/', 'image' => '', 'email' => 'private@example.test', 'state' => $state, 'order' => $id]));
}
$cacheDirectory = rtrim(sys_get_temp_dir(), '/\\') . '/typecho-enhancement-friends-' . hash('sha256', __TYPECHO_ROOT_DIR__ . "\0" . Typecho_Widget::$siteUrl);
register_shutdown_function(static function () use ($testDirectory, $cacheDirectory) {
    foreach ([$testDirectory, $cacheDirectory] as $directory) {
        foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($directory);
    }
});
$passed = 0;
function check($condition, $message) {
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $passed++;
}
function invalidSettings(array $values) {
    try { Enhancement_FriendPostsHelper::validateSettings($values); return false; }
    catch (Typecho_Widget_Exception $error) { return true; }
}

Enhancement_FriendPostsHelper::validateSettings([]);
Enhancement_FriendPostsHelper::validateSettings(['friend_posts_feeds' => "https://one.test/|https://one.test/custom.xml\nhttps://two.test/|-"]);
foreach ([['friend_posts_interval' => '4'], ['friend_posts_interval' => '1441'], ['friend_posts_per_friend' => '0'], ['friend_posts_per_friend' => '21'], ['friend_posts_interval' => '5.5'], ['enable_friend_posts' => 'yes'], ['friend_posts_feeds' => 'https://one.test/'], ['friend_posts_feeds' => 'https://one.test/|javascript:alert(1)'], ['friend_posts_feeds' => "https://one.test/|-\nhttps://one.test|https://other.test/feed"]] as $values) {
    check(invalidSettings($values), 'invalid setting rejected: ' . json_encode($values));
}
$posts = Enhancement_FriendPostsHelper::getPosts(2, -1);
check(count(Enhancement_FriendFeedHelper::$calls) === 1, 'cold page refreshes one feed');
check($posts['total'] === 3 && $posts['pages'] === 2 && $posts['page'] === 1, 'future filtering and pagination');
check($posts['items'][0]['title'] === 'Article 1', 'first friend is cached');
check(!isset($posts['items'][0]['friend']['email'], $posts['items'][0]['friend']['feed']), 'private link fields are omitted');
$posts = Enhancement_FriendPostsHelper::getPosts(2, 2);
check(count(Enhancement_FriendFeedHelper::$calls) === 2 && $posts['total'] === 5, 'next view warms next approved friend and deduplicates');
$all = Enhancement_FriendPostsHelper::getPosts(100);
check(count(Enhancement_FriendFeedHelper::$calls) === 2, 'fresh caches do not fetch again');
check($all['items'][0]['title'] === 'Article 2' && end($all['items'])['published'] === 0, 'newest articles first, unknown dates last');
check($all['updated'] > 0, 'successful refresh timestamp provided');
check(Enhancement_FriendPostsHelper::getPosts(2, 999)['page'] === 3, 'large page clamped');
check(count(Enhancement_FriendPostsHelper::status()['feeds']) === 2, 'pending friend excluded from status');

Enhancement_FriendFeedHelper::$fail = true;
$failed = Enhancement_FriendPostsHelper::refresh(1, 1);
check(!$failed['success'], 'feed failure reported');
check(Enhancement_FriendPostsHelper::getPosts(100)['total'] === 5, 'failed fetch preserves stale articles');
$status = Enhancement_FriendPostsHelper::status();
check($status['feeds'][0]['error'] !== '' && $status['feeds'][0]['updated'] > 0, 'failure and last successful time retained');
Enhancement_FriendFeedHelper::$fail = false;
Enhancement_FriendPostsHelper::refresh(1, 1);
check(end(Enhancement_FriendFeedHelper::$calls)[1] === '', 'automatic discovery retries after failure');
check(Enhancement_FriendPostsHelper::status()['feeds'][0]['error'] === '', 'successful retry clears error');
$callCount = count(Enhancement_FriendFeedHelper::$calls);
check(!Enhancement_FriendPostsHelper::refresh(1, 3)['success'] && count(Enhancement_FriendFeedHelper::$calls) === $callCount, 'manual refresh cannot fetch unapproved friend');

$database->query($database->update('table.links')->rows(['state' => 0])->where('lid = ?', 1));
check(Enhancement_FriendPostsHelper::getPosts(100)['total'] === 3, 'revoked friend disappears immediately despite old cache');
$database->query($database->delete('table.links')->where('lid = ?', 2));
check(Enhancement_FriendPostsHelper::getPosts()['total'] === 0, 'deleted friend disappears immediately');
$database->query($database->update('table.links')->rows(['state' => 1])->where('lid = ?', 1));

Enhancement_Plugin::$values = ['friend_posts_feeds' => 'https://one.test/|-'];
check(Enhancement_FriendPostsHelper::getPosts()['total'] === 0 && !Enhancement_FriendPostsHelper::status()['feeds'], 'excluded friend hidden immediately');
Enhancement_Plugin::$values = ['friend_posts_feeds' => 'https://one.test/|https://one.test/custom.xml'];
$custom = Enhancement_FriendPostsHelper::getPosts(100);
check(end(Enhancement_FriendFeedHelper::$calls)[1] === 'https://one.test/custom.xml' && $custom['total'] === 3, 'custom feed change invalidates prior cache');
Enhancement_Plugin::$values['friend_posts_per_friend'] = '1';
check(Enhancement_FriendPostsHelper::getPosts(100)['total'] === 1, 'per-friend article limit applies immediately');
$lock = fopen($cacheDirectory . '/refresh.lock', 'c');
flock($lock, LOCK_EX);
$callCount = count(Enhancement_FriendFeedHelper::$calls);
check(!Enhancement_FriendPostsHelper::refresh(1, 1)['success'] && count(Enhancement_FriendFeedHelper::$calls) === $callCount, 'concurrent refresh uses existing cache without duplicate fetch');
flock($lock, LOCK_UN);
fclose($lock);
Enhancement_Plugin::$values['enable_friend_posts'] = '0';
$disabled = Enhancement_FriendPostsHelper::getPosts();
check(!$disabled['enabled'] && !$disabled['items'] && !Enhancement_FriendPostsHelper::refresh(1, 1)['success'], 'disabled feature neither exposes nor refreshes articles');
check(count(Enhancement_FriendFeedHelper::$calls) === $callCount, 'disabled calls do not fetch');
foreach (['enable_friend_posts_by_qq', 'enable_friend_posts_by_weixin'] as $key) {
    check(invalidSettings([$key => ['1']]) && invalidSettings([$key => 'yes']), 'notification switches reject invalid values');
}
Enhancement_Plugin::$values['enable_friend_posts'] = '1';
Enhancement_Plugin::$values['enable_friend_posts_by_qq'] = '1';
$beforeFetch = count(Enhancement_FriendFeedHelper::$calls);
$result = Enhancement_FriendPostsHelper::refresh(100);
check($result['success'] && count(Enhancement_FriendFeedHelper::$calls) === $beforeFetch
    && Enhancement_FriendPostsNotifyHelper::$calls[0][0] === [] && count(Enhancement_FriendPostsNotifyHelper::$calls[0][1]) === 1,
    'fresh feeds still process pending notifications without another network fetch');
$result = Enhancement_FriendPostsHelper::refresh(1, 1);
$observed = end(Enhancement_FriendPostsNotifyHelper::$calls);
$expectedKey = hash('sha256', "1\0https://one.test/\0https://one.test/custom.xml");
check($result['success'] && count($observed[0]) === 1 && $observed[0][0]['key'] === $expectedKey
    && $observed[0][0]['friend']['name'] === 'one' && count($observed[0][0]['items']) === 4,
    'successful refresh forwards all cached articles and stable feed identity to the notifier');
$cached = json_decode(file_get_contents($cacheDirectory . '/' . $expectedKey . '.json'), true);
check($cached['items'] === $observed[0][0]['items'], 'cache is persisted before notification observation');
Enhancement_FriendFeedHelper::$fail = true;
$result = Enhancement_FriendPostsHelper::refresh(1, 1);
check(!$result['success'] && end(Enhancement_FriendPostsNotifyHelper::$calls)[0] === [], 'failed fetch never becomes a new notification observation');
Enhancement_FriendFeedHelper::$fail = false;
Enhancement_FriendPostsNotifyHelper::$fail = true;
$result = Enhancement_FriendPostsHelper::refresh(1, 1);
check(!$result['success'] && strpos($result['message'], 'fixture-secret') === false
    && json_decode(file_get_contents($cacheDirectory . '/' . $expectedKey . '.json'), true)['error'] === '',
    'notification failure is reported safely while retaining the successful feed cache');
Enhancement_FriendPostsNotifyHelper::$fail = false;
Enhancement_FriendPostsNotifyHelper::$result = ['success' => false, 'sent' => 0, 'failed' => 1,
    'message' => 'QQ 朋友文章通知未就绪，请先配置并绑定官方 QQ 机器人接收人。'];
$result = Enhancement_FriendPostsHelper::refresh(100);
check(!$result['success'] && strpos($result['message'], '绑定官方 QQ 机器人接收人') !== false,
    'administrator refresh preserves actionable notification diagnostics');
Enhancement_FriendPostsNotifyHelper::$result = null;
Enhancement_Plugin::$values['friend_posts_feeds'] = 'https://one.test/|-';
Enhancement_FriendPostsHelper::refresh(100);
check(end(Enhancement_FriendPostsNotifyHelper::$calls) === [[], []], 'excluded feeds are removed from the active notification set even without due jobs');
Enhancement_Plugin::$values['enable_friend_posts_by_qq'] = '0';
Enhancement_Plugin::$values['enable_friend_posts_by_weixin'] = '1';
Enhancement_FriendPostsHelper::refresh(100);
check(end(Enhancement_FriendPostsNotifyHelper::$calls) === [[], []], 'Weixin-only notification settings also run the notification pipeline');

echo "Passed $passed friend article cache checks.\n";
