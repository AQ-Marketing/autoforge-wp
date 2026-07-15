# Form-Email Custom Fields + Editable Styling — Implementation Plan

> Implement task-by-task. Checkboxes track progress. PHP/WordPress on Pressable — no unit-test harness, so "tests" = `php -l` + `wp eval` render checks + live end-to-end on AIM.

**Goal:** Any form field flows natively into the notification email (subject token + labeled body row), and 6 email-styling controls (header bg/fg, accent, border color, radius, background) become editable on the Forms screen — released once and rolled to all sites.

**Architecture:** All changes in `AQ_Lead_Capture` (`plugin/aq-core/includes/class-lead-capture.php`) + version bump. `handle()` captures extras into `$f['custom']`; `email_tokens()` renders them as rows + emits a `radius` token; `fill_subject_tokens()` resolves custom tokens; `email_theme()` overlays saved styling; `default_email_template()` tokenizes the card radius; `get_settings()/render()/save()` add the styling settings. Rollout removes per-site fold-hacks.

**Tech Stack:** PHP 8, WordPress, wp-cli, Pressable (SFTP+SSH via paramiko).

---

## Task 1: Capture custom fields (`handle()`)

**File:** Modify `plugin/aq-core/includes/class-lead-capture.php`

- [ ] **Step 1:** In `handle()`, immediately after `$f['tracking'] = self::captured_tracking($req);` add:
```php
		// Custom fields: any submitted field beyond the standard set, sanitized + capped.
		$f['custom'] = self::capture_custom($req);
```
- [ ] **Step 2:** Add the helper (place just after `captured_tracking()`):
```php
	/**
	 * Any submitted field that is NOT a standard/known field or a system/tracking key,
	 * collected in submission order. Sanitized + capped — this is untrusted input.
	 * @return array<string,string>
	 */
	private static function capture_custom(WP_REST_Request $req): array {
		$reserved = ['first_name','firstname','last_name','lastname','name','email','phone','message','service','company','business','website','address','city','state','zip','postal_code','postalcode','source','consent','_wpnonce','action','company_hp','company_url','hp','rest_route'];
		$out = [];
		foreach ((array) $req->get_params() as $k => $v) {
			if (count($out) >= 20) { break; }
			if (!is_string($k)) { continue; }
			$key = preg_replace('/[^a-z0-9_-]/', '', strtolower($k));
			if ($key === '' || in_array($key, $reserved, true)) { continue; }
			if (strpos($key, 'utm_') === 0 || in_array($key, ['gclid','fbclid','msclkid'], true)) { continue; } // tracking handled separately
			if (is_array($v)) {
				$v = implode(', ', array_map('sanitize_text_field', array_map('strval', $v)));
			} else {
				$v = sanitize_textarea_field((string) $v);
			}
			$v = trim($v);
			if ($v === '') { continue; }
			if (mb_strlen($v) > 2000) { $v = mb_substr($v, 0, 2000); }
			$out[$key] = $v;
		}
		return $out;
	}
```
- [ ] **Step 3:** `php -l` after all edits (Task 6 step) → "No syntax errors".

## Task 2: Render custom rows + radius token (`email_tokens()`)

- [ ] **Step 1:** In `email_tokens()`, right after the `foreach ($map ...)` loop that builds `$rows` (ends before the `// Ad / campaign attribution` comment), insert:
```php
		// Custom fields (any extra submitted field) — one labeled row each, in order.
		$custom = isset($f['custom']) && is_array($f['custom']) ? $f['custom'] : [];
		foreach ($custom as $ck => $cv) {
			$cv = (string) $cv;
			if ($cv === '') { continue; }
			$label = ucfirst(str_replace(['_', '-'], ' ', (string) $ck));
			$rows .= '<tr>'
				. '<td style="padding:11px 16px 11px 0;border-bottom:1px solid ' . $line . ';color:' . $muted . ';font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;vertical-align:top;width:120px;font-family:' . $font . '">' . esc_html($label) . '</td>'
				. '<td style="padding:11px 0;border-bottom:1px solid ' . $line . ';color:' . $ink . ';font-size:15px;line-height:1.5;vertical-align:top;font-family:' . $font . '">' . nl2br(esc_html($cv)) . '</td>'
				. '</tr>';
		}
```
- [ ] **Step 2:** In the `return [...]` of `email_tokens()`, add `'radius' => (int) $t['radius'],` alongside the other theme keys.

## Task 3: Resolve custom subject tokens (`fill_subject_tokens()`)

- [ ] **Step 1:** In `fill_subject_tokens()`, after the `$map = [...]` array literal is defined (before `$out = preg_replace_callback(...)`), insert:
```php
		// Merge custom fields so {facility_type}, {frequency}, … resolve too; standard
		// tokens above keep priority on their names.
		if (isset($f['custom']) && is_array($f['custom'])) {
			foreach ($f['custom'] as $ck => $cv) {
				$ck = strtolower((string) $ck);
				if (!array_key_exists($ck, $map)) { $map[$ck] = (string) $cv; }
			}
		}
```

## Task 4: Styling settings + theme overlay

- [ ] **Step 1:** In `get_settings()` default array, add after `'email_logo_h' => 0,`:
```php
			'email_header_bg'    => '',
			'email_header_fg'    => '',
			'email_accent'       => '',
			'email_border_color' => '',
			'email_bg'           => '',
			'email_radius'       => '', // '' = default 14px
```
- [ ] **Step 2:** In `email_theme()`, replace `return array_merge($theme, (array) apply_filters('aq_lead_email_theme', $theme));` with:
```php
		// Admin overlay (AutoForge → Forms → Email styling). Blank/invalid = keep derived.
		$cfg = self::get_settings();
		$hex = static fn ($v) => (is_string($v) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($v))) ? trim($v) : '';
		if ($c = $hex($cfg['email_header_bg'] ?? ''))    { $theme['header_bg'] = $c; }
		if ($c = $hex($cfg['email_header_fg'] ?? ''))    { $theme['header_fg'] = $c; }
		if ($c = $hex($cfg['email_accent'] ?? ''))       { $theme['accent']    = $c; }
		if ($c = $hex($cfg['email_border_color'] ?? '')) { $theme['line']      = $c; }
		if ($c = $hex($cfg['email_bg'] ?? ''))           { $theme['soft']      = $c; }
		$theme['radius'] = ($cfg['email_radius'] ?? '') === '' ? 14 : max(0, min(28, (int) $cfg['email_radius']));
		return array_merge($theme, (array) apply_filters('aq_lead_email_theme', $theme));
```

## Task 5: Tokenize the card radius (`default_email_template()`)

- [ ] **Step 1:** In `default_email_template()`, in the inner 600px card table, change `border-radius:14px;overflow:hidden;` to `border-radius:{{radius}}px;overflow:hidden;` (the `style="max-width:600px;width:100%;background:#ffffff;border:1px solid {{line}};border-radius:14px;overflow:hidden;"` string).

## Task 6: Forms screen — styling panel + save

- [ ] **Step 1:** In `save()`, inside the `array_merge($existing, [ ... ])`, after `'email_logo' => esc_url_raw(...),` add:
```php
				'email_header_bg'    => self::clean_hex($in['email_header_bg'] ?? ''),
				'email_header_fg'    => self::clean_hex($in['email_header_fg'] ?? ''),
				'email_accent'       => self::clean_hex($in['email_accent'] ?? ''),
				'email_border_color' => self::clean_hex($in['email_border_color'] ?? ''),
				'email_bg'           => self::clean_hex($in['email_bg'] ?? ''),
				'email_radius'       => (($in['email_radius'] ?? '') === '') ? '' : (string) max(0, min(28, (int) $in['email_radius'])),
```
- [ ] **Step 2:** Add the helper near `clean_url()`:
```php
	private static function clean_hex($v): string {
		$v = trim((string) $v);
		return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v) ? $v : '';
	}
```
- [ ] **Step 3:** In `render()`, after the "Email logo" `</div>` card, insert the styling card:
```php
			<div class="aq-forms-card">
				<h2>Email styling</h2>
				<p class="aq-forms-hint">Customize the notification email. Leave a field blank to use your brand colors.</p>
				<div class="aq-forms-row">
					<?php
					$colors = [
						'email_header_bg'    => 'Header background',
						'email_header_fg'    => 'Header text / logo',
						'email_accent'       => 'Accent (bar + links)',
						'email_border_color' => 'Border color',
						'email_bg'           => 'Background',
					];
					foreach ($colors as $ck => $clabel) :
						$cv = (string) ($cfg[$ck] ?? '');
					?>
					<div class="aq-forms-field" style="margin-bottom:10px">
						<label><?php echo esc_html($clabel); ?></label>
						<span style="display:inline-flex;align-items:center;gap:8px">
							<input type="color" value="<?php echo esc_attr($cv !== '' ? $cv : '#ffffff'); ?>" data-hex-for="<?php echo esc_attr($ck); ?>" style="width:40px;height:32px;padding:0;border:1px solid #c9cfd6;border-radius:6px;cursor:pointer">
							<input type="text" name="<?php echo esc_attr($ck); ?>" id="aqf-<?php echo esc_attr($ck); ?>" value="<?php echo esc_attr($cv); ?>" placeholder="brand default" style="width:130px" pattern="#?[0-9A-Fa-f]{3,6}">
						</span>
					</div>
					<?php endforeach; ?>
					<div class="aq-forms-field" style="margin-bottom:10px">
						<label>Corner radius (px)</label>
						<input type="number" name="email_radius" min="0" max="28" value="<?php echo esc_attr((string) ($cfg['email_radius'] ?? '')); ?>" placeholder="14" style="width:90px">
					</div>
				</div>
				<script>
				(function(){
					document.querySelectorAll('input[type=color][data-hex-for]').forEach(function(pick){
						var txt=document.getElementById('aqf-'+pick.getAttribute('data-hex-for'));
						if(!txt)return;
						pick.addEventListener('input',function(){txt.value=pick.value;});
						txt.addEventListener('input',function(){ if(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(txt.value)) pick.value=txt.value; });
					});
				})();
				</script>
			</div>
```

## Task 7: Version bump + build + release

- [ ] **Step 1:** In `plugin/aq-core/aq-core.php` header comment bump `Version: 0.3.16` → `0.3.17`; and `define('AQ_CORE_VERSION', '0.3.16');` → `'0.3.17'`.
- [ ] **Step 2:** In `package.json` bump the version to `0.3.17`.
- [ ] **Step 3:** `php -l plugin/aq-core/includes/class-lead-capture.php` and `php -l plugin/aq-core/aq-core.php` (on server after upload) → "No syntax errors".
- [ ] **Step 4:** Build the release zip if the build script exists: `node ~/.claude/scripts/run-quiet.mjs -- npm run build:release` (skip if unavailable — deploy files directly).

## Task 8: Deploy to AIM + remove fold-hack + verify

- [ ] **Step 1:** Back up server `class-lead-capture.php` + `aq-core.php` (`cp *.bak-20260715`), SFTP-upload the two edited engine files to AIM (`/srv/htdocs/wp-content/plugins/aq-core/…`), `php -l` both.
- [ ] **Step 2:** Remove AIM's fold-hack in `theme/aim/aq-sections/contact-form.php`: delete the capture-phase submit script that folds qualifiers into `message`/`service` (lines ~174–201), and the two hidden `<input name="service">`/`<input name="message">` become unnecessary — instead give the real fields their engine names so they post directly (facility_type, services[], facility_size, frequency, timeline, details already have good names; keep them). Upload, `php -l`.
- [ ] **Step 3:** `wp eval` render test on AIM (no send): build `$f` from a simulated submission incl. custom fields; confirm subject resolves a custom token, body shows custom rows, styling overlay applies.
- [ ] **Step 4:** `wp cache flush`; live E2E: load /contact/ as admin, click "Fill with test data", submit; confirm the email shows facility type/frequency/etc. as their own rows (not folded) and the subject resolves.

## Task 9: Deploy to AQM + remove fold-hack + verify

- [ ] **Step 1:** Back up + upload the two engine files to AQM (`aqm-wp` staging). `php -l`.
- [ ] **Step 2:** Remove AQM's chip→`service` fold in `theme/aqm-base/aq-sections/aqm-contact.php`: change the interest chips from `name="interests[]"` + hidden `service` sync to post directly (e.g. `name="interests[]"` captured as a custom "interests" row) OR keep `service` if preferred — verify no double display. Re-verify chips still submit + validate.
- [ ] **Step 3:** `wp cache flush` + re-save contact page; live E2E verify email + no double display.

## Task 10: Version alignment + hygiene

- [ ] **Step 1:** Confirm AIM + AQM both report `AQ_CORE_VERSION 0.3.17` (`wp eval 'echo AQ_CORE_VERSION;'`).
- [ ] **Step 2:** Reconcile repo release hygiene: tag the new release (`git tag v0.3.17`), and note the branch-name/version mismatch for Justin (branch `release/0.3.11` ships `0.3.17`). Do NOT commit/push or cut a GitHub release without Justin's OK.
- [ ] **Step 3:** Report: inventory of AutoForge sites + their versions; anything not yet on 0.3.17.
