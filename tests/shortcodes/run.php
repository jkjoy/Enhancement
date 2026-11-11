<?php
/** Real Typecho/SQLite/HTTP privacy regressions; no installed config or database is read. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ENHANCEMENT_REST_API_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/rest-api/run.php';
require dirname(__DIR__, 2) . '/RestApiHelper.php';
$source = realpath($argv[1] ?? '');
if (!$source || !is_file($source . '/install/SQLite.sql') || !is_dir($source . '/var')) {
    fwrite(STDERR, "Usage: php tests/shortcodes/run.php /path/to/typecho\n"); exit(2);
}
$hookProcess = proc_open([PHP_BINARY, __DIR__ . '/hooks.php', $source], [['pipe', 'r'], STDOUT, STDERR], $hookPipes);
if (!is_resource($hookProcess)) { exit(2); }
fclose($hookPipes[0]);
if (proc_close($hookProcess) !== 0) { exit(1); }
$repo = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . '/enhancement-shortcodes-' . bin2hex(random_bytes(8));
$root = $directory . '/site';
mkdir($root . '/usr/plugins', 0700, true);
foreach (['var', 'install', 'admin'] as $name) { symlink($source . '/' . $name, $root . '/' . $name); }
symlink($repo, $root . '/usr/plugins/Enhancement');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($socket, false); fclose($socket);
$base = 'http://' . $address;
$pdo = fixtureDatabase($root, $directory, $base);
$pdo->exec("UPDATE typecho_contents SET text='<!--markdown-->Before [reply]PRIVATE_SENTINEL[/reply] after' WHERE cid IN (1,2,6)");
$pdo->exec("UPDATE typecho_fields SET str_value='Stale PRIVATE_SENTINEL summary' WHERE cid=1 AND name='summary'");
$routes = json_decode($pdo->query("SELECT value FROM typecho_options WHERE name='routingTable'")->fetchColumn(), true);
$save = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=?');
$save->execute([json_encode(array_merge($routes, Enhancement_RestApiHelper::routes())), 'routingTable']);
$save->execute([serialize(['enable_rest_api' => '1']), 'plugin:Enhancement']);
$env = getenv();
$env['ENHANCEMENT_REST_API_TEST_DIR'] = $directory;
$env['ENHANCEMENT_REST_API_TEST_ROOT'] = $root;
$env['ENHANCEMENT_SHORTCODE_TESTS'] = '1';
$log = $directory . '/server.log';
$process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, dirname(__DIR__) . '/rest-api/router.php'],
    [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, $repo, $env);
fclose($pipes[0]);
$passed = 0; $failures = [];
function verify(bool $condition, string $label, $details = null): void
{
    global $passed, $failures;
    if ($condition) { $passed++; return; }
    $failures[] = $label;
    fwrite(STDERR, 'FAIL ' . $label . ': ' . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}
function hidden(array $r): bool { return $r['status'] === 200 && !str_contains((string)($r['json']['rendered'] ?? ''), 'PRIVATE_SENTINEL'); }
function cookies(array $r): array
{
    $cookies = [];
    foreach ($r['raw_headers'] as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) { $cookies[] = trim(explode(';', substr($header, 11), 2)[0]); }
    }
    return array_values(array_unique($cookies));
}
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) { throw new RuntimeException('Local fixture server failed to start'); }
    $path = '/__fixture/shortcodes';
    $r = fixtureRequest($base, $path);
    verify(hidden($r), 'Anonymous article is locked', $r);
    $prefix = md5($base . '/');
    $forged = ['Cookie: ' . $prefix . '__typecho_remember_author=Reader%201; ' . $prefix . '__typecho_remember_mail=reader1%40example.test'];
    verify(hidden(fixtureRequest($base, $path, 'GET', null, $forged)), 'Unsigned nickname and email cannot unlock');
    $r = fixtureRequest($base, $path . '?text=' . rawurlencode('Ordinary article without shortcodes'), 'GET', null, $forged);
    verify($r['status'] === 200 && ($r['json']['extra_queries'] ?? null) === [], 'Ordinary content performs zero extra queries', $r);
    $r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', ['post' => 1, 'author_name' => '', 'author_email' => 'invalid', 'content' => 'bad']);
    verify(!array_filter(cookies($r), static fn($c) => str_contains($c, '__enhancement_reply_access')), 'Failed comment never issues an access credential', $r);
    $r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', ['post' => 1, 'author_name' => 'Privacy reader', 'author_email' => 'privacy@example.test', 'content' => 'Waiting privacy fixture']);
    $id = (int)($r['json']['id'] ?? 0);
    $accessCookies = array_values(array_filter(cookies($r), static fn($c) => str_contains($c, '__enhancement_reply_access')));
    verify($r['status'] === 201 && $id > 0 && count($accessCookies) === 1, 'Successful core submission issues signed guest credential', $r);
    verify((bool)array_filter($r['raw_headers'], static fn($h) => str_contains($h, '__enhancement_reply_access') && stripos($h, 'httponly') !== false), 'Access credential is HttpOnly', $r);
    $credential = $accessCookies[0] ?? $prefix . '__enhancement_reply_access=invalid';
    $headers = ['Cookie: ' . $credential];
    verify(hidden(fixtureRequest($base, $path, 'GET', null, $headers)), 'Waiting comment cannot unlock');
    $pdo->exec("UPDATE typecho_comments SET status='approved' WHERE coid=" . $id);
    $r = fixtureRequest($base, $path, 'GET', null, $headers);
    verify($r['status'] === 200 && str_contains($r['json']['rendered'] ?? '', 'PRIVATE_SENTINEL'), 'Same credential unlocks after actual moderation', $r);
    verify(($r['headers']['cache-control'] ?? '') === 'private, no-store', 'Unlocked response prevents shared-cache storage', $r);
    verify(str_contains($r['json']['content'] ?? '', 'PRIVATE_SENTINEL') && !str_contains($r['json']['excerpt'] ?? '', 'PRIVATE_SENTINEL'), 'Authorized single content stays hidden in core excerpt', $r);
    $r = fixtureRequest($base, $path . '?legacy=1', 'GET', null, $headers);
    verify(!str_contains($r['json']['excerpt'] ?? '', 'PRIVATE_SENTINEL')
        && ($r['json']['excerpt_hooks'] ?? []) === [['Enhancement_ContentParseHelper', 'parseExcerpt'], 'fixtureExcerptPassthrough'],
        'In-place upgrade replaces own old hook once and preserves third-party callbacks', $r);
    $changeText = $pdo->prepare('UPDATE typecho_contents SET text=? WHERE cid=1');
    foreach (['[reply]outer[reply]inner[/reply]PRIVATE_SENTINEL[/reply]', '[reply]PRIVATE_SENTINEL'] as $text) {
        $changeText->execute([$text]);
        $r = fixtureRequest($base, $path, 'GET', null, $headers);
        verify(str_contains($r['json']['content'] ?? '', 'PRIVATE_SENTINEL') && !str_contains($r['json']['excerpt'] ?? '', 'PRIVATE_SENTINEL'),
            'Authorized nested or unclosed body stays private in excerpt', $r);
    }
    $changeText->execute(['<!--markdown-->Before [reply]PRIVATE_SENTINEL[/reply] after']);
    verify(hidden(fixtureRequest($base, $path . '?cid=2', 'GET', null, $headers)), 'Comment credential is bound to its article');
    verify(hidden(fixtureRequest($base, $path . '?feed=1', 'GET', null, $headers)), 'Feed remains locked for authorized guest');
    $tampered = $credential . '0';
    verify(hidden(fixtureRequest($base, $path, 'GET', null, ['Cookie: ' . $tampered])), 'Tampered signature cannot unlock');
    $payload = rtrim(strtr(base64_encode(json_encode([[$id, 1, time() - 1, bin2hex(random_bytes(16))]])), '+/', '-_'), '=');
    $secret = hash_hmac('sha256', 'enhancement-reply-access-v1|' . $base . '/', 'isolated-rest-api-fixture-secret');
    $expired = $prefix . '__enhancement_reply_access=' . $payload . '.' . hash_hmac('sha256', $payload, $secret);
    verify(hidden(fixtureRequest($base, $path, 'GET', null, ['Cookie: ' . $expired])), 'Correctly signed but expired credential cannot unlock');
    foreach (['waiting', 'spam'] as $status) {
        $pdo->exec("UPDATE typecho_comments SET status='" . $status . "' WHERE coid=" . $id);
        verify(hidden(fixtureRequest($base, $path, 'GET', null, $headers)), 'Moderation revocation locks again: ' . $status);
    }
    $pdo->exec("UPDATE typecho_comments SET status='approved' WHERE coid=" . $id);
    foreach (['/wp-json/wp/v2/posts/1', '/wp-json/wp/v2/posts', '/wp-json/wp/v2/pages/6', '/wp-json/wp/v2/pages'] as $restPath) {
        $r = fixtureRequest($base, $restPath, 'GET', null, $headers);
        verify($r['status'] === 200 && !str_contains($r['body'], 'PRIVATE_SENTINEL'), 'Cacheable public API always hides body and stale summary: ' . $restPath, $r);
    }
    $r = fixtureRequest($base, $path . '?text=' . rawurlencode('[article id=2]'), 'GET', null, $headers);
    verify($r['status'] === 200 && str_contains($r['json']['rendered'] ?? '', 'Beta public') && str_contains($r['json']['rendered'] ?? '', '/archives/2/'), 'Public article reference uses correct core permalink', $r);
    verify(($r['json']['cid'] ?? 0) === 1 && ($r['json']['title'] ?? '') === 'Alpha public', 'Article reference preserves current archive context', $r);
    foreach ([3, 4, 5, 6, 8, 9] as $privateId) {
        $r = fixtureRequest($base, $path . '?text=' . rawurlencode('[article id=' . $privateId . ']'));
        verify($r['status'] === 200 && ($r['json']['rendered'] ?? null) === '' && ($r['json']['cid'] ?? 0) === 1, 'Non-public/non-post article reference omitted: ' . $privateId, $r);
    }
    foreach (['[reply]PRIVATE_SENTINEL', '[reply]outer[reply]PRIVATE_SENTINEL[/reply]tail[/reply]', '<!--enhancement-reply-start-->PRIVATE_SENTINEL'] as $text) {
        $r = fixtureRequest($base, $path . '?text=' . rawurlencode($text));
        verify(hidden($r), 'Nested or truncated reply cannot leak: ' . $text, $r);
    }
    $pdo->exec("UPDATE typecho_users SET screenName='Privacy reader', mail='privacy@example.test' WHERE uid=1");
    $r = fixtureRequest($base, '/__fixture/auth', 'POST', []);
    $login = ['Cookie: ' . implode('; ', array_merge(cookies($r), [$credential]))];
    verify(hidden(fixtureRequest($base, $path, 'GET', null, $login)), 'Login identity cannot borrow guest/name/email permission');
    $pdo->exec("UPDATE typecho_comments SET authorId=1, status='approved' WHERE coid=" . $id);
    $r = fixtureRequest($base, $path, 'GET', null, $login);
    verify(str_contains($r['json']['rendered'] ?? '', 'PRIVATE_SENTINEL'), 'Approved authenticated authorId unlocks own article', $r);
    $r = fixtureRequest($base, '/wp-json/wp/v2/posts/1', 'GET', null, $login);
    verify($r['status'] === 200 && !str_contains($r['body'], 'PRIVATE_SENTINEL'), 'Public API remains hidden for logged-in approved commenter', $r);
    $pdo->exec('UPDATE typecho_comments SET authorId=0 WHERE coid=' . $id);
    $r = fixtureRequest($base, $path, 'GET', null, $headers);
    verify(str_contains($r['json']['rendered'] ?? '', 'PRIVATE_SENTINEL'), 'Guest credential is valid immediately before deletion', $r);
    $pdo->exec('DELETE FROM typecho_comments WHERE coid=' . $id);
    verify(hidden(fixtureRequest($base, $path, 'GET', null, $headers)), 'Deleted comment revokes guest access');
    if ($failures) { fwrite(STDERR, file_get_contents($log)); }
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage(); fwrite(STDERR, $exception . "\n" . file_get_contents($log));
} finally {
    proc_terminate($process); proc_close($process); $pdo = null; $save = null; $changeText = null;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
}
echo $passed . ' shortcode privacy checks passed, ' . count($failures) . " failed.\n";
exit($failures ? 1 : 0);
