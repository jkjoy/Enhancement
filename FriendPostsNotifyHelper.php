<?php

/** Durable friend-feed notification history; callers hold the feed refresh lock. */
class Enhancement_FriendPostsNotifyHelper
{
    private const CHANNELS = array('qq', 'weixin');
    private const LIMIT = 1800;
    private const ATTEMPTS = 4;

    private static function settings(): array
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        return is_object($settings) && method_exists($settings, 'toArray') ? $settings->toArray() : (array)$settings;
    }

    private static function partKey(string $key, string $bank, int $index): string
    {
        $suffix = '';
        do {
            $suffix = chr(97 + $index % 26) . $suffix;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);
        return $key . '_' . $bank . '_' . $suffix;
    }

    private static function readState(string $key): array
    {
        $header = Enhancement_NotifyTransportHelper::read($key);
        if (!isset($header['parts'])) { return $header; }
        if (!is_int($header['parts']) || $header['parts'] < 1
            || !in_array($header['bank'] ?? '', array('a', 'b'), true)
            || !is_string($header['sha256'] ?? null)) { throw new RuntimeException('Invalid notification history header.'); }
        $encoded = '';
        for ($index = 0; $index < $header['parts']; $index++) {
            $part = Enhancement_NotifyTransportHelper::read(self::partKey($key, $header['bank'], $index));
            if (!is_string($part['data'] ?? null)) { throw new RuntimeException('Incomplete notification history.'); }
            $encoded .= $part['data'];
        }
        $json = base64_decode($encoded, true);
        if ($json === false || !hash_equals($header['sha256'], hash('sha256', $json))) {
            throw new RuntimeException('Notification history checksum failed.');
        }
        $state = json_decode($json, true);
        if (!is_array($state) || ($state['identity'] ?? '') !== ($header['identity'] ?? '')
            || ($state['activation'] ?? '') !== ($header['activation'] ?? '')) { throw new RuntimeException('Invalid notification history.'); }
        return $state;
    }

    private static function writeState(string $key, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new RuntimeException('Invalid notification history text.'); }
        $before = Enhancement_NotifyTransportHelper::read($key);
        $sizes = Enhancement_NotifyTransportHelper::read($key . '_parts');
        if (strlen($json) <= 50000) {
            Enhancement_NotifyTransportHelper::write($key, $state);
            foreach (array('a', 'b') as $bank) {
                for ($index = 0; $index < (int)($sizes[$bank] ?? 0); $index++) {
                    Enhancement_NotifyTransportHelper::write(self::partKey($key, $bank, $index), array());
                }
            }
            if ($sizes) { Enhancement_NotifyTransportHelper::write($key . '_parts', array()); }
            return;
        }
        $encoded = base64_encode($json);
        $count = (int)ceil(strlen($encoded) / 40000);
        $bank = ($before['bank'] ?? '') === 'a' ? 'b' : 'a';
        $allocated = (int)($sizes[$bank] ?? 0);
        // Fixed banks preserve the previous committed state when a chunk write fails.
        $sizes[$bank] = max($allocated, $count);
        Enhancement_NotifyTransportHelper::write($key . '_parts', $sizes);
        for ($index = 0; $index < $count; $index++) {
            Enhancement_NotifyTransportHelper::write(self::partKey($key, $bank, $index),
                array('data' => substr($encoded, $index * 40000, 40000)));
        }
        if (Enhancement_NotifyTransportHelper::read($key) !== $before) { throw new RuntimeException('Notification activation changed while saving.'); }
        $header = array('identity' => $state['identity'], 'activation' => $state['activation'],
            'parts' => $count, 'bank' => $bank, 'sha256' => hash('sha256', $json));
        if (isset($state['last_result'])) { $header['last_result'] = $state['last_result']; }
        Enhancement_NotifyTransportHelper::write($key, $header);
        for ($index = $count; $index < $allocated; $index++) {
            Enhancement_NotifyTransportHelper::write(self::partKey($key, $bank, $index), array());
        }
        $sizes[$bank] = $count;
        Enhancement_NotifyTransportHelper::write($key . '_parts', $sizes);
    }

    private static function identity(string $channel, array $settings): string
    {
        if (($settings['enable_friend_posts'] ?? '1') != '1'
            || ($settings['enable_friend_posts_by_' . $channel] ?? '0') != '1') { return ''; }
        if ($channel === 'qq') {
            $binding = Enhancement_QqOfficialHelper::binding($settings);
            return $binding ? hash('sha256', serialize(array(
                Enhancement_QqOfficialHelper::credentialsIdentity($settings), $binding))) : '';
        }
        $account = Enhancement_NotifyTransportHelper::read('weixin_account');
        $recipient = Enhancement_NotifyTransportHelper::read('weixin_recipient');
        if (empty($account['token']) || empty($account['account_id']) || !empty($account['expired'])
            || empty($recipient['user_id']) || (!empty($account['owner_id']) && $account['owner_id'] !== $recipient['user_id'])) { return ''; }
        return hash('sha256', serialize(array($account['account_id'], $account['token'],
            $account['base_url'] ?? '', $account['connected_at'] ?? 0, $recipient['user_id'])));
    }

    public static function syncActivation(array $settings): void
    {
        foreach (self::CHANNELS as $channel) {
            $key = 'friend_posts_' . $channel;
            $state = Enhancement_NotifyTransportHelper::read($key);
            $identity = self::identity($channel, $settings);
            if ($identity === '') {
                if ($state || Enhancement_NotifyTransportHelper::read($key . '_parts')) { self::writeState($key, array()); }
            } elseif (($state['identity'] ?? '') !== $identity || empty($state['activation'])) {
                self::writeState($key, array('identity' => $identity,
                    'activation' => bin2hex(random_bytes(16)), 'feeds' => array(), 'pending' => array(), 'delivered' => array()));
            }
        }
    }

    private static function clean(string $text, int $limit): string
    {
        $text = trim(preg_replace('/[\x00-\x20\x7f]+/u', ' ',
            html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        $text = substr($text, 0, max(0, $limit));
        while ($text !== '' && preg_match('//u', $text) !== 1) { $text = substr($text, 0, -1); }
        return $text;
    }

    private static function article(array $item, array $friend, string $feed, int $now): ?array
    {
        $url = is_string($item['url'] ?? null) ? trim($item['url']) : '';
        $parts = parse_url($url);
        if ($url === '' || preg_match('//u', $url) !== 1 || preg_match('/[\x00-\x20\x7f<>]/', $url)
            || !is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)) { return null; }
        $published = (int)($item['published'] ?? 0);
        // Future entries remain unseen until a later successful fetch says they are due.
        if ($published > $now) { return null; }
        return array('feed' => $feed, 'url' => $url, 'published' => $published,
            'title' => self::clean(is_string($item['title'] ?? null) ? $item['title'] : '', 360),
            'friend' => self::clean(is_string($friend['name'] ?? null) ? $friend['name'] : '', 120),
            'created' => $now, 'attempts' => 0, 'next_at' => 0);
    }

    private static function entry(array $article): string
    {
        $url = $article['url'];
        $available = self::LIMIT - strlen("【朋友文章更新】\n") - strlen($url) - 2;
        if ($available < 0) { return ''; }
        $label = self::clean($article['friend'] . '：' . $article['title'], max(0, $available - 1));
        return ($label !== '' ? $label . "\n" : '') . $url . "\n\n";
    }

    private static function current(string $channel, array $state): bool
    {
        $current = Enhancement_NotifyTransportHelper::read('friend_posts_' . $channel);
        return ($current['activation'] ?? '') === $state['activation']
            && self::identity($channel, self::settings()) === $state['identity'];
    }

    private static function channel(string $channel, array $feeds, array $active, int $now): array
    {
        $key = 'friend_posts_' . $channel;
        $state = self::readState($key);
        $result = array('sent' => 0, 'failed' => 0);
        if (empty($state['identity'])) {
            $settings = self::settings();
            if (($settings['enable_friend_posts'] ?? '1') == '1'
                && ($settings['enable_friend_posts_by_' . $channel] ?? '0') == '1') {
                $result['failed'] = 1;
                $result['message'] = $channel === 'qq' ? 'QQ 朋友文章通知未就绪，请先配置并绑定官方 QQ 机器人接收人。'
                    : (!empty(Enhancement_NotifyTransportHelper::read('weixin_account')['expired'])
                        ? '微信朋友文章通知的登录已失效，请重新扫码并同步接收人。' : '微信朋友文章通知未就绪，请先连接微信并同步接收人。');
            }
            return $result;
        }
        foreach (array_keys($state['feeds']) as $feed) {
            if (!isset($active[$feed])) { unset($state['feeds'][$feed]); }
        }
        foreach ($state['pending'] as $id => $article) {
            if (!isset($active[$article['feed']]) || $article['created'] < $now - 7 * 86400) { unset($state['pending'][$id]); }
        }
        foreach ($feeds as $feed) {
            $feedKey = $feed['key'] ?? '';
            if (!is_string($feedKey) || !isset($active[$feedKey]) || !is_array($feed['items'] ?? null)) { continue; }
            $first = !isset($state['feeds'][$feedKey]);
            $seen = $state['feeds'][$feedKey]['seen'] ?? array();
            foreach (array_slice($feed['items'], 0, 50) as $item) {
                if (!is_array($item)) { continue; }
                $article = self::article($item, (array)($feed['friend'] ?? array()), $feedKey, $now);
                if ($article === null) { continue; }
                $id = hash('sha256', $article['url']);
                if (isset($seen[$id])) { continue; }
                $seen[$id] = 1;
                if (!$first && !isset($state['pending'][$id]) && !isset($state['delivered'][$id])) { $state['pending'][$id] = $article; }
            }
            $state['feeds'][$feedKey] = array('seen' => $seen);
        }
        if (!self::current($channel, $state)) { return $result; }
        self::writeState($key, $state);
        // Keep a refresh bounded even when many friends publish together.
        for ($batch = 0; $batch < 3; $batch++) {
            $text = "【朋友文章更新】\n";
            $selected = array();
            foreach ($state['pending'] as $id => $article) {
                if ($article['attempts'] >= self::ATTEMPTS || $article['next_at'] > $now) { continue; }
                $entry = self::entry($article);
                if ($entry === '') {
                    $state['pending'][$id]['attempts'] = self::ATTEMPTS;
                    $state['pending'][$id]['error'] = '文章链接超过通知长度限制，未发送。';
                    $state['last_result'] = array('success' => false, 'message' => $state['pending'][$id]['error'], 'time' => $now);
                    $result['failed']++;
                    continue;
                }
                if (strlen($text) + strlen($entry) > self::LIMIT) { break; }
                $text .= $entry;
                $selected[] = $id;
            }
            if (!$selected) {
                if (self::current($channel, $state)) { self::writeState($key, $state); }
                break;
            }
            if (!self::current($channel, $state)) { break; }
            foreach ($selected as $id) {
                $attempts = ++$state['pending'][$id]['attempts'];
                $state['pending'][$id]['next_at'] = $now + array(60, 300, 900, 86400)[$attempts - 1];
            }
            // Save the attempt before contacting the provider; a crashed refresh is throttled too.
            self::writeState($key, $state);
            try {
                $settings = self::settings();
                if (!self::current($channel, $state)) { break; }
                $delivery = $channel === 'qq' ? Enhancement_QqOfficialHelper::send($text, $settings)
                    : Enhancement_WeixinNotifyHelper::send($text, $settings);
            } catch (Throwable $exception) {
                $delivery = array('success' => false, 'error' => '通知发送失败，请检查通道连接状态。');
            }
            $success = !empty($delivery['success']);
            $error = $success ? '' : self::clean((string)($delivery['error'] ?? '通知发送失败。'), 500);
            foreach ($selected as $id) {
                if ($success) { unset($state['pending'][$id]); $state['delivered'][$id] = 1; }
                else { $state['pending'][$id]['error'] = $error; }
            }
            $state['last_result'] = array('success' => $success, 'message' => $success ? '朋友文章更新通知已受理。' : $error, 'time' => $now);
            if (self::current($channel, $state)) { self::writeState($key, $state); }
            $result[$success ? 'sent' : 'failed']++;
            if (!$success) { $result['message'] = ($channel === 'qq' ? 'QQ' : '微信') . ' 朋友文章通知失败：' . $error; break; }
        }
        foreach ($state['pending'] as $article) {
            if ($article['attempts'] >= self::ATTEMPTS) {
                $result['failed'] = max(1, $result['failed']);
                $result['message'] = ($channel === 'qq' ? 'QQ' : '微信') . ' 朋友文章通知有失败项已停止自动重试：'
                    . ($article['error'] ?? '已达到最多 4 次发送次数，请检查通道设置。');
                break;
            }
        }
        return $result;
    }

    public static function observe(array $feeds, array $activeFeeds): array
    {
        $result = array('success' => true, 'sent' => 0, 'failed' => 0);
        try {
            self::syncActivation(self::settings());
            $active = array_fill_keys(array_filter($activeFeeds, 'is_string'), true);
        } catch (Throwable $exception) {
            return array('success' => false, 'sent' => 0, 'failed' => 1, 'message' => '朋友文章通知状态无法保存，请检查数据库。');
        }
        $messages = array();
        foreach (self::CHANNELS as $channel) {
            try {
                $channelResult = self::channel($channel, $feeds, $active, time());
                $result['sent'] += $channelResult['sent'];
                $result['failed'] += $channelResult['failed'];
                if (!empty($channelResult['message'])) { $messages[] = $channelResult['message']; }
            } catch (Throwable $exception) {
                $result['failed']++;
                $messages[] = ($channel === 'qq' ? 'QQ' : '微信') . ' 朋友文章通知状态处理失败，请检查数据库。';
            }
        }
        $result['success'] = $result['failed'] === 0;
        if (!$result['success']) { $result['message'] = implode(' ', $messages); }
        return $result;
    }
}
