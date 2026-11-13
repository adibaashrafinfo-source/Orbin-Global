# PHASE P4 REPORT — Ledger and Finance

## 1. What was built

- **Party ledger** (Finance → Party ledger). Pick a customer or supplier and see their whole account as a statement with a running balance, for any date range. Invoice, receipt and GRN numbers link to the documents. A **Statement PDF** is one click away.
- **Receive and pay** (Finance → Receive / pay). Take a payment from a customer or pay a supplier. Before posting you see which documents it will settle, oldest first (the opening balance first), or you can split it by hand. Anything left over is kept as an advance. Cash, bKash, Nagad, Rocket and Bank, with the same references the till needs (sender number and TrxID, bank name and reference). A TrxID used before needs a manager PIN. After posting you get the **receipt PDF**. Payments can be confirmed (when verification is on) or voided with a reason.
- **Accounts.** The cash drawer, bKash, Nagad, Rocket and bank accounts as cards with their balance, a ledger for each with the balance after every entry, add and edit, a default per payment method, and an adjustment for a count difference or a missed entry. Settings → Payment accounts now opens this screen.
- **Expenses.** Entered under admin-managed categories, paid from an account, with an approval step above a limit you set (someone else must approve; the owner may approve their own). A posted expense is corrected by voiding it. A category list you can edit.
- **Fund transfers.** Move money between your own accounts (bKash to bank, drawer to bank). A charge is booked as an expense on the source account. A transfer can be voided if the receiving account still holds the money.
- **Day book.** Every taka in and out for a date range, per account with opening and closing, with each entry's type, reference and party.
- **Dues and aging.** Customers who owe us and suppliers we owe, in buckets 0–15, 16–30, 31–60, 61–90 and over 90 days. Search, filter by age, sort. A promise-to-pay date and note per party. A "copy reminder" button puts a short message in the clipboard for WhatsApp or SMS. Receive, pay and statement buttons on each row.
- **Write-off, discount and balance adjustment** (accountant and owner). Forgive or discount a due oldest first, or correct a party's balance, each with a reason.
- **Period lock** (Settings → Period lock, owner). Close the books up to a date: nothing dated on or before it can be created, changed or voided.
- **Customer page.** A new Payments tab and a Receive payment button. Suppliers get Pay and Ledger buttons.
- **Cash shift.** The drawer now counts what the cashier collected from customers and paid out for expenses, suppliers and transfers, so the expected cash and the Z-report stay right.
- **Integrity check.** Three new checks: each payment's allocations add up; each payment is in the account books and the party ledger once, in full (a voided one nets to nothing); an invoice's or receipt's paid total equals the payments behind it.
- English and Bangla for every new string (a test keeps the two files identical).

## 2. Traceability

| ID | Evidence | Status |
|---|---|---|
| FR-1001 party ledger | `DuesAndDayBookTest`, `FinanceApiTest`, E2E AT-014 | Done |
| FR-1002 receive and pay, allocation, receipt | `PartyPaymentServiceTest` (21), `AllocatorTest`, `allocation.test.ts`, E2E | Done. Receipt is A4 and English only |
| FR-1003 accounts | `AccountServiceTest`, `FinanceApiTest`, E2E | Done |
| FR-1004 fund transfers | `FundTransferServiceTest`, E2E | Done |
| FR-1005 expenses | `ExpenseServiceTest`, `FinanceApiTest`, E2E | Done. No file-attachment control on screen, and recurring templates are Phase 2 of the product (PRD) |
| FR-1006 day book | `DuesAndDayBookTest`, E2E | Done. No CSV or PDF export yet (Phase 5 reports) |
| FR-1007 dues, aging, promise, reminder | `DuesAndDayBookTest`, E2E | Done. The reminder is copied, not sent (as the PRD says) |
| FR-1008 write-off and discount | `PartyAdjustmentServiceTest` | Done |
| FR-1009 period lock | `FinanceApiTest`, every service test, E2E | Done |
| FR-604 customer Payments tab, FR-807 receive a due later | E2E | Done |
| FR-806 verification | `PartyPaymentServiceTest` | **Partly.** Confirm and void exist; rejecting is not offered (as in Phase 3) |
| FR-905 payment receipt, customer and supplier statements | `FinanceApiTest` (text read back with poppler) | Done (English) |
| AT-014 | service test, `AllocatorTest`, `allocation.test.ts`, E2E | Pass: 2,500 against three invoices of 1,000 settles two and takes 500 off the third |
| AT-018 locked period | every Phase 4 service test, Phase 3 `SaleServiceTest`, E2E | Pass |
| AT-019 roles | `FinanceAuthorizationMatrixTest`: every route × role | Pass |
| AT-023 races | `FinanceConcurrencyTest` | Pass: two receipts cannot allocate one invoice twice; a TrxID is accepted once; an account cannot be spent twice; a receipt and a write-off cannot both settle one due; payment numbers have no gaps |
| Gate: POS due appears in the customer ledger | `PartyPaymentServiceTest`, E2E | Pass. The browser test makes the dues through the sale service, not the POS screen; the Phase 3 browser test covers the POS screen path to the customer page |
| Gate: oldest-first allocation correct | as AT-014 | Pass |
| Gate: account balances equal the day book | `test_the_gate_account_balances_equal_the_day_book` (a busy mix, day by day), E2E (each account closes on its card) | Pass |
| Gate: period lock blocks edits | as AT-018 | Pass |
| Gate: staging on your cPanel | needs your hosting | **Not done** (section 4) |

## 3. Test results

| Suite | Command | Result |
|---|---|---|
| Backend | `php artisan test` (MariaDB 10.11) | 503 passed, 4,451 assertions, 0 skipped (96 more tests than Phase 3) |
| Code style | `vendor/bin/pint --test` | passed |
| Frontend lint | `npm run lint` | clean, no warnings |
| Frontend types | `npm run typecheck` | clean |
| Frontend unit | `npm test` | 24 passed (7 new: allocation preview, the manual split, the reminder text) |
| Browser E2E | all specs on a freshly seeded database | 60 passed, 14 skipped (screenshot capture only); 9 are new Phase 4 scenarios |
| Screenshots | 1366 × 768 and 1920 × 1080, plus Bangla | `docs/screenshots/p4/` (30 images) |
| Release | `bash deploy/build-release.sh 0.5.0` | `orbin-pos-release-0.5.0.zip`, 66 MB |

Measured here (not on your hosting): a statement of 2,000 ledger rows takes 3.8 seconds and peaks at 78 MB under a 128 MB limit.

Problems found while building and fixed:

- The general settings form could change the period lock date without the lock permission. It now cannot; the lock has its own endpoint and rules.
- The POS only checked its own payments for a TrxID used before. Later receipts could reuse one. Both now share one check.
- The cash shift ignored money collected or paid out through the new finance screens, which would have made the Z-report wrong.
- A Phase 2 check of the supplier ledger would have misread supplier discounts; it now looks at invoice and return entries only.
- A statement with 2,000 rows used 162 MB, which would not fit a 128 MB host; it is now written in slices (D-072).

Not tested: any real host, Firefox, Edge or Safari, phone or tablet widths, reduced motion on the new screens, the dues screen with thousands of debtors (it loads every party with a balance and ages them in memory; tested with a handful), Bangla receipts and statements (they are English only).

## 4. Staging deployment

**Not done.** You have not bought hosting yet. Phase 4 was built and checked on a local MariaDB. `orbin-pos-release-0.5.0.zip` is ready. It adds one migration (`2026_05_01_000010_create_ledger_and_expense_tables`), which also changes one database check on `sales`; it is reversible. The memory figure for statements must be repeated on your account.

## 5. Decisions and deviations

See `docs/decisions.md` D-060 to D-072. The ones that matter most:

- **D-061** how a payment is allocated, what "due" means after a return, and why aging always adds up to the ledger balance.
- **D-063** accounts cannot go below zero unless you allow it. You will need to enter opening balances before spending from an account.
- **D-064** expenses above a limit need another person's approval.
- **D-066** the period lock has its own permission and only the owner can move it back.
- **D-060** the few columns added beyond the JSON (promise to pay, written-off total, void fields, two settings).

One thing to know: after a return on a due invoice, the invoice row can keep a small "due" equal to the credit given, even though the customer owes nothing on it. The ledger and the dues screen are right; the invoice list shows the old figure (D-061).

## 6. Demo script

Run locally: `cd backend && php artisan migrate:fresh --seed && php artisan serve`, then `npm run build` in `frontend` and `bash deploy/sync-frontend.sh`, and open http://127.0.0.1:8000. Logins as before, password `Orbin@2026`. Fill Settings → Company profile first (a receipt or statement is refused until the name, address and a phone are saved).

1. Sign in as **cashier@orbin.local**, open the POS and sell on credit to a named customer (pay part or nothing). Open **Ledger**: a cashier sees only Receive / pay.
2. Sign in as **accounts@orbin.local**. Open Ledger → Accounts and use **Adjust** on bKash and the bank to enter what each holds today.
3. Ledger → Receive / pay: pick the customer, type an amount larger than one invoice. The right side shows what each invoice settles, oldest first, and the advance. Press **Record receipt**, then **Receipt PDF**.
4. Ledger → Party ledger: the invoice, the payment and the running balance. Press **Statement PDF**.
5. Ledger → Dues & aging: the customer is in the age bucket. Set a **promise to pay** and press **Copy reminder**.
6. Ledger → Expenses: add Shop rent from bKash. Ledger → Fund transfers: move ৳5,000 from bKash to the bank with a ৳20 charge. Ledger → Accounts: both balances moved.
7. Ledger → Day book: pick a range; each account closes on the balance its card shows.
8. Ledger → Party ledger → **Write off** a small due (with a reason), then **Void** a payment from Receive / pay (as accountant).
9. Sign in as **admin@orbin.local**, open Settings → Period lock, lock up to today, then try a receipt dated today as the accountant: it is refused. Remove the lock.

## 7. Known gaps and risks

- Staging, and the memory and time check for statements on real hosting, are still open.
- Receipts and statements are English only; invoices follow the invoice language setting, these do not.
- Only Chromium was used for the browser tests.
- Dues and aging are worked out in memory for every party with a balance. Fine for hundreds or a few thousand; not measured beyond a handful.
- No export of the day book or the dues list yet (Phase 5).
- No attachment upload on expenses and payments (the API accepts one).
- A payment's verification can be confirmed but not rejected.
- The invoice row can keep a small leftover "due" after a return on a due invoice (D-061).
- From Phase 3 and earlier, still open: the POS channel and salesperson pickers and note box, "keep change as advance", over-receipt rule, label stock choice, count-sheet printing and overstock alerts.

## 8. Questions for the owner

1. Hosting check answers and a go-ahead to deploy staging once the account exists (open since Phase 0).
2. Go-live numbers: what each account (cash drawer, bKash, Nagad, Rocket, bank) holds on the day you start, and what customers owe you and you owe suppliers (opening balances). Accounts cannot be overdrawn, so these must be entered first.
3. Should an expense above some amount need approval? If yes, from what amount? (Now off.)
4. Should accounts be allowed to go below zero? (Now not.)
5. When do you want to lock a period, for example the previous month once the accountant has closed it?
6. Write-off, discount and balance adjustment are for the accountant and you only. Should the manager have them too?
7. Should receipts and statements also come in Bangla?
8. Still open from Phase 3: company profile and signature, transparent logo, whether a credit limit of 0 means "no limit", the return window, and the missing POS pickers.
9. Should I start Phase 5 (reports and the dashboard)?

## 9. Ready for P5?

**Yes for the code, with open items that need you.** Every Phase 4 gate passes locally except the one that needs your hosting (staging). Phase 5 builds the report pages and the dashboard on the tables and entries made here, and must show the same totals as the screens and invoices (AT-017). Please confirm whether I should start it now or wait for staging.
