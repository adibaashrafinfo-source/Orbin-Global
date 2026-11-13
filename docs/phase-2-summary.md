# PHASE P2 REPORT — Inventory and Purchasing

## 1. What was built

- **Stock engine.** One service posts every stock change inside a transaction, keeps an append-only list of movements, and keeps the stock-on-hand table in step. Stock on file can always be rebuilt from the movements. Average cost is weighted. Negative stock is refused unless you switch it on.
- **Inventory screens.** Overview with value and alerts, movements, stock card per variant with running balance, breakage register, reorder suggestions (create purchase order drafts grouped by supplier), aging buckets, and valuation as of any date.
- **Stock documents.** Adjustments with a reason and photo, submitted for approval when over the quantity or value limit. Transfers between warehouses (draft, in transit, received, with a short arrival handled). Physical counts with an optional blind count, variance review and approval.
- **Purchasing.** Suppliers with opening balance and terms, purchase orders (approve, receive, close, cancel, PDF and a signed share link), goods receipts with broken-on-arrival units and extra costs spread by value or quantity into a landed unit cost, and returns to supplier. Last purchase costs show on the order line.
- **Supplier balance.** Every receipt and return writes the supplier ledger. The supplier page shows the balance and its entries.
- **Integrity check.** `php artisan integrity:check` runs nightly at 03:00 Dhaka and from Settings, System, Integrity. It compares the books against themselves and lists any difference. Hourly stock alerts raise low, out, dead and negative stock notices.
- **Catalogue links.** Products show stock on the list, card and filters. Deactivating a product with stock now asks first (this closes FR-308 from Phase 1).
- **Security.** Cost fields are absent from responses without the cost permission. Valuation needs the cost permission on the API and on the screen. Every endpoint is covered by a role-by-route test.
- **Language.** Every new string exists in English and Bangla.

## 2. Traceability

| ID | Evidence | Status |
|---|---|---|
| FR-401 stock levels, single source of truth | `StockPostingTest`, `IntegrityTest` (level equals sum of movements) | Done |
| FR-402 movement types, balance after | `StockPostingTest`, `InventoryReadsTest` | Done |
| FR-403 adjustments, reasons, photo, approval | `InventoryApiTest`, E2E negative-stock and approval scenario | Done |
| FR-404 breakage register | `InventoryReadsTest`, screen at Inventory, Breakage | Done for the register by product, staff and period. Linking a breakage to a supplier claim is not built (D-044) |
| FR-405 transfers with discrepancy | `InventoryApiTest`, E2E short transfer | Done (D-040) |
| FR-406 physical count | `InventoryApiTest`, E2E count | Done. Blind count and variance approval work; **printing a count sheet is not built** (D-044) |
| FR-407 alerts | `stock:alerts` tests | Low, out, dead, negative done. **Overstock not built** (D-044) |
| FR-408 reorder suggestions, PO drafts | `InventoryReadsTest`, `PurchasingApiTest` | Done |
| FR-409 valuation as of date | `InventoryReadsTest`, replay in `IntegrityTest` | Done |
| FR-410 stock card | `InventoryReadsTest`, E2E | Done |
| FR-411 aging buckets | `InventoryReadsTest` | Done |
| FR-412 negative stock blocked | `StockPostingTest`, E2E | Done (D-039) |
| FR-413 opening stock import | `InventoryApiTest` (opening stock import) | Done |
| FR-501 supplier CRUD | `PurchasingApiTest`, E2E | Done |
| FR-502 purchase orders, PDF, share link | `PurchasingApiTest` | Done |
| FR-503 goods receipt, landed cost | `GoodsReceiptServiceTest`, E2E | Done (D-038) |
| FR-504 purchase return | `PurchasingApiTest`, concurrency test | Done (D-041) |
| FR-505 supplier payments | needs payment accounts and allocation | **Deferred to Phase 4** (D-044) |
| FR-506 supplier statement and aging | same | **Deferred to Phase 4** (D-044) |
| FR-507 last 5 costs per supplier | `PurchasingApiTest`, order line | Done |
| FR-308 deactivate warning (from P1) | `ProductStockTest`, review step | Done |
| AT-011 landed cost by hand | `GoodsReceiptServiceTest` and E2E (freight and duty by value, averages checked) | Pass |
| AT-019 role access, cost omitted | `InventoryAuthorizationMatrixTest`, E2E roles | Pass |
| AT-023 concurrency | `InventoryConcurrencyTest` (real parallel processes, last unit, double post, returns) | Pass |
| Gate: receipt updates stock, average cost, supplier ledger | E2E scenario 1 | Pass |
| Gate: negative stock blocked | E2E scenario 2, `StockPostingTest` | Pass |
| Gate: stock on file equals movements, `integrity:check` | `IntegrityTest` (also catches a hand-altered figure), E2E scenario 5 | Pass |
| Gate: landed cost matches hand calculation | `GoodsReceiptServiceTest`, `DecTest` | Pass |
| Gate: staging on your cPanel | needs your hosting | **Not done** (see section 4) |

## 3. Test results

| Suite | Command | Result |
|---|---|---|
| Backend feature and unit | `php artisan test` (MariaDB 10.11) | 276 passed, 2,741 assertions, 0 skipped |
| Code style | `vendor/bin/pint --test` | passed |
| Frontend lint | `npm run lint` | clean |
| Frontend types | `npm run typecheck` | clean |
| Frontend unit | `npm test` | 7 passed |
| Browser E2E | all specs on a freshly seeded database | 40 passed, 8 skipped (screenshot capture only) |
| Screenshots | 1366 × 768 and 1920 × 1080, plus Bangla | `docs/screenshots/p2/` (42 images) |

Problems the tests found and I fixed:

- Two postings for the same stock could deadlock, and a stale read could lose an update. The lock order and the locking reads were changed, and the concurrency suite now passes repeatedly (five runs in a row).
- Two returns against one receipt could together return more than arrived. Returns are now serialized per receipt (D-041).
- Append-only tables tried to write an `updated_by` column. They stamp only the creator now.
- A storekeeper typing the valuation address saw an empty page instead of a refusal, because only the tab was hidden. The route is now gated (D-042).
- Several summary queries failed on MariaDB (`HAVING` without `GROUP BY`) and were rewritten.

Not tested: Apache behavior, real email, Safari and Firefox, a screen reader, phone widths, very large stock imports or valuation runs on shared hosting, or anything on your hosting.

## 4. Staging deployment

**Not done.** You have not bought hosting yet (D-022). Phase 2 was built and verified on a local MariaDB. The release zip `orbin-pos-release-0.3.0.zip` (64 MB) is built. `integrity:check` and `stock:alerts` run from the one cron entry already in `deploy/cron.example`, so no new cron is needed.

## 5. Decisions and deviations

See `docs/decisions.md` D-035 to D-044. The ones that matter most: money and quantities use our own decimal helper instead of bcmath (D-035); only incoming stock at a known cost moves the average, and broken-on-arrival goods do not lower it (D-037); extra costs are spread over good units only (D-038); supplier payments and statements wait for Phase 4 (D-044).

## 6. Demo script

Run locally: `cd backend && php artisan migrate:fresh --seed && php artisan serve`, build and copy the frontend with `npm run build` then `bash deploy/sync-frontend.sh`, and open http://127.0.0.1:8000. Logins are the same as before, password `Orbin@2026`.

1. Sign in as **admin@orbin.local**. Products: make sure a product has two variants (or use the import in Phase 1).
2. Purchases, Suppliers: add a supplier with an opening balance of 5,000.
3. Purchases, Goods receipts, New: pick the supplier and warehouse, add the two variants (10 at 100 and 30 at 200), and add extra costs (freight 3,000 and customs 12,000), spread by value. Open Landed cost to see each line's unit cost, then Post.
4. Inventory: the two variants show 10 and 30 on hand with the new average cost. Click one for its stock card.
5. Purchases, Suppliers: the balance now includes the goods and the extra costs.
6. Inventory, Adjustments, New: try to take out more than is on hand and see it refused. Then enter a big adjustment and submit it; sign in as **manager@orbin.local** to approve it.
7. Inventory, Transfers: send stock to the other warehouse and receive fewer units than sent. Inventory, Counts: count a shelf and approve the variance.
8. Settings, System, Integrity: press Run now and see All good.
9. Sign in as **store@orbin.local**: Valuation is not offered. As **cashier@orbin.local**: `/purchases/suppliers` shows the permission screen.

## 7. Known gaps and risks

- Staging is still unverified. Valuation, aging and the integrity replay read every movement; on a large history they are chunked but untested on shared hosting limits.
- Count sheets cannot be printed yet, overstock alerts are missing, and a breakage cannot yet be tied to a supplier claim (all D-044).
- Supplier payments, statements and aging are not available until Phase 4, so supplier dues can be seen but not paid in the system.
- Receiving more than was ordered against a purchase order is refused (OVER_RECEIPT); you raise the order instead. Tell me if you would rather allow it up to a percentage.
- Photos on adjustments are stored privately and served only through the permission check; the no-script rule for uploads still depends on your host honoring `.htaccess`.
- The release zip is still 64 MB because of the PDF library's fonts (see Phase 1 note).
- The logo is still the JPEG.

## 8. Questions for the owner

1. Hosting check answers and a go-ahead to deploy staging once the account exists (open since Phase 0).
2. Company profile details and signature image. Invoices in Phase 3 stay blocked without them.
3. The transparent logo as PNG or SVG.
4. Label stock: A4 sticker sheets or a roll printer (open since Phase 1).
5. Over-receipt policy: keep refusing (current), or allow up to a percentage?
6. Do you want count-sheet printing, an overstock alert (variants already carry a maximum stock level, so it is small to add) and supplier claim links on breakage now, or later?
7. Should supplier payments come in Phase 4 as planned, or sooner?

## 9. Ready for P3?

**Yes for the code, with one open item.** Every Phase 2 gate passes locally. Staging on your cPanel is the only gate item still open. Phase 3 (POS, sales and the invoice PDF) needs the company profile and a decision on the Bangla invoice test, which must run on the real hosting. Please confirm whether I should start Phase 3.
