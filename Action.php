<?php

class Enhancement_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $db;
    private $options;
    private $prefix;

    private function normalizeUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        $parts = @parse_url($url);
        if ($parts === false || !is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : 'http';
        $host = strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        $path = isset($parts['path']) ? (string)$parts['path'] : '/';
        $query = isset($parts['query']) ? (string)$parts['query'] : '';

        if ($path === '') {
            $path = '/';
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }

        $normalized = $scheme . '://' . $host;
        if ($port && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $normalized .= ':' . $port;
        }
        $normalized .= $path;
        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    private function getClientIpAddress()
    {
        $ip = trim((string)$this->request->getIp());
        if ($ip === '') {
            return '0.0.0.0';
        }
        return $ip;
    }

    private function getRateLimitWindowSeconds()
    {
        return 300;
    }

    private function getRateLimitMaxAttempts()
    {
        return 5;
    }

    private function getRateLimitStorePath()
    {
        return __DIR__ . '/runtime/rate-limit-links.json';
    }

    private function checkSubmitRateLimit(&$retryAfter = 0)
    {
        $file = $this->getRateLimitStorePath();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $window = $this->getRateLimitWindowSeconds();
        $maxAttempts = $this->getRateLimitMaxAttempts();
        $now = time();
        $ip = $this->getClientIpAddress();

        $records = array();
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $records = $decoded;
            }
        }

        foreach ($records as $recordIp => $timestamps) {
            if (!is_array($timestamps)) {
                unset($records[$recordIp]);
                continue;
            }
            $records[$recordIp] = array_values(array_filter($timestamps, function ($timestamp) use ($now, $window) {
                $timestamp = (int)$timestamp;
                return $timestamp > 0 && ($now - $timestamp) < $window;
            }));
            if (empty($records[$recordIp])) {
                unset($records[$recordIp]);
            }
        }

        $attempts = isset($records[$ip]) && is_array($records[$ip]) ? count($records[$ip]) : 0;
        if ($attempts >= $maxAttempts) {
            $oldest = isset($records[$ip][0]) ? (int)$records[$ip][0] : $now;
            $retryAfter = max(1, $window - ($now - $oldest));
            @file_put_contents($file, json_encode($records, JSON_UNESCAPED_UNICODE));
            return false;
        }

        if (!isset($records[$ip]) || !is_array($records[$ip])) {
            $records[$ip] = array();
        }
        $records[$ip][] = $now;
        @file_put_contents($file, json_encode($records, JSON_UNESCAPED_UNICODE));
        return true;
    }

    private function denySubmission($message, $statusCode = 400, $retryAfter = 0)
    {
        $message = (string)$message;
        $statusCode = (int)$statusCode;
        if ($statusCode > 0) {
            $this->response->setStatus($statusCode);
        }
        if ($retryAfter > 0) {
            $this->response->setHeader('Retry-After', (string)(int)$retryAfter);
        }

        if ($this->request->isAjax()) {
            $this->response->throwJson(array(
                'success' => false,
                'message' => $message
            ));
            return;
        }

        $this->widget('Widget_Notice')->set($message, null, 'error');
        $this->response->goBack();
    }

    private function sanitizePublicLinkItem(array $item)
    {
        $item['url'] = $this->normalizeUrl(isset($item['url']) ? $item['url'] : '');
        $item['image'] = $this->normalizeUrl(isset($item['image']) ? $item['image'] : '');
        $item['email'] = trim((string)(isset($item['email']) ? $item['email'] : ''));
        $item['name'] = trim((string)(isset($item['name']) ? $item['name'] : ''));
        $item['sort'] = trim((string)(isset($item['sort']) ? $item['sort'] : ''));
        $item['description'] = trim((string)(isset($item['description']) ? $item['description'] : ''));
        $item['user'] = trim((string)(isset($item['user']) ? $item['user'] : ''));

        return $item;
    }

    private function findExistingLinkByUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        return $this->db->fetchRow(
            $this->db->select('lid', 'state')
                ->from($this->prefix . 'links')
                ->where('url = ?', $url)
                ->limit(1)
        );
    }

    public function insertEnhancement()
    {
        if (Enhancement_Plugin::form('insert')->validate()) {
            $this->response->goBack();
        }
        /** 取出数据 */
        $item = $this->request->from('email', 'image', 'url', 'state');

        /** 过滤XSS */
        $item['name'] = $this->request->filter('xss')->name;
        $item['sort'] = $this->request->filter('xss')->sort;
        $item['description'] = $this->request->filter('xss')->description;
        $item['user'] = $this->request->filter('xss')->user;

        $maxOrder = $this->db->fetchObject(
            $this->db->select(array('MAX(order)' => 'maxOrder'))->from($this->prefix . 'links')
        )->maxOrder;
        $item['order'] = intval($maxOrder) + 1;

        /** 插入数据 */
        $item_lid = $this->db->query($this->db->insert($this->prefix . 'links')->rows($item));

        /** 设置高亮 */
        $this->widget('Widget_Notice')->highlight('enhancement-' . $item_lid);

        /** 提示信息 */
        $this->widget('Widget_Notice')->set(_t(
            '友链 <a href="%s">%s</a> 已经被增加',
            $item['url'],
            $item['name']
        ), null, 'success');

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function submitEnhancement()
    {
        if (Enhancement_Plugin::publicForm()->validate()) {
            $this->response->goBack();
        }

        $honeypot = trim((string)$this->request->get('homepage'));
        if ($honeypot !== '') {
            $this->denySubmission(_t('提交失败，请重试'), 400);
            return;
        }

        $retryAfter = 0;
        if (!$this->checkSubmitRateLimit($retryAfter)) {
            $this->denySubmission(_t('提交过于频繁，请稍后再试'), 429, $retryAfter);
            return;
        }

        /** 取出数据 */
        $item = $this->request->from('email', 'image', 'url');

        /** 过滤XSS */
        $item['name'] = $this->request->filter('xss')->name;
        $item['sort'] = $this->request->filter('xss')->sort;
        $item['description'] = $this->request->filter('xss')->description;
        $item['user'] = $this->request->filter('xss')->user;
        $item = $this->sanitizePublicLinkItem($item);

        if (!Enhancement_Plugin::validateHttpUrl($item['url'])) {
            $this->denySubmission(_t('友链地址仅支持 http:// 或 https://'));
            return;
        }
        if (!Enhancement_Plugin::validateOptionalHttpUrl($item['image'])) {
            $this->denySubmission(_t('友链图片仅支持 http:// 或 https://'));
            return;
        }

        $exists = $this->findExistingLinkByUrl($item['url']);
        if ($exists) {
            $message = ((string)$exists['state'] === '1')
                ? _t('该友链已存在，无需重复提交')
                : _t('该友链已提交，正在审核中');

            if ($this->request->isAjax()) {
                $this->response->throwJson(array(
                    'success' => true,
                    'message' => $message,
                    'duplicate' => true,
                    'lid' => (int)$exists['lid']
                ));
            } else {
                $this->widget('Widget_Notice')->set($message, null, 'notice');
                $this->response->goBack('?enhancement_submitted=1');
            }
            return;
        }

        $maxOrder = $this->db->fetchObject(
            $this->db->select(array('MAX(order)' => 'maxOrder'))->from($this->prefix . 'links')
        )->maxOrder;
        $item['order'] = intval($maxOrder) + 1;
        $item['state'] = '0';

        /** 插入数据 */
        $item_lid = $this->db->query($this->db->insert($this->prefix . 'links')->rows($item));

        if ($this->request->isAjax()) {
            $this->response->throwJson(array(
                'success' => true,
                'message' => _t('提交成功，等待审核'),
                'lid' => $item_lid
            ));
        } else {
            $this->response->goBack('?enhancement_submitted=1');
        }
    }

    public function updateEnhancement()
    {
        if (Enhancement_Plugin::form('update')->validate()) {
            $this->response->goBack();
        }

        /** 取出数据 */
        $item = $this->request->from('email', 'image', 'url', 'state');
        $item_lid = $this->request->get('lid');

        /** 过滤XSS */
        $item['name'] = $this->request->filter('xss')->name;
        $item['sort'] = $this->request->filter('xss')->sort;
        $item['description'] = $this->request->filter('xss')->description;
        $item['user'] = $this->request->filter('xss')->user;

        /** 更新数据 */
        $this->db->query($this->db->update($this->prefix . 'links')->rows($item)->where('lid = ?', $item_lid));

        /** 设置高亮 */
        $this->widget('Widget_Notice')->highlight('enhancement-' . $item_lid);

        /** 提示信息 */
        $this->widget('Widget_Notice')->set(_t(
            '友链 <a href="%s">%s</a> 已经被更新',
            $item['url'],
            $item['name']
        ), null, 'success');

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function deleteEnhancement()
    {
        $lids = $this->request->filter('int')->getArray('lid');
        $deleteCount = 0;
        if ($lids && is_array($lids)) {
            foreach ($lids as $lid) {
                if ($this->db->query($this->db->delete($this->prefix . 'links')->where('lid = ?', $lid))) {
                    $deleteCount++;
                }
            }
        }
        /** 提示信息 */
        $this->widget('Widget_Notice')->set(
            $deleteCount > 0 ? _t('记录已经删除') : _t('没有记录被删除'),
            null,
            $deleteCount > 0 ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function approveEnhancement()
    {
        $lids = $this->request->filter('int')->getArray('lid');
        $approveCount = 0;
        if ($lids && is_array($lids)) {
            foreach ($lids as $lid) {
                if ($this->db->query($this->db->update($this->prefix . 'links')->rows(array('state' => '1'))->where('lid = ?', $lid))) {
                    $approveCount++;
                }
            }
        }
        /** 提示信息 */
        $this->widget('Widget_Notice')->set(
            $approveCount > 0 ? _t('已通过审核') : _t('没有记录被通过'),
            null,
            $approveCount > 0 ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function rejectEnhancement()
    {
        $lids = $this->request->filter('int')->getArray('lid');
        $rejectCount = 0;
        if ($lids && is_array($lids)) {
            foreach ($lids as $lid) {
                if ($this->db->query($this->db->update($this->prefix . 'links')->rows(array('state' => '0'))->where('lid = ?', $lid))) {
                    $rejectCount++;
                }
            }
        }
        /** 提示信息 */
        $this->widget('Widget_Notice')->set(
            $rejectCount > 0 ? _t('已驳回') : _t('没有记录被驳回'),
            null,
            $rejectCount > 0 ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function sortEnhancement()
    {
        $items = $this->request->filter('int')->getArray('lid');
        if ($items && is_array($items)) {
            foreach ($items as $sort => $lid) {
                $this->db->query($this->db->update($this->prefix . 'links')->rows(array('order' => $sort + 1))->where('lid = ?', $lid));
            }
        }
    }

    public function emailLogo()
    {
        /* 邮箱头像解API接口 by 懵仙兔兔 */
        $type = $this->request->type;
        $email = trim((string)$this->request->email);

        if ($email == null || $email == '') {
            $this->response->throwJson('请提交邮箱链接 [email=abc@abc.com]');
            exit;
        } else if ($type == null || $type == '' || ($type != 'txt' && $type != 'json')) {
            $this->response->throwJson('请提交type类型 [type=txt, type=json]');
            exit;
        } else {
            $lower = strtolower($email);
            $qqNumber = null;
            if (is_numeric($email)) {
                $qqNumber = $email;
            } elseif (substr($lower, -7) === '@qq.com') {
                $qqNumber = substr($lower, 0, -7);
            }

            if ($qqNumber !== null && is_numeric($qqNumber) && strlen($qqNumber) < 11 && strlen($qqNumber) > 4) {
                stream_context_set_default([
                    'ssl' => [
                        'verify_host' => false,
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                ]);
                $geturl = 'https://s.p.qq.com/pub/get_face?img_type=3&uin=' . $qqNumber;
                $headers = get_headers($geturl, TRUE);
                if ($headers) {
                    $g = $headers['Location'];
                    $g = str_replace("http:", "https:", $g);
                } else {
                    $g = 'https://q.qlogo.cn/g?b=qq&nk=' . $qqNumber . '&s=100';
                }
            } else {
                $g = Enhancement_Plugin::buildAvatarUrl($email, 100, null);
            }
            $r = array('url' => $g);
            if ($type == 'txt') {
                $this->response->throwJson($g);
                exit;
            } else if ($type == 'json') {
                $this->response->throwJson(json_encode($r));
                exit;
            }
        }
    }

    public function insertMoment()
    {
        if (Enhancement_Plugin::momentsForm('insert')->validate()) {
            $this->response->goBack();
        }

        Enhancement_Plugin::ensureMomentsTable();

        $moment = array();
        $moment['content'] = (string)$this->request->get('content');
        $moment['tags'] = $this->request->filter('xss')->tags;
        $moment['created'] = $this->options->time;
        $mediaRaw = $this->request->get('media');
        $mediaRaw = is_string($mediaRaw) ? trim($mediaRaw) : $mediaRaw;
        if (empty($mediaRaw)) {
            $cleanedContent = $moment['content'];
            $mediaItems = Enhancement_Plugin::extractMediaFromContent($moment['content'], $cleanedContent);
            $moment['media'] = !empty($mediaItems) ? json_encode($mediaItems, JSON_UNESCAPED_UNICODE) : null;
            $moment['content'] = $cleanedContent;
        } else {
            $moment['media'] = $mediaRaw;
        }

        try {
            $mid = $this->db->query($this->db->insert($this->prefix . 'moments')->rows($moment));
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set(_t('瞬间发布失败，可能是数据表不存在'), null, 'error');
            $this->response->goBack();
        }

        $this->widget('Widget_Notice')->highlight('moment-' . $mid);
        $this->widget('Widget_Notice')->set(_t('瞬间已发布'), null, 'success');
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
    }

    public function updateMoment()
    {
        if (Enhancement_Plugin::momentsForm('update')->validate()) {
            $this->response->goBack();
        }

        Enhancement_Plugin::ensureMomentsTable();

        $moment = array();
        $moment['content'] = (string)$this->request->get('content');
        $moment['tags'] = $this->request->filter('xss')->tags;
        $mid = $this->request->get('mid');
        $mediaRaw = $this->request->get('media');
        $mediaRaw = is_string($mediaRaw) ? trim($mediaRaw) : $mediaRaw;
        if (empty($mediaRaw)) {
            $cleanedContent = $moment['content'];
            $mediaItems = Enhancement_Plugin::extractMediaFromContent($moment['content'], $cleanedContent);
            $moment['media'] = !empty($mediaItems) ? json_encode($mediaItems, JSON_UNESCAPED_UNICODE) : null;
            $moment['content'] = $cleanedContent;
        } else {
            $moment['media'] = $mediaRaw;
        }

        try {
            $this->db->query($this->db->update($this->prefix . 'moments')->rows($moment)->where('mid = ?', $mid));
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set(_t('瞬间更新失败，可能是数据表不存在'), null, 'error');
            $this->response->goBack();
        }

        $this->widget('Widget_Notice')->highlight('moment-' . $mid);
        $this->widget('Widget_Notice')->set(_t('瞬间已更新'), null, 'success');
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
    }

    public function deleteMoment()
    {
        $mids = $this->request->filter('int')->getArray('mid');
        $deleteCount = 0;
        if ($mids && is_array($mids)) {
            foreach ($mids as $mid) {
                try {
                    if ($this->db->query($this->db->delete($this->prefix . 'moments')->where('mid = ?', $mid))) {
                        $deleteCount++;
                    }
                } catch (Exception $e) {
                    // ignore delete errors
                }
            }
        }
        $this->widget('Widget_Notice')->set(
            $deleteCount > 0 ? _t('瞬间已经删除') : _t('没有瞬间被删除'),
            null,
            $deleteCount > 0 ? 'success' : 'notice'
        );
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
    }

    public function goRedirect()
    {
        $target = $this->request->get('target');
        if (is_array($target)) {
            $target = implode('', $target);
        }

        $target = trim((string)$target);
        if ($target === '') {
            $this->response->setStatus(404);
            echo _t('跳转目标不存在');
            return;
        }

        $url = Enhancement_Plugin::decodeGoTarget($target);
        if ($url === '') {
            $this->response->setStatus(400);
            echo _t('无效的跳转地址');
            return;
        }

        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive');

        $options = Typecho_Widget::widget('Widget_Options');
        $homeUrl = isset($options->siteUrl) ? (string)$options->siteUrl : '/';
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeHomeUrl = htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8');

        echo '<!doctype html>';
        echo '<html lang="zh-CN"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>即将离开本站</title>';
        echo '<style>';
        echo 'body{margin:0;background:#f5f7fb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,sans-serif;color:#1f2937;}';
        echo '.wrap{max-width:560px;margin:8vh auto;padding:24px;}';
        echo '.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 10px 24px rgba(0,0,0,.08);}';
        echo 'h1{margin:0 0 10px;font-size:22px;}';
        echo 'p{margin:8px 0;color:#4b5563;line-height:1.7;word-break:break-all;}';
        echo '.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px;}';
        echo '.btn{display:inline-block;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:500;}';
        echo '.btn-primary{background:#2563eb;color:#fff;}';
        echo '.btn-secondary{background:#eef2ff;color:#1e40af;}';
        echo '.host{display:inline-block;padding:2px 8px;background:#eef2ff;color:#3730a3;border-radius:99px;font-size:12px;}';
        echo '</style></head><body>';
        echo '<div class="wrap"><div class="card">';
        echo '<h1>即将离开本站</h1>';
        echo '<p>你将访问外部网站，请注意账号与隐私安全。</p>';
        echo '<p>目标地址：<span id="target-url">' . $safeUrl . '</span></p>';
        echo '<div class="actions">';
        echo '<a class="btn btn-primary" href="' . $safeUrl . '" rel="noopener noreferrer nofollow" target="_blank">继续访问</a>';
        echo '<a class="btn btn-secondary" href="' . $safeHomeUrl . '">回到首页</a>';
        echo '</div>';
        echo '</div></div>';
        echo '</body></html>';
    }

    public function action()
    {
        $this->db = Typecho_Db::get();
        $this->prefix = $this->db->getPrefix();
        $this->options = Typecho_Widget::widget('Widget_Options');

        $action = $this->request->get('action');
        $pathInfo = $this->request->getPathInfo();
        $hasContent = false;
        $this->request->get('content', null, $hasContent);
        $hasMid = false;
        $this->request->get('mid', null, $hasMid);
        $hasMidArray = !empty($this->request->getArray('mid'));

        if ($action === 'enhancement-submit' || $this->request->is('do=submit')) {
            Helper::security()->protect();
            $this->submitEnhancement();
            return;
        }

        $isMomentsAction = ($action === 'enhancement-moments-edit')
            || (is_string($pathInfo) && strpos($pathInfo, 'enhancement-moments-edit') !== false)
            || $hasContent
            || $hasMid
            || $hasMidArray;

        if ($isMomentsAction) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');

            $this->on($this->request->is('do=insert'))->insertMoment();
            $this->on($this->request->is('do=update'))->updateMoment();
            $this->on($this->request->is('do=delete'))->deleteMoment();
            $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
            return;
        }

        Helper::security()->protect();
        $user = Typecho_Widget::widget('Widget_User');
        $user->pass('administrator');

        $this->on($this->request->is('do=insert'))->insertEnhancement();
        $this->on($this->request->is('do=update'))->updateEnhancement();
        $this->on($this->request->is('do=delete'))->deleteEnhancement();
        $this->on($this->request->is('do=approve'))->approveEnhancement();
        $this->on($this->request->is('do=reject'))->rejectEnhancement();
        $this->on($this->request->is('do=sort'))->sortEnhancement();
        $this->on($this->request->is('do=email-logo'))->emailLogo();
        $this->response->redirect($this->options->adminUrl);
    }
}

/** Enhancement */
