import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';
import { join } from 'node:path';
import { rmSync } from 'node:fs';
const serviceDir = process.env.ENHANCEMENT_QQ_CHANNEL_DIR;
const { ChannelService } = await import(pathToFileURL(join(serviceDir, 'src/service.js')));
const { settings } = await import(pathToFileURL(join(serviceDir, 'src/config.js')));
const { QQBot } = await import(pathToFileURL(join(serviceDir, 'node_modules/@tencent-connect/qqbot-nodejs/dist/index.js')));
const { dispatchEvent } = await import(pathToFileURL(join(serviceDir, 'node_modules/@tencent-connect/qqbot-nodejs/dist/protocol/gateway/event-dispatcher.js')));
const base = process.env.ENHANCEMENT_QQ_TEST_BASE;
const dataDir = join(process.env.ENHANCEMENT_QQ_TEST_DIR, 'channel');
const calls = [];
const qq = createServer(async (req, res) => {
  const parts = []; for await (const part of req) parts.push(part);
  const body = JSON.parse(Buffer.concat(parts).toString());
  calls.push({ path: req.url, body });
  res.setHeader('Content-Type', 'application/json');
  res.end(JSON.stringify(req.url === '/app/getAppAccessToken' ? { access_token: 'local-token', expires_in: 7200 } : { id: 'local-message-' + calls.length }));
});
await new Promise(resolve => qq.listen(0, '127.0.0.1', resolve));
const sdkBase = 'http://127.0.0.1:' + qq.address().port;
const config = settings({ QQ_APP_ID: '12345', QQ_APP_SECRET: 'fixture-secret', BOT_API_TOKEN: 'a'.repeat(48),
  ENHANCEMENT_COMMAND_URL: base + '/action/enhancement-edit?do=qq-agent-command', ENHANCEMENT_COMMAND_SECRET: 'b'.repeat(48),
  QQ_ALLOWED_USERS: 'owner-openid', QQ_ALLOWED_GROUPS: 'admins-openid', BOT_PORT: '0', BOT_DATA_DIR: dataDir });
const bot = new QQBot({ appId: config.appId, appSecret: config.appSecret, baseUrl: sdkBase, tokenBaseUrl: sdkBase });
const service = new ChannelService(config, bot);
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
async function php(path, body) {
  const response = await fetch(base + path, { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json' }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const value = await response.json();
  return { code: response.status, value };
}
async function command(content, messageId, group = false) {
  const type = group ? 'GROUP_AT_MESSAGE_CREATE' : 'C2C_MESSAGE_CREATE';
  const raw = { id: messageId, content, author: group ? { member_openid: 'owner-openid' } : { user_openid: 'owner-openid' },
    ...(group ? { group_openid: 'admins-openid' } : {}), timestamp: new Date().toISOString() };
  // The SDK's own normalizer and facade feed the exact production listener contract.
  await bot.handleInboundMessage(dispatchEvent(type, raw, config.appId).msg);
  await service.tick();
}
bot.on('message', (_ctx, message) => service.ingest(message));
try {
  await service.listen(); service.connected = true;
  const url = 'http://127.0.0.1:' + service.server.address().port;
  for (const [alias, kind, target] of [['owner', 'user', 'owner-openid'], ['admins', 'group', 'admins-openid']]) {
    const binding = service.store.binding(alias, kind);
    await command(binding.command, 'bind-' + alias, kind === 'group');
    check(service.store.targets().some(row => row.name === alias && row.openid === target), 'bind actual SDK identity');
  }
  const saved = await php('/config', { qq_notify_provider: 'standalone', qq_official_url: url, qq_official_token: config.apiToken,
    qq_official_targets: 'owner\nadmins', qq_agent_commands: '1', qq_agent_command_secret: config.commandSecret,
    qq_agent_allowed_senders: 'owner-openid', qq_agent_admin_uid: '1', enable_comment_by_qq: '1' });
  check(saved.code === 200, 'save standalone config');
  const notification = await php('/test', { message: 'PHP → Node → QQ🙂' });
  check(notification.value.success === true && notification.value.provider === 'standalone', 'PHP accepts real Node receipt');
  await service.tick(); await service.tick();
  check(service.store.notification(notification.value.id).status === 'sent', 'real PHP notification delivered to both SDK paths');
  check(calls.some(row => row.path === '/v2/users/owner-openid/messages' && row.body.content === 'PHP → Node → QQ🙂' && !row.body.msg_id), 'SDK private proactive message');
  check(calls.some(row => row.path === '/v2/groups/admins-openid/messages' && row.body.content === 'PHP → Node → QQ🙂'), 'SDK group proactive message');
  await command('通过评论 2', 'approve-2', true);
  check(calls.some(row => row.body.msg_id === 'approve-2' && row.body.content.includes('approved')), 'signed group command executes in PHP');
  await command('回复评论 2：独立通道回复🙂', 'reply-2');
  await command('回复评论 2：独立通道回复🙂', 'reply-2');
  check(calls.filter(row => row.body.msg_id === 'reply-2').length === 1, 'duplicate QQ event has one response');
  await command('通过友链 2', 'link-2');
  check(calls.some(row => row.body.msg_id === 'link-2' && row.body.content.includes('操作完成')), 'link command result returned to QQ');
  await php('/config', { qq_agent_commands: '0' });
  await command('博客状态', 'disabled');
  check(calls.some(row => row.body.msg_id === 'disabled' && row.body.content.includes('404')), 'plugin command switch takes immediate effect');
  await php('/config', { qq_agent_commands: '1' });

  // The local queue must not submit a record to a different provider after a settings change.
  service.store.db.prepare('DELETE FROM targets WHERE name=?').run('owner');
  await php('/dispatch', { message: '待重试通知' });
  await php('/config', { qq_notify_provider: 'official', qq_agent_commands: '0' });
  const queuedBefore = service.store.db.prepare('SELECT COUNT(*) AS n FROM notifications').get().n;
  service.store.bind(service.store.binding('owner', 'user').command.split(' ')[1], { scope: 'c2c', targetId: 'owner-openid', sender: 'owner-openid' });
  await php('/process', {});
  check(service.store.db.prepare('SELECT COUNT(*) AS n FROM notifications').get().n === queuedBefore, 'provider switch pauses old queued notification');
  await php('/config', { qq_notify_provider: 'standalone', qq_agent_commands: '1' });
  await php('/process', {});
  check(service.store.db.prepare('SELECT COUNT(*) AS n FROM notifications').get().n === queuedBefore + 1, 'restored provider retries queued notification');
  console.log(checks + ' Node/PHP/SDK assertions passed.');
} finally {
  bot.stop(); await service.close();
  await new Promise(resolve => qq.close(resolve));
  rmSync(dataDir, { recursive: true, force: true });
}
