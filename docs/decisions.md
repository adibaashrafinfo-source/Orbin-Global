# Decisions and assumptions

Every assumption, deviation and choice between options lives here (CLAUDE.md §3). Newest first within each phase.

## P0

### D-001 Repository
Orbin POS lives in its own repository (`Orbin-Global`). The Lovable-synced UH Technologies repository was not touched.

### D-002 Hosting check not yet answered (owner action)
`DEPLOY_CPANEL.md §1` answers were not provided before P0. Defaults used until they arrive:
PHP 8.2 / Laravel 11, SSH assumed available (no web installer built), `vendor/` shipped in the release zip, MariaDB 10.11 locally,
one-minute cron, subdomain document root pointing at `orbin-pos/public`. **Impact:** if the host has no SSH, the FR-111 installer must be added before
the first staging deployment; if PHP maximum is 8.1, the backend must move to Laravel 10 (decision to record then).

### D-003 Logo file format
The supplied logo is a **JPEG (1536×1024)**, not the PNG named in the spec. It is used untouched at
`backend/public/assets/brand/orbin-logo.jpg` (and the same file in `frontend/public/assets/brand/`). The spec path
`orbin-logo.png` is therefore `orbin-logo.jpg` everywhere in code. The JPEG has a white background, so on navy surfaces it always sits on the
white rounded plate (CLAUDE.md §2.2). A transparent PNG/SVG original would look better on the invoice and sidebar; request it if available.

### D-004 Frontend routing and library versions
The spec lists React Router; **react-router-dom 6** is used. Versions are pinned to the latest releases of the majors the spec names:
React 18, Vite 5, Tailwind 3, TanStack Table 8, Zod 3, Recharts 2, TypeScript 5.9, ESLint 9. Newer majors (React 19, Vite 8, Tailwind 4, Table 9, TypeScript 7)
exist; they were not used because the spec and shadcn/Radix patterns target the pinned majors.

### D-005 shadcn/ui
The spec names shadcn/ui. The base components are written by hand on the same Radix primitives, class-variance-authority and tailwind-merge,
with design tokens from the spec, instead of running the shadcn generator. Reason: every component needs the spec's exact states (accent button navy text,
Mirror Shine, loading morph) and the generator output would be rewritten anyway.

### D-006 Table naming for password reset
Laravel's `password_reset_tokens` expects the `email` column; users authenticate with column `password_hash` (spec). `User::getAuthPasswordName()` maps this.
Sanctum personal access tokens are not used (SPA cookie auth only), so that table is not created.

### D-007 Framework tables
`sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` are created by migrations (spec `frameworkTables`).

### D-008 Settings validation lives in code
`app_settings` values are validated against `SettingsService::SCHEMA` (types, enum options, min/max), which also generates the settings screen.
A test asserts the defaults equal `appSettingsDefaults` in the JSON.

### D-009 Permissions mirror
`App\Support\Permissions` mirrors `roles.permissionKeys` and `roles.defaultMatrix`; a unit test compares it with `orbin-pos-spec.json` so the two cannot drift.

### D-010 Number sequences
The seeded rows are format templates (`period_key = 'all'`). Per-period counters (yearly/monthly reset) are created lazily as extra rows with the same key and `period_key = Y` or `Y-m`.
Editing a template updates the prefix, token, padding and policy of its period rows; counters never move backwards.

### D-011 Lint configuration
`react-hooks/incompatible-library` is off (react-hook-form `watch()` is used intentionally; the rule is a React Compiler advisory).
`react-refresh/only-export-components` is off for the design-system barrel and the route table. All other rules are errors.

### D-012 Demo logins
`SEED_DEMO_USERS=true` seeds one login per role (password `Orbin@2026`) in non-production environments only. Production creates its admin with
`php artisan orbin:make-super-admin`, which forces a password change at first login.

### D-013 Server `.htaccess` behaviour is not verifiable locally
The Apache rules in `deploy/htaccess/` (HTTPS redirect, deny dotfiles, no PHP in uploads, security headers, cache headers) cannot be exercised by PHP's built-in
development server. They must be checked on the real cPanel staging site (see the phase summary checklist).

### D-014 Company profile defaults
Phones and address start empty on purpose so invoice generation stays blocked until the owner supplies the real company data.

### D-015 Release packaging
`deploy/build-release.sh` strips `.git`, `tests`, `docs` and README files from `vendor/` and fails if any `.git`, `.env`, `tests`, `node_modules`, `.sql` or `.log`
remains. In the build sandbox the proxy blocks GitHub zip downloads, so Composer installs from source and leaves `.git` folders; without the prune step the zip was 284 MB, with it 18 MB.
Composer runs with `--no-scripts`, so Laravel generates its package manifest on first boot (needs `bootstrap/cache` writable, which the health page checks).

### D-016 Login throttle
Two layers: a per-account+IP lock after 5 failed attempts (NFR-010) and a per-IP route throttle that defaults to 10 requests per minute.
The route throttle is configurable with `ORBIN_LOGIN_RATE_LIMIT` (the local browser suite sets 1000; production keeps 10). A feature test proves both layers.

### D-017 Dialog close button has no tooltip
A focus-opened tooltip is a dismissable layer and swallowed the first Escape press on the second dialog opened in a session (found by the browser suite).
The dialog's own close "X" is a plain button with an accessible name. Toolbar icon buttons keep their tooltips.

### D-018 End-to-end tests are a separate package
`tests/e2e` has its own `package.json` and Playwright config so browser tooling never enters the frontend build. It runs against the app served by Laravel (same origin, cookie auth).
The two screenshot specs only run with `CAPTURE=1`.

### D-019 Color literals
The lint rule forbids hex and `rgb()/rgba()` literals everywhere except `src/design/tokens.generated.ts`. Focus glow and Mirror Shine use `theme(colors.*)` and `color-mix()` with tokens.

### D-020 Known gaps carried into later phases
- `app_settings.session_timeout_minutes` is stored and editable, but the live session lifetime still comes from `SESSION_LIFETIME` in `.env`; wiring it needs a runtime config override (planned P6).
- The user dialog assigns one default branch; multi-branch selection per user arrives with a second branch.
- Notification generators (low stock, approvals, dues, open shift) arrive with their modules; the bell, polling and read state are done.
- Date/time format and low-stock channel settings (FR-107) are not yet configurable; language is per user and the display timezone is fixed to Asia/Dhaka.

## P1

### D-021 Laravel 12 instead of 11
The spec names Laravel 11. Laravel 11 no longer receives security fixes and `composer audit` reported four open advisories against it (including a CRLF injection in the email validation rule that login uses);
the patched releases only exist in Laravel 12. The backend now runs Laravel 12.72, which still supports PHP 8.2, so the hosting requirement is unchanged. All 87 Phase 0 tests passed unchanged after the upgrade.

### D-022 No hosting yet
The owner has not bought hosting. Phase 1 is built and tested locally; the cPanel staging deployment and the hosting check stay open items for when the account exists.
The owner will supply a transparent logo later; the JPEG stays in use (see D-003).

### D-023 Images use PHP GD directly
The spec allows "PHP GD / Intervention Image". GD is used directly (resize, EXIF rotation, thumbnails) to avoid another dependency. Files are stored under `public/uploads/products/<yyyy>/<mm>` with random names.

### D-024 Duplicate guards are the database's unique keys
Variant identity (BR-092) is the `signature` column, SKU and barcode are unique columns, fitments use a `signature` column. The service pre-checks to give a friendly message and a pointer to the existing record,
but the unique key is the real guard: five racing processes create exactly one row (tests in `CatalogConcurrencyTest`, run 8 times). Duplicate-key errors are classified by the violated key name only.

### D-025 Price columns need `pricing.manage`
`products.manage` lets a role edit products and variants, but any price column (retail, wholesale, dealer, MRP, floor) can only be written with `pricing.manage`; an unchanged price in a payload is allowed. Cost (`last_cost`) is omitted from responses without `products.view_cost`.
Import of products needs both `products.import` and `pricing.manage`.

### D-026 Barcodes
Generated barcodes are store-internal EAN-13: `200` + 9-digit variant id + check digit, unique by construction. Manually typed barcodes (including supplier EAN-13) and up to 10 aliases are supported.

### D-027 Labels
Two templates: an A4 sheet of 27 labels (3 × 9, 70 × 33 mm, with a start position to reuse a partly used sheet) and a 50 × 25 mm roll (one label per page). The price is printed as "Tk 12,500.00" because the PDF font used for barcodes has no reliable taka glyph; the Bangla and taka glyph test is part of the invoice work in Phase 3.

### D-028 Imports
Four importable kinds in Phase 1: vehicle makes, vehicle models, products with variants (one row per variant, product columns on the first row), and fitments. Customers, suppliers, opening stock and opening balances arrive with their modules.
A dry run validates every row and returns row-level errors without writing; the commit re-reads the same file and applies it in one transaction, so an import is all or nothing. Imported products go live immediately.

### D-029 Not in Phase 1
- FR-308 (warning when deactivating a product that has stock) needs stock levels, which arrive in Phase 2.
- Product "total stock" and stock-status filters show nothing until Phase 2.
- Reordering uses up/down controls instead of drag and drop, which also works from the keyboard.
