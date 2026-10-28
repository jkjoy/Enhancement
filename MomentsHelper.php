<?php

class Enhancement_MomentsHelper
{
    public static function extractMediaFromContent($content, &$cleanedContent = null)
    {
        if (!is_string($content) || $content === '') {
            $cleanedContent = is_string($content) ? $content : '';
            return array();
        }

        $cleanedContent = $content;
        $media = array();
        $seen = array();

        $addUrl = function ($url) use (&$media, &$seen) {
            $url = trim((string)$url);
            if ($url === '' || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;

            $path = parse_url($url, PHP_URL_PATH);
            $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
            $type = in_array($ext, array('mp4', 'webm', 'ogg', 'm4v', 'mov'), true) ? 'VIDEO' : 'PHOTO';

            $media[] = array(
                'type' => $type,
                'url' => $url
            );
        };

        if (preg_match_all('/!\\[[^\\]]*\\]\\(([^)]+)\\)/i', $content, $matches)) {
            foreach ($matches[1] as $raw) {
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }
                if ($raw[0] === '<' && substr($raw, -1) === '>') {
                    $raw = substr($raw, 1, -1);
                }
                $parts = preg_split('/\\s+/', $raw);
                $url = trim($parts[0], "\"'");
                $addUrl($url);
            }
            $cleanedContent = preg_replace('/!\\[[^\\]]*\\]\\(([^)]+)\\)/i', '', $cleanedContent);
        }

        if (preg_match_all('/<img[^>]+src=[\'"]?([^\'"\\s>]+)[\'"]?/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $addUrl($url);
            }
            $cleanedContent = preg_replace('/<img[^>]*>/i', '', $cleanedContent);
        }

        if (preg_match_all('/<video[^>]+src=[\'"]?([^\'"\\s>]+)[\'"]?/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $addUrl($url);
            }
        }

        if (preg_match_all('/<source[^>]+src=[\'"]?([^\'"\\s>]+)[\'"]?/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $addUrl($url);
            }
        }

        if (is_string($cleanedContent)) {
            $cleanedContent = str_replace(array("\r\n", "\r"), "\n", $cleanedContent);
            $cleanedContent = preg_replace("/[ \\t]+\\n/", "\n", $cleanedContent);
            $cleanedContent = preg_replace("/\\n{3,}/", "\n\n", $cleanedContent);
            $cleanedContent = trim($cleanedContent);
            if ($cleanedContent === '' && !empty($media)) {
                $settings = Enhancement_Plugin::runtimeSettings();
                $fallback = isset($settings->moments_image_text) ? trim((string)$settings->moments_image_text) : '';
                if ($fallback === '') {
                    $fallback = '图片';
                }
                $cleanedContent = $fallback;
            }
        }

        return $media;
    }

    public static function tencentMapKey(): string
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        return isset($settings->tencent_map_key) ? trim((string)$settings->tencent_map_key) : '';
    }

    public static function normalizeLatitude($latitude)
    {
        $latitude = trim((string)$latitude);
        if ($latitude === '' || !is_numeric($latitude)) {
            return null;
        }

        $value = floatval($latitude);
        if ($value < -90 || $value > 90) {
            return null;
        }

        return rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');
    }

    public static function normalizeLongitude($longitude)
    {
        $longitude = trim((string)$longitude);
        if ($longitude === '' || !is_numeric($longitude)) {
            return null;
        }

        $value = floatval($longitude);
        if ($value < -180 || $value > 180) {
            return null;
        }

        return rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');
    }

    public static function normalizeLocationAddress($address, $maxLength = 255)
    {
        $address = trim((string)$address);
        if ($address === '') {
            return null;
        }

        $maxLength = intval($maxLength);
        if ($maxLength <= 0) {
            $maxLength = 255;
        }

        if (Typecho_Common::strLen($address) > $maxLength) {
            $address = Typecho_Common::subStr($address, 0, $maxLength, '');
        }

        return $address;
    }

    public static function validateLatitude($latitude)
    {
        $latitude = trim((string)$latitude);
        if ($latitude === '') {
            return true;
        }

        return self::normalizeLatitude($latitude) !== null;
    }

    public static function validateLongitude($longitude)
    {
        $longitude = trim((string)$longitude);
        if ($longitude === '') {
            return true;
        }

        return self::normalizeLongitude($longitude) !== null;
    }

    public static function normalizeStatus($status, $default = 'public')
    {
        $allowed = array('public', 'private');
        $status = strtolower(trim((string)$status));
        if (!in_array($status, $allowed, true)) {
            $status = strtolower(trim((string)$default));
            if (!in_array($status, $allowed, true)) {
                $status = 'public';
            }
        }

        return $status;
    }

    public static function validateStatus($status)
    {
        $status = strtolower(trim((string)$status));
        return in_array($status, array('public', 'private'), true);
    }

    public static function normalizeSource($source, $default = 'web')
    {
        $allowed = array('web', 'mobile', 'api', 'qq', 'weixin');
        $source = strtolower(trim((string)$source));
        if (!in_array($source, $allowed, true)) {
            $source = strtolower(trim((string)$default));
            if (!in_array($source, $allowed, true)) {
                $source = 'web';
            }
        }

        return $source;
    }

    public static function detectSourceByUserAgent($userAgent = null)
    {
        if ($userAgent === null) {
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
        }

        $userAgent = strtolower(trim((string)$userAgent));
        if ($userAgent === '') {
            return 'web';
        }

        if (preg_match('/mobile|android|iphone|ipad|ipod|windows phone|mobi/i', $userAgent)) {
            return 'mobile';
        }

        return 'web';
    }

    public static function ensureSourceColumn()
    {
        self::ensureColumns(array(
            array(
                'name' => 'source',
                'Mysql' => "`source` varchar(20) DEFAULT 'web' AFTER `media`",
                'Pgsql' => "\"source\" varchar(20) DEFAULT 'web'",
                'SQLite' => "`source` varchar(20) DEFAULT 'web'"
            )
        ));
    }

    public static function ensureStatusColumn()
    {
        self::ensureColumns(array(
            array(
                'name' => 'status',
                'Mysql' => "`status` varchar(20) DEFAULT 'public' AFTER `source`",
                'Pgsql' => "\"status\" varchar(20) DEFAULT 'public'",
                'SQLite' => "`status` varchar(20) DEFAULT 'public'"
            )
        ));
    }

    public static function ensureLocationColumns()
    {
        self::ensureColumns(array(
            array(
                'name' => 'latitude',
                'Mysql' => "`latitude` varchar(20) DEFAULT NULL AFTER `status`",
                'Pgsql' => "\"latitude\" varchar(20) DEFAULT NULL",
                'SQLite' => "`latitude` varchar(20) DEFAULT NULL"
            ),
            array(
                'name' => 'longitude',
                'Mysql' => "`longitude` varchar(20) DEFAULT NULL AFTER `latitude`",
                'Pgsql' => "\"longitude\" varchar(20) DEFAULT NULL",
                'SQLite' => "`longitude` varchar(20) DEFAULT NULL"
            ),
            array(
                'name' => 'location_address',
                'Mysql' => "`location_address` varchar(255) DEFAULT NULL AFTER `longitude`",
                'Pgsql' => "\"location_address\" varchar(255) DEFAULT NULL",
                'SQLite' => "`location_address` varchar(255) DEFAULT NULL"
            )
        ));
    }

    public static function ensureTable()
    {
        $db = Typecho_Db::get();
        $type = explode('_', $db->getAdapterName());
        $type = array_pop($type);
        $prefix = $db->getPrefix();

        $scripts = @file_get_contents(__DIR__ . '/sql/' . $type . '.sql');
        if (!$scripts) {
            return;
        }
        $scripts = str_replace('typecho_', $prefix, $scripts);
        $scripts = str_replace('%charset%', 'utf8', $scripts);
        $scripts = explode(';', $scripts);

        foreach ($scripts as $script) {
            $script = trim($script);
            if ($script && stripos($script, $prefix . 'moments') !== false) {
                try {
                    $db->query($script, Typecho_Db::WRITE);
                } catch (Exception $e) {
                    // ignore create errors
                }
            }
        }

        self::ensureSourceColumn();
        self::ensureStatusColumn();
        self::ensureLocationColumns();
    }

    private static function migrationContext()
    {
        $db = Typecho_Db::get();
        $type = explode('_', $db->getAdapterName());
        $type = array_pop($type);

        return array($db, $type, $db->getPrefix() . 'moments');
    }

    private static function columnExists($db, $type, $table, $column, &$sqliteColumns = null)
    {
        if ('Mysql' === $type) {
            $row = $db->fetchRow('SHOW COLUMNS FROM `' . $table . '` LIKE \'' . $column . '\'');
            return is_array($row) && !empty($row);
        }

        if ('Pgsql' === $type) {
            $row = $db->fetchRow(
                $db->select('column_name')
                    ->from('information_schema.columns')
                    ->where('table_name = ?', $table)
                    ->where('column_name = ?', $column)
                    ->limit(1)
            );
            return is_array($row) && !empty($row);
        }

        if ('SQLite' === $type) {
            if (!is_array($sqliteColumns)) {
                $sqliteColumns = array();
                $rows = $db->fetchAll('PRAGMA table_info(`' . $table . '`)');
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $name = isset($row['name']) ? strtolower((string)$row['name']) : '';
                        if ($name !== '') {
                            $sqliteColumns[$name] = true;
                        }
                    }
                }
            }

            return isset($sqliteColumns[strtolower((string)$column)]);
        }

        return false;
    }

    private static function ensureColumns(array $definitions)
    {
        list($db, $type, $table) = self::migrationContext();
        $quotedTable = ('Pgsql' === $type) ? '"' . $table . '"' : '`' . $table . '`';

        try {
            $sqliteColumns = null;

            foreach ($definitions as $definition) {
                $column = isset($definition['name']) ? trim((string)$definition['name']) : '';
                if ($column === '' || self::columnExists($db, $type, $table, $column, $sqliteColumns)) {
                    continue;
                }

                $columnSql = isset($definition[$type]) ? trim((string)$definition[$type]) : '';
                if ($columnSql === '') {
                    continue;
                }

                $db->query('ALTER TABLE ' . $quotedTable . ' ADD COLUMN ' . $columnSql, Typecho_Db::WRITE);

                if ('SQLite' === $type && is_array($sqliteColumns)) {
                    $sqliteColumns[strtolower($column)] = true;
                }
            }
        } catch (Exception $e) {
            // ignore migration errors to avoid blocking runtime
        }
    }
}
