"""Authenticated local API and signed QQ webhook for the notification worker."""

import hashlib
import hmac
import json
import logging
import re
import signal
import sqlite3

from cryptography.exceptions import InvalidSignature
from flask import Flask, jsonify, request
from werkzeug.exceptions import HTTPException

from bot_service import ApiError, BotService, Settings, WEBHOOK_WINDOW


def create_app(config=None, client=None, clock=None, start_worker=False, forwarder=None):
    settings = config if isinstance(config, Settings) else Settings.from_env(config)
    service = BotService(settings, client=client, clock=clock, forwarder=forwarder)
    app = Flask(__name__)
    app.config.update(MAX_CONTENT_LENGTH=65536, JSON_SORT_KEYS=False)
    app.extensions["bot_service"] = service
    private_key = settings.signing_key()

    @app.before_request
    def authenticate():
        if request.path.startswith("/api/"):
            header = request.headers.get("Authorization", "")
            if not hmac.compare_digest(header.encode("utf-8"), ("Bearer " + settings.api_token).encode("utf-8")):
                raise ApiError("unauthorized", 401)

    @app.after_request
    def response_headers(response):
        response.headers["Cache-Control"] = "no-store"
        response.headers["X-Content-Type-Options"] = "nosniff"
        return response

    @app.errorhandler(ApiError)
    def api_error(error):
        return jsonify(ok=False, error=error.code), error.status

    @app.errorhandler(sqlite3.Error)
    def storage_error(error):
        app.logger.error("Bot storage unavailable")
        return jsonify(ok=False, error="storage_unavailable"), 503

    @app.errorhandler(HTTPException)
    def http_error(error):
        return jsonify(ok=False, error="http_" + str(error.code)), error.code

    @app.errorhandler(Exception)
    def unexpected_error(error):
        app.logger.error("Unexpected API failure")
        return jsonify(ok=False, error="internal_error"), 500

    def body_object():
        body = request.get_json(silent=True)
        if not isinstance(body, dict):
            raise ApiError("invalid_json")
        return body

    @app.get("/healthz")
    def health():
        with service.store.connect() as connection:
            connection.execute("SELECT 1")
        return jsonify(ok=True)

    @app.get("/api/targets")
    def targets():
        return jsonify(ok=True, targets=service.store.targets())

    @app.post("/api/bindings")
    def bindings():
        body = body_object()
        return jsonify(ok=True, **service.store.binding(body.get("name"), body.get("type"))), 201

    @app.post("/api/notifications")
    def notifications():
        body = body_object()
        notification_id = service.store.enqueue(body.get("message"), body.get("targets"), body.get("idempotency_key"))
        service.worker.wake_event.set()
        return jsonify(ok=True, status="queued", id=notification_id), 202

    @app.get("/api/notifications/<notification_id>")
    def notification(notification_id):
        return jsonify(service.store.notification(notification_id))

    @app.post("/api/notifications/<notification_id>/retry")
    def retry(notification_id):
        count = service.store.retry(notification_id)
        service.worker.wake_event.set()
        return jsonify(ok=True, status="queued", id=notification_id, retried_targets=count), 202

    @app.get("/api/commands")
    def commands():
        return jsonify(ok=True, commands=service.commands.list_status())

    @app.get("/api/commands/<command_id>")
    def command(command_id):
        return jsonify(service.commands.status(command_id))

    @app.post("/api/commands/<command_id>/retry")
    def retry_command(command_id):
        service.commands.retry(command_id)
        service.command_worker.wake_event.set()
        return jsonify(ok=True, id=command_id, status="queued"), 202

    def verify_signature(raw):
        signature = request.headers.get("X-Signature-Ed25519", "")
        timestamp = request.headers.get("X-Signature-Timestamp", "")
        if not re.fullmatch(r"[0-9]{1,12}", timestamp) or abs(service.clock() - int(timestamp)) > WEBHOOK_WINDOW:
            raise ApiError("invalid_signature", 401)
        if not re.fullmatch(r"[0-9a-fA-F]{128}", signature):
            raise ApiError("invalid_signature", 401)
        try:
            private_key.public_key().verify(bytes.fromhex(signature), timestamp.encode("ascii") + raw)
        except (InvalidSignature, ValueError):
            raise ApiError("invalid_signature", 401) from None

    @app.post("/qq/webhook")
    def webhook():
        raw = request.get_data(cache=True)
        try:
            payload = json.loads(raw)
        except (ValueError, UnicodeError):
            raise ApiError("invalid_json") from None
        if not isinstance(payload, dict):
            raise ApiError("invalid_event")
        app_id = request.headers.get("X-Bot-Appid")
        if app_id is not None and app_id != settings.app_id:
            raise ApiError("wrong_app_id", 401)
        opcode = payload.get("op")
        if type(opcode) is not int:
            raise ApiError("invalid_opcode")
        signature_present = "X-Signature-Ed25519" in request.headers or "X-Signature-Timestamp" in request.headers
        if opcode == 13:
            if signature_present:
                verify_signature(raw)
            data = payload.get("d")
            if not isinstance(data, dict):
                raise ApiError("invalid_challenge")
            plain = data.get("plain_token")
            timestamp = data.get("event_ts")
            # Challenge and event signatures share the timestamp+body domain.
            # Accept only an opaque token, never JSON that could become a forged event.
            if not isinstance(plain, str) or not re.fullmatch(r"[A-Za-z0-9_-]{1,256}", plain):
                raise ApiError("invalid_challenge")
            if not isinstance(timestamp, str) or not re.fullmatch(r"[0-9]{1,12}", timestamp) or abs(service.clock() - int(timestamp)) > WEBHOOK_WINDOW:
                raise ApiError("invalid_challenge")
            signed = private_key.sign((timestamp + plain).encode("utf-8")).hex()
            return jsonify(plain_token=plain, signature=signed)
        verify_signature(raw)
        if opcode != 0:
            raise ApiError("unsupported_opcode")
        event_id = payload.get("id")
        if event_id is not None and (not isinstance(event_id, str) or not 1 <= len(event_id) <= 256):
            raise ApiError("invalid_event_id")
        # QQ retries may change signature timestamps. Use event ID, or immutable raw payload.
        event_key = hashlib.sha256((("id:" + event_id).encode("utf-8")) if event_id else (b"body:" + raw)).hexdigest()
        service.store.webhook_event(payload, event_key, settings)
        service.command_worker.wake_event.set()
        return jsonify(op=12)

    if start_worker:
        service.start()
    return app


def main():
    try:
        from waitress import serve
    except ImportError:
        raise SystemExit("Production serving requires Python 3.10+ and installed requirements") from None
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    try:
        app = create_app(start_worker=True)
    except (ValueError, OSError, sqlite3.Error) as error:
        # Configuration errors never include credential values.
        logging.error("Bot startup failed (%s)", type(error).__name__)
        raise SystemExit(1) from None
    service = app.extensions["bot_service"]

    def shutdown(signum, frame):
        service.stop()
        raise SystemExit(0)

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)
    try:
        serve(app, host="0.0.0.0", port=service.settings.port, threads=8, clear_untrusted_proxy_headers=True, max_request_body_size=65536)
    finally:
        service.stop()


if __name__ == "__main__":
    main()
