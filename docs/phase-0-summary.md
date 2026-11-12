# PHASE P0 REPORT — Foundation

## 1. What was built

- **Monorepo.** `backend/` (Laravel 11, PHP 8.2+), `frontend/` (React 18, TypeScript strict, Vite, Tailwind), `tests/e2e` (Playwright), `deploy/`, `docs/`, plus CI that runs every check and builds the release zip.
- **Database.** 14 reversible migrations for the system tables exactly as in the spec (native enums, `DATETIME(3)`, utf8mb4, soft delete, `VARCHAR(191)` on indexed text) and idempotent seeders: branch, showroom and godown, settings defaults, the full 5-role permission matrix, 16 number sequences, 9 system lookup types.
- **Auth.** Sanctum cookie login with CSRF, logout, password reset by email, idle-lock PIN with 5-attempt lockout, per-account and per-IP login throttling, forced password change, self profile.
- **Authorization.** One gate per permission key backed by `role_permissions`, route middleware, policies, and a deactivated-user guard. `super_admin` is the only wildcard.
- **Services.** `AuditService` (append-only, with model observers and redaction of hashes), `NumberService` (gap-free, row-locked), `SettingsService`, `PermissionService`, `HealthService`, `UploadService` (MIME sniffing, byte-for-byte brand files).
- **Screens.** Login, dashboard landing, company profile with live invoice preview and logo and signature upload, branches, warehouses and storage locations, users, roles grid, numbering, POS rules, audit log with CSV export, profile, hosting health, component gallery.
- **Design system.** Token file generated from the spec, Tailwind preset, motion presets with reduced-motion fallback, 20 base components, app shell with animated sidebar, English and Bangla strings with key parity enforced.
- **Deployment kit.** `.htaccess` templates, cron example, release script, restore runbook draft and a staging checklist.

## 2. Traceability

| ID | Evidence | Status |
|---|---|---|
| FR-101 login, reset, timeout, idle lock | `AuthTest`, E2E login for all 5 roles, `IdleLock.tsx` | Done. Reset email delivery needs real SMTP on staging. Session lifetime comes from `.env` (D-020) |
| FR-102 role grid | `RolePermissionsTest`, E2E permission edit takes effect | Done |
| FR-103 users | `UsersTest`, E2E create and deactivate | Done. One default branch per user in the UI (D-020) |
| FR-104 company profile | `CompanyProfileTest`, E2E save, unlock, logo upload | Done |
| FR-105 branches, warehouses, locations | `BranchesTest` | Done |
| FR-106 numbering | `NumberingTest` incl. a 6-process concurrency proof, `NumberFormatTest` | Done |
| FR-107 app settings | `AppSettingsTest` (defaults equal the spec JSON) | Partial: date format and notification channel not yet configurable (D-020) |
| FR-108 audit log | `AuditLogTest` (append-only, filters, export), E2E | Done |
| FR-109 notifications | `NotificationsTest`, bell polls every 45 s | Partial: bell and read state done, generators come with modules |
| FR-112 health page | `HealthTest`, E2E, rehearsal on the release zip | Done locally. Real cPanel values still to verify |
| FR-110 backups, FR-111 installer, FR-113 integrity | not in P0 scope or not needed yet | Not done: P6, only if no SSH, P2 |
| BR-070 gap-free numbers | `NumberingTest` concurrency | Done |
| BR-001 money helper | `MoneyTest` (PHP), `money.test.ts` (client) | Done |
| NFR-010 security | authorization matrix (34 endpoints x 5 roles + guest), throttles, upload sniffing, security headers | Done in code. Apache rules unverified (D-013) |
| NFR-011 audit | observers on every system model, hashes never logged | Done |
| NFR-060 i18n | key parity script, language toggle E2E | Done |
| NFR-090 code quality | ESLint incl. a11y and no-raw-color rule, strict TS | Done |
| Gate: login per role | E2E x5 | Pass |
| Gate: routes and endpoints gated | `AuthorizationMatrixTest`, E2E 403 and route guards | Pass |
| Gate: no raw hex outside tokens | ESLint rule, scan clean | Pass |
| Gate: gallery shows every state | E2E gallery test, screenshots | Pass |
| Gate: company profile persists, logo uploads | E2E | Pass |
| Gate: staging on owner's cPanel, cron green | needs your hosting | **Not done** (see section 4) |

## 3. Test results

| Suite | Command | Result |
|---|---|---|
| Backend feature and unit | `php artisan test` (MariaDB 10.11) | 87 passed, 673 assertions, 0 skipped |
| Code style | `vendor/bin/pint --test` | passed |
| Frontend lint | `npm run lint` | clean, 0 warnings |
| Frontend types | `npm run typecheck` | clean |
| Frontend unit | `npm test` | 5 passed |
| Browser E2E | `npm --prefix tests/e2e run test` | 22 passed, 2 skipped (optional screenshot capture) |
| Release build | `bash deploy/build-release.sh 0.1.0` | 18 MB zip |
| Fresh install from the zip | new database, `migrate --seed`, `orbin:make-super-admin`, cookie login | works, production mode, `APP_DEBUG=false` |

Bugs the tests found and I fixed: the tailwind merge helper dropped text colors, a focus-opened tooltip swallowed Escape on dialogs, guests got an HTML 500 instead of JSON 401, the release zip carried 77 `.git` folders (284 MB), and the health page was unreachable in maintenance mode.

Not tested: Apache behavior, real SMTP, Safari and Firefox, a screen reader, a real phone width, or anything on your hosting.

## 4. Staging deployment

**Not done.** It needs your cPanel, which this environment cannot reach. Follow `docs/staging-deploy-checklist.md`. Everything it needs is in the release zip built by CI or `deploy/build-release.sh`.

## 5. Decisions and deviations

See `docs/decisions.md` D-001 to D-020. The ones that matter most: the logo you sent is a JPEG, not a PNG (D-003); hosting answers are still assumed (D-002); library versions are pinned to the majors the spec names (D-004).

## 6. Demo script

Run locally: `cd backend && php artisan migrate:fresh --seed && php artisan serve`, then open http://127.0.0.1:8000.
All demo logins use the password `Orbin@2026` and exist only in local, test and staging.

| Role | Email | What to try |
|---|---|---|
| Super admin | admin@orbin.local | Settings, Roles grid, Users, Health, Gallery |
| Manager | manager@orbin.local | Audit log only; Settings pages are blocked |
| Cashier | cashier@orbin.local | Paste `/settings/users` in the address bar: permission screen; no Settings menu |
| Storekeeper | store@orbin.local | No POS entry |
| Accountant | accounts@orbin.local | No POS entry |

Click path: sign in as admin, Settings, Company profile, type an address and phone, Save. The banner flips to "enabled" and the preview updates live. Then Roles, tick one box for Storekeeper, Save, and open the audit log to see the change recorded.

## 7. Known gaps and risks

- Staging is unverified, so the biggest risk is host differences: PHP version, missing extensions, no SSH, document root.
- The Apache `.htaccess` rules were written to the spec but could not be run here (D-013).
- The logo is a JPEG with a white background. It sits correctly on a white plate everywhere, but a transparent PNG or SVG would look better on the invoice.
- Company details are empty on purpose, so invoices stay blocked until you add them.
- Demo accounts must never reach production: `SEED_DEMO_USERS` is ignored when `APP_ENV=production`.

## 8. Questions for the owner

1. Hosting check answers (`DEPLOY_CPANEL.md` section 1), especially PHP version, SSH yes or no, and subdomain document root.
2. Company profile details for the invoice, and the signature image.
3. Do you have the logo as PNG or SVG with a transparent background?
4. How would you like staging deployments to work: you upload each zip, or you give SSH or FTP access?

## 9. Ready for P1?

**No, not yet.** Everything that can be proven here passes. The one open gate item is staging on your cPanel with a green cron heartbeat. Please run the checklist, send me the Health page result, and I will fix anything the host reveals before starting the Catalog phase.
