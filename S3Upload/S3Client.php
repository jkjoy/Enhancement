<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Enhancement 内置的 S3 协议客户端
 */
class Enhancement_S3Upload_S3Client
{
    private static $instance = null;
    private $settings = null;
    private $endpoint = '';
    private $bucket = '';
    private $region = '';
    private $accessKey = '';
    private $secretKey = '';

    private function __construct()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        try {
            $this->settings = $options->plugin('Enhancement');
        } catch (Exception $e) {
            $this->settings = (object) array();
        }

        $this->endpoint = $this->normalizeHost(isset($this->settings->s3_endpoint) ? (string)$this->settings->s3_endpoint : '');
        $this->bucket = trim((string)(isset($this->settings->s3_bucket) ? $this->settings->s3_bucket : ''));
        $this->region = trim((string)(isset($this->settings->s3_region) ? $this->settings->s3_region : 'us-east-1'));
        $this->accessKey = trim((string)(isset($this->settings->s3_access_key) ? $this->settings->s3_access_key : ''));
        $this->secretKey = trim((string)(isset($this->settings->s3_secret_key) ? $this->settings->s3_secret_key : ''));
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function normalizeHost($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('#^https?://#i', '', $value);
        return rtrim($value, '/');
    }

    private function useHttps(): bool
    {
        return !isset($this->settings->s3_use_https) || trim((string)$this->settings->s3_use_https) !== '0';
    }

    private function sslVerifyEnabled(): bool
    {
        return !isset($this->settings->s3_ssl_verify) || trim((string)$this->settings->s3_ssl_verify) !== '0';
    }

    private function buildObjectKey($path)
    {
        $path = ltrim(trim((string)$path), '/');
        $prefix = trim((string)(isset($this->settings->s3_custom_path) ? $this->settings->s3_custom_path : ''));
        $prefix = trim($prefix, '/');

        if ($path === '') {
            return $prefix;
        }
        if ($prefix === '') {
            return $path;
        }

        return $prefix . '/' . $path;
    }

    private function ensureReady()
    {
        if ($this->endpoint === '' || $this->bucket === '' || $this->region === '' || $this->accessKey === '' || $this->secretKey === '') {
            throw new Exception('S3 配置不完整');
        }
        if (!function_exists('curl_init')) {
            throw new Exception('当前环境缺少 cURL 扩展');
        }
    }

    public function putObject($path, $file)
    {
        $this->ensureReady();
        $objectKey = $this->buildObjectKey($path);
        if ($objectKey === '') {
            throw new Exception('对象路径不能为空');
        }

        $payload = @file_get_contents($file);
        if (!is_string($payload)) {
            throw new Exception('无法读取上传文件内容');
        }

        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        $contentType = Enhancement_S3Upload_Utils::getMimeType($file);
        $contentSha256 = hash('sha256', $payload);

        $canonicalUri = '/' . $this->bucket . '/' . ltrim($objectKey, '/');
        $headers = array(
            'content-length' => strlen($payload),
            'content-type' => $contentType,
            'host' => $this->endpoint,
            'x-amz-content-sha256' => $contentSha256,
            'x-amz-date' => $date
        );

        $signature = $this->getSignature(
            'PUT',
            $canonicalUri,
            '',
            $headers,
            $contentSha256,
            $shortDate
        );

        $scheme = $this->useHttps() ? 'https://' : 'http://';
        $url = $scheme . $this->endpoint . $canonicalUri;
        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerifyEnabled(),
            CURLOPT_SSL_VERIFYHOST => $this->sslVerifyEnabled() ? 2 : 0,
            CURLOPT_HEADER => true
        ));

        $response = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $errorMessage = '上传失败，HTTP状态码：' . $httpCode;
            if ($curlError !== '') {
                $errorMessage .= '，cURL错误：' . $curlError;
            }
            throw new Exception($errorMessage);
        }

        return array(
            'path' => ltrim((string)$path, '/'),
            'url' => $this->getObjectUrl($path)
        );
    }

    public function deleteObject($path)
    {
        $this->ensureReady();
        $objectKey = $this->buildObjectKey($path);
        if ($objectKey === '') {
            return false;
        }

        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        $emptyHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $canonicalUri = '/' . $this->bucket . '/' . ltrim($objectKey, '/');
        $headers = array(
            'host' => $this->endpoint,
            'x-amz-content-sha256' => $emptyHash,
            'x-amz-date' => $date
        );

        $signature = $this->getSignature('DELETE', $canonicalUri, '', $headers, $emptyHash, $shortDate);
        $scheme = $this->useHttps() ? 'https://' : 'http://';
        $url = $scheme . $this->endpoint . $canonicalUri;

        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerifyEnabled(),
            CURLOPT_SSL_VERIFYHOST => $this->sslVerifyEnabled() ? 2 : 0
        ));

        $response = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);

        return $response !== false && ($httpCode === 200 || $httpCode === 204 || $httpCode === 404);
    }

    private function getSignature($method, $uri, $querystring, $headers, $payloadHash, $shortDate)
    {
        $algorithm = 'AWS4-HMAC-SHA256';
        $service = 's3';

        $canonicalHeaders = '';
        $signedHeaders = '';
        ksort($headers);
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim((string)$value) . "\n";
            $signedHeaders .= strtolower($key) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest = $method . "\n"
            . $uri . "\n"
            . $querystring . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $credentialScope = $shortDate . '/' . $this->region . '/' . $service . '/aws4_request';
        $stringToSign = $algorithm . "\n"
            . $headers['x-amz-date'] . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $kSecret = 'AWS4' . $this->secretKey;
        $kDate = hash_hmac('sha256', $shortDate, $kSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return $algorithm
            . ' Credential=' . $this->accessKey . '/' . $credentialScope
            . ',SignedHeaders=' . $signedHeaders
            . ',Signature=' . $signature;
    }

    public function getObjectUrl($path)
    {
        $path = ltrim(trim((string)$path), '/');
        if ($path === '') {
            return '';
        }

        $objectKey = $this->buildObjectKey($path);
        $protocol = $this->useHttps() ? 'https://' : 'http://';

        $customDomain = $this->normalizeHost(isset($this->settings->s3_custom_domain) ? (string)$this->settings->s3_custom_domain : '');
        if ($customDomain !== '') {
            return $protocol . $customDomain . '/' . $objectKey;
        }

        $urlStyle = isset($this->settings->s3_url_style) ? trim((string)$this->settings->s3_url_style) : 'path';
        if ($urlStyle === 'virtual') {
            return $protocol . $this->bucket . '.' . $this->endpoint . '/' . $objectKey;
        }

        return $protocol . $this->endpoint . '/' . $this->bucket . '/' . $objectKey;
    }

    public function generatePath($file)
    {
        $ext = pathinfo(isset($file['name']) ? (string)$file['name'] : '', PATHINFO_EXTENSION);
        $ext = $ext ? strtolower((string)$ext) : '';

        $date = new Typecho_Date();
        $path = $date->year . '/' . $date->month;
        $name = sprintf('%u', crc32(uniqid((string)mt_rand(), true)));
        if ($ext !== '') {
            $name .= '.' . $ext;
        }

        return $path . '/' . $name;
    }
}
