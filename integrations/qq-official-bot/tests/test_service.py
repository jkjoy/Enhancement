"""Offline regressions: no QQ credentials or real network calls are used."""
import json
import tempfile
import threading
import unittest
from concurrent.futures import ThreadPoolExecutor
from unittest.mock import patch

from app import create_app
from bot_service import DeliveryError, OfficialClient, Settings


class Clock:
    def __init__(self):
        self.now = 1800000000.0

    def __call__(self):
        return self.now

    def advance(self, seconds=10):
        self.now += seconds


class FakeQQ:
    def __init__(self):
        self.calls = []
        self.failures = {}
        self.lock = threading.Lock()

    def send(self, kind, openid, content):
        with self.lock:
            self.calls.append((kind, openid, content))
            failures = self.failures.get(openid, [])
            if failures:
                raise failures.pop(0)
            return {"id": "qq-fake-" + str(len(self.calls))}


class ServiceTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="qq-bot-test-")
        self.clock = Clock()
        self.qq = FakeQQ()
        self.config = {
            "QQ_APP_ID": "123456789", "QQ_APP_SECRET": "test-app-secret-not-a-real-key",
            "BOT_API_TOKEN": "test-management-token-" + "x" * 32,
            "BOT_DATA_DIR": self.directory.name,
        }
        self.app = create_app(self.config, client=self.qq, clock=self.clock)
        self.client = self.app.test_client()
        self.service = self.app.extensions["bot_service"]
        self.headers = {"Authorization": "Bearer " + self.config["BOT_API_TOKEN"]}

    def tearDown(self):
        self.service.stop()
        self.directory.cleanup()

    def post(self, path, data):
        return self.client.post(path, json=data, headers=self.headers)

    def event(self, payload, tamper=False, timestamp=None):
        raw = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        stamp = str(int(self.clock() if timestamp is None else timestamp))
        signature = self.service.settings.signing_key().sign(stamp.encode() + raw).hex()
        if tamper:
            raw += b" "
        return self.client.post("/qq/webhook", data=raw, content_type="application/json", headers={
            "X-Bot-Appid": self.config["QQ_APP_ID"], "X-Signature-Timestamp": stamp,
            "X-Signature-Ed25519": signature,
        })

    def bind(self, name="owner", kind="user", openid="USER_1"):
        response = self.post("/api/bindings", {"name": name, "type": kind})
        self.assertEqual(response.status_code, 201)
        command = response.get_json()["command"]
        data = {"content": command, "author": {"user_openid": openid}}
        if kind == "group":
            data = {"content": "<@!123456789> " + command, "group_openid": openid}
        payload = {"op": 0, "id": "bind-" + name + command, "t": "C2C_MESSAGE_CREATE" if kind == "user" else "GROUP_AT_MESSAGE_CREATE", "d": data}
        self.assertEqual(self.event(payload).get_json(), {"op": 12})
        return payload

    def enqueue(self, names=None, key="notification-1", message="博客有新评论"):
        response = self.post("/api/notifications", {"targets": names or ["owner"], "message": message, "idempotency_key": key})
        self.assertEqual(response.status_code, 202, response.get_json())
        return response.get_json()["id"]

    def status(self, notification_id):
        response = self.client.get("/api/notifications/" + notification_id, headers=self.headers)
        self.assertEqual(response.status_code, 200)
        return response.get_json()

    def test_private_api_requires_bearer_and_health_is_public(self):
        self.assertEqual(self.client.get("/healthz").get_json(), {"ok": True})
        for path in ("/api/targets", "/api/notifications/unknown"):
            self.assertEqual(self.client.get(path).status_code, 401)
            self.assertEqual(self.client.get(path, headers={"Authorization": "Bearer wrong"}).status_code, 401)
        self.assertEqual(self.client.post("/api/bindings", json={"name": "owner", "type": "user"}).status_code, 401)
        self.assertEqual(self.client.get("/api/targets", headers=self.headers).get_json()["targets"], [])

    def test_unsigned_official_challenge_and_reject_wrong_app(self):
        data = {"op": 13, "d": {"plain_token": "official-test-challenge", "event_ts": str(int(self.clock()))}}
        response = self.client.post("/qq/webhook", json=data, headers={"X-Bot-Appid": self.config["QQ_APP_ID"]})
        self.assertEqual(response.status_code, 200)
        answer = response.get_json()
        self.service.settings.signing_key().public_key().verify(bytes.fromhex(answer["signature"]), (data["d"]["event_ts"] + answer["plain_token"]).encode())
        self.assertEqual(self.client.post("/qq/webhook", json=data, headers={"X-Bot-Appid": "999"}).status_code, 401)
        data["d"]["event_ts"] = str(int(self.clock() - 3600))
        self.assertGreaterEqual(self.client.post("/qq/webhook", json=data).status_code, 400)

    def test_regular_events_fail_closed_for_missing_tampered_and_old_signatures(self):
        data = {"op": 0, "id": "ordinary-event", "t": "C2C_MESSAGE_CREATE", "d": {}}
        self.assertEqual(self.client.post("/qq/webhook", json=data).status_code, 401)
        self.assertEqual(self.event(data, tamper=True).status_code, 401)
        self.assertEqual(self.event(data, timestamp=self.clock() - 301).status_code, 401)
        self.assertEqual(self.event(data).get_json(), {"op": 12})

    def test_challenge_cannot_sign_event_bodies_or_control_characters(self):
        for plain in ('{"op":0,"d":{}}', ' {"op":0}', 'bad\ntoken', '[0]', ''):
            response = self.client.post("/qq/webhook", json={"op": 13, "d": {
                "plain_token": plain, "event_ts": str(int(self.clock())),
            }}, headers={"X-Bot-Appid": self.config["QQ_APP_ID"]})
            self.assertEqual(response.status_code, 400)

    def test_binding_is_typed_expiring_and_one_time(self):
        response = self.post("/api/bindings", {"name": "group", "type": "group"}).get_json()
        command = response["command"]
        wrong = {"op": 0, "id": "wrong-context", "t": "C2C_MESSAGE_CREATE", "d": {"content": command, "author": {"user_openid": "ATTACKER"}}}
        self.event(wrong)
        self.assertFalse(self.client.get("/api/targets", headers=self.headers).get_json()["targets"][0]["bound"])
        correct = {"op": 0, "id": "correct-context", "t": "GROUP_AT_MESSAGE_CREATE", "d": {"content": "<@123456789> " + command, "group_openid": "GROUP_1"}}
        self.event(correct)
        self.event(correct)
        correct["id"], correct["d"]["group_openid"] = "reuse-code", "EVIL_GROUP"
        self.event(correct)
        identifier = self.enqueue(["group"])
        self.assertTrue(self.service.worker.run_once())
        self.assertEqual(self.qq.calls[0][:2], ("group", "GROUP_1"))
        self.assertEqual(self.status(identifier)["status"], "sent")
        response = self.post("/api/bindings", {"name": "expired", "type": "user"}).get_json()
        self.clock.advance(601)
        self.event({"op": 0, "id": "expired-code", "t": "C2C_MESSAGE_CREATE", "d": {"content": response["command"], "author": {"user_openid": "USER_EXPIRED"}}})
        self.assertEqual(self.post("/api/notifications", {"targets": ["expired"], "message": "test", "idempotency_key": "expired"}).status_code, 422)

    def test_binding_alias_validation(self):
        for name, kind in (("../owner", "user"), ("owner", "channel"), ("名字", "group")):
            self.assertEqual(self.post("/api/bindings", {"name": name, "type": kind}).status_code, 400)
        self.bind()
        self.assertEqual(self.post("/api/bindings", {"name": "owner", "type": "group"}).status_code, 409)

    def test_multi_target_persistence_idempotency_and_snapshots(self):
        self.bind()
        self.bind("blog-group", "group", "GROUP_1")
        identifier = self.enqueue(["owner", "blog-group"])
        self.assertEqual(identifier, self.enqueue(["blog-group", "owner", "owner"]))
        self.assertEqual(self.post("/api/notifications", {"targets": ["owner"], "message": "different", "idempotency_key": "notification-1"}).status_code, 409)
        self.bind("owner", "user", "NEW_USER")
        replacement = create_app(self.config, client=self.qq, clock=self.clock)
        worker = replacement.extensions["bot_service"].worker
        try:
            for unused in range(4):
                worker.run_once()
            self.assertEqual({(kind, openid) for kind, openid, text in self.qq.calls}, {("user", "USER_1"), ("group", "GROUP_1")})
            self.assertEqual(len(self.qq.calls), 2)
            status = self.status(identifier)
            self.assertEqual(status["status"], "sent")
            self.assertNotIn("博客有新评论", json.dumps(status, ensure_ascii=False))
            self.assertNotIn("openid", json.dumps(status))
        finally:
            replacement.extensions["bot_service"].stop()

    def test_enqueue_is_atomic_and_payload_bounded(self):
        self.bind()
        data = {"message": "test", "targets": ["owner", "unbound"], "idempotency_key": "atomic"}
        self.assertEqual(self.post("/api/notifications", data).status_code, 422)
        self.assertFalse(self.service.worker.run_once())
        data["targets"] = ["owner"]
        self.assertEqual(self.post("/api/notifications", data).status_code, 202)
        for changes in ({"message": "中" * 667}, {"targets": ["owner"] * 21}, {"idempotency_key": ""}, {"message": " "}):
            self.assertEqual(self.post("/api/notifications", dict(data, **changes)).status_code, 400)
        self.assertEqual(self.client.post("/api/notifications", data=b"x" * 65537, content_type="application/json", headers=self.headers).status_code, 413)

    def test_failure_and_manual_retry_only_resend_failed_target(self):
        self.bind()
        self.bind("blog-group", "group", "GROUP_1")
        self.qq.failures["GROUP_1"] = [DeliveryError("permission_denied")]
        identifier = self.enqueue(["owner", "blog-group"])
        for unused in range(4):
            self.service.worker.run_once()
        self.assertEqual(self.status(identifier)["status"], "failed")
        self.assertEqual(self.post("/api/notifications/" + identifier + "/retry", {}).get_json()["retried_targets"], 1)
        self.clock.advance()
        self.service.worker.run_once()
        self.assertEqual(self.status(identifier)["status"], "sent")
        self.assertEqual(sum(openid == "USER_1" for kind, openid, text in self.qq.calls), 1)
        self.assertEqual(sum(openid == "GROUP_1" for kind, openid, text in self.qq.calls), 2)

    def test_retry_after_backoff_and_attempt_limit(self):
        self.bind()
        self.qq.failures["USER_1"] = [DeliveryError("rate_limited", retryable=True, retry_after=30) for unused in range(5)]
        identifier = self.enqueue()
        self.service.worker.run_once()
        self.clock.advance(29)
        self.assertFalse(self.service.worker.run_once())
        self.assertEqual(len(self.qq.calls), 1)
        for unused in range(4):
            self.clock.advance(300)
            self.service.worker.run_once()
        self.assertEqual(self.status(identifier)["status"], "failed")
        self.assertEqual(self.status(identifier)["targets"][0]["attempts"], 5)
        self.clock.advance(300)
        self.assertFalse(self.service.worker.run_once())

    def test_concurrent_submissions_and_workers_do_not_double_claim(self):
        self.bind()
        def submit(unused):
            with self.app.test_client() as client:
                response = client.post("/api/notifications", json={"message": "same", "targets": ["owner"], "idempotency_key": "concurrent"}, headers=self.headers)
                return response.status_code, response.get_json()["id"]
        with ThreadPoolExecutor(max_workers=6) as pool:
            results = list(pool.map(submit, range(6)))
        self.assertEqual(len({identifier for status, identifier in results}), 1)
        self.assertTrue(all(status == 202 for status, identifier in results))
        with ThreadPoolExecutor(max_workers=6) as pool:
            list(pool.map(lambda unused: self.service.worker.run_once(), range(6)))
        self.assertEqual(len(self.qq.calls), 1)

    def test_interrupted_delivery_recovers_after_lease(self):
        self.bind()
        identifier = self.enqueue()
        abandoned = self.service.store.claim()
        self.assertEqual(abandoned["attempts"], 1)
        replacement = create_app(self.config, client=self.qq, clock=self.clock)
        try:
            worker = replacement.extensions["bot_service"].worker
            self.assertFalse(worker.run_once())
            self.clock.advance(121)
            self.assertTrue(worker.run_once())
            status = self.status(identifier)
            self.assertEqual(status["status"], "sent")
            self.assertEqual(status["targets"][0]["attempts"], 2)
            self.assertEqual(len(self.qq.calls), 1)
        finally:
            replacement.extensions["bot_service"].stop()

    def test_throttled_recipient_does_not_block_other_targets(self):
        self.bind()
        self.bind("blog-group", "group", "GROUP_1")
        for number in range(3):
            self.enqueue(key="owner-" + str(number))
        self.enqueue(["blog-group"], key="group-job")
        self.assertTrue(self.service.worker.run_once())
        self.assertTrue(self.service.worker.run_once())
        self.assertEqual([openid for kind, openid, content in self.qq.calls], ["USER_1", "GROUP_1"])
        self.assertFalse(self.service.worker.run_once())
        self.clock.advance(3.1)
        self.assertTrue(self.service.worker.run_once())

    def test_queue_capacity_rejects_all_targets_atomically(self):
        self.bind()
        self.bind("blog-group", "group", "GROUP_1")
        with patch("bot_service.MAX_QUEUE", 1):
            data = {"message": "bounded queue", "targets": ["owner", "blog-group"], "idempotency_key": "capacity"}
            self.assertEqual(self.post("/api/notifications", data).status_code, 503)
            self.assertFalse(self.service.worker.run_once())
            data["targets"] = ["owner"]
            self.assertEqual(self.post("/api/notifications", data).status_code, 202)
            data["idempotency_key"] = "second-job"
            self.assertEqual(self.post("/api/notifications", data).status_code, 503)
            self.service.worker.run_once()
            self.assertEqual(self.post("/api/notifications", data).status_code, 202)


class Response:
    def __init__(self, status, body, headers=None):
        self.status_code, self.body, self.headers = status, body, headers or {}

    def json(self):
        return self.body


class Session:
    def __init__(self, responses):
        self.responses, self.calls = responses, []

    def post(self, url, **kwargs):
        self.calls.append((url, kwargs))
        return self.responses.pop(0)


class OfficialClientTests(unittest.TestCase):
    def setUp(self):
        self.settings = Settings("1234", "fake-secret", "x" * 40)
        self.clock = Clock()

    def test_cached_access_token_and_correct_active_message_payload(self):
        session = Session([Response(200, {"access_token": "fake-token", "expires_in": 7200}), Response(200, {"id": "one"}), Response(200, {"id": "two"})])
        client = OfficialClient(self.settings, self.clock, session)
        client.send("user", "USER_1", "comment")
        client.send("group", "GROUP_1", "friend-link")
        self.assertEqual(len(session.calls), 3)
        self.assertEqual(session.calls[0][0], "https://api.bot.qq.com/app/getAppAccessToken")
        for url, kwargs in session.calls[1:]:
            self.assertEqual(kwargs["headers"]["Authorization"], "QQBot fake-token")
            self.assertEqual(set(kwargs["json"]), {"content", "msg_type"})
            self.assertFalse(kwargs["allow_redirects"])
        self.assertTrue(session.calls[2][0].endswith("/v2/groups/GROUP_1/messages"))

    def test_http_200_business_error_is_failure_and_401_refreshes_once(self):
        client = OfficialClient(self.settings, self.clock, Session([Response(200, {"code": 100016, "message": "bad secret"})]))
        with self.assertRaises(DeliveryError):
            client.send("user", "USER", "message")
        session = Session([Response(200, {"access_token": "old", "expires_in": 7200}), Response(401, {"err_code": 11243}), Response(200, {"access_token": "new", "expires_in": 7200}), Response(200, {"id": "ok"})])
        self.assertEqual(OfficialClient(self.settings, self.clock, session).send("user", "USER", "message"), {"id": "ok"})
        self.assertEqual(session.calls[-1][1]["headers"]["Authorization"], "QQBot new")
        client = OfficialClient(self.settings, self.clock, Session([Response(200, {"access_token": "ok", "expires_in": 7200}), Response(200, {"err_code": 40034105})]))
        with self.assertRaises(DeliveryError):
            client.send("user", "USER", "message")

    def test_rate_limit_retry_and_fixed_official_destination(self):
        client = OfficialClient(self.settings, self.clock, Session([Response(429, {"err_code": 22009}, {"Retry-After": "30"})]))
        with self.assertRaises(DeliveryError) as caught:
            client.send("user", "USER", "message")
        self.assertTrue(caught.exception.retryable)
        self.assertEqual(caught.exception.retry_after, 30)
        with self.assertRaises(DeliveryError):
            client.send("user", "../../evil", "message")

    def test_missing_message_receipt_and_audit_are_not_sent(self):
        for status, body, code, retryable in (
            (200, {}, "invalid_message_response", True),
            (200, {"code": 0}, "invalid_message_response", True),
            (200, {"code": 0, "err_code": 40034105}, "qq_40034105", False),
            (202, {"data": {"message_audit": {"audit_id": "pending"}}}, "message_audit_pending", False),
        ):
            session = Session([Response(200, {"access_token": "ok", "expires_in": 7200}), Response(status, body)])
            with self.assertRaises(DeliveryError) as caught:
                OfficialClient(self.settings, self.clock, session).send("user", "USER", "message")
            self.assertEqual(caught.exception.code, code)
            self.assertEqual(caught.exception.retryable, retryable)


if __name__ == "__main__":
    unittest.main()
