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
                        <li><a href="<?php $options->adminUrl('extending.php?panel=Enhancement/manage-enhancement.php'); ?>"><?php _e('链接'); ?></a></li>
                        <li class="current"><a href="<?php $options->adminUrl('extending.php?panel=Enhancement/manage-moments.php'); ?>"><?php _e('瞬间'); ?></a></li>
                        <li><a href="<?php $options->adminUrl('options-plugin.php?config=Enhancement'); ?>"><?php _e('设置'); ?></a></li>
                    </ul>
                </div>

                <div class="col-mb-12 col-tb-8" role="main">
                    <?php
                        $prefix = $db->getPrefix();
                        $moments = $db->fetchAll($db->select()->from($prefix.'moments')->order($prefix.'moments.mid', Typecho_Db::SORT_DESC));
                    ?>
                    <form method="post" name="manage_moments" class="operate-form">
                    <div class="typecho-list-operate clearfix">
                        <div class="operate">
                            <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all" /></label>
                            <div class="btn-group btn-drop">
                                <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                                <ul class="dropdown-menu">
                                    <li><a lang="<?php _e('你确认要删除这些瞬间吗?'); ?>" href="<?php $security->index('/action/enhancement-moments-edit?do=delete'); ?>"><?php _e('删除'); ?></a></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup>
                                <col width="15"/>
                                <col width=""/>
                                <col width="20%"/>
                                <col width="20%"/>
                            </colgroup>
                            <thead>
                                <tr>
                                    <th> </th>
                                    <th><?php _e('内容'); ?></th>
                                    <th><?php _e('标签'); ?></th>
                                    <th><?php _e('时间'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($moments)): ?>
                                <?php foreach ($moments as $moment): ?>
                                <tr id="moment-<?php echo $moment['mid']; ?>">
                                    <td><input type="checkbox" value="<?php echo $moment['mid']; ?>" name="mid[]"/></td>
                                    <td>
                                        <a href="<?php echo $request->makeUriByRequest('mid=' . $moment['mid']); ?>" title="<?php _e('点击编辑'); ?>">
                                            <?php
                                                $plain = strip_tags($moment['content']);
                                                echo Typecho_Common::subStr($plain, 0, 60, '...');
                                            ?>
                                        </a>
                                    </td>
                                    <td><?php
                                        $tags = isset($moment['tags']) ? trim($moment['tags']) : '';
                                        if ($tags !== '') {
                                            $decoded = json_decode($tags, true);
                                            if (is_array($decoded)) {
                                                $tags = implode(' , ', $decoded);
                                            }
                                        }
                                        echo $tags;
                                    ?></td>
                                    <td><?php
                                        $created = isset($moment['created']) ? $moment['created'] : 0;
                                        if (is_numeric($created) && intval($created) > 0) {
                                            echo date('Y-m-d H:i', intval($created));
                                        }
                                    ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="4"><h6 class="typecho-list-table-title"><?php _e('没有任何瞬间'); ?></h6></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    </form>
                </div>
                <div class="col-mb-12 col-tb-4" role="form">
                    <?php Enhancement_Plugin::momentsForm()->render(); ?>
                </div>
        </div>
    </div>
</div>

<?php
include 'copyright.php';
include 'common-js.php';
?>

<script type="text/javascript">
(function () {
    $(document).ready(function () {
        var table = $('.typecho-list-table');

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

        <?php if (isset($request->mid)): ?>
        $('.typecho-mini-panel').effect('highlight', '#AACB36');
        <?php endif; ?>
    });
})();
</script>
<?php include 'footer.php'; ?>

<?php /** Enhancement */ ?>
