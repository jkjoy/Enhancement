<?php
/** PHP/Node integration with disposable Typecho SQLite; no real QQ traffic. */
declare(strict_types=1);
define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

try {
    $serviceDir = getenv('ENHANCEMENT_QQ_CHANNEL_DIR');
    if (!$serviceDir || !is_file($serviceDir . '/src/service.js')) {
        throw new RuntimeException('Set ENHANCEMENT_QQ_CHANNEL_DIR to the dockerfile/qq-channel checkout.');
    }
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $env = getenv();
    $env['ENHANCEMENT_QQ_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_QQ_TEST_ROOT'] = $root;
    $env['ENHANCEMENT_QQ_TEST_BASE'] = $base;
    qqStartServer($address, dirname(__DIR__) . '/qq-notify/router.php', $env, $directory . '/typecho.log');
    $process = proc_open(['node', __DIR__ . '/bridge.mjs'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes, __DIR__, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Could not start Node integration.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    qqCheck($code === 0, 'Node SDK/service integration passes against actual PHP', [$output, $errors]);
    qqCheck($pdo->query('SELECT status FROM typecho_comments WHERE coid=2')->fetchColumn() === 'approved', 'QQ command approves actual Typecho comment');
    qqCheck((int)$pdo->query("SELECT COUNT(*) FROM typecho_comments WHERE parent=2 AND text='独立通道回复🙂'")->fetchColumn() === 1,
        'Duplicate official event inserts exactly one administrator reply');
    qqCheck((int)$pdo->query('SELECT state FROM typecho_links WHERE lid=2')->fetchColumn() === 1, 'QQ command approves actual friend link');
    $settings = qqSettings();
    qqCheck(($settings['qq_notify_provider'] ?? '') === 'standalone', 'Standalone mode persists through real config handler');
    $form = fixtureRequest($base, '/form');
    qqCheck($form['status'] === 200 && strpos($form['body'], 'value="standalone"') !== false
        && strpos($form['body'], str_repeat('a', 48)) === false, 'Real backend renders standalone option and hides service token');
    qqCheck(strpos($form['body'], 'value="openclaw"') === false && strpos($form['body'], 'value="hermes"') === false
        && strpos($form['body'], 'name="qq_agent_url"') === false && strpos($form['body'], 'name="qq_agent_token"') === false,
        'Removed providers and their dedicated settings are absent from the real standalone form');
    qqCheck(preg_match('/<input[^>]*id="qq_agent_commands-1"[^>]*checked=/', $form['body']) === 1
        && strpos($form['body'], 'name="qq_agent_command_secret"') !== false && strpos($form['body'], str_repeat('b', 48)) === false,
        'Existing standalone management stays enabled and its command secret stays hidden');
    qqCheck(qqConfig(['qq_agent_commands' => '1', 'qq_agent_command_secret' => $settings['qq_official_token']])['status'] === 400,
        'Standalone requires a separate command secret');
    qqCheck(qqConfig(['qq_agent_allowed_senders' => '*'])['status'] === 400, 'Standalone rejects wildcard command identity');
    qqCheck(qqConfig(['qq_agent_commands' => '0'])['status'] === 200, 'Command switch independently turns off');
    qqCheck(preg_match('/PHP (?:Warning|Fatal error|Parse error|Deprecated|Notice)/i', file_get_contents($directory . '/typecho.log')) !== 1,
        'Real PHP/Node flow has no PHP warnings');
    echo $output;
} catch (Throwable $exception) {
    qqCheck(false, 'Runner exception', $exception->getMessage());
} finally {
    foreach ($processes as $process) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
    $pdo = null;
    if (!$failures) { foreach (glob($directory . '/*') as $file) { unlink($file); } rmdir($directory); }
}
echo $passed . ' PHP integration assertions passed; ' . count($failures) . " failed.\n";
if ($failures) { echo 'Fixtures retained: ' . $directory . "\n"; }
exit($failures ? 1 : 0);
