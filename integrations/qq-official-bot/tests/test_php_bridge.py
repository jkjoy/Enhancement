"""Optional PHP -> Waitress -> durable worker integration, without real QQ calls."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import threading
import time
import unittest

import requests
from waitress import create_server

from app import create_app


@unittest.skipUnless(os.environ.get("ENHANCEMENT_PLUGIN_DIR") and shutil.which("php"),
                     "Set ENHANCEMENT_PLUGIN_DIR and install PHP for the cross-project test")
class PhpBridgeTests(unittest.TestCase):
    def test_php_notification_reaches_user_and_group_with_background_worker(self):
        helper = Path(os.environ["ENHANCEMENT_PLUGIN_DIR"]) / "QqNotifyHelper.php"
        self.assertTrue(helper.is_file())
        calls = []

        class FakeQQ:
            def send(self, kind, openid, content):
                calls.append((kind, openid, content))
                return {"id": "offline-message-" + str(len(calls))}

        with tempfile.TemporaryDirectory(prefix="qq-php-bridge-") as directory:
            config = {"QQ_APP_ID": "123456789", "QQ_APP_SECRET": "offline-test-secret",
                      "BOT_API_TOKEN": "offline-api-token-" + "x" * 32, "BOT_DATA_DIR": directory}
            app = create_app(config, client=FakeQQ(), start_worker=True)
            service = app.extensions["bot_service"]
            server = create_server(app, host="127.0.0.1", port=0, threads=4)
            thread = threading.Thread(target=server.run, daemon=True)
            thread.start()
            session = requests.Session()
            session.trust_env = False
            base = "http://127.0.0.1:" + str(server.effective_port)
            authorization = {"Authorization": "Bearer " + config["BOT_API_TOKEN"]}
            try:
                self.assertEqual(session.get(base + "/healthz", timeout=3).status_code, 200)
                for name, kind, openid in (("owner", "user", "USER_OFFLINE"), ("blog-group", "group", "GROUP_OFFLINE")):
                    binding = session.post(base + "/api/bindings", json={"name": name, "type": kind}, headers=authorization, timeout=3)
                    self.assertEqual(binding.status_code, 201)
                    data = {"content": binding.json()["command"], "author": {"user_openid": openid}}
                    if kind == "group":
                        data = {"content": "<@!123456789> " + binding.json()["command"], "group_openid": openid}
                    event = {"op": 0, "id": "offline-bind-" + name, "t": "C2C_MESSAGE_CREATE" if kind == "user" else "GROUP_AT_MESSAGE_CREATE", "d": data}
                    raw = json.dumps(event, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
                    stamp = str(int(time.time()))
                    signature = service.settings.signing_key().sign(stamp.encode() + raw).hex()
                    response = session.post(base + "/qq/webhook", data=raw, headers={
                        "Content-Type": "application/json", "X-Bot-Appid": config["QQ_APP_ID"],
                        "X-Signature-Timestamp": stamp, "X-Signature-Ed25519": signature,
                    }, timeout=3)
                    self.assertEqual(response.status_code, 200)

                settings = {"qq_notify_provider": "official", "qq_official_url": base,
                            "qq_official_token": config["BOT_API_TOKEN"], "qq_official_targets": "owner\nblog-group",
                            "qq_official_include_links": "0"}
                program = r'''function _t($s, ...$a) { return $a ? vsprintf($s, $a) : $s; }
require $argv[1];
$settings = json_decode(stream_get_contents(STDIN), true);
echo json_encode(Enhancement_QqNotifyHelper::sendTestMessage("博客评论联调 https://example.test/post", $settings));'''
                php_program = Path(directory) / "offline-bridge.php"
                php_program.write_text("<?php\n" + program, encoding="utf-8")
                result = subprocess.run([shutil.which("php"), str(php_program), str(helper)],
                                        input=json.dumps(settings), text=True, encoding="utf-8",
                                        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15, check=True)
                self.assertTrue(result.stdout.startswith("{"), result.stdout + result.stderr)
                receipt = json.loads(result.stdout)
                self.assertTrue(receipt["success"], receipt)
                self.assertTrue(receipt["id"])
                deadline = time.monotonic() + 5
                while True:
                    status = session.get(base + "/api/notifications/" + receipt["id"], headers=authorization, timeout=3).json()
                    if status["status"] != "queued" or time.monotonic() >= deadline:
                        break
                    time.sleep(0.05)
                self.assertEqual(status["status"], "sent", status)
                self.assertEqual({(kind, openid) for kind, openid, content in calls},
                                 {("user", "USER_OFFLINE"), ("group", "GROUP_OFFLINE")})
                self.assertTrue(all("https://" not in content and "博客评论联调" in content for kind, openid, content in calls))
                self.assertEqual(len(calls), 2)
            finally:
                session.close()
                service.stop()
                server.close()
                server.task_dispatcher.shutdown(timeout=3)
                thread.join(timeout=3)


if __name__ == "__main__":
    unittest.main()
