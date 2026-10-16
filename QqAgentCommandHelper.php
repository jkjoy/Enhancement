<?php

/** Finite blog operations invoked by a trusted independent QQ service or Agent. */
class Enhancement_QqAgentCommandHelper
{
    public static function handle($action)
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        $status = 200;
        $body = array();
        try {
            if (!Enhancement_QqAgentHelper::commandsSupported($settings) || ($settings->qq_agent_commands ?? '0') !== '1') {
                throw new Typecho_Widget_Exception('commands_disabled', 404);
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                header('Allow: POST');
                throw new Typecho_Widget_Exception('post_required', 405);
            }
            if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
                throw new Typecho_Widget_Exception('json_required', 415);
            }
            $raw = file_get_contents('php://input', false, null, 0, 16385);
            if (!is_string($raw) || strlen($raw) > 16384) {
                throw new Typecho_Widget_Exception('body_too_large', 413);
            }
            $timestamp = $_SERVER['HTTP_X_ENHANCEMENT_TIMESTAMP'] ?? '';
            $signature = $_SERVER['HTTP_X_ENHANCEMENT_SIGNATURE'] ?? '';
            $secret = (string)($settings->qq_agent_command_secret ?? '');
            if (strlen($secret) < 32 || !preg_match('/\A[0-9]{1,12}\z/D', $timestamp) || abs(time() - (int)$timestamp) > 300
                || !preg_match('/\A[a-f0-9]{64}\z/D', $signature)
                || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $raw, $secret), $signature)) {
                throw new Typecho_Widget_Exception('invalid_signature', 401);
            }
            $request = json_decode($raw, true);
            if (!is_array($request) || !is_string($request['request_id'] ?? null)
                || !preg_match('/\A[a-f0-9]{64}\z/D', $request['request_id'])
                || !is_string($request['sender'] ?? null) || !is_string($request['command'] ?? null)
                || !is_array($request['args'] ?? null)) {
                throw new Typecho_Widget_Exception('invalid_request', 400);
            }
            if (!in_array($request['sender'], Enhancement_QqAgentHelper::allowedSenders($settings), true)) {
                throw new Typecho_Widget_Exception('sender_not_allowed', 403);
            }
            $db = Typecho_Db::get();
            $admin = $db->fetchRow($db->select()->from('table.users')->where('uid = ?', (int)($settings->qq_agent_admin_uid ?? 0))->limit(1));
            if (!$admin || $admin['group'] !== 'administrator') {
                throw new Typecho_Widget_Exception('administrator_unavailable', 403);
            }
            self::validateCommand($request['command'], $request['args']);
            $table = $db->getPrefix() . 'qq_agent_commands';
            $db->query('CREATE TABLE IF NOT EXISTS ' . $table . ' ('
                . 'request_id VARCHAR(64) PRIMARY KEY, fingerprint VARCHAR(64) NOT NULL, result TEXT, created INTEGER NOT NULL)', Typecho_Db::WRITE);
            // Retain receipts for 30 days. An uncertain write is never automatically re-run.
            $db->query($db->delete($table)->where('created < ?', time() - 30 * 86400));
            $fingerprint = hash('sha256', $raw);
            $stored = $db->fetchRow($db->select()->from($table)->where('request_id = ?', $request['request_id'])->limit(1));
            if (!$stored) {
                try {
                    $db->query($db->insert($table)->rows(array('request_id' => $request['request_id'], 'fingerprint' => $fingerprint, 'created' => time())));
                } catch (Exception $e) {
                    $stored = $db->fetchRow($db->select()->from($table)->where('request_id = ?', $request['request_id'])->limit(1));
                    if (!$stored) { throw $e; }
                }
            }
            if ($stored) {
                if (!hash_equals($stored['fingerprint'], $fingerprint)) {
                    throw new Typecho_Widget_Exception('request_id_conflict', 409);
                }
                $receipt = json_decode((string)($stored['result'] ?? ''), true);
                if (!is_array($receipt) || !isset($receipt['status'], $receipt['body'])) {
                    throw new Typecho_Widget_Exception('command_in_progress_or_uncertain', 409);
                }
                $status = $receipt['status'];
                $body = $receipt['body'];
                $body['replayed'] = true;
            } else {
                try {
                    $body = array('ok' => true, 'request_id' => $request['request_id'],
                        'result' => self::execute($request['command'], $request['args'], $admin, $action));
                } catch (Throwable $e) {
                    $status = in_array((int)$e->getCode(), array(400, 403, 404, 409), true) ? (int)$e->getCode() : 500;
                    $body = array('ok' => false, 'error' => $status === 500 ? 'command_failed_check_blog_before_retry' : $e->getMessage());
                }
                $db->query($db->update($table)->rows(array('result' => json_encode(array('status' => $status, 'body' => $body), JSON_INVALID_UTF8_SUBSTITUTE)))
                    ->where('request_id = ?', $request['request_id']));
            }
        } catch (Throwable $e) {
            $status = in_array((int)$e->getCode(), array(400, 401, 403, 404, 405, 409, 413, 415), true) ? (int)$e->getCode() : 503;
            $body = array('ok' => false, 'error' => $status === 503 ? 'command_storage_unavailable' : $e->getMessage());
        }
        Typecho_Response::getInstance()->setStatus($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function validateCommand(string $command, array $args)
    {
        $schemas = array('status' => array(), 'comments.list' => array('status', 'limit'),
            'comment.approve' => array('id'), 'comment.waiting' => array('id'), 'comment.spam' => array('id'),
            'comment.reply' => array('id', 'text'), 'links.list' => array('state', 'limit'),
            'link.approve' => array('id'), 'link.reject' => array('id'));
        if (!isset($schemas[$command]) || array_diff(array_keys($args), $schemas[$command])) {
            throw new Typecho_Widget_Exception('unsupported_command_or_arguments', 400);
        }
        if (strpos($command, 'comment.') === 0 || strpos($command, 'link.') === 0) {
            if (!is_int($args['id'] ?? null) || $args['id'] < 1 || $args['id'] > 2147483647) {
                throw new Typecho_Widget_Exception('invalid_id', 400);
            }
        }
        if (isset($args['limit']) && (!is_int($args['limit']) || $args['limit'] < 1 || $args['limit'] > 20)) {
            throw new Typecho_Widget_Exception('invalid_limit', 400);
        }
        if (isset($args['status']) && !in_array($args['status'], array('approved', 'waiting', 'spam'), true)) {
            throw new Typecho_Widget_Exception('invalid_status', 400);
        }
        if (isset($args['state']) && !in_array($args['state'], array('0', '1'), true)) {
            throw new Typecho_Widget_Exception('invalid_state', 400);
        }
        if ($command === 'comment.reply') {
            $text = $args['text'] ?? null;
            if (!is_string($text) || trim($text) === '' || strlen($text) > 4000 || !preg_match('//u', $text)
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text) || $text !== strip_tags($text)) {
                throw new Typecho_Widget_Exception('reply_requires_plain_text_up_to_4000_bytes', 400);
            }
            try {
                Enhancement_CommentGuardContentHelper::validate(array('text' => $text), (object)array(
                    'comment_guard_block_xss' => '1', 'comment_guard_block_html' => '1'
                ));
            } catch (Typecho_Widget_Exception $e) {
                throw new Typecho_Widget_Exception('reply_contains_unsafe_markup', 400);
            }
        }
    }

    private static function execute(string $command, array $args, array $admin, $action): array
    {
        $db = Typecho_Db::get();
        if ($command === 'status') {
            $counts = array();
            foreach (array('approved', 'waiting', 'spam') as $state) {
                $row = $db->fetchRow($db->select(array('COUNT(coid)' => 'total'))->from('table.comments')->where('status = ?', $state));
                $counts[$state] = (int)$row['total'];
            }
            return array('comments' => $counts, 'version' => Enhancement_Plugin::getPluginVersion());
        }
        if ($command === 'comments.list') {
            $rows = $db->fetchAll($db->select('coid', 'cid', 'author', 'text', 'status', 'created')->from('table.comments')
                ->where('status = ?', $args['status'] ?? 'waiting')->order('coid', Typecho_Db::SORT_DESC)->limit($args['limit'] ?? 10));
            foreach ($rows as &$row) {
                $row['text'] = Typecho_Common::subStr(strip_tags($row['text']), 0, 500, '…');
                $row['author'] = strip_tags($row['author']);
            }
            return array('comments' => $rows);
        }
        if ($command === 'links.list') {
            return array('links' => $db->fetchAll($db->select('lid', 'name', 'url', 'description', 'state')->from($db->getPrefix() . 'links')
                ->where('state = ?', $args['state'] ?? '0')->order('lid', Typecho_Db::SORT_DESC)->limit($args['limit'] ?? 10)));
        }
        if (strpos($command, 'link.') === 0) {
            return $action->reviewAgentLink($args['id'], $command === 'link.approve' ? '1' : '0');
        }
        $comment = $db->fetchRow($db->select()->from('table.comments')->where('coid = ?', $args['id'])->limit(1));
        if (!$comment || $comment['type'] !== 'comment') {
            throw new Typecho_Widget_Exception('comment_not_found', 404);
        }
        // Request-only identity; no browser login cookies are issued.
        if (!Typecho_Widget::widget('Widget_User')->simpleLogin($admin)) {
            throw new Typecho_Widget_Exception('administrator_unavailable', 403);
        }
        $editor = Typecho_Widget::widget('Widget_Comments_Edit@EnhancementQQAgent');
        $editor->push($comment);
        if ($command !== 'comment.reply') {
            $state = substr($command, strlen('comment.'));
            if ($state === 'approve') { $state = 'approved'; }
            $changed = $comment['status'] !== $state;
            if ($changed) {
                Typecho_Plugin::factory('Widget_Comments_Edit')->mark($comment, $editor, $state);
                $editor->update(array('status' => $state), $db->sql()->where('coid = ?', $args['id']));
            }
            return array('id' => $args['id'], 'status' => $state, 'changed' => $changed);
        }
        if ($comment['status'] !== 'approved') {
            throw new Typecho_Widget_Exception('approve_comment_before_reply', 409);
        }
        $reply = array('cid' => $comment['cid'], 'ownerId' => $comment['ownerId'], 'authorId' => $admin['uid'],
            'author' => $admin['screenName'] ?: $admin['name'], 'mail' => $admin['mail'], 'url' => $admin['url'],
            'parent' => $args['id'], 'text' => trim($args['text']), 'status' => 'approved', 'type' => 'comment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->comment($reply, $editor);
        $id = $editor->insert($reply);
        $editor->push($db->fetchRow($db->select()->from('table.comments')->where('coid = ?', $id)->limit(1)));
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment($editor);
        return array('id' => (int)$id, 'parent' => $args['id'], 'status' => 'approved');
    }
}
