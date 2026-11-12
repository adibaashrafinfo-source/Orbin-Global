# CLAUDE.md — Orbin POS (Car Mirror Edition) · cPanel / MySQL edition

You are building **Orbin POS**, a premium POS + inventory + ledger system for **Orbin Global** (Bangladesh) that sells **car mirrors only**: side mirrors, interior mirrors, mirror glass and mirror parts, in many sizes and fitments.

Brand: **Navy `#002756`**, **Orange `#FF7900`**, tagline *Trust. Growth. Excellence.* The original logo file is `backend/public/assets/brand/orbin-logo.png` (also copy to `frontend/public/assets/brand/`).

**Hosting rule:** the system runs on the owner's own **cPanel hosting** with the **MySQL/MariaDB database created in that cPanel**. **No third-party database or backend service of any kind** (no Supabase, Firebase, PlanetScale, Neon, Appwrite, etc.).

---

## 1. Read order and source of truth

1. `CLAUDE.md` (this file) — how you must work.
2. `PRD.md` — what to build, behavior, UX, business rules (`FR-`, `BR-`, `NFR-` IDs).
3. `orbin-pos-spec.json` — data model (61 tables), enums, roles/permissions, transactional services, API conventions, hosting + deployment spec, design tokens, invoice spec, reports, seeds, phases, acceptance tests.
4. `DEPLOY_CPANEL.md` — hosting check, release packaging, deployment, cron, backups, update/rollback.
5. `FABLE_KICKOFF.md` — prompts and report format.

Conflicts: **data model / enums / tokens / hosting constraints → JSON wins. Behavior / UX → PRD wins. Deployment steps → DEPLOY_CPANEL.md wins.** If still unclear, **stop and ask the owner**. Never guess business rules.

Read all files fully before writing code. Re-read the relevant section before starting each phase.

---

## 2. Non-negotiable rules

1. **Scope:** build only what the PRD and JSON describe. **No extra features, screens, libraries, or speculative code.** Anything not listed (PRD §13.1 Phase 2 backlog) needs the owner's approval. No dead code, unused components, or placeholder pages.
2. **Logo:** original file only. Never redraw, recolor, crop, stretch, vectorize, or re-compress. On navy surfaces use a white rounded plate. The invoice PDF embeds the original at full resolution.
3. **Colors:** all colors come from the token file generated from `designSystem.colorTokens`. **No raw hex outside that file** (ESLint rule). Never white text on orange; accent buttons use navy text. Never brand orange as text on white (use `orange-700`).
4. **Money and stock finalization only in backend service classes** (`SaleService::complete`, `void`, `SaleReturnService`, `GoodsReceiptService::post`, …) inside **one DB transaction** with `SELECT … FOR UPDATE` in deterministic id order and deadlock retry. The browser may *preview* totals but never decides. The server recomputes and rejects mismatches.
5. **Database limits of shared hosting:** use **InnoDB, utf8mb4**. **No stored procedures, triggers, events, or anything needing SUPER/root.** MySQL 8.0+/MariaDB 10.5+ features only. Uniqueness-with-NULL cases use the computed key columns defined in the JSON (`signature`, `trx_key`, `open_key`, `walkin_key`).
6. **Stock truth = `stock_movements`** (append-only). `stock_levels` is changed only by `StockPostingService`. Negative stock is blocked unless the setting allows it. `integrity:check` verifies invariants nightly.
7. **Invoices are immutable** (BR-050). Store company, customer, vehicle and line-spec **snapshots** on the sale. Corrections = void or return.
8. **Security:** every endpoint is authorized by Policy + permission middleware reading `role_permissions`; cost/margin omitted by API Resources without `products.view_cost`; prepared statements only; Sanctum cookie auth + CSRF; `APP_DEBUG=false` in production; `.env`, `vendor`, `storage` never web-reachable; uploads validated, no PHP execution in upload folders; no secrets in the repo. UI hiding is cosmetic only.
9. **Everything admin-editable stays admin-editable:** lookups, custom fields, vehicle makes/models, brands, categories, units, taxes, payment accounts, company profile, numbering, roles. Seed values; never hard-code dropdowns.
10. **Payment methods are exactly:** Cash, bKash, Nagad, Rocket, Bank Transfer. Recorded manually with required references (BR-060). No gateway integration.
11. **No hard-coded UI strings.** All text via i18n keys in `en` and `bn`.
12. **Accessibility and motion:** WCAG AA, keyboard-complete POS, `prefers-reduced-motion` respected, animate only `transform` and `opacity`.
13. **Do not copy third-party brand logos** (bKash, Nagad, Rocket, banks). Text labels and neutral icons only.
14. **No long-running processes.** Background work = Laravel scheduler driven by one cPanel cron entry; queue driver `database` drained by cron.

---

## 3. How to work (loop for every phase)

1. **Plan:** read the phase in `buildPhases`; list migrations, services, endpoints, screens, components, tests. Post the plan in 10–20 lines. Ask now if anything is ambiguous.
2. **Database first:** write reversible Laravel migrations exactly as in the JSON (types per `meta.typeMapping`, constraints, indexes, computed key columns). Then seeders. Then feature tests that fail for the right reasons.
3. **Backend:** models, Form Requests, Policies, Services, API Resources, controllers under `/api/v1` per `api`. Map service error codes to i18n messages.
4. **Frontend:** build with the design system and PRD §8 screen specs. Skeleton, empty and error states on every list/form. Types generated or hand-written from the API contract; Zod schemas aligned with Form Requests.
5. **Tests:** unit + feature + Playwright for the phase's acceptance tests. All pass; none skipped.
6. **Self-review:** run Pint, ESLint, typecheck, tests, a11y lint; open each new screen at 1366×768 and 1920×1080; check keyboard flow and reduced-motion; check the authorization matrix for every new endpoint.
7. **Deploy to staging** (from P0 onward, each phase): build the release zip and deploy to the cPanel staging subdomain per `DEPLOY_CPANEL.md`; verify the health page and the phase demo there.
8. **Summary:** write `docs/phase-N-summary.md`. Then **stop and report**; do not start the next phase until the owner confirms the gate.

Git: small commits, Conventional Commits, one branch per phase, PR with screenshots; CI green to merge. Decisions that deviate or choose between options go into `docs/decisions.md`.

---

## 4. Technical conventions

- **Backend:** PHP 8.2+, Laravel 11 (Laravel 10 only if the host maximum is PHP 8.1, recorded in decisions), Sanctum SPA cookie auth, Eloquent, migrations/seeders, Form Requests, Policies, API Resources, service classes, Laravel scheduler, mPDF 8, openspout (CSV/XLSX), PHP GD / Intervention Image, Pest or PHPUnit, Laravel Pint.
- **Frontend:** React 18, TypeScript strict, Vite, React Router, Tailwind, shadcn/ui, Framer Motion, TanStack Query/Table, Zustand (cart/POS UI only), React Hook Form + Zod, i18next, Recharts. Built to static files and copied into `backend/public` by the release step.
- Structure per `projectStructure`. Feature folders; components ≤ 300 lines; no `any` without a comment; business logic in hooks/lib/services, not in components or controllers (controllers stay thin).
- Server state in TanStack Query; optimistic UI only for non-financial interactions. POS cart state in Zustand (persisted locally); held bills stored server-side.
- API: `/api/v1`, `Idempotency-Key` header on checkout/payments/returns/GRN posting, uniform error shape, money as decimal strings. Real-time = polling 30–60 s.
- Dates: store UTC, display `Asia/Dhaka`, week starts Saturday. Money: `DECIMAL(14,2)`; PHP uses a decimal-safe `Money` helper (integer paisa or `bcmath`) — never floats; client previews use a decimal-safe helper; format `৳ 1,25,000.00` through one helper on each side.
- Naming: tables/columns `snake_case`; services `PascalCaseService`; permission keys as in `roles.permissionKeys`.
- Every mutation of money, stock, price, permission, void, or override writes `audit_logs` via `AuditService`.
- Document numbers only from `NumberService` (gap-free).
- Imports/exports/reports process in chunks and stream output so they stay inside shared-hosting memory and time limits.

---

## 5. Design rules (enforce in review)

- Tokens from `designSystem`. Sidebar navy-900, background navy-50, cards white radius 12.
- **One `accent` (orange) button per view**, reserved for the hero action (Pay, Complete Sale). Primary = navy gradient with white text. Danger only behind a confirm dialog.
- **Mirror Shine** only on primary/accent buttons, product cards, KPI cards, success screen, login logo plate. Never on tables or forms.
- Required animations are listed in `designSystem.motion.required`. Page loads use skeletons, not spinners.
- POS terminal keeps 60 fps on a mid-range laptop; virtualize long lists; debounce search 150 ms.
- Tabular figures and right alignment for numbers.
- Product cards show size chips with live stock; out-of-stock chips visibly disabled.
- Charts use the brand palette (navy series, orange highlight).

---

## 6. Invoice rules

- After `SaleService::complete` succeeds, the **server generates the PDF (mPDF)** from the stored snapshot, saves it in the private invoices folder, sets `pdf_path`; the browser fetches it and **auto-downloads** `Invoice_<invoice_no>_<customer_slug>.pdf`. Show a fallback **Download** button on the success screen.
- Layout per `invoiceSpec`: original logo (48 mm), full company details, full client details, vehicle card, itemized lines with variant specs and warranty, totals, **amount in words (lakh/crore)**, payment breakdown with references, terms, signatures, tagline footer, QR to `/verify/{token}` (server-rendered page, no personal data).
- Block generation with a clear message if company name, address, or phone is missing.
- Run the Bangla conjunct test (NFR-020) and a memory/time test on the real cPanel staging account in Phase 3; record the decision. Default invoice language is English.
- Provide the variants in `invoiceSpec.variants` using shared header/footer templates.

---

## 7. Testing requirements

- **Unit:** money math, VAT inclusive/exclusive, discount allocation (BR-010), weighted average cost, landed-cost allocation (value and quantity), `BdtToWords`, formatting.
- **Feature (MySQL test DB):** every service happy path and each error code; **authorization matrix** role × endpoint; invariants — `stock_levels = Σ stock_movements`, `account balance = opening + Σ in − Σ out`, `sale grand_total = paid + due`, party balance = ledger sum; concurrency guards (last-unit sale, duplicate variant signature, duplicate TrxID, second open shift, gap-free numbering).
- **E2E (Playwright):** AT-001 … AT-023 where UI-testable, including sale → PDF download → parse (logo, totals, words) → return → void.
- Deterministic seeded test dataset. No flaky tests; fix or remove.

---

## 8. Definition of Done (every task)

Meets the FR/BR IDs · strict types · tests written and passing · Pint/ESLint/a11y clean · keyboard + labels · reduced-motion respected · i18n keys in `en` and `bn` · no raw hex · no console errors · reversible Laravel migration · authorization test for every new endpoint · audit rows for money/stock actions · works on the cPanel staging site · screenshot in the PR · documented in the phase summary.

---

## 9. Phases (see `buildPhases` in the JSON)

| Phase | Name | Gate summary |
|---|---|---|
| P0 | Foundation (monorepo, tokens, shell, auth, roles, company profile, health page, installer if needed, first cPanel deploy) | Login per role; endpoints gated; staging runs on owner cPanel; cron heartbeat green |
| P1 | Catalog | 6-variant mirror with images and fitment; import dry-run + commit |
| P2 | Inventory + Purchasing | Invariants hold; negative stock blocked; landed cost correct; `integrity:check` |
| P3 | POS + Sales | E2E passes; concurrency test passes; Bangla/memory decision recorded |
| P4 | Ledger + Finance | Balances reconcile; allocation oldest-first |
| P5 | Reports + Dashboard | Dashboard = report = invoices; profit = Σ(price − cost) |
| P6 | Hardening + Launch | Lighthouse targets; authorization matrix; fresh deploy works; restore tested; UAT sign-off |

Start with **P0**. Do not begin P1 until the owner approves P0.

---

## 10. First actions when you start

1. Read all files. Summarize your understanding in ≤ 15 lines and list **blocking questions**.
2. Ask the owner for the **hosting check answers** (`DEPLOY_CPANEL.md §1`): PHP version, extensions, MySQL/MariaDB version, SSH/Terminal yes/no, Composer on server yes/no, cron, memory_limit, subdomain with custom document root, SSL. Adapt using `hosting.fallbacks`; record in `docs/decisions.md`.
3. Ask for the **company profile data** (legal name, address, phones, email, website, BIN/TIN, trade license, bank details, signature image) and the **logo file** if absent. Until provided, use clearly marked placeholders and keep invoice generation blocked.
4. Confirm assumptions in PRD §1.5 (especially *"laser" = ledger*).
5. Post the P0 plan and proceed (or wait if the owner wants to review).

### Kickoff prompt the owner can paste

> Read `CLAUDE.md`, `PRD.md`, `orbin-pos-spec.json`, `DEPLOY_CPANEL.md`, and `FABLE_KICKOFF.md` completely. Summarize your understanding, list blocking questions (including the hosting check), then execute Phase P0 following CLAUDE.md §3. No third-party database or service. Do not add anything outside the spec. Stop after P0 with `docs/phase-0-summary.md` and wait for my approval.

---

## 11. When in doubt

Prefer the spec over your own judgment, prefer asking over guessing, and prefer deleting code over keeping code the spec does not need.
