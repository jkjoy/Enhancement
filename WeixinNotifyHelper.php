<?php

// iLink protocol ported from Tencent/openclaw-weixin 2.4.10 (MIT).
class Enhancement_WeixinNotifyHelper
{
    private const VERSION = '2.4.10';
    private const BASE_URL = 'https://ilinkai.weixin.qq.com';

    public static function validateSettings(array $settings, ?array $previous = null): void
    {
        foreach (array('enable_comment_by_weixin', 'enable_link_submit_by_weixin', 'enable_friend_posts_by_weixin', 'weixin_include_links', 'weixin_moments_enabled') as $key) {
            if (isset($settings[$key]) && (!is_scalar($settings[$key]) || !in_array((string)$settings[$key], array('0', '1'), true))) {
                throw new Typecho_Widget_Exception(_t('微信通知开关值无效'), 400);
            }
        }
        if (empty($settings['enable_comment_by_weixin']) && empty($settings['enable_link_submit_by_weixin']) && empty($settings['enable_friend_posts_by_weixin']) && empty($settings['weixin_moments_enabled'])) {
            return;
        }
        // An expired session must not block saving unrelated settings.
        if ($previous !== null
            && ((string)($settings['enable_comment_by_weixin'] ?? '0') !== '1' || ($previous['enable_comment_by_weixin'] ?? '0') == '1')
            && ((string)($settings['enable_link_submit_by_weixin'] ?? '0') !== '1' || ($previous['enable_link_submit_by_weixin'] ?? '0') == '1')
            && ((string)($settings['enable_friend_posts_by_weixin'] ?? '0') !== '1' || ($previous['enable_friend_posts_by_weixin'] ?? '0') == '1')
            && ((string)($settings['weixin_moments_enabled'] ?? '0') !== '1' || ($previous['weixin_moments_enabled'] ?? '0') == '1')) {
            return;
        }
        try {
            self::recipient(self::account());
        } catch (Throwable $exception) {
            throw new Typecho_Widget_Exception(get_class($exception) === 'RuntimeException'
                ? $exception->getMessage() : _t('微信通知配置验证失败，请检查数据库和服务器环境'), 400);
        }
    }

    private static function userId(string $value): bool
    {
        return (bool)preg_match('/\A[A-Za-z0-9_@.:-]{1,256}\z/', $value);
    }

    private static function baseUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? strtolower((string)$parts['host']) : '';
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*weixin\.qq\.com\z/', $host)
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', array('', '/'), true)) {
            throw new RuntimeException('微信返回的接口地址不在受信任的官方域名范围内。');
        }
        return 'https://' . $host;
    }

    private static function qrUrl(string $url): string
    {
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        return preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*weixin\.qq\.com\z/', $host) ? $url : '';
    }

    private static function headers(?string $token = null, bool $json = true): array
    {
        $headers = array('iLink-App-Id: bot', 'iLink-App-ClientVersion: 132106');
        if ($json) {
            $number = unpack('Nvalue', random_bytes(4));
            $headers[] = 'AuthorizationType: ilink_bot_token';
            $headers[] = 'X-WECHAT-UIN: ' . base64_encode((string)$number['value']);
        }
        if ($token !== null) {
            if (!preg_match('/\A[\x21-\x7e]{1,8192}\z/', $token)) {
                throw new RuntimeException('微信登录凭证格式无效，请重新扫码。');
            }
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        return $headers;
    }

    private static function baseInfo(): array
    {
        return array('channel_version' => self::VERSION, 'bot_agent' => 'TypechoEnhancement/1.11.0');
    }

    private static function request(?callable $transport, string $method, string $url, array $headers = array(), $payload = null, int $timeout = 6): array
    {
        return call_user_func($transport === null ? array('Enhancement_NotifyTransportHelper', 'request') : $transport, $method, $url, $headers, $payload, $timeout);
    }

    private static function failed(array $response): bool
    {
        if (!isset($response['status'], $response['data']) || !is_array($response['data'])
            || (int)$response['status'] < 200 || (int)$response['status'] >= 300) {
            return true;
        }
        foreach (array('ret', 'errcode') as $key) {
            if (array_key_exists($key, $response['data']) && (!is_scalar($response['data'][$key]) || (string)$response['data'][$key] !== '0')) {
                return true;
            }
        }
        return false;
    }

    private static function error(array $response): RuntimeException
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();
        $code = Enhancement_NotifyTransportHelper::apiCode($data);
        foreach (array('ret', 'errcode') as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && preg_match('/\A-?[0-9]{1,12}\z/', (string)$data[$key]) && (string)$data[$key] !== '0') {
                $code = (string)$data[$key];
                break;
            }
        }
        if (in_array($data['ret'] ?? null, array(-14, '-14'), true) || in_array($data['errcode'] ?? null, array(-14, '-14'), true) || (int)($response['status'] ?? 0) === 401) {
            $account = Enhancement_NotifyTransportHelper::read('weixin_account');
            if ($account !== array()) {
                $account['expired'] = true;
                Enhancement_NotifyTransportHelper::write('weixin_account', $account);
            }
            return new RuntimeException('微信登录凭证已失效，请重新扫码并同步接收人（错误码 -14 或 HTTP 401）。');
        }
        $message = '微信接口失败（HTTP ' . (int)($response['status'] ?? 0) . ($code !== '' ? '，错误码 ' . $code : '') . '）。';
        $message .= $code === '-2'
            ? '请先向微信机器人发送一条消息，再在此同步接收人；该通道的主动发送受微信服务端限制。'
            : '请检查微信连接状态，稍后再试。';
        return new RuntimeException($message);
    }

    public static function status(): array
    {
        $account = Enhancement_NotifyTransportHelper::read('weixin_account');
        $login = Enhancement_NotifyTransportHelper::read('weixin_login');
        $sync = Enhancement_NotifyTransportHelper::read('weixin_sync');
        $recipient = Enhancement_NotifyTransportHelper::read('weixin_recipient');
        $settings = Enhancement_Plugin::runtimeSettings();
        $storedResult = Enhancement_NotifyTransportHelper::read('weixin_result');
        $lastResult = array();
        if ($storedResult !== array()) {
            $lastResult = array('success' => !empty($storedResult['success']),
                'message' => is_string($storedResult['message'] ?? null) ? $storedResult['message'] : '',
                'time' => (int)($storedResult['time'] ?? 0));
        }
        $userId = isset($recipient['user_id']) && is_string($recipient['user_id']) && self::userId($recipient['user_id']) ? $recipient['user_id'] : '';
        $pending = isset($login['qrcode']) && (int)($login['started_at'] ?? 0) > time() - 300;
        $candidates = array();
        foreach (array_slice((array)($sync['candidates'] ?? array()), 0, 20) as $candidate) {
            if (is_string($candidate) && self::userId($candidate)
                && (empty($account['owner_id']) || $candidate === $account['owner_id'])) {
                $candidates[] = array('user_id' => $candidate);
            }
        }
        return array(
            'logged_in' => !empty($account['token']), 'expired' => !empty($account['expired']),
            'account_id' => is_string($account['account_id'] ?? null) ? $account['account_id'] : '', 'user_id' => $userId,
            'has_context' => $userId !== '' && !empty($sync['context_tokens'][$userId]),
            'login_pending' => $pending, 'login_status' => $pending ? (string)($login['status'] ?? 'wait') : '',
            'need_verifycode' => $pending && ($login['status'] ?? '') === 'need_verifycode',
            'qrcode_url' => $pending ? self::qrUrl((string)($login['qrcode_url'] ?? '')) : '',
            'candidates' => $candidates, 'last_result' => $lastResult,
            'moments_enabled' => isset($settings->weixin_moments_enabled) && $settings->weixin_moments_enabled == '1',
            'moments_last_poll' => (int)($sync['synced_at'] ?? 0),
            'auto_receive' => class_exists('Enhancement_BotMomentsSchedulerHelper') ? Enhancement_BotMomentsSchedulerHelper::status() : array(),
        );
    }

    public static function startLogin(?callable $transport = null): array
    {
        $account = Enhancement_NotifyTransportHelper::read('weixin_account');
        $tokens = array();
        if (is_string($account['token'] ?? null) && $account['token'] !== '') {
            $tokens[] = $account['token'];
        }
        $response = self::request($transport, 'POST', self::BASE_URL . '/ilink/bot/get_bot_qrcode?bot_type=3', self::headers(), array('local_token_list' => $tokens), 12);
        if (self::failed($response)) {
            throw self::error($response);
        }
        $data = $response['data'];
        $qrcode = $data['qrcode'] ?? null;
        $url = is_string($data['qrcode_img_content'] ?? null) ? self::qrUrl($data['qrcode_img_content']) : '';
        if (!is_string($qrcode) || $qrcode === '' || strlen($qrcode) > 8192 || $url === '') {
            throw new RuntimeException('微信未返回有效的官方扫码链接。');
        }
        Enhancement_NotifyTransportHelper::write('weixin_login', array('qrcode' => $qrcode, 'qrcode_url' => $url, 'base_url' => self::BASE_URL, 'started_at' => time(), 'status' => 'wait'));
        return array('status' => 'wait', 'qrcode_url' => $url, 'message' => '请使用微信扫描二维码并确认连接。');
    }

    public static function pollLogin(string $verifyCode = '', ?callable $transport = null): array
    {
        $login = Enhancement_NotifyTransportHelper::read('weixin_login');
        if (empty($login['qrcode']) || (int)($login['started_at'] ?? 0) <= time() - 300) {
            Enhancement_NotifyTransportHelper::write('weixin_login', array());
            return array('status' => 'expired', 'message' => '扫码链接已过期，请重新生成。');
        }
        if ($verifyCode !== '' && !preg_match('/\A[0-9]{1,12}\z/', $verifyCode)) {
            throw new RuntimeException('请输入手机微信显示的数字验证码。');
        }
        $base = self::baseUrl((string)($login['base_url'] ?? self::BASE_URL));
        $path = '/ilink/bot/get_qrcode_status?qrcode=' . rawurlencode((string)$login['qrcode']);
        if ($verifyCode !== '') {
            $path .= '&verify_code=' . rawurlencode($verifyCode);
        }
        try {
            $response = self::request($transport, 'GET', $base . $path, self::headers(null, false), null, 12);
        } catch (RuntimeException $exception) {
            if (get_class($exception) !== 'RuntimeException' || $exception->getCode() !== 28) {
                throw $exception;
            }
            return array('status' => 'wait', 'message' => '微信尚未更新连接状态，请稍后再次检查。');
        }
        if (self::failed($response)) {
            throw self::error($response);
        }
        $data = $response['data'];
        $status = is_string($data['status'] ?? null) ? $data['status'] : '';
        $messages = array(
            'wait' => '等待微信扫码。', 'scaned' => '已扫码，请在手机微信中完成确认。',
            'need_verifycode' => '请输入手机微信显示的数字验证码，然后检查连接状态。',
            'verify_code_blocked' => '验证码多次错误，请稍后重新生成扫码链接。',
            'expired' => '扫码链接已过期，请重新生成。',
            'binded_redirect' => '微信提示已绑定此连接；如本机没有有效凭证，请在微信中解除旧连接后重新扫码。',
            'scaned_but_redirect' => '已扫码，微信正在切换服务区域，请再次检查状态。',
            'confirmed' => '微信已连接，通知开关已关闭。请向微信机器人发送“绑定通知”，同步接收人后重新保存启用。',
        );
        if (!isset($messages[$status])) {
            throw new RuntimeException('微信返回了无法识别的登录状态，请稍后重试。');
        }
        $login['status'] = $status;
        if ($status === 'scaned_but_redirect') {
            if (!is_string($data['redirect_host'] ?? null)) {
                throw new RuntimeException('微信未返回有效的区域地址。');
            }
            $login['base_url'] = self::baseUrl('https://' . $data['redirect_host']);
        }
        if ($status === 'confirmed') {
            $token = $data['bot_token'] ?? null;
            $accountId = $data['ilink_bot_id'] ?? null;
            $owner = $data['ilink_user_id'] ?? '';
            if (!is_string($token) || !preg_match('/\A[\x21-\x7e]{1,8192}\z/', $token)
                || !is_string($accountId) || !self::userId($accountId)
                || !is_string($owner) || ($owner !== '' && !self::userId($owner))) {
                throw new RuntimeException('微信返回的登录凭证不完整，请重新扫码。');
            }
            $accountBase = self::baseUrl(is_string($data['baseurl'] ?? null) && $data['baseurl'] !== '' ? $data['baseurl'] : $base);
            Enhancement_ConfigStorageHelper::configHandle(array('enable_comment_by_weixin' => '0', 'enable_link_submit_by_weixin' => '0', 'enable_friend_posts_by_weixin' => '0', 'weixin_moments_enabled' => '0'), false);
            Enhancement_NotifyTransportHelper::write('weixin_account', array('token' => $token, 'account_id' => $accountId, 'owner_id' => $owner, 'base_url' => $accountBase, 'connected_at' => time(), 'expired' => false));
            Enhancement_NotifyTransportHelper::write('weixin_sync', array());
            Enhancement_NotifyTransportHelper::write('weixin_recipient', array());
            Enhancement_NotifyTransportHelper::write('weixin_login', array());
            Enhancement_NotifyTransportHelper::write('weixin_result', array());
        } else {
            Enhancement_NotifyTransportHelper::write('weixin_login', in_array($status, array('expired', 'verify_code_blocked', 'binded_redirect'), true) ? array() : $login);
        }
        return array('status' => $status, 'message' => $messages[$status]);
    }

    private static function account(): array
    {
        $account = Enhancement_NotifyTransportHelper::read('weixin_account');
        if (empty($account['token']) || !is_string($account['token'])) {
            throw new RuntimeException('请先连接微信。');
        }
        if (!empty($account['expired'])) {
            throw new RuntimeException('微信登录凭证已失效，请重新扫码。');
        }
        self::headers($account['token']);
        if (!is_string($account['account_id'] ?? null) || !self::userId($account['account_id'])
            || !is_string($account['owner_id'] ?? '') || (!empty($account['owner_id']) && !self::userId($account['owner_id']))) {
            throw new RuntimeException('微信登录信息不完整，请重新扫码。');
        }
        $account['base_url'] = self::baseUrl((string)($account['base_url'] ?? ''));
        return $account;
    }

    private static function recipient(array $account): string
    {
        $recipient = Enhancement_NotifyTransportHelper::read('weixin_recipient');
        $sync = Enhancement_NotifyTransportHelper::read('weixin_sync');
        $to = is_string($recipient['user_id'] ?? null) ? $recipient['user_id'] : '';
        if (!self::userId($to) || !in_array($to, (array)($sync['candidates'] ?? array()), true)
            || (!empty($account['owner_id']) && $account['owner_id'] !== $to)) {
            throw new RuntimeException('微信接收人尚未完成绑定，请先发送绑定消息并同步。');
        }
        return $to;
    }

    public static function sync(?callable $transport = null): array
    {
        $lease = Enhancement_NotifyTransportHelper::acquireLease('weixin_sync_lock', 180);
        $counts = array('moments_published' => 0, 'moments_failed' => 0, 'moments_drafts' => 0, 'moments_replies_skipped' => 0);
        if ($lease === null) {
            return array('status' => 'busy', 'message' => '微信消息正在同步，请稍后重试。', 'candidates' => array()) + $counts;
        }
        try {
            $account = self::account();
            $sync = Enhancement_NotifyTransportHelper::read('weixin_sync');
            $settings = Enhancement_Plugin::runtimeSettings();
            $recipient = Enhancement_NotifyTransportHelper::read('weixin_recipient');
            // A message that establishes the first binding cannot also publish a moment.
            $momentRecipient = class_exists('Enhancement_BotMomentsHelper')
                && isset($settings->weixin_moments_enabled) && $settings->weixin_moments_enabled == '1'
                && !empty($recipient['user_id']) ? self::recipient($account) : '';
            try {
                $response = self::request($transport, 'POST', $account['base_url'] . '/ilink/bot/getupdates', self::headers($account['token']), array(
                    'get_updates_buf' => (string)($sync['get_updates_buf'] ?? ''), 'base_info' => self::baseInfo(),
                ), 12);
            } catch (RuntimeException $exception) {
                if (get_class($exception) !== 'RuntimeException' || $exception->getCode() !== 28) {
                    throw $exception;
                }
                return array('status' => 'wait', 'message' => '尚未收到新消息。请先向微信机器人发送“绑定通知”，再同步一次。', 'candidates' => array()) + $counts;
            }
            $currentAccount = Enhancement_NotifyTransportHelper::read('weixin_account');
            if (($currentAccount['token'] ?? null) !== $account['token'] || ($currentAccount['account_id'] ?? null) !== $account['account_id']
                || !empty($currentAccount['expired'])) {
                return array('status' => 'wait', 'message' => '微信连接已变更，请重新同步。', 'candidates' => array()) + $counts;
            }
            if (self::failed($response)) {
                throw self::error($response);
            }
            $data = $response['data'];
            $context = is_array($sync['context_tokens'] ?? null) ? $sync['context_tokens'] : array();
            $candidates = is_array($sync['candidates'] ?? null) ? $sync['candidates'] : array();
            $commands = array();
            foreach (is_array($data['msgs'] ?? null) ? $data['msgs'] : array() as $message) {
                if (!is_array($message) || !in_array($message['message_type'] ?? null, array(1, '1'), true) || !empty($message['group_id'])) {
                    continue;
                }
                $from = $message['from_user_id'] ?? '';
                $to = $message['to_user_id'] ?? '';
                $token = $message['context_token'] ?? '';
                if (($to !== '' && $to !== $account['account_id']) || !is_string($from) || !self::userId($from) || $from === $account['account_id']
                    || (!empty($account['owner_id']) && $account['owner_id'] !== $from)) {
                    continue;
                }
                if (is_string($token) && $token !== '' && strlen($token) <= 16384) {
                    $context[$from] = $token;
                }
                if (!in_array($from, $candidates, true)) {
                    $candidates[] = $from;
                }
                if ($momentRecipient === '' || $from !== $momentRecipient) {
                    continue;
                }
                $text = array();
                $attachments = array();
                foreach (is_array($message['item_list'] ?? null) ? $message['item_list'] : array() as $item) {
                    if (is_array($item) && in_array($item['type'] ?? null, array(1, '1'), true)
                        && is_array($item['text_item'] ?? null) && is_string($item['text_item']['text'] ?? null)) {
                        $text[] = $item['text_item']['text'];
                    }
                    if (is_array($item) && in_array($item['type'] ?? null, array(2, '2'), true)
                        && is_array($item['image_item'] ?? null)) {
                        $attachments[] = $item['image_item'];
                    }
                }
                if ($text !== array() || $attachments !== array()) {
                    $id = $message['message_id'] ?? '';
                    $created = $message['create_time_ms'] ?? 0;
                    $command = array('id' => is_string($id) || is_int($id) ? (string)$id : '',
                        'sender' => $from, 'conversation' => $from, 'text' => implode("\n", $text),
                        'timestamp' => is_numeric($created) ? (int)floor((float)$created / 1000) : 0,
                        'account' => $account['account_id']);
                    if ($attachments !== array()) { $command['attachments'] = $attachments; }
                    $commands[] = $command;
                }
            }
            $sync['context_tokens'] = array_slice($context, -20, null, true);
            $sync['candidates'] = array_slice($candidates, -20);
            // Replies need the newest context; retain the cursor until every command is handled.
            Enhancement_NotifyTransportHelper::write('weixin_sync', $sync);
            $replies = 0;
            foreach ($commands as $command) {
                if (!Enhancement_NotifyTransportHelper::renewLease('weixin_sync_lock', $lease, 180)) {
                    throw new RuntimeException('微信消息同步锁已失效，请重新同步。');
                }
                $result = Enhancement_BotMomentsHelper::handle('weixin', $command);
                if ($result === null || !empty($result['replayed'])) {
                    continue;
                }
                $counts[empty($result['success']) ? 'moments_failed' : (($result['kind'] ?? '') === 'draft' ? 'moments_drafts' : 'moments_published')]++;
                if ($replies < 10) {
                    self::send((string)$result['message'], $settings, $transport);
                    $replies++;
                } else {
                    $counts['moments_replies_skipped']++;
                }
            }
            if (is_string($data['get_updates_buf'] ?? null) && $data['get_updates_buf'] !== '') {
                $sync['get_updates_buf'] = $data['get_updates_buf'];
            }
            $sync['synced_at'] = time();
            Enhancement_NotifyTransportHelper::write('weixin_sync', $sync);
            if (!empty($recipient['user_id'])) {
                self::recipient($account);
                return array('status' => 'bound', 'message' => '接收人保持绑定，会话信息已同步。', 'candidates' => array()) + $counts;
            }
            if (!empty($account['owner_id']) && in_array($account['owner_id'], $sync['candidates'], true)) {
                self::bind($account['owner_id']);
                return array('status' => 'bound', 'message' => '已绑定扫码微信账号为通知接收人，现在可启用微信通知。', 'candidates' => array()) + $counts;
            }
            $view = array();
            foreach ($sync['candidates'] as $candidate) {
                if (is_string($candidate) && self::userId($candidate)) {
                    $view[] = array('user_id' => $candidate);
                }
            }
            return array('status' => $view === array() ? 'wait' : 'select', 'message' => $view === array() ? '尚未收到绑定消息。请先向微信机器人发送“绑定通知”，再同步一次。' : '请选择刚发送绑定消息的接收人。', 'candidates' => $view) + $counts;
        } finally {
            Enhancement_NotifyTransportHelper::releaseLease('weixin_sync_lock', $lease);
        }
    }

    public static function bind(string $userId): array
    {
        $account = self::account();
        $sync = Enhancement_NotifyTransportHelper::read('weixin_sync');
        if (!self::userId($userId) || !in_array($userId, (array)($sync['candidates'] ?? array()), true)
            || (!empty($account['owner_id']) && $account['owner_id'] !== $userId)) {
            throw new RuntimeException('只能绑定本次微信连接中已同步的接收人。');
        }
        $recipient = Enhancement_NotifyTransportHelper::read('weixin_recipient');
        if (!empty($recipient['user_id']) && $recipient['user_id'] !== $userId) {
            throw new RuntimeException('请先断开微信连接，再更换接收人。');
        }
        Enhancement_NotifyTransportHelper::write('weixin_recipient', array('user_id' => $userId));
        return array('status' => 'bound', 'message' => '微信通知接收人已绑定。');
    }

    public static function disconnect(): array
    {
        Enhancement_ConfigStorageHelper::configHandle(array('enable_comment_by_weixin' => '0', 'enable_link_submit_by_weixin' => '0', 'enable_friend_posts_by_weixin' => '0', 'weixin_moments_enabled' => '0'), false);
        foreach (array('weixin_account', 'weixin_login', 'weixin_sync', 'weixin_recipient', 'weixin_result') as $key) {
            Enhancement_NotifyTransportHelper::write($key, array());
        }
        return array('status' => 'disconnected', 'message' => '微信连接及接收人已清除，微信通知已关闭。重新连接并绑定后，请保存启用通知。');
    }

    public static function send(string $text, $settings = null, ?callable $transport = null): array
    {
        $result = array('success' => false, 'error' => '', 'id' => '', 'delivery' => '');
        try {
            $settings = $settings === null ? Enhancement_Plugin::runtimeSettings() : (object)$settings;
            $text = Enhancement_NotifyTransportHelper::text($text, !isset($settings->weixin_include_links) || $settings->weixin_include_links == '1');
            if ($text === '') {
                throw new RuntimeException('微信通知内容不能为空。');
            }
            $account = self::account();
            $to = self::recipient($account);
            $sync = Enhancement_NotifyTransportHelper::read('weixin_sync');
            $clientId = 'enhancement:' . (string)(int)floor(microtime(true) * 1000) . '-' . bin2hex(random_bytes(8));
            $msg = array('from_user_id' => '', 'to_user_id' => $to, 'client_id' => $clientId,
                'message_type' => 2, 'message_state' => 2, 'item_list' => array(array('type' => 1, 'text_item' => array('text' => $text))),
            );
            $context = $sync['context_tokens'][$to] ?? '';
            if (is_string($context) && $context !== '') {
                $msg['context_token'] = $context;
            }
            // A timeout can occur after acceptance. This call makes one attempt.
            $response = self::request($transport, 'POST', $account['base_url'] . '/ilink/bot/sendmessage', self::headers($account['token']), array('msg' => $msg, 'base_info' => self::baseInfo()));
            if (self::failed($response)) {
                throw self::error($response);
            }
            $data = $response['data'];
            $id = $data['message_id'] ?? '';
            $validId = is_scalar($id) && preg_match('/\A[0-9]{1,24}\z/', (string)$id);
            if (!array_key_exists('ret', $data) && !array_key_exists('errcode', $data) && !$validId) {
                throw new RuntimeException('微信接口未返回发送受理状态，请检查连接并在接收端核对是否送达。');
            }
            $result['success'] = true;
            $result['id'] = $validId ? (string)$id : $clientId;
            $result['delivery'] = 'accepted';
        } catch (Throwable $exception) {
            $result['error'] = get_class($exception) === 'RuntimeException' || $exception instanceof Typecho_Widget_Exception
                ? $exception->getMessage() : '微信通知发送失败，请检查连接状态。';
        }
        try {
            Enhancement_NotifyTransportHelper::recordResult('weixin', $result);
        } catch (Throwable $exception) {
            // An unavailable status store must not change the delivery outcome.
        }
        return $result;
    }

    public static function comment($comment, $statusOverride = null)
    {
        try {
            $settings = Enhancement_Plugin::runtimeSettings();
            if (!isset($settings->enable_comment_by_weixin) || $settings->enable_comment_by_weixin != '1') {
                return;
            }
            $status = $statusOverride !== null ? trim((string)$statusOverride) : (isset($comment->status) ? trim((string)$comment->status) : '');
            if ($status !== 'approved' || (isset($comment->authorId, $comment->ownerId) && (string)$comment->authorId === (string)$comment->ownerId)) {
                return;
            }
            $text = isset($comment->text) ? $comment->text : (isset($comment->content) ? $comment->content : '');
            $message = sprintf("【新评论通知】\n编号：%s\n📝 评论者：%s\n📖 文章标题：《%s》\n💬 评论内容：%s\n🔗 文章链接：%s",
                isset($comment->coid) ? (string)(int)$comment->coid : '-', (string)($comment->author ?? ''), (string)($comment->title ?? ''), strip_tags((string)$text), (string)($comment->permalink ?? ''));
            self::send($message, $settings);
        } catch (Throwable $exception) {
            // Notification failures must not affect the comment lifecycle.
        }
    }

    public static function mark($comment, $edit, $status)
    {
        if (trim((string)$status) === 'approved') {
            self::comment($edit, 'approved');
        }
    }

    public static function notifyLinkSubmission(array $link)
    {
        try {
            $settings = Enhancement_Plugin::runtimeSettings();
            if (!isset($settings->enable_link_submit_by_weixin) || $settings->enable_link_submit_by_weixin != '1') {
                return;
            }
            $options = Typecho_Widget::widget('Widget_Options');
            $title = isset($options->title) && trim((string)$options->title) !== '' ? trim((string)$options->title) : '网站';
            $reviewUrl = Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $options->adminUrl);
            $message = sprintf("【友情链接申请通知】\n编号：%s\n🌐 站点：%s\n🔖 友链名称：%s\n🔗 友链地址：%s\n📮 申请邮箱：%s\n📝 网站描述：%s\n🕒 提交时间：%s\n🛠 审核地址：%s",
                isset($link['lid']) ? (string)(int)$link['lid'] : '-', $title,
                (string)($link['name'] ?? '-'), (string)($link['url'] ?? '-'), (string)($link['email'] ?? '-'), (string)($link['description'] ?? '-'), date('Y-m-d H:i:s'), $reviewUrl);
            self::send($message, $settings);
        } catch (Throwable $exception) {
            // Notification failures must not affect link submissions.
        }
    }
}
