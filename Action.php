<?php

class Enhancement_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $db;
    private $options;
    private $prefix;

    private function normalizePluginSettings(array $settings)
    {
        $normalized = array();

        foreach ($settings as $key => $value) {
            $key = trim((string)$key);
            if ($key === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_null($value)) {
                $value = '';
            } elseif (is_scalar($value)) {
                $value = (string)$value;
            } elseif (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $value = ($encoded === false) ? '' : $encoded;
            } else {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function collectPluginSettings()
    {
        $settings = array();

        try {
            $row = $this->db->fetchRow(
                $this->db->select('value')
                    ->from('table.options')
                    ->where('name = ?', 'plugin:Enhancement')
                    ->where('user = ?', 0)
                    ->limit(1)
            );

            if (is_array($row) && isset($row['value'])) {
                $decoded = json_decode((string)$row['value'], true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }
        } catch (Exception $e) {
            // ignore db fallback errors
        }

        if (empty($settings)) {
            try {
                $options = Typecho_Widget::widget('Widget_Options');
                $plugin = $options->plugin('Enhancement');
                if (is_object($plugin) && method_exists($plugin, 'toArray')) {
                    $settings = $plugin->toArray();
                }
            } catch (Exception $e) {
                // ignore options fallback errors
            }
        }

        if (!is_array($settings)) {
            $settings = array();
        }

        return $this->normalizePluginSettings($settings);
    }

    private function parseBackupSettingsPayload($rawPayload, &$errorMessage = '')
    {
        $errorMessage = '';
        $rawPayload = trim((string)$rawPayload);
        if ($rawPayload === '') {
            $errorMessage = _t('备份内容为空');
            return null;
        }

        $decoded = json_decode($rawPayload, true);
        if (!is_array($decoded)) {
            $errorMessage = _t('备份文件不是有效的 JSON');
            return null;
        }

        if (isset($decoded['plugin'])) {
            $pluginName = trim((string)$decoded['plugin']);
            if ($pluginName !== '' && strcasecmp($pluginName, 'Enhancement') !== 0) {
                $errorMessage = _t('该备份文件不是 Enhancement 插件配置');
                return null;
            }
        }

        $settings = $decoded;
        if (isset($decoded['settings'])) {
            if (!is_array($decoded['settings'])) {
                $errorMessage = _t('备份文件 settings 字段格式错误');
                return null;
            }
            $settings = $decoded['settings'];
        }

        $settings = $this->normalizePluginSettings($settings);
        if (empty($settings)) {
            $errorMessage = _t('备份文件中没有可恢复的配置项');
            return null;
        }

        return $settings;
    }

    private function savePluginSettings(array $settings)
    {
        $settings = $this->normalizePluginSettings($settings);
        if (empty($settings)) {
            throw new Typecho_Widget_Exception(_t('没有可保存的配置项'));
        }

        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Typecho_Widget_Exception(_t('配置序列化失败'));
        }

        $row = $this->db->fetchRow(
            $this->db->select('name')
                ->from('table.options')
                ->where('name = ?', 'plugin:Enhancement')
                ->where('user = ?', 0)
                ->limit(1)
        );

        if (is_array($row) && !empty($row)) {
            $this->db->query(
                $this->db->update('table.options')
                    ->rows(array('value' => $json))
                    ->where('name = ?', 'plugin:Enhancement')
                    ->where('user = ?', 0)
            );
        } else {
            $this->db->query(
                $this->db->insert('table.options')->rows(array(
                    'name' => 'plugin:Enhancement',
                    'value' => $json,
                    'user' => 0
                ))
            );
        }
    }

    private function settingsBackupNamePrefix()
    {
        return 'plugin:Enhancement:backup:';
    }

    private function settingsBackupKeepCount()
    {
        return 20;
    }

    private function createSettingsBackupSnapshot(array $settings)
    {
        $settings = $this->normalizePluginSettings($settings);
        if (empty($settings)) {
            throw new Typecho_Widget_Exception(_t('没有可备份的配置项'));
        }

        $payload = array(
            'plugin' => 'Enhancement',
            'exported_at' => date('c'),
            'settings' => $settings
        );

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Typecho_Widget_Exception(_t('插件设置备份失败：JSON 编码异常'));
        }

        $snapshotName = $this->settingsBackupNamePrefix() . date('YmdHis') . '-' . Typecho_Common::randString(6);
        $this->db->query(
            $this->db->insert('table.options')->rows(array(
                'name' => $snapshotName,
                'value' => $json,
                'user' => 0
            ))
        );

        $this->pruneSettingsBackupSnapshots();
        return $snapshotName;
    }

    private function pruneSettingsBackupSnapshots()
    {
        $prefix = $this->settingsBackupNamePrefix();
        $keepCount = intval($this->settingsBackupKeepCount());
        if ($keepCount < 1) {
            $keepCount = 1;
        }

        $rows = $this->db->fetchAll(
            $this->db->select('name')
                ->from('table.options')
                ->where('name LIKE ?', $prefix . '%')
                ->where('user = ?', 0)
                ->order('name', Typecho_Db::SORT_DESC)
        );

        if (!is_array($rows) || count($rows) <= $keepCount) {
            return;
        }

        foreach ($rows as $index => $row) {
            if ($index < $keepCount) {
                continue;
            }

            $name = isset($row['name']) ? trim((string)$row['name']) : '';
            if ($name === '') {
                continue;
            }

            $this->db->query(
                $this->db->delete('table.options')
                    ->where('name = ?', $name)
                    ->where('user = ?', 0)
            );
        }
    }

    private function getLatestSettingsBackupSnapshot()
    {
        $prefix = $this->settingsBackupNamePrefix();

        return $this->db->fetchRow(
            $this->db->select('name', 'value')
                ->from('table.options')
                ->where('name LIKE ?', $prefix . '%')
                ->where('user = ?', 0)
                ->order('name', Typecho_Db::SORT_DESC)
                ->limit(1)
        );
    }

    private function countSettingsBackupSnapshots()
    {
        $prefix = $this->settingsBackupNamePrefix();

        $row = $this->db->fetchObject(
            $this->db->select(array('COUNT(name)' => 'num'))
                ->from('table.options')
                ->where('name LIKE ?', $prefix . '%')
                ->where('user = ?', 0)
        );

        return isset($row->num) ? intval($row->num) : 0;
    }

    private function isValidSettingsBackupName($name)
    {
        $name = trim((string)$name);
        if ($name === '') {
            return false;
        }

        $prefix = $this->settingsBackupNamePrefix();
        if (strpos($name, $prefix) !== 0) {
            return false;
        }

        return preg_match('/^plugin:Enhancement:backup:\d{14}-[A-Za-z0-9]+$/', $name) === 1;
    }

    private function getSettingsBackupSnapshotByName($name)
    {
        $name = trim((string)$name);
        if (!$this->isValidSettingsBackupName($name)) {
            return null;
        }

        return $this->db->fetchRow(
            $this->db->select('name', 'value')
                ->from('table.options')
                ->where('name = ?', $name)
                ->where('user = ?', 0)
                ->limit(1)
        );
    }

    private function backupResponse($success, $message, $statusCode = 200)
    {
        $statusCode = intval($statusCode);
        if ($statusCode > 0) {
            $this->response->setStatus($statusCode);
        }

        if ($this->request->isAjax()) {
            $this->response->throwJson(array(
                'success' => (bool)$success,
                'message' => (string)$message
            ));
            return;
        }

        $this->widget('Widget_Notice')->set(
            (string)$message,
            null,
            $success ? 'success' : 'error'
        );
        $this->response->goBack();
    }

    private function qqTestResponse($success, $message, $statusCode = 200)
    {
        $statusCode = intval($statusCode);
        if ($statusCode > 0) {
            $this->response->setStatus($statusCode);
        }

        if ($this->request->isAjax()) {
            $this->response->throwJson(array(
                'success' => (bool)$success,
                'message' => (string)$message
            ));
            return;
        }

        $this->widget('Widget_Notice')->set(
            (string)$message,
            null,
            $success ? 'success' : 'error'
        );
        $this->response->goBack();
    }

    private function qqQueueResponse($success, $message, $statusCode = 200)
    {
        $statusCode = intval($statusCode);
        if ($statusCode > 0) {
            $this->response->setStatus($statusCode);
        }

        if ($this->request->isAjax()) {
            $this->response->throwJson(array(
                'success' => (bool)$success,
                'message' => (string)$message
            ));
            return;
        }

        $this->widget('Widget_Notice')->set(
            (string)$message,
            null,
            $success ? 'success' : 'error'
        );
        $this->response->goBack();
    }

    private function uploadResponse($success, $message, $statusCode = 200)
    {
        $statusCode = intval($statusCode);
        if ($statusCode > 0) {
            $this->response->setStatus($statusCode);
        }

        if ($this->request->isAjax()) {
            $this->response->throwJson(array(
                'success' => (bool)$success,
                'message' => (string)$message
            ));
            return;
        }

        $this->widget('Widget_Notice')->set(
            (string)$message,
            null,
            $success ? 'success' : 'error'
        );
        $this->response->goBack();
    }

    private function removeDirectoryRecursively($path)
    {
        if (!is_dir($path)) {
            return false;
        }

        $items = @scandir($path);
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $current = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($current)) {
                if (!$this->removeDirectoryRecursively($current)) {
                    return false;
                }
            } else {
                if (!@unlink($current)) {
                    return false;
                }
            }
        }

        return @rmdir($path);
    }

    private function isZipFile($file)
    {
        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return false;
        }

        $bin = fread($fp, 4);
        fclose($fp);

        return strtolower(bin2hex($bin)) === '504b0304';
    }

    private function parseUploadPluginInfo($content)
    {
        $info = array('name' => '', 'title' => '', 'version' => '', 'author' => '');

        $tokens = token_get_all($content);
        $isDoc = false;
        $isClass = false;

        foreach ($tokens as $token) {
            if (!$isDoc && is_array($token) && $token[0] == T_DOC_COMMENT) {
                $lines = preg_split('/\r\n|\r|\n/', $token[1]);
                foreach ($lines as $line) {
                    $line = trim($line, " \t/*");
                    if (preg_match('/@package\s+(.+)/', $line, $matches)) {
                        $info['title'] = trim($matches[1]);
                    } else if (preg_match('/@version\s+(.+)/', $line, $matches)) {
                        $info['version'] = trim($matches[1]);
                    } else if (preg_match('/@author\s+(.+)/', $line, $matches)) {
                        $info['author'] = trim($matches[1]);
                    }
                }
                $isDoc = true;
            }

            if (!$isClass && is_array($token) && $token[0] == T_CLASS) {
                $isClass = true;
            }

            if ($isClass && is_array($token) && $token[0] == T_STRING) {
                $parts = explode('_', $token[1]);
                $info['name'] = $parts[0];
                break;
            }
        }

        return $info;
    }

    private function isThemeIndexFile($content)
    {
        $tokens = token_get_all($content);
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] == T_DOC_COMMENT) {
                return (strpos($token[1], '@package') !== false);
            }
        }
        return false;
    }

    public function uploadPackage()
    {
        $this->widget('Widget_User')->pass('administrator');

        if (!class_exists('ZipArchive')) {
            $this->uploadResponse(false, _t('当前环境不支持 ZipArchive，无法上传安装'), 500);
            return;
        }

        if (!isset($_FILES['pluginzip']) || intval($_FILES['pluginzip']['error']) !== 0) {
            $this->uploadResponse(false, _t('文件上传失败'), 400);
            return;
        }

        $file = $_FILES['pluginzip'];
        $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $this->uploadResponse(false, _t('无效的上传文件'), 400);
            return;
        }

        if (!$this->isZipFile($tmp)) {
            $this->uploadResponse(false, _t('上传文件不是有效ZIP压缩包'), 400);
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            $this->uploadResponse(false, _t('无法打开ZIP文件，可能已损坏'), 400);
            return;
        }

        $pluginDir = defined('__TYPECHO_PLUGIN_DIR__') ? __TYPECHO_PLUGIN_DIR__ : '/usr/plugins';
        $themeDir = defined('__TYPECHO_THEME_DIR__') ? __TYPECHO_THEME_DIR__ : '/usr/themes';
        $rootDir = defined('__TYPECHO_ROOT_DIR__') ? __TYPECHO_ROOT_DIR__ : dirname(dirname(dirname(__FILE__)));

        $targetBase = '';
        $typeLabel = '';

        $pluginIndex = $zip->locateName('Plugin.php', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR);
        if ($pluginIndex !== false) {
            $typeLabel = _t('插件');
            $fileName = $zip->getNameIndex($pluginIndex);
            $pathParts = explode('/', str_replace('\\', '/', (string)$fileName));

            if (count($pathParts) > 2) {
                $zip->close();
                $this->uploadResponse(false, _t('压缩包目录层级过深，无法安装'), 400);
                return;
            }

            if (count($pathParts) == 2) {
                $targetBase = rtrim($rootDir . $pluginDir, '/\\') . DIRECTORY_SEPARATOR;
            } else {
                $contents = $zip->getFromIndex($pluginIndex);
                $pluginInfo = $this->parseUploadPluginInfo((string)$contents);
                if (empty($pluginInfo['name'])) {
                    $zip->close();
                    $this->uploadResponse(false, _t('无法识别插件信息'), 400);
                    return;
                }
                $targetBase = rtrim($rootDir . $pluginDir, '/\\') . DIRECTORY_SEPARATOR . $pluginInfo['name'] . DIRECTORY_SEPARATOR;
            }
        } else {
            $themeIndex = $zip->locateName('index.php', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR);
            if ($themeIndex === false) {
                $zip->close();
                $this->uploadResponse(false, _t('上传文件不是有效Typecho插件或主题'), 400);
                return;
            }

            $typeLabel = _t('主题');
            $fileName = $zip->getNameIndex($themeIndex);
            $pathParts = explode('/', str_replace('\\', '/', (string)$fileName));
            if (count($pathParts) > 2) {
                $zip->close();
                $this->uploadResponse(false, _t('压缩包目录层级过深，无法安装'), 400);
                return;
            }

            $contents = $zip->getFromIndex($themeIndex);
            if (!$this->isThemeIndexFile((string)$contents)) {
                $zip->close();
                $this->uploadResponse(false, _t('无法识别主题信息'), 400);
                return;
            }

            if (count($pathParts) == 2) {
                $targetBase = rtrim($rootDir . $themeDir, '/\\') . DIRECTORY_SEPARATOR;
            } else {
                $themeName = pathinfo(isset($file['name']) ? (string)$file['name'] : 'theme', PATHINFO_FILENAME);
                $themeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $themeName);
                if ($themeName === '') {
                    $themeName = 'theme';
                }
                $targetBase = rtrim($rootDir . $themeDir, '/\\') . DIRECTORY_SEPARATOR . $themeName . DIRECTORY_SEPARATOR;
            }
        }

        if ($targetBase === '') {
            $zip->close();
            $this->uploadResponse(false, _t('未找到可安装目标目录'), 400);
            return;
        }

        if (!is_dir($targetBase)) {
            @mkdir($targetBase, 0755, true);
        }
        if (!is_dir($targetBase) || !is_writable($targetBase)) {
            $zip->close();
            $this->uploadResponse(false, _t('目标目录不可写，请检查权限：%s', $targetBase), 500);
            return;
        }

        if (!$zip->extractTo($targetBase)) {
            $zip->close();
            $this->uploadResponse(false, _t('解压失败，请检查目录写入权限'), 500);
            return;
        }

        $zip->close();
        $this->uploadResponse(true, _t('%s安装成功，请到控制台启用', $typeLabel), 200);
    }

    public function deletePluginPackage()
    {
        $this->widget('Widget_User')->pass('administrator');
        $name = trim((string)$this->request->get('name'));
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            $this->uploadResponse(false, _t('插件名称不合法'), 400);
            return;
        }

        $pluginDir = defined('__TYPECHO_PLUGIN_DIR__') ? __TYPECHO_PLUGIN_DIR__ : '/usr/plugins';
        $rootDir = defined('__TYPECHO_ROOT_DIR__') ? __TYPECHO_ROOT_DIR__ : dirname(dirname(dirname(__FILE__)));
        $path = rtrim($rootDir . $pluginDir, '/\\') . DIRECTORY_SEPARATOR . $name;

        if (!is_dir($path)) {
            $file = $path . '.php';
            if (is_file($file) && @unlink($file)) {
                $this->uploadResponse(true, _t('插件已删除：%s', $name), 200);
                return;
            }
            $this->uploadResponse(false, _t('插件不存在：%s', $name), 404);
            return;
        }

        if ($this->removeDirectoryRecursively($path)) {
            $this->uploadResponse(true, _t('插件已删除：%s', $name), 200);
            return;
        }

        $this->uploadResponse(false, _t('插件删除失败：%s', $name), 500);
    }

    public function deleteThemePackage()
    {
        $this->widget('Widget_User')->pass('administrator');
        $name = trim((string)$this->request->get('name'));
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            $this->uploadResponse(false, _t('主题名称不合法'), 400);
            return;
        }

        $themeDir = defined('__TYPECHO_THEME_DIR__') ? __TYPECHO_THEME_DIR__ : '/usr/themes';
        $rootDir = defined('__TYPECHO_ROOT_DIR__') ? __TYPECHO_ROOT_DIR__ : dirname(dirname(dirname(__FILE__)));
        $path = rtrim($rootDir . $themeDir, '/\\') . DIRECTORY_SEPARATOR . $name;

        if (!is_dir($path)) {
            $this->uploadResponse(false, _t('主题不存在：%s', $name), 404);
            return;
        }

        if ($this->removeDirectoryRecursively($path)) {
            $this->uploadResponse(true, _t('主题已删除：%s', $name), 200);
            return;
        }

        $this->uploadResponse(false, _t('主题删除失败：%s', $name), 500);
    }

    public function retryQqNotifyQueue()
    {
        try {
            $table = $this->prefix . 'qq_notify_queue';
            $affected = $this->db->query(
                $this->db->update($table)
                    ->rows(array(
                        'status' => 0,
                        'updated' => time()
                    ))
                    ->where('status = ?', 2)
            );

            $this->qqQueueResponse(true, _t('已将 %d 条失败记录标记为待重试', intval($affected)), 200);
        } catch (Exception $e) {
            $this->qqQueueResponse(false, _t('重试失败：%s', $e->getMessage()), 500);
        }
    }

    public function clearQqNotifyQueue()
    {
        try {
            $table = $this->prefix . 'qq_notify_queue';
            $this->db->query($this->db->delete($table));
            $this->qqQueueResponse(true, _t('QQ通知队列已清空'), 200);
        } catch (Exception $e) {
            $this->qqQueueResponse(false, _t('清空失败：%s', $e->getMessage()), 500);
        }
    }

    public function sendQqTestNotify()
    {
        $settings = $this->collectPluginSettings();
        $apiUrl = isset($settings['qqboturl']) ? trim((string)$settings['qqboturl']) : '';
        $qqNum = isset($settings['qq']) ? trim((string)$settings['qq']) : '';

        if ($apiUrl === '' || $qqNum === '') {
            $this->qqTestResponse(false, _t('QQ通知测试失败：请先填写 QQ 号 与 机器人 API 地址'), 400);
            return;
        }

        if (!function_exists('curl_init')) {
            $this->qqTestResponse(false, _t('QQ通知测试失败：当前环境缺少 cURL 扩展'), 500);
            return;
        }

        $siteUrl = isset($this->options->siteUrl) ? trim((string)$this->options->siteUrl) : '';
        $message = sprintf(
            "【QQ通知测试】\n"
            . "站点：%s\n"
            . "时间：%s\n"
            . "如果收到此消息，说明 QQ 通知配置可用。",
            $siteUrl !== '' ? $siteUrl : 'unknown',
            date('Y-m-d H:i:s')
        );

        $payload = array(
            'user_id' => (int)$qqNum,
            'message' => $message
        );

        $endpoint = rtrim($apiUrl, '/') . '/send_msg';
        $ch = curl_init();
        $curlOptions = array(
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json'
            ),
            CURLOPT_SSL_VERIFYPEER => false
        );

        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        if (defined('CURLOPT_NOSIGNAL')) {
            $curlOptions[CURLOPT_NOSIGNAL] = true;
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->qqTestResponse(false, _t('QQ通知测试失败：%s', $error), 500);
            return;
        }

        curl_close($ch);

        $decoded = json_decode((string)$response, true);
        $isOk = ($httpCode >= 200 && $httpCode < 300);
        if (is_array($decoded)) {
            if (isset($decoded['status'])) {
                $isOk = $isOk && strtolower((string)$decoded['status']) === 'ok';
            }
            if (isset($decoded['retcode'])) {
                $isOk = $isOk && intval($decoded['retcode']) === 0;
            }
        }

        if ($isOk) {
            $this->qqTestResponse(true, _t('QQ通知测试发送成功，请检查 QQ 是否收到消息。'), 200);
            return;
        }

        $bodyPreview = substr(trim((string)$response), 0, 300);
        if ($bodyPreview === '') {
            $bodyPreview = _t('empty response');
        }
        $this->qqTestResponse(false, _t('QQ通知测试失败（HTTP %d）：%s', $httpCode, $bodyPreview), 500);
    }

    public function backupPluginSettings()
    {
        $settings = $this->collectPluginSettings();
        try {
            $snapshotName = $this->createSettingsBackupSnapshot($settings);
            $total = $this->countSettingsBackupSnapshots();
            $this->backupResponse(true, _t('插件设置已备份到数据库（%s），当前共有 %d 份备份', $snapshotName, $total), 200);
        } catch (Exception $e) {
            $this->backupResponse(false, _t('插件设置备份失败：%s', $e->getMessage()), 500);
        }
    }

    public function restorePluginSettings()
    {
        $backupName = trim((string)$this->request->get('backup_name'));
        if ($backupName !== '' && !$this->isValidSettingsBackupName($backupName)) {
            $this->backupResponse(false, _t('备份标识格式不正确'), 400);
            return;
        }

        if ($backupName !== '') {
            $backupRow = $this->getSettingsBackupSnapshotByName($backupName);
            if (!is_array($backupRow) || empty($backupRow)) {
                $this->backupResponse(false, _t('数据库中没有找到指定备份，请先执行一次备份'), 400);
                return;
            }

            $rawPayload = isset($backupRow['value']) ? (string)$backupRow['value'] : '';
            $errorMessage = '';
            $settings = $this->parseBackupSettingsPayload($rawPayload, $errorMessage);
            if (!is_array($settings)) {
                $this->backupResponse(false, $errorMessage !== '' ? $errorMessage : _t('数据库备份内容解析失败'), 400);
                return;
            }

            try {
                $this->savePluginSettings($settings);
            } catch (Exception $e) {
                $this->backupResponse(false, _t('恢复失败：%s', $e->getMessage()), 500);
                return;
            }

            $this->backupResponse(true, _t('已从数据库备份恢复成功（%s），共恢复 %d 项配置', $backupName, count($settings)), 200);
            return;
        }

        $backupRow = $this->getLatestSettingsBackupSnapshot();
        if (!is_array($backupRow) || empty($backupRow)) {
            $this->backupResponse(false, _t('数据库中暂无可恢复的设置备份，请先执行一次备份'), 400);
            return;
        }

        $rawPayload = isset($backupRow['value']) ? (string)$backupRow['value'] : '';
        $errorMessage = '';
        $settings = $this->parseBackupSettingsPayload($rawPayload, $errorMessage);
        if (!is_array($settings)) {
            $this->backupResponse(false, $errorMessage !== '' ? $errorMessage : _t('数据库备份内容解析失败'), 400);
            return;
        }

        try {
            $this->savePluginSettings($settings);
        } catch (Exception $e) {
            $this->backupResponse(false, _t('恢复失败：%s', $e->getMessage()), 500);
            return;
        }

        $backupName = isset($backupRow['name']) ? (string)$backupRow['name'] : '';
        $this->backupResponse(true, _t('已从数据库备份恢复成功（%s），共恢复 %d 项配置', $backupName, count($settings)), 200);
    }

    public function deletePluginSettingsBackup()
    {
        $backupName = trim((string)$this->request->get('backup_name'));
        if (!$this->isValidSettingsBackupName($backupName)) {
            $this->backupResponse(false, _t('备份标识格式不正确'), 400);
            return;
        }

        $backupRow = $this->getSettingsBackupSnapshotByName($backupName);
        if (!is_array($backupRow) || empty($backupRow)) {
            $this->backupResponse(false, _t('未找到要删除的备份记录'), 404);
            return;
        }

        try {
            $this->db->query(
                $this->db->delete('table.options')
                    ->where('name = ?', $backupName)
                    ->where('user = ?', 0)
            );
            $total = $this->countSettingsBackupSnapshots();
            $this->backupResponse(true, _t('备份已删除（%s），当前剩余 %d 份', $backupName, $total), 200);
        } catch (Exception $e) {
            $this->backupResponse(false, _t('删除备份失败：%s', $e->getMessage()), 500);
        }
    }

    private function normalizeUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        $parts = @parse_url($url);
        if ($parts === false || !is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : 'http';
        $host = strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        $path = isset($parts['path']) ? (string)$parts['path'] : '/';
        $query = isset($parts['query']) ? (string)$parts['query'] : '';

        if ($path === '') {
            $path = '/';
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }

        $normalized = $scheme . '://' . $host;
        if ($port && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $normalized .= ':' . $port;
        }
        $normalized .= $path;
        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    private function getClientIpAddress()
    {
        $ip = trim((string)$this->request->getIp());
        if ($ip === '') {
            return '0.0.0.0';
        }
        return $ip;
    }

    private function getRateLimitWindowSeconds()
    {
        return 300;
    }

    private function getRateLimitMaxAttempts()
    {
        return 5;
    }

    private function getRateLimitStorePath()
    {
        return __DIR__ . '/runtime/rate-limit-links.json';
    }

    private function checkSubmitRateLimit(&$retryAfter = 0)
    {
        $file = $this->getRateLimitStorePath();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $window = $this->getRateLimitWindowSeconds();
        $maxAttempts = $this->getRateLimitMaxAttempts();
        $now = time();
        $ip = $this->getClientIpAddress();

        $records = array();
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $records = $decoded;
            }
        }

        foreach ($records as $recordIp => $timestamps) {
            if (!is_array($timestamps)) {
                unset($records[$recordIp]);
                continue;
            }
            $records[$recordIp] = array_values(array_filter($timestamps, function ($timestamp) use ($now, $window) {
                $timestamp = (int)$timestamp;
                return $timestamp > 0 && ($now - $timestamp) < $window;
            }));
            if (empty($records[$recordIp])) {
                unset($records[$recordIp]);
            }
        }

        $attempts = isset($records[$ip]) && is_array($records[$ip]) ? count($records[$ip]) : 0;
        if ($attempts >= $maxAttempts) {
            $oldest = isset($records[$ip][0]) ? (int)$records[$ip][0] : $now;
            $retryAfter = max(1, $window - ($now - $oldest));
            @file_put_contents($file, json_encode($records, JSON_UNESCAPED_UNICODE));
            return false;
        }

        if (!isset($records[$ip]) || !is_array($records[$ip])) {
            $records[$ip] = array();
        }
        $records[$ip][] = $now;
        @file_put_contents($file, json_encode($records, JSON_UNESCAPED_UNICODE));
        return true;
    }

    private function denySubmission($message, $statusCode = 400, $retryAfter = 0)
    {
        $message = (string)$message;
        $statusCode = (int)$statusCode;
        if ($statusCode > 0) {
            $this->response->setStatus($statusCode);
        }
        if ($retryAfter > 0) {
            $this->response->setHeader('Retry-After', (string)(int)$retryAfter);
        }

        if ($this->request->isAjax()) {
            $this->response->throwJson(array(
                'success' => false,
                'message' => $message
            ));
            return;
        }

        $this->widget('Widget_Notice')->set($message, null, 'error');
        $this->response->goBack();
    }

    private function sanitizePublicLinkItem(array $item)
    {
        $item['url'] = $this->normalizeUrl(isset($item['url']) ? $item['url'] : '');
        $item['image'] = $this->normalizeUrl(isset($item['image']) ? $item['image'] : '');
        $item['email'] = trim((string)(isset($item['email']) ? $item['email'] : ''));
        $item['name'] = trim((string)(isset($item['name']) ? $item['name'] : ''));
        $item['sort'] = trim((string)(isset($item['sort']) ? $item['sort'] : ''));
        $item['description'] = trim((string)(isset($item['description']) ? $item['description'] : ''));
        $item['user'] = trim((string)(isset($item['user']) ? $item['user'] : ''));

        return $item;
    }

    private function findExistingLinkByUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        return $this->db->fetchRow(
            $this->db->select('lid', 'state')
                ->from($this->prefix . 'links')
                ->where('url = ?', $url)
                ->limit(1)
        );
    }

    public function insertEnhancement()
    {
        if (Enhancement_Plugin::form('insert')->validate()) {
            $this->response->goBack();
        }
        /** 取出数据 */
        $item = $this->request->from('email', 'image', 'url', 'state');

        /** 过滤XSS */
        $item['name'] = $this->request->filter('xss')->name;
        $item['sort'] = $this->request->filter('xss')->sort;
        $item['description'] = $this->request->filter('xss')->description;
        $item['user'] = $this->request->filter('xss')->user;

        $maxOrder = $this->db->fetchObject(
            $this->db->select(array('MAX(order)' => 'maxOrder'))->from($this->prefix . 'links')
        )->maxOrder;
        $item['order'] = intval($maxOrder) + 1;

        /** 插入数据 */
        $item_lid = $this->db->query($this->db->insert($this->prefix . 'links')->rows($item));

        /** 设置高亮 */
        $this->widget('Widget_Notice')->highlight('enhancement-' . $item_lid);

        /** 提示信息 */
        $this->widget('Widget_Notice')->set(_t(
            '友链 <a href="%s">%s</a> 已经被增加',
            $item['url'],
            $item['name']
        ), null, 'success');

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function submitEnhancement()
    {
        if (Enhancement_Plugin::publicForm()->validate()) {
            $this->response->goBack();
        }

        if (Enhancement_Plugin::turnstileEnabled()) {
            $turnstileToken = $this->request->get('cf-turnstile-response');
            $verify = Enhancement_Plugin::turnstileVerify($turnstileToken, $this->request->getIp());
            if (empty($verify['success'])) {
                $message = isset($verify['message']) ? (string)$verify['message'] : _t('人机验证失败');
                $this->denySubmission($message, 403);
                return;
            }
        }

        $honeypot = trim((string)$this->request->get('homepage'));
        if ($honeypot !== '') {
            $this->denySubmission(_t('提交失败，请重试'), 400);
            return;
        }

        $retryAfter = 0;
        if (!$this->checkSubmitRateLimit($retryAfter)) {
            $this->denySubmission(_t('提交过于频繁，请稍后再试'), 429, $retryAfter);
            return;
        }

        /** 取出数据 */
        $item = $this->request->from('email', 'image', 'url');

        /** 过滤XSS */
        $item['name'] = $this->request->filter('xss')->name;
        $item['sort'] = $this->request->filter('xss')->sort;
        $item['description'] = $this->request->filter('xss')->description;
        $item['user'] = $this->request->filter('xss')->user;
        $item = $this->sanitizePublicLinkItem($item);

        if (!Enhancement_Plugin::validateHttpUrl($item['url'])) {
            $this->denySubmission(_t('友链地址仅支持 http:// 或 https://'));
            return;
        }
        if (!Enhancement_Plugin::validateOptionalHttpUrl($item['image'])) {
            $this->denySubmission(_t('友链图片仅支持 http:// 或 https://'));
            return;
        }

        $exists = $this->findExistingLinkByUrl($item['url']);
        if ($exists) {
            $message = ((string)$exists['state'] === '1')
                ? _t('该友链已存在，无需重复提交')
                : _t('该友链已提交，正在审核中');

            if ($this->request->isAjax()) {
                $this->response->throwJson(array(
                    'success' => true,
                    'message' => $message,
                    'duplicate' => true,
                    'lid' => (int)$exists['lid']
                ));
            } else {
                $this->widget('Widget_Notice')->set($message, null, 'notice');
                $this->response->goBack('?enhancement_submitted=1');
            }
            return;
        }

        $maxOrder = $this->db->fetchObject(
            $this->db->select(array('MAX(order)' => 'maxOrder'))->from($this->prefix . 'links')
        )->maxOrder;
        $item['order'] = intval($maxOrder) + 1;
        $item['state'] = '0';

        /** 插入数据 */
        $item_lid = $this->db->query($this->db->insert($this->prefix . 'links')->rows($item));

        if ($this->request->isAjax()) {
            $this->response->throwJson(array(
                'success' => true,
                'message' => _t('提交成功，等待审核'),
                'lid' => $item_lid
            ));
        } else {
            $this->response->goBack('?enhancement_submitted=1');
        }
    }

    public function updateEnhancement()
    {
        if (Enhancement_Plugin::form('update')->validate()) {
            $this->response->goBack();
        }

        /** 取出数据 */
        $item = $this->request->from('email', 'image', 'url', 'state');
        $item_lid = $this->request->get('lid');

        /** 过滤XSS */
        $item['name'] = $this->request->filter('xss')->name;
        $item['sort'] = $this->request->filter('xss')->sort;
        $item['description'] = $this->request->filter('xss')->description;
        $item['user'] = $this->request->filter('xss')->user;

        /** 更新数据 */
        $this->db->query($this->db->update($this->prefix . 'links')->rows($item)->where('lid = ?', $item_lid));

        /** 设置高亮 */
        $this->widget('Widget_Notice')->highlight('enhancement-' . $item_lid);

        /** 提示信息 */
        $this->widget('Widget_Notice')->set(_t(
            '友链 <a href="%s">%s</a> 已经被更新',
            $item['url'],
            $item['name']
        ), null, 'success');

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function deleteEnhancement()
    {
        $lids = $this->request->filter('int')->getArray('lid');
        $deleteCount = 0;
        if ($lids && is_array($lids)) {
            foreach ($lids as $lid) {
                if ($this->db->query($this->db->delete($this->prefix . 'links')->where('lid = ?', $lid))) {
                    $deleteCount++;
                }
            }
        }
        /** 提示信息 */
        $this->widget('Widget_Notice')->set(
            $deleteCount > 0 ? _t('记录已经删除') : _t('没有记录被删除'),
            null,
            $deleteCount > 0 ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function approveEnhancement()
    {
        $lids = $this->request->filter('int')->getArray('lid');
        $approveCount = 0;
        if ($lids && is_array($lids)) {
            foreach ($lids as $lid) {
                if ($this->db->query($this->db->update($this->prefix . 'links')->rows(array('state' => '1'))->where('lid = ?', $lid))) {
                    $approveCount++;
                }
            }
        }
        /** 提示信息 */
        $this->widget('Widget_Notice')->set(
            $approveCount > 0 ? _t('已通过审核') : _t('没有记录被通过'),
            null,
            $approveCount > 0 ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function rejectEnhancement()
    {
        $lids = $this->request->filter('int')->getArray('lid');
        $rejectCount = 0;
        if ($lids && is_array($lids)) {
            foreach ($lids as $lid) {
                if ($this->db->query($this->db->update($this->prefix . 'links')->rows(array('state' => '0'))->where('lid = ?', $lid))) {
                    $rejectCount++;
                }
            }
        }
        /** 提示信息 */
        $this->widget('Widget_Notice')->set(
            $rejectCount > 0 ? _t('已驳回') : _t('没有记录被驳回'),
            null,
            $rejectCount > 0 ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-enhancement.php', $this->options->adminUrl));
    }

    public function sortEnhancement()
    {
        $items = $this->request->filter('int')->getArray('lid');
        if ($items && is_array($items)) {
            foreach ($items as $sort => $lid) {
                $this->db->query($this->db->update($this->prefix . 'links')->rows(array('order' => $sort + 1))->where('lid = ?', $lid));
            }
        }
    }

    public function emailLogo()
    {
        /* 邮箱头像解API接口 by 懵仙兔兔 */
        $type = $this->request->type;
        $email = trim((string)$this->request->email);

        if ($email == null || $email == '') {
            $this->response->throwJson('请提交邮箱链接 [email=abc@abc.com]');
            exit;
        } else if ($type == null || $type == '' || ($type != 'txt' && $type != 'json')) {
            $this->response->throwJson('请提交type类型 [type=txt, type=json]');
            exit;
        } else {
            $lower = strtolower($email);
            $qqNumber = null;
            if (is_numeric($email)) {
                $qqNumber = $email;
            } elseif (substr($lower, -7) === '@qq.com') {
                $qqNumber = substr($lower, 0, -7);
            }

            if ($qqNumber !== null && is_numeric($qqNumber) && strlen($qqNumber) < 11 && strlen($qqNumber) > 4) {
                stream_context_set_default([
                    'ssl' => [
                        'verify_host' => false,
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                ]);
                $geturl = 'https://s.p.qq.com/pub/get_face?img_type=3&uin=' . $qqNumber;
                $headers = get_headers($geturl, TRUE);
                if ($headers) {
                    $g = $headers['Location'];
                    $g = str_replace("http:", "https:", $g);
                } else {
                    $g = 'https://q.qlogo.cn/g?b=qq&nk=' . $qqNumber . '&s=100';
                }
            } else {
                $g = Enhancement_Plugin::buildAvatarUrl($email, 100, null);
            }
            $r = array('url' => $g);
            if ($type == 'txt') {
                $this->response->throwJson($g);
                exit;
            } else if ($type == 'json') {
                $this->response->throwJson(json_encode($r));
                exit;
            }
        }
    }

    public function insertMoment()
    {
        if (Enhancement_Plugin::momentsForm('insert')->validate()) {
            $this->response->goBack();
        }

        Enhancement_Plugin::ensureMomentsTable();

        $moment = array();
        $moment['content'] = (string)$this->request->get('content');
        $moment['tags'] = $this->request->filter('xss')->tags;
        $moment['source'] = Enhancement_Plugin::detectMomentSourceByUserAgent($this->request->getServer('HTTP_USER_AGENT'));
        $moment['created'] = $this->options->time;
        $mediaRaw = $this->request->get('media');
        $mediaRaw = is_string($mediaRaw) ? trim($mediaRaw) : $mediaRaw;
        if (empty($mediaRaw)) {
            $cleanedContent = $moment['content'];
            $mediaItems = Enhancement_Plugin::extractMediaFromContent($moment['content'], $cleanedContent);
            $moment['media'] = !empty($mediaItems) ? json_encode($mediaItems, JSON_UNESCAPED_UNICODE) : null;
            $moment['content'] = $cleanedContent;
        } else {
            $moment['media'] = $mediaRaw;
        }

        try {
            $mid = $this->db->query($this->db->insert($this->prefix . 'moments')->rows($moment));
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set(_t('瞬间发布失败，可能是数据表不存在'), null, 'error');
            $this->response->goBack();
        }

        $this->widget('Widget_Notice')->highlight('moment-' . $mid);
        $this->widget('Widget_Notice')->set(_t('瞬间已发布'), null, 'success');
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
    }

    public function updateMoment()
    {
        if (Enhancement_Plugin::momentsForm('update')->validate()) {
            $this->response->goBack();
        }

        Enhancement_Plugin::ensureMomentsTable();

        $moment = array();
        $moment['content'] = (string)$this->request->get('content');
        $moment['tags'] = $this->request->filter('xss')->tags;
        $mid = $this->request->get('mid');
        $mediaRaw = $this->request->get('media');
        $mediaRaw = is_string($mediaRaw) ? trim($mediaRaw) : $mediaRaw;
        if (empty($mediaRaw)) {
            $cleanedContent = $moment['content'];
            $mediaItems = Enhancement_Plugin::extractMediaFromContent($moment['content'], $cleanedContent);
            $moment['media'] = !empty($mediaItems) ? json_encode($mediaItems, JSON_UNESCAPED_UNICODE) : null;
            $moment['content'] = $cleanedContent;
        } else {
            $moment['media'] = $mediaRaw;
        }

        try {
            $this->db->query($this->db->update($this->prefix . 'moments')->rows($moment)->where('mid = ?', $mid));
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set(_t('瞬间更新失败，可能是数据表不存在'), null, 'error');
            $this->response->goBack();
        }

        $this->widget('Widget_Notice')->highlight('moment-' . $mid);
        $this->widget('Widget_Notice')->set(_t('瞬间已更新'), null, 'success');
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
    }

    public function deleteMoment()
    {
        $mids = $this->request->filter('int')->getArray('mid');
        $deleteCount = 0;
        if ($mids && is_array($mids)) {
            foreach ($mids as $mid) {
                try {
                    if ($this->db->query($this->db->delete($this->prefix . 'moments')->where('mid = ?', $mid))) {
                        $deleteCount++;
                    }
                } catch (Exception $e) {
                    // ignore delete errors
                }
            }
        }
        $this->widget('Widget_Notice')->set(
            $deleteCount > 0 ? _t('瞬间已经删除') : _t('没有瞬间被删除'),
            null,
            $deleteCount > 0 ? 'success' : 'notice'
        );
        $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
    }

    public function goRedirect()
    {
        $target = $this->request->get('target');
        if (is_array($target)) {
            $target = implode('', $target);
        }

        $target = trim((string)$target);
        if ($target === '') {
            $this->response->setStatus(404);
            echo _t('跳转目标不存在');
            return;
        }

        $url = Enhancement_Plugin::decodeGoTarget($target);
        if ($url === '') {
            $this->response->setStatus(400);
            echo _t('无效的跳转地址');
            return;
        }

        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive');

        $options = Typecho_Widget::widget('Widget_Options');
        $homeUrl = isset($options->siteUrl) ? (string)$options->siteUrl : '/';
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeHomeUrl = htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8');

        echo '<!doctype html>';
        echo '<html lang="zh-CN"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>即将离开本站</title>';
        echo '<style>';
        echo 'body{margin:0;background:#f5f7fb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,PingFang SC,Hiragino Sans GB,Microsoft YaHei,sans-serif;color:#1f2937;}';
        echo '.wrap{max-width:560px;margin:8vh auto;padding:24px;}';
        echo '.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 10px 24px rgba(0,0,0,.08);}';
        echo 'h1{margin:0 0 10px;font-size:22px;}';
        echo 'p{margin:8px 0;color:#4b5563;line-height:1.7;word-break:break-all;}';
        echo '.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px;}';
        echo '.btn{display:inline-block;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:500;}';
        echo '.btn-primary{background:#2563eb;color:#fff;}';
        echo '.btn-secondary{background:#eef2ff;color:#1e40af;}';
        echo '.host{display:inline-block;padding:2px 8px;background:#eef2ff;color:#3730a3;border-radius:99px;font-size:12px;}';
        echo '</style></head><body>';
        echo '<div class="wrap"><div class="card">';
        echo '<h1>即将离开本站</h1>';
        echo '<p>你将访问外部网站，请注意账号与隐私安全。</p>';
        echo '<p>目标地址：<span id="target-url">' . $safeUrl . '</span></p>';
        echo '<div class="actions">';
        echo '<a class="btn btn-primary" href="' . $safeUrl . '" rel="noopener noreferrer nofollow" target="_blank">继续访问</a>';
        echo '<a class="btn btn-secondary" href="' . $safeHomeUrl . '">回到首页</a>';
        echo '</div>';
        echo '</div></div>';
        echo '</body></html>';
    }

    public function action()
    {
        $this->db = Typecho_Db::get();
        $this->prefix = $this->db->getPrefix();
        $this->options = Typecho_Widget::widget('Widget_Options');

        $action = $this->request->get('action');
        $pathInfo = $this->request->getPathInfo();
        $hasContent = false;
        $this->request->get('content', null, $hasContent);
        $hasMid = false;
        $this->request->get('mid', null, $hasMid);
        $hasMidArray = !empty($this->request->getArray('mid'));

        if ($action === 'enhancement-submit' || $this->request->is('do=submit')) {
            Helper::security()->protect();
            $this->submitEnhancement();
            return;
        }

        if ($this->request->is('do=backup-settings')) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');
            $this->backupPluginSettings();
            return;
        }

        if ($this->request->is('do=restore-settings')) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');
            $this->restorePluginSettings();
            return;
        }

        if ($this->request->is('do=delete-backup')) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');
            $this->deletePluginSettingsBackup();
            return;
        }

        if ($this->request->is('do=qq-test-notify')) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');
            $this->sendQqTestNotify();
            return;
        }

        if ($this->request->is('do=qq-queue-retry')) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');
            $this->retryQqNotifyQueue();
            return;
        }

        if ($this->request->is('do=qq-queue-clear')) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');
            $this->clearQqNotifyQueue();
            return;
        }

        if ($this->request->is('do=upload-package')) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');
            $this->uploadPackage();
            return;
        }

        if ($this->request->is('do=delete-plugin-package')) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');
            $this->deletePluginPackage();
            return;
        }

        if ($this->request->is('do=delete-theme-package')) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');
            $this->deleteThemePackage();
            return;
        }

        $isMomentsAction = ($action === 'enhancement-moments-edit')
            || (is_string($pathInfo) && strpos($pathInfo, 'enhancement-moments-edit') !== false)
            || $hasContent
            || $hasMid
            || $hasMidArray;

        if ($isMomentsAction) {
            Helper::security()->protect();
            $user = Typecho_Widget::widget('Widget_User');
            $user->pass('administrator');

            $this->on($this->request->is('do=insert'))->insertMoment();
            $this->on($this->request->is('do=update'))->updateMoment();
            $this->on($this->request->is('do=delete'))->deleteMoment();
            $this->response->redirect(Typecho_Common::url('extending.php?panel=Enhancement%2Fmanage-moments.php', $this->options->adminUrl));
            return;
        }

        Helper::security()->protect();
        $user = Typecho_Widget::widget('Widget_User');
        $user->pass('administrator');

        $this->on($this->request->is('do=insert'))->insertEnhancement();
        $this->on($this->request->is('do=update'))->updateEnhancement();
        $this->on($this->request->is('do=delete'))->deleteEnhancement();
        $this->on($this->request->is('do=approve'))->approveEnhancement();
        $this->on($this->request->is('do=reject'))->rejectEnhancement();
        $this->on($this->request->is('do=sort'))->sortEnhancement();
        $this->on($this->request->is('do=email-logo'))->emailLogo();
        $this->response->redirect($this->options->adminUrl);
    }
}

/** Enhancement */
