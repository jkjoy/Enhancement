# QQ notification regression

Run `php tests/qq-notify/run.php` from the plugin directory, optionally passing the Typecho root.

The suite uses the real Typecho configuration form, temporary SQLite and an isolated local HTTP server. The form fixture runs Widget_Plugins_Config::config(), including the plugin's config entry and Typecho's binding of every saved key; removed fields must accept binding without rendering their values or producing HTTP 500. It checks migration of saved/restored settings, removal of service URLs and tokens, the inactive provider, AppID/AppSecret configuration, saved-secret preservation, official QQ notification receipts, invalid responses, UTF-8 truncation and link removal. QQ HTTP requests use an injected fixture transport.

The Weixin integration checks cover private state persistence and compare-and-swap, UTF-8 message formatting, authenticated administrator POST actions and CSRF, recipient binding and redacted status, expired-session settings isolation, and logout cleanup.

No production configuration or database is loaded, and no request is forwarded to QQ or Weixin. Successful runs stop the local servers and remove their temporary files. Failed runs retain the fixture directory and print its path. Additional Weixin protocol tests use `php tests/notify-channels/run.php`. Direct official QQ Webhook signatures, binding and publishing are covered by `php tests/qq-moments/run.php`.
