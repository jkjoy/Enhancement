<?php

class Enhancement_SettingsHelper
{
    private static function settingsBackupNamePrefix()
    {
        return 'enh:bak:';
    }

    private static function legacySettingsBackupNamePrefix()
    {
        return 'plugin:Enhancement:backup:';
    }

    private static function settingsBackupNamePrefixes()
    {
        return array(
            self::settingsBackupNamePrefix(),
            self::legacySettingsBackupNamePrefix()
        );
    }

    private static function extractSettingsBackupTimestamp($name)
    {
        $name = trim((string)$name);
        if (preg_match('/^enh:bak:(\d{14})-[A-Za-z0-9]+$/', $name, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^plugin:Enhancement:backup:(\d{14})-[A-Za-z0-9]+$/', $name, $matches)) {
            return $matches[1];
        }

        return '';
    }

    public static function listSettingsBackups($limit = 5)
    {
        $limit = intval($limit);
        if ($limit <= 0) {
            $limit = 5;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        try {
            $db = Typecho_Db::get();
            $rows = array();
            foreach (self::settingsBackupNamePrefixes() as $prefix) {
                $currentRows = $db->fetchAll(
                    $db->select('name')
                        ->from('table.options')
                        ->where('name LIKE ?', $prefix . '%')
                        ->where('user = ?', 0)
                        ->order('name', Typecho_Db::SORT_DESC)
                        ->limit($limit)
                );
                if (is_array($currentRows)) {
                    $rows = array_merge($rows, $currentRows);
                }
            }

            usort($rows, array(__CLASS__, 'compareSettingsBackupRows'));
            if (count($rows) > $limit) {
                $rows = array_slice($rows, 0, $limit);
            }

            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    private static function compareSettingsBackupRows($left, $right)
    {
        $leftName = isset($left['name']) ? (string)$left['name'] : '';
        $rightName = isset($right['name']) ? (string)$right['name'] : '';
        $leftTime = self::extractSettingsBackupTimestamp($leftName);
        $rightTime = self::extractSettingsBackupTimestamp($rightName);

        if ($leftTime === $rightTime) {
            return strcmp($rightName, $leftName);
        }

        return strcmp($rightTime, $leftTime);
    }

    public static function pluginSettings($options = null)
    {
        $settings = self::readPluginSettingsFromDatabase();
        if (!empty($settings)) {
            return self::buildPluginConfigObject(self::encodePluginConfigValue($settings));
        }

        return self::buildPluginConfigObject(self::encodePluginConfigValue(array()));
    }

    public static function readPluginSettingsFromDatabase()
    {
        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('value')
                    ->from('table.options')
                    ->where('name = ?', 'plugin:Enhancement')
                    ->where('user = ?', 0)
                    ->limit(1)
            );

            if (is_array($row) && isset($row['value'])) {
                return self::decodePluginConfigValue((string)$row['value']);
            }
        } catch (Exception $e) {
            // ignore db read errors
        }

        return array();
    }

    public static function decodePluginConfigValue($value)
    {
        $text = trim((string)$value);
        if ($text === '') {
            return array();
        }

        $jsonDecoded = json_decode($text, true);
        if (is_array($jsonDecoded)) {
            return $jsonDecoded;
        }

        $unserialized = @unserialize($text);
        if (is_array($unserialized)) {
            return $unserialized;
        }

        return array();
    }

    public static function encodePluginConfigValue($settings)
    {
        if (!is_array($settings)) {
            $settings = array();
        }

        $serialized = @serialize($settings);
        if (!is_string($serialized) || $serialized === '') {
            $serialized = 'a:0:{}';
        }

        return $serialized;
    }

    public static function ensurePluginConfigOptionExists()
    {
        $pluginName = 'Enhancement';
        $globalOptionName = 'plugin:' . $pluginName;
        $defaultValue = self::encodePluginConfigValue(array());
        $globalOptionValue = self::normalizeOptionRows($globalOptionName, true, $defaultValue);
        self::normalizeOptionRows('_plugin:' . $pluginName, false, $defaultValue);
        self::syncOptionCache($globalOptionName, $globalOptionValue, $pluginName);
    }

    public static function normalizeOptionRows($optionName, $ensureGlobalRow = false, $defaultValue = null)
    {
        $optionName = trim((string)$optionName);
        if ($optionName === '') {
            return self::encodePluginConfigValue(array());
        }

        if ($defaultValue === null || trim((string)$defaultValue) === '') {
            $defaultValue = self::encodePluginConfigValue(array());
        }
        $defaultValue = self::normalizePluginConfigValue($defaultValue);
        $globalValue = $defaultValue;

        try {
            $db = Typecho_Db::get();
            $rows = $db->fetchAll(
                $db->select('user', 'value')
                    ->from('table.options')
                    ->where('name = ?', $optionName)
            );

            if (!is_array($rows) || empty($rows)) {
                if ($ensureGlobalRow) {
                    $db->query(
                        $db->insert('table.options')->rows(array(
                            'name' => $optionName,
                            'user' => 0,
                            'value' => $globalValue
                        ))
                    );
                }

                return $globalValue;
            }

            $hasGlobalRow = false;

            foreach ($rows as $row) {
                $userId = isset($row['user']) ? intval($row['user']) : 0;
                $currentValue = isset($row['value']) ? (string)$row['value'] : '';
                $normalizedValue = self::normalizePluginConfigValue($currentValue);

                if ($currentValue !== $normalizedValue) {
                    $db->query(
                        $db->update('table.options')
                            ->rows(array('value' => $normalizedValue))
                            ->where('name = ?', $optionName)
                            ->where('user = ?', $userId)
                    );
                }

                if ($userId === 0) {
                    $hasGlobalRow = true;
                    $globalValue = $normalizedValue;
                }
            }

            if ($ensureGlobalRow && !$hasGlobalRow) {
                $db->query(
                    $db->insert('table.options')->rows(array(
                        'name' => $optionName,
                        'user' => 0,
                        'value' => $globalValue
                    ))
                );
            }
        } catch (Exception $e) {
            // ignore db normalize errors
        }

        return $globalValue;
    }

    public static function normalizePluginConfigValue($value)
    {
        $settings = self::decodePluginConfigValue($value);
        return self::encodePluginConfigValue($settings);
    }

    public static function syncOptionCache($optionName, $optionValue, $pluginName = 'Enhancement')
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options');
            self::patchOptionsWidgetCache($options, $optionName, $optionValue, $pluginName);

            $widgetClass = class_exists('Typecho_Widget', false) ? 'Typecho_Widget' : 'Typecho\\Widget';
            $reflector = new ReflectionClass($widgetClass);
            if ($reflector->hasProperty('widgetPool')) {
                $poolProperty = $reflector->getProperty('widgetPool');
                $poolProperty->setAccessible(true);
                $pool = $poolProperty->getValue();
                if (is_array($pool)) {
                    foreach ($pool as $widget) {
                        self::patchOptionsWidgetCache($widget, $optionName, $optionValue, $pluginName);
                    }
                }
            }
        } catch (Exception $e) {
            // ignore cache sync errors
        }
    }

    public static function patchOptionsWidgetCache($widget, $optionName, $optionValue, $pluginName = 'Enhancement')
    {
        if (!is_object($widget) || !is_a($widget, 'Widget\\Options')) {
            return;
        }

        try {
            $reflector = new ReflectionObject($widget);

            while ($reflector) {
                if ($reflector->hasProperty('row')) {
                    $rowProperty = $reflector->getProperty('row');
                    $rowProperty->setAccessible(true);

                    $rows = $rowProperty->getValue($widget);
                    if (!is_array($rows)) {
                        $rows = array();
                    }

                    $rows[(string)$optionName] = (string)$optionValue;
                    $rowProperty->setValue($widget, $rows);
                    break;
                }

                $reflector = $reflector->getParentClass();
            }
        } catch (Exception $e) {
            // ignore
        }

        try {
            $reflector = new ReflectionObject($widget);
            if ($reflector->hasProperty('pluginConfig')) {
                $pluginConfigProperty = $reflector->getProperty('pluginConfig');
                $pluginConfigProperty->setAccessible(true);

                $pluginConfigs = $pluginConfigProperty->getValue($widget);
                if (!is_array($pluginConfigs)) {
                    $pluginConfigs = array();
                }

                $pluginConfigs[(string)$pluginName] = self::buildPluginConfigObject($optionValue);
                $pluginConfigProperty->setValue($widget, $pluginConfigs);
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    public static function pluginUpdateStateOptionName()
    {
        return 'enh:update';
    }

    public static function readPluginUpdateState()
    {
        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('value')
                    ->from('table.options')
                    ->where('name = ?', self::pluginUpdateStateOptionName())
                    ->where('user = ?', 0)
                    ->limit(1)
            );

            if (is_array($row) && isset($row['value'])) {
                $decoded = json_decode((string)$row['value'], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Exception $e) {
            // ignore update state read errors
        }

        return array();
    }

    public static function savePluginUpdateState(array $state)
    {
        $value = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($value) || $value === '') {
            return false;
        }

        try {
            $db = Typecho_Db::get();
            $optionName = self::pluginUpdateStateOptionName();
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
                        ->rows(array('value' => $value))
                        ->where('name = ?', $optionName)
                        ->where('user = ?', 0)
                );
            } else {
                $db->query(
                    $db->insert('table.options')->rows(array(
                        'name' => $optionName,
                        'user' => 0,
                        'value' => $value
                    ))
                );
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function clearPluginUpdateState()
    {
        try {
            $db = Typecho_Db::get();
            $db->query(
                $db->delete('table.options')
                    ->where('name = ?', self::pluginUpdateStateOptionName())
                    ->where('user = ?', 0)
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function buildPluginConfigObject($optionValue)
    {
        $settings = self::decodePluginConfigValue($optionValue);

        if (class_exists('Typecho_Config')) {
            return new Typecho_Config($settings);
        }

        if (class_exists('Typecho\\Config')) {
            return new \Typecho\Config($settings);
        }

        return (object)$settings;
    }
}
