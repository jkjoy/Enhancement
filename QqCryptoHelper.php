<?php

/** Official QQ Ed25519 signatures using the bundled PHP implementation. */
class Enhancement_QqCryptoHelper
{
    public static function backend(): string
    {
        if (!class_exists('ParagonIE_Sodium_Compat')) {
            $autoload = __DIR__ . '/vendor/sodium_compat/autoload-php7.php';
            if (!is_file($autoload)) { return 'unavailable'; }
            require_once $autoload;
        }
        if (!class_exists('SodiumException', false)) { require_once __DIR__ . '/vendor/sodium_compat/src/SodiumException.php'; }
        return class_exists('ParagonIE_Sodium_Compat') ? 'compat' : 'unavailable';
    }

    public static function available(): bool
    {
        return self::backend() !== 'unavailable';
    }

    private static function call(string $method, array $arguments)
    {
        if (!self::available()) { throw new RuntimeException('QQ 签名组件缺失，请更新完整插件。'); }
        // Keep QQ independent of ext-sodium without changing other callers of the library.
        $previous = ParagonIE_Sodium_Compat::$disableFallbackForUnitTests;
        ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
        try { return call_user_func_array(array('ParagonIE_Sodium_Compat', $method), $arguments); }
        finally { ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = $previous; }
    }

    public static function keypair(string $seed): string
    {
        return self::call('crypto_sign_seed_keypair', array($seed));
    }

    public static function publicKey(string $keypair): string
    {
        return self::call('crypto_sign_publickey', array($keypair));
    }

    public static function secretKey(string $keypair): string
    {
        return self::call('crypto_sign_secretkey', array($keypair));
    }

    public static function sign(string $message, string $secretKey): string
    {
        return self::call('crypto_sign_detached', array($message, $secretKey));
    }

    public static function verify(string $signature, string $message, string $publicKey): bool
    {
        try { return self::call('crypto_sign_verify_detached', array($signature, $message, $publicKey)); }
        catch (SodiumException $exception) { return false; }
    }
}
