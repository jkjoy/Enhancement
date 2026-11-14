<?php
/** Offline regression: php tests/public-api-security/run.php (PHP 8+, cURL and process creation). */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$disabled = 'curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error,curl_close';
if (empty(getenv('ENHANCEMENT_PUBLIC_API_FIXTURE'))) {
    if (PHP_VERSION_ID < 80000 || !extension_loaded('curl')) { fwrite(STDERR, "Requires PHP 8+ and cURL.\n"); exit(2); }
    $fixture = sys_get_temp_dir() . '/enhancement-public-test-' . bin2hex(random_bytes(5));
    mkdir($fixture . '/Meting', 0700, true);
    mkdir($fixture . '/runtime', 0700);
    foreach (['Action.php', 'PublicApiHelper.php', 'Meting/Meting.php'] as $name) { copy(dirname(__DIR__, 2) . '/' . $name, $fixture . '/' . $name); }
    $environment = getenv();
    $environment['ENHANCEMENT_PUBLIC_API_FIXTURE'] = $fixture;
    $process = proc_open([PHP_BINARY, '-d', 'disable_functions=' . $disabled, __FILE__, '--suite'], [STDIN, STDOUT, STDERR], $pipes, null, $environment);
    $exit = proc_close($process);
    function removeTree($path) { if (is_dir($path) && !is_link($path)) { foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { removeTree($path . '/' . $name); } } rmdir($path); } else { unlink($path); } }
    removeTree($fixture);
    exit($exit);
}
require __DIR__ . '/bootstrap.php';
$action = auditAction();
$storage = auditPrivate($action, 'publicStorageDirectory');
if (($argv[1] ?? '') === '--link-worker') {
    while (microtime(true) < (float)$argv[2]) { usleep(500); }
    try { echo auditPrivate($action, 'checkSubmitRateLimit') ? 'allowed' : 'denied'; } catch (Throwable $error) { echo 'error'; }
    exit;
}
if (($argv[1] ?? '') === '--meting-worker') {
    while (microtime(true) < (float)$argv[2]) { usleep(500); }
    $GLOBALS['curlSleep'] = 500000;
    echo json_encode(auditEndpoint($action, ['server' => 'tencent', 'type' => 'search', 'id' => 'concurrent']));
    exit;
}
$passed = 0;
function check($condition, $label) { global $passed; if (!$condition) { throw new RuntimeException('FAIL ' . $label); } $passed++; }
function failureCode($callback) { try { $callback(); return 0; } catch (RuntimeException $error) { return $error->getCode(); } }
function endpoint($id, $type = 'search') { global $action; return auditEndpoint($action, ['server' => 'tencent', 'type' => $type, 'id' => $id]); }
function calls() { return count($GLOBALS['curlCalls'] ?? []); }
function clearMemoryCache() { $property = new ReflectionProperty('Enhancement_Action', 'metingCache'); $property->setValue(null, []); }
function removeRuntime($storage) { foreach (glob($storage . '/*') ?: [] as $file) { unlink($file); } rmdir($storage); }
try {
    // Concurrent clients share one real file lock and cannot lose increments.
    $processes = [];
    $start = microtime(true) + 1;
    for ($index = 0; $index < 30; $index++) {
        $process = proc_open([PHP_BINARY, '-d', 'disable_functions=' . $disabled, __FILE__, '--link-worker', (string)$start], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $processes[] = [$process, $pipes];
    }
    $allowed = 0;
    foreach ($processes as [$process, $pipes]) {
        fclose($pipes[0]); $result = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        check($exit === 0 && $error === '', 'Concurrent worker completes cleanly');
        if ($result === 'allowed') { $allowed++; }
    }
    check($allowed === 5, 'Exactly five of thirty simultaneous friend applications are allowed');
    $rateFile = auditPrivate($action, 'getRateLimitStorePath');
    $records = json_decode(file_get_contents($rateFile), true);
    check(count($records['198.51.100.44']) === 5, 'Every accepted application is recorded');
    $action->request->clientIp = '203.0.113.88';
    check(auditPrivate($action, 'getClientIpAddress') === '198.51.100.44' && !auditPrivate($action, 'checkSubmitRateLimit'), 'Untrusted forwarded client IP cannot bypass a transport address quota');
    $action->request->clientIp = '198.51.100.44';
    file_put_contents($rateFile, '{broken');
    check(failureCode(static function () use ($action) { auditPrivate($action, 'checkSubmitRateLimit'); }) === 503, 'Corrupt quota storage fails closed');
    unlink($rateFile);
    mkdir($rateFile);
    check(failureCode(static function () use ($action) { auditPrivate($action, 'checkSubmitRateLimit'); }) === 503, 'Unwritable quota storage fails closed');
    rmdir($rateFile);
    file_put_contents($fixture . '/runtime/rate-limit-links.json', json_encode(['198.51.100.44' => array_fill(0, 5, time())]));
    check(!auditPrivate($action, 'checkSubmitRateLimit'), 'Upgrade preserves limits in legacy JSON records');
    check(!file_exists($fixture . '/runtime/rate-limit-links.json'), 'Legacy public IP file is removed after migration');

    Enhancement_Plugin::$enabled = false;
    check(endpoint('disabled')['status'] === 404 && calls() === 0, 'Disabled music endpoint performs no upstream work');
    Enhancement_Plugin::$enabled = true;
    $action->request->method = 'POST';
    check(endpoint('post')['status'] === 405 && calls() === 0, 'Music endpoint refuses state-changing HTTP methods');
    $action->request->method = 'GET';
    foreach ([[1], str_repeat('x', 257), "bad\nkeyword"] as $invalid) { check(endpoint($invalid)['status'] === 400, 'Invalid or oversized search input is rejected'); }
    check(endpoint('id with spaces', 'song')['status'] === 400, 'Non-search IDs retain safe-character rules');
    check(auditEndpoint($action, ['server' => ['tencent'], 'type' => 'search', 'id' => 'x'])['status'] === 400 && calls() === 0, 'Array inputs are rejected before transport');
    check(endpoint('normal')['status'] === 200 && calls() === 1, 'Valid player request works');
    $count = calls();
    check(endpoint('normal')['status'] === 200 && calls() === $count, 'Warm music cache avoids upstream work');
    foreach (['javascript:alert(1)', 'data:text/html,evil', 'https://user:pass@example.com/', "https://example.com/\r\nX:y", 'https://example.com/%0aevil', 'https://example.com\\@evil.example/'] as $invalid) {
        auditPrivate($action, 'metingCacheSet', ['url:tencent:unsafe', json_encode(['url' => $invalid]), 100]);
        check(endpoint('unsafe', 'url')['status'] === 502, 'Unsafe third-party music redirect is rejected');
    }
    auditPrivate($action, 'metingCacheSet', ['url:tencent:safe', '{"url":"https://cdn.example/song.mp3?x=1&y=2"}', 100]);
    $response = endpoint('safe', 'url');
    check($response['status'] === 302 && $response['redirect'] === 'https://cdn.example/song.mp3?x=1&y=2', 'Normal HTTPS media redirect is preserved');
    for ($index = 0; $index < 11; $index++) { check(endpoint('quota-' . $index)['status'] === 200, 'Normal search quota is available'); }
    $count = calls();
    $response = endpoint('over-quota');
    check($response['status'] === 429 && isset($response['headers']['Retry-After']) && calls() === $count, 'Cold search abuse is rate limited without transport');
    check(endpoint('normal')['status'] === 200 && calls() === $count, 'Rate limiting does not interrupt cached playback');
    unlink($storage . '/meting-rate.json');

    require_once $fixture . '/Meting/Meting.php';
    $api = new Metowolf\Meting('tencent');
    $api->timeout(1);
    $GLOBALS['curlSleep'] = 150000;
    auditPrivate($api, 'curl', ['http://upstream.example/first']);
    auditPrivate($api, 'curl', ['https://upstream.example/second']);
    $options = array_slice($GLOBALS['curlCalls'], -2);
    check($options[1][CURLOPT_TIMEOUT_MS] < $options[0][CURLOPT_TIMEOUT_MS], 'Multiple resolution calls share a decreasing total time budget');
    check($options[0][CURLOPT_SSL_VERIFYPEER] && $options[0][CURLOPT_SSL_VERIFYHOST] === 2 && strpos($options[0][CURLOPT_URL], 'https:') === 0, 'Upstreams use HTTPS with full certificate checks');
    usleep(750000);
    $count = calls();
    check(failureCode(static function () use ($api) { auditPrivate($api, 'curl', ['https://upstream.example/late']); }) === 504 && calls() === $count, 'Exhausted budget prevents further upstream requests');
    $GLOBALS['curlSleep'] = 0;
    $GLOBALS['curlFail'] = true;
    $api->timeout(1); $count = calls();
    check(failureCode(static function () use ($api) { auditPrivate($api, 'curl', ['https://upstream.example/failure']); }) === 504 && calls() === $count + 1, 'Timeout is not multiplied by automatic retries');
    $GLOBALS['curlFail'] = false;
    $GLOBALS['curlBody'] = str_repeat('x', 1048577); $api->timeout();
    check(failureCode(static function () use ($api) { auditPrivate($api, 'curl', ['https://upstream.example/large']); }) === 502, 'Oversized decoded upstream response is aborted');
    unset($GLOBALS['curlBody']);

    // Cache cleanup evicts expired and oldest records while preserving unrelated files.
    $cache = $fixture . '/usr/cache';
    foreach (glob($cache . '/enhancement_meting_*.json') as $file) { unlink($file); }
    file_put_contents($cache . '/unrelated.json', 'keep');
    for ($index = 0; $index < 1024; $index++) { file_put_contents($cache . '/enhancement_meting_' . md5('fixture-' . $index) . '.json', json_encode(['expires' => time() + 600, 'data' => '[]'])); }
    $expired = $cache . '/enhancement_meting_' . md5('expired') . '.json';
    file_put_contents($expired, json_encode(['expires' => time() - 1, 'data' => '[]']));
    auditPrivate($action, 'metingCacheSet', ['new-quota-record', '[]', 60]);
    check(count(glob($cache . '/enhancement_meting_*.json')) === 1024 && !is_file($expired), 'Cache sweep expires old records and enforces file count cap');
    check(file_get_contents($cache . '/unrelated.json') === 'keep', 'Cache sweep preserves other plugin files');
    foreach (glob($cache . '/enhancement_meting_*.json') as $file) { unlink($file); }
    $large = json_encode(['expires' => time() + 600, 'data' => str_repeat('a', 1048576)]);
    for ($index = 0; $index < 32; $index++) { file_put_contents($cache . '/enhancement_meting_' . md5('large-' . $index) . '.json', $large); }
    auditPrivate($action, 'metingCacheSet', ['large-final', str_repeat('b', 1500000), 60]);
    $bytes = array_sum(array_map('filesize', glob($cache . '/enhancement_meting_*.json')));
    check($bytes <= 33554432, 'Cache sweep enforces 32 MiB disk quota');
    foreach (glob($cache . '/enhancement_meting_*.json') as $file) { unlink($file); }
    clearMemoryCache();
    file_put_contents($fixture . '/transport.log', '');
    $start = microtime(true) + 0.3;
    $processes = [];
    for ($index = 0; $index < 2; $index++) {
        $process = proc_open([PHP_BINARY, '-d', 'disable_functions=' . $disabled, __FILE__, '--meting-worker', (string)$start], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $processes[] = [$process, $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        fclose($pipes[0]); $response = json_decode(stream_get_contents($pipes[1]), true); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        check($exit === 0 && $error === '' && $response['status'] === 200, 'Concurrent player request receives valid cached result');
    }
    check(count(file($fixture . '/transport.log')) === 1, 'Concurrent cold requests for the same key make one upstream request');
    $slots = [];
    for ($index = 0; $index < 4; $index++) { $slot = fopen($storage . '/meting-slot-' . $index . '.lock', 'c'); flock($slot, LOCK_EX); $slots[] = $slot; }
    $count = calls();
    check(endpoint('all-slots-busy')['status'] === 503 && calls() === $count, 'Global upstream concurrency limit rejects excess work');
    foreach ($slots as $slot) { flock($slot, LOCK_UN); fclose($slot); }
    foreach (['actionResponse'] as $method) {
        $action->response = new AuditResponse();
        auditPrivate($action, $method, [false, 'shared response', 418]);
        check($action->response->status === 418 && $action->response->json === ['success' => false, 'message' => 'shared response'], 'Shared administrator response preserves JSON shape and status');
    }
    echo "$passed public API security checks passed.\n";
} finally {
    removeRuntime($storage);
}
