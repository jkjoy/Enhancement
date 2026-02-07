<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class Enhancement_Go_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function execute()
    {
        // no-op
    }

    public function action()
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
        echo '</style></head><body>';
        echo '<div class="wrap"><div class="card">';
        echo '<h1>即将离开本站</h1>';
        echo '<p>你将访问外部网站，请注意账号与隐私安全。</p>';
        echo '<p>目标地址：<span id="target-url">' . $safeUrl . '</span></p>';
        echo '<div class="actions">';
        echo '<a class="btn btn-primary" href="' . $safeUrl . '" rel="noopener noreferrer nofollow">继续访问</a>';
        echo '<a class="btn btn-secondary" href="' . $safeHomeUrl . '">回到首页</a>';
        echo '</div>';
        echo '</div></div>';
        echo '</body></html>';
    }
}
