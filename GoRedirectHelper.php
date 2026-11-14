<?php

class Enhancement_GoRedirectHelper
{
    private static function settings()
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        return is_object($settings) ? $settings : (object) array();
    }

    public static function blankTargetEnabled(): bool
    {
        $settings = self::settings();
        return isset($settings->enable_blank_target) && $settings->enable_blank_target == '1';
    }

    public static function goRedirectEnabled(): bool
    {
        $settings = self::settings();
        return !isset($settings->enable_go_redirect) || $settings->enable_go_redirect == '1';
    }

    public static function parseGoRedirectWhitelist(): array
    {
        $settings = self::settings();
        $raw = isset($settings->go_redirect_whitelist) ? (string)$settings->go_redirect_whitelist : '';
        if ($raw === '') {
            return array();
        }

        $parts = preg_split('/[\r\n,，;；\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || empty($parts)) {
            return array();
        }

        $domains = array();
        foreach ($parts as $part) {
            $domain = strtolower(trim((string)$part));
            if ($domain === '') {
                continue;
            }

            if (strpos($domain, '://') !== false) {
                $parsedHost = parse_url($domain, PHP_URL_HOST);
                if (is_string($parsedHost) && $parsedHost !== '') {
                    $domain = strtolower(trim($parsedHost));
                }
            }

            if (strpos($domain, 'www.') === 0) {
                $domain = substr($domain, 4);
            }

            $domain = trim($domain, '.');
            if ($domain === '') {
                continue;
            }

            $domains[$domain] = true;
        }

        return array_keys($domains);
    }

    public static function isWhitelistedHost($host): bool
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return false;
        }

        foreach (self::parseGoRedirectWhitelist() as $domain) {
            $domain = self::normalizeHost($domain);
            if ($domain === '') {
                continue;
            }

            if ($host === $domain) {
                return true;
            }

            if (strlen($host) > strlen($domain) && substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeHost($host)
    {
        $host = strtolower(trim((string)$host));
        if ($host === '') {
            return '';
        }

        if (substr($host, 0, 4) === 'www.') {
            $host = substr($host, 4);
        }

        return $host;
    }

    public static function normalizeExternalUrl($url)
    {
        $url = trim(html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        if (strpos($url, '//') === 0) {
            $options = Typecho_Widget::widget('Widget_Options');
            $siteUrl = isset($options->siteUrl) ? (string)$options->siteUrl : '';
            $siteScheme = (string)parse_url($siteUrl, PHP_URL_SCHEME);
            if ($siteScheme === '') {
                $siteScheme = 'https';
            }
            $url = $siteScheme . ':' . $url;
        } elseif (!preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $url)) {
            $lower = strtolower($url);
            if (
                strpos($lower, 'mailto:') !== 0 &&
                strpos($lower, 'tel:') !== 0 &&
                strpos($lower, 'javascript:') !== 0 &&
                strpos($lower, 'data:') !== 0 &&
                strpos($url, '#') !== 0 &&
                strpos($url, '/') !== 0 &&
                strpos($url, '?') !== 0 &&
                preg_match('/^[^\s\/\?#]+\.[^\s\/\?#]+(?:[\/\?#].*)?$/', $url)
            ) {
                $url = 'http://' . $url;
            }
        }

        return $url;
    }

    public static function shouldUseGoRedirect($url)
    {
        if (!self::goRedirectEnabled()) {
            return false;
        }

        $decoded = self::normalizeExternalUrl($url);
        if ($decoded === '') {
            return false;
        }

        $lower = strtolower($decoded);
        if (strpos($lower, '#') === 0 || strpos($lower, '/') === 0 || strpos($lower, '?') === 0) {
            return false;
        }
        if (
            strpos($lower, 'mailto:') === 0 ||
            strpos($lower, 'tel:') === 0 ||
            strpos($lower, 'javascript:') === 0 ||
            strpos($lower, 'data:') === 0
        ) {
            return false;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $siteUrl = isset($options->siteUrl) ? (string)$options->siteUrl : '';

        $goPrefix = Typecho_Common::url('go/', $options->index);
        if (strpos($decoded, $goPrefix) === 0) {
            return false;
        }

        $parsed = @parse_url($decoded);
        if (!is_array($parsed)) {
            return false;
        }

        $scheme = isset($parsed['scheme']) ? strtolower((string)$parsed['scheme']) : '';
        $host = isset($parsed['host']) ? self::normalizeHost($parsed['host']) : '';
        if (!in_array($scheme, array('http', 'https'), true) || $host === '') {
            return false;
        }

        if (self::isWhitelistedHost($host)) {
            return false;
        }

        $siteHost = self::normalizeHost(parse_url($siteUrl, PHP_URL_HOST));
        if ($siteHost !== '' && $host === $siteHost) {
            return false;
        }

        return true;
    }

    public static function decodeGoRedirectUrl($href): string
    {
        $href = trim(html_entity_decode((string)$href, ENT_QUOTES, 'UTF-8'));
        if ($href === '') {
            return '';
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $goBase = Typecho_Common::url('go/', $options->index);
        $token = '';

        if (strpos($href, $goBase) === 0) {
            $token = (string)substr($href, strlen($goBase));
        } else {
            $goPath = (string)parse_url($goBase, PHP_URL_PATH);
            $hrefPath = parse_url($href, PHP_URL_PATH);
            if (!is_string($hrefPath) || $hrefPath === '') {
                return '';
            }

            $normalizedGoPath = '/' . ltrim($goPath, '/');
            $normalizedHrefPath = '/' . ltrim($hrefPath, '/');
            if ($normalizedGoPath === '/' || $normalizedGoPath === '') {
                return '';
            }
            if (strpos($normalizedHrefPath, $normalizedGoPath) !== 0) {
                return '';
            }

            $token = (string)substr($normalizedHrefPath, strlen($normalizedGoPath));
        }

        $token = ltrim($token, '/');
        if ($token === '') {
            return '';
        }

        $token = preg_replace('/[#\?].*$/', '', $token);
        if (!is_string($token) || $token === '') {
            return '';
        }

        $decoded = self::decodeGoTarget($token);
        if ($decoded !== '') {
            return $decoded;
        }

        if (preg_match('/^(.*?)(?:-?target=_blank.*)$/i', $token, $matches) && isset($matches[1])) {
            $fallbackToken = rtrim((string)$matches[1], '-_');
            if ($fallbackToken !== '') {
                return self::decodeGoTarget($fallbackToken);
            }
        }

        return '';
    }

    public static function normalizeAnchorTagSpacing($tag)
    {
        if (!is_string($tag) || $tag === '') {
            return $tag;
        }

        $tag = preg_replace('/"(?=[A-Za-z_:][A-Za-z0-9:_.-]*\s*=)/', '" ', $tag);
        $tag = preg_replace('/\'(?=[A-Za-z_:][A-Za-z0-9:_.-]*\s*=)/', '\' ', $tag);

        return is_string($tag) ? $tag : '';
    }

    public static function convertExternalUrlToGo($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return $url;
        }

        $decodedGoUrl = self::decodeGoRedirectUrl($url);

        if (!self::goRedirectEnabled()) {
            return $decodedGoUrl !== '' ? $decodedGoUrl : $url;
        }

        if ($decodedGoUrl !== '') {
            if (!self::shouldUseGoRedirect($decodedGoUrl)) {
                return $decodedGoUrl;
            }

            $rebuildGoUrl = self::buildGoRedirectUrl($decodedGoUrl);
            return $rebuildGoUrl !== '' ? $rebuildGoUrl : $url;
        }

        if (!self::shouldUseGoRedirect($url)) {
            return $url;
        }

        $goUrl = self::buildGoRedirectUrl($url);
        return $goUrl !== '' ? $goUrl : $url;
    }

    public static function upgradeCommentWidgetUrl($widget)
    {
        if (!($widget instanceof Widget_Abstract_Comments)) {
            return;
        }

        $currentUrl = isset($widget->url) ? trim((string)$widget->url) : '';
        if ($currentUrl === '') {
            return;
        }

        $goUrl = self::convertExternalUrlToGo($currentUrl);
        if ($goUrl === $currentUrl) {
            return;
        }

        try {
            $widget->url = $goUrl;
        } catch (Exception $e) {
        }
    }

    public static function upgradeLegacyCommentUrls($limit = 120)
    {
        static $executed = false;
        if ($executed) {
            return;
        }
        $executed = true;

        $limit = intval($limit);
        if ($limit <= 0) {
            $limit = 120;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        try {
            $db = Typecho_Db::get();
            $rows = $db->fetchAll(
                $db->select('coid', 'url')
                    ->from('table.comments')
                    ->where('url <> ?', '')
                    ->order('coid', Typecho_Db::SORT_DESC)
                    ->limit($limit)
            );

            if (!is_array($rows) || empty($rows)) {
                return;
            }

            foreach ($rows as $row) {
                $currentUrl = isset($row['url']) ? trim((string)$row['url']) : '';
                if ($currentUrl === '') {
                    continue;
                }

                $originUrl = self::decodeGoRedirectUrl($currentUrl);
                if ($originUrl === '' || $originUrl === $currentUrl) {
                    continue;
                }

                $coid = isset($row['coid']) ? intval($row['coid']) : 0;
                if ($coid <= 0) {
                    continue;
                }

                $db->query(
                    $db->update('table.comments')
                        ->rows(array('url' => $originUrl))
                        ->where('coid = ?', $coid)
                );
            }
        } catch (Exception $e) {
        }
    }

    public static function encodeGoTarget($url)
    {
        $encoded = base64_encode((string)$url);
        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    public static function decodeGoTarget($token)
    {
        $token = trim((string)$token);
        if ($token === '') {
            return '';
        }

        $token = rawurldecode($token);
        $normalized = strtr($token, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            return '';
        }

        $decoded = trim((string)$decoded);
        if (!Enhancement_Plugin::validateHttpUrl($decoded)) {
            return '';
        }

        return $decoded;
    }

    public static function buildGoRedirectUrl($url)
    {
        $normalized = self::normalizeExternalUrl($url);
        if (!Enhancement_Plugin::validateHttpUrl($normalized)) {
            return '';
        }

        $options = Typecho_Widget::widget('Widget_Options');
        return Typecho_Common::url('go/' . self::encodeGoTarget($normalized), $options->index);
    }

    public static function rewriteExternalLinksByRegex($content)
    {
        if (!is_string($content) || $content === '') {
            return $content;
        }

        return preg_replace_callback(
            '/<a\s+[^>]*>/i',
            function ($matches) {
                $tag = self::normalizeAnchorTagSpacing($matches[0]);

                if (preg_match('/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $tag, $classMatch)) {
                    $classValue = '';
                    for ($index = 1; $index <= 3; $index++) {
                        if (isset($classMatch[$index]) && $classMatch[$index] !== '') {
                            $classValue = strtolower((string)$classMatch[$index]);
                            break;
                        }
                    }

                    if ($classValue !== '' && strpos($classValue, 'enhancement-') !== false) {
                        return $tag;
                    }
                }

                if (!preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $tag, $hrefMatch)) {
                    return $tag;
                }

                $href = '';
                for ($index = 1; $index <= 3; $index++) {
                    if (isset($hrefMatch[$index]) && $hrefMatch[$index] !== '') {
                        $href = $hrefMatch[$index];
                        break;
                    }
                }

                $targetUrl = self::convertExternalUrlToGo($href);
                if ($targetUrl === '' || $targetUrl === $href) {
                    return $tag;
                }

                $target = htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8');
                $tag = preg_replace('/\bhref\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>"\']+)/i', 'href="' . $target . '"', $tag, 1);
                return self::normalizeAnchorTagSpacing($tag);
            },
            $content
        );
    }

    private static function mapAnchors($content, array $fallback, callable $each)
    {
        if (!is_string($content) || $content === '' || stripos($content, '<a') === false) {
            return $content;
        }

        if (!class_exists('DOMDocument')) {
            return call_user_func($fallback, $content);
        }

        $libxmlState = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loadFlags = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) {
            $loadFlags |= LIBXML_HTML_NOIMPLIED;
        }
        if (defined('LIBXML_HTML_NODEFDTD')) {
            $loadFlags |= LIBXML_HTML_NODEFDTD;
        }

        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $content, $loadFlags);
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlState);

        if (!$loaded) {
            return call_user_func($fallback, $content);
        }

        foreach ($dom->getElementsByTagName('a') as $link) {
            $each($link);
        }

        $result = $dom->saveHTML();
        if ($result === false) {
            return call_user_func($fallback, $content);
        }

        return str_replace('<?xml encoding="UTF-8">', '', $result);
    }

    public static function rewriteExternalLinks($content)
    {
        return self::mapAnchors($content, array(__CLASS__, 'rewriteExternalLinksByRegex'), function ($link) {
            $className = strtolower(trim((string)$link->getAttribute('class')));
            if ($className !== '' && strpos($className, 'enhancement-') !== false) {
                return;
            }

            $href = trim((string)$link->getAttribute('href'));
            $targetUrl = self::convertExternalUrlToGo($href);
            if ($targetUrl !== '' && $targetUrl !== $href) {
                $link->setAttribute('href', $targetUrl);
            }
        });
    }

    public static function appendBlankTargetByRegex($content)
    {
        return preg_replace_callback(
            '/<a\s+[^>]*>/i',
            function ($matches) {
                $tag = self::normalizeAnchorTagSpacing($matches[0]);
                if (preg_match('/\btarget\s*=\s*["\"][^"\"]*["\"]/i', $tag)) {
                    $tag = preg_replace('/\btarget\s*=\s*["\"][^"\"]*["\"]/i', 'target="_blank"', $tag, 1);
                } elseif (preg_match('/\btarget\s*=\s*\'[^\']*\'/i', $tag)) {
                    $tag = preg_replace('/\btarget\s*=\s*\'[^\']*\'/i', 'target="_blank"', $tag, 1);
                } else {
                    $tag = preg_replace('/>$/', ' target="_blank">', $tag, 1);
                }

                if (preg_match('/\brel\s*=\s*["\"]([^"\"]*)["\"]/i', $tag, $relMatch) || preg_match('/\brel\s*=\s*\'([^\']*)\'/i', $tag, $relMatch)) {
                    $rels = preg_split('/\s+/', strtolower(trim(isset($relMatch[1]) ? $relMatch[1] : '')), -1, PREG_SPLIT_NO_EMPTY);
                    $rels = is_array($rels) ? $rels : array();
                    if (!in_array('noopener', $rels, true)) {
                        $rels[] = 'noopener';
                    }
                    if (!in_array('noreferrer', $rels, true)) {
                        $rels[] = 'noreferrer';
                    }
                    $relValue = 'rel="' . implode(' ', $rels) . '"';
                    $tagBeforeRelReplace = $tag;
                    $tag = preg_replace('/\brel\s*=\s*["\"]([^"\"]*)["\"]/i', $relValue, $tag, 1);
                    if ($tag === $tagBeforeRelReplace) {
                        $tag = preg_replace('/\brel\s*=\s*\'([^\']*)\'/i', 'rel="' . implode(' ', $rels) . '"', $tag, 1);
                    }
                } else {
                    $tag = preg_replace('/>$/', ' rel="noopener noreferrer">', $tag, 1);
                }

                return self::normalizeAnchorTagSpacing($tag);
            },
            $content
        );
    }

    public static function addBlankTarget($content)
    {
        return self::mapAnchors($content, array(__CLASS__, 'appendBlankTargetByRegex'), function ($link) {
            $link->setAttribute('target', '_blank');
            $existingRel = trim((string)$link->getAttribute('rel'));
            $rels = preg_split('/\s+/', strtolower($existingRel), -1, PREG_SPLIT_NO_EMPTY);
            $rels = is_array($rels) ? $rels : array();
            if (!in_array('noopener', $rels, true)) {
                $rels[] = 'noopener';
            }
            if (!in_array('noreferrer', $rels, true)) {
                $rels[] = 'noreferrer';
            }
            $link->setAttribute('rel', implode(' ', $rels));
        });
    }
}
