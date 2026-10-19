<?php
/** Private HTTP fixture: real Typecho/action/helpers, with an entirely offline feed transport. */
declare(strict_types=1);

$directory = getenv('ENHANCEMENT_FRIEND_HTTP_DIR');
$root = getenv('ENHANCEMENT_FRIEND_HTTP_ROOT');
$transportFunctions = ['dns_get_record', 'curl_init', 'curl_setopt_array', 'curl_exec', 'curl_getinfo', 'curl_error', 'curl_close'];
if (PHP_SAPI !== 'cli-server' || !$directory || !$root || !is_file($directory . '/fixture.sqlite')
    || array_diff($transportFunctions, array_map('trim', explode(',', ini_get('disable_functions'))))) {
    http_response_code(404);
    exit;
}

// Disabled internal functions can be replaced in PHP 8+. These functions never
// open a socket; any unexpected feed host fails before it could reach a network.
if (!function_exists('dns_get_record') && !function_exists('curl_init')) {
function dns_get_record($hostname, $type = DNS_ANY, &$authoritative = null, &$additional = null, $raw = false)
{
    if (!in_array($hostname, ['friend.example.test', 'pending.example.test', 'other.example.test'], true)) {
        throw new RuntimeException('Unexpected fixture hostname: ' . $hostname);
    }
    return [['host' => $hostname, 'type' => 'A', 'ip' => '93.184.216.34']];
}
function curl_init($url = null) { return (object)['url' => $url, 'options' => [], 'failed' => false]; }
function curl_setopt_array($handle, $options) { $handle->options = $options; return true; }
function curl_exec($handle)
{
    $directory = getenv('ENHANCEMENT_FRIEND_HTTP_DIR');
    file_put_contents($directory . '/transport.jsonl', json_encode(['url' => $handle->url]) . "\n", FILE_APPEND | LOCK_EX);
    if (is_file($directory . '/transport-fail')) {
        $handle->failed = true;
        return false;
    }
    $header = $handle->options[CURLOPT_HEADERFUNCTION];
    $write = $handle->options[CURLOPT_WRITEFUNCTION];
    $header($handle, "HTTP/1.1 200 OK\r\n");
    $body = '<?xml version="1.0"?><rss version="2.0"><channel><title>Offline friend</title><item>'
        . '<title>Offline fixture article</title><link>https://friend.example.test/article</link>'
        . '<pubDate>Mon, 19 Oct 2020 00:00:00 GMT</pubDate><description>Fixture summary</description>'
        . '</item></channel></rss>';
    return $write($handle, $body) === strlen($body);
}
function curl_getinfo($handle, $option = null) { return $handle->failed ? 0 : 200; }
function curl_error($handle) { return $handle->failed ? 'Offline fixture transport failed' : ''; }
function curl_close($handle) {}
}

define('__TYPECHO_ROOT_DIR__', $root);
define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
define('__TYPECHO_THEME_DIR__', '/usr/themes');
define('__TYPECHO_ADMIN_DIR__', '/admin/');
define('__TYPECHO_DEBUG__', true);
$_SERVER['SCRIPT_NAME'] = '/fixture.php';
require_once $root . '/var/Typecho/Common.php';
Typecho\Common::init();
$database = new Typecho\Db('Pdo_SQLite', 'typecho_');
$database->addServer(['file' => $directory . '/fixture.sqlite'], Typecho\Db::READ | Typecho\Db::WRITE);
Typecho\Db::set($database);
Typecho\Widget::widget('Widget_Init');
require_once $root . '/usr/plugins/Enhancement/Plugin.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$referer = Typecho\Widget::widget('Widget_Options')->siteUrl . 'admin/extending.php?panel=Enhancement%2Fmanage-friend-posts.php';
try {
    if ($path === '/__fixture/session') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $loggedIn = false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($body['uid'] ?? null, [1, 2], true)) {
            $uid = $body['uid'];
            $database->query($database->update('table.users')->rows(['password' => Typecho\Common::hash('fixture-password')])->where('uid = ?', $uid));
            $loggedIn = Typecho\Widget::widget('Widget_User')->login('fixture' . $uid, 'fixture-password');
        }
        Typecho\Response::getInstance()->setContentType('application/json')->addResponder(static function () use ($loggedIn, $referer) {
            echo json_encode(['logged_in' => $loggedIn, 'token' => Typecho\Widget::widget('Widget_Security')->getToken($referer), 'referer' => $referer]);
        })->respond();
    }
    if ($path === '/__fixture/config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        Enhancement_ConfigStorageHelper::configHandle(json_decode(file_get_contents('php://input'), true), false);
        Typecho\Response::getInstance()->setContentType('application/json')->addResponder(static function () { echo '{"ok":true}'; })->respond();
    }
    // The actual core action route invokes Enhancement_Action::action(), so no
    // reflection or fixture shortcut can bypass CSRF, permissions or POST checks.
    Typecho\Router::dispatch();
} catch (Throwable $exception) {
    $status = in_array((int)$exception->getCode(), [400, 401, 403, 404, 405, 429, 503], true) ? (int)$exception->getCode() : 500;
    Typecho\Response::getInstance()->setStatus($status)->setContentType('application/json')->addResponder(static function () use ($exception) {
        echo json_encode(['fixture_exception' => get_class($exception), 'message' => $exception->getMessage()]);
    })->respond();
}
