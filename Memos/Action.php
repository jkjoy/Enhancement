<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class Enhancement_Memos_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $db;
    private $options;
    private $prefix;

    private function configuredToken(): string
    {
        try {
            $settings = $this->options->plugin('Enhancement');
        } catch (Exception $e) {
            $settings = (object) array();
        }

        return isset($settings->moments_token) ? trim((string)$settings->moments_token) : '';
    }

    private function validateToken($incomingToken): bool
    {
        $token = $this->configuredToken();
        $incomingToken = trim((string)$incomingToken);
        if ($token === '' || $incomingToken === '') {
            return false;
        }

        if (function_exists('hash_equals')) {
            return hash_equals($token, $incomingToken);
        }

        return $token === $incomingToken;
    }

    private function getBearerToken(): ?string
    {
        $headers = array(
            $this->request->getHeader('Authorization'),
            $this->request->getServer('REDIRECT_HTTP_AUTHORIZATION'),
            $this->request->getServer('HTTP_AUTHORIZATION'),
            $this->request->getServer('AUTHORIZATION')
        );

        foreach ($headers as $header) {
            if (!empty($header) && preg_match('/^Bearer\\s+(.+)$/i', $header, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    public function action()
    {
        $this->db = Typecho_Db::get();
        $this->prefix = $this->db->getPrefix();
        $this->options = Typecho_Widget::widget('Widget_Options');

        if ($this->request->isPost()) {
            $this->createMoment();
        } else {
            $this->listing();
        }
    }

    private function listing()
    {
        Enhancement_Plugin::ensureMomentsTable();

        $showAll = $this->validateToken($this->getBearerToken());

        $limit = intval($this->request->get('limit', 20));
        if ($limit <= 0) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        $page = intval($this->request->get('page', 1));
        if ($page <= 0) {
            $page = 1;
        }
        $offset = ($page - 1) * $limit;

        $sql = $this->db->select()->from($this->prefix . 'moments')
            ->order($this->prefix . 'moments.mid', Typecho_Db::SORT_DESC)
            ->limit($limit)
            ->offset($offset);
        if (!$showAll) {
            $sql->where('status = ? OR status IS NULL OR status = ?', 'public', '');
        }

        try {
            $rows = $this->db->fetchAll($sql);
        } catch (Exception $e) {
            if (!$showAll) {
                try {
                    $fallbackSql = $this->db->select()->from($this->prefix . 'moments')
                        ->order($this->prefix . 'moments.mid', Typecho_Db::SORT_DESC)
                        ->limit($limit)
                        ->offset($offset);
                    $rows = $this->db->fetchAll($fallbackSql);
                } catch (Exception $e2) {
                    $this->response->throwJson(array());
                    return;
                }
            } else {
                $this->response->throwJson(array());
                return;
            }
        }

        $items = array();
        foreach ($rows as $row) {
            $momentStatus = Enhancement_Plugin::normalizeMomentStatus(isset($row['status']) ? $row['status'] : '', 'public');
            if (!$showAll && $momentStatus !== 'public') {
                continue;
            }

            $tagsRaw = isset($row['tags']) ? trim($row['tags']) : '';
            $tags = array();
            if ($tagsRaw !== '') {
                $decodedTags = json_decode($tagsRaw, true);
                if (is_array($decodedTags)) {
                    foreach ($decodedTags as $tag) {
                        $tag = trim((string)$tag);
                        if ($tag !== '') {
                            $tags[] = $tag;
                        }
                    }
                } else {
                    $parts = explode(',', $tagsRaw);
                    foreach ($parts as $tag) {
                        $tag = trim($tag);
                        if ($tag !== '') {
                            $tags[] = $tag;
                        }
                    }
                }
            }

            $mediaRaw = isset($row['media']) ? trim($row['media']) : '';
            $media = array();
            if ($mediaRaw !== '') {
                $decodedMedia = json_decode($mediaRaw, true);
                if (is_array($decodedMedia)) {
                    $media = $decodedMedia;
                }
            }

            $created = isset($row['created']) ? $row['created'] : 0;
            $timestamp = 0;
            if (is_numeric($created)) {
                $timestamp = intval($created);
            } else {
                $parsed = strtotime((string)$created);
                $timestamp = $parsed ? $parsed : 0;
            }
            $timeString = $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '';

            $latitude = Enhancement_Plugin::normalizeMomentLatitude(isset($row['latitude']) ? $row['latitude'] : '');
            $longitude = Enhancement_Plugin::normalizeMomentLongitude(isset($row['longitude']) ? $row['longitude'] : '');
            if ($latitude === null || $longitude === null) {
                $latitude = null;
                $longitude = null;
            }

            $items[] = array(
                'id' => (string)$row['mid'],
                'content' => (string)$row['content'],
                'time' => $timeString,
                'tags' => $tags,
                'media' => $media,
                'status' => $momentStatus,
                'source' => Enhancement_Plugin::normalizeMomentSource(isset($row['source']) ? $row['source'] : '', 'web'),
                'latitude' => $latitude,
                'longitude' => $longitude
            );
        }

        $this->response->throwJson($items);
    }

    private function createMoment()
    {
        Enhancement_Plugin::ensureMomentsTable();

        $token = $this->configuredToken();

        if ($token === '') {
            $this->response->setStatus(403)->throwJson(array(
                'success' => false,
                'message' => _t('未设置 API Token')
            ));
            return;
        }

        $incomingToken = $this->getBearerToken();

        if (empty($incomingToken)) {
            $this->response->setStatus(403)->throwJson(array(
                'success' => false,
                'message' => _t('缺少 Authorization Token')
            ));
            return;
        }

        if (!$this->validateToken($incomingToken)) {
            $this->response->setStatus(403)->throwJson(array(
                'success' => false,
                'message' => _t('Token 无效')
            ));
            return;
        }

        $payload = $this->request->get('@json');
        $content = '';
        $tagsValue = null;
        $mediaValue = null;
        $createdValue = null;
        $sourceValue = null;
        $statusValue = null;
        $latitudeValue = null;
        $longitudeValue = null;
        $locationAddressValue = null;

        if (is_array($payload)) {
            $content = $payload['content'] ?? '';
            $tagsValue = $payload['tags'] ?? null;
            $mediaValue = $payload['media'] ?? null;
            $createdValue = $payload['created'] ?? null;
            $sourceValue = $payload['source'] ?? null;
            $statusValue = $payload['status'] ?? null;
            $latitudeValue = isset($payload['latitude']) ? $payload['latitude'] : (isset($payload['lat']) ? $payload['lat'] : null);
            $longitudeValue = isset($payload['longitude']) ? $payload['longitude'] : (isset($payload['lng']) ? $payload['lng'] : null);
            $locationAddressValue = isset($payload['location_address']) ? $payload['location_address'] : (isset($payload['address']) ? $payload['address'] : null);
        } else {
            $content = $this->request->get('content');
            $tagsValue = $this->request->get('tags');
            $mediaValue = $this->request->get('media');
            $createdValue = $this->request->get('created');
            $sourceValue = $this->request->get('source');
            $statusValue = $this->request->get('status');
            $latitudeValue = $this->request->get('latitude', $this->request->get('lat'));
            $longitudeValue = $this->request->get('longitude', $this->request->get('lng'));
            $locationAddressValue = $this->request->get('location_address', $this->request->get('address'));
        }

        $content = trim((string)$content);
        if ($content === '') {
            $this->response->setStatus(400)->throwJson(array(
                'success' => false,
                'message' => _t('内容不能为空')
            ));
            return;
        }

        $tags = null;
        if (is_array($tagsValue)) {
            $tags = json_encode($tagsValue, JSON_UNESCAPED_UNICODE);
        } else if ($tagsValue !== null) {
            $tags = trim((string)$tagsValue);
            if ($tags === '') {
                $tags = null;
            } else {
                $decodedTags = json_decode($tags, true);
                if (is_array($decodedTags)) {
                    $tags = json_encode($decodedTags, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        $media = null;
        if (is_array($mediaValue)) {
            $media = json_encode($mediaValue, JSON_UNESCAPED_UNICODE);
        } else if ($mediaValue !== null) {
            $media = trim((string)$mediaValue);
            if ($media === '') {
                $media = null;
            } else {
                $decodedMedia = json_decode($media, true);
                if (is_array($decodedMedia)) {
                    $media = json_encode($decodedMedia, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        if ($media === null) {
            $cleanedContent = $content;
            $mediaItems = Enhancement_Plugin::extractMediaFromContent($content, $cleanedContent);
            if (!empty($mediaItems)) {
                $media = json_encode($mediaItems, JSON_UNESCAPED_UNICODE);
                $content = $cleanedContent;
            }
        }

        $created = $this->options->time;
        if ($createdValue !== null && $createdValue !== '') {
            if (is_numeric($createdValue)) {
                $created = intval($createdValue);
            } else {
                $parsed = strtotime((string)$createdValue);
                if ($parsed) {
                    $created = $parsed;
                }
            }
        }

        $sourceDefault = 'api';
        $source = Enhancement_Plugin::normalizeMomentSource($sourceValue, $sourceDefault);
        $status = Enhancement_Plugin::normalizeMomentStatus($statusValue, 'public');
        $latitude = Enhancement_Plugin::normalizeMomentLatitude($latitudeValue);
        $longitude = Enhancement_Plugin::normalizeMomentLongitude($longitudeValue);
        if ($latitude === null || $longitude === null) {
            $latitude = null;
            $longitude = null;
        }
        $locationAddress = Enhancement_Plugin::normalizeMomentLocationAddress($locationAddressValue);

        $moment = array(
            'content' => $content,
            'tags' => $tags,
            'media' => $media,
            'source' => $source,
            'status' => $status,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_address' => $locationAddress,
            'created' => $created
        );

        try {
            $mid = $this->db->query($this->db->insert($this->prefix . 'moments')->rows($moment));
        } catch (Exception $e) {
            $this->response->setStatus(500)->throwJson(array(
                'success' => false,
                'message' => _t('数据库写入失败')
            ));
            return;
        }

        $this->response->throwJson(array(
            'success' => true,
            'id' => (string)$mid
        ));
    }
}
