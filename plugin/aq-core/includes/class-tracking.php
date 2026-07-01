<?php
/**
 * AQ Tracking — analytics & verification snippets for the front end.
 *
 * One screen (AutoForge → Tracking) where an admin enters tracking/verification
 * codes; AutoForge emits the correct, well-formed tags on the front end via the
 * standard wp_head / wp_body_open / wp_footer hooks (the renderer already calls
 * all three). Two layers:
 *
 *   1. Guided fields — paste only the ID/token; AutoForge builds the tag:
 *      GA4 (gtag.js), Google Tag Manager, Google Search Console + Bing
 *      verification metas, Meta Pixel.
 *   2. Custom snippets — raw Head / Body-open / Footer boxes for anything else.
 *
 * Environment posture: tracking renders in EVERY environment (staging and
 * production alike). Pressable staging URLs are not crawlable, so there is no
 * indexing risk, and having GA/Pixel/chatbot/verification active on staging is
 * useful for pre-launch testing. The site-wide indexing posture
 * (aq_noindex_active() → robots meta / robots.txt) is a separate concern and is
 * NOT affected by tracking output.
 *
 * Storage: a single non-autoloaded option `aq_tracking`. Guided IDs are
 * pattern-sanitized so a malformed value cannot inject markup. The raw snippet
 * boxes are stored and emitted unfiltered by design (the standard "header/footer
 * scripts" tradeoff) and are only settable by manage_options admins.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Tracking {

	const CAP    = 'manage_options';
	const OPTION = 'aq_tracking';
	const SLUG   = 'aq-tracking';

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 23);
		add_action('admin_post_aq_tracking_save', [__CLASS__, 'save']);

		// Front-end injection. Priority 20 keeps these after the core/SEO output.
		add_action('wp_head', [__CLASS__, 'print_head'], 20);
		add_action('wp_body_open', [__CLASS__, 'print_body_open'], 20);
		add_action('wp_footer', [__CLASS__, 'print_footer'], 20);
	}

	/* ---------------- data ---------------- */

	/** Full settings array with every key present (defaults to ''). */
	public static function get(): array {
		$opt = get_option(self::OPTION, []);
		$opt = is_array($opt) ? $opt : [];
		return array_merge([
			'ga4' => '', 'gtm' => '', 'gsc' => '', 'bing' => '', 'pixel' => '',
			'head' => '', 'body_open' => '', 'footer' => '',
		], $opt);
	}

	/**
	 * Whether tracking output renders. Always true — tracking fires in every
	 * environment (staging URLs aren't crawlable, so there's no indexing risk and
	 * pre-launch testing needs the tags live). Indexing posture is separate
	 * (aq_noindex_active()) and unaffected. A filter allows a site to opt back
	 * into suppression if it ever needs to.
	 */
	private static function tracking_live(): bool {
		return (bool) apply_filters('aq_tracking_render', true);
	}

	/* ---------------- front-end output ---------------- */

	public static function print_head(): void {
		if (is_admin()) {
			return;
		}
		$t = self::get();

		// Verification metas — always render (even on staging).
		if ($t['gsc'] !== '') {
			echo '<meta name="google-site-verification" content="' . esc_attr($t['gsc']) . '" />' . "\n";
		}
		if ($t['bing'] !== '') {
			echo '<meta name="msvalidate.01" content="' . esc_attr($t['bing']) . '" />' . "\n";
		}

		if (!self::tracking_live()) {
			return; // staging: no analytics, no custom head snippet
		}

		// Google Tag Manager (head half).
		if ($t['gtm'] !== '') {
			$id = esc_js($t['gtm']);
			echo "<!-- Google Tag Manager (AutoForge) -->\n";
			echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . $id . "');</script>\n";
		}

		// GA4 (gtag.js). Skipped silently if GTM is the chosen tag manager? No —
		// both are allowed; an admin who wants GA4 via GTM simply leaves GA4 blank.
		if ($t['ga4'] !== '') {
			$id = esc_js($t['ga4']);
			echo "<!-- Google tag, gtag.js (AutoForge) -->\n";
			echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr(rawurlencode($t['ga4'])) . '"></script>' . "\n";
			echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . $id . "');</script>\n";
		}

		// Meta Pixel (head half).
		if ($t['pixel'] !== '') {
			$id = esc_js($t['pixel']);
			echo "<!-- Meta Pixel (AutoForge) -->\n";
			echo "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . $id . "');fbq('track','PageView');</script>\n";
		}

		// Custom head snippet — raw, unfiltered by design.
		if ($t['head'] !== '') {
			echo "\n" . $t['head'] . "\n";
		}
	}

	public static function print_body_open(): void {
		if (is_admin() || !self::tracking_live()) {
			return;
		}
		$t = self::get();

		if ($t['gtm'] !== '') {
			echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr(rawurlencode($t['gtm'])) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
		}
		if ($t['pixel'] !== '') {
			echo '<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=' . esc_attr(rawurlencode($t['pixel'])) . '&ev=PageView&noscript=1" /></noscript>' . "\n";
		}
		if ($t['body_open'] !== '') {
			echo "\n" . $t['body_open'] . "\n";
		}
	}

	public static function print_footer(): void {
		if (is_admin() || !self::tracking_live()) {
			return;
		}
		$t = self::get();
		if ($t['footer'] !== '') {
			echo "\n" . $t['footer'] . "\n";
		}
	}

	/* ---------------- admin screen ---------------- */

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Tracking', 'Tracking', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$t    = self::get();
		$live = self::tracking_live();
		AQ_Admin_Hub::open('Tracking', 'Add analytics & verification codes. AutoForge places each tag correctly and renders them in every environment.', self::SLUG);
		?>
		<style>
			.aq-trk-field { margin-bottom: 16px; }
			.aq-trk-field label { display:block; font-weight:600; color:#0d1014; margin-bottom:5px; }
			.aq-trk-field input[type=text], .aq-trk-field textarea {
				width:100%; max-width:560px; padding:8px 11px; border:1px solid #c9cfd6; border-radius:8px; font-size:13px; color:#0d1014; font-family:inherit; }
			.aq-trk-field textarea { max-width:none; min-height:96px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; line-height:1.6; }
			.aq-trk-hint { font-size:12px; color:#5b6471; margin:5px 0 0; }
			.aq-trk-where { display:inline-block; font-size:11px; color:#5b6471; background:#eef1f5; padding:2px 8px; border-radius:999px; margin-left:8px; font-weight:600; }
			.aq-trk-banner { border-radius:10px; padding:12px 16px; margin-bottom:18px; font-size:13px; }
			.aq-trk-banner--staging { background:#fdf1dd; color:#7a4e0a; border:1px solid #f4d088; }
			.aq-trk-banner--live { background:#eaf0ea; color:#1a6f3f; border:1px solid #b9dcc4; }
		</style>
		<?php if (isset($_GET['updated'])) : ?>
			<div class="notice notice-success is-dismissible"><p>Tracking settings saved.</p></div>
		<?php endif; ?>

		<?php if ($live) : ?>
			<div class="aq-trk-banner aq-trk-banner--live"><strong>Tracking is active in every environment.</strong> All configured tags &amp; snippets render on the front end on staging and production alike. (Indexing is controlled separately under <a href="<?php echo esc_url(admin_url('admin.php?page=aq-performance')); ?>">Performance</a>.)</div>
		<?php else : ?>
			<div class="aq-trk-banner aq-trk-banner--staging"><strong>Tracking output is currently disabled</strong> via the <code>aq_tracking_render</code> filter. Remove that filter to render tags again.</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
			<input type="hidden" name="action" value="aq_tracking_save">
			<?php wp_nonce_field('aq_tracking_save'); ?>

			<div class="aq-panel">
				<h2>Guided tags</h2>
				<p class="aq-trk-hint" style="margin:4px 0 16px;">Paste just the ID or token — AutoForge generates the full, correct tag.</p>

				<div class="aq-trk-field">
					<label for="aq-trk-ga4">Google Analytics 4 — Measurement ID <span class="aq-trk-where">head</span></label>
					<input type="text" id="aq-trk-ga4" name="ga4" value="<?php echo esc_attr($t['ga4']); ?>" placeholder="G-XXXXXXXXXX">
					<p class="aq-trk-hint">From GA4 → Admin → Data Streams. Starts with <code>G-</code>.</p>
				</div>

				<div class="aq-trk-field">
					<label for="aq-trk-gtm">Google Tag Manager — Container ID <span class="aq-trk-where">head + body</span></label>
					<input type="text" id="aq-trk-gtm" name="gtm" value="<?php echo esc_attr($t['gtm']); ?>" placeholder="GTM-XXXXXXX">
					<p class="aq-trk-hint">If you manage GA4 inside GTM, set this and leave GA4 above blank. Starts with <code>GTM-</code>.</p>
				</div>

				<div class="aq-trk-field">
					<label for="aq-trk-gsc">Google Search Console — verification <span class="aq-trk-where">head · always on</span></label>
					<input type="text" id="aq-trk-gsc" name="gsc" value="<?php echo esc_attr($t['gsc']); ?>" placeholder="token, or paste the whole <meta> tag">
					<p class="aq-trk-hint">Paste the token <em>or</em> the entire <code>&lt;meta name="google-site-verification" …&gt;</code> — AutoForge extracts the token.</p>
				</div>

				<div class="aq-trk-field">
					<label for="aq-trk-bing">Bing Webmaster — verification <span class="aq-trk-where">head · always on</span></label>
					<input type="text" id="aq-trk-bing" name="bing" value="<?php echo esc_attr($t['bing']); ?>" placeholder="token, or paste the whole <meta> tag">
					<p class="aq-trk-hint">The <code>msvalidate.01</code> content value (token or full meta tag).</p>
				</div>

				<div class="aq-trk-field">
					<label for="aq-trk-pixel">Meta (Facebook) Pixel — ID <span class="aq-trk-where">head + body</span></label>
					<input type="text" id="aq-trk-pixel" name="pixel" value="<?php echo esc_attr($t['pixel']); ?>" placeholder="123456789012345">
					<p class="aq-trk-hint">The numeric Pixel ID from Meta Events Manager.</p>
				</div>
			</div>

			<div class="aq-panel">
				<h2>Custom snippets</h2>
				<p class="aq-trk-hint" style="margin:4px 0 16px;">For anything not covered above. Pasted exactly as entered — admins only. Paused on staging along with analytics.</p>

				<div class="aq-trk-field">
					<label for="aq-trk-head">Head <span class="aq-trk-where">in &lt;head&gt;</span></label>
					<textarea id="aq-trk-head" name="head" spellcheck="false" placeholder="&lt;script&gt;…&lt;/script&gt;"><?php echo esc_textarea($t['head']); ?></textarea>
				</div>
				<div class="aq-trk-field">
					<label for="aq-trk-body">Body open <span class="aq-trk-where">right after &lt;body&gt;</span></label>
					<textarea id="aq-trk-body" name="body_open" spellcheck="false" placeholder="&lt;noscript&gt;…&lt;/noscript&gt;"><?php echo esc_textarea($t['body_open']); ?></textarea>
				</div>
				<div class="aq-trk-field">
					<label for="aq-trk-footer">Footer <span class="aq-trk-where">before &lt;/body&gt;</span></label>
					<textarea id="aq-trk-footer" name="footer" spellcheck="false" placeholder="&lt;script&gt;…&lt;/script&gt;"><?php echo esc_textarea($t['footer']); ?></textarea>
				</div>
			</div>

			<?php submit_button('Save tracking'); ?>
		</form>
		<?php
		AQ_Admin_Hub::close();
	}

	public static function save(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_tracking_save')) {
			wp_die('Not allowed.');
		}
		$in = wp_unslash($_POST);

		$out = [
			'ga4'   => self::clean_id($in['ga4'] ?? ''),
			'gtm'   => self::clean_id($in['gtm'] ?? ''),
			'gsc'   => self::clean_token($in['gsc'] ?? ''),
			'bing'  => self::clean_token($in['bing'] ?? ''),
			'pixel' => preg_replace('/[^0-9]/', '', (string) ($in['pixel'] ?? '')),
			// Raw snippets: stored verbatim (manage_options-gated above).
			'head'      => trim((string) ($in['head'] ?? '')),
			'body_open' => trim((string) ($in['body_open'] ?? '')),
			'footer'    => trim((string) ($in['footer'] ?? '')),
		];

		update_option(self::OPTION, $out, false); // autoload=false
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => '1'], admin_url('admin.php')));
		exit;
	}

	/** Allow only the characters valid in a GA4/GTM id (e.g. G-…, GTM-…). */
	private static function clean_id(string $v): string {
		return preg_replace('/[^A-Za-z0-9_-]/', '', trim($v));
	}

	/**
	 * A verification token, or the full <meta …> the user pasted. Extract the
	 * content="…" value when a tag is pasted, then strip to token-safe chars.
	 */
	private static function clean_token(string $v): string {
		$v = trim($v);
		if (stripos($v, '<meta') !== false && preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $v, $m)) {
			$v = $m[1];
		}
		return preg_replace('/[^A-Za-z0-9_.\-]/', '', $v);
	}
}
