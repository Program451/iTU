#!/usr/bin/env bash
set -euo pipefail
umask 077
base=/opt/aulieata/backups
mkdir -p "$base"
work=$(mktemp -d "$base/.building-XXXXXX")
trap 'rm -rf -- "$work"' EXIT
docker exec aulieata-db sh -c 'exec mariadb-dump --user=root --password="$MARIADB_ROOT_PASSWORD" --single-transaction --routines --events "$MARIADB_DATABASE"' | gzip > "$work/database.sql.gz"
docker exec aulieata-web tar -C /var/www/app -czf - aulieata-media > "$work/media.tar.gz"
cp -- /opt/aulieata/private/config.php /opt/aulieata/private/database.env "$work/"
git -C /opt/aulieata/source rev-parse HEAD > "$work/source-revision.txt"
archive="$base/aulieata-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
tar -C "$work" -czf "$archive.partial" .
mv -- "$archive.partial" "$archive"
find "$base" -maxdepth 1 -type f -name 'aulieata-*.tar.gz' -mtime +7 -delete
printf 'Backup ready: %s\n' "$archive"
