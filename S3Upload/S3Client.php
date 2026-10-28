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
        $this->settings = null;

        if (class_exists('Enhancement_Plugin') && method_exists('Enhancement_Plugin', 'runtimeSettings')) {
            $this->settings = Enhancement_Plugin::runtimeSettings();
        }

        if (!is_object($this->settings)) {
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

        if (strpos($value, '://') === false) {
            $value = 'https://' . $value;
        }

        $parts = @parse_url($value);
        if (is_array($parts) && isset($parts['host'])) {
            $host = (string)$parts['host'];
            if (isset($parts['port']) && intval($parts['port']) > 0) {
                $host .= ':' . intval($parts['port']);
            }
            return trim($host);
        }

        $value = preg_replace('#^https?://#i', '', $value);
        $value = preg_replace('#[/?#].*$#', '', $value);
        return trim($value);
    }

    private function useHttps(): bool
    {
        return !isset($this->settings->s3_use_https) || trim((string)$this->settings->s3_use_https) !== '0';
    }

    private function sslVerifyEnabled(): bool
    {
        return !isset($this->settings->s3_ssl_verify) || trim((string)$this->settings->s3_ssl_verify) !== '0';
    }

    private function useVirtualStyle(): bool
    {
        $urlStyle = isset($this->settings->s3_url_style) ? trim((string)$this->settings->s3_url_style) : 'path';
        return strtolower($urlStyle) === 'virtual';
    }

    private function buildObjectKey($path)
    {
        $path = ltrim(trim((string)$path), '/');
        $prefix = $this->getNormalizedCustomPath();

        // 兼容历史数据：path 已包含前缀时避免重复拼接
        if ($prefix !== '' && $path !== '') {
            if ($path === $prefix) {
                $path = '';
            } elseif (strpos($path, $prefix . '/') === 0) {
                $path = substr($path, strlen($prefix) + 1);
            }
        }

        if ($path === '') {
            return $prefix;
        }
        if ($prefix === '') {
            return $path;
        }

        return $prefix . '/' . $path;
    }

    private function getNormalizedCustomPath()
    {
        $prefix = trim((string)(isset($this->settings->s3_custom_path) ? $this->settings->s3_custom_path : ''));
        $prefix = trim($prefix, '/');
        return $prefix;
    }

    private function encodePath($path)
    {
        $path = str_replace('\\', '/', ltrim((string)$path, '/'));
        if ($path === '') {
            return '/';
        }

        $segments = explode('/', $path);
        $encoded = array();
        foreach ($segments as $segment) {
            $encoded[] = str_replace('%7E', '~', rawurlencode($segment));
        }

        return '/' . implode('/', $encoded);
    }

    private function buildRequestTarget($objectKey)
    {
        $objectKey = ltrim(trim((string)$objectKey), '/');
        if ($objectKey === '') {
            throw new Exception('对象路径不能为空');
        }

        $protocol = $this->useHttps() ? 'https://' : 'http://';
        $encodedPath = $this->encodePath($objectKey);

        if ($this->useVirtualStyle()) {
            $host = $this->bucket . '.' . $this->endpoint;
            $canonicalUri = $encodedPath;
        } else {
            $host = $this->endpoint;
            $canonicalUri = '/' . str_replace('%7E', '~', rawurlencode($this->bucket)) . $encodedPath;
        }

        return array(
            'host' => $host,
            'canonicalUri' => $canonicalUri,
            'url' => $protocol . $host . $canonicalUri
        );
    }

    private function getResponseHeaderValue($headers, $name)
    {
        $headers = (string)$headers;
        $name = trim((string)$name);
        if ($headers === '' || $name === '') {
            return '';
        }

        if (preg_match('/^' . preg_quote($name, '/') . '\s*:\s*([^\r\n]+)/im', $headers, $matches)) {
            return trim((string)$matches[1]);
        }

        return '';
    }

    private function compactResponseBody($body)
    {
        $body = trim((string)$body);
        if ($body === '') {
            return '';
        }

        $body = preg_replace('/\s+/', ' ', $body);
        if (strlen($body) > 240) {
            $body = substr($body, 0, 240) . '...';
        }

        return $body;
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

    public function putObject($path, $file, $timeout = 120)
    {
        $this->ensureReady();
        $objectKey = $this->buildObjectKey($path);
        if ($objectKey === '') {
            throw new Exception('对象路径不能为空');
        }
        if (!is_string($file) || $file === '' || !is_file($file) || !is_readable($file)) {
            throw new Exception('无法读取上传文件内容');
        }

        $contentSha256 = @hash_file('sha256', $file);
        if (!is_string($contentSha256) || $contentSha256 === '') {
            throw new Exception('无法计算上传文件哈希');
        }

        $contentLength = @filesize($file);
        $contentLength = is_numeric($contentLength) ? intval($contentLength) : 0;
        if ($contentLength < 0) {
            $contentLength = 0;
        }

        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        $contentType = Enhancement_S3Upload_Utils::getMimeType($file);
        if (!is_string($contentType) || trim($contentType) === '') {
            $contentType = 'application/octet-stream';
        }

        $target = $this->buildRequestTarget($objectKey);
        $headers = array(
            'content-length' => (string)$contentLength,
            'content-type' => $contentType,
            'host' => $target['host'],
            'x-amz-content-sha256' => $contentSha256,
            'x-amz-date' => $date
        );

        $signature = $this->getSignature(
            'PUT',
            $target['canonicalUri'],
            '',
            $headers,
            $contentSha256,
            $shortDate
        );

        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;
        $curlHeaders[] = 'Expect:';

        $stream = @fopen($file, 'rb');
        if (!is_resource($stream)) {
            throw new Exception('无法打开上传文件流');
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $target['url'],
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE => $contentLength,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerifyEnabled(),
            CURLOPT_SSL_VERIFYHOST => $this->sslVerifyEnabled() ? 2 : 0,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => min(15, max(1, min(120, (int)$timeout))),
            CURLOPT_TIMEOUT => max(1, min(120, (int)$timeout)),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ));

        $response = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = curl_error($ch);
        $headerSize = intval(curl_getinfo($ch, CURLINFO_HEADER_SIZE));
        curl_close($ch);
        @fclose($stream);

        $responseHeaders = '';
        $responseBody = '';
        if (is_string($response) && $headerSize > 0) {
            $responseHeaders = substr($response, 0, $headerSize);
            $responseBody = substr($response, $headerSize);
        } elseif (is_string($response)) {
            $responseBody = $response;
        }

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $errorMessage = '上传失败，HTTP状态码：' . $httpCode;
            if ($curlError !== '') {
                $errorMessage .= '，cURL错误：' . $curlError;
            }

            $regionHint = $this->getResponseHeaderValue($responseHeaders, 'x-amz-bucket-region');
            if ($regionHint !== '' && strcasecmp($regionHint, $this->region) !== 0) {
                $errorMessage .= '，建议 Region：' . $regionHint;
            }

            $bodyHint = $this->compactResponseBody($responseBody);
            if ($bodyHint !== '') {
                $errorMessage .= '，响应：' . $bodyHint;
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

        $target = $this->buildRequestTarget($objectKey);
        $headers = array(
            'host' => $target['host'],
            'x-amz-content-sha256' => $emptyHash,
            'x-amz-date' => $date
        );

        $signature = $this->getSignature('DELETE', $target['canonicalUri'], '', $headers, $emptyHash, $shortDate);

        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;
        $curlHeaders[] = 'Expect:';

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $target['url'],
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerifyEnabled(),
            CURLOPT_SSL_VERIFYHOST => $this->sslVerifyEnabled() ? 2 : 0,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60
        ));

        $response = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $headerSize = intval(curl_getinfo($ch, CURLINFO_HEADER_SIZE));
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || !in_array($httpCode, array(200, 204, 404), true)) {
            $responseHeaders = '';
            $responseBody = '';
            if (is_string($response) && $headerSize > 0) {
                $responseHeaders = substr($response, 0, $headerSize);
                $responseBody = substr($response, $headerSize);
            } elseif (is_string($response)) {
                $responseBody = $response;
            }

            $message = '删除失败，HTTP状态码：' . $httpCode;
            if ($curlError !== '') {
                $message .= '，cURL错误：' . $curlError;
            }

            $regionHint = $this->getResponseHeaderValue($responseHeaders, 'x-amz-bucket-region');
            if ($regionHint !== '' && strcasecmp($regionHint, $this->region) !== 0) {
                $message .= '，建议 Region：' . $regionHint;
            }

            $bodyHint = $this->compactResponseBody($responseBody);
            if ($bodyHint !== '') {
                $message .= '，响应：' . $bodyHint;
            }

            Enhancement_S3Upload_Utils::log($message, 'error');
            return false;
        }

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
        $encodedPath = ltrim($this->encodePath($objectKey), '/');
        $protocol = $this->useHttps() ? 'https://' : 'http://';

        $customDomain = $this->normalizeHost(isset($this->settings->s3_custom_domain) ? (string)$this->settings->s3_custom_domain : '');
        if ($customDomain !== '') {
            return $protocol . $customDomain . '/' . $encodedPath;
        }

        if ($this->useVirtualStyle()) {
            return $protocol . $this->bucket . '.' . $this->endpoint . '/' . $encodedPath;
        }

        return $protocol . $this->endpoint . '/' . str_replace('%7E', '~', rawurlencode($this->bucket)) . '/' . $encodedPath;
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
