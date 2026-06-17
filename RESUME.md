# AutoForge — session status & resume point

_Pick up here when you log back in._

## What this is
A new **client-agnostic WordPress product** built at
`C:\Users\justi\Apps\Work\AutoForge WP`, extracted from the Ken Arnold
pilot (`C:\Users\justi\Apps\Work\Websites\ken-arnold-wp`, left untouched as the
read-only reference). The "Breakdance model": **one plugin owns all rendering**
via WordPress's `template_include` hook; the theme is a near-empty stub. Every
client runs the same code — brand, content, and design are delivered as data.

## ✅ Done and verified (static)
- **Plugin `plugin/aq-core/`** ("AutoForge", v0.2.0, Boost bundled in `thrust/`):
  - `render/` — the whole rendering engine moved out of the theme: `class-renderer.php`
    (`AQ_Renderer`, hooks `template_include`), 46 section templates, header/footer
    chrome (`head-open`/`body-close`/`site-header`/`site-footer`/`sticky-call-bar`/
    `post-cta`/`post-card`), `helpers.php` (ka_picture / ka_field_attr / ka_is_editing /
    ka_reading_time / external-link filter), `hero-preload.php`. Kill switch
    `AQ_RENDER_DISABLE`.
  - `includes/class-updater.php` (`AQ_Updater`) — GitHub-release auto-update.
  - De-branded: `config/site.php` ships empty; SEO meta, JSON-LD, and the header/footer
    mega-menu read brand/colors/copy from `aq_site()`.
- **Theme `theme/aqm-base/`** — stub theme; identical PHP everywhere, only compiled
  `assets/css/main.css` differs per client. Tailwind config scans the plugin render dir
  + reads `content/design.json`.
- **Verification:** `npm run lint` = 980 files, 0 errors. `npm run css` compiles
  **byte-identical** to the pilot (55,889 bytes, 394 selectors, 0 missing / 0 extra).
  `npm run build:release` → `dist/autoforge-wp-0.2.0.zip` (top-level `aq-core/`, Boost inside).
- **Client data:** none in the base repo. The base ships only the engine + the
  `content/schema/` contract; pages, `brand.json`, `design.json`, and compiled
  CSS/JS arrive per client at import. Test against the separate `aqm-test-content`
  repo (below). _(Removed the old `examples/ken-arnold/` fixture and the local
  `content/` client copy during the client-free-base cleanup.)_

> Mid-session catch: the reference theme had a **blog redesign** (`single.php`,
> `post-feed.php`, `functions.php`) that my first copy missed. Re-synced — that's why
> the CSS now matches byte-for-byte.

## ⏳ In progress: live test on Studio (NOT yet done)
Chosen approach: **fresh separate Studio site** + full import flow, verified in-browser
via the **Claude-in-Chrome extension** (incl. the builder canvas).

**Ready and waiting:**
- Private test content repo pushed: **https://github.com/jcasey76/aqm-test-content**
  (branch **`master`**) — 85 pages, 129 images (incl. logos `logo.png`/`logo-light.png`),
  `brand.json`, `design.json`, compiled `content/assets/main.css`. Staged locally at
  `C:\Users\justi\Apps\Work\Websites\aqm-test-content`.
- Importer was extended to also sideload the brand's logo files and seed config from
  `brand.json` + deliver the compiled CSS into the theme.

### 👉 What YOU need to do first (2 things)
1. **Create a blank Studio site** (Studio app → Add site → empty WordPress). Note its
   **name + port**, and tell me both.
2. **Connect the Claude-in-Chrome extension** (install/enable it, open Chrome) so I can
   drive wp-admin and test the builder canvas. (No browser was connected last session.)

### Then I will (automatically)
1. Junction into the new site's `wp-content`:
   - `plugins/advanced-custom-fields-pro` → pilot's ACF Pro
     (`C:\Users\justi\Studio\ken-arnold-wp\wp-content\plugins\advanced-custom-fields-pro`)
   - `plugins/autoforge-wp` → `AutoForge WP\plugin\aq-core`
   - `themes/aqm-base` → `AutoForge WP\theme\aqm-base`
2. Add `define('AQ_GITHUB_TOKEN', '…')` (from `gh auth token`) to the new site's
   `wp-config.php` so the importer can read the private repo.
3. Activate ACF Pro → AutoForge plugin → AQM Base theme.
4. **AutoForge → Import** against `https://github.com/jcasey76/aqm-test-content`,
   branch **`master`** → builds 85 pages, sideloads images, seeds brand, delivers CSS.
5. Verify: home `/`, a service page, a city×service page, a blog post — check header
   mega-menu, footer, heroes; then open the builder canvas (`?aq_canvas=1`) for click-to-edit.

## Handy facts
- Studio runs **PHP in-app (php-wasm)** → **no command-line WP-CLI**; drive via browser.
- Pilot Studio site: `ken-arnold-wp`, port **8882**, admin/admin; its `aq-core` plugin +
  `kenarnold` theme are **junctions into the reference repo** (so the pilot = the reference).
- Build commands (run in `AutoForge WP\`): `npm run css`, `npm run lint`,
  `npm run build:release`.
- Kill switches: `AQ_RENDER_DISABLE` (rendering → back to theme), `AQ_BOOST_DISABLE` (Boost).
- Auto-update repo config: `define('AQ_UPDATE_REPO','owner/name')` or the `aq_update_repo` option.

## Open follow-ups (after the test passes)
- ✅ DONE (2026-06-17): product repo **AQ-Marketing/autoforge-wp** (public) created; release **v0.2.0** published with `autoforge-wp-0.2.0.zip` attached. The updater now defaults to this repo (`AQ_Updater::DEFAULT_REPO`), so a fresh install auto-updates with no wp-config edit. `npm run build:release` also emits the `aqm-base-<v>.zip` theme.
- Optional: a small admin field to set `AQ_UPDATE_REPO` from the Integrations screen.
- Ken Arnold's own migration (its own `brand.json`/`design.json`, switch to AQM Base) — later.
