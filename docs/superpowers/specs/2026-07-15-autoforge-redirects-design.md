# AutoForge Redirects — Design Spec

**Date:** 2026-07-15
**Status:** Approved (design), pending implementation plan
**Component:** `plugin/aq-core` (AutoForge engine)
**First real dataset:** AQ Marketing migration (aqmarketing.com → new staging site)

---

## 1. Purpose

Every site AutoForge migrates off an old platform inherits hundreds or thousands of
old URLs that no longer exist. Those URLs must 301-redirect to the right new page so
visitors and Google don't hit dead ends and search rankings carry over.

Today the engine has a **minimal** redirect handler (`class-redirects.php`): exact-match
only, no dashboard, no import/export, no logging, rules delivered only via
`brand.json → aq_site('redirects')`. This spec **expands that module** into a complete,
dashboard-managed AutoForge feature — client-agnostic engine code + per-client rule data —
so redirect management is solved once for every current and future site, with **no
third-party redirect plugin**.

## 2. Goals / Non-goals

**Goals**
- Exact **and** pattern (regex) redirects, applied in pure PHP on the front end.
- A dashboard screen (AutoForge → Redirects) a non-technical client can use for simple
  redirects, with regex "pattern" rules tucked under an Advanced area.
- CSV import/export for bulk editing.
- A capped 404 log so gaps surface after launch without leaving the dashboard.
- Load AQM's real redirect map as the first import and verify AQM is cutover-ready.

**Non-goals (YAGNI)**
- Import/export formats other than CSV.
- Redirect analytics/charts, per-rule scheduling, A/B, geo rules.
- A bulk find-and-replace UI, or wildcard syntax beyond standard regex.
- An explicit "replace all rules" import mode (import always merges).

## 3. Architecture

Client-agnostic engine code lives in `plugin/aq-core`; per-client rule data lives in
per-site `wp_option`s. This mirrors the existing `AQ_Legal` screen (`aq_legal` option) and
`AQ_Site_Config` overlay patterns.

### 3.1 Storage
- **`aq_redirects`** (new option, `autoload=false`) — the managed rule set: an ordered
  list of rules. Each rule:
  | field | type | notes |
  |---|---|---|
  | `source` | string | old path, leading slash, normalized to a trailing slash for exact rules; raw regex for pattern rules |
  | `target` | string | destination path (or absolute URL) |
  | `code` | int | 301 (default) or 302 |
  | `match` | string | `exact` or `pattern` |
  | `enabled` | bool | on/off without deleting |
  | `notes` | string | free text |
- **`aq_redirect_log`** (new option, `autoload=false`) — recent unmatched 404s: keyed by
  path, each `{ hits, first_seen, last_seen }`. Capped at ~500 distinct paths (evict
  oldest `last_seen` when over cap) so it can never bloat the DB.
- **Migration:** on first run, if `aq_redirects` is unset, seed it from any legacy
  `aq_site('redirects')` exact map (from `brand.json`), converting each `from => to` into an
  `exact` rule. Existing migrations keep working with zero data loss.

### 3.2 Request handler (`AQ_Redirects`, expanded)
Runs on `template_redirect` (main query already resolved, so `is_404()` is reliable):

1. Normalize the request path.
2. **Exact rules** (enabled, `match=exact`): if the normalized path matches a rule's
   source → `wp_safe_redirect(target, code); exit`. Exact rules are deliberate 1:1 moves
   and fire regardless of whether a page exists.
3. **Pattern rules** — only if `is_404()` is true: test enabled `match=pattern` rules **in
   listed order**; first `preg_match` hit → redirect (with `preg_replace` so patterns can
   capture/substitute). Because this runs only on would-be-404s, a broad pattern can never
   hijack a live page or post.
4. If still unmatched **and** `is_404()` → record the path in `aq_redirect_log`, then let
   WordPress render its normal 404.

**Safety guards**
- **No self-redirect / loop:** never redirect when the resolved target path equals the
  request path. Regex output validated before redirecting.
- **Same-site targets:** relative targets resolved via `home_url()`. Absolute targets
  allowed only for the same host (guard against open redirects).
- **Invalid regex:** a pattern that fails to compile is skipped (logged once), never fatals
  the front end.
- Performance: steps 3–4 touch only would-be-404s, so normal page loads are unaffected.

### 3.3 Dashboard screen (`AQ_Redirects_Admin` or extend `AQ_Redirects`)
Registered like every other hub screen: `add_submenu_page('aq-dashboard', …)`, `manage_options`
cap, "Redirects" tab in `AQ_Admin_Hub::tabs()`, form → `admin-post.php` with nonce, save →
`update_option` → redirect back with `updated=1`. Uses `AQ_Admin_Hub::open()/close()` chrome.

Panels:
- **Simple redirects** — table of `exact` rules: old URL → new URL, on/off, notes, add-row.
  The default view for non-technical users.
- **Advanced — pattern rules** — collapsed `<details>` with a plain-English caution; table
  of `pattern` rules. Primarily populated by import.
- **Import / Export** — upload CSV (bulk add/update); download CSV of all rules. Unified
  format: `source, target, code, match, notes`.
- **Recent 404s** — table from `aq_redirect_log` (path, hits, last seen) with a one-click
  "Add a redirect for this" that pre-fills a new exact rule.

### 3.4 Import / Export
- **Format:** one CSV, header `source,target,code,match,notes`. `code` defaults 301, `match`
  defaults `exact` when blank.
- **Import = merge by source:** update in place if `source` exists, else append. Never wipes
  unlisted rules. Malformed rows are skipped and counted in a summary notice
  ("Imported 45, updated 3, skipped 1").
- **Export:** current rules in the same format — round-trips (export → re-import = identical).

## 4. AQM go-live (first real import)

1. Convert the existing AQM map into one unified CSV:
   - `redirects/redirect-map.csv` (45 exact) → `match=exact`.
   - `redirects/redirect-rules-regex.txt` (16 patterns) → `match=pattern` (order preserved:
     industry+town → covered towns → fallback towns → `/project/*`).
   - Add the 3 dropped-post redirects:
     - `/demystifying-your-google-ppc-campaign-costs/` → `/services/google-ads/`
     - `/the-startup-marketing-challenge-how-to-stand-out-in-a-crowded-market/` → `/startup-growth-marketing-101/`
     - `/the-ultimate-guide-to-understanding-the-healthcare-insurance-market/` → `/industries/insurance-agencies/`
2. Import the CSV on staging.
3. **Verify (logged-out, real requests):**
   - Sample exact rules 301 to the right target (e.g. `/about-aq-marketing/` → `/about/`,
     `/website-design-development/` → `/services/web-design/`).
   - Sample pattern rules 301 correctly (`/web-design-woburn-ma/` → `/locations/woburn-ma/`;
     a fallback town → `/services/web-design/` or `/`; `/industries-we-service/law-firm-web-design-…-ma/`
     → `/industries/law-firms/`; `/project/x/` → `/about/`).
   - A known-good page still returns 200 (e.g. `/services/local-seo/`) — patterns don't
     touch live pages.
   - All redirect **targets** resolve to real pages (already verified: 43-page sitemap).
4. Confirm coverage: of 3,578 old URLs, 3,573 covered (577 unchanged / 45 exact / 2,952
   pattern) + 3 new post redirects; `/locations.kml` intentionally ignored.

## 5. Testing

- **Handler:** exact hit; pattern hit on 404; unmatched 404 logged; live page untouched by
  patterns; trailing-slash normalization; self-redirect/loop guard; invalid-regex skip;
  open-redirect guard.
- **Import/export:** merge-by-source (add + update + skip malformed); export→re-import identity.
- **404 log:** dedupe + hit increment; cap eviction at ~500.
- **End-to-end:** the AQM verification in §4 on staging (source of truth = live server).

## 6. Rollout & deploy

- Build in `plugin/aq-core` on the current repo branch (`release/0.3.11`); mirror onto the
  Pressable staging server (live truth) and purge caches, per the project's deploy rules.
- No plugin release cut as part of AQM go-live unless Justin approves.
- Back up any server file before overwriting; `php -l` every edited PHP file; verify
  logged-out after cache purge.

## 7. Open questions / assumptions

- Assumes the "Redirects" screen stays `manage_options` (agency + admin) like all hub
  screens; the "hide patterns under Advanced" choice is UX within that screen, not a
  separate capability. Revisit if a client-facing lower cap is introduced later.
- Middle dropped-post redirect points to a surviving blog article rather than a service
  page (keeps a blog visitor on comparable content); change to `/services/` if preferred.
