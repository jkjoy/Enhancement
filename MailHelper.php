<?php

/** Shared SMTP setup and HTML boundaries for notification emails. */
class Enhancement_MailHelper
{
    public static function createMailer($settings, $exceptions = false)
    {
        require_once __DIR__ . '/CommentNotifier/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/CommentNotifier/PHPMailer/SMTP.php';
        require_once __DIR__ . '/CommentNotifier/PHPMailer/Exception.php';
        $settings = is_array($settings) ? $settings : (is_object($settings) && method_exists($settings, 'toArray') ? $settings->toArray() : (array)$settings);
        $mail = new \PHPMailer\PHPMailer\PHPMailer((bool)$exceptions);
        $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mail->Encoding = \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
        $mail->isSMTP();
        // Read the historical misspelling until all installed configurations migrate.
        $mail->Host = trim((string)($settings['SMTPHost'] ?? $settings['STMPHost'] ?? ''));
        $mail->SMTPAuth = true;
        $mail->Username = trim((string)($settings['SMTPUserName'] ?? ''));
        $mail->Password = (string)($settings['SMTPPassword'] ?? '');
        $mail->SMTPSecure = trim((string)($settings['SMTPSecure'] ?? ''));
        $port = (int)($settings['SMTPPort'] ?? 25);
        $mail->Port = $port > 0 && $port <= 65535 ? $port : 25;
        $mail->Timeout = 10;
        $mail->setFrom(trim((string)($settings['from'] ?? '')), trim((string)($settings['fromName'] ?? '')));
        $mail->isHTML();
        return $mail;
    }

    public static function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function safeUrl($value)
    {
        $url = trim((string)$value);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $parts = parse_url($url);
        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), array('http', 'https'), true)
            && !isset($parts['user']) && !isset($parts['pass']) ? $url : '';
    }

    /** Keep formatted comments and smileys, but discard active elements and attributes. */
    public static function sanitizeHtml($html)
    {
        if (!class_exists('DOMDocument')) {
            return nl2br(self::escape(strip_tags((string)$html)));
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><html><body><div>' . (string)$html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return self::escape($html);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }
        self::sanitizeChildren($body);
        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }
        return $result;
    }

    private static function sanitizeChildren(DOMNode $parent)
    {
        $allowed = array('p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 'del', 's', 'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'a', 'img', 'span');
        $discard = array('script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'link', 'meta', 'base', 'template');
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $parent->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, $discard, true)) {
                $parent->removeChild($child);
                continue;
            }
            self::sanitizeChildren($child);
            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }
            $url = self::safeUrl($child->getAttribute($tag === 'img' ? 'src' : 'href'));
            $label = $child->getAttribute($tag === 'img' ? 'alt' : 'title');
            $smiley = $tag === 'img' && preg_match('/(?:^|\s)(?:biaoqing|enh-smiley)(?:\s|$)/', $child->getAttribute('class'));
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $child->removeAttribute($attribute->name);
            }
            if ($tag === 'img') {
                if ($url === '') {
                    $parent->replaceChild($child->ownerDocument->createTextNode($label), $child);
                    continue;
                }
                $child->setAttribute('src', $url);
                $child->setAttribute('alt', $label);
                if ($smiley) {
                    $child->setAttribute('width', '30');
                    $child->setAttribute('style', 'display:inline-block;vertical-align:bottom;margin:0;');
                }
            } elseif ($tag === 'a' && $url !== '') {
                $child->setAttribute('href', $url);
                $child->setAttribute('rel', 'noopener noreferrer');
            }
            if ($tag === 'a' && $label !== '') {
                $child->setAttribute('title', $label);
            }
        }
    }
}
