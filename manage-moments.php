<?php

include 'manage-init.php';
include 'manage-page-start.php';

$momentsPanelPath = 'extending.php?panel=' . urlencode('Enhancement/manage-moments.php');
$momentsStatusFilter = strtolower(trim((string)$request->get('status', '')));
if (!in_array($momentsStatusFilter, array('public', 'private'), true)) {
    $momentsStatusFilter = '';
}
$momentsKeywords = trim((string)$request->get('keywords', ''));
$momentsPage = max(1, intval($request->get('page', 1)));
$momentsPageSize = 20;
$momentsEditingMid = isset($request->mid) ? intval($request->mid) : 0;

$momentsUrl = function (array $params = array()) use ($options, $momentsPanelPath, $momentsStatusFilter, $momentsKeywords) {
    $query = array_merge(array(
        'status' => $momentsStatusFilter,
        'keywords' => $momentsKeywords
    ), $params);
    $query = array_filter($query, function ($value) {
        return $value !== '' && $value !== null;
    });

    return $options->adminUrl($momentsPanelPath . (empty($query) ? '' : '&' . http_build_query($query)), true);
};

$momentsApplyFilters = function ($select, $withKeywords = true) use ($momentsStatusFilter, $momentsKeywords) {
    if ($momentsStatusFilter === 'private') {
        $select->where('status = ?', 'private');
    } else if ($momentsStatusFilter === 'public') {
        $select->where('(status IS NULL OR status <> ?)', 'private');
    }
    if ($withKeywords && $momentsKeywords !== '') {
        $like = '%' . $momentsKeywords . '%';
        $select->where('(content LIKE ? OR tags LIKE ?)', $like, $like);
    }

    return $select;
};

$momentsParseTags = function ($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return array();
    }
    $decoded = json_decode($raw, true);
    $items = is_array($decoded) ? $decoded : explode(',', $raw);
    $tags = array();
    foreach ($items as $tag) {
        $tag = trim((string)$tag);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    return $tags;
};

$momentsParseMedia = function ($raw) {
    $decoded = json_decode(trim((string)$raw), true);
    $media = array();
    if (!is_array($decoded)) {
        return $media;
    }
    foreach ($decoded as $item) {
        $url = is_array($item) && isset($item['url']) ? trim((string)$item['url']) : '';
        if ($url === '' || !preg_match('#^(https?:)?//|^/(?!/)#i', $url)) {
            continue;
        }
        $type = isset($item['type']) && strtoupper((string)$item['type']) === 'VIDEO' ? 'VIDEO' : 'PHOTO';
        $media[] = array('type' => $type, 'url' => $url);
    }

    return $media;
};

$momentsEscape = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

Enhancement_Plugin::ensureMomentsTable();
$prefix = $db->getPrefix();
$moments = array();
$momentsError = '';
$momentsTotal = 0;
$momentsCounts = array('all' => 0, 'public' => 0, 'private' => 0);
try {
    $momentsCountSql = function () use ($db, $prefix) {
        return $db->select(array('COUNT(mid)' => 'num'))->from($prefix . 'moments');
    };
    $momentsCounts['all'] = intval($db->fetchObject($momentsCountSql())->num);
    $momentsCounts['private'] = intval($db->fetchObject($momentsCountSql()->where('status = ?', 'private'))->num);
    $momentsCounts['public'] = max(0, $momentsCounts['all'] - $momentsCounts['private']);

    $momentsTotal = intval($db->fetchObject($momentsApplyFilters($momentsCountSql()))->num);
    $momentsPage = min($momentsPage, max(1, (int)ceil($momentsTotal / $momentsPageSize)));
    $moments = $db->fetchAll($momentsApplyFilters(
        $db->select()->from($prefix . 'moments')
            ->order($prefix . 'moments.mid', Typecho_Db::SORT_DESC)
            ->page($momentsPage, $momentsPageSize)
    ));
} catch (Exception $e) {
    $momentsError = $e->getMessage();
}

$momentsSourceLabels = array('mobile' => _t('手机端'), 'api' => 'API', 'web' => _t('Web端'), 'qq' => 'QQ', 'weixin' => _t('微信'));
$momentsFiltered = $momentsStatusFilter !== '' || $momentsKeywords !== '';
?>
<style>
.enh-moments-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin:16px 0 12px}
.enh-moments-filters{display:flex;flex-wrap:wrap;gap:6px;margin:0;padding:0;list-style:none}
.enh-moments-filters a{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border:1px solid #D9D9D6;border-radius:999px;background:#fff;color:#555;font-size:13px;line-height:20px;text-decoration:none}
.enh-moments-filters a:hover{border-color:#467B96;color:#467B96}
.enh-moments-filters .current a{border-color:#467B96;background:#467B96;color:#fff}
.enh-moments-filters .num{font-size:12px;opacity:.75}
.enh-moments-search{display:flex;gap:6px;align-items:center;margin:0}
.enh-moments-search .text-s{width:200px}
.enh-moments-search .clear{font-size:13px;white-space:nowrap}
.enh-moments-list-wrap{background:#fff;border:1px solid #E9E9E6;border-radius:6px;overflow:hidden}
.enh-moments-list-wrap .typecho-list-operate{margin:0;padding:10px 14px;border-bottom:1px solid #F0F0EC;background:#FAFAF8}
.typecho-list-table.enh-moments-list{width:100%;margin:0;border:0;border-collapse:collapse;border-spacing:0;table-layout:fixed}
.typecho-list-table.enh-moments-list td{padding:14px;border-top:0;border-bottom:1px solid #F0F0EC;vertical-align:top;word-break:break-word}
.typecho-list-table.enh-moments-list tr:last-child td{border-bottom:0}
.typecho-list-table.enh-moments-list tbody tr:hover{background:#FBFBF9}
.typecho-list-table.enh-moments-list tbody tr.is-editing{background:#F2F7FA}
.typecho-list-table.enh-moments-list tbody tr:has(input[type="checkbox"]:checked){background:#FFF9E8}
.typecho-list-table.enh-moments-list td.col-check{width:38px;padding-right:0;box-sizing:border-box}
.typecho-list-table.enh-moments-list td.col-check input{display:block;margin:5px 0 0}
.typecho-list-table.enh-moments-list td.col-actions{width:64px;text-align:right;white-space:nowrap}
.enh-moment-text{margin:0 0 8px;color:#333;font-size:14px;line-height:1.7;word-break:break-word;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.enh-moment-text a{color:#333}
.enh-moment-text a:hover{color:#467B96}
.enh-moment-media{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px}
.enh-moment-media a{position:relative;display:block;width:64px;height:64px;border-radius:4px;overflow:hidden;background:#F0F0EC}
.enh-moment-media img{display:block;width:100%;height:100%;object-fit:cover}
.enh-moment-media .video,.enh-moment-media .more{display:flex;align-items:center;justify-content:center;color:#777;font-size:12px}
.enh-moment-media .more{background:#E9E9E6;color:#555;font-weight:600}
.enh-moment-tags{display:flex;flex-wrap:wrap;gap:4px;margin:0 0 8px}
.enh-moment-tags span{padding:1px 8px;border-radius:999px;background:#EEF4F7;color:#467B96;font-size:12px;line-height:20px;white-space:nowrap}
.enh-moment-meta{display:flex;flex-wrap:wrap;align-items:center;gap:4px 14px;color:#999;font-size:12px;line-height:20px}
.enh-moment-meta .location{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.enh-moment-badge{padding:0 7px;border-radius:3px;background:#FFF3E0;color:#B26A00;font-size:12px;line-height:20px}
.enh-moment-actions a{display:block;font-size:13px;line-height:22px}
.enh-moment-actions .delete{color:#B94A48}
.enh-moments-empty{padding:40px 14px;text-align:center;color:#999}
.enh-moments-empty p{margin:0 0 6px}
.enh-moments-pager{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:12px 0 0;color:#999;font-size:12px}
.enh-moments-pager .typecho-pager{margin:0}
.enh-moment-editor{background:#fff;border:1px solid #E9E9E6;border-radius:6px;padding:4px 16px 16px}
.enh-moment-editor-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 -16px 6px;padding:12px 16px;border-bottom:1px solid #F0F0EC}
.enh-moment-editor-head h3{margin:0;font-size:15px}
.enh-moment-editor-head a{font-size:13px}
.enh-moment-editor .typecho-option{margin:12px 0 0;padding:0}
.enh-moment-editor textarea{width:100%;min-height:150px;box-sizing:border-box;resize:vertical}
.enh-moment-editor input.text{width:100%;box-sizing:border-box}
.enh-moment-editor .enhancement-option-no-bullet,.enh-moment-editor .enhancement-option-no-bullet li{list-style:none;margin:0;padding:0}
.enh-moment-editor .enhancement-option-no-bullet .description{margin:6px 0 0}
.enh-moment-editor .enhancement-action-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.enh-moment-editor .enhancement-action-note{color:#999;font-size:12px;line-height:1.5}
.enh-moment-editor .typecho-option-submit{margin-top:16px}
.enh-moment-editor .typecho-option-submit .btn{width:100%}
@media (max-width: 767px){
    .enh-moments-search,.enh-moments-search .text-s{width:100%}
    .enh-moments-search .text-s{flex:1}
    .typecho-list-table.enh-moments-list td{padding:12px 10px}
    .typecho-list-table.enh-moments-list td.col-check{width:30px}
    .typecho-list-table.enh-moments-list td.col-actions{width:40px}
    .enh-moment-editor{margin-top:20px}
}
</style>

<?php include 'manage-layout-start.php'; ?>
            <div class="col-mb-12">
                <?php
                $enhancementCurrentTab = 'moments';
                $enhancementTabPreset = 'core';
                include 'manage-tabs.php';
                ?>
            </div>

            <div class="col-mb-12 col-tb-8" role="main">
                <?php if ($momentsError !== ''): ?>
                <div class="message error"><?php echo $momentsEscape(_t('瞬间数据读取失败：') . $momentsError); ?></div>
                <?php endif; ?>

                <div class="enh-moments-toolbar">
                    <ul class="enh-moments-filters">
                        <?php foreach (array('' => _t('全部'), 'public' => _t('公开'), 'private' => _t('私密')) as $filterKey => $filterLabel): ?>
                        <li<?php if ($momentsStatusFilter === $filterKey): ?> class="current"<?php endif; ?>>
                            <a href="<?php echo $momentsEscape($momentsUrl(array('status' => $filterKey))); ?>"><?php echo $filterLabel; ?><span class="num"><?php echo $momentsCounts[$filterKey === '' ? 'all' : $filterKey]; ?></span></a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <form class="enh-moments-search" method="get" action="<?php $options->adminUrl('extending.php'); ?>" role="search">
                        <input type="hidden" name="panel" value="Enhancement/manage-moments.php" />
                        <?php if ($momentsStatusFilter !== ''): ?>
                        <input type="hidden" name="status" value="<?php echo $momentsEscape($momentsStatusFilter); ?>" />
                        <?php endif; ?>
                        <?php if ($momentsKeywords !== ''): ?>
                        <a class="clear" href="<?php echo $momentsEscape($momentsUrl(array('keywords' => ''))); ?>"><?php _e('&laquo; 取消搜索'); ?></a>
                        <?php endif; ?>
                        <input type="text" class="text-s" name="keywords" placeholder="<?php _e('搜索内容或标签'); ?>" value="<?php echo $momentsEscape($momentsKeywords); ?>" />
                        <button type="submit" class="btn btn-s"><?php _e('搜索'); ?></button>
                    </form>
                </div>

                <form method="post" name="manage_moments" class="operate-form">
                    <div class="enh-moments-list-wrap">
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

                        <table class="typecho-list-table enh-moments-list">
                            <tbody>
                                <?php if (!empty($moments)): ?>
                                    <?php foreach ($moments as $moment): ?>
                                    <?php
                                        $mid = intval($moment['mid']);
                                        $editUrl = $request->makeUriByRequest('mid=' . $mid);
                                        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$moment['content'])));
                                        $tags = $momentsParseTags(isset($moment['tags']) ? $moment['tags'] : '');
                                        $media = $momentsParseMedia(isset($moment['media']) ? $moment['media'] : '');
                                        $status = Enhancement_Plugin::normalizeMomentStatus(isset($moment['status']) ? $moment['status'] : '', 'public');
                                        $source = Enhancement_Plugin::normalizeMomentSource(isset($moment['source']) ? $moment['source'] : '', 'web');
                                        $address = isset($moment['location_address']) ? trim((string)$moment['location_address']) : '';
                                        $latitude = isset($moment['latitude']) ? trim((string)$moment['latitude']) : '';
                                        $longitude = isset($moment['longitude']) ? trim((string)$moment['longitude']) : '';
                                        $location = $address !== '' ? $address : (($latitude !== '' && $longitude !== '') ? $latitude . ', ' . $longitude : '');
                                        $created = isset($moment['created']) && is_numeric($moment['created']) ? intval($moment['created']) : 0;
                                    ?>
                                    <tr id="moment-<?php echo $mid; ?>"<?php if ($mid === $momentsEditingMid): ?> class="is-editing"<?php endif; ?>>
                                        <td class="col-check"><input type="checkbox" value="<?php echo $mid; ?>" name="mid[]"/></td>
                                        <td>
                                            <div class="enh-moment-text">
                                                <a href="<?php echo $momentsEscape($editUrl); ?>" title="<?php _e('点击编辑'); ?>"><?php echo $plain !== '' ? $momentsEscape(Typecho_Common::subStr($plain, 0, 160, '...')) : _t('（无文字内容）'); ?></a>
                                            </div>
                                            <?php if (!empty($media)): ?>
                                            <div class="enh-moment-media">
                                                <?php foreach (array_slice($media, 0, 4) as $mediaIndex => $item): ?>
                                                    <?php if ($mediaIndex === 3 && count($media) > 4): ?>
                                                    <a class="more" href="<?php echo $momentsEscape($editUrl); ?>">+<?php echo count($media) - 3; ?></a>
                                                    <?php elseif ($item['type'] === 'VIDEO'): ?>
                                                    <a class="video" href="<?php echo $momentsEscape($item['url']); ?>" target="_blank" rel="noopener noreferrer"><?php _e('视频'); ?></a>
                                                    <?php else: ?>
                                                    <a href="<?php echo $momentsEscape($item['url']); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo $momentsEscape($item['url']); ?>" alt="" loading="lazy" referrerpolicy="no-referrer" /></a>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($tags)): ?>
                                            <div class="enh-moment-tags">
                                                <?php foreach ($tags as $tag): ?><span><?php echo $momentsEscape($tag); ?></span><?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                            <div class="enh-moment-meta">
                                                <?php if ($status === 'private'): ?><span class="enh-moment-badge"><?php _e('私密'); ?></span><?php endif; ?>
                                                <?php if ($created > 0): ?><span><?php echo date('Y-m-d H:i', $created); ?></span><?php endif; ?>
                                                <span><?php echo $momentsSourceLabels[$source]; ?></span>
                                                <?php if ($location !== ''): ?><span class="location" title="<?php echo $momentsEscape($location); ?>"><?php echo $momentsEscape($location); ?></span><?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="col-actions enh-moment-actions">
                                            <a href="<?php echo $momentsEscape($editUrl); ?>"><?php _e('编辑'); ?></a>
                                            <a class="delete" href="<?php $security->index('/action/enhancement-moments-edit?do=delete&mid=' . $mid); ?>" onclick="return confirm('<?php _e('你确认要删除这条瞬间吗?'); ?>');"><?php _e('删除'); ?></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="enh-moments-empty">
                                            <?php if ($momentsFiltered): ?>
                                            <p><?php _e('没有符合条件的瞬间'); ?></p>
                                            <a href="<?php echo $momentsEscape($momentsUrl(array('status' => '', 'keywords' => ''))); ?>"><?php _e('查看全部瞬间'); ?></a>
                                            <?php else: ?>
                                            <p><?php _e('还没有任何瞬间'); ?></p>
                                            <?php _e('在右侧写下第一条吧'); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <?php if ($momentsTotal > 0): ?>
                <div class="enh-moments-pager">
                    <span><?php _e('共 %d 条', $momentsTotal); ?></span>
                    <?php if ($momentsTotal > $momentsPageSize): ?>
                    <ul class="typecho-pager">
                        <?php
                        $momentsNav = new Typecho_Widget_Helper_PageNavigator_Box($momentsTotal, $momentsPage, $momentsPageSize, $momentsUrl(array('page' => '{page}')));
                        $momentsNav->render(_t('&laquo;'), _t('&raquo;'));
                        ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-mb-12 col-tb-4" role="form">
                <div class="enh-moment-editor">
                    <div class="enh-moment-editor-head">
                        <h3><?php echo $momentsEditingMid > 0 ? _t('编辑瞬间 #%d', $momentsEditingMid) : _t('发布瞬间'); ?></h3>
                        <?php if ($momentsEditingMid > 0): ?>
                        <a href="<?php echo $momentsEscape($momentsUrl(array('page' => $momentsPage > 1 ? $momentsPage : ''))); ?>"><?php _e('取消编辑'); ?></a>
                        <?php endif; ?>
                    </div>
                    <?php Enhancement_Plugin::momentsForm()->render(); ?>
                </div>
            </div>
<?php include 'manage-layout-end.php'; ?>

<?php
include 'manage-page-assets.php';
?>

<?php
$enhancementListHighlightPanel = isset($request->mid);
include 'manage-list-script.php';
?>
<script type="text/javascript">
(function () {
    $(document).ready(function () {
        var locateBtn = $('#enhancement-moment-locate-btn');
        var locateStatus = $('#enhancement-moment-locate-status');
        var latitudeInput = $('input[name="latitude"]');
        var longitudeInput = $('input[name="longitude"]');
        var addressInput = $('input[name="location_address"]');

        function setLocateStatus(text, isError) {
            if (!locateStatus.length) {
                return;
            }
            locateStatus.text(text || '');
            locateStatus.css('color', isError ? '#c0392b' : '#666');
        }

        function reverseGeocode(latitude, longitude) {
            if (!locateBtn.length) {
                return;
            }

            var mapKey = (locateBtn.data('map-key') || '').toString().trim();
            if (mapKey === '') {
                setLocateStatus('已获取经纬度（未配置腾讯地图 API Key，跳过地址解析）', false);
                return;
            }

            setLocateStatus('已获取经纬度，正在通过腾讯地图解析详细地址...', false);
            $.ajax({
                url: 'https://apis.map.qq.com/ws/geocoder/v1/',
                method: 'GET',
                dataType: 'jsonp',
                jsonp: 'callback',
                timeout: 10000,
                cache: false,
                data: {
                    location: latitude + ',' + longitude,
                    key: mapKey,
                    get_poi: 0,
                    output: 'jsonp'
                }
            }).done(function (response) {
                if (response && Number(response.status) === 0) {
                    var result = response.result || {};
                    var address = '';
                    if (result.formatted_addresses && result.formatted_addresses.recommend) {
                        address = String(result.formatted_addresses.recommend || '').trim();
                    }
                    if (!address && result.address) {
                        address = String(result.address || '').trim();
                    }
                    if (!address && result.address_component) {
                        var c = result.address_component;
                        address = [
                            c.province || '',
                            c.city || '',
                            c.district || '',
                            c.street || ''
                        ].join('').trim();
                    }
                    if (addressInput.length && address) {
                        addressInput.val(address);
                    }
                    setLocateStatus(address ? '定位成功：已填充详细地址' : '定位成功：已获取经纬度', false);
                    return;
                }
                var statusCode = response && typeof response.status !== 'undefined' ? String(response.status) : '';
                var errorMessage = (response && response.message) ? String(response.message) : '地址解析失败，已保留经纬度';
                if (statusCode !== '') {
                    errorMessage = '腾讯地图解析失败（status ' + statusCode + '）：' + errorMessage;
                }
                setLocateStatus(errorMessage, true);
            }).fail(function () {
                setLocateStatus('地址解析失败，已保留经纬度', true);
            });
        }

        function locateByIp() {
            if (!locateBtn.length) {
                return;
            }

            var mapKey = (locateBtn.data('map-key') || '').toString().trim();
            if (mapKey === '') {
                locateBtn.prop('disabled', false);
                setLocateStatus('定位失败：未配置腾讯地图 API Key，无法使用 IP 定位兜底', true);
                return;
            }

            setLocateStatus('浏览器定位较慢，正在尝试 IP 快速定位...', false);
            $.ajax({
                url: 'https://apis.map.qq.com/ws/location/v1/ip',
                method: 'GET',
                dataType: 'jsonp',
                jsonp: 'callback',
                timeout: 8000,
                cache: false,
                data: {
                    key: mapKey,
                    output: 'jsonp'
                }
            }).done(function (response) {
                if (response && Number(response.status) === 0 && response.result && response.result.location) {
                    var ipLat = Number(response.result.location.lat || 0).toFixed(7);
                    var ipLng = Number(response.result.location.lng || 0).toFixed(7);

                    if (latitudeInput.length) {
                        latitudeInput.val(ipLat);
                    }
                    if (longitudeInput.length) {
                        longitudeInput.val(ipLng);
                    }

                    locateBtn.prop('disabled', false);
                    reverseGeocode(ipLat, ipLng);
                    return;
                }

                locateBtn.prop('disabled', false);
                var statusCode = response && typeof response.status !== 'undefined' ? String(response.status) : '';
                var errorMessage = (response && response.message) ? String(response.message) : 'IP 定位失败';
                if (statusCode !== '') {
                    errorMessage = '腾讯地图 IP 定位失败（status ' + statusCode + '）：' + errorMessage;
                }
                setLocateStatus(errorMessage, true);
            }).fail(function () {
                locateBtn.prop('disabled', false);
                setLocateStatus('IP 定位请求失败，请检查网络后重试', true);
            });
        }

        if (locateBtn.length) {
                locateBtn.on('click', function () {
                    if (!navigator.geolocation) {
                        locateByIp();
                        return;
                    }

                    locateBtn.prop('disabled', true);
                    setLocateStatus('正在快速获取当前位置...', false);

                function handlePositionSuccess(position) {
                    var latitude = Number(position.coords.latitude || 0).toFixed(7);
                    var longitude = Number(position.coords.longitude || 0).toFixed(7);

                    if (latitudeInput.length) {
                        latitudeInput.val(latitude);
                    }
                    if (longitudeInput.length) {
                        longitudeInput.val(longitude);
                    }

                    locateBtn.prop('disabled', false);
                    reverseGeocode(latitude, longitude);
                }

                function handlePositionError(error, retried) {
                    if (!retried && error && error.code === 3) {
                        setLocateStatus('浏览器定位超时，正在重试一次精准定位...', false);
                        requestPosition(true);
                        return;
                    }

                    if (error && error.code === 1) {
                        setLocateStatus('浏览器定位被拒绝，正在尝试 IP 快速定位...', false);
                    } else if (error && error.code === 2) {
                        setLocateStatus('浏览器定位不可用，正在尝试 IP 快速定位...', false);
                    } else {
                        setLocateStatus('浏览器定位仍超时，正在尝试 IP 快速定位...', false);
                    }
                    locateByIp();
                }

                function requestPosition(retried) {
                    navigator.geolocation.getCurrentPosition(function (position) {
                        handlePositionSuccess(position);
                    }, function (error) {
                        handlePositionError(error, retried);
                    }, retried ? {
                        enableHighAccuracy: true,
                        timeout: 6000,
                        maximumAge: 0
                    } : {
                        enableHighAccuracy: false,
                        timeout: 6000,
                        maximumAge: 180000
                    });
                }

                requestPosition(false);
            });
        }
    });
})();
</script>
<?php include 'footer.php'; ?>

<?php /** Enhancement */ ?>
