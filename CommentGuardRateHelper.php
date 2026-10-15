<?php

class Enhancement_CommentGuardRateHelper
{
    private const FILE_PREFIX = "<?php exit; ?>\n";
    private const MAX_BUCKETS = 10000;
    private const MAX_STORE_BYTES = 2097152;

    public static function storePath(): string
    {
        $options = self::options();
        return self::pathForOptions($options);
    }

    public static function check($ip, $text, $settings, $storePath = null, $now = null): void
    {
        $rateEnabled = self::setting($settings, 'comment_guard_rate_limit', '1') == '1';
        $duplicatesEnabled = self::setting($settings, 'comment_guard_block_duplicates', '1') == '1';
        if (!$rateEnabled && !$duplicatesEnabled) {
            return;
        }

        $ip = Enhancement_CommentGuardContentHelper::normalizeIp($ip);
        if ($ip === '') {
            throw new Typecho_Widget_Exception(_t('无法识别评论来源 IP，请稍后重试。'), 400);
        }

        $options = self::options();
        $secret = (string)$options->secret;
        $storePath = $storePath === null ? self::pathForOptions($options) : (string)$storePath;
        $now = $now === null ? time() : max(0, (int)$now);
        $window = self::boundedSetting($settings, 'comment_guard_window', 300, 10, 86400);
        $ipLimit = self::boundedSetting($settings, 'comment_guard_ip_limit', 5, 1, 1000);
        $subnetLimit = self::boundedSetting($settings, 'comment_guard_subnet_limit', 20, 1, 10000);
        $until = ((int)floor($now / $window) + 1) * $window;
        // A saved window change takes effect immediately; old buckets expire normally.
        $namespace = $window . "\0";
        $ipKey = 'i:' . hash_hmac('sha256', $namespace . $ip, $secret);
        $binaryIp = inet_pton($ip);
        $subnet = strlen($binaryIp) === 4 ? substr($binaryIp, 0, 3) : substr($binaryIp, 0, 8);
        $subnetKey = 's:' . hash_hmac('sha256', $namespace . $subnet, $secret);
        $duplicateKey = 'd:' . hash_hmac('sha256', $namespace . $ip . "\0" . self::normalizeText($text), $secret);

        $handle = @fopen($storePath, 'c+b');
        if (!$handle) {
            self::storageFailure();
        }

        $locked = false;
        $rejection = '';
        $retryAfter = 1;
        try {
            if (!@chmod($storePath, 0600) || !@flock($handle, LOCK_EX)) {
                self::storageFailure();
            }
            $locked = true;
            $stat = @fstat($handle);
            if (!$stat || $stat['size'] > self::MAX_STORE_BYTES) {
                self::storageFailure();
            }
            $raw = stream_get_contents($handle, self::MAX_STORE_BYTES + 1);
            if ($raw === false || strlen($raw) > self::MAX_STORE_BYTES) {
                self::storageFailure();
            }
            $buckets = self::readBuckets($raw, $now);

            if ($rateEnabled) {
                foreach (array($ipKey => $ipLimit, $subnetKey => $subnetLimit) as $key => $limit) {
                    if (isset($buckets[$key]) && $buckets[$key]['count'] >= $limit) {
                        $rejection = _t('评论提交过于频繁，请稍后重试。');
                        $retryAfter = max($retryAfter, $buckets[$key]['until'] - $now);
                    }
                }
            }
            if ($duplicatesEnabled && isset($buckets[$duplicateKey])) {
                $rejection = _t('请勿重复提交相同的评论。');
                $retryAfter = max($retryAfter, $buckets[$duplicateKey]['until'] - $now);
            }

            // Rejected attempts still consume the IP and subnet allowance.
            $updates = $rateEnabled ? array($ipKey => $ipLimit, $subnetKey => $subnetLimit) : array();
            if ($duplicatesEnabled && $rejection === '') {
                $updates[$duplicateKey] = 1;
            }
            $newBuckets = 0;
            foreach ($updates as $key => $limit) {
                if (!isset($buckets[$key])) {
                    $newBuckets++;
                }
            }
            if (count($buckets) + $newBuckets > self::MAX_BUCKETS) {
                self::storageFailure();
            }
            foreach ($updates as $key => $limit) {
                if (isset($buckets[$key])) {
                    $buckets[$key]['count'] = min($limit + 1, $buckets[$key]['count'] + 1);
                } else {
                    $buckets[$key] = array('until' => $until, 'count' => 1);
                }
            }

            $json = json_encode(array('version' => 1, 'buckets' => (object)$buckets));
            if ($json === false) {
                self::storageFailure();
            }
            $payload = self::FILE_PREFIX . $json;
            if (strlen($payload) > self::MAX_STORE_BYTES || !@rewind($handle)) {
                self::storageFailure();
            }
            $written = 0;
            $length = strlen($payload);
            while ($written < $length) {
                $bytes = @fwrite($handle, substr($payload, $written));
                if ($bytes === false || $bytes === 0) {
                    self::storageFailure();
                }
                $written += $bytes;
            }
            if (!@ftruncate($handle, $length) || !@fflush($handle)) {
                self::storageFailure();
            }
        } finally {
            if ($locked) {
                @flock($handle, LOCK_UN);
            }
            @fclose($handle);
        }

        if ($rejection !== '') {
            self::retryAfter($retryAfter);
            throw new Typecho_Widget_Exception($rejection, self::rateLimitStatus());
        }
    }

    private static function options()
    {
        try {
            if (class_exists('Typecho_Widget')) {
                $options = Typecho_Widget::widget('Widget_Options');
            } elseif (class_exists('Widget_Options')) {
                $options = Widget_Options::alloc();
            } else {
                self::storageFailure();
            }
            if (!is_object($options) || trim((string)$options->secret) === '') {
                self::storageFailure();
            }
            return $options;
        } catch (Throwable $e) {
            self::storageFailure();
        }
    }

    private static function pathForOptions($options): string
    {
        $siteUrl = (string)$options->siteUrl;
        $siteKey = hash('sha256', __DIR__ . "\0" . $siteUrl . "\0" . (string)$options->secret);
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'typecho-enhancement-comment-guard-' . $siteKey . '.php';
    }

    private static function readBuckets($raw, $now): array
    {
        if ($raw === '') {
            return array();
        }
        if (substr($raw, 0, strlen(self::FILE_PREFIX)) !== self::FILE_PREFIX) {
            self::storageFailure();
        }
        $data = json_decode(substr($raw, strlen(self::FILE_PREFIX)), true);
        if (!is_array($data) || !isset($data['version']) || $data['version'] !== 1
            || !isset($data['buckets']) || !is_array($data['buckets'])
            || count($data['buckets']) > self::MAX_BUCKETS) {
            self::storageFailure();
        }
        $buckets = $data['buckets'];
        foreach ($buckets as $key => $bucket) {
            if (!preg_match('/^[isd]:[a-f0-9]{64}$/D', (string)$key)
                || !is_array($bucket) || !isset($bucket['until'], $bucket['count'])
                || !is_int($bucket['until']) || !is_int($bucket['count'])
                || $bucket['until'] < 1 || $bucket['count'] < 1) {
                self::storageFailure();
            }
            if ($bucket['until'] <= $now) {
                unset($buckets[$key]);
            }
        }
        return $buckets;
    }

    private static function normalizeText($text): string
    {
        $text = (string)$text;
        $normalized = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text);
        if ($normalized === null) {
            return trim($text);
        }
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        return trim($normalized);
    }

    private static function setting($settings, $name, $default)
    {
        if (is_array($settings)) {
            return isset($settings[$name]) ? $settings[$name] : $default;
        }
        return isset($settings->{$name}) ? $settings->{$name} : $default;
    }

    private static function boundedSetting($settings, $name, $default, $minimum, $maximum): int
    {
        $value = self::setting($settings, $name, $default);
        return max($minimum, min($maximum, is_numeric($value) ? (int)$value : $default));
    }

    private static function retryAfter($seconds): void
    {
        try {
            if (class_exists('Typecho_Response')) {
                Typecho_Response::getInstance()->setHeader('Retry-After', (string)max(1, (int)$seconds));
            }
        } catch (Throwable $e) {
            // The rate decision remains valid if a response has already been sent.
        }
    }

    private static function rateLimitStatus(): int
    {
        try {
            if (class_exists('Typecho_Response', false)) {
                $codes = (new ReflectionClass('Typecho_Response'))->getConstant('HTTP_CODE');
                if (is_array($codes) && !array_key_exists(429, $codes)) {
                    return 403;
                }
            }
        } catch (Throwable $e) {
            // Versions without an inspectable status table can use the standard status.
        }
        return 429;
    }

    private static function storageFailure(): void
    {
        throw new Typecho_Widget_Exception(_t('评论防御暂时无法记录提交频率，请稍后重试。'), 503);
    }
}
