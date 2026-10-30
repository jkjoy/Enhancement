"""Exercise the shipped QQ service against the disposable real Typecho callback."""

import copy
import hashlib
import json
from pathlib import Path
import sqlite3
import sys
import threading
import time
from urllib.parse import urlsplit

# Import production modules even under an embedded Python with an isolated sys.path.
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "integrations" / "qq-official-bot"))

import requests
from waitress import create_server

from app import create_app
from bot_service import OfficialClient, Settings
from commands import EnhancementClient


class Clock:
    def __init__(self):
        self.now = time.time()

    def __call__(self):
        return self.now

    def advance(self, seconds=6):
        self.now += seconds


class Reply:
    def __init__(self, status, body):
        self.status_code, self.body, self.headers = status, body, {}

    def json(self):
        return self.body


class OfflineQQ:
    """Only the QQ HTTP transport is mocked; OfficialClient builds real replies."""
    def __init__(self):
        self.calls, self.failures = [], []

    def post(self, url, **kwargs):
        if url == "https://api.bot.qq.com/app/getAppAccessToken":
            return Reply(200, {"access_token": "offline-access-token", "expires_in": 7200})
        if url != "https://api.bot.qq.com/v2/users/private-owner/messages":
            raise AssertionError("Unexpected QQ request: " + url)
        if kwargs.get("allow_redirects") is not False:
            raise AssertionError("QQ redirects must be disabled")
        self.calls.append(copy.deepcopy(kwargs["json"]))
        if self.failures:
            return self.failures.pop(0)
        return Reply(200, {"id": "offline-reply-" + str(len(self.calls))})


class RealCallback:
    """Send actual HMAC requests; simulate one lost response after PHP commits."""
    def __init__(self, callback):
        self.callback, self.calls, self.lose_next_response = callback, [], False
        self.session = requests.Session()
        self.session.trust_env = False

    def post(self, url, **kwargs):
        if url != self.callback or urlsplit(url).hostname != "127.0.0.1":
            raise AssertionError("Callback must stay on the disposable loopback fixture")
        response = self.session.post(url, **kwargs)
        self.calls.append({"message": json.loads(kwargs["data"])["message"], "status": response.status_code})
        if self.lose_next_response:
            self.lose_next_response = False
            response.close()
            raise requests.Timeout("Simulated lost response after Typecho publication")
        return response


def run(config):
    checks = 0

    def check(condition, label):
        nonlocal checks
        if not condition:
            raise AssertionError(label)
        checks += 1

    clock, qq = Clock(), OfflineQQ()
    settings = Settings.from_env({
        "QQ_APP_ID": "123456789", "QQ_APP_SECRET": "offline-signature-secret",
        "BOT_API_TOKEN": "fixture-notifications-" + "n" * 32, "BOT_DATA_DIR": config["data_dir"],
        "ENHANCEMENT_WEBHOOK_URL": config["callback"], "ENHANCEMENT_COMMAND_SECRET": config["secret"],
    })
    callback = RealCallback(config["callback"])
    forwarder = EnhancementClient(settings, clock, session=callback)
    app = create_app(settings, client=OfficialClient(settings, clock, session=qq), clock=clock, forwarder=forwarder)
    service = app.extensions["bot_service"]
    worker = service.command_worker
    server = create_server(app, host="127.0.0.1", port=0, threads=2)
    thread = threading.Thread(target=server.run, daemon=True)
    thread.start()
    base = "http://127.0.0.1:" + str(server.effective_port)
    http = requests.Session()
    http.trust_env = False
    authorization = {"Authorization": "Bearer " + settings.api_token}

    def event(message_id, text, sender="private-owner"):
        return {"op": 0, "t": "C2C_MESSAGE_CREATE", "id": "event-" + message_id, "d": {
            "id": message_id, "content": text, "timestamp": int(clock()), "author": {"user_openid": sender},
        }}

    def post_event(payload, tamper=False):
        raw = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        stamp = str(int(clock()))
        signature = settings.signing_key().sign(stamp.encode("ascii") + raw).hex()
        return http.post(base + "/qq/webhook", data=raw + (b" " if tamper else b""), headers={
            "Content-Type": "application/json", "X-Bot-Appid": settings.app_id,
            "X-Signature-Timestamp": stamp, "X-Signature-Ed25519": signature,
        }, timeout=5)

    def status(message_id):
        identifier = hashlib.sha256((settings.app_id + "\n" + message_id).encode("utf-8")).hexdigest()
        response = http.get(base + "/api/commands/" + identifier, headers=authorization, timeout=5)
        check(response.status_code == 200, "Authenticated command status is available")
        return response.json()

    def rows():
        with sqlite3.connect(config["database"]) as db:
            if not db.execute("SELECT 1 FROM sqlite_master WHERE name='typecho_moments'").fetchone():
                return []
            return db.execute("SELECT content,status,source FROM typecho_moments ORDER BY mid").fetchall()

    def accepted(payload):
        response = post_event(payload)
        check(response.status_code == 200 and response.json() == {"op": 12}, "Real signed QQ webhook is acknowledged")

    try:
        check(http.get(base + "/healthz", timeout=5).status_code == 200, "Production Flask app serves real loopback HTTP")
        binding = http.post(base + "/api/bindings", json={"name": "owner", "type": "user"}, headers=authorization, timeout=5)
        check(binding.status_code == 201, "Owner binding is created through the authenticated production API")
        accepted(event("binding", binding.json()["command"]))
        check(service.store.targets() == [{"name": "owner", "type": "user", "bound": True}], "Signed C2C message binds the actual owner alias")
        check(not worker.run_once(), "Binding does not become a publishing job")

        original = event("original-command", "瞬间 QQ发布瞬间测试!~")
        check(http.post(base + "/qq/webhook", json=original, timeout=5).status_code == 401, "Unsigned QQ publication is rejected")
        check(post_event(original, tamper=True).status_code == 401, "Ed25519 rejects tampered request bytes")
        accepted(event("unbound", "瞬间 不应发布", sender="not-the-owner"))
        check(not worker.run_once() and rows() == [], "Unbound QQ sender never reaches PHP publication")

        accepted(original)
        accepted(original)
        redelivery = copy.deepcopy(original)
        redelivery["id"] = "new-event-id-same-message"
        accepted(redelivery)
        check(len(service.commands.list_status()) == 1, "Duplicate QQ events and message IDs create one durable command")
        check(callback.calls == [] and rows() == [], "QQ ACK completes before the PHP callback executes")

        callback.lose_next_response = True
        check(worker.run_once(), "Worker sends the first real HMAC callback")
        first_status = status("original-command")
        check(first_status["stage"] == "forward" and first_status["status"] == "queued", "Lost callback response remains retryable at the forwarding stage")
        check(rows() == [("QQ发布瞬间测试!~", "public", "qq")], "PHP commits the original command before the simulated response loss")
        check(not worker.run_once(), "Forward retry honors its persistent delay")
        clock.advance()
        check(worker.run_once(), "Worker retries the actual HMAC request")
        check(status("original-command")["stage"] == "reply", "Retried callback advances to independent QQ reply stage")
        check(len(rows()) == 1 and len(callback.calls) == 2, "PHP receipt prevents duplicate insertion after a lost HTTP response")

        qq.failures.append(Reply(503, {"code": 503}))
        check(worker.run_once(), "OfficialClient attempts the passive QQ reply")
        failed_reply = status("original-command")
        check(failed_reply["stage"] == "reply" and failed_reply["status"] == "queued", "Temporary QQ reply failure only queues the reply stage")
        check(not worker.run_once(), "Reply retry honors its persistent delay")
        clock.advance()
        check(worker.run_once(), "Transient QQ reply retry succeeds")
        done = status("original-command")
        check(done["status"] == "complete" and done["forward_attempts"] == 2 and done["reply_attempts"] == 2,
              "Durable command records forwarding and reply attempts independently")
        check(len(callback.calls) == 2 and len(rows()) == 1, "QQ reply retry never forwards or publishes the moment again")
        check(qq.calls[0] == qq.calls[1] == {"content": "已发布瞬间 #1", "msg_type": 0, "msg_id": "original-command", "msg_seq": 1},
              "Passive retries retain the original QQ message ID and stable message sequence")

        clock.advance()
        accepted(event("emoji-command", "瞬间 开心[微笑] 😄\n第二行"))
        check(worker.run_once() and worker.run_once(), "Public emoji command completes both actual worker stages")
        check(status("emoji-command")["status"] == "complete", "Emoji command completes successfully")
        check(rows()[1] == ("开心[微笑] 😄\n第二行", "public", "qq"), "QQ expression, emoji and multiline content survive normalization and HMAC HTTP")

        clock.advance()
        accepted(event("private-command", "私密瞬间 只给自己看 🔒"))
        check(worker.run_once(), "Private command reaches the real Typecho publisher")
        qq.failures.append(Reply(403, {"code": 403}))
        check(worker.run_once(), "Permanent QQ reply failure is recorded after private publication")
        private_status = status("private-command")
        check(private_status["stage"] == "reply" and private_status["status"] == "failed", "Permanent reply failure preserves the completed publication stage")
        check(rows()[2] == ("只给自己看 🔒", "private", "qq"), "Private visibility is persisted before any QQ reply succeeds")
        retry = http.post(base + "/api/commands/" + private_status["id"] + "/retry", json={}, headers=authorization, timeout=5)
        check(retry.status_code == 202, "Authenticated manual retry requeues the failed reply")
        clock.advance()
        check(worker.run_once() and status("private-command")["status"] == "complete", "Manual reply retry completes the existing command")
        check(len(callback.calls) == 4 and len(rows()) == 3, "Automatic and manual reply retries never duplicate database insertion")
        check(qq.calls[-2] == qq.calls[-1] == {"content": "已发布私密瞬间 #3", "msg_type": 0, "msg_id": "private-command", "msg_seq": 1},
              "Private passive reply retries use the same original QQ message ID and sequence")

        accepted(original)
        check(not worker.run_once() and len(rows()) == 3, "Redelivery after completion cannot publish or reply again")
        check(all(call["status"] == 200 for call in callback.calls), "Every real callback passes PHP HMAC authentication")
        normalized = callback.calls[0]["message"]
        check((normalized["account"], normalized["sender"], normalized["conversation"], normalized["conversation_type"], normalized["aliases"])
              == ("123456789", "private-owner", "private-owner", "private", ["owner"]), "Actual service normalization carries the bound alias and private sender identity")
        return {"ok": True, "checks": checks, "forward_requests": len(callback.calls), "reply_requests": len(qq.calls)}
    finally:
        http.close()
        callback.session.close()
        service.stop()
        server.close()
        server.task_dispatcher.shutdown(timeout=3)
        thread.join(timeout=3)


if __name__ == "__main__":
    print(json.dumps(run(json.load(sys.stdin)), ensure_ascii=False))
