# QQ notification regression

Run `php tests/qq-notify/run.php` from the plugin directory, optionally passing the Typecho root.

The suite uses the real Typecho configuration form, temporary SQLite and two isolated local HTTP servers. The form fixture runs Widget_Plugins_Config::config(), including the plugin's config entry and Typecho's binding of every saved key; removed fields must accept binding without rendering their values or producing HTTP 500. It checks migration of saved/restored settings, obsolete queue isolation and retry exclusion, the inactive provider, official Bot service authentication and receipts, invalid responses, redirect rejection, UTF-8 truncation, link removal, durable handoff and retry, recipient snapshots, saved-token preservation and configuration validation.

The Weixin integration checks cover private state persistence and compare-and-swap, UTF-8 message formatting, authenticated administrator POST actions and CSRF, recipient binding and redacted status, expired-session settings isolation, and logout cleanup.

No production configuration or database is loaded, and no request is forwarded to QQ or Weixin. Successful runs stop both servers and remove their temporary files. Failed runs retain the fixture directory and print its path. Additional Weixin protocol tests use `php tests/notify-channels/run.php`.

The separate `qq-official-bot` service has its own Python tests. Setting `ENHANCEMENT_PLUGIN_DIR` when running those tests also enables the PHP-to-service HTTP integration check.
