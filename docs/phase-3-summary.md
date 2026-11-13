# PHASE P3 REPORT — POS and Sales

## 1. What was built

- **POS terminal** (`/pos`). Vehicle finder (make, model, year, side), a search box that also takes a scanner, quick filters, a product grid with size chips showing live stock (out-of-stock chips are disabled), quick view with gallery, fitment and cross-reference numbers, and a cart with quantity, price (by customer tier), line and cart discount, delivery, VAT, round-off and a counting grand total. The whole screen is keyboard-driven (F2, F3, F4, F6, F8, F9, F10, F11, Del, + and −, Ctrl+Enter, Ctrl+P, Alt+N, Esc).
- **Payment drawer.** Cash, bKash, Nagad, Rocket and Bank, split across any number of lines, with a big keypad and change for cash, sender number and TrxID for mobile money, bank name and reference for bank, and "Fill remaining". Complete sale is the only orange button while the drawer is open.
- **Checkout.** One call that takes the stock, makes the invoice, records the payments, posts the account entries and the customer ledger, all or nothing. The server recomputes every figure and refuses a total that differs. A double click or a retry after a dropped connection makes one invoice.
- **Approvals.** A manager PIN lets a sale go below the floor price, past the cashier's discount limit, over a credit limit, or reuse a TrxID. The approver and reason are audited; wrong PINs lock the typist out.
- **Invoice PDF**, made on the server and saved privately: original logo, full company and customer details, vehicle card, itemised lines with specs and warranty, totals, amount in words (lakh and crore), payments with references, terms, signatures, tagline, page numbers and a QR code. It downloads by itself as `Invoice_<no>_<customer>.pdf`, with a Download button as fallback. The same layout gives an 80 mm receipt, a credit note, a quotation and the Z-report. The QR opens a public page showing only the number, date, total and paid status.
- **Sales screens.** Invoice list with filters and totals, invoice detail with history, reprints counted, void (with reason), a three-step return wizard with credit note, returns list with approval, warranty claims, quotations (made from the cart; converting one checks stock and refills the POS cart), held bills (F10 and F11).
- **Customers.** List, add and edit, price tier, credit limit, opening balance, vehicles, duplicate-phone warning, quick-add inside the POS, and a customer page with due, advance, lifetime value, invoices, ledger and top products.
- **Shifts.** Open with a float, cash in and out, close against a count of notes and coins, and a Z-report. The top bar shows "Shift open · since 10:02" from the server.
- **Language.** Every new string exists in English and Bangla (a test keeps the two files identical).

## 2. Traceability

| ID | Evidence | Status |
|---|---|---|
| FR-601 customer record | `CustomerAndQuotationTest`, `SalesApiTest`, E2E | Done. No photo or file attachment (D-059) |
| FR-602 vehicles | `CustomerAndQuotationTest`, E2E credit sale | Done |
| FR-603 quick-add, walk-in | E2E | Done. Exactly one walk-in, enforced by a unique key |
| FR-604 customer page | E2E | Done except the Payments tab (Phase 4) |
| FR-605 duplicate phone | `CustomerAndQuotationTest`, `SalesApiTest` | Done |
| FR-606 credit limit | `SaleServiceTest` | Done (D-048) |
| FR-701 terminal, scanner | E2E | Done. A scanner is simulated by typing and Enter; no real scanner was tried |
| FR-702 vehicle finder | `PosSearchTest`, E2E | Done |
| FR-703 search, scan, 150 ms debounce | `PosSearchTest`, E2E | Done. Search speed on 10,000 variants not measured |
| FR-704 size chips, live stock | `PosSearchTest`, E2E | Done. A disabled chip gives a hint, no transfer request |
| FR-705 cart | `SalePricingTest`, pricing unit tests, E2E | Done |
| FR-706 customer, vehicle, channel, salesperson, note | E2E | **Partly.** Customer and vehicle done; the channel and salesperson pickers and the invoice note box are not on screen (server supports them; default is counter and the logged-in user) |
| FR-707 hold and resume | `SaleServiceTest`, `SalesApiTest`, E2E | Done |
| FR-708 quotation | `CustomerAndQuotationTest`, `PdfDocumentsTest` | Done |
| FR-709 price override, floor PIN | `SaleServiceTest`, E2E | Done |
| FR-710, FR-711 pay drawer, atomic checkout | `SaleServiceTest`, `SalesConcurrencyTest`, E2E | Done |
| FR-712 success screen | E2E | Done |
| FR-713 sales list and detail | `SalesApiTest`, E2E | **Mostly.** Date, status, method, due and search filters on screen; the customer and cashier filters exist in the API only |
| FR-714 void | `SaleServiceTest`, E2E | Done (D-053) |
| FR-715 return, credit note | `SaleReturnServiceTest`, `PdfDocumentsTest`, E2E | Done. An "exchange" is a return followed by a new sale, not one button |
| FR-716 shift, Z-report | `ShiftServiceTest`, E2E | Done |
| FR-717 warranty | `CustomerAndQuotationTest`, `SalesAuthorizationMatrixTest` | Done (D-057) |
| FR-801 to FR-803 split payments, fields, status | `SaleServiceTest`, E2E | Done |
| FR-804 overpayment | `SaleServiceTest` | **Partly.** Blocked for non-cash and change for cash; the "keep as advance" toggle is not built |
| FR-805 account entries | `SaleServiceTest` | Done |
| FR-806 verification | `SalesApiTest` | **Partly.** Pending and confirm work; rejecting is not offered; the dashboard widget is Phase 5 |
| FR-807 receive a due later | none | Phase 4 |
| FR-901 to FR-904, FR-906 PDF, snapshot, verify | `PdfDocumentsTest`, `SalesApiTest`, E2E | Done |
| FR-905 document variants | `PdfDocumentsTest` | Invoice, receipt, credit note, quotation, purchase order, Z-report done. Payment receipt and the two statements are Phase 4 |
| FR-802 screenshot or slip attached to a payment | none | Not built (optional in the PRD) |
| AT-001 vehicle finder order and speed | `PosSearchTest` | Ordering passes. The 300 ms figure on 10,000 variants is **not measured** |
| AT-002, AT-003, AT-004 | E2E, `SaleServiceTest`, `PdfDocumentsTest` | Pass |
| AT-005 bKash without TrxID | `SaleServiceTest`, E2E drawer message | Pass |
| AT-006 repeated TrxID, manager override | `SaleServiceTest`, `SalesConcurrencyTest` | Pass |
| AT-007 walk-in cannot leave a due | E2E | Pass |
| AT-008 discount over the limit | E2E | Pass (wrong PIN refused, right PIN audited) |
| AT-009 last unit | `SalesConcurrencyTest` (8 racing sales) | Pass: one succeeds, seven get INSUFFICIENT_STOCK |
| AT-010 network drop | E2E (request aborted, then Retry), `SalesConcurrencyTest` | Pass: nothing saved, cart kept, one invoice after Retry |
| AT-012 void | `SaleServiceTest`, E2E | Pass |
| AT-013 returns, resellable and broken | `SaleReturnServiceTest` | Pass |
| AT-016 shift arithmetic | `ShiftServiceTest` (worked by hand) | Pass |
| AT-018 locked period | `SaleServiceTest` | Pass |
| AT-019 roles and cost | `SalesAuthorizationMatrixTest` (every route × role), `SalesApiTest` | Pass |
| AT-020 reduced motion | none for the new screens | **Not tested.** The flash, count-up and success animations are written to stop under that setting, but this was not checked in a browser |
| AT-023 races | `SalesConcurrencyTest` | Pass (last unit, TrxID, double click, invoice numbers, second shift, void against return) |
| NFR-020 Bangla PDF decision | `PdfDocumentsTest`, D-046 | **Recorded.** Measured here, not on your hosting |
| NFR-040 end-to-end flow | `phase3-pos.spec.ts` | Pass: finder, two sizes, cash plus bKash, complete, PDF read back (logo bytes, totals, words), return, void |
| Gate: auto-download in Chrome, Edge, Firefox, Safari with fallback | E2E in Chromium | **Chromium only.** The other three could not be run here |
| Gate: staging on your cPanel | needs your hosting | **Not done** (see section 4) |

## 3. Test results

| Suite | Command | Result |
|---|---|---|
| Backend feature and unit | `php artisan test` (MariaDB 10.11) | 407 passed, 3,608 assertions, 0 skipped |
| Code style | `vendor/bin/pint --test` | passed |
| Frontend lint | `npm run lint` | clean |
| Frontend types | `npm run typecheck` | clean |
| Frontend unit | `npm test` | 17 passed (money, class names, locale parity, pricing preview against the server's worked examples) |
| Browser E2E | all specs on a freshly seeded database | 51 passed, 11 skipped (screenshot capture only) |
| Screenshots | 1366 × 768 and 1920 × 1080, plus Bangla | `docs/screenshots/p3/` (29 images) |
| Release | `bash deploy/build-release.sh 0.4.0` | `orbin-pos-release-0.4.0.zip`, 66 MB, includes the PDF templates, fonts and QR library |

PDF figures on this machine (not your hosting): 60-line invoice, 4 pages, 0.3 s, 171 KB, and it renders inside a 64 MB PHP memory limit.

Problems the tests found and I fixed:

- The sales list lost its paging information (a summary strip replaced it), which crashed the screen. A test now checks the paging.
- A new customer came back from the API with empty credit limit and balance, which crashed the POS. The API now returns the saved record.
- The discount dialog reset itself on every render and froze the page (an infinite loop).
- After "Add payment" the drawer counted the next, pre-filled payment at once, so "Remaining" showed ৳ 0.00. The next payment now starts empty.
- The shared type check I had been running did not cover the sources; the real `npm run typecheck` found three more type errors, now fixed.
- Two decisions of mine were wrong and were corrected in the record: a Phase 2 note about overstock alerts, and the font (see D-046).

Not tested: any host, Firefox, Edge or Safari, a real barcode scanner or receipt printer, phone or tablet widths, reduced-motion on the new screens, search speed on a large catalogue.
One Phase 1 test (SKU uniqueness) failed once in one of three full browser runs and passed on the other two and on its own; I could not reproduce it and do not know why.

## 4. Staging deployment

**Not done.** You have not bought hosting yet (D-022). Phase 3 was built and checked on a local MariaDB. The release zip `orbin-pos-release-0.4.0.zip` is ready. It needs `memory_limit` of at least 256 MB and `max_execution_time` of at least 60 seconds for PDFs (the spec's own figure); the local test fits well inside that, but only your account can confirm it, and the Bangla and memory test (D-046) must be repeated there.

## 5. Decisions and deviations

See `docs/decisions.md` D-045 to D-059. The ones that matter most: Bangla in PDFs uses Hind Siliguri because the first-choice font cannot be read by the PDF engine (D-046); a credit limit of 0 means "no limit" (D-048); one PIN dialog covers four kinds of approval (D-049); returns are capped at what was paid and a cashier's return waits for a manager (D-052); the POS takes the whole screen by shrinking the sidebar (D-058); a list of small things left out on purpose (D-059).

## 6. Demo script

Run locally: `cd backend && php artisan migrate:fresh --seed && php artisan serve`, then `npm run build` in `frontend` and `bash deploy/sync-frontend.sh`, and open http://127.0.0.1:8000. Logins as before, password `Orbin@2026`. Before selling, fill Settings, Company profile (name, address, a phone) or invoices stay blocked, and have stock and a product that fits a car (Phase 1 and 2 screens).

1. Sign in as **cashier@orbin.local**, open POS Terminal and open a shift with a float of 1,000.
2. Choose Toyota, Axio, 2015, Right. Pick two sizes of a mirror. The cart shows two lines.
3. Press F9. Enter 5,000 cash on the keypad and Add payment, switch to bKash, Fill remaining, enter a sender number and a TrxID, press Complete sale. The invoice PDF downloads by itself; open it and check the logo, company and totals.
4. Press F9 on a new cart with a 20% discount: the manager PIN dialog appears. Try 0000 (refused), then 2345 (works).
5. Scan or type a SKU and press Enter: the item is added with a beep and an orange flash. Press F10 to hold the bill and F11 to bring it back.
6. Press F4, choose New customer, add a name and phone, and pay only part: the due is shown and recorded.
7. Sign in as **manager@orbin.local**. Sales: open an invoice, Return one line (a credit note is made), open another and Void it (the verify page then says VOID).
8. Shifts: close the cashier's shift with a count and open the Z-report.
9. Sign in as **store@orbin.local** and open `/pos` to see the permission screen.

## 7. Known gaps and risks

- Staging, the PDF memory and time check on real hosting, and the Bangla check there are all still open.
- Only Chromium was used. The automatic download and print behaviour in Edge, Firefox and Safari is untested; Safari in particular treats downloads without a click strictly, which is why the Download button is always there.
- The POS search speed target (300 ms on 10,000 variants) is not measured.
- The logo on the invoice is the original JPEG with its pale box. The transparent version will fix that.
- A sale voided on a later day is not taken out of the earlier shift's drawer figures (D-053). Cash is always right in the account books; only that shift's report would differ.
- A bundle is sold from its components' stock; a stock shortage in one component blocks the bundle with the usual "not enough stock" message for that component.
- The channel and salesperson pickers, the invoice note box and the customer and cashier filters on the sales list are missing from the screens (FR-706, FR-713).
- The overstock alert and count-sheet printing from Phase 2 are still not built.

## 8. Questions for the owner

1. Hosting check answers and a go-ahead to deploy staging once the account exists (open since Phase 0). The invoice and Bangla checks need it.
2. Company profile: legal name, full address, phones, email, website, BIN, TIN, trade licence, bank details, signature image, and your invoice terms, return policy and warranty wording. Invoices print exactly what is saved there; the test data used placeholders.
3. The transparent logo (PNG or SVG), so the invoice header and receipt look clean.
4. Credit limit: is "0 = no limit checked" what you want, or should 0 mean "no credit"? (D-048)
5. Return window: 7 days for all, or longer for some mirrors? A product can have its own (D-052).
6. Should I add the channel and salesperson pickers and the invoice note box to the POS now, or leave them for later? Do you need "keep change as advance" and attaching a payment slip, or are they fine to wait?
7. Label stock (A4 sheet or roll), the over-receipt rule, and whether you want count-sheet printing, overstock alerts and supplier claim links (all open from Phases 1 and 2).
8. Should I start Phase 4 (ledger and finance)?

## 9. Ready for P4?

**Yes for the code, with open items that need you.** Every Phase 3 gate passes locally except the ones that need your hosting (staging, the PDF check on it) or other browsers (Edge, Firefox, Safari). Phase 4 (accounts, receiving payments with oldest-first allocation, expenses, day book, aging, period lock, statements) builds directly on the tables and entries made here. Please confirm whether I should start it now or wait for staging.
