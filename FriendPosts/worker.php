<?php
/** Single-run friend feed refresh for an external cron task. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$friendWorkerHelp = false;
foreach (array_slice($argv, 1) as $friendWorkerArgument) {
    if ($friendWorkerArgument === '--help' || $friendWorkerArgument === '-h') {
        $friendWorkerHelp = true;
    } elseif ($friendWorkerArgument !== '--once') {
        fwrite(STDERR, "参数无效。使用 --help 查看说明。\n");
        exit(2);
    }
}
if ($friendWorkerHelp) {
    echo "朋友文章订阅检查（仅限命令行）\n"
        . "用法：php FriendPosts/worker.php [--once]\n"
        . "  --once  检查一次后退出（默认），最多刷新 100 个到期订阅\n"
        . "  --help  显示说明，不加载站点配置\n"
        . "请启用 Enhancement、朋友文章及至少一种朋友文章 QQ / 微信通知。\n"
        . "无需开启发布瞬间。每个订阅仍遵守后台设置的刷新间隔。\n"
        . "可在宝塔或 1Panel 计划任务中每 15 分钟执行一次。\n";
    exit(0);
}

ini_set('display_errors', '0');
$friendWorkerErrorHandler = static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new RuntimeException('friend_worker_runtime_error');
};
set_error_handler($friendWorkerErrorHandler);
$friendWorkerBufferLevel = ob_get_level();
try {
    ob_start();
    $friendWorkerSiteRoot = dirname(__DIR__, 4);
    if (!is_file($friendWorkerSiteRoot . '/config.inc.php')) {
        throw new RuntimeException('friend_worker_bootstrap_unavailable');
    }
    require_once $friendWorkerSiteRoot . '/config.inc.php';
    set_error_handler($friendWorkerErrorHandler);
    $friendWorkerDb = Typecho_Db::get();
    // Typecho's cookie initialization needs an absolute site URL in CLI as well.
    if (!defined('__TYPECHO_ROOT_URL__')) {
        $friendWorkerSiteOption = $friendWorkerDb->fetchRow($friendWorkerDb->select('value')->from('table.options')
            ->where('name = ?', 'siteUrl')->where('user = ?', 0)->limit(1));
        $friendWorkerSiteUrl = is_array($friendWorkerSiteOption) ? (string)($friendWorkerSiteOption['value'] ?? '') : '';
        if (!preg_match('~\Ahttps?://~i', $friendWorkerSiteUrl) || !parse_url($friendWorkerSiteUrl, PHP_URL_HOST)) {
            throw new RuntimeException('friend_worker_site_url_unavailable');
        }
        define('__TYPECHO_ROOT_URL__', rtrim($friendWorkerSiteUrl, '/'));
    }
    Typecho_Widget::widget('Widget_Init');
    require_once dirname(__DIR__) . '/Plugin.php';
    while (ob_get_level() > $friendWorkerBufferLevel) { ob_end_clean(); }
    ini_set('display_errors', '0');
    @set_time_limit(0);

    $friendWorkerReadOption = static function (string $name) use ($friendWorkerDb): string {
        $row = $friendWorkerDb->fetchRow($friendWorkerDb->select('value')->from('table.options')
            ->where('name = ?', $name)->where('user = ?', 0)->limit(1));
        return is_array($row) && isset($row['value']) ? (string)$row['value'] : '';
    };
    $friendWorkerDecodeOption = static function (string $value): array {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) { $decoded = @unserialize($value, array('allowed_classes' => false)); }
        return is_array($decoded) ? $decoded : array();
    };
    $friendWorkerPlugins = $friendWorkerDecodeOption($friendWorkerReadOption('plugins'));
    if (!is_array($friendWorkerPlugins['activated'] ?? null) || !array_key_exists('Enhancement', $friendWorkerPlugins['activated'])) {
        fwrite(STDERR, "Enhancement 插件已停用，朋友文章检查已停止。\n");
        exit(1);
    }
    $friendWorkerConfigValue = $friendWorkerReadOption('plugin:Enhancement');
    $friendWorkerSettings = $friendWorkerDecodeOption($friendWorkerConfigValue);
    Enhancement_SettingsHelper::syncOptionCache('plugin:Enhancement', $friendWorkerConfigValue, 'Enhancement');
    if (($friendWorkerSettings['enable_friend_posts'] ?? '1') !== '1') {
        fwrite(STDERR, "朋友文章功能已关闭，检查任务已停止。\n");
        exit(1);
    }
    if (($friendWorkerSettings['enable_friend_posts_by_qq'] ?? '0') !== '1'
        && ($friendWorkerSettings['enable_friend_posts_by_weixin'] ?? '0') !== '1') {
        fwrite(STDERR, "朋友文章 QQ 和微信通知均未开启，检查任务已停止。\n");
        exit(1);
    }
    ob_start();
    $friendWorkerResult = Enhancement_FriendPostsHelper::refresh(100);
    while (ob_get_level() > $friendWorkerBufferLevel) { ob_end_clean(); }
    $friendWorkerNotifications = is_array($friendWorkerResult['notifications'] ?? null) ? $friendWorkerResult['notifications'] : array();
    $friendWorkerSent = max(0, (int)(is_scalar($friendWorkerNotifications['sent'] ?? null) ? $friendWorkerNotifications['sent'] : 0));
    $friendWorkerFailed = max(0, (int)(is_scalar($friendWorkerNotifications['failed'] ?? null) ? $friendWorkerNotifications['failed'] : 0));
    if (isset($friendWorkerNotifications['success']) && !$friendWorkerNotifications['success']) {
        fwrite(STDERR, sprintf("reason=notification_failed notifications_sent=%d notifications_failed=%d\n", $friendWorkerSent, $friendWorkerFailed)
            . "朋友文章更新通知失败，请在后台检查 QQ / 微信通道连接和最近发送结果。\n");
        exit(1);
    }
    if (empty($friendWorkerResult['success'])) { throw new RuntimeException('friend_worker_refresh_failed'); }
    fwrite(STDOUT, sprintf("status=success\nnotifications_sent=%d notifications_failed=%d\n", $friendWorkerSent, $friendWorkerFailed));
} catch (Throwable $friendWorkerException) {
    while (ob_get_level() > $friendWorkerBufferLevel) { ob_end_clean(); }
    fwrite(STDERR, "朋友文章检查失败，请在后台检查订阅、通知设置及服务器环境。\n");
    exit(1);
}
