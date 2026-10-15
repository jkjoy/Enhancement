<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
require_once __DIR__ . '/CommentHelper.php';

/** WordPress-compatible REST resources, adapted from jkjoy's Typecho-RestAPI. */
class Enhancement_RestApi_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $options;
    private $db;

    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
        if (!Enhancement_RestApiHelper::enabled()) {
            $this->json(['code' => 'rest_no_route', 'message' => 'REST API is disabled.'], 404);
        }
        $this->options = $this->widget('Widget_Options');
        $this->db = Typecho_Db::get();
        // Set permissive CORS and handle preflight
        $this->cors();
        $method = strtoupper((string)$this->request->getServer('REQUEST_METHOD', 'GET'));
        $route = isset(Typecho_Router::$current) ? Typecho_Router::get(Typecho_Router::$current) : null;
        $isComments = is_array($route) && isset($route['action'], $route['widget'])
            && $route['action'] === 'comments' && $route['widget'] === __CLASS__;
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !($method === 'POST' && $isComments)) {
            header('Allow: ' . ($isComments ? 'GET, HEAD, POST, OPTIONS' : 'GET, HEAD, OPTIONS'));
            $this->json(['code' => 'rest_no_route', 'message' => 'Method not allowed.'], 405);
        }
        if ($method === 'OPTIONS') {
            $this->response->setStatus(204);
            Typecho_Response::getInstance()->sendHeaders();
            exit;
        }
    }

    private function cors()
    {
        header('Access-Control-Allow-Origin: *');
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, HEAD, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
        header('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages');
    }

    private function json($data, $status = 200)
    {
        $this->response->setStatus($status);
        $this->response->setContentType('application/json');
        Typecho_Response::getInstance()->sendHeaders();
        if (strtoupper((string)$this->request->getServer('REQUEST_METHOD', 'GET')) !== 'HEAD') {
            echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    private function get_pagination()
    {
        $page = max(1, (int)$this->request->get('page', 1));
        $per = min(100, max(1, (int)$this->request->get('per_page', 10)));
        $offset = ($page - 1) * $per;
        return [$page, $per, $offset];
    }

    public function root()
    {
        $cfg = Enhancement_Plugin::runtimeSettings();
        $title = isset($cfg->rest_api_site_title) && trim((string)$cfg->rest_api_site_title) !== ''
            ? trim((string)$cfg->rest_api_site_title)
            : $this->options->title;
        $description = isset($cfg->rest_api_site_description) && trim((string)$cfg->rest_api_site_description) !== ''
            ? trim((string)$cfg->rest_api_site_description)
            : $this->options->description;

        $base = rtrim($this->options->siteUrl, '/');
        $this->json([
            'name' => $title,
            'description' => $description,
            'url' => $base,
            'home' => $base,
            'namespaces' => ['wp/v2'],
            'routes' => [
                '/wp/v2' => ['namespace' => 'wp/v2', 'methods' => ['GET']],
                '/wp/v2/posts' => ['namespace' => 'wp/v2', 'methods' => ['GET']],
                '/wp/v2/pages' => ['namespace' => 'wp/v2', 'methods' => ['GET']],
                '/wp/v2/categories' => ['namespace' => 'wp/v2', 'methods' => ['GET']],
                '/wp/v2/tags' => ['namespace' => 'wp/v2', 'methods' => ['GET']],
                '/wp/v2/settings' => ['namespace' => 'wp/v2', 'methods' => ['GET']],
                '/wp/v2/links' => ['namespace' => 'wp/v2', 'methods' => ['GET']],
                '/wp/v2/link-categories' => ['namespace' => 'wp/v2', 'methods' => ['GET']],
                '/wp/v2/comments' => ['namespace' => 'wp/v2', 'methods' => ['GET', 'POST']],
                '/wp/v2/users' => ['namespace' => 'wp/v2', 'methods' => ['GET']],
            ],
        ]);
    }

    public function wpv2()
    {
        $base = rtrim($this->options->index, '/');
        $this->json([
            'name' => 'wp/v2',
            'routes' => [
                '/wp/v2/posts' => ['methods' => ['GET']],
                '/wp/v2/posts/slug/(?P<slug>[a-zA-Z0-9\-_]+)' => ['methods' => ['GET']],
                '/wp/v2/posts/tag/(?P<slug>[a-zA-Z0-9\-_]+)' => ['methods' => ['GET']],
                '/wp/v2/pages' => ['methods' => ['GET']],
                '/wp/v2/pages/slug/(?P<slug>[a-zA-Z0-9\-_]+)' => ['methods' => ['GET']],
                '/wp/v2/categories' => ['methods' => ['GET']],
                '/wp/v2/tags' => ['methods' => ['GET']],
                '/wp/v2/settings' => ['methods' => ['GET']],
                '/wp/v2/links' => ['methods' => ['GET']],
                '/wp/v2/link-categories' => ['methods' => ['GET']],
                '/wp/v2/comments' => ['methods' => ['GET','POST']],
                '/wp/v2/users' => ['methods' => ['GET']],
                '/wp/v2/users/(?P<id>[\\d]+)' => ['methods' => ['GET']],
            ],
            '_links' => [
                'collection' => [['href' => $base . '/wp-json/wp/v2']],
            ],
        ]);
    }

    public function settings()
    {
        $cfg = Enhancement_Plugin::runtimeSettings();
        $title = isset($cfg->rest_api_site_title) && trim((string)$cfg->rest_api_site_title) !== ''
            ? trim((string)$cfg->rest_api_site_title)
            : $this->options->title;
        $description = isset($cfg->rest_api_site_description) && trim((string)$cfg->rest_api_site_description) !== ''
            ? trim((string)$cfg->rest_api_site_description)
            : $this->options->description;
        $headHtml = isset($cfg->rest_api_head_html) ? (string)$cfg->rest_api_head_html : '';
        $footerText = isset($cfg->rest_api_site_footer_text) ? (string)$cfg->rest_api_site_footer_text : '';
        $icp = isset($cfg->rest_api_site_icp) ? (string)$cfg->rest_api_site_icp : '';

        $this->json([
            'site_title' => $title,
            'site_description' => $description,
            'siteurl' => rtrim($this->options->siteUrl, '/'),
            'head_html' => $headHtml,
            'site_footer_text' => $footerText,
            'site_icp' => $icp,
        ]);
    }

    public function links()
    {
        $db = $this->db;
        $prefix = $db->getPrefix();
        $baseUrl = rtrim($this->options->index, '/');

        // Check existence of links table
        try {
            $select = $db->select()
                ->from($prefix . 'links')
                ->where('state = ?', 1);
            // Optional filters
            $sort = trim((string)$this->request->get('sort', ''));
            if ($sort !== '') {
                $select->where('sort = ?', $sort);
            }
            $state = $this->request->get('state');
            if ($state !== null && $state !== '') {
                $select->where('state = ?', (int)$state);
            }
            $countSelect = clone $select;
            $count = $db->fetchObject($countSelect->select(['COUNT(1)' => 'cnt']))->cnt;
            list($page, $per, $offset) = $this->get_pagination();
            $select->order('order', Typecho_Db::SORT_ASC);
            $select->limit($per)->offset($offset);

            $rows = $db->fetchAll($select);

            $items = [];
            foreach ($rows as $r) {
                $linkId = (int)$r['lid'];
                $items[] = [
                    'id' => $linkId,
                    'name' => $r['name'],
                    'url' => $r['url'],
                    'description' => isset($r['description']) ? $r['description'] : '',
                    'avatar' => isset($r['image']) ? $r['image'] : '',
                    'category' => [
                        'id' => 1,
                        'name' => '友情链接',
                        'slug' => 'friends',
                    ],
                    'target' => '_blank',
                    'visible' => isset($r['state']) && (int)$r['state'] === 1 ? 'yes' : 'no',
                    'rating' => 0,
                    'sort_order' => isset($r['order']) ? (int)$r['order'] : 0,
                    'created_at' => date('c', time()),
                    'updated_at' => date('c', time()),
                    '_links' => [
                        'self' => [
                            ['href' => $baseUrl . '/wp-json/wp/v2/links/' . $linkId]
                        ],
                        'collection' => [
                            ['href' => $baseUrl . '/wp-json/wp/v2/links']
                        ]
                    ]
                ];
            }

            header('X-WP-Total: ' . (int)$count);
            header('X-WP-TotalPages: ' . (int)ceil($count / $per));
            header('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages');
            $this->json($items);
        } catch (Exception $e) {
            // Table missing or other issue → return empty array per requirement
            header('X-WP-Total: 0');
            header('X-WP-TotalPages: 0');
            $this->json([]);
        }
    }

    public function linkCategories()
    {
        $this->json([
            [
                'id' => 1,
                'name' => '默认分类',
                'slug' => 'friends',
                'description' => '排名不分先后',
                'count' => 1,
            ]
        ]);
    }

    public function comments()
    {
        if ($this->request->isPost()) {
            return $this->create_comment();
        }

        list($page, $per, $offset) = $this->get_pagination();
        try {
            $select = Enhancement_RestApi_CommentHelper::query($this->request);
            $orderby = strtolower(trim((string)$this->request->get('orderby', 'date')));
            $order = strtolower(trim((string)$this->request->get('order', 'desc')));
            $select->order($orderby === 'id' ? 'table.comments.coid' : 'table.comments.created',
                $order === 'asc' ? Typecho_Db::SORT_ASC : Typecho_Db::SORT_DESC);
            $rows = $this->db->fetchAll($select->limit($per)->offset($offset));
            $count = $this->db->fetchObject(Enhancement_RestApi_CommentHelper::query($this->request, true))->cnt;
            $items = [];
            foreach ($rows as $row) {
                $items[] = Enhancement_RestApi_CommentHelper::format($row);
            }
            header('X-WP-Total: ' . (int)$count);
            header('X-WP-TotalPages: ' . (int)ceil($count / $per));
            $this->json($items);
        } catch (Typecho_Widget_Exception $e) {
            $this->json(['code' => 'rest_comment_invalid', 'message' => $e->getMessage()],
                in_array((int)$e->getCode(), [400, 401, 403, 404, 429, 503], true) ? (int)$e->getCode() : 400);
        }
    }

    private function create_comment()
    {
        $payload = [];
        $contentType = (string)$this->request->getServer('CONTENT_TYPE', '');
        if (stripos($contentType, 'application/json') !== false) {
            $payload = json_decode(file_get_contents('php://input'), true);
            if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
                $this->json(['code' => 'rest_invalid_json', 'message' => 'Invalid JSON body.'], 400);
            }
        }
        try {
            $row = Enhancement_RestApi_CommentHelper::create($this->request, $payload);
            $this->json(Enhancement_RestApi_CommentHelper::format($row), 201);
        } catch (Typecho_Widget_Exception $e) {
            $this->json(['code' => 'rest_comment_invalid', 'message' => $e->getMessage()],
                in_array((int)$e->getCode(), [400, 401, 403, 404, 429, 503], true) ? (int)$e->getCode() : 400);
        }
    }

    public function users()
    {
        list($page, $per, $offset) = $this->get_pagination();
        $select = $this->db->select()->from('table.users')
            ->order('uid', Typecho_Db::SORT_ASC)
            ->limit($per)->offset($offset);

        $search = trim((string)$this->request->get('search', ''));
        if ($search !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $select->where('(name LIKE ? OR screenName LIKE ?)', $kw, $kw);
        }

        $rows = $this->db->fetchAll($select);
        $countSelect = $this->db->select(['COUNT(1)' => 'cnt'])->from('table.users');
        if ($search !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $countSelect->where('(name LIKE ? OR screenName LIKE ?)', $kw, $kw);
        }
        $count = $this->db->fetchObject($countSelect)->cnt;

        $items = [];
        foreach ($rows as $u) {
            $items[] = [
                'id' => (int)$u['uid'],
                'name' => $u['screenName'],
                'slug' => $u['name'],
                'url' => $u['url'],
                'description' => '',
                'link' => $u['url'],
                'avatar_urls' => [
                    '24' => $this->gravatar($u['mail'], 24),
                    '48' => $this->gravatar($u['mail'], 48),
                    '96' => $this->gravatar($u['mail'], 96),
                ],
                'meta' => (object)[],
            ];
        }

        header('X-WP-Total: ' . (int)$count);
        header('X-WP-TotalPages: ' . (int)ceil($count / $per));
        header('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages');
        $this->json($items);
    }

    private function gravatar($mail, $size)
    {
        $hash = md5(strtolower(trim((string)$mail)));
        return 'https://www.gravatar.com/avatar/' . $hash . '?s=' . (int)$size . '&d=identicon';
    }

    public function user()
    {
        $uid = (int)$this->request->get('uid');
        $u = $this->db->fetchRow($this->db->select()->from('table.users')->where('uid = ?', $uid)->limit(1));
        if (!$u) {
            $this->json(['code' => 'not_found', 'message' => 'User not found'], 404);
        }
        $this->json([
            'id' => (int)$u['uid'],
            'name' => $u['screenName'],
            'slug' => $u['name'],
            'url' => $u['url'],
            'description' => isset($u['screenName']) ? $u['screenName'] : '',
            'link' => $u['url'],
            'avatar_urls' => [
                '24' => $this->gravatar($u['mail'], 24),
                '48' => $this->gravatar($u['mail'], 48),
                '96' => $this->gravatar($u['mail'], 96),
            ],
            'meta' => (object)[],
        ]);
    }

    private function content_permalink($row)
    {
        // Let Typecho resolve date/category/directory fields and the rewrite setting.
        $content = Typecho_Widget::widget('Widget_Abstract_Contents@enhancement-rest-api-' . (int)$row['cid']);
        $content->push($row);
        return $content->permalink;
    }

    private function public_content($row, $type = null)
    {
        return $row && ($type === null || $row['type'] === $type)
            && in_array($row['type'], ['post', 'page'], true)
            && $row['status'] === 'publish' && (int)$row['created'] <= time()
            && (!isset($row['password']) || (string)$row['password'] === '');
    }

    private function build_post_row($row)
    {
        $permalink = $this->content_permalink($row);
        $author = $this->db->fetchRow($this->db->select()
            ->from('table.users')->where('uid = ?', $row['authorId'])->limit(1));
        $summary = $this->get_custom_field((int)$row['cid'], 'summary');
        $excerpt = (string)$summary;
        // terms
        list($catIds, $tagIds) = $this->get_post_terms((int)$row['cid']);

        $item = [
            'id' => (int)$row['cid'],
            'date' => date('c', $row['created']),
            'date_gmt' => gmdate('c', $row['created']),
            'modified' => date('c', $row['modified']),
            'modified_gmt' => gmdate('c', $row['modified']),
            'slug' => $row['slug'],
            'status' => $row['status'] === 'publish' ? 'publish' : $row['status'],
            'type' => $row['type'],
            'link' => $permalink,
            'guid' => ['rendered' => $permalink],
            'title' => ['rendered' => $row['title']],
            'content' => [
                'rendered' => str_replace('<!--markdown-->', '', $row['text']),
                'protected' => false,
            ],
            'excerpt' => [
                'rendered' => $excerpt,
                'protected' => false,
            ],
            'author' => (int)$row['authorId'],
            'featured_media' => 0,
            'comment_status' => !empty($row['allowComment']) ? 'open' : 'closed',
            'ping_status' => !empty($row['allowPing']) ? 'open' : 'closed',
            'sticky' => false,
            'template' => '',
            'format' => 'standard',
            'meta' => (object)[],
            'categories' => $catIds,
            'tags' => $tagIds,
        ];

        if ($this->request->get('_embed')) {
            $item['_embedded'] = [
                'author' => [[
                    'id' => (int)$row['authorId'],
                    'name' => $author ? $author['screenName'] : '',
                    'url' => $author ? $author['url'] : '',
                    'description' => $author ? $author['screenName'] : '',
                ]],
            ];
        }

        return $item;
    }

    private function get_custom_field($cid, $name)
    {
        $f = $this->db->fetchRow(
            $this->db->select()->from('table.fields')
                ->where('cid = ?', $cid)
                ->where('name = ?', $name)
                ->limit(1)
        );
        if (!$f) return null;
        switch ($f['type']) {
            case 'str':
            default:
                return isset($f['str_value']) ? (string)$f['str_value'] : '';
            case 'int':
                return isset($f['int_value']) ? (string)$f['int_value'] : '';
            case 'float':
                return isset($f['float_value']) ? (string)$f['float_value'] : '';
        }
    }

    private function get_post_terms($cid)
    {
        $rels = $this->db->fetchAll(
            $this->db->select('table.metas.mid', 'table.metas.type')
                ->from('table.relationships')
                ->join('table.metas', 'table.relationships.mid = table.metas.mid')
                ->where('table.relationships.cid = ?', $cid)
        );
        $cats = [];
        $tags = [];
        foreach ($rels as $r) {
            if ($r['type'] === 'category') $cats[] = (int)$r['mid'];
            if ($r['type'] === 'tag') $tags[] = (int)$r['mid'];
        }
        return [$cats, $tags];
    }

    private function list_contents($type)
    {
        list($page, $per, $offset) = $this->get_pagination();
        $select = $this->db->select('table.contents.*')->from('table.contents')
            ->where('table.contents.type = ?', $type)
            ->where('table.contents.status = ?', 'publish')
            ->where('(table.contents.password IS NULL OR table.contents.password = ?)', '')
            ->where('table.contents.created <= ?', time());
        // search
        $search = trim((string)$this->request->get('search', ''));
        if ($search !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $select->where("(table.contents.title LIKE ? OR table.contents.text LIKE ?)", $kw, $kw);
        }
        // filter by slug (WordPress often uses ?slug=foo)
        $slug = trim((string)$this->request->get('slug', ''));
        if ($slug !== '') {
            $select->where('table.contents.slug = ?', $slug);
        }
        // filter by author id (?author=1)
        $authorId = (int)$this->request->get('author', 0);
        if ($authorId > 0) {
            $select->where('table.contents.authorId = ?', $authorId);
        }
        // include / exclude by content id
        $includeParam = trim((string)$this->request->get('include', ''));
        if ($includeParam !== '') {
            $includeIds = array_filter(array_map('intval', explode(',', $includeParam)));
            if (!empty($includeIds)) {
                $ph = implode(',', array_fill(0, count($includeIds), '?'));
                $select->where('table.contents.cid IN (' . $ph . ')', ...$includeIds);
            }
        }
        $excludeParam = trim((string)$this->request->get('exclude', ''));
        if ($excludeParam !== '') {
            $excludeIds = array_filter(array_map('intval', explode(',', $excludeParam)));
            if (!empty($excludeIds)) {
                $ph = implode(',', array_fill(0, count($excludeIds), '?'));
                // Keep NOT adjacent to '(' so Typecho does not quote it as a column.
                $select->where('NOT(table.contents.cid IN (' . $ph . '))', ...$excludeIds);
            }
        }

        // read tag slug for later taxonomy join
        $tagSlug = trim((string)$this->request->get('tag', ''));

        // taxonomy filters (ids separated by comma)
        $catParam = trim((string)$this->request->get('categories', ''));
        $tagParam = trim((string)$this->request->get('tags', ''));
        $catIds = $catParam !== '' ? array_filter(array_map('intval', explode(',', $catParam))) : [];
        $tagIds = $tagParam !== '' ? array_filter(array_map('intval', explode(',', $tagParam))) : [];
        $needsTermJoin = (!empty($catIds) || !empty($tagIds) || $tagSlug !== '');
        if ($needsTermJoin) {
            $select->join('table.relationships', 'table.relationships.cid = table.contents.cid')
                ->join('table.metas', 'table.metas.mid = table.relationships.mid');

            $parts = [];
            $binds = [];
            if (!empty($catIds)) {
                $in = implode(',', array_map('intval', $catIds));
                $parts[] = "(table.metas.type = 'category' AND table.metas.mid IN ($in))";
            }
            if (!empty($tagIds)) {
                $in = implode(',', array_map('intval', $tagIds));
                $parts[] = "(table.metas.type = 'tag' AND table.metas.mid IN ($in))";
            }
            if ($tagSlug !== '') {
                $parts[] = "(table.metas.type = 'tag' AND table.metas.slug = ?)";
                $binds[] = $tagSlug;
            }
            if (!empty($parts)) {
                $expr = '(' . implode(' OR ', $parts) . ')';
                if (!empty($binds)) {
                    $select->where($expr, ...$binds);
                } else {
                    $select->where($expr);
                }
            }
            $select->group('table.contents.cid');
        }

        // orderby/order for posts/pages
        $orderby = strtolower(trim((string)$this->request->get('orderby', 'date')));
        $order = strtolower(trim((string)$this->request->get('order', 'desc')));
        $orderCol = 'table.contents.created';
        if ($orderby === 'modified') $orderCol = 'table.contents.modified';
        else if ($orderby === 'title') $orderCol = 'table.contents.title';
        else if ($orderby === 'id') $orderCol = 'table.contents.cid';
        $orderDir = ($order === 'asc') ? Typecho_Db::SORT_ASC : Typecho_Db::SORT_DESC;
        $select->order($orderCol, $orderDir);

        // Apply pagination limit after all filters and ordering
        $select->limit($per)->offset($offset);

        $rows = $this->db->fetchAll($select);
        // total count (respect filters, avoid duplicates via DISTINCT)
        $countSelect = $this->db->select(['COUNT(DISTINCT table.contents.cid)' => 'cnt'])->from('table.contents')
            ->where('table.contents.type = ?', $type)
            ->where('table.contents.status = ?', 'publish')
            ->where('(table.contents.password IS NULL OR table.contents.password = ?)', '')
            ->where('table.contents.created <= ?', time());
        if ($search !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $countSelect->where("(title LIKE ? OR text LIKE ?)", $kw, $kw);
        }
        if ($slug !== '') {
            $countSelect->where('table.contents.slug = ?', $slug);
        }
        if ($authorId > 0) {
            $countSelect->where('table.contents.authorId = ?', $authorId);
        }
        if (!empty($includeIds)) {
            $countSelect->where('table.contents.cid IN (' . implode(',', array_map('intval', $includeIds)) . ')');
        }
        if (!empty($excludeIds)) {
            $countSelect->where('NOT(table.contents.cid IN (' . implode(',', array_map('intval', $excludeIds)) . '))');
        }
        if ($needsTermJoin) {
            $countSelect->join('table.relationships', 'table.relationships.cid = table.contents.cid')
                ->join('table.metas', 'table.metas.mid = table.relationships.mid');
            $parts = [];
            $binds = [];
            if (!empty($catIds)) {
                $in = implode(',', array_map('intval', $catIds));
                $parts[] = "(table.metas.type = 'category' AND table.metas.mid IN ($in))";
            }
            if (!empty($tagIds)) {
                $in = implode(',', array_map('intval', $tagIds));
                $parts[] = "(table.metas.type = 'tag' AND table.metas.mid IN ($in))";
            }
            if ($tagSlug !== '') {
                $parts[] = "(table.metas.type = 'tag' AND table.metas.slug = ?)";
                $binds[] = $tagSlug;
            }
            if (!empty($parts)) {
                $expr = '(' . implode(' OR ', $parts) . ')';
                if (!empty($binds)) {
                    $countSelect->where($expr, ...$binds);
                } else {
                    $countSelect->where($expr);
                }
            }
        }
        $count = $this->db->fetchObject($countSelect)->cnt;

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->build_post_row($row);
        }

        // WP style headers
        header('X-WP-Total: ' . (int)$count);
        header('X-WP-TotalPages: ' . (int)ceil($count / $per));
        header('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages');

        $this->json($items);
    }

    private function get_content_item($cid, $type)
    {
        $row = $this->db->fetchRow($this->db->select()->from('table.contents')
            ->where('cid = ?', $cid)->limit(1));
        if (!$this->public_content($row, $type)) {
            $this->json(['code' => 'not_found', 'message' => 'Not found'], 404);
        }
        $this->json($this->build_post_row($row));
    }

    public function posts() { $this->list_contents('post'); }
    public function post() { $this->get_content_item((int)$this->request->get('cid'), 'post'); }
    public function pages() { $this->list_contents('page'); }
    public function page() { $this->get_content_item((int)$this->request->get('cid'), 'page'); }

    public function post_by_slug()
    {
        $slug = $this->request->get('slug');
        $row = $this->db->fetchRow($this->db->select()->from('table.contents')
            ->where('type = ?', 'post')
            ->where('slug = ?', $slug)
            ->limit(1));
        if (!$this->public_content($row, 'post')) {
            $this->json(['code' => 'not_found', 'message' => 'Not found'], 404);
        }
        $this->json($this->build_post_row($row));
    }

    public function page_by_slug()
    {
        $slug = $this->request->get('slug');
        $row = $this->db->fetchRow($this->db->select()->from('table.contents')
            ->where('type = ?', 'page')
            ->where('slug = ?', $slug)
            ->limit(1));
        if (!$this->public_content($row, 'page')) {
            $this->json(['code' => 'not_found', 'message' => 'Not found'], 404);
        }
        $this->json($this->build_post_row($row));
    }

    public function posts_by_tag()
    {
        $slug = $this->request->get('slug');
        list($page, $per, $offset) = $this->get_pagination();
        $select = $this->db->select('table.contents.*')->from('table.contents')
            ->where('table.contents.type = ?', 'post')
            ->where('table.contents.status = ?', 'publish')
            ->where('(table.contents.password IS NULL OR table.contents.password = ?)', '')
            ->where('table.contents.created <= ?', time())
            ->join('table.relationships', 'table.relationships.cid = table.contents.cid')
            ->join('table.metas', 'table.metas.mid = table.relationships.mid')
            ->where('table.metas.type = ?', 'tag')
            ->where('table.metas.slug = ?', $slug)
            ->order('table.contents.created', Typecho_Db::SORT_DESC)
            ->limit($per)->offset($offset);

        $rows = $this->db->fetchAll($select);

        $count = $this->db->fetchObject(
            $this->db->select(['COUNT(1)' => 'cnt'])->from('table.contents')
                ->where('table.contents.type = ?', 'post')
                ->where('table.contents.status = ?', 'publish')
                ->where('(table.contents.password IS NULL OR table.contents.password = ?)', '')
                ->where('table.contents.created <= ?', time())
                ->join('table.relationships', 'table.relationships.cid = table.contents.cid')
                ->join('table.metas', 'table.metas.mid = table.relationships.mid')
                ->where('table.metas.type = ?', 'tag')
                ->where('table.metas.slug = ?', $slug)
        )->cnt;

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->build_post_row($row);
        }

        header('X-WP-Total: ' . (int)$count);
        header('X-WP-TotalPages: ' . (int)ceil($count / $per));
        header('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages');
        $this->json($items);
    }

    private function build_term_row($row, $taxonomy)
    {
        return [
            'id' => (int)$row['mid'],
            'count' => (int)$row['count'],
            'description' => $row['description'],
            'link' => Typecho_Router::url($taxonomy, $row, $this->options->index),
            'name' => $row['name'],
            'slug' => $row['slug'],
            'taxonomy' => $taxonomy === 'category' ? 'category' : 'post_tag',
            'parent' => (int)$row['parent'],
            'meta' => (object)[],
        ];
    }

    private function list_terms($taxonomy)
    {
        list($page, $per, $offset) = $this->get_pagination();

        $select = $this->db->select('table.metas.*')->from('table.metas')
            ->where('table.metas.type = ?', $taxonomy);

        // search by name/slug/description
        $search = trim((string)$this->request->get('search', ''));
        if ($search !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $select->where('(table.metas.name LIKE ? OR table.metas.slug LIKE ? OR table.metas.description LIKE ?)', $kw, $kw, $kw);
        }

        // include / exclude by term ids
        $includeParam = trim((string)$this->request->get('include', ''));
        $excludeParam = trim((string)$this->request->get('exclude', ''));
        $includeIds = $includeParam !== '' ? array_filter(array_map('intval', explode(',', $includeParam))) : [];
        $excludeIds = $excludeParam !== '' ? array_filter(array_map('intval', explode(',', $excludeParam))) : [];
        if (!empty($includeIds)) {
            $in = implode(',', array_map('intval', $includeIds));
            $select->where("table.metas.mid IN ($in)");
        }
        if (!empty($excludeIds)) {
            $in = implode(',', array_map('intval', $excludeIds));
            $select->where("NOT(table.metas.mid IN ($in))");
        }

        // slug filter (comma separated)
        $slugs = [];
        $slugParam = trim((string)$this->request->get('slug', ''));
        if ($slugParam !== '') {
            $slugs = array_filter(array_map('trim', explode(',', $slugParam)));
            if (!empty($slugs)) {
                $ph = implode(',', array_fill(0, count($slugs), '?'));
                $select->where('table.metas.slug IN (' . $ph . ')', ...$slugs);
            }
        }

        // hide_empty
        $hideEmpty = $this->request->get('hide_empty');
        if ($hideEmpty === '1' || $hideEmpty === 'true' || $hideEmpty === 1) {
            $select->where('table.metas.count > 0');
        }

        // filter terms attached to a specific post id
        $postId = (int)$this->request->get('post', 0);
        if ($postId > 0) {
            $select->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                   ->where('table.relationships.cid = ?', $postId)
                   ->group('table.metas.mid');
        }

        // orderby/order
        $orderby = strtolower(trim((string)$this->request->get('orderby', 'name')));
        $order = strtolower(trim((string)$this->request->get('order', 'asc')));
        $orderDir = ($order === 'desc') ? Typecho_Db::SORT_DESC : Typecho_Db::SORT_ASC;

        if ($orderby === 'id') {
            $select->order('table.metas.mid', $orderDir);
        } else if ($orderby === 'slug') {
            $select->order('table.metas.slug', $orderDir);
        } else if ($orderby === 'count') {
            $select->order('table.metas.count', $orderDir);
        } else if ($orderby === 'description') {
            $select->order('table.metas.description', $orderDir);
        } else if ($orderby === 'include' && !empty($includeIds)) {
            // We'll sort in PHP after fetch to respect include order
        } else {
            $select->order('table.metas.name', $orderDir);
        }

        $sortByInclude = $orderby === 'include' && !empty($includeIds);
        if (!$sortByInclude) {
            $select->limit($per)->offset($offset);
        }
        $rows = $this->db->fetchAll($select);

        // Apply the requested include order before choosing the current page.
        if ($sortByInclude) {
            $orderMap = array_flip($includeIds);
            usort($rows, function($a, $b) use ($orderMap) {
                $ia = isset($orderMap[$a['mid']]) ? $orderMap[$a['mid']] : PHP_INT_MAX;
                $ib = isset($orderMap[$b['mid']]) ? $orderMap[$b['mid']] : PHP_INT_MAX;
                return $ia <=> $ib;
            });
            $rows = array_slice($rows, $offset, $per);
        }

        // count with same filters
        $countSelect = $this->db->select(['COUNT(DISTINCT table.metas.mid)' => 'cnt'])->from('table.metas')
            ->where('table.metas.type = ?', $taxonomy);
        if ($search !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $countSelect->where('(table.metas.name LIKE ? OR table.metas.slug LIKE ? OR table.metas.description LIKE ?)', $kw, $kw, $kw);
        }
        if (!empty($includeIds)) {
            $in = implode(',', array_map('intval', $includeIds));
            $countSelect->where("table.metas.mid IN ($in)");
        }
        if (!empty($excludeIds)) {
            $in = implode(',', array_map('intval', $excludeIds));
            $countSelect->where("NOT(table.metas.mid IN ($in))");
        }
        if (!empty($slugs)) {
            $ph = implode(',', array_fill(0, count($slugs), '?'));
            $countSelect->where('table.metas.slug IN (' . $ph . ')', ...$slugs);
        }
        if ($hideEmpty === '1' || $hideEmpty === 'true' || $hideEmpty === 1) {
            $countSelect->where('table.metas.count > 0');
        }
        if ($postId > 0) {
            $countSelect->join('table.relationships', 'table.relationships.mid = table.metas.mid')
                        ->where('table.relationships.cid = ?', $postId);
        }
        $count = $this->db->fetchObject($countSelect)->cnt;

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->build_term_row($row, $taxonomy === 'category' ? 'category' : 'tag');
        }

        header('X-WP-Total: ' . (int)$count);
        header('X-WP-TotalPages: ' . (int)ceil($count / $per));
        $this->json($items);
    }

    private function get_term_item($mid, $taxonomy)
    {
        $row = $this->db->fetchRow($this->db->select()->from('table.metas')
            ->where('mid = ?', $mid)->limit(1));
        if (!$row || $row['type'] !== $taxonomy) {
            $this->json(['code' => 'not_found', 'message' => 'Not found'], 404);
        }
        $this->json($this->build_term_row($row, $taxonomy === 'category' ? 'category' : 'tag'));
    }

    public function categories() { $this->list_terms('category'); }
    public function category() { $this->get_term_item((int)$this->request->get('mid'), 'category'); }
    public function tags() { $this->list_terms('tag'); }
    public function tag() { $this->get_term_item((int)$this->request->get('mid'), 'tag'); }

    public function action()
    {
        $this->json(['code' => 'rest_no_route', 'message' => 'Not found.'], 404);
    }
}
