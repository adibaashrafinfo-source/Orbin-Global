# Orbin POS — Car Mirror Edition

**Product Requirements Document (PRD) v1.1 — cPanel / MySQL edition**
Client / Brand: **Orbin Global** — *Trust. Growth. Excellence.*
Prepared: 2026-11-12 · Market: Bangladesh (BDT ৳) · Status: Ready for build

---

## 0. How to use this document set

| File | Purpose | Authority |
|---|---|---|
| `CLAUDE.md` | Operating instructions for Claude Code: rules, build order, definition of done | Highest for *process* |
| `PRD.md` (this file) | What to build and how it must look and behave | Authority for *behavior and UX* |
| `orbin-pos-spec.json` | Machine-readable spec: tokens, enums, roles, full data model, transactional services, API conventions, hosting, phases | Authority for *data model, enums, tokens, hosting constraints* |
| `DEPLOY_CPANEL.md` | Hosting check, release packaging, cPanel deployment, cron, backups, update/rollback | Authority for *deployment steps* |
| `FABLE_KICKOFF.md` | Master prompt and per-phase prompts for the coding agent | Convenience |

Conflict rule: data model / enum / token / hosting constraint → JSON wins. Behavior / UX → PRD wins. Anything still ambiguous → ask the owner, do not guess.

This document set contains **no application code**. Claude Code writes all code from these specs.

Requirement IDs (`FR-xxx`, `BR-xxx`, `NFR-xxx`) are referenced by `CLAUDE.md` build phases and by the JSON. Keep IDs stable.

---

## 1. Product overview

### 1.1 Vision
A premium, fast, single-purpose POS and back-office system for a business that sells **car mirrors only** (side mirrors, interior rear-view mirrors, replacement mirror glass, and mirror parts) in many sizes and fitments. It must handle full inventory, purchasing, ledgers, payments (Cash, bKash, Nagad, Rocket, Bank Transfer), and generate a branded PDF invoice automatically at the end of every sale.

### 1.2 Goals
1. A cashier can find the right mirror for a customer's car in **under 10 seconds** (by vehicle make → model → year → position, by SKU/barcode scan, or by text search).
2. Every sale ends with an **auto-downloaded PDF invoice** carrying the original Orbin Global logo, full company details, and full client details.
3. Stock is always correct: every change is a movement record; no silent edits.
4. Owner sees accurate **profit, stock value, dues, and cash/mobile-wallet/bank balances** at any time.
5. The admin can add and change **everything** (mirror attributes, sizes, vehicle makes/models, images, lookup lists, custom fields, tax, payment accounts, company profile) from the admin panel — nothing important is hard-coded.
6. Every screen looks premium: consistent Orbin brand, polished animation, refined buttons.

### 1.3 Non-goals (Phase 1)
- No online storefront / e-commerce sync.
- No live bKash/Nagad/Rocket gateway API. Phase 1 records these payments **manually** (sender number + TrxID).
- No full double-entry accounting (a party ledger + account ledger is built; formal journal is Phase 2).
- No native mobile app. Responsive web only.
- **No third-party database or backend-as-a-service** (no Supabase, Firebase, etc.). The database is the MySQL/MariaDB that the owner creates in his own cPanel hosting, and the application runs on that same hosting.
- No multi-currency.
- No product categories outside car mirrors (the schema stays generic enough, but UI/seed focus on mirrors).

### 1.4 Success metrics
| Metric | Target |
|---|---|
| Time to complete a typical sale (1–2 items, one payment) | ≤ 45 s after cart starts |
| Vehicle-finder result latency | ≤ 300 ms for 10 000 variants |
| Invoice PDF generation after "Complete Sale" | ≤ 3 s |
| Stock mismatch between movements and levels | 0 (enforced by DB) |
| Lighthouse (desktop, POS screen) Performance / Accessibility | ≥ 85 / ≥ 95 |

### 1.5 Assumptions (confirm with owner; each is easy to change)
| # | Assumption |
|---|---|
| A1 | **"Laser" means "Ledger"** (customer/supplier due ledger and cash/bank ledger), as commonly said in Bangladesh. If it means something else (e.g., laser glass cutting), tell the owner/Claude Code before Phase 4. |
| A2 | One shop at launch, but the schema is **multi-branch / multi-warehouse ready** (`branch_id` everywhere). |
| A3 | **Stack for the owner's cPanel hosting:** React + TypeScript + Vite + Tailwind + shadcn/ui + Framer Motion (frontend, built to static files) and **Laravel (PHP 8.2+) REST API with MySQL 8 / MariaDB 10.5+** (backend). Same domain/subdomain, cookie authentication, files stored on the hosting account. No third-party services. |
| A8 | The owner confirms the hosting capabilities in `DEPLOY_CPANEL.md §1` (PHP version/extensions, MySQL version, SSH yes/no, cron, memory) **before Phase 0**. Fallbacks exist for no SSH (web installer), no server Composer (vendor shipped in the release zip), PHP 8.1 only (Laravel 10), and low memory (PDF fallback). |
| A9 | Real-time push is replaced by polling every 30–60 s (stock badges, notifications). |
| A4 | VAT is **configurable** (`tax_rates`); default 0 %. Common standard rate in Bangladesh is 15 %; owner/accountant must confirm. Invoice is a commercial invoice, **not** an NBR-certified Mushak 6.3 / EFD output in Phase 1. |
| A5 | Invoice default language is English with BDT amounts; Bangla labels are an optional setting once PDF Bangla shaping passes the test in NFR-020. |
| A6 | Payment methods are exactly: Cash, bKash, Nagad, Rocket, Bank Transfer. (Card is not included; admin may add methods later via `financial_accounts` + enum extension in Phase 2.) |
| A7 | The uploaded Orbin Global logo is the only logo source. It is **never redrawn, recolored, cropped, or distorted**. |

---

## 2. Brand and design system

### 2.1 Brand assets
- Company: **Orbin Global**. Tagline: *Trust. Growth. Excellence.*
- Logo file (provided by owner): place at `backend/public/assets/brand/orbin-logo.png` and `frontend/public/assets/brand/orbin-logo.png` (original, untouched). Admin can replace it in **Settings → Company Profile**; the uploaded file is stored under `public/uploads/brand` on the server and referenced by `company_profile.logo_url`.
- Logo usage rules:
  - Always on a white or very light (`navy-50`) surface. On dark navy surfaces use a white rounded "logo plate" container behind the original logo; do not invert or recolor.
  - Keep aspect ratio. Minimum clear space = height of the "O" in ORBIN on every side.
  - Minimum rendered width: 120 px on screen, 35 mm on paper.
  - Sidebar collapsed state shows the circular emblem only (crop the emblem region via CSS `object-position` on a masked container, never by editing the file).
  - The invoice PDF embeds the **original logo file at full resolution** (no re-compression below 200 DPI at print size).

### 2.2 Color tokens
Owner-supplied brand colors: **Navy `#002756`**, **Orange `#FF7900`**.

| Token | Hex | Use |
|---|---|---|
| `navy-950` | `#001734` | Deep backgrounds, login hero gradient end |
| `navy-900` (brand) | `#002756` | Sidebar, primary buttons, headings, table headers |
| `navy-800` | `#062D5E` | Hover on navy surfaces |
| `navy-700` | `#0A3468` | Pressed, active nav item bg |
| `navy-600` | `#0F4180` | Links, focus rings (on light) |
| `navy-500` | `#2A5A9C` | Chart series 2 |
| `navy-400` | `#5B80B5` | Chart series 3 / icons muted |
| `navy-300` | `#97B0D3` | Borders on navy surfaces |
| `navy-200` | `#C7D5EA` | Dividers, skeleton base |
| `navy-100` | `#E6EDF7` | Hover rows, chips |
| `navy-50` | `#F3F6FB` | App background |
| `orange-800` | `#8C4300` | Orange text on tinted bg |
| `orange-700` | `#B85200` | **Orange text on white** (contrast 4.95:1) |
| `orange-600` | `#E06A00` | Accent hover |
| `orange-500` (brand) | `#FF7900` | Accent buttons, highlights, badges, active indicator |
| `orange-400` | `#FF9A40` | Gradient top, glow |
| `orange-300` | `#FFBC80` | Focus ring on navy |
| `orange-200` | `#FFD4AD` | Soft borders |
| `orange-100` | `#FFEAD6` | Soft badge bg |
| `orange-50` | `#FFF6ED` | Highlight bg |

Semantic colors (status only, never decorative): success `#16A34A`, warning `#D97706`, danger `#DC2626`, info `#0F4180`. Neutral text: `slate-900 #0F172A`, `slate-600 #475569` (7.58:1 on white), `slate-400 #94A3B8`.

**Verified contrast (WCAG 2.1):**
| Pair | Ratio | Rule |
|---|---|---|
| White on navy-900 | 14.74 : 1 | OK for all text |
| Navy-900 on orange-500 | 5.61 : 1 | **Text on orange buttons is navy** |
| White on orange-500 | 2.63 : 1 | **FAIL — never put white text on orange** |
| Orange-500 on white | 2.63 : 1 | **FAIL — never use brand orange as text on white; use `orange-700`** |
| Orange-700 on white | 4.95 : 1 | OK |

Orange is used as **accent**: CTA, highlight, indicator, chart accent. Navy is the **structure color**.

### 2.3 Typography
- Headings & numbers: **Plus Jakarta Sans** (600/700/800). Tabular numerals on all money/quantity columns.
- Body/UI: **Inter** (400/500/600).
- Bangla: **Noto Sans Bengali** (and Hind Siliguri as fallback). Bundled locally, not from a CDN, so the PDF and the app render identically.
- Scale (px / line-height): 12/16, 13/18, 14/20 (default), 16/24, 18/28, 20/28, 24/32, 30/36, 36/40, 48/52.
- Money format: `৳ 1,25,000.00` (South Asian lakh/crore grouping, `en-BD`). Negative in danger color with minus sign. Never abbreviate on invoices.

### 2.4 Shape, depth, spacing
- Radius: 8 (inputs, buttons), 12 (cards), 16 (modals, drawers), 999 (chips).
- Spacing grid: 4 px base; common 8/12/16/24/32.
- Elevation (navy-tinted shadows, never pure black): `sm 0 1px 2px rgba(0,39,86,.06)`, `md 0 6px 16px rgba(0,39,86,.10)`, `lg 0 16px 40px rgba(0,39,86,.16)`, `glow-orange 0 8px 24px rgba(255,121,0,.35)`.
- Surfaces: white cards on `navy-50` background; navy sidebar; 1 px `navy-200` borders.

### 2.5 Signature effect — "Mirror Shine"
The product sells mirrors, so the UI carries one tasteful reflective motif used consistently:
- A soft diagonal **gloss sweep** (a 20° white-to-transparent band, 18 % opacity) travels across a card/button on hover, 700 ms, ease-out, once per hover.
- Product cards have a subtle **reflection tilt**: on hover the image gets a 2° 3D tilt and the gloss band follows the pointer.
- Logo plate on login has a slow, subtle ambient gloss pass every 8 s.
- Use it on: primary/accent buttons, product cards, KPI cards, success screen. Do **not** apply to tables or forms (keeps data screens calm).
- Must be disabled under `prefers-reduced-motion`.

### 2.6 Motion system (Framer Motion)
| Token | Value |
|---|---|
| `duration.fast` | 120 ms |
| `duration.base` | 220 ms |
| `duration.slow` | 400 ms |
| `duration.hero` | 700 ms |
| `ease.standard` | `cubic-bezier(0.2, 0, 0, 1)` |
| `ease.emphasized` | `cubic-bezier(0.3, 0, 0, 1.2)` (slight overshoot, buttons/toasts) |
| `spring.soft` | stiffness 260, damping 26 |

Required animations:
1. **Page transition:** fade + 8 px upward slide, 220 ms; sidebar stays mounted.
2. **List/grid stagger:** items enter with 30 ms stagger (max 12 items animated, rest instant).
3. **Number count-up:** KPI numbers animate 600 ms on first load and on filter change.
4. **Button feedback:** press scale 0.97, release spring; loading state morphs label into spinner without width jump.
5. **Drawer/modal:** scale 0.98→1 + fade, backdrop blur 6 px. Right-side drawers slide in 320 ms.
6. **Cart:** line added = row slides in with orange flash; qty change = number flips; removal = collapse 180 ms.
7. **Skeletons:** navy-100 → navy-50 shimmer; never use spinners for page-level loads.
8. **Sale success:** animated checkmark draws in a circle (stroke-dashoffset, 600 ms), confetti-free; invoice number types in; "Download started" toast with progress.
9. **Toasts:** slide from bottom-right with `ease.emphasized`, auto-dismiss 4 s, pause on hover.
10. **Charts:** series draw-in 500 ms, tooltips fade 120 ms.
11. **Low stock badge:** gentle 2 s pulse (orange glow) — only on the badge.
12. Everything respects `prefers-reduced-motion` (replace with instant opacity change).

Performance rule: animate only `transform` and `opacity`; animations must not drop the POS terminal below 60 fps on a mid-range laptop (e.g., Core i3, 8 GB).

### 2.7 Premium component specs

**Buttons** (all 40 px default height; sizes sm 32 / md 40 / lg 48 / xl 56 for POS pay):
| Variant | Look | Text | Use |
|---|---|---|---|
| `primary` | Navy gradient `navy-800 → navy-900`, `shadow-md`, Mirror Shine on hover | White | Main actions (Save, Add) |
| `accent` | Orange gradient `orange-400 → orange-500`, `glow-orange` on hover, Mirror Shine | **Navy-900** | The single hero action per screen (Complete Sale, Pay) |
| `secondary` | White, 1 px navy-200 border, hover navy-50 | Navy-900 | Secondary actions |
| `ghost` | Transparent, hover navy-100 | Navy-700 | Tertiary, toolbar |
| `danger` | Red gradient, only for destructive | White | Void, delete (always behind confirm) |
| `icon` | 40 × 40 round, tooltip required | — | Toolbars |

States for every variant: default, hover, focus-visible (2 px `orange-300` ring + 2 px offset on dark; `navy-600` ring on light), pressed, loading, disabled (50 % opacity, `not-allowed`). One `accent` button per view at most.

**Inputs:** 44 px height in POS, 40 px elsewhere; floating-label style; focus = navy-600 border + soft glow; inline validation messages with 120 ms fade; numeric inputs have stepper buttons and select-on-focus; currency input shows `৳` prefix.

**Cards:** white, radius 12, `shadow-sm`, hover lift 2 px + `shadow-md` (interactive cards only).

**Tables:** sticky header (navy-900 bg, white text, 12 px uppercase tracking), zebra off, row hover navy-100, row height 52 px (comfortable) / 40 px (compact toggle), column visibility menu, sortable, sticky first column on horizontal scroll, bulk-select bar slides from top, empty state with illustration + action, server-side pagination (default 25).

**Badges/status chips:** soft backgrounds (`*-100`) with dark text from the 800 shade; stock: In stock (green), Low (orange pulse), Out (red).

**Sidebar:** navy-900, 264 px (72 px collapsed). Active item: left 3 px orange bar + navy-700 bg + white text; icons from `lucide-react`, 20 px. Group labels in `navy-300` 11 px uppercase. Collapse animates width 220 ms; tooltips when collapsed.

**Top bar:** white, 64 px, global search (`/` focuses), branch selector, shift status pill (green "Shift open"), notifications bell, user menu, language toggle (EN | বাং).

**Empty / error / loading states:** every list, chart, and form has all three, designed (not default).

### 2.8 Accessibility and responsive
- WCAG 2.1 AA minimum. Full keyboard use of POS. Visible focus. ARIA labels for icon buttons. Touch targets ≥ 44 px in POS.
- Desktop-first. Layout tuned for 1366×768 and 1920×1080. Tablet (≥ 768) fully usable for POS. Phone: read-only dashboards, stock lookup, and invoice lookup are responsive; full POS terminal is not required on phones.
- Light theme in Phase 1. Dark theme = Phase 2 (tokens already semantic to allow it).

---

## 3. Users and roles

### 3.1 Personas
| Persona | Role | Needs |
|---|---|---|
| **Owner (Admin)** | `super_admin` | Full control, profit and cash visibility, configuration |
| **Manager** | `manager` | Approve discounts, returns, stock adjustments; see reports |
| **Cashier / Sales** | `cashier` | Fast POS, customer lookup, take payments, print invoices |
| **Storekeeper** | `storekeeper` | Receive goods, count stock, transfers, damage entry, labels |
| **Accountant** | `accountant` | Ledgers, payments, expenses, reports, exports |

### 3.2 Permission matrix (Phase 1)
Legend: ● full · ◐ limited (see note) · ○ none

| Capability | super_admin | manager | cashier | storekeeper | accountant |
|---|---|---|---|---|---|
| Company profile, settings, roles, numbering | ● | ○ | ○ | ○ | ○ |
| Master data (makes, models, lookups, custom fields) | ● | ● | ○ | ◐ view | ○ |
| Create/edit products & variants, prices | ● | ● | ○ | ◐ no price | ○ |
| View cost price & margin | ● | ● | ○ | ○ | ● |
| POS sale | ● | ● | ● | ○ | ○ |
| Discount over cashier limit / below price floor | ● | ● | ○ (needs manager PIN) | ○ | ○ |
| Void invoice | ● | ● | ○ | ○ | ○ |
| Sales return / refund | ● | ● | ◐ create, manager approves | ○ | ○ |
| Purchases (PO, GRN), suppliers | ● | ● | ○ | ◐ GRN only | ◐ view |
| Stock adjust / transfer / count | ● | ● | ○ | ● (adjust needs manager approval above threshold) | ○ |
| Customer ledger view | ● | ● | ◐ own customer balance | ○ | ● |
| Receive due payment | ● | ● | ● | ○ | ● |
| Expenses, fund transfers | ● | ● | ○ | ○ | ● |
| Reports | ● | ● | ◐ own sales, own shift | ◐ stock reports | ● |
| Audit log | ● | ◐ | ○ | ○ | ◐ |
| Users & roles | ● | ○ | ○ | ○ | ○ |

Implementation: permission keys per module action stored in `role_permissions`; every API endpoint enforces them through Laravel Policies/permission middleware (the UI also hides what is not allowed, but UI hiding is never the security). Cost/margin fields are omitted from API responses unless the user has `products.view_cost`. Manager override = **PIN prompt** (4–6 digit PIN stored hashed in `users.override_pin_hash`), logged in `audit_logs`.


---

## 4. Domain model: Car Mirror product information sheet

A **Product** is a mirror *model family* (e.g., "Toyota Axio NZE141 Side Mirror"). A **Variant** is the sellable, stockable unit (specific position, size, glass, features, color). Stock, price, barcode, and SKU live on the **variant**. Everything below must be editable in the admin panel; every dropdown marked *(lookup)* is an admin-managed list (`lookup_types` / `lookup_values`) — admin can add, rename, reorder, deactivate values without a developer.

### 4.1 Product level (the mirror family)
| Field | Type | Req | Notes / examples |
|---|---|---|---|
| Product name (EN) | text | ✔ | "Side Mirror – Toyota Axio NZE141" |
| Product name (BN) | text | | For Bangla search and optional invoice |
| Product code / SKU prefix | text | ✔ | Auto-suggest, e.g., `SM-TOY-AXIO`; unique |
| Mirror type | enum | ✔ | `side_mirror`, `interior_mirror`, `mirror_glass`, `mirror_cover`, `mirror_signal_lamp`, `mirror_motor`, `blind_spot_mirror`, `accessory` |
| Category | tree | ✔ | Admin-managed hierarchy |
| Brand (manufacturer) | select | | Genuine/OEM brand or aftermarket brand (`product_brands`) |
| Quality grade *(lookup)* | select | ✔ | Genuine OEM, OEM-equivalent, Aftermarket premium, Aftermarket standard, Used/reconditioned |
| Country of origin *(lookup)* | select | | Japan, Taiwan, China, Thailand, India, Korea, Malaysia |
| Short description | text | | Shown on POS card |
| Long description | rich text | | Features, fitment notes |
| Warranty (months) | int | | 0 = none; drives invoice warranty text and warranty tracking |
| Return window (days) | int | | Default from settings |
| VAT/tax rate | select | | From `tax_rates` |
| Default unit | select | ✔ | pcs / pair / set / box (`units`) |
| Tags / search keywords | tags | | Alias spellings ("looking glass", "wing mirror", "side glass", "লুকিং গ্লাস") |
| Active | bool | ✔ | Inactive hidden from POS |
| Custom fields | dynamic | | Defined by admin in `custom_field_defs` (text, number, date, select, multi-select, yes/no, URL) |

### 4.2 Variant level (the sellable unit)
| Group | Field | Type | Notes |
|---|---|---|---|
| Identity | SKU | text ✔ unique | Auto-generated from prefix + attributes, editable |
| | Barcode | text unique | EAN-13/Code128; "Generate" button; extra barcodes stored in `barcode_aliases` (text array) |
| | Variant name | computed | "RH · Electric · Heated · 7×4 in" |
| Position | Position | enum ✔ | `left` (LH), `right` (RH) — both from the driver's seat view (see BR-091), `interior`, `pair`, `universal` |
| Size | Width, height, thickness | numeric mm | Stored in **mm**; display toggle mm / inch |
| | Size label | text | e.g., `7 × 4 in` or `180 × 110 mm`; auto from dims, overridable |
| | Weight | numeric g | |
| Glass | Glass type *(lookup)* | select | Flat, Convex, Aspheric (multi-radius), Anti-glare, Wide-angle |
| | Tint / coating *(lookup)* | select | Clear chrome, Blue tint, Anti-glare blue, Hydrophobic |
| | Glass only (no housing) | bool | |
| Housing | Color / finish *(lookup)* | select | Black, Chrome, Silver, Body-color/painted, Carbon-look, Unpainted/primed |
| | Cover/cap included | bool | |
| Features | Adjustment | enum | `manual`, `electric`, `cable` |
| | Heated glass | bool | |
| | Auto-fold (power fold) | bool | |
| | Turn-signal lamp | bool | |
| | Blind-spot sensor (BSM) | bool | |
| | Side camera | bool | |
| | Memory / auto-tilt in reverse | bool | |
| | Auto-dimming (interior/side) | bool | |
| | Puddle / ground light | bool | |
| | Wire/pin count | int | 3, 5, 7, 9, 12 … |
| | Connector/plug note | text | Photo allowed in images |
| Identification | OEM part number(s) | list | Stored in `oem_cross_references` |
| | Aftermarket / competitor cross-refs | list | Same table, `ref_type` |
| | Supplier part number | text | |
| | Model year range of this variant | range | Detailed fitment is in 4.3 |
| Pricing | Cost price (last / average) | money | Average maintained by system; visible only with permission |
| | Retail price | money ✔ | |
| | Wholesale price | money | Used when customer type = wholesale |
| | Dealer / garage price | money | Used when customer type = dealer/garage |
| | MRP (printed) | money | Optional |
| | Minimum selling price (floor) | money | Below this requires manager PIN |
| | Tax override | select | Else product tax rate |
| Stock control | Reorder level | numeric | Low-stock alert threshold |
| | Reorder quantity | numeric | Suggested PO quantity |
| | Max stock | numeric | Over-stock warning |
| | Default rack/shelf location | select | `storage_locations` |
| | Track serial/batch | bool | Phase 2 (field exists, UI later) |
| Media | Images | up to 8 | Primary + gallery; also front/back/connector/package; drag-sort; alt text |
| Notes | Internal note | text | Never printed |
| Status | Active, discontinued flag | bool | |
| Custom | Variant custom fields | dynamic | Admin-defined |

**Bundles / pairs:** a variant with `is_bundle = true` (e.g., "Pair LH+RH") lists components in `variant_bundle_items`. Selling a bundle decrements each component's stock; bundles themselves hold no stock.

### 4.3 Vehicle fitment (compatibility)
Rows in `vehicle_fitments`: variant → vehicle model with `year_from`, `year_to`, optional `trim`/`notes` ("with BSM only", "Hybrid only"). Vehicle data:
- `vehicle_makes`: Toyota, Honda, Nissan, Mitsubishi, Suzuki, Hyundai, Kia, Mazda, Subaru, Daihatsu, Ford, Isuzu, Tata, Mahindra, BMW, Mercedes-Benz, Audi, Lexus, MG, Proton… (starter seed, fully editable).
- `vehicle_models`: name, make, body type, generation/chassis code (e.g., NZE141), production year range.
- The **Vehicle Finder** in POS filters variants through this table.
- Bulk import by CSV for fitment (template provided in app).

### 4.4 Product images
- Upload via drag-drop or camera (mobile/tablet). Accept JPG/PNG/WebP up to 8 MB; the server (PHP GD) resizes to max 2000 px long edge and generates a 400 px thumbnail; files are stored under `public/uploads/products/<yyyy>/<mm>` with random names (public read, role-restricted write, PHP execution disabled in the uploads folder).
- Primary image appears on POS card, search results, stock lists. Gallery in product detail and optional on quotations.
- Image labels (`front`, `back`, `connector`, `package`, `size-chart`, `other`).

---

## 5. Functional requirements

### 5.1 Foundation and administration (FR-100)
- **FR-101** Email + password login (Laravel Sanctum cookie session, CSRF-protected, passwords hashed); password reset by email (hosting SMTP); session timeout configurable (default 8 h); optional idle lock screen (PIN) after N minutes for POS.
- **FR-102** Roles per §3; role-permission matrix editable by `super_admin` (UI grid of module × action).
- **FR-103** User management: create/disable users, assign role and branch(es), reset password, set override PIN.
- **FR-104** **Company Profile** (drives invoices): legal name, trade name, tagline, logo upload, address lines, city, district, postcode, country, phone(s), WhatsApp, email, website, BIN/VAT reg no., TIN, trade license no., invoice bank details (bank, branch, account name/no. shown in footer), authorized signature image, default invoice terms, return/warranty policy text, footer note. Invoice generation is blocked with a clear message if required fields (name, address, phone) are empty.
- **FR-105** Branches, warehouses (showroom, godown), and storage locations (rack/shelf/bin) CRUD.
- **FR-106** Number sequences: configurable prefix, date token, padding, reset policy (yearly/monthly/never) for invoice, quotation, PO, GRN, adjustment, transfer, return, payment, expense. Default invoice: `ORB-INV-YYMM-000001`. Gap-free, concurrency-safe.
- **FR-107** App settings: currency format, rounding rule, VAT mode (inclusive/exclusive), cashier max discount %, allow negative stock (default off), require customer for due sales (default on), manager-PIN thresholds, default return window, low-stock notification channel, date/time format, language.
- **FR-108** Audit log: who/what/when/before/after for all create/update/delete/void/price-change/stock-adjust/permission-change; filter and export.
- **FR-109** Notifications: in-app (bell) for low stock, pending approvals, overdue dues, shift not closed.
- **FR-110** Backup/export: admin can export any table as CSV/XLSX (streamed, memory-safe); a **nightly database backup** (gzip dump) is written outside the web root by a scheduled command and listed on **Admin → Backups** with download; restore steps in `docs/restore-runbook.md`; the owner also enables the hosting provider's backups and keeps an off-site copy.
- **FR-111** **Web installer** (only for hosts without SSH/Terminal): reachable at `/install` only while `storage/installed.lock` is absent and only with the one-time `INSTALL_KEY` from `.env`; checks requirements, tests the DB connection, runs migrations and seeders, creates the first `super_admin`, writes the lock file, and disables itself.
- **FR-112** **Hosting health page** (`super_admin`): PHP version/extensions/limits, DB version, writable folders, `.env` safety, HTTPS, cron heartbeat (last scheduler run), last backup time, queue status, disk free, app version. Actions (each behind a confirm dialog and audited): backup now, maintenance mode on/off, run pending migrations (for hosts without SSH), clear/rebuild caches.
- **FR-113** **Integrity check** (`integrity:check` command nightly + admin page): verifies stock levels = Σ movements, account balances = opening + Σ in − Σ out, party balances = ledger sums, sale totals = paid + due, orphan rows; failures create critical notifications.

### 5.2 Master data and custom fields (FR-200)
- **FR-201** Admin CRUD with search, sort, activate/deactivate, drag-reorder for: vehicle makes, vehicle models, product brands, categories (tree), units, tax rates, lookup lists, expense categories.
- **FR-202** **Lookup manager:** admin can create new lookup *types* (list names) and values; system-defined types (origin, quality grade, glass type, tint, color/finish, return reason, adjustment reason, customer type, expense payee type) are pre-seeded and cannot be deleted, only extended.
- **FR-203** **Custom field builder:** admin defines extra fields for `product`, `variant`, `customer`, `supplier`; field types: text, long text, number, date, select, multi-select, yes/no, URL; flags: required, show on POS card, show on invoice, searchable. Values stored in a `custom` JSON column and rendered dynamically in forms and detail views.
- **FR-204** Bulk import (CSV/XLSX) with template download, dry-run validation preview (row-level errors), and commit; supported for makes, models, products+variants, fitments, customers, suppliers, opening stock, opening balances.
- **FR-205** Soft delete everywhere; hard delete only by `super_admin` for records with no transactions.

### 5.3 Product catalog (FR-300)
- **FR-301** Product list: grid and table toggle; image, name, type, brand, variant count, total stock, price range, status; filters (type, brand, category, quality, origin, glass type, position, make/model, stock status, active); saved filter presets.
- **FR-302** Product editor as a stepper with autosave drafts: (1) Basic · (2) Variants & Sizes · (3) Images · (4) Fitment · (5) Pricing · (6) Stock control · (7) Custom fields · (8) Review & publish.
- **FR-303** **Variant matrix generator:** choose positions × sizes × colors × feature sets → generates variants with auto SKUs; admin edits individually afterward. Duplicate-variant guard.
- **FR-304** Clone product/variant. Bulk price update (percentage or fixed, by filter) with preview and audit entry. Price history is kept in `audit_logs` (old/new value, user, time) and shown on the variant.
- **FR-305** Barcode generation and **label printing** (A4 sheet or 50×25 mm roll): barcode, SKU, short name, size, price; template selectable.
- **FR-306** Fitment editor with typeahead (make → model → year range); bulk fitment copy ("same as variant X").
- **FR-307** Cross-reference search: searching an OEM number returns matching variants.
- **FR-308** Deactivating a product with stock warns and requires confirmation.

### 5.4 Inventory (FR-400) — Advanced
- **FR-401** **Stock levels** per variant × warehouse × location with `on_hand`, `reserved` (held quotations optional), `available`, `avg_cost`, stock value. Single source of truth = `stock_movements` (append-only); `stock_levels` is maintained by the database in the same transaction.
- **FR-402** Movement types: `opening`, `purchase_receipt`, `purchase_return`, `sale`, `sale_return`, `adjustment_in`, `adjustment_out`, `transfer_out`, `transfer_in`, `breakage`, `damage`, `count_correction`. Each movement stores qty delta, unit cost, balance after, reference document, user, timestamp.
- **FR-403** **Stock adjustments** with mandatory reason (admin-managed list: breakage, damaged in transit, lost/theft, found, recount, sample, other), optional photo attachment, approval above threshold quantity/value, posts movements on approval.
- **FR-404** **Breakage/damage register:** glass breakage is frequent. A dedicated view lists breakage by product, supplier, staff, and period, with cost impact; can link a breakage to a supplier claim.
- **FR-405** **Stock transfer** between warehouses/branches: draft → in transit → received (with received-qty discrepancy handling).
- **FR-406** **Physical stock count:** create a count session (full, by category, by location), print count sheet, enter counted qty (blind count option), review variances, approve → posts `count_correction` movements.
- **FR-407** Alerts: low stock (≤ reorder level), out of stock, overstock, dead stock (no sale in N days), negative stock attempt. Dashboard widgets and notification entries.
- **FR-408** **Reorder suggestions:** list below reorder level with suggested quantity (`reorder_qty`) grouped by supplier; one-click "Create PO draft".
- **FR-409** Valuation: weighted-average cost by default; stock valuation report as-of date (uses movement history). Landed cost (see FR-503) is part of cost.
- **FR-410** Stock card per variant: chronological movements with running balance, filters, export.
- **FR-411** Stock aging buckets: 0–30, 31–60, 61–90, 91–180, 180+ days.
- **FR-412** Negative stock blocked by default (database check); `allow_negative_stock` setting + permission can relax it.
- **FR-413** Opening stock import with cost per variant per warehouse.

### 5.5 Purchasing (FR-500)
- **FR-501** Supplier CRUD: code, name, contact person, phones, email, address, country, type (local/import), payment terms (days), credit limit, opening balance, bank/MFS details, notes, custom fields.
- **FR-502** Purchase order: draft → approved → partially received → received → closed/cancelled; items by variant with qty, unit cost, tax; PDF export of PO (same branding as invoice); email/WhatsApp share link.
- **FR-503** **Goods receipt (GRN)** against a PO or standalone: received qty, damaged qty on arrival (auto-creates damage movement), batch of **extra costs** (freight, customs duty, clearing & forwarding, local transport, other) allocated to lines **by value or by quantity** (admin chooses per GRN) → landed unit cost. Posting creates stock movements and a supplier ledger entry.
- **FR-504** Purchase return to supplier: reason, items, posts movement out + supplier ledger credit.
- **FR-505** Supplier payments (cash/bKash/Nagad/Rocket/bank) with allocation to open bills; advance payments supported.
- **FR-506** Supplier statement (PDF/XLSX), supplier due aging.
- **FR-507** Price comparison: last 5 purchase costs per variant per supplier shown in PO line.

### 5.6 Customers (FR-600)
- **FR-601** Customer CRUD: auto code (`CUS-000001`), type (*Retail, Wholesale, Garage/Workshop, Dealer, Corporate* — editable lookup), name, company name, phone(s) (primary required, validated BD format `01XXXXXXXXX`), WhatsApp, email, address line(s), area, district, postcode, NID/trade license (optional), BIN (optional for VAT invoice), credit limit, opening balance, price tier, notes, custom fields, photo/attachment.
- **FR-602** Customer vehicles: registration no., make, model, year, color, notes; selectable at POS so the invoice shows the vehicle.
- **FR-603** **Quick-add** customer inside POS (name + phone minimum). Walk-in customer is a built-in record; walk-in cannot take a due sale (BR-040).
- **FR-604** Customer 360 page: profile, vehicles, invoices, payments, ledger, due, advance, lifetime value, last purchase, top products; tabs with animated transitions.
- **FR-605** Duplicate detection by phone on create (warn + link to existing).
- **FR-606** Credit limit enforcement at POS (warn/block per setting).

### 5.7 POS terminal and sales (FR-700)
See §6 for the full flow. Requirements:
- **FR-701** Full-screen **POS Terminal** with three zones: Finder/Catalog (left), Cart (right), Action bar (bottom). Works with mouse, touch, keyboard, USB barcode scanner (keyboard-wedge).
- **FR-702** **Vehicle Finder** wizard strip: Make → Model → Year → Position (L/R/Interior/Pair) with chips and typeahead; result grid shows only fitting variants, sorted by availability then price; "Show all sizes" expands size options per product card.
- **FR-703** Search box: text (name, SKU, barcode, OEM number, tags, Bangla), instant results (debounced 150 ms), scanning a barcode adds the item immediately.
- **FR-704** **Size selector on card:** product card lists size chips (e.g., `7×4`, `8×5`) with stock count per size; tapping a chip adds that variant. Out-of-stock chips are visibly disabled but show "notify/transfer" info.
- **FR-705** Cart: qty stepper, unit price (price tier auto from customer type), line discount (% or ৳), line note, remove, undo; cart-level discount; delivery/handling charge; VAT shown per settings; running totals in a sticky summary.
- **FR-706** Customer selector with quick-add; vehicle selector (from customer vehicles or one-off entry); sale channel (counter, phone, wholesale, online-order); salesperson (defaults to logged-in user); invoice note.
- **FR-707** **Hold / resume** bills (multiple parked carts, named by customer/time; survive reload; stored server-side).
- **FR-708** Quotation: convert cart to quotation (PDF, validity date), later convert quotation → invoice with stock check.
- **FR-709** Price override per line requires permission; below floor price requires manager PIN (BR-020).
- **FR-710** **Payment drawer** (FR-800) opens on "Pay" (F9).
- **FR-711** On confirm: one atomic service call `SaleService::complete` (one database transaction, `POST /api/v1/sales/checkout`) validates stock, prices, discount limits, credit limit; creates invoice, items, payments, stock movements, ledger entries, account transactions; returns invoice snapshot.
- **FR-712** After success: success screen + **automatic PDF invoice download** (FR-900) + options (Download again, Print A4, Print 80 mm receipt, WhatsApp share link, New sale, View invoice).
- **FR-713** Sales list/detail: filters (date, customer, cashier, status, payment method, due), invoice preview, re-download PDF, reprint (reprint counter on audit), void (permission + reason), return/exchange button.
- **FR-714** Void: only same-day by manager or any day by super_admin; reverses stock, payments, ledger, and account transactions; invoice remains visible as **VOID** with watermark.
- **FR-715** Sales return/exchange: select invoice and lines, qty, reason, condition (resellable / broken), refund method or credit-to-ledger; creates credit note PDF; resellable returns increase stock, broken ones go to `breakage`.
- **FR-716** Cash shift: open (opening float) → sales and cash movements → close (counted cash vs expected, variance, notes) → Z-report PDF. Sales require an open shift (setting).
- **FR-717** Warranty: each sold line stores `warranty_until`; **Warranty claim** record (invoice line, issue, photo, status, resolution: replace/refund/reject, links to stock movement).

### 5.8 Payments (FR-800)
Payment methods (exactly five): **Cash, bKash, Nagad, Rocket, Bank Transfer**.
- **FR-801** Payment drawer supports **split payments** (e.g., 5,000 cash + 3,500 bKash). Remaining amount is shown live; "Fill remaining" button per method.
- **FR-802** Per-method fields:
  - Cash: tendered amount → **change due** computed; drawer-style large keypad.
  - bKash / Nagad / Rocket: sender number (BD format), **TrxID** (required, unique per method/day warning), received-into account (select from `financial_accounts`, e.g., "bKash Merchant 01XXXXXXXXX"), optional screenshot attach.
  - Bank Transfer: bank name, account/ref number, transaction/cheque reference (required), received-into bank account, transfer date, optional slip attach.
- **FR-803** Payment status auto-derived: `paid`, `partial`, `unpaid`. Partial/unpaid creates a **due** on the customer ledger (requires a named customer).
- **FR-804** Overpayment on non-cash methods is blocked; for cash it becomes change. Optional "keep as customer advance" toggle.
- **FR-805** Each payment posts an `account_transactions` row to the selected financial account (cash drawer, bKash, Nagad, Rocket, bank).
- **FR-806** Payment verification flag: MFS/bank payments can be marked *pending verification* and later *confirmed* by accountant/manager (setting). Pending amounts show on the dashboard.
- **FR-807** Receive due later (FR-1002) with the same method forms and allocation to invoices.
- **FR-808** Phase 2 only: live API verification of bKash/Nagad/Rocket.

### 5.9 Invoice PDF (FR-900)
Detailed layout in §7. Requirements:
- **FR-901** PDF generated **immediately on sale completion** and **downloaded automatically** by the browser as `Invoice_<InvoiceNo>_<CustomerName>.pdf`; the PDF is generated **on the server** (mPDF) from the stored snapshot, saved in the private `invoices` folder (outside the public web root, served only through an authenticated endpoint or short-lived signed URL) and linked on the sale (`pdf_path`); the browser fetches it and saves it automatically.
- **FR-902** Uses an **immutable snapshot** of company details, customer details, vehicle, and line descriptions captured at sale time. Later edits to company/customer/product never change old invoices.
- **FR-903** Contains the **original Orbin Global logo**, full company details, full client details, itemized lines with variant specs, totals, payment breakdown, due, amount in words, QR verification, terms, signatures, footer tagline.
- **FR-904** Re-download any time from sale detail; reprints are logged.
- **FR-905** Variants: A4 (default), 80 mm thermal receipt (compact), Credit Note, Quotation, Purchase Order, Payment Receipt, Customer Statement, Supplier Statement, Z-report — all share one branded layout system.
- **FR-906** Public verification page (`/verify/:token`) shows invoice number, date, total, paid status only (no personal data), reachable from the QR.

### 5.10 Ledger and finance (FR-1000) — "Laser/Ledger"
- **FR-1001** **Party ledger** for every customer and supplier: chronological entries (opening, invoice, payment, return, discount, adjustment, write-off), debit/credit, running balance, as-of filter, print/export statement. Balance = what the party owes us (customer) or what we owe (supplier).
- **FR-1002** **Receive customer payment** (against due invoices or on account) / **make supplier payment**; allocation UI (auto-oldest-first or manual); receipt PDF.
- **FR-1003** **Financial accounts:** cash drawer(s), bKash, Nagad, Rocket (each with number/label), bank accounts. Each has opening balance, current balance, and a ledger (all `account_transactions`).
- **FR-1004** **Fund transfers** between accounts (e.g., bKash → bank, cash → bank deposit) with charges as an expense line.
- **FR-1005** **Expenses:** category (admin-managed), amount, account paid from, date, payee, note, attachment, approval (setting). Recurring expense templates (rent, salary) = Phase 2 reminders.
- **FR-1006** **Day book:** all money in/out for a date range across accounts, with opening/closing balance per account.
- **FR-1007** **Dues dashboard:** receivable and payable lists with aging buckets (0–15, 16–30, 31–60, 61–90, 90+ days), promise-to-pay date and note per party, "send reminder" copy-text for WhatsApp/SMS (manual in Phase 1).
- **FR-1008** Write-off/discount on due with reason and permission, posted as a ledger entry.
- **FR-1009** Period lock: owner can lock transactions up to a date; locked periods reject edits/voids.

### 5.11 Reports and dashboard (FR-1100)
All reports: date-range + branch filters, compare-to-previous-period toggle, export CSV/XLSX/PDF, print view, drill-down to documents, saved presets. Full catalog in JSON `reports`. Highlights:
- **Sales:** daily/monthly summary, by product/variant/size, by brand, by vehicle make/model (demand insight), by category, by cashier, by customer, by payment method, hourly heatmap, discount report, returns report, void report.
- **Profit:** gross profit by invoice/product/category/period using cost snapshot on each sale line; margin %; net profit after expenses.
- **Inventory:** current stock, valuation as-of, low/out/over stock, dead stock, aging, stock card, movement summary, breakage report, adjustment report, count variance report, reorder list.
- **Purchase:** by supplier/product/period, landed cost analysis, pending PO, GRN report, supplier price trend.
- **Finance:** customer dues/aging, supplier dues/aging, cash/MFS/bank balances, day book, expense report, fund transfers, cashier shift/Z reports, payment-method split.
- **Tax:** VAT collected/paid summary by period (summary only).
- **Audit:** price changes, voids, overrides, stock adjustments, login history.
- **Dashboard (role-aware):** today's sales, orders, average ticket, gross profit, collections by method, dues, low-stock count, stock value, top 10 products, sales trend (7/30/90 days), top vehicle models, cash/bKash/Nagad/Rocket/bank balances, pending approvals. KPI cards animate count-up; charts use brand palette (navy primary series, orange highlight series).


---

## 6. POS sale flow (end to end)

```
Open shift ──► Find item ──► Add to cart ──► Customer & vehicle ──► Review ──► Pay ──► Complete ──► Invoice PDF auto-download
```

1. **Open shift** (if required): enter opening cash float. Top bar shows "Shift open · since 10:02".
2. **Find the mirror** (any combination):
   a. Vehicle Finder: *Toyota → Axio → 2015 → Right* → grid of fitting variants.
   b. Scan barcode → added instantly with a soft beep and orange row flash.
   c. Type in search: name / SKU / OEM no. / tag. Results update as you type.
3. **Pick size:** product card shows size chips with live stock. Tap chip → variant added (qty 1). Same chip again = qty +1.
4. **Cart adjustments:** qty, discount, note. Price tier follows customer type. Warnings inline (low stock, below-floor price, over discount limit).
5. **Customer & vehicle:** search by name/phone/code or quick-add; pick vehicle or type registration/make/model/year. Walk-in allowed for fully-paid sales.
6. **Review:** sticky summary — subtotal, discount, VAT, delivery, round-off, grand total.
7. **Pay (F9):** payment drawer slides in. Add one or more payment lines (Cash, bKash, Nagad, Rocket, Bank Transfer) with required references. Remaining/Change shown live. "Complete Sale" (accent button) enables only when payments are valid (fully paid, or partial with a named customer).
8. **Complete:** server validates and commits atomically. UI shows the **success screen**; the **PDF invoice downloads automatically**; a toast confirms. Buttons: Download again · Print A4 · Print receipt (80 mm) · Share on WhatsApp · View invoice · **New sale** (primary).
9. **Close shift** at end of day: counted cash vs expected → Z-report PDF.

Failure handling: if the network drops during completion, the UI shows "Not completed — nothing was saved" (no partial sales exist because the checkout transaction is atomic), keeps the cart, and offers Retry. Double-submit is prevented with an idempotency key per checkout.

### 6.1 POS keyboard shortcuts
| Key | Action | Key | Action |
|---|---|---|---|
| `/` or `F2` | Focus search | `F8` | Cart discount |
| `F3` | Vehicle Finder | `F9` | Pay |
| `F4` | Customer | `F10` | Hold bill |
| `F6` | Delivery charge | `F11` | Resume held bills |
| `+` / `-` | Qty on selected line | `Del` | Remove selected line |
| `Ctrl+Enter` | Complete sale (in pay drawer) | `Esc` | Close drawer/dialog |
| `Ctrl+P` | Reprint last invoice | `Alt+N` | New sale |

---

## 7. Invoice PDF specification

**Page:** A4 portrait, margins 14 mm; fonts embedded (Plus Jakarta Sans for headings/numbers, Inter for body; Noto Sans Bengali when Bangla text is present). Max 1 logo, original file, full resolution.

### 7.1 Layout (top to bottom)
1. **Header band (white background):**
   - Left: **Original Orbin Global logo**, 48 mm wide.
   - Right (right-aligned): company legal name, address, city/district/postcode, phone(s), WhatsApp, email, website, **BIN/VAT reg. no., TIN, trade license no.** (only non-empty items print).
   - A 1.2 mm **orange-500** rule under the header with a short navy segment at left (brand echo of the logo's slash).
2. **Title row:** "INVOICE" (large, navy-900, Plus Jakarta Sans 800) with a status pill (PAID / PARTIAL / DUE) — pill uses soft `*-100` bg with dark text. Right: QR code (verification URL).
3. **Meta block (two columns):**
   - Invoice no., invoice date & time, due date (if any), sale channel, branch, cashier/salesperson, shift no.
   - Payment status, payment method summary.
4. **Bill To (left card, navy-50 bg):** customer code, name, company, phone(s), email, **full address**, BIN/NID (if present). **Vehicle card (right):** registration no., make, model, year, color (only if provided).
5. **Items table** — header navy-900/white text, thin navy-100 row dividers:
   | SL | Item (bold name; second line small: *position · size · glass · color · key features · SKU*) | Warranty | Qty | Unit price | Disc. | VAT | Amount |
   - Long names wrap; rows never split across pages; header repeats on every page.
   - Bundle lines show component list in small text.
6. **Totals (right-aligned box):** Subtotal · Discount · Taxable amount · VAT · Delivery/handling · Round-off · **Grand total** (navy-900 bar, white text, 14 pt) · Paid · **Due** (orange-700 text on orange-50 bg if > 0).
7. **Amount in words** (left, italic): "Taka Twelve Thousand Five Hundred Only" (lakh/crore aware).
8. **Payment breakdown table:** method · reference (TrxID / bank ref) · received into · date/time · amount.
9. **Terms & warranty block:** return window, warranty rules, "glass breakage in transit not covered after delivery" style text — all taken from settings and product warranty fields.
10. **Bank details** (optional, from company profile) for due payments.
11. **Signatures:** "Customer's signature" and "Authorized signature" (signature image optional) with lines.
12. **Footer (every page):** tagline *Trust. Growth. Excellence.* (italic), thank-you line, "Page x of y", "Generated by Orbin POS", invoice number.

### 7.2 Rules
- Money: `৳ 12,500.00` format; numbers right-aligned; tabular figures.
- **VOID** invoices print a diagonal red "VOID" watermark; **PAID** shows a subtle navy-50 watermark (≤ 6 % opacity).
- Long invoices paginate cleanly; totals and signatures never orphan onto a blank page (keep-together).
- File naming and storage per FR-901. Metadata title = invoice number.
- **80 mm receipt variant:** logo (emblem + wordmark, 40 mm), company short block, items as 2-line rows, totals, payment lines, QR, tagline.
- Credit note, quotation, PO, receipt, statements reuse the same header/footer components with their own titles.

### 7.3 Amount in words
Implement `bdtToWords(amount)` supporting lakh/crore, paisa ("and Fifty Paisa"), zero, large values; unit-tested with edge cases (0, 1, 100, 1 000, 99 999, 1 00 000, 1 23 45 678.50).

---

## 8. Screen-by-screen UI specification

All screens: navy sidebar, white top bar, `navy-50` page background, page header (title + breadcrumb + primary action), animated route transition, skeleton loading, designed empty states, and keyboard focus order. "Premium" means consistent spacing, restrained color, purposeful animation, and zero visual clutter.

### 8.1 Login
Split layout. Left 55 %: navy-950 → navy-900 gradient with slow-moving soft light beams, the original logo on a white rounded plate with ambient gloss pass, tagline below. Right: clean white form (email, password with reveal toggle, "Remember me", accent "Sign in" button), subtle error shake on failure. Footer: © Orbin Global.

### 8.2 Dashboard
Row 1: 5 KPI cards (Today's Sales, Orders, Gross Profit, Collections, Dues) with count-up, delta vs yesterday (green/red arrow), mini sparkline, Mirror Shine on hover. Row 2: Sales trend (area, navy line, orange highlight for today) + Payment-method donut (navy/orange/blues). Row 3: Top products (horizontal bars) · Top vehicle models · Low stock list (pulse badges, "Create PO" link). Row 4: Account balances strip (Cash · bKash · Nagad · Rocket · Bank) · Pending approvals · Recent sales table. Date-range segmented control (Today · 7D · 30D · Month · Custom). Role-aware widgets.

### 8.3 POS Terminal (hero screen)
- **Left (62 %)**
  - Sticky **Vehicle Finder strip**: four pill dropdowns (Make · Model · Year · Position) with typeahead; "Clear" ghost button; selected values become removable chips.
  - Search bar (large, 48 px) with scan icon, quick filters (Type, Brand, Quality, In stock only).
  - **Product grid** (responsive 3–5 columns): card = image (4:3, tilt on hover), name, brand + quality badge, feature icons (electric, heated, camera…), **size chips with stock**, price (from–to), stock badge. Click chip → add; click card body → quick-view modal with gallery, specs, fitment, cross-refs.
  - Infinite scroll with skeletons; empty state "No mirror fits this car — try another year or search by OEM number" with "Request/Notify" action.
- **Right (38 %) — Cart panel (white card, `shadow-lg`)**
  - Header: customer chip (tap to change) + vehicle chip + held-bills counter.
  - Lines: thumbnail, name, variant spec line, qty stepper, unit price, discount chip, line total; swipe/delete icon; line note.
  - Summary: subtotal, discount (F8), VAT, delivery (F6), round-off, **Grand total** (navy bar, white, big tabular numbers with count animation).
  - Bottom: ghost "Hold" · secondary "Quotation" · **accent XL "Pay ৳ 12,500" (F9)** with Mirror Shine.
- **Payment drawer (right, 480 px):** method tiles (Cash · bKash · Nagad · Rocket · Bank) with brand-neutral icons (do not copy third-party logos; use text + simple glyph), selected tile = navy border + orange indicator. Active method form below. Payment lines list with remove. Totals: To pay · Paid · Remaining/Change. Customer advance toggle. "Complete Sale" accent XL button with loading morph.
- **Success screen (full overlay):** animated check, invoice number, amount, change due (if cash), PDF download progress toast, action buttons, auto-reset timer (optional) to new sale.

### 8.4 Sales
List: filters bar (date, status, method, customer, cashier, due-only), summary strip (count, total, paid, due), table with status chips, quick actions (view, PDF, return, void). Detail: invoice preview left, timeline right (created, payments, returns, reprints, void), action bar. Returns: 3-step wizard (pick lines → condition & reason → refund method) with credit-note PDF.

### 8.5 Products
Toolbar with view toggle, filter drawer, bulk actions, "Add product" primary. Grid card shows primary image, variant count, stock bar. **Editor** (stepper with progress rail): each step is a card with live validation; Variants step has a **matrix generator** modal and an editable spreadsheet-like grid (inline edit price/stock control); Images step has drag-drop with reorder and primary star; Fitment step has make/model/year builder with chips; Review step shows a POS-card preview. Autosave indicator ("Saved 2 s ago").

### 8.6 Inventory
Tabs: **Overview** (stock table with value, alerts, location) · **Movements** (stock ledger) · **Adjustments** · **Breakage** · **Transfers** · **Counts** · **Reorder** · **Aging**. Overview has KPI strip (SKUs, units, value, low, out). Each document screen uses the same pattern: list → document page (header form + lines grid + status timeline + approve/post actions).

### 8.7 Purchases
Suppliers (cards/table), Purchase Orders, Goods Receipts (with landed-cost allocation panel showing per-line landed cost live), Purchase Returns, Supplier Payments. PO builder has variant search with last-cost hints and reorder-suggestion import.

### 8.8 Customers
Table + side-peek drawer on row click; full 360 page with tabs (Overview · Invoices · Ledger · Payments · Vehicles · Notes). Receive-payment button opens payment drawer identical to POS.

### 8.9 Ledger & Finance
Tabs: **Party Ledger** (select party → statement with running balance, print/export) · **Accounts** (cash/bKash/Nagad/Rocket/bank cards with balance + mini ledger) · **Receive/Pay** · **Expenses** · **Fund Transfers** · **Day Book** · **Dues & Aging**. Account cards use brand palette with method glyphs; balances count-up.

### 8.10 Reports hub
Gallery of report cards grouped by domain with search; each report page = filter bar, summary tiles, chart (when useful), table, export menu. Drill-down links on every document number.

### 8.11 Settings (admin panel)
Left sub-nav: **Company Profile** (live **invoice preview** beside the form) · Branches & Warehouses · Users & Roles (permission grid) · **Master Data** (Vehicle makes/models, Brands, Categories, Units, Tax rates, **Lookup lists**, **Custom fields**) · Payment Accounts · Numbering · POS & Sales rules · Invoice & Print · Notifications · Import/Export · Audit Log · System info. Every list in Master Data has inline add, drag-reorder, activate toggle, and bulk import.

### 8.12 Shift / Cash register
Open-shift modal (float). During shift: cash-in/cash-out entries (reason required). Close-shift screen: expected vs counted by denomination (৳ 1000/500/200/100/50/20/10/5/2/1 counters), variance highlight, notes, Z-report PDF.

### 8.13 Shared patterns
Global search (`Ctrl+K`) across products, customers, invoices, suppliers; confirm dialogs for destructive actions with typed confirmation for void/delete; unsaved-changes guard; undo toast for removals; consistent date pickers (BD week start Saturday, configurable); toast system; error boundary with friendly page and "copy error ID".


---

## 9. Data model overview

Full column-level definitions are in `orbin-pos-spec.json → dataModel`. This section lists the tables by domain so the PRD and JSON stay aligned. All tables have `id` (BIGINT auto-increment), `created_at`, `created_by` (and `updated_at/updated_by`, `deleted_at` unless append-only) — see JSON `commonColumns`. Every table has `branch_id` where branch-scoped; access is enforced by the API layer (Policies + permission middleware), not by the database. Laravel framework tables (`migrations`, `sessions`, `jobs`, `password_reset_tokens`, `cache`, `failed_jobs`) exist in addition.

| Domain | Tables |
|---|---|
| System & admin | `company_profile`, `branches`, `warehouses`, `storage_locations`, `users`, `role_permissions`, `app_settings`, `number_sequences`, `audit_logs`, `notifications`, `attachments`, `lookup_types`, `lookup_values`, `custom_field_defs` |
| Catalog | `vehicle_makes`, `vehicle_models`, `product_brands`, `categories`, `units`, `tax_rates`, `products`, `product_variants`, `product_images`, `oem_cross_references`, `vehicle_fitments`, `variant_bundle_items` |
| Inventory | `stock_levels`, `stock_movements`, `stock_adjustments`, `stock_adjustment_items`, `stock_transfers`, `stock_transfer_items`, `stock_counts`, `stock_count_items` |
| Purchasing | `suppliers`, `purchase_orders`, `purchase_order_items`, `goods_receipts`, `goods_receipt_items`, `purchase_extra_costs`, `purchase_returns`, `purchase_return_items` |
| Sales | `customers`, `customer_vehicles`, `quotations`, `quotation_items`, `sales`, `sale_items`, `sale_payments`, `sale_returns`, `sale_return_items`, `warranty_claims`, `cash_shifts` |
| Finance | `financial_accounts`, `account_transactions`, `party_ledger_entries`, `party_payments`, `party_payment_allocations`, `expense_categories`, `expenses`, `fund_transfers` |

### 9.1 Transactional services (PHP) — all atomic
`NumberService::next`, `VariantSearchService::search`, `SaleService::complete`, `SaleService::void`, `SaleReturnService::create/post`, `GoodsReceiptService::post`, `StockAdjustmentService::post`, `StockTransferService::post`, `StockCountService::post`, `PartyPaymentService::record`, `ShiftService::open/close`, `PeriodLockService::lock`. Contracts (inputs, steps, outputs, errors, endpoints) are in JSON `transactionalServices`. Money and stock math for finalization happens **only** inside these services (one DB transaction, `SELECT … FOR UPDATE` in id order, deadlock retry), never in the browser. **No stored procedures, triggers, or events** are used because shared cPanel hosts usually forbid them.

### 9.2 Key queries
`v_stock_summary`, `v_party_balance`, `v_day_book`, `v_sales_profit`, `v_account_balance`, `v_low_stock` (and aging queries) are implemented as repository queries; a MySQL VIEW may be used only if the host allows it. Definitions in JSON `views`.

### 9.3 File storage (plain folders on the hosting account)
`public/uploads/brand` (public), `public/uploads/products` (public), `storage/app/private/invoices` (private), `storage/app/private/attachments` (private), `~/backups/orbin-pos` (private, outside web root). See JSON `storage`.

### 9.4 API and hosting
REST/JSON under `/api/v1` with Sanctum cookie auth, `Idempotency-Key` header on money-posting endpoints, uniform error shape `{code, message, fields, request_id}`, decimal strings for money. Full conventions and endpoint groups are in JSON `api`. Hosting requirements and the cPanel layout are in JSON `hosting`/`deployment` and `DEPLOY_CPANEL.md`.

---

## 10. Business rules

| ID | Rule |
|---|---|
| BR-001 | Money = `numeric(14,2)` BDT. Round half-up at **line level**, then sum; optional invoice round-off to nearest ৳1 (setting) stored as its own line. |
| BR-002 | Unit price tier: customer type Retail/Walk-in → `retail_price`; Wholesale → `wholesale_price`; Dealer/Garage → `dealer_price`; fallback to retail if tier price empty. Cashier may override only with permission. |
| BR-003 | VAT: if `vat_mode = exclusive`, tax is added on taxable amount; if `inclusive`, tax is extracted from price. Rate precedence: variant override → product → default. Invoice shows both modes correctly. |
| BR-010 | Discount order: line discount first, then cart discount allocated **proportionally** to lines (needed for correct VAT, profit, and returns). |
| BR-020 | Selling below `min_price` (floor) or discounting beyond the role limit requires a **manager/super_admin PIN**; logged in `audit_logs` with approver. |
| BR-021 | Cashier max discount % per bill from settings (default 5 %). |
| BR-030 | Stock is deducted at **sale completion** from the selected warehouse. Quotations and held bills **do not** reserve stock unless `reserve_on_hold` is on. |
| BR-031 | Each `sale_items` row stores `unit_cost` (weighted average at that moment) as COGS snapshot; profit reports use it. |
| BR-032 | Weighted-average cost is recalculated on each purchase receipt (incl. landed costs) and **not** changed by sales, adjustments-out, or transfers (transfers carry cost). |
| BR-033 | Selling a bundle decrements components; bundle COGS = sum of component costs. Returning a bundle returns components. |
| BR-040 | Walk-in customer cannot have `due > 0`. Any due requires a named customer record. |
| BR-041 | Credit limit: if `balance_after_sale > credit_limit` → warn (default) or block (setting); manager PIN can override. |
| BR-050 | A completed invoice is **immutable**. Corrections = void (with reason) or return/credit note. |
| BR-051 | Void: same day by manager+, any day by super_admin, not allowed in a locked period; reverses stock, payments, account transactions, ledger; invoice kept with VOID status. |
| BR-052 | Return window default 7 days (setting/product). Refund ≤ amount paid for those lines; the rest becomes ledger credit. Resellable → stock in; broken → `breakage` movement. |
| BR-060 | Payment references are mandatory: bKash/Nagad/Rocket → sender number + TrxID; Bank → bank name + reference. Cash → none. |
| BR-061 | Duplicate TrxID (same method) within 30 days is blocked unless a manager overrides (prevents double-claiming one payment). |
| BR-062 | Customer payments allocate **oldest invoice first** by default; user may allocate manually; remainder = customer advance. |
| BR-070 | Document numbers are **gap-free** per sequence and generated inside the same transaction as the document. |
| BR-080 | Period lock: no create/edit/void with a document date ≤ lock date. |
| BR-090 | Timezone `Asia/Dhaka` for display/day boundaries; timestamps stored UTC. Week starts Saturday (configurable). |
| BR-091 | Position semantics: `left`/`right` are from the **driver's seat view**; `app_settings` holds `driver_side` (`right` for Bangladesh RHD) so UI and invoices can also show "(Driver side)". |
| BR-092 | A variant is unique by (product, position, size, glass type, tint, color/finish, adjustment, feature set). Duplicate attempts are rejected with a pointer to the existing variant. |
| BR-093 | Barcodes and SKUs are globally unique (including deactivated items). |
| BR-094 | Prices can never be negative; cost price may be zero only for samples (flag required). |

---

## 11. Non-functional requirements

| ID | Requirement |
|---|---|
| NFR-001 | Performance: POS search ≤ 300 ms p95 on 10 000 variants (maintained `search_text` column, indexed SKU/barcode/OEM-number/phone columns, composite fitment index, eager loading, cached master data, gzip via `.htaccess`). First load ≤ 2.5 s on 4G; route chunks lazy-loaded. |
| NFR-010 | Security: authorization on **every** endpoint (Policy + permission middleware) proven by a role × endpoint test matrix; Sanctum cookie (httpOnly, Secure, SameSite=Lax) + CSRF; Form Request validation server-side and Zod client-side; prepared statements only; rate-limit login and PIN attempts (lock after 5 failures); upload MIME sniffing and no PHP execution in upload folders; HTTPS forced, HSTS and security headers; `APP_DEBUG=false`; `.env`, `vendor`, `storage` not reachable from the web; no secrets in the repo. |
| NFR-011 | Audit: every mutation of money, stock, price, permission, void, or override is logged with actor, time, before/after. Audit rows are append-only. |
| NFR-012 | Privacy: public verify page exposes no personal data. PII fields can be exported only by `super_admin`/`accountant`. |
| NFR-020 | **Bangla PDF test (Phase 3 gate):** render Bangla conjuncts (e.g., "স্ক্রিন", "ক্ষ", "র‍্যাম্প") in the invoice PDF and compare with the reference screenshot. Primary engine is **mPDF on the server** (OpenType shaping, Noto Sans Bengali embedded). If it mis-shapes them or exceeds the host memory limit, use the `@react-pdf/renderer` fallback or keep invoices English-only for Phase 1; record the decision in `docs/decisions.md`. |
| NFR-030 | Reliability: finalization services are atomic (one DB transaction) and idempotent (`Idempotency-Key`); deadlocks are retried up to 3 times; no partial sales; optimistic UI only for non-financial interactions. |
| NFR-040 | Testing: unit tests for money math, VAT, discount allocation, weighted-average cost, number-to-words, landed-cost allocation, stock movement posting; feature tests for each service and endpoint against a MySQL test database (including the authorization matrix, invariants, and concurrency guards); Playwright E2E for: login → vehicle finder → add two sizes → split payment (cash + bKash) → complete → PDF downloaded & parsed (logo present, totals match) → return → void. CI must pass before merge. |
| NFR-050 | Observability: structured error logging (Laravel log files with rotation; Sentry optional), service error codes mapped to friendly UI messages, request IDs surfaced on error pages. |
| NFR-060 | i18n: all UI strings in `en` and `bn` resource files; no hard-coded strings in components; numbers/dates localized; BN numerals optional setting. |
| NFR-070 | Backup/recovery: nightly gzip database dump outside the web root with 14-day retention, hosting-provider backups as a second layer, weekly off-site copy by the owner, a **tested** restore into a scratch database, restore runbook, CSV export of all tables. |
| NFR-110 | Hosting constraints: runs on cPanel shared hosting — PHP 8.2+ (8.1 with Laravel 10), MySQL 8.0+/MariaDB 10.5+, extensions per JSON `nonFunctional.hosting`, `memory_limit ≥ 256M`, no root/SUPER privileges, no stored procedures/triggers/events, no long-running daemons (scheduler via one 1-minute cron entry, queue = `database` drained by cron), no outbound third-party services required for core features. |
| NFR-080 | Browser support: latest 2 versions of Chrome, Edge, Firefox, Safari; Chrome on Android tablets. |
| NFR-090 | Code quality: strict TypeScript (no `any` without comment), ESLint + Prettier, feature-folder structure, components ≤ 300 lines, shared design tokens only (no raw hex outside the token file), accessibility lint (`eslint-plugin-jsx-a11y`). |
| NFR-100 | Printing: A4 via PDF viewer print; 80 mm receipt via print-optimized PDF/HTML at 72–80 mm width; labels via print stylesheet. No dependency on a specific printer driver in Phase 1. |

---

## 12. Build phases and acceptance criteria

Each phase ends with a demo script, passing tests, and a short `docs/phase-N-summary.md`. Do not start the next phase until the gate passes.

| Phase | Scope | Gate / acceptance |
|---|---|---|
| **P0 Foundation** | Repo scaffold, tooling, design tokens, base components (buttons, inputs, tables, drawers, toasts, skeletons), app shell (sidebar/top bar), routing, i18n, Sanctum auth, roles/permissions with authorization tests, audit service, number service, **Company Profile** with logo upload, hosting health page, installer (if no SSH), first deploy to a cPanel staging subdomain | Login works; roles gate routes and endpoints; token file is the only color source; component gallery shows every state; company profile saves and logo uploads; staging subdomain runs on the owner's cPanel and the cron heartbeat shows green |
| **P1 Catalog** | Lookups, custom fields, vehicle makes/models, brands, categories, units, tax, product + variant editor, matrix generator, images, fitment, cross-refs, import/export, barcode labels | Admin can create a mirror with 6 size/position variants, 4 images, fitment to 3 models; CSV import dry-run + commit passes; labels print |
| **P2 Inventory & Purchasing** | Suppliers, PO, GRN with landed cost, stock levels/movements, adjustments, breakage, transfers, counts, alerts, reorder, valuation | Posting a GRN updates stock + avg cost + supplier ledger; negative stock blocked; stock card equals levels (automated invariant test) |
| **P3 POS & Sales** | Customers, vehicles, POS terminal, finder, hold/resume, discount rules + PIN, payment drawer (5 methods, split), `complete_sale`, **invoice PDF auto-download**, success screen, sales list/detail, returns, void, quotations, shifts + Z-report, warranty | E2E in NFR-040 passes; invoice contains logo, full company and client details; Bangla test per NFR-020 decided; two cashiers selling the last unit simultaneously → exactly one succeeds |
| **P4 Ledger & Finance** | Financial accounts, party ledgers, receive/pay, allocation, expenses, fund transfers, day book, dues & aging, period lock, statements | Customer due created at POS shows in ledger; payment allocation oldest-first; account balances reconcile with day book (invariant test) |
| **P5 Reports & Dashboard** | Report catalog, exports, dashboard widgets, charts, saved presets | Sales total on dashboard equals sales report equals sum of invoices for the same filters; profit equals Σ(line price − line cost) |
| **P6 Hardening & Launch** | Performance pass, security review, authorization tests per role, accessibility audit, seed/demo data, user guide (EN/BN), production deployment on cPanel, nightly backup + tested restore, UAT fixes | Lighthouse targets met; authorization matrix green; fresh deployment from `DEPLOY_CPANEL.md` works; restore tested; UAT sign-off complete |

**Definition of Done (every task):** meets FR/BR IDs · types strict · tests written and passing · accessible (keyboard + labels) · motion respects reduced-motion · i18n keys added · no raw hex colors · no console errors · Laravel migration included and reversible · authorization test for every new endpoint · audit rows for money/stock actions · screenshot added to PR.

---

## 13. Phase 2 backlog, risks, open questions

### 13.1 Phase 2 backlog (not in Phase 1)
Offline-capable PWA POS with sync · live bKash/Nagad/Rocket payment verification · SMS (sms.net.bd) and WhatsApp Business notifications (dues, invoice send) · courier integration (Steadfast / Pathao) for delivery orders · double-entry accounting with chart of accounts and trial balance · NBR-compliant Mushak/EFD integration · serial/batch tracking · made-to-order custom-size glass cutting job cards · loyalty/discount campaigns · customer-facing catalog/e-commerce sync · dark theme · mobile app for storekeeper counts · thermal printer ESC/POS direct printing · multi-branch consolidated reports · recurring expenses · supplier claim workflow for breakage.

### 13.2 Risks and mitigations
| Risk | Mitigation |
|---|---|
| Bangla text mis-shaped in PDF | NFR-020 gate; mPDF primary; `@react-pdf` fallback; English-only fallback |
| Shared-hosting limits (memory, execution time, no SSH, no triggers) | Hosting check before P0; no triggers/procedures; installer wizard; chunked imports/exports; PDF memory test; fallbacks in JSON `hosting.fallbacks` |
| Data loss on a single hosting account | Nightly dump + provider backup + weekly off-site copy + tested restore (NFR-070) |
| Updating a live site by file upload | Release zip, maintenance mode, backup first, migrations, rollback plan (`DEPLOY_CPANEL.md`) |
| Auto-download blocked by browser | Download is triggered directly from the user's click/keypress chain after the checkout request resolves; fallback shows a prominent "Download invoice" button on the success screen and stores the PDF for later |
| Stock drift from concurrent sales | Row locks (`FOR UPDATE`) inside `SaleService::complete`; invariant tests; nightly `integrity:check` |
| Over-complex product form slows data entry | Stepper + matrix generator + clone + CSV import |
| Manual MFS entry errors | Required TrxID/sender, duplicate-TrxID guard, pending-verification state, accountant confirmation |
| Scope creep | `CLAUDE.md` scope rule: nothing outside this PRD without owner approval |
| VAT/legal compliance | Configurable VAT; commercial-invoice only; confirm with accountant before claiming Mushak compliance |

### 13.3 Open questions for the owner (defaults in brackets; build proceeds with defaults)
1. Does "laser" mean **ledger**? [Yes]
2. Single shop or multiple branches at launch? [Single, multi-ready]
3. VAT registered? Standard rate? [VAT off, 0 %]
4. Company details for invoices: legal name, address, phones, email, website, BIN/TIN, trade license, bank details, signature. [Admin fills in Settings; invoice blocked until name/address/phone set]
5. Which side is the driver seat for "Left/Right" labels? [Right-hand drive, Bangladesh]
6. Cashier maximum discount? [5 %]
7. Need thermal 80 mm receipts on day one? [Yes, simple variant included]
8. Do you keep stock in more than one place (showroom + godown)? [Two warehouses seeded: Showroom, Godown]
9. Return window and warranty text? [7 days; owner supplies wording]
10. Should invoices also show Bangla labels? [No in Phase 1 unless NFR-020 passes]

