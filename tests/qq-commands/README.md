# QQ management command regression

Run `php tests/qq-commands/run.php` from the Enhancement directory with PHP and a Typecho source checkout available. An optional first argument supplies the Typecho root.

The suite uses the shared disposable SQLite/HTTP fixtures in `tests/qq-notify`. It verifies the standalone management switch, required configuration, protected secrets, HMAC signatures, source authorization, replay protection, Typecho comment counts and lifecycle hooks, comment replies and moderation, friend-link review, and rejection of commands under retired providers.

No real QQ service or production configuration is used. Successful runs stop their servers and remove the temporary database; failed runs retain their fixtures and logs for diagnosis. The PHP/Node integration in `tests/qq-channel` verifies commands from the actual independent service.
