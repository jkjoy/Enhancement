<?php

class Enhancement_CommentGuardContentHelper
{
    public static function validate(array $comment, $settings): void
    {
        $rawText = isset($comment['text']) ? $comment['text'] : '';
        $text = self::normalizeText($rawText);

        if (self::setting($settings, 'comment_guard_block_xss', '1') == '1') {
            $fields = array($rawText);
            foreach (array('author', 'url') as $field) {
                if (isset($comment[$field])) {
                    $fields[] = $comment[$field];
                }
            }
            foreach ($fields as $field) {
                if (!is_scalar($field) || preg_match('//u', (string)$field) !== 1) {
                    self::reject('评论包含无效的字符编码，请修改后再提交。');
                }
                if (self::containsDangerousMarkup(self::normalizeText($field, true))) {
                    self::reject('评论包含不安全的标签或脚本，请移除后再提交。');
                }
            }
            if (isset($comment['url']) && self::dangerousUrl(self::normalizeText($comment['url'], true))) {
                self::reject('评论网址包含不安全的协议，请修改后再提交。');
            }
        }

        if (self::setting($settings, 'comment_guard_block_html', '0') == '1') {
            $hasHtml = preg_match('/<(?:\/?[a-z][a-z0-9:_-]*(?=[\s\/>])(?:[^>\'\"]++|\"[^\"]*\"|\'[^\']*\')*+(?:>|$)|!DOCTYPE\b|!--)/i', $text);
            if ($hasHtml !== 0) {
                self::reject('评论不允许包含 HTML 标签，请使用纯文本。');
            }
        }

        $visible = preg_replace('/<!--.*?(?:-->|$)|<(script|style|template)\b[^>]*>.*?(?:<\/\1\s*>|$)/is', '', $text);
        $visible = strip_tags((string)$visible);
        if (self::setting($settings, 'comment_guard_require_chinese', '0') == '1') {
            if (!preg_match('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{20000}-\x{323AF}]/u', $visible)) {
                self::reject('评论内容须至少包含一个中文汉字。');
            }
        }

        foreach (self::lines(self::setting($settings, 'comment_guard_blocked_keywords', '')) as $keyword) {
            $keyword = self::normalizeText($keyword);
            if ($keyword !== '' && (self::contains($text, $keyword) || self::contains($visible, $keyword))) {
                self::reject('评论包含禁止提交的内容，请修改后再提交。');
            }
        }

        $required = self::lines(self::setting($settings, 'comment_guard_required_keywords', ''));
        $hasRequiredRule = false;
        $matched = false;
        foreach ($required as $keyword) {
            $keyword = self::normalizeText($keyword);
            if ($keyword === '') {
                continue;
            }
            $hasRequiredRule = true;
            if (self::contains($visible, $keyword)) {
                $matched = true;
                break;
            }
        }
        if ($hasRequiredRule && !$matched) {
            self::reject('评论未包含站点要求的指定字符，请按评论区提示填写。');
        }

        $ip = isset($comment['ip']) ? $comment['ip'] : '';
        foreach (self::lines(self::setting($settings, 'comment_guard_blocked_ips', '')) as $rule) {
            if (self::matchesIpRule($ip, $rule)) {
                self::reject('当前 IP 地址不允许发表评论。');
            }
        }
    }

    public static function normalizeIp($ip): string
    {
        if (!is_scalar($ip)) {
            return '';
        }
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return '';
        }
        if (self::isMappedIpv4($packed)) {
            $packed = substr($packed, 12);
        }
        return (string)inet_ntop($packed);
    }

    public static function matchesIpRule($ip, $rule): bool
    {
        $ip = self::normalizeIp($ip);
        $network = self::parseIpRule($rule);
        if ($ip === '' || $network === null) {
            return false;
        }
        $packed = inet_pton($ip);
        if (strlen($packed) !== strlen($network['address'])) {
            // A mapped IPv6 CIDR with a prefix below /96 also includes its mapped IPv4 addresses.
            if (strlen($packed) === 4 && $network['mapped']) {
                $packed = str_repeat("\0", 10) . "\xff\xff" . $packed;
            } else {
                return false;
            }
        }
        $bytes = intdiv($network['prefix'], 8);
        if (substr($packed, 0, $bytes) !== substr($network['address'], 0, $bytes)) {
            return false;
        }
        $remaining = $network['prefix'] % 8;
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remaining)) & 0xff;
        return (ord($packed[$bytes]) & $mask) === (ord($network['address'][$bytes]) & $mask);
    }

    public static function validateIpRules($value): array
    {
        $invalid = array();
        foreach (self::lines($value) as $rule) {
            if (self::parseIpRule($rule) === null) {
                $invalid[] = $rule;
            }
        }
        return $invalid;
    }

    private static function parseIpRule($rule)
    {
        if (!is_scalar($rule)) {
            return null;
        }
        $rule = trim((string)$rule);
        if (strpos($rule, '*') !== false) {
            $parts = explode('.', $rule);
            if (count($parts) > 4) {
                return null;
            }
            $octets = array();
            $wildcard = false;
            foreach ($parts as $part) {
                if ($part === '*') {
                    $wildcard = true;
                    continue;
                }
                if ($wildcard || !preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $part) || (int)$part > 255) {
                    return null;
                }
                $octets[] = $part;
            }
            $prefix = count($octets) * 8;
            while (count($octets) < 4) {
                $octets[] = '0';
            }
            return array('address' => inet_pton(implode('.', $octets)), 'prefix' => $prefix, 'mapped' => false);
        }

        $parts = explode('/', $rule);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = @inet_pton($parts[0]);
        if ($packed === false) {
            return null;
        }
        $maxPrefix = strlen($packed) * 8;
        $prefix = $maxPrefix;
        if (isset($parts[1])) {
            if (!preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $parts[1]) || (int)$parts[1] > $maxPrefix) {
                return null;
            }
            $prefix = (int)$parts[1];
        }
        $mapped = self::isMappedIpv4($packed);
        if ($mapped && $prefix >= 96) {
            $packed = substr($packed, 12);
            $prefix -= 96;
            $mapped = false;
        }
        return array('address' => $packed, 'prefix' => $prefix, 'mapped' => $mapped);
    }

    private static function isMappedIpv4($packed): bool
    {
        return strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff";
    }

    private static function normalizeText($text, $decodePercent = false): string
    {
        $text = is_scalar($text) ? (string)$text : '';
        // Bound decoding work while catching common nested entities and percent-encoded payloads.
        for ($i = 0; $i < 4; $i++) {
            $decoded = $decodePercent ? rawurldecode($text) : $text;
            $decoded = preg_replace_callback('/&#(x[0-9a-f]+|[0-9]+);?/i', function ($match) {
                return html_entity_decode('&#' . $match[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }, $decoded);
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }
        $normalized = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{00AD}\x{034F}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $text);
        return $normalized === null ? str_replace("\0", '', $text) : $normalized;
    }

    private static function containsDangerousMarkup($text): bool
    {
        // Reject active markup instead of trying to implement an HTML sanitizer here.
        if (preg_match('/<\s*\/?\s*(?:script|iframe|frame|frameset|object|embed|applet|svg|math|meta|link|base|form|input|button|textarea|select|option|style|noscript|template)\b/i', $text)) {
            return true;
        }
        $tagCount = preg_match_all('/<[a-z](?:[^>\'\"]++|\"[^\"]*\"|\'[^\']*\')*+(?:>|$)/i', $text, $tags);
        if ($tagCount === false) {
            return true;
        }
        if ($tagCount > 0) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/[\s\/]on[a-z][a-z0-9_-]*\s*=/i', $tag)
                    || preg_match('/[\s\/]srcdoc\s*=/i', $tag)) {
                    return true;
                }
                if (preg_match_all('/[\s\/]([a-z_:][a-z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $attributes, PREG_SET_ORDER)) {
                    foreach ($attributes as $attribute) {
                        $value = isset($attribute[4]) ? $attribute[4] : (isset($attribute[3]) ? $attribute[3] : $attribute[2]);
                        if (in_array(strtolower($attribute[1]), array('href', 'src', 'xlink:href', 'action', 'formaction', 'poster', 'background', 'cite', 'longdesc', 'lowsrc', 'dynsrc', 'profile', 'manifest', 'data', 'codebase', 'archive', 'classid'), true)
                            && self::dangerousUrl($value)) {
                            return true;
                        }
                        if (strtolower($attribute[1]) === 'style'
                            && preg_match('/(?:expression\s*\(|(?:url\s*\(|@import\s*)[\s\'\"]*(?:javascript|vbscript|data)\s*:|-moz-binding\s*:|behavior\s*:)/i', $value)) {
                            return true;
                        }
                    }
                }
            }
        }
        // Markdown links, reference links, and autolinks can become HTML URL attributes later.
        if (preg_match('/(?:\]\s*\(\s*|\]\s*:\s*|<\s*)(?:javascript|vbscript|data)[\x00-\x20]*:/i', $text)) {
            return true;
        }
        return false;
    }

    private static function dangerousUrl($value): bool
    {
        $value = preg_replace('/[\x00-\x20\x7F]+/', '', (string)$value);
        return (bool)preg_match('/^(?:javascript|vbscript|data):/i', $value);
    }

    private static function contains($text, $keyword): bool
    {
        return function_exists('mb_stripos')
            ? mb_stripos($text, $keyword, 0, 'UTF-8') !== false
            : stripos($text, $keyword) !== false;
    }

    private static function lines($value): array
    {
        $value = is_scalar($value) ? (string)$value : '';
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value)), function ($line) {
            return $line !== '';
        }));
    }

    private static function setting($settings, $key, $default)
    {
        if (is_array($settings)) {
            return isset($settings[$key]) ? $settings[$key] : $default;
        }
        return is_object($settings) && isset($settings->$key) ? $settings->$key : $default;
    }

    private static function reject($message): void
    {
        throw new Typecho_Widget_Exception($message, 403);
    }
}
