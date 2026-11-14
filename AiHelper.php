<?php

require_once __DIR__ . '/ShortcodeHelper.php';

class Enhancement_AiHelper
{
    private static function settings()
    {
        $settings = Enhancement_Plugin::runtimeSettings();
        return is_object($settings) ? $settings : (object) array();
    }

    public static function summaryEnabled(): bool
    {
        $settings = self::settings();
        return isset($settings->enable_ai_summary) && $settings->enable_ai_summary == '1';
    }

    public static function slugTranslateEnabled(): bool
    {
        $settings = self::settings();
        return isset($settings->enable_ai_slug_translate) && trim((string)$settings->enable_ai_slug_translate) === '1';
    }

    public static function handlePostFinishPublish($contents, $edit)
    {
        self::autoGeneratePostSummary($contents, $edit);
    }

    public static function previewSlug(string $title, int $cid = 0): array
    {
        $fail = function ($message) {
            return array('success' => false, 'slug' => '', 'message' => $message);
        };

        if (!self::slugTranslateEnabled()) {
            return $fail('AI slug 翻译未启用');
        }

        $title = trim($title);
        if ($title === '') {
            return $fail('标题不能为空');
        }

        $settings = self::settings();
        $apiResult = self::callApi(
            '标题：' . trim($title),
            isset($settings->ai_slug_prompt) ? trim((string)$settings->ai_slug_prompt) : '',
            0.1,
            $settings,
            'AI 配置不完整（API 地址 / Token / 模型）',
            'AI 接口未返回 slug 内容'
        );
        if (empty($apiResult['success'])) {
            return $fail(isset($apiResult['error']) && trim((string)$apiResult['error']) !== ''
                ? trim((string)$apiResult['error'])
                : 'AI slug 生成失败');
        }

        $slugRaw = isset($apiResult['content']) ? (string)$apiResult['content'] : '';
        $slug = self::normalizeSlugResult($slugRaw, $settings);
        if ($slug === '') {
            return $fail('AI 未返回有效 slug');
        }

        $slug = self::buildUniqueSlugCandidate($cid, $slug);
        if ($slug === '') {
            return $fail('slug 去重失败');
        }

        return array('success' => true, 'slug' => $slug, 'message' => 'ok');
    }

    public static function autoGeneratePostSummary($contents, $edit, $force = false)
    {
        if (!self::summaryEnabled()) {
            return array('status' => 'skipped', 'message' => 'ai summary disabled');
        }

        if (!is_object($edit) || !isset($edit->cid)) {
            return array('status' => 'error', 'message' => 'invalid post object');
        }

        $cid = intval($edit->cid);
        if ($cid <= 0) {
            return array('status' => 'error', 'message' => 'invalid cid');
        }

        $force = ($force === true || $force === 1 || $force === '1');
        $settings = self::settings();
        $fieldName = self::summaryFieldName($settings);
        if ($fieldName === '') {
            return array('status' => 'error', 'message' => 'invalid field name');
        }

        $updateMode = isset($settings->ai_summary_update_mode) ? trim((string)$settings->ai_summary_update_mode) : 'empty';
        $existingSummary = self::readSummaryFieldValue($cid, $fieldName);
        if (!$force && $updateMode !== 'always' && $existingSummary !== '') {
            return array('status' => 'skipped', 'message' => 'summary exists');
        }

        $title = '';
        if (is_array($contents) && isset($contents['title'])) {
            $title = trim((string)$contents['title']);
        }
        if ($title === '' && isset($edit->title)) {
            $title = trim((string)$edit->title);
        }

        $contentText = '';
        if (is_array($contents) && isset($contents['text'])) {
            $contentText = (string)$contents['text'];
        }
        if ($contentText === '' && isset($edit->text)) {
            $contentText = (string)$edit->text;
        }

        $sourceText = self::buildSummarySourceText($title, $contentText, $settings);
        if ($sourceText === '') {
            return array('status' => 'skipped', 'message' => 'empty content');
        }

        $apiResult = self::callApi(
            $sourceText,
            isset($settings->ai_summary_prompt) ? trim((string)$settings->ai_summary_prompt) : '',
            0.2,
            $settings,
            'AI 摘要配置不完整（API 地址 / Token / 模型）',
            'AI 接口未返回摘要内容'
        );
        if (empty($apiResult['success'])) {
            $error = isset($apiResult['error']) ? trim((string)$apiResult['error']) : '';
            if ($error !== '') {
                error_log('[Enhancement][AISummary] ' . $error);
            }
            return array('status' => 'error', 'message' => ($error !== '' ? $error : 'ai api error'));
        }

        $summary = isset($apiResult['content']) ? self::normalizeSummaryResult((string)$apiResult['content'], $settings) : '';
        if ($summary === '') {
            return array('status' => 'error', 'message' => 'empty summary');
        }

        $saved = false;
        if (method_exists($edit, 'setField')) {
            try {
                $setResult = $edit->setField($fieldName, 'str', $summary, $cid);
                $saved = ($setResult !== false);
            } catch (Exception $e) {
                $saved = false;
            }
        }

        if (!$saved) {
            $saved = self::saveSummaryFieldValue($cid, $fieldName, $summary);
        }

        if (!$saved) {
            return array('status' => 'error', 'message' => 'save summary failed');
        }

        return array('status' => 'generated', 'message' => 'ok');
    }

    private static function callApi(
        string $sourceText,
        string $prompt,
        float $temperature,
        $settings,
        string $missingConfigError,
        string $emptyContentError
    ): array {
        $fail = function ($error) {
            return array('success' => false, 'content' => '', 'error' => $error);
        };

        $endpoint = self::apiEndpoint(isset($settings->ai_summary_api_url) ? (string)$settings->ai_summary_api_url : '');
        $token = isset($settings->ai_summary_api_token) ? trim((string)$settings->ai_summary_api_token) : '';
        $model = isset($settings->ai_summary_model) ? trim((string)$settings->ai_summary_model) : '';

        if ($endpoint === '' || $token === '' || $model === '') {
            return $fail($missingConfigError);
        }

        if ($prompt === '') {
            $prompt = $temperature < 0.2
                ? '请将用户提供的标题转换为英文 URL slug，只输出 slug。'
                : '请基于用户提供的文章内容生成摘要，只输出摘要正文。';
        }

        if (!function_exists('curl_init')) {
            return $fail('curl 扩展未启用');
        }

        $payload = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => $prompt,
                ),
                array(
                    'role' => 'user',
                    'content' => $sourceText,
                ),
            ),
            'temperature' => $temperature,
        );

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            return $fail('AI 请求数据编码失败');
        }

        $headers = array(
            'Content-Type: application/json; charset=UTF-8',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        );

        $ch = curl_init();
        $sslVerify = self::sslVerifyEnabled($settings);
        $curlOptions = array(
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_NOSIGNAL => true,
        );

        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : '';
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        unset($ch);

        if ($errno !== 0) {
            return $fail('AI 请求失败：' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $bodyPreview = self::truncate((string)$response, 180);
            return $fail('AI 接口响应异常（HTTP ' . $httpCode . '）' . ($bodyPreview === '' ? '' : '：' . $bodyPreview));
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            return $fail('AI 接口响应格式错误');
        }

        $content = self::extractContent($decoded);
        if ($content === '') {
            return $fail(isset($decoded['error']['message']) && trim((string)$decoded['error']['message']) !== ''
                ? 'AI 接口返回错误：' . trim((string)$decoded['error']['message'])
                : $emptyContentError);
        }

        return array('success' => true, 'content' => $content, 'error' => '');
    }

    private static function normalizeSlugResult(string $slug, $settings): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        $slug = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $slug);
        $slug = preg_replace('/\s*```$/', '', $slug);
        $slug = preg_replace('/^slug\s*[:：]\s*/i', '', $slug);
        $slug = str_replace(array('"', "'", '`'), '', $slug);
        $slug = str_replace('_', '-', $slug);
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = strtolower(trim((string)$slug));

        $maxLen = self::intSetting($settings, 'ai_slug_max_length', 80, 20, 128);
        $slug = Typecho_Common::slugName($slug, '', $maxLen);
        $slug = strtolower(trim((string)$slug, '-_'));

        return $slug;
    }

    private static function summaryFieldName($settings): string
    {
        $name = isset($settings->ai_summary_field) ? trim((string)$settings->ai_summary_field) : 'summary';
        if (!preg_match('/^[_a-z][_a-z0-9]*$/i', $name)) {
            $name = 'summary';
        }

        return $name;
    }

    private static function readSummaryFieldValue(int $cid, string $fieldName): string
    {
        if ($cid <= 0 || $fieldName === '') {
            return '';
        }

        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('type', 'str_value', 'int_value', 'float_value')
                    ->from('table.fields')
                    ->where('cid = ?', $cid)
                    ->where('name = ?', $fieldName)
                    ->limit(1)
            );

            if (!is_array($row) || empty($row)) {
                return '';
            }

            $type = isset($row['type']) ? strtolower((string)$row['type']) : 'str';
            if ($type === 'int') {
                return (string)intval($row['int_value']);
            }
            if ($type === 'float') {
                return trim((string)$row['float_value']);
            }

            return trim((string)$row['str_value']);
        } catch (Exception $e) {
            return '';
        }
    }

    private static function saveSummaryFieldValue(int $cid, string $fieldName, string $summary): bool
    {
        if ($cid <= 0 || $fieldName === '') {
            return false;
        }

        try {
            $db = Typecho_Db::get();
            $exists = $db->fetchRow(
                $db->select('cid')
                    ->from('table.fields')
                    ->where('cid = ?', $cid)
                    ->where('name = ?', $fieldName)
                    ->limit(1)
            );

            $rows = array(
                'type' => 'str',
                'str_value' => (string)$summary,
                'int_value' => 0,
                'float_value' => 0,
            );

            if (is_array($exists) && !empty($exists)) {
                $db->query(
                    $db->update('table.fields')
                        ->rows($rows)
                        ->where('cid = ?', $cid)
                        ->where('name = ?', $fieldName)
                );
            } else {
                $rows['cid'] = $cid;
                $rows['name'] = $fieldName;
                $db->query($db->insert('table.fields')->rows($rows));
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function buildSummarySourceText(string $title, string $text, $settings): string
    {
        $plain = self::summaryToPlainText($text);
        if ($plain === '') {
            return '';
        }

        $limit = self::intSetting($settings, 'ai_summary_input_limit', 6000, 500, 30000);
        $plain = self::truncate($plain, $limit);

        $title = trim(strip_tags((string)$title));
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim((string)$title);

        if ($title !== '') {
            return "标题：{$title}\n\n正文：{$plain}";
        }

        return $plain;
    }

    private static function summaryToPlainText(string $text): string
    {
        // Remove hidden bodies while their shortcode or rendered boundaries still exist.
        $text = Enhancement_ShortcodeHelper::hideReplyContent($text);
        $text = str_replace('<!--markdown-->', '', (string)$text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/<pre\b[^>]*>[\s\S]*?<\/pre>/i', ' ', $text);
        $text = preg_replace('/<code\b[^>]*>[\s\S]*?<\/code>/i', ' ', $text);
        $text = preg_replace('/```[\s\S]*?```/', ' ', $text);
        $text = preg_replace('/`[^`\r\n]+`/', ' ', $text);
        $text = preg_replace('/!\[[^\]]*]\([^)]+\)/', ' ', $text);
        $text = preg_replace('/\[([^\]]+)\]\((?:[^)]+)\)/', '$1', $text);
        $text = strip_tags($text);
        $text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', (string)$text);

        return trim((string)$text);
    }

    private static function intSetting($settings, string $key, int $default, int $min, int $max): int
    {
        $value = isset($settings->{$key}) ? intval($settings->{$key}) : $default;
        if ($value <= 0) {
            $value = $default;
        }

        return max($min, min($max, $value));
    }

    private static function sslVerifyEnabled($settings): bool
    {
        return !isset($settings->ai_ssl_verify) || trim((string)$settings->ai_ssl_verify) !== '0';
    }

    private static function apiEndpoint(string $rawUrl): string
    {
        $url = trim($rawUrl);
        if ($url === '') {
            return '';
        }

        $url = rtrim($url, '/');
        if (preg_match('#/chat/completions$#i', $url)) {
            return $url;
        }

        if (preg_match('#/v1$#i', $url)) {
            return $url . '/chat/completions';
        }

        return $url . '/v1/chat/completions';
    }

    private static function extractContent(array $decoded): string
    {
        if (
            isset($decoded['choices'][0]['message']) &&
            is_array($decoded['choices'][0]['message']) &&
            array_key_exists('content', $decoded['choices'][0]['message'])
        ) {
            $content = $decoded['choices'][0]['message']['content'];
            if (is_string($content)) {
                return trim($content);
            }
            if (is_array($content)) {
                $parts = array();
                foreach ($content as $chunk) {
                    if (is_array($chunk)) {
                        if (isset($chunk['text']) && is_string($chunk['text'])) {
                            $parts[] = $chunk['text'];
                        }
                    } elseif (is_string($chunk)) {
                        $parts[] = $chunk;
                    }
                }
                return trim(implode('', $parts));
            }
        }

        if (isset($decoded['choices'][0]['text']) && is_string($decoded['choices'][0]['text'])) {
            return trim((string)$decoded['choices'][0]['text']);
        }

        return '';
    }

    private static function buildUniqueSlugCandidate(int $cid, string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        try {
            $db = Typecho_Db::get();
            $baseSlug = Typecho_Common::slugName(trim($slug), $cid > 0 ? (string)$cid : '', 128);
            $baseSlug = strtolower(trim((string)$baseSlug, '-_'));
            if ($baseSlug === '') {
                return '';
            }

            $resultSlug = $baseSlug;
            $count = 1;
            while (true) {
                $query = $db->select(array('COUNT(cid)' => 'num'))
                    ->from('table.contents')
                    ->where('slug = ?', $resultSlug);
                if ($cid > 0) {
                    $query->where('cid <> ?', $cid);
                }
                $exists = $db->fetchObject($query);

                if (!isset($exists->num) || intval($exists->num) <= 0) {
                    break;
                }

                $resultSlug = $baseSlug . '-' . $count;
                $count++;
            }

            return $resultSlug;
        } catch (Exception $e) {
            return $slug;
        }
    }

    private static function normalizeSummaryResult(string $summary, $settings): string
    {
        $summary = trim($summary);
        if ($summary === '') {
            return '';
        }

        if (preg_match('/^```(?:[a-zA-Z0-9_-]+)?\s*([\s\S]*?)\s*```$/', $summary, $matches)) {
            $summary = isset($matches[1]) ? trim((string)$matches[1]) : $summary;
        }

        $summary = strip_tags($summary);
        $summary = html_entity_decode((string)$summary, ENT_QUOTES, 'UTF-8');
        $summary = preg_replace('/\s+/', ' ', (string)$summary);
        $summary = trim((string)$summary);

        $maxLen = self::intSetting($settings, 'ai_summary_max_length', 180, 20, 2000);
        return self::truncate($summary, $maxLen);
    }

    private static function truncate(string $text, int $length): string
    {
        $text = trim($text);
        if ($text === '' || $length <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $length) {
                return trim((string)mb_substr($text, 0, $length, 'UTF-8'));
            }
            return $text;
        }

        if (Typecho_Common::strLen($text) > $length) {
            return trim((string)Typecho_Common::subStr($text, 0, $length, ''));
        }

        return $text;
    }
}
