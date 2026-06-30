# AutoForge WP — Production Readiness & Performance Review

**Date:** 2026-06-30  
**Version:** 0.2.19

---

## Production Readiness

### ✅ Ready

| Area | Status | Notes |
|------|--------|-------|
| **Build pipeline** | ✅ | `npm run build:release` produces versioned zips for plugin + theme. Archiver excludes dev files. Asset naming handles legacy updater compatibility. |
| **Auto-update** | ✅ | GitHub release → WordPress one-click update. Both plugin and theme served from one release. Source dir renaming handled. Private repo auth works. |
| **Deployment model** | ✅ | Upload zip + activate. Importer pulls content. No CLI dependency. Works on any WordPress host. |
| **Kill switches** | ✅ | `AQ_RENDER_DISABLE` (fall back to theme), `AQ_BOOST_DISABLE` (performance module off). Proper constants in wp-config. |
| **NoIndex safety** | ✅ | Default `true` — fresh installs never accidentally go live in Google. Hard override via `AQ_NOINDEX` constant. |
| **Error containment** | ✅ | Hero preload in try/catch. Missing ACF gracefully returns. Builder canvas nonce failures just don't activate. |
| **Data separation** | ✅ | No client data in the plugin repo. Content, brand, design all delivered as external data via import. Plugin updates never clobber client config. |
| **Backward compat** | ✅ | Updater handles legacy installs (pre-0.2.0) via alphabetical asset sort fallback. |

### ⚠️ Needs attention before scaling

| Area | Issue | Risk | Recommendation |
|------|-------|------|----------------|
| **Rollback** | No built-in rollback. A bad release affects all clients until they manually reinstall an older zip. | Medium | Add a "previous version" field to the updater transient. Or: keep last-2 zips on the GitHub release. WordPress's own rollback plugin (`WP Rollback`) supports GitHub releases if structured correctly. |
| **Staging/production parity** | No documented workflow for testing a new plugin version on one client before all clients update. | Medium | Implement a `AQ_UPDATE_CHANNEL` constant (stable/beta). Beta clients get a pre-release; stable clients only see full releases. Or use GitHub release `prerelease` flag — updater already sees it. |
| **Monitoring** | No error reporting or health endpoint. If a client's import fails or Boost misconfigures, nobody knows until the client calls. | Low | Add a `GET aq/v1/health` endpoint (public, no auth) that returns `{"ok":true, "version":"0.2.19", "renderer":"active", "boost":"active"}`. Makes automated monitoring trivial. |
| **Database migrations** | No versioned schema evolution. Options are created on first use. | Low | Acceptable at current scale. If you add DB tables later (e.g., SEO Agent history), add a version check + migration runner. |
| **Documentation** | `README.md` and `RESUME.md` exist but no user-facing docs for client site admins. | Low | Ship a minimal in-admin help page or link to a Notion/doc from the Overview screen. |

---

## Performance Review

### Rendering Pipeline

**Architecture:** Plugin hooks `template_include` at priority 50 → routes to its own `page.php`/`single.php`/`index.php` → each calls `AQ_Renderer::head_open()`, `render_sections()`, `body_close()`. Sections are rendered by including individual PHP templates with isolated scope.

**Assessment: Fast.** 

- No database queries per-section (ACF loads all `sections` as one field)
- Templates are simple PHP includes (no template engine overhead)
- Theme override via `locate_template()` is a single filesystem check
- Section markers (for the builder) are off by default in production — zero added overhead

### LCP Optimization

**`hero-preload.php`** correctly:
- Identifies the first hero section from ACF data
- Emits a `<link rel="preload" as="image">` with `srcset` + `sizes` and `fetchpriority="high"`
- Hooks at priority 5 (before other wp_head output)
- Handles multiple hero types (`hero`, `city_hero`, `specialty_hero`)
- Swallows exceptions (never breaks the page)

**Assessment: Excellent.** This is exactly what PageSpeed Insights wants.

### Font Loading

**Strategy:** Google Fonts loaded async via `media="print" onload="this.media='all'"` with a `<noscript>` fallback. Preconnect hints for `fonts.googleapis.com` and `fonts.gstatic.com`.

**Assessment: Good.** Prevents render-blocking. Consider `font-display: swap` in the CSS for CLS protection (likely already in the Tailwind output).

### Boost (WP Rocket Fork)

The embedded `thrust/` module handles:
- Page caching
- JS delay/defer
- CSS optimization
- Lazy loading

**Interaction with the renderer:** Boost sees the plugin's rendered output as normal HTML (it hooks later in the WordPress stack). No conflict risk — the renderer outputs to a template, Boost processes the final HTML buffer.

**Potential issue:** Boost's delay-JS list (`dynamic-lists-delayjs.json`, 229KB) is a static snapshot. It contains rules for plugins AutoForge clients may never use. Consider trimming this to known-relevant entries.

### Recommendations

| Priority | Area | Action |
|----------|------|--------|
| **P2** | CLS | Verify `font-display: swap` is in the compiled CSS. If Google Fonts are used without it, layout shift will occur on slow connections. |
| **P3** | Boost trimming | Remove Boost delay-JS entries for plugins you never install (WooCommerce, Elementor, etc.). Reduces the JSON parse overhead and the accidental-delay surface. |
| **P3** | Critical CSS | Consider inlining above-the-fold CSS for the hero section. Currently the full `main.css` must download before paint. Boost may handle this, but verify. |
| **P4** | Image sizes | The `ka-480`, `ka-768`, `ka-1280` breakpoints are good. Verify that the importer generates all three sizes on upload (WordPress generates them on `wp_generate_attachment_metadata`). |
| **P4** | Template override cost | `locate_template()` is called once per section render. On a page with 15 sections, that's 15 filesystem checks. In practice this is cached by the OS and negligible, but worth noting. |

---

## Overall Verdict

**Production-ready for your current deployment model** (single-admin client sites, manual plugin updates via GitHub releases). The rendering pipeline is fast and well-optimized. Boost handles the heavy lifting for caching and asset optimization.

Before scaling to 10+ client sites, add:
1. A health endpoint for monitoring
2. A beta update channel so you can test on one client first
3. A rollback path (even if it's just "keep the previous zip on the release")
