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

### D-030 Product editor saves
A draft product autosaves 1.2 s after the last keystroke in step 1, because nothing live can change. A published product saves only when you press Save, so a half-typed edit never reaches the POS. Other steps save per action or per row.

### D-031 Drag and drop only for images
Images can be reordered by dragging, with left and right buttons for keyboard users. Master-data lists and custom fields use up and down buttons only (see D-029).

### D-032 Bulk price scope
A bulk price change applies to the products that match the filters currently set on the product list (category, brand, mirror type), not to hand-picked rows. A preview showing how many variants change, with before and after samples, is required before Apply.

### D-033 New rows go to the end
A new vehicle make or category is placed after its siblings instead of at the top, so the order you set is not disturbed.

### D-034 Scheduler heartbeat is not versioned
`backend/storage/framework/scheduler-heartbeat` is runtime state written by cron. It was tracked by mistake in Phase 0 and is now ignored.

### D-035 Decimal math without bcmath
The host check may not offer bcmath, and floats are forbidden for money. All quantity, cost and money arithmetic goes through `App\Support\Dec`, which works on scaled integers: quantity 3 decimals, money 2, unit cost 4. Rounding is half away from zero, an overflow guard refuses numbers that would not fit, and `allocate` splits an amount in proportion to weights using largest remainder so the parts always add up to the whole to the paisa.

### D-036 One writer for stock
`StockPostingService` is the only code that changes `stock_levels` and appends `stock_movements`. It refuses to run outside a transaction. Locks are taken in a fixed order (variant rows shared, then stock levels in ascending variant and warehouse order) so two postings cannot deadlock each other. Stock on file is also rebuilt from the movements by the same code for valuation as of a past date and for the integrity check.

### D-037 Average cost rules
Only stock coming in at a known cost changes the average: opening stock, purchase receipt and transfer in (which carries the source cost). Sales, returns to supplier, adjustments and counts leave the average alone. Goods that arrive broken are posted at the average that was already on file, so a damaged delivery does not lower the average of good stock.

### D-038 Landed cost
Extra costs (freight, duty, clearing, local transport, other) are spread over the lines that have good units, by value or by quantity as chosen on the receipt, split to the paisa. Landed unit cost = (good units × invoice unit cost + that line's share) ÷ good units, kept at 4 decimals. A hand-worked example is a test (AT-011).
The supplier is credited for the goods plus the extra costs that have no named payee. An extra cost with a payee (a freight company, say) is not added to the supplier balance.

### D-039 Negative stock and adjustments needing approval
A posting that would take stock below zero fails with a clear message unless the setting `allow_negative_stock` is on. An adjustment over the quantity or value threshold cannot be posted by someone without the approve permission: they submit it, managers are notified, and it waits in pending approval until a user with the approve permission posts it.

### D-040 A short transfer
When a transfer arrives with fewer units than were sent, the received quantity is posted at the destination and the missing units stay at the source as a draft adjustment for a manager to resolve, instead of vanishing in transit.

### D-041 Purchase returns are serialized per receipt
Two returns against the same receipt are processed one after the other (the receipt row is locked first), so together they can never return more than was received. Found by a concurrency test.

### D-042 Valuation and cost permission
Valuation, and any cost column, need the cost permission as well as the inventory reports permission. The API refuses the request (403) and the screen refuses the route, not only the tab.

### D-043 Integrity check
`integrity:check` runs every night at 03:00 Dhaka time and from Settings, System, Integrity. It checks that stock on file equals the sum of movements, that each movement's running balance follows the one before, that the stored average cost equals a replay of the movements, that nothing is negative (a warning when the setting allows it), that receipt totals add up, that the supplier ledger matches the receipts and returns behind it, and that purchase order progress matches what was received. Any difference is listed. A test alters a figure by hand and confirms the check reports it. `stock:alerts` runs hourly for low, out, dead and negative stock.

### D-044 Left for later in Phase 2 scope
- FR-505 and FR-506 (supplier payments, statements, aging) wait for Phase 4 with the ledger and payment accounts. The supplier balance is already correct from receipts, returns and opening balance.
- Printing a count sheet and the overstock alert are not built. (Correction made in Phase 3: variants already have a maximum stock field, so the overstock alert needs no new data; it simply was not built.) Say if you want them.

### D-045 Accounts and payment lines arrive with sales
Every payment posts a row to a financial account (FR-805), so the account tables (`financial_accounts`, `account_transactions`) are created in Phase 3, with the five starter accounts (Cash Drawer, bKash, Nagad, Rocket, Bank). The screens to manage accounts, and everything about balances, stay in Phase 4.

### D-046 Bangla in PDFs (NFR-020): decision recorded
**Engine and fonts.** mPDF on the server with OpenType shaping. Latin text uses Inter (body) and Plus Jakarta Sans (headings); Bangla text and the taka sign use Hind Siliguri. All three are open-licence fonts shipped in `backend/resources/fonts` with their licence files (1.9 MB; mPDF embeds only the glyphs a document uses).
**Why Hind Siliguri.** The first choice, Noto Sans Bengali, could not be used: mPDF's font reader stops on it with "GPOS Lookup Type 5, Format 3 not supported". The same happens with Anek Bangla and Noto Serif Bengali, and Tiro Bangla is refused for "MarkGlyphSets". Hind Siliguri loads and shapes স্ক্রিন, ক্ষ, র‍্যাম্প and ৳ as single joined glyphs (checked by eye on a rendering). mPDF's bundled FreeSerif also shapes them but is a serif.
**How it is kept honest.** A test renders the three conjuncts and the taka sign and compares the picture with an approved reference image (`backend/tests/fixtures/bangla-reference.png`). Text extraction cannot judge Bangla (it returns font glyph codes), so the picture is the check.
**Default language.** English, as the PRD says. Bangla names and notes print correctly when present.
**Cost on this machine.** A 60-line invoice: 4 pages, 0.3 s, 171 KB, and it renders inside a 64 MB PHP memory limit. This was measured locally, not on your hosting, so the same test must be repeated on the staging account (memory_limit and max_execution_time there decide).
The `@react-pdf/renderer` fallback was not needed.

### D-047 Invoice logo
The invoice embeds the original brand file `assets/brand/orbin-logo.jpg`, byte for byte (a test checks the bytes are inside the PDF). The logo uploaded under Company profile is used in the app, not on paper, until you supply the transparent version.

### D-048 Credit limit
A credit limit of 0 means no limit is checked. In "warn" mode the screen warns and the server never blocks. In "block" mode a sale that takes the balance past the limit needs a manager PIN.

### D-049 Approvals
One PIN dialog serves four cases, each needing the right permission in the approver: a price below the floor (`pos.sell_below_floor`), more discount than the cashier's limit (`pos.discount_over_limit`; the limit is all line and cart discount as a share of the subtotal), credit past the limit in block mode (same permission), and a repeated TrxID (`payments.verify`). Wrong PINs count against the person typing, who is locked out after the setting's limit for 15 minutes. The approver and the reason are written to the audit log.

### D-050 TrxID rule
The same TrxID on the same method is refused for `duplicate_trx_days` (30) unless a manager approves. After 30 days it may be reused. A unique key on method and TrxID is the race guard; rows that were overridden, or that reuse an old TrxID, carry no key.

### D-051 Bundles on an invoice
A bundle is one priced line plus one zero-priced row per component, and the components carry the cost (BR-033). The bundle row's own cost is 0, so a profit report adding up cost is not counted twice. Returning the bundle returns its components in proportion; a component cannot be returned alone.

### D-052 Returns
The window is the product's own return window, else the shop setting (7 days); a manager can approve past it. A cashier's return waits for a manager; a manager's posts at once. The refund is limited to what was paid for the lines (less earlier refunds); the rest becomes account credit. A line returned in full takes the exact remainder, so shares always add up to the line to the paisa. Resellable units go back at the cost they left at; broken ones come back and go straight to the breakage register at that cost. A refund paid in cash counts in the shift of the person who made the return, not the approver's.

### D-053 Void
A manager may void the same day, a super admin any day (setting `void_same_day_manager_only`), never when a return exists or the period is locked. Stock goes back at its original cost, payments are reversed as account entries (type adjustment), the ledger gets reversing entries, and the PDF is rebuilt with a VOID watermark. A sale from an earlier shift that is voided today is not subtracted from today's drawer (known limit, see report).

### D-054 Checkout is one idempotent call
`Idempotency-Key` is required. The browser keeps the same key for the same cart, so Retry after a dropped connection cannot make a second invoice, and any edit to the cart starts a new key. The PDF is made after the transaction commits; if it fails the sale stands and the PDF is made when someone downloads it.

### D-055 Held bills
A held bill is a row in `sales` with status `held` and the cart as JSON. It takes no stock, no number and no money. Resuming hands the cart back and removes the parked row.

### D-056 Shifts
One open shift per cashier. Expected cash = opening float + cash sales that still stand − cash refunds + cash in − cash out; voided sales drop out whole. The float itself is booked as a cash entry stamped with the cashier.

### D-057 Warranty
Raising a claim needs `sales.return.create` (cashiers can). Resolving one (in review, replaced, refunded, rejected) needs `sales.return.approve`, stricter than the spec's single write permission, because a replacement takes a unit out of stock. A claim must be raised before the line's warranty date.

### D-058 The POS uses the whole screen
On `/pos` the sidebar shrinks to its icon rail and the page margins tighten, without changing anyone's saved sidebar choice. The cart's Pay button is the one accent button on the screen; while the payment drawer is open Complete sale takes that role and Pay turns secondary.

### D-059 Left out of Phase 3 (all small, none blocking the gate)
- Keeping change as a customer advance (FR-804, optional), attaching a screenshot or slip to a payment (FR-802, optional), and rejecting a payment from the verification step (only "confirm" is offered).
- Receive-payment from the customer page and the customer Payments tab (FR-807, FR-1002): Phase 4.
- Customer photo or file attachment (FR-601).
- Payment receipt, customer statement and supplier statement PDFs: Phase 4. A4 invoice, 80 mm receipt, credit note, quotation, purchase order and Z-report are done.
- WhatsApp sharing is a link that opens WhatsApp with the message; nothing is sent from the server.
- The auto-download, print and keyboard behaviour was tested in Chromium only.

### D-060 What Phase 4 adds to the data model beyond the JSON
All small, all needed by a PRD line the JSON had no column for. `customers` and `suppliers` get `promise_date` and `promise_note` (FR-1007). `sales` and `goods_receipts` get `written_off_total` (FR-1008). `party_payments` also keeps `idempotency_key`, `trx_key` (a unique key like the sale payments have, so one TrxID cannot be taken twice at the same moment), and `status`, `void_reason`, `voided_by`. `expenses` and `fund_transfers` get the same void fields, and `expense_status` gains `void` and `decided_at`/`decision_note`. `expense_categories` gets a `code` that is set only on the category the system itself posts transfer charges to. `purchase_extra_costs.account_id` gets its foreign key at last (its comment promised it for Phase 4). Two settings are new: `allow_negative_account_balance` and `expense_approval_threshold`.

### D-061 How a payment is allocated, and what "due" means
Oldest first (BR-062): the opening balance (it is the oldest debt there is), then invoices (or goods receipts) by date. The user may split it by hand; each line is capped at what its document still owes, and the lines at the payment. The rest is kept as an advance, a ledger credit that is not applied to a later invoice by itself. A return's credit that was not refunded in cash settles that same invoice first, so what an invoice still owes is `due_total` minus those credits (worked out when needed, not stored). Aging (FR-1007) always adds up to the ledger balance: the balance is laid over the party's open documents newest first, because advances and credits settle the oldest first. A balance no document explains, such as an adjustment, counts as current. One quirk to know: after a return on a due invoice the invoice row can keep a small `due_total` equal to that return credit even though the customer owes nothing on it; the ledger and the dues screen are right, the invoice list shows the old figure.

### D-062 Write-off and discount
A write-off (bad debt) or discount takes the amount off the oldest dues, writes one ledger entry per document with the reason, lowers the document's due and raises its `written_off_total`. The invoice identity is now `grand_total = paid_total + due_total + written_off_total` (the database check was changed to match). A supplier's discount works the same way on goods receipts. A separate "adjustment" entry corrects a party's balance itself (for instance a wrong opening balance) without touching any document. Both need `ledger.writeoff` (the accountant and the owner), a reason, and an open period.

### D-063 Accounts cannot be overdrawn unless the owner allows it
A supplier payment, an expense, a transfer, an outgoing adjustment and the void of a receipt or a transfer are refused with INSUFFICIENT_FUNDS when the account would go below zero. The setting `allow_negative_account_balance` (default off) lifts this. The check and the write share one lock on the account. It does not apply to the Phase 3 till refunds and shift cash-out, which keep their own rule. This means the owner must enter opening balances (or book an account adjustment) before spending from an account.

### D-064 Expenses
An expense at or above `expense_approval_threshold` (whole taka, 0 = never) is saved as waiting for approval and touches no account until approved. Whoever entered it cannot approve it, except the owner (super_admin). A posted expense is never edited: it is voided, which puts the money back as an adjustment entry, and entered again. The charge on a fund transfer is an expense in the system category and cannot be voided alone; void the transfer.

### D-065 Voiding a payment, an expense or a transfer
A payment is voided with `payments.verify` (manager, accountant, owner) and a reason. The invoices or receipts it settled become due again, the money leaves the account, the ledger gets a reversing entry, the TrxID becomes free to use again, and the receipt is made again with a VOID mark. The reversal is dated today; the period lock is checked against the original date. A void that would overdraw an account is refused (D-063).

### D-066 The period lock
It is set only through `POST /settings/period-lock` with `periods.lock`, which only the owner holds by default. The general settings form can no longer change it (it could before, without that permission). Moving the lock earlier or removing it reopens closed books, so only the owner may; a lock date in the future is refused. Every create, edit and void checks the document's own date against it (BR-080); the Phase 2 and 3 services already did, and every Phase 4 service now does.

### D-067 The shift drawer counts collections and payments out
Expected cash now also adds what the cashier collected from customers and moved in by transfer, and takes off what they paid out for expenses, suppliers and transfers, all from the cash drawer during their shift. A voided payment, expense or transfer drops out whole. The Z-report shows both lines.

### D-068 One TrxID rule at the till and later
The checks for payment references and for a TrxID used before now live in one class used by the POS and by receipts and supplier payments, and the duplicate check reads both tables, so a TrxID taken at the till is also refused as a later receipt and the other way round (BR-061). This also fixed a gap: before, the POS looked only at POS payments.

### D-069 Who pays whom
A customer can only pay us (a receipt) and a supplier can only be paid by us. Paying a customer back out of an advance, or a supplier refunding us, is not in the spec and is not offered; a customer's refund goes through a return (Phase 3).

### D-070 Reminders and promises
A promise-to-pay is a date and a note kept on the customer (or, for a supplier, the date we plan to pay). The "reminder" button copies a short message with the amount and the shop's name to the clipboard for WhatsApp or SMS; nothing is sent from the server (FR-1007 says manual).

### D-071 Left out of Phase 4 (small, none blocking the gate)
- Attaching a slip or file to an expense or a payment: the API accepts an `attachment_id`, but there is no upload control on these screens yet.
- Rejecting a payment from the verification step (only "confirm" and "void" exist), as in Phase 3.
- Recurring expense templates: the PRD itself puts them in Phase 2 of the product.
- CSV, Excel or PDF export of the day book and dues lists: these are the report pages of Phase 5; for now they are screens, and each party has a statement PDF.
- A payment receipt in 80 mm roll size: the A4 receipt is made; roll receipts exist for sales.
- Browser testing was Chromium only.

### D-072 Long statements are written in slices
A statement PDF is written 200 rows at a time instead of as one big page of HTML. Measured here with 2,000 ledger rows: 3.8 seconds and 78 MB peak memory under a 128 MB limit, against 162 MB when it was one piece (which would not fit a 128 MB host). A test keeps rows from being lost or reordered where two slices join. The figures must still be checked on your real hosting account.

## Phase 5 — Reports and dashboard

### D-073 One report engine, 41 reports
Every report in the spec's `reports` list (41) is one definition: its columns, a query that yields rows, which permission opens it and which columns need cost. One runner turns a definition into the screen, the CSV, the Excel file, the PDF and the print view, so the file always has the rows the screen shows. A test runs every report through all three formats and compares row counts with the screen.

### D-074 Revenue, cost and profit have one definition
Revenue of a line is its total less VAT, for the quantity still sold after returns. Cost is that quantity times the unit cost stored on the line at sale time. Profit is revenue less cost. Sales by product, brand and vehicle, profit by invoice and product, the profit and loss report and the dashboard all use these two expressions from one place (`Q::REV`, `Q::COGS`). Invoices that are void are left out; returned quantity is taken out of both revenue and cost. A test works the figure out independently from the invoice lines and requires all of those screens to equal it (the Phase 5 gate).

### D-075 The dashboard is built from the same reports
The dashboard does not have its own sums. Each tile asks the same report code for the chosen period (and the one before it, for the arrow) and each widget is left out of the response unless the person may open that report. A cashier gets sales; a storekeeper gets low stock; only roles with cost permission get profit. It refreshes every 60 seconds (polling, per CLAUDE.md §4).

### D-076 Who sees which report
Each report names the permissions that open it; the hub lists only those, and opening one by address without permission shows the usual "no permission" page (the API answers 403). Cost, profit and margin columns are removed server-side for anyone without `products.view_cost`; a report whose whole point is cost (stock valuation, profit reports) needs it to open. A person with only `reports.view_own` sees their own sales only. Exports have their own permission (`reports.export`).

### D-077 Saved views
The spec asks for presets saved per user. A new table `report_presets` (user, report, name, the filters as JSON) holds them. A preset stores filters only, never rows, and a user can only list, create or delete their own.

### D-078 Differences from the spec's report measures
- **VAT summary** shows VAT collected only. The spec also lists `vat_paid`, but purchases do not carry VAT in the data model (JSON), so there is nothing true to show. Adding input VAT needs a data model decision from the owner.
- Reports carry no phone numbers, addresses or other personal data of customers; a customer appears by name. The login history shows user, event, time and IP address, as the spec says.
- Some measures in the spec's list (for example `avg_ticket`, `age`) appear as columns only where they make sense for the grouping.

### D-079 Files are written in English
CSV, Excel and PDF use English column and report names even when the screen is in Bangla. This avoids the Bangla conjunct risk in PDF (NFR-020, still open from Phase 3) and keeps files usable in accounting software. The screen, the hub and the dashboard are fully translated.

### D-080 What the files contain
- Money, quantities and percentages are numbers in Excel (so they add up in a spreadsheet) and plain decimal text in CSV and PDF, formatted as ৳ 1,25,000.00 only on screen.
- In CSV, a text cell that begins with `=`, `+`, `-` or `@` gets a leading apostrophe so a spreadsheet will not run it as a formula. Excel files store text as plain strings, so they need no guard.
- CSV and Excel are written row by row and the PDF in slices of 150 rows, so large reports stay inside shared-hosting memory.
- File names are `Orbin_<report>_<from>_<to>.<ext>`.

### D-081 Screen reports are held in memory
The on-screen table loads the whole report, sorts and pages it in PHP. Exports stream. This is fine for a shop's daily and monthly reports; a report over several years of line items on a small host could be slow. Not measured at scale (section 3 of the Phase 5 summary). If it proves slow, the first step is limiting the range on those reports.

### D-082 "From zero" comparison
When "compare to previous period" is on and the earlier period was zero there is no percentage to show. The tile says "From zero vs <dates>" instead of hiding the comparison.

### D-083 Left out of Phase 5 (small, none blocking the gate)
- Scheduled or emailed reports: not in the spec.
- Charts in the PDF export: the table is exported; the on-screen chart is not.
- Reports across branches side by side: filters pick one branch or all.
- Browser testing was Chromium only.
