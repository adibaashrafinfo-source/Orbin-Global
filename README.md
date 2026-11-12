# Orbin POS — Car Mirror Edition

Premium POS, inventory and ledger system for **Orbin Global** (Bangladesh). Runs entirely on the owner's cPanel hosting: Laravel 11 API + MySQL/MariaDB created in cPanel + a React SPA served from `backend/public`.

Read order: `CLAUDE.md` → `PRD.md` → `orbin-pos-spec.json` → `DEPLOY_CPANEL.md` → `FABLE_KICKOFF.md`.

## Layout

```
backend/    Laravel 11 (PHP 8.2+) — API under /api/v1, scheduler, PDF, storage
frontend/   React 18 + TypeScript + Vite + Tailwind — built into backend/public
deploy/     .htaccess templates, cron example, release packaging script
docs/       decisions, phase summaries, runbooks, screenshots
tests/e2e   Playwright end-to-end tests
```

## Local development

Requirements: PHP 8.2+, Composer, Node 20+, a MySQL 8 / MariaDB 10.5+ server.

```bash
# backend
cd backend
cp .env.example .env            # fill DB_* values
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve                # http://127.0.0.1:8000

# frontend (second terminal)
cd frontend
npm install
npm run dev                      # http://127.0.0.1:5173 (proxies /api and /sanctum to :8000)
```

Seed logins (local and staging only; change on production): see `docs/phase-0-summary.md`.

## Quality gates

```bash
cd backend  && vendor/bin/pint --test && php artisan test
cd frontend && npm run lint && npm run typecheck && npm test && npm run build
```

## Release

```bash
bash deploy/build-release.sh 0.1.0   # produces release/orbin-pos-release-0.1.0.zip
```

Deployment steps are in `DEPLOY_CPANEL.md`.
