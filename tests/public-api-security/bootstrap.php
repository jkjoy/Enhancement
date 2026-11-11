<?php
/** Private CLI-only doubles; the actual Action, trait and Meting code are copied into a disposable root. */
if (PHP_SAPI !== 'cli' || !getenv('ENHANCEMENT_PUBLIC_API_FIXTURE')) { exit(1); }
$fixture = getenv('ENHANCEMENT_PUBLIC_API_FIXTURE');
define('__TYPECHO_ROOT_DIR__', $fixture);
interface Widget_Interface_Do {}
class Typecho_Widget { public $request; public $response; public function widget($name) { return new AuditNotice(); } }
class AuditNotice { public function set($message, $unused, $type) { $GLOBALS['notice'] = [$message, $type]; } }
class Typecho_Common { public static function url($path, $base) { return rtrim($base, '/') . '/' . $path; } }
class Enhancement_Plugin {
    public static $enabled = true;
    public static function musicParserEnabled() { return self::$enabled; }
    public static function runtimeSettings() { return (object)['enable_music_parser' => self::$enabled ? '1' : '0']; }
}
class AuditRequest {
    public $params = [];
    public $method = 'GET';
    public $ajax = true;
    public $clientIp = '198.51.100.44';
    public $remoteIp = '198.51.100.44';
    public function get($name) { return $this->params[$name] ?? null; }
    public function getIp() { return $this->clientIp; }
    public function getServer($name, $default = null) { return $name === 'REQUEST_METHOD' ? $this->method : ($name === 'REMOTE_ADDR' ? $this->remoteIp : $default); }
    public function isAjax() { return $this->ajax; }
}
class AuditResponse {
    public $status = 200;
    public $headers = [];
    public $redirect;
    public $json;
    public $back = false;
    public function setStatus($status) { $this->status = $status; return $this; }
    public function setHeader($key, $value) { $this->headers[$key] = $value; return $this; }
    public function setContentType($value) { return $this->setHeader('Content-Type', $value); }
    public function redirect($url) { $this->status = 302; $this->redirect = $url; }
    public function throwJson($value) { $this->json = $value; }
    public function goBack() { $this->back = true; }
}
if (!function_exists('curl_init')) {
    function curl_init() { return (object)['options' => [], 'error' => 0]; }
    function curl_setopt_array($handle, $options) { $handle->options = $options; return true; }
    function curl_exec($handle) {
        $GLOBALS['curlCalls'][] = $handle->options;
        file_put_contents(getenv('ENHANCEMENT_PUBLIC_API_FIXTURE') . '/transport.log', "fetch\n", FILE_APPEND | LOCK_EX);
        if (!empty($GLOBALS['curlSleep'])) { usleep($GLOBALS['curlSleep']); }
        if (!empty($GLOBALS['curlFail'])) { $handle->error = CURLE_OPERATION_TIMEDOUT; return false; }
        $body = $GLOBALS['curlBody'] ?? '{"data":{"song":{"list":[]}}}';
        $callback = $handle->options[CURLOPT_WRITEFUNCTION];
        return $callback($handle, $body) === strlen($body);
    }
    function curl_getinfo($handle) { return ['http_code' => 200]; }
    function curl_errno($handle) { return $handle->error; }
    function curl_error($handle) { return 'fixture transport failure'; }
    function curl_close($handle) {}
}
require $fixture . '/Action.php';
function auditAction() {
    $action = new Enhancement_Action();
    $action->request = new AuditRequest();
    $action->response = new AuditResponse();
    $options = new ReflectionProperty($action, 'options');
    $options->setValue($action, (object)['siteUrl' => 'https://' . basename(getenv('ENHANCEMENT_PUBLIC_API_FIXTURE')) . '.test/', 'index' => 'https://audit.example/']);
    return $action;
}
function auditPrivate($action, $method, array $arguments = []) {
    $reflection = new ReflectionMethod($action, $method);
    return $reflection->invokeArgs($action, $arguments);
}
function auditEndpoint($action, array $params) {
    $action->request->params = $params;
    $action->response = new AuditResponse();
    ob_start();
    $action->metingApi();
    $body = ob_get_clean();
    return ['status' => $action->response->status, 'body' => $body, 'headers' => $action->response->headers, 'redirect' => $action->response->redirect];
}
