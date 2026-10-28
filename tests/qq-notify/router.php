<?php
/** Disposable Typecho router. Never loads the site's config.inc.php. */
declare(strict_types=1);
$directory = getenv('ENHANCEMENT_QQ_TEST_DIR');
$root = getenv('ENHANCEMENT_QQ_TEST_ROOT');
if (PHP_SAPI !== 'cli-server' || !$directory || !$root || !is_file($directory . '/fixture.sqlite')) {
    http_response_code(404);
    exit;
}
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
Typecho\Plugin::init(['activated' => [], 'handles' => []]);
if (getenv('ENHANCEMENT_QQ_COMMAND_HOOKS') === '1') {
    Typecho_Plugin::factory('Widget_Comments_Edit')->mark = static function ($comment, $editor, $status) use ($directory) {
        file_put_contents($directory . '/hooks.jsonl', json_encode(['hook' => 'mark', 'id' => $comment['coid'], 'status' => $status]) . "\n", FILE_APPEND);
    };
    Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = static function ($comment) use ($directory) {
        file_put_contents($directory . '/hooks.jsonl', json_encode(['hook' => 'finish', 'id' => $comment->coid]) . "\n", FILE_APPEND);
    };
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
try {
    header('Content-Type: application/json');
    switch ($path) {
        case '/action/enhancement-edit':
            require_once dirname(__DIR__, 2) . '/Action.php';
            Typecho_Widget::widget('Enhancement_Action')->action();
            break;
        case '/config':
            Enhancement_ConfigStorageHelper::configHandle($body, false);
            echo '{"ok":true}';
            break;
        case '/validate':
            Enhancement_QqNotifyHelper::validateSettings($body);
            echo '{"ok":true}';
            break;
        case '/bot-moment':
            echo json_encode(Enhancement_BotMomentsHelper::handle((string)($body['channel'] ?? ''), $body['event'] ?? []));
            break;
        case '/bot-moment-media':
            $receive = static function (string $channel, array $attachments) use ($directory, $body): array {
                file_put_contents($directory . '/media-receive.jsonl', json_encode(['channel' => $channel, 'attachments' => $attachments]) . "\n", FILE_APPEND | LOCK_EX);
                if (!empty($body['receive_failure'])) { throw new RuntimeException('Fixture media download failed.'); }
                if (!empty($body['cancel_during_receive'])) {
                    $cancel = $body['event'];
                    $cancel['id'] = 'cancel-during-receive-' . bin2hex(random_bytes(8));
                    $cancel['text'] = '取消瞬间';
                    $cancel['attachments'] = [];
                    $cancel['timestamp'] = time();
                    $result = Enhancement_BotMomentsHelper::handle($channel, $cancel);
                    file_put_contents($directory . '/media-cancel.jsonl', json_encode($result) . "\n", FILE_APPEND | LOCK_EX);
                }
                if (!empty($body['restart_during_receive'])) {
                    $result = Enhancement_BotMomentsHelper::handle($channel, $body['restart_during_receive']);
                    file_put_contents($directory . '/media-restart.jsonl', json_encode($result) . "\n", FILE_APPEND | LOCK_EX);
                }
                if (!empty($body['settings_during_receive'])) {
                    Enhancement_ConfigStorageHelper::configHandle($body['settings_during_receive'], false);
                }
                if (!empty($body['reactivate_during_receive'])) {
                    $before = Enhancement_NotifyTransportHelper::read('bot_moments_gate');
                    Enhancement_ConfigStorageHelper::configHandle([$channel . '_moments_enabled' => '0'], false);
                    $disabled = Enhancement_NotifyTransportHelper::read('bot_moments_gate');
                    Enhancement_ConfigStorageHelper::configHandle([$channel . '_moments_enabled' => '1'], false);
                    $after = Enhancement_NotifyTransportHelper::read('bot_moments_gate');
                    $after[$channel]['enabled_at'] = $before[$channel]['enabled_at'];
                    Enhancement_NotifyTransportHelper::write('bot_moments_gate', $after);
                    file_put_contents($directory . '/media-reactivate.jsonl', json_encode([
                        'before' => $before[$channel], 'disabled' => $disabled[$channel] ?? [], 'after' => $after[$channel]
                    ]) . "\n", FILE_APPEND | LOCK_EX);
                }
                foreach ($body['state_during_receive'] ?? [] as $key => $record) {
                    Enhancement_NotifyTransportHelper::write($key, $record);
                }
                $media = [];
                foreach ($attachments as $index => $attachment) {
                    $media[] = ['type' => 'PHOTO', 'url' => 'https://fixture.invalid/photo-' . ($index + 1) . '.png'];
                }
                return $media;
            };
            echo json_encode(Enhancement_BotMomentsHelper::handle((string)($body['channel'] ?? ''), $body['event'] ?? [], $receive));
            break;
        case '/api/v1/memos':
            require_once dirname(__DIR__, 2) . '/Memos/Action.php';
            Typecho_Widget::widget('Enhancement_Memos_Action')->action();
            break;
        case '/notify-format':
            echo json_encode(['text' => Enhancement_NotifyTransportHelper::text((string)($body['message'] ?? ''), !empty($body['include_links']))]);
            break;
        case '/state':
            $key = (string)($body['key'] ?? 'fixture_state');
            $operation = (string)($body['operation'] ?? 'read');
            if ($operation === 'write') {
                Enhancement_NotifyTransportHelper::write($key, $body['record'] ?? []);
            }
            $token = $operation === 'acquire' ? Enhancement_NotifyTransportHelper::acquireLease($key, (int)($body['seconds'] ?? 60)) : null;
            if ($operation === 'release') {
                Enhancement_NotifyTransportHelper::releaseLease($key, (string)($body['token'] ?? ''));
            }
            $changed = $operation === 'cas'
                ? Enhancement_NotifyTransportHelper::compareAndSwap($key, $body['expected'] ?? [], $body['record'] ?? []) : null;
            echo json_encode(['record' => Enhancement_NotifyTransportHelper::read($key), 'changed' => $changed, 'token' => $token]);
            break;
        case '/session':
            $uid = (int)($body['uid'] ?? 0);
            if (!in_array($uid, [1, 2], true) || !Typecho_Widget::widget('Widget_User')->simpleLogin($uid, false)) {
                throw new RuntimeException('Invalid fixture user.', 400);
            }
            $actions = [];
            foreach (['qq-binding-start', 'qq-binding-cancel', 'qq-binding-status', 'weixin-login-start', 'weixin-login-poll',
                'weixin-sync', 'weixin-bind', 'weixin-disconnect', 'weixin-status', 'weixin-test-notify'] as $action) {
                $actions[$action] = Helper::security()->getIndex('/action/enhancement-edit?do=' . $action);
            }
            echo json_encode(['actions' => $actions, 'referer' => Typecho_Request::getInstance()->getRequestUrl()]);
            break;
        case '/restore':
        case '/retry':
            require_once dirname(__DIR__, 2) . '/Action.php';
            $action = Typecho_Widget::widget('Enhancement_Action');
            foreach (['db' => $db, 'prefix' => $db->getPrefix()] as $name => $value) {
                $property = new ReflectionProperty('Enhancement_Action', $name);
                $property->setAccessible(true);
                $property->setValue($action, $value);
            }
            if ($path === '/retry') {
                $action->retryQqNotifyQueue();
            } else {
                $method = new ReflectionMethod('Enhancement_Action', 'savePluginSettings');
                $method->setAccessible(true);
                $method->invoke($action, $body);
                echo '{"ok":true}';
            }
            break;
        case '/test':
            echo json_encode(Enhancement_QqNotifyHelper::sendTestMessage((string)($body['message'] ?? '测试通知')));
            break;
        case '/dispatch':
            $method = new ReflectionMethod('Enhancement_QqNotifyHelper', 'dispatchMessage');
            $method->setAccessible(true);
            $method->invoke(null, (string)($body['message'] ?? '业务通知'));
            echo '{"ok":true}';
            break;
        case '/process':
            Enhancement_QqNotifyHelper::processQueue();
            echo '{"ok":true}';
            break;
        case '/comment':
            Enhancement_QqNotifyHelper::comment((object)[
                'status' => 'approved', 'authorId' => 0, 'ownerId' => 1,
                'author' => 'Fixture reader', 'title' => 'Fixture post',
                'text' => '评论业务通知', 'permalink' => 'https://fixture.example.test/post',
            ]);
            echo '{"ok":true}';
            break;
        case '/form':
            ob_start();
            // Exercise the real admin config/binding method, using only fixture state.
            Typecho_Plugin::init(['activated' => ['Enhancement' => []], 'handles' => []]);
            $config = new Widget\Plugins\Config(
                new Typecho\Widget\Request(Typecho\Request::getInstance(), new Typecho\Config(['config' => 'Enhancement'])),
                new Typecho\Widget\Response(Typecho\Request::getInstance(), Typecho\Response::getInstance())
            );
            $pluginFile = dirname(__DIR__, 2) . '/Plugin.php';
            foreach (['pluginFileName' => $pluginFile, 'className' => 'Enhancement_Plugin'] as $name => $value) {
                $property = new ReflectionProperty($config, $name);
                $property->setAccessible(true);
                $property->setValue($config, $value);
            }
            $config->info = Typecho_Plugin::parseInfo($pluginFile);
            $form = $config->config();
            $form->render();
            $html = ob_get_clean();
            header('Content-Type: text/html; charset=UTF-8');
            echo $html;
            break;
        default:
            Typecho\Response::getInstance()->setStatus(404);
            echo '{}';
    }
} catch (Throwable $exception) {
    Typecho\Response::getInstance()->setStatus(in_array((int)$exception->getCode(), [400, 403], true) ? (int)$exception->getCode() : 500);
    echo json_encode(['error' => $exception->getMessage()]);
}
