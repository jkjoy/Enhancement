<?php

Typecho_Widget::widget('Widget_Init');
Typecho_Plugin::factory('admin/common.php')->begin();

Typecho_Widget::widget('Widget_Options')->to($options);
Typecho_Widget::widget('Widget_User')->to($user);
Typecho_Widget::widget('Widget_Security')->to($security);
Typecho_Widget::widget('Widget_Menu')->to($menu);

$request = $options->request;
$response = $options->response;

include 'header.php';
include 'menu.php';
?>

<style>
.enh-upload-card {
    margin-top: 20px;
    padding: 20px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: linear-gradient(180deg, #fbfcff 0%, #f7f9fc 100%);
}
.enh-upload-title {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
}
.enh-upload-subtitle {
    margin: 0 0 14px;
    color: #6b7280;
    font-size: 12px;
}
.enh-upload-drop {
    border: 1px dashed #9ca3af;
    border-radius: 8px;
    background: #fff;
    padding: 14px;
    margin-bottom: 10px;
    transition: all .15s ease;
    cursor: pointer;
}
.enh-upload-drop.is-hover {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .08);
}
.enh-upload-drop strong {
    display: block;
    margin-bottom: 4px;
    color: #111827;
}
.enh-upload-drop span {
    color: #6b7280;
    font-size: 12px;
}
.enh-upload-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.enh-upload-status {
    font-size: 12px;
}
.enh-upload-status.success { color: #059669; }
.enh-upload-status.error { color: #dc2626; }
.enh-upload-status.loading { color: #2563eb; }
</style>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="row typecho-page-main manage-metas">
            <div class="col-mb-12 col-tb-12" role="main">
                <div class="enh-upload-card">
                    <h3 class="enh-upload-title"><?php _e('上传插件 / 主题'); ?></h3>
                    <p class="enh-upload-subtitle"><?php _e('支持 ZIP 格式压缩包，自动识别为插件或主题并安装到对应目录。需保证打开 PHP 的 ZIP 扩展, zip压缩包内的文件名与插件/主题名保持一致,否则容易出现错误。'); ?></p>
                    <form id="upload-form" method="post" enctype="multipart/form-data">
                        <div class="enh-upload-drop" id="upload-drop">
                            <strong><?php _e('点击选择 ZIP 文件，或拖拽到此区域'); ?></strong>
                            <span id="upload-file-hint"><?php _e('未选择文件'); ?></span>
                        </div>
                        <input type="file" name="pluginzip" id="pluginzip" accept=".zip,application/zip" required style="display:none;" />
                        <div class="enh-upload-actions">
                            <button type="submit" class="btn primary" id="upload-btn"><?php _e('上传并安装'); ?></button>
                            <span id="upload-status" class="enh-upload-status"></span>
                        </div>
                    </form>
                </div>

                <?php Typecho_Widget::widget('Widget_Plugins_List@unactivated', 'activated=0')->to($deactivatedPlugins); ?>
                <h4 class="typecho-list-table-title" style="margin-top:20px;"><?php _e('可删除的插件'); ?></h4>
                <div class="typecho-table-wrap">
                    <table class="typecho-list-table">
                        <colgroup>
                            <col width="25%"/>
                            <col width="45%"/>
                            <col width="8%"/>
                            <col width="10%"/>
                            <col width=""/>
                        </colgroup>
                        <thead>
                            <tr>
                                <th><?php _e('名称'); ?></th>
                                <th><?php _e('描述'); ?></th>
                                <th><?php _e('版本'); ?></th>
                                <th><?php _e('作者'); ?></th>
                                <th><?php _e('操作'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($deactivatedPlugins->have()): ?>
                                <?php while ($deactivatedPlugins->next()): ?>
                                <tr id="plugin-<?php $deactivatedPlugins->name(); ?>">
                                    <td><?php $deactivatedPlugins->title(); ?></td>
                                    <td><?php $deactivatedPlugins->description(); ?></td>
                                    <td><?php $deactivatedPlugins->version(); ?></td>
                                    <td><?php echo empty($deactivatedPlugins->homepage) ? $deactivatedPlugins->author : '<a href="' . $deactivatedPlugins->homepage . '">' . $deactivatedPlugins->author . '</a>'; ?></td>
                                    <td>
                                        <a lang="<?php _e('你确认要删除 %s 插件吗?', $deactivatedPlugins->name); ?>" href="<?php $security->index('/action/enhancement-edit?do=delete-plugin-package&name=' . $deactivatedPlugins->name); ?>" class="operate-delete"><?php _e('删除'); ?></a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5"><h6 class="typecho-list-table-title"><?php _e('没有可以删除的插件'); ?></h6></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="col-mb-12 col-tb-12" role="main">
                <h4 class="typecho-list-table-title"><?php _e('可删除的主题'); ?></h4>
                <div class="typecho-table-wrap">
                    <table class="typecho-list-table">
                        <colgroup>
                            <col width="25%"/>
                            <col width="45%"/>
                            <col width="8%"/>
                            <col width="10%"/>
                            <col width=""/>
                        </colgroup>
                        <thead>
                            <tr>
                                <th><?php _e('名称'); ?></th>
                                <th><?php _e('描述'); ?></th>
                                <th><?php _e('版本'); ?></th>
                                <th><?php _e('作者'); ?></th>
                                <th><?php _e('操作'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php Typecho_Widget::widget('Widget_Themes_List')->to($themes); ?>
                            <?php if ($themes->length >= 2): ?>
                                <?php while ($themes->next()): ?>
                                    <?php if ($themes->activated) continue; ?>
                                    <tr id="theme-<?php $themes->name(); ?>">
                                        <td><?php $themes->name(); ?></td>
                                        <td><?php echo nl2br($themes->description); ?></td>
                                        <td><?php $themes->version(); ?></td>
                                        <td><?php echo empty($themes->homepage) ? $themes->author : '<a href="' . $themes->homepage . '">' . $themes->author . '</a>'; ?></td>
                                        <td>
                                            <a lang="<?php _e('你确认要删除 %s 主题吗?', $themes->name); ?>" class="operate-delete" href="<?php $security->index('/action/enhancement-edit?do=delete-theme-package&name=' . $themes->name); ?>"><?php _e('删除'); ?></a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5"><h6 class="typecho-list-table-title"><?php _e('没有可以删除的主题'); ?></h6></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include 'copyright.php';
include 'common-js.php';
?>

<script>
(function() {
    var form = document.getElementById('upload-form');
    var btn = document.getElementById('upload-btn');
    var status = document.getElementById('upload-status');
    var fileInput = document.getElementById('pluginzip');
    var drop = document.getElementById('upload-drop');
    var fileHint = document.getElementById('upload-file-hint');

    function setStatus(text, type) {
        status.textContent = text || '';
        status.className = 'enh-upload-status' + (type ? (' ' + type) : '');
    }

    function updateFileHint(file) {
        if (!file) {
            fileHint.textContent = '未选择文件';
            return;
        }
        var sizeKb = Math.max(1, Math.round(file.size / 1024));
        fileHint.textContent = file.name + '（' + sizeKb + ' KB）';
    }

    if (drop && fileInput) {
        drop.addEventListener('click', function() {
            fileInput.click();
        });

        fileInput.addEventListener('change', function() {
            updateFileHint(fileInput.files && fileInput.files[0] ? fileInput.files[0] : null);
        });

        ['dragenter', 'dragover'].forEach(function(eventName) {
            drop.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                drop.classList.add('is-hover');
            });
        });
        ['dragleave', 'drop'].forEach(function(eventName) {
            drop.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                drop.classList.remove('is-hover');
            });
        });
        drop.addEventListener('drop', function(e) {
            if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files[0]) {
                return;
            }
            fileInput.files = e.dataTransfer.files;
            updateFileHint(e.dataTransfer.files[0]);
        });
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            if (!fileInput.files || !fileInput.files[0]) {
                alert('请选择要上传的文件');
                return;
            }

            var file = fileInput.files[0];
            if (!file.name.toLowerCase().endsWith('.zip')) {
                alert('仅支持 ZIP 格式文件');
                return;
            }

            var formData = new FormData();
            formData.append('pluginzip', file);

            btn.disabled = true;
            setStatus('上传中...', 'loading');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php $security->index('/action/enhancement-edit?do=upload-package'); ?>', true);

            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            setStatus(response.message || '上传成功', 'success');
                            setTimeout(function() {
                                window.location.reload();
                            }, 1200);
                        } else {
                            setStatus(response.message || '上传失败', 'error');
                            btn.disabled = false;
                        }
                    } catch (e) {
                        setStatus('上传失败：服务端响应异常', 'error');
                        btn.disabled = false;
                    }
                } else {
                    setStatus('上传失败：HTTP ' + xhr.status, 'error');
                    btn.disabled = false;
                }
            };

            xhr.onerror = function() {
                setStatus('上传失败：网络错误', 'error');
                btn.disabled = false;
            };

            xhr.send(formData);
        });
    }

    var deleteLinks = document.querySelectorAll('.operate-delete');
    for (var i = 0; i < deleteLinks.length; i++) {
        deleteLinks[i].addEventListener('click', function(e) {
            if (!confirm(this.getAttribute('lang'))) {
                e.preventDefault();
                return false;
            }
        });
    }
})();
</script>

<?php include 'footer.php'; ?>

<?php /** Enhancement */ ?>
