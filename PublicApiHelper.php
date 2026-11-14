<?php

/** Shared public endpoint quotas, bounded music caching and Meting responses. */
trait Enhancement_PublicApiHelper
{
    private function publicStorageDirectory()
    {
        $site = is_object($this->options) && isset($this->options->siteUrl) ? (string)$this->options->siteUrl : '';
        $directory = rtrim(sys_get_temp_dir(), '/\\') . '/enhancement-public-' . hash('sha256', __DIR__ . "\0" . $site);
        if (!is_dir($directory)) { @mkdir($directory, 0700, true); }
        if (is_link($directory) || !is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('公共接口暂时不可用，请稍后重试。', 503);
        }
        return $directory;
    }

    /** Keep the lock on one inode throughout the complete read/check/write operation. */
    private function consumePublicRateLimit($file, array $limits, $window, &$retryAfter, $legacyFile = '')
    {
        if (is_link($file)) {
            throw new RuntimeException('公共接口暂时不可用，请稍后重试。', 503);
        }
        $handle = @fopen($file, 'c+');
        if (!$handle) {
            throw new RuntimeException('公共接口暂时不可用，请稍后重试。', 503);
        }
        try {
            @chmod($file, 0600);
            if (!@flock($handle, LOCK_EX) || !rewind($handle)) {
                throw new RuntimeException('公共接口暂时不可用，请稍后重试。', 503);
            }
            $raw = stream_get_contents($handle, 2097153);
            if ($raw === '' && $legacyFile !== '' && is_file($legacyFile) && !is_link($legacyFile)) {
                $raw = @file_get_contents($legacyFile, false, null, 0, 2097153);
            }
            $records = $raw === '' ? array() : json_decode((string)$raw, true);
            if (!is_string($raw) || strlen($raw) > 2097152 || !is_array($records)) {
                throw new RuntimeException('公共接口暂时不可用，请稍后重试。', 503);
            }
            $now = time();
            foreach ($records as $key => $timestamps) {
                if (!is_array($timestamps)) {
                    unset($records[$key]);
                    continue;
                }
                $records[$key] = array_values(array_filter($timestamps, static function ($timestamp) use ($now, $window) {
                    return is_numeric($timestamp) && (int)$timestamp > $now - $window && (int)$timestamp <= $now;
                }));
                if (!$records[$key]) { unset($records[$key]); }
            }
            $allowed = true;
            foreach ($limits as $key => $maximum) {
                if (count($records[$key] ?? array()) >= $maximum) {
                    $retryAfter = max(1, $window - ($now - min($records[$key])));
                    $allowed = false;
                    break;
                }
            }
            // Bound records even when clients rotate source addresses.
            if ($allowed && count($records) + count(array_diff_key($limits, $records)) > 4096) {
                $allowed = false;
                $retryAfter = $window;
            }
            if ($allowed) {
                foreach ($limits as $key => $maximum) { $records[$key][] = $now; }
            }
            $json = json_encode($records);
            if (!is_string($json) || strlen($json) > 2097152 || !rewind($handle) || !ftruncate($handle, 0)
                || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('公共接口暂时不可用，请稍后重试。', 503);
            }
            if ($legacyFile !== '' && is_file($legacyFile) && !is_link($legacyFile)) { @unlink($legacyFile); }
            return $allowed;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function metingCacheFile($key)
    {
        $root = defined('__TYPECHO_ROOT_DIR__') ? (string)__TYPECHO_ROOT_DIR__ : dirname(__DIR__, 3);
        $directory = rtrim(str_replace('\\', '/', $root), '/') . '/usr/cache';
        if (!is_dir($directory)) { @mkdir($directory, 0755, true); }
        if (is_link($directory) || !is_dir($directory) || !is_writable($directory)) {
            return '';
        }
        return $directory . '/enhancement_meting_' . md5($key) . '.json';
    }

    private function metingCacheGet($key, $ttl = 300)
    {
        $now = time();
        if (isset(self::$metingCache[$key]['expires'], self::$metingCache[$key]['data'])
            && self::$metingCache[$key]['expires'] > $now) {
            return self::$metingCache[$key]['data'];
        }
        $file = $this->metingCacheFile($key);
        if ($file !== '') { clearstatcache(true, $file); }
        if ($file === '' || is_link($file) || !is_file($file) || filesize($file) > 2097152) { return null; }
        $data = json_decode((string)@file_get_contents($file), true);
        $expires = is_array($data) ? (int)($data['expires'] ?? ((int)($data['time'] ?? 0) + $ttl)) : 0;
        if (!$data || $expires <= $now || !isset($data['data'])) {
            @unlink($file);
            return null;
        }
        self::$metingCache[$key] = array('expires' => $expires, 'data' => $data['data']);
        return $data['data'];
    }

    private function metingCacheSet($key, $data, $ttl = 300)
    {
        $file = $this->metingCacheFile($key);
        $cache = array('expires' => time() + $ttl, 'data' => $data);
        $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($file === '' || !is_string($json) || strlen($json) > 2097152) { return; }
        $lockFile = $this->publicStorageDirectory() . '/meting-cache.lock';
        if (is_link($lockFile) || !($lock = @fopen($lockFile, 'c'))) { return; }
        try {
            @chmod($lockFile, 0600);
            if (!flock($lock, LOCK_EX)) { return; }
            $files = array();
            $bytes = 0;
            foreach (glob(dirname($file) . '/enhancement_meting_*.json') ?: array() as $candidate) {
                if (is_link($candidate) || !is_file($candidate)) { continue; }
                $header = (string)@file_get_contents($candidate, false, null, 0, 128);
                $expires = preg_match('/"expires"\s*:\s*(\d+)/', $header, $matches) ? (int)$matches[1] : (int)filemtime($candidate) + 86400;
                $size = (int)filesize($candidate);
                if ($expires <= time() || $size > 2097152 || $candidate === $file) {
                    @unlink($candidate);
                    continue;
                }
                $files[$candidate] = array('time' => (int)filemtime($candidate), 'bytes' => $size);
                $bytes += $size;
            }
            uasort($files, static function ($left, $right) { return $left['time'] <=> $right['time']; });
            foreach ($files as $candidate => $metadata) {
                if (count($files) < 1024 && $bytes + strlen($json) <= 33554432) { break; }
                if (@unlink($candidate)) {
                    $bytes -= $metadata['bytes'];
                    unset($files[$candidate]);
                }
            }
            if (count($files) >= 1024 || $bytes + strlen($json) > 33554432) { return; }
            $temporary = @tempnam(dirname($file), '.enhancement-meting-');
            if ($temporary === false) { return; }
            @chmod($temporary, 0600);
            if (@file_put_contents($temporary, $json) === strlen($json) && @rename($temporary, $file)) {
                self::$metingCache[$key] = $cache;
            } else {
                @unlink($temporary);
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Cache hits are unrestricted; only work that calls an upstream consumes a quota. */
    private function metingCachedFetch($key, $ttl, $search, callable $fetch)
    {
        $data = $this->metingCacheGet($key, $ttl);
        if ($data !== null) { return $data; }
        $directory = $this->publicStorageDirectory();
        // A fixed number of striped locks prevents a lock file for every attacker-controlled key.
        $lockFile = $directory . '/meting-key-' . (hexdec(substr(md5($key), 0, 2)) % 32) . '.lock';
        if (is_link($lockFile) || !($keyLock = @fopen($lockFile, 'c'))) {
            throw new RuntimeException('音乐接口暂时不可用。', 503);
        }
        $slot = null;
        try {
            @chmod($lockFile, 0600);
            $waitUntil = microtime(true) + 2;
            $acquired = false;
            do {
                if (@flock($keyLock, LOCK_EX | LOCK_NB)) { $acquired = true; break; }
                usleep(50000);
            } while (microtime(true) < $waitUntil);
            if (!$acquired) { throw new RuntimeException('音乐数据正在更新，请稍后重试。', 503); }
            $data = $this->metingCacheGet($key, $ttl);
            if ($data !== null) { return $data; }
            for ($index = 0; $index < 4; $index++) {
                $slotPath = $directory . '/meting-slot-' . $index . '.lock';
                if (is_link($slotPath)) { continue; }
                $candidate = @fopen($slotPath, 'c');
                if (!$candidate) { continue; }
                @chmod($slotPath, 0600);
                if (@flock($candidate, LOCK_EX | LOCK_NB)) { $slot = $candidate; break; }
                fclose($candidate);
            }
            if (!$slot) { throw new RuntimeException('音乐接口繁忙，请稍后重试。', 503); }
            $ipKey = hash('sha256', $this->getClientIpAddress());
            $limits = array('global' => 600, 'ip:' . $ipKey => 120);
            if ($search) { $limits['search:' . $ipKey] = 12; }
            $retryAfter = 0;
            if (!$this->consumePublicRateLimit($directory . '/meting-rate.json', $limits, 60, $retryAfter)) {
                $this->response->setHeader('Retry-After', (string)$retryAfter);
                throw new RuntimeException('音乐请求过于频繁，请稍后再试。', 429);
            }
            $data = $fetch();
            $this->metingCacheSet($key, $data, $ttl);
            return $data;
        } finally {
            if ($slot) { @flock($slot, LOCK_UN); fclose($slot); }
            @flock($keyLock, LOCK_UN);
            fclose($keyLock);
        }
    }

    private function metingApiResponseJson($data, $status = 200)
    {
        $this->metingApiDiscardBufferedOutput();
        $this->response->setStatus(intval($status));
        $this->response->setContentType('application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function metingApiResponseError($message, $status = 400)
    {
        $this->metingApiResponseJson(array('error' => trim((string)$message)), $status);
    }

    private function metingApiBeginOutputGuard()
    {
        $this->metingOutputBaseLevel = ob_get_level();
        @ob_start();
        $this->metingDisplayErrorsBackup = ini_get('display_errors');
        $this->metingErrorReportingBackup = error_reporting();
        @ini_set('display_errors', '0');
        @error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    }

    private function metingApiDiscardBufferedOutput()
    {
        $base = max(0, intval($this->metingOutputBaseLevel));
        while (ob_get_level() > $base) {
            @ob_end_clean();
        }
    }

    private function metingApiEndOutputGuard()
    {
        $this->metingApiDiscardBufferedOutput();

        if ($this->metingDisplayErrorsBackup !== null) {
            @ini_set('display_errors', (string)$this->metingDisplayErrorsBackup);
        }

        if ($this->metingErrorReportingBackup !== null) {
            @error_reporting(intval($this->metingErrorReportingBackup));
        }
    }

    private function metingApiNormalizeServer($server)
    {
        $server = strtolower(trim((string)$server));
        return in_array($server, array('netease', 'tencent', 'kugou', 'kuwo', 'xiami', 'baidu'), true) ? $server : '';
    }

    private function metingApiNormalizeId($id)
    {
        $id = trim((string)$id);
        if ($id === '' || strlen($id) > 128) {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $id)) {
            return '';
        }

        return $id;
    }

    private function metingApiBuildAudioList($songs)
    {
        if (!is_array($songs)) {
            return array();
        }

        $rows = array();
        foreach ($songs as $song) {
            if (!is_array($song)) {
                continue;
            }

            $source = isset($song['source']) ? trim((string)$song['source']) : '';
            $urlId = isset($song['url_id']) ? trim((string)$song['url_id']) : '';
            $picId = isset($song['pic_id']) ? trim((string)$song['pic_id']) : '';
            $lyricId = isset($song['lyric_id']) ? trim((string)$song['lyric_id']) : '';
            if ($source === '' || $urlId === '' || $picId === '' || $lyricId === '') {
                continue;
            }

            $name = isset($song['name']) ? trim((string)$song['name']) : '';
            $album = isset($song['album']) ? trim((string)$song['album']) : '';
            $artists = array();
            if (isset($song['artist']) && is_array($song['artist'])) {
                foreach ($song['artist'] as $artist) {
                    $artistName = trim((string)$artist);
                    if ($artistName !== '') {
                        $artists[] = $artistName;
                    }
                }
            }

            $base = Typecho_Common::url('action/enhancement-edit', $this->options->index) . '?do=meting-api';
            $rows[] = array(
                'name' => $name,
                'artist' => implode(' / ', $artists),
                'album' => $album,
                'url' => $base . '&server=' . rawurlencode($source) . '&type=url&id=' . rawurlencode($urlId),
                'cover' => $base . '&server=' . rawurlencode($source) . '&type=pic&id=' . rawurlencode($picId),
                'lrc' => $base . '&server=' . rawurlencode($source) . '&type=lrc&id=' . rawurlencode($lyricId)
            );
        }

        return $rows;
    }

    private function metingRedirectUrl($url)
    {
        if (!is_string($url) || $url === '' || strlen($url) > 4096
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url)
            || !filter_var($url, FILTER_VALIDATE_URL)) { return ''; }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)) { return ''; }
        return $url;
    }

    public function metingApi()
    {
        $this->metingApiBeginOutputGuard();
        try {
            if (!in_array(strtoupper((string)$this->request->getServer('REQUEST_METHOD', 'GET')), array('GET', 'HEAD'), true)) {
                $this->response->setHeader('Allow', 'GET, HEAD');
                $this->metingApiResponseError('请使用 GET 请求', 405);
                return;
            }
            if (!Enhancement_Plugin::musicParserEnabled()) {
                $this->metingApiResponseError('音乐解析已关闭', 404);
                return;
            }
            if (!function_exists('curl_init')) {
                $this->metingApiResponseError('缺少 cURL 扩展', 503);
                return;
            }
            $rawServer = $this->request->get('server');
            $rawType = $this->request->get('type');
            $rawId = $this->request->get('id');
            if (!is_string($rawServer) || !is_string($rawType) || !is_string($rawId)) {
                $this->metingApiResponseError('参数格式无效', 400);
                return;
            }
            $server = $this->metingApiNormalizeServer($rawServer);
            $type = strtolower(trim($rawType));
            $id = trim($rawId);
            if ($server === '' || !in_array($type, array('url', 'pic', 'lrc', 'song', 'album', 'artist', 'playlist', 'search'), true)) {
                $this->metingApiResponseError('server 或 type 参数无效', 400);
                return;
            }
            if ($type === 'search') {
                if ($id === '' || strlen($id) > 256 || preg_match('/[\x00-\x1f\x7f]/', $id) || !preg_match('//u', $id)) {
                    $this->metingApiResponseError('搜索关键词无效或过长', 400);
                    return;
                }
            } elseif (($id = $this->metingApiNormalizeId($id)) === '') {
                $this->metingApiResponseError('id 参数无效', 400);
                return;
            }
            require_once __DIR__ . '/Meting/Meting.php';
            $api = new \Metowolf\Meting($server);
            $api->format(true)->timeout(8);
            if ($server === 'netease') {
                $settings = Enhancement_Plugin::runtimeSettings();
                $cookie = isset($settings->music_netease_cookie) ? trim((string)$settings->music_netease_cookie) : '';
                if ($cookie !== '') { $api->cookie($cookie); }
            }
            $ttl = array('url' => 1200, 'pic' => 86400, 'lrc' => 86400, 'search' => 300)[$type] ?? 3600;
            $key = $type . ':' . $server . ':' . ($type === 'search' ? md5($id) : $id);
            $data = $this->metingCachedFetch($key, $ttl, $type === 'search', static function () use ($api, $type, $id) {
                if ($type === 'url') { return $api->url($id, 320); }
                if ($type === 'pic') { return $api->pic($id, 300); }
                if ($type === 'lrc') { return $api->lyric($id); }
                if ($type === 'search') { return $api->search($id, array('limit' => 30, 'page' => 1)); }
                return $api->$type($id);
            });
            $parsed = json_decode((string)$data, true);
            if ($type === 'url' || $type === 'pic') {
                $url = is_array($parsed) && isset($parsed['url']) ? trim((string)$parsed['url']) : '';
                if ($url === '' && $server === 'netease' && $type === 'url') {
                    $url = 'https://music.163.com/song/media/outer/url?id=' . rawurlencode($id) . '.mp3';
                }
                if (($url = $this->metingRedirectUrl($url)) === '') {
                    $this->metingApiResponseError('未找到有效的音乐地址', 502);
                    return;
                }
                $this->metingApiDiscardBufferedOutput();
                $this->response->redirect($url);
                return;
            }
            if ($type === 'lrc') {
                $this->metingApiDiscardBufferedOutput();
                $this->response->setStatus(200)->setContentType('text/plain; charset=UTF-8');
                echo is_array($parsed) && isset($parsed['lyric']) ? (string)$parsed['lyric'] : '';
                return;
            }
            $this->metingApiResponseJson($this->metingApiBuildAudioList($parsed));
        } catch (Throwable $error) {
            $status = in_array((int)$error->getCode(), array(429, 503, 504), true) ? (int)$error->getCode() : 502;
            $this->metingApiResponseError($status === 502 ? '音乐服务暂时不可用' : $error->getMessage(), $status);
        } finally {
            $this->metingApiEndOutputGuard();
        }
    }

}
