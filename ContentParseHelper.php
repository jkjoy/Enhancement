<?php

class Enhancement_ContentParseHelper
{
    public static function hideReplyForExcerpt($text, $widget = null, $lastResult = null)
    {
        return Enhancement_ShortcodeHelper::hideReplyContent(empty($lastResult) ? $text : $lastResult);
    }

    public static function parseExcerpt($text, $widget, $lastResult = null)
    {
        return self::parse(self::hideReplyForExcerpt($text, $widget, $lastResult), $widget, null);
    }

    public static function parse($text, $widget, $lastResult)
    {
        $text = empty($lastResult) ? $text : $lastResult;
        if (!is_string($text)) {
            return $text;
        }

        $isContentWidget = $widget instanceof Widget_Abstract_Contents;
        $isCommentWidget = $widget instanceof Widget_Abstract_Comments;
        if (!$isContentWidget && !$isCommentWidget) {
            return $text;
        }

        if ($isCommentWidget) {
            Enhancement_GoRedirectHelper::upgradeCommentWidgetUrl($widget);
        }

        $protectedBlocks = array();
        $placeholderPrefix = 'enhancement-protected-code-' . sha1($text) . '-';
        $protectedText = preg_replace_callback(
            '/(<pre\b[^>]*>[\s\S]*?<\/pre\s*>|<code\b[^>]*>[\s\S]*?<\/code\s*>)/i',
            function ($matches) use (&$protectedBlocks, $placeholderPrefix) {
                $placeholder = '<!--' . $placeholderPrefix . count($protectedBlocks) . '-->';
                $protectedBlocks[$placeholder] = $matches[0];
                return $placeholder;
            },
            $text
        );
        if (!is_string($protectedText)) {
            return self::parseSegment($text, $widget, $isContentWidget, $isCommentWidget);
        }

        $text = self::parseSegment($protectedText, $widget, $isContentWidget, $isCommentWidget);

        return empty($protectedBlocks) ? $text : strtr($text, $protectedBlocks);
    }

    private static function parseSegment($text, $widget, $isContentWidget, $isCommentWidget)
    {
        $text = preg_replace_callback(
            "/<(?:links|enhancement)\\s*(\\d*)\\s*(\\w*)\\s*(\\d*)>\\s*(.*?)\\s*<\\/(?:links|enhancement)>/is",
            array('Enhancement_Plugin', 'parseCallback'),
            $text ? $text : ''
        );

        if ($isContentWidget && Enhancement_Plugin::videoParserEnabled()) {
            $text = Enhancement_MediaHelper::replaceVideoLinks($text);
        }

        if ($isContentWidget && Enhancement_Plugin::musicParserEnabled()) {
            $text = Enhancement_MediaHelper::replaceMusicLinks($text);
        }

        if ($isContentWidget) {
            $text = Enhancement_ShortcodeHelper::parseContent($text, $widget);
        }

        if ($isCommentWidget && Enhancement_Plugin::commentSmileyEnabled()) {
            $text = Enhancement_CommentSmileyHelper::parseShortcodes($text);
        }

        $text = Enhancement_GoRedirectHelper::rewriteExternalLinks($text);

        if (Enhancement_Plugin::blankTargetEnabled()) {
            $text = Enhancement_GoRedirectHelper::addBlankTarget($text);
        }

        return $text;
    }
}
