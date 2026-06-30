# AutoForge WP — Code Quality & Architecture Review

**Date:** 2026-06-30  
**Version:** 0.2.19  
**Scope:** 89 core files, 731KB custom PHP/JS (excludes thrust/)

## Executive Summary

The codebase is well-organized for a 0.2.x product. Architecture is sound — the "plugin owns rendering" model is the right call. The main improvement opportunities are in the largest files (5 classes over 20KB) and some patterns that will bite as the codebase grows.

## Findings

| Priority | Category | File(s) | Issue | Recommendation |
|----------|----------|---------|-------|----------------|
| **P2** | File size | `class-editor.php` (59KB), `class-navigation.php` (66KB) | These are full-stack files: PHP REST handlers + HTML rendering + inline CSS + inline JS, all in one class. They work, but are hard to navigate and test. | Split into: (1) REST controller, (2) admin screen renderer, (3) move inline JS to separate .js files (like you already did with `builder.js`/`canvas.js`). Not urgent but pays off as features grow. |
| **P2** | File size | `class-seo-agent.php` (43KB) | Contains the cron runner, DataForSEO API client, OpenAI narrative generator, email renderer, admin settings screen, and REST endpoint — all in one file. | Extract `class-dataforseo-client.php` and `class-seo-report-renderer.php`. The agent orchestrator stays thin. |
| **P2** | File size | `class-content-sync.php` (37KB, 34 methods) | The god-class for import/export. Handles JSON parsing, validation, WP post creation, ACF field mapping, image resolution, and REST endpoints. | Split into: `class-content-validator.php` (validate_item, normalize_page, normalize_section) and keep sync as the orchestrator. The validator is independently testable. |
| **P2** | Architecture | `class-assistant.php` L623 | System prompt is hardcoded with "a home-inspection company" — not client-agnostic! | Replace with `aq_site('industry')` or a generic fallback. This is a bug in the "client-agnostic" promise. |
| **P3** | Duplication | Multiple admin screens | Every screen repeats: capability check → `AQ_Admin_Hub::open()` → inline `<style>` → form → inline `<script>`. The pattern is consistent but not DRY. | Consider a lightweight admin-page base class or at minimum extract shared CSS to a single admin stylesheet. Not urgent — the consistency is more valuable than DRY here. |
| **P3** | Duplication | `includes/class-performance.php` + `includes/class-admin-hub.php` | Both define a `card()` and `card_html()` helper with identical signatures. | Move to `AQ_Admin_Hub` as the single source (it already has them). Have Performance call the Hub's version. |
| **P3** | Error handling | Throughout | Methods use a mix of: `WP_Error` returns, `self::fail()` (throws), and silent returns. No logging. | Standardize: REST handlers return `WP_Error`; internal methods throw. Add `error_log()` or a lightweight `AQ_Logger` for debugging without exposing to users. |
| **P3** | JS architecture | `admin/editor/builder.js` (26KB) | Single file for the entire builder UI. No modules, no build step. Variables are function-scoped and rely on closures. | Fine for now (no build dep = easier deployment). When it hits 40KB+, consider splitting into ES modules with a simple esbuild step. |
| **P3** | Testing | N/A | No test suite exists. 980 files pass lint, but there are no unit or integration tests. | Priority targets for first tests: `normalize_page()`, `validate_item()`, `sanitize_sections()`, and the importer URL validation regex. These are pure functions with clear inputs/outputs. |
| **P4** | Naming | `includes/class-llms.php` | Vague name — "LLMs" could mean anything. It appears to handle LLM-related features but the name doesn't communicate the scope. | Rename to `class-ai-features.php` or merge into `class-assistant.php` if small enough (5KB suggests it should merge). |
| **P4** | Naming | `render/helpers.php` | Non-class functions (`ka_picture`, `ka_field_attr`, etc.) use a `ka_` prefix (from "Ken Arnold" pilot). | Rename to `aq_` prefix for consistency with the product name. Low priority — only matters if you ever ship template functions for client themes to call. |
| **P4** | WordPress standards | Inline styles/scripts | Most admin screens embed CSS/JS inline rather than enqueueing via `wp_enqueue_style`/`wp_enqueue_script`. | Acceptable for admin-only code that doesn't conflict with other plugins. Only matters if you intend to pass WordPress.org review (which you don't — it's self-hosted). |

## Architecture Assessment

### Strengths
- **Clear separation of concerns at the class level** — each class owns one domain (SEO, navigation, editor, import, etc.)
- **Renderer is properly isolated** — `template_include` hook with a kill switch is textbook
- **Content model is well-designed** — JSON schema → validate → normalize → persist via WP APIs
- **No raw SQL** except one properly prepared query in `apply_sections`
- **ACF integration is clean** — uses `update_field()` API, not raw meta manipulation
- **Plugin → Theme boundary is correct** — theme is truly a stub, all logic lives in the plugin

### Architecture Risks
1. **`thrust/` (Boost/WP Rocket fork)** — 5.1MB of third-party code bundled inline. If WP Rocket publishes a security fix, you need to manually port it. Consider whether this should be a separate plugin that AutoForge recommends rather than bundles.
2. **Single-file admin screens** — manageable now at 15-20 classes, but will get painful at 30+. The `AQ_Admin_Hub::open()/close()` pattern is good scaffolding for future extraction.
3. **No autoloader** — files are `require_once`'d in the main plugin file. Works fine at this scale. When you hit 40+ classes, add a PSR-4 autoloader.

## Verdict

**Solid 0.2.x codebase.** The architecture will scale to 1.0 with the P2 splits above. The P3s are nice-to-haves that prevent future tech debt. Nothing here blocks shipping.

Most impactful next step: **Write tests for `normalize_page()` and `validate_item()`** — these are the data contract that everything else depends on, and they're pure functions that are trivial to test.
