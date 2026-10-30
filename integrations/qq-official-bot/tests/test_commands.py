"""Real QQ signatures and local HTTP forwarding; official QQ calls stay mocked."""

import hashlib
import hmac
import json
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from unittest.mock import patch

from app import create_app
from bot_service import DeliveryError, OfficialClient, Settings
from commands import EnhancementClient
import test_service as service_fixture
from test_service import Clock, Response, Session


class FakeQQ:
    def __init__(self):
        self.calls, self.failures = [], []

    def send(self, kind, openid, content, reply_message_id=None):
        self.calls.append((kind, openid, content, reply_message_id))
        if self.failures:
            raise self.failures.pop(0)
        return {"id": "reply-" + str(len(self.calls))}


class Forwarder:
    def __init__(self, responses=None):
        self.calls = []
        self.responses = list(responses or [{"ok": True, "result": {"success": True}, "reply": "瞬间发布成功"}])

    def forward(self, raw):
        self.calls.append(json.loads(raw))
        response = self.responses.pop(0) if len(self.responses) > 1 else self.responses[0]
        if isinstance(response, Exception):
            raise response
        return response


class CommandTests(unittest.TestCase):
    event = service_fixture.ServiceTests.event
    post = service_fixture.ServiceTests.post
    bind = service_fixture.ServiceTests.bind

    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="qq-command-test-")
        self.clock, self.qq, self.forwarder = Clock(), FakeQQ(), Forwarder()
        self.config = {"QQ_APP_ID": "123456789", "QQ_APP_SECRET": "offline-signature-secret",
                       "BOT_API_TOKEN": "management-" + "x" * 32, "BOT_DATA_DIR": self.directory.name,
                       "ENHANCEMENT_WEBHOOK_URL": "http://127.0.0.1:8765/index.php/action/enhancement-edit?do=qq-moments-webhook",
                       "ENHANCEMENT_COMMAND_SECRET": "command-" + "y" * 32}
        self.app = create_app(self.config, client=self.qq, clock=self.clock, forwarder=self.forwarder)
        self.service = self.app.extensions["bot_service"]
        self.client = self.app.test_client()
        self.headers = {"Authorization": "Bearer " + self.config["BOT_API_TOKEN"]}

    def tearDown(self):
        self.service.stop()
        self.directory.cleanup()

    def message(self, message_id="msg-1", text="瞬间 QQ发布瞬间测试!~ /微笑", sender="USER_1", **changes):
        data = {"id": message_id, "content": text, "timestamp": int(self.clock()), "author": {"user_openid": sender}}
        data.update(changes)
        return {"op": 0, "id": "event-" + message_id, "t": "C2C_MESSAGE_CREATE", "d": data}

    def statuses(self):
        response = self.client.get("/api/commands", headers=self.headers)
        self.assertEqual(response.status_code, 200)
        return response.get_json()["commands"]

    def test_signed_event_real_http_hmac_and_passive_reply(self):
        self.bind()
        self.bind("second-owner")
        seen = []
        secret = self.config["ENHANCEMENT_COMMAND_SECRET"]

        class Handler(BaseHTTPRequestHandler):
            def do_POST(handler):
                raw = handler.rfile.read(int(handler.headers["Content-Length"]))
                stamp = handler.headers["X-Enhancement-Timestamp"]
                expected = hmac.new(secret.encode(), stamp.encode() + b"\n" + raw, hashlib.sha256).hexdigest()
                if not hmac.compare_digest(expected, handler.headers["X-Enhancement-Signature"]):
                    handler.send_error(401)
                    return
                seen.append((handler.path, json.loads(raw)))
                body = json.dumps({"ok": True, "result": {"success": True, "mid": 1}, "reply": "已发布瞬间 #1"}, ensure_ascii=False).encode()
                handler.send_response(200)
                handler.send_header("Content-Length", str(len(body)))
                handler.end_headers()
                handler.wfile.write(body)

            def log_message(handler, *args):
                pass

        server = HTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            configuration = dict(self.config, ENHANCEMENT_WEBHOOK_URL="http://127.0.0.1:" + str(server.server_port) + "/index.php/action/enhancement-edit?do=qq-moments-webhook")
            settings = Settings.from_env(configuration)
            self.service.command_worker.forwarder = EnhancementClient(settings, self.clock)
            qq_session = Session([Response(200, {"access_token": "offline-token", "expires_in": 7200}), Response(200, {"id": "reply-ok"})])
            self.service.command_worker.client = OfficialClient(settings, self.clock, qq_session)
            attachments = [{"content_type": "image/jpeg", "url": "https://gchat.qpic.cn/offline.jpg"}]
            payload = self.message(timestamp="2027-01-15T16:00:00+08:00", attachments=attachments)
            self.assertEqual(self.event(payload).get_json(), {"op": 12})
            self.assertFalse(seen, "QQ webhook must ACK without waiting for Typecho")
            worker = self.service.command_worker
            self.assertTrue(worker.run_once())
            self.assertEqual(self.statuses()[0]["stage"], "reply")
            self.assertTrue(worker.run_once())
            self.assertEqual(self.statuses()[0]["status"], "complete")
            self.assertEqual(len(seen), 1)
            message = seen[0][1]["message"]
            self.assertEqual(seen[0][1]["version"], 1)
            self.assertEqual(message["text"], payload["d"]["content"])
            self.assertEqual(message["timestamp"], 1800000000)
            self.assertEqual(message["aliases"], ["owner", "second-owner"])
            self.assertEqual(message["attachments"], attachments)
            self.assertEqual((message["account"], message["sender"], message["conversation"], message["conversation_type"]), ("123456789", "USER_1", "USER_1", "private"))
            reply = qq_session.calls[-1][1]["json"]
            self.assertEqual(reply, {"content": "已发布瞬间 #1", "msg_type": 0, "msg_id": "msg-1", "msg_seq": 1})
            self.assertFalse(qq_session.calls[-1][1]["allow_redirects"])
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=3)

    def test_reject_unsigned_tampered_unbound_group_and_invalid_time(self):
        self.bind()
        payload = self.message()
        self.assertEqual(self.client.post("/qq/webhook", json=payload).status_code, 401)
        self.assertEqual(self.event(payload, tamper=True).status_code, 401)
        self.event(self.message("unknown", sender="UNBOUND"))
        self.event(dict(self.message("group"), t="GROUP_AT_MESSAGE_CREATE"))
        self.event(self.message("chat", text="hello"))
        for index, stamp in enumerate((None, "not-a-time", "2026-10-30T00:00:00", False, 0)):
            self.event(self.message("bad-time-" + str(index), timestamp=stamp))
        no_id = self.message("missing-id")
        no_id["d"].pop("id")
        self.event(no_id)
        self.assertEqual(self.statuses(), [])

    def test_attachment_only_is_queued_and_disabled_service_ignores_commands(self):
        self.bind()
        self.event(self.message(text="", attachments=[{"url": "https://gchat.qpic.cn/image.jpg"}]))
        self.assertEqual(len(self.statuses()), 1)
        disabled = dict(self.config)
        disabled.pop("ENHANCEMENT_WEBHOOK_URL")
        disabled.pop("ENHANCEMENT_COMMAND_SECRET")
        app = create_app(disabled, client=self.qq, clock=self.clock)
        try:
            original_client = self.client
            self.client = app.test_client()
            self.event(self.message("disabled"))
            self.client = original_client
            self.assertEqual(len(self.statuses()), 1)
        finally:
            app.extensions["bot_service"].stop()

    def test_duplicate_message_ids_survive_restart_and_event_id_changes(self):
        self.bind()
        event = self.message()
        self.event(event)
        event["id"] = "retry-new-event-id"
        self.event(event)
        self.assertEqual(len(self.statuses()), 1)
        replacement = create_app(self.config, client=self.qq, clock=self.clock, forwarder=self.forwarder)
        try:
            worker = replacement.extensions["bot_service"].command_worker
            self.assertTrue(worker.run_once())
            self.assertTrue(worker.run_once())
            self.event(event)
            self.assertFalse(worker.run_once())
            self.assertEqual(len(self.forwarder.calls), 1)
            self.assertEqual(len(self.qq.calls), 1)
        finally:
            replacement.extensions["bot_service"].stop()

    def test_reply_retry_never_forwards_again_and_manual_retry_keeps_stage(self):
        self.bind()
        self.event(self.message())
        worker = self.service.command_worker
        worker.run_once()
        self.qq.failures = [DeliveryError("reply_permission_denied")]
        worker.run_once()
        status = self.statuses()[0]
        self.assertEqual((status["stage"], status["status"]), ("reply", "failed"))
        self.assertEqual(self.post("/api/commands/" + status["id"] + "/retry", {}).status_code, 202)
        self.clock.advance(3.1)
        worker.run_once()
        self.assertEqual(self.statuses()[0]["status"], "complete")
        self.assertEqual(len(self.forwarder.calls), 1)
        self.assertEqual(len(self.qq.calls), 2)
        self.assertEqual({call[3] for call in self.qq.calls}, {"msg-1"})

    def test_colon_commands_are_forwarded_and_reply_shares_notification_throttle(self):
        self.bind()
        self.event(self.message("colon", "瞬间：正文"))
        self.event(self.message("ascii-colon", "私密瞬间:正文"))
        self.assertEqual(len(self.statuses()), 2)
        notification_id = self.post("/api/notifications", {"message": "new comment", "targets": ["owner"], "idempotency_key": "throttle"}).get_json()["id"]
        self.assertTrue(self.service.worker.run_once())
        self.assertTrue(self.service.command_worker.run_once())
        self.assertFalse(self.service.command_worker.run_once(), "passive reply must wait for active notification throttle")
        self.clock.advance(3.1)
        self.assertTrue(self.service.command_worker.run_once())
        self.assertTrue(self.service.command_worker.run_once())
        self.assertFalse(self.service.command_worker.run_once())
        self.clock.advance(3.1)
        self.assertTrue(self.service.command_worker.run_once())
        self.assertEqual([call[3] for call in self.qq.calls], [None, "colon", "ascii-colon"])
        self.assertEqual(self.service.store.notification(notification_id)["status"], "sent")

    def test_retrying_sender_stays_ordered_other_sender_progresses(self):
        self.bind()
        self.bind("other", openid="USER_2")
        self.forwarder.responses = [DeliveryError("plugin_network_error", retryable=True), {"ok": True, "result": None, "reply": ""}]
        self.event(self.message("draft-start", "图文瞬间 正文"))
        self.event(self.message("draft-image", "", attachments=[{"url": "image"}]))
        self.event(self.message("other", sender="USER_2"))
        worker = self.service.command_worker
        worker.run_once()
        worker.run_once()
        self.assertEqual([call["message"]["id"] for call in self.forwarder.calls], ["draft-start", "other"])
        self.assertFalse(worker.run_once())
        self.clock.advance(10)
        worker.run_once()
        worker.run_once()
        self.assertEqual([call["message"]["id"] for call in self.forwarder.calls], ["draft-start", "other", "draft-start", "draft-image"])

    def test_alias_rebinding_revokes_entire_queued_alias_snapshot(self):
        self.bind()
        self.bind("second")
        self.event(self.message())
        self.bind("owner", openid="NEW_OWNER")
        self.assertFalse(self.service.command_worker.run_once())
        self.assertEqual(self.statuses()[0]["status"], "skipped")
        self.assertEqual(self.statuses()[0]["last_error"], "binding_changed")
        self.assertEqual(self.forwarder.calls, [])

    def test_business_failure_is_replied_null_result_is_complete(self):
        self.bind()
        self.forwarder.responses = [{"ok": True, "result": {"success": False}, "reply": "图片格式不支持"}, {"ok": True, "result": None, "reply": ""}]
        self.event(self.message())
        self.event(self.message("no-op"))
        worker = self.service.command_worker
        for unused in range(3):
            worker.run_once()
        self.assertEqual(len(self.qq.calls), 1)
        self.assertEqual(self.qq.calls[0][2], "图片格式不支持")
        self.assertTrue(all(item["status"] == "complete" for item in self.statuses()))
        status = json.dumps(self.statuses(), ensure_ascii=False)
        for private in ("USER_1", "图片格式不支持", self.config["ENHANCEMENT_COMMAND_SECRET"]):
            self.assertNotIn(private, status)
        self.assertEqual(self.client.get("/api/commands").status_code, 401)

    def test_abandoned_forward_and_reply_claims_recover_without_changing_stage(self):
        self.bind()
        self.event(self.message())
        abandoned = self.service.commands.claim()
        self.assertEqual(abandoned["stage"], "forward")
        self.clock.advance(121)
        self.service.command_worker.run_once()
        abandoned = self.service.commands.claim()
        self.assertEqual(abandoned["stage"], "reply")
        self.clock.advance(121)
        self.service.command_worker.run_once()
        self.assertEqual(self.statuses()[0]["status"], "complete")
        self.assertEqual(len(self.forwarder.calls), 1)

    def test_forward_attempt_limit_and_capacity(self):
        self.bind()
        self.forwarder.responses = [DeliveryError("plugin_http_503", retryable=True)]
        self.event(self.message())
        with patch("commands.MAX_QUEUE", 1):
            self.assertEqual(self.event(self.message("overflow")).status_code, 503)
        for unused in range(5):
            self.service.command_worker.run_once()
            self.clock.advance(300)
        self.assertEqual(self.statuses()[0]["status"], "failed")
        self.assertEqual(self.statuses()[0]["forward_attempts"], 5)
        self.assertEqual(len(self.forwarder.calls), 5)


class HttpResponse:
    def __init__(self, status, raw):
        self.status_code, self.raw, self.closed = status, raw, False

    def iter_content(self, size):
        yield self.raw

    def close(self):
        self.closed = True


class ForwardClientTests(unittest.TestCase):
    def settings(self, **changes):
        values = {"QQ_APP_ID": "1234", "QQ_APP_SECRET": "offline-secret", "BOT_API_TOKEN": "x" * 40,
                  "ENHANCEMENT_WEBHOOK_URL": "http://blog:8080/index.php/action/enhancement-edit?do=qq-moments-webhook",
                  "ENHANCEMENT_COMMAND_SECRET": "y" * 40}
        values.update(changes)
        return Settings.from_env(values)

    def test_pairing_secret_and_url_validation(self):
        for changes in ({"ENHANCEMENT_WEBHOOK_URL": ""}, {"ENHANCEMENT_COMMAND_SECRET": ""},
                        {"ENHANCEMENT_COMMAND_SECRET": "x" * 40},
                        {"ENHANCEMENT_COMMAND_SECRET": "short"}, {"ENHANCEMENT_COMMAND_SECRET": "x" * 32 + "\n"},
                        {"ENHANCEMENT_WEBHOOK_URL": "ftp://blog/file"}, {"ENHANCEMENT_WEBHOOK_URL": "http://u:p@blog/path"},
                        {"ENHANCEMENT_WEBHOOK_URL": "http://blog/path#fragment"}):
            with self.assertRaises(ValueError):
                self.settings(**changes)
        self.assertTrue(self.settings().enhancement_webhook_url)

    def test_errors_retry_sanitized_without_redirect_or_body_leak(self):
        for status, raw in ((302, b"secret-token"), (401, b"secret-token"), (500, b"secret-token"),
                            (200, b"bad-json-secret"), (200, b'{"ok":false,"secret":"secret-token"}'),
                            (200, b'{"ok":true,"reply":42}'), (200, b"x" * 65537)):
            response = HttpResponse(status, raw)
            session = Session([response])
            forwarder = EnhancementClient(self.settings(), Clock(), session)
            with self.assertRaises(DeliveryError) as caught:
                forwarder.forward('{"version":1}')
            self.assertTrue(caught.exception.retryable)
            self.assertNotIn("secret", caught.exception.code)
            self.assertFalse(session.calls[0][1]["allow_redirects"])
            self.assertTrue(response.closed)


if __name__ == "__main__":
    unittest.main()
