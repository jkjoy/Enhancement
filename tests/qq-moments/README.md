# Official QQ moment callback regression

Run `php tests/qq-moments/run.php [Typecho root]` from the plugin directory. Requires PHP with PDO SQLite and a local Typecho installation. The fixture creates a disposable database and local HTTP server; it never reads production configuration or sends messages to QQ.

The tests exercise the anonymous POST action, timestamped HMAC verification, invalid payload rejection, private alias authorization, real moment inserts, QQ expressions and Unicode emoji, private visibility, publishing replies, durable deduplication, disabling publishing and secret rotation.

`php tests/bot-moments/qq-official.php` covers QQ media drafts and configuration changes. `php tests/bot-moments/media.php` covers verified image data and official QQ CDN URL validation, including protocol-relative URLs.

For the full service pipeline, install Python 3.12 and `integrations/qq-official-bot/requirements.txt`, then run `php tests/qq-moments/service.php [Typecho root] [Python executable]`. This starts real local Waitress and Typecho HTTP servers, signs QQ events with Ed25519, forwards HMAC requests to the plugin, verifies SQLite writes and exercises passive replies with a simulated QQ HTTP transport. It also covers a lost response after publishing and both automatic and manual reply retries without duplicate publication. `ENHANCEMENT_QQ_PYTHON` can select Python when the second argument is omitted.
