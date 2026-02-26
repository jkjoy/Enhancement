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

$settings = null;
try {
    $settings = $options->plugin('Enhancement');
} catch (Exception $e) {
    $settings = (object) array();
}

$summaryField = isset($settings->ai_summary_field) ? trim((string)$settings->ai_summary_field) : 'summary';
if ($summaryField === '' || !preg_match('/^[_a-z][_a-z0-9]*$/i', $summaryField)) {
    $summaryField = 'summary';
}

$summaryEnabled = isset($settings->enable_ai_summary) && $settings->enable_ai_summary == '1';
$updateMode = isset($settings->ai_summary_update_mode) ? trim((string)$settings->ai_summary_update_mode) : 'empty';

$statusOptions = array(
    '' => _t('全部状态'),
    'publish' => _t('公开'),
    'private' => _t('私密'),
    'waiting' => _t('待审核'),
    'hidden' => _t('隐藏'),
);

$statusFilter = trim((string)$request->get('status'));
if (!array_key_exists($statusFilter, $statusOptions)) {
    $statusFilter = '';
}

$pageSize = 30;
$page = intval($request->get('page'));
if ($page <= 0) {
    $page = 1;
}

$countSql = $db->select(array('COUNT(cid)' => 'num'))
    ->from('table.contents')
    ->where('type = ?', 'post');
if ($statusFilter !== '') {
    $countSql = $countSql->where('status = ?', $statusFilter);
}

$countRow = $db->fetchRow($countSql);
$totalPosts = isset($countRow['num']) ? intval($countRow['num']) : 0;
$totalPages = max(1, (int)ceil($totalPosts / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $pageSize;

$listSql = $db->select('cid', 'title', 'status', 'created')
    ->from('table.contents')
    ->where('type = ?', 'post');
if ($statusFilter !== '') {
    $listSql = $listSql->where('status = ?', $statusFilter);
}
$posts = $db->fetchAll(
    $listSql->order('created', Typecho_Db::SORT_DESC)
        ->limit($pageSize)
        ->offset($offset)
);

$summaryMap = array();
if (is_array($posts) && !empty($posts)) {
    $postIds = array();
    foreach ($posts as $post) {
        $cid = isset($post['cid']) ? intval($post['cid']) : 0;
        if ($cid > 0) {
            $postIds[] = $cid;
        }
    }

    if (!empty($postIds)) {
        $summaryRows = $db->fetchAll(
            $db->select('cid', 'str_value')
                ->from('table.fields')
                ->where('name = ?', $summaryField)
                ->where('cid IN ?', $postIds)
        );

        if (is_array($summaryRows)) {
            foreach ($summaryRows as $summaryRow) {
                $cid = isset($summaryRow['cid']) ? intval($summaryRow['cid']) : 0;
                if ($cid > 0) {
                    $summaryMap[$cid] = trim((string)(isset($summaryRow['str_value']) ? $summaryRow['str_value'] : ''));
                }
            }
        }
    }
}

$buildPanelUrl = function ($targetPage = null, $targetStatus = null) use ($options, $statusFilter, $statusOptions) {
    $query = array('panel' => 'Enhancement/manage-ai-summary.php');

    $status = $targetStatus;
    if ($status === null) {
        $status = $statusFilter;
    }
    $status = trim((string)$status);
    if ($status !== '' && isset($statusOptions[$status])) {
        $query['status'] = $status;
    }

    if ($targetPage !== null) {
        $targetPage = intval($targetPage);
        if ($targetPage > 1) {
            $query['page'] = $targetPage;
        }
    }

    return Typecho_Common::url('extending.php?' . http_build_query($query), $options->adminUrl);
};

$batchActionUrl = '/action/enhancement-edit?do=ai-summary-batch';
if ($statusFilter !== '') {
    $batchActionUrl .= '&status=' . rawurlencode($statusFilter);
}
if ($page > 1) {
    $batchActionUrl .= '&page=' . $page;
}
$batchForceActionUrl = $batchActionUrl . '&force=1';

include 'header.php';
include 'menu.php';
?>

<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="row typecho-page-main manage-metas">
            <div class="col-mb-12">
                <ul class="typecho-option-tabs clearfix">
                    <li class="current"><a href="<?php $options->adminUrl('extending.php?panel=Enhancement/manage-ai-summary.php'); ?>"><?php _e('摘要'); ?></a></li>
                    <li><a href="<?php $options->adminUrl('options-plugin.php?config=Enhancement'); ?>"><?php _e('设置'); ?></a></li>
                </ul>
            </div>

            <div class="col-mb-12" role="main">
                <div class="notice" style="margin-bottom:12px;line-height:1.8;">
                    <?php
                    if ($summaryEnabled) {
                        echo _t(
                            '当前摘要字段：<code>%s</code>；更新策略：%s。共 %d 篇文章，当前第 %d / %d 页。',
                            htmlspecialchars($summaryField, ENT_QUOTES, 'UTF-8'),
                            $updateMode === 'always' ? _t('每次发布覆盖') : _t('仅字段为空时生成'),
                            $totalPosts,
                            $page,
                            $totalPages
                        );
                    } else {
                        echo _t('AI 摘要功能当前未启用。请先到插件设置页开启后再批量生成。');
                    }
                    ?>
                </div>

                <form method="get" action="<?php $options->adminUrl('extending.php'); ?>" class="typecho-list-operate clearfix">
                    <div class="operate">
                        <a href="<?php echo htmlspecialchars($buildPanelUrl(), ENT_QUOTES, 'UTF-8'); ?>"><?php _e('&laquo; 重置'); ?></a>
                    </div>
                    <div class="search" role="search">
                        <input type="hidden" name="panel" value="Enhancement/manage-ai-summary.php" />
                        <select name="status">
                            <?php foreach ($statusOptions as $statusValue => $statusLabel): ?>
                                <option value="<?php echo htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8'); ?>"<?php if ($statusFilter === $statusValue): ?> selected="selected"<?php endif; ?>>
                                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-s"><?php _e('筛选'); ?></button>
                    </div>
                </form>

                <form method="post" name="manage_ai_summary" class="operate-form">
                    <div class="typecho-list-operate clearfix">
                        <div class="operate">
                            <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all" /></label>
                            <div class="btn-group btn-drop">
                                <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                                <ul class="dropdown-menu">
                                    <li><a lang="<?php _e('你确认要为这些文章生成摘要吗?'); ?>" href="<?php $security->index($batchActionUrl); ?>"><?php _e('批量生成摘要'); ?></a></li>
                                    <li><a lang="<?php _e('你确认要强制覆盖这些文章的摘要吗?'); ?>" href="<?php $security->index($batchForceActionUrl); ?>"><?php _e('强制重生成'); ?></a></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup>
                                <col width="15"/>
                                <col width="35%"/>
                                <col width="10%"/>
                                <col width="16%"/>
                                <col width=""/>
                            </colgroup>
                            <thead>
                                <tr>
                                    <th> </th>
                                    <th><?php _e('文章标题'); ?></th>
                                    <th><?php _e('状态'); ?></th>
                                    <th><?php _e('发布时间'); ?></th>
                                    <th><?php _e('摘要预览'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($posts)): ?>
                                    <?php foreach ($posts as $post): ?>
                                        <?php
                                        $cid = intval($post['cid']);
                                        $title = isset($post['title']) && trim((string)$post['title']) !== '' ? (string)$post['title'] : _t('（无标题）');
                                        $status = isset($post['status']) ? (string)$post['status'] : '';
                                        $statusText = _t('未知');
                                        if ($status === 'publish') {
                                            $statusText = _t('公开');
                                        } elseif ($status === 'private') {
                                            $statusText = _t('私密');
                                        } elseif ($status === 'waiting') {
                                            $statusText = _t('待审核');
                                        } elseif ($status === 'hidden') {
                                            $statusText = _t('隐藏');
                                        }
                                        $summaryText = isset($summaryMap[$cid]) ? trim((string)$summaryMap[$cid]) : '';
                                        $summaryPreview = $summaryText === '' ? _t('未生成') : Typecho_Common::subStr($summaryText, 0, 90, '...');
                                        $summaryColor = $summaryText === '' ? '#999' : '#333';
                                        ?>
                                        <tr id="post-<?php echo $cid; ?>">
                                            <td><input type="checkbox" value="<?php echo $cid; ?>" name="cid[]"/></td>
                                            <td>
                                                <a href="<?php $options->adminUrl('write-post.php?cid=' . $cid); ?>" target="_blank" title="<?php _e('编辑文章'); ?>">
                                                    <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo isset($post['created']) && intval($post['created']) > 0 ? date('Y-m-d H:i', intval($post['created'])) : ''; ?></td>
                                            <td style="color:<?php echo $summaryColor; ?>;"><?php echo htmlspecialchars($summaryPreview, ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5"><h6 class="typecho-list-table-title"><?php _e('没有可处理的文章'); ?></h6></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <?php if ($totalPages > 1): ?>
                    <div class="typecho-list-operate clearfix">
                        <ul class="typecho-pager">
                            <?php if ($page > 1): ?>
                                <li><a href="<?php echo htmlspecialchars($buildPanelUrl($page - 1), ENT_QUOTES, 'UTF-8'); ?>">&laquo;</a></li>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <?php if ($i == $page): ?>
                                    <li class="current"><a href="javascript:void(0);"><?php echo $i; ?></a></li>
                                <?php else: ?>
                                    <li><a href="<?php echo htmlspecialchars($buildPanelUrl($i), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a></li>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li><a href="<?php echo htmlspecialchars($buildPanelUrl($page + 1), ENT_QUOTES, 'UTF-8'); ?>">&raquo;</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>
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
    });
})();
</script>
<?php include 'footer.php'; ?>

<?php /** Enhancement */ ?>
