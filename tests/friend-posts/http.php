<?php
/** Offline HTTP integration. Usage: php tests/friend-posts/http.php /path/to/typecho */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80000 || !extension_loaded('curl')) {
    fwrite(STDERR, "These isolated tests require PHP 8+, cURL, DOM and PDO SQLite.\n");
    exit(2);
}
define('ENHANCEMENT_REST_API_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/rest-api/run.php';

$sourceRoot = realpath($argv[1] ?? dirname(__DIR__, 4) . '/docker/typechotest/data');
if (!$sourceRoot || !is_file($sourceRoot . '/var/Typecho/Common.php') || !is_file($sourceRoot . '/install/SQLite.sql')) {
    fwrite(STDERR, "Supply a Typecho source root containing var/Typecho/Common.php and install/SQLite.sql.\n");
    exit(2);
}
$directory = sys_get_temp_dir() . '/enhancement-friend-http-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$root = $directory . '/typecho';
mkdir($root . '/usr/plugins', 0700, true);
foreach (['var', 'install', 'admin'] as $folder) {
    if (!symlink($sourceRoot . '/' . $folder, $root . '/' . $folder)) throw new RuntimeException('Cannot create fixture source link.');
}
if (!symlink(dirname(__DIR__, 2), $root . '/usr/plugins/Enhancement')) throw new RuntimeException('Cannot link fixture plugin.');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Cannot allocate fixture server port.');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$pdo = fixtureDatabase($root, $directory, $base);
$routes = json_decode($pdo->query("SELECT value FROM typecho_options WHERE name='routingTable' AND user=0")->fetchColumn(), true);
$routes['do'] = ['url' => '/action/[action:alpha]', 'widget' => 'Widget_Do', 'action' => 'action'];
$statement = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=? AND user=0');
$statement->execute([json_encode($routes), 'routingTable']);
$statement->execute([json_encode(['enhancement-edit' => 'Enhancement_Action']), 'actionTable']);
$statement->execute([serialize(['enable_friend_posts' => '1', 'friend_posts_feeds' => 'https://friend.example.test/|https://friend.example.test/feed.xml']), 'plugin:Enhancement']);
$pdo->exec("UPDATE typecho_links SET url='https://friend.example.test/' WHERE lid=1");
$pdo->exec("UPDATE typecho_links SET url='https://pending.example.test/' WHERE lid=2");
$pdo->exec("UPDATE typecho_links SET url='https://other.example.test/' WHERE lid=3");
$cacheDirectory = rtrim(sys_get_temp_dir(), '/\\') . '/typecho-enhancement-friends-' . hash('sha256', $root . "\0" . $base . '/');
$env = getenv();
$env['ENHANCEMENT_FRIEND_HTTP_DIR'] = $directory;
$env['ENHANCEMENT_FRIEND_HTTP_ROOT'] = $root;
$log = $directory . '/server.log';
$disabled = 'dns_get_record,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_error,curl_close';
$process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=32767', '-d', 'disable_functions=' . $disabled,
    '-S', $address, __DIR__ . '/router.php'], [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, __DIR__, $env, ['bypass_shell' => true]);
$passed = 0;
$failures = [];

function httpCheck(bool $condition, string $label, $details = null): void
{
    global $passed, $failures;
    if ($condition) { $passed++; return; }
    $failures[] = $label . ($details === null ? '' : ': ' . substr((string)json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2000));
    echo 'FAIL ' . end($failures) . "\n";
}
function httpCookies(array $response): string
{
    $cookies = [];
    foreach ($response['raw_headers'] as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) $cookies[] = trim(explode(';', substr($header, 11), 2)[0]);
    }
    return implode('; ', $cookies);
}
function httpFetches(): int
{
    global $directory;
    return is_file($directory . '/transport.jsonl') ? count(file($directory . '/transport.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
}
function httpSnapshot(): array
{
    global $pdo, $cacheDirectory;
    $cache = [];
    foreach (glob($cacheDirectory . '/*') ?: [] as $file) $cache[basename($file)] = file_get_contents($file);
    ksort($cache);
    return ['links' => $pdo->query('SELECT * FROM typecho_links ORDER BY lid')->fetchAll(PDO::FETCH_ASSOC),
        'settings' => $pdo->query("SELECT value FROM typecho_options WHERE name='plugin:Enhancement' AND user=0")->fetchColumn(),
        'cache' => $cache, 'fetches' => httpFetches()];
}
function httpAction($lid, ?string $token, string $cookie = '', string $method = 'POST', ?string $referer = null): array
{
    global $base;
    $params = ['do' => 'refresh-friend-posts'];
    if ($token !== null) $params['_'] = $token;
    $headers = ['Referer: ' . ($referer ?? $base . '/admin/extending.php?panel=Enhancement%2Fmanage-friend-posts.php')];
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    $body = $lid === null ? [] : ['lid' => $lid];
    if ($method !== 'POST') return fixtureRequest($base, '/action/enhancement-edit?' . http_build_query(array_merge($params, $body)), $method, null, $headers);
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    return fixtureRequest($base, '/action/enhancement-edit?' . http_build_query($params), 'POST', http_build_query($body), $headers);
}
function httpJsonStatus(array $response): bool
{
    $data = $response['json'];
    if (!is_array($data) || !isset($data['success'], $data['message'], $data['enabled'], $data['updated'], $data['feeds'])
        || !is_bool($data['success']) || !is_string($data['message']) || !is_bool($data['enabled']) || !is_int($data['updated']) || !is_array($data['feeds'])) return false;
    foreach ($data['feeds'] as $feed) {
        foreach (['lid', 'count', 'checked', 'updated'] as $key) if (!isset($feed[$key]) || !is_int($feed[$key])) return false;
        foreach (['name', 'url', 'feed_url', 'error'] as $key) if (!isset($feed[$key]) || !is_string($feed[$key])) return false;
        if (!isset($feed['due']) || !is_bool($feed['due'])) return false;
    }
    return str_starts_with($response['headers']['content-type'] ?? '', 'application/json');
}
function httpRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $item) if ($item !== '.' && $item !== '..') httpRemoveTree($path . '/' . $item);
    rmdir($path);
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Fixture server did not start.');
    $anonymous = fixtureRequest($base, '/__fixture/session');
    httpCheck($anonymous['status'] === 200 && !empty($anonymous['json']['token']), 'Fixture creates a real anonymous Typecho CSRF token', $anonymous);
    $before = httpSnapshot();
    $r = httpAction(1, $anonymous['json']['token'] ?? '');
    httpCheck($r['status'] === 302, 'Anonymous request with valid CSRF is refused by administrator login', $r);
    httpCheck(httpSnapshot() === $before, 'Anonymous request cannot change settings, links, caches or fetch feeds');

    $contributor = fixtureRequest($base, '/__fixture/session', 'POST', ['uid' => 2]);
    $contributorCookie = httpCookies($contributor);
    httpCheck(!empty($contributor['json']['logged_in']) && $contributorCookie !== '', 'Fixture creates a real contributor login', $contributor);
    $before = httpSnapshot();
    $r = httpAction(1, $contributor['json']['token'] ?? '', $contributorCookie);
    httpCheck($r['status'] === 403, 'Contributor with valid CSRF cannot use administrator refresh', $r);
    httpCheck(httpSnapshot() === $before, 'Non-administrator request leaves all business state and transport untouched');

    $admin = fixtureRequest($base, '/__fixture/session', 'POST', ['uid' => 1]);
    $cookie = httpCookies($admin);
    $token = $admin['json']['token'] ?? '';
    httpCheck(!empty($admin['json']['logged_in']) && $cookie !== '' && $token !== '', 'Fixture creates a real administrator login', $admin);
    foreach ([null, 'invalid-token', $contributor['json']['token'] ?? ''] as $csrf) {
        $before = httpSnapshot();
        $r = httpAction(1, $csrf, $cookie);
        httpCheck($r['status'] === 302 && httpSnapshot() === $before, 'Missing, forged or another user CSRF token cannot fetch or mutate data', $r);
    }
    $before = httpSnapshot();
    $r = httpAction(1, $token, $cookie, 'POST', $base . '/different-page');
    httpCheck($r['status'] === 302 && httpSnapshot() === $before, 'CSRF token is bound to the submitting page referer', $r);
    $r = httpAction(1, $token, $cookie, 'GET');
    httpCheck($r['status'] === 405 && ($r['headers']['allow'] ?? '') === 'POST' && httpSnapshot() === $before, 'Authenticated GET is rejected with Allow POST and no side effects', $r);
    foreach ([null, 0, -1, [1], '1abc', '1.0', '1e0', '+1'] as $lid) {
        $r = httpAction($lid, $token, $cookie);
        httpCheck($r['status'] === 400 && ($r['json']['success'] ?? null) === false && httpSnapshot() === $before, 'Only a positive integer lid is accepted without coercing arrays or malformed input', $r);
    }
    foreach ([999, 2] as $lid) {
        $fetches = httpFetches();
        $r = httpAction($lid, $token, $cookie);
        httpCheck($r['status'] === 200 && ($r['json']['success'] ?? null) === false && httpFetches() === $fetches, 'Unknown or unapproved friend cannot be fetched', $r);
        httpCheck(httpJsonStatus($r) && array_column($r['json']['feeds'], 'lid') === [1, 3], 'Rejected valid-lid response contains typed status for approved friends only', $r);
    }

    $r = httpAction(1, $token, $cookie);
    httpCheck($r['status'] === 200 && ($r['json']['success'] ?? null) === true && httpJsonStatus($r), 'Administrator POST returns successful typed JSON status', $r);
    $feed = $r['json']['feeds'][0] ?? [];
    httpCheck(($feed['count'] ?? 0) === 1 && ($feed['checked'] ?? 0) > 0 && ($feed['updated'] ?? 0) > 0 && ($feed['error'] ?? null) === '' && ($feed['due'] ?? true) === false, 'Successful refresh updates only the requested friend cache', $r);
    httpCheck(httpFetches() === 1 && ($r['json']['feeds'][1]['checked'] ?? -1) === 0, 'One refresh performs exactly one offline fetch and leaves other friends cold');

    file_put_contents($directory . '/transport-fail', '1');
    $r = httpAction(1, $token, $cookie);
    httpCheck($r['status'] === 200 && ($r['json']['success'] ?? null) === false && httpJsonStatus($r)
        && ($r['json']['feeds'][0]['count'] ?? 0) === 1 && ($r['json']['feeds'][0]['updated'] ?? 0) === $feed['updated']
        && str_contains($r['json']['feeds'][0]['error'] ?? '', 'Offline fixture transport failed'), 'Failed fetch returns a site error while preserving old articles and last success', $r);
    unlink($directory . '/transport-fail');
    $before = httpSnapshot();
    $r = httpAction(1, 'invalid-token', $cookie);
    httpCheck($r['status'] === 302 && httpSnapshot() === $before, 'Rejected CSRF cannot change existing warm cache');

    foreach ([['friend_posts_interval' => '4'], ['friend_posts_per_friend' => '21'], ['friend_posts_feeds' => 'https://friend.example.test/|javascript:alert(1)']] as $invalid) {
        $before = httpSnapshot();
        $r = fixtureRequest($base, '/__fixture/config', 'POST', $invalid);
        httpCheck($r['status'] >= 400 && httpSnapshot() === $before, 'Actual config save rejects invalid friend settings without modifying stored data', $r);
    }
    $r = fixtureRequest($base, '/__fixture/config', 'POST', ['enable_friend_posts' => '0']);
    httpCheck($r['status'] === 200, 'Feature can be disabled through the real config save', $r);
    $before = httpSnapshot();
    $r = httpAction(1, $token, $cookie);
    httpCheck($r['status'] === 200 && httpJsonStatus($r) && ($r['json']['success'] ?? null) === false
        && ($r['json']['enabled'] ?? null) === false && httpSnapshot() === $before, 'Disabled feature returns state without fetching or modifying cache', $r);
    fixtureRequest($base, '/__fixture/config', 'POST', ['enable_friend_posts' => '0']);
    $panels = json_decode($pdo->query("SELECT value FROM typecho_options WHERE name='panelTable' AND user=0")->fetchColumn(), true);
    $panelUrl = 'extending.php?panel=' . urlencode('Enhancement/manage-friend-posts.php');
    $friendPanels = array_filter($panels['child'][3] ?? [], static fn($panel) => ($panel[2] ?? '') === $panelUrl);
    httpCheck(count($friendPanels) === 1 && in_array(urlencode('Enhancement/manage-friend-posts.php'), $panels['file'] ?? [], true), 'In-place config saves register the new panel once without reactivating plugin', $panels);

    echo $passed . ' friend article HTTP assertions passed; ' . count($failures) . " failed.\n";
} catch (Throwable $error) {
    $failures[] = $error->getMessage();
    fwrite(STDERR, $error->getMessage() . "\n");
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $pdo = null;
    if (!$failures) { httpRemoveTree($cacheDirectory); httpRemoveTree($directory); }
    else echo 'Retained isolated fixture and server log: ' . $log . "\n";
}
exit($failures ? 1 : 0);
