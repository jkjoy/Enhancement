<?php

class Enhancement_ConfigUiHelper
{
    public static function renderConfigChrome($pluginVersion)
    {
        $pluginVersionJson = json_encode((string)$pluginVersion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($pluginVersionJson) || $pluginVersionJson === '') {
            $pluginVersionJson = '""';
        }

        echo '<style type="text/css">
    table {
        background: #FFF;
        border: 2px solid #e3e3e3;
        color: #666;
        font-size: .92857em;
        width: 452px;
    }

    th {
        border: 2px solid #e3e3e3;
        padding: 5px;
    }

    table td {
        border-top: 1px solid #e3e3e3;
        padding: 3px;
        text-align: center;
        border-right: 2px solid #e3e3e3;
    }

    .field {
        color: #467B96;
        font-weight: bold;
    }
    .enhancement-title{
        margin:24px 0 8px;
        font-size: 1.2em;
        font-weight: bold;
        color: #270b5b;
    }    
    .enhancement-title::before {
        content: "# ";
        font-size:1em;
        color: #c82609;
    }
    .enhancement-subtitle{
        margin:20px 0 8px;
        font-size: 1.05em;
        font-weight: bold;
        color: #334155;
    }
    .enhancement-subtitle::before {
        content: "## ";
        font-size:1em;
        color: #64748b;
    }
    .enhancement-backup-box{
        margin-top: 12px;
        padding: 12px;
        border: 1px solid #e3e3e3;
        border-radius: 6px;
        background: #fafbff;
    }
    .enhancement-backup-actions{
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        margin-top: 8px;
    }
    .enhancement-backup-list{
        margin-top: 10px;
        padding-left: 18px;
    }
    .enhancement-backup-list li{
        margin-bottom: 8px;
    }
    .enhancement-backup-item-actions{
        display: inline-flex;
        gap: 6px;
        margin-left: 8px;
        vertical-align: middle;
    }
    .enhancement-backup-inline-btn{
        display: inline-block;
        padding: 2px 8px;
        font-size: 12px;
        line-height: 1.6;
        border: 1px solid #c9d3f5;
        border-radius: 4px;
        background: #fff;
        color: #334155;
        text-decoration: none;
        cursor: pointer;
    }
    .enhancement-backup-inline-btn:hover{
        background: #f1f5ff;
    }
    .enhancement-backup-inline-btn.danger{
        border-color: #f3c2c2;
        color: #b42318;
    }
    .enhancement-backup-inline-btn.danger:hover{
        background: #fff1f1;
    }
    .enhancement-action-row{
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
        margin-top: 6px;
    }
    .enhancement-action-btn{
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        height: 32px;
        line-height: 32px;
        padding: 0 14px;
        box-sizing: border-box;
        text-decoration: none !important;
        vertical-align: middle;
    }
    .enhancement-action-btn:hover,
    .enhancement-action-btn:focus{
        text-decoration: none !important;
    }
    .enhancement-update-upgrade.is-hidden{
        display: none !important;
    }
    .enhancement-action-note{
        color: #666;
        line-height: 1.6;
    }
    .enhancement-option-no-bullet,
    .enhancement-option-no-bullet li{
        list-style: none !important;
        margin: 0;
        padding: 0;
    }
    .enhancement-option-no-bullet .description{
        margin: 0;
    }
    .enhancement-option-no-bullet .description:before{
        content: none !important;
        display: none !important;
    }
</style>';

        echo str_replace('__ENHANCEMENT_PLUGIN_VERSION_JSON__', $pluginVersionJson, <<<'HTML'
<style type="text/css">
.typecho-page-main > .enhancement-settings-page{flex:0 0 100%;max-width:100%;margin-left:0;}
.body.container.enhancement-settings-container{max-width:1400px;}
.enhancement-settings-app{--enh-accent:#2563eb;--enh-border:#e2e8f0;--enh-muted:#64748b;--enh-text:#0f172a;margin-top:14px;box-sizing:border-box;}
.enhancement-settings-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 20px;padding:18px 20px;background:#fff;border:1px solid var(--enh-border);border-radius:10px;}
.enhancement-settings-brand{min-width:0;}
.enhancement-settings-brand h2{margin:0;font-size:18px;line-height:1.35;color:var(--enh-text);font-weight:700;}
.enhancement-settings-brand p{margin:4px 0 0;font-size:13px;line-height:1.6;color:var(--enh-muted);}
.enhancement-settings-version{display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;background:#eff6ff;color:var(--enh-accent);font-size:12px;font-weight:600;vertical-align:middle;}
.enhancement-settings-tools{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap;}
.enhancement-settings-search{display:inline-flex;align-items:center;gap:8px;height:36px;min-width:280px;padding:0 12px;border:1px solid #cfd8e3;border-radius:8px;background:#fff;box-sizing:border-box;}
.enhancement-settings-search:focus-within{border-color:#93c5fd;box-shadow:0 0 0 3px rgba(59,130,246,.12);}
.enhancement-settings-search-icon{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;color:#94a3b8;font-size:14px;line-height:1;}
.enhancement-settings-search input{width:100%;border:0;background:transparent;outline:0;box-shadow:none;padding:0;color:var(--enh-text);font-size:13px;}
.enhancement-settings-toolbar-btn{display:inline-flex;align-items:center;justify-content:center;height:36px;padding:0 14px;border-radius:8px;border:1px solid #cfd8e3;background:#fff;color:#334155;cursor:pointer;box-sizing:border-box;font-size:13px;line-height:1;transition:background .15s ease,border-color .15s ease;}
.enhancement-settings-toolbar-btn:hover,.enhancement-settings-toolbar-btn:focus{background:#f8fafc;text-decoration:none;border-color:#b8c4d3;}
.enhancement-settings-body{display:grid;grid-template-columns:184px minmax(0,1fr);gap:24px;align-items:start;}
.enhancement-settings-tabs{position:sticky;top:56px;z-index:20;}
.enhancement-settings-nav{display:flex;flex-direction:column;gap:2px;padding:8px;background:#fff;border:1px solid var(--enh-border);border-radius:10px;}
.enhancement-settings-nav-item{appearance:none;display:flex;align-items:center;width:100%;padding:9px 12px;border:0;border-radius:6px;background:transparent;color:#475569;font-size:13px;line-height:1.2;text-align:left;white-space:nowrap;cursor:pointer;box-sizing:border-box;transition:background .15s ease,color .15s ease;}
.enhancement-settings-nav-item:hover,.enhancement-settings-nav-item:focus{background:#f1f5f9;color:var(--enh-text);outline:none;text-decoration:none;}
.enhancement-settings-nav-item:focus-visible{box-shadow:0 0 0 3px rgba(59,130,246,.18);}
.enhancement-settings-nav-item.is-active{background:#eff6ff;color:var(--enh-accent);font-weight:600;}
.enhancement-settings-nav-label{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;}
.enhancement-settings-nav-short{display:none;}
.enhancement-settings-content-wrap{min-width:0;padding:0 0 88px;}
form.enhancement-settings-form{margin:0;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit{display:flex!important;align-items:center;justify-content:flex-end;position:fixed!important;right:24px!important;bottom:24px!important;z-index:1000!important;margin:0!important;padding:0!important;border:0!important;background:transparent!important;box-shadow:none!important;width:auto!important;list-style:none!important;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit li{display:block!important;margin:0!important;padding:0!important;list-style:none!important;width:auto!important;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit input[type="submit"],
form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit button[type="submit"]{appearance:none;display:inline-flex;align-items:center;justify-content:center;height:42px;min-width:112px;padding:0 20px;border:1px solid var(--enh-accent);border-radius:8px;background:var(--enh-accent);color:#fff;box-sizing:border-box;font-size:14px;font-weight:600;line-height:1;cursor:pointer;box-shadow:0 6px 16px rgba(37,99,235,.28);}
form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit input[type="submit"]:hover,
form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit input[type="submit"]:focus,
form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit button[type="submit"]:hover,
form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit button[type="submit"]:focus{background:#1d4ed8;border-color:#1d4ed8;outline:none;}
.enhancement-settings-main{display:block;}
.enhancement-settings-panel{display:none;background:#fff;border:1px solid var(--enh-border);border-radius:10px;overflow:hidden;}
.enhancement-settings-panel.is-active{display:block;}
.enhancement-settings-panel + .enhancement-settings-panel{margin-top:20px;}
.enhancement-settings-panel-head{padding:16px 24px;border-bottom:1px solid var(--enh-border);background:#fafbfc;}
.enhancement-settings-panel-head h3{margin:0;font-size:16px;line-height:1.4;font-weight:700;color:var(--enh-text);}
form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option{margin:0;padding:18px 24px;border-top:1px solid #f1f5f9;background:transparent;width:auto;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-settings-panel-head + .typecho-option{border-top:0;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option li{list-style:none;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field{display:grid;grid-template-columns:minmax(200px,32%) minmax(0,1fr);gap:8px 32px;align-items:start;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-meta{min-width:0;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-meta .typecho-label{margin:0;padding-top:7px;color:var(--enh-text);font-size:14px;font-weight:600;line-height:1.5;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-meta .description{margin:6px 0 0;color:var(--enh-muted);font-size:12px;line-height:1.65;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control{min-width:0;display:flex;flex-wrap:wrap;align-items:center;gap:8px 18px;min-height:34px;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > input.text,
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > input[type="text"],
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > input[type="password"],
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > textarea,
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > select{flex:1 1 100%;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > span{display:inline-flex;align-items:center;gap:6px;margin:0;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > span label{margin:0;cursor:pointer;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > span input{margin:0;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > .multiline{flex:1 1 100%;display:flex;align-items:center;gap:6px;margin:0;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > .description{flex:1 1 100%;margin:0;color:var(--enh-muted);font-size:12px;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-control > p.description:empty{display:none;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-option-no-bullet .description:empty{display:none;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-option-no-bullet > li{display:grid;grid-template-columns:minmax(200px,32%) minmax(0,1fr);column-gap:32px;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-option-no-bullet > li > *{grid-column:2;}
form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-action-row{margin:0;}
form.enhancement-settings-form.enhancement-settings-form--enhanced input[type="text"],
form.enhancement-settings-form.enhancement-settings-form--enhanced input[type="password"],
form.enhancement-settings-form.enhancement-settings-form--enhanced input[type="number"],
form.enhancement-settings-form.enhancement-settings-form--enhanced input[type="url"],
form.enhancement-settings-form.enhancement-settings-form--enhanced textarea,
form.enhancement-settings-form.enhancement-settings-form--enhanced select{width:100%;max-width:100%;min-height:34px;padding:6px 10px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;box-sizing:border-box;font-size:13px;line-height:1.5;transition:border-color .15s ease,box-shadow .15s ease;}
form.enhancement-settings-form.enhancement-settings-form--enhanced textarea{min-height:96px;resize:vertical;}
form.enhancement-settings-form.enhancement-settings-form--enhanced input[type="text"]:focus,
form.enhancement-settings-form.enhancement-settings-form--enhanced input[type="password"]:focus,
form.enhancement-settings-form.enhancement-settings-form--enhanced input[type="number"]:focus,
form.enhancement-settings-form.enhancement-settings-form--enhanced input[type="url"]:focus,
form.enhancement-settings-form.enhancement-settings-form--enhanced textarea:focus,
form.enhancement-settings-form.enhancement-settings-form--enhanced select:focus{border-color:#93c5fd;box-shadow:0 0 0 3px rgba(59,130,246,.12);outline:none;}
.enhancement-settings-app table{width:100%;max-width:100%;box-sizing:border-box;}
.enhancement-settings-app th,.enhancement-settings-app td,.enhancement-settings-app p,.enhancement-settings-app code{overflow-wrap:anywhere;word-break:break-word;}
.enhancement-settings-app code{white-space:normal;}
.enhancement-settings-app .enhancement-title{margin:0 0 12px;font-size:16px;line-height:1.4;font-weight:700;color:var(--enh-text);}
.enhancement-settings-app .enhancement-title::before{content:none !important;display:none !important;}
.enhancement-settings-app .enhancement-subtitle{margin:8px 0 10px;font-size:15px;line-height:1.35;font-weight:700;color:#1e293b;}
.enhancement-settings-app .enhancement-subtitle::before{content:none !important;display:none !important;}
.enhancement-settings-app .enhancement-backup-box,.enhancement-settings-app .enhancement-update-box{margin-top:0;padding:0;border:0;border-radius:0;background:transparent;}
.enhancement-settings-app .enhancement-action-note{color:var(--enh-muted);}
.enhancement-settings-app .enhancement-smtp-test-status.is-success{color:#15803d;}
.enhancement-settings-app .enhancement-smtp-test-status.is-error{color:#b42318;}
.enhancement-settings-empty{display:none;padding:40px 16px;text-align:center;font-size:14px;line-height:1.8;color:var(--enh-muted);background:#fff;border:1px dashed #d8e2f0;border-radius:10px;}
.enhancement-settings-app.is-searching .enhancement-settings-empty.is-visible{display:block;}
@media (max-width: 991px){
    .enhancement-settings-body{grid-template-columns:minmax(0,1fr);gap:14px;}
    .enhancement-settings-tabs{top:0;z-index:60;margin:0 -10px;padding:8px 10px 0;background:#F6F6F3;}
    .enhancement-settings-nav{flex-direction:row;gap:4px;padding:6px;overflow-x:auto;scrollbar-width:thin;-webkit-overflow-scrolling:touch;touch-action:pan-x;}
    .enhancement-settings-nav-item{width:auto;flex:0 0 auto;padding:8px 12px;}
    .enhancement-settings-nav-label{display:none;}
    .enhancement-settings-nav-short{display:block;}
    form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field,
    form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-option-no-bullet > li{grid-template-columns:minmax(0,1fr);}
    form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-option-no-bullet > li > *{grid-column:1;}
    form.enhancement-settings-form.enhancement-settings-form--enhanced .enhancement-field-meta .typecho-label{padding-top:0;}
}
@media (max-width: 767px){
    .enhancement-settings-header{flex-direction:column;align-items:stretch;padding:14px 16px;}
    .enhancement-settings-tools{justify-content:stretch;}
    .enhancement-settings-search{flex:1 1 auto;min-width:0;}
    .enhancement-settings-panel-head{padding:14px 16px;}
    form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option{padding:16px;}
    .enhancement-settings-content-wrap{padding-bottom:24px;}
    form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit{position:static!important;justify-content:stretch;margin:16px 0 0!important;}
    form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit li{width:100%!important;}
    form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit input[type="submit"],
    form.enhancement-settings-form.enhancement-settings-form--enhanced .typecho-option-submit button[type="submit"]{width:100%;min-width:0;box-shadow:none;}
}
</style>
<script>
(function () {
    var booted = false;

    function boot() {
        var $ = window.jQuery;
        if (booted) {
            return true;
        }
        if (!$) {
            return false;
        }

        booted = true;
        $(function () {
        var pluginVersion = __ENHANCEMENT_PLUGIN_VERSION_JSON__ || '';
        var $page = $('.typecho-page-main .col-mb-12.col-tb-8.col-tb-offset-2').first();
        if (!$page.length) {
            return;
        }

        var $form = $page.children('form').first();
        if (!$form.length || !$form.find('.enhancement-title').length || $form.data('enhancementConfigLayout')) {
            return;
        }

        $form.data('enhancementConfigLayout', '1');

        var $looseBlocks = $page.children('.typecho-option').detach();
        var $configForm = $form.detach();
        var $submit = $configForm.children('.typecho-option-submit').first();
        var $options = $configForm.children('.typecho-option').not('.typecho-option-submit').detach();

        var $introBlocks = $looseBlocks.filter(function () {
            return $(this).find('#enhancement-links-help').length > 0;
        });
        var $backupBlocks = $looseBlocks.filter(function () {
            return $(this).find('.enhancement-backup-box').length > 0;
        });
        var $updateBlocks = $looseBlocks.filter(function () {
            return $(this).find('.enhancement-update-box').length > 0;
        });
        var $miscBlocks = $looseBlocks.not($introBlocks).not($backupBlocks).not($updateBlocks);

        $introBlocks.find('#enhancement-links-help-toggle').remove();
        $introBlocks.find('#enhancement-links-help').show();

        var $app = $('<div class="enhancement-settings-app"></div>');
        var $header = $('<div class="enhancement-settings-header"></div>');
        var $brand = $('<div class="enhancement-settings-brand"><h2>Enhancement <span class="enhancement-settings-version"></span></h2><p>按功能分组管理插件设置，支持搜索、标签切换和快捷保存。</p></div>');
        var $tools = $('<div class="enhancement-settings-tools"></div>');
        var $search = $('<label class="enhancement-settings-search"><span class="enhancement-settings-search-icon" aria-hidden="true">⌕</span><input type="search" id="enhancement-settings-search-input" placeholder="搜索设置项"></label>');
        var $toggleBtn = $('<button type="button" class="enhancement-settings-toolbar-btn">展开全部</button>');
        var $body = $('<div class="enhancement-settings-body"></div>');
        var $tabs = $('<div class="enhancement-settings-tabs"><div class="enhancement-settings-tabs-row"><div class="enhancement-settings-nav" role="tablist" aria-label="插件设置分组" data-enhancement-drag-scroll></div></div></div>');
        var $nav = $tabs.find('.enhancement-settings-nav');
        var $contentWrap = $('<div class="enhancement-settings-content-wrap"></div>');
        var $main = $('<div class="enhancement-settings-main"></div>');
        var $empty = $('<div class="enhancement-settings-empty">没有找到匹配的设置项，请换个关键词试试。</div>');

        $brand.find('.enhancement-settings-version').text('v' + pluginVersion);
        $tools.append($search).append($toggleBtn);
        $header.append($brand).append($tools);
        $body.append($tabs).append($contentWrap);
        $app.append($header).append($body);
        $page.append($app);
        $page.addClass('enhancement-settings-page');
        $page.closest('.body.container').addClass('enhancement-settings-container');

        $configForm.addClass('enhancement-settings-form enhancement-settings-form--enhanced');
        $contentWrap.append($configForm);
        $configForm.prepend($main);
        $main.append($empty);
        if ($submit.length) {
            var $submitControl = $submit.find('input[type="submit"], button[type="submit"]').first();
            if ($submitControl.length) {
                if ($submitControl.is('input')) {
                    $submitControl.val('保存设置');
                } else {
                    $submitControl.text('保存设置');
                }
            }
            $configForm.append($submit);
        } else {
            $submit = $('<ul class="typecho-option typecho-option-submit"><li><button type="submit" class="primary">保存设置</button></li></ul>');
            $configForm.append($submit);
        }

        var sections = [];

        function compactSectionTitle(title) {
            var value = $.trim(title || '').replace(/\s+/g, '');
            if (!value) {
                return '其他';
            }
            if (value.indexOf('备份') >= 0) {
                return '备份';
            }
            if (value.indexOf('概览') >= 0 || value.indexOf('帮助') >= 0) {
                return '帮助';
            }
            if (value.indexOf('更新') >= 0 || value.indexOf('升级') >= 0) {
                return '更新';
            }
            if (value.indexOf('友链') >= 0) {
                return '友链';
            }
            if (value.indexOf('瞬间') >= 0) {
                return '瞬间';
            }
            if (value.indexOf('REST') >= 0) {
                return 'API';
            }
            if (value.indexOf('评论防御') >= 0) {
                return '防御';
            }
            if (value.indexOf('功能') >= 0 || value.indexOf('开关') >= 0) {
                return '开关';
            }
            if (value.indexOf('安全') >= 0 || value.indexOf('Turnstile') >= 0) {
                return '安全';
            }
            if (value.indexOf('AI') >= 0 || value.indexOf('摘要') >= 0) {
                return 'AI';
            }
            if (value.indexOf('QQ') >= 0) {
                return 'QQ';
            }
            if (value.indexOf('邮件') >= 0 || value.indexOf('SMTP') >= 0) {
                return '邮件';
            }
            if (value.indexOf('附件') >= 0 || value.indexOf('上传') >= 0 || value.indexOf('S3') >= 0) {
                return '上传';
            }
            if (value.indexOf('维护') >= 0) {
                return '维护';
            }
            if (value.length <= 2) {
                return value;
            }
            return value.slice(0, 2);
        }

        function pushSection(title, nodes) {
            if (!nodes || !nodes.length) {
                return;
            }
            sections.push({
                title: compactSectionTitle(title),
                heading: $.trim(title || '') || '其他',
                nodes: nodes
            });
        }

        if ($updateBlocks.length) {
            pushSection('版本更新', $.makeArray($updateBlocks));
        }

        var currentTitle = '';
        var currentNodes = [];
        $options.each(function () {
            var $item = $(this);
            var $title = $item.find('.enhancement-title:first');
            if ($title.length) {
                pushSection(currentTitle, currentNodes);
                currentTitle = $.trim($title.text()) || '未分类';
                currentNodes = [this];
            } else {
                if (!currentTitle) {
                    currentTitle = '未分类';
                }
                currentNodes.push(this);
            }
        });
        pushSection(currentTitle, currentNodes);

        if ($backupBlocks.length) {
            pushSection('设置备份', $.makeArray($backupBlocks));
        }

        if ($introBlocks.length || $miscBlocks.length) {
            pushSection('使用帮助', $.makeArray($introBlocks).concat($.makeArray($miscBlocks)));
        }

        if (!sections.length) {
            return;
        }

        function splitField(node) {
            var $option = $(node);
            if (!$option.is('ul.typecho-option') || $option.hasClass('enhancement-option-no-bullet')) {
                return;
            }

            var $li = $option.children('li').first();
            var $label = $li.children('label.typecho-label').first();
            if (!$label.length) {
                return;
            }

            $label.children('.enhancement-title').remove();
            $label.children('hr').remove();

            var $meta = $('<div class="enhancement-field-meta"></div>');
            var $control = $('<div class="enhancement-field-control"></div>');
            $meta.append($label).append($li.children('p.description'));
            $control.append($li.contents());
            $li.addClass('enhancement-field').append($meta).append($control);
        }

        var panels = [];
        $.each(sections, function (index, section) {
            var sectionId = 'enhancement-settings-section-' + index;
            var $navItem = $('<button type="button" class="enhancement-settings-nav-item" role="tab" aria-selected="false"></button>')
                .attr('data-section', sectionId)
                .attr('aria-controls', sectionId);
            $navItem.append($('<span class="enhancement-settings-nav-label"></span>').text(section.heading));
            $navItem.append($('<span class="enhancement-settings-nav-short"></span>').text(section.title));
            $nav.append($navItem);

            var $panel = $('<section class="enhancement-settings-panel" role="tabpanel"></section>')
                .attr('data-section', sectionId)
                .attr('id', sectionId);
            $panel.append($('<div class="enhancement-settings-panel-head"><h3></h3></div>').find('h3').text(section.heading).end());
            $.each(section.nodes, function (_, node) {
                var $node = $(node);
                $node.children('.enhancement-title').remove();
                if ($node.find('input, textarea, select').length && !$node.find('input:not([type="hidden"]), textarea, select').length) {
                    $configForm.append($node.hide());
                    return;
                }
                splitField(node);
                $panel.append(node);
            });
            $main.append($panel);
            panels.push({
                id: sectionId,
                nav: $navItem,
                panel: $panel
            });
        });

        var currentSectionId = panels[0].id;
        var showAll = false;
        var $searchInput = $search.find('input');

        function escapeHtml(value) {
            return $('<div></div>').text(value == null ? '' : String(value)).html();
        }

        function renderUpdateState(state, fallbackMessage) {
            state = state || {};
            var $box = $app.find('.enhancement-update-box').first();
            if (!$box.length) {
                return;
            }

            var remoteVersion = $.trim(state.remote_version || '');
            var error = $.trim(state.error || '');
            var hasUpdate = !!state.has_update;
            var html = '';

            if (error) {
                html = '<p class="enhancement-action-note" style="margin:8px 0 0;color:#b42318;">上次检查失败：' + escapeHtml(error) + '</p>';
            } else if (remoteVersion) {
                html = '<p class="enhancement-action-note" style="margin:8px 0 0;">远程版本：<strong>' + escapeHtml(remoteVersion) + '</strong></p>';
                html += '<p class="enhancement-action-note" style="margin:6px 0 0;color:' + (hasUpdate ? '#1a7f37' : '#64748b') + ';">'
                    + (hasUpdate ? '检测结果：发现新版本，可在线升级。' : '检测结果：当前已是最新版本。')
                    + '</p>';
            } else {
                html = '<p class="enhancement-action-note" style="margin:8px 0 0;">' + escapeHtml(fallbackMessage || '检查更新失败，请稍后重试。') + '</p>';
            }

            $box.find('.enhancement-update-status').html(html);
            $box.find('.enhancement-update-upgrade').toggleClass('is-hidden', !hasUpdate);
        }

        $app.on('click', '.enhancement-smtp-test', function (event) {
            event.preventDefault();

            var $button = $(this);
            var url = $button.attr('href') || '';
            if (!url || $button.data('loading')) {
                return;
            }

            var fieldNames = ['STMPHost', 'SMTPUserName', 'SMTPPassword', 'from', 'fromName', 'adminfrom', 'SMTPSecure', 'SMTPPort'];
            var selector = $.map(fieldNames, function (name) {
                return '[name="' + name + '"]';
            }).join(',');
            var originalText = $button.text();
            var $status = $button.closest('.enhancement-action-row').find('.enhancement-smtp-test-status');

            $button.data('loading', '1').text('发送中...');
            $status.removeClass('is-success is-error').text('正在连接 SMTP 服务器并发送测试邮件...');

            $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: $form.find(selector).serialize(),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).done(function (response) {
                var success = !!(response && response.success);
                $status
                    .toggleClass('is-success', success)
                    .toggleClass('is-error', !success)
                    .text(response && response.message ? response.message : (success ? '测试邮件发送成功。' : '测试邮件发送失败。'));
            }).fail(function (xhr) {
                var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
                $status
                    .removeClass('is-success')
                    .addClass('is-error')
                    .text(response && response.message ? response.message : '测试邮件发送失败，请检查 SMTP 设置和服务器网络。');
            }).always(function () {
                $button.data('loading', '').text(originalText);
            });
        });

        $app.on('click', '.enhancement-update-check', function (event) {
            event.preventDefault();

            var $button = $(this);
            var url = $button.attr('href') || '';
            if (!url || $button.data('loading')) {
                return;
            }

            var originalText = $button.text();
            $button.data('loading', '1').text('检查中...');
            $.ajax({
                url: url,
                type: 'GET',
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).done(function (response) {
                renderUpdateState(response && response.update_state ? response.update_state : {}, response && response.message ? response.message : '');
            }).fail(function (xhr) {
                var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
                if (response && response.update_state) {
                    renderUpdateState(response.update_state, response.message || '');
                } else {
                    renderUpdateState({ error: response && response.message ? response.message : '检查更新失败，请稍后重试。' });
                }
            }).always(function () {
                $button.data('loading', '').text(originalText);
            });
        });

        function updateLayout() {
            var keyword = $.trim(($searchInput.val() || '').toLowerCase());
            var isSearching = keyword !== '';
            var visibleCount = 0;

            $app.toggleClass('is-searching', isSearching);

            $.each(panels, function (_, item) {
                var hasMatch = false;
                item.panel.children('.typecho-option').each(function () {
                    var $option = $(this);
                    var matched = true;
                    if (isSearching) {
                        matched = ($option.text() || '').toLowerCase().indexOf(keyword) >= 0;
                    }
                    $option.toggle(matched);
                    if (matched) {
                        hasMatch = true;
                    }
                });

                item.nav.toggle(!isSearching || hasMatch);

                var shouldShow = isSearching ? hasMatch : (showAll || item.id === currentSectionId);
                item.panel.toggle(shouldShow);
                item.panel.prop('hidden', !shouldShow);
                item.panel.toggleClass('is-active', !isSearching && item.id === currentSectionId);
                item.nav.toggleClass('is-active', !isSearching && item.id === currentSectionId);
                item.nav.attr('aria-selected', (!isSearching && item.id === currentSectionId) ? 'true' : 'false');

                if (shouldShow) {
                    visibleCount++;
                }
            });

            $empty.toggleClass('is-visible', isSearching && visibleCount === 0);

            if (isSearching) {
                $toggleBtn.text('清空搜索');
            } else {
                $toggleBtn.text(showAll ? '收起分组' : '展开全部');
            }
        }

        $nav.on('click', '.enhancement-settings-nav-item', function () {
            var sectionId = $(this).attr('data-section') || '';
            if (!sectionId) {
                return;
            }

            if ($app.hasClass('is-searching')) {
                var $target = $main.find('.enhancement-settings-panel[data-section="' + sectionId + '"]');
                if ($target.length && $target.is(':visible') && $target.get(0).scrollIntoView) {
                    $target.get(0).scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                return;
            }

            currentSectionId = sectionId;
            updateLayout();
        });

        $toggleBtn.on('click', function () {
            if ($app.hasClass('is-searching')) {
                $searchInput.val('');
                updateLayout();
                return;
            }

            showAll = !showAll;
            updateLayout();
        });

        $searchInput.on('input', function () {
            updateLayout();
        });

            updateLayout();
        });

        return true;
    }

    if (boot()) {
        return;
    }

    var attempts = 0;
    var timer = window.setInterval(function () {
        attempts++;
        if (boot() || attempts > 200) {
            window.clearInterval(timer);
        }
    }, 50);
})();
</script>
HTML
        );
    }

    public static function renderLinksHelp()
    {
        echo '<div class="typecho-option" style="margin-top:12px;">
            <button type="button" class="btn enhancement-action-btn" id="enhancement-links-help-toggle" style="display:none;">帮助</button>
            <div id="enhancement-links-help" style="display:none; margin-top:10px;">
                <p>【管理】→【友情链接】进入审核页面。</p>
                <p>友链支持后台审核与前台提交。</p>
                <p>前台提交表单：</p>
                <p>前台可使用 <code>Enhancement_Plugin::publicForm()->render();</code> 输出提交表单。</p>
                <p>或自定义表单提交到 <code>/action/enhancement-submit</code>（需带安全 token）。</p>
                <p>文章内容可用标签 <code>&lt;links 0 sort 32&gt;SHOW_TEXT&lt;/links&gt;</code> 输出友链。</p>
                <p>模板可使用 <code>&lt;?php $this-&gt;enhancement(&quot;SHOW_TEXT&quot;, 0, null, 32); ?&gt;</code> 输出。</p>
                <p>仅审核通过（state=1）的友链会被输出。</p>
                <div style="margin-top:10px;">
                    <table>
                        <colgroup>
                            <col width="30%" />
                            <col width="70%" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>字段</th>
                                <th>对应数据</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="field">{url}</td>
                                <td>友链地址</td>
                            </tr>
                            <tr>
                                <td class="field">{title}<br />{description}</td>
                                <td>友链描述</td>
                            </tr>
                            <tr>
                                <td class="field">{name}</td>
                                <td>友链名称</td>
                            </tr>
                            <tr>
                                <td class="field">{image}</td>
                                <td>友链图片</td>
                            </tr>
                            <tr>
                                <td class="field">{size}</td>
                                <td>图片尺寸</td>
                            </tr>
                            <tr>
                                <td class="field">{sort}</td>
                                <td>友链分类</td>
                            </tr>
                            <tr>
                                <td class="field">{user}</td>
                                <td>自定义数据</td>
                            </tr>
                            <tr>
                                <td class="field">{lid}</td>
                                <td>链接的数据表ID</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:10px;">
                    <p>扩展功能：</p>
                    <p>评论同步：游客/登录用户评论时自动同步历史评论中的网址/昵称/邮箱。</p>
                    <p>标签助手：后台写文章时显示标签快捷选择列表。</p>
                    <p>Sitemap：访问 <code>/sitemap.xml</code>。</p>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var btn = document.getElementById("enhancement-links-help-toggle");
            var panel = document.getElementById("enhancement-links-help");
            if (!btn || !panel) return;
            btn.addEventListener("click", function () {
                panel.style.display = panel.style.display === "none" ? "block" : "none";
            });

            var inlineBtn = document.getElementById("enhancement-links-help-trigger-inline");
            if (inlineBtn) {
                inlineBtn.addEventListener("click", function () {
                    btn.click();
                    if (btn.scrollIntoView) {
                        btn.scrollIntoView({behavior: "smooth", block: "center"});
                    }
                });
            }
        })();
        </script>';
    }

    public static function renderBackupActions($backupUrl)
    {
        echo '<div class="typecho-option">'
            . '<h3 class="enhancement-title">设置备份</h3>'
            . '<div class="enhancement-backup-box">'
            . '<p style="margin:0;">备份本插件的设置内容,将直接保存到数据库。方便下次启用插件时快速恢复设置。</p>'
            . '<div class="enhancement-backup-actions">'
            . '<a class="btn enhancement-action-btn" href="' . htmlspecialchars($backupUrl, ENT_QUOTES, 'UTF-8') . '">' . _t('备份插件设置') . '</a>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    public static function renderBackupHistory(array $backupRows)
    {
        if (empty($backupRows)) {
            return;
        }

        echo '<div class="typecho-option">'
            . '<div class="enhancement-backup-box">'
            . '<p style="margin:0 0 8px;"><strong>' . _t('最近 5 条备份') . '</strong></p>'
            . '<ol class="enhancement-backup-list">';

        foreach ($backupRows as $row) {
            $backupName = isset($row['name']) ? trim((string)$row['name']) : '';
            if ($backupName === '') {
                continue;
            }

            $timeText = $backupName;
            if (preg_match('/(?:^enh:bak:|backup:)(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})-/', $backupName, $matches)) {
                $timeText = $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
            }

            $restoreByNameUrl = Helper::security()->getIndex('/action/enhancement-edit?do=restore-settings&backup_name=' . $backupName);
            $deleteByNameUrl = Helper::security()->getIndex('/action/enhancement-edit?do=delete-backup&backup_name=' . $backupName);

            echo '<li>'
                . '<code>' . htmlspecialchars($timeText, ENT_QUOTES, 'UTF-8') . '</code>'
                . '<span class="enhancement-backup-item-actions">'
                . '<a class="enhancement-backup-inline-btn" href="' . htmlspecialchars($restoreByNameUrl, ENT_QUOTES, 'UTF-8') . '" onclick="return window.confirm(\'确定要恢复这份备份吗？当前设置将被覆盖。\');">' . _t('恢复此份') . '</a>'
                . '<a class="enhancement-backup-inline-btn danger" href="' . htmlspecialchars($deleteByNameUrl, ENT_QUOTES, 'UTF-8') . '" onclick="return window.confirm(\'确定要删除这份备份吗？\');">' . _t('删除') . '</a>'
                . '</span>'
                . '</li>';
        }

        echo '</ol>'
            . '</div>'
            . '</div>';
    }

    public static function renderUpdateActions($currentVersion, $checkUrl, $upgradeUrl, array $updateState = array())
    {
        $currentVersion = trim((string)$currentVersion);
        if ($currentVersion === '') {
            $currentVersion = _t('未知');
        }

        $remoteVersion = isset($updateState['remote_version']) ? trim((string)$updateState['remote_version']) : '';
        $checkedAt = isset($updateState['checked_at']) ? intval($updateState['checked_at']) : 0;
        $checkError = isset($updateState['error']) ? trim((string)$updateState['error']) : '';
        $hasUpdate = $remoteVersion !== ''
            && $currentVersion !== _t('未知')
            && !empty($updateState['has_update'])
            && version_compare(preg_replace('/^[vV]\s*/', '', $remoteVersion), preg_replace('/^[vV]\s*/', '', $currentVersion), '>');
        if ($checkError !== '') {
            $updateHint = '<p class="enhancement-action-note" style="margin:8px 0 0;color:#b42318;">' . _t('上次检查失败：%s', htmlspecialchars($checkError, ENT_QUOTES, 'UTF-8')) . '</p>';
        } elseif ($checkedAt > 0 && $remoteVersion !== '') {
            $resultText = $hasUpdate
                ? _t('检测结果：发现新版本，可在线升级。')
                : _t('检测结果：当前已是最新版本。');
            $resultColor = $hasUpdate ? '#1a7f37' : '#64748b';
            $updateHint = '<p class="enhancement-action-note" style="margin:8px 0 0;">' . _t('远程版本：%s', '<strong>' . htmlspecialchars($remoteVersion, ENT_QUOTES, 'UTF-8') . '</strong>') . '</p>'
                . '<p class="enhancement-action-note" style="margin:6px 0 0;color:' . $resultColor . ';">' . $resultText . '</p>';
        } else {
            $updateHint = '<p class="enhancement-action-note" style="margin:8px 0 0;">' . _t('点击“检查更新”后，会在这里显示远程版本和检测结果。') . '</p>';
        }
        $upgradeButton = $hasUpdate
            ? '<a class="btn enhancement-action-btn primary enhancement-update-upgrade" href="' . htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8') . '" onclick="return window.confirm(\'确定要从远程仓库下载并覆盖当前 Enhancement 插件目录吗？建议先备份插件文件。\');">' . _t('在线升级') . '</a>'
            : '<a class="btn enhancement-action-btn primary enhancement-update-upgrade is-hidden" href="' . htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8') . '" onclick="return window.confirm(\'确定要从远程仓库下载并覆盖当前 Enhancement 插件目录吗？建议先备份插件文件。\');">' . _t('在线升级') . '</a>';

        echo '<div class="typecho-option">'
            . '<h3 class="enhancement-title">版本更新</h3>'
            . '<div class="enhancement-update-box">'
            . '<p style="margin:0;">' . _t('当前版本：%s', '<strong>' . htmlspecialchars($currentVersion, ENT_QUOTES, 'UTF-8') . '</strong>') . '</p>'
            . '<p class="enhancement-action-note" style="margin:8px 0 0;">' . _t('远程仓库：%s', '<code>github.com/jkjoy/Enhancement</code>') . '</p>'
            . '<div class="enhancement-update-status">' . $updateHint . '</div>'
            . '<div class="enhancement-backup-actions">'
            . '<a class="btn enhancement-action-btn enhancement-update-check" href="' . htmlspecialchars($checkUrl, ENT_QUOTES, 'UTF-8') . '">' . _t('检查更新') . '</a>'
            . $upgradeButton
            . '</div>'
            . '</div>'
            . '</div>';
    }
}
