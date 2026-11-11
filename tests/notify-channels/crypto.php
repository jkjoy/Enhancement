<?php
/** Native/fallback interoperability and cheap webhook rejection, with no network access. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/bootstrap.php';

$cryptoMode = $argv[1] ?? '';
if ($cryptoMode === 'prevalidation') {
    // The child process disables these builtins so a call counter can replace key derivation.
    if (function_exists('sodium_crypto_sign_seed_keypair')) { throw new RuntimeException('Native fixture functions were not disabled.'); }
    $cryptoKeyCalls = 0;
    function sodium_crypto_sign_seed_keypair($seed) { global $cryptoKeyCalls; $cryptoKeyCalls++; throw new RuntimeException('Key derivation reached'); }
    function sodium_crypto_sign_publickey($keypair) { return ''; }
    function sodium_crypto_sign_secretkey($keypair) { return ''; }
    function sodium_crypto_sign_detached($message, $key) { return ''; }
    function sodium_crypto_sign_verify_detached($signature, $message, $key) { return false; }
    require_once dirname(__DIR__, 2) . '/QqOfficialHelper.php';
    notifyReset(array('qq_notify_provider' => 'official', 'qq_app_id' => '123456', 'qq_app_secret' => 'fixture-secret'));
    $event = json_encode(array('op' => 0, 't' => 'TEST', 'd' => array()));
    foreach (array(
        array($event, array(), 403),
        array($event, array('X-Signature-Timestamp' => (string)time(), 'X-Signature-Ed25519' => 'bad'), 403),
        array($event, array('X-Signature-Timestamp' => (string)(time() - 301), 'X-Signature-Ed25519' => str_repeat('0', 128)), 403),
        array($event, array('X-Bot-Appid' => '999999'), 403),
        array(json_encode(array('op' => 13, 'd' => array('plain_token' => '{"event":0}', 'event_ts' => (string)time()))), array(), 400),
        array(json_encode(array('op' => 13, 'd' => array('plain_token' => 'opaque', 'event_ts' => (string)(time() - 301)))), array(), 403),
    ) as $case) {
        notifyCheck(Enhancement_QqOfficialHelper::webhook($case[0], $case[1])['status'] === $case[2], 'Invalid callback is rejected');
    }
    notifyCheck($cryptoKeyCalls === 0 && Enhancement_NotifyTransportHelper::$state === array(), 'Invalid callback cannot derive keys or mutate state');
    $result = Enhancement_QqOfficialHelper::webhook($event,
        array('X-Signature-Timestamp' => (string)time(), 'X-Signature-Ed25519' => str_repeat('0', 128)));
    notifyCheck($result['status'] === 500 && $cryptoKeyCalls === 1, 'A syntactically valid request reaches the instrumented crypto backend');
    echo 'Prevalidation checks passed: ' . $notifyPassed . PHP_EOL;
    exit;
}

require_once dirname(__DIR__, 2) . '/QqCryptoHelper.php';
$cryptoSecret = 'DG5g3B4j9X2KOErG';
$cryptoSeed = substr(str_repeat($cryptoSecret, (int)ceil(32 / strlen($cryptoSecret))), 0, 32);
$cryptoKeypair = Enhancement_QqCryptoHelper::keypair($cryptoSeed);
$cryptoMessage = '1725442341Arq0D5A61EgUu4OxUvOp';
$cryptoSignature = Enhancement_QqCryptoHelper::sign($cryptoMessage, Enhancement_QqCryptoHelper::secretKey($cryptoKeypair));
$cryptoExpected = '87befc99c42c651b3aac0278e71ada338433ae26fcb24307bdc5ad38c1adc2d01bcfcadc0842edac85e85205028a1132afe09280305f13aa6909ffc2d652c706';
if ($cryptoMode === 'compat') {
    echo json_encode(array('backend' => Enhancement_QqCryptoHelper::backend(), 'signature' => bin2hex($cryptoSignature),
        'verified' => Enhancement_QqCryptoHelper::verify($cryptoSignature, $cryptoMessage, Enhancement_QqCryptoHelper::publicKey($cryptoKeypair))));
    exit;
}
notifyCheck(bin2hex($cryptoSignature) === $cryptoExpected, 'Preferred crypto backend matches the official QQ fixed signature vector');
notifyCheck(Enhancement_QqCryptoHelper::verify($cryptoSignature, $cryptoMessage, Enhancement_QqCryptoHelper::publicKey($cryptoKeypair)),
    'Preferred crypto backend verifies the official signature');
notifyCheck(Enhancement_QqCryptoHelper::backend() === (function_exists('sodium_crypto_sign_seed_keypair') ? 'native' : 'compat'),
    'Native crypto is preferred when the extension is available');

foreach (array('compat', 'prevalidation') as $cryptoChildMode) {
    // PHP 8 removes disabled builtins, permitting the instrumented prevalidation fixture.
    if ($cryptoChildMode === 'prevalidation' && PHP_VERSION_ID < 80000) { continue; }
    $cryptoProcess = proc_open(array(PHP_BINARY, '-d',
        'disable_functions=sodium_crypto_sign_seed_keypair,sodium_crypto_sign_publickey,sodium_crypto_sign_secretkey,sodium_crypto_sign_detached,sodium_crypto_sign_verify_detached',
        __FILE__, $cryptoChildMode), array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')), $cryptoPipes);
    if (!is_resource($cryptoProcess)) { throw new RuntimeException('Could not start the isolated crypto fixture.'); }
    fclose($cryptoPipes[0]);
    $cryptoOutput = stream_get_contents($cryptoPipes[1]);
    $cryptoErrors = stream_get_contents($cryptoPipes[2]);
    fclose($cryptoPipes[1]);
    fclose($cryptoPipes[2]);
    notifyCheck(proc_close($cryptoProcess) === 0 && $cryptoErrors === '', 'Isolated ' . $cryptoChildMode . ' fixture completes without warnings');
    if ($cryptoChildMode === 'compat') {
        $cryptoResult = json_decode($cryptoOutput, true);
        notifyCheck(($cryptoResult['backend'] ?? '') === 'compat' && ($cryptoResult['signature'] ?? '') === $cryptoExpected
            && !empty($cryptoResult['verified']), 'Pure PHP fallback preserves the same Ed25519 wire protocol without sodium');
    } else {
        notifyCheck(strpos($cryptoOutput, 'Prevalidation checks passed: 8') !== false, 'Cheap webhook failures never reach key derivation');
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { echo 'Crypto checks passed: ' . $notifyPassed . PHP_EOL; }
