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

    /** Saving must use a fresh, strict read; a failed read must never become empty settings. */
    private static function currentSettings(): array
    {
        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow($db->select('value')->from('table.options')
                ->where('name = ?', 'plugin:Enhancement')->where('user = ?', 0)->limit(1));
            if ($row === false) {
                throw new RuntimeException('Could not read plugin settings.');
            }
            return Enhancement_QqNotifyHelper::normalizeSettings(self::normalizeSettingsForStorage(
                Enhancement_SettingsHelper::decodePluginConfigValue($row['value'] ?? '')));
        } catch (Throwable $exception) {
            throw new Typecho_Widget_Exception(_t('读取插件配置失败，未保存任何更改，请稍后重试'), 500);
        }
    }

    private static function normalizeSmtpAliases(array $settings): array
    {
        if (array_key_exists('SMTPHost', $settings)) {
            $settings['STMPHost'] = $settings['SMTPHost'];
        } elseif (array_key_exists('STMPHost', $settings)) {
            $settings['SMTPHost'] = $settings['STMPHost'];
        }
        return $settings;
    }

    public static function configHandle($settings, $isInit)
    {
        if (!is_array($settings)) {
            return;
        }
        $incoming = self::normalizeSmtpAliases(self::normalizeSettingsForStorage($settings));
        if (!$incoming) {
            return;
        }
        $current = self::currentSettings();
        if (isset($incoming['qq_app_id']) && $incoming['qq_app_id'] !== ($current['qq_app_id'] ?? '')
            && ($current['qq_app_id'] ?? '') !== '' && ($incoming['qq_app_id'] ?? '') !== ''
            && ($current['qq_app_secret'] ?? '') !== '' && ($incoming['qq_app_secret'] ?? '') === '') {
            throw new Typecho_Widget_Exception(_t('修改 QQ 机器人 AppID 时须同时填写对应的新 AppSecret'), 400);
        }
        // The password input stays empty in HTML; an empty save keeps its secret.
        if (isset($incoming['qq_app_secret']) && $incoming['qq_app_secret'] === '') {
            unset($incoming['qq_app_secret']);
        }
        self::persistSettings(array_merge($current, $incoming), $current, (bool)$isInit);
    }

    /** Backup restoration replaces settings, while the settings form merges them. */
    public static function replaceSettings(array $settings): void
    {
        self::persistSettings(self::normalizeSmtpAliases(self::normalizeSettingsForStorage($settings)),
            self::currentSettings(), false);
    }

    private static function persistSettings(array $settings, array $current, bool $isInit): void
    {
        $settings = Enhancement_QqNotifyHelper::normalizeSettings($settings);
        Enhancement_CommentGuardHelper::validateSettings($settings);
        Enhancement_QqNotifyHelper::validateSettings($settings);
        Enhancement_FriendPostsHelper::validateSettings($settings);
        Enhancement_WeixinNotifyHelper::validateSettings($settings, $current);
        Enhancement_BotMomentsHelper::validateSettings($settings);
        Enhancement_BotMomentsSchedulerHelper::validateSettings($settings);
        $optionName = 'plugin:Enhancement';
        $storedValue = Enhancement_SettingsHelper::encodePluginConfigValue($settings);
        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow($db->select('name')->from('table.options')
                ->where('name = ?', $optionName)->where('user = ?', 0)->limit(1));
            if ($row === false) {
                throw new RuntimeException('Could not read plugin settings.');
            }
            $query = $row
                ? $db->update('table.options')->rows(array('value' => $storedValue))
                    ->where('name = ?', $optionName)->where('user = ?', 0)
                : $db->insert('table.options')->rows(array('name' => $optionName, 'user' => 0, 'value' => $storedValue));
            // Updating identical settings may legitimately affect zero rows.
            if ($db->query($query) === false) {
                throw new RuntimeException('Could not write plugin settings.');
            }
        } catch (Throwable $exception) {
            throw new Typecho_Widget_Exception(_t('保存插件配置失败，原配置和通知状态未更改，请稍后重试'), 500);
        }

        // All cache, notification and route effects follow a successful database write.
        try {
            Enhancement_SettingsHelper::syncOptionCache($optionName, $storedValue, 'Enhancement');
            if (Enhancement_QqOfficialHelper::credentialsIdentity($current) !== Enhancement_QqOfficialHelper::credentialsIdentity($settings)) {
                foreach (array('qq_binding', 'qq_binding_pending', 'qq_token') as $key) {
                    Enhancement_NotifyTransportHelper::write($key, array());
                }
            }
            Enhancement_BotMomentsHelper::syncActivation($settings);
            Enhancement_FriendPostsNotifyHelper::syncActivation($settings);
            Enhancement_LifecycleHelper::ensureFriendPostsPanel();
            Enhancement_RestApiHelper::syncRoutes((object)$settings);
            if (Enhancement_S3Helper::enabled()) {
                Enhancement_S3Helper::registerHooks();
            } elseif (!$isInit) {
                Enhancement_S3Helper::unregisterHooks();
            }
        } catch (Throwable $exception) {
            throw new Typecho_Widget_Exception(_t('插件配置已保存，但通知或路由状态更新失败，请重试保存'), 500);
        }
    }
}
