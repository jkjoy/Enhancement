<?php
/** Real Typecho plugin ownership checks, entirely in memory. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = realpath($argv[1] ?? '');
if (!$source || !is_file($source . '/var/Typecho/Common.php')) { exit(2); }
define('__TYPECHO_ROOT_DIR__', $source);
require $source . '/var/Typecho/Common.php';
Typecho\Common::init();
require dirname(__DIR__, 2) . '/LifecycleHelper.php';
require dirname(__DIR__, 2) . '/ContentParseHelper.php';
require dirname(__DIR__, 2) . '/ShortcodeHelper.php';
function shortcodeHookPassthrough($text = null) { return $text; }
function hookCheck(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) { throw new RuntimeException('FAIL ' . $label); }
    $passed++;
}
function hookCallbacks(array $plugins, string $hook): array
{
    return array_values($plugins['handles']['Widget_Abstract_Contents:' . $hook] ?? []);
}
$passed = 0;
$early = ['Enhancement_ContentParseHelper', 'hideReplyForExcerpt'];
$late = ['Enhancement_ContentParseHelper', 'parseExcerpt'];
$old = ['Enhancement_Plugin', 'parse'];
$key = 'Widget_Abstract_Contents:excerptEx';
try {
    Typecho_Plugin::init(['activated' => ['Enhancement' => ['handles' => [$key => [$old]]]],
        'handles' => [$key => [$old, 'shortcodeHookPassthrough', $late, $late]]]);
    Enhancement_LifecycleHelper::registerReplyPrivacyHooks();
    $normalized = Typecho_Plugin::export();
    Enhancement_LifecycleHelper::registerReplyPrivacyHooks();
    hookCheck(Typecho_Plugin::export() === $normalized, 'Runtime registration is idempotent');
    hookCheck(hookCallbacks($normalized, 'excerptEx') === [$late, 'shortcodeHookPassthrough'], 'Own old callback and duplicates migrate while third-party callback stays');
    hookCheck(hookCallbacks($normalized, 'excerpt') === [$early], 'Missing early privacy callback is registered');
    hookCheck(($normalized['activated']['Enhancement']['handles']['Widget_Abstract_Contents:excerpt'] ?? []) === [$early], 'Upgrade records ownership with Enhancement');
    Typecho_Plugin::factory('Other')->run = 'shortcodeHookPassthrough';
    // Simulate an Enhancement load during another plugin's activation sequence.
    Enhancement_LifecycleHelper::registerReplyPrivacyHooks();
    Typecho_Plugin::activate('Other');
    $plugins = Typecho_Plugin::export();
    hookCheck(($plugins['activated']['Other']['handles'] ?? []) === ['Other:run' => ['shortcodeHookPassthrough']], 'Runtime privacy registration does not pollute another plugin activation');
    Typecho_Plugin::deactivate('Other');
    $plugins = Typecho_Plugin::export();
    hookCheck(hookCallbacks($plugins, 'excerpt') === [$early] && hookCallbacks($plugins, 'excerptEx') === [$late, 'shortcodeHookPassthrough'], 'Disabling another plugin preserves both privacy callbacks');
    hookCheck(Typecho_Plugin::factory('Widget_Abstract_Contents')->filter('excerpt', 'before[reply]PRIVATE_SENTINEL[/reply]after') === 'beforeafter', 'Privacy filter still hides contents after Other is disabled');
    Typecho_Plugin::deactivate('Enhancement');
    $plugins = Typecho_Plugin::export();
    hookCheck(hookCallbacks($plugins, 'excerpt') === [], 'Disabling Enhancement cleans up its upgraded early callback');
    hookCheck(hookCallbacks($plugins, 'excerptEx') === ['shortcodeHookPassthrough'], 'Disabling Enhancement preserves third-party callback');

    Typecho_Plugin::init(['activated' => [], 'handles' => [$key => ['shortcodeHookPassthrough']]]);
    Enhancement_LifecycleHelper::registerReplyPrivacyHooks();
    // LifecycleHelper::activate calls this mode before core Plugin::activate.
    Enhancement_LifecycleHelper::registerReplyPrivacyHooks(true);
    Typecho_Plugin::activate('Enhancement');
    $plugins = Typecho_Plugin::export();
    $owned = $plugins['activated']['Enhancement']['handles'] ?? [];
    hookCheck(($owned['Widget_Abstract_Contents:excerpt'] ?? []) === [$early]
        && ($owned[$key] ?? []) === [$late], 'Normal activation attributes both privacy callbacks to Enhancement');
    hookCheck(count(array_filter(hookCallbacks($plugins, 'excerptEx'), static fn($c) => $c === $late)) === 1, 'Normal activation does not duplicate the runtime callback');
    Typecho_Plugin::deactivate('Enhancement');
    $plugins = Typecho_Plugin::export();
    hookCheck(hookCallbacks($plugins, 'excerpt') === [] && hookCallbacks($plugins, 'excerptEx') === ['shortcodeHookPassthrough'], 'Normal deactivation removes only Enhancement callbacks');
    echo $passed . " lifecycle ownership checks passed, 0 failed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error . "\n"); exit(1);
}
