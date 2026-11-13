# Performance and accessibility results (P6)

Measured on a local machine (Chromium headless from the project's Playwright install, desktop profile, Lighthouse 13.6, MariaDB 10.11, PHP's built-in server). **Not measured on the cPanel hosting.** Real numbers there will differ, mostly on speed; re-run after the staging deploy and record the result in this file.

## Lighthouse (desktop, Chromium, logged in where needed)

Gate (P6): performance ≥ 85 and accessibility ≥ 95 on the POS.

| Page | Performance | Accessibility | Best practices | LCP (ms) | CLS | Result |
|---|---|---|---|---|---|---|
| POS terminal | **90** | **100** | 100 | 2,080 | 0.014 | Gate met |
| Login | 94 | 100 | 96 | 1,640 | 0 | — |
| Dashboard | 89 | 97 | 100 | 2,157 | 0.024 | — (one audit left, see below) |
| Sales list | 93 | 100 | 100 | 1,770 | 0.026 | — |
| Report page (sales summary) | 88 | 100 | 100 | 2,259 | 0.023 | — |

NFR-001 asks for the first load to be ≤ 2.5 s on 4G. The LCP figures above are on a desktop profile with no network throttling, so they are not a 4G test. A mobile-throttled run has not been done.

### What Lighthouse found and what was changed

| Finding | Where | Fix |
|---|---|---|
| Sidebar links lost their active style and shrank to 48×20 px when the sidebar was collapsed | Sidebar tooltip in collapsed mode | The tooltip now wraps the link in a plain `span`; its props no longer merge into the link's class function. |
| Hint and placeholder text below 4.5:1 contrast | `text-ink-subtle` (#94A3B8 on white, about 2.5:1) | Text uses `ink-muted` (#475569, about 7.6:1). The token stays in the token file and is no longer used in the app. |
| Status badges with coloured text on a tinted background below 4.5:1 | Badges with success, warning and danger tones | Dark text on the same tint. |
| No `main` landmark on the login page | Login | Wrapped in `<main>`. |
| Account button name did not contain its visible text | Top bar | Accessible name is the person's name. |
| Rows-per-page select had no name | Pagination | Named "Rows per page". |
| Donut chart drawing exposed as a list of unnamed graphics | Dashboard, payment donut | Hidden from assistive technology; its legend lists every amount. |
| Recharts sectors focusable inside a hidden region | Dashboard donut | **Open.** Lighthouse still reports `aria-hidden-focus` on the dashboard (score 97, above the gate). Next step: give the donut its own accessible table or remove focusability at the library level. |

Lighthouse also reports `label-content-name-mismatch` on several buttons. Its score weight is zero, so it does not move the score; it is listed for later.

## axe (WCAG 2.2 AA, in the browser)

`tests/e2e/a11y.spec.ts` runs axe on six screens and fails on any serious or critical violation: login, POS, dashboard, sales list, reports hub and a report page. All six pass. A separate run that listed **every** WCAG-tagged finding of any impact (not just serious and critical) on the dashboard, POS, sales list and report page returned no findings.

Not covered by axe or Lighthouse: keyboard-only completion of the whole sale, screen reader reading order, and reduced motion (AT-020). Those are on the UAT checklist.

## Search speed (NFR-001)

`GET /api/v1/variants/search`, 60 requests across 12 different queries, against 10,000 variants of one product on a local database (logged in as the owner):

| Median | p95 | Max | Target |
|---|---|---|---|
| 28 ms | **41 ms** | 50 ms | ≤ 300 ms p95 |

The search runs `LIKE` on a maintained `search_text` column plus an indexed SKU, barcode and OEM lookup. The local numbers are a floor, not a promise for the host. The vehicle-fitting list (AT-001) was not timed separately.

## Items not done

- Mobile-throttled Lighthouse run (first load on 4G, NFR-001).
- Tablet and phone widths for the POS (the owner's counter tablet is the real test).
- Reduced-motion check (AT-020).
- Real hosting numbers for speed and for the statement and report exports.
