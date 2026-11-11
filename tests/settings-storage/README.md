Run `php tests/settings-storage/run.php /path/to/typecho` with PDO SQLite.

The suite creates a disposable Typecho installation and database, injects SQLite read/write failures, and checks the real form and backup restoration paths. It verifies that failed saves preserve settings, caches, notification bindings, activation state and routes; successful saves preserve form secrets, synchronize SMTP aliases and apply restoration as a replacement. It never loads the installed site's configuration or sends notifications.
