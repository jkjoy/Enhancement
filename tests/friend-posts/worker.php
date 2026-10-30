<?php
/** Isolated cron startup checks using real Typecho and a disposable site/database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('ENHANCEMENT_REST_API_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/rest-api/run.php';
$root = realpath($argv[1] ?? dirname(__DIR__, 5));
if (!$root || !is_file($root . '/var/Typecho/Common.php') || !is_file($root . '/install/SQLite.sql')) {
    fwrite(STDERR, "Supply a Typecho root containing var/Typecho/Common.php and install/SQLite.sql.\n");
    exit(2);
}
$directory = sys_get_temp_dir() . '/enhancement-friend-worker-' . bin2hex(random_bytes(8));
$pluginDirectory = $directory . '/usr/plugins/Enhancement';
$workerPath = $pluginDirectory . '/FriendPosts/worker.php';
mkdir(dirname($workerPath), 0700, true);
$passed = 0;
$failures = [];
$pdo = null;
$httpProcess = null;

function friendWorkerCheck(bool $condition, string $label, $details = null): void
{
    global $passed, $failures;
    if ($condition) { $passed++; return; }
    $failures[] = $label . ($details === null ? '' : ': ' . substr(json_encode($details, JSON_UNESCAPED_UNICODE), 0, 1200));
    echo 'FAIL ' . end($failures) . PHP_EOL;
}
function friendWorkerReset(array $settings = [], bool $active = true): void
{
    global $pdo, $directory;
    foreach (['calls.jsonl', 'bootstrap.jsonl'] as $file) {
        if (is_file($directory . '/' . $file)) { unlink($directory . '/' . $file); }
    }
    $values = array_replace(['enable_friend_posts' => '1', 'enable_friend_posts_by_qq' => '1',
        'enable_friend_posts_by_weixin' => '0', 'qq_moments_enabled' => '0', 'weixin_moments_enabled' => '0'], $settings);
    $statement = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=? AND user=0');
    $statement->execute([serialize($values), 'plugin:Enhancement']);
    $statement->execute([json_encode(['activated' => $active ? ['Enhancement' => []] : [], 'handles' => []]), 'plugins']);
}
function friendWorkerCalls(): array
{
    global $directory;
    return array_map(static fn($line) => json_decode($line, true), is_file($directory . '/calls.jsonl')
        ? file($directory . '/calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []);
}
function friendWorkerRun(array $arguments = [], string $case = 'normal'): array
{
    global $root, $directory, $workerPath;
    $env = getenv();
    $env['ENHANCEMENT_FRIEND_WORKER_TEST_ROOT'] = $root;
    $env['ENHANCEMENT_FRIEND_WORKER_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_FRIEND_WORKER_TEST_PLUGIN'] = dirname(__DIR__, 2);
    $env['ENHANCEMENT_FRIEND_WORKER_TEST_CASE'] = $case;
    $started = microtime(true);
    $process = proc_open(array_merge([PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=32767', $workerPath], $arguments),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Could not start disposable friend worker.'); }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $state = proc_get_status($process);
        if (!$state['running']) { break; }
        usleep(20000);
    } while (microtime(true) - $started < 6);
    $timedOut = $state['running'];
    if ($timedOut) { proc_terminate($process); }
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    return ['exit' => $state['exitcode'] >= 0 ? $state['exitcode'] : $closed, 'stdout' => $stdout, 'stderr' => $stderr,
        'timed_out' => $timedOut];
}

try {
    $pdo = fixtureDatabase($root, $directory, 'https://friend-worker-fixture.invalid');
    if (!copy(dirname(__DIR__, 2) . '/FriendPosts/worker.php', $workerPath)) {
        throw new RuntimeException('Could not copy worker to the disposable site.');
    }
    file_put_contents($directory . '/config.inc.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/bootstrap.jsonl', json_encode(['config' => __FILE__]) . "\n", FILE_APPEND);
echo 'private-bootstrap-output password=fixture-secret';
ini_set('display_errors', '1');
ini_set('log_errors', '1');
if (getenv('ENHANCEMENT_FRIEND_WORKER_TEST_CASE') === 'bootstrap-error') {
    throw new RuntimeException('private-bootstrap-error password=fixture-secret');
}
if (getenv('ENHANCEMENT_FRIEND_WORKER_TEST_CASE') === 'bootstrap-warning') {
    trigger_error('private-bootstrap-warning password=fixture-secret', E_USER_WARNING);
}
define('__TYPECHO_ROOT_DIR__', getenv('ENHANCEMENT_FRIEND_WORKER_TEST_ROOT'));
define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
define('__TYPECHO_THEME_DIR__', '/usr/themes');
define('__TYPECHO_ADMIN_DIR__', '/admin/');
define('__TYPECHO_DEBUG__', true);
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Common.php';
Typecho\Common::init();
$fixtureDb = new Typecho\Db('Pdo_SQLite', 'typecho_');
$fixtureDb->addServer(['file' => __DIR__ . '/fixture.sqlite'], Typecho\Db::READ | Typecho\Db::WRITE);
Typecho\Db::set($fixtureDb);
PHP
    );
    file_put_contents($pluginDirectory . '/Plugin.php', <<<'PHP'
<?php
require_once getenv('ENHANCEMENT_FRIEND_WORKER_TEST_PLUGIN') . '/SettingsHelper.php';
Enhancement_SettingsHelper::syncOptionCache('plugin:Enhancement', serialize(['enable_friend_posts' => 'stale']), 'Enhancement');
class Enhancement_FriendPostsHelper
{
    public static function refresh($limit = 1, $lid = 0): array
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = $options->plugin('Enhancement');
        file_put_contents(getenv('ENHANCEMENT_FRIEND_WORKER_TEST_DIR') . '/calls.jsonl', json_encode([
            'initialized' => defined('__TYPECHO_CLASS_ALIASES__') && $options instanceof Widget\Options,
            'site_url' => defined('__TYPECHO_ROOT_URL__') ? __TYPECHO_ROOT_URL__ : '',
            'title' => $options->title, 'enabled' => $settings->enable_friend_posts,
            'qq' => $settings->enable_friend_posts_by_qq, 'weixin' => $settings->enable_friend_posts_by_weixin,
            'qq_moments' => $settings->qq_moments_enabled, 'weixin_moments' => $settings->weixin_moments_enabled,
            'limit' => $limit, 'lid' => $lid,
        ]) . "\n", FILE_APPEND);
        $case = getenv('ENHANCEMENT_FRIEND_WORKER_TEST_CASE');
        if ($case === 'refresh-error') {
            throw new RuntimeException('private-feed-error password=fixture-secret root=' . __TYPECHO_ROOT_DIR__);
        }
        if ($case === 'refresh-warning') {
            trigger_error('private-feed-warning password=fixture-secret root=' . __TYPECHO_ROOT_DIR__, E_USER_WARNING);
        }
        echo 'private-refresh-output password=fixture-secret';
        if ($case === 'notification-success') {
            return ['success' => true, 'notifications' => ['success' => true, 'sent' => 3, 'failed' => 0,
                'message' => 'private-notification-message token=fixture-secret']];
        }
        if ($case === 'notification-failure' || $case === 'notification-hostile-counts') {
            return ['success' => false, 'message' => 'private-feed-result password=fixture-secret',
                'notifications' => ['success' => false, 'sent' => $case === 'notification-failure' ? 1 : ['private-token'],
                    'failed' => $case === 'notification-failure' ? 2 : -3, 'token' => 'fixture-secret',
                    'message' => 'private-notification-message token=fixture-secret']];
        }
        return ['success' => $case !== 'refresh-failure', 'message' => 'private-feed-result password=fixture-secret'];
    }
}
PHP
    );

    friendWorkerReset();
    foreach ([['--help'], ['-h']] as $arguments) {
        $result = friendWorkerRun($arguments);
        friendWorkerCheck($result['exit'] === 0 && !$result['timed_out'] && strpos($result['stdout'], '--once') !== false
            && !is_file($directory . '/bootstrap.jsonl') && friendWorkerCalls() === [],
            'Help does not load site configuration: ' . implode(' ', $arguments), $result);
    }
    foreach ([['--loop'], ['--interval=15'], ['--limit=200'], ['--once', '--invalid']] as $arguments) {
        $result = friendWorkerRun($arguments);
        friendWorkerCheck($result['exit'] === 2 && !$result['timed_out'] && $result['stdout'] === ''
            && !is_file($directory . '/bootstrap.jsonl') && friendWorkerCalls() === [],
            'Unsupported arguments do not load configuration: ' . implode(' ', $arguments), $result);
    }

    friendWorkerReset();
    $result = friendWorkerRun(['--once']);
    $calls = friendWorkerCalls();
    friendWorkerCheck($result['exit'] === 0 && !$result['timed_out']
        && $result['stdout'] === "status=success\nnotifications_sent=0 notifications_failed=0\n" && $result['stderr'] === '',
        'Once prints a fixed success status and zero counts when notification results are absent', $result);
    friendWorkerCheck(count($calls) === 1 && $calls[0]['initialized'] === true && $calls[0]['title'] === 'REST fixture site'
        && $calls[0]['site_url'] === 'https://friend-worker-fixture.invalid',
        'Worker initializes real Typecho and supplies an absolute CLI root URL', $calls);
    friendWorkerCheck(($calls[0]['enabled'] ?? '') === '1' && ($calls[0]['qq'] ?? '') === '1',
        'Worker refreshes stale Typecho configuration from the temporary database', $calls);
    friendWorkerCheck(($calls[0]['limit'] ?? 0) === 100 && ($calls[0]['lid'] ?? -1) === 0,
        'Refresh checks up to 100 due feeds without bypassing per-feed intervals', $calls);
    friendWorkerCheck(($calls[0]['qq_moments'] ?? '') === '0' && ($calls[0]['weixin_moments'] ?? '') === '0',
        'Cron works while both moments publishing features are disabled', $calls);
    $bootstrap = json_decode(trim(file_get_contents($directory . '/bootstrap.jsonl')), true);
    friendWorkerCheck(realpath($bootstrap['config'] ?? '') === realpath($directory . '/config.inc.php'),
        'Worker loads only the copied temporary site configuration', $bootstrap);
    friendWorkerCheck(strpos($result['stdout'] . $result['stderr'], 'private-') === false,
        'Bootstrap, helper output and private result details do not reach the console');

    friendWorkerReset();
    $result = friendWorkerRun();
    friendWorkerCheck($result['exit'] === 0 && !$result['timed_out'] && count(friendWorkerCalls()) === 1,
        'Default mode refreshes once and exits', $result);
    foreach ([['enable_friend_posts_by_qq' => '0', 'enable_friend_posts_by_weixin' => '1'],
        ['enable_friend_posts_by_qq' => '1', 'enable_friend_posts_by_weixin' => '1']] as $channels) {
        friendWorkerReset($channels);
        $result = friendWorkerRun(['--once']);
        friendWorkerCheck($result['exit'] === 0 && !$result['timed_out'] && count(friendWorkerCalls()) === 1,
            'Weixin alone or both notification channels can run the cron', $result);
    }
    foreach (['inactive', 'disabled', 'channels-disabled'] as $case) {
        friendWorkerReset($case === 'disabled' ? ['enable_friend_posts' => '0']
            : ($case === 'channels-disabled' ? ['enable_friend_posts_by_qq' => '0', 'enable_friend_posts_by_weixin' => '0'] : []),
            $case !== 'inactive');
        $result = friendWorkerRun(['--once']);
        friendWorkerCheck($result['exit'] === 1 && !$result['timed_out'] && friendWorkerCalls() === []
            && $result['stdout'] === '' && $result['stderr'] !== '' && strpos($result['stderr'], '停止') !== false,
            'Disabled task stops before refreshing: ' . $case, $result);
    }
    foreach (['bootstrap-error', 'bootstrap-warning', 'refresh-error', 'refresh-warning', 'refresh-failure'] as $case) {
        friendWorkerReset();
        $result = friendWorkerRun(['--once'], $case);
        friendWorkerCheck($result['exit'] === 1 && !$result['timed_out'] && $result['stdout'] === ''
            && $result['stderr'] === "朋友文章检查失败，请在后台检查订阅、通知设置及服务器环境。\n"
            && strpos($result['stderr'], 'fixture-secret') === false && strpos($result['stderr'], $root) === false,
            'Failure hides bootstrap and feed details: ' . $case, $result);
        friendWorkerCheck(count(friendWorkerCalls()) === (strpos($case, 'refresh-') === 0 ? 1 : 0),
            'Failures do not retry the refresh: ' . $case);
    }

    friendWorkerReset();
    $result = friendWorkerRun(['--once'], 'notification-success');
    friendWorkerCheck($result['exit'] === 0 && !$result['timed_out'] && $result['stderr'] === ''
        && $result['stdout'] === "status=success\nnotifications_sent=3 notifications_failed=0\n",
        'Successful refresh exposes notification counts without provider messages', $result);
    foreach (['notification-failure', 'notification-hostile-counts'] as $case) {
        friendWorkerReset();
        $result = friendWorkerRun(['--once'], $case);
        $counts = $case === 'notification-failure' ? 'notifications_sent=1 notifications_failed=2' : 'notifications_sent=0 notifications_failed=0';
        friendWorkerCheck($result['exit'] === 1 && !$result['timed_out'] && $result['stdout'] === ''
            && $result['stderr'] === 'reason=notification_failed ' . $counts . "\n朋友文章更新通知失败，请在后台检查 QQ / 微信通道连接和最近发送结果。\n",
            'Notification failure prints safe counts and a fixed diagnosis: ' . $case, $result);
        friendWorkerCheck(count(friendWorkerCalls()) === 1 && strpos($result['stderr'], 'private-') === false
            && strpos($result['stderr'], 'fixture-secret') === false && strpos($result['stderr'], $root) === false,
            'Notification failure neither retries nor exposes private helper data: ' . $case);
    }

    friendWorkerReset();
    rename($directory . '/config.inc.php', $directory . '/config.inc.php.fixture');
    try {
        $result = friendWorkerRun(['--once']);
        friendWorkerCheck($result['exit'] === 1 && !$result['timed_out'] && $result['stdout'] === ''
            && friendWorkerCalls() === [] && strpos($result['stderr'], '检查失败') !== false,
            'Missing site configuration exits safely without refreshing', $result);
    } finally {
        rename($directory . '/config.inc.php.fixture', $directory . '/config.inc.php');
    }
    friendWorkerReset();
    $statement = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=? AND user=0');
    $statement->execute(['invalid-site-url', 'siteUrl']);
    $result = friendWorkerRun(['--once']);
    friendWorkerCheck($result['exit'] === 1 && !$result['timed_out'] && $result['stdout'] === ''
        && friendWorkerCalls() === [] && strpos($result['stderr'], '检查失败') !== false,
        'Invalid CLI root URL fails before initializing a refresh', $result);
    $statement->execute(['https://friend-worker-fixture.invalid/', 'siteUrl']);

    friendWorkerReset();
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) { throw new RuntimeException('Could not allocate a local test port.'); }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $httpProcess = proc_open([PHP_BINARY, '-S', $address, $workerPath],
        [['pipe', 'r'], ['file', $directory . '/web.log', 'a'], ['file', $directory . '/web.log', 'a']],
        $httpPipes, $directory, null, ['bypass_shell' => true]);
    if (!is_resource($httpProcess)) { throw new RuntimeException('Could not start the non-CLI fixture.'); }
    fclose($httpPipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(20000);
    }
    if (!$ready) { throw new RuntimeException('Non-CLI fixture did not start.'); }
    foreach (['GET', 'POST'] as $method) {
        $result = fixtureRequest('http://' . $address, '/FriendPosts/worker.php', $method);
        friendWorkerCheck($result['status'] === 404 && $result['body'] === ''
            && !is_file($directory . '/bootstrap.jsonl') && friendWorkerCalls() === [],
            'Web access cannot load configuration or trigger a refresh: ' . $method, $result);
    }
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo 'FAIL ' . $exception->getMessage() . PHP_EOL;
} finally {
    if (is_resource($httpProcess)) { proc_terminate($httpProcess); proc_close($httpProcess); }
    $statement = null;
    $pdo = null;
}

echo 'Friend worker checks passed: ' . $passed . '; failed: ' . count($failures) . PHP_EOL;
if (!$failures) {
    foreach ([$workerPath, $pluginDirectory . '/Plugin.php', $directory . '/config.inc.php', $directory . '/fixture.sqlite',
        $directory . '/calls.jsonl', $directory . '/bootstrap.jsonl', $directory . '/web.log'] as $file) {
        if (is_file($file)) { unlink($file); }
    }
    foreach ([dirname($workerPath), $pluginDirectory, dirname($pluginDirectory), $directory . '/usr', $directory] as $path) { rmdir($path); }
} else {
    echo 'Fixtures retained: ' . $directory . PHP_EOL;
}
exit($failures ? 1 : 0);
