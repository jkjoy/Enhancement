<?php
/** Offline scheduler checks using real Typecho and disposable SQLite. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('ENHANCEMENT_REST_API_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/rest-api/run.php';
$root = realpath(getenv('ENHANCEMENT_SCHEDULER_TEST_ROOT') ?: ($argv[1] ?? dirname(__DIR__, 5)));
if (!$root || !is_file($root . '/var/Typecho/Common.php') || !is_file($root . '/install/SQLite.sql')) {
    fwrite(STDERR, "Supply a Typecho root containing var/Typecho/Common.php and install/SQLite.sql.\n");
    exit(2);
}
$child = getenv('ENHANCEMENT_SCHEDULER_TEST_CHILD') ?: '';
$directory = $child !== '' ? getenv('ENHANCEMENT_SCHEDULER_TEST_DIR')
    : sys_get_temp_dir() . '/enhancement-scheduler-' . bin2hex(random_bytes(8));
if ($child === '' && !mkdir($directory, 0700)) { throw new RuntimeException('Could not create isolated scheduler fixture.'); }
$pdo = $child === '' ? fixtureDatabase($root, $directory, 'https://scheduler-fixture.invalid')
    : new PDO('sqlite:' . $directory . '/fixture.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout=5000');

define('__TYPECHO_ROOT_DIR__', $root);
define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
define('__TYPECHO_THEME_DIR__', '/usr/themes');
define('__TYPECHO_ADMIN_DIR__', '/admin/');
define('__TYPECHO_DEBUG__', true);
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once $root . '/var/Typecho/Common.php';
Typecho\Common::init();
$fixtureDb = new Typecho\Db('Pdo_SQLite', 'typecho_');
$fixtureDb->addServer(['file' => $directory . '/fixture.sqlite'], Typecho\Db::READ | Typecho\Db::WRITE);
Typecho\Db::set($fixtureDb);
$fixtureDb->query('PRAGMA busy_timeout=5000', Typecho\Db::WRITE);
require_once dirname(__DIR__, 2) . '/SettingsHelper.php';
require_once dirname(__DIR__, 2) . '/NotifyTransportHelper.php';
require_once dirname(__DIR__, 2) . '/BotMomentsHelper.php';
require_once dirname(__DIR__, 2) . '/WeixinNotifyHelper.php';

class Enhancement_Plugin
{
    public static function runtimeSettings() { return (object)Enhancement_SettingsHelper::readPluginSettingsFromDatabase(); }
}
require_once dirname(__DIR__, 2) . '/BotMomentsSchedulerHelper.php';

$passed = 0;
$failures = [];
function schedulerCheck(bool $condition, string $label, $details = null): void
{
    global $passed, $failures;
    if ($condition) { $passed++; return; }
    $failures[] = $label . ($details === null ? '' : ': ' . substr(json_encode($details, JSON_UNESCAPED_UNICODE), 0, 1000));
    echo 'FAIL ' . end($failures) . PHP_EOL;
}
function schedulerSettings(array $changes): array
{
    global $pdo;
    $settings = array_replace(Enhancement_SettingsHelper::readPluginSettingsFromDatabase(), $changes);
    $statement = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=? AND user=0');
    $statement->execute([serialize($settings), 'plugin:Enhancement']);
    return $settings;
}
function schedulerOption(string $name, string $value): void
{
    global $pdo;
    $statement = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=? AND user=0');
    $statement->execute([$value, $name]);
    Typecho_Widget::destroy();
}
function schedulerReset(array $changes = []): void
{
    global $pdo;
    $pdo->exec("DELETE FROM typecho_options WHERE name LIKE 'enh:notify:%'");
    $statement = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=? AND user=0');
    $statement->execute([serialize(array_replace(['weixin_moments_enabled' => '1'], $changes)), 'plugin:Enhancement']);
    $statement->execute([json_encode(['activated' => ['Enhancement' => []], 'handles' => []]), 'plugins']);
    schedulerOption('siteUrl', 'https://scheduler-fixture.invalid/');
    schedulerOption('rewrite', '1');
    Enhancement_NotifyTransportHelper::write('weixin_account', ['token' => 'private-account-token', 'account_id' => 'fixture-bot',
        'owner_id' => 'fixture-owner', 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false]);
    Enhancement_NotifyTransportHelper::write('weixin_recipient', ['user_id' => 'fixture-owner']);
    Enhancement_NotifyTransportHelper::write('weixin_sync', ['candidates' => ['fixture-owner'], 'context_tokens' => []]);
    Enhancement_BotMomentsHelper::syncActivation(Enhancement_SettingsHelper::readPluginSettingsFromDatabase());
}
function schedulerDispatch(?callable $callback = null): array
{
    $calls = [];
    $result = Enhancement_BotMomentsSchedulerHelper::dispatchIfDue(static function ($url, $token) use (&$calls, $callback): bool {
        $calls[] = ['url' => $url, 'token' => $token];
        return $callback === null ? true : (bool)$callback($url, $token);
    });
    return ['dispatched' => $result, 'calls' => $calls];
}
function schedulerState(array $changes = []): array
{
    $state = Enhancement_NotifyTransportHelper::read('weixin_cron');
    if ($changes !== []) {
        $state = array_replace($state, $changes);
        Enhancement_NotifyTransportHelper::write('weixin_cron', $state);
    }
    return $state;
}
function schedulerQueuedToken(): string
{
    $result = schedulerDispatch();
    if (!$result['dispatched'] || count($result['calls']) !== 1) { throw new RuntimeException('Expected one offline scheduled dispatch.'); }
    return $result['calls'][0]['token'];
}
function schedulerFixtureProcesses(string $operation, array $values): array
{
    global $directory, $root;
    $processes = [];
    foreach ($values as $value) {
        $env = getenv();
        $env['ENHANCEMENT_SCHEDULER_TEST_ROOT'] = $root;
        $env['ENHANCEMENT_SCHEDULER_TEST_DIR'] = $directory;
        $env['ENHANCEMENT_SCHEDULER_TEST_CHILD'] = $operation;
        $env['ENHANCEMENT_SCHEDULER_TEST_VALUE'] = $value;
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=32767', __FILE__],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory, $env, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new RuntimeException('Could not start isolated scheduler child.'); }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }
    $results = [];
    foreach ($processes as $process) {
        $stdout = stream_get_contents($process['stdout']);
        $stderr = stream_get_contents($process['stderr']);
        fclose($process['stdout']); fclose($process['stderr']);
        $results[] = ['exit' => proc_close($process['process']), 'json' => json_decode($stdout, true), 'stderr' => $stderr];
    }
    return $results;
}

if ($child !== '') {
    if ($child === 'claim') {
        echo json_encode(['claim' => Enhancement_BotMomentsSchedulerHelper::claim(getenv('ENHANCEMENT_SCHEDULER_TEST_VALUE'))]);
    } elseif ($child === 'dispatch') {
        echo json_encode(schedulerDispatch());
    } else { exit(2); }
    exit;
}

try {
    foreach ([[], ['weixin_moments_auto_receive' => '0'], ['weixin_moments_auto_receive' => '1']] as $settings) {
        Enhancement_BotMomentsSchedulerHelper::validateSettings($settings);
        schedulerCheck(true, 'Automatic receiving accepts an absent setting or a valid switch');
    }
    foreach ([['1'], 'yes', 2, new stdClass()] as $value) {
        $rejected = false;
        try { Enhancement_BotMomentsSchedulerHelper::validateSettings(['weixin_moments_auto_receive' => $value]); }
        catch (Throwable $exception) { $rejected = $exception->getCode() === 400; }
        schedulerCheck($rejected, 'Automatic receiving rejects malformed settings before saving');
    }

    schedulerReset();
    $first = schedulerDispatch();
    schedulerCheck($first['dispatched'] === true && count($first['calls']) === 1,
        'Existing enabled Weixin publishing defaults to automatic receiving', $first);
    $request = $first['calls'][0] ?? [];
    $url = parse_url($request['url'] ?? '');
    schedulerCheck(($url['scheme'] ?? '') === 'https' && ($url['host'] ?? '') === 'scheduler-fixture.invalid'
        && empty($url['fragment']) && strpos($request['url'] ?? '', $request['token'] ?? 'missing') === false,
        'Wakeup goes to the configured site URL without exposing its bearer in the URL', $request['url'] ?? '');
    schedulerCheck(is_string($request['token'] ?? null) && strlen($request['token']) >= 32
        && $request['token'] !== 'private-account-token', 'Wakeup uses an independent unpredictable credential');
    $queued = schedulerState();
    schedulerCheck(($queued['status'] ?? '') === 'queued' && ($queued['next_at'] ?? 0) >= time() + 59
        && ($queued['expires_at'] ?? 0) >= time() + 119 && ($queued['expires_at'] ?? 0) <= time() + 120,
        'Queued wakeup is limited to one minute and expires after two minutes', $queued);
    schedulerCheck(($queued['token_hash'] ?? '') === hash('sha256', $request['token'])
        && strpos(json_encode($queued), $request['token']) === false
        && strpos(json_encode($queued), 'private-account-token') === false,
        'Persisted wakeup stores only independent hashes without bot credentials');
    $second = schedulerDispatch();
    schedulerCheck($second['dispatched'] === false && $second['calls'] === [],
        'Repeated visitor requests do not start another poll before it is due', $second);

    schedulerReset(['weixin_moments_auto_receive' => '1']);
    schedulerCheck(schedulerDispatch()['dispatched'], 'An explicit enabled automatic switch permits receiving');
    foreach (['publish-disabled', 'auto-disabled', 'plugin-inactive', 'expired', 'no-token', 'no-account', 'no-recipient', 'wrong-owner', 'no-gate'] as $case) {
        schedulerReset();
        if ($case === 'publish-disabled') { schedulerSettings(['weixin_moments_enabled' => '0']); }
        if ($case === 'auto-disabled') { schedulerSettings(['weixin_moments_auto_receive' => '0']); }
        if ($case === 'plugin-inactive') {
            $statement = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=? AND user=0');
            $statement->execute([json_encode(['activated' => [], 'handles' => []]), 'plugins']);
        }
        if (in_array($case, ['expired', 'no-token', 'no-account'], true)) {
            $account = Enhancement_NotifyTransportHelper::read('weixin_account');
            if ($case === 'expired') { $account['expired'] = true; }
            if ($case === 'no-token') { unset($account['token']); }
            if ($case === 'no-account') { unset($account['account_id']); }
            Enhancement_NotifyTransportHelper::write('weixin_account', $account);
        }
        if ($case === 'no-recipient') { Enhancement_NotifyTransportHelper::write('weixin_recipient', []); }
        if ($case === 'wrong-owner') { Enhancement_NotifyTransportHelper::write('weixin_recipient', ['user_id' => 'different-owner']); }
        if ($case === 'no-gate') { Enhancement_NotifyTransportHelper::write('bot_moments_gate', []); }
        $result = schedulerDispatch();
        schedulerCheck(!$result['dispatched'] && $result['calls'] === [], 'Unready site never starts a wakeup: ' . $case, $result);
    }

    schedulerReset();
    $parallel = schedulerFixtureProcesses('dispatch', ['', '']);
    schedulerCheck(count(array_filter($parallel, static fn($result) => ($result['json']['dispatched'] ?? false) === true)) === 1
        && array_sum(array_map(static fn($result) => count($result['json']['calls'] ?? []), $parallel)) === 1,
        'Concurrent visitor requests dispatch only one scheduled poll', $parallel);
    schedulerCheck(!array_filter($parallel, static fn($result) => $result['exit'] !== 0 || $result['stderr'] !== ''),
        'Concurrent dispatch uses real SQLite without emitting warnings or database details', $parallel);

    schedulerReset();
    $_SERVER['HTTP_HOST'] = 'attacker.invalid';
    $_SERVER['SERVER_NAME'] = 'attacker.invalid';
    $_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.invalid';
    $request = schedulerDispatch()['calls'][0] ?? [];
    schedulerCheck(($request['url'] ?? '') === 'https://scheduler-fixture.invalid/action/enhancement-edit?do=bot-moments-cron',
        'An attacker-controlled Host header cannot receive the wakeup credential', $request['url'] ?? '');
    unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME'], $_SERVER['HTTP_X_FORWARDED_HOST']);

    foreach ([['https://scheduler-fixture.invalid/blog/', '1', 'https://scheduler-fixture.invalid/blog/action/enhancement-edit?do=bot-moments-cron'],
        ['https://scheduler-fixture.invalid/blog/', '0', 'https://scheduler-fixture.invalid/blog/index.php/action/enhancement-edit?do=bot-moments-cron'],
        ['http://scheduler-fixture.invalid:8080/', '0', 'http://scheduler-fixture.invalid:8080/index.php/action/enhancement-edit?do=bot-moments-cron']] as [$siteUrl, $rewrite, $expectedUrl]) {
        schedulerReset();
        schedulerOption('siteUrl', $siteUrl);
        schedulerOption('rewrite', $rewrite);
        $result = schedulerDispatch();
        schedulerCheck($result['dispatched'] && ($result['calls'][0]['url'] ?? '') === $expectedUrl,
            'Saved site subdirectories, rewrite mode, and explicit ports produce a valid internal task URL', $result['calls'][0]['url'] ?? '');
    }
    foreach (['file:///tmp/secret', 'https:///missing-host', 'https://user:private-password@scheduler-fixture.invalid/',
        'https://scheduler-fixture.invalid/?token=secret', 'https://scheduler-fixture.invalid/#secret',
        "https://scheduler-fixture.invalid/\r\nprivate-header", 'https://scheduler-fixture.invalid\\@attacker.invalid/'] as $siteUrl) {
        schedulerReset();
        schedulerOption('siteUrl', $siteUrl);
        $result = schedulerDispatch();
        $state = schedulerState();
        schedulerCheck(!$result['dispatched'] && $result['calls'] === [] && ($state['status'] ?? '') === 'dispatch_failed'
            && !isset($state['token_hash']) && ($state['expires_at'] ?? -1) === 0 && ($state['next_at'] ?? 0) >= time() + 59,
            'Malformed saved URLs cannot dispatch and retain only a safe delayed failure status', ['url' => $siteUrl, 'result' => $result, 'state' => $state]);
    }

    schedulerReset();
    $statement = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=? AND user=0');
    $statement->execute([serialize(['activated' => ['Enhancement' => []], 'handles' => []]), 'plugins']);
    schedulerCheck(schedulerDispatch()['dispatched'], 'Serialized Typecho activation records remain compatible');

    schedulerReset();
    $token = schedulerQueuedToken();
    $queued = schedulerState();
    foreach (['', 'wrong', strtoupper($token), str_repeat('f', 64), $token . "\n"] as $invalid) {
        schedulerCheck(Enhancement_BotMomentsSchedulerHelper::claim($invalid) === null && schedulerState() === $queued,
            'Malformed or incorrect wakeup credentials leave the pending task untouched');
    }
    $claim = Enhancement_BotMomentsSchedulerHelper::claim($token);
    schedulerCheck(is_array($claim) && ($claim['status'] ?? '') === 'running' && !isset($claim['token_hash'])
        && preg_match('/\A[a-f0-9]{32}\z/D', $claim['run_id'] ?? '') === 1
        && ($claim['expires_at'] ?? 0) >= time() + 179 && ($claim['expires_at'] ?? 0) <= time() + 180,
        'Valid credential is consumed into one separately identified three-minute running claim', $claim);
    schedulerCheck(Enhancement_BotMomentsSchedulerHelper::claim($token) === null,
        'A consumed wakeup credential cannot be replayed');
    $calls = 0;
    $recursive = null;
    ob_start();
    Enhancement_BotMomentsSchedulerHelper::run($claim ?? [], static function () use (&$calls, &$recursive): array {
        $calls++;
        $recursive = schedulerDispatch();
        echo 'private-sync-output account-token=private-account-token';
        return ['status' => 'bound', 'moments_published' => 2, 'moments_drafts' => 1, 'moments_failed' => 0,
            'message' => 'private-result-message', 'token' => 'private-result-token'];
    });
    $output = ob_get_clean();
    $completed = schedulerState();
    schedulerCheck($calls === 1 && $output === '' && ($completed['status'] ?? '') === 'bound'
        && ($completed['expires_at'] ?? -1) === 0 && ($completed['last_run'] ?? 0) >= time() - 1
        && ($completed['counts'] ?? []) === ['moments_published' => 2, 'moments_drafts' => 1, 'moments_failed' => 0],
        'One claimed wakeup performs one synchronization and records only safe status and counts', $completed);
    schedulerCheck($recursive === ['dispatched' => false, 'calls' => []]
        && strpos(json_encode($completed), 'private-') === false,
        'Running a task does not recursively schedule another task or retain upstream output');
    Enhancement_BotMomentsSchedulerHelper::run($claim ?? [], static function () use (&$calls): array { $calls++; return ['status' => 'bound']; });
    schedulerCheck($calls === 1 && schedulerState() === $completed, 'Completed claims cannot execute again');
    schedulerCheck(!schedulerDispatch()['dispatched'], 'Finishing a poll retains its original visitor rate limit');
    schedulerState(['next_at' => time() - 1]);
    $replacementToken = schedulerQueuedToken();
    schedulerCheck($replacementToken !== $token && Enhancement_BotMomentsSchedulerHelper::claim($token) === null,
        'A due task creates a fresh credential and rejects credentials from earlier polls');

    schedulerReset();
    $token = schedulerQueuedToken();
    $parallel = schedulerFixtureProcesses('claim', [$token, $token]);
    schedulerCheck(count(array_filter($parallel, static fn($result) => is_array($result['json']['claim'] ?? null))) === 1,
        'Two independent processes can consume the same wakeup credential only once', $parallel);
    schedulerCheck(!array_filter($parallel, static fn($result) => $result['exit'] !== 0 || $result['stderr'] !== ''),
        'Concurrent claims fail cleanly without warnings or database details', $parallel);

    schedulerReset();
    $token = schedulerQueuedToken();
    schedulerState(['expires_at' => time() - 1]);
    schedulerCheck(Enhancement_BotMomentsSchedulerHelper::claim($token) === null, 'Expired queued credentials cannot start receiving');
    schedulerState(['next_at' => time() - 1]);
    schedulerCheck(schedulerDispatch()['dispatched'], 'Expired abandoned tasks can be replaced once the rate limit has elapsed');
    schedulerReset();
    $token = schedulerQueuedToken();
    $claim = Enhancement_BotMomentsSchedulerHelper::claim($token);
    $expired = schedulerState(['expires_at' => time() - 1]);
    $calls = 0;
    Enhancement_BotMomentsSchedulerHelper::run($expired, static function () use (&$calls): array { $calls++; return ['status' => 'bound']; });
    schedulerCheck($calls === 0 && schedulerState() === $expired, 'Expired running claims cannot resume receiving');

    foreach (['auto-disabled', 'publish-disabled', 'plugin-inactive', 'binding-changed', 'reactivated'] as $case) {
        foreach (['before-claim', 'before-run'] as $stage) {
            schedulerReset();
            $token = schedulerQueuedToken();
            $claim = $stage === 'before-run' ? Enhancement_BotMomentsSchedulerHelper::claim($token) : null;
            if ($case === 'auto-disabled') { schedulerSettings(['weixin_moments_auto_receive' => '0']); }
            if ($case === 'publish-disabled') { schedulerSettings(['weixin_moments_enabled' => '0']); }
            if ($case === 'plugin-inactive') {
                $statement->execute([json_encode(['activated' => [], 'handles' => []]), 'plugins']);
            }
            if ($case === 'binding-changed') {
                $account = Enhancement_NotifyTransportHelper::read('weixin_account');
                $account['token'] = 'private-new-account-token';
                Enhancement_NotifyTransportHelper::write('weixin_account', $account);
            }
            if ($case === 'reactivated') {
                Enhancement_BotMomentsHelper::syncActivation(schedulerSettings(['weixin_moments_enabled' => '0']));
                Enhancement_BotMomentsHelper::syncActivation(schedulerSettings(['weixin_moments_enabled' => '1']));
            }
            $calls = 0;
            if ($stage === 'before-claim') { $claim = Enhancement_BotMomentsSchedulerHelper::claim($token); }
            elseif (is_array($claim)) {
                Enhancement_BotMomentsSchedulerHelper::run($claim, static function () use (&$calls): array { $calls++; return ['status' => 'bound']; });
            }
            schedulerCheck($calls === 0 && ($stage !== 'before-claim' || $claim === null),
                'Configuration changes revoke old tasks ' . $stage . ': ' . $case);
            if ($stage === 'before-run') { schedulerCheck((schedulerState()['status'] ?? '') === 'disabled', 'Revoked running task records a safe disabled status: ' . $case); }
        }
    }

    schedulerReset();
    $calls = 0;
    $result = schedulerDispatch(static function () use (&$calls): bool { $calls++; return false; });
    $failed = schedulerState();
    schedulerCheck(!$result['dispatched'] && $calls === 1 && ($failed['status'] ?? '') === 'dispatch_failed'
        && !isset($failed['token_hash']) && ($failed['expires_at'] ?? -1) === 0,
        'Failed dispatch revokes its credential and records a bounded retry state', $failed);
    schedulerCheck(Enhancement_BotMomentsSchedulerHelper::claim($result['calls'][0]['token']) === null
        && !schedulerDispatch()['dispatched'], 'Failed dispatch cannot execute later or retry on every page visit');
    schedulerReset();
    ob_start();
    $result = schedulerDispatch(static function (): bool { throw new RuntimeException('private-dispatch-error token=private-account-token'); });
    $output = ob_get_clean();
    schedulerCheck(!$result['dispatched'] && $output === '' && (schedulerState()['status'] ?? '') === 'dispatch_failed'
        && strpos(json_encode(schedulerState()), 'private-') === false,
        'Dispatch exceptions do not leak private details to output or persisted state');

    foreach (['exception', 'hostile-result'] as $case) {
        schedulerReset();
        $claim = Enhancement_BotMomentsSchedulerHelper::claim(schedulerQueuedToken());
        ob_start();
        Enhancement_BotMomentsSchedulerHelper::run($claim ?? [], static function () use ($case): array {
            echo 'private-worker-output';
            if ($case === 'exception') { throw new RuntimeException('private-worker-error token=private-account-token'); }
            return ['status' => 'private-hostile-status', 'moments_published' => -10, 'moments_drafts' => 999999999, 'token' => 'private-result-token'];
        });
        $output = ob_get_clean();
        $state = schedulerState();
        schedulerCheck($output === '' && ($state['status'] ?? '') === 'error' && strpos(json_encode($state), 'private-') === false,
            'Synchronization failure is safely recorded without output or credentials: ' . $case, $state);
        if ($case === 'hostile-result') {
            schedulerCheck(($state['counts']['moments_published'] ?? -1) === 0 && ($state['counts']['moments_drafts'] ?? -1) === 100000,
                'Unexpected result counters cannot create unbounded or negative status values');
        }
    }

    schedulerReset();
    $claim = Enhancement_BotMomentsSchedulerHelper::claim(schedulerQueuedToken());
    $forged = $claim;
    $forged['run_id'] = str_repeat('f', 32);
    $calls = 0;
    Enhancement_BotMomentsSchedulerHelper::run($forged, static function () use (&$calls): array { $calls++; return ['status' => 'bound']; });
    schedulerCheck($calls === 0 && schedulerState() === $claim, 'A forged or stale running claim cannot execute');
    $newer = null;
    Enhancement_BotMomentsSchedulerHelper::run($claim ?? [], static function () use (&$newer): array {
        $newer = schedulerState(['run_id' => bin2hex(random_bytes(16)), 'status' => 'running']);
        return ['status' => 'bound'];
    });
    schedulerCheck(schedulerState() === $newer, 'An old worker cannot overwrite a replacement running record');

    schedulerReset();
    $claim = Enhancement_BotMomentsSchedulerHelper::claim(schedulerQueuedToken());
    $lease = Enhancement_NotifyTransportHelper::acquireLease('weixin_sync_lock', 180);
    $networkCalls = 0;
    Enhancement_BotMomentsSchedulerHelper::run($claim ?? [], static function () use (&$networkCalls): array {
        return Enhancement_WeixinNotifyHelper::sync(static function () use (&$networkCalls): array {
            $networkCalls++;
            return ['status' => 200, 'data' => ['ret' => 0, 'msgs' => []]];
        });
    });
    schedulerCheck($networkCalls === 0 && (schedulerState()['status'] ?? '') === 'busy'
        && (Enhancement_NotifyTransportHelper::read('weixin_sync_lock')['token'] ?? '') === $lease,
        'Automatic receiving respects the existing receiver lock and preserves its current owner');
    Enhancement_NotifyTransportHelper::releaseLease('weixin_sync_lock', (string)$lease);
    schedulerState(['next_at' => time() - 1]);
    $claim = Enhancement_BotMomentsSchedulerHelper::claim(schedulerQueuedToken());
    Enhancement_BotMomentsSchedulerHelper::run($claim ?? [], static function () use (&$networkCalls): array {
        return Enhancement_WeixinNotifyHelper::sync(static function ($method, $url, $headers, $payload, $timeout) use (&$networkCalls): array {
            $networkCalls++;
            schedulerCheck($method === 'POST' && $url === 'https://ilinkai.weixin.qq.com/ilink/bot/getupdates' && $timeout === 12,
                'Scheduled receiving uses the existing bounded Weixin polling protocol');
            return ['status' => 200, 'data' => ['ret' => 0, 'get_updates_buf' => 'offline-next-cursor', 'msgs' => []]];
        });
    });
    schedulerCheck($networkCalls === 1 && (schedulerState()['status'] ?? '') === 'bound'
        && (Enhancement_NotifyTransportHelper::read('weixin_sync')['get_updates_buf'] ?? '') === 'offline-next-cursor'
        && Enhancement_NotifyTransportHelper::read('weixin_sync_lock') === [],
        'A due automatic task reuses the actual receiver, saves its cursor, and releases its synchronization lock');

    schedulerReset();
    $status = Enhancement_BotMomentsSchedulerHelper::status();
    schedulerCheck($status === ['enabled' => true, 'status' => 'idle', 'last_run' => 0, 'last_attempt' => 0, 'next_at' => 0],
        'A configured scheduler exposes a safe idle status before its first visitor');
    $token = schedulerQueuedToken();
    $beforeStatus = schedulerState();
    $status = Enhancement_BotMomentsSchedulerHelper::status();
    schedulerCheck(array_keys($status) === ['enabled', 'status', 'last_run', 'last_attempt', 'next_at']
        && $status['enabled'] === true && $status['status'] === 'queued' && schedulerState() === $beforeStatus
        && strpos(json_encode($status), $token) === false && strpos(json_encode($status), 'token_hash') === false
        && strpos(json_encode($status), 'binding') === false,
        'Reading scheduler status exposes no authentication data and cannot trigger a poll', $status);
    schedulerState(['status' => 'private-untrusted-status', 'last_run' => -1, 'last_attempt' => -100, 'next_at' => -1,
        'message' => 'private-error-message', 'token_hash' => 'private-hash', 'binding' => 'private-account-binding']);
    $status = Enhancement_BotMomentsSchedulerHelper::status();
    schedulerCheck($status === ['enabled' => true, 'status' => 'idle', 'last_run' => 0, 'last_attempt' => 0, 'next_at' => 0]
        && strpos(json_encode($status), 'private-') === false, 'Unexpected persisted status and private errors stay out of displayed status');
    schedulerSettings(['weixin_moments_auto_receive' => '0']);
    schedulerCheck(Enhancement_BotMomentsSchedulerHelper::status()['enabled'] === false, 'Displayed automatic receiving status follows its off switch');
    schedulerReset(['weixin_moments_enabled' => '0']);
    schedulerCheck(Enhancement_BotMomentsSchedulerHelper::status()['enabled'] === false, 'Displayed automatic receiving status follows the publishing off switch');

    schedulerReset();
    Enhancement_BotMomentsSchedulerHelper::registerTick();
    Enhancement_BotMomentsSchedulerHelper::registerTick();
    schedulerCheck(schedulerState() === [], 'CLI receiver startup never registers a visitor shutdown wakeup');
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo 'FAIL ' . $exception->getMessage() . PHP_EOL;
} finally {
    $statement = null;
    $pdo = null;
}

echo 'Bot scheduler checks passed: ' . $passed . '; failed: ' . count($failures) . PHP_EOL;
if (!$failures) {
    Typecho_Widget::destroy();
    Typecho_Db::set(new Typecho_Db('Pdo_SQLite', 'typecho_'));
    $fixtureDb = null;
    if (is_file($directory . '/fixture.sqlite')) { unlink($directory . '/fixture.sqlite'); }
    rmdir($directory);
} else {
    echo 'Fixtures retained: ' . $directory . PHP_EOL;
}
exit($failures ? 1 : 0);
