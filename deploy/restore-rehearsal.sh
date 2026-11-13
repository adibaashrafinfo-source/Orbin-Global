#!/usr/bin/env bash
# Backup and restore rehearsal (AT-022, DEPLOY_CPANEL.md §10). Dumps the app database, restores the dump into a scratch
# database, compares row counts for the key tables and runs integrity:check against the scratch copy. Never touches the live database.
# Usage: bash deploy/restore-rehearsal.sh   (needs mysqldump and mysql, and root access to create the scratch database)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT/backend"
LIVE=$(php artisan tinker --execute='echo config("database.connections.".config("database.default").".database");' | tail -1)
SCRATCH="${LIVE}_restore_rehearsal"
DIR="$(mktemp -d)"
APP_USER=$(php artisan tinker --execute='echo config("database.connections.".config("database.default").".username");' | tail -1)
cleanup() { mysql -uroot -e "DROP DATABASE IF EXISTS \`$SCRATCH\`;" || true; rm -rf "$DIR"; }
trap cleanup EXIT

php artisan db:backup --dir="$DIR"
DUMP=$(ls "$DIR"/*.sql.gz | head -1)
mysql -uroot -e "DROP DATABASE IF EXISTS \`$SCRATCH\`; CREATE DATABASE \`$SCRATCH\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -e "GRANT ALL PRIVILEGES ON \`$SCRATCH\`.* TO '$APP_USER'@'localhost';"
gunzip -c "$DUMP" | mysql -uroot "$SCRATCH"

for t in sales sale_items stock_movements party_payments audit_logs users stock_levels financial_accounts; do
  a=$(mysql -uroot -N -e "SELECT COUNT(*) FROM \`$LIVE\`.$t"); b=$(mysql -uroot -N -e "SELECT COUNT(*) FROM \`$SCRATCH\`.$t")
  [ "$a" = "$b" ] && echo "OK   $t $a rows" || { echo "FAIL $t live=$a restored=$b"; exit 1; }
done
DB_DATABASE="$SCRATCH" php artisan integrity:check
mysql -uroot -e "REVOKE ALL PRIVILEGES ON \`$SCRATCH\`.* FROM '$APP_USER'@'localhost';" || true
echo "Restore rehearsal passed. Scratch database dropped."
