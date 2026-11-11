<?php
/** Private fixture router; only reads the temporary site's configuration. */
declare(strict_types=1);
$root = getenv('ENHANCEMENT_MAIL_TEST_ROOT');
if (PHP_SAPI !== 'cli-server' || !$root || !is_file($root . '/config.inc.php')) { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$_SERVER['SCRIPT_NAME'] = '/fixture.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/fixture.php';
$_SERVER['PHP_SELF'] = '/fixture.php';
// Any accidental SMTP attempt is recorded and aborted before opening a socket.
if (array_diff(array('stream_socket_client', 'fsockopen'), array_map('trim', explode(',', ini_get('disable_functions'))))) { http_response_code(503); exit; }
if (!function_exists('stream_socket_client')) {
    function stream_socket_client(...$arguments) { file_put_contents(dirname(getenv('ENHANCEMENT_MAIL_TEST_ROOT')) . '/smtp-attempts.log', "socket\n", FILE_APPEND); throw new RuntimeException('SMTP disabled in fixture'); }
    function fsockopen(...$arguments) { file_put_contents(dirname(getenv('ENHANCEMENT_MAIL_TEST_ROOT')) . '/smtp-attempts.log', "socket\n", FILE_APPEND); throw new RuntimeException('SMTP disabled in fixture'); }
}
try {
    require $root . '/config.inc.php';
    if ($path === '/admin/extending.php') {
        // Let the real admin bootstrap set __TYPECHO_ADMIN__ before Widget_Init.
        $_SERVER['SCRIPT_NAME'] = '/admin/extending.php';
        $_SERVER['SCRIPT_FILENAME'] = $root . '/admin/extending.php';
        $_SERVER['PHP_SELF'] = '/admin/extending.php';
        chdir($root . '/admin');
        set_include_path($root . '/admin' . PATH_SEPARATOR . get_include_path());
        require $root . '/admin/extending.php';
        return;
    }
    Typecho_Widget::widget('Widget_Init');
    require $root . '/usr/plugins/Enhancement/Plugin.php';
    if ($path === '/__fixture/auth') {
        $db = Typecho_Db::get();
        $db->query($db->update('table.users')->rows(array('password' => Typecho_Common::hash('fixture-password')))->where('uid = ?', 1));
        $loggedIn = Typecho_Widget::widget('Widget_User')->login('fixture1', 'fixture-password');
        $suffix = Typecho_Widget::widget('Widget_Options')->siteUrl . 'admin/extending.php?panel=Enhancement%2FCommentNotifier%2Fconsole.php&act=theme&theme=default&file=owner.html';
        Typecho_Response::getInstance()->setContentType('application/json')->addResponder(static function () use ($loggedIn, $suffix) {
            echo json_encode(array('logged_in' => $loggedIn, 'token' => Typecho_Widget::widget('Widget_Security')->getToken($suffix)));
        })->respond();
    }
    if ($path === '/usr/plugins/Enhancement/CommentNotifier/template/preview.php') {
        require $root . '/usr/plugins/Enhancement/CommentNotifier/template/preview.php';
        return;
    }
    Typecho_Router::dispatch();
} catch (Throwable $error) {
    $code = (int)$error->getCode();
    Typecho_Response::getInstance()->setStatus(in_array($code, array(400, 401, 403, 404, 405), true) ? $code : 500)->setContentType('application/json');
    echo json_encode(array('fixture_error' => get_class($error), 'message' => $error->getMessage()));
}
