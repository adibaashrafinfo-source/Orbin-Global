# Operations runbook (P6)

For the owner's cPanel hosting. Read `DEPLOY_CPANEL.md` first for the hosting check and the first install; this runbook covers what you do afterwards. Nothing here has been run on a real cPanel account yet (no hosting exists at the time of writing). Each step was rehearsed locally on MariaDB 10.11 as noted.

## 1. Files and folders on the server

| Path | What it is | Web-reachable? |
|---|---|---|
| `~/orbin-pos/` | the application (the release zip extracted) | No. Only `public/` is the document root |
| `~/orbin-pos/public/` | document root of the subdomain | Yes (this is all the browser sees) |
| `~/orbin-pos/.env` | secrets and database login | No. Keep it outside `public/`; `APP_DEBUG=false` |
| `~/backups/` | nightly database dumps and CSV exports | No. Outside the web root |

## 2. Cron

One cPanel cron entry, every minute (see `deploy/cron.example`):

```bash
* * * * * cd ~/orbin-pos && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs:

| Job | When (Asia/Dhaka) | What |
|---|---|---|
| `system:heartbeat` | every minute | Admin → Health shows "Last cron run" |
| `queue:work --stop-when-empty` | every minute | drains the database queue |
| `integrity:check` | 03:00 | proves stock, costs, receipts and ledgers (exits 1 on drift) |
| `stock:alerts` | hourly | low and out-of-stock alerts |
| `db:backup` | 02:30 | gzipped dump to `~/backups/`, 14 days kept |

If Admin → Health shows **Last cron run** older than 2 minutes, the cron entry is wrong or the hosting has stopped it.

## 3. Backup

- **Nightly (automatic):** `db:backup` at 02:30. Files: `~/backups/<database>-YYYYMMDD-HHMMSS.sql.gz`. Dumps older than `BACKUP_KEEP_DAYS` (default 14) are deleted. Set `BACKUP_DIR` in `.env` to choose another folder outside the web root.
- **Hosting provider backups:** the second layer. Turn them on in cPanel (Backup Wizard or the provider's own backup); the owner is responsible for this.
- **Weekly copy off the server (owner):** download the newest `*.sql.gz` from `~/backups/` to a computer or cloud drive you control.
- **CSV of every table (on demand):** `php artisan orbin:export-csv` writes one CSV per business table to `~/backups/csv-YYYYMMDD-HHMMSS/`. It skips the session, cache and queue tables. Use it when you want the data in a spreadsheet, or as a readable second copy.

Check that the backup is being made: the `backup` line on Admin → Health turns to OK after the first night. Until then it shows "No backup found yet" (a warning, not a failure).

## 4. Restore (tested)

Never restore over the live database. Restore into a scratch database first, check it, then decide.

1. In cPanel create a scratch database and user with a new name (for example `orbin_restore`).
2. Pick the dump to restore (`~/backups/...sql.gz`).
3. In Terminal:
   ```bash
   gunzip -c ~/backups/orbin_pos-YYYYMMDD-HHMMSS.sql.gz | mysql -u SCRATCH_USER -p SCRATCH_DB
   ```
4. Point a copy of `.env` at the scratch database (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) and run:
   ```bash
   php artisan integrity:check
   ```
   It must end with `Result: ok`.
5. Compare a few numbers with the live system (today's sales, one customer's due, one stock level).
6. Only after the owner agrees: stop the site (`php artisan down`), restore into the live database with the same command, run `php artisan up`. Record the decision in the operations log.
7. Drop the scratch database and its user.

**Rehearsal (local, P6):** `bash deploy/restore-rehearsal.sh` dumps the database, restores it into `<name>_restore_rehearsal`, compares row counts of nine key tables and runs `integrity:check` on the copy, then drops the copy. It passed with 8 of 8 tables and `integrity:check` = ok. Run it on a computer with MariaDB before trusting a new release.

## 5. Update

1. Take a backup now: `php artisan db:backup`.
2. Put the site in maintenance: `php artisan down`.
3. Upload the new release zip with File Manager into `~/orbin-pos/`, overwriting the files. Keep `.env` and `storage/` (do not overwrite them with the zip's copies).
4. In Terminal inside `~/orbin-pos`:
   ```bash
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
5. `php artisan up`.
6. Open Admin → Health: all checks green (the HTTPS check must be OK on the real site).
7. Sign in and check one invoice and one report.

## 6. Rollback

Every migration in the project has a `down()` method, so a single release can be undone. But a rollback that drops a column also drops its data, so restore from the backup where in doubt.

1. `php artisan down`
2. Put the previous release's files back (keep the zip of the previous version; the release is named `orbin-pos-release-<version>.zip`).
3. If the new release added migrations, decide with the owner:
   - **Only additive changes (new tables or columns):** `php artisan migrate:rollback --step=N` where N is the number of new migrations. Check the migration names against the release notes.
   - **Anything doubtful:** restore the database from the backup taken in step 1 of the update (section 4).
4. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
5. `php artisan up`, then Admin → Health.

## 7. Demo data

The demo logins (one per role, password `Orbin@2026`) exist only on staging, created by setting `SEED_DEMO_USERS=true`. Remove them before go-live:

```bash
php artisan orbin:demo-remove        # asks for confirmation
```

It soft-deletes the five demo logins; their history stays in the audit log. The command refuses to run when `APP_ENV=production`. Production creates its first owner with `php artisan orbin:make-super-admin` and asks that person to change the password at first login.

## 8. Security settings to confirm on the real site

- `APP_ENV=production`, `APP_DEBUG=false`.
- `SESSION_SECURE_COOKIE=true` and `SESSION_DOMAIN` set to the subdomain (the example file has a placeholder). Cookies are not sent over plain HTTP with these settings, so test on HTTPS only.
- `SANCTUM_STATEFUL_DOMAINS` set to the subdomain.
- Open `https://<subdomain>/.env`, `/composer.json` and `/vendor/autoload.php`: they must not show file contents (403 or 404). **Not tested yet**, because the local test used PHP's built-in server, which does not read `.htaccess`. The real check is on the cPanel subdomain.
- Upload a test `.php` file into `public/uploads/` and open it: it must not run.

## 9. Updating the operating checklist

When something here changes, change this file and note the date and the release in `docs/decisions.md`.
