<?php
/** Private router for run.php's disposable local PHP server. */
declare(strict_types=1);
$testDirectory = getenv('ENHANCEMENT_REST_API_TEST_DIR');
$testRoot = getenv('ENHANCEMENT_REST_API_TEST_ROOT');
if (PHP_SAPI !== 'cli-server' || !$testDirectory || !$testRoot || !is_file($testDirectory . '/fixture.sqlite')) { http_response_code(404); exit; }
define('__TYPECHO_ROOT_DIR__', $testRoot);
define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
define('__TYPECHO_THEME_DIR__', '/usr/themes');
define('__TYPECHO_ADMIN_DIR__', '/admin/');
define('__TYPECHO_DEBUG__', true);
$_SERVER['SCRIPT_NAME'] = '/fixture.php';
require_once $testRoot . '/var/Typecho/Common.php';
Typecho\Common::init();
class EnhancementFixtureDatabase extends Typecho\Db
{
    public $queries = [];
    public function query($query, int $op = self::READ, string $action = self::SELECT)
    {
        if (getenv('ENHANCEMENT_SHORTCODE_TESTS') === '1') {
            $this->queries[] = (string)$query;
        }
        return parent::query($query, $op, $action);
    }
}
$database = new EnhancementFixtureDatabase('Pdo_SQLite', 'typecho_');
$database->addServer(['file' => $testDirectory . '/fixture.sqlite'], Typecho\Db::READ | Typecho\Db::WRITE);
Typecho\Db::set($database);
Typecho\Widget::widget('Widget_Init');
require_once dirname(__DIR__, 2) . '/Plugin.php';
require_once dirname(__DIR__, 2) . '/RestApi/Action.php';

function fixtureCommentFilter($comment, $content)
{
    $comment['text'] .= ' [fixture-filter]';
    return $comment;
}
function fixtureCommentFinished($comment): void
{
    Enhancement_ReplyAccessHelper::rememberComment($comment);
    file_put_contents(getenv('ENHANCEMENT_REST_API_TEST_DIR') . '/comment-hooks.json', json_encode(['id' => (int)$comment->coid, 'status' => $comment->status, 'cid' => (int)$comment->cid]));
}
function fixtureExcerptPassthrough($text, $widget = null, $lastResult = null) { return $text; }
// Typecho's route-save helper persists Plugin::export(). Rebuild disposable
// callbacks once per request so config saves cannot multiply filter execution.
Typecho\Plugin::init(['activated' => [], 'handles' => []]);
Typecho\Plugin::factory('Widget_Feedback')->comment = 'fixtureCommentFilter';
Typecho\Plugin::factory('Widget_Feedback')->comment_1 = ['Enhancement_Plugin', 'turnstileFilterComment'];
Typecho\Plugin::factory('Widget_Feedback')->finishComment = 'fixtureCommentFinished';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__fixture/shortcodes' && getenv('ENHANCEMENT_SHORTCODE_TESTS') === '1') {
    Typecho\Plugin::factory('Widget_Abstract_Contents')->contentEx = Enhancement_Plugin::callback('parse');
    if (!empty($_GET['legacy'])) {
        Typecho\Plugin::factory('Widget_Abstract_Contents')->excerptEx = Enhancement_Plugin::callback('parse');
        Typecho\Plugin::factory('Widget_Abstract_Contents')->excerptEx = 'fixtureExcerptPassthrough';
    }
    Enhancement_LifecycleHelper::registerReplyPrivacyHooks();
    Enhancement_LifecycleHelper::registerReplyPrivacyHooks();
    $cid = max(1, (int)($_GET['cid'] ?? 1));
    $parameters = 'type=post&checkPermalink=0' . (!empty($_GET['feed']) ? '&isFeed=1' : '');
    $widget = Typecho\Widget::widget('Widget_Archive', $parameters, ['cid' => $cid]);
    $text = (string)($_GET['text'] ?? $widget->text);
    $before = count($database->queries);
    $rendered = Enhancement_ShortcodeHelper::parseContent($text, $widget);
    $queries = array_slice($database->queries, $before);
    header('Content-Type: application/json');
    Typecho\Response::getInstance()->sendHeaders();
    echo json_encode(['rendered' => $rendered, 'cid' => (int)$widget->cid,
        'title' => $widget->title, 'content' => $widget->content, 'excerpt' => $widget->excerpt,
        'extra_queries' => $queries,
        'excerpt_hooks' => array_values(Typecho\Plugin::export()['handles']['Widget_Abstract_Contents:excerptEx'] ?? [])]);
    exit;
}
if ($path === '/__fixture/guard' && getenv('ENHANCEMENT_COMMENT_GUARD_TESTS') === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'store_path' => Enhancement_CommentGuardRateHelper::storePath(),
        'token' => Typecho\Widget::widget('Widget_Security')->getToken(Typecho\Widget::widget('Widget_Options')->siteUrl . 'archives/1/'),
    ]);
    exit;
}
if ($path === '/__fixture/config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = json_decode(file_get_contents('php://input'), true);
    Enhancement_ConfigStorageHelper::configHandle($settings, false);
    $routes = Typecho\Widget::widget('Widget_Options')->routingTable;
    header('Content-Type: application/json');
    echo json_encode(['rest_routes' => count(array_filter(array_keys($routes), static fn($name) => is_string($name) && str_starts_with($name, 'enhancement_restapi_'))), 'preserved' => isset($routes['fixture_preserved'])]);
    exit;
}
if ($path === '/__fixture/options' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (json_decode(file_get_contents('php://input'), true) as $name => $value) { Helper::setOption($name, $value); }
    header('Content-Type: application/json');
    echo '{}';
    exit;
}
if ($path === '/__fixture/auth' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $database->query($database->update('table.users')->rows(['password' => Typecho\Common::hash('fixture-password')])->where('uid = ?', 1));
    $loggedIn = Typecho\Widget::widget('Widget_User')->login('fixture1', 'fixture-password');
    header('Content-Type: application/json');
    echo json_encode(['logged_in' => $loggedIn, 'token' => Typecho\Widget::widget('Widget_Security')->getToken(null)]);
    exit;
}
try {
    Typecho\Router::dispatch();
} catch (Throwable $exception) {
    Typecho\Response::getInstance()->setStatus(in_array((int)$exception->getCode(), [400, 401, 403, 404, 429, 503], true) ? (int)$exception->getCode() : 500);
    Typecho\Response::getInstance()->setContentType('application/json');
    echo json_encode(['fixture_exception' => get_class($exception), 'message' => $exception->getMessage()]);
}
