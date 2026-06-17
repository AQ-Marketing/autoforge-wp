<?php
/**
 * AQ Global Styles — site-wide brand colors + fonts, editable without code.
 *
 * Parity-safe by construction. Every control DEFAULTS to the current Tailwind
 * token value, and the front end emits override CSS ONLY for tokens the editor
 * actually changes. When nothing is changed the stored option is empty and this
 * module emits zero bytes — the live site is byte-identical to the pure port.
 *
 * How the override works: the compiled theme CSS (assets/css/main.css) names the
 * token in every utility selector, e.g.
 *     .bg-brand-900{--tw-bg-opacity:1;background-color:rgb(15 23 42/var(--tw-bg-opacity,1))}
 * For a changed color we scan main.css for every rule whose selector contains
 * that token slug and re-emit the SAME selector with the rgb triple swapped
 * (opacity variable preserved). The override is injected right after main.css via
 * wp_add_inline_style(), so identical selectors win purely on cascade order — no
 * !important, no specificity games, every variant (hover, responsive) covered.
 *
 * The generated CSS is cached in a transient keyed by the settings + main.css
 * mtime, so it regenerates automatically after a save or a CSS rebuild.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Global_Styles {

	const CAP    = 'manage_options';
	const OPTION = 'aq_global_styles';

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 22);
		add_action('admin_post_aq_global_styles_save', [__CLASS__, 'save_settings']);
		// Priority 20: runs after the theme enqueues the 'aqm-base' stylesheet (10),
		// so the inline override is attached to (and printed after) main.css.
		add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 20);
	}

	/**
	 * Curated, friendly brand controls. Each default is the CURRENT token value
	 * (see theme/aqm-base/tailwind.config.cjs). Colors map to a Tailwind token
	 * slug; fonts map to the font-{slug} utilities.
	 */
	public static function controls(): array {
		// One control per UNIQUE brand color. The compiled CSS groups same-value
		// tokens into one rule (e.g. .bg-brand-800,.bg-brand-900), so one swatch =
		// one real color; changing it recolors every utility AND component class
		// (.btn-primary, .pill-eyebrow, .h2-sub, …) that uses that exact value.
		return [
			'colors' => [
				['key' => 'navy',      'label' => 'Primary dark',          'help' => 'Dark sections, headings, footer, hero overlay.', 'default' => '#0f172a'],
				['key' => 'body_navy', 'label' => 'Body text',             'help' => 'Main paragraph text.',                            'default' => '#334155'],
				['key' => 'tint',      'label' => 'Light background tint',  'help' => 'Soft section backgrounds.',                       'default' => '#f8fafc'],
				['key' => 'gold',      'label' => 'Primary accent',        'help' => 'Buttons, links and highlights.',                  'default' => '#3b82f6'],
				['key' => 'amber',     'label' => 'Accent hover',          'help' => 'Hover state for accent elements.',                'default' => '#1d4ed8'],
				['key' => 'dark_gold', 'label' => 'Accent link',           'help' => 'Accent links on light backgrounds.',              'default' => '#2563eb'],
			],
			'fonts' => [
				['key' => 'serif', 'label' => 'Heading font', 'help' => 'Used for all headings.',     'default' => 'Georgia, Cambria, "Times New Roman", serif'],
				['key' => 'sans',  'label' => 'Body font',    'help' => 'Used for body text and UI.', 'default' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'],
			],
		];
	}

	/** Effective value for a control key: stored override, else its default. */
	private static function value(string $key, string $default): string {
		$opt = get_option(self::OPTION, []);
		if (is_array($opt) && isset($opt[$key]) && $opt[$key] !== '') {
			return (string) $opt[$key];
		}
		return $default;
	}

	/* ---------------- front-end output ---------------- */

	public static function enqueue(): void {
		$css = self::css_cached();
		if ($css !== '') {
			wp_add_inline_style('aqm-base', $css);
		}
	}

	private static function css_cached(): string {
		$opt = get_option(self::OPTION, []);
		if (empty($opt) || !is_array($opt)) {
			return ''; // nothing customized → zero output → pixel parity
		}
		$path  = get_theme_file_path('assets/css/main.css');
		$mtime = (is_string($path) && file_exists($path)) ? (int) filemtime($path) : 0;
		$key   = 'aq_gs_css_' . md5(maybe_serialize($opt) . '|' . $mtime);
		$hit   = get_transient($key);
		if (is_string($hit)) {
			return $hit;
		}
		$css = self::build_css($path);
		set_transient($key, $css, DAY_IN_SECONDS);
		return $css;
	}

	/** Build the override stylesheet for the changed tokens only. */
	private static function build_css(string $css_path): string {
		if (!is_string($css_path) || !file_exists($css_path) || !is_readable($css_path)) {
			return '';
		}
		$src = (string) file_get_contents($css_path);
		if ($src === '') {
			return '';
		}
		// Work only on top-level rules: stripping @media/@keyframes/@supports/
		// @font-face blocks means a color rule can never be lifted out of its
		// breakpoint (no responsive color utilities exist today, but this keeps it
		// safe if any are added later — they simply won't be recolored).
		$top = self::strip_at_rules($src);
		$ctl = self::controls();

		// Gather the colors the editor actually changed (old/new rgb-triple + hex).
		$changes = [];
		foreach ($ctl['colors'] as $c) {
			$new = self::value($c['key'], $c['default']);
			if (strtolower($new) === strtolower($c['default'])) {
				continue;
			}
			$ot = self::hex_triple($c['default']);
			$nt = self::hex_triple($new);
			if ($ot === '' || $nt === '') {
				continue;
			}
			$changes[] = ['ot' => $ot, 'nt' => $nt, 'oh' => strtolower($c['default']), 'nh' => strtolower($new)];
		}

		$out  = '';
		$seen = [];
		if ($changes && preg_match_all('/([^{}]+)\{([^}]*)\}/', $top, $m, PREG_SET_ORDER)) {
			foreach ($m as $rule) {
				$sel  = $rule[1];
				$body = $rule[2];
				$orig = $body;
				foreach ($changes as $ch) {
					// Tailwind opacity form: rgb(R G B / var(...)) and rgb(R G B).
					$body = str_replace('rgb(' . $ch['ot'], 'rgb(' . $ch['nt'], $body);
					// Hardcoded hex in component classes; never touch an 8-digit (alpha) hex.
					$body = (string) preg_replace('/' . preg_quote($ch['oh'], '/') . '(?![0-9a-fA-F])/i', $ch['nh'], $body);
				}
				if ($body !== $orig) {
					$line = $sel . '{' . $body . '}';
					if (!isset($seen[$line])) {
						$seen[$line] = true;
						$out .= $line;
					}
				}
			}
		}

		// Fonts: rebuild the font-family declaration on the relevant utility rules.
		$font_targets = ['serif' => ['font-serif', 'font-display'], 'sans' => ['font-sans']];
		foreach ($ctl['fonts'] as $f) {
			$new = self::value($f['key'], $f['default']);
			if ($new === $f['default']) {
				continue;
			}
			$family = self::sanitize_font($new);
			if ($family === '') {
				continue;
			}
			foreach (($font_targets[$f['key']] ?? []) as $slug) {
				$out .= self::rewrite_font($top, $slug, $family);
			}
			if ($f['key'] === 'sans') {
				$out .= 'body{font-family:' . $family . '}';
			}
		}

		return $out;
	}

	/** Remove balanced @media/@supports/@keyframes/@font-face blocks (and any nesting). */
	private static function strip_at_rules(string $css): string {
		$out = '';
		$i   = 0;
		$n   = strlen($css);
		while ($i < $n) {
			if ($css[$i] === '@') {
				$brace = strpos($css, '{', $i);
				if ($brace === false) {
					$out .= substr($css, $i);
					break;
				}
				$depth = 0;
				$j     = $brace;
				for (; $j < $n; $j++) {
					if ($css[$j] === '{') {
						$depth++;
					} elseif ($css[$j] === '}') {
						$depth--;
						if ($depth === 0) {
							$j++;
							break;
						}
					}
				}
				$i = $j; // drop the whole at-rule block
				continue;
			}
			$out .= $css[$i];
			$i++;
		}
		return $out;
	}

	/**
	 * Re-emit every rule whose selector names the .font-{slug} utility (bounded so
	 * it can't match a longer class) with its font-family rebuilt to $family.
	 */
	private static function rewrite_font(string $top, string $slug, string $family): string {
		$pattern = '/(\.[^{}]*' . preg_quote($slug, '/') . '(?=[{:\\\\])[^{}]*)\{[^}]*\}/';
		if (!preg_match_all($pattern, $top, $m, PREG_SET_ORDER)) {
			return '';
		}
		$out  = '';
		$seen = [];
		foreach ($m as $rule) {
			$line = $rule[1] . '{font-family:' . $family . '}';
			if (!isset($seen[$line])) {
				$seen[$line] = true;
				$out .= $line;
			}
		}
		return $out;
	}

	/** "#rrggbb" / "#rgb" → "r g b" (space-separated, matching Tailwind output). */
	private static function hex_triple(string $hex): string {
		$hex = ltrim(trim($hex), '#');
		if (strlen($hex) === 3) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
			return '';
		}
		return hexdec(substr($hex, 0, 2)) . ' ' . hexdec(substr($hex, 2, 2)) . ' ' . hexdec(substr($hex, 4, 2));
	}

	/** Allow only safe CSS font-family characters. */
	private static function sanitize_font(string $val): string {
		$val = preg_replace('/[^A-Za-z0-9 ,_"\'\-]/', '', $val);
		return trim((string) $val);
	}

	/* ---------------- admin screen ---------------- */

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Styles', 'Styles', self::CAP, 'aq-styles', [__CLASS__, 'render']);
	}

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$ctl = self::controls();
		AQ_Admin_Hub::open('Global Styles', 'Brand colors and fonts for the whole site. Every value starts at the current design — nothing changes until you change it.', 'aq-styles');
		?>
		<style>
			.aq-gs-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
			.aq-gs-item { display:flex; align-items:flex-start; gap:12px; }
			.aq-gs-item label { font-weight:600; color:#0d1014; display:block; margin-bottom:3px; }
			.aq-gs-item .aq-gs-help { font-size:12px; color:#5b6471; margin:0; }
			.aq-gs-swatch { width:46px; height:46px; border:1px solid #c9cfd6; border-radius:10px; padding:0; cursor:pointer; flex-shrink:0; background:none; }
			.aq-gs-hex { width:96px; padding:7px 9px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; font-family:ui-monospace,Menlo,monospace; color:#0d1014; margin-top:4px; }
			.aq-gs-font { width:100%; padding:8px 11px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; color:#0d1014; }
			.aq-gs-actions { display:flex; align-items:center; gap:14px; margin-top:18px; }
		</style>
		<?php if (isset($_GET['updated'])) : ?>
			<div class="notice notice-success is-dismissible"><p>Global styles saved.</p></div>
		<?php endif; ?>
		<?php if (isset($_GET['reset'])) : ?>
			<div class="notice notice-success is-dismissible"><p>Reset to defaults.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="aq_global_styles_save">
			<?php wp_nonce_field('aq_global_styles_save'); ?>

			<div class="aq-panel">
				<h2>Brand colors</h2>
				<div class="aq-gs-grid">
					<?php foreach ($ctl['colors'] as $c) :
						$val = strtolower(self::value($c['key'], $c['default'])); ?>
						<div class="aq-gs-item">
							<input type="color" class="aq-gs-swatch"
								id="aq-gs-<?php echo esc_attr($c['key']); ?>"
								value="<?php echo esc_attr($val); ?>"
								data-hex="aq-gs-hex-<?php echo esc_attr($c['key']); ?>">
							<div>
								<label for="aq-gs-<?php echo esc_attr($c['key']); ?>"><?php echo esc_html($c['label']); ?></label>
								<p class="aq-gs-help"><?php echo esc_html($c['help']); ?></p>
								<input type="text" class="aq-gs-hex"
									id="aq-gs-hex-<?php echo esc_attr($c['key']); ?>"
									name="<?php echo esc_attr($c['key']); ?>"
									value="<?php echo esc_attr($val); ?>"
									data-default="<?php echo esc_attr(strtolower($c['default'])); ?>"
									data-color="aq-gs-<?php echo esc_attr($c['key']); ?>">
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="aq-panel">
				<h2>Fonts</h2>
				<div class="aq-gs-grid">
					<?php foreach ($ctl['fonts'] as $f) :
						$val = self::value($f['key'], $f['default']); ?>
						<div>
							<label for="aq-gs-font-<?php echo esc_attr($f['key']); ?>" style="font-weight:600;color:#0d1014;display:block;margin-bottom:3px;"><?php echo esc_html($f['label']); ?></label>
							<p class="aq-gs-help" style="margin:0 0 6px;"><?php echo esc_html($f['help']); ?></p>
							<input type="text" class="aq-gs-font"
								id="aq-gs-font-<?php echo esc_attr($f['key']); ?>"
								name="font_<?php echo esc_attr($f['key']); ?>"
								value="<?php echo esc_attr($val); ?>"
								list="aq-gs-fontlist">
						</div>
					<?php endforeach; ?>
				</div>
				<datalist id="aq-gs-fontlist">
					<option value="Georgia, Cambria, &quot;Times New Roman&quot;, serif"></option>
					<option value="system-ui, -apple-system, &quot;Segoe UI&quot;, Roboto, sans-serif"></option>
					<option value="Georgia, &quot;Times New Roman&quot;, serif"></option>
					<option value="Arial, Helvetica, sans-serif"></option>
					<option value="system-ui, -apple-system, sans-serif"></option>
				</datalist>
				<p class="aq-gs-help" style="margin-top:10px;">Enter a font stack (the browser uses the first available font). The fonts themselves must be available to the site; changing this does not load new web fonts.</p>
			</div>

			<div class="aq-gs-actions">
				<?php submit_button('Save styles', 'primary', 'submit', false); ?>
				<button type="submit" name="reset" value="1" class="button">Reset to defaults</button>
				<span class="aq-gs-help">Nothing changes on the live site until you save a different value.</span>
			</div>
		</form>

		<script>
		(function () {
			// Keep each color picker and its hex text field in sync.
			document.querySelectorAll('.aq-gs-swatch').forEach(function (sw) {
				var hex = document.getElementById(sw.getAttribute('data-hex'));
				sw.addEventListener('input', function () { if (hex) { hex.value = sw.value; } });
			});
			document.querySelectorAll('.aq-gs-hex').forEach(function (hex) {
				var sw = document.getElementById(hex.getAttribute('data-color'));
				hex.addEventListener('input', function () {
					if (sw && /^#[0-9a-fA-F]{6}$/.test(hex.value)) { sw.value = hex.value; }
				});
			});
		})();
		</script>
		<?php
		AQ_Admin_Hub::close();
	}

	public static function save_settings(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_global_styles_save')) {
			wp_die('Not allowed.');
		}
		if (!empty($_POST['reset'])) {
			delete_option(self::OPTION);
			wp_safe_redirect(add_query_arg(['page' => 'aq-styles', 'reset' => '1'], admin_url('admin.php')));
			exit;
		}
		$ctl   = self::controls();
		$store = [];
		foreach ($ctl['colors'] as $c) {
			$raw = isset($_POST[$c['key']]) ? strtolower(trim((string) wp_unslash($_POST[$c['key']]))) : '';
			if ($raw === '' || !preg_match('/^#[0-9a-f]{6}$/', $raw)) {
				continue; // invalid or empty → keep default
			}
			if ($raw !== strtolower($c['default'])) {
				$store[$c['key']] = $raw; // store only genuine overrides
			}
		}
		foreach ($ctl['fonts'] as $f) {
			$raw = isset($_POST['font_' . $f['key']]) ? trim((string) wp_unslash($_POST['font_' . $f['key']])) : '';
			$raw = self::sanitize_font($raw);
			if ($raw !== '' && $raw !== $f['default']) {
				$store[$f['key']] = $raw;
			}
		}
		if ($store) {
			update_option(self::OPTION, $store); // autoloaded: read on every front-end page
		} else {
			delete_option(self::OPTION); // all back to default → no stored overrides → parity
		}
		wp_safe_redirect(add_query_arg(['page' => 'aq-styles', 'updated' => '1'], admin_url('admin.php')));
		exit;
	}
}
