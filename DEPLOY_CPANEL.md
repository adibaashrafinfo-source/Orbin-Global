# DEPLOY_CPANEL.md — Hosting check and deployment on your own cPanel

Orbin POS runs **entirely on your cPanel hosting**: PHP (Laravel) + the **MySQL/MariaDB database you create in cPanel** + static React files. No Supabase or any other external service.

---

## 1. Hosting check (do this BEFORE Phase 0)

Open cPanel and note the answers. Give them to the coding agent (it adapts the build using the fallbacks in `orbin-pos-spec.json → hosting`).

| # | Check | Where in cPanel | Needed | If not available |
|---|---|---|---|---|
| 1 | PHP version selectable | **MultiPHP Manager** | 8.2 or higher | 8.1 works with Laravel 10 (agent switches) |
| 2 | PHP extensions on | **Select PHP Version** → Extensions | `pdo_mysql, mbstring, intl, gd, zip, fileinfo, bcmath, ctype, json, openssl, tokenizer, xml, curl` | Ask the host to enable; `intl`/`gd` are required for Bangla PDF and images |
| 3 | PHP limits | **MultiPHP INI Editor** | `memory_limit ≥ 256M`, `max_execution_time ≥ 60`, `upload_max_filesize ≥ 16M`, `post_max_size ≥ 24M` | Raise via INI Editor; if the host forbids, PDF fallback is used |
| 4 | Database | **MySQL® Databases** + **phpMyAdmin** | MySQL 8.0+ or MariaDB 10.5+ (version shown on phpMyAdmin home) | Ask host to upgrade; older versions are not supported |
| 5 | Terminal / SSH | **Terminal** (or SSH access) | Preferred | **No SSH → the web installer is used** (FR-111) |
| 6 | Composer on server | Terminal: `composer -V` | Optional | Not needed; release zip contains `vendor/` |
| 7 | Cron Jobs | **Cron Jobs** | Every-minute interval allowed | If minimum is 5 min, tell the agent (scheduler still works; alerts delayed) |
| 8 | Subdomain with custom document root | **Domains** (or Subdomains) | Yes | Alternative layout in §3 |
| 9 | SSL | **SSL/TLS Status** (AutoSSL) | Active for the subdomain | Enable AutoSSL / Let's Encrypt |
| 10 | Disk space and inodes | cPanel home page stats | ≥ 5 GB free, inodes not near limit | Clean or upgrade |
| 11 | Backups | **Backup** / **JetBackup** | Available | App still makes its own nightly dump |
| 12 | Email account for sending | **Email Accounts** | One mailbox for password reset mails | Create `noreply@yourdomain` |

Tip: use a dedicated subdomain such as `pos.yourdomain.com`. Keep the app separate from your other websites.

---

## 2. How it runs

```
Browser ──HTTPS──► Apache (cPanel) ──► backend/public
                                        ├─ /api/*, /sanctum/*, /verify/*, /files/*  → Laravel (PHP) → MySQL (cPanel)
                                        └─ everything else                         → React app (static files)
Cron (every minute) ──► Laravel scheduler → backups, integrity check, stock alerts, queue
```

Same domain for site and API: no CORS problems, cookie login works safely.

---

## 3. Folder layout on the server

Recommended (dedicated subdomain):

```
/home/<cpanel_user>/orbin-pos/          ← application (NOT inside public_html)
    app/ bootstrap/ config/ database/ routes/ resources/ vendor/
    storage/                             ← private files, logs, invoices PDFs
    public/                              ← subdomain document root points HERE
        index.php  .htaccess  assets/  uploads/
    .env                                 ← secrets (never in public/)
/home/<cpanel_user>/backups/orbin-pos/  ← nightly database dumps (outside web root)
```

Alternative (host cannot change document root): put the contents of `public/` into `~/public_html/pos/` and keep everything else in `~/orbin-pos/`. The agent adjusts the two path lines in `index.php` and tests it. Only do this if step 8 above is "No".

---

## 4. One-time setup in cPanel

1. **Create the database:** *MySQL® Databases* → create database (e.g., `cpuser_orbinpos`) → create a user with a strong random password → **add user to database with ALL PRIVILEGES**. Write down: host (`localhost`), database name, user, password. cPanel adds your account prefix to both names.
2. **Create the subdomain** (`pos.yourdomain.com`) and set its **document root** to `orbin-pos/public`.
3. **PHP:** *MultiPHP Manager* → set that subdomain to PHP 8.2+. Enable extensions and limits from §1.
4. **SSL:** *SSL/TLS Status* → run AutoSSL for the subdomain; confirm the padlock.
5. **Mailbox:** create `noreply@yourdomain.com` for password-reset emails.
6. **Security:** turn on cPanel two-factor authentication for your own account.

---

## 5. Release package

The coding agent (or CI) builds **`orbin-pos-release-<version>.zip`** containing: backend code with `vendor/` (no dev dependencies, optimized autoload), the built React app inside `public/`, `.htaccess` files, `.env.example`, and the install notes. It never contains `.env`, `tests`, or `node_modules`.

Every phase is deployed to the **staging subdomain** first (e.g., `pos-staging.yourdomain.com` with its own database), then to production after you approve.

---

## 6. Install

### Path A — you have Terminal/SSH
1. Upload the zip with **File Manager**, extract into `~/orbin-pos/`.
2. Copy `.env.example` to `.env` and fill the values (§7).
3. Run (in Terminal, inside `~/orbin-pos`): generate app key → run migrations with seed → create the super admin → cache config/routes → link/prepare storage. (The agent gives the exact commands for the installed version.)
4. Set permissions: folders 755, files 644, `storage/` and `bootstrap/cache/` writable.

### Path B — no SSH (web installer)
1. Upload and extract the zip as above.
2. Create `.env` with DB credentials and a **long random `INSTALL_KEY`**.
3. Open `https://pos.yourdomain.com/install`, enter the `INSTALL_KEY`, and follow the steps: requirements check → database test → migrations and seed → create super admin.
4. The installer writes `storage/installed.lock` and disables itself. Confirm `/install` now shows "Already installed".

After either path: log in, **change the super admin password**, open **Admin → Health**, and make sure every check is green.

---

## 7. `.env` values (names only — never share or commit real values)

`APP_NAME`, `APP_ENV=production`, `APP_KEY`, `APP_DEBUG=false`, `APP_URL=https://pos.yourdomain.com`, `APP_TIMEZONE=UTC`, `DB_CONNECTION=mysql`, `DB_HOST=localhost`, `DB_PORT=3306`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SESSION_DRIVER=database`, `SESSION_LIFETIME=480`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS=pos.yourdomain.com`, `QUEUE_CONNECTION=database`, `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `LOG_CHANNEL=daily`, `INSTALL_KEY` (remove after install).

---

## 8. Web server rules (`.htaccess`, provided in the release)

- Send `/api`, `/sanctum`, `/verify`, `/files` to Laravel; every other non-file URL to the React app (so page refresh works).
- Force HTTPS, HSTS; security headers (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy).
- Deny dotfiles (`.env`, `.git`), `*.sql`, `*.log`, `*.zip`, `composer.*`.
- **Disable PHP execution** inside `public/uploads/`.
- Gzip/Brotli and long cache headers for hashed assets; no-cache for the app shell.
- Directory listing off.

---

## 9. Cron job (one entry only)

In **Cron Jobs**, add a job **every minute**:

```
* * * * * /usr/local/bin/php /home/<cpanel_user>/orbin-pos/artisan schedule:run >> /dev/null 2>&1
```

Use the PHP binary path your host shows for PHP 8.2 (for example `/opt/cpanel/ea-php82/root/usr/bin/php`). The scheduler then runs: nightly database backup (02:30 Dhaka), nightly integrity check (03:00), hourly stock alerts, and the queue worker (stops when empty). **Admin → Health** shows "Last cron run"; if older than 2 minutes, the cron is wrong.

---

## 10. Backups and restore

| Layer | What | How often | Where |
|---|---|---|---|
| 1 | App nightly gzip database dump | Daily, keep 14 | `~/backups/orbin-pos/` (outside web root); listed in **Admin → Backups** |
| 2 | Hosting provider backup (JetBackup / cPanel Backup) | As provided | Provider |
| 3 | **Off-site copy** | Weekly | You download from Admin → Backups to your PC / cloud drive |
| 4 | Uploaded files (`public/uploads`, `storage/app/private`) | Weekly | Provider backup or File Manager → Compress → download |

**Restore test (do once before go-live, then every quarter):** create a scratch database in cPanel → import the latest dump in phpMyAdmin (or Terminal) → point a staging `.env` to it → run **Integrity check** → compare a few numbers. Full steps in `docs/restore-runbook.md`.

---

## 11. Update procedure and rollback

1. Deploy to **staging** first; run the phase demo.
2. Production: turn on **maintenance mode** (Admin → Health, or the maintenance command).
3. **Take a database backup** (Admin → Backups → Backup now) and download it.
4. Upload and extract the new release over `~/orbin-pos/` — **keep `.env` and `storage/`** (the zip does not contain them).
5. Run migrations (SSH command, or Admin → Health → "Run pending migrations" when no SSH).
6. Clear and rebuild caches. Turn off maintenance mode.
7. Check Admin → Health and make one test sale (void it, or use the training branch).

**Rollback:** maintenance mode → restore the previous release zip → restore the pre-update database backup → clear caches → verify.

---

## 12. Security checklist

- [ ] App, `vendor`, `storage`, `.env` are outside the public web root (or blocked)
- [ ] `APP_DEBUG=false`; no error details shown to users
- [ ] HTTPS forced; HSTS on
- [ ] DB user is used by this app only; strong unique password; Remote MySQL **off**
- [ ] `INSTALL_KEY` removed and `/install` locked
- [ ] File permissions 755/644; only `storage` and `bootstrap/cache` writable
- [ ] cPanel 2FA on; FTP accounts removed if unused; no shared logins
- [ ] Default super admin password changed; each staff member has their own account and role
- [ ] Backups verified by a real restore test
- [ ] Uploads folder cannot run PHP (try uploading a test `.php` — it must not execute)

---

## 13. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Blank page / HTTP 500 | Wrong PHP version, missing extension, permissions, bad `.env` | Check `storage/logs` (File Manager); set PHP 8.2+; enable extensions; fix permissions |
| 404 on page refresh | SPA fallback rule missing | Make sure the release `.htaccess` is in `public/` and `mod_rewrite` is on |
| Login works then logs out / 419 | Cookie domain or HTTPS mismatch | Set `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `APP_URL` to the exact HTTPS subdomain |
| API returns 403 | User role lacks permission | Check Settings → Roles |
| PDF fails / blank | `memory_limit` too low, missing `gd`/`mbstring` | Raise limits; enable extensions; see Health page |
| Bangla shows boxes in PDF | Font not embedded or `intl`/`mbstring` missing | Re-deploy release (fonts inside); enable extensions |
| "Last cron run" is old | Cron path wrong | Use the PHP path from your host; check cron email output |
| Import/export times out | Large file | Use smaller batches (the app chunks automatically); raise `max_execution_time` |
| Cannot upload images | `upload_max_filesize` / permissions | Raise INI; make `public/uploads` writable |
| Deadlock / lock wait errors | Heavy concurrent use | App retries automatically; report if frequent |

---

## 14. Go-live checklist

- [ ] Hosting check passed and recorded in `docs/decisions.md`
- [ ] Staging and production run from release zips
- [ ] Company profile complete (name, address, phones, BIN/TIN, bank, signature) and logo uploaded
- [ ] Opening data imported: products/variants, opening stock, customers, suppliers, opening balances, financial accounts
- [ ] One real sale test: bKash + cash split, invoice PDF auto-downloads with logo and full details
- [ ] Backup restore tested; off-site copy routine agreed
- [ ] Staff accounts and roles created; manager PINs set
- [ ] Cron heartbeat green; Health page all green
- [ ] UAT checklist signed off
