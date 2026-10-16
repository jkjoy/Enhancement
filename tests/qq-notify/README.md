# QQ notification regression

Run `php tests/qq-notify/run.php` from the plugin directory, optionally passing the Typecho root.

The suite uses the real Typecho configuration form, temporary SQLite and two isolated local HTTP servers. It checks retirement of the old transport, migration of saved/restored settings, obsolete queue isolation and retry exclusion, the inactive provider, official authentication and receipts, invalid responses, redirect rejection, UTF-8 truncation, link removal, durable handoff and retry, recipient snapshots, provider switching, saved-token preservation and configuration validation.

No production configuration or database is loaded, and no request is forwarded to QQ. Successful runs stop both servers and remove their temporary files. Failed runs retain the fixture directory and print its path.

The separate `qq-official-bot` service has its own Python tests. Setting `ENHANCEMENT_PLUGIN_DIR` when running those tests also enables the PHP-to-service HTTP integration check.
