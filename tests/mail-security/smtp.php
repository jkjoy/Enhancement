<?php
/** Four real PHPMailer send paths against a disposable loopback SMTP sink. No delivery or site config. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (($argv[1] ?? '') === '--smtp-sink') {
    $smtpSinkDirectory = $argv[2] ?? '';
    if (!is_dir($smtpSinkDirectory)) { exit(2); }
    $smtpSink = stream_socket_server('tcp://127.0.0.1:0', $smtpSinkErrno, $smtpSinkError);
    if (!$smtpSink) { throw new RuntimeException('Could not start the loopback SMTP fixture.'); }
    echo stream_socket_get_name($smtpSink, false) . PHP_EOL;
    fflush(STDOUT);
    while ($smtpConnection = @stream_socket_accept($smtpSink, 10)) {
        stream_set_timeout($smtpConnection, 5);
        $smtpRecord = array('username' => '', 'password' => '', 'from' => '', 'to' => array(), 'data' => '', 'accepted' => false);
        $smtpAuth = '';
        fwrite($smtpConnection, "220 fixture.local ESMTP local sink\r\n");
        while (($smtpLine = fgets($smtpConnection)) !== false) {
            $smtpLine = rtrim($smtpLine, "\r\n");
            if ($smtpAuth === 'username') {
                $smtpRecord['username'] = base64_decode($smtpLine, true);
                $smtpAuth = 'password';
                fwrite($smtpConnection, "334 UGFzc3dvcmQ6\r\n");
            } elseif ($smtpAuth === 'password') {
                $smtpRecord['password'] = base64_decode($smtpLine, true);
                $smtpAuth = '';
                fwrite($smtpConnection, "235 2.7.0 Authenticated\r\n");
            } elseif (preg_match('/\A(?:EHLO|HELO)\b/i', $smtpLine)) {
                fwrite($smtpConnection, "250-fixture.local\r\n250-AUTH LOGIN\r\n250 SIZE 1048576\r\n");
            } elseif (strcasecmp($smtpLine, 'AUTH LOGIN') === 0) {
                $smtpAuth = 'username';
                fwrite($smtpConnection, "334 VXNlcm5hbWU6\r\n");
            } elseif (preg_match('/\AMAIL FROM:\s*<([^>]*)>/i', $smtpLine, $smtpMatch)) {
                $smtpRecord['from'] = $smtpMatch[1];
                fwrite($smtpConnection, "250 2.1.0 Sender accepted\r\n");
            } elseif (preg_match('/\ARCPT TO:\s*<([^>]*)>/i', $smtpLine, $smtpMatch)) {
                $smtpRecord['to'][] = $smtpMatch[1];
                fwrite($smtpConnection, $smtpMatch[1] === 'reject@example.test' ? "550 5.1.1 Fixture rejection\r\n" : "250 2.1.5 Recipient accepted\r\n");
            } elseif (strcasecmp($smtpLine, 'DATA') === 0) {
                fwrite($smtpConnection, "354 End with a single dot\r\n");
                while (($smtpBodyLine = fgets($smtpConnection)) !== false && rtrim($smtpBodyLine, "\r\n") !== '.') {
                    if (substr($smtpBodyLine, 0, 2) === '..') { $smtpBodyLine = substr($smtpBodyLine, 1); }
                    $smtpRecord['data'] .= $smtpBodyLine;
                }
                $smtpRecord['accepted'] = true;
                fwrite($smtpConnection, "250 2.0.0 Stored only in the local test fixture\r\n");
            } elseif (strcasecmp($smtpLine, 'QUIT') === 0) {
                fwrite($smtpConnection, "221 2.0.0 Bye\r\n");
                break;
            } elseif (in_array(strtoupper($smtpLine), array('RSET', 'NOOP'), true)) {
                fwrite($smtpConnection, "250 2.0.0 OK\r\n");
            } else {
                fwrite($smtpConnection, "500 5.5.1 Unsupported fixture command\r\n");
            }
        }
        fclose($smtpConnection);
        file_put_contents($smtpSinkDirectory . '/messages.jsonl', json_encode($smtpRecord) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    fclose($smtpSink);
    exit;
}

// Only framework plumbing is replaced; the SMTP setup and all four send implementations are real.
interface Widget_Interface_Do {}
class Typecho_Widget { public $request; public $response; }
class Typecho_Common
{
    public static function url($path, $base) { return rtrim($base, '/') . '/' . ltrim($path, '/'); }
}
class Enhancement_Plugin
{
    public static $settings;
    public static function runtimeSettings() { return self::$settings; }
}
class SmtpFixtureConfig
{
    private $values;
    public function __construct(array $values) { $this->values = $values; }
    public function __get($key) { return $this->values[$key] ?? null; }
    public function __isset($key) { return isset($this->values[$key]); }
    public function toArray() { return $this->values; }
}
class SmtpFixtureInvalidConfig extends SmtpFixtureConfig
{
    public function toArray() { throw new RuntimeException('private-fixture-smtp-configuration'); }
}
class SmtpFixtureRequest
{
    public $values = array();
    public function get($key, $default = null) { return $this->values[$key] ?? $default; }
    public function isAjax() { return true; }
}
class SmtpFixtureResponse
{
    public $status = 200;
    public $json;
    public function setStatus($status) { $this->status = $status; return $this; }
    public function throwJson($json) { $this->json = $json; }
}
function _t($text, ...$arguments) { return $arguments ? vsprintf($text, $arguments) : $text; }
function smtpCheck(bool $condition, string $message): void
{
    global $smtpPassed;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $smtpPassed++;
}
function smtpPrivate($action, string $method, array $arguments)
{
    $reflection = new ReflectionMethod($action, $method);
    if (PHP_VERSION_ID < 80100) { $reflection->setAccessible(true); }
    return $reflection->invokeArgs($action, $arguments);
}
function smtpRemove(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { smtpRemove($path . '/' . $name); } }
    rmdir($path);
}
function smtpMessages(string $directory, int $expected): array
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $lines = is_file($directory . '/messages.jsonl') ? file($directory . '/messages.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
        if (count($lines) >= $expected) { return array_map(static function ($line) { return json_decode($line, true); }, $lines); }
        usleep(10000);
    }
    throw new RuntimeException('SMTP fixture did not record the expected number of completed connections.');
}

$smtpDirectory = sys_get_temp_dir() . '/enhancement-smtp-tests-' . bin2hex(random_bytes(6));
mkdir($smtpDirectory, 0700);
$smtpPlugin = $smtpDirectory . '/Enhancement';
$smtpProcess = null;
$smtpLog = null;
$smtpPassed = 0;
$smtpExit = 0;
$smtpReporting = error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new RuntimeException($message);
});
try {
    $smtpFiles = array('Action.php', 'PublicApiHelper.php', 'SettingsHelper.php', 'MailHelper.php', 'CommentNotifierHelper.php',
        'CommentNotifier/TemplateHelper.php', 'CommentNotifier/PHPMailer/PHPMailer.php', 'CommentNotifier/PHPMailer/SMTP.php', 'CommentNotifier/PHPMailer/Exception.php');
    foreach ($smtpFiles as $smtpFile) {
        $smtpDestination = $smtpPlugin . '/' . $smtpFile;
        if (!is_dir(dirname($smtpDestination))) { mkdir(dirname($smtpDestination), 0700, true); }
        copy(dirname(__DIR__, 2) . '/' . $smtpFile, $smtpDestination);
    }
    require $smtpPlugin . '/MailHelper.php';
    require $smtpPlugin . '/SettingsHelper.php';
    require $smtpPlugin . '/CommentNotifierHelper.php';
    require $smtpPlugin . '/Action.php';

    $smtpProcess = proc_open(array(PHP_BINARY, __FILE__, '--smtp-sink', $smtpDirectory),
        array(array('pipe', 'r'), array('pipe', 'w'), array('file', $smtpDirectory . '/sink-errors.log', 'a')), $smtpPipes);
    if (!is_resource($smtpProcess)) { throw new RuntimeException('Could not start the disposable SMTP process.'); }
    fclose($smtpPipes[0]);
    stream_set_timeout($smtpPipes[1], 5);
    $smtpAddress = trim((string)fgets($smtpPipes[1]));
    smtpCheck(preg_match('/\A127\.0\.0\.1:([0-9]+)\z/D', $smtpAddress, $smtpPortMatch) === 1, 'SMTP sink listens only on loopback');
    $smtpPassword = "  fixture password \t";
    $smtpSettings = array('SMTPHost' => '127.0.0.1', 'STMPHost' => '127.0.0.2', 'SMTPPort' => $smtpPortMatch[1],
        'SMTPUserName' => ' fixture-user ', 'SMTPPassword' => $smtpPassword, 'SMTPSecure' => '',
        'from' => 'sender@example.test', 'fromName' => 'Fixture Sender', 'adminfrom' => 'admin@example.test',
        'enable_link_submit_admin_mail_notifier' => '1', 'enable_link_approval_mail_notifier' => '1', 'log' => '1');
    $smtpLegacy = $smtpSettings;
    unset($smtpLegacy['SMTPHost']);
    $smtpLegacy['STMPHost'] = '127.0.0.1';
    foreach (array($smtpSettings, $smtpLegacy) as $smtpFactorySettings) {
        $smtpMailer = Enhancement_MailHelper::createMailer(new SmtpFixtureConfig($smtpFactorySettings));
        smtpCheck($smtpMailer->Host === '127.0.0.1' && $smtpMailer->Password === $smtpPassword
            && $smtpMailer->Username === 'fixture-user' && $smtpMailer->Port === (int)$smtpPortMatch[1]
            && $smtpMailer->From === 'sender@example.test' && $smtpMailer->FromName === 'Fixture Sender',
            'Shared factory preserves canonical/legacy hosts, sender, port and password whitespace');
    }

    $smtpAction = new Enhancement_Action();
    $smtpAction->request = new SmtpFixtureRequest();
    $smtpAction->request->values = $smtpSettings;
    $smtpAction->response = new SmtpFixtureResponse();
    $smtpOptions = new ReflectionProperty('Enhancement_Action', 'options');
    if (PHP_VERSION_ID < 80100) { $smtpOptions->setAccessible(true); }
    $smtpOptions->setValue($smtpAction, (object)array('siteUrl' => 'https://fixture.example.test/',
        'adminUrl' => 'https://fixture.example.test/admin/', 'title' => 'Fixture site'));
    $smtpAction->sendSmtpTestMail();
    smtpCheck($smtpAction->response->status === 200 && !empty($smtpAction->response->json['success']),
        'SMTP test action succeeds through the real shared mailer');
    $smtpLink = array('name' => 'Fixture Link', 'url' => 'https://friend.example.test/', 'email' => 'friend@example.test', 'description' => 'Fixture description');
    smtpCheck(smtpPrivate($smtpAction, 'sendLinkSubmissionAdminMail', array($smtpLink, $smtpLegacy)) === true,
        'Friend application notification sends using a historical STMPHost-only configuration');
    $smtpApproval = $smtpSettings;
    $smtpApproval['fromName'] = '';
    smtpCheck(smtpPrivate($smtpAction, 'sendLinkApprovalMail', array($smtpLink, $smtpApproval)) === true,
        'Friend approval notification sends with the canonical host and site-title sender fallback');

    Enhancement_Plugin::$settings = new SmtpFixtureConfig($smtpSettings);
    $smtpLog = rtrim(sys_get_temp_dir(), '/\\') . '/enhancement-mail-' . hash('sha256', realpath($smtpPlugin)) . '.log';
    smtpCheck(!file_exists($smtpLog), 'Comment mail log belongs to this disposable plugin copy');
    $smtpParam = array('to' => 'reader@example.test', 'fromName' => 'Fixture Reader', 'subject' => 'Fixture comment', 'html' => '<p>Fixture comment body</p>');
    smtpCheck(Enhancement_CommentNotifierHelper::zemail($smtpParam) === true, 'Comment zemail sends successfully with logging enabled');
    $smtpLogText = file_get_contents($smtpLog);
    smtpCheck(strpos($smtpLogText, '发送成功!') !== false && strpos($smtpLogText, '发件人:Fixture Sender') !== false
        && strpos($smtpLogText, '发件邮箱:sender@example.test') !== false && strpos($smtpLogText, '接收邮箱:reader@example.test') !== false,
        'Successful comment log retains the real sender and recipient after shared-factory refactoring');
    smtpCheck((fileperms($smtpLog) & 0777) === 0600 && !file_exists($smtpPlugin . '/CommentNotifier/log.txt'),
        'Comment log is private and does not create the former public log file');

    $smtpRecords = smtpMessages($smtpDirectory, 4);
    foreach ($smtpRecords as $smtpIndex => $smtpRecord) {
        smtpCheck(!empty($smtpRecord['accepted']) && $smtpRecord['username'] === 'fixture-user' && $smtpRecord['password'] === $smtpPassword,
            'Real SMTP LOGIN preserves credentials for send path ' . ($smtpIndex + 1));
        smtpCheck($smtpRecord['from'] === 'sender@example.test'
            && $smtpRecord['to'] === array(array('admin@example.test', 'admin@example.test', 'friend@example.test', 'reader@example.test')[$smtpIndex]),
            'Real SMTP envelope preserves sender and intended recipient for send path ' . ($smtpIndex + 1));
        $smtpExpectedName = $smtpIndex === 2 ? 'Fixture site' : 'Fixture Sender';
        smtpCheck(preg_match('/^From:\s*"?' . preg_quote($smtpExpectedName, '/') . '"?\s*<sender@example\.test>/mi', $smtpRecord['data']) === 1
            && stripos($smtpRecord['data'], 'Content-Type: text/html; charset=utf-8') !== false,
            'Real message headers preserve sender name and HTML encoding for send path ' . ($smtpIndex + 1));
    }

    $smtpParam['to'] = 'reject@example.test';
    smtpCheck(Enhancement_CommentNotifierHelper::zemail($smtpParam) === false, 'A rejected SMTP recipient returns a failed comment delivery');
    $smtpFailedLog = substr(file_get_contents($smtpLog), strlen($smtpLogText));
    smtpCheck($smtpFailedLog !== '' && strpos($smtpFailedLog, '发送成功!') === false && strpos($smtpFailedLog, $smtpPassword) === false,
        'SMTP failure appends an error log without a false success or password');
    smtpCheck(count(smtpMessages($smtpDirectory, 5)) === 5 && file_get_contents($smtpDirectory . '/sink-errors.log') === '',
        'All SMTP connections remain inside the clean local sink');
    Enhancement_Plugin::$settings = new SmtpFixtureInvalidConfig($smtpSettings);
    $smtpBeforeExceptionLog = file_get_contents($smtpLog);
    smtpCheck(Enhancement_CommentNotifierHelper::zemail($smtpParam) === false,
        'Mailer initialization exceptions return a failed comment delivery');
    $smtpExceptionLog = substr(file_get_contents($smtpLog), strlen($smtpBeforeExceptionLog));
    smtpCheck(strpos($smtpExceptionLog, '邮件发送失败：RuntimeException') !== false
        && strpos($smtpExceptionLog, 'private-fixture-smtp-configuration') === false,
        'Initialization failure logs keep the exception class without private exception details');
    echo 'SMTP send-path checks passed: ' . $smtpPassed . PHP_EOL;
} catch (Throwable $smtpError) {
    $smtpExit = 1;
    fwrite(STDERR, get_class($smtpError) . ': ' . $smtpError->getMessage() . PHP_EOL);
} finally {
    if (is_resource($smtpProcess)) { proc_terminate($smtpProcess); proc_close($smtpProcess); }
    if (isset($smtpPipes[1]) && is_resource($smtpPipes[1])) { fclose($smtpPipes[1]); }
    if ($smtpLog !== null && is_file($smtpLog)) { unlink($smtpLog); }
    smtpRemove($smtpDirectory);
    restore_error_handler();
    error_reporting($smtpReporting);
}
exit($smtpExit);
