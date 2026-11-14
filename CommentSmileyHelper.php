<?php

class Enhancement_CommentSmileyHelper
{
    public static function definitions(): array
    {
        return array(
            array(':?:', 'doubt.png', '疑问', '疑问'),
            array(':razz:', 'razz.png', '调皮', '调皮'),
            array(':sad:', 'sad.png', '难过', '难过'),
            array(':evil:', 'evil.png', '抠鼻', '抠鼻'),
            array(':naughty:', 'naughty.png', '顽皮', '顽皮'),
            array(':!:', 'scare.png', '吓', '吓'),
            array(':smile:', 'smile.png', '微笑', '微笑'),
            array(':oops:', 'oops.png', '憨笑', '憨笑'),
            array(':neutral:', 'neutral.png', '亲亲', '亲亲'),
            array(':cry:', 'cry.png', '大哭', '大哭'),
            array(':mrgreen:', 'mrgreen.png', '呲牙', '呲牙'),
            array(':grin:', 'grin.png', '坏笑', '坏笑'),
            array(':eek:', 'eek.png', '惊讶', '惊讶'),
            array(':shock:', 'shock.png', '发呆', '发呆'),
            array(':???:', 'bz.png', '撇嘴', '撇嘴'),
            array(':cool:', 'cool.png', '酷', '酷'),
            array(':lol:', 'lol.png', '偷笑', '偷笑'),
            array(':mad:', 'mad.png', '咒骂', '咒骂'),
            array(':twisted:', 'twisted.png', '发怒', '发怒'),
            array(':roll:', 'roll.png', '白眼', '白眼'),
            array(':wink:', 'wink.png', '鼓掌', '鼓掌'),
            array(':idea:', 'idea.png', '想法', '想法'),
            array(':despise:', 'despise.png', '蔑视', '蔑视'),
            array(':celebrate:', 'celebrate.png', '庆祝', '庆祝'),
            array(':watermelon:', 'watermelon.png', '西瓜', '西瓜'),
            array(':xmas:', 'xmas.png', '圣诞', '圣诞'),
            array(':warn:', 'warn.png', '警告', '警告'),
            array(':rainbow:', 'rainbow.png', '彩虹', '彩虹'),
            array(':loveyou:', 'loveyou.png', '爱你', '爱你'),
            array(':love:', 'love.png', '爱', '爱'),
            array(':beer:', 'beer.png', '啤酒', '啤酒'),
        );
    }

    public static function baseUrl(): string
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $pluginUrl = rtrim((string)$options->pluginUrl, '/');
        if ($pluginUrl === '') {
            return '';
        }

        return $pluginUrl . '/Enhancement/smiley';
    }

    public static function items(): array
    {
        $items = array();
        $baseUrl = self::baseUrl();
        if ($baseUrl !== '') {
            foreach (self::definitions() as $item) {
                $items[] = array(
                    'code' => $item[0],
                    'title' => $item[3],
                    'image' => Enhancement_Plugin::appendVersionToAssetUrl($baseUrl . '/' . $item[1]),
                );
            }
        }

        return $items;
    }

    public static function parseShortcodes(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $replaceMap = array();
        foreach (self::items() as $item) {
            $replaceMap[$item['code']] = '<img class="biaoqing enhancement-smiley" width="20" height="20" src="'
                . htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') . '" alt="'
                . htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8') . '" title="'
                . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '" />';
        }

        // strtr() always prefers the longest matching code, e.g. :loveyou: over :love:.
        return empty($replaceMap) ? $text : strtr($text, $replaceMap);
    }
}
