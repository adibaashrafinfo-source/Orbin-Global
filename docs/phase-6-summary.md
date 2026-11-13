# PHASE P6 REPORT — Hardening and Launch

## 1. What was built

- **Nightly backup** (`php artisan db:backup`, 02:30 Asia/Dhaka): gzipped dump into `~/backups/` (outside the web root), 14 days kept, password never on the command line.
- **Restore rehearsal** (`deploy/restore-rehearsal.sh`): dump, restore into a scratch copy, compare nine key tables, run `integrity:check` on the copy, drop it.
- **CSV of every business table** (`php artisan orbin:export-csv`, NFR-070), on demand.
- **Demo logins removal** (`php artisan orbin:demo-remove`): soft-deletes the five staging logins, refuses in production.
- **Accessibility fixes** from the Lighthouse and axe runs: sidebar tooltip no longer breaks the active link in collapsed mode; text uses a darker grey that meets AA; status badges have readable text; login has a `main` landmark; account button and rows-per-page select have accessible names; donut chart hidden from assistive technology beside its legend.
- **Axe regression spec** (`tests/e2e/a11y.spec.ts`): six screens, fails on serious or critical WCAG 2.2 AA violations. All pass.
- **Version in health**: the release's `VERSION` file is now the version the app reports.
- **Operations runbook** (`docs/operations-runbook.md`): folders, cron, backup, restore, update, rollback, demo removal, security settings to confirm on the real site.
- **User guides** in English (`docs/user-guide-en.md`) and Bangla (`docs/user-guide-bn.md`).
- **UAT checklist** (`docs/uat-checklist.md`) for every acceptance test AT-001 to AT-023, with what is automated and what the owner should check.
- **Performance and accessibility results** (`docs/accessibility-performance.md`).
- Release `orbin-pos-release-0.7.0.zip` built. It does not yet include the health version fix; a rebuild is needed before deploy (see section 4).

## 2. Traceability

| ID | Evidence | Status |
|---|---|---|
| NFR-001 performance | Lighthouse POS 90; search p95 41 ms on 10,000 variants (local) | **Partly.** Target met locally; 4G first load and host numbers not measured |
| NFR-010 security | Authorization matrix 122 tests; rate limits and headers in config and `.htaccess`; `.env` outside `public/` | **Partly.** `.htaccess` deny rules not tested on Apache |
| NFR-011 audit | Phase 1–5 audit tests | Done (carried) |
| NFR-040 testing | 540 backend tests, 73 browser tests (second clean run) | Done. One earlier full browser run failed 11 tests, not reproduced (D-096) |
| NFR-070 backup and recovery | `db:backup` tests (3); CSV export test (1); rehearsal passed locally; runbook | **Partly.** Not run on the cPanel account; off-site copy is the owner's task |
| AT-019 roles | Authorization matrix, 122 tests, all green | Done |
| AT-020 reduced motion | Reviewed in code | **Not automated** (D-095) |
| AT-021 fresh install | Local: release zip extracted, migrations and seed, owner created, login, health, backup, demo removal | **Partly.** Not run on cPanel |
| AT-022 backup restore | `deploy/restore-rehearsal.sh`: 8 of 8 tables match, `integrity:check` ok on the copy | Done locally. Not on cPanel |
| AT-023 races | Phase 3 and 4 concurrency tests (carried) | Done (carried) |

## 3. Test results

| Suite | Command | Result |
|---|---|---|
| Backend | `php artisan test` (MariaDB 10.11) | 540 passed, 4,848 assertions, 0 skipped (6 new: backup, retention, failed dump, demo removal, production refusal, CSV export) |
| Authorization matrix | `php artisan test --filter=Matrix` | 122 passed |
| Code style | `vendor/bin/pint --test` | passed |
| Frontend lint | `npm run lint` | clean |
| Frontend types | `npm run typecheck` | clean |
| Frontend unit | `npx vitest run` | 29 passed (includes locale parity) |
| Browser | all specs on a freshly seeded database | 73 passed, 17 skipped (screenshot capture only). Includes 6 new axe checks |
| Lighthouse | desktop, local, logged in | POS: performance 90, accessibility 100, best practices 100 |
| Fresh install (local) | release zip → scratch folder, `migrate:fresh --seed`, `orbin:make-super-admin`, login 200, health 200 | Works; health shows the expected HTTPS and limits warnings on local http |
| Search latency | 60 requests, 10,000 variants | median 28 ms, p95 41 ms |
| Restore rehearsal | `bash deploy/restore-rehearsal.sh` | 8 of 8 tables match; `integrity:check` ok; scratch copy dropped |

Problems found while doing this phase, and fixed:

- **Sidebar in collapsed mode** lost its active style and its 40 px height (a tooltip wrapper merged its props into the link's class function). Fixed.
- **Subtle text** (`ink-subtle`) on white was about 2.5:1, below AA. Changed to `ink-muted` (D-088).
- **Health reported version 0.1.0** for a 0.7.0 release. The app never read the `VERSION` file. Fixed (D-090).
- **CSV export listed tables from every database the account could see**, which would have copied other databases into the backup. Restricted to the current database, with a test.
- **Restore rehearsal failed first** because the scratch database grant was missing for the app user. The script now grants that one database for the copy and revokes it on exit.
- **Lighthouse first run measured the login page** instead of the POS, because a Chrome instance was reused across pages. Each page now gets a fresh browser; the first results were discarded.

Not tested: a cPanel host; Apache and its `.htaccess` deny rules (the local check used PHP's built-in server, which serves the app shell for `/.env` but does not read `.htaccess`; the file content was not exposed); 4G and mobile-throttled Lighthouse; tablet and phone widths for the POS; Firefox, Edge and Safari; reduced motion (AT-020); a second hosting restore; the owner's UAT.

## 4. Staging and production deployment

**Staging: not done.** No cPanel hosting exists yet, and the project's rules do not allow another host. The steps in `DEPLOY_CPANEL.md` and `docs/staging-deploy-checklist.md` are the path; the runbook covers the rest.

**Production: not done, and not started.** The brief asks to wait for your explicit go-ahead before deploying to production.

Before the next release is uploaded, rebuild it so that the version fix (D-090) is in the zip: `bash deploy/build-release.sh 0.7.1`. The 0.7.0 zip on disk still reports 0.1.0 in health.

## 5. Decisions and deviations

See `docs/decisions.md` D-084 to D-097. The ones that matter most:

- **D-084** backup is a command using `mysqldump`; if the host lacks that binary, the provider's backups are the fallback.
- **D-086** the demo seed is only the five demo logins. No sample business data was invented. **Needs your answer** (section 8).
- **D-087** CSV export is on demand, not scheduled.
- **D-088** the spec's `textSubtle` token does not meet AA for text. The app uses `ink-muted` instead. **Needs your answer** (section 8).
- **D-096** one browser run failed in an unexplained way and did not repeat. This is an open risk.

## 6. Demo script (staging)

1. Run on staging: `SEED_DEMO_USERS=true php artisan migrate:fresh --seed` (staging only; never on the live database).
2. Sign in as **cashier@orbin.local**, make one sale, check the PDF downloads.
3. Sign in as **manager@orbin.local**: dashboard, Reports → Sales summary → Compare → Export → Excel.
4. Sign in as **store@orbin.local** and try `/reports/profit-loss`: you should see "You do not have permission to do this."
5. Admin → Health: every check green on HTTPS, "Last cron run" under two minutes.
6. Run `php artisan db:backup`, then `bash deploy/restore-rehearsal.sh` on a development machine.
7. Run `php artisan orbin:demo-remove`, then sign in as the owner account created with `orbin:make-super-admin`.

## 7. Known gaps and risks

- Everything that needs the real host is open: the cPanel install, HTTPS, `.htaccess` deny rules, the cron heartbeat, the backup on the host, the restore on the host, speed on the host.
- The dashboard still has one open accessibility audit (`aria-hidden-focus`, D-094).
- The browser suite has state-dependence: one run failed for an unexplained reason and did not repeat (D-096). Each spec should create its own data.
- Reduced motion (AT-020) and tablet and phone widths are not verified.
- Report and file exports are English only (from Phase 5); the receipt and statement Bangla question is still open.
- The variant search uses `LIKE` on a text column. Fast enough at 10,000 variants locally; watch it on the host.
- Earlier open items remain: POS channel and salesperson pickers, "keep change as advance", over-receipt rule, label stock choice, count-sheet printing, overstock alerts, expense and payment attachments, rejecting a payment, the leftover "due" on an invoice after a return (D-061), VAT paid (D-078).

## 8. Questions for the owner

1. **Hosting** (still needed): the cPanel check answers from `DEPLOY_CPANEL.md §1`, the subdomain name, and the HTTPS certificate. Nothing can be deployed or measured on the real site without them.
2. **Spec colour token** (D-088): darken `textSubtle` in the spec to at least 4.5:1 on white (recommended), or keep it for decoration only?
3. **Demo data for training** (D-086): do you want a sample catalogue and a few sales for training? If yes, I will add it as a separate seeder the removal command can clear.
4. **Backup on the host** (D-084): does the host have `mysqldump` available? If not, I will use the provider's backups and a manual export, and note that the nightly job is off.
5. **Weekly off-site copy** (NFR-070): who does it, and where should it go (a drive, a cloud folder)?
6. **Go-live steps**: when you want staging, and when production; and who on your side signs the UAT checklist.
7. Earlier questions still open: company profile and transparent logo, opening balances, expense approval limit, negative balances, period lock policy, manager write-offs, Bangla receipts and reports, VAT paid.

## 9. Ready for launch?

**Not yet.** Local gates pass: Lighthouse POS (90 and 100 against 85 and 95), the authorization matrix (122 tests), a fresh install from the release zip, and a backup restore rehearsal. Three gate items cannot be closed without your hosting and sign-off: the fresh deployment on cPanel from `DEPLOY_CPANEL.md`, the restore on the host, and UAT. Production deployment has not been started and waits for your go-ahead.

Please send the hosting check answers, and answer questions 2 to 6 above. Then I will build 0.7.1, deploy to staging (when the account exists), and run the UAT checklist with you.
