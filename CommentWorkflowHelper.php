<?php

class Enhancement_CommentWorkflowHelper
{
    public static function finishComment($comment)
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        $user = Typecho_Widget::widget('Widget_User');
        $commentUrl = isset($comment->url) ? trim((string)$comment->url) : '';

        if (!isset($settings->enable_comment_sync) || $settings->enable_comment_sync == '1') {
            self::syncCommentIdentity($comment, $user, $commentUrl);
        }

        if ((isset($settings->enable_comment_by_qq) && $settings->enable_comment_by_qq == '1')
            || (isset($settings->enable_comment_by_weixin) && $settings->enable_comment_by_weixin == '1')) {
            Enhancement_Plugin::commentByQQ($comment);
        }

        if (isset($settings->enable_comment_notifier) && $settings->enable_comment_notifier == '1') {
            Enhancement_Plugin::commentNotifierRefinishComment($comment);
        }

        return $comment;
    }

    private static function syncCommentIdentity($comment, $user, $commentUrl)
    {
        $db = Typecho_Db::get();

        if (!$user->hasLogin()) {
            if (!empty($commentUrl)) {
                $update = $db->update('table.comments')
                    ->rows(array('url' => $commentUrl))
                    ->where('ip =? and mail =? and authorId =?', $comment->ip, $comment->mail, '0');
                $db->query($update);
            }
            return;
        }

        $userUrl = isset($user->url) ? trim((string)$user->url) : '';
        $update = $db->update('table.comments')
            ->rows(array('url' => $userUrl, 'mail' => $user->mail, 'author' => $user->screenName))
            ->where('authorId =?', $user->uid);
        $db->query($update);
    }
}
