<?php

include 'manage-init.php';
$user->pass('administrator');

$friendPostsLoadError = false;
try {
    $friendPostsStatus = Enhancement_FriendPostsHelper::status();
} catch (Throwable $error) {
    $friendPostsLoadError = true;
    $friendPostsStatus = array('enabled' => false, 'updated' => 0, 'feeds' => array());
}
$friendPostsAction = $security->getIndex('/action/enhancement-edit?do=refresh-friend-posts');
$friendPostsJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$friendPostsEscape = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

include 'manage-page-start.php';
include 'manage-layout-start.php';
?>
<div class="col-mb-12">
    <?php
    $enhancementCurrentTab = 'friend-posts';
    $enhancementTabs = array(
        'links' => array('label' => _t('链接'), 'url' => 'extending.php?panel=Enhancement/manage-enhancement.php'),
        'friend-posts' => array('label' => _t('朋友文章'), 'url' => 'extending.php?panel=Enhancement/manage-friend-posts.php'),
        'settings' => array('label' => _t('设置'), 'url' => 'options-plugin.php?config=Enhancement')
    );
    include 'manage-tabs.php';
    ?>
</div>
<div class="col-mb-12" role="main" id="enhancement-friend-posts">
    <?php if ($friendPostsLoadError): ?><div class="notice error"><?php _e('朋友文章暂时无法读取，请检查友链表和 PHP 临时缓存目录的读写权限。'); ?></div><?php endif; ?>
    <div class="notice" style="margin-bottom:16px;line-height:1.8;">
        <?php _e('从已审核友链的 RSS / Atom 订阅获取最近文章。前台每次访问轮换更新一个到期站点；首次使用可在这里依次刷新全部站点。每站最多等待 8 秒，失败时保留旧文章。'); ?>
        <br />
        <span id="friend-posts-enabled"><?php echo $friendPostsLoadError ? _t('暂时无法获取订阅状态。') : (!empty($friendPostsStatus['enabled']) ? _t('功能已启用。') : _t('功能已关闭，请先在插件设置的“朋友文章”分组启用。')); ?></span>
    </div>
    <div class="typecho-list-operate clearfix">
        <button type="button" class="btn primary" id="friend-posts-refresh-all"<?php if (empty($friendPostsStatus['enabled']) || empty($friendPostsStatus['feeds'])): ?> disabled<?php endif; ?>><?php _e('依次刷新全部站点'); ?></button>
        <span id="friend-posts-feedback" role="status" aria-live="polite" style="margin-left:12px;"></span>
    </div>
    <div class="typecho-table-wrap">
        <table class="typecho-list-table">
            <thead><tr><th><?php _e('朋友 / 订阅地址'); ?></th><th><?php _e('缓存文章'); ?></th><th><?php _e('最近检查 / 最近成功'); ?></th><th><?php _e('状态'); ?></th><th><?php _e('操作'); ?></th></tr></thead>
            <tbody id="friend-posts-list">
                <?php foreach ($friendPostsStatus['feeds'] as $feed): ?>
                    <tr>
                        <td style="max-width:360px;overflow-wrap:anywhere;">
                            <strong><?php echo $friendPostsEscape($feed['name']); ?></strong><br />
                            <small><?php echo $friendPostsEscape($feed['feed_url'] !== '' ? $feed['feed_url'] : $feed['url']); ?></small>
                        </td>
                        <td><?php echo intval($feed['count']); ?></td>
                        <td><?php echo $feed['checked'] ? date('Y-m-d H:i', $feed['checked']) : _t('尚未检查'); ?><br /><small><?php echo $feed['updated'] ? date('Y-m-d H:i', $feed['updated']) : _t('尚未成功'); ?></small></td>
                        <td style="max-width:280px;overflow-wrap:anywhere;"><?php echo $friendPostsEscape($feed['error'] !== '' ? $feed['error'] : ($feed['due'] ? _t('等待更新') : _t('缓存有效'))); ?></td>
                        <td><button type="button" class="btn btn-s" data-refresh="<?php echo intval($feed['lid']); ?>"<?php if (empty($friendPostsStatus['enabled'])): ?> disabled<?php endif; ?>><?php _e('刷新'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($friendPostsStatus['feeds'])): ?>
                    <tr><td colspan="5"><?php _e('暂无可获取的友链。请先审核友链，并确认该站未被排除。'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'manage-layout-end.php'; ?>
<?php include 'manage-page-assets.php'; ?>
<script>
(function ($) {
    var state = <?php echo json_encode($friendPostsStatus, $friendPostsJsonFlags); ?>;
    var action = <?php echo json_encode($friendPostsAction, $friendPostsJsonFlags); ?>;
    var busy = false;
    var $list = $('#friend-posts-list');
    var $all = $('#friend-posts-refresh-all');
    var $feedback = $('#friend-posts-feedback');

    function dateLabel(value, fallback) {
        if (!value) return fallback;
        var date = new Date(value * 1000);
        return date.toLocaleString();
    }

    function controls() {
        $all.prop('disabled', busy || !state.enabled || !state.feeds.length);
        $list.find('[data-refresh]').prop('disabled', busy || !state.enabled);
    }

    function render(next) {
        if (!next || !Array.isArray(next.feeds)) return;
        state = next;
        $('#friend-posts-enabled').text(state.enabled ? '功能已启用。' : '功能已关闭，请先在插件设置的“朋友文章”分组启用。');
        $list.empty();
        state.feeds.forEach(function (feed) {
            var $row = $('<tr>');
            var $source = $('<td>').css({maxWidth: '360px', overflowWrap: 'anywhere'});
            $source.append($('<strong>').text(feed.name)).append('<br>').append($('<small>').text(feed.feed_url || feed.url));
            var $dates = $('<td>').text(dateLabel(feed.checked, '尚未检查'));
            $dates.append('<br>').append($('<small>').text(dateLabel(feed.updated, '尚未成功')));
            var label = feed.error || (feed.due ? '等待更新' : '缓存有效');
            var $status = $('<td>').css({maxWidth: '280px', overflowWrap: 'anywhere'}).text(label);
            if (feed.error) $status.css('color', '#b42318');
            var $button = $('<button type="button" class="btn btn-s">').attr('data-refresh', feed.lid).text('刷新');
            $row.append($source, $('<td>').text(feed.count), $dates, $status, $('<td>').append($button));
            $list.append($row);
        });
        if (!state.feeds.length) $list.append($('<tr>').append($('<td colspan="5">').text('暂无可获取的友链。请先审核友链，并确认该站未被排除。')));
        controls();
    }

    function refresh(lid, done) {
        var succeeded = false;
        $.ajax({url: action, method: 'POST', data: {lid: lid}, dataType: 'json', timeout: 30000})
            .done(function (data) {
                render(data);
                succeeded = !!(data && data.success);
                $feedback.text(data && data.message ? data.message : (succeeded ? '刷新成功。' : '刷新失败，请查看站点状态。'));
            })
            .fail(function (xhr) {
                var data = xhr.responseJSON;
                if (data) render(data);
                $feedback.text(data && data.message ? data.message : '请求失败，请检查网络或登录状态后重试。');
            })
            .always(function () { done(succeeded); });
    }

    $list.on('click', '[data-refresh]', function () {
        if (busy || !state.enabled) return;
        busy = true;
        controls();
        $feedback.text('正在刷新…');
        refresh(parseInt($(this).attr('data-refresh'), 10), function () {
            busy = false;
            controls();
        });
    });

    $all.on('click', function () {
        if (busy || !state.enabled || !state.feeds.length) return;
        var queue = state.feeds.map(function (feed) { return {lid: feed.lid, name: feed.name}; });
        var finished = 0;
        var succeeded = 0;
        busy = true;
        controls();
        function next() {
            if (finished >= queue.length || !state.enabled) {
                busy = false;
                controls();
                $feedback.text('刷新完成：成功 ' + succeeded + '，失败 ' + (finished - succeeded) + '，共处理 ' + finished + ' 个站点。');
                return;
            }
            var feed = queue[finished];
            $feedback.text('正在刷新 ' + (finished + 1) + ' / ' + queue.length + '：' + feed.name);
            refresh(feed.lid, function (success) {
                finished++;
                if (success) succeeded++;
                next();
            });
        }
        next();
    });
})(jQuery);
</script>
<?php include 'footer.php'; ?>
