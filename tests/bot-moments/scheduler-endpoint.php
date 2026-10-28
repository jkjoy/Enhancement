<?php
/** Real Typecho HTTP task route with disposable SQLite and a network-free Weixin sync. */
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $directory = getenv('ENHANCEMENT_SCHEDULER_ENDPOINT_DIR');
    $root = getenv('ENHANCEMENT_SCHEDULER_ENDPOINT_ROOT');
    if (!$directory || !$root || !is_file($directory . '/fixture.sqlite')) { http_response_code(404); exit; }
    define('__TYPECHO_ROOT_DIR__', $root);
    define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
    define('__TYPECHO_THEME_DIR__', '/usr/themes');
    define('__TYPECHO_ADMIN_DIR__', '/admin/');
    define('__TYPECHO_DEBUG__', true);
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require_once $root . '/var/Typecho/Common.php';
    Typecho\Common::init();
    $db = new Typecho\Db('Pdo_SQLite', 'typecho_');
    $db->addServer(['file' => $directory . '/fixture.sqlite'], Typecho\Db::READ | Typecho\Db::WRITE);
    Typecho\Db::set($db);
    Typecho\Widget::widget('Widget_Init');
    require_once dirname(__DIR__, 2) . '/SettingsHelper.php';
    require_once dirname(__DIR__, 2) . '/NotifyTransportHelper.php';
    require_once dirname(__DIR__, 2) . '/BotMomentsHelper.php';
    class Enhancement_Plugin
    {
        public static function runtimeSettings() { return (object)Enhancement_SettingsHelper::readPluginSettingsFromDatabase(); }
    }
    class Enhancement_WeixinNotifyHelper
    {
        public static function sync(): array
        {
            $calls = Enhancement_NotifyTransportHelper::read('endpoint_calls');
            Enhancement_NotifyTransportHelper::write('endpoint_calls', ['count' => (int)($calls['count'] ?? 0) + 1]);
            $control = Enhancement_NotifyTransportHelper::read('endpoint_control');
            if (!empty($control['delay_ms'])) { usleep(min(2000, (int)$control['delay_ms']) * 1000); }
            echo 'private-scheduler-fixture-output';
            if (!empty($control['throw'])) {
                throw new RuntimeException('private-scheduler-fixture-exception');
            }
            return ['status' => 'wait', 'moments_published' => 2, 'moments_drafts' => 1, 'moments_failed' => 0];
        }
    }
    require_once dirname(__DIR__, 2) . '/BotMomentsSchedulerHelper.php';
    require_once dirname(__DIR__, 2) . '/Action.php';
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/fixture/barrier') {
        echo 'done';
        exit;
    }
    if ($path === '/fixture/visit') {
        Enhancement_BotMomentsSchedulerHelper::registerTick();
        Enhancement_BotMomentsSchedulerHelper::registerTick();
        echo 'visitor-content';
        exit;
    }
    if ($path === '/fixture/native') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $receiver = getenv('ENHANCEMENT_SCHEDULER_ENDPOINT_RECEIVER');
        if (!$receiver || !str_starts_with((string)($body['url'] ?? ''), $receiver . '/')) { http_response_code(400); exit; }
        $dispatch = new ReflectionMethod('Enhancement_BotMomentsSchedulerHelper', 'dispatch');
        $start = microtime(true);
        $sent = $dispatch->invoke(null, $body['url'], $body['token']);
        echo json_encode(['sent' => $sent, 'elapsed' => microtime(true) - $start]);
        exit;
    }
    if ($path === '/fixture/redirect') {
        Typecho_Response::getInstance()->setStatus(302)->setHeader('Location',
            getenv('ENHANCEMENT_SCHEDULER_ENDPOINT_RECEIVER') . '/fixture/trap');
        exit;
    }
    if ($path === '/fixture/trap') {
        Enhancement_NotifyTransportHelper::write('endpoint_trap', ['visited' => true]);
        exit;
    }
    if ($path === '/fixture/queue') {
        Enhancement_BotMomentsHelper::syncActivation(Enhancement_SettingsHelper::readPluginSettingsFromDatabase());
        Enhancement_NotifyTransportHelper::write('weixin_cron', []);
        $token = null;
        $sent = Enhancement_BotMomentsSchedulerHelper::dispatchIfDue(static function ($url, $value) use (&$token): bool {
            $token = $value;
            return true;
        });
        header('Content-Type: application/json');
        echo json_encode(['dispatched' => $sent, 'token' => $token]);
        exit;
    }
    file_put_contents($directory . '/endpoint-requests.jsonl', json_encode(['port' => (string)$_SERVER['SERVER_PORT'],
        'method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'],
        'token' => $_SERVER['HTTP_X_ENHANCEMENT_CRON_TOKEN'] ?? '', 'cookie' => $_SERVER['HTTP_COOKIE'] ?? '']) . "\n", FILE_APPEND | LOCK_EX);
    Typecho\Router::dispatch();
    exit;
}
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

function endpointOption(string $name, $value): void
{
    global $pdo;
    $encoded = $name === 'plugin:Enhancement' ? serialize($value) : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $statement = $pdo->prepare('INSERT OR REPLACE INTO typecho_options(name,user,value) VALUES(?,0,?)');
    $statement->execute([$name, $encoded]);
}
function endpointState(string $key): array
{
    global $pdo;
    $statement = $pdo->prepare('SELECT value FROM typecho_options WHERE name=? AND user=0');
    $statement->execute(['enh:notify:' . $key]);
    return json_decode((string)$statement->fetchColumn(), true) ?: [];
}
function endpointQueue(): string
{
    global $base;
    $response = fixtureRequest($base, '/fixture/queue');
    if (empty($response['json']['dispatched']) || !is_string($response['json']['token'] ?? null)) {
        throw new RuntimeException('Could not queue an isolated endpoint task.');
    }
    return $response['json']['token'];
}
function endpointCall(?string $token, string $method = 'POST', string $query = ''): array
{
    global $base;
    $response = fixtureRequest($base, '/action/enhancement-edit?do=bot-moments-cron' . $query, $method, '',
        $token === null ? [] : ['X-Enhancement-Cron-Token: ' . $token]);
    // A zero-length HTTP response may arrive before its independent task finishes.
    fixtureRequest($base, '/fixture/barrier');
    return $response;
}

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $settings = ['weixin_moments_enabled' => '1', 'weixin_moments_auto_receive' => '1'];
    endpointOption('plugin:Enhancement', $settings);
    endpointOption('plugins', ['activated' => ['Enhancement' => []], 'handles' => []]);
    endpointOption('actionTable', ['enhancement-edit' => 'Enhancement_Action']);
    endpointOption('routingTable', ['do' => ['url' => '/action/[action:alpha]', 'widget' => 'Widget_Do']]);
    $account = ['account_id' => 'fixture-bot', 'token' => 'private-scheduler-account-token',
        'owner_id' => 'fixture-owner', 'expired' => false];
    endpointOption('enh:notify:weixin_account', $account);
    endpointOption('enh:notify:weixin_recipient', ['user_id' => 'fixture-owner']);
    $env = getenv();
    $receiverAddress = qqAddress();
    $receiverBase = 'http://' . $receiverAddress;
    $env['ENHANCEMENT_SCHEDULER_ENDPOINT_DIR'] = $directory;
    $env['ENHANCEMENT_SCHEDULER_ENDPOINT_ROOT'] = $root;
    $env['ENHANCEMENT_SCHEDULER_ENDPOINT_RECEIVER'] = $receiverBase;
    qqStartServer($address, __FILE__, $env, $directory . '/endpoint.log');
    qqStartServer($receiverAddress, __FILE__, $env, $directory . '/receiver.log');

    $token = endpointQueue();
    $queued = endpointState('weixin_cron');
    foreach ([['GET', $token, ''], ['POST', null, '&token=' . $token], ['POST', 'invalid', ''],
        ['POST', str_repeat('b', 64), '']] as [$method, $credential, $query]) {
        $response = endpointCall($credential, $method, $query);
        qqCheck($response['status'] === 403 && $response['body'] === '' && !isset($response['headers']['location']),
            'Unauthenticated task request is rejected with an empty 403: ' . $method, $response);
        qqCheck(endpointState('weixin_cron') === $queued && endpointState('endpoint_calls') === [],
            'Rejected task cannot consume the queued token or execute Weixin sync');
    }
    $response = endpointCall($token);
    $state = endpointState('weixin_cron');
    qqCheck($response['status'] === 204 && $response['body'] === ''
        && ($response['headers']['cache-control'] ?? '') === 'no-store'
        && ($response['headers']['content-length'] ?? '') === '0'
        && !isset($response['headers']['location']) && !isset($response['headers']['set-cookie']),
        'The real Typecho task route accepts its own token without login, redirect, cookies or body', $response);
    qqCheck((endpointState('endpoint_calls')['count'] ?? 0) === 1 && ($state['status'] ?? '') === 'wait'
        && !isset($state['token_hash']) && ($state['counts']['moments_published'] ?? 0) === 2,
        'Accepted route consumes the one-time token and records exactly one isolated sync', $state);
    $response = endpointCall($token);
    qqCheck($response['status'] === 403 && $response['body'] === ''
        && (endpointState('endpoint_calls')['count'] ?? 0) === 1, 'Replayed task credential cannot execute the HTTP route again', $response);

    $token = endpointQueue();
    endpointOption('plugin:Enhancement', array_replace($settings, ['weixin_moments_auto_receive' => '0']));
    $response = endpointCall($token);
    qqCheck($response['status'] === 403 && (endpointState('endpoint_calls')['count'] ?? 0) === 1,
        'Disabling automatic receiving revokes a queued HTTP task', $response);
    endpointOption('plugin:Enhancement', $settings);

    $token = endpointQueue();
    endpointOption('enh:notify:weixin_recipient', ['user_id' => 'replacement-owner']);
    $response = endpointCall($token);
    qqCheck($response['status'] === 403 && (endpointState('endpoint_calls')['count'] ?? 0) === 1,
        'Changing the bound Weixin recipient revokes a queued HTTP task', $response);
    endpointOption('enh:notify:weixin_recipient', ['user_id' => 'fixture-owner']);

    $token = endpointQueue();
    endpointOption('plugins', ['activated' => [], 'handles' => []]);
    $response = endpointCall($token);
    qqCheck($response['status'] === 403 && (endpointState('endpoint_calls')['count'] ?? 0) === 1,
        'Deactivating the plugin revokes a queued HTTP task', $response);
    endpointOption('plugins', ['activated' => ['Enhancement' => []], 'handles' => []]);

    $token = endpointQueue();
    endpointOption('enh:notify:weixin_cron', array_replace(endpointState('weixin_cron'), ['expires_at' => time() - 1]));
    $response = endpointCall($token);
    qqCheck($response['status'] === 403 && (endpointState('endpoint_calls')['count'] ?? 0) === 1,
        'Expired task credential cannot execute the HTTP route', $response);

    $token = endpointQueue();
    endpointOption('enh:notify:endpoint_control', ['throw' => true]);
    $response = endpointCall($token);
    qqCheck($response['status'] === 204 && $response['body'] === ''
        && (endpointState('endpoint_calls')['count'] ?? 0) === 2 && (endpointState('weixin_cron')['status'] ?? '') === 'error',
        'A sync exception records failure while keeping the accepted HTTP task response empty', $response);

    $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=? AND user=0')->execute([$receiverBase . '/', 'siteUrl']);
    endpointOption('enh:notify:endpoint_control', []);
    $token = endpointQueue();
    $url = $receiverBase . '/action/enhancement-edit?do=bot-moments-cron';
    $response = fixtureRequest($base, '/fixture/native', 'POST', ['url' => $url, 'token' => $token]);
    fixtureRequest($receiverBase, '/fixture/barrier');
    qqCheck(($response['json']['sent'] ?? false) === true && (endpointState('endpoint_calls')['count'] ?? 0) === 3
        && (endpointState('weixin_cron')['status'] ?? '') === 'wait',
        'Native cURL dispatcher completes a real authenticated 204 round trip to an independent PHP server', $response);
    $requests = array_map(static fn($line) => json_decode($line, true), file($directory . '/endpoint-requests.jsonl', FILE_IGNORE_NEW_LINES));
    $received = array_values(array_filter($requests, static fn($request) => $request['port'] === (string)parse_url($receiverBase, PHP_URL_PORT)));
    qqCheck(count($received) === 1 && $received[0]['method'] === 'POST' && $received[0]['token'] === $token
        && $received[0]['uri'] === '/action/enhancement-edit?do=bot-moments-cron' && $received[0]['cookie'] === '',
        'Native dispatch sends its independent credential only in the POST header without cookies or a token query', $received);

    $response = fixtureRequest($base, '/fixture/native', 'POST', ['url' => $receiverBase . '/fixture/redirect', 'token' => $token]);
    fixtureRequest($receiverBase, '/fixture/barrier');
    qqCheck(($response['json']['sent'] ?? true) === false && endpointState('endpoint_trap') === [],
        'Native dispatcher rejects a redirect without forwarding the task credential to its destination', $response);

    endpointOption('enh:notify:endpoint_control', ['delay_ms' => 1200]);
    $token = endpointQueue();
    $response = fixtureRequest($base, '/fixture/native', 'POST', ['url' => $url, 'token' => $token]);
    qqCheck(($response['json']['sent'] ?? false) === true && ($response['json']['elapsed'] ?? 10) < 1,
        'Native dispatch releases the visitor in under one second while the receiver is still processing', $response);
    fixtureRequest($receiverBase, '/fixture/barrier');
    qqCheck((endpointState('endpoint_calls')['count'] ?? 0) === 4 && (endpointState('weixin_cron')['status'] ?? '') === 'wait',
        'An independent receiver finishes its delayed sync after the caller timeout without losing its result');

    endpointOption('enh:notify:endpoint_control', []);
    endpointOption('enh:notify:weixin_cron', []);
    $visit = fixtureRequest($base, '/fixture/visit');
    fixtureRequest($receiverBase, '/fixture/barrier');
    $state = endpointState('weixin_cron');
    qqCheck($visit['status'] === 200 && $visit['body'] === 'visitor-content'
        && (endpointState('endpoint_calls')['count'] ?? 0) === 5 && ($state['status'] ?? '') === 'wait',
        'A real HTTP visit preserves its page body and its shutdown dispatch executes exactly one independent sync', $visit);
    $visit = fixtureRequest($base, '/fixture/visit');
    fixtureRequest($receiverBase, '/fixture/barrier');
    qqCheck($visit['status'] === 200 && $visit['body'] === 'visitor-content'
        && (endpointState('endpoint_calls')['count'] ?? 0) === 5 && endpointState('weixin_cron') === $state,
        'Another HTTP visit inside the sixty-second interval preserves its page without dispatching another task', $visit);

    $log = file_get_contents($directory . '/endpoint.log') . file_get_contents($directory . '/receiver.log');
    qqCheck(preg_match('/PHP (?:Warning|Fatal error|Parse error|Deprecated|Notice)/i', $log) !== 1
        && strpos($log, 'private-scheduler-') === false, 'Task route emits no PHP errors or private sync output to its HTTP server log', $log);
} catch (Throwable $exception) {
    qqCheck(false, 'Scheduler endpoint runner exception', $exception->getMessage());
} finally {
    foreach ($processes as $process) {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
    $pdo = null;
    if (!$failures) {
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
}
echo $passed . ' scheduler endpoint assertions passed; ' . count($failures) . " failed.\n";
if ($failures) { echo 'Fixture logs retained: ' . $directory . "\n"; }
exit($failures ? 1 : 0);
