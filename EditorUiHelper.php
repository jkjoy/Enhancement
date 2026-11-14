<?php

class Enhancement_EditorUiHelper
{
    public static function renderBottom($includeTagsList = false)
    {
        if (Enhancement_S3Helper::enabled() && Enhancement_S3Helper::configured()) {
            Enhancement_AttachmentHelper::addS3UrlSyncScript();
        }
        if (Enhancement_Plugin::attachmentPreviewEnabled()) {
            Enhancement_AttachmentHelper::addEnhancedFeatures();
        }
        self::renderShortcodesHelper();
        if ($includeTagsList) {
            self::renderTagsList();
        }
        self::renderAiSlugHelper();
    }

    public static function renderAiSlugHelper()
    {
        if (!Enhancement_Plugin::aiSlugTranslateEnabled()) {
            return;
        }

        $translateUrl = Helper::security()->getIndex('/action/enhancement-edit?do=ai-slug-translate');
?>
<style>
.enh-ai-slug-icon-btn{margin-left:8px !important;width:28px;height:28px;min-width:28px;padding:0 !important;display:inline-flex !important;align-items:center;justify-content:center;vertical-align:middle;line-height:1;border-radius:4px;}
.enh-ai-slug-icon-btn .enh-ai-slug-icon{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;color:inherit;}
.enh-ai-slug-icon-btn .enh-ai-slug-icon svg{display:block;width:14px;height:14px;}
.enh-ai-slug-icon-btn.is-loading .enh-ai-slug-icon{animation:enh-ai-slug-spin .8s linear infinite;}
@keyframes enh-ai-slug-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}
</style>
<script>
(function ($) {
    $(function () {
        var $slug = $('#slug');
        var $title = $('#title');
        if (!$slug.length || !$title.length) {
            return;
        }

        var actionUrl = <?php echo json_encode($translateUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var $cid = $('input[name="cid"]');
        var requestTimer = null;
        var translating = false;
        var lastRequestKey = '';
        var $status = $('#enh-ai-slug-status');
        var $button = $('#enh-ai-slug-generate');
        var $slugRow = $slug.closest('p.url-slug');
        var buttonIcon = '<span class="enh-ai-slug-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.9L19 10l-5.1 2.1L12 17l-1.9-4.9L5 10l5.1-2.1L12 3z"></path><path d="M19 3v4"></path><path d="M21 5h-4"></path></svg></span>';
        var loadingIcon = '<span class="enh-ai-slug-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9"></path></svg></span>';

        if (!$status.length) {
            $status = $('<p id="enh-ai-slug-status" style="margin:4px 0 0;color:#888;font-size:12px;line-height:1.5;"></p>');
            if ($slugRow.length) {
                $slugRow.after($status);
            } else {
                $slug.after($status);
            }
        }

        if (!$button.length) {
            $button = $('<button type="button" id="enh-ai-slug-generate" class="btn enh-ai-slug-icon-btn" title="AI 生成 slug" aria-label="AI 生成 slug">' + buttonIcon + '</button>');
            if ($slugRow.length) {
                $slugRow.append($button);
            } else {
                $status.before($button);
            }
        } else {
            $button.addClass('enh-ai-slug-icon-btn');
        }

        function setStatus(text, color) {
            $status.text(text || '');
            $status.css('color', color || '#888');
        }

        function setButtonLoading(loading) {
            loading = !!loading;
            $button
                .prop('disabled', loading)
                .toggleClass('is-loading', loading)
                .attr('title', loading ? '正在生成 slug' : 'AI 生成 slug')
                .attr('aria-label', loading ? '正在生成 slug' : 'AI 生成 slug')
                .html(loading ? loadingIcon : buttonIcon);
        }

        setButtonLoading(false);

        function requestTranslate(trigger, force) {
            var slugValue = $.trim($slug.val() || '');
            var titleValue = $.trim($title.val() || '');
            if (!force && slugValue !== '') {
                setStatus('');
                return;
            }
            if (titleValue === '') {
                setStatus('标题为空，无法生成 slug', '#d9822b');
                return;
            }

            var cidValue = $.trim(($cid.val() || ''));
            var requestKey = titleValue + '|' + cidValue;
            if (translating || (!force && requestKey === lastRequestKey)) {
                return;
            }

            translating = true;
            if (!force) {
                lastRequestKey = requestKey;
            }
            setButtonLoading(true);
            setStatus(force ? '正在重新生成 slug…' : '正在生成 slug…', '#576a7a');

            $.ajax({
                url: actionUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    title: titleValue,
                    cid: cidValue,
                    trigger: trigger || 'clear'
                }
            }).done(function (response) {
                if (response && response.success && response.slug) {
                    $slug.val(response.slug).trigger('change');
                    setStatus(force ? '已重新生成 slug' : '已自动生成 slug', '#2d8a34');
                    return;
                }

                var message = response && response.message ? response.message : '生成 slug 失败';
                setStatus(message, '#c23030');
                lastRequestKey = '';
            }).fail(function (xhr) {
                var message = '生成 slug 失败';
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
                setStatus(message, '#c23030');
                lastRequestKey = '';
            }).always(function () {
                translating = false;
                setButtonLoading(false);
            });
        }

        function scheduleTranslate() {
            if (requestTimer) {
                window.clearTimeout(requestTimer);
            }

            requestTimer = window.setTimeout(function () {
                requestTranslate('clear', false);
            }, 250);
        }

        $slug.on('blur change', function () {
            if ($.trim($slug.val() || '') === '') {
                scheduleTranslate();
            } else {
                setStatus('');
            }
        });

        $title.on('change', function () {
            if ($.trim($slug.val() || '') === '') {
                lastRequestKey = '';
                scheduleTranslate();
            }
        });

        $button.on('click', function (event) {
            event.preventDefault();
            if (requestTimer) {
                window.clearTimeout(requestTimer);
            }
            lastRequestKey = '';
            requestTranslate('button', true);
        });
    });
})(window.jQuery);
</script>
<?php
    }

    public static function renderShortcodesHelper()
    {
?>
<style>
#wmd-button-row .enh-wmd-shortcode-btn{position:relative;display:block;float:left;width:20px;height:20px;cursor:pointer;}
#wmd-button-row .enh-wmd-shortcode-btn .enh-wmd-icon{display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:#6b7280;}
#wmd-button-row .enh-wmd-shortcode-btn .enh-wmd-icon svg{display:block;width:14px;height:14px;}
#wmd-button-row .enh-wmd-shortcode-btn:hover .enh-wmd-icon{color:#374151;}
#wmd-button-row .enh-wmd-shortcode-spacer{float:left;width:1px;height:18px;margin:1px 6px 0 4px;background:#d1d5db;}
#enh-wmd-shortcode-group{display:inline-flex;align-items:center;vertical-align:top;}
.enh-shortcodes-modal-mask{display:none;position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,.42);}
.enh-shortcodes-modal{position:absolute;top:14vh;left:50%;transform:translateX(-50%);width:min(640px,92vw);background:#fff;border-radius:8px;box-shadow:0 12px 30px rgba(15,23,42,.22);border:1px solid #e5e7eb;padding:14px;}
.enh-shortcodes-modal-close{position:absolute;top:10px;right:10px;width:26px;height:26px;border:0;border-radius:6px;background:transparent;color:#6b7280;font-size:18px;line-height:26px;text-align:center;cursor:pointer;}
.enh-shortcodes-modal-close:hover{background:#f3f4f6;color:#111827;}
.enh-shortcodes-modal-title{font-size:14px;font-weight:600;color:#111827;margin:0 0 10px;}
.enh-shortcodes-modal-input{width:100%;min-height:140px;resize:vertical;border:1px solid #d1d5db;border-radius:6px;padding:8px 10px;line-height:1.6;color:#111827;background:#fff;box-sizing:border-box;}
.enh-shortcodes-modal-fields{display:none;}
.enh-shortcodes-modal-field{margin-bottom:10px;}
.enh-shortcodes-modal-field:last-child{margin-bottom:0;}
.enh-shortcodes-modal-field label{display:block;margin:0 0 4px;font-size:12px;color:#4b5563;}
.enh-shortcodes-modal-field input{width:100%;height:34px;border:1px solid #d1d5db;border-radius:6px;padding:0 10px;box-sizing:border-box;color:#111827;background:#fff;}
.enh-shortcodes-modal-error{display:none;margin-top:8px;font-size:12px;color:#dc2626;line-height:1.4;}
.enh-shortcodes-modal-error.is-visible{display:block;}
.enh-shortcodes-modal-field input.is-error{border-color:#dc2626;box-shadow:0 0 0 2px rgba(220,38,38,.12);}
.enh-shortcodes-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px;}
.enh-shortcodes-modal-btn{border:1px solid #d1d5db;border-radius:4px;background:#fff;color:#374151;padding:5px 12px;cursor:pointer;}
.enh-shortcodes-modal-btn.primary{background:#467B96;border-color:#467B96;color:#fff;}
.enh-shortcodes-modal-btn:hover{opacity:.92;}
@media (max-width: 1200px){
    #wmd-button-bar{display:block;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;padding-bottom:4px;}
    #wmd-button-row{height:auto;min-height:20px;display:flex;flex-wrap:nowrap;align-items:center;width:max-content;min-width:100%;}
    #wmd-button-row .wmd-button,
    #wmd-button-row .wmd-spacer{float:none;flex:0 0 auto;}
    #wmd-button-row .enh-wmd-shortcode-spacer{display:none;}
    #enh-wmd-shortcode-group{display:inline-flex;flex-wrap:nowrap;align-items:center;gap:4px;margin-left:2px;flex:0 0 auto;}
    #wmd-button-row .enh-wmd-shortcode-btn{width:24px;height:24px;margin:0;display:flex;align-items:center;justify-content:center;flex:0 0 auto;}
    #wmd-button-row .enh-wmd-shortcode-btn .enh-wmd-icon svg{width:15px;height:15px;}
}
</style>
<script>
(function ($) {
    $(function () {
        var $text = $('#text');
        if (!$text.length) {
            return;
        }

        var $toolbar = $('#wmd-button-row');
        if (!$toolbar.length || $('#enh-wmd-shortcode-group').length) {
            return;
        }

        var icons = {
            reply: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" aria-hidden="true"><path fill="currentColor" d="M232,200a8,8,0,0,1-16,0,88.1,88.1,0,0,0-88-88H51.31l34.35,34.34a8,8,0,0,1-11.32,11.32l-48-48a8,8,0,0,1,0-11.32l48-48A8,8,0,0,1,85.66,61.66L51.31,96H128A104.11,104.11,0,0,1,232,200Z"></path></svg>',
            primary: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="none" stroke="currentColor" stroke-width="18" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="128" cy="128" r="88"></circle><line x1="128" y1="72" x2="128" y2="144"></line><circle cx="128" cy="184" r="8" fill="currentColor" stroke="none"></circle></svg>',
            success: '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#000000" viewBox="0 0 256 256"><path d="M173.66,98.34a8,8,0,0,1,0,11.32l-56,56a8,8,0,0,1-11.32,0l-24-24a8,8,0,0,1,11.32-11.32L112,148.69l50.34-50.35A8,8,0,0,1,173.66,98.34ZM232,128A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"></path></svg>',
            info: '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#000000" viewBox="0 0 256 256"><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216Zm16-40a8,8,0,0,1-8,8,16,16,0,0,1-16-16V128a8,8,0,0,1,0-16,16,16,0,0,1,16,16v40A8,8,0,0,1,144,176ZM112,84a12,12,0,1,1,12,12A12,12,0,0,1,112,84Z"></path></svg>',
            danger: '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#000000" viewBox="0 0 256 256"><path d="M236.8,188.09,149.35,36.22h0a24.76,24.76,0,0,0-42.7,0L19.2,188.09a23.51,23.51,0,0,0,0,23.72A24.35,24.35,0,0,0,40.55,224h174.9a24.35,24.35,0,0,0,21.33-12.19A23.51,23.51,0,0,0,236.8,188.09ZM222.93,203.8a8.5,8.5,0,0,1-7.48,4.2H40.55a8.5,8.5,0,0,1-7.48-4.2,7.59,7.59,0,0,1,0-7.72L120.52,44.21a8.75,8.75,0,0,1,15,0l87.45,151.87A7.59,7.59,0,0,1,222.93,203.8ZM120,144V104a8,8,0,0,1,16,0v40a8,8,0,0,1-16,0Zm20,36a12,12,0,1,1-12-12A12,12,0,0,1,140,180Z"></path></svg>',
            article: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="none" stroke="currentColor" stroke-width="18" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M80 32h72l56 56v136H80z"></path><polyline points="152 32 152 88 208 88"></polyline><line x1="104" y1="132" x2="184" y2="132"></line><line x1="104" y1="164" x2="184" y2="164"></line></svg>',
            github: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8a8 8 0 0 0 5.47 7.59c.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52 0-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.5-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.01.08-2.1 0 0 .67-.21 2.2.82A7.6 7.6 0 0 1 8 4.8c.68 0 1.37.09 2.01.27 1.53-1.04 2.2-.82 2.2-.82.44 1.09.16 1.9.08 2.1.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8 8 0 0 0 16 8c0-4.42-3.58-8-8-8z"></path></svg>',
            download: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" aria-hidden="true"><path fill="currentColor" d="M216,144v64a16,16,0,0,1-16,16H56a16,16,0,0,1-16-16V144a8,8,0,0,1,16,0v64H200V144a8,8,0,0,1,16,0ZM93.66,117.66,120,144V40a8,8,0,0,1,16,0V144l26.34-26.34a8,8,0,0,1,11.32,11.32l-40,40a8,8,0,0,1-11.32,0l-40-40a8,8,0,0,1,11.32-11.32Z"></path></svg>'
        };

        var items = [
            {
                key: 'reply',
                title: 'reply',
                placeholder: '输入隐藏内容',
                modalTitle: '输入需要隐藏的内容，回复后才能看到',
                defaultText: '内容',
                build: function (value) { return '[reply]' + value + '[/reply]'; }
            },
            {
                key: 'primary',
                title: 'primary',
                placeholder: '输入 primary 内容',
                modalTitle: '输入提示的内容',
                defaultText: '内容',
                build: function (value) { return '[primary]' + value + '[/primary]'; }
            },
            {
                key: 'success',
                title: 'success',
                placeholder: '输入 success 内容',
                modalTitle: '输入成功提示的内容',
                defaultText: '内容',
                build: function (value) { return '[success]' + value + '[/success]'; }
            },
            {
                key: 'info',
                title: 'info',
                placeholder: '输入 info 内容',
                modalTitle: '输入信息提示的内容',
                defaultText: '内容',
                build: function (value) { return '[info]' + value + '[/info]'; }
            },
            {
                key: 'danger',
                title: 'danger',
                placeholder: '输入 danger 内容',
                modalTitle: '输入警示的内容',
                defaultText: '内容',
                build: function (value) { return '[danger]' + value + '[/danger]'; }
            },
            {
                key: 'article',
                title: 'article',
                modalTitle: '输入需要引用文章的cid',
                placeholder: '输入需要引用文章的cid',
                defaultText: '1',
                useSelection: false,
                build: function (value) {
                    var cid = String(value || '').replace(/[^\d]/g, '');
                    if (!cid) {
                        cid = '1640';
                    }
                    return '[article id="' + cid + '"]';
                }
            },
            {
                key: 'github',
                title: 'github',
                placeholder: '输入仓库（例如 jkjoy/memos）',
                modalTitle: '输入 GitHub 仓库名称（例如 jkjoy/memos）',
                defaultText: 'jkjoy/memos',
                build: function (value) {
                    var repo = $.trim(String(value || '')).replace(/\s+/g, '');
                    if (!repo) {
                        repo = 'jkjoy/memos';
                    }
                    return '[github=' + repo + ']';
                }
            },
            {
                key: 'download',
                title: 'download',
                placeholder: '第一行文件名\n第二行文件大小\n第三行下载链接',
                modalTitle: '输入下载卡片信息（文件名/大小/链接）',
                defaultText: 'demo.zip\n1024kb\nhttps://file.imsun.org/demo.zip',
                useSelection: false,
                build: function (value) {
                    var data = parseDownloadModalText(value);
                    var file = data.file;
                    var size = data.size;
                    var url = data.url;

                    if (!url) {
                        url = 'https://file.imsun.org/demo.zip';
                    }

                    function escapeAttr(input) {
                        return String(input || '')
                            .replace(/'/g, '&#39;')
                            .replace(/\]/g, '');
                    }

                    file = escapeAttr(file);
                    size = escapeAttr(size);

                    var attrs = '';
                    if (file) {
                        attrs += " file='" + file + "'";
                    }
                    if (size) {
                        attrs += " size='" + size + "'";
                    }

                    return '[download' + attrs + ']' + url + '[/download]';
                }
            }
        ];

        var $group = $('<span id="enh-wmd-shortcode-group"></span>');
        var lastSelection = {start: null, end: null};
        var currentItem = null;

        function getSelectionRange(value, textarea, range) {
            var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : value.length;
            var end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : value.length;

            if (range && typeof range.start === 'number' && typeof range.end === 'number') {
                start = Math.max(0, Math.min(value.length, range.start));
                end = Math.max(start, Math.min(value.length, range.end));
            }

            return {start: start, end: end};
        }

        function rememberSelection() {
            var textarea = $text.get(0);
            if (!textarea) {
                return;
            }

            var value = $text.val() || '';
            lastSelection = getSelectionRange(value, textarea);
        }

        function insertSnippet(snippet, range) {
            var textarea = $text.get(0);
            if (!textarea) {
                return;
            }

            var value = $text.val() || '';
            var selection = getSelectionRange(value, textarea, range);
            var start = selection.start;
            var end = selection.end;
            var nextValue = value.substring(0, start) + snippet + value.substring(end);

            $text.val(nextValue).trigger('input').trigger('change');
            textarea.focus();

            var cursor = start + snippet.length;
            if (typeof textarea.setSelectionRange === 'function') {
                textarea.setSelectionRange(cursor, cursor);
            }
            lastSelection = {start: cursor, end: cursor};
        }

        function parseDownloadModalText(raw) {
            var text = String(raw || '').replace(/\r/g, '');
            var file = '';
            var size = '';
            var url = '';

            if (text.indexOf('\n') >= 0) {
                var lines = text.split('\n');
                file = $.trim(lines[0] || '');
                size = $.trim(lines[1] || '');
                url = $.trim(lines.slice(2).join('\n') || '');
            } else if (text.indexOf('|') >= 0) {
                var parts = text.split('|');
                file = $.trim(parts[0] || '');
                size = $.trim(parts[1] || '');
                url = $.trim(parts.slice(2).join('|') || '');
            } else {
                url = $.trim(text);
            }

            return {
                file: file,
                size: size,
                url: url
            };
        }

        function readDownloadModalData() {
            return {
                file: $.trim($('#enh-shortcode-download-file').val() || ''),
                size: $.trim($('#enh-shortcode-download-size').val() || ''),
                url: $.trim($('#enh-shortcode-download-url').val() || '')
            };
        }

        function clearModalError() {
            $('#enh-shortcode-modal-error').removeClass('is-visible').text('');
            $('#enh-shortcode-download-url').removeClass('is-error');
        }

        function showModalError(message) {
            var $error = $('#enh-shortcode-modal-error');
            if (!$error.length) {
                return;
            }

            $error.text($.trim(String(message || '')) || '输入有误，请检查后重试').addClass('is-visible');
        }

        function ensureModal() {
            if ($('#enh-shortcode-modal-mask').length) {
                return;
            }

            var modalHtml = ''
                + '<div id="enh-shortcode-modal-mask" class="enh-shortcodes-modal-mask">'
                + '  <div class="enh-shortcodes-modal">'
                + '    <button type="button" id="enh-shortcode-modal-close" class="enh-shortcodes-modal-close" aria-label="关闭">×</button>'
                + '    <h4 id="enh-shortcode-modal-title" class="enh-shortcodes-modal-title">插入短代码</h4>'
                + '    <textarea id="enh-shortcode-modal-input" class="enh-shortcodes-modal-input" placeholder="输入内容"></textarea>'
                + '    <div id="enh-shortcode-download-fields" class="enh-shortcodes-modal-fields">'
                + '      <div class="enh-shortcodes-modal-field">'
                + '        <label for="enh-shortcode-download-file">文件名</label>'
                + '        <input type="text" id="enh-shortcode-download-file" placeholder="例如：demo.zip">'
                + '      </div>'
                + '      <div class="enh-shortcodes-modal-field">'
                + '        <label for="enh-shortcode-download-size">文件大小</label>'
                + '        <input type="text" id="enh-shortcode-download-size" placeholder="例如：1024kb">'
                + '      </div>'
                + '      <div class="enh-shortcodes-modal-field">'
                + '        <label for="enh-shortcode-download-url">下载链接</label>'
                + '        <input type="text" id="enh-shortcode-download-url" placeholder="https://...">'
                + '      </div>'
                + '    </div>'
                + '    <div id="enh-shortcode-modal-error" class="enh-shortcodes-modal-error" role="alert" aria-live="polite"></div>'
                + '    <div class="enh-shortcodes-modal-actions">'
                + '      <button type="button" id="enh-shortcode-modal-cancel" class="enh-shortcodes-modal-btn">取消</button>'
                + '      <button type="button" id="enh-shortcode-modal-confirm" class="enh-shortcodes-modal-btn primary">确定插入</button>'
                + '    </div>'
                + '  </div>'
                + '</div>';

            $('body').append(modalHtml);

            var closeModal = function () {
                clearModalError();
                $('#enh-shortcode-modal-mask').hide();
            };

            $('#enh-shortcode-modal-cancel,#enh-shortcode-modal-close').on('click', function () {
                closeModal();
            });

            $(document).on('keydown.enh-shortcode-modal', function (e) {
                var key = e && (e.key || e.keyCode);
                var isEscape = key === 'Escape' || key === 'Esc' || key === 27;
                if (!isEscape) {
                    return;
                }

                if (!$('#enh-shortcode-modal-mask').is(':visible')) {
                    return;
                }

                e.preventDefault();
                closeModal();
            });

            $('#enh-shortcode-download-file,#enh-shortcode-download-size,#enh-shortcode-download-url').on('input', function () {
                clearModalError();
            });

            $('#enh-shortcode-modal-confirm').on('click', function () {
                if (!currentItem || typeof currentItem.build !== 'function') {
                    closeModal();
                    return;
                }

                clearModalError();

                var value = '';
                if (currentItem.key === 'download') {
                    var downloadData = readDownloadModalData();
                    if (!downloadData.url) {
                        $('#enh-shortcode-download-url').addClass('is-error').focus();
                        showModalError('请填写下载链接');
                        return;
                    }
                    value = [downloadData.file, downloadData.size, downloadData.url].join('\n');
                } else {
                    var raw = $('#enh-shortcode-modal-input').val();
                    value = $.trim(raw || '');
                }
                if (!value) {
                    value = currentItem.defaultText || '内容';
                }

                var snippet = currentItem.build(value);
                if (!snippet) {
                    return;
                }

                var range = $('#enh-shortcode-modal-mask').data('selection') || lastSelection;
                insertSnippet(snippet, range);
                closeModal();
            });
        }

        function openModal(item) {
            var textarea = $text.get(0);
            if (!textarea) {
                return;
            }

            currentItem = item;

            var value = $text.val() || '';
            var selection = getSelectionRange(value, textarea, lastSelection);
            var selected = value.substring(selection.start, selection.end);

            ensureModal();
            clearModalError();

            var useSelection = item.useSelection !== false;
            var defaultValue = (useSelection && selected !== '') ? selected : (item.defaultText || '');
            var $modalInput = $('#enh-shortcode-modal-input');
            var $downloadFields = $('#enh-shortcode-download-fields');
            $('#enh-shortcode-modal-title').text(item.modalTitle || ('插入 ' + item.title + ' 短代码'));

            if (item.key === 'download') {
                var downloadValues = parseDownloadModalText(defaultValue);
                $modalInput.hide();
                $downloadFields.show();
                $('#enh-shortcode-download-file').val(downloadValues.file || '');
                $('#enh-shortcode-download-size').val(downloadValues.size || '');
                $('#enh-shortcode-download-url').val(downloadValues.url || '');
            } else {
                $downloadFields.hide();
                $modalInput.show().attr('placeholder', item.placeholder || '输入内容').val(defaultValue);
            }

            $('#enh-shortcode-modal-mask').data('selection', selection).show();
            if (item.key === 'download') {
                $('#enh-shortcode-download-file').focus();
            } else {
                $modalInput.focus();
            }
        }

        for (var i = 0; i < items.length; i++) {
            (function (item) {
                var iconSvg = icons[item.key] || icons.info;
                var $btn = $('<li class="wmd-button enh-wmd-shortcode-btn" id="wmd-enh-' + item.key + '-button" title="' + item.title + '"><span class="enh-wmd-icon">' + iconSvg + '</span></li>');
                $btn.on('click', function (e) {
                    e.preventDefault();
                    openModal(item);
                });
                $group.append($btn);
            })(items[i]);
        }

        $text.on('keyup click mouseup select focus', rememberSelection);
        rememberSelection();

        $toolbar.append('<li class="wmd-spacer enh-wmd-shortcode-spacer" aria-hidden="true"></li>');
        $toolbar.append($group);
    });
})(jQuery);
</script>
<?php
    }

    public static function renderTagsList()
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        if (isset($settings->enable_tags_helper) && $settings->enable_tags_helper != '1') {
            return;
        }

?>
<style>
.tagshelper a { cursor: pointer; padding: 0px 6px; margin: 2px 0; display: inline-block; border-radius: 2px; text-decoration: none; }
.tagshelper a:hover { background: #ccc; color: #fff; }
</style>
<script>
$(document).ready(function(){
    $('#tags').after('<div style="margin-top: 35px;" class="tagshelper"><ul style="list-style: none;border: 1px solid #D9D9D6;padding: 6px 12px; max-height: 240px;overflow: auto;background-color: #FFF;border-radius: 2px;"><?php
$i = 0;
Typecho_Widget::widget('Widget_Metas_Tag_Cloud', 'sort=count&desc=1&limit=200')->to($tags);
while ($tags->next()) {
    echo "<a id=".$i." onclick=\"$(\'#tags\').tokenInput(\'add\', {id: \'".$tags->name."\', tags: \'".$tags->name."\'});\">".$tags->name."</a>";
    $i++;
}
?></ul></div>');
});
</script>
<?php
    }
}
