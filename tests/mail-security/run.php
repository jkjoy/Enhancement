<?php
/** Mail rendering, template traversal, preview authentication and editor CSRF regressions. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ENHANCEMENT_REST_API_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/rest-api/run.php';
$source = realpath($argv[1] ?? dirname(__DIR__, 4) . '/docker/typechotest/data');
if (!$source || !is_file($source . '/var/Typecho/Common.php')) { fwrite(STDERR, "Supply a Typecho source directory.\n"); exit(2); }
$directory = sys_get_temp_dir() . '/enhancement-mail-tests-' . bin2hex(random_bytes(6));
$root = $directory . '/site';
$plugin = $root . '/usr/plugins/Enhancement';
mkdir($plugin, 0700, true);
foreach (array('var', 'admin', 'install') as $folder) symlink($source . '/' . $folder, $root . '/' . $folder);
$repository = dirname(__DIR__, 2);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repository, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = substr($file->getPathname(), strlen($repository) + 1);
    if (!$file->isFile() || $file->isLink() || preg_match('~^(?:\.git|tests|runtime|assets)/~', $relative)
        || !in_array(strtolower($file->getExtension()), array('php', 'html'), true)) continue;
    $destination = $plugin . '/' . $relative;
    if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
    copy($file->getPathname(), $destination);
}
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($socket, false); fclose($socket);
$base = 'http://' . $address;
$pdo = fixtureDatabase($root, $directory, $base);
$pdo->exec('UPDATE typecho_users SET activated=created, logged=created');
$routes = json_decode($pdo->query("SELECT value FROM typecho_options WHERE name='routingTable' AND user=0")->fetchColumn(), true);
$routes['do'] = array('url' => '/action/[action:alpha]', 'widget' => 'Widget_Do', 'action' => 'action');
$panel = 'Enhancement/CommentNotifier/console.php';
$panelTable = array('file' => array(urlencode($panel)), 'child' => array(1 => array(array('Mail', 'Mail templates', 'extending.php?panel=' . urlencode($panel), 'administrator'))));
$statement = $pdo->prepare('UPDATE typecho_options SET value=? WHERE name=?');
$statement->execute(array(json_encode($routes), 'routingTable'));
$statement->execute(array(json_encode(array('comment-notifier' => 'Enhancement_CommentNotifier_Action')), 'actionTable'));
$statement->execute(array(json_encode($panelTable), 'panelTable'));
$statement->execute(array(serialize(array('template' => 'default')), 'plugin:Enhancement'));
fixtureInsert($pdo, 'options', array('name' => 'version', 'user' => 0, 'value' => '1.3.0'));
$bootstrap = '<?php define("__TYPECHO_ROOT_DIR__", __DIR__); define("__TYPECHO_PLUGIN_DIR__", "/usr/plugins"); define("__TYPECHO_THEME_DIR__", "/usr/themes"); define("__TYPECHO_ADMIN_DIR__", "/admin/"); define("__TYPECHO_URL_PREFIX__", ' . var_export($base, true) . '); define("__TYPECHO_DEBUG__", true); require __DIR__ . "/var/Typecho/Common.php"; Typecho_Common::init(); $db = new Typecho_Db("Pdo_SQLite", "typecho_"); $db->addServer(array("file" => ' . var_export($directory . '/fixture.sqlite', true) . '), Typecho_Db::READ | Typecho_Db::WRITE); Typecho_Db::set($db);';
file_put_contents($root . '/config.inc.php', $bootstrap);
$sentinel = $plugin . '/audit-secret.php';
file_put_contents($sentinel, '<?php /* MAIL_SECURITY_SENTINEL */');
symlink($sentinel, $plugin . '/CommentNotifier/template/default/escape.html');
$env = getenv(); $env['ENHANCEMENT_MAIL_TEST_ROOT'] = $root;
$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=0', '-d', 'disable_functions=stream_socket_client,fsockopen', '-S', $address, __DIR__ . '/router.php'), array(array('pipe', 'r'), array('file', $directory . '/server.log', 'a'), array('file', $directory . '/server.log', 'a')), $pipes, __DIR__, $env);
$passed = 0; $failures = array();
function mailCheck($condition, $label, $detail = null) {
    global $passed, $failures;
    if ($condition) { $passed++; return; }
    $failures[] = $label;
    fwrite(STDERR, 'FAIL ' . $label . ($detail === null ? '' : ': ' . json_encode($detail)) . "\n");
}
function mailRemove($path) {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') mailRemove($path . '/' . $name);
    rmdir($path);
}
function mailStoredSettings(): array {
    global $pdo;
    $value = $pdo->query("SELECT value FROM typecho_options WHERE name='plugin:Enhancement' AND user=0")->fetchColumn();
    $settings = json_decode($value, true);
    return is_array($settings) ? $settings : unserialize($value, array('allowed_classes' => false));
}
try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, .1);
        if ($connection) { fclose($connection); break; } usleep(50000);
    }
    $preview = '/usr/plugins/Enhancement/CommentNotifier/template/preview.php';
    $r = fixtureRequest($base, $preview . '?theme=..&file=audit-secret.php');
    mailCheck($r['status'] === 403 && strpos($r['body'], 'MAIL_SECURITY_SENTINEL') === false, 'Anonymous preview cannot disclose a traversed file', $r['status']);
    $auth = fixtureRequest($base, '/__fixture/auth', 'POST', array());
    $cookies = array();
    foreach ($auth['raw_headers'] as $header) if (stripos($header, 'Set-Cookie:') === 0) $cookies[] = explode(';', trim(substr($header, 11)), 2)[0];
    $headers = array('Cookie: ' . implode('; ', $cookies));
    mailCheck($auth['status'] === 200 && !empty($auth['json']['logged_in']) && !empty($auth['json']['token']), 'Real Typecho administrator login', $auth);
    $r = fixtureRequest($base, $preview . '?theme=default&file=owner.html', 'GET', null, $headers);
    mailCheck($r['status'] === 200 && strpos($r['headers']['content-security-policy'] ?? '', 'sandbox') !== false, 'Admin preview works in a sandbox', $r['status']);
    foreach (array(array('theme' => '..', 'file' => 'audit-secret.php'), array('theme' => 'default', 'file' => '../../../audit-secret.php'), array('theme' => 'default', 'file' => 'escape.html'), array('theme' => 'default', 'file' => '</title><script>alert(1)</script>'), array('theme' => array('default'), 'file' => 'owner.html')) as $parameters) {
        $r = fixtureRequest($base, $preview . '?' . http_build_query($parameters), 'GET', null, $headers);
        mailCheck($r['status'] === 404 && strpos($r['body'], 'MAIL_SECURITY_SENTINEL') === false && strpos($r['body'], '<script>') === false, 'Invalid, traversal, symlink and reflected HTML preview paths are rejected', $r['status']);
    }
    $editor = '/admin/extending.php?panel=Enhancement%2FCommentNotifier%2Fconsole.php&act=theme&theme=default&file=owner.html';
    $target = $plugin . '/CommentNotifier/template/default/owner.html'; $original = file_get_contents($target);
    $r = fixtureRequest($base, $editor, 'GET', null, $headers);
    mailCheck($r['status'] === 200 && strpos($r['body'], 'name="_"') !== false, 'Editor renders a CSRF token', $r['status']);
    $postHeaders = array_merge($headers, array('Content-Type: application/x-www-form-urlencoded', 'Referer: ' . $base . $editor));
    $r = fixtureRequest($base, $editor . '&do=editTheme&edit=owner.html&content=bad', 'GET', null, $headers);
    mailCheck($r['status'] === 405 && file_get_contents($target) === $original, 'GET cannot modify templates', $r['status']);
    foreach (array(null, 'forged') as $token) {
        $body = array('do' => 'editTheme', 'edit' => 'owner.html', 'content' => 'bad'); if ($token !== null) $body['_'] = $token;
        $r = fixtureRequest($base, $editor, 'POST', http_build_query($body), $postHeaders);
        mailCheck($r['status'] === 302 && file_get_contents($target) === $original, 'Missing or forged CSRF cannot modify a template', $r['status']);
    }
    $body = array('do' => 'editTheme', '_' => $auth['json']['token'], 'edit' => '../../../audit-secret.php', 'content' => 'bad');
    $r = fixtureRequest($base, $editor, 'POST', http_build_query($body), $postHeaders);
    mailCheck($r['status'] === 400 && strpos(file_get_contents($sentinel), 'MAIL_SECURITY_SENTINEL') !== false, 'Authenticated traversal cannot overwrite PHP', $r['status']);
    $body['edit'] = 'owner.html'; $body['content'] = '<p>Saved safe template</p>';
    $r = fixtureRequest($base, $editor, 'POST', http_build_query($body), $postHeaders);
    mailCheck(file_get_contents($target) === $body['content'], 'Valid administrator POST still saves an HTML template', $r['status']);
    file_put_contents($target, $original);

    $preserved = array('template' => 'default', 'auth' => 'fixture-preserved-secret', 'enable_comment_notifier' => '1', 'SMTPHost' => 'smtp.fixture.invalid', 'SMTPPassword' => 'fixture-smtp-password', 'friend_posts_interval' => '30', 'custom_setting' => 'fixture-preserved');
    $statement->execute(array(serialize($preserved), 'plugin:Enhancement'));
    // The menu opens without act=index, while the rendered form submits with it.
    $menu = '/admin/extending.php?panel=Enhancement%2FCommentNotifier%2Fconsole.php';
    $r = fixtureRequest($base, $menu, 'GET', null, $headers);
    $found = preg_match('~<form method="post" action="([^"]+)">\s*<input[^>]*name="_" value="([^"]+)"[^>]*>\s*<input[^>]*name="change" value="LP"~s', $r['body'], $matches);
    mailCheck($r['status'] === 200 && $found === 1, 'Template list renders an LP activation POST form with CSRF', $r['status']);
    if (!$found) { throw new RuntimeException('Cannot inspect rendered LP activation form'); }
    $formAction = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    mailCheck(strpos($formAction, $base . $menu . '&act=index') === 0, 'Activation form targets the actual index action');
    $activationPath = substr($formAction, strlen($base));
    $activationToken = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $activationHeaders = array_merge($headers, array('Content-Type: application/x-www-form-urlencoded', 'Referer: ' . $base . $menu));
    $r = fixtureRequest($base, $activationPath . '&change=LP', 'GET', null, $headers);
    mailCheck($r['status'] === 405 && ($r['headers']['allow'] ?? '') === 'POST' && mailStoredSettings() === $preserved, 'Template activation GET cannot change saved configuration', $r['status']);
    foreach (array(null, 'forged') as $token) {
        $params = array('change' => 'LP'); if ($token !== null) $params['_'] = $token;
        $r = fixtureRequest($base, $activationPath, 'POST', http_build_query($params), $activationHeaders);
        mailCheck($r['status'] === 302 && mailStoredSettings() === $preserved, 'Missing or forged activation CSRF preserves all saved settings', $r['status']);
    }
    foreach (array('../LP') as $theme) {
        $r = fixtureRequest($base, $activationPath, 'POST', http_build_query(array('_' => $activationToken, 'change' => $theme)), $activationHeaders);
        mailCheck($r['status'] === 400 && mailStoredSettings() === $preserved, 'Authenticated traversal activation is rejected without modifying settings', $r['status']);
    }
    $r = fixtureRequest($base, $activationPath, 'POST', http_build_query(array('_' => $activationToken, 'change' => array('LP'))), $activationHeaders);
    mailCheck($r['status'] === 200 && mailStoredSettings() === $preserved, 'Typecho ignores an array activation parameter without modifying settings', $r['status']);
    $r = fixtureRequest($base, $activationPath, 'POST', http_build_query(array('_' => $activationToken, 'change' => 'LP')), $activationHeaders);
    $saved = mailStoredSettings();
    mailCheck($r['status'] === 302 && ($saved['template'] ?? '') === 'LP', 'Valid activation token from the menu page enables LP', $r);
    unset($preserved['template']);
    mailCheck(array_intersect_key($saved, $preserved) === $preserved, 'Enabling a template preserves other saved settings and SMTP secrets');

    // Exercise the real public action route; invalid credentials never reach SMTP.
    $action = '/action/comment-notifier';
    $mailParams = array('to' => 'reader@example.test', 'fromName' => 'Fixture', 'subject' => 'Fixture subject', 'html' => '<p>Fixture</p>');
    $formHeaders = array('Content-Type: application/x-www-form-urlencoded');
    foreach (array('', 'fixture-mail-secret') as $secret) {
        $statement->execute(array(serialize(array('template' => 'default', 'auth' => $secret, 'enable_comment_notifier' => '1')), 'plugin:Enhancement'));
        $r = fixtureRequest($base, $action . '?' . http_build_query(array_merge($mailParams, array('auth' => $secret))));
        mailCheck($r['status'] === 405 && ($r['headers']['allow'] ?? '') === 'POST', 'Mail action rejects GET before authentication or SMTP', $r);
        foreach (array(null, '', 'wrong-secret', array('fixture-mail-secret')) as $provided) {
            $params = $mailParams; if ($provided !== null) $params['auth'] = $provided;
            $r = fixtureRequest($base, $action, 'POST', http_build_query($params), $formHeaders);
            mailCheck($r['status'] === 403 && ($r['headers']['cache-control'] ?? '') === 'no-store', 'Missing, empty, wrong or array mail secrets are rejected', $r);
        }
    }
    $r = fixtureRequest($base, $action, 'POST', http_build_query(array_merge($mailParams, array('auth' => 'fixture-mail-secret', 'to' => 'invalid-email'))), $formHeaders);
    mailCheck($r['status'] === 400, 'Correct mail secret reaches recipient validation without SMTP', $r);
    $statement->execute(array(serialize(array('template' => 'default', 'auth' => 'fixture-mail-secret', 'enable_comment_notifier' => '0')), 'plugin:Enhancement'));
    $r = fixtureRequest($base, $action, 'POST', http_build_query(array_merge($mailParams, array('auth' => 'fixture-mail-secret'))), $formHeaders);
    mailCheck($r['status'] === 403, 'Disabled mail action rejects even the correct secret', $r);
    mailCheck(!is_file($directory . '/smtp-attempts.log'), 'Rejected mail requests never open an SMTP connection');
    $statement->execute(array(serialize(array('template' => 'default')), 'plugin:Enhancement'));

    // Check actual email rendering against the same isolated Typecho database.
    $_SERVER['SCRIPT_NAME'] = '/fixture.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/fixture.php'; $_SERVER['PHP_SELF'] = '/fixture.php'; $_SERVER['HTTP_HOST'] = $address; $_SERVER['REQUEST_URI'] = '/fixture.php';
    require $root . '/config.inc.php'; Typecho_Widget::widget('Widget_Init');
    require $plugin . '/Plugin.php';
    $comment = (object)array('title' => '<img src=x onerror=alert(1)>', 'created' => 1700000000, 'content' => '<p><strong>Safe text</strong><a href="javascript:alert(1)">unsafe link</a><img src="https://example.test/a.png" onerror="alert(1)"><script>alert(1)</script></p>', 'author' => '"><svg onload=alert(1)>', 'mail' => 'reader@example.test', 'ip' => '<img src=x onerror=alert(1)>', 'permalink' => 'javascript:alert(1)', 'status' => 'waiting', 'parent' => 0, 'cid' => 1);
    $method = new ReflectionMethod('Enhancement_CommentNotifierHelper', 'mailBody');
    if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
    $html = $method->invoke(null, $comment, Typecho_Widget::widget('Widget_Options'), 2);
    mailCheck(strpos($html, '<svg') === false && strpos($html, '<img src=x') === false && strpos($html, '&lt;') !== false, 'Comment metadata is HTML escaped');
    mailCheck(strpos($html, '<script>') === false && strpos($html, 'javascript:') === false && strpos($html, 'onerror="') === false, 'Comment HTML strips scripts, event attributes and active URLs');
    mailCheck(strpos($html, '<strong>Safe text</strong>') !== false && strpos($html, 'https://example.test/a.png') !== false, 'Safe formatting and images survive email sanitization');
    foreach (array(array('STMPHost' => 'legacy.example.test'), array('SMTPHost' => 'new.example.test', 'STMPHost' => 'old.example.test')) as $config) {
        $mail = Enhancement_MailHelper::createMailer(array_merge(array('from' => 'sender@example.test', 'fromName' => 'Fixture', 'SMTPUserName' => 'user', 'SMTPPassword' => 'password', 'SMTPPort' => '465', 'SMTPSecure' => 'ssl'), $config));
        mailCheck($mail->Host === ($config['SMTPHost'] ?? $config['STMPHost']) && $mail->Port === 465 && $mail->SMTPSecure === 'ssl' && $mail->Mailer === 'smtp', 'Shared SMTP factory preserves legacy and canonical settings');
    }
    foreach (array(array('STMPHost' => 'legacy-form.example.test'), array('SMTPHost' => 'canonical-form.example.test', 'STMPHost' => 'old-form.example.test')) as $config) {
        $encoded = serialize($config);
        $statement->execute(array($encoded, 'plugin:Enhancement'));
        Enhancement_SettingsHelper::invalidateRuntimeSettingsCache();
        $form = new Typecho_Widget_Helper_Form();
        ob_start();
        try { Enhancement_ConfigFormHelper::build($form); } finally { ob_end_clean(); }
        $binding = Enhancement_SettingsHelper::buildPluginConfigObject($encoded);
        // Match Typecho's real Config::config() binding loop, including old keys.
        foreach ($binding as $key => $value) { $form->getInput($key)->value($value); }
        mailCheck(($form->getValues()['SMTPHost'] ?? '') === ($config['SMTPHost'] ?? $config['STMPHost']), 'Legacy and canonical SMTP hosts bind to the real canonical form field');
    }
    echo $passed . ' mail security checks passed; ' . count($failures) . " failed.\n";
} catch (Throwable $error) {
    $failures[] = $error->getMessage(); fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $pdo = null;
    if (!$failures) mailRemove($directory); else fwrite(STDERR, 'Fixture retained: ' . $directory . "\n");
}
exit($failures ? 1 : 0);
