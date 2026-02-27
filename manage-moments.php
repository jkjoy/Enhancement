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
                    Enhancement_Plugin::ensureMomentsTable();
                    $prefix = $db->getPrefix();
                    $moments = $db->fetchAll($db->select()->from($prefix . 'moments')->order($prefix . 'moments.mid', Typecho_Db::SORT_DESC));
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
                                <col width="16%"/>
                                <col width="10%"/>
                                <col width="18%"/>
                                <col width="12%"/>
                                <col width="16%"/>
                            </colgroup>
                            <thead>
                                <tr>
                                    <th> </th>
                                    <th><?php _e('内容'); ?></th>
                                    <th><?php _e('标签'); ?></th>
                                    <th><?php _e('状态'); ?></th>
                                    <th><?php _e('定位'); ?></th>
                                    <th><?php _e('来源'); ?></th>
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
                                            $statusRaw = isset($moment['status']) ? (string)$moment['status'] : '';
                                            $status = Enhancement_Plugin::normalizeMomentStatus($statusRaw, 'public');
                                            echo $status === 'private' ? _t('私密') : _t('公开');
                                        ?></td>
                                        <td><?php
                                            $address = isset($moment['location_address']) ? trim((string)$moment['location_address']) : '';
                                            $latitude = isset($moment['latitude']) ? trim((string)$moment['latitude']) : '';
                                            $longitude = isset($moment['longitude']) ? trim((string)$moment['longitude']) : '';
                                            if ($address !== '') {
                                                echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8');
                                            } else if ($latitude !== '' && $longitude !== '') {
                                                echo htmlspecialchars($latitude . ', ' . $longitude, ENT_QUOTES, 'UTF-8');
                                            } else {
                                                echo '-';
                                            }
                                        ?></td>
                                        <td><?php
                                            $sourceRaw = isset($moment['source']) ? trim((string)$moment['source']) : '';
                                            $source = Enhancement_Plugin::normalizeMomentSource($sourceRaw, 'web');
                                            if ($source === 'mobile') {
                                                echo _t('手机端');
                                            } else if ($source === 'api') {
                                                echo 'API';
                                            } else {
                                                echo _t('Web端');
                                            }
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
                                        <td colspan="7"><h6 class="typecho-list-table-title"><?php _e('没有任何瞬间'); ?></h6></td>
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
                timeout: 12000,
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

        if (locateBtn.length) {
            locateBtn.on('click', function () {
                if (!navigator.geolocation) {
                    setLocateStatus('当前浏览器不支持定位 API', true);
                    return;
                }

                locateBtn.prop('disabled', true);
                setLocateStatus('正在获取当前位置...', false);

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

                function handlePositionError(error, triedFallback) {
                    if (!triedFallback && error && error.code === 3) {
                        setLocateStatus('高精度定位超时，正在尝试低精度定位...', false);
                        requestPosition(true);
                        return;
                    }

                    locateBtn.prop('disabled', false);
                    var message = '定位失败';
                    if (error && error.code === 1) {
                        message = '定位失败：用户拒绝了定位权限';
                    } else if (error && error.code === 2) {
                        message = '定位失败：无法获取位置信息';
                    } else if (error && error.code === 3) {
                        message = triedFallback
                            ? '定位失败：低精度定位仍超时，请检查网络后重试'
                            : '定位失败：请求超时';
                    }
                    setLocateStatus(message, true);
                }

                function requestPosition(useLowAccuracy) {
                    navigator.geolocation.getCurrentPosition(function (position) {
                        handlePositionSuccess(position);
                    }, function (error) {
                        handlePositionError(error, useLowAccuracy);
                    }, useLowAccuracy ? {
                        enableHighAccuracy: false,
                        timeout: 20000,
                        maximumAge: 600000
                    } : {
                        enableHighAccuracy: true,
                        timeout: 15000,
                        maximumAge: 0
                    });
                }

                requestPosition(false);
            });
        }

        <?php if (isset($request->mid)): ?>
        $('.typecho-mini-panel').effect('highlight', '#AACB36');
        <?php endif; ?>
    });
})();
</script>
<?php include 'footer.php'; ?>

<?php /** Enhancement */ ?>
