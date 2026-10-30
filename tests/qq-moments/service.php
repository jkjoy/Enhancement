<?php
/** QQ Ed25519 webhook -> Python worker -> HMAC callback -> real Typecho/SQLite.
 * Usage: php tests/qq-moments/service.php [Typecho root] [Python with service requirements]
 */
declare(strict_types=1);
define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

function qmsRemoveFixture(string $path): void
{
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) { continue; }
        $name = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) { qmsRemoveFixture($name); }
        else { @unlink($name); }
    }
    @rmdir($path);
}

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $env = getenv();
    $env['ENHANCEMENT_QQ_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_QQ_TEST_ROOT'] = $root;
    $env['PYTHONUTF8'] = '1';
    $env['PYTHONDONTWRITEBYTECODE'] = '1';
    qqStartServer($address, dirname(__DIR__) . '/qq-notify/router.php', $env, $directory . '/typecho.log');
    $commandSecret = 'fixture-commands-' . str_repeat('c', 32);
    $response = qqConfig(['qq_notify_provider' => 'official', 'qq_official_url' => 'http://127.0.0.1:8080',
        'qq_official_token' => 'fixture-notifications-' . str_repeat('n', 32), 'qq_official_targets' => 'owner',
        'qq_moments_enabled' => '1', 'qq_moments_secret' => $commandSecret, 'qq_moments_targets' => 'owner']);
    qqCheck($response['status'] === 200, 'Real Typecho fixture enables official service publishing', $response);

    $python = $argv[2] ?? (getenv('ENHANCEMENT_QQ_PYTHON') ?: 'python');
    $process = proc_open([$python, '-B', __DIR__ . '/service-client.py'],
        [['pipe', 'r'], ['file', $directory . '/service-client.out', 'w'], ['file', $directory . '/service-client.err', 'w']],
        $pipes, __DIR__, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Could not start Python integration client.'); }
    fwrite($pipes[0], json_encode(['callback' => $base . '/action/enhancement-edit?do=qq-moments-webhook',
        'database' => $directory . '/fixture.sqlite', 'secret' => $commandSecret, 'data_dir' => $directory . '/service'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $exit = proc_close($process);
    $output = file_get_contents($directory . '/service-client.out');
    $errors = file_get_contents($directory . '/service-client.err');
    $report = json_decode($output, true);
    qqCheck($exit === 0 && is_array($report) && ($report['ok'] ?? false) === true,
        'Real Python service passes signed webhook, publication and passive reply integration',
        ['exit' => $exit, 'output' => $output, 'errors' => $errors]);
    if ($exit === 0 && is_array($report)) {
        $passed += (int)($report['checks'] ?? 0);
    }

    $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='typecho_moments'")->fetchColumn();
    $rows = $exists ? $pdo->query('SELECT content,status,source FROM typecho_moments ORDER BY mid')->fetchAll(PDO::FETCH_ASSOC) : [];
    qqCheck(count($rows) === 3, 'Transport and reply retries leave exactly three published moments', $rows);
    qqCheck(($rows[0] ?? null) === ['content' => 'QQ发布瞬间测试!~', 'status' => 'public', 'source' => 'qq'],
        'Original user command persists through the full service pipeline', $rows[0] ?? null);
    qqCheck(($rows[1] ?? null) === ['content' => "开心[微笑] 😄\n第二行", 'status' => 'public', 'source' => 'qq'],
        'QQ expression, Unicode emoji and line breaks persist exactly', $rows[1] ?? null);
    qqCheck(($rows[2] ?? null) === ['content' => '只给自己看 🔒', 'status' => 'private', 'source' => 'qq'],
        'Signed private command persists with private visibility', $rows[2] ?? null);
    $log = file_get_contents($directory . '/typecho.log');
    qqCheck(!preg_match('/PHP (?:Warning|Fatal error|Parse error|Uncaught)/', $log),
        'Cross-language publication produces no PHP runtime warnings or errors', $log);
} catch (Throwable $exception) {
    $failures[] = 'Runner exception: ' . $exception->getMessage();
} finally {
    foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
    if ($failures) { echo 'Fixture retained at ' . $directory . "\n"; }
    else { $pdo = null; qmsRemoveFixture($directory); }
}
echo $passed . " checks passed\n";
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
