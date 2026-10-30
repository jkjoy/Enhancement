"""Durable, authenticated private-message forwarding and independent QQ replies."""

import hashlib
import hmac
import json
import logging
import re
from datetime import datetime

import requests

from bot_service import ApiError, DeliveryError, MAX_ATTEMPTS, MAX_QUEUE, MIN_SEND_INTERVAL, OPENID_RE, Worker


LOGGER = logging.getLogger("qq_bot")
COMMAND_RE = re.compile(r"^(?:私密图文瞬间|图文瞬间|私密瞬间|发瞬间|瞬间)(?:[\s:：]|$)|^(?:发布瞬间|取消瞬间)\s*$")
COMMAND_RETENTION = 30 * 86400


def message_timestamp(value):
    if type(value) is int:
        return value if value > 0 else None
    if not isinstance(value, str) or len(value) > 64:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        if parsed.tzinfo is None:
            return None
        seconds = int(parsed.timestamp())
        return seconds if seconds > 0 else None
    except (ValueError, OverflowError, OSError):
        return None


def enqueue_command(connection, data, openid, account, now):
    """Called inside the signed webhook event transaction; never performs HTTP."""
    if not isinstance(openid, str) or not OPENID_RE.fullmatch(openid):
        return
    text = data.get("content", "")
    attachments = data.get("attachments", [])
    if not isinstance(text, str) or not isinstance(attachments, list):
        return
    if not attachments and not COMMAND_RE.match(text.lstrip()):
        return
    message_id = data.get("id")
    timestamp = message_timestamp(data.get("timestamp"))
    if not isinstance(message_id, str) or not re.fullmatch(r"[\x21-\x7e]{1,256}", message_id) or timestamp is None:
        return
    aliases = [row[0] for row in connection.execute(
        "SELECT name FROM targets WHERE type='user' AND openid=? ORDER BY name", (openid,)).fetchall()]
    if not aliases:
        return
    identifier = hashlib.sha256((account + "\n" + message_id).encode("utf-8")).hexdigest()
    if connection.execute("SELECT 1 FROM commands WHERE id=?", (identifier,)).fetchone():
        return
    connection.execute("DELETE FROM commands WHERE status IN ('complete','failed','skipped') AND updated_at<?", (now - COMMAND_RETENTION,))
    if connection.execute("SELECT COUNT(*) FROM commands WHERE status IN ('queued','sending')").fetchone()[0] >= MAX_QUEUE:
        raise ApiError("command_queue_capacity", 503)
    payload = {"version": 1, "message": {
        "id": message_id, "account": account, "sender": openid, "conversation": openid,
        "conversation_type": "private", "text": text, "timestamp": timestamp,
        "aliases": aliases, "attachments": attachments,
    }}
    raw = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
    try:
        valid_size = len(raw.encode("utf-8")) <= 65536
    except UnicodeError:
        valid_size = False
    if not valid_size:
        raise ApiError("invalid_command_payload")
    connection.execute("""INSERT INTO commands(id,payload,openid,message_id,next_attempt,created_at,updated_at)
        VALUES(?,?,?,?,?,?,?)""", (identifier, raw, openid, message_id, now, now, now))


class EnhancementClient:
    def __init__(self, settings, clock, session=None):
        self.settings, self.clock = settings, clock
        self.session = session or requests.Session()
        self.session.trust_env = False

    def forward(self, raw):
        stamp = str(int(self.clock()))
        encoded = raw.encode("utf-8")
        signature = hmac.new(self.settings.enhancement_command_secret.encode("ascii"),
                             stamp.encode("ascii") + b"\n" + encoded, hashlib.sha256).hexdigest()
        response = None
        try:
            response = self.session.post(self.settings.enhancement_webhook_url, data=encoded, headers={
                "Content-Type": "application/json; charset=utf-8", "Accept": "application/json",
                "X-Enhancement-Timestamp": stamp, "X-Enhancement-Signature": signature,
            }, timeout=(3, 30), allow_redirects=False, stream=True)
            if not 200 <= response.status_code < 300:
                raise DeliveryError("plugin_http_" + str(response.status_code), retryable=True)
            body = bytearray()
            for chunk in response.iter_content(8192):
                body.extend(chunk)
                if len(body) > 65536:
                    raise DeliveryError("plugin_response_too_large", retryable=True)
            try:
                result = json.loads(body)
            except (ValueError, UnicodeError):
                raise DeliveryError("plugin_invalid_json", retryable=True) from None
            if not isinstance(result, dict) or result.get("ok") is not True or not isinstance(result.get("reply"), str):
                raise DeliveryError("plugin_invalid_response", retryable=True)
            if result.get("result") is not None and not isinstance(result["result"], (dict, list)):
                raise DeliveryError("plugin_invalid_response", retryable=True)
            return {"ok": True, "result": result.get("result"), "reply": result["reply"]}
        except requests.RequestException:
            raise DeliveryError("plugin_network_error", retryable=True) from None
        finally:
            if response is not None:
                response.close()


class CommandStore:
    def __init__(self, store, settings):
        self.store, self.settings, self.clock = store, settings, store.clock
        with store.connect() as connection:
            connection.executescript("""
                CREATE TABLE IF NOT EXISTS commands (
                    id TEXT PRIMARY KEY, payload TEXT NOT NULL, openid TEXT NOT NULL,
                    message_id TEXT NOT NULL, stage TEXT NOT NULL DEFAULT 'forward',
                    status TEXT NOT NULL DEFAULT 'queued', attempts INTEGER NOT NULL DEFAULT 0,
                    forward_attempts INTEGER NOT NULL DEFAULT 0, reply_attempts INTEGER NOT NULL DEFAULT 0,
                    response TEXT, reply TEXT, next_attempt REAL NOT NULL,
                    lease_until REAL NOT NULL DEFAULT 0, claim TEXT, last_error TEXT,
                    created_at REAL NOT NULL, updated_at REAL NOT NULL
                );
                CREATE INDEX IF NOT EXISTS commands_due ON commands(status,next_attempt);
            """)

    @staticmethod
    def summary(row):
        return {key: row[key] for key in ("id", "stage", "status", "forward_attempts", "reply_attempts", "last_error", "created_at", "updated_at")}

    def list_status(self):
        with self.store.connect() as connection:
            rows = connection.execute("SELECT * FROM commands ORDER BY created_at DESC LIMIT 100").fetchall()
        return [self.summary(row) for row in rows]

    def status(self, identifier):
        with self.store.connect() as connection:
            row = connection.execute("SELECT * FROM commands WHERE id=?", (identifier,)).fetchone()
        if not row:
            raise ApiError("not_found", 404)
        return dict(ok=True, **self.summary(row))

    def retry(self, identifier):
        with self.store.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            row = connection.execute("SELECT status FROM commands WHERE id=?", (identifier,)).fetchone()
            if not row:
                raise ApiError("not_found", 404)
            if row["status"] != "failed":
                raise ApiError("command_not_failed", 409)
            if connection.execute("SELECT COUNT(*) FROM commands WHERE status IN ('queued','sending')").fetchone()[0] >= MAX_QUEUE:
                raise ApiError("command_queue_capacity", 503)
            connection.execute("UPDATE commands SET status='queued',attempts=0,next_attempt=?,updated_at=?,last_error=NULL WHERE id=? AND status='failed'", (self.clock(), self.clock(), identifier))
            connection.commit()

    def claim(self):
        if not self.settings.enhancement_webhook_url:
            return None
        import secrets
        now = self.clock()
        with self.store.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            connection.execute("UPDATE commands SET status=CASE WHEN attempts>=? THEN 'failed' ELSE 'queued' END,claim=NULL,last_error='command_interrupted',next_attempt=?,updated_at=? WHERE status='sending' AND lease_until<=?", (MAX_ATTEMPTS, now, now, now))
            rows = connection.execute("SELECT * FROM commands WHERE status='queued' AND next_attempt<=? ORDER BY created_at LIMIT ?", (now, MAX_QUEUE)).fetchall()
            for row in rows:
                # Serialize messages from one sender, including image draft steps.
                previous = connection.execute("SELECT 1 FROM commands WHERE openid=? AND rowid<? AND status IN ('queued','sending') LIMIT 1", (row["openid"], connection.execute("SELECT rowid FROM commands WHERE id=?", (row["id"],)).fetchone()[0])).fetchone()
                if previous:
                    continue
                if row["stage"] == "reply":
                    target_key = hashlib.sha256(("user:" + row["openid"]).encode()).hexdigest()
                    throttle = connection.execute("SELECT next_allowed FROM throttle WHERE target_key=?", (target_key,)).fetchone()
                    if throttle and throttle["next_allowed"] > now:
                        continue
                    connection.execute("INSERT OR REPLACE INTO throttle VALUES(?,?)", (target_key, now + MIN_SEND_INTERVAL))
                if row["stage"] == "forward":
                    payload = json.loads(row["payload"])
                    bound = {target[0] for target in connection.execute("SELECT name FROM targets WHERE type='user' AND openid=?", (row["openid"],))}
                    if not set(payload["message"]["aliases"]).issubset(bound):
                        connection.execute("UPDATE commands SET status='skipped',last_error='binding_changed',updated_at=? WHERE id=?", (now, row["id"]))
                        continue
                claim = secrets.token_hex(16)
                column = "forward_attempts" if row["stage"] == "forward" else "reply_attempts"
                connection.execute("UPDATE commands SET status='sending',attempts=attempts+1," + column + "=" + column + "+1,lease_until=?,claim=?,updated_at=? WHERE id=?", (now + 120, claim, now, row["id"]))
                connection.commit()
                return dict(row, attempts=row["attempts"] + 1, claim=claim)
            connection.commit()
        return None

    def finish_forward(self, job, response):
        reply = response["reply"].encode("utf-8")[:1800].decode("utf-8", errors="ignore")
        status = "queued" if reply else "complete"
        now = self.clock()
        with self.store.connect() as connection:
            connection.execute("UPDATE commands SET stage='reply',status=?,attempts=0,response=?,reply=?,next_attempt=?,updated_at=?,lease_until=0,claim=NULL,last_error=NULL WHERE id=? AND status='sending' AND claim=?", (status, json.dumps(response, ensure_ascii=False), reply, now, now, job["id"], job["claim"]))

    def finish(self, job, error=None):
        now = self.clock()
        retry = error and error.retryable and job["attempts"] < MAX_ATTEMPTS
        status = "queued" if retry else ("failed" if error else "complete")
        delay = max(3.1, min(300, 5 * 2 ** (job["attempts"] - 1)), error.retry_after) if retry else 0
        with self.store.connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            updated = connection.execute("UPDATE commands SET status=?,next_attempt=?,updated_at=?,last_error=?,lease_until=0,claim=NULL WHERE id=? AND status='sending' AND claim=?", (status, now + delay, now, error.code if error else None, job["id"], job["claim"]))
            if updated.rowcount and error and error.retryable and job["stage"] == "reply":
                target_key = hashlib.sha256(("user:" + job["openid"]).encode()).hexdigest()
                connection.execute("UPDATE throttle SET next_allowed=MAX(next_allowed,?) WHERE target_key=?", (now + max(MIN_SEND_INTERVAL, error.retry_after), target_key))
            connection.commit()


class CommandWorker(Worker):
    def __init__(self, store, client, forwarder):
        super().__init__(store, client)
        self.forwarder = forwarder

    def run_once(self):
        job = self.store.claim()
        if not job:
            return False
        try:
            if job["stage"] == "forward":
                response = self.forwarder.forward(job["payload"])
                self.store.finish_forward(job, response)
            else:
                self.client.send("user", job["openid"], job["reply"], reply_message_id=job["message_id"])
                self.store.finish(job)
            LOGGER.info("Command %s stage %s: accepted", job["id"], job["stage"])
        except DeliveryError as error:
            self.store.finish(job, error)
            LOGGER.info("Command %s stage %s: %s", job["id"], job["stage"], error.code)
        except Exception:
            LOGGER.error("Unexpected command failure; stage will be retried")
            self.store.finish(job, DeliveryError("internal_command_error", retryable=True))
        return True
