# Restore runbook (draft, completed and rehearsed in P6)

Nightly dumps are created by `db:backup` (added in P6) into `~/backups/orbin-pos/`, 14-day retention.

1. In cPanel create a scratch database and user (never restore over production).
2. phpMyAdmin → Import the latest `*.sql.gz`, or in Terminal: `gunzip -c backup.sql.gz | mysql -u USER -p DATABASE`.
3. Point a staging `.env` at the scratch database.
4. Run `php artisan integrity:check` (P2) and compare a few numbers with production.
5. Drop the scratch database when done.
