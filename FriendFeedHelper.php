<?php

/** Fetch public friend feeds without loading Typecho or a database. */
class Enhancement_FriendFeedHelper
{
    const MAX_BYTES = 1048576;
    const MAX_ITEMS = 50;
    const TIMEOUT = 8;

    public static function fetch($siteUrl, $feedUrl = '')
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('服务器未安装 cURL 扩展，无法获取朋友文章');
        }
        $deadline = microtime(true) + self::TIMEOUT;
        $manual = trim((string)$feedUrl) !== '';
        $url = self::normalizeUrl($manual ? $feedUrl : $siteUrl);
        if ($url === '') {
            throw new RuntimeException('订阅地址必须是公开的 HTTP 或 HTTPS 地址');
        }

        $response = null;
        $lastError = '未找到有效的 RSS 或 Atom 订阅，请手动填写订阅地址';
        try {
            $response = self::request($url, $deadline);
            return array('url' => $response['url'], 'items' => self::parse($response['body'], $response['url']));
        } catch (RuntimeException $exception) {
            if ($manual) {
                throw $exception;
            }
            $lastError = $exception->getMessage();
        }

        $base = $response ? $response['url'] : $url;
        $candidates = $response ? self::discover($response['body'], $base) : array();
        foreach (array('/feed/', '/rss.xml') as $path) {
            $candidate = self::resolveUrl($path, $base);
            if ($candidate !== '' && !in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }
        foreach ($candidates as $candidate) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $response = self::request($candidate, $deadline);
                return array('url' => $response['url'], 'items' => self::parse($response['body'], $response['url']));
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();
            }
        }
        throw new RuntimeException('无法获取有效订阅：' . $lastError);
    }

    public static function parse($xml, $feedUrl)
    {
        if (!class_exists('DOMDocument')) {
            throw new RuntimeException('服务器未安装 DOM XML 扩展，无法解析订阅');
        }
        $xml = (string)$xml;
        if (trim($xml) === '' || strlen($xml) > self::MAX_BYTES || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
            throw new RuntimeException('订阅包含不允许的 XML 声明或内容过大');
        }
        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        $entityLoader = null;
        if (PHP_VERSION_ID < 80000) {
            $entityLoader = libxml_disable_entity_loader(true);
        }
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } catch (Throwable $exception) {
            $loaded = false;
        } finally {
            if ($entityLoader !== null) {
                libxml_disable_entity_loader($entityLoader);
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->doctype || !$document->documentElement) {
            throw new RuntimeException('订阅 XML 无效');
        }
        $root = $document->documentElement;
        $name = strtolower($root->localName);
        $xpath = new DOMXPath($document);
        if ($name === 'feed' && $root->namespaceURI === 'http://www.w3.org/2005/Atom') {
            $atom = true;
            $nodes = $xpath->query('./*[local-name()="entry"]', $root);
        } elseif ($name === 'rss') {
            $atom = false;
            $nodes = $xpath->query('./*[local-name()="channel"]/*[local-name()="item"]', $root);
            if (!$xpath->query('./*[local-name()="channel"]', $root)->length) {
                throw new RuntimeException('RSS 订阅缺少 channel');
            }
        } elseif ($name === 'rdf' && $root->namespaceURI === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#') {
            $atom = false;
            $nodes = $xpath->query('./*[local-name()="item"]', $root);
        } else {
            throw new RuntimeException('内容不是 RSS 或 Atom 订阅');
        }

        $items = array();
        $seen = array();
        $now = time();
        $scanned = 0;
        foreach ($nodes as $node) {
            if (++$scanned > 250) {
                break;
            }
            $base = self::elementBase($node, $feedUrl);
            $title = self::plainText(self::childText($node, array('title')), 300);
            $url = '';
            if ($atom) {
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'link'
                        && (!$child->hasAttribute('rel') || $child->getAttribute('rel') === 'alternate')) {
                        $url = self::resolveUrl($child->getAttribute('href'), self::elementBase($child, $feedUrl));
                        if ($url !== '') {
                            break;
                        }
                    }
                }
                if ($url === '') {
                    $url = self::resolveUrl(self::childText($node, array('id')), $base);
                }
                $date = self::childText($node, array('published', 'updated'));
                $summary = self::childText($node, array('summary', 'content'));
                $author = '';
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'author') {
                        $author = self::childText($child, array('name'));
                        break;
                    }
                }
            } else {
                $url = self::resolveUrl(self::childText($node, array('link')), $base);
                if ($url === '') {
                    foreach ($node->childNodes as $child) {
                        if ($child instanceof DOMElement && $child->localName === 'guid'
                            && strtolower($child->getAttribute('isPermaLink')) !== 'false') {
                            $url = self::resolveUrl($child->textContent, $base);
                            break;
                        }
                    }
                }
                $date = self::childText($node, array('pubDate', 'date', 'published'));
                $summary = self::childText($node, array('description', 'encoded', 'summary'));
                $author = self::childText($node, array('creator', 'author'));
            }
            $date = trim($date);
            $published = $date !== '' && strlen($date) <= 120 ? strtotime($date) : false;
            $published = $published !== false && $published > 0 ? (int)$published : 0;
            if ($title === '' || $url === '' || isset($seen[$url]) || $published > $now) {
                continue;
            }
            $seen[$url] = true;
            $items[] = array(
                'title' => $title,
                'url' => $url,
                'published' => $published,
                'summary' => self::plainText($summary, 600),
                'author' => self::plainText($author, 120),
            );
        }
        usort($items, static function ($left, $right) {
            return $right['published'] <=> $left['published'];
        });
        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /** Pure syntax/literal-host validation; DNS addresses are validated immediately before fetching. */
    public static function normalizeUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return '';
        }
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $address = trim($host, '[]');
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($address)) {
                return '';
            }
            $host = strpos($address, ':') === false ? $address : '[' . $address . ']';
        } else {
            if (function_exists('idn_to_ascii')) {
                $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                $host = $ascii === false ? '' : strtolower($ascii);
            }
            if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host)
                || preg_match('/(?:^|\.)(?:localhost|localdomain|local|internal|home|lan|onion|invalid)$/i', $host)) {
                return '';
            }
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return '';
        }
        $authority = $host . (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':' . $port);
        $path = isset($parts['path']) && $parts['path'] !== '' ? self::removeDotSegments($parts['path']) : '/';
        return $scheme . '://' . $authority . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public static function resolveUrl($url, $base)
    {
        $url = trim((string)$url);
        $base = self::normalizeUrl($base);
        if ($url === '' || $base === '') {
            return '';
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return self::normalizeUrl($url);
        }
        $parts = parse_url($base);
        if (substr($url, 0, 2) === '//') {
            return self::normalizeUrl($parts['scheme'] . ':' . $url);
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($url[0] === '#') {
            return $base;
        }
        if ($url[0] === '?') {
            return self::normalizeUrl($origin . $parts['path'] . $url);
        }
        if ($url[0] === '/') {
            return self::normalizeUrl($origin . $url);
        }
        $directory = substr($parts['path'], 0, strrpos($parts['path'], '/') + 1);
        return self::normalizeUrl($origin . $directory . $url);
    }

    private static function removeDotSegments($path)
    {
        $segments = explode('/', $path);
        $output = array();
        foreach ($segments as $index => $segment) {
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
            } elseif ($segment !== '.') {
                $output[] = $segment;
            }
            if ($index === count($segments) - 1 && ($segment === '.' || $segment === '..')) {
                $output[] = '';
            }
        }
        return implode('/', $output);
    }

    private static function isPublicIp($address)
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $binary = inet_pton($address);
        if (strlen($binary) === 4) {
            $first = ord($binary[0]);
            $second = ord($binary[1]);
            return $first < 224 && $first !== 0
                && !($first === 100 && $second >= 64 && $second <= 127)
                && !($first === 192 && $second === 0)
                && !($first === 192 && $second === 88 && ord($binary[2]) === 99)
                && !($first === 198 && ($second === 18 || $second === 19 || ($second === 51 && ord($binary[2]) === 100)))
                && !($first === 203 && $second === 0 && ord($binary[2]) === 113);
        }
        // Only globally routed unicast; exclude transition protocols and documentation ranges.
        return (ord($binary[0]) & 0xe0) === 0x20
            && substr($binary, 0, 2) !== "\x20\x02"
            && substr($binary, 0, 2) !== "\x3f\xfe"
            && !(substr($binary, 0, 2) === "\x3f\xff" && (ord($binary[2]) & 0xf0) === 0)
            && !(substr($binary, 0, 2) === "\x20\x01" && ord($binary[2]) < 2)
            && substr($binary, 0, 4) !== "\x20\x01\x0d\xb8";
    }

    private static function publicAddresses($host)
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = array($host);
        } else {
            $addresses = array();
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $addresses[] = $record['ip'];
                    } elseif (isset($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }
        if (!$addresses) {
            throw new RuntimeException('订阅域名无法解析');
        }
        foreach ($addresses as $address) {
            if (!self::isPublicIp($address)) {
                throw new RuntimeException('订阅地址指向非公开网络，已拒绝访问');
            }
        }
        return array_values(array_unique($addresses));
    }

    private static function request($url, $deadline)
    {
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $url = self::normalizeUrl($url);
            if ($url === '') {
                throw new RuntimeException('订阅或跳转地址不是公开的 HTTP 或 HTTPS 地址');
            }
            $parts = parse_url($url);
            $addresses = self::publicAddresses($parts['host']);
            $milliseconds = (int)floor(($deadline - microtime(true)) * 1000);
            if ($milliseconds <= 0) {
                throw new RuntimeException('获取订阅超时');
            }
            $port = isset($parts['port']) ? (int)$parts['port'] : ($parts['scheme'] === 'https' ? 443 : 80);
            $body = '';
            $location = '';
            $oversized = false;
            $headerBytes = 0;
            $handle = curl_init($url);
            $options = array(
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT_MS => min(2500, $milliseconds),
                CURLOPT_TIMEOUT_MS => $milliseconds,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '',
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'Enhancement-FriendPosts/1.0',
                CURLOPT_HTTPHEADER => array('Accept: application/atom+xml, application/rss+xml, application/xml, text/xml, text/html;q=0.8'),
                CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body, &$oversized) {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        $oversized = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($curl, $header) use (&$location, &$headerBytes, &$oversized) {
                    $headerBytes += strlen($header);
                    if ($headerBytes > 65536) {
                        $oversized = true;
                        return 0;
                    }
                    if (stripos($header, 'HTTP/') === 0) {
                        $location = '';
                    } elseif (stripos($header, 'Location:') === 0) {
                        $location = trim(substr($header, 9));
                    }
                    return strlen($header);
                },
            );
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
            } else {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            // The verified address is pinned for this connection, including TLS hostname checks.
            $host = trim($parts['host'], '[]');
            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                $address = $addresses[0];
                $options[CURLOPT_RESOLVE] = array($host . ':' . $port . ':' . (strpos($address, ':') === false ? $address : '[' . $address . ']'));
            }
            curl_setopt_array($handle, $options);
            $success = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = curl_error($handle);
            if (PHP_VERSION_ID < 80000) {
                curl_close($handle);
            }
            unset($handle);
            if ($oversized) {
                throw new RuntimeException('订阅响应内容过大');
            }
            if ($success === false) {
                throw new RuntimeException('订阅请求失败：' . $error);
            }
            if (in_array($status, array(301, 302, 303, 307, 308), true)) {
                if ($redirect >= 3 || $location === '') {
                    throw new RuntimeException('订阅跳转次数过多或缺少跳转地址');
                }
                $url = self::resolveUrl($location, $url);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('订阅服务器返回 HTTP ' . $status);
            }
            return array('url' => $url, 'body' => $body);
        }
        throw new RuntimeException('订阅跳转次数过多');
    }

    private static function discover($html, $base)
    {
        if (!class_exists('DOMDocument') || trim($html) === '') {
            return array();
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable $exception) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return array();
        }
        $bases = $document->getElementsByTagName('base');
        if ($bases->length) {
            $resolved = self::resolveUrl($bases->item(0)->getAttribute('href'), $base);
            if ($resolved !== '') {
                $base = $resolved;
            }
        }
        $urls = array();
        foreach ($document->getElementsByTagName('link') as $link) {
            $rel = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))));
            $type = strtolower(trim(explode(';', $link->getAttribute('type'))[0]));
            if (!in_array('alternate', $rel, true) || !in_array($type, array('application/rss+xml', 'application/atom+xml', 'application/rdf+xml'), true)) {
                continue;
            }
            $url = self::resolveUrl($link->getAttribute('href'), $base);
            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
            if (count($urls) >= 2) {
                break;
            }
        }
        return $urls;
    }

    private static function childText(DOMElement $node, array $names)
    {
        foreach ($names as $name) {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === $name && trim($child->textContent) !== '') {
                    // XHTML Atom content and mixed XML markup need their paragraph boundaries.
                    foreach ($child->childNodes as $content) {
                        if ($content instanceof DOMElement) {
                            $markup = '';
                            foreach ($child->childNodes as $part) {
                                $markup .= $child->ownerDocument->saveXML($part);
                            }
                            return $markup;
                        }
                    }
                    return $child->textContent;
                }
            }
        }
        return '';
    }

    private static function elementBase(DOMElement $node, $fallback)
    {
        $elements = array();
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            array_unshift($elements, $current);
        }
        $base = $fallback;
        foreach ($elements as $element) {
            $reference = $element->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base');
            if ($reference !== '') {
                $resolved = self::resolveUrl($reference, $base);
                if ($resolved !== '') {
                    $base = $resolved;
                }
            }
        }
        return $base;
    }

    private static function plainText($html, $limit)
    {
        $text = (string)$html;
        for ($i = 0; $i < 2; $i++) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', ' ', $text);
        $text = preg_replace('/<\/?(?:p|div|br|li|h[1-6]|blockquote)\b[^>]*>/i', ' ', $text);
        $text = strip_tags($text);
        $text = trim(preg_replace('/[\s\p{Z}\x{0000}-\x{001f}\x{007f}]+/u', ' ', $text));
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit, 'UTF-8');
        }
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($characters) ? implode('', array_slice($characters, 0, $limit)) : '';
    }
}
