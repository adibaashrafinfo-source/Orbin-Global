# FABLE_KICKOFF.md — Instructions for Claude Fable 5.2 (Claude Code) · cPanel / MySQL edition

Use this as the **first message** (or project instruction) when you start the build with Claude Fable 5.2 in Claude Code. Keep these files in the project root: `CLAUDE.md`, `PRD.md`, `orbin-pos-spec.json`, `DEPLOY_CPANEL.md`, `FABLE_KICKOFF.md`.

**Hosting rule:** the app runs on the owner's own cPanel hosting, with the MySQL/MariaDB database created in that cPanel. **No Supabase or any third-party database/service.**

---

## 0. Before you start (owner checklist)

- [ ] Project folder created, `git init`, GitHub repo connected (private).
- [ ] The five files above are in the root.
- [ ] **Hosting check done** (`DEPLOY_CPANEL.md §1`): PHP version, extensions, memory limit, MySQL/MariaDB version, SSH yes/no, Composer yes/no, cron, subdomain document root, SSL. Write the answers down; the agent will ask for them.
- [ ] Staging subdomain created (e.g., `pos-staging.yourdomain.com`) with its own empty database and user.
- [ ] Production subdomain and database planned (do not deploy production before P6 approval).
- [ ] Logo saved as `orbin-logo.png` (original, untouched) in the project root; the agent places it in the right folders.
- [ ] On your computer (for the agent to build and test): PHP 8.2+, Composer, Node 20+, pnpm or npm, Docker (for a local MySQL test database), Playwright browsers.
- [ ] Company profile data ready: legal name, address, phones, email, website, BIN/TIN, trade license, bank details, signature image.
- [ ] Decide the assumptions in PRD §1.5 (especially: "laser" means ledger).

---

## 1. Master prompt (paste this)

```
You are the lead engineer building "Orbin POS — Car Mirror Edition" for Orbin Global (Bangladesh).

Read these five files completely before doing anything: CLAUDE.md, PRD.md, orbin-pos-spec.json, DEPLOY_CPANEL.md, FABLE_KICKOFF.md.
They are the full specification. CLAUDE.md = process rules. PRD.md = behavior and UX. The JSON = data model, enums, tokens, roles, services, API, hosting, phases, acceptance tests. DEPLOY_CPANEL.md = hosting and deployment.
Conflict rule: data model/enums/tokens/hosting constraints -> JSON wins; behavior/UX -> PRD wins; deployment steps -> DEPLOY_CPANEL.md wins; otherwise ask me.

Hard constraint: the system runs on my own cPanel hosting with the MySQL/MariaDB database created in cPanel. Use Laravel (PHP) for the backend and React for the frontend as specified. Do NOT use Supabase, Firebase or any other third-party database or backend service. No stored procedures, triggers or events (shared hosting). No long-running processes.

Your operating mode:
1. Work phase by phase (P0 to P6). Start with P0 only.
2. For each phase run the loop in CLAUDE.md section 3: plan -> migrations/seeders/tests -> backend services and API -> frontend -> tests -> self-review -> deploy to my cPanel staging -> phase summary.
3. Work autonomously inside a phase. Do not ask for approval on every step. Use the defaults in PRD section 13.3 and log every assumption in docs/decisions.md. Ask me only for: the hosting-check answers, company profile data, a true spec conflict, or a decision that cannot be undone.
4. Stop at the end of each phase. Deliver docs/phase-N-summary.md and wait for my approval before the next phase.
5. Build only what the spec describes. No extra features, libraries, screens, or dead code.
6. Verify your own work with real commands (Pint, ESLint, typecheck, unit/feature tests, Playwright). Never claim something works unless you ran it and saw it pass. Report failures honestly, including anything you could not test (for example things that need my real hosting, a real printer, or a real barcode scanner).

Begin now: (a) summarize your understanding in at most 15 lines, (b) ask for the hosting-check answers and any blocking questions, (c) post the P0 plan, then (d) execute P0.
```

---

## 2. Rules for the model

### 2.1 Quality bar
- Treat every **`FR-`, `BR-`, `NFR-`** ID and every **`AT-`** acceptance test as a checklist item. At the end of each phase give a **traceability table**: ID → file/test → status (done / partial / not done + why).
- Migrations must match the JSON exactly (names, types per `meta.typeMapping`, constraints, indexes, computed key columns). Deviations go to `docs/decisions.md` and to me.
- The transactional services in `transactionalServices` are the heart of the product. Implement each step by step in the listed order, with feature tests for every error code **before** building UI on top.
- Money is computed on the server with a decimal-safe helper; the browser only previews.

### 2.2 Verification loop (mandatory per task)
1. Write or update the test first for money, stock, ledger, permissions.
2. Implement.
3. Run Pint, ESLint, typecheck, unit tests, feature tests, and the relevant Playwright test.
4. Open the screen in a real browser (screenshots at 1366×768 and 1920×1080); check keyboard flow and `prefers-reduced-motion`.
5. Deploy to my staging subdomain and check the Health page.
6. Only then mark the task done.

### 2.3 Hard-to-get-right items
| Item | What to do |
|---|---|
| `SaleService::complete` | One DB transaction, `FOR UPDATE` locks in id order, deadlock retry, idempotency key, server recomputes totals and rejects mismatch. Write the last-unit concurrency test first. |
| Shared-hosting DB limits | No triggers/procedures/events. Use the computed key columns (`signature`, `trx_key`, `open_key`, `walkin_key`) for conditional uniqueness and prove them with concurrency tests. |
| Weighted-average + landed cost | Per BR-032 and FR-503; hand-calculated fixtures. |
| Invoice PDF (mPDF) | Original logo, snapshot data only, server-side generation, auto-download with fallback button, parse the PDF in Playwright. Run the Bangla shaping test and a memory/time test **on my real staging hosting**; record the decision. |
| Authorization | Test matrix role × endpoint; cost/margin hidden without `products.view_cost`. Do not rely on UI hiding. |
| Payment references | Enforce BR-060/061 on the server and with `trx_key`, not only in forms. |
| Gap-free numbering | Only through `NumberService`; test under concurrent requests. |
| Void/return reversal | Reverse stock, account transactions, ledger exactly; invariants still hold. |
| Imports/exports/reports | Chunk and stream; must finish within shared-hosting memory/time limits. |
| Design tokens | No raw hex outside the token file; lint rule early. White text never on orange. |
| i18n | Every string via keys (`en`, `bn`) from the first component. |
| Deployment | Release zip with `vendor/`, `.htaccess`, health page, cron heartbeat; web installer only if no SSH. Test a fresh install from the zip. |

### 2.4 Parallel work
If subagents are available, parallelize **independent** work (UI components, seeders, tests). One agent owns all money/stock service logic. Merge only when tests pass.

### 2.5 Things you must not do
- Do not use Supabase, Firebase, or any third-party database/backend service.
- Do not invent business rules, fields, or screens.
- Do not integrate bKash/Nagad/Rocket APIs, SMS, WhatsApp, or couriers (Phase 2).
- Do not put secrets in the repo; never commit `.env`; never print real passwords in logs or reports.
- Do not recolor, redraw, crop, or distort the logo.
- Do not skip, disable, or weaken a failing test to make a phase pass.
- Do not deploy to production or start the next phase without my approval.

### 2.6 When stuck
Try twice with different approaches. Then stop and report: what you tried, exact error output, best hypothesis, and 2 options with trade-offs.

---

## 3. Report format at the end of each phase

```
PHASE Pn REPORT
1. What was built (5-10 bullets, plain language)
2. Traceability table (ID -> evidence -> status)
3. Test results (counts + commands run + failures or skipped items with reasons)
4. Staging deployment (URL, release version, Health page status, cron heartbeat)
5. Decisions and deviations (link docs/decisions.md entries)
6. Demo script (exact click path, with seed logins per role)
7. Known gaps and risks
8. Questions for the owner
9. Ready for Pn+1? (yes/no and why)
```

---

## 4. Per-phase prompts (paste after you approve the previous phase)

**P1 — Catalog**
```
P0 is approved. Execute Phase P1 (Catalog) per CLAUDE.md section 3 and buildPhases P1. Include lookup manager, custom field builder, vehicle makes/models, brands, categories, units, tax, product + variant editor with matrix generator, server-side image upload and resize, fitment editor, OEM cross-references, CSV/XLSX import with dry-run, barcode generation and label printing. Meet every gate. Deploy to staging. Stop with the phase report.
```

**P2 — Inventory and Purchasing**
```
P1 is approved. Execute Phase P2. Start with StockPostingService and the invariant tests (no triggers), then GoodsReceiptService with landed cost, adjustments with approval, breakage register, transfers, counts, alerts, reorder, valuation, stock card, and the integrity:check command and page. Prove invariants with automated tests. Deploy to staging. Stop with the phase report.
```

**P3 — POS and Sales**
```
P2 is approved. Execute Phase P3. Order: customers and vehicles -> VariantSearchService -> SaleService::complete with all feature tests including concurrency -> server-side invoice PDF with auto-download and private storage -> POS terminal UI (finder, search, size chips, cart, hold/resume, discount + manager PIN) -> payment drawer (Cash, bKash, Nagad, Rocket, Bank Transfer, split) -> success screen -> sales list/detail -> returns -> void -> quotations -> shifts and Z-report -> warranty -> 80 mm receipt. Run AT-001..AT-010, AT-012, AT-013, AT-016, AT-023 and the Bangla + memory PDF test on my staging hosting. Stop with the phase report.
```

**P4 — Ledger and Finance**
```
P3 is approved. Execute Phase P4: financial accounts, party ledgers, PartyPaymentService with allocation, expenses, fund transfers, day book, dues and aging, period lock, statements PDF. Prove balance invariants. Run AT-014, AT-018. Deploy to staging. Stop with the phase report.
```

**P5 — Reports and Dashboard**
```
P4 is approved. Execute Phase P5: every report in the JSON reports list with filters, streamed exports (CSV/XLSX/PDF), drill-down and saved presets, plus the role-aware dashboard. Keep queries fast on shared hosting (indexes, chunking). Prove dashboard = report = invoice totals (AT-017) and profit = sum(line price - line cost). Deploy to staging. Stop with the phase report.
```

**P6 — Hardening and Launch**
```
P5 is approved. Execute Phase P6: performance pass against NFR-001, full authorization matrix tests (AT-019), accessibility audit and reduced-motion check (AT-020), demo seed with removal command, user guides in English and Bangla, fresh-install test from the release zip (AT-021), nightly backup + restore test (AT-022), production deployment runbook, UAT checklist. Report Lighthouse numbers honestly. Prepare the production release but wait for my explicit go-ahead before deploying to production. Stop with the final report.
```

---

## 5. After each phase (owner review checklist)

- Open the staging site and follow the demo script; try to break it (wrong inputs, double clicks, slow network).
- Check one invoice PDF by eye: logo sharp, company and client details, totals, amount in words.
- Compare 3 random numbers (stock, due, profit) against a manual calculation.
- In cPanel: confirm `.env` is not reachable in the browser (`/.env` must be blocked), `APP_DEBUG=false`, and Admin → Health is all green.
- Confirm a backup file was created last night and downloads from Admin → Backups.
- Only then approve the next phase.
