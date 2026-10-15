<?php
/** Isolated integration tests. Usage: php tests/rest-api/run.php [Typecho root]. */
declare(strict_types=1);

function fixtureInsert(PDO $pdo, string $table, array $row): void
{
    $columns = array_map(static fn($name) => '"' . $name . '"', array_keys($row));
    $statement = $pdo->prepare('INSERT INTO typecho_' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
    $statement->execute(array_values($row));
}

function fixtureDatabase(string $root, string $directory, string $base): PDO
{
    $pdo = new PDO('sqlite:' . $directory . '/fixture.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents($root . '/install/SQLite.sql'));
    $pdo->exec('CREATE TABLE typecho_links (lid INTEGER PRIMARY KEY, name TEXT, url TEXT, description TEXT, image TEXT, sort TEXT, state INTEGER, "order" INTEGER)');
    $routes = [
        'post' => ['url' => '/archives/[cid:digital]/', 'widget' => 'Widget_Archive', 'action' => 'render'],
        'page' => ['url' => '/[slug].html', 'widget' => 'Widget_Archive', 'action' => 'render'],
        'category' => ['url' => '/category/[slug]/', 'widget' => 'Widget_Archive', 'action' => 'render'],
        'tag' => ['url' => '/tag/[slug]/', 'widget' => 'Widget_Archive', 'action' => 'render'],
        'author' => ['url' => '/author/[uid:digital]/', 'widget' => 'Widget_Archive', 'action' => 'render'],
        'feedback' => ['url' => '/action/feedback', 'widget' => 'Widget_Feedback', 'action' => 'action'],
        'fixture_preserved' => ['url' => '/fixture/preserved', 'widget' => 'Widget_Archive', 'action' => 'render'],
    ];
    $options = [
        'siteUrl' => $base . '/', 'title' => 'REST fixture site', 'description' => 'Fixture description',
        'theme' => 'default', 'charset' => 'UTF-8', 'contentType' => 'text/html', 'lang' => 'zh_CN',
        'timezone' => 28800, 'installed' => 0, 'rewrite' => 1, 'generator' => 'Typecho 1.3.0',
        'secret' => 'isolated-rest-api-fixture-secret', 'routingTable' => json_encode($routes),
        'plugins' => json_encode(['activated' => [], 'handles' => []]), 'plugin:Enhancement' => serialize([]),
        'actionTable' => '{}', 'panelTable' => '{}', 'pageSize' => 10, 'defaultCategory' => 1,
        'commentsRequireModeration' => 1, 'commentsRequireMail' => 1, 'commentsRequireUrl' => 0,
        'commentsWhitelist' => 0, 'commentsThreaded' => 1, 'commentsMaxNestingLevels' => 5,
        'commentsAntiSpam' => 1, 'commentsCheckReferer' => 0, 'commentsAutoClose' => 0,
        'commentsPostIntervalEnable' => 0, 'commentsPostInterval' => 60, 'commentsPostTimeout' => 0,
        'commentsMarkdown' => 0, 'commentsHTMLTagAllowed' => '<a href=""> <b> <strong> <em> <code>',
        'commentsShowUrl' => 1, 'commentsUrlNofollow' => 1, 'commentsPageBreak' => 0,
        'commentsPageSize' => 20, 'commentsOrder' => 'ASC', 'commentsShowCommentOnly' => 1,
        'commentsAvatarRating' => 'G', 'commentsPageDisplay' => 'last', 'commentsAvatar' => 1,
        'defaultAllowComment' => 1, 'defaultAllowPing' => 1, 'defaultAllowFeed' => 1,
        'markdown' => 1, 'allowRegister' => 0, 'frontPage' => 'recent',
    ];
    foreach ($options as $name => $value) {
        fixtureInsert($pdo, 'options', ['name' => $name, 'user' => 0, 'value' => (string)$value]);
    }
    $now = time() - 3600;
    foreach ([1 => 'administrator', 2 => 'contributor'] as $id => $group) {
        fixtureInsert($pdo, 'users', ['uid' => $id, 'name' => 'fixture' . $id, 'screenName' => 'Fixture author ' . $id, 'password' => 'private-password', 'authCode' => 'private-token', 'mail' => 'private' . $id . '@example.test', 'url' => 'https://example.test/user' . $id, 'created' => $now, 'group' => $group]);
    }
    $contents = [
        [1, 'alpha', 'Alpha public', 'post', 'publish', '', $now, 1, 1],
        [2, 'beta', 'Beta public', 'post', 'publish', null, $now + 1, 2, 1],
        [3, 'password', 'Secret password', 'post', 'publish', 'secret', $now, 1, 1],
        [4, 'draft', 'Secret draft', 'post', 'draft', '', $now, 1, 1],
        [5, 'future', 'Secret future', 'post', 'publish', '', time() + 86400, 1, 1],
        [6, 'about', 'About page', 'page', 'publish', '', $now, 1, 1],
        [7, 'closed', 'Closed public', 'post', 'publish', '', $now + 2, 1, 0],
        [8, 'hidden-page', 'Hidden page', 'page', 'publish', 'secret', $now, 1, 1],
        [9, 'zero-password', 'Zero password', 'post', 'publish', '0', $now, 1, 1],
    ];
    foreach ($contents as [$id, $slug, $title, $type, $status, $password, $created, $author, $allow]) {
        fixtureInsert($pdo, 'contents', ['cid' => $id, 'slug' => $slug, 'title' => $title, 'type' => $type, 'status' => $status, 'password' => $password, 'created' => $created, 'modified' => $created, 'authorId' => $author, 'allowComment' => (string)$allow, 'allowPing' => '1', 'allowFeed' => '1', 'text' => '<!--markdown-->Fixture body ' . $slug, 'commentsNum' => $id === 1 ? 1 : 0]);
    }
    foreach ([[1, 'News', 'news', 'category'], [2, 'Tools', 'tools', 'category'], [3, 'PHP', 'php', 'tag'], [4, 'Typecho', 'typecho', 'tag']] as [$id, $name, $slug, $type]) {
        fixtureInsert($pdo, 'metas', ['mid' => $id, 'name' => $name, 'slug' => $slug, 'type' => $type, 'count' => 5]);
    }
    foreach ([[1, 1], [1, 3], [1, 4], [2, 2], [2, 3], [3, 1], [3, 3], [4, 1], [4, 3], [5, 1], [5, 3], [6, 1], [7, 2]] as [$cid, $mid]) {
        fixtureInsert($pdo, 'relationships', ['cid' => $cid, 'mid' => $mid]);
    }
    fixtureInsert($pdo, 'fields', ['cid' => 1, 'name' => 'summary', 'type' => 'str', 'str_value' => 'Alpha summary']);
    foreach ([[1, 1, 'approved'], [2, 1, 'waiting'], [3, 1, 'spam'], [4, 3, 'approved'], [5, 4, 'approved'], [6, 5, 'approved'], [7, 6, 'approved']] as [$id, $cid, $status]) {
        fixtureInsert($pdo, 'comments', ['coid' => $id, 'cid' => $cid, 'created' => $now + $id, 'author' => 'Reader ' . $id, 'ownerId' => 1, 'mail' => 'reader' . $id . '@example.test', 'url' => '', 'text' => '<p>Fixture comment ' . $id . '</p>', 'type' => 'comment', 'status' => $status, 'parent' => 0]);
    }
    foreach ([[1, 1, 'friends'], [2, 0, 'friends'], [3, 1, 'tools']] as [$id, $state, $sort]) {
        fixtureInsert($pdo, 'links', ['lid' => $id, 'name' => 'Link ' . $id, 'url' => 'https://example.test/' . $id, 'description' => 'Link fixture', 'image' => '', 'sort' => $sort, 'state' => $state, 'order' => $id]);
    }
    return $pdo;
}

function fixtureRequest(string $base, string $path, string $method = 'GET', $body = null, array $headers = []): array
{
    $headers[] = 'Connection: close';
    if (!array_filter($headers, static fn($header) => stripos($header, 'User-Agent:') === 0)) {
        $headers[] = 'User-Agent: Enhancement-RestAPI-Isolated-Tests/1.0';
    }
    if (is_array($body)) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($body);
    }
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]]);
    $body = file_get_contents($base . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})(?:\s|$)/', $responseHeaders[0] ?? '', $status);
    $mapped = [];
    foreach (array_slice($responseHeaders, 1) as $header) {
        if (strpos($header, ':') !== false) {
            [$name, $value] = explode(':', $header, 2);
            $mapped[strtolower($name)] = trim($value);
        }
    }
    return ['status' => (int)($status[1] ?? 0), 'headers' => $mapped, 'raw_headers' => $responseHeaders, 'body' => $body, 'json' => json_decode((string)$body, true)];
}

if (PHP_SAPI === 'cli-server') {
    require __DIR__ . '/router.php';
    exit;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Reuse only the disposable fixture builders from another CLI test runner.
if (defined('ENHANCEMENT_REST_API_FIXTURE_LIBRARY') && ENHANCEMENT_REST_API_FIXTURE_LIBRARY === true) {
    return;
}

$root = realpath($argv[1] ?? dirname(__DIR__, 5));
if (!$root || !is_file($root . '/var/Typecho/Common.php') || !is_file($root . '/install/SQLite.sql')) {
    fwrite(STDERR, "Supply a Typecho root containing var/Typecho/Common.php and install/SQLite.sql.\n");
    exit(2);
}
$directory = sys_get_temp_dir() . '/enhancement-rest-api-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$pdo = fixtureDatabase($root, $directory, $base);
$env = getenv();
$env['ENHANCEMENT_REST_API_TEST_DIR'] = $directory;
$env['ENHANCEMENT_REST_API_TEST_ROOT'] = $root;
$log = $directory . '/server.log';
$process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=32767', '-S', $address, __FILE__], [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, __DIR__, $env, ['bypass_shell' => true]);
$passed = 0;
$failures = [];
function check(bool $condition, string $label, $details = null): void
{
    global $passed, $failures;
    if ($condition) {
        $passed++;
    } else {
        $failures[] = $label . ($details === null ? '' : ': ' . substr((string)json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2000));
        echo 'FAIL ' . end($failures) . "\n";
    }
}
function ids(array $response): array { return array_map('intval', array_column($response['json'] ?? [], 'id')); }
function total(array $response): int { return (int)($response['headers']['x-wp-total'] ?? -1); }
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) { throw new RuntimeException('Fixture server did not start.'); }
    $r = fixtureRequest($base, '/wp-json');
    check($r['status'] === 404, 'REST is off by default', $r);
    $r = fixtureRequest($base, '/wp-json/wp/v2/posts', 'OPTIONS');
    check($r['status'] === 404, 'Disabled OPTIONS is not publicly available', $r);
    $r = fixtureRequest($base, '/__fixture/config', 'POST', ['enable_rest_api' => '1']);
    check($r['status'] === 200 && ($r['json']['rest_routes'] ?? 0) === 76, 'Saving enabled registers all 76 routes', $r);
    check(!empty($r['json']['preserved']), 'Saving enabled preserves other routes', $r);
    require_once dirname(__DIR__, 2) . '/RestApiHelper.php';
    foreach (Enhancement_RestApiHelper::routes() as $name => $route) {
        $path = str_replace(['[cid:digital]', '[mid:digital]', '[uid:digital]', '[slug]'], ['1', '1', '1', 'alpha'], $route['url']);
        if ($route['action'] === 'posts_by_tag') { $path = str_replace('alpha', 'php', $path); }
        if ($route['action'] === 'page') { $path = str_replace('/pages/1', '/pages/6', $path); }
        if ($route['action'] === 'page_by_slug') { $path = str_replace('alpha', 'about', $path); }
        if ($route['action'] === 'tag') { $path = str_replace('/tags/1', '/tags/3', $path); }
        $r = fixtureRequest($base, $path);
        check($r['status'] === 200 && is_array($r['json']), 'Route ' . $name, $r);
    }
    $r = fixtureRequest($base, '/wp-json/wp/v2/posts?per_page=2&orderby=id&order=asc');
    check(ids($r) === [1, 2] && total($r) === 3 && ($r['headers']['x-wp-totalpages'] ?? '') === '2', 'Public posts and pagination', $r);
    foreach ([3 => 'password', 4 => 'draft', 5 => 'future', 9 => 'zero-password'] as $id => $slug) {
        foreach (['/wp-json/wp/v2/posts/' . $id, '/wp-json/wp/v2/posts/slug/' . $slug] as $path) {
            $r = fixtureRequest($base, $path);
            check($r['status'] === 404, 'Hidden content ' . $path, $r);
        }
    }
    foreach (['categories=1' => [1], 'tags=3' => [2, 1], 'search=Alpha' => [1], 'include=1,2&exclude=2' => [1], 'author=2' => [2], 'slug=alpha' => [1]] as $query => $expected) {
        $r = fixtureRequest($base, '/wp-json/wp/v2/posts?' . $query);
        check(ids($r) === $expected && total($r) === count($expected), 'Filtered rows and totals ' . $query, $r);
    }
    $r = fixtureRequest($base, '/wp-json/wp/v2/posts/tag/php');
    check(ids($r) === [2, 1] && total($r) === 2, 'Tag slug filters public posts', $r);
    $r = fixtureRequest($base, '/wp-json/wp/v2/categories?slug=news');
    check(ids($r) === [1] && total($r) === 1, 'Category slug filter', $r);
    foreach (['categories?include=1,2&exclude=2' => [1], 'tags?include=3,4&exclude=4' => [3]] as $query => $expected) {
        $r = fixtureRequest($base, '/wp-json/wp/v2/' . $query);
        check(ids($r) === $expected && total($r) === count($expected), 'Term exclude filters rows and totals ' . $query, $r);
    }
    $r = fixtureRequest($base, '/wp-json/wp/v2/links?per_page=1');
    check(ids($r) === [1] && total($r) === 2 && ($r['headers']['x-wp-totalpages'] ?? '') === '2', 'Approved links pagination', $r);
    $r = fixtureRequest($base, '/wp-json/wp/v2/links?state=0');
    check(ids($r) === [], 'Query cannot expose unapproved links', $r);
    $r = fixtureRequest($base, '/wp-json/wp/v2/comments?per_page=100');
    check(ids($r) === [7, 1] && total($r) === 2, 'Only approved comments on public content', $r);
    foreach (['any', 'all', 'hold', 'waiting', 'spam'] as $status) {
        $r = fixtureRequest($base, '/wp-json/wp/v2/comments?status=' . $status);
        check(!array_intersect(ids($r), [2, 3, 4, 5, 6]), 'Status cannot expose private comments ' . $status, $r);
    }
    $r = fixtureRequest($base, '/wp-json/wp/v2/users');
    check($r['status'] === 200 && !str_contains($r['body'], 'private-password') && !str_contains($r['body'], 'private-token') && !str_contains($r['body'], '@example.test'), 'Users omit account secrets and email', $r);
    $r = fixtureRequest($base, '/__fixture/config', 'POST', ['rest_api_site_title' => 'API title', 'rest_api_site_description' => 'API description', 'rest_api_site_icp' => 'ICP fixture']);
    $r = fixtureRequest($base, '/wp-json/wp/v2/settings');
    check(($r['json']['site_title'] ?? '') === 'API title' && ($r['json']['site_description'] ?? '') === 'API description' && ($r['json']['site_icp'] ?? '') === 'ICP fixture', 'REST settings use saved overrides', $r);
    foreach (['/wp-json', '/wp-json/wp/v2/posts', '/wp-json/wp/v2/users'] as $path) {
        $r = fixtureRequest($base, $path, 'POST', []);
        check($r['status'] === 405, 'Read endpoint refuses POST ' . $path, $r);
    }
    $r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'DELETE');
    check($r['status'] === 405, 'Comments refuse unsupported method', $r);
    $r = fixtureRequest($base, '/wp-json/wp/v2/posts', 'OPTIONS');
    check($r['status'] === 204 && $r['body'] === '' && ($r['headers']['access-control-allow-origin'] ?? '') === '*', 'Enabled CORS preflight', $r);
    $r = fixtureRequest($base, '/wp-json/wp/v2/posts', 'HEAD');
    check($r['status'] === 200 && $r['body'] === '' && total($r) === 3, 'HEAD preserves collection headers without body', $r);

    require __DIR__ . '/comments.php';

    $storedRoutes = json_decode($pdo->query("SELECT value FROM typecho_options WHERE name = 'routingTable' AND user = 0")->fetchColumn(), true);
    unset($storedRoutes[0]);
    $storedRoutes['post']['url'] = '/[year:digital:4]/[month:digital:2]/[category]/[slug].html';
    fixtureRequest($base, '/__fixture/options', 'POST', ['rewrite' => 0, 'routingTable' => $storedRoutes]);
    $r = fixtureRequest($base, '/index.php/wp-json/wp/v2/posts/1');
    check($r['status'] === 200 && preg_match('~/index\.php/\d{4}/\d{2}/news/alpha\.html$~', $r['json']['link'] ?? '') === 1, 'Core permalink honors no-rewrite and date/category template', $r);
    $r = fixtureRequest($base, '/index.php/wp-json/wp/v2/comments?post=1');
    check($r['status'] === 200 && str_starts_with($r['json'][0]['_links']['self'][0]['href'] ?? '', $base . '/index.php/wp-json/wp/v2/comments/'), 'Comment API links honor no-rewrite index', $r);

    $r = fixtureRequest($base, '/__fixture/config', 'POST', ['enable_rest_api' => '0']);
    check(($r['json']['rest_routes'] ?? -1) === 0 && !empty($r['json']['preserved']), 'Saving disabled removes REST routes and preserves other routes', $r);
    $r = fixtureRequest($base, '/wp-json/wp/v2/posts', 'OPTIONS');
    check($r['status'] === 404, 'Disabled API no longer answers OPTIONS', $r);
    echo $passed . ' assertions passed; ' . count($failures) . " failed.\n";
    if ($failures) { echo 'Server log: ' . $log . "\n"; }
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $pdo = null;
    if (!$failures) {
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
}
exit($failures ? 1 : 0);
