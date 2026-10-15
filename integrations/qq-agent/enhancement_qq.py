#!/usr/bin/env python3
"""Invoke a finite Enhancement operation using trusted QQ event metadata."""

import argparse
import hashlib
import hmac
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

COMMANDS = (
    "status", "comments.list", "comment.approve", "comment.waiting", "comment.spam",
    "comment.reply", "links.list", "link.approve", "link.reject",
)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=COMMANDS)
    parser.add_argument("--sender", required=True, help="QQ sender OpenID from trusted channel metadata")
    parser.add_argument("--message-id", required=True, help="Original QQ message ID; reuse on retry")
    argument_source = parser.add_mutually_exclusive_group()
    argument_source.add_argument("--args", help="Command arguments as a JSON object")
    argument_source.add_argument("--args-file", help="Read arguments from a UTF-8 JSON file")
    options = parser.parse_args()
    url = os.environ.get("ENHANCEMENT_COMMAND_URL", "")
    secret = os.environ.get("ENHANCEMENT_COMMAND_SECRET", "")
    parts = urllib.parse.urlsplit(url)
    if parts.scheme not in ("http", "https") or not parts.hostname or parts.username or parts.password or parts.fragment:
        parser.error("Set ENHANCEMENT_COMMAND_URL to the plugin's complete HTTP(S) command endpoint")
    if not 32 <= len(secret) <= 1024 or any(not 33 <= ord(char) <= 126 for char in secret):
        parser.error("Set ENHANCEMENT_COMMAND_SECRET to the separate 32+ character command secret")
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,128}", options.sender) or not 1 <= len(options.message_id) <= 512:
        parser.error("Use the sender OpenID and message ID from trusted QQ channel metadata")
    try:
        if options.args_file:
            with open(options.args_file, "rb") as source:
                argument_json = source.read(16385).decode("utf-8-sig")
        else:
            argument_json = options.args or "{}"
        if len(argument_json.encode("utf-8")) > 16384:
            parser.error("Command arguments exceed 16 KiB")
        arguments = json.loads(argument_json)
    except (OSError, ValueError):
        parser.error("--args must be a JSON object")
    if not isinstance(arguments, dict):
        parser.error("--args must be a JSON object")
    identity = json.dumps([options.sender, options.message_id, options.command], ensure_ascii=True, separators=(",", ":"))
    payload = {"request_id": hashlib.sha256(identity.encode()).hexdigest(), "sender": options.sender,
               "command": options.command, "args": arguments}
    raw = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    if len(raw) > 16384:
        parser.error("Command body exceeds 16 KiB")
    timestamp = str(int(time.time()))
    signature = hmac.new(secret.encode("ascii"), timestamp.encode() + b"." + raw, hashlib.sha256).hexdigest()
    request = urllib.request.Request(url, data=raw, method="POST", headers={
        "Content-Type": "application/json", "Accept": "application/json",
        "X-Enhancement-Timestamp": timestamp, "X-Enhancement-Signature": signature,
    })
    try:
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=15) as response:
            result = json.loads(response.read(65537))
        if not isinstance(result, dict) or result.get("ok") is not True:
            raise ValueError("Invalid command receipt")
        print(json.dumps(result, ensure_ascii=False))
        return 0
    except urllib.error.HTTPError as error:
        try:
            result = json.loads(error.read(65537))
            code = result.get("error", "command_rejected") if isinstance(result, dict) else "command_rejected"
        except (ValueError, UnicodeError):
            code = "command_rejected"
        print(json.dumps({"ok": False, "status": error.code, "error": code}, ensure_ascii=False))
    except (OSError, ValueError):
        # Never include URLs, request headers, credential values or response bodies.
        print(json.dumps({"ok": False, "error": "connection_or_receipt_failed", "retry": "Reuse the original sender, message-id, command and args"}))
    return 1


if __name__ == "__main__":
    sys.exit(main())
