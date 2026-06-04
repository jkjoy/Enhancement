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
        $current = self::normalizeSettingsForStorage($current);

        $merged = array_merge($current, $incoming);
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
        if (Enhancement_S3Helper::enabled()) {
            Enhancement_S3Helper::registerHooks();
        } else {
            Enhancement_S3Helper::unregisterHooks();
        }
    }
}
