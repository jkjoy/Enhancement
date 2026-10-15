<?php
/** Real HTTP coverage on a disposable Typecho installation. Usage: php tests/comment-guard/run.php [Typecho root]. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$unit = proc_open([PHP_BINARY, __DIR__ . '/unit.php'], [['pipe', 'r'], STDOUT, STDERR], $unitPipes, __DIR__, null, ['bypass_shell' => true]);
if (!is_resource($unit)) { exit(2); }
fclose($unitPipes[0]);
if (proc_close($unit) !== 0) { exit(1); }

define('ENHANCEMENT_REST_API_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/rest-api/run.php';
$root = realpath($argv[1] ?? dirname(__DIR__, 5));
if (!$root || !is_file($root . '/var/Typecho/Common.php') || !is_file($root . '/install/SQLite.sql')) {
    fwrite(STDERR, "Supply a Typecho root containing var/Typecho/Common.php and install/SQLite.sql.\n");
    exit(2);
}
$directory = sys_get_temp_dir() . '/enhancement-comment-guard-http-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$pdo = fixtureDatabase($root, $directory, $base);
$env = getenv();
$env['ENHANCEMENT_REST_API_TEST_DIR'] = $directory;
$env['ENHANCEMENT_REST_API_TEST_ROOT'] = $root;
$env['ENHANCEMENT_COMMENT_GUARD_TESTS'] = '1';
$log = $directory . '/server.log';
$process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=32767', '-S', $address, dirname(__DIR__) . '/rest-api/router.php'], [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, __DIR__, $env, ['bypass_shell' => true]);
if (isset($pipes[0])) { fclose($pipes[0]); }
$passed = 0;
$failures = [];
$storePath = null;
function httpGuardCheck(bool $condition, string $label, $details = null): void {
    global $passed, $failures;
    if ($condition) { $passed++; return; }
    $failures[] = $label . ($details === null ? '' : ': ' . substr(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1500));
    echo 'FAIL ' . end($failures) . "\n";
}
function httpGuardConfig(array $changes = []): void {
    global $base;
    fixtureRequest($base, '/__fixture/config', 'POST', array_replace([
        'enable_rest_api' => '1', 'enable_comment_guard' => '1',
        'comment_guard_block_xss' => '1', 'comment_guard_block_html' => '0',
        'comment_guard_require_chinese' => '0', 'comment_guard_blocked_keywords' => '',
        'comment_guard_required_keywords' => '', 'comment_guard_blocked_ips' => '',
        'comment_guard_trusted_proxies' => '', 'comment_guard_rate_limit' => '0',
        'comment_guard_window' => '300', 'comment_guard_ip_limit' => '5',
        'comment_guard_subnet_limit' => '20', 'comment_guard_block_duplicates' => '0',
        'enable_turnstile' => '0', 'enable_comment_notifier' => '0', 'enable_qq_notify' => '0',
    ], $changes));
}
function httpGuardSnapshot(): array {
    global $pdo, $directory;
    return [
        'rows' => (int)$pdo->query('SELECT COUNT(*) FROM typecho_comments')->fetchColumn(),
        'count' => (int)$pdo->query('SELECT commentsNum FROM typecho_contents WHERE cid = 1')->fetchColumn(),
        'finish_hook' => is_file($directory . '/comment-hooks.json') ? file_get_contents($directory . '/comment-hooks.json') : null,
    ];
}
function httpGuardSubmit(string $entry, string $text, array $headers = []): array {
    global $base, $token;
    if ($entry === 'REST') {
        return fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', [
            'post' => 1, 'author_name' => 'Guard fixture reader', 'author_email' => 'guard@example.test', 'content' => $text,
        ], $headers);
    }
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $headers[] = 'Referer: ' . $base . '/archives/1/';
    return fixtureRequest($base, '/action/feedback', 'POST', http_build_query([
        'type' => 'comment', 'permalink' => '/archives/1/', 'author' => 'Guard fixture reader',
        'mail' => 'guard@example.test', 'text' => $text, '_' => $token,
    ]), $headers);
}
function httpGuardReject(string $entry, string $text, string $label, array $headers = [], int $status = 403): void {
    $before = httpGuardSnapshot();
    $response = httpGuardSubmit($entry, $text, $headers);
    $expectedStatus = $status === 429 ? in_array($response['status'], [403, 429], true) && !empty($response['headers']['retry-after']) : $response['status'] === $status;
    httpGuardCheck($expectedStatus, $entry . ' ' . $label, $response);
    httpGuardCheck(httpGuardSnapshot() === $before, $entry . ' rejection has no insertion, comment count, or finish/notification hooks: ' . $label, httpGuardSnapshot());
}
function httpGuardResetStore(): void {
    global $storePath;
    if ($storePath) { clearstatcache(true, $storePath); }
    if ($storePath && is_file($storePath)) { unlink($storePath); }
}
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) { throw new RuntimeException('Fixture server did not start.'); }
    $meta = fixtureRequest($base, '/__fixture/guard');
    $storePath = $meta['json']['store_path'] ?? null;
    $token = $meta['json']['token'] ?? '';
    if (!$storePath || !preg_match('/^typecho-enhancement-comment-guard-[a-f0-9]{64}\.php$/D', basename($storePath))
        || realpath(dirname($storePath)) !== realpath(sys_get_temp_dir())) {
        throw new RuntimeException('Fixture did not return its isolated temporary rate store.');
    }
    httpGuardResetStore();
    fixtureRequest($base, '/__fixture/options', 'POST', ['commentsRequireModeration' => 0]);

    foreach (['REST', 'native'] as $entry) {
        httpGuardConfig(['enable_comment_guard' => '0', 'comment_guard_require_chinese' => '1', 'comment_guard_required_keywords' => '必须', 'comment_guard_blocked_ips' => '127.0.0.0/8']);
        $before = httpGuardSnapshot();
        $response = httpGuardSubmit($entry, 'English master switch off ' . $entry);
        httpGuardCheck(in_array($response['status'], [201, 302], true) && httpGuardSnapshot()['rows'] === $before['rows'] + 1, $entry . ' master switch off bypasses every guard rule', $response);
        httpGuardConfig();
        httpGuardReject($entry, '<img src=x onerror=alert(1)>', 'rejects HTML event injection');
        httpGuardReject($entry, '[x](jav&#x61;script:alert(1))', 'rejects obfuscated Markdown URL');
        httpGuardConfig(['comment_guard_block_html' => '1']);
        httpGuardReject($entry, '<b>Safe markup</b>', 'optional HTML rule');
        httpGuardConfig(['comment_guard_require_chinese' => '1']);
        httpGuardReject($entry, 'English without Chinese', 'Chinese requirement');
        httpGuardConfig(['comment_guard_blocked_keywords' => '广告']);
        httpGuardReject($entry, '这是一条广&#21578;评论', 'normalized blocked keyword');
        httpGuardConfig(['comment_guard_required_keywords' => "第一\n第二"]);
        httpGuardReject($entry, '无关键内容', 'required keyword missing');
        httpGuardConfig(['comment_guard_blocked_ips' => '127.0.0.0/8']);
        httpGuardReject($entry, '伪造转发地址', 'untrusted XFF cannot bypass IP block', ['X-Forwarded-For: 198.51.100.9']);
        httpGuardConfig(['comment_guard_trusted_proxies' => '127.0.0.1', 'comment_guard_blocked_ips' => '198.51.100.0/24']);
        httpGuardReject($entry, '可信代理传入封禁地址', 'trusted proxy real client IP blocked', ['X-Forwarded-For: 198.51.100.9']);
        httpGuardReject($entry, '垃圾前缀不能覆盖真实地址', 'irrelevant invalid XFF prefix cannot bypass blocked real client', ['X-Forwarded-For: garbage, 198.51.100.9']);
        httpGuardReject($entry, '过长转发信息不能绕过', 'oversized trusted forwarding header rejected', ['X-Forwarded-For: ' . str_repeat('192.0.2.1,', 240) . '198.51.100.9']);
        httpGuardReject($entry, '过多转发层数不能绕过', 'too many trusted forwarding hops rejected', ['X-Forwarded-For: ' . str_repeat('192.0.2.1,', 32) . '198.51.100.9']);
        httpGuardReject($entry, '必要代理节点不可解析', 'malformed necessary forwarded hop rejected', ['X-Forwarded-For: 198.51.100.9, garbage']);
        httpGuardConfig(['comment_guard_require_chinese' => '1', 'comment_guard_required_keywords' => "first\n通过"]);
        $before = httpGuardSnapshot();
        $response = httpGuardSubmit($entry, '正常通过的中文评论 ' . $entry . '，比较 2 < 3。');
        $after = httpGuardSnapshot();
        httpGuardCheck(in_array($response['status'], [201, 302], true) && $after['rows'] === $before['rows'] + 1 && $after['count'] === $before['count'] + 1 && $after['finish_hook'] !== $before['finish_hook'], $entry . ' valid Chinese comment inserts, counts, and finishes normally', $response);
    }

    httpGuardConfig(['comment_guard_rate_limit' => '1', 'comment_guard_ip_limit' => '2', 'comment_guard_subnet_limit' => '10']);
    httpGuardResetStore();
    foreach (['REST', 'native'] as $entry) {
        $response = httpGuardSubmit($entry, 'shared IP threshold ' . $entry, ['X-Forwarded-For: 198.51.100.' . ($entry === 'REST' ? 1 : 2)]);
        httpGuardCheck(in_array($response['status'], [201, 302], true), $entry . ' shares allowed IP quota', $response);
    }
    httpGuardReject('REST', 'same socket spoofed IP', 'untrusted XFF cannot bypass shared native/REST IP rate', ['X-Forwarded-For: 203.0.113.40'], 429);
    httpGuardCheck(!empty(fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', ['post' => 1, 'author_name' => 'Reader', 'author_email' => 'reader@example.test', 'content' => 'retry header'])['headers']['retry-after']), 'Rate rejection returns Retry-After');

    httpGuardConfig(['comment_guard_rate_limit' => '1', 'comment_guard_ip_limit' => '10', 'comment_guard_subnet_limit' => '2', 'comment_guard_trusted_proxies' => '127.0.0.1']);
    httpGuardResetStore();
    foreach ([1, 2] as $suffix) {
        $response = httpGuardSubmit('REST', 'subnet rotation ' . $suffix, ['X-Forwarded-For: 198.51.100.' . $suffix]);
        httpGuardCheck($response['status'] === 201, 'Trusted proxy rotating client admitted below subnet quota ' . $suffix, $response);
    }
    httpGuardReject('native', 'third rotating client', 'same /24 subnet blocks another client across native/REST', ['X-Forwarded-For: 198.51.100.240'], 429);
    $response = httpGuardSubmit('REST', 'adjacent subnet', ['X-Forwarded-For: 198.51.101.1']);
    httpGuardCheck($response['status'] === 201, 'Trusted proxy client in adjacent /24 still passes', $response);
    $storedIp = $pdo->query('SELECT ip FROM typecho_comments ORDER BY coid DESC LIMIT 1')->fetchColumn();
    httpGuardCheck($storedIp === '198.51.101.1', 'Trusted proxy selected client IP is stored by core', $storedIp);

    httpGuardConfig(['comment_guard_block_duplicates' => '1']);
    httpGuardResetStore();
    $response = httpGuardSubmit('REST', '重复内容跨原生和 API');
    httpGuardCheck($response['status'] === 201, 'First duplicate candidate accepted', $response);
    httpGuardReject('native', '重复内容跨原生和 API', 'duplicate shares storage across endpoints', [], 429);
    httpGuardConfig(['comment_guard_block_duplicates' => '1', 'enable_turnstile' => '1', 'turnstile_site_key' => '', 'turnstile_secret_key' => '']);
    httpGuardResetStore();
    $before = httpGuardSnapshot();
    $response = httpGuardSubmit('REST', '验证码修正后允许重试同文');
    clearstatcache(true, $storePath);
    httpGuardCheck(in_array($response['status'], [400, 403], true) && strpos($response['json']['message'] ?? '', 'Turnstile') !== false, 'Unconfigured Turnstile rejects before guard', $response);
    httpGuardCheck(!is_file($storePath) && httpGuardSnapshot() === $before, 'Failed CAPTCHA does not consume duplicate state or create comments');
    httpGuardConfig(['comment_guard_block_duplicates' => '1']);
    $response = httpGuardSubmit('REST', '验证码修正后允许重试同文');
    httpGuardCheck($response['status'] === 201, 'Same content may retry after CAPTCHA is corrected', $response);
    httpGuardReject('REST', '验证码修正后允许重试同文', 'accepted retry now participates in duplicate defense', [], 429);
    httpGuardConfig(['comment_guard_rate_limit' => '1']);
    httpGuardResetStore();
    $corrupted = false;
    // Windows can briefly hold a just-deleted PHP state file. Retry only the
    // fixture mutation, then fail clearly instead of testing an unchanged store.
    for ($attempt = 0; $attempt < 10; $attempt++) {
        if (@file_put_contents($storePath, 'intentionally corrupt disposable fixture store') !== false) { $corrupted = true; break; }
        usleep(50000);
    }
    if (!$corrupted) { throw new RuntimeException('Unable to prepare corrupt disposable rate store.'); }
    httpGuardReject('REST', 'storage failure API', 'corrupt rate store preserves temporary failure status', [], 503);
    httpGuardReject('native', 'storage failure native', 'corrupt rate store rejects native comment', [], 503);
    $serverLog = file_get_contents($log);
    httpGuardCheck(!preg_match('/PHP (?:Warning|Fatal error|Parse error):/', $serverLog), 'Fixture log has no warnings or fatal errors', $serverLog);
    echo $passed . ' guard HTTP assertions passed; ' . count($failures) . " failed.\n";
    if ($failures) { echo 'Server log: ' . $log . "\n"; }
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    httpGuardResetStore();
    $pdo = null;
    if (!$failures) {
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
}
exit($failures ? 1 : 0);
