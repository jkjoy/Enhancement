<?php
/** Real moments API timestamp checks against disposable Typecho/SQLite. */
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $directory = getenv('ENHANCEMENT_MOMENTS_TIME_DIR');
    $root = getenv('ENHANCEMENT_MOMENTS_TIME_ROOT');
    if (!$directory || !$root || !is_file($directory . '/fixture.sqlite')) { http_response_code(404); exit; }
    date_default_timezone_set('UTC');
    define('__TYPECHO_ROOT_DIR__', $root);
    define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
    define('__TYPECHO_THEME_DIR__', '/usr/themes');
    define('__TYPECHO_ADMIN_DIR__', '/admin/');
    define('__TYPECHO_DEBUG__', true);
    $_SERVER['SCRIPT_NAME'] = '/fixture.php';
    require_once $root . '/var/Typecho/Common.php';
    Typecho\Common::init();
    $db = new Typecho\Db('Pdo_SQLite', 'typecho_');
    $db->addServer(['file' => $directory . '/fixture.sqlite'], Typecho\Db::READ | Typecho\Db::WRITE);
    Typecho\Db::set($db);
    Typecho\Widget::widget('Widget_Init');
    require_once dirname(__DIR__, 2) . '/Plugin.php';
    require_once dirname(__DIR__, 2) . '/Memos/Action.php';
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/v1/memos') { http_response_code(404); exit; }
    Typecho_Widget::widget('Enhancement_Memos_Action')->action();
    exit;
}
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $token = 'isolated-moments-time-token';
    $pdo->prepare("UPDATE typecho_options SET value=? WHERE name='plugin:Enhancement' AND user=0")
        ->execute([serialize(['moments_token' => $token])]);
    $env = getenv();
    $env['ENHANCEMENT_MOMENTS_TIME_DIR'] = $directory;
    $env['ENHANCEMENT_MOMENTS_TIME_ROOT'] = $root;
    qqStartServer($address, __FILE__, $env, $directory . '/typecho.log');

    foreach ([28800 => '+08:00', -12600 => '-03:30'] as $offset => $zone) {
        $pdo->prepare("UPDATE typecho_options SET value=? WHERE name='timezone' AND user=0")->execute([(string)$offset]);
        $localTimestamp = (new DateTimeImmutable('2026-11-09T12:34:56' . $zone))->getTimestamp();
        $absoluteTimestamp = (new DateTimeImmutable('2026-11-09T04:34:56Z'))->getTimestamp();
        $cases = [
            'omitted' => [null, null],
            'unix-seconds' => [$absoluteTimestamp, $absoluteTimestamp],
            'unix-epoch' => [0, 0],
            'site-local' => ['2026-11-09 12:34:56', $localTimestamp],
            'explicit-offset' => ['2026-11-09T12:34:56+08:00', $absoluteTimestamp],
            'explicit-utc' => ['2026-11-09T04:34:56Z', $absoluteTimestamp],
            'text-epoch' => ['1970-01-01T00:00:00Z', 0],
            'invalid-falls-back' => ['invalid timestamp', null],
        ];
        foreach (['json', 'form'] as $format) {
            foreach ($cases as $case => [$created, $expected]) {
                $label = $zone . ' ' . $format . ' ' . $case;
                $payload = ['content' => $label];
                if ($created !== null) { $payload['created'] = $created; }
                $headers = ['Authorization: Bearer ' . $token];
                if ($format === 'form') {
                    $payload = http_build_query($payload);
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                }
                $before = time();
                $response = fixtureRequest($base, '/api/v1/memos', 'POST', $payload, $headers);
                $after = time();
                qqCheck($response['status'] === 200 && !empty($response['json']['success']), $label . ' publishes', $response);
                $query = $pdo->prepare('SELECT created FROM typecho_moments WHERE mid=?');
                $query->execute([$response['json']['id'] ?? 0]);
                $stored = $query->fetchColumn();
                $query->closeCursor();
                qqCheck($stored !== false && ($expected === null
                    ? (int)$stored >= $before && (int)$stored <= $after : (int)$stored === $expected),
                    $label . ' stores the correct Unix seconds', ['stored' => $stored, 'expected' => $expected]);
                $listing = fixtureRequest($base, '/api/v1/memos?limit=100');
                $items = array_column($listing['json'] ?? [], null, 'id');
                $item = $items[$response['json']['id'] ?? ''] ?? [];
                $displayTimestamp = $expected ?? (int)$stored;
                $display = $displayTimestamp > 0 ? gmdate('Y-m-d H:i', $displayTimestamp + $offset) : '';
                qqCheck($listing['status'] === 200 && ($item['time'] ?? '') === $display,
                    $label . ' lists time in the site timezone', ['item' => $item, 'expected' => $display]);
            }
        }
    }
    $log = file_get_contents($directory . '/typecho.log');
    qqCheck(preg_match('/PHP (?:Warning|Fatal error|Parse error|Deprecated|Notice)/i', $log) !== 1,
        'Timestamp requests produce no PHP errors or warnings', $log);
} catch (Throwable $exception) {
    qqCheck(false, 'Runner exception', $exception->getMessage());
} finally {
    foreach ($processes as $process) {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
    $query = null;
    $pdo = null;
    if (!$failures) {
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
}
echo $passed . ' assertions passed; ' . count($failures) . " failed.\n";
if ($failures) { echo 'Fixture logs retained: ' . $directory . "\n"; }
exit($failures ? 1 : 0);
