<?php

/** Cached RSS/Atom articles from approved friend links. No schema migration is needed. */
class Enhancement_FriendPostsHelper
{
    public static function settings()
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        return (object) array(
            'enable_friend_posts' => !isset($settings->enable_friend_posts) || (string)$settings->enable_friend_posts === '1',
            'friend_posts_interval' => max(5, min(1440, isset($settings->friend_posts_interval) ? (int)$settings->friend_posts_interval : 60)),
            'friend_posts_per_friend' => max(1, min(20, isset($settings->friend_posts_per_friend) ? (int)$settings->friend_posts_per_friend : 5)),
            'friend_posts_feeds' => isset($settings->friend_posts_feeds) ? (string)$settings->friend_posts_feeds : ''
        );
    }

    public static function validateSettings(array $settings)
    {
        foreach (array('friend_posts_interval' => array(5, 1440), 'friend_posts_per_friend' => array(1, 20)) as $key => $range) {
            if (isset($settings[$key]) && (!is_scalar($settings[$key])
                || !preg_match('/^\d+$/D', (string)$settings[$key])
                || (int)$settings[$key] < $range[0] || (int)$settings[$key] > $range[1])) {
                throw new Typecho_Widget_Exception(_t('朋友文章设置：%s 必须为 %d 到 %d 之间的整数。', $key, $range[0], $range[1]));
            }
        }
        if (isset($settings['enable_friend_posts']) && (!is_scalar($settings['enable_friend_posts'])
            || !in_array((string)$settings['enable_friend_posts'], array('0', '1'), true))) {
            throw new Typecho_Widget_Exception(_t('朋友文章开关无效。'));
        }
        if (isset($settings['friend_posts_feeds'])) {
            if (!is_string($settings['friend_posts_feeds']) || strlen($settings['friend_posts_feeds']) > 200000) {
                throw new Typecho_Widget_Exception(_t('朋友文章订阅设置过长。'));
            }
            self::overrides($settings['friend_posts_feeds'], true);
        }
    }

    private static function overrides($text, $strict = false)
    {
        $result = array();
        foreach (preg_split('/\R/u', trim((string)$text)) ?: array() as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line);
            $site = count($parts) === 2 ? self::siteKey(trim($parts[0])) : '';
            $feed = count($parts) === 2 ? trim($parts[1]) : '';
            $feed = $feed === '-' ? '-' : Enhancement_FriendFeedHelper::normalizeUrl($feed);
            if ($site === '' || $feed === '' || isset($result[$site]) || strlen($line) > 4096) {
                if ($strict) {
                    throw new Typecho_Widget_Exception(_t('朋友文章订阅第 %d 行无效：请填写“友链地址|RSS 地址”，排除站点用“友链地址|-”，每个站点只填一次。', $index + 1));
                }
                continue;
            }
            $result[$site] = $feed;
        }
        return $result;
    }

    private static function siteKey($url)
    {
        return rtrim(Enhancement_FriendFeedHelper::normalizeUrl($url), '/');
    }

    private static function friends($settings)
    {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll($db->select()->from('table.links')
            ->where('state = ?', '1')->order('table.links.order', Typecho_Db::SORT_ASC));
        $overrides = self::overrides($settings->friend_posts_feeds);
        $friends = array();
        foreach ($rows as $row) {
            $url = Enhancement_FriendFeedHelper::normalizeUrl(isset($row['url']) ? $row['url'] : '');
            $key = self::siteKey($url);
            if ((string)$row['state'] !== '1' || $url === '' || (int)$row['lid'] < 1
                || (isset($overrides[$key]) && $overrides[$key] === '-')) {
                continue;
            }
            $friends[] = array(
                'lid' => (int)$row['lid'],
                'name' => trim(strip_tags((string)$row['name'])),
                'url' => $url,
                'image' => Enhancement_FriendFeedHelper::normalizeUrl(isset($row['image']) ? $row['image'] : ''),
                'feed' => isset($overrides[$key]) ? $overrides[$key] : ''
            );
        }
        return $friends;
    }

    private static function cacheDirectory()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $root = defined('__TYPECHO_ROOT_DIR__') ? __TYPECHO_ROOT_DIR__ : __DIR__;
        $key = hash('sha256', $root . "\0" . (string)$options->siteUrl);
        $directory = rtrim(sys_get_temp_dir(), '/\\') . '/typecho-enhancement-friends-' . $key;
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true))) {
            throw new RuntimeException('朋友文章缓存目录不可写。');
        }
        return $directory;
    }

    private static function cacheFile(array $friend)
    {
        return self::cacheDirectory() . '/' . hash('sha256', $friend['lid'] . "\0" . $friend['url'] . "\0" . $friend['feed']) . '.json';
    }

    private static function readCache($file)
    {
        $empty = array('items' => array(), 'feed_url' => '', 'checked' => 0, 'updated' => 0, 'error' => '');
        if (!is_file($file) || is_link($file) || filesize($file) > 2097152) {
            return $empty;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return $empty;
        }
        return array_merge($empty, $data);
    }

    private static function writeCache($file, array $cache)
    {
        $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $temporary = @tempnam(dirname($file), '.feed-');
        if ($temporary === false) {
            throw new RuntimeException('无法写入朋友文章缓存。');
        }
        @chmod($temporary, 0600);
        if (!is_string($json) || @file_put_contents($temporary, $json) !== strlen($json) || !@rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('无法写入朋友文章缓存。');
        }
    }

    /** A shared lock and oldest-check-first order bound public work to one feed per view. */
    public static function refresh($limit = 1, $lid = 0)
    {
        $settings = self::settings();
        if (!$settings->enable_friend_posts) {
            return array('success' => false, 'message' => '朋友文章功能已关闭。');
        }
        $friends = self::friends($settings);
        $jobs = array();
        foreach ($friends as $friend) {
            $file = self::cacheFile($friend);
            $cache = self::readCache($file);
            if ((int)$lid > 0 ? $friend['lid'] === (int)$lid : self::isDue($cache, $settings)) {
                $jobs[] = array('friend' => $friend, 'file' => $file, 'cache' => $cache);
            }
        }
        usort($jobs, static function ($left, $right) {
            $order = (int)$left['cache']['checked'] <=> (int)$right['cache']['checked'];
            return $order !== 0 ? $order : $left['friend']['lid'] <=> $right['friend']['lid'];
        });
        if (!$jobs) {
            return array('success' => (int)$lid === 0, 'message' => (int)$lid > 0 ? '友链不存在、尚未审核或已排除。' : '订阅缓存均在有效期内。');
        }
        $lockPath = self::cacheDirectory() . '/refresh.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('朋友文章缓存锁无效。');
        }
        $lock = @fopen($lockPath, 'c');
        if (!$lock) {
            throw new RuntimeException('无法锁定朋友文章缓存。');
        }
        @chmod($lockPath, 0600);
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return array('success' => false, 'message' => '其他请求正在更新订阅，请稍后重试。');
        }
        $success = true;
        $message = '订阅已更新。';
        try {
            foreach (array_slice($jobs, 0, max(1, min(100, (int)$limit))) as $job) {
                $cache = self::readCache($job['file']);
                if ((int)$lid === 0 && !self::isDue($cache, $settings)) {
                    continue;
                }
                $cache['checked'] = time();
                try {
                    // Remember discovered feeds, but rediscover after a failed attempt.
                    $feed = $job['friend']['feed'];
                    if ($feed === '' && $cache['error'] === '') {
                        $feed = $cache['feed_url'];
                    }
                    $result = Enhancement_FriendFeedHelper::fetch($job['friend']['url'], $feed);
                    $cache['feed_url'] = $result['url'];
                    $cache['items'] = array_slice($result['items'], 0, 50);
                    $cache['updated'] = time();
                    $cache['error'] = '';
                } catch (Throwable $error) {
                    // A temporarily broken feed must not erase the last successful result.
                    $message = strip_tags($error->getMessage());
                    $cache['error'] = function_exists('mb_substr') ? mb_substr($message, 0, 300, 'UTF-8') : substr($message, 0, 900);
                    $success = false;
                    $message = $cache['error'];
                }
                self::writeCache($job['file'], $cache);
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
        return array('success' => $success, 'message' => $message);
    }

    private static function isDue(array $cache, $settings)
    {
        return (int)$cache['checked'] <= time() - $settings->friend_posts_interval * 60;
    }

    public static function status()
    {
        $settings = self::settings();
        $result = array('enabled' => (bool)$settings->enable_friend_posts, 'updated' => 0, 'feeds' => array());
        foreach (self::friends($settings) as $friend) {
            $cache = self::readCache(self::cacheFile($friend));
            $result['updated'] = max($result['updated'], (int)$cache['updated']);
            $result['feeds'][] = array(
                'lid' => $friend['lid'], 'name' => $friend['name'], 'url' => $friend['url'],
                'feed_url' => $friend['feed'] !== '' ? $friend['feed'] : $cache['feed_url'],
                'count' => count($cache['items']), 'checked' => (int)$cache['checked'],
                'updated' => (int)$cache['updated'], 'error' => $cache['error'],
                'due' => self::isDue($cache, $settings)
            );
        }
        return $result;
    }

    public static function getPosts($pageSize = 20, $page = 1)
    {
        $settings = self::settings();
        $result = array('enabled' => (bool)$settings->enable_friend_posts, 'items' => array(), 'total' => 0,
            'page' => 1, 'pageSize' => max(1, min(100, (int)$pageSize)), 'pages' => 1, 'updated' => 0);
        if (!$result['enabled']) {
            return $result;
        }
        try {
            self::refresh();
        } catch (Throwable $error) {
            // Public pages can still show cached articles if a refresh fails.
        }
        $items = array();
        $seen = array();
        try {
            foreach (self::friends($settings) as $friend) {
                $cache = self::readCache(self::cacheFile($friend));
                $result['updated'] = max($result['updated'], (int)$cache['updated']);
                $friendCount = 0;
                foreach ($cache['items'] as $item) {
                    if (!is_array($item) || empty($item['title']) || empty($item['url'])) {
                        continue;
                    }
                    $url = Enhancement_FriendFeedHelper::normalizeUrl($item['url']);
                    $published = isset($item['published']) ? (int)$item['published'] : 0;
                    if ($url === '' || isset($seen[$url]) || $published > time()) {
                        continue;
                    }
                    $seen[$url] = true;
                    $publicFriend = $friend;
                    unset($publicFriend['feed']);
                    $items[] = array(
                        'title' => (string)$item['title'], 'url' => $url, 'published' => max(0, $published),
                        'summary' => isset($item['summary']) ? (string)$item['summary'] : '',
                        'author' => isset($item['author']) ? (string)$item['author'] : '', 'friend' => $publicFriend
                    );
                    if (++$friendCount >= $settings->friend_posts_per_friend) {
                        break;
                    }
                }
            }
        } catch (Throwable $error) {
            $result['error'] = '朋友文章暂时无法读取。';
        }
        usort($items, static function ($left, $right) {
            $order = $right['published'] <=> $left['published'];
            return $order !== 0 ? $order : strcmp($left['url'], $right['url']);
        });
        $result['total'] = count($items);
        $result['pages'] = max(1, (int)ceil($result['total'] / $result['pageSize']));
        $result['page'] = max(1, min($result['pages'], (int)$page));
        $result['items'] = array_slice($items, ($result['page'] - 1) * $result['pageSize'], $result['pageSize']);
        return $result;
    }
}
