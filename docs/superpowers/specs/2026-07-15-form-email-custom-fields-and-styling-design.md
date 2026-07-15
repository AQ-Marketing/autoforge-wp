# AutoForge Form-Email: Custom Fields + Editable Styling — Design Spec

**Date:** 2026-07-15
**Status:** Approved (design), pending implementation plan
**Component:** `plugin/aq-core` — `AQ_Lead_Capture` (lead-capture / notification email) + the AutoForge → Forms admin screen
**Motivation:** AIM Commercial Cleaning — custom form fields (facility type, frequency, size, timeline, services) don't show in the notification email subject/body, and email styling isn't editable. Root cause is engine-wide, and fleet versions have drifted (AQM 0.3.14, AIM 0.3.15, repo 0.3.16).

---

## 1. Problem & goal

The engine's lead-capture email supports only a **fixed field set** and **auto-derived styling**:
- `handle()` captures a fixed whitelist; other submitted fields are dropped.
- `email_tokens()` renders a fixed 13-field `$map` as body rows.
- `fill_subject_tokens()` resolves only ~13 fixed tokens; custom tokens render **literally** (verified live on AIM: `{facility_type}` came through unresolved).
- `email_theme()` derives colors from brand (`themeColor`, `headerStyle`) with hardcoded fallbacks; **border-radius is hardcoded** in the template; nothing is editable.

Because of this, every site hand-rolls a per-site workaround (AIM folds custom fields into `message`/`service` via JS; AQM folds chips into `service`) — divergent and fragile, and none can put a custom field in the subject or as its own body row.

**Goal:** one engine capability so **every** AutoForge site behaves identically —
(A) any submitted form field flows natively into the email (subject token + its own body row), and
(B) the email's key styling (header bg, header text, accent, border color, border radius, background) is editable from the Forms screen — then release once and align all sites.

## 2. Non-goals (YAGNI)
- Pushing custom fields to the CRM (GHL) — **fast follow**, separate task.
- Per-field custom-label UI — auto-humanize the field key instead.
- Full WYSIWYG email builder; body-text-color / font controls (outside the approved core styling set).
- Reworking the SMTP / recipients / test-send parts of the Forms screen.

## 3. Part A — Native custom fields

### 3.1 Capture (`AQ_Lead_Capture::handle()`)
After the existing standard-field capture, collect all remaining POST params into an ordered `custom` map:
- **Exclude** reserved names (`first_name`,`firstName`,`last_name`,`lastName`,`name`,`email`,`phone`,`message`,`service`,`company`,`business`,`website`,`address`,`city`,`state`,`zip`,`postal_code`,`postalCode`,`source`,`consent`) and system keys (`_wpnonce`,`action`,`company_hp`,`company_url`,`hp`). Tracking is captured separately as today.
- **Sanitize (untrusted-input boundary):** key → `preg_replace('/[^a-z0-9_-]/','',strtolower($k))`, ≤64 chars, dropped if empty after cleaning; value → array values `sanitize_text_field`-cleaned and joined with `", "`, scalars `sanitize_textarea_field`, HTML stripped, each field's value truncated to **2000** chars.
- **Cap 20** custom fields (extras ignored). Skip empty values.
- Store as `$f['custom']` = ordered `[ key => value ]` (insertion order = submission order). `$f` continues to carry `tracking` as now.

### 3.2 Body (`email_tokens()`)
After the fixed-`$map` rows and before the tracking rows, append one row per `$f['custom']` entry: label = **humanized key** (`str_replace(['_','-'],' ',$k)` → `ucfirst`), value rendered like the message cell (`nl2br(esc_html())`). Same row markup/styling as standard rows.

### 3.3 Subject (`fill_subject_tokens()`)
Build the token map as today, then **merge** `$f['custom']` under their raw keys (standard tokens keep priority on their 13 names). The existing `preg_replace_callback('/\{([a-zA-Z_]+)\}/', …)` then resolves any custom token; unknown tokens remain literal (unchanged, safe). Existing whitespace/separator cleanup unchanged.

## 4. Part B — Editable email styling

### 4.1 Settings (`get_settings()` defaults → `aq_forms` option)
Add, all empty/`0` by default (**blank = today's brand-derived value; emails byte-identical until customized**):
`email_header_bg`, `email_header_fg`, `email_accent`, `email_border_color`, `email_bg` (colors), `email_radius` (int px).

### 4.2 Resolve (`email_theme()`)
After building the derived `$theme`, overlay each saved styling value **when valid**, before the `aq_lead_email_theme` filter:
- Colors validated `/^#([0-9a-f]{3}|[0-9a-f]{6})$/i`; invalid/blank ignored (keep derived).
- Mapping: `email_header_bg`→`header_bg`, `email_header_fg`→`header_fg`, `email_accent`→`accent`, `email_border_color`→`line`, `email_bg`→`soft`.
- `email_radius` → new `radius` theme key: `max(0, min(28, (int)$v))`; default `14`.
- This is the single resolve point, so both `default_email_template()` and any saved custom `email_template` pick the values up.

### 4.3 Template (`default_email_template()` + `email_tokens()`)
- Replace the hardcoded card `border-radius:14px` with `border-radius:{{radius}}px`.
- Add `'radius' => (int) $t['radius']` to the `email_tokens()` return (colors are already tokens: `{{header_bg}}`,`{{header_fg}}`,`{{accent}}`,`{{line}}`,`{{soft}}`,`{{ink}}`,`{{muted}}`).

### 4.4 Forms screen (`render()` + `save()`)
- New **"Email styling"** panel: for each of the 5 colors a native `<input type="color">` **plus** a hex text input (for paste/clear); a number input (px, min 0 max 28) for radius. Hint: "Leave blank to use your brand colors."
- `save()` (`admin_post_aq_forms_save`, existing nonce + `manage_options`): read the 6 keys, validate colors (blank or `#hex`; else store blank), clamp radius int; persist into the `aq_forms` option alongside existing settings.

## 5. Safety & compatibility
- Part A is the only untrusted surface — caps + sanitization in §3.1; honeypot/system keys never captured; rate-limit + origin checks in `handle()` unchanged.
- Part B is admin-only (`manage_options` + nonce), validated on save.
- **No behavior change when unconfigured:** no custom fields submitted → body/subject identical to today; blank styling → theme identical to today (radius default 14 matches the current hardcoded value).

## 6. Rollout & version alignment
1. Implement in the `AutoForge WP` product repo; bump `0.3.16 → 0.3.17` (aq-core.php header + `AQ_CORE_VERSION` + `package.json`), `npm run build:release`, cut a GitHub release.
2. **Fix release hygiene:** the working branch is named `release/0.3.11` but ships `0.3.16`; git tags stop at `v0.3.9` while sites run 0.3.14/0.3.15. Tag the new release properly and reconcile branch naming so the repo is a clean source of truth.
3. **Inventory all AutoForge sites** and their engine versions (start from known: AIM 0.3.15, AQM 0.3.14).
4. Deploy **0.3.17** to every site. Per site, in order: deploy → **remove that site's fold-hack** (AIM: the `message`/`service` folding script in `aq-sections/contact-form.php`; AQM: the chip→`service` hidden-field fold in `aqm-contact.php`) so raw custom fields flow natively with no double-display → verify.
5. Deploy respects each site's rules (Pressable SFTP+SSH; back up, `php -l`, `wp cache flush`, verify live). Do not hand-patch a single site's engine — fix in the engine and re-release (per the /wordpress "one engine everywhere" rule).

## 7. Testing
No PHP unit harness in this repo → `php -l` + `wp eval` render tests + live verification (project norm).
- **Custom fields:** custom token resolves in subject; each custom field appears as its own labeled body row; cap at 20; key/value sanitized (HTML stripped, length capped); array (`services[]`) joined; empty skipped; reserved keys excluded; standard-only submission byte-identical to today.
- **Styling:** blank settings → theme + rendered HTML identical to today (radius = 14); each color overlay applies; invalid hex ignored; radius clamps to 0–28; `{{radius}}` reflected in the card.
- **Live E2E on AIM:** submit via the admin test-fill button; confirm the email shows facility type / frequency / size / timeline as their own rows and the chosen styling; subject resolves its tokens. Then confirm AIM's fold-hack removal didn't double- or drop-render.

## 8. Files touched
- `plugin/aq-core/includes/class-lead-capture.php` — `handle()` (capture), `email_tokens()` (rows + radius token), `fill_subject_tokens()` (custom tokens), `email_theme()` (styling overlay + radius), `default_email_template()` (radius token), `get_settings()` (new keys), `render()` + `save()` (styling panel).
- `plugin/aq-core/aq-core.php` + `package.json` — version bump.
- Per-site theme (rollout): remove fold-hacks — AIM `theme/aim/aq-sections/contact-form.php`; AQM `theme/aqm-base/aq-sections/aqm-contact.php`.
