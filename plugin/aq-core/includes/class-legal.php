<?php
/**
 * AQ Legal Pages — an AutoForge admin screen for the site's legal documents
 * (Privacy Policy, Cookie Policy, Terms of Service, Disclaimer, Accessibility
 * Statement, plus any custom pages).
 *
 * The flow the client asked for:
 *   1. Paste each policy's embed/snippet (a Termly / iubenda / CookieYes embed
 *      script, or plain HTML) into its box on AutoForge -> Legal Pages.
 *   2. Tick "Create this page" for the ones to publish.
 *   3. Tick "Show in footer" for the ones to link in the footer.
 *
 * On save this becomes real, engine-rendered pages: each enabled page is
 * upserted (AQ_Content_Sync::upsert_page) at /{slug}/ with a single raw_html
 * section carrying the pasted content inside a centred reading column, and is
 * marked noindex (legal pages are kept out of Google — that also excludes them
 * from the XML sitemap automatically). Disabling a page unpublishes it (its
 * content is kept as a draft, never destroyed). The "show in footer" pages are
 * written into the footer's legal-links list, so any theme that renders
 * aq_site('footer.legal') shows them with no template change.
 *
 * Client-agnostic: no site owns this — the curated set + whatever custom pages a
 * site adds live in the per-site `aq_legal` option, exactly like every other
 * dashboard-editable value.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Legal {

	const CAP    = 'manage_options';
	const OPTION = 'aq_legal';   // saved rows keyed by slug
	const SLUG   = 'aq-legal';

	/** The ready-made rows offered on the screen: slug => default title. */
	private static function curated(): array {
		return [
			'privacy-policy'         => 'Privacy Policy',
			'cookie-policy'          => 'Cookie Policy',
			'terms-of-service'       => 'Terms of Service',
			'disclaimer'             => 'Disclaimer',
			'accessibility-statement'=> 'Accessibility Statement',
		];
	}

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 26);
		add_action('admin_post_aq_legal_save', [__CLASS__, 'save']);
	}

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Legal Pages', 'Legal Pages', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	/* ---------------- data ---------------- */

	/**
	 * The effective rows to display/persist: every curated slug (with any saved
	 * title/content/toggles overlaid), followed by any saved custom rows.
	 *
	 * @return array<string,array{title:string,content:string,enabled:bool,footer:bool,custom:bool}>
	 */
	public static function rows(): array {
		$saved = get_option(self::OPTION, []);
		$saved = is_array($saved) ? $saved : [];

		$rows = [];
		foreach (self::curated() as $slug => $title) {
			$s = is_array($saved[$slug] ?? null) ? $saved[$slug] : [];
			$rows[$slug] = [
				'title'   => (string) ($s['title'] ?? $title),
				'content' => (string) ($s['content'] ?? ''),
				'enabled' => !empty($s['enabled']),
				'footer'  => !empty($s['footer']),
				'custom'  => false,
			];
		}
		foreach ($saved as $slug => $s) {
			if (isset($rows[$slug]) || !is_array($s) || empty($s['custom'])) {
				continue;
			}
			$rows[$slug] = [
				'title'   => (string) ($s['title'] ?? ''),
				'content' => (string) ($s['content'] ?? ''),
				'enabled' => !empty($s['enabled']),
				'footer'  => !empty($s['footer']),
				'custom'  => true,
			];
		}
		return $rows;
	}

	/* ---------------- save ---------------- */

	public static function save(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_legal_save')) {
			wp_die('Not allowed.');
		}
		$in = wp_unslash($_POST);

		$rows     = [];
		$curated  = self::curated();

		// Curated rows (fixed slugs).
		$legal = is_array($in['legal'] ?? null) ? $in['legal'] : [];
		foreach ($curated as $slug => $default_title) {
			$r = is_array($legal[$slug] ?? null) ? $legal[$slug] : [];
			$title = sanitize_text_field($r['title'] ?? '');
			$rows[$slug] = [
				'title'   => $title !== '' ? $title : $default_title,
				'content' => self::clean_content($r['content'] ?? ''),
				'enabled' => !empty($r['enabled']),
				'footer'  => !empty($r['footer']),
				'custom'  => false,
			];
		}

		// Custom rows (user-named). Slug derived from the title; de-duplicated
		// against curated + other customs. Rows with no title are dropped.
		$custom = is_array($in['custom'] ?? null) ? $in['custom'] : [];
		foreach ($custom as $r) {
			if (!is_array($r)) {
				continue;
			}
			$title = sanitize_text_field($r['title'] ?? '');
			if ($title === '') {
				continue;
			}
			$slug = sanitize_title($r['slug'] ?? '') ?: sanitize_title($title);
			if ($slug === '') {
				continue;
			}
			// Keep it unique so a custom page never clobbers a curated one.
			$base = $slug; $n = 2;
			while (isset($rows[$slug])) { $slug = $base . '-' . $n++; }
			$rows[$slug] = [
				'title'   => $title,
				'content' => self::clean_content($r['content'] ?? ''),
				'enabled' => !empty($r['enabled']),
				'footer'  => !empty($r['footer']),
				'custom'  => true,
			];
		}

		update_option(self::OPTION, $rows, false);
		self::sync_pages($rows);
		self::sync_footer_links($rows);

		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => '1'], admin_url('admin.php')));
		exit;
	}

	/**
	 * Legal embeds are pasted third-party snippets (script + markup), so the
	 * content is stored RAW — manage_options + nonce gated, exactly like the
	 * Forms email-template field. wp_kses would strip the <script> the embed
	 * needs. Only trims surrounding whitespace.
	 */
	private static function clean_content($v): string {
		return trim((string) $v);
	}

	/* ---------------- page sync ---------------- */

	/**
	 * Reconcile the WordPress pages with the saved rows: enabled rows with
	 * content become published pages; everything else is unpublished (kept as a
	 * draft). Rendered by the engine like any page — one raw_html section holding
	 * the pasted embed inside a centred column — and marked noindex.
	 *
	 * @param array<string,array> $rows
	 */
	private static function sync_pages(array $rows): void {
		if (!class_exists('AQ_Content_Sync')) {
			return;
		}
		foreach ($rows as $slug => $r) {
			$publish = !empty($r['enabled']) && $r['content'] !== '';
			if ($publish) {
				AQ_Content_Sync::upsert_page([
					'path'     => '/' . $slug . '/',
					'title'    => $r['title'],
					'status'   => 'publish',
					'seo'      => ['title' => $r['title'], 'noindex' => true],
					'sections' => [[
						'type' => 'raw_html',
						'v'    => 1,
						'html' => self::wrap_content($r['content']),
					]],
				]);
			} else {
				self::unpublish($slug);
			}
		}
	}

	/** Centred reading column around the pasted embed, so it renders neatly on any site (Tailwind or bespoke). */
	private static function wrap_content(string $content): string {
		return '<section class="aq-legal-page" style="padding:56px 20px;">'
			. '<div class="aq-legal-page__inner" style="max-width:880px;margin:0 auto;">'
			. $content
			. '</div></section>';
	}

	/** Set an existing legal page to draft (hide it) without deleting its content. */
	private static function unpublish(string $slug): void {
		$page = get_page_by_path($slug, OBJECT, 'page');
		if ($page && $page->post_status === 'publish') {
			wp_update_post(['ID' => $page->ID, 'post_status' => 'draft']);
		}
	}

	/* ---------------- footer links ---------------- */

	/**
	 * Write the "show in footer" legal pages into the footer's legal-links list.
	 * Preserves any manually-added legal links (those NOT pointing at a legal
	 * page this feature manages), then appends the enabled+footer pages in order.
	 *
	 * @param array<string,array> $rows
	 */
	private static function sync_footer_links(array $rows): void {
		if (!class_exists('AQ_Site_Config')) {
			return;
		}
		$managed = [];
		$mine    = [];
		foreach ($rows as $slug => $r) {
			$href = '/' . $slug . '/';
			$managed[$href] = true;
			if (!empty($r['enabled']) && !empty($r['footer']) && $r['content'] !== '') {
				$mine[] = ['label' => $r['title'], 'href' => $href];
			}
		}

		$cfg      = AQ_Site_Config::get();
		$existing = (array) ($cfg['footer']['legal'] ?? []);
		$kept     = [];
		foreach ($existing as $link) {
			if (is_array($link) && !isset($managed[(string) ($link['href'] ?? '')])) {
				$kept[] = $link;
			}
		}

		AQ_Site_Config::update(['footer' => ['legal' => array_merge($kept, $mine)]]);
	}

	/* ---------------- admin screen ---------------- */

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$rows = self::rows();
		AQ_Admin_Hub::open('Legal Pages', 'Create your legal pages from pasted policy embeds, and choose which link in the footer.', self::SLUG);
		?>
		<style>
			.aq-legal-card{background:#fff;border:1px solid #dcdfe3;border-radius:10px;padding:18px 20px;margin:0 0 16px;max-width:820px}
			.aq-legal-card h2{margin:0 0 4px;font-size:15px;display:flex;align-items:center;gap:10px}
			.aq-legal-card .slug{color:#5b6471;font-size:12px;font-weight:500}
			.aq-legal-card textarea{width:100%;min-height:120px;padding:10px 12px;border:1px solid #c9cfd6;border-radius:8px;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;margin-top:8px}
			.aq-legal-card input[type=text]{padding:7px 10px;border:1px solid #c9cfd6;border-radius:8px;font-size:13px;width:100%;max-width:360px}
			.aq-legal-toggles{display:flex;gap:22px;flex-wrap:wrap;margin-top:12px}
			.aq-legal-toggles label{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#0d1014}
			.aq-legal-field{margin-bottom:8px}
			.aq-legal-field label{display:block;font-weight:600;font-size:12px;color:#5b6471;margin-bottom:4px}
			.aq-badge{display:inline-block;border-radius:999px;padding:1px 9px;font-size:11px;font-weight:700}
			.aq-badge--live{background:#eaf0ea;color:#1a6f3f;border:1px solid #b9dcc4}
			.aq-badge--off{background:#f1f3f5;color:#5b6471;border:1px solid #dcdfe3}
			.aq-legal-hint{color:#5b6471;font-size:12px;margin:2px 0 0}
			#aq-legal-custom .aq-legal-card{border-style:dashed}
		</style>
		<?php if (isset($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>Legal pages saved.</p></div><?php endif; ?>

		<p class="aq-legal-hint" style="max-width:820px;margin:0 0 16px">
			Paste each policy's embed code (e.g. the snippet from Termly, iubenda or CookieYes) or plain HTML into its box.
			Tick <strong>Create this page</strong> to publish it, and <strong>Show in footer</strong> to add a footer link.
			All legal pages are kept out of Google automatically.
		</p>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="aq_legal_save">
			<?php wp_nonce_field('aq_legal_save'); ?>

			<?php foreach ($rows as $slug => $r) : if ($r['custom']) { continue; } ?>
				<?php self::render_row('legal[' . $slug . ']', $slug, $r, false); ?>
			<?php endforeach; ?>

			<h2 style="max-width:820px;margin:26px 0 10px;font-size:15px">Custom legal pages</h2>
			<div id="aq-legal-custom">
				<?php $ci = 0; foreach ($rows as $slug => $r) : if (!$r['custom']) { continue; } ?>
					<?php self::render_row('custom[' . $ci . ']', $slug, $r, true); $ci++; ?>
				<?php endforeach; ?>
			</div>
			<p style="max-width:820px"><button type="button" class="button button-secondary" id="aq-legal-add">+ Add a custom legal page</button></p>

			<?php submit_button('Save legal pages'); ?>
		</form>

		<template id="aq-legal-tpl"><?php self::render_row('custom[__i__]', '', ['title' => '', 'content' => '', 'enabled' => false, 'footer' => false, 'custom' => true], true, true); ?></template>
		<script>
		(function(){
			var wrap=document.getElementById('aq-legal-custom');
			var add=document.getElementById('aq-legal-add');
			var tpl=document.getElementById('aq-legal-tpl');
			if(!wrap||!add||!tpl)return;
			var i=<?php echo (int) $ci; ?>;
			add.addEventListener('click',function(){
				var html=tpl.innerHTML.replace(/__i__/g,String(i++));
				var d=document.createElement('div');d.innerHTML=html;
				wrap.appendChild(d.firstElementChild);
			});
		})();
		</script>
		<?php
		AQ_Admin_Hub::close();
	}

	/**
	 * One editable card. $name is the field-name prefix (e.g. legal[privacy-policy]
	 * or custom[0]); curated cards show a fixed slug, custom cards let the title +
	 * slug be edited. $blank renders an empty template (for JS cloning).
	 */
	private static function render_row(string $name, string $slug, array $r, bool $custom, bool $blank = false): void {
		$page   = ($slug !== '' && !$blank) ? get_page_by_path($slug, OBJECT, 'page') : null;
		$live   = $page && $page->post_status === 'publish';
		?>
		<div class="aq-legal-card">
			<h2>
				<?php if ($custom) : ?>
					<span>Custom page</span>
				<?php else : ?>
					<span><?php echo esc_html($r['title']); ?></span>
				<?php endif; ?>
				<?php if (!$blank) : ?>
					<span class="aq-badge <?php echo $live ? 'aq-badge--live' : 'aq-badge--off'; ?>"><?php echo $live ? 'Live' : 'Not published'; ?></span>
				<?php endif; ?>
				<?php if (!$custom) : ?><span class="slug">/<?php echo esc_html($slug); ?>/</span><?php endif; ?>
			</h2>

			<?php if ($custom) : ?>
			<div class="aq-legal-field">
				<label>Page title</label>
				<input type="text" name="<?php echo esc_attr($name); ?>[title]" value="<?php echo esc_attr($r['title']); ?>" placeholder="e.g. Refund Policy">
			</div>
			<div class="aq-legal-field">
				<label>URL slug <span style="font-weight:400">(optional — made from the title if blank)</span></label>
				<input type="text" name="<?php echo esc_attr($name); ?>[slug]" value="<?php echo esc_attr($custom && !$blank ? $slug : ''); ?>" placeholder="refund-policy">
			</div>
			<?php else : ?>
			<div class="aq-legal-field">
				<label>Page title</label>
				<input type="text" name="<?php echo esc_attr($name); ?>[title]" value="<?php echo esc_attr($r['title']); ?>">
			</div>
			<?php endif; ?>

			<label class="aq-legal-field" style="font-weight:600;font-size:12px;color:#5b6471">Embed code or HTML <?php echo AQ_Admin_Hub::tip('Paste an embed code from a policy service like Termly, or type your own policy text here.'); ?></label>
			<textarea name="<?php echo esc_attr($name); ?>[content]" placeholder="Paste your policy embed snippet here…"><?php echo esc_textarea($r['content']); ?></textarea>

			<div class="aq-legal-toggles">
				<label><input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked(!empty($r['enabled'])); ?>> Create this page</label>
				<label><input type="checkbox" name="<?php echo esc_attr($name); ?>[footer]" value="1" <?php checked(!empty($r['footer'])); ?>> Show in footer</label>
			</div>
		</div>
		<?php
	}
}
