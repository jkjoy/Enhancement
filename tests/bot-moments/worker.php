<?php
/** Isolated CLI receiver startup tests using real Typecho and disposable SQLite. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('ENHANCEMENT_REST_API_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/rest-api/run.php';
$root = realpath($argv[1] ?? dirname(__DIR__, 5));
if (!$root || !is_file($root . '/var/Typecho/Common.php') || !is_file($root . '/install/SQLite.sql')) {
    fwrite(STDERR, "Supply a Typecho root containing var/Typecho/Common.php and install/SQLite.sql.\n");
    exit(2);
}
$directory = sys_get_temp_dir() . '/enhancement-bot-worker-' . bin2hex(random_bytes(8));
$pluginDirectory = $directory . '/usr/plugins/Enhancement';
$workerPath = $pluginDirectory . '/BotMoments/worker.php';
mkdir(dirname($workerPath), 0700, true);
$passed = 0;
$failures = [];
$pdo = null;

function workerCheck(bool $condition, string $label, $details = null): void
{
    global $passed, $failures;
    if ($condition) { $passed++; return; }
    $failures[] = $label . ($details === null ? '' : ': ' . substr(json_encode($details, JSON_UNESCAPED_UNICODE), 0, 1000));
    echo 'FAIL ' . end($failures) . PHP_EOL;
}
function workerReset(bool $enabled = true, bool $active = true): void
{
    global $pdo, $directory;
    foreach (['calls.jsonl', 'bootstrap.jsonl'] as $file) {
        if (is_file($directory . '/' . $file)) { unlink($directory . '/' . $file); }
    }
    $statement = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=? AND user=0');
    $statement->execute([serialize(['weixin_moments_enabled' => $enabled ? '1' : '0']), 'plugin:Enhancement']);
    $statement->execute([json_encode(['activated' => $active ? ['Enhancement' => []] : [], 'handles' => []]), 'plugins']);
}
function workerCalls(): array
{
    global $directory;
    return array_map(static fn($line) => json_decode($line, true), is_file($directory . '/calls.jsonl')
        ? file($directory . '/calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []);
}
function workerRun(array $arguments = [], string $case = 'normal'): array
{
    global $root, $directory, $workerPath;
    $env = getenv();
    $env['ENHANCEMENT_WORKER_TEST_ROOT'] = $root;
    $env['ENHANCEMENT_WORKER_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_WORKER_TEST_PLUGIN'] = dirname(__DIR__, 2);
    $env['ENHANCEMENT_WORKER_TEST_CASE'] = $case;
    $started = microtime(true);
    $process = proc_open(array_merge([PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=32767', $workerPath], $arguments),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Could not start disposable worker.'); }
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
        'timed_out' => $timedOut, 'elapsed' => microtime(true) - $started];
}

try {
    $pdo = fixtureDatabase($root, $directory, 'http://worker-fixture.invalid');
    // Copying is essential: the worker resolves config.inc.php from its own __DIR__.
    if (!copy(dirname(__DIR__, 2) . '/BotMoments/worker.php', $workerPath)) {
        throw new RuntimeException('Could not copy worker to the disposable site.');
    }
    file_put_contents($directory . '/config.inc.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/bootstrap.jsonl', json_encode(['config' => __FILE__]) . "\n", FILE_APPEND);
echo 'private-bootstrap-output';
ini_set('display_errors', '1');
ini_set('log_errors', '1');
if (getenv('ENHANCEMENT_WORKER_TEST_CASE') === 'bootstrap-error') {
    throw new RuntimeException('private-bootstrap-error password=fixture-secret');
}
if (getenv('ENHANCEMENT_WORKER_TEST_CASE') === 'bootstrap-warning') {
    trigger_error('private-bootstrap-warning password=fixture-secret', E_USER_WARNING);
}
define('__TYPECHO_ROOT_DIR__', getenv('ENHANCEMENT_WORKER_TEST_ROOT'));
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
require_once getenv('ENHANCEMENT_WORKER_TEST_PLUGIN') . '/SettingsHelper.php';
// A stale cached value proves that the worker refreshes the actual Options widget.
Enhancement_SettingsHelper::syncOptionCache('plugin:Enhancement', serialize(['weixin_moments_enabled' => 'stale']), 'Enhancement');
class Enhancement_WeixinNotifyHelper
{
    public static function status(): array
    {
        $case = getenv('ENHANCEMENT_WORKER_TEST_CASE');
        return ['logged_in' => $case !== 'logged-out', 'expired' => $case === 'expired',
            'user_id' => $case === 'unbound' ? '' : 'fixture-owner', 'token' => 'private-state-token'];
    }
    public static function sync(): array
    {
        $options = Typecho_Widget::widget('Widget_Options');
        file_put_contents(getenv('ENHANCEMENT_WORKER_TEST_DIR') . '/calls.jsonl', json_encode([
            'initialized' => defined('__TYPECHO_CLASS_ALIASES__') && $options instanceof Widget\Options,
            'title' => $options->title, 'cached_enabled' => $options->plugin('Enhancement')->weixin_moments_enabled,
        ]) . "\n", FILE_APPEND);
        $case = getenv('ENHANCEMENT_WORKER_TEST_CASE');
        if ($case === 'sync-error') {
            throw new RuntimeException('private-upstream-error password=fixture-secret root=' . __TYPECHO_ROOT_DIR__);
        }
        if ($case === 'sync-warning') {
            trigger_error('private-upstream-warning password=fixture-secret root=' . __TYPECHO_ROOT_DIR__, E_USER_WARNING);
        }
        if ($case === 'loop-disable') {
            $db = Typecho_Db::get();
            $db->query($db->update('table.options')->rows(['value' => serialize(['weixin_moments_enabled' => '0'])])
                ->where('name = ?', 'plugin:Enhancement')->where('user = ?', 0));
        }
        return ['status' => $case === 'hostile-status' ? 'private-status-token' : 'bound',
            'moments_published' => 2, 'moments_failed' => 1, 'message' => 'private-result-message'];
    }
}
PHP
    );

    workerReset();
    $result = workerRun(['--help']);
    workerCheck($result['exit'] === 0 && !$result['timed_out'] && strpos($result['stdout'], '--interval=N') !== false
        && !is_file($directory . '/bootstrap.jsonl') && workerCalls() === [], 'Help exits before loading site configuration', $result);
    $result = workerRun(['--interval=0']);
    workerCheck($result['exit'] === 2 && !$result['timed_out'] && !is_file($directory . '/bootstrap.jsonl'),
        'Invalid arguments exit before loading site configuration', $result);

    workerReset();
    $result = workerRun(['--once']);
    $calls = workerCalls();
    workerCheck($result['exit'] === 0 && !$result['timed_out'] && $result['stdout'] === "status=bound published=2 failed=1\n" && $result['stderr'] === '',
        'Single-shot worker starts and prints safe counts', $result);
    workerCheck(count($calls) === 1 && $calls[0]['initialized'] === true && $calls[0]['title'] === 'REST fixture site',
        'Worker runs the real Typecho initialization with disposable SQLite', $calls);
    workerCheck(($calls[0]['cached_enabled'] ?? '') === '1', 'Worker refreshes stale configuration in the real Options widget', $calls);
    $bootstrap = json_decode(trim(file_get_contents($directory . '/bootstrap.jsonl')), true);
    workerCheck(realpath($bootstrap['config'] ?? '') === realpath($directory . '/config.inc.php'), 'Worker loads only the copied temporary site configuration', $bootstrap);
    workerCheck(strpos($result['stdout'] . $result['stderr'], 'private-') === false,
        'Bootstrap output and private state never appear in worker output');

    workerReset();
    $result = workerRun();
    workerCheck($result['exit'] === 0 && !$result['timed_out'] && count(workerCalls()) === 1, 'Default mode performs exactly one synchronization', $result);
    foreach (['disabled', 'inactive', 'expired', 'unbound', 'logged-out'] as $case) {
        workerReset($case !== 'disabled', $case !== 'inactive');
        $result = workerRun(['--once'], $case);
        workerCheck($result['exit'] === 1 && !$result['timed_out'] && workerCalls() === [] && $result['stdout'] === '' && $result['stderr'] !== '',
            'Worker stops before synchronization: ' . $case, $result);
    }
    foreach (['bootstrap-error', 'bootstrap-warning', 'sync-error', 'sync-warning'] as $case) {
        workerReset();
        $result = workerRun(['--once'], $case);
        workerCheck($result['exit'] === 1 && !$result['timed_out'] && $result['stdout'] === ''
            && $result['stderr'] === "微信接收失败，请在后台检查微信连接、插件设置及服务器环境后重启接收器。\n"
            && strpos($result['stderr'], 'private-') === false && strpos($result['stderr'], $root) === false,
            'Worker hides sensitive exception details: ' . $case, $result);
        workerCheck(count(workerCalls()) === (strpos($case, 'sync-') === 0 ? 1 : 0), 'Exception does not retry synchronization: ' . $case);
    }
    workerReset();
    $result = workerRun(['--once'], 'hostile-status');
    workerCheck($result['exit'] === 0 && $result['stdout'] === "status=synced published=2 failed=1\n" && $result['stderr'] === '',
        'Unexpected upstream status cannot leak into CLI output', $result);

    workerReset();
    $result = workerRun(['--loop', '--interval=1'], 'loop-disable');
    workerCheck($result['exit'] === 1 && !$result['timed_out'] && count(workerCalls()) === 1
        && $result['stdout'] === "status=bound published=2 failed=1\n" && strpos($result['stderr'], '微信发布瞬间已关闭') !== false,
        'Loop reloads a disabled database setting and stops after one synchronization', $result);
    workerCheck($result['elapsed'] >= 0.9 && $result['elapsed'] < 6, 'Loop pauses between polls and exits within the bounded test timeout', $result['elapsed']);
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo 'FAIL ' . $exception->getMessage() . PHP_EOL;
} finally {
    $pdo = null;
}
echo 'Bot worker checks passed: ' . $passed . '; failed: ' . count($failures) . PHP_EOL;
if (!$failures) {
    foreach ([$workerPath, $pluginDirectory . '/Plugin.php', $directory . '/config.inc.php', $directory . '/fixture.sqlite',
        $directory . '/calls.jsonl', $directory . '/bootstrap.jsonl'] as $file) {
        if (is_file($file)) { unlink($file); }
    }
    foreach ([dirname($workerPath), $pluginDirectory, dirname($pluginDirectory), $directory . '/usr', $directory] as $path) { rmdir($path); }
} else {
    echo 'Fixtures retained: ' . $directory . PHP_EOL;
}
exit($failures ? 1 : 0);
