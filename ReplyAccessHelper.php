<?php

/** Guest reply access is proof of a completed comment, never of a remembered name. */
class Enhancement_ReplyAccessHelper
{
    const COOKIE_NAME = '__enhancement_reply_access';
    const LIFETIME = 2592000;
    const MAX_COMMENTS = 24;

    public static function rememberComment($comment)
    {
        if (!($comment instanceof Widget_Feedback) || !Typecho_Request::getInstance()->isPost()) {
            return;
        }
        $user = Typecho_Widget::widget('Widget_User');
        if ($user->hasLogin() || (int)$comment->authorId !== 0 || $comment->type !== 'comment'
            || !in_array($comment->status, array('approved', 'waiting'), true)) {
            return;
        }
        $coid = (int)$comment->coid;
        $cid = (int)$comment->cid;
        $secret = self::secret();
        if ($coid <= 0 || $cid <= 0 || $secret === '') {
            return;
        }
        $entries = self::verifiedComments();
        $entries = array_values(array_filter($entries, function ($entry) use ($coid) {
            return $entry[0] !== $coid;
        }));
        $entries[] = array($coid, $cid, time() + self::LIFETIME, bin2hex(random_bytes(16)));
        $entries = array_slice($entries, -self::MAX_COMMENTS);
        $payload = rtrim(strtr(base64_encode(json_encode($entries)), '+/', '-_'), '=');
        $token = $payload . '.' . hash_hmac('sha256', $payload, $secret);
        $name = Typecho_Cookie::getPrefix() . self::COOKIE_NAME;
        $_COOKIE[$name] = $token;
        Typecho_Response::getInstance()->setCookie($name, $token, self::LIFETIME,
            Typecho_Cookie::getPath(), Typecho_Cookie::getDomain(),
            Typecho_Request::getInstance()->isSecure(), true);
    }

    public static function canAccess($cid): bool
    {
        $cid = (int)$cid;
        if ($cid <= 0) {
            return false;
        }
        try {
            $user = Typecho_Widget::widget('Widget_User');
            $uid = $user->hasLogin() ? (int)$user->uid : 0;
            $commentIds = array();
            if ($uid <= 0) {
                foreach (self::verifiedComments() as $entry) {
                    if ($entry[1] === $cid) {
                        $commentIds[] = $entry[0];
                    }
                }
                if (!$commentIds) {
                    return false;
                }
            }
            static $cache = array();
            $cacheKey = $cid . ':' . $uid . ':' . implode(',', $commentIds);
            if (array_key_exists($cacheKey, $cache)) {
                return $cache[$cacheKey];
            }
            $db = Typecho_Db::get();
            $query = $db->select('coid')->from('table.comments')
                ->where('cid = ?', $cid)->where('type = ?', 'comment')
                ->where('status = ?', 'approved')->where('authorId = ?', $uid)->limit(1);
            if ($uid <= 0) {
                $query->where('coid IN ?', $commentIds);
            }
            return $cache[$cacheKey] = (bool)$db->fetchRow($query);
        } catch (Exception $e) {
            return false;
        }
    }

    private static function verifiedComments(): array
    {
        $token = (string)Typecho_Cookie::get(self::COOKIE_NAME);
        if ($token === '' || strlen($token) > 4096 || substr_count($token, '.') !== 1) {
            return array();
        }
        list($payload, $signature) = explode('.', $token, 2);
        $secret = self::secret();
        if ($secret === '' || !hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            return array();
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $entries = $json === false ? null : json_decode($json, true);
        if (!is_array($entries) || count($entries) > self::MAX_COMMENTS) {
            return array();
        }
        $valid = array();
        $now = time();
        foreach ($entries as $entry) {
            if (is_array($entry) && count($entry) === 4 && isset($entry[0], $entry[1], $entry[2], $entry[3])
                && is_int($entry[0]) && is_int($entry[1]) && is_int($entry[2])
                && is_string($entry[3]) && preg_match('/^[a-f0-9]{32}$/D', $entry[3])
                && $entry[0] > 0 && $entry[1] > 0 && $entry[2] > $now
                && $entry[2] <= $now + self::LIFETIME) {
                $valid[] = $entry;
            }
        }
        return $valid;
    }

    private static function secret(): string
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $secret = (string)$options->secret;
        return $secret === '' ? '' : hash_hmac('sha256', 'enhancement-reply-access-v1|' . $options->siteUrl, $secret);
    }
}
