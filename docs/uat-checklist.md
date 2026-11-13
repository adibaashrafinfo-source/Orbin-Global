# UAT checklist (P6)

For the owner (and the shop's cashier, manager and accountant) to sign before go-live. Each acceptance test from `orbin-pos-spec.json` is listed. The **Automated** column says what the test suite already proves on a local database; the **Owner check** column says what you should try on the real site. A test marked "not automated" has not been proven yet.

Sign-off: fill in the name and date at the bottom. Mark each row **Pass**, **Fail** (with a note) or **Skip** (with the reason).

| ID | What it checks | Automated (local) | Owner check on the real site |
|---|---|---|---|
| AT-001 | Vehicle finder (Toyota → Axio → 2015 → Right) shows only fitting variants, within 300 ms | POS search tests; search p95 41 ms on 10,000 variants (see `accessibility-performance.md`) | Pick a car in the POS and watch the list |
| AT-002 | Tapping two sizes adds two lines with correct prices; stock changes only after completion | POS phase 3 tests | Add 7x4 and 8x5 from one card |
| AT-003 | Split payment cash + bKash with sender and TrxID creates one invoice, two payments, and the PDF downloads | Phase 3 end-to-end flow | Pay 5,000 cash and 7,500 bKash on a 12,500 cart |
| AT-004 | Downloaded invoice shows logo, company, client, items, totals, amount in words; QR opens the verify page | Phase 3 PDF parse test | Open the PDF in a reader and scan the QR with a phone |
| AT-005 | bKash without TrxID is refused with a field error | Phase 3 tests | Try it |
| AT-006 | A TrxID used within 30 days is refused unless the manager enters a PIN; the override is audited | Phase 3 and 4 TrxID tests | Reuse a TrxID |
| AT-007 | Walk-in customer with part payment cannot complete the sale | Phase 3 tests | Try it |
| AT-008 | Discount above the limit asks for the manager PIN; a wrong PIN is refused | Phase 3 tests | Apply 12% with a 5% limit |
| AT-009 | Two cashiers, last unit: exactly one sale succeeds | Phase 3 concurrency tests | Ask a colleague to sell the same unit (optional) |
| AT-010 | A retry with the same key creates one invoice | Phase 3 idempotency tests | Not practical by hand; rely on the test |
| AT-011 | Goods receipt with freight and customs gives the hand-calculated landed cost | Phase 2 landed-cost tests | Post one receipt and compare with your own sum |
| AT-012 | Same-day void by manager reverses stock, payments, ledger; invoice shows VOID | Phase 3 tests | Void a test sale |
| AT-013 | Return with resellable and broken items; credit note PDF | Phase 3 return tests | Return one item from a sale |
| AT-014 | Oldest-first allocation across three invoices | Phase 4 `AllocatorTest`, end-to-end | Receive 2,500 against three invoices of 1,000 |
| AT-015 | New lookup, custom field and vehicle model usable without code changes | Phase 1 master-data tests | Add a vehicle model |
| AT-016 | Shift close: expected cash, variance and Z-report agree | Phase 3 shift tests | Close a shift with a counted amount |
| AT-017 | Dashboard, sales report and invoice list totals agree for one period | Phase 5 reconciliation and browser gate | Compare the three for today |
| AT-018 | A locked period refuses create, edit and void | Phase 4 tests (`PERIOD_LOCKED`) | Lock to yesterday and try a receipt dated yesterday |
| AT-019 | Each role sees only its screens; the API returns 403; cost hidden without permission | Authorization matrix: 122 tests across all modules, green | Sign in as each role and try the other roles' screens |
| AT-020 | Reduced-motion setting: no Mirror Shine, no transforms | **Not automated.** Reviewed in code only | Turn on "reduce motion" in the OS and browse the POS and dashboard |
| AT-021 | Fresh cPanel install: login, migrations, seed, health green, cron heartbeat within 2 minutes | **Partly.** Fresh install from the release zip tested locally (migrations, seed, owner login, health, backup, demo removal). **Not tested on cPanel** | Follow `DEPLOY_CPANEL.md` and `operations-runbook.md` on the staging subdomain |
| AT-022 | Backup restores into a scratch database; `integrity:check` passes; row counts match | Local rehearsal: `deploy/restore-rehearsal.sh` (8 of 8 tables, check = ok) | Restore last night's backup into a scratch database on cPanel |
| AT-023 | Duplicate variant, duplicate TrxID, second open shift: exactly one succeeds | Phase 3 and 4 concurrency tests | Not practical by hand; rely on the test |

## Other checks for you

- [ ] Admin → Health: every check green on the real site (the HTTPS check is only OK on HTTPS).
- [ ] Demo logins removed (`php artisan orbin:demo-remove`), and the owner's own login works.
- [ ] Company profile saved (name, address, phones); one invoice printed and read by eye.
- [ ] Opening balances of each account and each customer and supplier entered (see Phase 4 summary).
- [ ] One real sale, one real purchase receipt, one expense, one payment, one report exported to Excel.
- [ ] The Bangla screens read well for the staff (the reports and invoices stay English in this version).
- [ ] Phone and tablet: the POS works on the tablet you will use at the counter (not tested by us).

## Sign-off

| | |
|---|---|
| Owner name | |
| Date | |
| Outcome | Accepted / Accepted with the items below / Not accepted |
| Items to fix | |
