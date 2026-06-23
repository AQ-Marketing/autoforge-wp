# AutoForge — multi-session / multi-site coordination

**Read this before working in `plugin/aq-core` (the shared engine).** It is used by
multiple client sites (a home-inspection pilot, AQ Marketing "aqm", Ken Arnold).
More than one session may be editing this repo at a time.

## The architecture — the engine is client/design-AGNOSTIC
Three layers, owned separately:

1. **`plugin/aq-core` = the engine only.** How to render pages, run the visual
   editor, import content, generate SEO/schema, handle nav. It must hold **zero**
   business facts and **zero** design.
2. **The theme (one per site, e.g. `theme/aqm-base`) = all design.** CSS, JS,
   header/footer markup, and the page "blocks" (markup **and** their editor fields).
3. **Per-site data = the business.** The WP DB option `aq_site_config` (loaded from a
   per-site `content/brand.json`) + pages (imported from `content/pages/*.json`).
   `content/*` is gitignored on purpose — client content lives in a per-site content
   repo, never in the engine.

**The rule:** if it names a business or decides what something looks like, it belongs
in a **theme** or the **database** — never in `aq-core`.

## How a theme plugs in (use these seams; do NOT edit the plugin to add a site)
All six are implemented and confirmed working:

- **Chrome markup** → `{theme}/render-parts/{name}.php` (engine checks theme first via
  the `aq_part_roots` filter; `AQ_Renderer::part()`).
- **Block markup** → `{theme}/render-sections/{type}.php` (via `aq_section_roots`;
  `AQ_Renderer::locate_section()`). **Filenames are HYPHENATED** — a type `aqm_page_hero`
  resolves to `aqm-page-hero.php` (the renderer does `str_replace('_','-')`).
- **Block registration** (editor fields + DB persistence + import) → a
  `{theme}/blocks/*.php` required from the theme's `functions.php`, adding types via
  three filters: `aq_field_schema` (fields), `aq_layout_labels` (menu label),
  `aq_field_order` (import order — derive with `AQ_Editor::field_order_from_schema()`).
- **Business data** → `aq_site_config` (DB), loaded from the site's `content/brand.json`;
  deep-merged over the blank defaults in `plugin/aq-core/config/site.php`. Keep
  `config/site.php` **blank** — never put a client's values there.
- **Pages** → the site's `content/pages/*.json`, imported via `AQ_Content_Sync`.
- **Updates** → per-site GitHub repo via the `AQ_UPDATE_REPO` constant.

See `theme/aqm-base/blocks/aqm-blocks.php` + `theme/aqm-base/render-sections/` for a
complete worked example (the AQM interior block pack).

## Coordination rules (multiple sessions share this one working tree)
- Treat `plugin/aq-core` as **read-mostly**. Don't edit another site's theme.
- **No destructive git ops** without checking with the others: `reset`, `checkout`,
  `stash`, force-push, or branch switches on a tree with uncommitted work.
- **Don't cut a new `aq-core` GitHub release** while unreleased work is on a branch —
  a release pushes whatever's tagged and the auto-updater would distribute it to every
  site, potentially reverting newer local code. Merge first.
- **Don't bake one site's specifics into the engine.** If you need a *generic*
  capability every site would use, make it additive + backward-compatible and flag it.

## Known engine gaps to make it fully agnostic (do these as generic, coordinated changes)
- **Theme slug hardcoded `aqm-base`** in `includes/class-updater.php` (`THEME_SLUG`) and
  `includes/class-global-styles.php` (`wp_add_inline_style('aqm-base', …)`). A site on a
  different theme slug silently misses theme updates + global styles. Fix generically
  (`get_stylesheet()` / an `AQ_THEME_SLUG` constant) — **affects Ken Arnold.**
- **Inspection-pilot defaults** baked in `config/site.php` (CTAs "Schedule Inspection" /
  "Request a Call Back" → `/schedule/`, megamenu bases `/testing-and-specialty/` &
  `/service-area/`, footer `inspections` column) and in section field defaults / render
  fallbacks (`includes/fields/sections.php`, `render/sections/cta-band.php`, `final-cta.php`,
  `render/parts/*`). Neutralize to blank so no new client inherits inspection wording.
- **Domain assumptions** in `includes/class-assistant.php` ("home-inspection company",
  "MA"), `includes/class-jsonld.php` (`priceRange '$$'`), `render/parts/post-cta.php`.
  Parameterize from `aq_site('businessType')` / license / region.
- **AQM design blocks still in the plugin** — the 14 animated `.hm-*` home/about types
  (`local-hero`, `stat-split`, `problem-panel`, `sticky-steps`, `service-showcase`,
  `proof-story`, `spotlight-grid`, `chip-marquee`, `compare-table`, `scrub-quote`,
  `network-hero`, `logo-marquee`, `faq-split`, `cta-banner`) are registered/rendered in
  `aq-core`. They should move into `theme/aqm-base` (`render-sections/` + `blocks/`) using
  the per-design seam above, leaving the plugin with only generic primitives.
