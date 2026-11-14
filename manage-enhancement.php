<?php

include 'manage-init.php';
include 'manage-page-start.php';
?>


<?php include 'manage-layout-start.php'; ?>
                <div class="col-mb-12">
                    <?php
                    $enhancementCurrentTab = 'links';
                    $enhancementTabPreset = 'core';
                    include 'manage-tabs.php';
                    ?>
                </div>

                <div class="col-mb-12 col-tb-8" role="main">
                    <?php
                        $prefix = $db->getPrefix();
                        $items = $db->fetchAll($db->select()->from($prefix.'links')->order($prefix.'links.order', Typecho_Db::SORT_ASC));
                    ?>
                    <form method="post" name="manage_categories" class="operate-form">
                    <div class="typecho-list-operate clearfix">
                        <div class="operate">
                            <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all" /></label>
                            <div class="btn-group btn-drop">
                                <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                                <ul class="dropdown-menu">
                                    <li><a lang="<?php _e('你确认要删除这些记录吗?'); ?>" href="<?php $security->index('/action/enhancement-edit?do=delete'); ?>"><?php _e('删除'); ?></a></li>
                                    <li><a lang="<?php _e('你确认要通过这些申请吗?'); ?>" href="<?php $security->index('/action/enhancement-edit?do=approve'); ?>"><?php _e('通过'); ?></a></li>
                                    <li><a lang="<?php _e('你确认要驳回这些申请吗?'); ?>" href="<?php $security->index('/action/enhancement-edit?do=reject'); ?>"><?php _e('驳回'); ?></a></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup>
                                <col width="15"/>
                                <col width="25%"/>
                                <col width=""/>
                                <col width="15%"/>
                                <col width="10%"/>
                                <col width="12%"/>
                            </colgroup>
                            <thead>
                                <tr>
                                    <th> </th>
                                    <th><?php _e('友链名称'); ?></th>
                                    <th><?php _e('友链地址'); ?></th>
                                    <th><?php _e('分类'); ?></th>
                                    <th><?php _e('图片'); ?></th>
                                    <th><?php _e('审核'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($items)): ?>
                                <?php foreach ($items as $item): ?>
                                <tr id="enhancement-<?php echo (int)$item['lid']; ?>">
                                    <td><input type="checkbox" value="<?php echo (int)$item['lid']; ?>" name="lid[]"/></td>
                                    <td><a href="<?php echo htmlspecialchars($request->makeUriByRequest('lid=' . (int)$item['lid']), ENT_QUOTES, 'UTF-8'); ?>" title="<?php _e('点击编辑'); ?>"><?php echo htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                                    <td><?php echo htmlspecialchars((string)$item['url'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$item['sort'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php
                                        if ($item['image']) {
                                            $safeImage = htmlspecialchars((string)$item['image'], ENT_QUOTES, 'UTF-8');
                                            $safeName = htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8');
                                            echo '<a href="' . $safeImage . '" title="' . _t('点击放大') . '" target="_blank"><img class="avatar" src="' . $safeImage . '" alt="' . $safeName . '" width="32" height="32"/></a>';
                                        } else {
                                            $nopic_url = Enhancement_Plugin::appendVersionToAssetUrl(
                                                Typecho_Common::url('usr/plugins/Enhancement/nopic.png', $options->siteUrl)
                                            );
                                            echo '<img class="avatar" src="'.$nopic_url.'" alt="NOPIC" width="32" height="32"/>';
                                        }
                                    ?></td>
                                    <td><?php echo $item['state'] == 1 ? '已通过' : '待审核'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="6"><h6 class="typecho-list-table-title"><?php _e('没有任何记录'); ?></h6></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    </form>
                </div>
                <div class="col-mb-12 col-tb-4" role="form">
                    <?php Enhancement_Plugin::form()->render(); ?>
                </div>
<?php include 'manage-layout-end.php'; ?>

<?php
include 'manage-page-assets.php';
?>

<script>
$('input[name="email"]').blur(function() {
    var _email = $(this).val();
    var _image = $('input[name="image"]').val();
    if (_email != '' && _image == '') {
        var k = "<?php $security->index('/action/enhancement-edit'); ?>";
        $.post(k, {"do": "email-logo", "type": "json", "email": $(this).val()}, function (result) {
            var k = jQuery.parseJSON(result).url;
            $('input[name="image"]').val(k);
        });
    }
    return false;
});
</script>
<?php
$enhancementListSortUrl = Helper::security()->getIndex('/action/enhancement-edit?do=sort');
$enhancementListSortField = 'lid';
$enhancementListHighlightPanel = isset($request->lid);
include 'manage-list-script.php';
?>
<?php include 'footer.php'; ?>

<?php /** Enhancement */ ?>
