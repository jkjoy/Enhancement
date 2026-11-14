<?php

class Enhancement_MediaHelper
{
    public static function musicMetingApiTemplate(): string
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $settings = Enhancement_Plugin::runtimeSettings();
        $value = isset($settings->music_meting_api) ? trim((string)$settings->music_meting_api) : '';
        $defaultLocal = self::defaultLocalMetingApiTemplate($options);

        if ($value === '' || $value === 'https://api.injahow.cn/meting/?server=:server&type=:type&id=:id&r=:r') {
            $value = $defaultLocal;
        }

        return $value;
    }

    public static function defaultLocalMetingApiTemplate($options = null): string
    {
        if ($options === null) {
            $options = Typecho_Widget::widget('Widget_Options');
        }

        $base = Typecho_Common::url('action/enhancement-edit', $options->index);
        return $base . '?do=meting-api&server=:server&type=:type&id=:id&r=:r';
    }

    public static function archiveHeader($archive = null)
    {
        if (!Enhancement_Plugin::musicParserEnabled()) {
            return;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $base = rtrim((string)$options->pluginUrl, '/');
        if ($base === '') {
            return;
        }

        $cssUrl = htmlspecialchars(Enhancement_Plugin::appendVersionToAssetUrl($base . '/Enhancement/Meting/APlayer.min.css'), ENT_QUOTES, 'UTF-8');
        $aPlayerJsUrl = htmlspecialchars(Enhancement_Plugin::appendVersionToAssetUrl($base . '/Enhancement/Meting/APlayer.min.js'), ENT_QUOTES, 'UTF-8');
        $metingJsUrl = htmlspecialchars(Enhancement_Plugin::appendVersionToAssetUrl($base . '/Enhancement/Meting/Meting.min.js'), ENT_QUOTES, 'UTF-8');
        $api = html_entity_decode(self::musicMetingApiTemplate(), ENT_QUOTES, 'UTF-8');

        echo '<link rel="stylesheet" href="' . $cssUrl . '">' . "\n";
        echo '<script src="' . $aPlayerJsUrl . '"></script>' . "\n";
        echo '<script>var meting_api=' . json_encode($api, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>' . "\n";
        echo '<script src="' . $metingJsUrl . '"></script>' . "\n";
    }

    public static function replaceVideoLinks($content)
    {
        if (empty($content)) {
            return $content;
        }

        $replace = function ($matches) {
            $url = html_entity_decode(isset($matches[1]) ? $matches[1] : $matches[0], ENT_QUOTES, 'UTF-8');
            $videoInfo = self::extractVideoInfo($url);

            return $videoInfo ? self::generateVideoPlayer($videoInfo) : $matches[0];
        };

        $content = preg_replace_callback(
            '/<a\s+[^>]*href=["\']([^"\']*)["\'][^>]*>.*?<\/a>/is',
            $replace,
            $content
        );

        return preg_replace_callback(
            '/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/|bilibili\.com\/video\/|v\.youku\.com\/v_show\/id_)[^\s<]+/i',
            $replace,
            $content
        );
    }

    public static function replaceMusicLinks($content)
    {
        if (empty($content)) {
            return $content;
        }

        $replace = function ($matches) {
            $url = html_entity_decode(isset($matches[1]) ? $matches[1] : $matches[0], ENT_QUOTES, 'UTF-8');
            $musicInfo = self::extractMusicInfo($url);
            if (!$musicInfo) {
                return $matches[0];
            }

            $player = self::generateMusicPlayer($musicInfo);
            return $player !== '' ? $player : $matches[0];
        };

        $content = preg_replace_callback(
            '/<a\s+[^>]*href=["\']([^"\']*)["\'][^>]*>.*?<\/a>/is',
            $replace,
            $content
        );

        return preg_replace_callback('/https?:\/\/[^\s<]+/i', $replace, $content);
    }

    private static function extractMusicInfo($url)
    {
        $url = trim(html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return null;
        }

        $decodedGoUrl = Enhancement_GoRedirectHelper::decodeGoRedirectUrl($url);
        if ($decodedGoUrl !== '') {
            $url = $decodedGoUrl;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        if (strpos($host, 'music.163.com') !== false || strpos($host, '.163.com') !== false) {
            foreach (array('(?:playlist|toplist)' => 'playlist', 'album' => 'album', 'song' => 'song', 'artist' => 'artist') as $pattern => $type) {
                if (preg_match('/' . $pattern . '\?id=(\d+)/i', $url, $matches)) {
                    return array('server' => 'netease', 'type' => $type, 'id' => $matches[1]);
                }
            }
        }

        if (strpos($host, 'y.qq.com') !== false || strpos($host, 'qq.com') !== false) {
            foreach (array('playsquare' => 'playlist', 'playlist' => 'playlist', 'album' => 'album', 'song' => 'song', 'singer' => 'artist') as $pattern => $type) {
                if (preg_match('/' . $pattern . '\/([^\.?&#\/]+)/i', $url, $matches)) {
                    return array('server' => 'tencent', 'type' => $type, 'id' => $matches[1]);
                }
            }
        }

        if (strpos($host, 'kugou.com') !== false) {
            if (preg_match('/special\/single\/(\d+)/i', $url, $matches)) {
                return array('server' => 'kugou', 'type' => 'playlist', 'id' => $matches[1]);
            }
            if (preg_match('/album\/[single\/]*(\d+)/i', $url, $matches)) {
                return array('server' => 'kugou', 'type' => 'album', 'id' => $matches[1]);
            }
            if (preg_match('/singer\/[home\/]*(\d+)/i', $url, $matches)) {
                return array('server' => 'kugou', 'type' => 'artist', 'id' => $matches[1]);
            }
            if (preg_match('/[\?&#]hash=([A-Za-z0-9]+)/i', $url, $matches)) {
                return array('server' => 'kugou', 'type' => 'song', 'id' => $matches[1]);
            }
        }

        return null;
    }

    private static function generateMusicPlayer(array $musicInfo): string
    {
        $server = isset($musicInfo['server']) ? strtolower(trim((string)$musicInfo['server'])) : '';
        $type = isset($musicInfo['type']) ? strtolower(trim((string)$musicInfo['type'])) : '';
        $id = isset($musicInfo['id']) ? trim((string)$musicInfo['id']) : '';

        if ($server === '' || $type === '' || $id === '') {
            return '';
        }

        if (!preg_match('/^[a-z]+$/', $server)) {
            return '';
        }

        if (!preg_match('/^(song|album|artist|playlist)$/', $type)) {
            return '';
        }

        if (!preg_match('/^[0-9A-Za-z_\-]+$/', $id)) {
            return '';
        }

        return '<meting-js server="' . htmlspecialchars($server, ENT_QUOTES, 'UTF-8')
            . '" type="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8')
            . '" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8')
            . '" fixed="false" autoplay="false" loop="all" order="list" list-folded="false" list-max-height="340px"></meting-js>';
    }

    private static function extractVideoInfo($url)
    {
        $url = trim(html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return null;
        }

        $decodedGoUrl = Enhancement_GoRedirectHelper::decodeGoRedirectUrl($url);
        if ($decodedGoUrl !== '') {
            $url = $decodedGoUrl;
        }

        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([^&\n?#\/]+)/i', $url, $matches)) {
            return array(
                'platform' => 'youtube',
                'videoId' => $matches[1]
            );
        }

        if (preg_match('/bilibili\.com\/video\/(BV[0-9A-Za-z]+)/i', $url, $matches)) {
            return array(
                'platform' => 'bilibili',
                'videoId' => $matches[1],
                'idType' => 'bvid'
            );
        }

        if (preg_match('/bilibili\.com\/video\/av(\d+)/i', $url, $matches)) {
            return array(
                'platform' => 'bilibili',
                'videoId' => $matches[1],
                'idType' => 'aid'
            );
        }

        if (preg_match('/v\.youku\.com\/v_show\/id_([A-Za-z0-9=]+)\.html/i', $url, $matches)) {
            return array(
                'platform' => 'youku',
                'videoId' => $matches[1]
            );
        }

        return null;
    }

    private static function generateVideoPlayer($videoInfo)
    {
        $embedUrl = self::getVideoEmbedUrl($videoInfo);
        if ($embedUrl === '') {
            return '';
        }

        $platform = isset($videoInfo['platform']) ? strtolower((string)$videoInfo['platform']) : '';
        $platformLabelHtml = self::buildVideoPlatformLabelHtml($platform);
        $html = '<div class="enhancement-video-player-wrapper">';
        $html .= '<div class="enhancement-platform-label enhancement-label-' . $platform . '">' . $platformLabelHtml . '</div>';
        $html .= '<div class="enhancement-player-container enhancement-' . $platform . '">';
        $html .= '<iframe src="' . htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') . '" ';
        $html .= 'allowfullscreen ';
        $html .= 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" ';
        $html .= 'style="width: 100%; height: 500px; border: none;">';
        $html .= '</iframe>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function buildVideoPlatformLabelHtml($platform)
    {
        $platform = strtolower(trim((string)$platform));
        $platformLabel = strtoupper($platform);
        $iconSvg = self::getVideoPlatformIconSvg($platform);

        if ($iconSvg !== '') {
            return '<span class="enhancement-platform-icon" title="' . htmlspecialchars($platformLabel, ENT_QUOTES, 'UTF-8') . '" '
                . 'style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;line-height:1;vertical-align:middle;">'
                . $iconSvg
                . '</span>';
        }

        return htmlspecialchars($platformLabel, ENT_QUOTES, 'UTF-8');
    }

    private static function getVideoPlatformIconSvg($platform)
    {
        switch ($platform) {
            case 'bilibili':
                return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 640 640" aria-hidden="true" focusable="false">'
                    . '<path fill="#74C0FC" d="M552.6 168.1C569.3 186.2 577 207.8 575.9 233.8L575.9 436.2C575.5 462.6 566.7 484.3 549.4 501.3C532.2 518.3 510.3 527.2 483.9 528L156 528C129.6 527.2 107.8 518.2 90.7 500.8C73.6 483.4 64.7 460.5 64 432.2L64 233.8C64.8 207.8 73.7 186.2 90.7 168.1C107.8 151.8 129.5 142.8 156 142L185.4 142L160 116.2C154.3 110.5 151.4 103.2 151.4 94.4C151.4 85.6 154.3 78.3 160 72.6C165.7 66.9 173 64 181.9 64C190.8 64 198 66.9 203.8 72.6L277.1 142L365.1 142L439.6 72.6C445.7 66.9 453.2 64 462 64C470.8 64 478.1 66.9 483.9 72.6C489.6 78.3 492.5 85.6 492.5 94.4C492.5 103.2 489.6 110.5 483.9 116.2L458.6 142L487.9 142C514.3 142.8 535.9 151.8 552.6 168.1zM513.8 237.8C513.4 228.2 510.1 220.4 503.1 214.3C497.9 208.2 489.1 204.9 480.4 204.5L160 204.5C150.4 204.9 142.6 208.2 136.4 214.3C130.3 220.4 127 228.2 126.6 237.8L126.6 432.2C126.6 441.4 129.9 449.2 136.4 455.7C142.9 462.2 150.8 465.5 160 465.5L480.4 465.5C489.6 465.5 497.4 462.2 503.7 455.7C510 449.2 513.4 441.4 513.8 432.2L513.8 237.8zM249.5 280.5C255.8 286.8 259.2 294.6 259.6 303.7L259.6 337C259.2 346.2 255.9 353.9 249.8 360.2C243.6 366.5 235.8 369.7 226.2 369.7C216.6 369.7 208.7 366.5 202.6 360.2C196.5 353.9 193.2 346.2 192.8 337L192.8 303.7C193.2 294.6 196.6 286.8 202.9 280.5C209.2 274.2 216.1 270.9 226.2 270.5C235.4 270.9 243.2 274.2 249.5 280.5zM441 280.5C447.3 286.8 450.7 294.6 451.1 303.7L451.1 337C450.7 346.2 447.4 353.9 441.3 360.2C435.2 366.5 427.3 369.7 417.7 369.7C408.1 369.7 400.3 366.5 394.1 360.2C387.1 353.9 384.7 346.2 384.4 337L384.4 303.7C384.7 294.6 388.1 286.8 394.4 280.5C400.7 274.2 408.5 270.9 417.7 270.5C426.9 270.9 434.7 274.2 441 280.5z"/>'
                    . '</svg>';
            default:
                return '';
        }
    }

    private static function getVideoEmbedUrl($videoInfo)
    {
        $platform = isset($videoInfo['platform']) ? strtolower((string)$videoInfo['platform']) : '';
        $videoId = isset($videoInfo['videoId']) ? (string)$videoInfo['videoId'] : '';

        if ($videoId === '') {
            return '';
        }

        switch ($platform) {
            case 'youtube':
                return 'https://www.youtube.com/embed/' . rawurlencode($videoId);
            case 'bilibili':
                $idType = isset($videoInfo['idType']) ? strtolower((string)$videoInfo['idType']) : 'bvid';
                if ($idType === 'aid') {
                    return 'https://player.bilibili.com/player.html?aid=' . rawurlencode($videoId) . '&high_quality=1';
                }
                return 'https://player.bilibili.com/player.html?bvid=' . rawurlencode($videoId) . '&high_quality=1';
            case 'youku':
                return 'https://player.youku.com/embed/' . rawurlencode($videoId);
            default:
                return '';
        }
    }
}
