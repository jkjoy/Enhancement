<?php
/** Isolated real Typecho/SQLite/HTTP tests for independent QQ management commands. */
declare(strict_types=1);
define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

function qqCommand(string $command, array $args = [], array $overrides = [], array $headers = [], ?int $timestamp = null): array
{
    global $base, $commandSecret;
    $payload = array_merge(['request_id' => bin2hex(random_bytes(32)), 'sender' => 'owner-openid', 'command' => $command, 'args' => $args], $overrides);
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $time = (string)($timestamp ?? time());
    if (!$headers) {
        $headers = ['X-Enhancement-Timestamp: ' . $time, 'X-Enhancement-Signature: ' . hash_hmac('sha256', $time . '.' . $raw, $commandSecret)];
    }
    $headers[] = 'Content-Type: application/json';
    return fixtureRequest($base, '/action/enhancement-edit?do=qq-agent-command', 'POST', $raw, $headers);
}

try {
    $address = qqAddress();
    $base = 'http://' . $address;
    $pdo = fixtureDatabase($root, $directory, $base);
    $botAddress = qqAddress();
    $botBase = 'http://' . $botAddress;
    $env = getenv();
    $env['ENHANCEMENT_QQ_TEST_DIR'] = $directory;
    $env['ENHANCEMENT_QQ_TEST_ROOT'] = $root;
    $env['ENHANCEMENT_QQ_COMMAND_HOOKS'] = '1';
    qqRespond();
    qqStartServer($botAddress, dirname(__DIR__) . '/qq-notify/fake-bot.php', $env, $directory . '/bot.log');
    qqStartServer($address, dirname(__DIR__) . '/qq-notify/router.php', $env, $directory . '/typecho.log');
    $token = 'command-test-notification-token-' . str_repeat('x', 32);
    $commandSecret = 'separate-command-secret-' . str_repeat('y', 32);
    $settings = ['qq_notify_provider' => 'standalone', 'qq_official_url' => $botBase . '/official',
        'qq_official_token' => $token, 'qq_official_targets' => 'owner'];
    qqCheck(qqConfig($settings)['status'] === 200, 'Standalone notification configuration saves');
    foreach ([['qq_agent_commands' => '1'], ['qq_agent_commands' => '1', 'qq_agent_command_secret' => $commandSecret,
        'qq_agent_allowed_senders' => '*', 'qq_agent_admin_uid' => '1'], ['qq_agent_command_secret' => ['invalid']]] as $change) {
        qqCheck(qqConfig($change)['status'] === 400, 'Incomplete or invalid command configuration is rejected', $change);
    }

    $path = '/action/enhancement-edit?do=qq-agent-command';
    qqCheck(fixtureRequest($base, $path, 'POST', [])['status'] === 404, 'Command endpoint disabled by default');
    qqCheck(qqConfig(['qq_agent_commands' => '1', 'qq_agent_command_secret' => $commandSecret,
        'qq_agent_allowed_senders' => "owner-openid\ngroup-owner-openid", 'qq_agent_admin_uid' => '1'])['status'] === 200, 'Command switch can be enabled with explicit owner and identity');
    qqCheck(fixtureRequest($base, $path)['status'] === 405, 'Command endpoint rejects GET');
    qqCheck(fixtureRequest($base, $path, 'POST', '{}', ['Content-Type: text/plain'])['status'] === 415, 'Command endpoint requires JSON content type');
    qqCheck(fixtureRequest($base, $path, 'POST', '{}', ['Content-Type: application/jsonp'])['status'] === 415, 'JSONP content type cannot masquerade as JSON');
    qqCheck(fixtureRequest($base, $path, 'POST', str_repeat('x', 16385), ['Content-Type: application/json'])['status'] === 413, 'Command body size is bounded');
    qqCheck(fixtureRequest($base, $path, 'POST', [])['status'] === 401, 'Command endpoint rejects unsigned requests');
    qqCheck(qqCommand('status', [], [], ['X-Enhancement-Timestamp: ' . time(), 'X-Enhancement-Signature: ' . str_repeat('0', 64)])['status'] === 401, 'Invalid HMAC is rejected');
    qqCheck(qqCommand('status', [], [], [], time() - 301)['status'] === 401, 'Expired signature is rejected');
    qqCheck(qqCommand('status', [], [], [], time() + 301)['status'] === 401, 'Future signature outside window is rejected');
    qqCheck(qqCommand('status', [], ['sender' => 'other-user'])['status'] === 403, 'Unauthorized sender cannot read or write blog');
    qqCheck(qqCommand('comment.delete', ['id' => 1])['status'] === 400, 'Arbitrary/destructive operation is unavailable');
    qqCheck(qqCommand('comment.approve', ['id' => '1 OR 1=1'])['status'] === 400, 'Command ID is strictly numeric');
    qqCheck(qqCommand('comments.list', ['limit' => 21])['status'] === 400, 'Comment query size is bounded');
    qqCheck(qqCommand('status', ['sql' => 'anything'])['status'] === 400, 'Unknown arguments are rejected');
    qqCommand('status'); // Initialize the receipt table for the crash-recovery fixture.
    $uncertainId = bin2hex(random_bytes(32));
    $uncertainPayload = ['request_id' => $uncertainId, 'sender' => 'owner-openid', 'command' => 'comment.approve', 'args' => ['id' => 2]];
    $uncertainRaw = json_encode($uncertainPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO typecho_qq_agent_commands(request_id,fingerprint,created) VALUES(?,?,?)')->execute([$uncertainId, hash('sha256', $uncertainRaw), time()]);
    $response = qqCommand('comment.approve', ['id' => 2], ['request_id' => $uncertainId]);
    qqCheck($response['status'] === 409 && ($response['json']['error'] ?? '') === 'command_in_progress_or_uncertain'
        && $pdo->query('SELECT status FROM typecho_comments WHERE coid=2')->fetchColumn() === 'waiting', 'Uncertain claimed command is not re-executed', $response);
    $response = qqCommand('status');
    qqCheck(($response['json']['ok'] ?? false) === true && ($response['json']['result']['comments']['waiting'] ?? 0) === 1, 'Authorized owner can query blog status', $response);
    qqCheck(!isset($response['headers']['set-cookie']) && ($response['headers']['cache-control'] ?? '') === 'no-store', 'Command response cannot create browser session and disables caches', $response);
    $response = qqCommand('comments.list');
    qqCheck(count($response['json']['result']['comments'] ?? []) === 1 && (int)$response['json']['result']['comments'][0]['coid'] === 2
        && strpos($response['body'], '@example.test') === false && strpos($response['body'], '"ip"') === false, 'Comment list returns requested status without mail/IP');
    $identity = bin2hex(random_bytes(32));
    $response = qqCommand('comment.approve', ['id' => 2], ['request_id' => $identity]);
    qqCheck(($response['json']['result']['changed'] ?? false) === true && $pdo->query('SELECT status FROM typecho_comments WHERE coid=2')->fetchColumn() === 'approved', 'Owner approval modifies real Typecho comment', $response);
    $approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM typecho_comments WHERE cid=1 AND status='approved'")->fetchColumn();
    qqCheck((int)$pdo->query('SELECT commentsNum FROM typecho_contents WHERE cid=1')->fetchColumn() === $approvedCount, 'Approval updates Typecho article comment count');
    $response = qqCommand('comment.approve', ['id' => 2], ['request_id' => $identity]);
    qqCheck(($response['json']['replayed'] ?? false) === true && count(file($directory . '/hooks.jsonl')) === 1, 'Repeated approval returns receipt without repeating plugin hooks', $response);
    qqCheck(qqCommand('comment.spam', ['id' => 2], ['request_id' => $identity])['status'] === 409, 'Reused request ID with different operation is rejected');
    qqCheck((qqCommand('comment.approve', ['id' => 2])['json']['result']['changed'] ?? true) === false, 'Already approved comment is a no-op');
    $identity = bin2hex(random_bytes(32));
    $response = qqCommand('comment.reply', ['id' => 2, 'text' => '谢谢留言🙂'], ['request_id' => $identity]);
    $replyId = (int)($response['json']['result']['id'] ?? 0);
    $reply = $pdo->query('SELECT * FROM typecho_comments WHERE coid=' . $replyId)->fetch(PDO::FETCH_ASSOC);
    qqCheck($replyId > 7 && (int)($reply['authorId'] ?? 0) === 1 && (int)($reply['parent'] ?? 0) === 2
        && ($reply['text'] ?? '') === '谢谢留言🙂', 'Reply uses configured administrator and correct parent', $response);
    qqCheck(!isset($response['headers']['set-cookie']), 'Reply does not issue administrator browser login cookies');
    $before = (int)$pdo->query('SELECT COUNT(*) FROM typecho_comments')->fetchColumn();
    $response = qqCommand('comment.reply', ['id' => 2, 'text' => '谢谢留言🙂'], ['request_id' => $identity]);
    qqCheck(($response['json']['replayed'] ?? false) === true && (int)$pdo->query('SELECT COUNT(*) FROM typecho_comments')->fetchColumn() === $before,
        'Repeated QQ reply cannot insert a second comment');
    qqCheck(count(file($directory . '/hooks.jsonl')) === 2 && strpos(file_get_contents($directory . '/hooks.jsonl'), 'finish') !== false, 'Reply runs Typecho finishComment hook once');
    qqCheck(qqCommand('comment.reply', ['id' => 2, 'text' => '<img src=x onerror=alert(1)>'])['status'] === 400, 'Reply rejects HTML injection');
    foreach (['[链接](javascript:alert(1))', '&lt;img src=x onerror=alert(1)&gt;', '%3Cscript%3Ealert(1)%3C/script%3E'] as $unsafeText) {
        qqCheck(qqCommand('comment.reply', ['id' => 2, 'text' => $unsafeText])['status'] === 400, 'QQ reply applies existing XSS checks to Markdown and encoded tags', $unsafeText);
    }
    qqCheck(qqCommand('comment.reply', ['id' => 2, 'text' => str_repeat('x', 4001)])['status'] === 400, 'Reply size is bounded');
    qqCheck(qqCommand('comment.reply', ['id' => 3, 'text' => 'spam parent'])['status'] === 409, 'Unapproved parent cannot receive published reply');
    qqCheck(qqCommand('comment.approve', ['id' => 99999])['status'] === 404, 'Missing comment has explicit failure');
    $response = qqCommand('comment.spam', ['id' => 2]);
    qqCheck(($response['json']['result']['status'] ?? '') === 'spam', 'Spam command works');
    $response = qqCommand('comment.waiting', ['id' => 2]);
    qqCheck(($response['json']['result']['status'] ?? '') === 'waiting', 'Waiting command works');
    $approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM typecho_comments WHERE cid=1 AND status='approved'")->fetchColumn();
    qqCheck((int)$pdo->query('SELECT commentsNum FROM typecho_contents WHERE cid=1')->fetchColumn() === $approvedCount, 'Moderation and reply maintain exact approved count');
    $response = qqCommand('links.list');
    qqCheck(count($response['json']['result']['links'] ?? []) === 1 && (int)$response['json']['result']['links'][0]['lid'] === 2, 'Pending friend links can be queried');
    qqCheck((qqCommand('link.approve', ['id' => 2])['json']['result']['state'] ?? '') === '1'
        && (int)$pdo->query('SELECT state FROM typecho_links WHERE lid=2')->fetchColumn() === 1, 'Friend-link approval modifies real record');
    qqCheck((qqCommand('link.reject', ['id' => 2])['json']['result']['state'] ?? '') === '0', 'Friend-link rejection hides it');
    qqCheck(qqCommand('link.approve', ['id' => 99999])['status'] === 404, 'Missing friend link has explicit failure');
    qqConfig(['qq_agent_admin_uid' => '2']);
    qqCheck(qqCommand('status')['status'] === 403, 'Configured user must still be a Typecho administrator');
    qqConfig(['qq_agent_admin_uid' => '1']);

    qqCheck(qqConfig(['qq_agent_command_secret' => ''])['status'] === 200
        && qqSettings()['qq_agent_command_secret'] === $commandSecret, 'Empty command password preserves existing secret');
    $form = fixtureRequest($base, '/form');
    qqCheck($form['status'] === 200 && strpos($form['body'], $commandSecret) === false
        && strpos($form['body'], $token) === false && strpos($form['body'], 'name="qq_agent_commands"') !== false,
        'Standalone command settings remain available without exposing either secret');
    qqCheck(qqConfig(['qq_notify_provider' => 'official'])['status'] === 400,
        'Switching to Webhook mode requires disabling management commands');
    foreach (['openclaw', 'hermes'] as $retiredProvider) {
        $pdo->prepare("UPDATE typecho_options SET value=? WHERE name='plugin:Enhancement' AND user=0")
            ->execute([serialize(array_replace(qqSettings(), ['qq_notify_provider' => $retiredProvider]))]);
        qqCheck(qqCommand('status')['status'] === 404, 'Retired provider cannot execute signed management commands: ' . $retiredProvider);
    }
    $pdo->prepare("UPDATE typecho_options SET value=? WHERE name='plugin:Enhancement' AND user=0")
        ->execute([serialize(array_replace(qqSettings(), ['qq_notify_provider' => 'standalone']))]);
    qqConfig(['qq_agent_commands' => '0']);
    qqCheck(qqCommand('status')['status'] === 404, 'Turning command switch off immediately blocks signed requests');
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo 'FAIL ' . $exception->getMessage() . "\n";
} finally {
    foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
    $pdo = null;
}
echo 'QQ command checks passed: ' . $passed . '; failed: ' . count($failures) . "\n";
if (!$failures) {
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
} else {
    echo 'Fixtures retained: ' . $directory . "\n";
}
exit($failures ? 1 : 0);
