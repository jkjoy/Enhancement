<?php
/** Standard Ed25519 vectors and official QQ callbacks, including a PHP -n process. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/notify-channels/bootstrap.php';
require dirname(__DIR__, 2) . '/QqOfficialHelper.php';

$passed = 0;
function cryptoCheck(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) { throw new RuntimeException($label); }
    $passed++;
}

try {
    $child = ($argv[1] ?? '') === '--without-extensions';
    if ($child) {
        cryptoCheck(!extension_loaded('sodium') && !extension_loaded('openssl'), 'Child runs without sodium or OpenSSL extensions');
        cryptoCheck(!function_exists('sodium_crypto_sign_detached'), 'No global sodium shim is installed');
    }
    cryptoCheck(Enhancement_QqCryptoHelper::backend() === 'compat', 'QQ always uses bundled PHP even when sodium is installed');
    $vectors = [
        ['seed' => '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60',
            'public' => 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a', 'message' => '',
            'signature' => 'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b'],
        ['seed' => '4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb',
            'public' => '3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c', 'message' => '72',
            'signature' => '92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00']
    ];
    foreach ($vectors as $vector) {
        $pair = Enhancement_QqCryptoHelper::keypair(hex2bin($vector['seed']));
        $public = Enhancement_QqCryptoHelper::publicKey($pair);
        $secret = Enhancement_QqCryptoHelper::secretKey($pair);
        $message = hex2bin($vector['message']);
        $signature = Enhancement_QqCryptoHelper::sign($message, $secret);
        cryptoCheck(bin2hex($public) === $vector['public'], 'RFC8032 public key matches');
        cryptoCheck(bin2hex($signature) === $vector['signature'], 'RFC8032 detached signature matches');
        cryptoCheck(Enhancement_QqCryptoHelper::verify($signature, $message, $public), 'RFC8032 signature verifies');
        cryptoCheck(!Enhancement_QqCryptoHelper::verify($signature, $message . 'tampered', $public), 'Changed message is rejected');
        cryptoCheck(!Enhancement_QqCryptoHelper::verify(str_repeat("\xff", 64), $message, $public), 'Noncanonical signature is rejected');
        if (!$child && function_exists('sodium_crypto_sign_detached')) {
            cryptoCheck($pair === sodium_crypto_sign_seed_keypair(hex2bin($vector['seed'])), 'PHP keypair equals native sodium');
            cryptoCheck(sodium_crypto_sign_verify_detached($signature, $message, $public), 'Native sodium independently verifies PHP signature');
        }
    }
    cryptoCheck(!ParagonIE_Sodium_Compat::$disableFallbackForUnitTests, 'Library setting is restored after each operation');
    try { Enhancement_QqCryptoHelper::sign('test', 'short'); }
    catch (SodiumException $expected) {}
    cryptoCheck(!ParagonIE_Sodium_Compat::$disableFallbackForUnitTests, 'Library setting is restored after exceptions');
    $secret = 'DG5g3B4j9X2KOErG';
    $pair = Enhancement_QqCryptoHelper::keypair($secret . $secret);
    $signature = Enhancement_QqCryptoHelper::sign('1725442341Arq0D5A61EgUu4OxUvOp', Enhancement_QqCryptoHelper::secretKey($pair));
    cryptoCheck(bin2hex($signature) === '87befc99c42c651b3aac0278e71ada338433ae26fcb24307bdc5ad38c1adc2d01bcfcadc0842edac85e85205028a1132afe09280305f13aa6909ffc2d652c706', 'Published QQ challenge signature matches');

    notifyReset(['qq_notify_provider' => 'official', 'qq_app_id' => '123456', 'qq_app_secret' => $secret]);
    $state = Enhancement_QqOfficialHelper::status();
    cryptoCheck($state['available'] && $state['crypto_backend'] === 'compat', 'QQ reports ready without requiring an extension');
    $binding = Enhancement_QqOfficialHelper::startBinding();
    cryptoCheck(strlen($binding['code']) === 16, 'Binding code can be generated without sodium');
    $stamp = (string)time();
    $challenge = ['op' => 13, 'd' => ['plain_token' => 'QQ-plain-PHP', 'event_ts' => $stamp]];
    $result = Enhancement_QqOfficialHelper::webhook(json_encode($challenge), []);
    cryptoCheck($result['status'] === 200 && $result['body']['plain_token'] === 'QQ-plain-PHP', 'Initial official QQ challenge succeeds');
    cryptoCheck(Enhancement_QqCryptoHelper::verify(hex2bin($result['body']['signature']), $stamp . 'QQ-plain-PHP',
        Enhancement_QqCryptoHelper::publicKey($pair)), 'Initial challenge response contains the correct signature');
    $event = json_encode(['op' => 0, 't' => 'C2C_MESSAGE_CREATE', 'd' => ['content' => $binding['command'],
        'author' => ['user_openid' => 'PHP_ONLY_OWNER']]]);
    $headers = ['X-Signature-Timestamp' => $stamp, 'X-Signature-Ed25519' => bin2hex(Enhancement_QqCryptoHelper::sign(
        $stamp . $event, Enhancement_QqCryptoHelper::secretKey($pair)))];
    $result = Enhancement_QqOfficialHelper::webhook($event, $headers);
    cryptoCheck($result['status'] === 200 && Enhancement_QqOfficialHelper::binding()['target_id'] === 'PHP_ONLY_OWNER',
        'Signed QQ callback binds the sender using PHP verification');
    cryptoCheck(Enhancement_QqOfficialHelper::webhook($event . ' ', $headers)['status'] === 403, 'Tampered callback remains forbidden');
    cryptoCheck(!function_exists('sodium_crypto_sign_detached') || !$child, 'No sodium functions were added by the bundled class loader');

    if (!$child) {
        $vendor = dirname(__DIR__, 2) . '/vendor/sodium_compat/';
        $manifest = json_decode(file_get_contents($vendor . 'UPSTREAM.json'), true);
        foreach ($manifest['files'] as $file => $hashes) {
            cryptoCheck(hash_file('sha256', $vendor . $file) === $hashes['sha256'], 'Bundled upstream file changed: ' . $file);
        }
        $process = proc_open([PHP_BINARY, '-n', __FILE__, '--without-extensions'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Could not start PHP without extensions'); }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exitCode = proc_close($process);
        cryptoCheck($exitCode === 0, 'PHP without extensions failed: ' . $output . $error);
        $report = json_decode($output, true);
        cryptoCheck(is_array($report) && ($report['passed'] ?? 0) >= 20, 'No-extension child returned a valid report: ' . $output . $error);
        echo 'QQ PHP crypto checks passed: ' . $passed . '; no-extension checks: ' . $report['passed'] . PHP_EOL;
    } else { echo json_encode(['passed' => $passed]); }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
