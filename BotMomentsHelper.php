<?php

/** Authenticated bot messages may publish only after an administrator opts in. */
class Enhancement_BotMomentsHelper
{
    private static function senders(array $settings): array
    {
        return array_values(array_unique(preg_split('/\s+/', trim((string)($settings['qq_moments_allowed_senders'] ?? '')), -1, PREG_SPLIT_NO_EMPTY)));
    }

    private static function identity(string $channel, array $settings): string
    {
        if ($channel === 'qq') {
            $senders = self::senders($settings);
            sort($senders);
            $parts = array();
            foreach (array('qq_notify_provider', 'qq_app_id', 'qq_app_secret', 'qq_target_type', 'qq_target_id') as $key) {
                $parts[] = trim((string)($settings[$key] ?? ''));
            }
            return hash('sha256', serialize(array($parts, $senders)));
        }
        $account = Enhancement_NotifyTransportHelper::read('weixin_account');
        $recipient = Enhancement_NotifyTransportHelper::read('weixin_recipient');
        return hash('sha256', serialize(array($account['account_id'] ?? '', $account['token'] ?? '', $recipient['user_id'] ?? '')));
    }

    public static function validateSettings(array $settings): void
    {
        foreach (array('qq_moments_enabled', 'weixin_moments_enabled') as $key) {
            if (isset($settings[$key]) && (!is_scalar($settings[$key]) || !in_array((string)$settings[$key], array('0', '1'), true))) {
                throw new Typecho_Widget_Exception(_t('机器人发布瞬间的开关值无效'), 400);
            }
        }
        if (isset($settings['qq_moments_allowed_senders']) && !is_scalar($settings['qq_moments_allowed_senders'])) {
            throw new Typecho_Widget_Exception(_t('QQ群瞬间发布者必须逐行填写 OpenID'), 400);
        }
        $senders = self::senders($settings);
        if (count($senders) > 50 || strlen((string)($settings['qq_moments_allowed_senders'] ?? '')) > 8192) {
            throw new Typecho_Widget_Exception(_t('QQ群瞬间发布者最多填写 50 个 OpenID'), 400);
        }
        foreach ($senders as $sender) {
            if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $sender)) {
                throw new Typecho_Widget_Exception(_t('QQ群瞬间发布者 OpenID 格式无效'), 400);
            }
        }
        if (($settings['qq_moments_enabled'] ?? '0') == '1') {
            $type = trim((string)($settings['qq_target_type'] ?? ''));
            if (($settings['qq_notify_provider'] ?? '') !== 'direct' || trim((string)($settings['qq_app_id'] ?? '')) === ''
                || trim((string)($settings['qq_app_secret'] ?? '')) === '' || trim((string)($settings['qq_target_id'] ?? '')) === ''
                || !in_array($type, array('c2c', 'group'), true) || ($type === 'group' && !$senders)) {
                throw new Typecho_Widget_Exception(_t('请先配置并绑定 QQ 直连机器人；群聊还须填写允许发布的成员 OpenID，频道暂不支持发布瞬间'), 400);
            }
        }
    }

    public static function syncActivation(array $settings): void
    {
        $gates = Enhancement_NotifyTransportHelper::read('bot_moments_gate');
        $next = $gates;
        foreach (array('qq', 'weixin') as $channel) {
            if (($settings[$channel . '_moments_enabled'] ?? '0') != '1') {
                unset($next[$channel]);
                continue;
            }
            $identity = self::identity($channel, $settings);
            if (($gates[$channel]['identity'] ?? '') !== $identity || empty($gates[$channel]['enabled_at'])) {
                $next[$channel] = array('identity' => $identity, 'enabled_at' => time());
            }
        }
        if ($next !== $gates) { Enhancement_NotifyTransportHelper::write('bot_moments_gate', $next); }
    }

    private static function authorized(string $channel, array $message, array $settings): bool
    {
        $sender = $message['sender'] ?? '';
        $conversation = $message['conversation'] ?? '';
        if (!is_string($sender) || $sender === '' || !is_string($conversation)) { return false; }
        if ($channel === 'qq') {
            $type = trim((string)($settings['qq_target_type'] ?? ''));
            if (($settings['qq_notify_provider'] ?? '') !== 'direct' || ($message['account'] ?? '') !== trim((string)($settings['qq_app_id'] ?? ''))
                || $conversation !== trim((string)($settings['qq_target_id'] ?? '')) || ($message['conversation_type'] ?? '') !== $type) { return false; }
            return ($type === 'c2c' && $sender === $conversation) || ($type === 'group' && in_array($sender, self::senders($settings), true));
        }
        $account = Enhancement_NotifyTransportHelper::read('weixin_account');
        $recipient = Enhancement_NotifyTransportHelper::read('weixin_recipient');
        return !empty($account['token']) && empty($account['expired']) && !empty($account['account_id'])
            && ($message['account'] ?? '') === $account['account_id'] && $sender === ($recipient['user_id'] ?? '')
            && $conversation === $sender && (empty($account['owner_id']) || $sender === $account['owner_id']);
    }

    private static function failure(string $message, bool $replayed = false): array
    {
        return array('success' => false, 'mid' => 0, 'status' => '', 'message' => $message, 'replayed' => $replayed);
    }

    public static function handle(string $channel, array $message): ?array
    {
        if (!in_array($channel, array('qq', 'weixin'), true)) { return null; }
        $config = Enhancement_Plugin::runtimeSettings();
        $settings = is_object($config) && method_exists($config, 'toArray') ? $config->toArray() : (array)$config;
        if (($settings[$channel . '_moments_enabled'] ?? '0') != '1' || !self::authorized($channel, $message, $settings)) { return null; }
        $text = $message['text'] ?? null;
        if (!is_string($text)) { return null; }
        $text = trim(str_replace(array("\r\n", "\r"), "\n", $text));
        if (!preg_match('/\A(私密瞬间|发瞬间|瞬间)(?=$|\s|:|：)(?:\s|:|：)*([\s\S]*)\z/u', $text, $match)) { return null; }
        $gate = Enhancement_NotifyTransportHelper::read('bot_moments_gate')[$channel] ?? array();
        $timestamp = $message['timestamp'] ?? 0;
        if (!is_int($timestamp) || $timestamp <= 0 || $timestamp < (int)($gate['enabled_at'] ?? PHP_INT_MAX)
            || $timestamp < time() - 86400 || $timestamp > time() + 300
            || !hash_equals(self::identity($channel, $settings), (string)($gate['identity'] ?? ''))) { return null; }
        $id = $message['id'] ?? '';
        if (!is_string($id) || !preg_match('/\A[\x21-\x7e]{1,256}\z/D', $id)) {
            return self::failure('消息缺少有效编号，未发布瞬间。请发送一条新消息。');
        }
        $db = Typecho_Db::get();
        $table = $db->getPrefix() . 'bot_moments_receipts';
        $db->query('CREATE TABLE IF NOT EXISTS ' . $table . ' (request_id VARCHAR(64) PRIMARY KEY, fingerprint VARCHAR(64) NOT NULL, result TEXT, created INTEGER NOT NULL)', Typecho_Db::WRITE);
        $db->query($db->delete($table)->where('created < ?', time() - 30 * 86400));
        $requestId = hash('sha256', $channel . "\n" . $message['account'] . "\n" . $id);
        $fingerprint = hash('sha256', serialize(array($message['sender'], $message['conversation'], $text, $timestamp)));
        $stored = $db->fetchRow($db->select()->from($table)->where('request_id = ?', $requestId)->limit(1));
        if (!$stored) {
            try {
                $db->query($db->insert($table)->rows(array('request_id' => $requestId, 'fingerprint' => $fingerprint, 'created' => time())));
            } catch (Exception $exception) {
                $stored = $db->fetchRow($db->select()->from($table)->where('request_id = ?', $requestId)->limit(1));
                if (!$stored) { throw $exception; }
            }
        }
        if ($stored) {
            if (!hash_equals((string)$stored['fingerprint'], $fingerprint)) {
                return self::failure('消息编号冲突，未再次发布。请在后台核对瞬间。', true);
            }
            $receipt = json_decode((string)($stored['result'] ?? ''), true);
            if (!is_array($receipt)) { return self::failure('该消息正在处理或结果待核对，请查看后台瞬间，勿重复提交。', true); }
            $receipt['replayed'] = true;
            return $receipt;
        }
        try {
            $body = trim($match[2]);
            $status = $match[1] === '私密瞬间' ? 'private' : 'public';
            if ($body === '' || strlen($body) > 20000 || preg_match('//u', $body) !== 1
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $body)) {
                $result = self::failure('请在“瞬间”或“私密瞬间”后填写内容，最多 20000 字节。');
            } else {
                try {
                    Enhancement_CommentGuardContentHelper::validate(array('text' => $body), (object)array(
                        'comment_guard_block_xss' => '1', 'comment_guard_block_html' => '1'));
                    $content = $body;
                    $media = Enhancement_MomentsHelper::extractMediaFromContent($body, $content);
                    foreach ($media as $item) {
                        $parts = parse_url($item['url']);
                        if (strlen($item['url']) > 2048 || !is_array($parts) || empty($parts['host'])
                            || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)
                            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $item['url'])) {
                            throw new Typecho_Widget_Exception('invalid_media', 400);
                        }
                    }
                } catch (Typecho_Widget_Exception $exception) {
                    $result = self::failure('内容含有不支持的标签或链接，请使用文字和 HTTP(S) Markdown 图片链接。');
                }
                if (!isset($result)) {
                    Enhancement_MomentsHelper::ensureTable();
                    $mid = (int)$db->query($db->insert($db->getPrefix() . 'moments')->rows(array(
                        'content' => $content, 'tags' => null, 'media' => $media ? json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                        'source' => $channel, 'status' => $status, 'created' => time(),
                    )));
                    if ($mid < 1) { throw new RuntimeException('Moment storage did not return an ID.'); }
                    $result = array('success' => true, 'mid' => $mid, 'status' => $status,
                        'message' => ($status === 'private' ? '已发布私密瞬间 #' : '已发布瞬间 #') . $mid, 'replayed' => false);
                }
            }
        } catch (Throwable $exception) {
            // The insert may already have committed. Never execute this receipt again.
            $result = self::failure('发布结果待核对，请先查看后台瞬间，勿重复提交。');
        }
        $db->query($db->update($table)->rows(array('result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            ->where('request_id = ?', $requestId));
        return $result;
    }
}
