# Restore runbook (completed and rehearsed in P6)

The full procedure, with the update and rollback steps, is in `operations-runbook.md`. The rehearsal is scripted in `deploy/restore-rehearsal.sh`.

Nightly dumps are created by `db:backup` (added in P6) into `~/backups/`, 14-day retention (`BACKUP_KEEP_DAYS`).

1. In cPanel create a scratch database and user (never restore over production).
2. phpMyAdmin → Import the latest `*.sql.gz`, or in Terminal: `gunzip -c backup.sql.gz | mysql -u USER -p DATABASE`.
3. Point a staging `.env` at the scratch database.
4. Run `php artisan integrity:check` (P2) and compare a few numbers with production.
5. Drop the scratch database when done.

Rehearsal result (local, MariaDB 10.11, P6): 8 of 8 key tables matched row for row, `integrity:check` = ok on the copy, scratch database dropped afterwards. Not yet rehearsed on the cPanel account.
