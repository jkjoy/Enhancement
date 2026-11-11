<?php
/** Request-cache regression checks with isolated options and real link rendering. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

class SettingsFixtureQuery {
    public function from($table) { return $this; }
    public function where($expression, $value) { return $this; }
    public function limit($count) { return $this; }
}
class Typecho_Db {
    public static $reads = 0;
    public static $value;
    public static function get() { return new self(); }
    public function select($column) { return new SettingsFixtureQuery(); }
    public function fetchRow($query) { self::$reads++; return ['value' => self::$value]; }
}
class Typecho_Widget {
    public static $failSync = false;
    public static function widget($name) {
        if (self::$failSync) { throw new RuntimeException('Fixture widget cache unavailable'); }
        return (object)['siteUrl' => 'https://site.invalid/', 'index' => 'https://site.invalid/'];
    }
}
class Typecho_Common {
    public static function url($path, $base) { return rtrim($base, '/') . '/' . ltrim($path, '/'); }
}
class Widget_Abstract_Contents {}
class Widget_Abstract_Comments { public $url = ''; }
class Enhancement_Plugin {
    public static function runtimeSettings($reload = false) {
        return Enhancement_SettingsHelper::pluginSettings(null, $reload);
    }
    public static function commentSmileyEnabled() { return false; }
    public static function parseCallback($matches) { return $matches[0]; }
    public static function validateHttpUrl($url) { return (bool)filter_var($url, FILTER_VALIDATE_URL); }
    public static function blankTargetEnabled() { return Enhancement_GoRedirectHelper::blankTargetEnabled(); }
}
class SettingsFixtureObject {
    public static $awakened = false;
    public function __wakeup() { self::$awakened = true; }
}
require_once dirname(__DIR__, 2) . '/SettingsHelper.php';
require_once dirname(__DIR__, 2) . '/GoRedirectHelper.php';
require_once dirname(__DIR__, 2) . '/ContentParseHelper.php';

$passed = 0;
function settingsCheck($condition, string $message): void {
    global $passed;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $passed++;
}
function settingsFixture(array $settings): void {
    Typecho_Db::$value = serialize($settings);
    Typecho_Db::$reads = 0;
    Enhancement_SettingsHelper::invalidateRuntimeSettingsCache();
}

settingsFixture(['enable_go_redirect' => '1', 'enable_blank_target' => '1']);
$text = '';
for ($index = 0; $index < 20; $index++) { $text .= '<a href="https://external.invalid/' . $index . '">link</a>'; }
$rendered = Enhancement_ContentParseHelper::parse($text, new Widget_Abstract_Comments(), null);
settingsCheck(Typecho_Db::$reads === 1, 'twenty comment links share one settings query throughout real rendering');
settingsCheck(substr_count($rendered, 'https://site.invalid/go/') === 20
    && substr_count($rendered, 'target="_blank"') === 20, 'cached settings preserve redirect and new-window rendering');
$settings = Enhancement_Plugin::runtimeSettings();
$settings->enable_go_redirect = '0';
settingsCheck(Enhancement_Plugin::runtimeSettings()->enable_go_redirect === '1', 'mutating a returned config object does not alter the request cache');

Typecho_Db::$value = serialize(['enable_go_redirect' => '0']);
settingsCheck(Enhancement_Plugin::runtimeSettings()->enable_go_redirect === '1' && Typecho_Db::$reads === 1,
    'normal reads keep a stable request snapshot without another query');
settingsCheck(Enhancement_SettingsHelper::reloadPluginSettings()->enable_go_redirect === '0' && Typecho_Db::$reads === 2,
    'explicit worker reload reads an externally updated setting');
Typecho_Db::$value = serialize(['enable_go_redirect' => '1']);
settingsCheck(Enhancement_Plugin::runtimeSettings(true)->enable_go_redirect === '1' && Typecho_Db::$reads === 3,
    'runtimeSettings reload can bypass the cache for delivery identity checks');

Typecho_Db::$value = serialize(['SMTPHost' => 'smtp.saved.invalid']);
Enhancement_SettingsHelper::syncOptionCache('plugin:Enhancement', Typecho_Db::$value);
settingsCheck(Enhancement_Plugin::runtimeSettings()->SMTPHost === 'smtp.saved.invalid' && Typecho_Db::$reads === 4,
    'saving plugin settings invalidates the request cache immediately');
Typecho_Db::$value = serialize(['STMPHost' => 'smtp.restored.invalid']);
Typecho_Widget::$failSync = true;
Enhancement_SettingsHelper::syncOptionCache('plugin:Enhancement', Typecho_Db::$value);
Typecho_Widget::$failSync = false;
$restored = Enhancement_Plugin::runtimeSettings();
settingsCheck($restored->SMTPHost === 'smtp.restored.invalid' && $restored->STMPHost === 'smtp.restored.invalid'
    && Typecho_Db::$reads === 5, 'backup restore invalidates the runtime cache even when widget patching fails and exposes the legacy SMTP alias');
Typecho_Db::$value = serialize(['SMTPHost' => 'smtp.external.invalid']);
Enhancement_SettingsHelper::syncOptionCache('enh:unrelated', '{}');
settingsCheck(Enhancement_Plugin::runtimeSettings()->SMTPHost === 'smtp.restored.invalid' && Typecho_Db::$reads === 5,
    'unrelated option writes do not invalidate plugin settings');

settingsFixture([]);
for ($index = 0; $index < 10; $index++) { Enhancement_Plugin::runtimeSettings(); }
settingsCheck(Typecho_Db::$reads === 1, 'empty plugin configuration is also cached');
settingsFixture(['SMTPHost' => 'smtp.current.invalid', 'STMPHost' => 'smtp.legacy.invalid']);
$settings = Enhancement_Plugin::runtimeSettings();
settingsCheck($settings->SMTPHost === 'smtp.current.invalid' && $settings->STMPHost === 'smtp.legacy.invalid',
    'the canonical SMTP host wins when both option names exist without modifying legacy data');
settingsFixture(['SMTPHost' => '', 'STMPHost' => 'smtp.legacy.invalid']);
settingsCheck(Enhancement_Plugin::runtimeSettings()->SMTPHost === '', 'an explicitly cleared canonical SMTP host stays cleared');

$plain = ['SMTPHost' => 'smtp.serialized.invalid', 'enabled' => '1'];
settingsCheck(Enhancement_SettingsHelper::decodePluginConfigValue(serialize($plain)) === $plain
    && Enhancement_SettingsHelper::decodePluginConfigValue(json_encode($plain)) === $plain,
    'serialized and JSON settings remain compatible');
Enhancement_SettingsHelper::decodePluginConfigValue(serialize(new SettingsFixtureObject()));
$nested = Enhancement_SettingsHelper::decodePluginConfigValue(serialize(['nested' => new SettingsFixtureObject()]));
settingsCheck(!SettingsFixtureObject::$awakened && isset($nested['nested'])
    && $nested['nested'] instanceof __PHP_Incomplete_Class, 'configuration decoding never instantiates serialized classes or invokes wakeup hooks');

echo "Passed $passed settings cache checks.\n";
