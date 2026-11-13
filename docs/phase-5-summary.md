# PHASE P5 REPORT — Reports and Dashboard

## 1. What was built

- **Dashboard** (the home page). A period control (Today, 7 days, 30 days, This month, Custom) and, below it, the tiles and charts your role may see: Sales, Orders (with average ticket), Gross profit (with margin), Collections, Customer dues (with what we owe suppliers); a sales trend with today as the orange point; collections by payment method; top products and top vehicle models; low and out-of-stock items with a "create purchase order" link; the balance of each money account; pending approvals; recent sales. Each tile shows the change against the period before. It refreshes every 60 seconds.
- **Reports hub** (Reports). All **41 reports** in the spec, grouped Sales, Profit, Inventory, Purchases, Finance, Tax and Audit, with a search box. You see only the reports your role may open.
- **Report page.** Date range or as-of date, branch, warehouse and grouping where the report supports them. Summary tiles for the totals. A chart where it helps (brand colours, navy series). A sortable table with totals, and a **compare to previous period** switch. Every invoice, GRN, payment or other document number is a link to that document. **Saved views**: save the current filters under a name and reopen them later (per user).
- **Export.** CSV, Excel and PDF for every report, plus a print view that opens the PDF. The file has the same rows and totals as the screen.
- **Who sees what.** Cost, profit and margin columns are removed on the server for anyone without cost permission. A person with only "view own" sees their own sales. Exporting has its own permission.
- **Reconciliation.** The dashboard, the sales report, the profit reports, the profit and loss report and the invoice list all work from the same two expressions for revenue and cost (D-074), so they agree.
- English and Bangla for every new string (a test keeps the two files identical).

## 2. Traceability

| ID | Evidence | Status |
|---|---|---|
| FR-1100 dashboard, widgets by role | `DashboardTest`, E2E "dashboard shows only what each role may see" | Done |
| FR-1101 … FR-1104 reports, filters, drill-down, presets, exports | `ReportReconciliationTest`, `ReportExportTest`, `ReportAccessTest`, E2E | Done. 41 of 41 spec reports |
| Report measures in the spec's list | each report definition | **Partly.** VAT paid is not shown, since purchases carry no VAT in the data model (D-078) |
| AT-017 dashboard = report = invoices | `ReportReconciliationTest`, E2E gate tests 1 and 2 | Pass |
| Gate: profit = Σ(price − cost) | `ReportReconciliationTest`: works the figure out from the invoice lines and requires the dashboard, profit by invoice, by product, sales by product and brand, and profit and loss to equal it. E2E repeats it in the browser | Pass |
| Gate: every report exports | `ReportExportTest`: every report as CSV, Excel and PDF, row counts match the screen | Pass |
| AT-019 roles | `ReportAccessTest`: every route × role, and cost hiding | Pass |
| Gate: staging on your cPanel | needs your hosting | **Not done** (section 4) |

## 3. Test results

| Suite | Command | Result |
|---|---|---|
| Backend | `php artisan test` (MariaDB 10.11) | 534 passed, 4,822 assertions, 0 skipped (31 more than Phase 4) |
| Code style | `vendor/bin/pint --test` | passed |
| Frontend lint | `npm run lint` | clean, no warnings |
| Frontend types | `npm run typecheck` | clean |
| Frontend unit | `npm test` | 29 passed (5 new: date ranges with the Saturday week start and last month across a year end, label keys, document links) |
| Browser E2E | all specs on a freshly seeded database | 67 passed, 17 skipped (screenshot capture only); 7 are new Phase 5 scenarios |
| Screenshots | 1366 × 768 and 1920 × 1080, plus Bangla | `docs/screenshots/p5/` (23 images) |
| Release | `bash deploy/build-release.sh 0.6.0` | `orbin-pos-release-0.6.0.zip`, 66 MB |

Problems found while building and fixed:

- A comparison with a previous period of zero hid the comparison altogether. It now says "From zero vs <dates>" (D-082).
- The totals tiles on the sales summary skipped the Total, because only the first six totals were shown and some were zero. Tiles now prefer totals that are not zero.
- The vehicle report grouped on a JSON expression, which MariaDB refuses in strict mode; it now groups on the selected parts.
- Cost columns printed in the PDF threw an error on four-decimal costs; they print as stored.
- An Excel export used a font call that does not exist in the library version used; fixed and covered by the export test.

Not tested: any real host, Firefox, Edge or Safari, phone or tablet widths, reduced motion on the new screens, report speed on large data (the tests and screenshots use a few dozen invoices; screen reports are held in memory, D-081), Bangla in exported files (English only, D-079), and the PDF layout of the widest reports (the page shape and column widths were not checked for every one of the 41).

## 4. Staging deployment

**Not done.** You have not bought hosting yet, and the rules for this project do not allow another host, so there is nowhere to deploy. `orbin-pos-release-0.6.0.zip` is ready. It adds one migration (`2026_06_01_000010_create_report_presets_table`, reversible). Phase 5 was built and checked on a local MariaDB.

## 5. Decisions and deviations

See `docs/decisions.md` D-073 to D-083. The ones that matter most:

- **D-074** how revenue, cost and profit are defined, once, for everything.
- **D-076** who can open which report, and how cost is hidden.
- **D-078** VAT paid is not in the report because the data model has nowhere to store it.
- **D-079** files are in English even when the screen is in Bangla.
- **D-081** the screen loads a report fully before paging it; exports stream.

## 6. Demo script

Run locally: `cd backend && php artisan migrate:fresh --seed && php artisan serve`, then `npm run build` in `frontend` and `bash deploy/sync-frontend.sh`, and open http://127.0.0.1:8000. Logins as before, password `Orbin@2026`. Fill Settings → Company profile first (an invoice is refused until the name, address and a phone are saved).

1. Sign in as **cashier@orbin.local**, open the POS and make two or three sales (one with a discount, which needs a manager PIN, 2345).
2. Sign in as **manager@orbin.local**. The **Dashboard** shows today's sales, orders, profit, collections and dues. Change the period to 7 days and Custom.
3. Open **Reports**. Search "valuation". Open **Sales summary**, pick This month, switch on **Compare to previous period**. Group by day, then by month.
4. Open **Gross profit by invoice**. Click an invoice number: the invoice opens. The profit total equals the profit tile on the dashboard.
5. Press **Export** → Excel, then CSV, then PDF, then **Print view**. Open the Excel file: the totals are numbers.
6. Press **Save this view**, name it, reload the page, and pick it from Saved views.
7. Open **Profit and loss** for the month: gross profit equals the dashboard's profit for the same range; expenses and breakage come off to give net profit.
8. Sign in as **store@orbin.local**: the hub lists only stock reports, the dashboard shows only low stock, and typing `/reports/profit-loss` shows "no permission". Sign in as **cashier@orbin.local**: only your own sales.

## 7. Known gaps and risks

- Staging, and every measurement that depends on real hosting (memory and time of the PDF and Excel exports, report speed), are still open.
- Report files are English only.
- VAT paid is not reported (D-078).
- Screen reports are loaded in full; a very large range on a small host could be slow. Not measured beyond a few dozen invoices.
- Charts are not part of the PDF export.
- Only Chromium was used for the browser tests.
- From earlier phases, still open: the Bangla invoice and conjunct decision on real hosting, the POS channel and salesperson pickers and note box, "keep change as advance", over-receipt rule, label stock choice, count-sheet printing, overstock alerts, no upload control for expense and payment attachments, rejecting a payment from verification, and the small leftover "due" on an invoice after a return (D-061).

## 8. Questions for the owner

1. **Hosting.** The cPanel account and the hosting check answers (PHP version and extensions, MySQL or MariaDB version, SSH/Terminal, Composer, cron, memory limit, a subdomain with its own document root, SSL). Without them nothing can be deployed or measured, and this has been open since Phase 0.
2. Company profile data (legal name, address, phones, email, BIN/TIN, trade licence, bank details, signature) and your transparent logo. Invoices stay blocked until the name, address and a phone exist.
3. Go-live numbers: what each money account holds on the day you start, and what customers owe you and you owe suppliers.
4. Should an expense above some amount need approval, and from what amount (now off)? Should accounts be allowed to go below zero (now not)? When do you want to lock a period?
5. Should the manager be allowed to write off or discount a due (now the accountant and you only)?
6. Do you want VAT paid on purchases tracked? It means storing VAT on purchase lines, a change to the data model, so I will not add it without your approval.
7. Should receipts, statements and report files also come in Bangla? This depends on the Bangla check on your real hosting.
8. Which reports will you use every day? I can put those first on the hub, or list "favourites", if you want it. Nothing more is added without your say.
9. Should I start Phase 6 (hardening and launch: performance targets, full authorization check, a fresh deploy and a restore test, your sign-off)? Most of Phase 6 needs the hosting account.

## 9. Ready for P6?

**Yes for the code, with items that need you.** Every Phase 5 gate passes locally except the one that needs your hosting (staging). Phase 6 is mostly about proving the system on the real server: speed, a clean install from the zip, a database restore, and your acceptance. Please send the hosting details, or tell me to continue with what can be done locally first.
