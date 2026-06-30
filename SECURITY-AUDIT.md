# AutoForge WP — Security Audit Report

**Date:** 2026-06-30  
**Version:** 0.2.19  
**Scope:** All core plugin PHP/JS (89 files, 731KB). Excludes `thrust/` (WP Rocket fork).

## Summary

The plugin has **strong security fundamentals** — every REST route is capability-gated, CSRF is handled properly on form submissions, API keys are encrypted at rest, and the SSRF vector in the importer is properly locked to GitHub-only URLs. However, there are a few issues worth addressing before wider deployment.

## Findings

| Severity | File | Area | Issue | Exploit Path | Recommended Fix |
|----------|------|------|-------|-------------|-----------------|
| **Medium** | `render/sections/raw-html.php` | XSS (Stored) | Outputs `$s['html']` with zero escaping | Requires `manage_options` to save. A compromised admin gets persistent XSS on the front end. Mirrors WP's own Custom HTML block. | Document as intentional (admin-only). Add `wp_kses_post()` for multi-admin installs. |
| **Medium** | `includes/class-tracking.php` L109, L126, L136 | XSS (Stored) | Custom head/body_open/footer snippets output raw | Same — requires `manage_options` + CSRF token to save. Intentional by design (custom script injection is the feature). | Document clearly. Mirrors WP's Script widget pattern. |
| **Medium** | `includes/class-updater.php` | No integrity verification | Downloads zip from GitHub releases with no signature/hash check | Compromised GitHub repo → backdoored plugin on all client sites via auto-update. | Enable branch protection + require review on main. Optionally add hash manifest or signed releases. Same risk as any GitHub-distributed plugin. |
| **Low** | `includes/class-integrations.php` L124 | Weak fallback encryption | Without OpenSSL → base64 only (trivially reversible) | DB read access → decode all API keys. PHP without OpenSSL is extremely rare. | Already warns in admin. Consider refusing storage without OpenSSL. |
| **Low** | `includes/class-content-sync.php` | Custom capability `aq_agency` | REST import/export uses custom cap — if never assigned, endpoints are inaccessible (safe). | Minimal risk — WP denies unknown caps by default. | Document where `aq_agency` is assigned. |
| **Low** | `includes/class-importer.php` L219 | Zip Slip | `$za->extractTo($dest)` without checking for `../` in entry names | GitHub's own zipball strips these, but defence-in-depth matters for edge cases. | Add 5-line loop to reject entries containing `..` before extraction. |
| **Info** | `includes/class-assistant.php` | Prompt injection | Admin user messages sent to OpenAI without sanitization | Output is NEVER auto-saved. User reviews before Save. Structured validation blocks unknown types. | Acceptable. The no-auto-save design is correct. |

## Positive Findings (things done right)

- ✅ Every REST route has a `permission_callback` — no open endpoints
- ✅ All form submissions use `wp_nonce_field()` + `check_admin_referer()`
- ✅ Consistent `manage_options` gating across all admin screens
- ✅ Importer regex-validates GitHub URLs only; rejects arbitrary URLs (no SSRF)
- ✅ API keys encrypted at rest (AES-256-CBC, key from WP salts)
- ✅ Keys never exposed to browser (password fields, masked placeholders)
- ✅ `normalize_page()` strips unknown keys before persistence
- ✅ Editor save only persists known section types from `field_schema()`
- ✅ Uses `$wpdb->prepare()` for raw queries; WP APIs elsewhere (no SQL injection)
- ✅ Templates consistently use `esc_html()`, `esc_attr()`, `wp_kses_post()`
- ✅ NoIndex defaults to true (staging-safe)
- ✅ Editor canvas nonce + `manage_options` required

## Verdict

**Safe to ship for single-admin client sites.** The Medium findings mirror WordPress core patterns (Custom HTML block, Script widgets). For multi-admin installs, document the raw-html section and tracking snippet behavior.

Most actionable fix: Zip Slip defence in `class-importer.php` — 5 lines for meaningful defence-in-depth.
