<?php
use Widget\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/PHPMailer/Exception.php';

class Enhancement_CommentNotifier_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function execute()
    {
        // no-op
    }

    public function action($data = "")
    {
        $options = Options::alloc();
        $plugin = $options->plugin('Enhancement');

        if (!isset($_REQUEST['auth']) || $_REQUEST['auth'] != $plugin->auth) {
            echo '密钥不正确';
            return;
        }

        try {
            $from = $plugin->from;
            $fromName = $plugin->fromName;

            $mail = new PHPMailer(false);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->isSMTP();
            $mail->Host = $plugin->STMPHost;
            $mail->SMTPAuth = true;
            $mail->Username = $plugin->SMTPUserName;
            $mail->Password = $plugin->SMTPPassword;
            $mail->SMTPSecure = $plugin->SMTPSecure;
            $mail->Port = $plugin->SMTPPort;

            $mail->setFrom($from, $fromName);
            $mail->addAddress($_REQUEST['to'], $_REQUEST['fromName']);
            $mail->Subject = $_REQUEST['subject'];
            $mail->isHTML();
            $mail->Body = $_REQUEST['html'];
            $mail->send();

            $at = date('Y-m-d H:i:s');
            if ($mail->isError()) {
                $data = $at . ' ' . $mail->ErrorInfo;
            } else {
                $data = PHP_EOL . $at . ' 发送成功!! ';
                $data .= ' 发件人:' . $fromName;
                $data .= ' 发件邮箱:' . $from;
                $data .= ' 接收邮箱:' . $_REQUEST['to'];
                $data .= ' 接收人:' . $_REQUEST['fromName'] . PHP_EOL;
            }
            echo $data;
        } catch (Exception $e) {
            $str = "\nerror time: " . date('Y-m-d H:i:s') . "\n";
            echo $str . $e . "\n";
        }
    }
}
