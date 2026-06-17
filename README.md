# AutoForge

The client-agnostic AQM WordPress platform. **One plugin owns everything**
(rendering, the visual builder, SEO, JSON-LD, and the embedded **Boost**
performance module); the theme is a near-empty stub. Every client runs the
*same* code — all brand, content, and design is delivered per site as **data**.

This is the "Breakdance model": WordPress still needs an active theme, but it's
a stub. The plugin takes over the front end via the `template_include` hook.

> Extracted from the Ken Arnold pilot (`../ken-arnold-wp`, the read-only
> reference). The base repo ships **no client data** — content, brand, design,
> and the compiled CSS/JS are added per client at import time. The shape a client
> repo must follow is defined in `content/schema/`.

## What's here

```
plugin/aq-core/        The AutoForge plugin (folder/slug stays "aq-core";
                       Plugin Name = "AutoForge"). Boost lives in thrust/.
  render/              The rendering engine moved out of the theme:
                       class-renderer.php (template_include), section templates,
                       header/footer chrome, helpers, LCP preload.
  includes/            Business logic + class-updater.php (GitHub auto-update).
  config/site.php      EMPTY placeholder defaults — real values come per client.
theme/aqm-base/        The stub theme. Identical PHP everywhere; only its
                       compiled assets/css/main.css differs per client.
content/schema/        The page + section contract (page.schema.json,
                       components.json). The ONLY content/ that ships; the rest
                       (pages, brand.json, design.json) is per-client, gitignored.
migration/             lint-php.mjs, build-release.mjs.
```

## Build / dev

```bash
npm install
npm run css          # compile theme/aqm-base/assets/css/main.css
npm run lint         # PHP syntax check (no new errors expected)
npm run build:release # → dist/autoforge-wp-<version>.zip (GitHub release asset)
```

## Stand up a client

1. **Install** the plugin (upload `dist/autoforge-wp-*.zip`) and activate the
   **AQM Base** theme.
2. **Import** the client's content repo via AutoForge → Import. The importer:
   - sideloads referenced images into the media library,
   - builds every page from `content/pages/*.json`,
   - seeds brand/site config from `content/brand.json` into the `aq_site_config`
     option (resolving logo filenames to attachment IDs),
   - delivers the client's compiled `main.css` / `site.js` into the stub theme.
3. The client's CSS is pre-compiled in their repo: drop their `content/` (incl.
   `design.json`) into a build checkout and run `npm run css`, then commit the
   output to `content/assets/main.css`.

## Auto-update

The plugin shows WordPress's normal "update available" button by checking a
GitHub repo's latest release. The product repo defaults to `AQ-Marketing/autoforge-wp`; override it only to point at a fork:

```php
// wp-config.php
define('AQ_UPDATE_REPO', 'AQ-Marketing/autoforge-wp');
```

(or set the `aq_update_repo` option). Private repos reuse the GitHub token from
AutoForge → Integrations. Publish a release tagged `v<version>` with the
`autoforge-wp-<version>.zip` attached as an asset.

- **Content importer** pulls a *client's* content repo. **Plugin updater** pulls
  *this product's* releases. Different repos, different triggers.
- Per-client config lives in the DB (`aq_site_config`) and the client repo —
  never in plugin files — so updates never clobber a client's data.

## Kill switches

- `define('AQ_RENDER_DISABLE', true)` — hand rendering back to the active theme.
- `define('AQ_BOOST_DISABLE', true)` — disable the Boost performance module.
