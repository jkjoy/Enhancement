<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// Feedback finishes by redirecting. Keep its validation, insertion and plugin
// callbacks, while allowing the REST controller to send the created JSON row.
if (class_exists('Typecho\\Widget\\Response')) {
    class Enhancement_RestApi_CommentResponse extends \Typecho\Widget\Response
    {
        public function redirect(string $location, bool $isPermanently = false)
        {
        }
    }
} else {
    class Enhancement_RestApi_CommentResponse extends Typecho_Response
    {
        public function redirect($location, $isPermanently = false)
        {
        }
    }
}

class Enhancement_RestApi_CommentHelper
{
    /** Public comments never expose moderation queues or protected content. */
    public static function query($request, $count = false)
    {
        $db = Typecho_Db::get();
        $options = Typecho_Widget::widget('Widget_Options');
        $select = $count ? $db->select(array('COUNT(table.comments.coid)' => 'cnt'))
            : $db->select('table.comments.*');
        $select->from('table.comments')
            ->join('table.contents', 'table.comments.cid = table.contents.cid')
            ->where('table.comments.type = ?', 'comment')
            ->where('table.comments.status = ?', 'approved')
            ->where('table.contents.status = ?', 'publish')
            ->where('table.contents.type IN (?, ?)', 'post', 'page')
            ->where('table.contents.created <= ?', $options->time)
            ->where('(table.contents.password IS NULL OR table.contents.password = ?)', '');

        $status = strtolower(trim((string)$request->get('status', 'approved')));
        if (!in_array($status, array('', 'approve', 'approved', 'any', 'all'), true)) {
            $select->where('table.comments.coid = ?', 0);
        }
        $postId = (int)$request->get('post', 0);
        if ($postId > 0) {
            $select->where('table.comments.cid = ?', $postId);
        }
        $parent = $request->get('parent');
        if ($parent !== null && $parent !== '') {
            $select->where('table.comments.parent = ?', (int)$parent);
        }
        return $select;
    }

    /** Return the wp/v2 public representation through Typecho's HTML filters. */
    public static function format(array $row)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $base = rtrim($options->index, '/') . '/wp-json/wp/v2';
        $id = (int)$row['coid'];
        $postId = (int)$row['cid'];
        $widget = Typecho_Widget::widget('Widget_Abstract_Comments@enhancement-rest-api-comment-' . $id);
        $widget->push($row);
        $post = Typecho_Db::get()->fetchRow(Typecho_Db::get()->select()->from('table.contents')
            ->where('cid = ?', $postId)->limit(1));
        $postType = $post && $post['type'] === 'page' ? 'page' : 'post';
        $avatar = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim((string)$row['mail'])));
        $statuses = array('approved' => 'approved', 'waiting' => 'hold', 'spam' => 'spam');
        $status = isset($statuses[$row['status']]) ? $statuses[$row['status']] : $row['status'];
        return array(
            'id' => $id,
            'post' => $postId,
            'parent' => (int)$row['parent'],
            'author' => (int)$row['authorId'],
            'author_name' => $row['author'],
            'author_url' => $widget->url,
            'date' => gmdate('Y-m-d\\TH:i:s', (int)$row['created'] + (int)$options->timezone),
            'date_gmt' => gmdate('Y-m-d\\TH:i:s', (int)$row['created']),
            'content' => array('rendered' => (string)$widget->content),
            'link' => (string)$widget->permalink,
            'status' => $status,
            'type' => 'comment',
            'author_avatar_urls' => array(
                '24' => $avatar . '?s=24&d=mm&r=g',
                '48' => $avatar . '?s=48&d=mm&r=g',
                '96' => $avatar . '?s=96&d=mm&r=g',
            ),
            'meta' => (object)array(),
            '_links' => array(
                'self' => array(array('href' => $base . '/comments/' . $id)),
                'collection' => array(array('href' => $base . '/comments')),
                'up' => array(array('embeddable' => true, 'post_type' => $postType,
                    'href' => $base . '/' . ($postType === 'page' ? 'pages' : 'posts') . '/' . $postId)),
            ),
        );
    }

    /** Run the normal feedback action so moderation, spam checks and hooks agree. */
    public static function create($request, array $payload)
    {
        $db = Typecho_Db::get();
        $options = Typecho_Widget::widget('Widget_Options');
        $postId = filter_var(self::parameter($request, $payload, 'post', 0), FILTER_VALIDATE_INT);
        if (!$postId || $postId < 1) {
            throw new Typecho_Widget_Exception(_t('无效的文章 ID。'), 400);
        }
        $post = $db->fetchRow($db->select()->from('table.contents')->where('cid = ?', $postId)->limit(1));
        if (!$post || $post['status'] !== 'publish' || !in_array($post['type'], array('post', 'page'), true)
            || (int)$post['created'] > $options->time || (isset($post['password']) && (string)$post['password'] !== '')) {
            throw new Typecho_Widget_Exception(_t('找不到公开文章。'), 404);
        }
        if (empty($post['allowComment'])) {
            throw new Typecho_Widget_Exception(_t('此内容已关闭评论。'), 403);
        }
        $parent = filter_var(self::parameter($request, $payload, 'parent', 0), FILTER_VALIDATE_INT);
        if ($parent === false || $parent < 0) {
            throw new Typecho_Widget_Exception(_t('无效的父级评论。'), 400);
        }
        if ($parent > 0 && !$db->fetchRow($db->select('coid')->from('table.comments')
            ->where('coid = ? AND cid = ? AND type = ? AND status = ?', $parent, $postId, 'comment', 'approved')
            ->limit(1))) {
            throw new Typecho_Widget_Exception(_t('父级评论不存在。'), 400);
        }

        $content = isset($payload['content']) ? $payload['content'] : $request->get('content', '');
        if (is_array($content)) {
            $content = isset($content['rendered']) ? $content['rendered'] : (isset($content['raw']) ? $content['raw'] : '');
        }
        if (!is_string($content) || trim($content) === '') {
            throw new Typecho_Widget_Exception(_t('必须填写评论内容。'), 400);
        }
        $contentWidget = Typecho_Widget::widget('Widget_Abstract_Contents@enhancement-rest-api-comment-post-' . $postId);
        $contentWidget->push($post);
        $mapped = array(
            'type' => 'comment',
            'permalink' => $contentWidget->path,
            'text' => $content,
            'author' => self::parameter($request, $payload, 'author_name'),
            'mail' => self::parameter($request, $payload, 'author_email'),
            'url' => self::parameter($request, $payload, 'author_url'),
            'parent' => $parent,
            '_' => self::parameter($request, $payload, '_'),
            'cf-turnstile-response' => self::parameter($request, $payload, 'cf-turnstile-response'),
        );
        $http = Typecho_Request::getInstance();
        $security = Typecho_Widget::widget('Widget_Security');
        if ($options->commentsAntiSpam) {
            $expected = $security->getToken($http->getReferer());
            if (Typecho_Widget::widget('Widget_User')->hasLogin()) {
                // Cookie-authenticated submissions retain Typecho's CSRF check.
                if (!hash_equals($expected, (string)$mapped['_'])) {
                    throw new Typecho_Widget_Exception(_t('评论安全校验失败，请提供有效的 Typecho 评论令牌。'), 403);
                }
            } else {
                // Anonymous API clients have no account privileges. The public
                // form handshake is internal; referer, interval and spam hooks remain active.
                $mapped['_'] = $expected;
            }
        }

        $savedGet = $_GET;
        $savedPost = $_POST;
        $hadAgent = array_key_exists('HTTP_USER_AGENT', $_SERVER);
        $savedAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
        if ($savedAgent === null) {
            // Typecho's comment insertion expects a string, including for API clients.
            $_SERVER['HTTP_USER_AGENT'] = '';
        }
        $_GET = array();
        $_POST = $mapped;
        $sandbox = method_exists($http, 'beginSandbox');
        try {
            if ($sandbox) {
                // Existing plugins such as Turnstile read the global request.
                $http->beginSandbox(new Typecho_Config($mapped));
            }
            if (class_exists('Typecho\\Widget\\Request')) {
                $feedbackRequest = new \Typecho\Widget\Request($http, new Typecho_Config($mapped));
                $response = new Enhancement_RestApi_CommentResponse($http, Typecho_Response::getInstance());
            } else {
                $feedbackRequest = new Typecho_Request();
                $feedbackRequest->setParams($mapped);
                $response = new Enhancement_RestApi_CommentResponse();
            }
            $feedback = new Widget_Feedback($feedbackRequest, $response);
            $feedback->execute();
            $feedback->action();
            $id = (int)$feedback->coid;
        } finally {
            if ($sandbox) {
                $http->endSandbox();
            }
            $_GET = $savedGet;
            $_POST = $savedPost;
            if (!$hadAgent) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $savedAgent;
            }
        }
        $created = $id ? $db->fetchRow($db->select()->from('table.comments')->where('coid = ?', $id)->limit(1)) : array();
        if (!$created) {
            throw new Typecho_Widget_Exception(_t('评论提交失败。'), 400);
        }
        return $created;
    }

    private static function parameter($request, array $payload, $key, $default = '')
    {
        $value = array_key_exists($key, $payload) ? $payload[$key] : $request->get($key, $default);
        if (!is_scalar($value) && $value !== null) {
            throw new Typecho_Widget_Exception(_t('评论参数格式错误。'), 400);
        }
        return $value === null ? $default : $value;
    }
}
