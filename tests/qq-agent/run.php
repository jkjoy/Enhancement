<?php
/** Isolated real Typecho/SQLite/HTTP tests for existing Agent QQ channels. */
declare(strict_types=1);
define('ENHANCEMENT_QQ_FIXTURE_LIBRARY', true);
require dirname(__DIR__) . '/qq-notify/run.php';

function agentCommand(string $command, array $args = [], array $overrides = [], array $headers = [], ?int $timestamp = null): array
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
    $env['ENHANCEMENT_QQ_AGENT_HOOKS'] = '1';
    qqRespond(200, '__AUTO_AGENT__');
    qqStartServer($botAddress, dirname(__DIR__) . '/qq-notify/fake-bot.php', $env, $directory . '/bot.log');
    qqStartServer($address, dirname(__DIR__) . '/qq-notify/router.php', $env, $directory . '/typecho.log');
    $token = 'agent-notification-token-' . str_repeat('x', 32);
    $commandSecret = 'separate-command-secret-' . str_repeat('y', 32);
    $settings = ['qq_notify_provider' => 'openclaw', 'qq_agent_url' => $botBase . '/tools/invoke', 'qq_agent_token' => $token,
        'qq_agent_target' => 'qqbot:c2c:owner-openid', 'qq_agent_account' => 'blog', 'qq_agent_include_links' => '1'];
    $response = qqConfig($settings);
    qqCheck($response['status'] === 200, 'OpenClaw configuration saves', $response);
    $response = qqCall('/test', ['message' => '博客通知']);
    $request = qqLastRequest();
    qqCheck(($response['json']['success'] ?? false) === true && ($response['json']['provider'] ?? '') === 'openclaw', 'OpenClaw test reaches existing channel', $response);
    qqCheck($request['path'] === '/tools/invoke' && $request['authorization'] === 'Bearer ' . $token
        && $request['payload']['tool'] === 'message' && $request['payload']['args'] === ['action' => 'send', 'channel' => 'qqbot',
            'target' => 'qqbot:c2c:owner-openid', 'message' => '博客通知', 'accountId' => 'blog'], 'OpenClaw uses literal QQ message tool and account', $request);
    foreach ([[200, ''], [200, ['ok' => false]], [200, ['ok' => true, 'result' => ['isError' => true]]], [200, ['ok' => true]],
        [200, ['ok' => true, 'result' => []]], [503, ['ok' => true, 'result' => []]]] as [$code, $body]) {
        qqRespond($code, $body);
        qqCheck((qqCall('/test')['json']['success'] ?? true) === false, 'OpenClaw rejects missing/error receipt HTTP ' . $code);
    }
    qqRespond(302, '', $botBase . '/credential-trap');
    $before = count(qqRequests());
    qqCheck((qqCall('/test')['json']['success'] ?? true) === false && count(qqRequests()) === $before + 1, 'OpenClaw does not follow redirects with Gateway token');
    qqRespond(200, '__AUTO_AGENT__');
    qqConfig(['qq_agent_target' => 'qqbot:group:group-openid', 'qq_agent_include_links' => '0']);
    qqCall('/test', ['message' => "<b>通知</b> https://example.test/a\n" . str_repeat('中文🙂', 600)]);
    $text = qqLastRequest()['payload']['args']['message'];
    qqCheck(strlen($text) <= 1800 && preg_match('//u', $text) === 1 && strpos($text, 'https://') === false && strpos($text, '<b>') === false,
        'Agent notification removes HTML/URLs and preserves UTF-8 bounds');
    qqCheck(qqLastRequest()['payload']['args']['target'] === 'qqbot:group:group-openid', 'OpenClaw group target is preserved');

    qqConfig(['qq_notify_provider' => 'hermes', 'qq_agent_url' => $botBase . '/webhooks/enhancement']);
    $response = qqCall('/test', ['message' => '群聊通知']);
    $request = qqLastRequest();
    qqCheck(($response['json']['success'] ?? false) === true && ($response['json']['provider'] ?? '') === 'hermes', 'Hermes direct-delivery receipt accepted', $response);
    qqCheck($request['payload']['message'] === '群聊通知' && $request['payload']['chat_id'] === 'group-openid'
        && $request['authorization'] === '' && $request['payload']['event_type'] === 'enhancement.notification', 'Hermes receives literal message and native OpenID', $request);
    qqCheck(hash_equals(hash_hmac('sha256', $request['timestamp'] . '.' . $request['raw'], $token), $request['signature'])
        && abs(time() - (int)$request['timestamp']) < 5, 'Hermes V2 HMAC covers exact timestamp and raw body', $request);
    qqCheck($request['request_id'] === $request['payload']['event_id'] . '-0', 'Hermes includes a bounded delivery ID');
    foreach ([['status' => 'accepted'], ['status' => 'delivered', 'delivery_id' => 'wrong'], ['status' => 'failed']] as $body) {
        qqRespond(200, $body);
        qqCheck((qqCall('/test')['json']['success'] ?? true) === false, 'Hermes rejects non-delivery or unrelated receipt');
    }

    // Persist first, retry immediately via the existing queue, without redirecting old jobs.
    qqConfig(['enable_comment_by_qq' => '1']);
    qqCall('/process');
    qqClearQueue();
    qqRespond(503, []);
    qqCall('/dispatch', ['message' => '持久通知']);
    $queue = qqQueue();
    $prefix = '__ENHANCEMENT_QQ_AGENT_V1__:';
    $envelope = json_decode(substr($queue[0]['message'], strlen($prefix)), true);
    qqCheck(count($queue) === 1 && (int)$queue[0]['status'] === 0 && $envelope['provider'] === 'hermes'
        && strpos($queue[0]['message'], $token) === false, 'Failed Agent notification persists without token', $queue);
    $firstId = qqLastRequest()['request_id'];
    qqConfig(['qq_agent_target' => 'qqbot:c2c:changed-user']);
    qqRespond(200, '__AUTO_AGENT__');
    qqCall('/process');
    qqCheck((int)qqQueue()[0]['status'] === 1 && qqLastRequest()['payload']['chat_id'] === 'group-openid', 'Retry uses snapshotted target');
    qqCheck(qqLastRequest()['payload']['event_id'] === $envelope['requestId'] && qqLastRequest()['request_id'] !== $firstId,
        'Hermes retry retains event ID and changes attempt ID after rejected delivery');
    qqClearQueue();
    qqRespond(503, []);
    qqCall('/dispatch');
    qqConfig(['qq_agent_url' => $botBase . '/other']);
    $before = count(qqRequests());
    qqCall('/process');
    qqCheck(count(qqRequests()) === $before && (int)qqQueue()[0]['status'] === 0, 'URL change blocks sending current secret to old queue endpoint');
    qqConfig(['qq_notify_provider' => 'openclaw', 'qq_agent_url' => $botBase . '/tools/invoke']);
    qqCall('/process');
    qqCheck(count(qqRequests()) === $before, 'Provider switching cannot send Hermes records through OpenClaw');
    qqClearQueue();

    // Saving configuration cannot expose/erase credentials or bypass command requirements.
    qqConfig(['qq_agent_token' => '', 'qq_agent_command_secret' => $commandSecret]);
    qqCheck(qqSettings()['qq_agent_token'] === $token, 'Empty password save preserves Agent notification token');
    $form = fixtureRequest($base, '/form');
    qqCheck(strpos($form['body'], $token) === false && strpos($form['body'], $commandSecret) === false
        && strpos($form['body'], 'qq_agent_commands') !== false, 'Real config form provides command switch and hides both secrets');
    foreach ([['qq_agent_url' => 'file:///etc/passwd'], ['qq_agent_url' => 'https://user:pass@example.test/x'],
        ['qq_agent_target' => '123456789'], ['qq_agent_token' => 'short'], ['qq_agent_commands' => '1'],
        ['qq_agent_commands' => '1', 'qq_agent_allowed_senders' => '*', 'qq_agent_admin_uid' => '1']] as $change) {
        qqCheck(qqConfig($change)['status'] === 400, 'Invalid Agent configuration is rejected', $change);
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
    qqCheck(agentCommand('status', [], [], ['X-Enhancement-Timestamp: ' . time(), 'X-Enhancement-Signature: ' . str_repeat('0', 64)])['status'] === 401, 'Invalid HMAC is rejected');
    qqCheck(agentCommand('status', [], [], [], time() - 301)['status'] === 401, 'Expired signature is rejected');
    qqCheck(agentCommand('status', [], [], [], time() + 301)['status'] === 401, 'Future signature outside window is rejected');
    qqCheck(agentCommand('status', [], ['sender' => 'other-user'])['status'] === 403, 'Unauthorized sender cannot read or write blog');
    qqCheck(agentCommand('comment.delete', ['id' => 1])['status'] === 400, 'Arbitrary/destructive operation is unavailable');
    qqCheck(agentCommand('comment.approve', ['id' => '1 OR 1=1'])['status'] === 400, 'Command ID is strictly numeric');
    qqCheck(agentCommand('comments.list', ['limit' => 21])['status'] === 400, 'Comment query size is bounded');
    qqCheck(agentCommand('status', ['sql' => 'anything'])['status'] === 400, 'Unknown arguments are rejected');
    agentCommand('status'); // Initialize the receipt table for the crash-recovery fixture.
    $uncertainId = bin2hex(random_bytes(32));
    $uncertainPayload = ['request_id' => $uncertainId, 'sender' => 'owner-openid', 'command' => 'comment.approve', 'args' => ['id' => 2]];
    $uncertainRaw = json_encode($uncertainPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO typecho_qq_agent_commands(request_id,fingerprint,created) VALUES(?,?,?)')->execute([$uncertainId, hash('sha256', $uncertainRaw), time()]);
    $response = agentCommand('comment.approve', ['id' => 2], ['request_id' => $uncertainId]);
    qqCheck($response['status'] === 409 && ($response['json']['error'] ?? '') === 'command_in_progress_or_uncertain'
        && $pdo->query('SELECT status FROM typecho_comments WHERE coid=2')->fetchColumn() === 'waiting', 'Uncertain claimed command is not re-executed', $response);
    $response = agentCommand('status');
    qqCheck(($response['json']['ok'] ?? false) === true && ($response['json']['result']['comments']['waiting'] ?? 0) === 1, 'Authorized owner can query blog status', $response);
    qqCheck(!isset($response['headers']['set-cookie']) && ($response['headers']['cache-control'] ?? '') === 'no-store', 'Command response cannot create browser session and disables caches', $response);
    $response = agentCommand('comments.list');
    qqCheck(count($response['json']['result']['comments'] ?? []) === 1 && (int)$response['json']['result']['comments'][0]['coid'] === 2
        && strpos($response['body'], '@example.test') === false && strpos($response['body'], '"ip"') === false, 'Comment list returns requested status without mail/IP');
    $identity = bin2hex(random_bytes(32));
    $response = agentCommand('comment.approve', ['id' => 2], ['request_id' => $identity]);
    qqCheck(($response['json']['result']['changed'] ?? false) === true && $pdo->query('SELECT status FROM typecho_comments WHERE coid=2')->fetchColumn() === 'approved', 'Owner approval modifies real Typecho comment', $response);
    $approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM typecho_comments WHERE cid=1 AND status='approved'")->fetchColumn();
    qqCheck((int)$pdo->query('SELECT commentsNum FROM typecho_contents WHERE cid=1')->fetchColumn() === $approvedCount, 'Approval updates Typecho article comment count');
    $response = agentCommand('comment.approve', ['id' => 2], ['request_id' => $identity]);
    qqCheck(($response['json']['replayed'] ?? false) === true && count(file($directory . '/hooks.jsonl')) === 1, 'Repeated approval returns receipt without repeating plugin hooks', $response);
    qqCheck(agentCommand('comment.spam', ['id' => 2], ['request_id' => $identity])['status'] === 409, 'Reused request ID with different operation is rejected');
    qqCheck((agentCommand('comment.approve', ['id' => 2])['json']['result']['changed'] ?? true) === false, 'Already approved comment is a no-op');
    $identity = bin2hex(random_bytes(32));
    $response = agentCommand('comment.reply', ['id' => 2, 'text' => '谢谢留言🙂'], ['request_id' => $identity]);
    $replyId = (int)($response['json']['result']['id'] ?? 0);
    $reply = $pdo->query('SELECT * FROM typecho_comments WHERE coid=' . $replyId)->fetch(PDO::FETCH_ASSOC);
    qqCheck($replyId > 7 && (int)($reply['authorId'] ?? 0) === 1 && (int)($reply['parent'] ?? 0) === 2
        && ($reply['text'] ?? '') === '谢谢留言🙂', 'Reply uses configured administrator and correct parent', $response);
    qqCheck(!isset($response['headers']['set-cookie']), 'Reply does not issue administrator browser login cookies');
    $before = (int)$pdo->query('SELECT COUNT(*) FROM typecho_comments')->fetchColumn();
    $response = agentCommand('comment.reply', ['id' => 2, 'text' => '谢谢留言🙂'], ['request_id' => $identity]);
    qqCheck(($response['json']['replayed'] ?? false) === true && (int)$pdo->query('SELECT COUNT(*) FROM typecho_comments')->fetchColumn() === $before,
        'Repeated QQ reply cannot insert a second comment');
    qqCheck(count(file($directory . '/hooks.jsonl')) === 2 && strpos(file_get_contents($directory . '/hooks.jsonl'), 'finish') !== false, 'Reply runs Typecho finishComment hook once');
    qqCheck(agentCommand('comment.reply', ['id' => 2, 'text' => '<img src=x onerror=alert(1)>'])['status'] === 400, 'Reply rejects HTML injection');
    foreach (['[链接](javascript:alert(1))', '&lt;img src=x onerror=alert(1)&gt;', '%3Cscript%3Ealert(1)%3C/script%3E'] as $unsafeText) {
        qqCheck(agentCommand('comment.reply', ['id' => 2, 'text' => $unsafeText])['status'] === 400, 'QQ reply applies existing XSS checks to Markdown and encoded tags', $unsafeText);
    }
    qqCheck(agentCommand('comment.reply', ['id' => 2, 'text' => str_repeat('x', 4001)])['status'] === 400, 'Reply size is bounded');
    qqCheck(agentCommand('comment.reply', ['id' => 3, 'text' => 'spam parent'])['status'] === 409, 'Unapproved parent cannot receive published reply');
    qqCheck(agentCommand('comment.approve', ['id' => 99999])['status'] === 404, 'Missing comment has explicit failure');
    $response = agentCommand('comment.spam', ['id' => 2]);
    qqCheck(($response['json']['result']['status'] ?? '') === 'spam', 'Spam command works');
    $response = agentCommand('comment.waiting', ['id' => 2]);
    qqCheck(($response['json']['result']['status'] ?? '') === 'waiting', 'Waiting command works');
    $approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM typecho_comments WHERE cid=1 AND status='approved'")->fetchColumn();
    qqCheck((int)$pdo->query('SELECT commentsNum FROM typecho_contents WHERE cid=1')->fetchColumn() === $approvedCount, 'Moderation and reply maintain exact approved count');
    $response = agentCommand('links.list');
    qqCheck(count($response['json']['result']['links'] ?? []) === 1 && (int)$response['json']['result']['links'][0]['lid'] === 2, 'Pending friend links can be queried');
    qqCheck((agentCommand('link.approve', ['id' => 2])['json']['result']['state'] ?? '') === '1'
        && (int)$pdo->query('SELECT state FROM typecho_links WHERE lid=2')->fetchColumn() === 1, 'Friend-link approval modifies real record');
    qqCheck((agentCommand('link.reject', ['id' => 2])['json']['result']['state'] ?? '') === '0', 'Friend-link rejection hides it');
    qqCheck(agentCommand('link.approve', ['id' => 99999])['status'] === 404, 'Missing friend link has explicit failure');
    qqConfig(['qq_agent_admin_uid' => '2']);
    qqCheck(agentCommand('status')['status'] === 403, 'Configured user must still be a Typecho administrator');
    qqConfig(['qq_agent_admin_uid' => '1']);

    // Execute the actual Agent-side Python client against the actual PHP command endpoint.
    $scriptEnv = getenv();
    $scriptEnv['ENHANCEMENT_COMMAND_URL'] = $base . $path;
    $scriptEnv['ENHANCEMENT_COMMAND_SECRET'] = $commandSecret;
    $scriptEnv['PYTHONIOENCODING'] = 'utf-8';
    $script = dirname(__DIR__, 2) . '/integrations/qq-agent/enhancement_qq.py';
    $scriptText = 'Quote\'s `literal` $(literal) & 中文🙂';
    file_put_contents($directory . '/arguments.json', json_encode(['id' => 1, 'text' => $scriptText], JSON_UNESCAPED_UNICODE));
    $outputs = [];
    foreach ([1, 2] as $attempt) {
        $process = proc_open(['python', $script, 'comment.reply', '--sender', 'owner-openid', '--message-id', 'original-qq-message-1',
            '--args-file', $directory . '/arguments.json'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, __DIR__, $scriptEnv, ['bypass_shell' => true]);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        $outputs[] = json_decode($output, true);
        qqCheck($code === 0 && ($outputs[$attempt - 1]['ok'] ?? false) === true, 'Agent Python client signs and executes actual PHP reply, attempt ' . $attempt, [$output, $error]);
    }
    qqCheck(($outputs[1]['replayed'] ?? false) === true && $outputs[0]['result']['id'] === $outputs[1]['result']['id'], 'Actual Python retry reuses deterministic command ID');
    qqCheck($pdo->query('SELECT text FROM typecho_comments WHERE coid=' . (int)$outputs[0]['result']['id'])->fetchColumn() === $scriptText, 'File arguments preserve quotes, shell metacharacters and UTF-8 as plain data');
    qqConfig(['qq_agent_commands' => '0']);
    qqCheck(agentCommand('status')['status'] === 404, 'Turning command switch off immediately blocks signed requests');
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    echo 'FAIL ' . $exception->getMessage() . "\n";
} finally {
    foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
    $pdo = null;
}
echo 'QQ Agent checks passed: ' . $passed . '; failed: ' . count($failures) . "\n";
if (!$failures) {
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
} else {
    echo 'Fixtures retained: ' . $directory . "\n";
}
exit($failures ? 1 : 0);
