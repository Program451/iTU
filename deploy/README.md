# DigitalOcean deployment

This deployment runs AulieAta in its own PHP/Apache and MariaDB containers. Only the web container joins the existing `birge_default` proxy network. The database has no public port. Existing applications and their databases are not changed.

The source checkout is `/opt/aulieata/source`. Private settings are stored outside it in `/opt/aulieata/private/config.php` and `/opt/aulieata/private/database.env`. The configuration follows `aulieata-secrets.example.php`, with database host `db`. Database environment keys are `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD` and `MARIADB_ROOT_PASSWORD`. Create independent random database, webhook and encryption secrets. Never add these files to Git.

Build and launch with `docker compose -f deploy/compose.yaml up -d --build`, then run `docker exec -u www-data aulieata-web php tools/migrate.php`. The proxy must route only the new hostname to `aulieata-web:80`; validate its complete configuration before a graceful reload. Preserve all existing hostname blocks.

Install a separate `/etc/cron.d/aulieata` entry to execute `docker exec -u www-data aulieata-web php /var/www/app/public/cron.php` once per minute. Back up the database volume, media volume and private configuration together. Do not replace encryption keys during updates.

The bind directory `/run/aulieata` is reserved for private deployment and test files. It is outside the document root. Database and web containers have explicit resource limits for the small shared server.
