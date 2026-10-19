<?php
/** Offline feed parser and URL security checks; no Typecho, database, or external requests. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/FriendFeedHelper.php';

$passed = 0;
$failures = array();
function feedCheck($condition, $label)
{
    global $passed, $failures;
    if ($condition) {
        $passed++;
    } else {
        $failures[] = $label;
        fwrite(STDERR, 'FAIL ' . $label . "\n");
    }
}
function feedThrows($operation)
{
    try { $operation(); } catch (RuntimeException $exception) { return true; }
    return false;
}
function feedPrivate($method, array $arguments)
{
    $reflection = new ReflectionMethod('Enhancement_FriendFeedHelper', $method);
    if (PHP_VERSION_ID < 80100) { $reflection->setAccessible(true); }
    return $reflection->invokeArgs(null, $arguments);
}

$rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel><title>Fixture</title>
<item><title><![CDATA[旧文章 &amp; 测试]]></title><link>../old#read</link><pubDate>Mon, 01 Jan 2024 00:00:00 +0000</pubDate><description><![CDATA[<p>第一段</p><p>第二段 &amp; 内容</p><script>hidden()</script>]]></description><dc:creator>作者甲</dc:creator></item>
<item><title><![CDATA[<b>新文章</b>]]></title><link>https://friend.example/new</link><pubDate>Tue, 02 Jan 2024 00:00:00 +0000</pubDate><content:encoded><![CDATA[&lt;p&gt;内容&lt;/p&gt;]]></content:encoded><author>Author B</author></item>
<item><title>重复</title><link>https://friend.example/new#duplicate</link></item>
<item><title>未来</title><link>/future</link><pubDate>Fri, 01 Jan 2100 00:00:00 +0000</pubDate></item>
<item><title>恶意链接</title><link>javascript:alert(1)</link></item>
<item><title>私网链接</title><link>http://127.0.0.1/admin</link></item>
<item><title>缺少链接</title></item>
<item><title>无日期</title><guid isPermaLink="true">https://friend.example/no-date</guid></item>
</channel></rss>
XML;
$items = Enhancement_FriendFeedHelper::parse($rss, 'https://friend.example/blog/rss.xml');
feedCheck(count($items) === 3, 'RSS skips duplicates, future dates, unsafe and missing links');
feedCheck($items[0]['title'] === '新文章' && $items[0]['published'] === 1704153600, 'RSS newest first and title markup removed');
feedCheck($items[0]['summary'] === '内容' && $items[0]['author'] === 'Author B', 'RSS content:encoded fallback and author');
feedCheck($items[1]['url'] === 'https://friend.example/old', 'Relative RSS URL resolves and loses fragment');
feedCheck($items[1]['summary'] === '第一段 第二段 & 内容' && $items[1]['author'] === '作者甲', 'RSS plain summary, entities and dc:creator');
feedCheck($items[2]['published'] === 0, 'Undated items follow dated articles');

$atom = <<<'XML'
<feed xmlns="http://www.w3.org/2005/Atom" xml:base="https://friend.example/blog/">
<title>Atom</title><entry xml:base="posts/">
<title type="html">&lt;strong&gt;Atom &amp;amp; 标题&lt;/strong&gt;</title><id>urn:uuid:fixture</id>
<link rel="self" href="atom.xml"/><link rel="alternate" href="../article?a=1&amp;b=2"/>
<published>2024-03-20T12:34:56+08:00</published><updated>2024-03-21T00:00:00Z</updated>
<author><name>作者 &amp; 名称</name></author><summary type="xhtml"><div xmlns="http://www.w3.org/1999/xhtml"><p>第一段</p><p>第二段</p><script>hidden()</script></div></summary>
</entry><entry><title>updated fallback</title><link href="/updated"/><updated>2024-03-19T04:34:56Z</updated><content type="html">&lt;p&gt;内容&lt;/p&gt;</content></entry></feed>
XML;
$items = Enhancement_FriendFeedHelper::parse($atom, 'https://feed.example/feed.xml');
feedCheck(count($items) === 2, 'Atom entries accepted');
feedCheck($items[0]['url'] === 'https://friend.example/blog/article?a=1&b=2', 'Atom alternate URL, inherited XML base and entities');
feedCheck($items[0]['published'] === 1710909296, 'Atom publication date takes priority with timezone');
feedCheck($items[0]['title'] === 'Atom & 标题' && $items[0]['author'] === '作者 & 名称', 'Atom HTML title and author become plain text');
feedCheck($items[0]['summary'] === '第一段 第二段', 'Atom XHTML preserves paragraphs and removes script content');
feedCheck($items[1]['summary'] === '内容' && $items[1]['published'] === 1710822896, 'Atom updated and content fallback');

$rdf = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel/><item><title>RDF</title><link>https://friend.example/rdf</link><dc:date>2024-01-01T00:00:00Z</dc:date></item></rdf:RDF>';
$items = Enhancement_FriendFeedHelper::parse($rdf, 'https://friend.example/feed');
feedCheck(count($items) === 1 && $items[0]['published'] === 1704067200, 'RSS 1.0 RDF supported');
feedCheck(Enhancement_FriendFeedHelper::parse('<rss version="2.0"><channel/></rss>', 'https://friend.example/feed') === array(), 'Empty valid RSS is successful');
feedCheck(Enhancement_FriendFeedHelper::parse('<feed xmlns="http://www.w3.org/2005/Atom"/>', 'https://friend.example/feed') === array(), 'Empty valid Atom is successful');

foreach (array('', '   ', '<html><head/><body>not a feed</body></html>', '<rss/>', '<rss><channel>', '<feed/>', '<!DOCTYPE rss [<!ENTITY xx SYSTEM "file:///etc/passwd">]><rss><channel>&xx;</channel></rss>', '<!DOCTYPE rss SYSTEM "http://127.0.0.1/secret"><rss><channel/></rss>', str_repeat('x', 1048577)) as $invalid) {
    feedCheck(feedThrows(static function () use ($invalid) { Enhancement_FriendFeedHelper::parse($invalid, 'https://friend.example/feed'); }), 'Invalid, DTD, XXE, or oversized XML rejected');
}

$many = '<rss><channel>';
for ($i = 0; $i < 80; $i++) {
    $many .= '<item><title>' . $i . '</title><link>https://friend.example/' . $i . '</link><pubDate>' . gmdate(DATE_RSS, 1700000000 + $i * 3600) . '</pubDate><description>' . str_repeat('中', 800) . '</description></item>';
}
$many .= '</channel></rss>';
$items = Enhancement_FriendFeedHelper::parse($many, 'https://friend.example/feed');
feedCheck(count($items) === 50, 'Maximum of 50 parsed articles');
feedCheck($items[0]['title'] === '79' && $items[49]['title'] === '30', 'Unsorted feed returns the latest 50 articles after sorting');
feedCheck(preg_match('/^中{600}$/u', $items[0]['summary']) === 1, 'Summary length is bounded without cutting UTF-8');

foreach (array(
    array('../post', 'https://friend.example/blog/feed.xml', 'https://friend.example/post'),
    array('./post/', 'https://friend.example/blog/feed.xml', 'https://friend.example/blog/post/'),
    array('/a/../b/.', 'https://friend.example/feed', 'https://friend.example/b/'),
    array('?page=2', 'https://friend.example/feed?p=1', 'https://friend.example/feed?page=2'),
    array('#fragment', 'https://friend.example/feed?p=1', 'https://friend.example/feed?p=1'),
    array('//other.example/feed', 'https://friend.example/feed', 'https://other.example/feed'),
    array('HTTPS://Friend.Example:443/path#fragment', 'https://friend.example/', 'https://friend.example/path'),
    array('https://8.8.8.8/feed', 'https://friend.example/', 'https://8.8.8.8/feed'),
    array('https://[2606:4700:4700::1111]/feed', 'https://friend.example/', 'https://[2606:4700:4700::1111]/feed'),
) as $case) {
    feedCheck(Enhancement_FriendFeedHelper::resolveUrl($case[0], $case[1]) === $case[2], 'URL resolution: ' . $case[0]);
}
foreach (array(
    'file:///etc/passwd', 'ftp://friend.example/feed', 'javascript:alert(1)', 'http://localhost/', 'http://localhost.localdomain/',
    'http://service.local/', 'http://service.internal/', 'http://127.0.0.1/', 'http://127.1/', 'http://2130706433/',
    'http://0177.0.0.1/', 'http://0x7f000001/', 'http://0.0.0.0/', 'http://10.1.1.1/', 'http://172.16.1.1/',
    'http://192.168.1.1/', 'http://169.254.169.254/', 'http://100.64.1.1/', 'http://224.0.0.1/', 'http://198.18.0.1/',
    'http://198.51.100.1/', 'http://203.0.113.1/', 'http://192.88.99.1/', 'http://[3fff::1]/', 'http://[3ffe::1]/',
    'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://[fe80::1]/', 'http://[fc00::1]/', 'http://[2002:7f00:1::]/',
    'https://user:password@friend.example/feed', "https://friend.example/\r\nHeader:value", 'http://friend.example\\@127.0.0.1/',
    'https://friend.example:0/', 'https://friend.example:65536/',
) as $unsafe) {
    feedCheck(Enhancement_FriendFeedHelper::normalizeUrl($unsafe) === '', 'Unsafe URL rejected: ' . $unsafe);
}
feedCheck(Enhancement_FriendFeedHelper::resolveUrl('//127.0.0.1/private', 'https://friend.example/feed') === '', 'Redirect resolution rejects private destination');
feedCheck(feedThrows(static function () { feedPrivate('publicAddresses', array('127.0.0.1')); }), 'DNS address policy rejects loopback literal without a request');
feedCheck(feedPrivate('publicAddresses', array('8.8.8.8')) === array('8.8.8.8'), 'DNS address policy permits public literal');
$html = '<html><head><base href="https://friend.example/blog/"><link rel="alternate stylesheet" type="text/css" href="style.css"><link rel="ALTERNATE" type="application/atom+xml; charset=utf-8" href="atom.xml"><link rel="alternate" type="application/rss+xml" href="//127.0.0.1/private"><link rel="alternate" type="application/rss+xml" href="rss.xml"></head></html>';
feedCheck(feedPrivate('discover', array($html, 'https://friend.example/')) === array('https://friend.example/blog/atom.xml', 'https://friend.example/blog/rss.xml'), 'HTML discovery honors base, recognizes feeds and filters unsafe URLs');
feedCheck(feedThrows(static function () { Enhancement_FriendFeedHelper::fetch('https://friend.example/', 'http://127.0.0.1/'); }), 'Manual private feed fails before networking');

echo $passed . ' feed checks passed' . ($failures ? ', ' . count($failures) . ' failed' : '') . ".\n";
exit($failures ? 1 : 0);
