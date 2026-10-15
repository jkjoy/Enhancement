# Agent QQ regression

Run `php tests/qq-agent/run.php` from the Enhancement directory with PHP, Python 3 and a Typecho source checkout available. An optional first argument supplies the Typecho root.

The suite uses the shared disposable SQLite/HTTP fixtures in `tests/qq-notify`. It verifies OpenClaw message-tool payloads, Hermes V2 HMAC and direct delivery receipts, redirect rejection, bounded UTF-8 notifications, queue retries and configuration changes, saved secrets, command switches and source authorization, signed request replay protection, Typecho comment counts and lifecycle hooks, friend-link review, and the actual Python signing client calling PHP over HTTP.

No real Agent, QQ service or production configuration is used. Successful runs stop their servers and remove the temporary database; failed runs retain their fixtures and logs for diagnosis.
