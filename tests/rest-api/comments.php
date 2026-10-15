<?php
/** Executed within run.php after its general resource assertions. */
if (PHP_SAPI !== 'cli' || !isset($pdo, $base, $directory)) { http_response_code(404); exit; }
$valid = ['post' => 1, 'author_name' => 'REST reader', 'author_email' => 'rest-reader@example.test', 'content' => 'REST fixture comment'];
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', '{invalid', ['Content-Type: application/json']);
check($r['status'] === 400 && ($r['json']['code'] ?? '') === 'rest_invalid_json', 'Malformed JSON rejected', $r);
foreach ([['post' => 0], ['content' => ''], ['author_name' => ''], ['author_email' => 'invalid']] as $invalid) {
    $r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', array_replace($valid, $invalid));
    check($r['status'] === 400, 'Invalid comment rejected ' . implode(',', array_keys($invalid)), $r);
}
foreach ([3, 4, 5, 8, 9] as $id) {
    $r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', array_replace($valid, ['post' => $id]));
    check(in_array($r['status'], [403, 404], true), 'Comment rejected on hidden content ' . $id, $r);
}
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', array_replace($valid, ['post' => 7]));
check($r['status'] === 403, 'Closed comments rejected', $r);
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', array_replace($valid, ['parent' => 7]));
check($r['status'] === 400, 'Cross-post parent rejected', $r);
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', $valid);
check($r['status'] === 201 && in_array($r['json']['status'] ?? '', ['hold', 'waiting'], true), 'Valid JSON comment awaits moderation', $r);
$id = (int)($r['json']['id'] ?? 0);
$stored = $pdo->query('SELECT * FROM typecho_comments WHERE coid = ' . $id)->fetch(PDO::FETCH_ASSOC);
check($stored && $stored['status'] === 'waiting' && str_contains($stored['text'], '[fixture-filter]'), 'Core comment filter ran before insertion', $stored);
$hooks = is_file($directory . '/comment-hooks.json') ? json_decode(file_get_contents($directory . '/comment-hooks.json'), true) : [];
check(($hooks['id'] ?? 0) === $id && $id > 0, 'Core finishComment hook ran', $hooks);
check((int)$pdo->query('SELECT commentsNum FROM typecho_contents WHERE cid = 1')->fetchColumn() === 1, 'Waiting comment does not increment approved count');
fixtureRequest($base, '/__fixture/options', 'POST', ['commentsRequireModeration' => 0]);
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', array_replace($valid, ['content' => 'Auto approved fixture comment', 'author_name' => 'Another REST reader']));
check($r['status'] === 201 && ($r['json']['status'] ?? '') === 'approved', 'Core no-moderation setting approves comment', $r);
check((int)$pdo->query('SELECT commentsNum FROM typecho_contents WHERE cid = 1')->fetchColumn() === 2, 'Approved comment updates core count');
fixtureRequest($base, '/__fixture/options', 'POST', ['commentsPostIntervalEnable' => 1]);
$pdo->exec('UPDATE typecho_comments SET created = ' . (time() - 2) . ' WHERE cid = 1 AND ip = \'127.0.0.1\'');
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', array_replace($valid, ['content' => 'Immediate repeat fixture comment']));
check($r['status'] === 429 || $r['status'] === 403, 'Core comment interval prevents immediate repeated posting', $r);
fixtureRequest($base, '/__fixture/options', 'POST', ['commentsPostIntervalEnable' => 0, 'commentsCheckReferer' => 1]);
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', array_replace($valid, ['content' => 'Referer validation fixture']));
check($r['status'] === 403, 'Core referer requirement applies to REST comments', $r);
fixtureRequest($base, '/__fixture/options', 'POST', ['commentsCheckReferer' => 0]);
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', http_build_query(array_replace($valid, ['content' => 'Form encoded fixture comment'])), ['Content-Type: application/x-www-form-urlencoded']);
check($r['status'] === 201 && ($r['json']['status'] ?? '') === 'approved', 'Form encoded comment uses core workflow', $r);
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', array_replace($valid, ['content' => 'No User Agent fixture comment']), ['User-Agent:']);
check($r['status'] === 201, 'Comment without User-Agent is accepted by core bridge', $r);
$r = fixtureRequest($base, '/__fixture/auth', 'POST', []);
$cookies = [];
foreach ($r['raw_headers'] as $header) {
    if (stripos($header, 'Set-Cookie:') === 0) { $cookies[] = trim(explode(';', substr($header, 11), 2)[0]); }
}
$token = $r['json']['token'] ?? '';
check(!empty($r['json']['logged_in']) && $cookies && $token !== '', 'Fixture creates real Typecho login', $r);
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', array_replace($valid, ['content' => 'Logged in missing CSRF fixture']), ['Cookie: ' . implode('; ', $cookies)]);
check($r['status'] === 403, 'Logged in comment requires Typecho CSRF token', $r);
$r = fixtureRequest($base, '/wp-json/wp/v2/comments', 'POST', array_replace($valid, ['content' => 'Logged in valid CSRF fixture', '_' => $token]), ['Cookie: ' . implode('; ', $cookies)]);
check($r['status'] === 201 && ($r['json']['author'] ?? 0) === 1, 'Logged in comment with valid token uses authenticated author', $r);
