# AI summary privacy regression

Run `php tests/ai-summary/run.php` to verify that hidden reply blocks never enter the AI summary source. The checks call only the production text preparation methods through reflection. They load no site configuration or database and make no network or AI requests.
