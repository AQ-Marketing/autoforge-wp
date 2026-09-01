# AutoForge Alt Text Generator — Design Spec

**Date:** 2026-09-01
**Status:** Approved (design), pending implementation plan
**Component:** `plugin/aq-core` (AutoForge engine) — new `includes/class-alt-text.php` (`AQ_Alt_Text`), plus shared-client upgrades in `includes/class-claude.php` (`AQ_Claude`)
**Target release:** aq-core **v0.3.49** (plugin-only; theme unchanged)
**Branch / worktree:** `feat/alt-text` at `C:\Users\justi\Apps\Work\AutoForge-WP-alt-text` (cut from `origin/main` @ v0.3.48)
**Sibling spec:** `2026-09-01-site-assistant-knowledge-pack-design.md` (v0.3.50) depends on the `AQ_Claude` upgrades in §4.6 — ship this first.

---

## 1. Purpose

Images reach an AutoForge site three ways — an upload in wp-admin, the builder's media
picker, or a content-repo import (`wp aq import` / AutoForge → Import, which sideloads
`content/media/*.webp`). **None of them writes alt text.** The only alt the engine ever sets
is a blog post's featured image (`class-content-sync.php`, `hero.alt`). Section images
render through `wp_get_attachment_image()`, which reads the attachment's library alt — so
an image with no library alt ships with `alt=""` and fails the `page-complete` Gate A8
("every meaningful image has descriptive alt").

This spec adds an **automatic, honest alt-text writer**: every image that lands in the
media library with an empty alt gets a short description written by Claude, in the
background, plus a one-click backfill for the existing library. It never overwrites alt a
human wrote.

## 2. Goals / Non-goals

**Goals**
- Alt text on every new image within ~1 minute of arrival, regardless of upload path.
- A **"Generate missing alt text"** backfill that clears an existing library in batches.
- Descriptions that pass the QC bar: what is actually visible, ≤ ~125 chars, no
  "image of…", no keyword stuffing, no invented claims, decorative images marked as such.
- Zero impact on upload speed or visitor page loads; zero bytes of front-end output.
- Client-agnostic: context comes from `aq_site()`; nothing industry-specific baked in.

**Non-goals (YAGNI)**
- Rewriting alt text a human typed (no "regenerate all" mode — decided with Justin).
- Captions, titles, descriptions, or filename renames.
- Per-page keyword targeting of alt text (alt is a media-library property; stuffing
  keywords into it is a known penalty pattern).
- Video, PDF, or SVG description. SVG/AVIF are skipped (the API accepts jpeg/png/gif/webp).

## 3. Decisions locked with Justin (2026-09-01)

| # | Decision | Choice |
|---|---|---|
| 1 | Existing library | **Fill empty only + one-click backfill.** Never overwrite human alt. |
| 2 | Default model | **`claude-opus-5`** (best judgement); `claude-haiku-4-5` and `claude-opus-4-8` selectable per site. |
| 3 | Default state | **On** as soon as `AQ_Claude::is_ready()` (a key or key-proxy is configured); dormant otherwise. |

## 4. Architecture

Client-agnostic engine code in `plugin/aq-core`; per-site state in `autoload=false`
options + attachment meta. No new tables, no third-party SDK (the engine's one Claude
client is plain `wp_remote_post`; keep it that way).

### 4.1 Trigger

Hook the **`wp_generate_attachment_metadata`** filter (priority 20, 3 args:
`$metadata, $attachment_id, $context`). WordPress runs it after sub-sizes are generated on
every arrival path: `media_handle_upload` (wp-admin + builder picker), REST
`POST /wp/v2/media`, `media_handle_sideload` (both engine importers), and WP-CLI
`media import`. The filter must **return `$metadata` unchanged** — it only enqueues.

Eligibility (all must hold, else silently skip):
- mime ∈ {`image/jpeg`, `image/png`, `image/gif`, `image/webp`};
- `_wp_attachment_image_alt` is empty **and** `_aq_alt_decorative` is not set;
- `AQ_Alt_Text::enabled()` (setting on **and** `AQ_Claude::is_ready()`).

### 4.2 Queue + runner (never on the request that uploaded)

- **`aq_alt_queue`** option (`autoload=false`): ordered, de-duplicated list of attachment
  IDs with `queued_at` and `attempts`.
- Enqueue → `wp_schedule_single_event(time() + 30, 'aq_alt_text_run')` if not already
  scheduled. The runner processes up to **8** items per run inside a **~40 s** time
  budget, then reschedules itself if items remain.
- **Fallback for lazy hosts:** on `admin_init`, if the queue is non-empty and the last run
  is > 90 s old, call `spawn_cron()` (WordPress's own non-blocking loopback). No
  dependency on the Boost module's bundled Action Scheduler.
- **Synchronous batch entry points** (same worker function):
  - `POST aq/v1/alt-text/run` `{ limit≤10, missing:true }` — powers the dashboard button
    (JS polls until `remaining=0`, mirroring the importer's batch pattern).
  - WP-CLI `wp aq alt-text [--missing] [--limit=<n>] [--dry-run]` registered via
    `WP_CLI::add_command('aq alt-text', …)` so it hangs off the existing `aq` parent with
    no coupling to `AQ_Content_Sync`. Useful right after a large import.
- "Missing" mode enumerates eligible attachments directly (`WP_Query` on
  `post_type=attachment`, mime prefix `image/`, meta `_wp_attachment_image_alt` absent or
  empty, `_aq_alt_decorative` absent), paged by `limit`.

### 4.3 Generation

**Image payload:** prefer the `large` (1024px) sub-size, else `medium_large`, else the
original if ≤ 5 MB; read the file from disk (`get_attached_file()` + size path) and send as
a **base64 image block** — works on password-protected staging and local installs where a
URL fetch would not. Skip with a logged reason if no usable file ≤ 5 MB exists.

**Context sent (all from site data, never hardcoded):** business `name`, `industry`,
`address.locality/region`, the first few `towns`, the attachment's filename (stem,
de-slugged), and — when the attachment has a `post_parent` page — that page's title. That
is enough for "a ranch-style house in southern New Hampshire" without inventing anything.

**Instruction rules (system prompt):**
- Describe only what is visible; 1 sentence, ≤ 125 characters, no trailing period
  required, sentence case.
- Never start with "Image of", "Picture of", "Photo of"; never repeat the filename.
- No marketing claims, no superlatives, no numbers/awards not visible in the image.
- Use a town, service, or brand name **only** when it is genuinely part of what the image
  shows (a lettered truck, a storefront sign) — never as SEO seasoning.
- If the image is purely decorative (abstract texture, gradient, divider, icon-like
  ornament), say so instead of describing it.
- No em dashes.

**Structured answer:** a forced tool call `set_alt_text` with
`{ alt: string, decorative: boolean, confidence: 'high'|'medium'|'low' }` — the house
pattern (`AQ_Claude::tool()` + `tool_choice` `tool`), no free-text parsing.

**Model:** per-site setting, default `claude-opus-5`; validated through
`AQ_Claude::resolve_model()`. `max_tokens` 300, timeout 45 s. Thinking left at the model
default (adaptive on Opus 5).

### 4.4 Storage and markers

On success:
- `_wp_attachment_image_alt` ← `alt` (sanitized, trimmed to 200 chars) — or `''` when
  `decorative=true`, plus `_aq_alt_decorative=1` so it is never re-queued.
- `_aq_alt_source=ai`, `_aq_alt_at=<unix>`, `_aq_alt_model=<id>`,
  `_aq_alt_confidence=<high|medium|low>`.

Rules:
- **Write only when the alt is empty at write time** (re-checked inside the runner — a
  human may have typed one while the item sat in the queue).
- A later human edit simply replaces the alt; the `_aq_alt_*` markers stay as provenance.
- Known behaviour: if a human *clears* an AI alt, a later backfill will write a new one.
  Acceptable for v1 (the fix would be a "skip" marker; add only if it bites).

### 4.5 Settings

Option **`aq_alt_text`** (`autoload=false`):

| key | default | notes |
|---|---|---|
| `enabled` | `true` | Effective only when `AQ_Claude::is_ready()` |
| `model` | `claude-opus-5` | Any id in `AQ_Claude::models()` |
| `daily_cap` | `300` | Generations per site per day; over cap → items stay queued until tomorrow |

Daily counter: `aq_alt_daily` option `{ day: 'Y-m-d', count }`.

### 4.6 Shared `AQ_Claude` upgrades (land in this release; the assistant relies on them)

All backward-compatible; existing callers (`AQ_Editor_Review`, `AQ_SEO_Agent`) unchanged.
Wire shapes come from the Anthropic Messages API reference (the `claude-api` skill,
`curl/examples.md`) — verify each against it while implementing, not from memory.

1. `models()` adds **`claude-opus-5`** ("Claude Opus 5 (newest, most capable)") and it
   becomes the engine default `MODEL`. Behaviour change to call out in the release note:
   callers that pass no model (today only the SEO Agent's report narrative) move from
   Opus 4.8 to Opus 5; `AQ_Editor_Review` keeps its own Sonnet 5 default.
2. **Image input helper** `AQ_Claude::image_block(string $path): ?array` → base64 image
   content block (`{type:'image', source:{type:'base64', media_type, data}}`) or `null`
   when the file is unreadable/too large. `message()` already passes `messages` through
   untouched, so content arrays with image blocks work without other changes.
3. **Prompt caching:** `$args['cache_system'] = true` sends `system` as a block array with
   `cache_control: {type:'ephemeral'}` on the (single) stable block. Nothing else is
   cached; volatile per-request content stays in `messages`.
4. **Effort:** `$args['effort']` → `output_config.effort` (`low|medium|high|xhigh|max`).
5. **Return more:** the normalized result gains `content` (raw blocks, needed to echo
   `thinking` blocks back on any future multi-step call) and `usage`
   (`input_tokens`, `output_tokens`, `cache_read_input_tokens`,
   `cache_creation_input_tokens`) so screens can show cost and cache hit rate.
6. **Refusal handling:** `stop_reason === 'refusal'` → `WP_Error('aq_refusal', …)` with a
   friendly message; callers treat it like any failure (alt: mark `_aq_alt_fail`, don't
   retry more than 3×).
7. **Opus 5 fallbacks:** when the resolved model is `claude-opus-5` (direct mode), send
   header `anthropic-beta: server-side-fallback-2026-07-01` and body `fallbacks: 'default'`
   so a safety-classifier refusal routes to a fallback model instead of failing. In
   key-proxy mode pass the same header; **open item:** confirm the `aq-claude-proxy`
   Worker forwards `anthropic-beta` (or add it there).

## 5. Admin UI

**New screen: AutoForge → Content → Media** (`aq-media`, `manage_options`), rendered via
`AQ_Admin_Hub::open()` in the house style, and added to `AQ_Admin_Hub::nav()` under the
**Content** group (remember: both `add_submenu_page` **and** `nav()` must be edited).

Card "Alt text":
- Status line: *Claude connected / not configured* (links to Integrations).
- Counts: **images in library · missing alt · written by AutoForge · marked decorative**.
- Toggle "Write alt text for new uploads automatically", model picker, daily cap.
- **"Generate missing alt text"** button → runs `POST aq/v1/alt-text/run` in batches of
  10 with a progress bar and a running list of `filename → alt` results; disabled with a
  hint when Claude isn't configured. Shows "N left today" when near the cap.
- Recent results table (last 25): thumbnail, alt, confidence, when — each row links to
  the WP attachment edit screen so a human can fix wording.

Settings save via `admin_post_aq_alt_text_save` + `check_admin_referer` (house pattern —
no admin-ajax). **Help topic** added to `AQ_Help`: "Alt text — what it is, why it matters,
what AutoForge writes and what it never touches."

## 6. Security and cost

- Every REST route has a `permission_callback` (`manage_options`); the WP-CLI command runs
  under CLI trust. No front-end output, no visitor-triggerable path (uploads already
  require `upload_files`).
- The API key never leaves the server (`AQ_Claude` contract). Image bytes go to Anthropic
  (or the key proxy) — the same data class the site already publishes.
- Cost: ~1.2k input tokens/image at the `large` size + ~60 output → roughly **$0.01–0.02
  per image on Opus 5** (≈$0.002 on Haiku). ACME's 75-image library ≈ $1. The daily cap
  bounds a runaway (e.g. a 2,000-image bulk import) to `daily_cap` per day.

## 7. Failure handling

| Situation | Behaviour |
|---|---|
| No Claude key / proxy | Items are not enqueued; Media card shows "not configured". |
| API/HTTP error or refusal | `attempts++`, re-queued with backoff (2 min → 10 min → 1 h); after 3 failures `_aq_alt_fail=<reason>` and dropped; listed on the Media card. |
| Daily cap reached | Items stay queued; runner reschedules for the next day. |
| Unsupported mime / oversize | Skipped with `_aq_alt_skip=<reason>`; never re-queued. |
| Tool call missing/malformed | Treated as an API error (retry path). |
| Alt filled by a human meanwhile | Runner re-checks and skips (no write). |

## 8. Verification (ACME staging, Pressable 1761035)

No PHP binary on the dev machine: `npm run lint` locally; behaviour verified live.
1. Update staging to the built zip; confirm Media card renders with correct counts.
2. Upload a real job photo via wp-admin → within ~1 min the attachment shows a sensible
   alt, `_aq_alt_source=ai`; upload time unchanged (queue, not inline).
3. Upload via the builder's media picker and via `wp aq images` → same result (proves all
   three arrival paths).
4. Backfill: click "Generate missing alt text" → ACME's ~75 imported images clear in
   batches; progress bar and results list behave; re-clicking reports 0 missing.
5. Human alt preserved: set a custom alt on one image, run backfill → untouched.
6. Decorative: upload a plain gradient/texture → `alt=""` + `_aq_alt_decorative=1`.
7. Negative: remove the Claude key → uploads still work, nothing queued, card explains.
8. Front end: logged-out HTML for `/` and a service page byte-identical before/after
   (the feature emits nothing on the front end). Rendered `<img alt>` now populated for
   backfilled section images.
9. WP-CLI: `wp aq alt-text --missing --dry-run` lists candidates without writing.

## 9. Rollout

1. Implement on `feat/alt-text`; bump `Version` + `AQ_CORE_VERSION` in `aq-core.php` and
   `package.json` to **0.3.49**; `npm run build:release`; `npm run lint` clean.
2. Verify on ACME staging per §8, then merge to `main` and cut GitHub release `v0.3.49`
   (plugin zip; theme unchanged). Every site picks it up via the normal updater.
3. Run the backfill on ACME staging (and later on each live site as it updates).
4. `/wordpress` skill: add one line to the pre-launch checklist — "run *Generate missing
   alt text* after the media import; confirm 0 missing" — so new builds never ship
   alt-less images.

## 10. Open items

- Confirm the key-proxy Worker forwards the `anthropic-beta` header (fallbacks on Opus 5).
- Confirm WP-Cron actually fires on Pressable staging; the `spawn_cron()` admin fallback
  covers it either way, but note the observed latency in the implementation plan.

## 11. Related

- `class-content-sync.php` (`hero.alt` — the only existing alt write; `images()` sideload)
- `class-importer.php` (`media_handle_sideload`, batch-across-requests pattern reused for the backfill UI)
- `class-integrations.php` (key storage pattern), `class-claude.php` (single Claude client)
- `page-complete` skill → `reference/checks.md` §A8 (the alt-text bar this satisfies)
- Sibling spec: `2026-09-01-site-assistant-knowledge-pack-design.md`
