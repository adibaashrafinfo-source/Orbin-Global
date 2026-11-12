# Staging deployment checklist (P0 gate item 6)

This part needs your cPanel and cannot be done from the build environment. Everything below follows `DEPLOY_CPANEL.md`.

1. Create the staging subdomain (for example `pos-staging.yourdomain.com`) with document root `orbin-pos/public`, PHP 8.2+, AutoSSL, and its own empty MySQL database and user.
2. Build the release: `bash deploy/build-release.sh 0.1.0` (or download the `orbin-pos-release` artifact from the CI run).
3. Upload the zip with File Manager and extract into `~/orbin-pos/`.
4. Copy `.env.example` to `.env`, fill `APP_URL`, `DB_*`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, mail settings. Leave `SEED_DEMO_USERS=true` for staging only.
5. In Terminal inside `~/orbin-pos`:
   ```bash
   php artisan key:generate --force
   php artisan migrate --seed --force
   php artisan storage:link   # not required; uploads live in public/uploads
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
   Production instead creates its admin with `php artisan orbin:make-super-admin`.
6. Add the cron entry from `deploy/cron.example` (every minute).
7. Open the site, sign in as `admin@orbin.local`, open **Admin → Health** and confirm: all checks green, "Last cron run" under 2 minutes.
8. Verify protections: `/.env` and `/composer.json` return 403/404, `APP_DEBUG=false`, uploading a test `.php` into `public/uploads/brand` does not execute.
9. Send the Health page screenshot and the PHP/MySQL versions so they are recorded in `docs/decisions.md`.
