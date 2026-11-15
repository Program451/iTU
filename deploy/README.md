# DigitalOcean deployment

This deployment runs AulieAta in its own PHP/Apache and MariaDB containers. Only the web container joins the existing `birge_default` proxy network. The database has no public port. Existing applications and their databases are not changed.

The source checkout is `/opt/aulieata/source`. Private settings are stored outside it in `/opt/aulieata/private/config.php` and `/opt/aulieata/private/database.env`. The configuration follows `aulieata-secrets.example.php`, with database host `db`. Database environment keys are `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD` and `MARIADB_ROOT_PASSWORD`. Create independent random database, webhook and encryption secrets. Never add these files to Git.

Build and launch with `docker compose -f deploy/compose.yaml up -d --build`, then run `docker exec -u www-data aulieata-web php tools/migrate.php`. The proxy must route only the new hostname to `aulieata-web:80`; validate its complete configuration before a graceful reload. Preserve all existing hostname blocks.

Install a separate `/etc/cron.d/aulieata` entry to execute `docker exec -u www-data aulieata-web php /var/www/app/public/cron.php` once per minute. Back up the database volume, media volume and private configuration together. Do not replace encryption keys during updates.

The bind directory `/run/aulieata` is reserved for private deployment and test files. It is outside the document root. Database and web containers have explicit resource limits for the small shared server.

Set `gemini_model` in the private configuration to a text model verified with the deployed key. The workspace, structured tools, and Telegram use this setting; speech uses its separate TTS model. `gemini_media_users` is an optional allowlist for server-managed Gemini media. A workspace's explicit Cloudflare connection takes precedence; disabling the managed connection is retained per workspace. Image access still depends on the provider's actual quota.

Run `bash deploy/backup.sh` as root to create a private backup of this application's SQL database, media, configuration and source revision under `/opt/aulieata/backups`. The installed `aulieata-backup` cron runs at 22:17 UTC (03:17 Kazakhstan), retaining seven days. Copies are on the same Droplet, so they do not protect against loss of the server. Restore only into the AulieAta database/media volumes, preserving the encryption key and matching database credentials; never import into another project's database.

Verification used a separate database named `itu_test_aulieata` and a temporary test container. PHP suites covered authentication, CSRF, staff permissions, data isolation, inventory, bookings, social queues, private media, Telegram retry safety, announcements and lessons. JavaScript/source checks run with `npm test`. External publication providers are mocked by those suites; live Gemini text/audio, Telegram text/voice, and Cloudflare images/ideas require separate checks against the deployed service.

Optional official-source search uses tavily_key and an explicit tavily_users workspace allowlist in private configuration. Basic search is limited to ten requests per workspace/day and 900 per key/calendar month. Set the provider key limit to 900 too and keep pay-as-you-go disabled. Search sends the entered query only, limits sources to HTTPS government/operator domains, and returns citations checked against actual results; Gemini summarizes the returned excerpts. No search key is exposed in page boot data. tests/web-search.php verifies isolation, citations, domain filtering and quota enforcement without external calls.

Apache access logs omit query strings and referrers so OAuth authorization codes and signed media links are not recorded.
