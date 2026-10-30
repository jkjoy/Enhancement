<?php

class Enhancement_ConfigStorageHelper
{
    public static function normalizeSettingsForStorage($settings)
    {
        if (!is_array($settings)) {
            return array();
        }

        $normalized = array();
        foreach ($settings as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = $value;
                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = $value ? '1' : '0';
            } elseif (is_null($value)) {
                $normalized[$key] = '';
            } elseif (is_scalar($value)) {
                $normalized[$key] = (string)$value;
            }
        }

        return $normalized;
    }

    public static function configHandle($settings, $isInit)
    {
        if (!is_array($settings)) {
            return;
        }

        Enhancement_SettingsHelper::ensurePluginConfigOptionExists();

        $optionName = 'plugin:Enhancement';
        $incoming = self::normalizeSettingsForStorage($settings);
        if (empty($incoming)) {
            return;
        }

        $currentValue = Enhancement_SettingsHelper::normalizeOptionRows(
            $optionName,
            true,
            Enhancement_SettingsHelper::encodePluginConfigValue(array())
        );
        $current = Enhancement_SettingsHelper::decodePluginConfigValue($currentValue);
        $current = Enhancement_QqNotifyHelper::normalizeSettings(self::normalizeSettingsForStorage($current));

        if (isset($incoming['qq_app_id']) && $incoming['qq_app_id'] !== ($current['qq_app_id'] ?? '')
            && ($current['qq_app_id'] ?? '') !== '' && ($incoming['qq_app_id'] ?? '') !== ''
            && ($current['qq_app_secret'] ?? '') !== '' && ($incoming['qq_app_secret'] ?? '') === '') {
            throw new Typecho_Widget_Exception(_t('修改 QQ 机器人 AppID 时须同时填写对应的新 AppSecret'), 400);
        }

        // The password input stays empty in HTML; an empty save keeps its secret.
        foreach (array('qq_app_secret') as $secretKey) {
            if (isset($incoming[$secretKey]) && $incoming[$secretKey] === '') {
                unset($incoming[$secretKey]);
            }
        }
        $merged = Enhancement_QqNotifyHelper::normalizeSettings(array_merge($current, $incoming));
        Enhancement_CommentGuardHelper::validateSettings($merged);
        Enhancement_QqNotifyHelper::validateSettings($merged);
        Enhancement_FriendPostsHelper::validateSettings($merged);
        Enhancement_WeixinNotifyHelper::validateSettings($merged, $current);
        Enhancement_BotMomentsHelper::validateSettings($merged);
        Enhancement_BotMomentsSchedulerHelper::validateSettings($merged);
        if (Enhancement_QqOfficialHelper::credentialsIdentity($current) !== Enhancement_QqOfficialHelper::credentialsIdentity($merged)) {
            foreach (array('qq_binding', 'qq_binding_pending', 'qq_token') as $key) {
                Enhancement_NotifyTransportHelper::write($key, array());
            }
        }
        Enhancement_BotMomentsHelper::syncActivation($merged);
        Enhancement_FriendPostsNotifyHelper::syncActivation($merged);
        $storedValue = Enhancement_SettingsHelper::encodePluginConfigValue($merged);

        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('name')
                    ->from('table.options')
                    ->where('name = ?', $optionName)
                    ->where('user = ?', 0)
                    ->limit(1)
            );

            if (is_array($row) && !empty($row)) {
                $db->query(
                    $db->update('table.options')
                        ->rows(array('value' => $storedValue))
                        ->where('name = ?', $optionName)
                        ->where('user = ?', 0)
                );
            } else {
                $db->query(
                    $db->insert('table.options')->rows(array(
                        'name' => $optionName,
                        'user' => 0,
                        'value' => $storedValue
                    ))
                );
            }
        } catch (Exception $e) {
            // ignore save errors
        }

        Enhancement_SettingsHelper::syncOptionCache($optionName, $storedValue, 'Enhancement');
        Enhancement_LifecycleHelper::ensureFriendPostsPanel();
        Enhancement_RestApiHelper::syncRoutes((object)$merged);
        if (Enhancement_S3Helper::enabled()) {
            Enhancement_S3Helper::registerHooks();
        } elseif (!$isInit) {
            Enhancement_S3Helper::unregisterHooks();
        }
    }
}
