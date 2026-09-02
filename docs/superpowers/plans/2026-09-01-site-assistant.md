# Site Assistant + Knowledge Pack (aq-core v0.3.50) Implementation Plan

> **For agentic workers:** implement task-by-task; steps use `- [ ]`. Tests run with the local PHP (non-default path — see Task 0) or on ACME staging via `wp eval-file`.

**Goal:** An admin-only, live-site **SEO-guardian assistant** for AutoForge sites: a logged-in admin points at any editable text on the real page, says what they want changed, and a server-side guardian (Claude with the full client knowledge pack, then deterministic SEO rules that can only tighten the verdict) returns **Safe → Apply / Adjusted → pick a rewording / Blocked → explain + "update the plan first"**, writes one field through the existing write path, and logs it with undo. Nobody — client or agency — can break the SEO plan from chat. Plus the **knowledge pack** (extended `seo-intents.json` keys + `client-brief.md`) and an agency-editable **Knowledge** screen.

**Architecture:** New `AQ_Assistant_Rules` (pure deterministic R1–R8, unit-tested), `AQ_Assistant` (activation on the live page for `manage_options`, marker enablement, panel enqueue, REST context/message/apply/undo, the one Claude call + rules pipeline, conversation transient, apply via `AQ_Content_Sync::update_sections` / SEO-manager fields, capped log + undo), `AQ_Knowledge` (the `aq_knowledge` brief option + agency-editable screen), plus `admin/assistant/assistant.js|css` (the front-end panel with point-and-ask selection). Reuses: the builder's `ka_field_attr`/`data-aq-field` markers (`aq_render_section_markers` filter), `AQ_Editor::field_schema()`/`layout_labels()`, `AQ_Editor_Review::company_profile()`/`seo_context()`, `AQ_Content_SEO_Gate::evaluate()`, `AQ_Claude` (0.3.49 upgrades: caching, effort, refusal). Depends on making `AQ_Content_Sync::seo_inventory()` public.

**Tech Stack:** PHP 8.0+ WordPress plugin (no SDK), WP REST (`aq/v1`), vanilla JS front-end panel, the mini test harness from v0.3.49 (`tests/lib/`).

**Spec:** `docs/superpowers/specs/2026-09-01-site-assistant-knowledge-pack-design.md` (read it).
**Worktree/branch:** `C:\Users\justi\Apps\Work\AutoForge-WP-assistant` on `feat/site-assistant` (rebased onto `main` @ v0.3.49). **Public repo — no secrets.**

---

## File map

| File | Responsibility |
|---|---|
| `plugin/aq-core/includes/class-assistant-rules.php` (new) | Pure deterministic guardian rules R1–R8 → `{verdict, findings[]}` |
| `plugin/aq-core/includes/class-assistant.php` (new) | Activation, markers, panel enqueue, REST, Claude+rules pipeline, apply/undo/log |
| `plugin/aq-core/includes/class-knowledge.php` (new) | `aq_knowledge` brief store + agency-editable Knowledge screen + page-record edit |
| `plugin/aq-core/admin/assistant/assistant.js` (new) | Front-end panel: launcher, point-and-ask, thread, proposal/blocked cards, apply/undo |
| `plugin/aq-core/admin/assistant/assistant.css` (new) | Panel + selection-overlay styles (namespaced `.aq-asst-*`) |
| `plugin/aq-core/includes/class-content-sync.php` (edit) | `seo_inventory()` private→public; `seo_manifest()` also loads `client-brief.md`; REST `client_brief` |
| `plugin/aq-core/includes/class-admin-hub.php` (edit) | Add `aq-knowledge` to the SEO nav group; `aq-assistant` link |
| `plugin/aq-core/includes/class-help.php` (edit) | Assistant + Knowledge Help topics |
| `plugin/aq-core/aq-core.php` (edit) | require + register `AQ_Knowledge`, `AQ_Assistant`; version → 0.3.50 |
| `package.json` (edit) | version → 0.3.50 |
| `tests/assistant-rules-test.php` (new) | R1–R8 fixtures red→green |
| ACME content repo: `content/seo-intents.json` (+ extended keys), `content/client-brief.md` (new) | The knowledge pack data for ACME |

---

### Task 0: worktree ready
- [ ] `cd` worktree; `npm ci` (done); confirm PHP: prepend `export PATH="$PATH:/c/Users/justi/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe"`; tests run with `-d extension_dir="…/ext" -d extension=mbstring`.

### Task 1: `AQ_Assistant_Rules` (pure R1–R8) — TDD
The heart of the guardian: given the page **before/after** a single-field change, the plan record, the site inventory, and the field kind, return `{verdict: 'safe'|'adjusted'|'blocked', findings: [{rule,severity,message}]}`. Severity ladder `ok < caution < block` maps to verdict `safe < adjusted < blocked` (worst finding wins). Rules can only *raise*.

Rules (from spec §5.6): R1 primary keyword lost from title/H1/first-100-words (block); R2 a `secondary_keywords` term lost from the page (caution); R3 a plan `entities` item / brand / phone lost from the field (block); R4 richtext links fewer or destination changed (block; adding ok); R5 heading-kind field emptied (block), page words −10..−25% (caution), beyond −25% or below `target_words` lower bound (block); R6 em dash / banned slop phrase / primary-keyword density >3% (caution); R7 the site gate (`AQ_Content_SEO_Gate::evaluate` with the proposed page swapped in) any finding (block); R8 page-SEO title>60 or description outside 120–155 (caution), primary keyword absent from title/description after (block). See spec for the exact wording of each `message`.

- [ ] Write `tests/assistant-rules-test.php` with a fixture per rule (red), then implement `class-assistant-rules.php` to green. Full code lives in the build (this session writes it directly). Target: all rule fixtures pass.

### Task 2: knowledge storage + `AQ_Knowledge` screen
- [ ] Make `AQ_Content_Sync::seo_inventory()` public (one keyword change).
- [ ] `seo_manifest()` also returns `brief` from a sibling `client-brief.md`; `import_path`/REST persist it to option `aq_knowledge = {brief, source, updated_at, updated_by}`; absence never clears.
- [ ] `class-knowledge.php`: `AQ_Knowledge::brief()`, `can_edit()` (`manage_options` AND `@AQ_AGENCY_EMAIL_DOMAIN`), REST `POST aq/v1/knowledge/brief` + `POST aq/v1/knowledge/page/{id}` (validate the page record with the gate's `normalize_intent` rules before `update_post_meta('_aq_content_intent')`), and a **Knowledge** admin screen (rendered markdown brief + textarea; per-page plan table; read-only for non-agency). Register + nav entry.

### Task 3: `AQ_Assistant` — activation, REST, guardian, apply/undo/log
- [ ] `active()` gating (front end, `is_singular('page')`, `manage_options`, not canvas, enabled, `AQ_Claude::is_ready()`); on `template_redirect` pri 2 turn on `aq_render_section_markers` + enqueue `assistant.js|css` with a localized bootstrap (rest root, nonce, pageId, labels from `AQ_Editor`); admin-bar "Assistant" node.
- [ ] REST (all `manage_options`+`edit_post`+nonce): `GET /assistant/context/{id}`, `POST /assistant/message`, `POST /assistant/apply`, `POST /assistant/undo`.
- [ ] Guardian pipeline (`message`): assemble the cached stable prefix (rules rubric = the nine `seo-humanize` invariants + slop list; `aq_knowledge` brief; `company_profile()`; tracked keywords; site inventory summary) + per-message part (this page's fields w/ addresses, plan record, selection, thread) → one `AQ_Claude::message()` (Opus 5 default, `cache_system`, effort high) forcing one of tools `propose_change` / `answer` / `need_selection` → every `propose_change` (and alternatives) re-checked by `AQ_Assistant_Rules` (raise-only) → store proposal server-side (transient, 1 h) → return cards.
- [ ] `apply`: reload live sections, hash-guard (md5 of canonical `_`-stripped JSON), re-run rules, write **one field** via `AQ_Content_Sync::update_sections` (or SEO-manager `update_field` for `seo.title`/`seo.description`), purge caches (`AQ_Performance::purge_caches()`), append to capped `aq_assistant_log` (500), return applied. `undo`: reverse from the log entry through the same path+rules.
- [ ] Wire into `aq-core.php`; version 0.3.50.

### Task 4: `assistant.js` + `assistant.css` (front-end panel) — subagent
- [ ] Launcher (bottom-right, above sticky bar), side panel with thread + composer + "Point at something" + "Page SEO" chip; selection mode outlines `[data-aq-field]` inside `[data-aq-section]` (label like `canvas.js#fieldLabel`), captures `{sectionIndex,layout,field,repeater,rindex,text}`; proposal cards (before→after, verdict pill, reason, alternatives, Apply), blocked cards (reason + plan rule + Knowledge link), Undo on the last change; DOM updates in place on apply; focus trap, Esc, reduced-motion, ARIA live. Vanilla JS, namespaced `.aq-asst-*`.

### Task 5: settings + Help
- [ ] `AQ_Assistant` settings screen (`aq-assistant`): on/off, model, daily cap, status lights (Claude connected · brief present · N/M pages have full plan rows), recent log w/ undo. Help topics.

### Task 6: ACME knowledge back-fill + deploy + verify
- [ ] Back-fill ACME `content/seo-intents.json` extended keys + `content/client-brief.md` from the audit report + built pages (home + service + town pages at least); `wp aq import --dry-run` passes the gate first; import to staging.
- [ ] Deploy the new/changed files to ACME staging (1761035) from the pushed commit; run `assistant-rules-test.php` on the host; verify live per spec §7: parity, launcher, Safe/Adjusted/Blocked, undo, stale, page-SEO, limits, unmarked text.

### Task 7: release
- [ ] Bump 0.3.50, lint, build, verify on staging, merge `main`, `gh release v0.3.50`. (Gated on Justin's OK — this reaches the fleet.)

---

## Notes
- The assistant is **off without a Claude key** (no rules-only write path — unlike the builder gate). ACME staging now has a Claude key.
- Marker coverage: ACME's 16 bespoke templates already emit `ka_field_attr`; unmarked text → "open in builder".
- Do not change the builder's existing agency-bypass (out of scope; §9 open item).
