<?php
/** CLI receiver for moments sent to the connected Weixin bot. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$workerLoop = false;
$workerMode = null;
$workerInterval = 3;
$workerHelp = false;
foreach (array_slice($argv, 1) as $workerArgument) {
    if ($workerArgument === '--help' || $workerArgument === '-h') {
        $workerHelp = true;
    } elseif ($workerArgument === '--once' || $workerArgument === '--loop') {
        if ($workerMode !== null && $workerMode !== $workerArgument) {
            fwrite(STDERR, "--once 与 --loop 不能同时使用。\n");
            exit(2);
        }
        $workerMode = $workerArgument;
        $workerLoop = $workerArgument === '--loop';
    } elseif (preg_match('/\A--interval=([1-9][0-9]{0,2})\z/D', $workerArgument, $workerMatches)
        && (int)$workerMatches[1] <= 300) {
        $workerInterval = (int)$workerMatches[1];
    } else {
        fwrite(STDERR, "参数无效。使用 --help 查看说明；--interval 须为 1 至 300 秒。\n");
        exit(2);
    }
}
if ($workerHelp) {
    echo "微信瞬间消息接收器（仅限命令行）\n"
        . "用法：php BotMoments/worker.php [--once | --loop] [--interval=N]\n"
        . "  --once        同步一次后退出（默认）\n"
        . "  --loop        持续接收；每轮使用微信长轮询，再暂停指定秒数\n"
        . "  --interval=N  每轮同步后的暂停秒数，范围 1–300，默认 3\n"
        . "  --help        显示说明，不加载站点配置\n"
        . "运行前请在后台登录并绑定微信，开启微信发布瞬间。\n"
        . "插件停用、发布关闭、连接过期或发生异常时会退出，请检查后台后重启。\n";
    exit(0);
}

ini_set('display_errors', '0');
$workerErrorHandler = static function (int $severity): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new RuntimeException('worker_runtime_error');
};
set_error_handler($workerErrorHandler);
$workerBufferLevel = ob_get_level();
try {
    // Suppress bootstrap output so initialization failures cannot expose site details.
    ob_start();
    $workerSiteRoot = dirname(__DIR__, 4);
    if (!is_file($workerSiteRoot . '/config.inc.php')) {
        throw new RuntimeException('worker_bootstrap_unavailable');
    }
    require_once $workerSiteRoot . '/config.inc.php';
    set_error_handler($workerErrorHandler);
    $workerDb = Typecho_Db::get();
    // Typecho omits the request URL prefix in CLI; its cookie initialization still needs an absolute root URL.
    if (!defined('__TYPECHO_ROOT_URL__')) {
        $workerSiteOption = $workerDb->fetchRow($workerDb->select('value')->from('table.options')
            ->where('name = ?', 'siteUrl')->where('user = ?', 0)->limit(1));
        $workerSiteUrl = is_array($workerSiteOption) ? (string)($workerSiteOption['value'] ?? '') : '';
        if (!preg_match('~\Ahttps?://~i', $workerSiteUrl) || !parse_url($workerSiteUrl, PHP_URL_HOST)) {
            throw new RuntimeException('worker_site_url_unavailable');
        }
        define('__TYPECHO_ROOT_URL__', rtrim($workerSiteUrl, '/'));
    }
    Typecho_Widget::widget('Widget_Init');
    require_once dirname(__DIR__) . '/Plugin.php';
    while (ob_get_level() > $workerBufferLevel) {
        ob_end_clean();
    }
    ini_set('display_errors', '0');
    @set_time_limit(0);

    $workerReadOption = static function (string $name) use ($workerDb): string {
        $row = $workerDb->fetchRow($workerDb->select('value')->from('table.options')
            ->where('name = ?', $name)->where('user = ?', 0)->limit(1));
        return is_array($row) && isset($row['value']) ? (string)$row['value'] : '';
    };
    $workerDecodeOption = static function (string $value): array {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            $decoded = @unserialize($value, array('allowed_classes' => false));
        }
        return is_array($decoded) ? $decoded : array();
    };
    do {
        // Read both options anew: a running receiver must respect backend changes.
        $workerPlugins = $workerDecodeOption($workerReadOption('plugins'));
        if (!is_array($workerPlugins['activated'] ?? null) || !array_key_exists('Enhancement', $workerPlugins['activated'])) {
            fwrite(STDERR, "Enhancement 插件已停用，微信接收器已停止。\n");
            exit(1);
        }
        $workerConfigValue = $workerReadOption('plugin:Enhancement');
        $workerSettings = $workerDecodeOption($workerConfigValue);
        Enhancement_SettingsHelper::syncOptionCache('plugin:Enhancement', $workerConfigValue, 'Enhancement');
        if (($workerSettings['weixin_moments_enabled'] ?? '0') !== '1') {
            fwrite(STDERR, "微信发布瞬间已关闭，接收器已停止。\n");
            exit(1);
        }
        $workerState = Enhancement_WeixinNotifyHelper::status();
        if (!empty($workerState['expired'])) {
            fwrite(STDERR, "微信连接已过期，请在后台重新扫码后重启接收器。\n");
            exit(1);
        }
        if (empty($workerState['logged_in']) || empty($workerState['user_id'])) {
            fwrite(STDERR, "微信尚未登录或绑定接收人，请在后台完成配置后重启接收器。\n");
            exit(1);
        }
        $workerResult = Enhancement_WeixinNotifyHelper::sync();
        $workerStatus = in_array($workerResult['status'] ?? '', array('bound', 'wait', 'busy', 'select'), true)
            ? $workerResult['status'] : 'synced';
        fwrite(STDOUT, sprintf("status=%s published=%d failed=%d\n", $workerStatus,
            max(0, (int)($workerResult['moments_published'] ?? 0)), max(0, (int)($workerResult['moments_failed'] ?? 0))));
        if ($workerLoop) {
            sleep($workerInterval);
        }
    } while ($workerLoop);
} catch (Throwable $workerException) {
    while (ob_get_level() > $workerBufferLevel) {
        ob_end_clean();
    }
    fwrite(STDERR, "微信接收失败，请在后台检查微信连接、插件设置及服务器环境后重启接收器。\n");
    exit(1);
}
