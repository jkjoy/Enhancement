# Official QQ Webhook publishing regression

Run `php tests/qq-moments/run.php [Typecho root]` from the plugin directory. Requires PHP with PDO SQLite and sodium, plus a local Typecho installation. The fixture creates a disposable database and local HTTP server; it never reads production configuration or sends messages to QQ. Official token and message HTTP requests are intercepted by the fixture transport.

The tests send actual Ed25519-signed QQ challenges and events through the anonymous Typecho HTTP action. They cover administrator-only binding with CSRF protection, one-time private and group binding using the settings UI parameters, missing/tampered/expired signatures, original commands, QQ expressions and Unicode emoji, private visibility, real moment inserts, unauthorized senders, durable duplicate-event handling, passive reply payloads, reply retries, disabling publishing and credential rotation.

`php tests/bot-moments/qq-official.php` covers QQ media drafts and configuration changes. `php tests/bot-moments/media.php` covers verified image data and official QQ CDN URL validation, including protocol-relative URLs.
