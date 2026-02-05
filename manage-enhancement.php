<?php

/** 初始化组件 */
Typecho_Widget::widget('Widget_Init');

/** 注册一个初始化插件 */
Typecho_Plugin::factory('admin/common.php')->begin();

Typecho_Widget::widget('Widget_Options')->to($options);
Typecho_Widget::widget('Widget_User')->to($user);
Typecho_Widget::widget('Widget_Security')->to($security);
Typecho_Widget::widget('Widget_Menu')->to($menu);

/** 初始化上下文 */
$request = $options->request;
$response = $options->response;
include 'header.php';
include 'menu.php';
?>


<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="row typecho-page-main manage-metas">
                <div class="col-mb-12">
                    <ul class="typecho-option-tabs clearfix">
                        <li class="current"><a href="<?php $options->adminUrl('extending.php?panel=Enhancement/manage-enhancement.php'); ?>"><?php _e('链接'); ?></a></li>
                        <li><a href="<?php $options->adminUrl('extending.php?panel=Enhancement/manage-moments.php'); ?>"><?php _e('瞬间'); ?></a></li>
                        <li><a href="<?php $options->adminUrl('options-plugin.php?config=Enhancement'); ?>"><?php _e('设置'); ?></a></li>
                    </ul>
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
                                <?php if (!empty($items)): $alt = 0;?>
                                <?php foreach ($items as $item): ?>
                                <tr id="enhancement-<?php echo $item['lid']; ?>">
                                    <td><input type="checkbox" value="<?php echo $item['lid']; ?>" name="lid[]"/></td>
                                    <td><a href="<?php echo $request->makeUriByRequest('lid=' . $item['lid']); ?>" title="<?php _e('点击编辑'); ?>"><?php echo $item['name']; ?></a>
                                    <td><?php echo $item['url']; ?></td>
                                    <td><?php echo $item['sort']; ?></td>
                                    <td><?php
                                        if ($item['image']) {
                                            echo '<a href="'.$item['image'].'" title="'._t('点击放大').'" target="_blank"><img class="avatar" src="'.$item['image'].'" alt="'.$item['name'].'" width="32" height="32"/></a>';
                                        } else {
                                            $options = Typecho_Widget::widget('Widget_Options');
                                            $nopic_url = Typecho_Common::url('usr/plugins/Enhancement/nopic.png', $options->siteUrl);
                                            echo '<img class="avatar" src="'.$nopic_url.'" alt="NOPIC" width="32" height="32"/>';
                                        }
                                    ?></td>
                                    <td><?php
                                        if ($item['state'] == 1) {
                                            echo '已通过';
                                        } else {
                                            echo '待审核';
                                        }
                                    ?></td>
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
        </div>
    </div>
</div>

<?php
include 'copyright.php';
include 'common-js.php';
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
<script type="text/javascript">
(function () {
    $(document).ready(function () {
        var table = $('.typecho-list-table').tableDnD({
            onDrop : function () {
                var ids = [];

                $('input[type=checkbox]', table).each(function () {
                    ids.push($(this).val());
                });

                $.post('<?php $security->index('/action/enhancement-edit?do=sort'); ?>',
                    $.param({lid : ids}));

                $('tr', table).each(function (i) {
                    if (i % 2) {
                        $(this).addClass('even');
                    } else {
                        $(this).removeClass('even');
                    }
                });
            }
        });

        table.tableSelectable({
            checkEl     :   'input[type=checkbox]',
            rowEl       :   'tr',
            selectAllEl :   '.typecho-table-select-all',
            actionEl    :   '.dropdown-menu a'
        });

        $('.btn-drop').dropdownMenu({
            btnEl       :   '.dropdown-toggle',
            menuEl      :   '.dropdown-menu'
        });

        $('.dropdown-menu button.merge').click(function () {
            var btn = $(this);
            btn.parents('form').attr('action', btn.attr('rel')).submit();
        });

        <?php if (isset($request->lid)): ?>
        $('.typecho-mini-panel').effect('highlight', '#AACB36');
        <?php endif; ?>
    });
})();
</script>
<?php include 'footer.php'; ?>

<?php /** Enhancement */ ?>
