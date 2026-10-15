<?php
/** Pure guard checks. No Typecho config, production database, or external service is loaded. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

class Typecho_Widget_Exception extends RuntimeException {}
function _t($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
class Enhancement_Plugin {
    public static $settings;
    public static function runtimeSettings() { return self::$settings; }
}
class Typecho_Widget {
    public static function widget($name) { return (object)['siteUrl' => 'https://comment-guard-fixture.invalid/', 'secret' => 'isolated-comment-guard-secret']; }
}
require_once dirname(__DIR__, 2) . '/CommentGuardContentHelper.php';
require_once dirname(__DIR__, 2) . '/CommentGuardRateHelper.php';
require_once dirname(__DIR__, 2) . '/CommentGuardHelper.php';

function guardSettings(array $changes = []): object {
    return (object)array_replace([
        'enable_comment_guard' => '1', 'comment_guard_block_xss' => '1',
        'comment_guard_block_html' => '0', 'comment_guard_require_chinese' => '0',
        'comment_guard_blocked_keywords' => '', 'comment_guard_required_keywords' => '',
        'comment_guard_blocked_ips' => '', 'comment_guard_rate_limit' => '1',
        'comment_guard_window' => '300', 'comment_guard_ip_limit' => '5',
        'comment_guard_subnet_limit' => '20', 'comment_guard_block_duplicates' => '1',
    ], $changes);
}

// Subprocesses contend on the same file lock instead of simulating sequential requests.
if (($argv[1] ?? '') === '--rate-worker') {
    try {
        Enhancement_CommentGuardRateHelper::check('198.51.100.22', 'worker ' . $argv[3], guardSettings([
            'comment_guard_ip_limit' => '2', 'comment_guard_subnet_limit' => '100',
            'comment_guard_block_duplicates' => '0',
        ]), $argv[2], 1700000000);
        exit(0);
    } catch (Typecho_Widget_Exception $exception) {
        exit($exception->getCode() === 429 ? 29 : 30);
    }
}

$passed = 0;
$failures = [];
function guardCheck(bool $condition, string $label, $details = null): void {
    global $passed, $failures;
    if ($condition) { $passed++; return; }
    $failures[] = $label . ($details === null ? '' : ': ' . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo 'FAIL ' . end($failures) . "\n";
}
function guardResult(callable $operation): int {
    try { $operation(); return 0; }
    catch (Typecho_Widget_Exception $exception) { return (int)$exception->getCode(); }
}
function contentResult(string $text, array $changes = [], array $fields = []): int {
    return guardResult(static function () use ($text, $changes, $fields) {
        Enhancement_CommentGuardContentHelper::validate(array_replace([
            'text' => $text, 'ip' => '198.51.100.1', 'author' => 'Reader', 'url' => 'https://example.test/',
        ], $fields), guardSettings($changes));
    });
}

foreach ([
    '<script>alert(1)</script>', '<ScRiPt src="https://evil.test/x">',
    '<img src=x onerror=alert(1)>', '<img/onload=alert(1)>',
    '<svg><a onload=alert(1)></a></svg>', '<iframe srcdoc="<p>x</p>">',
    '<a href="jav&#x61;script:alert(1)">click</a>',
    '<a href="java&#9;script:alert(1)">click</a>',
    '<a href="data:text/html;base64,PHNjcmlwdD4=">click</a>',
    '<div style="background:url(javascript:alert(1))">',
    '<div style="width:expression(alert(1))">',
    '<img title="<" src=x onerror=alert(1)>',
    '<img title=">" src=x onerror=alert(1)>',
    '<a title="< >" href="javascript:alert(1)">',
    '&lt;script&gt;alert(1)&lt;/script&gt;', '%3Cscript%3Ealert(1)%3C/script%3E',
    '<scr' . "\u{200B}" . 'ipt>alert(1)</script>',
    '[click](javascript:alert(1))', '[click]: vbscript:alert(1)',
] as $attack) {
    guardCheck(contentResult($attack) === 403, 'XSS is rejected: ' . $attack);
}
guardCheck(contentResult('<img ' . str_repeat(' ', 20000) . ' onerror=alert(1)>') === 403, 'Long tag cannot exhaust regex and bypass XSS');
guardCheck(contentResult("non-UTF8 \xff <img onerror=alert(1)>") === 403, 'Malformed UTF8 fails closed with XSS rule enabled');
foreach ([
    '普通中文评论', '2 < 3 && 5 > 4', 'Use `array_map()` and `a < b` here.',
    "```php\nif (\$a < 3) { return true; }\n```", '[docs](https://example.test/docs)',
    '<a href="https://example.test/">safe link</a>', '<strong>普通 HTML</strong>',
    'Discuss javascript: URLs as plain text.',
] as $text) {
    guardCheck(contentResult($text) === 0, 'Benign comment passes: ' . $text);
}
guardCheck(contentResult('<script>x</script>', ['comment_guard_block_xss' => '0']) === 0, 'XSS rule has its own switch');
guardCheck(contentResult('plain text', [], ['author' => '<img onerror=alert(1)>']) === 403, 'Author XSS rejected');
guardCheck(contentResult('plain text', [], ['url' => 'jav&#x61;script:alert(1)']) === 403, 'URL XSS rejected');
guardCheck(contentResult('<b>中文</b>', ['comment_guard_block_html' => '1']) === 403, 'Optional HTML rule rejects safe tags');
guardCheck(contentResult('<b title="<">中文</b>', ['comment_guard_block_html' => '1']) === 403, 'HTML rule handles angle bracket inside quoted attribute');
guardCheck(contentResult('<!-- comment -->', ['comment_guard_block_html' => '1']) === 403, 'HTML rule rejects comments');
guardCheck(contentResult('a < b and b > 0', ['comment_guard_block_html' => '1']) === 0, 'HTML rule permits comparisons');
guardCheck(contentResult('English only', ['comment_guard_require_chinese' => '1']) === 403, 'Chinese requirement rejects English');
guardCheck(contentResult('English 中 text', ['comment_guard_require_chinese' => '1']) === 0, 'Chinese requirement accepts mixed language');
guardCheck(contentResult('<a title="中文">English</a>', ['comment_guard_require_chinese' => '1']) === 403, 'Hidden attribute does not satisfy Chinese rule');
guardCheck(contentResult('<!--中文-->English', ['comment_guard_require_chinese' => '1']) === 403, 'HTML comment does not satisfy Chinese rule');
guardCheck(contentResult('&#20013;文', ['comment_guard_require_chinese' => '1']) === 0, 'Chinese entity is decoded');
guardCheck(contentResult('%E4%B8%AD%E6%96%87', ['comment_guard_require_chinese' => '1']) === 403, 'Percent encoded bytes are not visible Chinese');
guardCheck(contentResult('This SPAM offer', ['comment_guard_blocked_keywords' => "spam\n广告"]) === 403, 'Blocked keywords ignore ASCII case');
guardCheck(contentResult('sp' . "\u{200B}" . 'am', ['comment_guard_blocked_keywords' => 'spam']) === 403, 'Zero width does not bypass keyword');
guardCheck(contentResult('sp&#97;m', ['comment_guard_blocked_keywords' => 'spam']) === 403, 'Entities do not bypass keyword');
guardCheck(contentResult('literal a.b content', ['comment_guard_blocked_keywords' => 'a.b']) === 403, 'Keywords are literal');
guardCheck(contentResult('axb content', ['comment_guard_blocked_keywords' => 'a.b']) === 0, 'Keywords do not become regular expressions');
guardCheck(contentResult('contains second', ['comment_guard_required_keywords' => "first\nSECOND"]) === 0, 'One required keyword is sufficient');
guardCheck(contentResult('no match', ['comment_guard_required_keywords' => "first\nsecond"]) === 403, 'Missing required keyword rejected');
guardCheck(contentResult('<a title="first">no match</a>', ['comment_guard_required_keywords' => 'first']) === 403, 'HTML attribute does not satisfy required keyword');
guardCheck(contentResult('%66irst', ['comment_guard_required_keywords' => 'first']) === 403, 'Percent encoded text does not satisfy required keyword');
guardCheck(contentResult('anything', ['comment_guard_required_keywords' => " \n\t\n"]) === 0, 'Empty lines add no keyword requirement');

$ipCases = [
    ['192.0.2.1', '192.0.2.1', true], ['192.0.2.2', '192.0.2.1', false],
    ['192.0.2.0', '192.0.2.128/24', true], ['192.0.2.255', '192.0.2.128/24', true],
    ['192.0.3.0', '192.0.2.0/24', false], ['192.0.2.127', '192.0.2.0/25', true],
    ['192.0.2.128', '192.0.2.0/25', false], ['8.8.8.8', '0.0.0.0/0', true],
    ['192.0.2.1', '192.0.2.*', true], ['192.0.3.1', '192.0.2.*', false],
    ['192.9.2.1', '192.*', true], ['2001:db8::1', '2001:db8:0:0:0:0:0:1', true],
    ['2001:db8:0:1::1', '2001:db8::/64', false], ['2001:db8::ffff', '2001:db8::/64', true],
    ['2001:db8::1', '::/0', true], ['2001:db8::1', '192.0.2.0/24', false],
    ['::ffff:192.0.2.1', '192.0.2.0/24', true], ['192.0.2.1', '::ffff:192.0.2.0/120', true],
    ['::ffff:192.0.3.1', '::ffff:192.0.2.0/120', false],
];
foreach ($ipCases as [$ip, $rule, $expected]) {
    guardCheck(Enhancement_CommentGuardContentHelper::matchesIpRule($ip, $rule) === $expected, 'IP boundary ' . $ip . ' / ' . $rule);
}
foreach (['192.0.2.1/33', '2001:db8::/129', '192.0.*.1', '256.1.2.*', '192.0.2.1/-1', 'not-an-ip'] as $rule) {
    guardCheck(Enhancement_CommentGuardContentHelper::validateIpRules($rule) === [$rule], 'Invalid IP rule detected ' . $rule);
}
guardCheck(contentResult('comment', ['comment_guard_blocked_ips' => '198.51.100.0/24']) === 403, 'IP blacklist is enforced by content validation');
foreach ([['comment_guard_blocked_ips' => '192.0.2.0/33'], ['comment_guard_trusted_proxies' => 'broken'], ['comment_guard_window' => '9'], ['comment_guard_window' => '86401'], ['comment_guard_ip_limit' => '0'], ['comment_guard_subnet_limit' => '10001']] as $invalid) {
    guardCheck(guardResult(static function () use ($invalid) { Enhancement_CommentGuardHelper::validateSettings($invalid); }) === 400, 'Settings reject invalid boundary ' . json_encode($invalid));
}
guardCheck(guardResult(static function () { Enhancement_CommentGuardHelper::validateSettings([
    'comment_guard_blocked_ips' => "192.0.2.0/24\n2001:db8::/64", 'comment_guard_trusted_proxies' => '127.*',
    'comment_guard_window' => '10', 'comment_guard_ip_limit' => '1', 'comment_guard_subnet_limit' => '10000',
]); }) === 0, 'Valid settings boundaries accepted');

$directory = sys_get_temp_dir() . '/enhancement-comment-guard-unit-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $path = $directory . '/rate.php';
    $settings = guardSettings(['comment_guard_ip_limit' => '2', 'comment_guard_subnet_limit' => '20', 'comment_guard_block_duplicates' => '0']);
    $rate = static function ($ip, $text, $options, $file, $now) {
        return guardResult(static function () use ($ip, $text, $options, $file, $now) {
            Enhancement_CommentGuardRateHelper::check($ip, $text, $options, $file, $now);
        });
    };
    guardCheck($rate('198.51.100.1', 'first', $settings, $path, 1000) === 0, 'First IP comment passes');
    guardCheck($rate('198.51.100.1', 'second', $settings, $path, 1001) === 0, 'IP limit boundary allowed');
    guardCheck($rate('198.51.100.1', 'third', $settings, $path, 1002) === 429, 'IP over limit rejected');
    guardCheck($rate('198.51.100.1', 'new window setting', guardSettings(['comment_guard_window' => '10', 'comment_guard_ip_limit' => '2', 'comment_guard_block_duplicates' => '0']), $path, 1002) === 0, 'Changing window immediately creates independent quota');
    guardCheck($rate('198.51.100.1', 'expired', $settings, $path, 1301) === 0, 'Expired rate window recovers');
    guardCheck(strpos(file_get_contents($path), '<?php') === 0, 'Rate store is guarded against direct web reading');
    guardCheck(strpos(file_get_contents($path), '198.51.100.1') === false, 'Rate store does not retain literal IP');
    $subnet = guardSettings(['comment_guard_ip_limit' => '10', 'comment_guard_subnet_limit' => '2', 'comment_guard_block_duplicates' => '0']);
    foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
        guardCheck($rate($ip, 'rotation', $subnet, $directory . '/subnet.php', 1000) === 0, 'Subnet permits rotation below threshold ' . $ip);
    }
    guardCheck($rate('198.51.100.250', 'rotation', $subnet, $directory . '/subnet.php', 1000) === 429, 'IPv4 /24 blocks rotating client addresses');
    guardCheck($rate('198.51.101.1', 'rotation', $subnet, $directory . '/subnet.php', 1000) === 0, 'Adjacent IPv4 /24 remains independent');
    guardCheck($rate('2001:db8:1::1', 'v6 first', $subnet, $directory . '/ipv6.php', 1000) === 0, 'IPv6 subnet first passes');
    guardCheck($rate('2001:db8:1::2', 'v6 second', $subnet, $directory . '/ipv6.php', 1000) === 0, 'IPv6 subnet second passes');
    guardCheck($rate('2001:db8:1::abcd', 'v6 third', $subnet, $directory . '/ipv6.php', 1000) === 429, 'IPv6 /64 aggregates rotating addresses');
    guardCheck($rate('2001:db8:1:1::1', 'v6 adjacent', $subnet, $directory . '/ipv6.php', 1000) === 0, 'Adjacent IPv6 /64 remains independent');
    $duplicates = guardSettings(['comment_guard_rate_limit' => '0']);
    guardCheck($rate('198.51.100.1', 'Repeated content', $duplicates, $directory . '/duplicate.php', 1000) === 0, 'Duplicate rule first comment passes with frequency off');
    guardCheck($rate('198.51.100.1', 'Repeated content', $duplicates, $directory . '/duplicate.php', 1001) === 429, 'Duplicate content blocked for same IP');
    guardCheck($rate('198.51.100.2', 'Repeated content', $duplicates, $directory . '/duplicate.php', 1001) === 0, 'Different readers may post the same common phrase');
    guardCheck($rate('198.51.100.2', 'Repeated content', $duplicates, $directory . '/duplicate.php', 1301) === 0, 'Duplicate expires with configured window');
    guardCheck($rate('198.51.100.1', 'x', guardSettings(['comment_guard_rate_limit' => '0', 'comment_guard_block_duplicates' => '0']), $directory . '/missing/rate.php', 1000) === 0, 'Both rate controls off require no storage');
    guardCheck($rate('198.51.100.1', 'x', $settings, $directory, 1000) === 503, 'Unwritable storage rejects instead of bypassing protection');
    file_put_contents($directory . '/corrupt.php', 'not-a-valid-store');
    guardCheck($rate('198.51.100.1', 'x', $settings, $directory . '/corrupt.php', 1000) === 503, 'Corrupt storage cannot reset or bypass protection');
    $processes = [];
    for ($i = 0; $i < 6; $i++) {
        $pipes = [];
        $processes[] = proc_open([PHP_BINARY, __FILE__, '--rate-worker', $directory . '/parallel.php', (string)$i], [['pipe', 'r'], ['file', $directory . '/worker.log', 'a'], ['file', $directory . '/worker.log', 'a']], $pipes, __DIR__, null, ['bypass_shell' => true]);
        if (isset($pipes[0])) { fclose($pipes[0]); }
    }
    $codes = array_map(static function ($process) { return is_resource($process) ? proc_close($process) : -1; }, $processes);
    guardCheck(count(array_filter($codes, static function ($code) { return $code === 0; })) === 2 && count(array_filter($codes, static function ($code) { return $code === 29; })) === 4, 'File lock enforces exact limit across six concurrent PHP processes', $codes);
} finally {
    foreach (glob($directory . '/*') as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($directory);
}
echo $passed . ' guard unit assertions passed; ' . count($failures) . " failed.\n";
exit($failures ? 1 : 0);
