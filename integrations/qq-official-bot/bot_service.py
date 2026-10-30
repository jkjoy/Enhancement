"""Durable delivery and binding for the official QQ Bot HTTP API."""

import hashlib
import json
import logging
import os
import re
import secrets
import sqlite3
import threading
import time
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import quote, urlsplit

import requests
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey


ALIAS_RE = re.compile(r"[A-Za-z0-9_-]{1,64}\Z")
OPENID_RE = re.compile(r"[A-Za-z0-9_-]{1,128}\Z")
MAX_ATTEMPTS = 5
MAX_QUEUE = 1000
MAX_TARGETS = 100
MAX_BINDINGS = 200
MAX_EVENTS = 10000
MIN_SEND_INTERVAL = 3.1
WEBHOOK_WINDOW = 300
LOGGER = logging.getLogger("qq_bot")


@dataclass(frozen=True)
class Settings:
    app_id: str
    app_secret: str
    api_token: str
    data_dir: str = "/data"
    port: int = 8080
    enhancement_webhook_url: str = ""
    enhancement_command_secret: str = ""

    @classmethod
    def from_env(cls, env=None):
        values = os.environ if env is None else env
        app_id = str(values.get("QQ_APP_ID", "")).strip()
        app_secret = str(values.get("QQ_APP_SECRET", "")).strip()
        api_token = str(values.get("BOT_API_TOKEN", "")).strip()
        if not re.fullmatch(r"[0-9]{1,32}", app_id):
            raise ValueError("QQ_APP_ID must be a numeric application ID")
        if not app_secret or len(app_secret.encode("utf-8")) > 512:
            raise ValueError("QQ_APP_SECRET is required")
        if not 32 <= len(api_token) <= 1024 or any(not 33 <= ord(c) <= 126 for c in api_token):
            raise ValueError("BOT_API_TOKEN must contain at least 32 visible ASCII characters")
        try:
            port = int(values.get("BOT_PORT", "8080"))
        except (ValueError, TypeError):
            raise ValueError("BOT_PORT must be an integer") from None
        if not 1 <= port <= 65535:
            raise ValueError("BOT_PORT is outside the supported range")
        webhook = str(values.get("ENHANCEMENT_WEBHOOK_URL", "")).strip()
        command_secret = str(values.get("ENHANCEMENT_COMMAND_SECRET", ""))
        if bool(webhook) != bool(command_secret):
            raise ValueError("ENHANCEMENT_WEBHOOK_URL and ENHANCEMENT_COMMAND_SECRET must be configured together")
        if webhook:
            try:
                parsed = urlsplit(webhook)
                valid = parsed.scheme in ("http", "https") and parsed.hostname and parsed.username is None and parsed.password is None and not parsed.fragment
                valid = valid and parsed.port != 0 and not any(ord(c) <= 32 or ord(c) == 127 for c in webhook)
            except ValueError:
                valid = False
            if not valid:
                raise ValueError("ENHANCEMENT_WEBHOOK_URL must be a fixed HTTP or HTTPS callback URL")
            if not 32 <= len(command_secret) <= 1024 or any(not 33 <= ord(c) <= 126 for c in command_secret):
                raise ValueError("ENHANCEMENT_COMMAND_SECRET must contain 32 to 1024 visible ASCII characters")
            if command_secret == api_token:
                raise ValueError("ENHANCEMENT_COMMAND_SECRET must differ from BOT_API_TOKEN")
        return cls(app_id, app_secret, api_token, str(values.get("BOT_DATA_DIR", "/data")), port, webhook, command_secret)

    def signing_key(self):
        seed = self.app_secret.encode("utf-8")
        seed = (seed * ((32 + len(seed) - 1) // len(seed)))[:32]
        return Ed25519PrivateKey.from_private_bytes(seed)


class ApiError(Exception):
    def __init__(self, code, status=400):
        super().__init__(code)
        self.code = code
        self.status = status


class DeliveryError(Exception):
    """Only sanitized codes reach persistent job status or HTTP responses."""

    def __init__(self, code, retryable=False, retry_after=0):
        safe_code = re.sub(r"[^A-Za-z0-9_-]", "_", str(code))[:80]
        super().__init__(safe_code)
        self.code = safe_code
        self.retryable = retryable
        self.retry_after = max(0, min(float(retry_after), 86400))


class OfficialClient:
    BASE_URL = "https://api.bot.qq.com"

    def __init__(self, settings, clock=time.time, session=None):
        self.settings = settings
        self.clock = clock
        self.session = session or requests.Session()
        self.session.trust_env = False
        self._token = ""
        self._token_expires = 0
        self._lock = threading.Lock()

    @staticmethod
    def _response_error(response, body):
        status = response.status_code
        errors = [body.get(field) for field in ("code", "err_code")] if isinstance(body, dict) else []
        error = next((item for item in errors if str(item) not in ("0", "", "None")), 0)
        if 200 <= status < 300 and not error and isinstance(body, dict):
            return None
        raw_retry = response.headers.get("Retry-After", "0")
        try:
            retry = min(max(float(raw_retry), 0), 86400)
        except (ValueError, TypeError):
            retry = 0
        suffix = str(error) if error and re.fullmatch(r"[0-9]{1,12}", str(error)) else str(status)
        return DeliveryError("qq_" + suffix, status == 429 or status >= 500, retry)

    def _post(self, path, payload, headers=None):
        # The hostname is fixed; redirects and environment proxies are disabled.
        try:
            response = self.session.post(
                self.BASE_URL + path,
                json=payload,
                headers=headers or {},
                timeout=(5, 20),
                allow_redirects=False,
            )
        except requests.RequestException:
            raise DeliveryError("network_error", retryable=True) from None
        try:
            body = response.json()
        except (ValueError, TypeError):
            body = None
        error = self._response_error(response, body)
        if error:
            error.unauthorized = response.status_code == 401
            raise error
        return body

    def _access_token(self, force=False):
        with self._lock:
            if not force and self._token and self._token_expires > self.clock():
                return self._token
            body = self._post("/app/getAppAccessToken", {
                "appId": self.settings.app_id,
                "clientSecret": self.settings.app_secret,
            })
            token = body.get("access_token")
            try:
                expires = float(body.get("expires_in", 0))
            except (ValueError, TypeError):
                expires = 0
            if not isinstance(token, str) or not token or not 0 < expires <= 604800:
                raise DeliveryError("invalid_token_response", retryable=True)
            self._token = token
            self._token_expires = self.clock() + expires - min(60, expires * 0.1)
            return token

    def send(self, kind, openid, content, reply_message_id=None):
        if kind not in ("user", "group") or not OPENID_RE.fullmatch(openid):
            raise DeliveryError("invalid_target")
        path = "/v2/{}/{}/messages".format("users" if kind == "user" else "groups", quote(openid, safe=""))
        payload = {"content": content, "msg_type": 0}
        if reply_message_id is not None:
            if kind != "user" or not isinstance(reply_message_id, str) or not re.fullmatch(r"[\x21-\x7e]{1,256}", reply_message_id):
                raise DeliveryError("invalid_reply_message_id")
            payload.update(msg_id=reply_message_id, msg_seq=1)
        for attempt in range(2):
            token = self._access_token(force=attempt == 1)
            try:
                body = self._post(path, payload, {"Authorization": "QQBot " + token})
                data = body.get("data")
                data = data if isinstance(data, dict) else {}
                if any(field in source for source in (body, data) for field in ("message_audit", "audit_id")):
                    # Audit acceptance is not confirmed delivery. An operator can inspect
                    # and retry the failed target without silently reporting it as sent.
                    raise DeliveryError("message_audit_pending")
                message_id = body.get("id")
                if not isinstance(message_id, str) or not message_id.strip() or len(message_id) > 256 or any(ord(c) < 33 or ord(c) == 127 for c in message_id):
                    raise DeliveryError("invalid_message_response", retryable=True)
                return body
            except DeliveryError as error:
                if attempt == 0 and getattr(error, "unauthorized", False):
                    continue
                raise


class Store:
    def __init__(self, directory, clock=time.time):
        self.clock = clock
        directory = Path(directory)
        directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        self.path = str(directory / "qq-bot.sqlite3")
        with self.connect() as connection:
            connection.execute("PRAGMA journal_mode=WAL")
            connection.executescript("""
                CREATE TABLE IF NOT EXISTS targets (
                    name TEXT PRIMARY KEY, type TEXT NOT NULL,
                    openid TEXT, updated_at REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS bindings (
                    code_hash TEXT PRIMARY KEY, name TEXT UNIQUE NOT NULL,
                    type TEXT NOT NULL, expires_at REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS notifications (
                    id TEXT PRIMARY KEY, idempotency_hash TEXT UNIQUE NOT NULL,
                    payload_hash TEXT NOT NULL, message TEXT NOT NULL,
                    created_at REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS deliveries (
                    notification_id TEXT NOT NULL, name TEXT NOT NULL,
                    type TEXT NOT NULL, openid TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'queued', attempts INTEGER NOT NULL DEFAULT 0,
                    next_attempt REAL NOT NULL, lease_until REAL NOT NULL DEFAULT 0,
                    claim TEXT, last_error TEXT, message_id TEXT,
                    PRIMARY KEY (notification_id, name)
                );
                CREATE INDEX IF NOT EXISTS deliveries_due ON deliveries(status,next_attempt);
                CREATE TABLE IF NOT EXISTS throttle (
                    target_key TEXT PRIMARY KEY, next_allowed REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS webhook_events (
                    event_key TEXT PRIMARY KEY, expires_at REAL NOT NULL
                );
            """)
        if os.name != "nt":
            os.chmod(self.path, 0o600)

    @contextmanager
    def connect(self):
        connection = sqlite3.connect(self.path, timeout=15, isolation_level=None)
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA busy_timeout=15000")
        try:
            with connection:
                yield connection
        finally:
            connection.close()

    def targets(self):
        with self.connect() as connection:
            rows = connection.execute("SELECT name,type,openid FROM targets ORDER BY name").fetchall()
        return [{"name": row["name"], "type": row["type"], "bound": bool(row["openid"])} for row in rows]

    def binding(self, name, kind):
        if not isinstance(name, str) or not ALIAS_RE.fullmatch(name) or kind not in ("user", "group"):
            raise ApiError("invalid_binding")
        now = self.clock()
        code = secrets.token_urlsafe(24)
        digest = hashlib.sha256(code.encode()).hexdigest()
        with self.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            connection.execute("DELETE FROM bindings WHERE expires_at <= ?", (now,))
            target = connection.execute("SELECT type FROM targets WHERE name=?", (name,)).fetchone()
            if target and target["type"] != kind:
                raise ApiError("target_type_conflict", 409)
            if not target and connection.execute("SELECT COUNT(*) FROM targets").fetchone()[0] >= MAX_TARGETS:
                raise ApiError("target_capacity", 503)
            if connection.execute("SELECT COUNT(*) FROM bindings WHERE name<>?", (name,)).fetchone()[0] >= MAX_BINDINGS:
                raise ApiError("binding_capacity", 503)
            connection.execute("INSERT OR IGNORE INTO targets(name,type,updated_at) VALUES(?,?,?)", (name, kind, now))
            connection.execute("DELETE FROM bindings WHERE name=?", (name,))
            connection.execute("INSERT INTO bindings VALUES(?,?,?,?)", (digest, name, kind, now + 600))
            connection.commit()
        return {"name": name, "type": kind, "code": code, "command": "/bind " + code, "expires_at": int(now + 600)}

    @staticmethod
    def _bind_command(content):
        if not isinstance(content, str) or len(content) > 4096:
            return None
        # QQ group events prefix the content with an @ mention token.
        content = re.sub(r"^(?:\s*<@!?[A-Za-z0-9_-]+>\s*)+", "", content).strip()
        match = re.fullmatch(r"/bind\s+([A-Za-z0-9_-]{20,100})", content)
        return match.group(1) if match else None

    def webhook_event(self, payload, event_key, command_settings=None):
        now = self.clock()
        data = payload.get("d")
        data = data if isinstance(data, dict) else {}
        event_type = payload.get("t")
        kind = None
        openid = ""
        if event_type == "C2C_MESSAGE_CREATE":
            author = data.get("author")
            author = author if isinstance(author, dict) else {}
            kind, openid = "user", author.get("user_openid", "")
        elif event_type in ("GROUP_AT_MESSAGE_CREATE", "GROUP_MESSAGE_CREATE"):
            kind, openid = "group", data.get("group_openid", "")
        code = self._bind_command(data.get("content"))
        with self.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            connection.execute("DELETE FROM webhook_events WHERE expires_at <= ?", (now,))
            connection.execute("DELETE FROM bindings WHERE expires_at <= ?", (now,))
            if connection.execute("SELECT 1 FROM webhook_events WHERE event_key=?", (event_key,)).fetchone():
                return
            if connection.execute("SELECT COUNT(*) FROM webhook_events").fetchone()[0] >= MAX_EVENTS:
                # Fail closed, retaining all event IDs in the signature validity window.
                raise ApiError("event_capacity", 503)
            connection.execute("INSERT INTO webhook_events VALUES(?,?)", (event_key, now + WEBHOOK_WINDOW * 2))
            if code and kind and isinstance(openid, str) and OPENID_RE.fullmatch(openid):
                digest = hashlib.sha256(code.encode()).hexdigest()
                binding = connection.execute("SELECT name,type FROM bindings WHERE code_hash=? AND expires_at>?", (digest, now)).fetchone()
                if binding and binding["type"] == kind:
                    connection.execute("UPDATE targets SET openid=?,updated_at=? WHERE name=? AND type=?", (openid, now, binding["name"], kind))
                    connection.execute("DELETE FROM bindings WHERE code_hash=?", (digest,))
            if command_settings and command_settings.enhancement_webhook_url and kind == "user" and not code:
                from commands import enqueue_command
                enqueue_command(connection, data, openid, command_settings.app_id, now)
            connection.commit()

    def enqueue(self, message, targets, key):
        try:
            message_bytes = len(message.encode("utf-8")) if isinstance(message, str) else 0
            key_bytes = len(key.encode("utf-8")) if isinstance(key, str) else 0
        except UnicodeError:
            raise ApiError("invalid_text") from None
        if not isinstance(message, str) or not message.strip() or message_bytes > 2000:
            raise ApiError("invalid_message")
        if not isinstance(targets, list) or not 1 <= len(targets) <= 20:
            raise ApiError("invalid_targets")
        if any(not isinstance(name, str) or not ALIAS_RE.fullmatch(name) for name in targets):
            raise ApiError("invalid_targets")
        if not isinstance(key, str) or not key or key_bytes > 200:
            raise ApiError("invalid_idempotency_key")
        names = sorted(set(targets))
        payload = json.dumps({"message": message, "targets": names}, ensure_ascii=False, separators=(",", ":"))
        payload_hash = hashlib.sha256(payload.encode("utf-8")).hexdigest()
        key_hash = hashlib.sha256(key.encode("utf-8")).hexdigest()
        now = self.clock()
        with self.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            existing = connection.execute("SELECT id,payload_hash FROM notifications WHERE idempotency_hash=?", (key_hash,)).fetchone()
            if existing:
                if existing["payload_hash"] != payload_hash:
                    raise ApiError("idempotency_conflict", 409)
                return existing["id"]
            bound = []
            for name in names:
                row = connection.execute("SELECT name,type,openid FROM targets WHERE name=?", (name,)).fetchone()
                if not row or not row["openid"]:
                    raise ApiError("target_not_bound", 422)
                bound.append(row)
            active = connection.execute("SELECT COUNT(*) FROM deliveries WHERE status IN ('queued','sending')").fetchone()[0]
            if active + len(names) > MAX_QUEUE:
                raise ApiError("queue_capacity", 503)
            notification_id = secrets.token_hex(16)
            connection.execute("INSERT INTO notifications VALUES(?,?,?,?,?)", (notification_id, key_hash, payload_hash, message, now))
            connection.executemany("INSERT INTO deliveries(notification_id,name,type,openid,next_attempt) VALUES(?,?,?,?,?)", [(notification_id, row["name"], row["type"], row["openid"], now) for row in bound])
            connection.commit()
        return notification_id

    def notification(self, notification_id):
        with self.connect() as connection:
            notification = connection.execute("SELECT id,created_at FROM notifications WHERE id=?", (notification_id,)).fetchone()
            if not notification:
                raise ApiError("not_found", 404)
            rows = connection.execute("SELECT name,type,status,attempts,next_attempt,last_error FROM deliveries WHERE notification_id=? ORDER BY name", (notification_id,)).fetchall()
        targets = [{"name": row["name"], "type": row["type"], "status": row["status"], "attempts": row["attempts"], "next_attempt_at": row["next_attempt"] if row["status"] == "queued" else None, "last_error": row["last_error"]} for row in rows]
        statuses = [row["status"] for row in rows]
        status = "queued" if any(s in ("queued", "sending") for s in statuses) else ("sent" if all(s == "sent" for s in statuses) else "failed")
        return {"ok": True, "id": notification_id, "status": status, "created_at": notification["created_at"], "targets": targets}

    def retry(self, notification_id):
        now = self.clock()
        with self.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            if not connection.execute("SELECT 1 FROM notifications WHERE id=?", (notification_id,)).fetchone():
                raise ApiError("not_found", 404)
            count = connection.execute("SELECT COUNT(*) FROM deliveries WHERE notification_id=? AND status='failed'", (notification_id,)).fetchone()[0]
            if not count:
                raise ApiError("no_failed_targets", 409)
            active = connection.execute("SELECT COUNT(*) FROM deliveries WHERE status IN ('queued','sending')").fetchone()[0]
            if active + count > MAX_QUEUE:
                raise ApiError("queue_capacity", 503)
            connection.execute("UPDATE deliveries SET status='queued',attempts=0,next_attempt=?,lease_until=0,claim=NULL,last_error=NULL WHERE notification_id=? AND status='failed'", (now, notification_id))
            connection.commit()
        return count

    def claim(self):
        now = self.clock()
        with self.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            connection.execute("UPDATE deliveries SET status='failed',last_error='delivery_interrupted',claim=NULL WHERE status='sending' AND lease_until<=? AND attempts>=?", (now, MAX_ATTEMPTS))
            connection.execute("UPDATE deliveries SET status='queued',last_error='delivery_interrupted',claim=NULL,next_attempt=? WHERE status='sending' AND lease_until<=?", (now, now))
            connection.execute("DELETE FROM throttle WHERE next_allowed < ?", (now - 86400,))
            candidates = connection.execute("""SELECT d.*,n.message FROM deliveries d
                JOIN notifications n ON n.id=d.notification_id
                WHERE d.status='queued' AND d.next_attempt<=? ORDER BY d.next_attempt,n.created_at LIMIT ?""", (now, MAX_QUEUE)).fetchall()
            row = None
            target_key = None
            for candidate in candidates:
                candidate_key = hashlib.sha256((candidate["type"] + ":" + candidate["openid"]).encode()).hexdigest()
                throttle = connection.execute("SELECT next_allowed FROM throttle WHERE target_key=?", (candidate_key,)).fetchone()
                if throttle and throttle["next_allowed"] > now:
                    continue
                row, target_key = candidate, candidate_key
                break
            if not row:
                connection.commit()
                return None
            claim = secrets.token_hex(16)
            connection.execute("INSERT OR REPLACE INTO throttle VALUES(?,?)", (target_key, now + MIN_SEND_INTERVAL))
            connection.execute("UPDATE deliveries SET status='sending',attempts=attempts+1,lease_until=?,claim=? WHERE notification_id=? AND name=?", (now + 120, claim, row["notification_id"], row["name"]))
            connection.commit()
            result = dict(row)
            result["attempts"] += 1
            result["claim"] = claim
            return result

    def finish(self, job, error=None, message_id=None):
        now = self.clock()
        if error and error.retryable and job["attempts"] < MAX_ATTEMPTS:
            status = "queued"
            delay = max(MIN_SEND_INTERVAL, min(300, 5 * (2 ** (job["attempts"] - 1))), error.retry_after)
            next_attempt = now + delay
        else:
            status = "failed" if error else "sent"
            next_attempt = now
        with self.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            updated = connection.execute("UPDATE deliveries SET status=?,next_attempt=?,last_error=?,message_id=?,lease_until=0,claim=NULL WHERE notification_id=? AND name=? AND status='sending' AND claim=?", (status, next_attempt, error.code if error else None, message_id, job["notification_id"], job["name"], job["claim"]))
            if updated.rowcount and error and error.retryable:
                target_key = hashlib.sha256((job["type"] + ":" + job["openid"]).encode()).hexdigest()
                # A provider rate limit applies to the recipient, including other jobs.
                next_allowed = now + max(MIN_SEND_INTERVAL, error.retry_after)
                connection.execute("UPDATE throttle SET next_allowed=MAX(next_allowed,?) WHERE target_key=?", (next_allowed, target_key))
            connection.commit()


class Worker:
    def __init__(self, store, client):
        self.store = store
        self.client = client
        self.stop_event = threading.Event()
        self.wake_event = threading.Event()
        self.thread = None

    def run_once(self):
        job = self.store.claim()
        if not job:
            return False
        try:
            response = self.client.send(job["type"], job["openid"], job["message"])
            message_id = response.get("id") if isinstance(response, dict) else None
            if not isinstance(message_id, str) or len(message_id) > 256:
                message_id = None
            self.store.finish(job, message_id=message_id)
            LOGGER.info("Notification %s target %s: sent", job["notification_id"], job["name"])
        except DeliveryError as error:
            self.store.finish(job, error)
            LOGGER.info("Notification %s target %s: %s", job["notification_id"], job["name"], error.code)
        except Exception:
            # Do not log exception text: SDK exceptions may embed credentials or content.
            LOGGER.error("Unexpected delivery failure; notification will be retried")
            self.store.finish(job, DeliveryError("internal_delivery_error", retryable=True))
        return True

    def _run(self):
        while not self.stop_event.is_set():
            try:
                if self.run_once():
                    continue
            except sqlite3.Error:
                LOGGER.error("Delivery storage unavailable")
            self.wake_event.wait(0.5)
            self.wake_event.clear()

    def start(self):
        if self.thread and self.thread.is_alive():
            return
        self.stop_event.clear()
        self.thread = threading.Thread(target=self._run, name="qq-delivery", daemon=True)
        self.thread.start()

    def stop(self):
        self.stop_event.set()
        self.wake_event.set()
        if self.thread:
            self.thread.join(timeout=30)


class BotService:
    def __init__(self, settings, client=None, clock=None, forwarder=None):
        self.settings = settings
        self.clock = clock or time.time
        self.store = Store(settings.data_dir, self.clock)
        self.client = client or OfficialClient(settings, self.clock)
        self.worker = Worker(self.store, self.client)
        from commands import CommandWorker, CommandStore, EnhancementClient
        self.commands = CommandStore(self.store, settings)
        self.command_worker = CommandWorker(self.commands, self.client, forwarder or EnhancementClient(settings, self.clock))

    def start(self):
        self.worker.start()
        if self.settings.enhancement_webhook_url:
            self.command_worker.start()

    def stop(self):
        self.worker.stop()
        self.command_worker.stop()
