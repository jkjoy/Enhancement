<?php
require_once dirname(__DIR__) . '/MailHelper.php';

class Enhancement_CommentNotifier_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function execute()
    {
        // no-op
    }

    public function action($data = "")
    {
        if (class_exists('Enhancement_Plugin') && method_exists('Enhancement_Plugin', 'runtimeSettings')) {
            $plugin = Enhancement_Plugin::runtimeSettings();
        } else {
            $plugin = (object) array();
        }

        $this->response->setHeader('Cache-Control', 'no-store');
        if (!$this->request->isPost()) {
            $this->response->setStatus(405)->setHeader('Allow', 'POST');
            return;
        }
        $expected = is_object($plugin) && isset($plugin->auth) ? (string)$plugin->auth : '';
        $provided = $this->request->get('auth');
        if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)
            || (isset($plugin->enable_comment_notifier) && (string)$plugin->enable_comment_notifier !== '1')) {
            $this->response->setStatus(403);
            return;
        }
        $param = array();
        foreach (array('to', 'fromName', 'subject', 'html') as $key) {
            $value = $this->request->get($key, '');
            if (!is_string($value) || strlen($value) > ($key === 'html' ? 1048576 : 1000)) {
                $this->response->setStatus(400);
                return;
            }
            $param[$key] = $value;
        }
        if (!filter_var($param['to'], FILTER_VALIDATE_EMAIL)) {
            $this->response->setStatus(400);
            return;
        }
        try {
            $mail = Enhancement_MailHelper::createMailer($plugin);
            $mail->addAddress($param['to'], $param['fromName']);
            $mail->Subject = $param['subject'];
            $mail->Body = $param['html'];
            $sent = $mail->send();
            $this->response->setStatus($sent ? 200 : 502);
            echo $sent ? '发送成功' : '发送失败';
        } catch (Throwable $error) {
            $this->response->setStatus(502);
            echo '发送失败';
        }
    }
}
