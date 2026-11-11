<?php
/** Real Typecho/SQLite failure injection. Never loads an installed site config. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ENHANCEMENT_REST_API_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/rest-api/run.php';
$source = realpath($argv[1] ?? '');
if (!$source || !is_file($source . '/install/SQLite.sql')) {
    fwrite(STDERR, "Usage: php tests/settings-storage/run.php /path/to/typecho\n"); exit(2);
}
$repo = dirname(__DIR__, 2);
$directory = sys_get_temp_dir() . '/enhancement-settings-' . bin2hex(random_bytes(8));
$root = $directory . '/site';
mkdir($root . '/usr/plugins', 0700, true);
foreach (['var', 'install', 'admin'] as $name) { symlink($source . '/' . $name, $root . '/' . $name); }
symlink($repo, $root . '/usr/plugins/Enhancement');
$pdo = fixtureDatabase($root, $directory, 'https://settings-fixture.invalid');
define('__TYPECHO_ROOT_DIR__', $root);
define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
define('__TYPECHO_THEME_DIR__', '/usr/themes');
define('__TYPECHO_ADMIN_DIR__', '/admin/');
define('__TYPECHO_ROOT_URL__', 'https://settings-fixture.invalid');
define('__TYPECHO_DEBUG__', true);
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once $root . '/var/Typecho/Common.php';
Typecho\Common::init();
$db = new Typecho\Db('Pdo_SQLite', 'typecho_');
$db->addServer(['file' => $directory . '/fixture.sqlite'], Typecho\Db::READ | Typecho\Db::WRITE);
Typecho\Db::set($db);
Typecho\Widget::widget('Widget_Init');
require_once $repo . '/Plugin.php';
require_once $repo . '/Action.php';
$passed = 0;
function verifyStorage(bool $condition, string $message): void {
    global $passed;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $passed++;
}
function savedSettings(): array {
    global $pdo;
    return unserialize($pdo->query("SELECT value FROM typecho_options WHERE name='plugin:Enhancement' AND user=0")->fetchColumn(), ['allowed_classes' => false]);
}
function optionsSnapshot(): array {
    global $pdo;
    return $pdo->query('SELECT * FROM typecho_options ORDER BY name, user')->fetchAll(PDO::FETCH_ASSOC);
}
function failedSave(callable $save, string $message): void {
    try { $save(); }
    catch (Typecho_Widget_Exception $exception) {
        verifyStorage((int)$exception->getCode() === 500, $message . ': administrator receives failure');
        verifyStorage(!str_contains($exception->getMessage(), 'SQL') && !str_contains($exception->getMessage(), 'fixture-secret'), $message . ': error hides database details and secrets');
        return;
    }
    throw new RuntimeException('FAIL: ' . $message . ': save falsely reported success');
}
function removeStorageFixture(string $path): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) { removeStorageFixture($entry->getPathname()); }
    }
    rmdir($path);
}
try {
    $old = ['SMTPHost' => 'old.smtp.invalid', 'qq_notify_provider' => 'official', 'qq_app_id' => '111',
        'qq_app_secret' => 'fixture-secret-old', 'qq_moments_enabled' => '1', 'enable_rest_api' => '0'];
    $statement = $pdo->prepare("UPDATE typecho_options SET value=? WHERE name='plugin:Enhancement' AND user=0");
    $statement->execute([serialize($old)]);
    Enhancement_SettingsHelper::syncOptionCache('plugin:Enhancement', serialize($old));
    Enhancement_NotifyTransportHelper::write('qq_binding', ['identity' => Enhancement_QqOfficialHelper::credentialsIdentity($old),
        'target_type' => 'c2c', 'target_id' => 'fixture-reader', 'bound_at' => time()]);
    foreach (['qq_binding_pending', 'qq_token'] as $key) { Enhancement_NotifyTransportHelper::write($key, ['sentinel' => 'keep']); }
    Enhancement_BotMomentsHelper::syncActivation($old);
    Enhancement_FriendPostsNotifyHelper::syncActivation($old);
    $cached = Enhancement_Plugin::runtimeSettings()->toArray();
    $before = optionsSnapshot();
    $pdo->exec("CREATE TRIGGER reject_settings_update BEFORE UPDATE ON typecho_options WHEN NEW.name='plugin:Enhancement' BEGIN SELECT RAISE(ABORT, 'fixture-secret SQL write blocked'); END");
    $next = ['SMTPHost' => 'new.smtp.invalid', 'qq_app_id' => '222', 'qq_app_secret' => 'fixture-secret-new',
        'qq_notify_provider' => 'official', 'qq_moments_enabled' => '0', 'enable_rest_api' => '1'];
    failedSave(static function () use ($next) { Enhancement_ConfigStorageHelper::configHandle($next, false); }, 'Settings form write failure');
    verifyStorage(optionsSnapshot() === $before, 'Failed form changes no config, notification, gate, panel or routing rows');
    verifyStorage(Enhancement_Plugin::runtimeSettings()->toArray() === $cached, 'Failed form preserves runtime cache');
    verifyStorage(Typecho_Widget::widget('Widget_Options')->plugin('Enhancement')->SMTPHost === 'old.smtp.invalid', 'Failed form preserves widget option cache');
    $action = (new ReflectionClass('Enhancement_Action'))->newInstanceWithoutConstructor();
    $restore = new ReflectionMethod('Enhancement_Action', 'savePluginSettings');
    if (PHP_VERSION_ID < 80100) { $restore->setAccessible(true); }
    failedSave(static function () use ($restore, $action, $next) { $restore->invoke($action, $next); }, 'Backup restore write failure');
    verifyStorage(optionsSnapshot() === $before && Enhancement_Plugin::runtimeSettings()->toArray() === $cached,
        'Failed restore also preserves database and runtime state');
    $pdo->exec('DROP TRIGGER reject_settings_update');
    $pdo->exec('ALTER TABLE typecho_options RENAME TO typecho_options_unavailable');
    try {
        failedSave(static function () { Enhancement_ConfigStorageHelper::configHandle(['SMTPHost' => 'must-not-save.invalid'], false); }, 'Fresh read failure');
        verifyStorage(Enhancement_Plugin::runtimeSettings()->toArray() === $cached, 'Read failure does not invalidate known settings');
    } finally { $pdo->exec('ALTER TABLE typecho_options_unavailable RENAME TO typecho_options'); }
    verifyStorage(optionsSnapshot() === $before, 'Read failure cannot overwrite old configuration with defaults');
    Enhancement_ConfigStorageHelper::configHandle(['SMTPHost' => 'saved.smtp.invalid', 'qq_app_secret' => ''], false);
    verifyStorage(savedSettings()['qq_app_secret'] === $old['qq_app_secret'], 'Empty form secret retains saved secret');
    verifyStorage(savedSettings()['SMTPHost'] === 'saved.smtp.invalid' && savedSettings()['STMPHost'] === 'saved.smtp.invalid', 'SMTP aliases persist together');
    verifyStorage(Enhancement_Plugin::runtimeSettings()->SMTPHost === 'saved.smtp.invalid', 'Successful form refreshes runtime cache');
    $binding = Enhancement_NotifyTransportHelper::read('qq_binding');
    Enhancement_ConfigStorageHelper::configHandle(['SMTPHost' => 'saved.smtp.invalid'], false);
    verifyStorage(Enhancement_NotifyTransportHelper::read('qq_binding') === $binding, 'Identical credentials keep binding');
    Enhancement_ConfigStorageHelper::configHandle($next, false);
    verifyStorage(savedSettings()['qq_app_id'] === '222', 'Changed credentials persist');
    foreach (['qq_binding', 'qq_binding_pending', 'qq_token'] as $key) {
        verifyStorage(Enhancement_NotifyTransportHelper::read($key) === [], 'Successful identity change clears ' . $key);
    }
    verifyStorage(Enhancement_NotifyTransportHelper::read('bot_moments_gate') === [], 'Activation updates after successful save');
    verifyStorage(isset(Typecho_Widget::widget('Widget_Options')->routingTable['enhancement_restapi_posts']), 'Successful save registers REST routes');
    $restore->invoke($action, ['STMPHost' => 'restored.smtp.invalid', 'qq_notify_provider' => 'none']);
    verifyStorage(savedSettings()['SMTPHost'] === 'restored.smtp.invalid' && !isset(savedSettings()['qq_app_id']), 'Backup restore replaces rather than merges and migrates SMTP alias');
    verifyStorage(Enhancement_Plugin::runtimeSettings()->SMTPHost === 'restored.smtp.invalid', 'Restore refreshes runtime cache');
    verifyStorage(!isset(Typecho_Widget::widget('Widget_Options')->routingTable['enhancement_restapi_posts']), 'Restore synchronizes removed routes');
    $pdo->exec("DELETE FROM typecho_options WHERE name='plugin:Enhancement'");
    $before = optionsSnapshot();
    $pdo->exec("CREATE TRIGGER reject_settings_insert BEFORE INSERT ON typecho_options WHEN NEW.name='plugin:Enhancement' BEGIN SELECT RAISE(ABORT, 'fixture-secret SQL insert blocked'); END");
    failedSave(static function () { Enhancement_ConfigStorageHelper::configHandle(['SMTPHost' => 'insert.smtp.invalid'], false); }, 'New configuration insert failure');
    verifyStorage(optionsSnapshot() === $before, 'Failed insert has no premature default row or side effects');
    $pdo->exec('DROP TRIGGER reject_settings_insert');
    Enhancement_ConfigStorageHelper::configHandle(['SMTPHost' => 'insert.smtp.invalid'], true);
    verifyStorage(savedSettings()['SMTPHost'] === 'insert.smtp.invalid', 'First configuration can be inserted successfully');
    echo "Passed $passed settings storage checks.\n";
} finally {
    $pdo = null;
    removeStorageFixture($directory);
}
