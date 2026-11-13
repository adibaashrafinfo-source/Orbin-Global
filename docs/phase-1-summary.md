# PHASE P1 REPORT — Catalog

## 1. What was built

- **Master data.** Vehicle makes, vehicle models (with generation and years), brands, categories as a tree, units and tax rates. Each has search, add and edit in a dialog, activate switch, delete that is refused when the record is in use, and ordering where it matters.
- **Lookup manager and custom field builder.** New lists and values, and extra fields for products, variants, customers and suppliers (text, number, date, pick one, pick many, yes/no, link). A field can be required to publish, shown on the POS card, shown on the invoice.
- **Product list.** Grid and table views, search by name, code, SKU, barcode, OEM number or vehicle, a filter drawer, export to CSV, and a bulk price change with preview.
- **Product editor in eight steps.** Basics (autosaved draft), Variants, Images, Fitment, Pricing, Stock control, Custom fields, Review with a POS card preview and Publish.
- **Variants.** Add one, edit in a drawer, clone, delete, or generate many with the matrix (positions, sizes, finishes, feature sets). SKUs are generated, barcodes can be generated, OEM and aftermarket cross-reference numbers and bundle components are supported.
- **Images.** Upload by drag and drop, per product or per variant, labelled, one primary, reordered, resized and thumbnailed on the server.
- **Fitment.** Make, model and year range per variant, applied to one or all variants, or copied from another variant.
- **Labels.** Barcode labels on an A4 sheet of 27 with a choice of first free slot, or a 50 × 25 mm roll, as a PDF with preview.
- **Import and export.** Vehicle makes, models, products with variants and fitments from CSV or Excel. A dry run lists every bad row, and the commit is all or nothing.
- **Security.** Prices change only with the pricing permission. Cost is absent from API responses without the cost permission, not just hidden on screen.
- **Language.** Every new string exists in English and Bangla, and a unit test keeps the two files identical.

## 2. Traceability

| ID | Evidence | Status |
|---|---|---|
| FR-201 master data CRUD, activate, reorder | `MasterDataTest`, E2E master data (7 tests) | Done. Reorder uses up and down buttons (D-029) |
| FR-202 lookup manager | `LookupAndCustomFieldTest`, E2E lookups | Done |
| FR-203 custom field builder | `LookupAndCustomFieldTest`, E2E custom fields, required fields block publish | Done |
| FR-204 import with dry run | `ImportExportTest` (11 tests), E2E import | Done for makes, models, products, fitments (D-028) |
| FR-205 soft delete | models use soft deletes, SKU and barcode stay reserved | Done |
| FR-301 product list | E2E gate, screenshots | Done. Stock total shows nothing until Phase 2 (D-029) |
| FR-302 editor stepper, autosave drafts | E2E gate | Done (D-030) |
| FR-303 variant matrix | `MatrixImagesFitmentTest`, E2E gate (2 × 3 = 6) | Done |
| FR-304 clone, bulk price | `ProductVariantTest`, price tests | Done (D-032) |
| FR-305 barcodes and labels | `LabelTest` (PDF parsed), E2E labels A4 and roll | Done |
| FR-306 fitment editor, copy | `MatrixImagesFitmentTest`, E2E gate (3 fitments) | Done |
| FR-307 cross-reference search | `ProductVariantTest` search by OEM number | Done |
| FR-308 deactivate warning when stock exists | needs stock levels | **Not done**, arrives with Phase 2 (D-029) |
| AT-015 new lookup, field and model selectable | `LookupAndCustomFieldTest` | Pass |
| AT-019 role access, cost omitted | `CatalogAuthorizationMatrixTest` (about 58 cases), E2E cashier blocked | Pass |
| AT-023 duplicate variant under concurrency | `CatalogConcurrencyTest` (5 tests, real parallel processes) | Pass |
| Gate: mirror with 6 variants, 4 images, 3 fitments | E2E `phase1-catalog.spec.ts`, published at the end | Pass |
| Gate: import dry run catches row errors, commit works | E2E import, `ImportExportTest` | Pass |
| Gate: SKU and barcode uniqueness | E2E, `ProductVariantTest`, concurrency tests | Pass |
| Gate: labels on A4 and 50 × 25 mm | E2E (PDF response checked), `LabelTest` | Pass |
| Gate: staging on your cPanel | needs your hosting | **Not done** (see section 4) |

## 3. Test results

| Suite | Command | Result |
|---|---|---|
| Backend feature and unit | `php artisan test` (MariaDB 10.11) | 209 passed, 1,974 assertions, 0 skipped |
| Code style | `vendor/bin/pint --test` | passed |
| Frontend lint | `npm run lint` | clean |
| Frontend types | `npm run typecheck` | clean |
| Frontend unit | `npm test` | 7 passed (money, class names, locale parity) |
| Browser E2E | `npm --prefix tests/e2e run test` on a freshly seeded database | 34 passed, 5 skipped (screenshot capture only) |
| Screenshots | 1366 × 768 and 1920 × 1080, plus Bangla | `docs/screenshots/p1/` (30 images) |

Bugs the tests found and I fixed:

- The CSV import template started with two byte-order marks, which could make a template fail on re-upload. It now has one, and a test uploads each template unchanged.
- A newly added category or make sorted to the top of its list. It now goes last.
- Zero cost showed a 100% margin. It now shows a dash until a real cost exists.
- Price cells wrapped the taka sign onto its own line.
- A runtime heartbeat file was tracked in git (D-034).

Not tested: any Apache behavior, real SMTP, Safari and Firefox, a screen reader, phone widths, very large imports near the 5,000-row limit on shared hosting, or anything on your hosting.

## 4. Staging deployment

**Not done.** You have not bought hosting yet (D-022). Phase 1 was built and verified on a local MariaDB. When the account exists, follow `docs/staging-deploy-checklist.md`; the release zip includes everything.

## 5. Decisions and deviations

See `docs/decisions.md` D-021 to D-034. The ones that matter most: Laravel 12 instead of 11 because Laravel 11 has open security advisories (D-021); price columns need their own permission (D-025); a published product saves on demand while a draft autosaves (D-030); bulk price uses the list filters (D-032).

## 6. Demo script

Run locally: `cd backend && php artisan migrate:fresh --seed && php artisan serve`, build and copy the frontend with `npm run build` then `bash deploy/sync-frontend.sh`, and open http://127.0.0.1:8000.
Logins are the same as Phase 0, password `Orbin@2026`.

1. Sign in as **admin@orbin.local**. Settings, Master data: add a vehicle model, open Lookup lists and add a glass type, open Custom fields and add one for products.
2. Products, Add product. Fill the basics and press Create draft.
3. Variants, Generate variants: keep Left and Right, add three sizes, enter a retail price, Preview, then Create. Six variants appear.
4. Images: drop four pictures. Fitment: pick Toyota and a model, a start year, Add, three times. Pricing: change one price and Save.
5. Review: all ticks green except advice, then Publish product.
6. Products, Labels: search the product code, add a variant, choose A4 or Roll, Create PDF.
7. Products, Import: pick Products and variants, download the template, change a category to a wrong name, Check file to see the row error, fix it, Check again and Import.
8. Sign in as **cashier@orbin.local** and open `/products/new` or `/products/import` to see the permission screen.

## 7. Known gaps and risks

- Staging is still unverified, so host differences (PHP extensions such as GD and mbstring, memory for PDFs and imports) are the main risk.
- The deactivate-with-stock warning waits for Phase 2.
- Product images are stored under `public/uploads`; the no-PHP-execution rule is tested locally but depends on the host honoring `.htaccess`.
- Label text prints the price as "Tk 12,500.00" because the barcode font has no reliable taka sign (D-027).
- Imports and exports are chunked in design but only tested with small files. Please try your real catalogue on staging.
- The release zip is 64 MB (Phase 0 was 18 MB) because the PDF library ships about 98 MB of fonts, mostly Asian scripts we do not use. If your host limits uploads, say so and I will prune the fonts after the Bangla invoice test in Phase 3.
- The logo is still the JPEG.

## 8. Questions for the owner

1. Hosting check answers and a go-ahead to deploy staging once the account exists (same as Phase 0).
2. Company profile details and signature image, so invoices can be enabled.
3. The transparent logo as PNG or SVG.
4. Do your real product names use a standard pattern (for example Brand, model, side, size)? I can then suggest a naming template for generated variants.
5. Which label stock do you actually use: A4 sticker sheets with 27 labels, or a roll printer? I can drop the unused one.

## 9. Ready for P2?

**Yes for the code, with one open item.** Every Phase 1 gate passes locally. Staging on your cPanel is the only gate item still open, and it blocks only the hosting check, not the Inventory work. Please confirm whether I should start Phase 2 now or wait for staging.
