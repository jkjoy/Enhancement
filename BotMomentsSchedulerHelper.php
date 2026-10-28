<?php

/** A visit may dispatch one authenticated, independent Weixin receive request. */
class Enhancement_BotMomentsSchedulerHelper
{
    private static $registered = false;

    public static function validateSettings(array $settings): void
    {
        if (isset($settings['weixin_moments_auto_receive']) && (!is_scalar($settings['weixin_moments_auto_receive'])
            || !in_array((string)$settings['weixin_moments_auto_receive'], array('0', '1'), true))) {
            throw new Typecho_Widget_Exception(_t('微信自动接收的开关值无效'), 400);
        }
    }

    public static function status(): array
    {
        try {
            $config = Enhancement_Plugin::runtimeSettings();
            $settings = is_object($config) && method_exists($config, 'toArray') ? $config->toArray() : (array)$config;
            $state = Enhancement_NotifyTransportHelper::read('weixin_cron');
            $allowed = array('queued', 'running', 'bound', 'wait', 'busy', 'select', 'error', 'dispatch_failed', 'disabled');
            return array('enabled' => ($settings['weixin_moments_enabled'] ?? '0') == '1' && ($settings['weixin_moments_auto_receive'] ?? '1') == '1',
                'status' => in_array($state['status'] ?? '', $allowed, true) ? $state['status'] : 'idle',
                'last_run' => max(0, (int)($state['last_run'] ?? 0)), 'last_attempt' => max(0, (int)($state['last_attempt'] ?? 0)),
                'next_at' => max(0, (int)($state['next_at'] ?? 0)));
        } catch (Throwable $exception) { return array('enabled' => false, 'status' => 'error', 'last_run' => 0, 'last_attempt' => 0, 'next_at' => 0); }
    }

    private static function context(): ?array
    {
        $config = Enhancement_Plugin::runtimeSettings();
        $settings = is_object($config) && method_exists($config, 'toArray') ? $config->toArray() : (array)$config;
        if (($settings['weixin_moments_enabled'] ?? '0') != '1' || ($settings['weixin_moments_auto_receive'] ?? '1') != '1') { return null; }
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'plugins')->where('user = ?', 0)->limit(1));
        $plugins = json_decode((string)($row['value'] ?? ''), true);
        if (!is_array($plugins)) { $plugins = @unserialize((string)($row['value'] ?? ''), array('allowed_classes' => false)); }
        if (!is_array($plugins['activated'] ?? null) || !array_key_exists('Enhancement', $plugins['activated'])) { return null; }
        $account = Enhancement_NotifyTransportHelper::read('weixin_account');
        $recipient = Enhancement_NotifyTransportHelper::read('weixin_recipient');
        $gate = Enhancement_NotifyTransportHelper::read('bot_moments_gate')['weixin'] ?? array();
        if (empty($account['token']) || empty($account['account_id']) || !empty($account['expired']) || empty($recipient['user_id'])
            || (!empty($account['owner_id']) && $account['owner_id'] !== $recipient['user_id']) || empty($gate['identity']) || empty($gate['enabled_at'])) { return null; }
        return array('binding' => hash('sha256', serialize(array($account['account_id'], $account['token'],
            $account['owner_id'] ?? '', $recipient['user_id'], $gate))));
    }

    private static function taskUrl(): string
    {
        // Widget_Options::index can reflect the request Host; the credential must use the saved site URL.
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'siteUrl')->where('user = ?', 0)->limit(1));
        $base = rtrim((string)($row['value'] ?? ''), '/');
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\\\\\x00-\x20\x7f]/', $base)) { throw new RuntimeException('Invalid site URL.'); }
        $rewrite = Typecho_Widget::widget('Widget_Options')->rewrite;
        return $base . ($rewrite == 1 ? '' : '/index.php') . '/action/enhancement-edit?do=bot-moments-cron';
    }

    public static function registerTick(): void
    {
        if (self::$registered || PHP_SAPI === 'cli' || isset($_SERVER['HTTP_X_ENHANCEMENT_CRON_TOKEN'])
            || ($_GET['do'] ?? '') === 'bot-moments-cron') { return; }
        self::$registered = true;
        register_shutdown_function(static function () {
            try { self::dispatchIfDue(); } catch (Throwable $exception) { /* A failed background dispatch must not affect the page. */ }
        });
    }

    private static function dispatch(string $url, string $token): bool
    {
        if (!function_exists('curl_init')) { return false; }
        $handle = curl_init($url);
        try {
            curl_setopt_array($handle, array(
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => '',
                CURLOPT_HTTPHEADER => array('X-Enhancement-Cron-Token: ' . $token, 'Content-Length: 0'),
                CURLOPT_CONNECTTIMEOUT_MS => 300, CURLOPT_TIMEOUT_MS => 500, CURLOPT_NOSIGNAL => true,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) { return strlen($chunk); },
            ));
            curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $sent = (int)curl_getinfo($handle, CURLINFO_REQUEST_SIZE);
            // Without FastCGI the task may acknowledge only after the short caller timeout.
            return $status === 204 || (curl_errno($handle) === 28 && $sent > 0);
        } finally { curl_close($handle); }
    }

    public static function dispatchIfDue(?callable $dispatch = null): bool
    {
        $lease = null;
        try {
            $context = self::context();
            if ($context === null) { return false; }
            $state = Enhancement_NotifyTransportHelper::read('weixin_cron');
            if (($state['next_at'] ?? 0) > time() || (in_array($state['status'] ?? '', array('queued', 'running'), true)
                && ($state['expires_at'] ?? 0) > time())) { return false; }
            $lease = Enhancement_NotifyTransportHelper::acquireLease('weixin_cron_lock', 10);
            if ($lease === null) { return false; }
            $context = self::context();
            if ($context === null) { return false; }
            $state = Enhancement_NotifyTransportHelper::read('weixin_cron');
            if (($state['next_at'] ?? 0) > time() || (in_array($state['status'] ?? '', array('queued', 'running'), true)
                && ($state['expires_at'] ?? 0) > time())) { return false; }
            $token = bin2hex(random_bytes(32));
            $next = array('status' => 'queued', 'next_at' => time() + 60, 'expires_at' => time() + 120,
                'token_hash' => hash('sha256', $token), 'binding' => $context['binding'],
                'last_attempt' => time(), 'last_run' => (int)($state['last_run'] ?? 0));
            Enhancement_NotifyTransportHelper::write('weixin_cron', $next);
            try { $sent = (bool)call_user_func($dispatch ?? array(__CLASS__, 'dispatch'), self::taskUrl(), $token); }
            catch (Throwable $exception) { $sent = false; }
            if (!$sent) {
                $failed = $next;
                $failed['status'] = 'dispatch_failed';
                unset($failed['token_hash']);
                $failed['expires_at'] = 0;
                Enhancement_NotifyTransportHelper::compareAndSwap('weixin_cron', $next, $failed);
            }
            return $sent;
        } catch (Throwable $exception) { return false; }
        finally {
            if ($lease !== null) {
                try { Enhancement_NotifyTransportHelper::releaseLease('weixin_cron_lock', $lease); } catch (Throwable $exception) {}
            }
        }
    }

    public static function claim(string $token): ?array
    {
        try {
            if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) { return null; }
            $context = self::context();
            $state = Enhancement_NotifyTransportHelper::read('weixin_cron');
            if ($context === null || ($state['status'] ?? '') !== 'queued' || ($state['expires_at'] ?? 0) <= time()
                || !hash_equals((string)($state['token_hash'] ?? ''), hash('sha256', $token))
                || !hash_equals((string)($state['binding'] ?? ''), $context['binding'])) { return null; }
            $next = $state;
            unset($next['token_hash']);
            $next['status'] = 'running';
            $next['run_id'] = bin2hex(random_bytes(16));
            $next['expires_at'] = time() + 180;
            return Enhancement_NotifyTransportHelper::compareAndSwap('weixin_cron', $state, $next) ? $next : null;
        } catch (Throwable $exception) { return null; }
    }

    public static function run(array $claim, ?callable $sync = null): void
    {
        $level = ob_get_level();
        $status = 'error';
        $counts = array();
        try {
            if (($claim['status'] ?? '') !== 'running' || ($claim['expires_at'] ?? 0) <= time()
                || Enhancement_NotifyTransportHelper::read('weixin_cron') !== $claim) { return; }
            $context = self::context();
            if ($context === null || !hash_equals((string)($claim['binding'] ?? ''), $context['binding'])) { $status = 'disabled'; }
            else {
                ob_start();
                $result = call_user_func($sync ?? array('Enhancement_WeixinNotifyHelper', 'sync'));
                $status = in_array($result['status'] ?? '', array('bound', 'wait', 'busy', 'select'), true) ? $result['status'] : 'error';
                foreach (array('moments_published', 'moments_drafts', 'moments_failed') as $key) { $counts[$key] = max(0, min(100000, (int)($result[$key] ?? 0))); }
            }
        } catch (Throwable $exception) { $status = 'error'; }
        finally { while (ob_get_level() > $level) { ob_end_clean(); } }
        $next = $claim;
        $next['status'] = $status;
        $next['expires_at'] = 0;
        $next['last_run'] = time();
        $next['counts'] = $counts;
        try { Enhancement_NotifyTransportHelper::compareAndSwap('weixin_cron', $claim, $next); } catch (Throwable $exception) {}
    }
}
