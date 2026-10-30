<?php

/** Authenticated bot messages may publish only after an administrator opts in. */
class Enhancement_BotMomentsHelper
{
    private static function identity(string $channel, array $settings): string
    {
        if ($channel === 'qq') {
            return hash('sha256', serialize(array(Enhancement_QqOfficialHelper::credentialsIdentity($settings),
                Enhancement_QqOfficialHelper::binding($settings))));
        }
        $account = Enhancement_NotifyTransportHelper::read('weixin_account');
        $recipient = Enhancement_NotifyTransportHelper::read('weixin_recipient');
        return hash('sha256', serialize(array($account['account_id'] ?? '', $account['token'] ?? '', $recipient['user_id'] ?? '')));
    }

    public static function validateSettings(array $settings): void
    {
        if (isset($settings['weixin_moments_enabled']) && (!is_scalar($settings['weixin_moments_enabled'])
            || !in_array((string)$settings['weixin_moments_enabled'], array('0', '1'), true))) {
            throw new Typecho_Widget_Exception(_t('微信发布瞬间的开关值无效'), 400);
        }
    }

    public static function syncActivation(array $settings): void
    {
        $gates = Enhancement_NotifyTransportHelper::read('bot_moments_gate');
        $next = array();
        foreach (array('qq', 'weixin') as $channel) {
            if (($settings[$channel . '_moments_enabled'] ?? '0') != '1') { continue; }
            $identity = self::identity($channel, $settings);
            $next[$channel] = ($gates[$channel]['identity'] ?? '') === $identity && !empty($gates[$channel]['enabled_at'])
                ? $gates[$channel] : array('identity' => $identity, 'enabled_at' => time(), 'activation_id' => bin2hex(random_bytes(16)));
        }
        if ($next !== $gates) { Enhancement_NotifyTransportHelper::write('bot_moments_gate', $next); }
    }

    private static function authorized(string $channel, array $message, array $settings): bool
    {
        $sender = $message['sender'] ?? '';
        $conversation = $message['conversation'] ?? '';
        if (!is_string($sender) || $sender === '' || !is_string($conversation)) { return false; }
        if ($channel === 'qq') {
            if (($settings['qq_notify_provider'] ?? '') !== 'official' || ($message['conversation_type'] ?? '') !== 'private'
                || $conversation !== $sender || !is_string($message['account'] ?? null) || $message['account'] === '') { return false; }
            try { Enhancement_QqOfficialHelper::validateSettings($settings); } catch (Throwable $exception) { return false; }
            $binding = Enhancement_QqOfficialHelper::binding($settings);
            return ($binding['target_type'] ?? '') === 'c2c' && ($binding['target_id'] ?? '') === $sender
                && $message['account'] === ($settings['qq_app_id'] ?? '');
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

    private static function command(string $text): ?array
    {
        if ($text === '发布瞬间' || $text === '取消瞬间') {
            return array('mode' => $text === '发布瞬间' ? 'finish' : 'cancel');
        }
        if (!preg_match('/\A(私密图文瞬间|图文瞬间|私密瞬间|发瞬间|瞬间)(?=$|\s|:|：)(?:\s|:|：)*([\s\S]*)\z/u', $text, $match)) { return null; }
        return array('mode' => strpos($match[1], '图文') !== false ? 'start' : 'create',
            'body' => trim($match[2]), 'status' => strpos($match[1], '私密') === 0 ? 'private' : 'public');
    }

    private static function draftRow($db, string $scope): ?array
    {
        $table = $db->getPrefix() . 'bot_moment_drafts';
        $db->query('CREATE TABLE IF NOT EXISTS ' . $table . ' (scope_id VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, updated INTEGER NOT NULL)', Typecho_Db::WRITE);
        $row = $db->fetchRow($db->select()->from($table)->where('scope_id = ?', $scope)->limit(1));
        return $row ?: null;
    }

    private static function changeDraft($db, string $scope, ?array $before, array $next): bool
    {
        $table = $db->getPrefix() . 'bot_moment_drafts';
        $value = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($value === false || strlen($value) > 60000) { throw new RuntimeException('Invalid draft text.'); }
        if ($before !== null) {
            if ($before['value'] === $value) { return true; }
            $condition = stripos($db->getAdapterName(), 'mysql') !== false ? 'BINARY value = BINARY ?' : 'value = ?';
            return (int)$db->query($db->update($table)->rows(array('value' => $value, 'updated' => time()))
                ->where('scope_id = ?', $scope)->where($condition, $before['value'])) > 0;
        }
        try {
            $db->query($db->insert($table)->rows(array('scope_id' => $scope, 'value' => $value, 'updated' => time())));
            return true;
        } catch (Exception $exception) {
            if (self::draftRow($db, $scope) === null) { throw $exception; }
            return false;
        }
    }

    private static function draftReply(string $text, string $status = ''): array
    {
        return array('success' => true, 'mid' => 0, 'status' => $status, 'kind' => 'draft', 'message' => $text, 'replayed' => false);
    }

    private static function content(string $body, bool $allowEmpty): array
    {
        if ((!$allowEmpty && $body === '') || strlen($body) > 20000 || preg_match('//u', $body) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $body)) {
            throw new Typecho_Widget_Exception('invalid_content', 400);
        }
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
        return array($content, $media);
    }

    private static function insertMoment($db, string $channel, string $content, array $media, string $status, int $timestamp): array
    {
        $mid = (int)$db->query($db->insert($db->getPrefix() . 'moments')->rows(array(
            'content' => $content === '' ? '图片' : $content, 'tags' => null,
            'media' => $media ? json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'source' => $channel, 'status' => $status, 'created' => $timestamp,
        )));
        if ($mid < 1) { throw new RuntimeException('Moment storage did not return an ID.'); }
        return array('success' => true, 'mid' => $mid, 'status' => $status, 'kind' => 'published',
            'message' => ($status === 'private' ? '已发布私密瞬间 #' : '已发布瞬间 #') . $mid, 'replayed' => false);
    }

    private static function stillAuthorized(string $channel, array $message, string $identity): bool
    {
        $config = Enhancement_Plugin::runtimeSettings();
        $settings = is_object($config) && method_exists($config, 'toArray') ? $config->toArray() : (array)$config;
        $gate = Enhancement_NotifyTransportHelper::read('bot_moments_gate')[$channel] ?? array();
        return ($settings[$channel . '_moments_enabled'] ?? '0') == '1' && self::authorized($channel, $message, $settings)
            && hash_equals($identity, hash('sha256', serialize($gate)))
            && hash_equals(self::identity($channel, $settings), (string)($gate['identity'] ?? ''));
    }

    private static function execute($db, string $channel, ?array $command, $attachments, string $scope,
        ?array $draftRow, array $draft, string $identity, int $timestamp, ?callable $receive, array $message): array
    {
        $mode = $command['mode'] ?? 'append';
        $busy = '图文草稿正在处理或发布结果待核对，请先查看后台瞬间，再发送“取消瞬间”。';
        if ($mode === 'cancel') {
            if (($draft['state'] ?? '') === 'committing') { return self::failure('瞬间正在保存，暂时不能取消，请稍后查看后台瞬间。'); }
            return self::changeDraft($db, $scope, $draftRow, array())
                ? self::draftReply('已取消图文草稿，未发布瞬间。') : self::failure('草稿已变化，请发送新的取消指令。');
        }
        if (in_array($draft['state'] ?? '', array('publishing', 'committing', 'uncertain'), true)) { return self::failure($busy); }
        if (!is_array($attachments)) { return self::failure('图片附件格式无效，请重新发送图片。'); }
        try {
            $attachments = Enhancement_BotMomentsMediaHelper::normalize($channel, $attachments);
        } catch (Throwable $exception) {
            return self::failure('图片附件无效，仅支持官方聊天图片，单条最多 9 张。');
        }
        if ($mode === 'start' || $mode === 'create') {
            $body = $command['body'];
            try {
                list($content, $media) = self::content($body, $mode === 'start' || !empty($attachments));
            } catch (Typecho_Widget_Exception $exception) {
                return self::failure('请填写有效的文字或 Markdown 图片链接，最多 20000 字节，不支持 HTML 或脚本。');
            }
            if ($attachments && count($attachments) + count($media) > 9) { return self::failure('一条图文瞬间最多包含 9 张图片。'); }
            if ($mode === 'start') {
                if (count($attachments) + count($media) > 9) { return self::failure('一条图文瞬间最多包含 9 张图片。'); }
                $next = array('draft_id' => bin2hex(random_bytes(16)), 'identity' => $identity, 'created' => $timestamp, 'body' => $body, 'status' => $command['status'],
                    'attachments' => $attachments, 'state' => 'pending');
                return self::changeDraft($db, $scope, $draftRow, $next)
                    ? self::draftReply('已创建' . ($command['status'] === 'private' ? '私密' : '公开') . '图文草稿（15 分钟有效），已收 '
                        . count($attachments) . ' 张图片。请继续发图，最后发送“发布瞬间”；发送“取消瞬间”放弃。', $command['status'])
                    : self::failure('草稿已变化，请发送一条新的图文指令。');
            }
            try {
                $received = $attachments ? call_user_func($receive ?? array('Enhancement_BotMomentsMediaHelper', 'receive'), $channel, $attachments) : array();
            } catch (Throwable $exception) {
                return self::failure('图片下载或保存失败，未发布瞬间。请检查图片大小和存储配置后发送新消息重试。');
            }
            if (!self::stillAuthorized($channel, $message, $identity)) { return self::failure('发布权限或机器人绑定已变化，未发布瞬间。'); }
            Enhancement_MomentsHelper::ensureTable();
            return self::insertMoment($db, $channel, $content, array_merge($media, $received), $command['status'], $timestamp);
        }
        if (!$draft) { return self::failure('没有有效的图文草稿，请先发送“图文瞬间 正文”或“私密图文瞬间 正文”。'); }
        $unique = array();
        foreach (array_merge((array)($draft['attachments'] ?? array()), $attachments) as $attachment) {
            $unique[hash('sha256', serialize($attachment))] = $attachment;
        }
        $next = $draft;
        $next['attachments'] = array_values($unique);
        list($content, $media) = self::content((string)$draft['body'], true);
        if (count($next['attachments']) + count($media) > 9) { return self::failure('一条图文瞬间最多包含 9 张图片，草稿已保留。'); }
        if ($mode === 'append') {
            return self::changeDraft($db, $scope, $draftRow, $next)
                ? self::draftReply('图文草稿已收 ' . count($next['attachments']) . ' 张图片。继续发图，或发送“发布瞬间”完成。', $draft['status'])
                : self::failure('草稿已变化，请重新发送这条图片消息。');
        }
        if (!$next['attachments'] && !$media) { return self::failure('草稿还没有图片，请先发送图片，再发送“发布瞬间”。'); }
        $writing = $next;
        $writing['state'] = 'publishing';
        $writing['claim_id'] = bin2hex(random_bytes(16));
        if (!self::changeDraft($db, $scope, $draftRow, $writing)) { return self::failure($busy); }
        $claimedRow = array('value' => json_encode($writing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            $received = $next['attachments'] ? call_user_func($receive ?? array('Enhancement_BotMomentsMediaHelper', 'receive'), $channel, $next['attachments']) : array();
            Enhancement_MomentsHelper::ensureTable();
        } catch (Throwable $exception) {
            return self::failure(self::changeDraft($db, $scope, $claimedRow, $next)
                ? '图片下载或保存失败，草稿已保留。请检查图片大小和存储配置后，重新发送“发布瞬间”或“取消瞬间”。'
                : '图文草稿已取消或变化，未发布瞬间。');
        }
        if (!self::stillAuthorized($channel, $message, $identity)) {
            self::changeDraft($db, $scope, $claimedRow, array());
            return self::failure('发布权限或机器人绑定已变化，未发布瞬间。');
        }
        // A cancellation during downloads wins before the database insert can start.
        $committing = $writing;
        $committing['state'] = 'committing';
        if (!self::changeDraft($db, $scope, $claimedRow, $committing)) {
            return self::failure('图文草稿已取消或变化，未发布瞬间。');
        }
        $claimedRow['value'] = json_encode($committing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $result = self::insertMoment($db, $channel, $content, array_merge($media, $received), $draft['status'], $timestamp);
        } catch (Throwable $exception) {
            $committing['state'] = 'uncertain';
            self::changeDraft($db, $scope, $claimedRow, $committing);
            return self::failure('发布结果待核对，请先查看后台瞬间，确认后发送“取消瞬间”，勿直接重复发布。');
        }
        if (!self::changeDraft($db, $scope, $claimedRow, array())) { throw new RuntimeException('Published draft cleanup uncertain.'); }
        return $result;
    }

    public static function handle(string $channel, array $message, ?callable $receive = null): ?array
    {
        if (!in_array($channel, array('qq', 'weixin'), true)) { return null; }
        $config = Enhancement_Plugin::runtimeSettings();
        $settings = is_object($config) && method_exists($config, 'toArray') ? $config->toArray() : (array)$config;
        if (($settings[$channel . '_moments_enabled'] ?? '0') != '1' || !self::authorized($channel, $message, $settings)) { return null; }
        $text = $message['text'] ?? '';
        if (!is_string($text)) { return null; }
        $text = trim(str_replace(array("\r\n", "\r"), "\n", $text));
        $command = self::command($text);
        $attachments = $message['attachments'] ?? array();
        if ($command === null && empty($attachments)) { return null; }
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
        $draftRow = null;
        $draft = array();
        $scope = hash('sha256', serialize(array($channel, $message['account'], $message['conversation_type'] ?? '', $message['conversation'], $message['sender'])));
        $identity = hash('sha256', serialize($gate));
        if ($command === null || $command['mode'] !== 'create') {
            $draftRow = self::draftRow($db, $scope);
            $draft = $draftRow ? json_decode((string)$draftRow['value'], true) : array();
            $draft = is_array($draft) ? $draft : array();
            if ((($draft['identity'] ?? '') !== $identity && ($draft['state'] ?? '') !== 'committing')
                || (!in_array($draft['state'] ?? '', array('publishing', 'committing', 'uncertain'), true) && (int)($draft['created'] ?? 0) < $timestamp - 900)) {
                $draft = array();
            }
            // Ordinary pictures never create a moment or a draft by themselves.
            if ($command === null && (!$draft || $timestamp < (int)($draft['created'] ?? 0))) { return null; }
            if ($draft && $timestamp < (int)($draft['created'] ?? 0)) { return null; }
        }
        $table = $db->getPrefix() . 'bot_moments_receipts';
        $db->query('CREATE TABLE IF NOT EXISTS ' . $table . ' (request_id VARCHAR(64) PRIMARY KEY, fingerprint VARCHAR(64) NOT NULL, result TEXT, created INTEGER NOT NULL)', Typecho_Db::WRITE);
        $db->query($db->delete($table)->where('created < ?', time() - 30 * 86400));
        $requestId = hash('sha256', $channel . "\n" . $message['account'] . "\n" . $id);
        $fingerprintParts = array($message['sender'], $message['conversation'], $text, $timestamp);
        if (!empty($attachments)) { $fingerprintParts[] = $attachments; }
        $fingerprint = hash('sha256', serialize($fingerprintParts));
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
            $result = self::execute($db, $channel, $command, $attachments, $scope, $draftRow, $draft, $identity, $timestamp, $receive, $message);
        } catch (Throwable $exception) {
            // The insert may already have committed. Never execute this receipt again.
            $result = self::failure('发布结果待核对，请先查看后台瞬间，勿重复提交。');
        }
        $db->query($db->update($table)->rows(array('result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            ->where('request_id = ?', $requestId));
        return $result;
    }
}
