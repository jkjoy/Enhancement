<?php

require_once __DIR__ . '/ReplyAccessHelper.php';

class Enhancement_ShortcodeHelper
{
    public static function renderStyles()
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        $options = Typecho_Widget::widget('Widget_Options');
        $pluginUrl = rtrim((string)$options->pluginUrl, '/');
        if ($pluginUrl === '') {
            return;
        }

        $cssUrl = htmlspecialchars(Enhancement_Plugin::appendVersionToAssetUrl($pluginUrl . '/Enhancement/shortcodes.css'), ENT_QUOTES, 'UTF-8');
        echo '<link rel="stylesheet" href="' . $cssUrl . '">' . "\n";
    }

    public static function parseContent($content, $widget)
    {
        if (!is_string($content) || $content === '') {
            return $content;
        }

        if (stripos($content, '[reply]') !== false || stripos($content, '<!--enhancement-reply-start-->') !== false) {
            if (!self::canViewerAccessReplyShortcode($widget)) {
                $content = self::hideReplyContent($content, self::replyLockedNotice());
            } else {
                $content = self::renderUnlockedReplyContent($content);
            }
        }

        $content = self::replaceCalloutShortcode($content, 'primary', 'important');
        $content = self::replaceCalloutShortcode($content, 'success', 'success');
        $content = self::replaceCalloutShortcode($content, 'info', 'info');
        $content = self::replaceCalloutShortcode($content, 'danger', 'danger');

        $content = preg_replace_callback(
            '/\[article\s+id=["\']?(\d+)["\']?\s*\]/i',
            function ($matches) {
                $cid = isset($matches[1]) ? intval($matches[1]) : 0;
                if ($cid <= 0) {
                    return '';
                }

                try {
                    $db = Typecho_Db::get();
                    $row = $db->fetchRow(
                        $db->select()
                            ->from('table.contents')
                            ->where('cid = ?', $cid)
                            ->where('type = ?', 'post')
                            ->where('status = ?', 'publish')
                            ->where('created <= ?', time())
                            ->where('(password IS NULL OR password = ?)', '')
                            ->limit(1)
                    );

                    if (!is_array($row) || empty($row)) {
                        return '';
                    }

                    $title = isset($row['title']) ? trim((string)$row['title']) : '';
                    if ($title === '') {
                        $title = '未命名文章';
                    }

                    $permalink = '';
                    $archive = Typecho_Widget::widget('Widget_Abstract_Contents@enhancement-article-ref-' . $cid);
                    if (method_exists($archive, 'filter')) {
                        $archive->push($row);
                        if (isset($archive->permalink)) {
                            $permalink = (string)$archive->permalink;
                        }
                    }

                    if ($permalink === '') {
                        $options = Typecho_Widget::widget('Widget_Options');
                        $permalink = Typecho_Common::url('?cid=' . $cid, $options->index);
                    }

                    return '<a class="enhancement-shortcode enhancement-article-ref" href="'
                        . htmlspecialchars((string)$permalink, ENT_QUOTES, 'UTF-8')
                        . '">'
                        . '📄 ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
                        . '</a>';
                } catch (Exception $e) {
                    return '';
                }
            },
            $content
        );

        $content = preg_replace_callback(
            '/\[github\s*=\s*([A-Za-z0-9_.\-]+\/[A-Za-z0-9_.\-]+)\s*\]/i',
            function ($matches) {
                $repo = isset($matches[1]) ? trim((string)$matches[1]) : '';
                if ($repo === '' || strpos($repo, '/') === false) {
                    return '';
                }

                $parts = explode('/', $repo, 2);
                $owner = isset($parts[0]) ? trim((string)$parts[0]) : '';
                $name = isset($parts[1]) ? trim((string)$parts[1]) : '';
                if ($owner === '' || $name === '') {
                    return '';
                }

                $repoUrl = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($name);
                $cardUrl = 'https://githubcard.com/' . rawurlencode($owner) . '/' . rawurlencode($name) . '.svg';

                return '<a class="enhancement-shortcode enhancement-github-card-link" href="'
                    . htmlspecialchars($repoUrl, ENT_QUOTES, 'UTF-8')
                    . '" target="_blank" rel="noopener noreferrer">'
                    . '<img class="enhancement-github-card" src="'
                    . htmlspecialchars($cardUrl, ENT_QUOTES, 'UTF-8')
                    . '" alt="'
                    . htmlspecialchars($repo, ENT_QUOTES, 'UTF-8')
                    . '" loading="lazy" decoding="async" />'
                    . '</a>';
            },
            $content
        );

        $content = preg_replace_callback(
            "/\\[download([^\\]]*)\\]([\\s\\S]*?)\\[\\/download\\]/i",
            function ($matches) {
                $rawAttributes = isset($matches[1]) ? (string)$matches[1] : '';
                $rawBody = isset($matches[2]) ? (string)$matches[2] : '';
                $rawUrl = self::extractDownloadShortcodeUrl($rawBody);
                if ($rawUrl === '') {
                    return '';
                }

                $attributes = self::parseShortcodeAttributes($rawAttributes);
                $fileName = isset($attributes['file']) ? (string)$attributes['file'] : '';
                $size = isset($attributes['size']) ? (string)$attributes['size'] : '';

                $card = self::renderDownloadShortcodeCard($rawUrl, $fileName, $size);
                if ($card === '') {
                    return isset($matches[0]) ? (string)$matches[0] : '';
                }

                return $card;
            },
            $content
        );

        return $content;
    }

    private static function renderUnlockedReplyContent($content): string
    {
        $depth = 0;
        $opening = '<!--enhancement-reply-start--><div class="enhancement-shortcode enhancement-reply">';
        $closing = '</div><!--enhancement-reply-end-->';
        $content = preg_replace_callback('/\[\/?reply\]/i', function ($match) use (&$depth, $opening, $closing) {
            if (stripos($match[0], '[/') === 0) {
                if ($depth <= 0) {
                    return '';
                }
                $depth--;
                return $closing;
            }
            $depth++;
            return $opening;
        }, $content);
        return $content . str_repeat($closing, $depth);
    }

    /** Hide complete, nested and truncated reply blocks in public output. */
    public static function hideReplyContent($content, $replacement = ''): string
    {
        $content = (string)$content;
        if (!preg_match_all('/\[\/?reply\]|<!--enhancement-reply-(?:start|end)-->/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }
        $result = '';
        $offset = 0;
        $depth = 0;
        foreach ($matches[0] as $match) {
            list($tag, $position) = $match;
            if ($depth === 0) {
                $result .= substr($content, $offset, $position - $offset);
            }
            $closing = stripos($tag, '[/') === 0 || stripos($tag, '-end-->') !== false;
            if ($closing) {
                $depth = max(0, $depth - 1);
            } else {
                if ($depth === 0) {
                    $result .= $replacement;
                }
                $depth++;
            }
            $offset = $position + strlen($tag);
        }
        return $depth === 0 ? $result . substr($content, $offset) : $result;
    }

    private static function replyLockedNotice(): string
    {
        return '<div class="enhancement-shortcode enhancement-reply enhancement-reply-locked">'
            . '<span class="enhancement-reply-lock-text">该内容仅评论审核通过后可见</span>'
            . '<a class="enhancement-reply-action" href="#comments">评论后刷新查看隐藏内容</a>'
            . '</div>';
    }

    private static function canViewerAccessReplyShortcode($widget): bool
    {
        // Lists and feeds can be shared or cached; only the actual article can unlock.
        if (!($widget instanceof Widget_Archive) || !$widget->is('single') || $widget->is('feed')) {
            return false;
        }
        if (!Enhancement_ReplyAccessHelper::canAccess((int)$widget->cid)) {
            return false;
        }
        Typecho_Response::getInstance()->setHeader('Cache-Control', 'private, no-store');
        return true;
    }

    private static function extractDownloadShortcodeUrl($rawBody): string
    {
        $rawBody = trim((string)$rawBody);
        if ($rawBody === '') {
            return '';
        }

        $decoded = html_entity_decode($rawBody, ENT_QUOTES, 'UTF-8');
        $hrefCandidate = '';

        if (preg_match('/<a\s+[^>]*href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $decoded, $hrefMatch)) {
            for ($index = 1; $index <= 3; $index++) {
                if (!isset($hrefMatch[$index]) || $hrefMatch[$index] === '') {
                    continue;
                }

                $href = self::normalizeDownloadShortcodeUrlCandidate($hrefMatch[$index]);
                if ($href !== '') {
                    $hrefCandidate = $href;
                    break;
                }
            }
        }

        $plain = trim(strip_tags($decoded));
        if ($plain === '') {
            return $hrefCandidate;
        }

        $plainCandidate = '';
        if (preg_match('#^(https?:)?//#i', $plain)) {
            $plainCandidate = self::normalizeDownloadShortcodeUrlCandidate($plain);
        } else if (preg_match('#(?:https?:\/\/|//)#i', $plain, $protocolMatch, PREG_OFFSET_CAPTURE)) {
            $offset = isset($protocolMatch[0][1]) ? intval($protocolMatch[0][1]) : -1;
            if ($offset >= 0) {
                $tail = trim((string)substr($plain, $offset));
                if ($tail !== '') {
                    $plainCandidate = self::normalizeDownloadShortcodeUrlCandidate($tail);
                }
            }
        } else {
            $plainCandidate = self::normalizeDownloadShortcodeUrlCandidate($plain);
        }

        if ($hrefCandidate !== '' && $plainCandidate !== '') {
            if (
                preg_match('#^(https?:)?//#i', $plainCandidate) &&
                strlen($plainCandidate) > strlen($hrefCandidate) &&
                strpos($plainCandidate, $hrefCandidate) === 0
            ) {
                return $plainCandidate;
            }
            return $hrefCandidate;
        }

        if ($hrefCandidate !== '') {
            return $hrefCandidate;
        }

        return $plainCandidate;
    }

    private static function normalizeDownloadShortcodeUrlCandidate($value): string
    {
        $value = trim(html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8'));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = preg_replace('/\s+/u', '%20', (string)$value);

        return trim((string)$value);
    }

    private static function parseShortcodeAttributes($rawAttributes): array
    {
        $rawAttributes = trim((string)$rawAttributes);
        if ($rawAttributes === '') {
            return array();
        }

        $attributes = array();
        if (!preg_match_all(
            "/([a-zA-Z0-9_-]+)\\s*=\\s*(?:\"([^\"]*)\"|'([^']*)'|([^\\s\"'`=<>]+))/u",
            $rawAttributes,
            $matches,
            PREG_SET_ORDER
        )) {
            return $attributes;
        }

        foreach ($matches as $match) {
            $key = isset($match[1]) ? strtolower(trim((string)$match[1])) : '';
            if ($key === '') {
                continue;
            }

            $value = '';
            if (isset($match[2]) && $match[2] !== '') {
                $value = (string)$match[2];
            } else if (isset($match[3]) && $match[3] !== '') {
                $value = (string)$match[3];
            } else if (isset($match[4])) {
                $value = (string)$match[4];
            }

            $attributes[$key] = trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
        }

        return $attributes;
    }

    private static function renderDownloadShortcodeCard($url, $fileName = '', $size = ''): string
    {
        $url = self::extractDownloadShortcodeUrl($url);
        $url = trim(html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        $decodedGoUrl = Enhancement_GoRedirectHelper::decodeGoRedirectUrl($url);
        if ($decodedGoUrl !== '') {
            $url = $decodedGoUrl;
        }

        if (!preg_match('#^(https?:)?//#i', $url) && strpos($url, '/') !== 0) {
            return '';
        }

        $displayFile = trim((string)$fileName);
        if ($displayFile === '') {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $basename = basename($path);
                if ($basename !== '') {
                    $displayFile = rawurldecode($basename);
                }
            }
        }
        if ($displayFile === '') {
            $displayFile = '下载文件';
        }

        $size = trim((string)$size);

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || trim($host) === '') {
            $host = '本站资源';
        }

        $extLabel = 'FILE';
        if (preg_match('/\.([a-zA-Z0-9.]{1,12})$/', $displayFile, $extMatches)) {
            $extLabel = strtoupper((string)$extMatches[1]);
        }

        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeFile = htmlspecialchars($displayFile, ENT_QUOTES, 'UTF-8');
        $safeHost = htmlspecialchars($host, ENT_QUOTES, 'UTF-8');
        $safeExt = htmlspecialchars($extLabel, ENT_QUOTES, 'UTF-8');

        $metaParts = array('来源：' . $safeHost);
        if ($size !== '') {
            $metaParts[] = '大小：' . htmlspecialchars($size, ENT_QUOTES, 'UTF-8');
        }
        $metaText = implode(' · ', $metaParts);

        return '<div class="enhancement-shortcode enhancement-download-card">'
            . '<a class="enhancement-download-link" href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer" download>'
            . '<span class="enhancement-download-icon" aria-hidden="true">⬇</span>'
            . '<span class="enhancement-download-main">'
            . '<span class="enhancement-download-file">' . $safeFile . '</span>'
            . '<span class="enhancement-download-meta">' . $metaText . '</span>'
            . '</span>'
            . '<span class="enhancement-download-badge">' . $safeExt . '</span>'
            . '</a>'
            . '</div>';
    }

    private static function replaceCalloutShortcode($content, $name, $theme)
    {
        $name = strtolower(trim((string)$name));
        $theme = strtolower(trim((string)$theme));
        if ($name === '' || $theme === '') {
            return $content;
        }

        $pattern = '/\[' . preg_quote($name, '/') . '\]([\s\S]*?)\[\/' . preg_quote($name, '/') . '\]/i';
        return preg_replace_callback(
            $pattern,
            function ($matches) use ($theme) {
                $inner = isset($matches[1]) ? (string)$matches[1] : '';
                return '<div class="enhancement-shortcode enhancement-callout enhancement-callout-' . $theme . '">' . $inner . '</div>';
            },
            $content
        );
    }
}
