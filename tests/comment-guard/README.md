# Comment guard regression

Run `php tests/comment-guard/run.php` from the Enhancement directory, optionally passing a Typecho root. `php tests/comment-guard/unit.php` runs the standalone checks alone.

The suite checks XSS, HTML, Chinese and literal keyword rules, IPv4/IPv6/CIDR boundaries, settings validation, rate-window expiration, subnet aggregation, duplicate comments, storage failures, and six PHP processes competing for the same lock. Real HTTP checks exercise the existing native Feedback and REST bridge with saved settings, spoofed/explicitly trusted forwarding headers, shared quotas, CAPTCHA retries, and rejection before database insertion, counters, and finish hooks. Rate failures require `Retry-After` and accept Typecho's compatible 403 fallback or native 429.

It reuses the REST suite's temporary SQLite fixture and private CLI server. It never reads the production Typecho configuration or database, and never registers external email/QQ hooks. Rate state uses the fixture's unique site URL and secret in the system temporary directory and is deleted after every run. The metadata endpoint is gated by the CLI server and the runner's dedicated environment flag.
