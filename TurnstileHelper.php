<?php

class Enhancement_TurnstileHelper
{
    public static function enabled(): bool
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        return isset($settings->enable_turnstile) && $settings->enable_turnstile == '1';
    }

    public static function siteKey(): string
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        return isset($settings->turnstile_site_key) ? trim((string)$settings->turnstile_site_key) : '';
    }

    public static function secretKey(): string
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        return isset($settings->turnstile_secret_key) ? trim((string)$settings->turnstile_secret_key) : '';
    }

    public static function ready(): bool
    {
        return self::enabled() && self::siteKey() !== '' && self::secretKey() !== '';
    }

    public static function commentGuestOnly(): bool
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        return !isset($settings->turnstile_comment_guest_only) || $settings->turnstile_comment_guest_only == '1';
    }

    public static function verify($token, $remoteIp = ''): array
    {
        if (!self::enabled()) {
            return array('success' => true, 'message' => 'disabled');
        }

        $siteKey = self::siteKey();
        $secret = self::secretKey();
        if ($siteKey === '' || $secret === '') {
            return array('success' => false, 'message' => _t('Turnstile 未配置完整（缺少 Site Key 或 Secret Key）'));
        }

        $token = trim((string)$token);
        if ($token === '') {
            return array('success' => false, 'message' => _t('请完成人机验证后再提交'));
        }

        $postFields = array(
            'secret' => $secret,
            'response' => $token
        );
        $remoteIp = trim((string)$remoteIp);
        if ($remoteIp !== '') {
            $postFields['remoteip'] = $remoteIp;
        }

        $ch = function_exists('curl_init') ? curl_init() : null;
        if (!$ch) {
            return array('success' => false, 'message' => _t('当前环境不支持 Turnstile 校验（缺少 cURL）'));
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded'
            )
        ));

        $response = curl_exec($ch);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        unset($ch);
        if ($error !== null) {
            return array('success' => false, 'message' => _t('人机验证请求失败：%s', $error));
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            return array('success' => false, 'message' => _t('人机验证返回数据异常'));
        }

        if (!empty($decoded['success'])) {
            return array('success' => true, 'message' => 'ok');
        }

        $codes = isset($decoded['error-codes']) && is_array($decoded['error-codes']) ? $decoded['error-codes'] : array();
        $codeText = !empty($codes) ? implode(', ', $codes) : 'unknown_error';
        return array('success' => false, 'message' => _t('人机验证失败：%s', $codeText));
    }

    public static function renderBlock($formId = ''): string
    {
        if (!self::ready()) {
            return '';
        }

        $formId = trim((string)$formId);
        $formIdAttr = $formId !== '' ? ' data-form-id="' . htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') . '"' : '';
        $siteKey = htmlspecialchars(self::siteKey(), ENT_QUOTES, 'UTF-8');

        return '<div class="typecho-option enhancement-turnstile enhancement-public-full"' . $formIdAttr . '>'
            . '<div class="cf-turnstile" data-sitekey="' . $siteKey . '"></div>'
            . '</div>';
    }

    public static function renderFooter($archive = null)
    {
        if (!($archive instanceof Widget_Archive) || !$archive->is('single')) {
            return;
        }

        if (!self::ready()) {
            return;
        }

        $siteKey = htmlspecialchars(self::siteKey(), ENT_QUOTES, 'UTF-8');
        $commentNeedCaptcha = true;
        if (self::commentGuestOnly()) {
            $user = Typecho_Widget::widget('Widget_User');
            $commentNeedCaptcha = !$user->hasLogin();
        }
        $selectorParts = array('form.enhancement-public-form');
        if ($commentNeedCaptcha) {
            $selectorParts[] = 'form[action*="/comment"]';
        }
        $selector = implode(', ', $selectorParts);

        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        echo '<script>(function(){'
            . 'var siteKey=' . json_encode($siteKey) . ';'
            . 'var selector=' . json_encode($selector) . ';'
            . 'var forms=document.querySelectorAll(selector);'
            . 'for(var i=0;i<forms.length;i++){'
            . 'var form=forms[i];'
            . 'if(form.querySelector(".cf-turnstile")){continue;}'
            . 'var holder=document.createElement("div");'
            . 'holder.className="typecho-option enhancement-turnstile";'
            . 'var widget=document.createElement("div");'
            . 'widget.className="cf-turnstile";'
            . 'widget.setAttribute("data-sitekey", siteKey);'
            . 'holder.appendChild(widget);'
            . 'var submit=form.querySelector("button[type=submit], input[type=submit]");'
            . 'if(submit){'
            . 'var wrap=submit.closest?submit.closest("p,div"):null;'
            . 'if(wrap && wrap.parentNode===form){form.insertBefore(holder, wrap);}'
            . 'else if(submit.parentNode){submit.parentNode.insertBefore(holder, submit);}'
            . 'else{form.appendChild(holder);}'
            . '}else{form.appendChild(holder);}'
            . '}'
            . 'if(window.turnstile && window.turnstile.render){'
            . 'var els=document.querySelectorAll(".cf-turnstile");'
            . 'for(var j=0;j<els.length;j++){'
            . 'if(!els[j].hasAttribute("data-widget-id")){'
            . 'var id=window.turnstile.render(els[j]);'
            . 'if(id){els[j].setAttribute("data-widget-id", id);}'
            . '}'
            . '}'
            . '}'
            . '})();</script>';
    }

    public static function filterComment($comment, $post, $last)
    {
        $current = empty($last) ? $comment : $last;
        if (!self::enabled()) {
            return $current;
        }

        if (self::commentGuestOnly()) {
            $user = Typecho_Widget::widget('Widget_User');
            if ($user->hasLogin()) {
                return $current;
            }
        }

        $token = Typecho_Request::getInstance()->get('cf-turnstile-response');
        $verify = self::verify($token, Typecho_Request::getInstance()->getIp());
        if (empty($verify['success'])) {
            Typecho_Cookie::set('__typecho_remember_text', isset($current['text']) ? (string)$current['text'] : '');
            throw new Typecho_Widget_Exception(isset($verify['message']) ? $verify['message'] : _t('人机验证失败'));
        }

        return $current;
    }
}
