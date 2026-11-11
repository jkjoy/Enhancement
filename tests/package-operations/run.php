<?php
/** Offline regression: php tests/package-operations/run.php (PHP 8+, ZipArchive, subprocesses). */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function packageRemove($path) {
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') packageRemove($path . '/' . $name);
    rmdir($path);
}
function packageDirectory($path) { if (!is_dir($path)) mkdir($path, 0700, true); }

if (!getenv('ENHANCEMENT_PACKAGE_FIXTURE')) {
    if (PHP_VERSION_ID < 80000 || !class_exists('ZipArchive')) { fwrite(STDERR, "Requires PHP 8+ and ZipArchive.\n"); exit(2); }
    $temporary = sys_get_temp_dir() . '/enhancement-package-test-' . bin2hex(random_bytes(6));
    $repository = dirname(__DIR__, 2);
    $exit = 0;
    try {
        foreach (['normal', 'linked'] as $mode) {
            $fixture = $temporary . '/' . $mode;
            $site = $fixture . '/site';
            $plugin = $mode === 'linked' ? $fixture . '/source' : $site . '/usr/plugins/Enhancement';
            packageDirectory($plugin . '/Go');
            packageDirectory($site . '/usr/themes');
            packageDirectory($site . '/usr/plugins');
            foreach (['Action.php', 'PublicApiHelper.php', 'Go/Action.php', 'Go/Renderer.php', 'GoRedirectHelper.php', 'FormHelper.php'] as $name) copy($repository . '/' . $name, $plugin . '/' . $name);
            if ($mode === 'linked') symlink($plugin, $site . '/usr/plugins/Enhancement');
            $environment = getenv();
            $environment['ENHANCEMENT_PACKAGE_FIXTURE'] = $fixture;
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', __FILE__, $mode], [STDIN, STDOUT, STDERR], $pipes, null, $environment);
            $result = proc_close($process);
            if ($result !== 0) { $exit = $result; break; }
        }
    } finally { packageRemove($temporary); }
    exit($exit);
}

$fixture = getenv('ENHANCEMENT_PACKAGE_FIXTURE');
$site = $fixture . '/site';
$plugin = $site . '/usr/plugins/Enhancement';
define('__TYPECHO_ROOT_DIR__', $site);
interface Widget_Interface_Do {}
class Typecho_Widget {
    public $request;
    public $response;
    public static function widget($name) { return (object)['siteUrl' => 'https://home.example/?a=1&b=2']; }
}
function _t($message, ...$arguments) { return $arguments ? vsprintf($message, $arguments) : $message; }
require $plugin . '/FormHelper.php';
require $plugin . '/GoRedirectHelper.php';
class Enhancement_Plugin {
    public static function decodeGoTarget($token) { return Enhancement_GoRedirectHelper::decodeGoTarget($token); }
    public static function validateHttpUrl($url) { return Enhancement_FormHelper::validateHttpUrl($url); }
}
class PackageRequest {
    public $target;
    public function isAjax() { return true; }
    public function get($name) { return $name === 'target' ? $this->target : null; }
}
class PackageResponse {
    public $status = 200;
    public $json;
    public function setStatus($status) { $this->status = $status; }
    public function throwJson($value) { $this->json = $value; }
}
require $plugin . '/Action.php';
require $plugin . '/Go/Action.php';
$action = new Enhancement_Action();
$action->request = new PackageRequest();
$action->response = new PackageResponse();
$options = new ReflectionProperty($action, 'options');
$options->setValue($action, (object)['plugins' => ['activated' => ['Active' => []]], 'theme' => 'nebula']);
$passed = 0;
function packageCheck($condition, $label) {
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    $passed++;
}
function packageCall($method, array $arguments = []) {
    global $action;
    return (new ReflectionMethod($action, $method))->invokeArgs($action, $arguments);
}
function packageZip($path, array $entries, $symbolic = false) {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create ZIP fixture');
    foreach ($entries as $name => $body) {
        $zip->addFromString($name, $body);
        if ($symbolic) $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
    }
    $zip->close();
}
function packageDelete($name, $plugin = true) {
    global $action;
    $action->response = new PackageResponse();
    packageCall('deleteInstalledPackage', [$name, $plugin ? '插件' : '主题', $plugin ? '/usr/plugins' : '/usr/themes', $plugin]);
    return $action->response;
}
function packageRender($instance, $method, $target) {
    $instance->request = new PackageRequest();
    $instance->request->target = $target;
    $instance->response = new PackageResponse();
    ob_start();
    try { $instance->$method(); return [ob_get_contents(), $instance->response->status]; }
    finally { ob_end_clean(); }
}

set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});

$zipFile = $fixture . '/update.zip';
$newPlugin = "<?php\n/**\n * @package Enhancement\n * @version 9.9.9\n */\nclass Enhancement_Plugin {}\n";
packageZip($zipFile, ['Enhancement-main/Plugin.php' => $newPlugin, 'Enhancement-main/new-file.txt' => 'updated']);

if (($argv[1] ?? '') === 'linked') {
    $sentinel = $fixture . '/source/keep.txt';
    file_put_contents($sentinel, 'preserve source repository');
    $message = '';
    $result = packageCall('installDownloadedEnhancementPackage', [$zipFile, '9.9.9', &$message]);
    packageCheck(!$result && strpos($message, 'Git') !== false, 'Linked deployments reject online replacement with actionable guidance');
    packageCheck(is_link($plugin) && file_get_contents($sentinel) === 'preserve source repository' && is_file($plugin . '/Action.php'), 'Rejected linked update preserves link and source files');
    packageCheck(count(glob($site . '/usr/plugins/.Enhancement-backup-*')) === 0, 'Rejected linked update creates no backup');
    echo "$passed linked deployment checks passed.\n";
    exit;
}

// Cleanup must unlink links rather than follow them, at the root and within a tree.
$outside = $fixture . '/outside';
packageDirectory($outside);
file_put_contents($outside . '/keep.txt', 'outside sentinel');
symlink($outside, $fixture . '/root-link');
packageCheck(packageCall('removeDirectoryRecursively', [$fixture . '/root-link']) && !is_link($fixture . '/root-link'), 'Cleanup removes a root link');
packageCheck(file_get_contents($outside . '/keep.txt') === 'outside sentinel', 'Root link cleanup preserves external contents');
$tree = $fixture . '/tree';
packageDirectory($tree . '/nested');
file_put_contents($tree . '/nested/owned.txt', 'owned');
symlink($outside, $tree . '/directory-link');
symlink($outside . '/keep.txt', $tree . '/file-link');
symlink($outside . '/missing', $tree . '/broken-link');
packageCheck(packageCall('removeDirectoryRecursively', [$tree]) && !is_dir($tree), 'Cleanup removes a normal tree containing directory, file and broken links');
packageCheck(file_get_contents($outside . '/keep.txt') === 'outside sentinel', 'Nested link cleanup preserves external contents');

foreach (['Active', 'Enhancement', 'Inactive', 'Linked'] as $name) {
    if ($name === 'Linked') symlink($outside, $site . '/usr/plugins/' . $name);
    else { packageDirectory($site . '/usr/plugins/' . $name); file_put_contents($site . '/usr/plugins/' . $name . '/keep.txt', 'owned'); }
}
foreach (['nebula', 'unused'] as $name) { packageDirectory($site . '/usr/themes/' . $name); file_put_contents($site . '/usr/themes/' . $name . '/index.php', '<?php'); }
foreach (['Active', 'active', 'Enhancement', 'enhancement'] as $name) packageCheck(packageDelete($name)->status === 400, 'Enabled plugins and Enhancement are protected regardless of case');
packageCheck(is_file($plugin . '/Action.php') && is_file($site . '/usr/plugins/Active/keep.txt'), 'Protected plugin files remain present');
packageCheck(packageDelete('nebula', false)->status === 400 && is_file($site . '/usr/themes/nebula/index.php'), 'Current theme cannot be deleted');
packageCheck(packageDelete('Inactive')->json['success'] && !is_dir($site . '/usr/plugins/Inactive'), 'Inactive plugin can be deleted');
file_put_contents($site . '/usr/plugins/Single.php', '<?php');
packageCheck(packageDelete('Single')->json['success'] && !is_file($site . '/usr/plugins/Single.php'), 'Inactive single-file plugin can be deleted');
packageCheck(packageDelete('unused', false)->json['success'] && !is_dir($site . '/usr/themes/unused'), 'Unused theme can be deleted');
packageCheck(packageDelete('Linked')->status === 400 && is_link($site . '/usr/plugins/Linked') && is_file($outside . '/keep.txt'), 'Online deletion refuses linked deployments');
foreach (['../outside', '', ['Active'], 123] as $name) packageCheck(packageDelete($name)->status === 400, 'Malformed package names are rejected without warnings');
packageCheck(packageDelete('Missing')->status === 404, 'Missing packages retain the existing not-found response');

foreach (['../escape', '/absolute', 'C:/drive', 'folder/..', 'folder/../../escape', 'folder\\..\\escape'] as $entry) {
    $invalidFile = $fixture . '/invalid.zip';
    packageZip($invalidFile, [$entry => 'invalid']);
    $zip = new ZipArchive(); $zip->open($invalidFile); $message = '';
    packageCheck(!packageCall('validateZipArchivePaths', [$zip, &$message]) && $message !== '', 'Real ZIP entries with unsafe paths are refused');
    $zip->close();
    $message = '';
    packageCheck(!packageCall('installDownloadedEnhancementPackage', [$invalidFile, '9.9.9', &$message]) && is_file($plugin . '/Action.php'), 'Unsafe update package preserves the installed version');
}
$invalidFile = $fixture . '/symlink.zip';
packageZip($invalidFile, ['Enhancement-main/link' => $outside], true);
$zip = new ZipArchive(); $zip->open($invalidFile); $message = '';
packageCheck(!packageCall('validateZipArchivePaths', [$zip, &$message]) && strpos($message, '符号链接') !== false, 'Unix ZIP symbolic-link entries are refused');
$zip->close();

// Both entry points use the same renderer and preserve their existing window behavior.
$legacy = new Enhancement_Go_Action();
foreach ([[$action, 'goRedirect'], [$legacy, 'action']] as [$instance, $method]) {
    foreach ([null, '', [['nested']]] as $target) {
        [$body, $status] = packageRender($instance, $method, $target);
        packageCheck($status === 404 && strpos($body, '不存在') !== false, 'Missing and nested-array go targets return clean not-found responses');
    }
    foreach (['not-a-valid-token!', base64_encode('javascript:alert(1)')] as $target) {
        [$body, $status] = packageRender($instance, $method, $target);
        packageCheck($status === 400 && strpos($body, '<script') === false, 'Invalid go targets are rejected safely');
    }
}
$url = 'https://example.test/path?a=1&b=2';
$token = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
[$currentBody, $status] = packageRender($action, 'goRedirect', [$token]);
packageCheck($status === 200 && strpos($currentBody, 'href="https://example.test/path?a=1&amp;b=2"') !== false, 'Current route escapes the decoded target');
packageCheck(strpos($currentBody, 'target="_blank"') !== false && strpos($currentBody, 'https://home.example/?a=1&amp;b=2') !== false, 'Current route keeps its new window behavior and escapes the home URL');
[$legacyBody, $status] = packageRender($legacy, 'action', $token);
packageCheck($status === 200 && strpos($legacyBody, 'target="_blank"') === false, 'Legacy route keeps same-window navigation');
packageCheck(str_replace(' target="_blank"', '', $currentBody) === $legacyBody, 'Both go routes share identical reminder rendering');

// A real normal-directory update succeeds and cleans backups without following old links.
symlink($outside, $plugin . '/external-source');
file_put_contents($plugin . '/old-file.txt', 'old');
$message = '';
packageCheck(packageCall('installDownloadedEnhancementPackage', [$zipFile, '9.9.9', &$message]), 'Normal-directory deployment installs a real ZIP update');
packageCheck(file_get_contents($plugin . '/Plugin.php') === $newPlugin && file_get_contents($plugin . '/new-file.txt') === 'updated', 'Updated package files are installed');
packageCheck(!file_exists($plugin . '/old-file.txt') && count(glob($site . '/usr/plugins/.Enhancement-backup-*')) === 0 && count(glob($site . '/usr/plugins/.enhancement-update-*')) === 0, 'Successful update removes old files and staging directories');
packageCheck(file_get_contents($outside . '/keep.txt') === 'outside sentinel', 'Successful update cleanup preserves external linked source');
echo "$passed package operation and go route checks passed.\n";
