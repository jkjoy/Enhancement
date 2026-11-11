# Shortcode privacy integration tests

```sh
php tests/shortcodes/run.php /path/to/typecho
```

Requires PHP with PDO SQLite and local process/HTTP support. The suite builds a temporary site root linked to Typecho's source, creates its own SQLite database, and uses a random loopback port. It never loads the installed `config.inc.php`, the site's database, or external notification hooks. Temporary files are removed after the run.

Coverage includes forged remembered identities, failed and real successful core comment submissions, signed HttpOnly guest credentials, moderation approval/revocation, tampering and expiry, per-article binding, authenticated `authorId`, excerpt/feed/REST privacy, stale summaries, in-place hook migration, public article references and preservation of the current archive. Ordinary text must perform zero extra queries.

The suite also uses Typecho's real plugin activation/deactivation API in memory to verify runtime upgrades preserve third-party hooks, avoid duplicate callbacks, and keep ownership with Enhancement when another plugin is enabled or disabled.

Guest credentials last 30 days and retain the most recent 24 comments. Guests who commented before this fix need to submit a new comment in the same browser to receive a signed credential. Approval of that comment unlocks the article; moving it back to waiting/spam or deleting it revokes access on the next request.
