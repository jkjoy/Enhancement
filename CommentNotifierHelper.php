<?php

require_once __DIR__ . '/MailHelper.php';
require_once __DIR__ . '/CommentNotifier/TemplateHelper.php';

class Enhancement_CommentNotifierHelper
{
    private static function settings()
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        return is_object($settings) ? $settings : (object) array();
    }

    private static function notifierEnabled($settings): bool
    {
        return !isset($settings->enable_comment_notifier) || $settings->enable_comment_notifier == '1';
    }

    public static function getParent($comment): array
    {
        if (empty($comment->parent)) {
            return array();
        }

        try {
            $parent = Helper::widgetById('comments', $comment->parent);
        } catch (Exception $e) {
            return array();
        }

        if (!$parent) {
            return array();
        }

        return array(
            'name' => $parent->author,
            'mail' => $parent->mail,
        );
    }

    public static function getAuthor($comment): array
    {
        $plugin = self::settings();
        $db = Typecho_Db::get();
        $ae = $db->fetchRow($db->select()->from('table.users')->where('table.users.uid=?', $comment->ownerId));
        $mail = isset($ae['mail']) ? $ae['mail'] : '';
        if (empty($mail)) {
            $mail = isset($plugin->adminfrom) ? $plugin->adminfrom : '';
        }

        return array(
            'name' => isset($ae['screenName']) ? $ae['screenName'] : '',
            'mail' => $mail,
        );
    }

    public static function mark($comment, $edit, $status)
    {
        Enhancement_Plugin::commentByQQMark($comment, $edit, $status);

        $plugin = self::settings();
        if (!self::notifierEnabled($plugin)) {
            return;
        }

        $recipients = array();
        $from = isset($plugin->adminfrom) ? $plugin->adminfrom : '';
        if ($status == 'approved') {
            $type = 0;
            if ($edit->parent > 0) {
                $recipients[] = self::getParent($edit);
                $type = 1;
            } else {
                $recipients[] = self::getAuthor($edit);
            }

            $adminRecipient = self::getAdminRecipient($plugin);
            if (!empty($adminRecipient)) {
                self::sendMail($edit, array($adminRecipient), 0);
            }

            if (empty($recipients[0]['mail']) || $recipients[0]['mail'] == $edit->mail || $recipients[0]['mail'] == $from) {
                return;
            }

            self::sendMail($edit, $recipients, $type);
        }
    }

    public static function refinishComment($comment)
    {
        $plugin = self::settings();
        if (!self::notifierEnabled($plugin)) {
            return;
        }

        $from = isset($plugin->adminfrom) ? $plugin->adminfrom : '';
        $fromName = isset($plugin->fromName) ? $plugin->fromName : '';
        $recipients = array();

        if ($comment->status == 'approved') {
            $type = 0;
            $author = self::getAuthor($comment);
            if ($comment->authorId != $comment->ownerId && $comment->mail != $author['mail']) {
                $recipients[] = $author;
            }

            if ($comment->parent) {
                $type = 1;
                $parent = self::getParent($comment);
                if (!empty($parent) && $parent['mail'] != $from && $parent['mail'] != $comment->mail) {
                    $recipients[] = $parent;
                }
            }

            $adminRecipient = self::getAdminRecipient($plugin);
            if (!empty($adminRecipient)) {
                self::sendMail($comment, array($adminRecipient), 0);
            }

            self::sendMail($comment, $recipients, $type);
        } else {
            if (!empty($from)) {
                $recipients[] = array('name' => $fromName, 'mail' => $from);
                self::sendMail($comment, $recipients, 2);
            }
        }
    }

    private static function getAdminRecipient($plugin): array
    {
        if (isset($plugin->zznotice) && $plugin->zznotice == 1) {
            return array();
        }

        $mail = isset($plugin->adminfrom) ? trim((string)$plugin->adminfrom) : '';
        if ($mail === '') {
            return array();
        }

        $name = isset($plugin->fromName) ? trim((string)$plugin->fromName) : '';
        return array(
            'name' => $name !== '' ? $name : $mail,
            'mail' => $mail
        );
    }

    private static function sendMail($comment, array $recipients, $type)
    {
        if (empty($recipients)) {
            return;
        }

        $title = html_entity_decode($comment->title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($type == 1) {
            $subject = '你在[' . $title . ']的评论有了新的回复';
        } elseif ($type == 2) {
            $subject = '文章《' . $title . '》有条待审评论';
        } else {
            $subject = '你的《' . $title . '》文章有了新的评论';
        }

        $options = Typecho_Widget::widget('Widget_Options');
        foreach ($recipients as $recipient) {
            if (empty($recipient['mail'])) {
                continue;
            }

            $param = array(
                'to' => $recipient['mail'],
                'fromName' => $recipient['name'],
                'subject' => $subject,
                'html' => self::mailBody($comment, $options, $type)
            );
            self::resendMail($param);
        }
    }

    public static function resendMail($param)
    {
        $plugin = self::settings();
        if (!self::notifierEnabled($plugin)) {
            return;
        }

        if (isset($plugin->yibu) && $plugin->yibu == 1) {
            Helper::requestService('send', $param);
        } else {
            self::send($param);
        }
    }

    public static function send($param)
    {
        $plugin = self::settings();
        if (!self::notifierEnabled($plugin)) {
            return;
        }

        self::zemail($param);
    }

    public static function zemail($param)
    {
        $plugin = self::settings();
        $flag = true;

        try {
            if (empty($plugin->from) || empty($plugin->fromName)) {
                return false;
            }

            $mail = Enhancement_MailHelper::createMailer($plugin);
            $mail->addAddress($param['to'], $param['fromName']);
            $mail->Subject = $param['subject'];
            $mail->Body = $param['html'];
            $mail->send();

            if ($mail->isError()) {
                $flag = false;
            }

            if (!empty($plugin->log)) {
                $at = date('Y-m-d H:i:s');
                if ($mail->isError()) {
                    $data = $at . ' ' . $mail->ErrorInfo;
                } else {
                    $data = PHP_EOL . $at . ' 发送成功! ';
                    $data .= ' 发件人:' . $plugin->fromName;
                    $data .= ' 发件邮箱:' . $plugin->from;
                    $data .= ' 接收人:' . $param['fromName'];
                    $data .= ' 接收邮箱:' . $param['to'] . PHP_EOL;
                }

                self::writeLog($data);
            }
        } catch (Throwable $e) {
            $flag = false;
            if (!empty($plugin->log)) {
                $str = "\nerror time: " . date('Y-m-d H:i:s') . "\n";
                self::writeLog($str . '邮件发送失败：' . get_class($e) . "\n");
            }
        }

        return $flag;
    }

    private static function writeLog($message)
    {
        // Keep recipient addresses out of files served directly by the web server.
        $key = hash('sha256', __DIR__);
        $path = rtrim(sys_get_temp_dir(), '/\\') . '/enhancement-mail-' . $key . '.log';
        if (is_link($path)) {
            return;
        }
        $handle = @fopen($path, 'c+');
        if (!$handle) {
            return;
        }
        @chmod($path, 0600);
        if (@flock($handle, LOCK_EX)) {
            $stat = fstat($handle);
            if (is_array($stat) && $stat['size'] > 1048576) {
                ftruncate($handle, 0);
            }
            fseek($handle, 0, SEEK_END);
            fwrite($handle, (string)$message);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    private static function mailBody($comment, $options, $type): string
    {
        $plugin = self::settings();
        $commentAt = new Typecho_Date($comment->created);
        $commentAt = $commentAt->format('Y-m-d H:i:s');
        $commentText = isset($comment->content) ? $comment->content : (isset($comment->text) ? $comment->text : '');
        $html = 'owner';
        if ($type == 1) {
            $html = 'guest';
        } elseif ($type == 2) {
            $html = 'notice';
        }

        $Pmail = '';
        $Pname = '';
        $Ptext = '';
        $Pmd5 = '';
        if ($comment->parent) {
            try {
                $parent = Helper::widgetById('comments', $comment->parent);
                $Pname = $parent->author;
                $Ptext = $parent->content;
                $Pmail = $parent->mail;
                $Pmd5 = md5($parent->mail);
            } catch (Exception $e) {
            }
        }

        $commentMail = isset($comment->mail) ? $comment->mail : '';
        $avatarUrl = Enhancement_Plugin::buildAvatarUrl($commentMail, 40, 'monsterid');
        $PavatarUrl = Enhancement_Plugin::buildAvatarUrl($Pmail, 40, 'monsterid');

        $postAuthor = '';
        try {
            $post = Helper::widgetById('Contents', $comment->cid);
            $postAuthor = $post->author->screenName;
        } catch (Exception $e) {
        }

        if (isset($plugin->biaoqing) && is_callable($plugin->biaoqing)) {
            $parseBiaoQing = $plugin->biaoqing;
            $commentText = $parseBiaoQing($commentText);
            $Ptext = $parseBiaoQing($Ptext);
        }

        $commentText = Enhancement_MailHelper::sanitizeHtml($commentText);
        $Ptext = Enhancement_MailHelper::sanitizeHtml($Ptext);

        $content = self::getTemplate($html);
        $content = preg_replace('#<\\?php#', '<!--', $content);
        $content = preg_replace('#\\?>#', '-->', $content);

        $template = !empty($plugin->template) ? $plugin->template : 'default';
        $status = array(
            'approved' => '通过',
            'waiting' => '待审',
            'spam' => '垃圾',
        );
        return strtr($content, array(
            '{title}' => Enhancement_MailHelper::escape($comment->title),
            '{PostAuthor}' => Enhancement_MailHelper::escape($postAuthor),
            '{time}' => Enhancement_MailHelper::escape($commentAt),
            '{commentText}' => $commentText,
            '{author}' => Enhancement_MailHelper::escape($comment->author),
            '{mail}' => Enhancement_MailHelper::escape($commentMail),
            '{md5}' => md5($commentMail),
            '{avatar}' => Enhancement_MailHelper::escape(Enhancement_MailHelper::safeUrl($avatarUrl)),
            '{ip}' => Enhancement_MailHelper::escape($comment->ip),
            '{permalink}' => Enhancement_MailHelper::escape(Enhancement_MailHelper::safeUrl($comment->permalink)),
            '{siteUrl}' => Enhancement_MailHelper::escape(Enhancement_MailHelper::safeUrl($options->siteUrl)),
            '{siteTitle}' => Enhancement_MailHelper::escape($options->title),
            '{Pname}' => Enhancement_MailHelper::escape($Pname),
            '{Ptext}' => $Ptext,
            '{Pmail}' => Enhancement_MailHelper::escape($Pmail),
            '{Pmd5}' => $Pmd5,
            '{Pavatar}' => Enhancement_MailHelper::escape(Enhancement_MailHelper::safeUrl($PavatarUrl)),
            '{url}' => Enhancement_MailHelper::escape(Enhancement_MailHelper::safeUrl($options->pluginUrl . '/Enhancement/CommentNotifier/template/' . $template . '/')),
            '{manageurl}' => Enhancement_MailHelper::escape(Enhancement_MailHelper::safeUrl($options->adminUrl . '/manage-comments.php')),
            '{status}' => Enhancement_MailHelper::escape(isset($status[$comment->status]) ? $status[$comment->status] : $comment->status),
        ));
    }

    private static function getTemplate($template = 'owner')
    {
        $template .= '.html';
        $templateDir = self::configStr('template', 'default');
        try {
            return Enhancement_CommentNotifier_TemplateHelper::read($templateDir, $template, true);
        } catch (RuntimeException $error) {
            return Enhancement_CommentNotifier_TemplateHelper::read('default', $template);
        }
    }

    public static function configStr(string $key, $default = '', string $method = 'empty'): string
    {
        $settings = self::settings();
        $value = isset($settings->$key) ? $settings->$key : null;
        if ($method === 'empty') {
            return empty($value) ? $default : $value;
        }

        return call_user_func($method, $value) ? $default : $value;
    }
}
