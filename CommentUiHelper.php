<?php

class Enhancement_CommentUiHelper
{
    public static function renderSmileyPicker($archive = null)
    {
        // Output on every page: PJAX themes (e.g. Swup) swap in comment forms
        // without rerunning the footer, so the observer below must already exist.
        if (!($archive instanceof Widget_Archive)) {
            return;
        }

        if (!Enhancement_Plugin::commentSmileyEnabled()) {
            return;
        }

        $baseUrl = Enhancement_CommentSmileyHelper::baseUrl();
        if ($baseUrl === '') {
            return;
        }

        $items = array();
        foreach (Enhancement_CommentSmileyHelper::definitions() as $item) {
            $code = isset($item[0]) ? trim((string)$item[0]) : '';
            $image = isset($item[1]) ? trim((string)$item[1]) : '';
            $title = isset($item[3]) ? trim((string)$item[3]) : '';
            if ($title === '' && isset($item[2])) {
                $title = trim((string)$item[2]);
            }

            if ($code === '' || $image === '') {
                continue;
            }

            $items[] = array(
                'code' => $code,
                'title' => $title !== '' ? $title : $code,
                'image' => Enhancement_Plugin::appendVersionToAssetUrl($baseUrl . '/' . ltrim($image, '/')),
            );
        }

        if (empty($items)) {
            return;
        }

        echo '<style id="enhancement-comment-smiley-style">'
            . '.enhancement-comment-smiley{--enhancement-comment-smiley-size:20px;margin:0 0 10px;font-size:inherit;}'
            . '.enhancement-comment-smiley-toggle{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fff;color:#333;cursor:pointer;line-height:1;font-size:13px;transition:border-color .2s ease,background-color .2s ease;}'
            . '.enhancement-comment-smiley-toggle:hover{border-color:#bbb;background:#fafafa;}'
            . '.enhancement-comment-smiley-panel{display:none;margin-top:8px;padding:10px;border:1px solid #eee;border-radius:8px;background:#fff;box-sizing:border-box;max-height:220px;overflow:auto;}'
            . '.enhancement-comment-smiley-panel.is-open{display:flex;flex-wrap:wrap;gap:8px;}'
            . '.enhancement-comment-smiley-item{display:inline-flex;align-items:center;justify-content:center;padding:4px;border:1px solid transparent;border-radius:6px;background:#fff;cursor:pointer;line-height:0;transition:border-color .2s ease,background-color .2s ease;}'
            . '.enhancement-comment-smiley-item:hover{border-color:#e2e2e2;background:#fafafa;}'
            . '.enhancement-comment-smiley-item img{width:var(--enhancement-comment-smiley-size);height:var(--enhancement-comment-smiley-size);display:block;object-fit:contain;}'
            . '#comments img[src*="/Enhancement/smiley/"], .comment-list img[src*="/Enhancement/smiley/"], .comment-content img[src*="/Enhancement/smiley/"]{width:20px !important;height:20px !important;max-width:20px !important;display:inline-block;vertical-align:-0.15em;margin:0 .08em;}'
            . '</style>';

        echo '<script>(function(){'
            . 'var items=' . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';'
            . 'if(!items||!items.length){return;}'
            . 'function createEvent(name){try{return new Event(name,{bubbles:true});}catch(e){var evt=document.createEvent("Event");evt.initEvent(name,true,true);return evt;}}'
            . 'function insertText(textarea,text){if(!textarea){return;}'
            . 'var value=textarea.value||"";'
            . 'var start=typeof textarea.selectionStart==="number"?textarea.selectionStart:value.length;'
            . 'var end=typeof textarea.selectionEnd==="number"?textarea.selectionEnd:value.length;'
            . 'var before=value.slice(0,start);'
            . 'var after=value.slice(end);'
            . 'var prefix=before&&!/\s$/.test(before)?" ":"";'
            . 'var suffix=after&&!/^\s/.test(after)?" ":"";'
            . 'var insertValue=prefix+text+suffix;'
            . 'textarea.value=before+insertValue+after;'
            . 'var cursor=(before+insertValue).length;'
            . 'textarea.focus();'
            . 'if(typeof textarea.setSelectionRange==="function"){textarea.setSelectionRange(cursor,cursor);}'
            . 'textarea.dispatchEvent(createEvent("input"));'
            . 'textarea.dispatchEvent(createEvent("change"));'
            . '}'
            . 'function bindForm(form){'
            . 'if(!form||form.getAttribute("data-enhancement-smiley")==="1"){return;}'
            . 'var textarea=form.querySelector(\'textarea[name="text"], textarea#textarea, textarea#text\');'
            . 'if(!textarea){return;}'
            . 'form.setAttribute("data-enhancement-smiley","1");'
            . 'var wrapper=document.createElement("div");'
            . 'wrapper.className="enhancement-comment-smiley";'
            . 'var toggle=document.createElement("button");'
            . 'toggle.type="button";'
            . 'toggle.className="enhancement-comment-smiley-toggle";'
            . 'toggle.innerHTML="<span aria-hidden=\\"true\\">😊</span><span>表情</span>";'
            . 'var panel=document.createElement("div");'
            . 'panel.className="enhancement-comment-smiley-panel";'
            . 'for(var i=0;i<items.length;i++){'
            . 'var item=items[i]||{};'
            . 'if(!item.code||!item.image){continue;}'
            . 'var btn=document.createElement("button");'
            . 'btn.type="button";'
            . 'btn.className="enhancement-comment-smiley-item";'
            . 'btn.setAttribute("title",item.title||item.code);'
            . 'btn.setAttribute("aria-label",item.title||item.code);'
            . 'btn.setAttribute("data-code",item.code);'
            . 'var img=document.createElement("img");'
            . 'img.src=item.image;'
            . 'img.alt=item.code;'
            . 'img.loading="lazy";'
            . 'btn.appendChild(img);'
            . 'btn.addEventListener("click",function(e){'
            . 'e.preventDefault();'
            . 'insertText(textarea,this.getAttribute("data-code")||"");'
            . '});'
            . 'panel.appendChild(btn);'
            . '}'
            . 'toggle.addEventListener("click",function(e){'
            . 'e.preventDefault();'
            . 'panel.classList.toggle("is-open");'
            . '});'
            . 'document.addEventListener("click",function(e){'
            . 'if(!wrapper.contains(e.target)){panel.classList.remove("is-open");}'
            . '});'
            . 'wrapper.appendChild(toggle);'
            . 'wrapper.appendChild(panel);'
            . 'if(textarea.parentNode){textarea.parentNode.insertBefore(wrapper,textarea);}'
            . 'else{form.insertBefore(wrapper,form.firstChild);}'
            . '}'
            . 'function init(){'
            . 'var forms=document.querySelectorAll(\'form[action*="/comment"], form#comment-form, #comment-form form, form.comment-form\');'
            . 'if(!forms||!forms.length){return;}'
            . 'for(var i=0;i<forms.length;i++){bindForm(forms[i]);}'
            . '}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}'
            . 'if(window.MutationObserver&&document.body){'
            . 'var observer=new MutationObserver(function(){init();});'
            . 'observer.observe(document.body,{childList:true,subtree:true});'
            . '}'
            . '})();</script>';
    }

    public static function renderAuthorLinkEnhancer($archive = null)
    {
        if (!($archive instanceof Widget_Archive) || !$archive->is('single')) {
            return;
        }

        $enableBlankTarget = Enhancement_Plugin::blankTargetEnabled();
        $enableGoRedirect = Enhancement_Plugin::goRedirectEnabled();
        if (!$enableBlankTarget && !$enableGoRedirect) {
            return;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $siteHost = Enhancement_GoRedirectHelper::normalizeHost(parse_url((string)$options->siteUrl, PHP_URL_HOST));
        $goBase = Typecho_Common::url('go/', $options->index);
        $goPath = (string)parse_url($goBase, PHP_URL_PATH);
        $goPath = '/' . ltrim($goPath, '/');
        $whitelist = array_values(Enhancement_GoRedirectHelper::parseGoRedirectWhitelist());

        echo '<script>(function(){'
            . 'var enableBlank=' . json_encode($enableBlankTarget) . ';'
            . 'var enableGo=' . json_encode($enableGoRedirect) . ';'
            . 'var siteHost=' . json_encode($siteHost) . ';'
            . 'var goBase=' . json_encode($goBase) . ';'
            . 'var goPath=' . json_encode($goPath) . ';'
            . 'var whitelist=' . json_encode($whitelist) . ';'
            . 'var links=document.querySelectorAll("#comments .comment-author a[href], #comments .comment__author-name a[href], .comment-author a[href], .comment__author-name a[href], .comment-meta .comment-author a[href], .vcard a[href]");'
            . 'if(!links||!links.length){return;}'
            . 'function normalizeHost(host){host=(host||"").toLowerCase().trim();if(host.indexOf("www.")==0){host=host.slice(4);}return host;}'
            . 'function isWhitelisted(host){if(!host){return false;}host=normalizeHost(host);for(var i=0;i<whitelist.length;i++){var domain=normalizeHost(whitelist[i]);if(!domain){continue;}if(host===domain){return true;}if(host.length>domain.length&&host.slice(-1*(domain.length+1))==="."+domain){return true;}}return false;}'
            . 'function isGoHref(url){if(!url){return false;}if(goBase&&url.indexOf(goBase)===0){return true;}try{var parsed=new URL(url,window.location.href);if(!goPath||goPath==="/"){return false;}var path="/"+(parsed.pathname||"").replace(/^\/+/,"");var normalizedGoPath="/"+String(goPath).replace(/^\/+/,"");return path.indexOf(normalizedGoPath)===0;}catch(e){return false;}}'
            . 'function toBase64Url(input){try{var utf8=unescape(encodeURIComponent(input));var b64=btoa(utf8);return b64.replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/g,"");}catch(e){return "";}}'
            . 'for(var i=0;i<links.length;i++){'
            . 'var link=links[i];'
            . 'var href=(link.getAttribute("href")||"").trim();'
            . 'if(!href){continue;}'
            . 'if(enableGo&&!isGoHref(href)){'
            . 'try{'
            . 'var lower=href.toLowerCase();'
            . 'if(lower.indexOf("mailto:")!==0&&lower.indexOf("tel:")!==0&&lower.indexOf("javascript:")!==0&&lower.indexOf("data:")!==0&&href.indexOf("#")!==0&&href.indexOf("/")!==0&&href.indexOf("?")!==0){'
            . 'var parsed=new URL(href,window.location.href);'
            . 'var protocol=(parsed.protocol||"").toLowerCase();'
            . 'var host=normalizeHost(parsed.hostname||"");'
            . 'if((protocol==="http:"||protocol==="https:")&&host&&host!==normalizeHost(siteHost)&&!isWhitelisted(host)){'
            . 'var normalized=parsed.href;'
            . 'var token=toBase64Url(normalized);'
            . 'if(token){link.setAttribute("href", String(goBase||"")+token);href=link.getAttribute("href")||href;}'
            . '}'
            . '}'
            . '}catch(e){}'
            . '}'
            . 'if(enableBlank){'
            . 'link.setAttribute("target","_blank");'
            . 'var rel=(link.getAttribute("rel")||"").toLowerCase().trim();'
            . 'var rels=rel?rel.split(/\s+/):[];'
            . 'if(rels.indexOf("noopener")<0){rels.push("noopener");}'
            . 'if(rels.indexOf("noreferrer")<0){rels.push("noreferrer");}'
            . 'link.setAttribute("rel",rels.join(" ").trim());'
            . '}'
            . '}'
            . '})();</script>';
    }
}
