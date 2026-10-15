# REST API integration regression

Run from the Enhancement plugin directory:

```powershell
php tests/rest-api/run.php
```

The optional first argument is a Typecho root. PHP needs PDO SQLite, process creation, and local HTTP streams. The runner starts a private PHP server on a random loopback port, creates a temporary SQLite database from Typecho's installation schema, and loads the real core without reading `config.inc.php` or the site's database. No external mail or QQ hooks are registered.

Coverage includes all 76 route variants, saved enable/disable routing, public data filtering and pagination, settings, HTTP methods/CORS, and comments through Typecho's real validation, moderation, counters, filter/finish hooks, rate limit, referer and logged-in CSRF checks. It also verifies no-rewrite links and date/category permalink templates.

Successful runs remove their temporary files. Failed runs print the retained server log path. The fixture router only runs under the local CLI server with the runner's explicit environment variables and its temporary database.
