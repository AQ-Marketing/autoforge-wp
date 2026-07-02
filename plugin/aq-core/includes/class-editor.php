<?php
/**
 * AQ Visual Editor — a structured, Breakdance-style page builder.
 *
 * The builder UI (full-screen, mounted on the AutoForge → Pages → "Open
 * editor" screen) shows the REAL front-end page in an iframe "canvas". Clicking
 * a section in the canvas selects it; its fields are edited in an inspector
 * panel; Save persists through the one true write path (AQ_Content_Sync) and
 * reloads the canvas so you see the true rendered result.
 *
 * Editing is STRUCTURED — you edit the defined fields of each section (text,
 * lists, links, add/remove/reorder sections), never arbitrary CSS — so pixel
 * parity and the canonical JSON model are preserved.
 *
 * Canvas mode: the front end renders normally but tags each section with
 * data-aq-section (via the aq_render_section_markers filter) and loads the
 * canvas runtime, gated on a nonce + manage_options. Off by default.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Editor {

	const CAP         = 'manage_options';
	const CANVAS_FLAG = 'aq_canvas';
	const CANVAS_NONCE = 'aq_canvas';

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_canvas']);
		add_action('admin_bar_menu', [__CLASS__, 'admin_bar_link'], 80);
	}

	/* ---------------- canvas (front-end edit mode) ---------------- */

	/** True when the front end is being rendered inside the editor iframe. */
	public static function is_canvas(): bool {
		if (!isset($_GET[self::CANVAS_FLAG]) || $_GET[self::CANVAS_FLAG] !== '1') {
			return false;
		}
		if (!current_user_can(self::CAP)) {
			return false;
		}
		$nonce = isset($_GET['_aqnonce']) ? sanitize_text_field((string) wp_unslash($_GET['_aqnonce'])) : '';
		return (bool) wp_verify_nonce($nonce, self::CANVAS_NONCE);
	}

	/** In canvas mode: turn on section markers and load the canvas runtime. */
	public static function maybe_canvas(): void {
		if (!self::is_canvas()) {
			return;
		}
		add_filter('aq_render_section_markers', '__return_true');
		add_filter('show_admin_bar', '__return_false'); // no WP toolbar inside the editor canvas iframe

		$base = plugins_url('admin/editor/', AQ_CORE_DIR . 'aq-core.php');
		$dir  = AQ_CORE_DIR . 'admin/editor/';
		wp_enqueue_style('aq-canvas', $base . 'canvas.css', [], self::ver($dir . 'canvas.css'));
		wp_enqueue_script('aq-canvas', $base . 'canvas.js', [], self::ver($dir . 'canvas.js'), true);
	}

	private static function ver(string $file): string {
		return file_exists($file) ? (string) filemtime($file) : AQ_CORE_VERSION;
	}

	/** Front-end admin-bar shortcut into the builder for the current page. */
	public static function admin_bar_link($bar): void {
		if (is_admin() || !is_singular('page') || !current_user_can(self::CAP)) {
			return;
		}
		$id = get_queried_object_id();
		if (!$id) {
			return;
		}
		$bar->add_node([
			'id'    => 'aq-edit-page',
			'title' => '✏ Edit with AQ',
			'href'  => admin_url('admin.php?page=aq-pages&page_id=' . $id),
		]);
	}

	/* ---------------- builder host (admin screen) ---------------- */

	/**
	 * Render the full-screen builder. Called from AQ_Admin_Hub's Pages screen
	 * when a page_id is present. Echoes the mount point + boots the builder app.
	 */
	public static function render_builder(int $page_id): void {
		$post = get_post($page_id);
		if (!$post || $post->post_type !== 'page') {
			echo '<div class="wrap"><p>Page not found. <a href="' . esc_url(admin_url('admin.php?page=aq-pages')) . '">Back to pages</a></p></div>';
			return;
		}

		$permalink   = get_permalink($post);
		$canvas_url  = add_query_arg([
			self::CANVAS_FLAG => '1',
			'_aqnonce'        => wp_create_nonce(self::CANVAS_NONCE),
		], $permalink);

		$base = plugins_url('admin/editor/', AQ_CORE_DIR . 'aq-core.php');
		$dir  = AQ_CORE_DIR . 'admin/editor/';
		wp_enqueue_media(); // WordPress media-library picker (wp.media) for image fields.
		wp_enqueue_style('aq-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap', [], null);
		wp_enqueue_style('aq-builder', $base . 'builder.css', [], self::ver($dir . 'builder.css'));
		wp_enqueue_script('aq-builder', $base . 'builder.js', ['jquery'], self::ver($dir . 'builder.js'), true);
		wp_localize_script('aq-builder', 'AQ_EDITOR', [
			'restRoot'  => esc_url_raw(rest_url('aq/v1/editor')),
			'nonce'     => wp_create_nonce('wp_rest'),
			'pageId'    => $page_id,
			'pageTitle' => get_the_title($post),
			'permalink' => $permalink,
			'canvasUrl' => $canvas_url,
			'pagesUrl'  => admin_url('admin.php?page=aq-pages'),
			'schema'    => self::field_schema(),
			'labels'    => self::layout_labels(),
			'icons'     => self::icon_library(),
			'assistant' => class_exists('AQ_Assistant') && AQ_Assistant::is_configured(),
		]);

		echo '<div id="aq-builder-root" data-page-id="' . (int) $page_id . '">'
			. '<div class="aq-builder-loading">Loading editor…</div></div>';
	}

	/* ---------------- REST ---------------- */

	public static function rest_routes(): void {
		$can = function () { return current_user_can(self::CAP); };

		register_rest_route('aq/v1', '/editor/page/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => [__CLASS__, 'rest_get_page'],
		]);

		register_rest_route('aq/v1', '/editor/save', [
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => [__CLASS__, 'rest_save'],
		]);
	}

	public static function rest_get_page(WP_REST_Request $req) {
		$id   = (int) $req['id'];
		$post = get_post($id);
		if (!$post || $post->post_type !== 'page') {
			return new WP_Error('aq_not_found', 'Page not found.', ['status' => 404]);
		}
		$sections = class_exists('AQ_Content_Sync') ? AQ_Content_Sync::read_sections($id) : [];
		return rest_ensure_response([
			'ok'        => true,
			'id'        => $id,
			'title'     => get_the_title($post),
			'permalink' => get_permalink($post),
			'sections'  => $sections,
			'images'    => self::image_previews($sections),
		]);
	}

	/**
	 * Build a { filename: {id,url,thumb} } map for every image referenced in
	 * the page's sections, so the builder can show real thumbnails for images
	 * that are already set (newly-picked ones come straight from wp.media).
	 */
	private static function image_previews(array $sections): array {
		if (!class_exists('AQ_Content_Sync')) {
			return [];
		}
		$out = [];
		foreach ($sections as $s) {
			if (!empty($s['image']) && is_string($s['image']) && !isset($out[$s['image']])) {
				$out[$s['image']] = AQ_Content_Sync::image_info($s['image']);
			}
		}
		return $out;
	}

	public static function rest_save(WP_REST_Request $req) {
		$body = $req->get_json_params();
		$id   = (int) ($body['id'] ?? 0);
		$secs = $body['sections'] ?? null;
		$post = $id ? get_post($id) : null;

		if (!$post || $post->post_type !== 'page') {
			return new WP_Error('aq_not_found', 'Page not found.', ['status' => 404]);
		}
		if (!is_array($secs)) {
			return new WP_Error('aq_bad_body', 'Missing sections array.', ['status' => 400]);
		}
		if (!current_user_can('edit_post', $id)) {
			return new WP_Error('aq_forbidden', 'You cannot edit this page.', ['status' => 403]);
		}
		if (!class_exists('AQ_Content_Sync')) {
			return new WP_Error('aq_no_sync', 'Content sync unavailable.', ['status' => 500]);
		}

		// Only keep known layouts + drop any client-only keys.
		$allowed = array_keys(self::field_schema());
		$clean   = [];
		foreach ($secs as $s) {
			if (!is_array($s) || empty($s['type']) || !in_array($s['type'], $allowed, true)) {
				continue;
			}
			foreach (array_keys($s) as $k) { // drop transient client keys (_uid, etc.)
				if (is_string($k) && isset($k[0]) && $k[0] === '_') {
					unset($s[$k]);
				}
			}
			$clean[] = $s;
		}

		AQ_Content_Sync::update_sections($id, $clean);

		return rest_ensure_response([
			'ok'       => true,
			'count'    => count($clean),
			'sections' => AQ_Content_Sync::read_sections($id),
		]);
	}

	/* ---------------- schema ---------------- */

	/** Human labels for the section types (structure panel + add menu). */
	public static function layout_labels(): array {
		return [
			// Heroes
			'hero'             => 'Hero',
			'city_hero'        => 'City Hero',
			'media_hero'       => 'Media Hero',
			// Structure
			'breadcrumb'       => 'Breadcrumb',
			'page_header'      => 'Page Header',
			// Text
			'prose'            => 'Prose / Text',
			'prose_with_image' => 'Prose + Image',
			'prose_article'    => 'Prose Article (long-form)',
			'legal_doc'        => 'Legal / Doc Page',
			// Cards & grids
			'why_overview'     => 'Why / Overview',
			'trust_image_left' => 'Trust + Image',
			'journey_cards'    => 'Journey Cards',
			'dark_card_grid'   => 'Dark Card Grid',
			'service_card_grid'=> 'Service Card Grid',
			'town_card_grid'   => 'Town Card Grid',
			'link_card_grid'   => 'Link Cards',
			'feature_cards'    => 'Feature Cards',
			'step_cards'       => 'Step Cards',
			'embed' => 'Embed (responsive iframe)',
			'logos' => 'Logo Grid',
			'team' => 'Team / Staff Grid',
			'columns' => 'Columns (equal rich-text columns)',
			'video' => 'Video (responsive embed)',
			'gallery' => 'Image Gallery (grid)',
			'accordion' => 'Accordion (expandable items)',
			'button_group' => 'Button Group',
			'callout' => 'Callout (alert / notice)',
			'cta' => 'CTA (call-to-action band)',
			'divider' => 'Divider (horizontal rule)',
			'heading_block' => 'Heading',
			'icon_list' => 'Icon List (icon + text rows)',
			'image_block' => 'Image Block (single media image)',
			'pricing_table' => 'Pricing Table (plans grid)',
			'quote' => 'Pull Quote',
			'spacer' => 'Spacer (vertical gap)',
			'stats' => 'Stat Figures (number grid)',
			'text_block' => 'Text Block (rich text)',
			'timeline' => 'Timeline (vertical)',
			// Social proof
			'testimonials'     => 'Testimonials',
			// FAQ
			'faq'              => 'FAQ Accordion',
			'faq_dl'           => 'FAQ (plain list)',
			// CTA
			'cta_band'         => 'CTA Band',
			'final_cta'        => 'Final CTA',
			// Blog
			'post_feed'        => 'Post Feed',
			// Advanced
			'rich_section'     => 'Rich Section (HTML)',
			'raw_html'         => 'Raw HTML',
		];
	}

	/**
	 * Editable field definitions per layout, consumed by the inspector.
	 * Field types: text, textarea, richtext, select, toggle, url, image,
	 * repeater (with subfields). Fields NOT listed are preserved untouched on
	 * save (internal flags like open_article / wrapper_class / margin_top / v).
	 */
	public static function field_schema(): array {
		// Reusable clusters.
		$image = static function (string $label = 'Image'): array {
			return ['name' => 'image', 'label' => $label, 'type' => 'image'];
		};
		$ctas = [
			'name' => 'ctas', 'label' => 'Buttons', 'type' => 'repeater', 'subfields' => [
				['name' => 'label', 'label' => 'Label', 'type' => 'text'],
				['name' => 'href', 'label' => 'Link', 'type' => 'url'],
				['name' => 'style', 'label' => 'Style', 'type' => 'select', 'options' => ['primary' => 'Primary', 'secondary' => 'Secondary']],
			],
		];
		$cta = [
			['name' => 'headline', 'label' => 'Headline', 'type' => 'text'],
			['name' => 'body', 'label' => 'Body', 'type' => 'textarea'],
			['name' => 'primary_label', 'label' => 'Button label', 'type' => 'text'],
			['name' => 'primary_href', 'label' => 'Button link', 'type' => 'url'],
			['name' => 'secondary_label', 'label' => 'Second button label (blank = Call phone)', 'type' => 'text'],
			['name' => 'secondary_href', 'label' => 'Second button link (blank = tel:)', 'type' => 'url'],
		];
		$schema = [
			/* ---------------- heroes ---------------- */
			'hero' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading (line 1)', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Heading (line 2, gold)', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				$image('Background image'),
				$ctas,
			]],
			'city_hero' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading (line 1)', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Heading (line 2)', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				$image('Background image'),
				['name' => 'badges', 'label' => 'Badges', 'type' => 'repeater', 'subfields' => [
					['name' => 'text', 'label' => 'Badge text', 'type' => 'text'],
				]],
				$ctas,
			]],
			'media_hero' => ['fields' => [
				$image('Background image'),
				['name' => 'body', 'label' => 'Hero content', 'type' => 'richtext'],
			]],

			/* ---------------- structure ---------------- */
			'breadcrumb' => ['fields' => [
				['name' => 'items', 'label' => 'Crumbs', 'type' => 'repeater', 'subfields' => [
					['name' => 'label', 'label' => 'Label', 'type' => 'text'],
					['name' => 'url', 'label' => 'Link (blank = current page)', 'type' => 'url'],
				]],
			]],
			'page_header' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading (H1)', 'type' => 'text'],
				['name' => 'meta', 'label' => 'Meta line', 'type' => 'text'],
			]],

			/* ---------------- text ---------------- */
			'prose' => ['fields' => [
				['name' => 'heading', 'label' => 'Heading (H2)', 'type' => 'text'],
				['name' => 'blocks', 'label' => 'Paragraphs', 'type' => 'repeater', 'subfields' => [
					['name' => 'html', 'label' => 'Text', 'type' => 'richtext'],
					['name' => 'variant', 'label' => 'Style', 'type' => 'select', 'options' => ['normal' => 'Normal', 'lead' => 'Lead']],
				]],
			]],
			'prose_with_image' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'paragraphs', 'label' => 'Paragraphs', 'type' => 'repeater', 'subfields' => [
					['name' => 'html', 'label' => 'Text', 'type' => 'richtext'],
				]],
				['name' => 'checklist', 'label' => 'Checklist', 'type' => 'repeater', 'subfields' => [
					['name' => 'text', 'label' => 'Item', 'type' => 'text'],
				]],
				['name' => 'link_list', 'label' => 'Link rows', 'type' => 'repeater', 'subfields' => [
					['name' => 'label', 'label' => 'Left label', 'type' => 'text'],
					['name' => 'link_text', 'label' => 'Link text', 'type' => 'text'],
					['name' => 'href', 'label' => 'Link', 'type' => 'url'],
				]],
				['name' => 'footnote', 'label' => 'Footnote', 'type' => 'textarea'],
				['name' => 'cta_label', 'label' => 'Button label', 'type' => 'text'],
				['name' => 'cta_href', 'label' => 'Button link', 'type' => 'url'],
				$image('Image'),
			]],
			'prose_article' => ['fields' => [
				['name' => 'body', 'label' => 'Article body', 'type' => 'richtext'],
				['name' => 'aside', 'label' => 'Sidebar (optional)', 'type' => 'richtext'],
			]],
			'legal_doc' => ['fields' => [
				['name' => 'heading', 'label' => 'Heading (H1)', 'type' => 'text'],
				['name' => 'meta', 'label' => 'Sub-line (e.g. Last updated…)', 'type' => 'text'],
				['name' => 'body', 'label' => 'Body', 'type' => 'richtext'],
			]],

			/* ---------------- cards & grids ---------------- */
			'why_overview' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'paragraphs', 'label' => 'Paragraphs', 'type' => 'repeater', 'subfields' => [
					['name' => 'html', 'label' => 'Text', 'type' => 'richtext'],
				]],
				$image('Image'),
			]],
			'trust_image_left' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'paragraphs', 'label' => 'Paragraphs', 'type' => 'repeater', 'subfields' => [
					['name' => 'html', 'label' => 'Text', 'type' => 'richtext'],
				]],
				['name' => 'checklist', 'label' => 'Checklist', 'type' => 'repeater', 'subfields' => [
					['name' => 'text', 'label' => 'Item', 'type' => 'text'],
				]],
				['name' => 'cta_label', 'label' => 'Button label', 'type' => 'text'],
				['name' => 'cta_href', 'label' => 'Button link', 'type' => 'url'],
				$image('Image'),
			]],
			'journey_cards' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				['name' => 'cards', 'label' => 'Cards', 'type' => 'repeater', 'subfields' => [
					['name' => 'number', 'label' => 'Number', 'type' => 'text'],
					['name' => 'title', 'label' => 'Title', 'type' => 'text'],
					['name' => 'body', 'label' => 'Body', 'type' => 'textarea'],
				]],
			]],
			'dark_card_grid' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				['name' => 'cards', 'label' => 'Cards', 'type' => 'repeater', 'subfields' => [
					['name' => 'icon_svg', 'label' => 'Icon', 'type' => 'icon'],
					['name' => 'title', 'label' => 'Title', 'type' => 'text'],
					['name' => 'body', 'label' => 'Body', 'type' => 'textarea'],
					['name' => 'link_label', 'label' => 'Link label', 'type' => 'text'],
					['name' => 'link_href', 'label' => 'Link', 'type' => 'url'],
					['name' => 'link_aria', 'label' => 'Link aria-label', 'type' => 'text'],
				]],
			]],
			'service_card_grid' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				['name' => 'cards', 'label' => 'Cards', 'type' => 'repeater', 'subfields' => [
					['name' => 'icon_svg', 'label' => 'Icon', 'type' => 'icon'],
					['name' => 'title', 'label' => 'Title', 'type' => 'text'],
					['name' => 'body', 'label' => 'Body', 'type' => 'textarea'],
					['name' => 'price_primary', 'label' => 'Price line 1', 'type' => 'text'],
					['name' => 'price_secondary', 'label' => 'Price line 2', 'type' => 'text'],
					['name' => 'link_label', 'label' => 'Link label', 'type' => 'text'],
					['name' => 'link_href', 'label' => 'Link', 'type' => 'url'],
				]],
			]],
			'town_card_grid' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				['name' => 'cta_label', 'label' => 'Card link text (e.g. View Town Profile)', 'type' => 'text'],
				['name' => 'cards', 'label' => 'Towns', 'type' => 'repeater', 'subfields' => [
					['name' => 'county', 'label' => 'County eyebrow', 'type' => 'text'],
					['name' => 'title', 'label' => 'Title', 'type' => 'text'],
					['name' => 'body', 'label' => 'Body', 'type' => 'textarea'],
					['name' => 'href', 'label' => 'Link', 'type' => 'url'],
				]],
			]],
			'link_card_grid' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				['name' => 'cards', 'label' => 'Cards', 'type' => 'repeater', 'subfields' => [
					['name' => 'title', 'label' => 'Title', 'type' => 'text'],
					['name' => 'body', 'label' => 'Description', 'type' => 'textarea'],
					['name' => 'note', 'label' => 'Small note', 'type' => 'text'],
					['name' => 'href', 'label' => 'Link', 'type' => 'url'],
					['name' => 'aria', 'label' => 'Link aria-label', 'type' => 'text'],
				]],
				['name' => 'cta_label', 'label' => 'Button label', 'type' => 'text'],
				['name' => 'cta_href', 'label' => 'Button link', 'type' => 'url'],
			]],
			'feature_cards' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				['name' => 'cards', 'label' => 'Cards', 'type' => 'repeater', 'subfields' => [
					['name' => 'html', 'label' => 'Card content', 'type' => 'richtext'],
				]],
			]],

			'step_cards' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				['name' => 'cards', 'label' => 'Steps', 'type' => 'repeater', 'subfields' => [
					['name' => 'number', 'label' => 'Number', 'type' => 'text'],
					['name' => 'title', 'label' => 'Title', 'type' => 'text'],
					['name' => 'text', 'label' => 'Text', 'type' => 'textarea'],
				]],
			]],

			'gallery' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select'], ['name' => 'columns', 'label' => 'Columns', 'type' => 'select'], ['name' => 'items', 'label' => 'Images', 'type' => 'repeater', 'subfields' => [['name' => 'image', 'label' => 'Image', 'type' => 'image'], ['name' => 'caption', 'label' => 'Caption', 'type' => 'text']]]]],

			'video' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'provider', 'label' => 'Provider', 'type' => 'select', 'options' => ['youtube' => 'YouTube', 'vimeo' => 'Vimeo', 'file' => 'File URL (self-hosted)']], ['name' => 'video_id', 'label' => 'Video ID (YouTube/Vimeo)', 'type' => 'text'], ['name' => 'file_url', 'label' => 'File URL (.mp4/.webm)', 'type' => 'url'], ['name' => 'poster', 'label' => 'Poster image (file only)', 'type' => 'image'], ['name' => 'aspect', 'label' => 'Aspect ratio', 'type' => 'select', 'options' => ['16/9' => '16:9 (widescreen)', '4/3' => '4:3 (standard)']], ['name' => 'max_width', 'label' => 'Max width', 'type' => 'select', 'options' => ['4xl' => 'max-w-4xl', '3xl' => 'max-w-3xl', '5xl' => 'max-w-5xl', 'full' => 'Full width']], ['name' => 'caption', 'label' => 'Caption', 'type' => 'text'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'bg-white', 'brand-50' => 'bg-brand-50']], ['name' => 'section_class', 'label' => 'Section class (overrides bg)', 'type' => 'text']]],

			'columns' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'h2_mt', 'label' => 'Heading top margin', 'type' => 'select', 'options' => ['mt-0' => 'Tight (mt-0)', 'mt-4' => 'Spaced (mt-4)']], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light (brand-50)']], ['name' => 'cols', 'label' => 'Columns', 'type' => 'select', 'options' => ['2' => '2 columns', '3' => '3 columns', '4' => '4 columns']], ['name' => 'gap', 'label' => 'Gap', 'type' => 'select', 'options' => ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large']], ['name' => 'align', 'label' => 'Vertical alignment', 'type' => 'select', 'options' => ['start' => 'Top', 'center' => 'Center', 'stretch' => 'Stretch']], ['name' => 'columns', 'label' => 'Columns', 'type' => 'repeater', 'subfields' => [['name' => 'body', 'label' => 'Column content', 'type' => 'richtext']]]]],

			'team' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'h2_mt', 'label' => 'H2 top margin', 'type' => 'select'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select'], ['name' => 'cols', 'label' => 'Columns', 'type' => 'select'], ['name' => 'members', 'label' => 'Team members', 'type' => 'repeater', 'subfields' => [['name' => 'photo', 'label' => 'Photo', 'type' => 'image'], ['name' => 'name', 'label' => 'Name', 'type' => 'text'], ['name' => 'role', 'label' => 'Role', 'type' => 'text'], ['name' => 'bio', 'label' => 'Bio', 'type' => 'textarea'], ['name' => 'link_label', 'label' => 'Link label', 'type' => 'text'], ['name' => 'link_href', 'label' => 'Link URL', 'type' => 'url']]]]],

			'logos' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select'], ['name' => 'columns', 'label' => 'Columns', 'type' => 'select'], ['name' => 'grayscale', 'label' => 'Grayscale logos', 'type' => 'toggle'], ['name' => 'logos', 'label' => 'Logos', 'type' => 'repeater', 'subfields' => [['name' => 'image', 'label' => 'Logo', 'type' => 'image'], ['name' => 'alt', 'label' => 'Alt text', 'type' => 'text'], ['name' => 'href', 'label' => 'Link (optional)', 'type' => 'url']]]]],

			'embed' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Brand 50']], ['name' => 'embed_url', 'label' => 'Embed URL', 'type' => 'url'], ['name' => 'aspect', 'label' => 'Aspect Ratio', 'type' => 'select', 'options' => ['16x9' => '16:9 (widescreen)', '4x3' => '4:3 (standard)', '1x1' => '1:1 (square)']], ['name' => 'iframe_title', 'label' => 'Iframe Title (accessibility)', 'type' => 'text'], ['name' => 'max_width', 'label' => 'Max Width', 'type' => 'select', 'options' => ['full' => 'Full width', '4xl' => 'Wide (4xl)', '3xl' => 'Medium (3xl)', '2xl' => 'Narrow (2xl)']], ['name' => 'allow_fullscreen', 'label' => 'Allow Fullscreen', 'type' => 'toggle'], ['name' => 'caption', 'label' => 'Caption', 'type' => 'textarea']]],

			'accordion' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'h2_mt', 'label' => 'Heading gap', 'type' => 'select', 'options' => ['mt-0' => 'No gap (home)', 'mt-4' => 'Gap below eyebrow'], 'group' => 'design'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design'], ['name' => 'first_open', 'label' => 'Open first item on load', 'type' => 'toggle', 'group' => 'design'], ['name' => 'items', 'label' => 'Items', 'type' => 'repeater', 'subfields' => [['name' => 'title', 'label' => 'Title', 'type' => 'text'], ['name' => 'body', 'label' => 'Body', 'type' => 'richtext']]]]],

			'button_group' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'align', 'label' => 'Alignment', 'type' => 'select', 'options' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'], 'group' => 'design'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design'], ['name' => 'buttons', 'label' => 'Buttons', 'type' => 'repeater', 'subfields' => [['name' => 'label', 'label' => 'Label', 'type' => 'text'], ['name' => 'href', 'label' => 'Link', 'type' => 'url'], ['name' => 'style', 'label' => 'Style', 'type' => 'select', 'options' => ['primary' => 'Primary', 'secondary' => 'Secondary', 'ghost' => 'Ghost (outlined)']]]]]],

			'callout' => ['fields' => [['name' => 'style', 'label' => 'Style', 'type' => 'select', 'options' => ['info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'danger' => 'Danger'], 'group' => 'design'], ['name' => 'title', 'label' => 'Title', 'type' => 'text'], ['name' => 'icon_svg', 'label' => 'Icon', 'type' => 'icon'], ['name' => 'body', 'label' => 'Body', 'type' => 'richtext'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design']]],

			'cta' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'body', 'label' => 'Body', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Soft tint (brand-50)', 'brand-900' => 'Navy (brand-900)'], 'group' => 'design'], ['name' => 'align', 'label' => 'Alignment', 'type' => 'select', 'options' => ['center' => 'Centered', 'left' => 'Left'], 'group' => 'design'], ['name' => 'buttons', 'label' => 'Buttons', 'type' => 'repeater', 'subfields' => [['name' => 'label', 'label' => 'Label', 'type' => 'text'], ['name' => 'href', 'label' => 'Link', 'type' => 'url'], ['name' => 'style', 'label' => 'Style', 'type' => 'select', 'options' => ['primary' => 'Primary', 'secondary' => 'Secondary']]]]]],

			'divider' => ['fields' => [['name' => 'style', 'label' => 'Line style', 'type' => 'select', 'options' => ['solid' => 'Solid', 'dashed' => 'Dashed'], 'group' => 'design'], ['name' => 'width', 'label' => 'Width', 'type' => 'select', 'options' => ['full' => 'Full width', 'narrow' => 'Narrow (centered)'], 'group' => 'design'], ['name' => 'spacing', 'label' => 'Spacing', 'type' => 'select', 'options' => ['compact' => 'Compact', 'normal' => 'Normal', 'spacious' => 'Spacious'], 'group' => 'design'], ['name' => 'accent', 'label' => 'Accent color', 'type' => 'toggle', 'group' => 'design']]],

			'heading_block' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'level', 'label' => 'Heading level', 'type' => 'select', 'options' => ['h2' => 'H2 (section heading)', 'h3' => 'H3 (sub-section)'], 'group' => 'design'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'align', 'label' => 'Alignment', 'type' => 'select', 'options' => ['center' => 'Centered', 'left' => 'Left'], 'group' => 'design'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design'], ['name' => 'pad', 'label' => 'Section spacing', 'type' => 'select', 'options' => ['normal' => 'Normal', 'compact' => 'Compact', 'spacious' => 'Spacious'], 'group' => 'design']]],

			'icon_list' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design'], ['name' => 'columns', 'label' => 'Columns', 'type' => 'select', 'options' => ['1' => '1 column', '2' => '2 columns'], 'group' => 'design'], ['name' => 'items', 'label' => 'Rows', 'type' => 'repeater', 'subfields' => [['name' => 'icon_svg', 'label' => 'Icon', 'type' => 'icon'], ['name' => 'title', 'label' => 'Title', 'type' => 'text'], ['name' => 'text', 'label' => 'Text', 'type' => 'textarea']]]]],

			'image_block' => ['fields' => [['name' => 'image', 'label' => 'Image', 'type' => 'image'], ['name' => 'alt', 'label' => 'Alt text (optional override)', 'type' => 'text'], ['name' => 'caption', 'label' => 'Caption', 'type' => 'textarea'], ['name' => 'link_href', 'label' => 'Link (optional — wraps image)', 'type' => 'url'], ['name' => 'align', 'label' => 'Alignment', 'type' => 'select', 'options' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'], 'group' => 'design'], ['name' => 'max_width', 'label' => 'Max width', 'type' => 'select', 'options' => ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large', 'full' => 'Full width'], 'group' => 'design'], ['name' => 'aspect', 'label' => 'Crop ratio', 'type' => 'select', 'options' => ['auto' => 'Natural (no crop)', '16/9' => '16:9', '4/3' => '4:3', '1/1' => 'Square', '3/4' => 'Portrait 3:4'], 'group' => 'design'], ['name' => 'rounded', 'label' => 'Rounded corners', 'type' => 'toggle', 'group' => 'design'], ['name' => 'shadow', 'label' => 'Drop shadow', 'type' => 'toggle', 'group' => 'design'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design'], ['name' => 'pad', 'label' => 'Section spacing', 'type' => 'select', 'options' => ['normal' => 'Normal', 'compact' => 'Compact', 'spacious' => 'Spacious'], 'group' => 'design']]],

			'pricing_table' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design'], ['name' => 'cols', 'label' => 'Columns', 'type' => 'select', 'options' => ['2' => '2 columns', '3' => '3 columns', '4' => '4 columns'], 'group' => 'design'], ['name' => 'header_mb', 'label' => 'Header spacing', 'type' => 'select', 'options' => ['mb-12' => 'Standard', 'mb-10' => 'Tight'], 'group' => 'design'], ['name' => 'featured_label', 'label' => 'Featured badge text', 'type' => 'text'], ['name' => 'plans', 'label' => 'Plans', 'type' => 'repeater', 'subfields' => [['name' => 'name', 'label' => 'Plan name', 'type' => 'text'], ['name' => 'price', 'label' => 'Price', 'type' => 'text'], ['name' => 'period', 'label' => 'Period suffix', 'type' => 'text'], ['name' => 'features', 'label' => 'Features (list)', 'type' => 'richtext'], ['name' => 'cta_label', 'label' => 'Button label', 'type' => 'text'], ['name' => 'cta_href', 'label' => 'Button link', 'type' => 'url'], ['name' => 'featured', 'label' => 'Featured plan', 'type' => 'toggle'], ['name' => 'wrapper_class', 'label' => 'Card class override', 'type' => 'text']]]]],

			'quote' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'quote', 'label' => 'Quote', 'type' => 'textarea'], ['name' => 'name', 'label' => 'Attribution name', 'type' => 'text'], ['name' => 'role', 'label' => 'Attribution role', 'type' => 'text'], ['name' => 'align', 'label' => 'Alignment', 'type' => 'select', 'options' => ['center' => 'Centered', 'left' => 'Left'], 'group' => 'design'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['brand-50' => 'Light tint', 'white' => 'White'], 'group' => 'design']]],

			'spacer' => ['fields' => [['name' => 'size', 'label' => 'Gap size', 'type' => 'select', 'options' => ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large', 'xl' => 'Extra large'], 'group' => 'design'], ['name' => 'divider', 'label' => 'Show divider line', 'type' => 'toggle', 'group' => 'design'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design']]],

			'stats' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'h2_mt', 'label' => 'Heading gap', 'type' => 'select', 'options' => ['mt-0' => 'No gap (home)', 'mt-4' => 'Gap below eyebrow'], 'group' => 'design'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design'], ['name' => 'cols', 'label' => 'Columns', 'type' => 'select', 'options' => ['2' => '2 columns', '3' => '3 columns', '4' => '4 columns'], 'group' => 'design'], ['name' => 'stats', 'label' => 'Stats', 'type' => 'repeater', 'subfields' => [['name' => 'value', 'label' => 'Value', 'type' => 'text'], ['name' => 'prefix', 'label' => 'Prefix', 'type' => 'text'], ['name' => 'suffix', 'label' => 'Suffix', 'type' => 'text'], ['name' => 'label', 'label' => 'Label', 'type' => 'text']]]]],

			'text_block' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading (H2)', 'type' => 'text'], ['name' => 'body', 'label' => 'Body', 'type' => 'richtext'], ['name' => 'align', 'label' => 'Text alignment', 'type' => 'select', 'options' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'], 'group' => 'design'], ['name' => 'max_width', 'label' => 'Max width', 'type' => 'select', 'options' => ['prose' => 'Prose (readable)', 'narrow' => 'Narrow', 'wide' => 'Wide', 'full' => 'Full'], 'group' => 'design'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design'], ['name' => 'pad', 'label' => 'Padding', 'type' => 'select', 'options' => ['normal' => 'Normal', 'compact' => 'Compact', 'spacious' => 'Spacious'], 'group' => 'design']]],

			'timeline' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'], ['name' => 'h2_mt', 'label' => 'Heading gap', 'type' => 'select', 'options' => ['mt-0' => 'No gap (home)', 'mt-4' => 'Gap below eyebrow'], 'group' => 'design'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design'], ['name' => 'items', 'label' => 'Timeline items', 'type' => 'repeater', 'subfields' => [['name' => 'date', 'label' => 'Date / label', 'type' => 'text'], ['name' => 'title', 'label' => 'Title', 'type' => 'text'], ['name' => 'body', 'label' => 'Body', 'type' => 'richtext']]]]],

			/* ---------------- social proof ---------------- */
			'testimonials' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				['name' => 'items', 'label' => 'Quotes', 'type' => 'repeater', 'subfields' => [
					['name' => 'quote', 'label' => 'Quote', 'type' => 'textarea'],
					['name' => 'name', 'label' => 'Name', 'type' => 'text'],
					['name' => 'role', 'label' => 'Role', 'type' => 'text'],
				]],
				['name' => 'cta_label', 'label' => 'Button label', 'type' => 'text'],
				['name' => 'cta_href', 'label' => 'Button link', 'type' => 'url'],
			]],

			/* ---------------- faq ---------------- */
			'faq' => ['fields' => [
				['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'items', 'label' => 'Questions', 'type' => 'repeater', 'subfields' => [
					['name' => 'q', 'label' => 'Question', 'type' => 'text'],
					['name' => 'a', 'label' => 'Answer', 'type' => 'richtext'],
				]],
				['name' => 'schema', 'label' => 'Emit FAQ rich-results', 'type' => 'toggle'],
			]],
			'faq_dl' => ['fields' => [
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'items', 'label' => 'Questions', 'type' => 'repeater', 'subfields' => [
					['name' => 'q', 'label' => 'Question', 'type' => 'text'],
					['name' => 'a', 'label' => 'Answer', 'type' => 'richtext'],
				]],
				['name' => 'schema', 'label' => 'Emit FAQ rich-results', 'type' => 'toggle'],
			]],

			/* ---------------- cta ---------------- */
			'cta_band' => ['fields' => $cta],
			'final_cta' => ['fields' => [
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'subheading', 'label' => 'Subheading', 'type' => 'text'],
				['name' => 'body', 'label' => 'Body', 'type' => 'textarea'],
				$image('Background image'),
				['name' => 'cta_label', 'label' => 'Button label', 'type' => 'text'],
				['name' => 'cta_href', 'label' => 'Button link', 'type' => 'url'],
				['name' => 'footnote', 'label' => 'Footnote', 'type' => 'text'],
			]],

			/* ---------------- blog ---------------- */
			'post_feed' => ['fields' => [
				['name' => 'heading', 'label' => 'Heading', 'type' => 'text'],
				['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'],
				['name' => 'limit', 'label' => 'Max posts', 'type' => 'text'],
			]],

			/* ---------------- advanced (raw HTML) ---------------- */
			'rich_section' => ['fields' => [
				['name' => 'body', 'label' => 'HTML body (advanced)', 'type' => 'code'],
			]],
			'raw_html' => ['fields' => [
				['name' => 'html', 'label' => 'HTML (advanced)', 'type' => 'code'],
			]],
		];

		/*
		 * Design controls. These fields ALREADY exist in the ACF registration
		 * (includes/fields/sections.php) and are honored by the templates; the
		 * first option of each select is the current default, so surfacing them
		 * changes nothing until an editor deliberately picks another value
		 * (pixel parity preserved by default). Grouped under "Design" in the
		 * inspector via the 'group' => 'design' marker.
		 */
		$sel = static function (string $name, string $label, array $options): array {
			return ['name' => $name, 'label' => $label, 'type' => 'select', 'options' => $options, 'group' => 'design'];
		};
		$tog = static function (string $name, string $label): array {
			return ['name' => $name, 'label' => $label, 'type' => 'toggle', 'group' => 'design'];
		};
		$h2_mt = $sel('h2_mt', 'Heading gap', ['mt-0' => 'No gap (home)', 'mt-4' => 'Gap below eyebrow']);
		$bg_light = $sel('bg', 'Background', ['white' => 'White', 'brand-50' => 'Light tint']);
		$pad_sel = $sel('pad', 'Section spacing', ['normal' => 'Normal', 'compact' => 'Compact', 'spacious' => 'Spacious']);
		$design = [
			'hero'             => [$sel('intro_max', 'Intro width', ['860' => 'Standard', '930' => 'Wide'])],
			'why_overview'     => [$bg_light, $pad_sel],
			'trust_image_left' => [$bg_light, $pad_sel],
			'city_hero'        => [$sel('sub_style', 'Second line style', ['h1-sub' => 'Gold underline', 'text-accent-500' => 'Solid gold'])],
			'prose'            => [$sel('margin_top', 'Top spacing', ['mt-10' => 'Normal', 'mt-8' => 'Tight'])],
			'prose_with_image' => [
				$bg_light,
				$sel('image_side', 'Image position', ['right' => 'Image on right', 'left' => 'Image on left']),
				$sel('align', 'Vertical alignment', ['start' => 'Align top', 'center' => 'Align center']),
			],
			'journey_cards'    => [$h2_mt],
			'dark_card_grid'   => [$h2_mt, $tog('compact', 'Compact cards (service pages)')],
			'testimonials'     => [
				$h2_mt,
				$sel('bg', 'Background', ['brand-50' => 'Light tint (home)', 'white' => 'White (service)']),
			],
			'faq'              => [$h2_mt],
			'link_card_grid'   => [
				$sel('variant', 'Card style', ['bare' => 'Plain', 'light' => 'Light card', 'dark' => 'Dark card']),
				$sel('cols', 'Columns', ['3' => '3 columns', '4' => '4 columns']),
				$sel('bg', 'Background (light style)', ['brand-50' => 'Light tint', 'white' => 'White']),
			],
			'town_card_grid'   => [
				$bg_light,
				$sel('card_heading_size', 'Card heading size', ['base' => 'Normal', 'xl' => 'Large']),
				$tog('line_clamp', 'Clamp card text to 3 lines'),
			],
			'feature_cards'    => [
				$bg_light,
				$sel('header_mb', 'Header spacing', ['mb-12' => 'Standard', 'mb-10' => 'Tight']),
			],
			'breadcrumb'       => [$sel('variant', 'Style', ['plain' => 'Plain', 'wide' => 'Wide band', 'wide_index' => 'Wide (index hub)'])],
		];
		foreach ($design as $type => $fields) {
			if (isset($schema[$type])) {
				$schema[$type]['fields'] = array_merge($schema[$type]['fields'], $fields);
			}
		}
		return $schema;
	}

	/**
	 * Curated icon set for the inspector's icon picker (icon_svg fields). Each
	 * value is complete <svg> markup (Font Awesome Free 7.3.0, CC BY 4.0) using
	 * fill=currentColor, so it inherits the card badge's color. Editors can still
	 * paste custom SVG (e.g. Font Awesome Pro) via the picker's advanced box.
	 */
	public static function icon_library(): array {
		// Font Awesome Free 7.3.0 (CC BY 4.0). Complete <svg> markup, fill=currentColor
		// so each icon inherits its container color. Editors can also paste custom SVG.
		return [
			// — Trees, Landscape & Nature —
			'Tree' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M224-32c7 0 13.7 3.1 18.3 8.5l136 160c6.1 7.1 7.4 17.1 3.5 25.6S369.4 176 360 176l-24.9 0 75.2 88.5c6.1 7.1 7.4 17.1 3.5 25.6S401.4 304 392 304l-38.5 0 88.8 104.5c6.1 7.1 7.4 17.1 3.5 25.6S433.4 448 424 448l-168 0 0 64c0 17.7-14.3 32-32 32s-32-14.3-32-32l0-64-168 0c-9.4 0-17.9-5.4-21.8-13.9s-2.6-18.5 3.5-25.6L94.5 304 56 304c-9.4 0-17.9-5.4-21.8-13.9s-2.6-18.5 3.5-25.6L112.9 176 88 176c-9.4 0-17.9-5.4-21.8-13.9s-2.6-18.5 3.5-25.6l136-160C210.3-28.9 217-32 224-32z"/></svg>',
			'Tree (City)' => '<svg width="24" height="24" viewBox="0 0 640 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M288 48c0-26.5 21.5-48 48-48l96 0c26.5 0 48 21.5 48 48l0 48 48 0 0-72c0-13.3 10.7-24 24-24s24 10.7 24 24l0 72 16 0c26.5 0 48 21.5 48 48l0 320c0 26.5-21.5 48-48 48l-256 0c-26.5 0-48-21.5-48-48l0-416zm64 64l0 32c0 8.8 7.2 16 16 16l32 0c8.8 0 16-7.2 16-16l0-32c0-8.8-7.2-16-16-16l-32 0c-8.8 0-16 7.2-16 16zm16 80c-8.8 0-16 7.2-16 16l0 32c0 8.8 7.2 16 16 16l32 0c8.8 0 16-7.2 16-16l0-32c0-8.8-7.2-16-16-16l-32 0zM352 304l0 32c0 8.8 7.2 16 16 16l32 0c8.8 0 16-7.2 16-16l0-32c0-8.8-7.2-16-16-16l-32 0c-8.8 0-16 7.2-16 16zM528 192c-8.8 0-16 7.2-16 16l0 32c0 8.8 7.2 16 16 16l32 0c8.8 0 16-7.2 16-16l0-32c0-8.8-7.2-16-16-16l-32 0zM512 304l0 32c0 8.8 7.2 16 16 16l32 0c8.8 0 16-7.2 16-16l0-32c0-8.8-7.2-16-16-16l-32 0c-8.8 0-16 7.2-16 16zM96 480l0-160-16 0c-44.2 0-80-35.8-80-80 0-26.7 13.1-50.3 33.2-64.9-.8-4.9-1.2-10-1.2-15.1 0-53 43-96 96-96s96 43 96 96l0 96c0 35.3-28.7 64-64 64l0 160c0 17.7-14.3 32-32 32s-32-14.3-32-32z"/></svg>',
			'Leaf' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M471.3 6.7C477.7 .6 487-1.6 495.6 1.2 505.4 4.5 512 13.7 512 24l0 186.9c0 131.2-108.1 237.1-238.8 237.1-77 0-143.4-49.5-167.5-118.7-35.4 30.8-57.7 76.1-57.7 126.7 0 13.3-10.7 24-24 24S0 469.3 0 456C0 381.1 38.2 315.1 96.1 276.3 131.4 252.7 173.5 240 216 240l80 0c13.3 0 24-10.7 24-24s-10.7-24-24-24l-80 0c-39.7 0-77.3 8.8-111 24.5 23.3-70 89.2-120.5 167-120.5 66.4 0 115.8-22.1 148.7-44 19.2-12.8 35.5-28.1 50.7-45.3z"/></svg>',
			'Seedling' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M512 32C512 140.1 435.4 230.3 333.6 251.4 325.7 193.3 299.6 141 261.1 100.5 301.2 40 369.9 0 448 0l32 0c17.7 0 32 14.3 32 32zM0 96C0 78.3 14.3 64 32 64l32 0c123.7 0 224 100.3 224 224l0 192c0 17.7-14.3 32-32 32s-32-14.3-32-32l0-160C100.3 320 0 219.7 0 96z"/></svg>',
			'Wheat' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M79.7 234.6c6.2-4.1 14.7-3.4 20.1 2.1l46.1 46.1 6.1 6.7c19.7 23.8 26.3 55 19.2 83.9 31.7-7.7 66.2 1 90.6 25.3l46.1 46.1c6.2 6.2 6.2 16.4 0 22.6l-7.4 7.4c-37.5 37.5-98.3 37.5-135.8 0L134.1 444.3 49.4 529c-9.4 9.4-24.5 9.4-33.9 0-9.4-9.4-9.4-24.6 0-33.9l84.7-84.7-30.5-30.5c-37.5-37.5-37.5-98.3 0-135.7l7.4-7.4 2.5-2.1zm104-104c6.2-4.1 14.7-3.4 20.1 2.1l46.1 46.1 6.1 6.7c19.7 23.8 26.3 55 19.2 83.9 31.7-7.7 66.2 1 90.6 25.3l46.1 46.1c6.2 6.2 6.2 16.4 0 22.6l-7.4 7.4c-37.5 37.5-98.3 37.5-135.8 0l-94.9-94.9c-37.5-37.5-37.5-98.3 0-135.7l7.4-7.4 2.5-2.1zM495.2 15c9.4-9.4 24.6-9.4 34 0 8.8 8.8 9.3 22.7 1.6 32.2L529.2 49 414.7 163.4c7.7 1 15.2 3 22.5 5.9L495.5 111c9.4-9.4 24.6-9.4 34 0 8.8 8.8 9.3 22.7 1.6 32.1l-1.7 1.8-52.7 52.7 39 39c6.2 6.2 6.2 16.4 0 22.6l-7.4 7.4c-37.5 37.5-98.3 37.5-135.8 0l-94.9-94.9c-37.5-37.5-37.5-98.3 0-135.7l7.4-7.4 2.5-2.1c6.2-4.1 14.7-3.4 20.1 2.1l39 39 52.7-52.7c9.4-9.4 24.6-9.4 34 0 8.8 8.8 9.3 22.7 1.6 32.1l-1.7 1.8-58.3 58.3c2.8 7.1 4.7 14.5 5.7 22.1L495.2 15z"/></svg>',
			'Sun' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M288-32c8.4 0 16.3 4.4 20.6 11.7L364.1 72.3 468.9 46c8.2-2 16.9 .4 22.8 6.3S500 67 498 75.1l-26.3 104.7 92.7 55.5c7.2 4.3 11.7 12.2 11.7 20.6s-4.4 16.3-11.7 20.6L471.7 332.1 498 436.8c2 8.2-.4 16.9-6.3 22.8S477 468 468.9 466l-104.7-26.3-55.5 92.7c-4.3 7.2-12.2 11.7-20.6 11.7s-16.3-4.4-20.6-11.7L211.9 439.7 107.2 466c-8.2 2-16.8-.4-22.8-6.3S76 445 78 436.8l26.2-104.7-92.6-55.5C4.4 272.2 0 264.4 0 256s4.4-16.3 11.7-20.6L104.3 179.9 78 75.1c-2-8.2 .3-16.8 6.3-22.8S99 44 107.2 46l104.7 26.2 55.5-92.6 1.8-2.6c4.5-5.7 11.4-9.1 18.8-9.1zm0 144a144 144 0 1 0 0 288 144 144 0 1 0 0-288zm0 240a96 96 0 1 1 0-192 96 96 0 1 1 0 192z"/></svg>',
			'Storm (Cloud Bolt)' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M0 224c0 53 43 96 96 96l38.6 0 124.1-111c12.2-10.9 28-17 44.4-17 44.6 0 76.5 43 63.7 85.7L354.1 320 416 320c53 0 96-43 96-96s-43-96-96-96c-.5 0-1.1 0-1.6 0 1.1-5.2 1.6-10.5 1.6-16 0-44.2-35.8-80-80-80-24.3 0-46.1 10.9-60.8 28-18.7-35.7-56.1-60-99.2-60-61.9 0-112 50.1-112 112 0 7.1 .7 14.1 1.9 20.8-38.3 12.6-65.9 48.7-65.9 91.2zM160.6 400l61.8 0-31.2 104.1c-3.6 11.9 5.3 23.9 17.8 23.9 4.6 0 9-1.7 12.4-4.7L362.5 396.9c3.5-3.1 5.5-7.6 5.5-12.4 0-9.2-7.4-16.6-16.6-16.6l-61.8 0 31.2-104.1c3.6-11.9-5.3-23.9-17.8-23.9-4.6 0-9 1.7-12.4 4.7L149.5 371.1c-3.5 3.1-5.5 7.6-5.5 12.4 0 9.2 7.4 16.6 16.6 16.6z"/></svg>',
			'Wind' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M288 32c0 17.7 14.3 32 32 32l40 0c13.3 0 24 10.7 24 24s-10.7 24-24 24L32 112c-17.7 0-32 14.3-32 32s14.3 32 32 32l328 0c48.6 0 88-39.4 88-88S408.6 0 360 0L320 0c-17.7 0-32 14.3-32 32zm64 352c0 17.7 14.3 32 32 32l32 0c53 0 96-43 96-96s-43-96-96-96L32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l384 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-32 0c-17.7 0-32 14.3-32 32zM128 512l40 0c48.6 0 88-39.4 88-88s-39.4-88-88-88L32 336c-17.7 0-32 14.3-32 32s14.3 32 32 32l136 0c13.3 0 24 10.7 24 24s-10.7 24-24 24l-40 0c-17.7 0-32 14.3-32 32s14.3 32 32 32z"/></svg>',
			'Snowflake' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M288.2 0c0-17.7-14.3-32-32-32s-32 14.3-32 32l0 62.1-15-15c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l49 49 0 70.6-61.2-35.3-17.9-66.9c-3.4-12.8-16.6-20.4-29.4-17S95.3 98 98.7 110.8l5.5 20.5-53.7-31C35.2 91.5 15.6 96.7 6.8 112s-3.6 34.9 11.7 43.7l53.7 31-20.5 5.5c-12.8 3.4-20.4 16.6-17 29.4s16.6 20.4 29.4 17l66.9-17.9 61.2 35.3-61.2 35.3-66.9-17.9c-12.8-3.4-26 4.2-29.4 17s4.2 26 17 29.4l20.5 5.5-53.7 31C3.2 365.1-2 384.7 6.8 400s28.4 20.6 43.7 11.7l53.7-31-5.5 20.5c-3.4 12.8 4.2 26 17 29.4s26-4.2 29.4-17l17.9-66.9 61.2-35.3 0 70.6-49 49c-9.4 9.4-9.4 24.6 0 33.9s24.6 9.4 33.9 0l15-15 0 62.1c0 17.7 14.3 32 32 32s32-14.3 32-32l0-62.1 15 15c9.4 9.4 24.6 9.4 33.9 0s9.4-24.6 0-33.9l-49-49 0-70.6 61.2 35.3 17.9 66.9c3.4 12.8 16.6 20.4 29.4 17s20.4-16.6 17-29.4l-5.5-20.5 53.7 31c15.3 8.8 34.9 3.6 43.7-11.7s3.6-34.9-11.7-43.7l-53.7-31 20.5-5.5c12.8-3.4 20.4-16.6 17-29.4s-16.6-20.4-29.4-17l-66.9 17.9-61.2-35.3 61.2-35.3 66.9 17.9c12.8 3.4 26-4.2 29.4-17s-4.2-26-17-29.4l-20.5-5.5 53.7-31c15.3-8.8 20.6-28.4 11.7-43.7s-28.4-20.5-43.7-11.7l-53.7 31 5.5-20.5c3.4-12.8-4.2-26-17-29.4s-26 4.2-29.4 17l-17.9 66.9-61.2 35.3 0-70.6 49-49c9.4-9.4 9.4-24.6 0-33.9s-24.6-9.4-33.9 0l-15 15 0-62.1z"/></svg>',
			'Fire' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M160.5-26.4c9.3-7.8 23-7.5 31.9 .9 12.3 11.6 23.3 24.4 33.9 37.4 13.5 16.5 29.7 38.3 45.3 64.2 5.2-6.8 10-12.8 14.2-17.9 1.1-1.3 2.2-2.7 3.3-4.1 7.9-9.8 17.7-22.1 30.8-22.1 13.4 0 22.8 11.9 30.8 22.1 1.3 1.7 2.6 3.3 3.9 4.8 10.3 12.4 24 30.3 37.7 52.4 27.2 43.9 55.6 106.4 55.6 176.6 0 123.7-100.3 224-224 224S0 411.7 0 288c0-91.1 41.1-170 80.5-225 19.9-27.7 39.7-49.9 54.6-65.1 8.2-8.4 16.5-16.7 25.5-24.2zM225.7 416c25.3 0 47.7-7 68.8-21 42.1-29.4 53.4-88.2 28.1-134.4-4.5-9-16-9.6-22.5-2l-25.2 29.3c-6.6 7.6-18.5 7.4-24.7-.5-17.3-22.1-49.1-62.4-65.3-83-5.4-6.9-15.2-8-21.5-1.9-18.3 17.8-51.5 56.8-51.5 104.3 0 68.6 50.6 109.2 113.7 109.2z"/></svg>',
			'Mountain & Sun' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M256.5 0c14.7 0 28.2 8.1 35.2 21l216 400c6.7 12.4 6.4 27.4-.8 39.5-7.2 12.1-20.3 19.5-34.3 19.5l-432 0c-14.1 0-27.1-7.4-34.3-19.5s-7.5-27.1-.8-39.5l216-400 2.9-4.6C231.7 6.2 243.6 0 256.5 0zM170.4 249.9l26.8 26.8c6.2 6.2 16.4 6.2 22.6 0l43.3-43.3c6-6 14.1-9.4 22.6-9.4l42.8 0-72.1-133.5-86.1 159.4zM496.5 160a80 80 0 1 1 0-160 80 80 0 1 1 0 160z"/></svg>',
			'Water' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M410.6 124.1c20.7 15.6 46 30.7 74.2 34.5 13.1 1.8 25.2-7.5 27-20.6s-7.5-25.2-20.6-27c-15.9-2.1-33.2-11.3-51.7-25.2-38.4-29-90.5-29-129 0-24 18.1-40.7 26.3-54.5 26.3s-30.5-8.2-54.5-26.3c-38.4-29-90.5-29-129 0-18.5 13.9-35.8 23.1-51.7 25.2-13.1 1.8-22.4 13.8-20.6 27s13.8 22.4 27 20.6c28.2-3.8 53.6-18.9 74.2-34.5 21.3-16.1 49.9-16.1 71.2 0 24.2 18.3 52.3 35.9 83.4 35.9s59.1-17.7 83.4-35.9c21.3-16.1 49.9-16.1 71.2 0zm0 144c20.7 15.6 46 30.7 74.2 34.5 13.1 1.8 25.2-7.5 27-20.6s-7.5-25.2-20.6-27c-15.9-2.1-33.2-11.3-51.7-25.2-38.4-29-90.5-29-129 0-24 18.1-40.7 26.3-54.5 26.3s-30.5-8.2-54.5-26.3c-38.4-29-90.5-29-129 0-18.5 13.9-35.8 23.1-51.7 25.2-13.1 1.7-22.4 13.8-20.6 27s13.8 22.4 27 20.6c28.2-3.8 53.6-18.9 74.2-34.5 21.3-16.1 49.9-16.1 71.2 0 24.2 18.3 52.3 35.9 83.4 35.9s59.1-17.7 83.4-35.9c21.3-16.1 49.9-16.1 71.2 0zm-71.2 144c21.3-16.1 49.9-16.1 71.2 0 20.7 15.6 46 30.7 74.2 34.5 13.1 1.8 25.2-7.5 27-20.6s-7.5-25.2-20.6-27c-15.9-2.1-33.2-11.3-51.7-25.2-38.4-29-90.5-29-129 0-24 18.1-40.7 26.3-54.5 26.3s-30.5-8.2-54.5-26.3c-38.4-29-90.5-29-129 0-18.5 13.9-35.8 23.1-51.7 25.2-13.1 1.8-22.4 13.8-20.6 27s13.8 22.4 27 20.6c28.2-3.8 53.6-18.9 74.2-34.5 21.3-16.1 49.9-16.1 71.2 0 24.2 18.3 52.3 35.9 83.4 35.9s59.1-17.7 83.4-35.9z"/></svg>',
			'Droplet' => '<svg width="24" height="24" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M192 512C86 512 0 426 0 320 0 228.8 130.2 45.9 166.6-3.5 172.5-11.5 181.8-16 191.8-16l.4 0c10 0 19.3 4.5 25.2 12.5 36.4 49.4 166.6 232.3 166.6 323.5 0 106-86 192-192 192zM112 312c0-13.3-10.7-24-24-24s-24 10.7-24 24c0 75.1 60.9 136 136 136 13.3 0 24-10.7 24-24s-10.7-24-24-24c-48.6 0-88-39.4-88-88z"/></svg>',
			'Faucet Drip' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M224 32c-17.7 0-32 14.3-32 32L96 64C78.3 64 64 78.3 64 96s14.3 32 32 32l96 0 0 64-18.7 0c-8.5 0-16.6 3.4-22.6 9.4L128 224 32 224c-17.7 0-32 14.3-32 32l0 64c0 17.7 14.3 32 32 32l100.1 0c20.2 29 53.9 48 91.9 48s71.7-19 91.9-48l36.1 0c17.7 0 32 14.3 32 32s14.3 32 32 32l64 0c17.7 0 32-14.3 32-32 0-88.4-71.6-160-160-160l-32 0-22.6-22.6c-6-6-14.1-9.4-22.6-9.4l-18.7 0 0-64 96 0c17.7 0 32-14.3 32-32s-14.3-32-32-32l-96 0c0-17.7-14.3-32-32-32zM436.8 455.4l-18.2 42.4c-1.8 4.1-2.7 8.6-2.7 13.1l0 1.2c0 17.7 14.3 32 32 32s32-14.3 32-32l0-1.2c0-4.5-.9-8.9-2.7-13.1l-18.2-42.4c-1.9-4.5-6.3-7.4-11.2-7.4s-9.2 2.9-11.2 7.4z"/></svg>',
			'Spray Can' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M160 32l0 80 128 0 0-80c0-17.7-14.3-32-32-32L192 0c-17.7 0-32 14.3-32 32zm0 128c-53 0-96 43-96 96l0 208c0 26.5 21.5 48 48 48l224 0c26.5 0 48-21.5 48-48l0-208c0-53-43-96-96-96l-128 0zm64 96a80 80 0 1 1 0 160 80 80 0 1 1 0-160zM448 48c0-1.4-1-3-2.2-3.6L416 32 403.6 2.2C403 1 401.4 0 400 0s-3 1-3.6 2.2L384 32 354.2 44.4c-1.2 .6-2.2 2.2-2.2 3.6 0 1.4 1 3 2.2 3.6L384 64 396.4 93.8C397 95 398.6 96 400 96s3-1 3.6-2.2L416 64 445.8 51.6C447 51 448 49.4 448 48zm76.4 45.8C525 95 526.6 96 528 96s3-1 3.6-2.2L544 64 573.8 51.6c1.2-.6 2.2-2.2 2.2-3.6 0-1.4-1-3-2.2-3.6L544 32 531.6 2.2C531 1 529.4 0 528 0s-3 1-3.6 2.2L512 32 482.2 44.4c-1.2 .6-2.2 2.2-2.2 3.6 0 1.4 1 3 2.2 3.6L512 64 524.4 93.8zm7.2 100.4c-.6-1.2-2.2-2.2-3.6-2.2s-3 1-3.6 2.2L512 224 482.2 236.4c-1.2 .6-2.2 2.2-2.2 3.6 0 1.4 1 3 2.2 3.6L512 256 524.4 285.8c.6 1.2 2.2 2.2 3.6 2.2s3-1 3.6-2.2L544 256 573.8 243.6c1.2-.6 2.2-2.2 2.2-3.6 0-1.4-1-3-2.2-3.6L544 224 531.6 194.2zM512 144c0-1.4-1-3-2.2-3.6L480 128 467.6 98.2C467 97 465.4 96 464 96s-3 1-3.6 2.2L448 128 418.2 140.4c-1.2 .6-2.2 2.2-2.2 3.6 0 1.4 1 3 2.2 3.6L448 160 460.4 189.8c.6 1.2 2.2 2.2 3.6 2.2s3-1 3.6-2.2L480 160 509.8 147.6c1.2-.6 2.2-2.2 2.2-3.6z"/></svg>',
			'Broom' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M566.6 54.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0l-192 192-34.7-34.7c-4.2-4.2-10-6.6-16-6.6-12.5 0-22.6 10.1-22.6 22.6l0 29.1 108.3 108.3 29.1 0c12.5 0 22.6-10.1 22.6-22.6 0-6-2.4-11.8-6.6-16l-34.7-34.7 192-192zM341.1 353.4L222.6 234.9c-42.7-3.7-85.2 11.7-115.8 42.3l-8 8c-22.3 22.3-34.8 52.5-34.8 84 0 6.8 7.1 11.2 13.2 8.2l51.1-25.5c5-2.5 9.5 4.1 5.4 7.9L7.3 473.4C2.7 477.6 0 483.6 0 489.9 0 502.1 9.9 512 22.1 512l173.3 0c38.8 0 75.9-15.4 103.4-42.8 30.6-30.6 45.9-73.1 42.3-115.8z"/></svg>',
			'Trowel' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M407.9 181.4L277.3 312 342.6 377.4c7.9 7.9 11.1 19.4 8.4 30.3s-10.8 19.6-21.5 22.9l-256 80c-11.4 3.5-23.8 .5-32.2-7.9s-11.5-20.8-7.9-32.2l80-256c3.3-10.7 12-18.9 22.9-21.5s22.4 .5 30.3 8.4L232 266.7 362.6 136.1c-14.3-14.6-14.2-38 .3-52.5l95.4-95.4c26.9-26.9 70.5-26.9 97.5 0s26.9 70.5 0 97.5l-95.4 95.4c-14.5 14.5-37.9 14.6-52.5 .3z"/></svg>',
			'Trowel & Bricks' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M224 32c0-11.5-6.2-22.2-16.2-27.8s-22.3-5.5-32.2 .4l-160 96C5.9 106.3 0 116.8 0 128s5.9 21.7 15.5 27.4l160 96c9.9 5.9 22.2 6.1 32.2 .4S224 235.5 224 224l0-64 256 0c17.7 0 32-14.3 32-32s-14.3-32-32-32l-256 0 0-64zm96 192c-17.7 0-32 14.3-32 32l0 64c0 17.7 14.3 32 32 32l160 0c17.7 0 32-14.3 32-32l0-64c0-17.7-14.3-32-32-32l-160 0zM0 416l0 64c0 17.7 14.3 32 32 32l96 0c17.7 0 32-14.3 32-32l0-64c0-17.7-14.3-32-32-32l-96 0c-17.7 0-32 14.3-32 32zm224-32c-17.7 0-32 14.3-32 32l0 64c0 17.7 14.3 32 32 32l256 0c17.7 0 32-14.3 32-32l0-64c0-17.7-14.3-32-32-32l-256 0z"/></svg>',
			// — Trades & Equipment —
			'Truck' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M0 96C0 60.7 28.7 32 64 32l288 0c35.3 0 64 28.7 64 64l0 32 50.7 0c17 0 33.3 6.7 45.3 18.7L557.3 192c12 12 18.7 28.3 18.7 45.3L576 384c0 35.3-28.7 64-64 64l-3.3 0c-10.4 36.9-44.4 64-84.7 64s-74.2-27.1-84.7-64l-102.6 0c-10.4 36.9-44.4 64-84.7 64s-74.2-27.1-84.7-64L64 448c-35.3 0-64-28.7-64-64L0 96zM512 288l0-50.7-45.3-45.3-50.7 0 0 96 96 0zM192 424a40 40 0 1 0 -80 0 40 40 0 1 0 80 0zm232 40a40 40 0 1 0 0-80 40 40 0 1 0 0 80z"/></svg>',
			'Pickup Truck' => '<svg width="24" height="24" viewBox="0 0 640 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M363.8 96l57.6 96-133.4 0 0-96 75.8 0zM496 192L418.6 63.1C407.1 43.8 386.2 32 363.8 32L256 32c-17.7 0-32 14.3-32 32l0 128-144 0c-26.5 0-48 21.5-48 48l0 80c-17.7 0-32 14.3-32 32s14.3 32 32 32l32.4 0c-.2 2.6-.4 5.3-.4 8 0 48.6 39.4 88 88 88s88-39.4 88-88c0-2.7-.1-5.4-.4-8l160.7 0c-.2 2.6-.4 5.3-.4 8 0 48.6 39.4 88 88 88s88-39.4 88-88c0-2.7-.1-5.4-.4-8l32.4 0c17.7 0 32-14.3 32-32s-14.3-32-32-32l0-80c0-26.5-21.5-48-48-48l-64 0zM112 392a40 40 0 1 1 80 0 40 40 0 1 1 -80 0zm376-40a40 40 0 1 1 0 80 40 40 0 1 1 0-80z"/></svg>',
			'Trailer' => '<svg width="24" height="24" viewBox="0 0 640 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M32 96c0-35.3 28.7-64 64-64l384 0c35.3 0 64 28.7 64 64l0 256 64 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-296.4 0c.2 2.6 .4 5.3 .4 8 0 48.6-39.4 88-88 88s-88-39.4-88-88c0-2.7 .1-5.4 .4-8L96 416c-35.3 0-64-28.7-64-64L32 96zm408 16c-13.3 0-24 10.7-24 24l0 160c0 13.3 10.7 24 24 24s24-10.7 24-24l0-160c0-13.3-10.7-24-24-24zM112 136l0 160c0 13.3 10.7 24 24 24s24-10.7 24-24l0-160c0-13.3-10.7-24-24-24s-24 10.7-24 24zm176-24c-13.3 0-24 10.7-24 24l0 160c0 13.3 10.7 24 24 24s24-10.7 24-24l0-160c0-13.3-10.7-24-24-24zM264 424a40 40 0 1 0 -80 0 40 40 0 1 0 80 0z"/></svg>',
			'Safety Helmet' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M352 264l0-200c0-17.7-14.3-32-32-32l-64 0c-17.7 0-32 14.3-32 32l0 200c0 13.3-10.7 24-24 24s-24-10.7-24-24l0-185.9C90 109.8 32 191.8 32 288l0 64 512 0 0-64c-1-95.2-58.4-177.7-144-209.8L400 264c0 13.3-10.7 24-24 24s-24-10.7-24-24zM40 400c-22.1 0-40 17.9-40 40s17.9 40 40 40l496 0c22.1 0 40-17.9 40-40s-17.9-40-40-40L40 400z"/></svg>',
			'Hammer' => '<svg width="24" height="24" viewBox="0 0 640 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M246.9 18.3L271 3.8c21.6-13 46.3-19.8 71.5-19.8 36.8 0 72.2 14.6 98.2 40.7l63.9 63.9c15 15 23.4 35.4 23.4 56.6l0 30.9 19.7 19.7 0 0c15.6-15.6 40.9-15.6 56.6 0s15.6 40.9 0 56.6l-64 64c-15.6 15.6-40.9 15.6-56.6 0s-15.6-40.9 0-56.6L464 240 433.1 240c-21.2 0-41.6-8.4-56.6-23.4l-49.1-49.1c-15-15-23.4-35.4-23.4-56.6l0-12.7c0-11.2-5.9-21.7-15.5-27.4l-41.6-25c-10.4-6.2-10.4-21.2 0-27.4zM50.7 402.7l222.1-222.1 90.5 90.5-222.1 222.1c-25 25-65.5 25-90.5 0s-25-65.5 0-90.5z"/></svg>',
			'Screwdriver & Wrench' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M70.8-6.7c5.4-5.4 13.8-6.2 20.2-2L209.9 70.5c8.9 5.9 14.2 15.9 14.2 26.6l0 49.6 90.8 90.8c33.3-15 73.9-8.9 101.2 18.5L542.2 382.1c18.7 18.7 18.7 49.1 0 67.9l-60.1 60.1c-18.7 18.7-49.1 18.7-67.9 0L288.1 384c-27.4-27.4-33.5-67.9-18.5-101.2l-90.8-90.8-49.6 0c-10.7 0-20.7-5.3-26.6-14.2L23.4 58.9c-4.2-6.3-3.4-14.8 2-20.2L70.8-6.7zm145 303.5c-6.3 36.9 2.3 75.9 26.2 107.2l-94.9 95c-28.1 28.1-73.7 28.1-101.8 0s-28.1-73.7 0-101.8l135.4-135.5 35.2 35.1zM384.1 0c20.1 0 39.4 3.7 57.1 10.5 10 3.8 11.8 16.5 4.3 24.1L388.8 91.3c-3 3-4.7 7.1-4.7 11.3l0 41.4c0 8.8 7.2 16 16 16l41.4 0c4.2 0 8.3-1.7 11.3-4.7l56.7-56.7c7.6-7.5 20.3-5.7 24.1 4.3 6.8 17.7 10.5 37 10.5 57.1 0 43.2-17.2 82.3-45 111.1l-49.1-49.1c-33.1-33-78.5-45.7-121.1-38.4l-56.8-56.8 0-29.7-.2-5c-.8-12.4-4.4-24.3-10.5-34.9 29.4-35 73.4-57.2 122.7-57.3z"/></svg>',
			'Wrench' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M509.4 98.6c7.6-7.6 20.3-5.7 24.1 4.3 6.8 17.7 10.5 37 10.5 57.1 0 88.4-71.6 160-160 160-17.5 0-34.4-2.8-50.2-8L146.9 498.9c-28.1 28.1-73.7 28.1-101.8 0s-28.1-73.7 0-101.8L232 210.2c-5.2-15.8-8-32.6-8-50.2 0-88.4 71.6-160 160-160 20.1 0 39.4 3.7 57.1 10.5 10 3.8 11.8 16.5 4.3 24.1l-88.7 88.7c-3 3-4.7 7.1-4.7 11.3l0 41.4c0 8.8 7.2 16 16 16l41.4 0c4.2 0 8.3-1.7 11.3-4.7l88.7-88.7z"/></svg>',
			'Toolbox' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M176 56l0 40 160 0 0-40c0-4.4-3.6-8-8-8L184 48c-4.4 0-8 3.6-8 8zM128 96l0-40c0-30.9 25.1-56 56-56L328 0c30.9 0 56 25.1 56 56l0 40 28.1 0c12.7 0 24.9 5.1 33.9 14.1l51.9 51.9c9 9 14.1 21.2 14.1 33.9l0 76.1-136 0 0-16c0-13.3-10.7-24-24-24s-24 10.7-24 24l0 16-144 0 0-16c0-13.3-10.7-24-24-24s-24 10.7-24 24l0 16-136 0 0-76.1c0-12.7 5.1-24.9 14.1-33.9l51.9-51.9c9-9 21.2-14.1 33.9-14.1L128 96zM0 416l0-96 136 0 0 16c0 13.3 10.7 24 24 24s24-10.7 24-24l0-16 144 0 0 16c0 13.3 10.7 24 24 24s24-10.7 24-24l0-16 136 0 0 96c0 35.3-28.7 64-64 64L64 480c-35.3 0-64-28.7-64-64z"/></svg>',
			'Gears' => '<svg width="24" height="24" viewBox="0 0 640 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M415.9 210.5c12.2-3.3 25 2.5 30.5 13.8L465 261.9c10.3 1.4 20.4 4.2 29.9 8.1l35-23.3c10.5-7 24.4-5.6 33.3 3.3l19.2 19.2c8.9 8.9 10.3 22.9 3.3 33.3l-23.3 34.9c1.9 4.7 3.6 9.6 5 14.7 1.4 5.1 2.3 10.1 3 15.2l37.7 18.6c11.3 5.6 17.1 18.4 13.8 30.5l-7 26.2c-3.3 12.1-14.6 20.3-27.2 19.5l-42-2.7c-6.3 8.1-13.6 15.6-21.9 22l2.7 41.9c.8 12.6-7.4 24-19.5 27.2l-26.2 7c-12.2 3.3-24.9-2.5-30.5-13.8l-18.6-37.6c-10.3-1.4-20.4-4.2-29.9-8.1l-35 23.3c-10.5 7-24.4 5.6-33.3-3.3l-19.2-19.2c-8.9-8.9-10.3-22.8-3.3-33.3l23.3-35c-1.9-4.7-3.6-9.6-5-14.7s-2.3-10.2-3-15.2l-37.7-18.6c-11.3-5.6-17-18.4-13.8-30.5l7-26.2c3.3-12.1 14.6-20.3 27.2-19.5l41.9 2.7c6.3-8.1 13.6-15.6 21.9-22l-2.7-41.8c-.8-12.6 7.4-24 19.5-27.2l26.2-7zM448.4 340a44 44 0 1 0 .1 88 44 44 0 1 0 -.1-88zM224.9-45.5l26.2 7c12.1 3.3 20.3 14.7 19.5 27.2l-2.7 41.8c8.3 6.4 15.6 13.8 21.9 22l42-2.7c12.5-.8 23.9 7.4 27.2 19.5l7 26.2c3.2 12.1-2.5 24.9-13.8 30.5l-37.7 18.6c-.7 5.1-1.7 10.2-3 15.2s-3.1 10-5 14.7l23.3 35c7 10.5 5.6 24.4-3.3 33.3L307.3 262c-8.9 8.9-22.8 10.3-33.3 3.3L239 242c-9.5 3.9-19.6 6.7-29.9 8.1l-18.6 37.6c-5.6 11.3-18.4 17-30.5 13.8l-26.2-7c-12.2-3.3-20.3-14.7-19.5-27.2l2.7-41.9c-8.3-6.4-15.6-13.8-21.9-22l-42 2.7c-12.5 .8-23.9-7.4-27.2-19.5l-7-26.2c-3.2-12.1 2.5-24.9 13.8-30.5l37.7-18.6c.7-5.1 1.7-10.1 3-15.2 1.4-5.1 3-10 5-14.7L55.1 46.5c-7-10.5-5.6-24.4 3.3-33.3L77.6-6c8.9-8.9 22.8-10.3 33.3-3.3l35 23.3c9.5-3.9 19.6-6.7 29.9-8.1l18.6-37.6c5.6-11.3 18.3-17 30.5-13.8zM192.4 84a44 44 0 1 0 0 88 44 44 0 1 0 0-88z"/></svg>',
			'Ruler Combined' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M1 441.7C5.5 463.5 24.8 480 48 480l352 0c26.5 0 48-21.5 48-48l0-96c0-26.5-21.5-48-48-48l-48 0 0 72c0 13.3-10.7 24-24 24s-24-10.7-24-24l0-72-64 0 0 72c0 13.3-10.7 24-24 24s-24-10.7-24-24l0-72-72 0c-13.3 0-24-10.7-24-24s10.7-24 24-24l72 0 0-64-72 0c-13.3 0-24-10.7-24-24s10.7-24 24-24l72 0 0-48c0-26.5-21.5-48-48-48L48 32C21.5 32 0 53.5 0 80L0 432c0 3.3 .3 6.6 1 9.7z"/></svg>',
			// — Trust & Credentials —
			'Shield Check' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M256 0c4.6 0 9.2 1 13.4 2.9L457.8 82.8c22 9.3 38.4 31 38.3 57.2-.5 99.2-41.3 280.7-213.6 363.2-16.7 8-36.1 8-52.8 0-172.4-82.5-213.1-264-213.6-363.2-.1-26.2 16.3-47.9 38.3-57.2L242.7 2.9C246.9 1 251.4 0 256 0zm0 66.8l0 378.1c138-66.8 175.1-214.8 176-303.4l-176-74.6 0 0z"/></svg>',
			'Shield Heart' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M269.4 2.9C265.2 1 260.7 0 256 0s-9.2 1-13.4 2.9L54.3 82.8c-22 9.3-38.4 31-38.3 57.2 .5 99.2 41.3 280.7 213.6 363.2 16.7 8 36.1 8 52.8 0 172.4-82.5 213.2-264 213.6-363.2 .1-26.2-16.3-47.9-38.3-57.2L269.4 2.9zM249.6 183.5l6.4 8.5 6.4-8.5c11.1-14.8 28.5-23.5 46.9-23.5 32.4 0 58.7 26.3 58.7 58.7l0 5.3c0 49.1-65.8 98.1-96.5 118.3-9.5 6.2-21.5 6.2-30.9 0-30.7-20.2-96.5-69.3-96.5-118.3l0-5.3c0-32.4 26.3-58.7 58.7-58.7 18.5 0 35.9 8.7 46.9 23.5z"/></svg>',
			'Award' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M245.9-25.9c-13.4-8.2-30.3-8.2-43.7 0-24.4 14.9-39.5 18.9-68.1 18.3-15.7-.4-30.3 8.1-37.9 21.9-13.7 25.1-24.8 36.2-49.9 49.9-13.8 7.5-22.2 22.2-21.9 37.9 .7 28.6-3.4 43.7-18.3 68.1-8.2 13.4-8.2 30.3 0 43.7 14.9 24.4 18.9 39.5 18.3 68.1-.4 15.7 8.1 30.3 21.9 37.9 22.1 12.1 33.3 22.1 45.1 41.5L42.7 458.5c-5.9 11.9-1.1 26.3 10.7 32.2l86 43c11.5 5.7 25.5 1.4 31.7-9.8l52.8-95.1 52.8 95.1c6.2 11.2 20.2 15.6 31.7 9.8l86-43c11.9-5.9 16.7-20.3 10.7-32.2l-48.6-97.2c11.7-19.4 23-29.4 45.1-41.5 13.8-7.5 22.2-22.2 21.9-37.9-.7-28.6 3.4-43.7 18.3-68.1 8.2-13.4 8.2-30.3 0-43.7-14.9-24.4-18.9-39.5-18.3-68.1 .4-15.7-8.1-30.3-21.9-37.9-25.1-13.7-36.2-24.8-49.9-49.9-7.5-13.8-22.2-22.2-37.9-21.9-28.6 .7-43.7-3.4-68.1-18.3zM224 96a96 96 0 1 1 0 192 96 96 0 1 1 0-192z"/></svg>',
			'Medal' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M224.3 128L139.7-12.9c-6.5-10.8-20.1-14.7-31.3-9.1L21.8 21.3C9.9 27.2 5.1 41.6 11 53.5L80.6 192.6c-30.1 33.9-48.3 78.5-48.3 127.4 0 106 86 192 192 192s192-86 192-192c0-48.9-18.3-93.5-48.3-127.4L437.6 53.5c5.9-11.9 1.1-26.3-10.7-32.2L340.2-22.1c-11.2-5.6-24.9-1.6-31.3 9.1L224.3 128zm30.8 142.5c1.4 2.8 4 4.7 7 5.1l50.1 7.3c7.7 1.1 10.7 10.5 5.2 16l-36.3 35.4c-2.2 2.2-3.2 5.2-2.7 8.3l8.6 49.9c1.3 7.6-6.7 13.5-13.6 9.9l-44.8-23.6c-2.7-1.4-6-1.4-8.7 0l-44.8 23.6c-6.9 3.6-14.9-2.2-13.6-9.9l8.6-49.9c.5-3-.5-6.1-2.7-8.3l-36.3-35.4c-5.6-5.4-2.5-14.8 5.2-16l50.1-7.3c3-.4 5.7-2.4 7-5.1l22.4-45.4c3.4-7 13.3-7 16.8 0l22.4 45.4z"/></svg>',
			'Certificate' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M239.2-8c-6.1-6.2-15-8.7-23.4-6.4S200.9-5.6 198.8 2.8L183.5 63c-1.1 4.4-5.6 7-9.9 5.7L113.8 51.9c-8.4-2.4-17.4 0-23.5 6.1s-8.5 15.1-6.1 23.5l16.9 59.8c1.2 4.3-1.4 8.8-5.7 9.9L35.1 166.5c-8.4 2.1-15 8.7-17.3 17.1s.2 17.3 6.4 23.4l44.5 43.3c3.2 3.1 3.2 8.3 0 11.5L24.3 305.1c-6.2 6.1-8.7 15-6.4 23.4s8.9 14.9 17.3 17.1l60.2 15.3c4.4 1.1 7 5.6 5.7 9.9L84.2 430.5c-2.4 8.4 0 17.4 6.1 23.5s15.1 8.5 23.5 6.1l59.8-16.9c4.3-1.2 8.8 1.4 9.9 5.7l15.3 60.2c2.1 8.4 8.7 15 17.1 17.3s17.3-.2 23.4-6.4l43.3-44.5c3.1-3.2 8.3-3.2 11.5 0L337.3 520c6.1 6.2 15 8.7 23.4 6.4s14.9-8.9 17.1-17.3L393.1 449c1.1-4.4 5.6-7 9.9-5.7l59.8 16.9c8.4 2.4 17.4 0 23.5-6.1s8.5-15.1 6.1-23.5l-16.9-59.8c-1.2-4.3 1.4-8.8 5.7-9.9l60.2-15.3c8.4-2.1 15-8.7 17.3-17.1s-.2-17.4-6.4-23.4l-44.5-43.3c-3.2-3.1-3.2-8.3 0-11.5l44.5-43.3c6.2-6.1 8.7-15 6.4-23.4s-8.9-14.9-17.3-17.1l-60.2-15.3c-4.4-1.1-7-5.6-5.7-9.9l16.9-59.8c2.4-8.4 0-17.4-6.1-23.5s-15.1-8.5-23.5-6.1L403 68.8c-4.3 1.2-8.8-1.4-9.9-5.7L377.8 2.8c-2.1-8.4-8.7-15-17.1-17.3s-17.3 .2-23.4 6.4L294 36.5c-3.1 3.2-8.3 3.2-11.5 0L239.2-8z"/></svg>',
			'Handshake' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M268.9 85.2L152.3 214.8c-4.6 5.1-4.4 13 .5 17.9 30.5 30.5 80 30.5 110.5 0l31.8-31.8c4.2-4.2 9.5-6.5 14.9-6.9 6.8-.6 13.8 1.7 19 6.9L505.6 376 576 320 576 32 464 96 440.2 80.1C424.4 69.6 405.9 64 386.9 64l-70.4 0c-1.1 0-2.3 0-3.4 .1-16.9 .9-32.8 8.5-44.2 21.1zM116.6 182.7L223.4 64 183.8 64c-25.5 0-49.9 10.1-67.9 28.1L112 96 0 32 0 320 156.4 450.3c23 19.2 52 29.7 81.9 29.7l15.7 0-7-7c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l41 41 9 0c19.1 0 37.8-4.3 54.8-12.3L359 441c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l32 32 17.5-17.5c8.9-8.9 11.5-21.8 7.6-33.1l-137.9-136.8-14.9 14.9c-49.3 49.3-129.1 49.3-178.4 0-23-23-23.9-59.9-2.2-84z"/></svg>',
			'Thumbs Up' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M80 160c17.7 0 32 14.3 32 32l0 256c0 17.7-14.3 32-32 32l-48 0c-17.7 0-32-14.3-32-32L0 192c0-17.7 14.3-32 32-32l48 0zM270.6 16C297.9 16 320 38.1 320 65.4l0 4.2c0 6.8-1.3 13.6-3.8 19.9L288 160 448 160c26.5 0 48 21.5 48 48 0 19.7-11.9 36.6-28.9 44 17 7.4 28.9 24.3 28.9 44 0 23.4-16.8 42.9-39 47.1 4.4 7.3 7 15.8 7 24.9 0 22.2-15 40.8-35.4 46.3 2.2 5.5 3.4 11.5 3.4 17.7 0 26.5-21.5 48-48 48l-87.9 0c-36.3 0-71.6-12.4-99.9-35.1L184 435.2c-15.2-12.1-24-30.5-24-50l0-186.6c0-14.9 3.5-29.6 10.1-42.9L226.3 43.3C234.7 26.6 251.8 16 270.6 16z"/></svg>',
			'Star' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M309.5-18.9c-4.1-8-12.4-13.1-21.4-13.1s-17.3 5.1-21.4 13.1L193.1 125.3 33.2 150.7c-8.9 1.4-16.3 7.7-19.1 16.3s-.5 18 5.8 24.4l114.4 114.5-25.2 159.9c-1.4 8.9 2.3 17.9 9.6 23.2s16.9 6.1 25 2L288.1 417.6 432.4 491c8 4.1 17.7 3.3 25-2s11-14.2 9.6-23.2L441.7 305.9 556.1 191.4c6.4-6.4 8.6-15.8 5.8-24.4s-10.1-14.9-19.1-16.3L383 125.3 309.5-18.9z"/></svg>',
			'Star Half' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M288.1 353.6c10 0 19.9 2.3 29 7l74.4 37.9-13-82.5c-3.2-20.2 3.5-40.7 17.9-55.2l59-59.1-82.5-13.1c-20.2-3.2-37.7-15.9-47-34.1l-38-74.4 0 273.6zM457.4 489c-7.3 5.3-17 6.1-25 2L288.1 417.6 143.8 491c-8 4.1-17.7 3.3-25-2s-11-14.2-9.6-23.2L134.4 305.9 20 191.4c-6.4-6.4-8.6-15.8-5.8-24.4s10.1-14.9 19.1-16.3l159.9-25.4 73.6-144.2c4.1-8 12.4-13.1 21.4-13.1s17.3 5.1 21.4 13.1L383 125.3 542.9 150.7c8.9 1.4 16.3 7.7 19.1 16.3s.5 18-5.8 24.4L441.7 305.9 467 465.8c1.4 8.9-2.3 17.9-9.6 23.2z"/></svg>',
			// — Contact & Local —
			'Phone' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M160.2 25C152.3 6.1 131.7-3.9 112.1 1.4l-5.5 1.5c-64.6 17.6-119.8 80.2-103.7 156.4 37.1 175 174.8 312.7 349.8 349.8 76.3 16.2 138.8-39.1 156.4-103.7l1.5-5.5c5.4-19.7-4.7-40.3-23.5-48.1l-97.3-40.5c-16.5-6.9-35.6-2.1-47 11.8l-38.6 47.2C233.9 335.4 177.3 277 144.8 205.3L189 169.3c13.9-11.3 18.6-30.4 11.8-47L160.2 25z"/></svg>',
			'Phone Volume' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M344-32c128.1 0 232 103.9 232 232 0 13.3-10.7 24-24 24s-24-10.7-24-24c0-101.6-82.4-184-184-184-13.3 0-24-10.7-24-24s10.7-24 24-24zm8 192a32 32 0 1 1 0 64 32 32 0 1 1 0-64zM320 88c0-13.3 10.7-24 24-24 75.1 0 136 60.9 136 136 0 13.3-10.7 24-24 24s-24-10.7-24-24c0-48.6-39.4-88-88-88-13.3 0-24-10.7-24-24zM144.1 1.4c19.7-5.4 40.3 4.7 48.1 23.5l40.5 97.3c6.9 16.5 2.1 35.6-11.8 47l-44.1 36.1c32.5 71.6 89 130 159.3 164.9L374.7 323c11.3-13.9 30.4-18.6 47-11.8L519 351.8c18.8 7.8 28.9 28.4 23.5 48.1l-1.5 5.5C523.4 470.1 460.9 525.3 384.6 509.2 209.6 472.1 71.9 334.4 34.8 159.4 18.7 83.1 73.9 20.6 138.5 2.9l5.5-1.5z"/></svg>',
			'Mobile' => '<svg width="24" height="24" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M16 64C16 28.7 44.7 0 80 0L304 0c35.3 0 64 28.7 64 64l0 384c0 35.3-28.7 64-64 64L80 512c-35.3 0-64-28.7-64-64L16 64zm64 0l0 304 224 0 0-304-224 0zM192 472c17.7 0 32-14.3 32-32s-14.3-32-32-32-32 14.3-32 32 14.3 32 32 32z"/></svg>',
			'Location Pin' => '<svg width="24" height="24" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M0 188.6C0 84.4 86 0 192 0S384 84.4 384 188.6c0 119.3-120.2 262.3-170.4 316.8-11.8 12.8-31.5 12.8-43.3 0-50.2-54.5-170.4-197.5-170.4-316.8zM192 256a64 64 0 1 0 0-128 64 64 0 1 0 0 128z"/></svg>',
			'Map Location' => '<svg width="24" height="24" viewBox="0 0 640 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M576 48c0-11.1-5.7-21.4-15.2-27.2s-21.2-6.4-31.1-1.4L413.5 77.5 234.1 17.6c-8.1-2.7-16.8-2.1-24.4 1.7l-128 64C70.8 88.8 64 99.9 64 112l0 352c0 11.1 5.7 21.4 15.2 27.2s21.2 6.4 31.1 1.4l116.1-58.1 173.3 57.8c-4.3-6.4-8.5-13.1-12.6-19.9-11-18.3-21.9-39.3-30-61.8l-101.2-33.7 0-284.5 128 42.7 0 99.3c31-35.8 77-58.4 128-58.4 22.6 0 44.2 4.4 64 12.5L576 48zM512 224c-66.3 0-120 52.8-120 117.9 0 68.9 64.1 150.4 98.6 189.3 11.6 13 31.3 13 42.9 0 34.5-38.9 98.6-120.4 98.6-189.3 0-65.1-53.7-117.9-120-117.9zM472 344a40 40 0 1 1 80 0 40 40 0 1 1 -80 0z"/></svg>',
			'Map' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M512 48c0-11.1-5.7-21.4-15.2-27.2s-21.2-6.4-31.1-1.4L349.5 77.5 170.1 17.6c-8.1-2.7-16.8-2.1-24.4 1.7l-128 64C6.8 88.8 0 99.9 0 112L0 464c0 11.1 5.7 21.4 15.2 27.2s21.2 6.4 31.1 1.4l116.1-58.1 179.4 59.8c8.1 2.7 16.8 2.1 24.4-1.7l128-64c10.8-5.4 17.7-16.5 17.7-28.6l0-352zM192 376.9l0-284.5 128 42.7 0 284.5-128-42.7z"/></svg>',
			'Envelope' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M48 64c-26.5 0-48 21.5-48 48 0 15.1 7.1 29.3 19.2 38.4l208 156c17.1 12.8 40.5 12.8 57.6 0l208-156c12.1-9.1 19.2-23.3 19.2-38.4 0-26.5-21.5-48-48-48L48 64zM0 196L0 384c0 35.3 28.7 64 64 64l384 0c35.3 0 64-28.7 64-64l0-188-198.4 148.8c-34.1 25.6-81.1 25.6-115.2 0L0 196z"/></svg>',
			'Clock' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M256 0a256 256 0 1 1 0 512 256 256 0 1 1 0-512zM232 120l0 136c0 8 4 15.5 10.7 20l96 64c11 7.4 25.9 4.4 33.3-6.7s4.4-25.9-6.7-33.3L280 243.2 280 120c0-13.3-10.7-24-24-24s-24 10.7-24 24z"/></svg>',
			'Calendar Check' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M320 0c17.7 0 32 14.3 32 32l0 32 32 0c35.3 0 64 28.7 64 64l0 288c0 35.3-28.7 64-64 64L64 480c-35.3 0-64-28.7-64-64L0 128C0 92.7 28.7 64 64 64l32 0 0-32c0-17.7 14.3-32 32-32s32 14.3 32 32l0 32 128 0 0-32c0-17.7 14.3-32 32-32zm22 161.7c-10.7-7.8-25.7-5.4-33.5 5.3L189.1 331.2 137 279.1c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l72 72c5 5 11.9 7.5 18.8 7s13.4-4.1 17.5-9.8L347.3 195.2c7.8-10.7 5.4-25.7-5.3-33.5z"/></svg>',
			'Comment' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M512 240c0 132.5-114.6 240-256 240-37.1 0-72.3-7.4-104.1-20.7L33.5 510.1c-9.4 4-20.2 1.7-27.1-5.8S-2 485.8 2.8 476.8l48.8-92.2C19.2 344.3 0 294.3 0 240 0 107.5 114.6 0 256 0S512 107.5 512 240z"/></svg>',
			'Comments' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M384 144c0 97.2-86 176-192 176-26.7 0-52.1-5-75.2-14L35.2 349.2c-9.3 4.9-20.7 3.2-28.2-4.2s-9.2-18.9-4.2-28.2l35.6-67.2C14.3 220.2 0 183.6 0 144 0 46.8 86-32 192-32S384 46.8 384 144zm0 368c-94.1 0-172.4-62.1-188.8-144 120-1.5 224.3-86.9 235.8-202.7 83.3 19.2 145 88.3 145 170.7 0 39.6-14.3 76.2-38.4 105.6l35.6 67.2c4.9 9.3 3.2 20.7-4.2 28.2s-18.9 9.2-28.2 4.2L459.2 498c-23.1 9-48.5 14-75.2 14z"/></svg>',
			'Headset' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M224 64c-79 0-144.7 57.3-157.7 132.7 9.3-3 19.3-4.7 29.7-4.7l16 0c26.5 0 48 21.5 48 48l0 96c0 26.5-21.5 48-48 48l-16 0c-53 0-96-43-96-96l0-64C0 100.3 100.3 0 224 0S448 100.3 448 224l0 168.1c0 66.3-53.8 120-120.1 120l-87.9-.1-32 0c-26.5 0-48-21.5-48-48s21.5-48 48-48l32 0c26.5 0 48 21.5 48 48l0 0 40 0c39.8 0 72-32.2 72-72l0-20.9c-14.1 8.2-30.5 12.8-48 12.8l-16 0c-26.5 0-48-21.5-48-48l0-96c0-26.5 21.5-48 48-48l16 0c10.4 0 20.3 1.6 29.7 4.7-13-75.3-78.6-132.7-157.7-132.7z"/></svg>',
			// — Home & Property —
			'House' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M277.8 8.6c-12.3-11.4-31.3-11.4-43.5 0l-224 208c-9.6 9-12.8 22.9-8 35.1S18.8 272 32 272l16 0 0 176c0 35.3 28.7 64 64 64l288 0c35.3 0 64-28.7 64-64l0-176 16 0c13.2 0 25-8.1 29.8-20.3s1.6-26.2-8-35.1l-224-208zM240 320l32 0c26.5 0 48 21.5 48 48l0 96-128 0 0-96c0-26.5 21.5-48 48-48z"/></svg>',
			'House Chimney' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M234.2 8.6c12.3-11.4 31.3-11.4 43.5 0L368 92.3 368 80c0-17.7 14.3-32 32-32l32 0c17.7 0 32 14.3 32 32l0 101.5 37.8 35.1c9.6 9 12.8 22.9 8 35.1S493.2 272 480 272l-16 0 0 176c0 35.3-28.7 64-64 64l-288 0c-35.3 0-64-28.7-64-64l0-176-16 0c-13.2 0-25-8.1-29.8-20.3s-1.6-26.2 8-35.1l224-208zM240 320c-26.5 0-48 21.5-48 48l0 96 128 0 0-96c0-26.5-21.5-48-48-48l-32 0z"/></svg>',
			'Building' => '<svg width="24" height="24" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M64 0C28.7 0 0 28.7 0 64L0 448c0 35.3 28.7 64 64 64l256 0c35.3 0 64-28.7 64-64l0-384c0-35.3-28.7-64-64-64L64 0zM176 352l32 0c17.7 0 32 14.3 32 32l0 80-96 0 0-80c0-17.7 14.3-32 32-32zM96 112c0-8.8 7.2-16 16-16l32 0c8.8 0 16 7.2 16 16l0 32c0 8.8-7.2 16-16 16l-32 0c-8.8 0-16-7.2-16-16l0-32zM240 96l32 0c8.8 0 16 7.2 16 16l0 32c0 8.8-7.2 16-16 16l-32 0c-8.8 0-16-7.2-16-16l0-32c0-8.8 7.2-16 16-16zM96 240c0-8.8 7.2-16 16-16l32 0c8.8 0 16 7.2 16 16l0 32c0 8.8-7.2 16-16 16l-32 0c-8.8 0-16-7.2-16-16l0-32zm144-16l32 0c8.8 0 16 7.2 16 16l0 32c0 8.8-7.2 16-16 16l-32 0c-8.8 0-16-7.2-16-16l0-32c0-8.8 7.2-16 16-16z"/></svg>',
			'Warehouse' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M0 142.1L0 480c0 17.7 14.3 32 32 32s32-14.3 32-32l0-240c0-17.7 14.3-32 32-32l384 0c17.7 0 32 14.3 32 32l0 240c0 17.7 14.3 32 32 32s32-14.3 32-32l0-337.9c0-27.5-17.6-52-43.8-60.7L303.2 5.1c-9.9-3.3-20.5-3.3-30.4 0L43.8 81.4C17.6 90.1 0 114.6 0 142.1zM464 256l-352 0 0 64 352 0 0-64zM112 416l352 0 0-64-352 0 0 64zm352 32l-352 0 0 64 352 0 0-64z"/></svg>',
			// — General / UI —
			'Check' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M434.8 70.1c14.3 10.4 17.5 30.4 7.1 44.7l-256 352c-5.5 7.6-14 12.3-23.4 13.1s-18.5-2.7-25.1-9.3l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0l101.5 101.5 234-321.7c10.4-14.3 30.4-17.5 44.7-7.1z"/></svg>',
			'Circle Check' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M256 512a256 256 0 1 1 0-512 256 256 0 1 1 0 512zM374 145.7c-10.7-7.8-25.7-5.4-33.5 5.3L221.1 315.2 169 263.1c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l72 72c5 5 11.8 7.5 18.8 7s13.4-4.1 17.5-9.8L379.3 179.2c7.8-10.7 5.4-25.7-5.3-33.5z"/></svg>',
			'List Check' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M133.8 36.3c10.9 7.6 13.5 22.6 5.9 33.4l-56 80c-4.1 5.8-10.5 9.5-17.6 10.1S52 158 47 153L7 113C-2.3 103.6-2.3 88.4 7 79S31.6 69.7 41 79l19.8 19.8 39.6-56.6c7.6-10.9 22.6-13.5 33.4-5.9zm0 160c10.9 7.6 13.5 22.6 5.9 33.4l-56 80c-4.1 5.8-10.5 9.5-17.6 10.1S52 318 47 313L7 273c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l19.8 19.8 39.6-56.6c7.6-10.9 22.6-13.5 33.4-5.9zM224 96c0-17.7 14.3-32 32-32l224 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-224 0c-17.7 0-32-14.3-32-32zm0 160c0-17.7 14.3-32 32-32l224 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-224 0c-17.7 0-32-14.3-32-32zM160 416c0-17.7 14.3-32 32-32l288 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-288 0c-17.7 0-32-14.3-32-32zM64 376a40 40 0 1 1 0 80 40 40 0 1 1 0-80z"/></svg>',
			'Arrow Right' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M502.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L402.7 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l370.7 0-105.4 105.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"/></svg>',
			'Chevron Right' => '<svg width="24" height="24" viewBox="0 0 320 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M311.1 233.4c12.5 12.5 12.5 32.8 0 45.3l-192 192c-12.5 12.5-32.8 12.5-45.3 0s-12.5-32.8 0-45.3L243.2 256 73.9 86.6c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0l192 192z"/></svg>',
			'Circle Info' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M256 512a256 256 0 1 0 0-512 256 256 0 1 0 0 512zM224 160a32 32 0 1 1 64 0 32 32 0 1 1 -64 0zm-8 64l48 0c13.3 0 24 10.7 24 24l0 88 8 0c13.3 0 24 10.7 24 24s-10.7 24-24 24l-80 0c-13.3 0-24-10.7-24-24s10.7-24 24-24l24 0 0-64-24 0c-13.3 0-24-10.7-24-24s10.7-24 24-24z"/></svg>',
			'Circle Question' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M256 512a256 256 0 1 0 0-512 256 256 0 1 0 0 512zm0-336c-17.7 0-32 14.3-32 32 0 13.3-10.7 24-24 24s-24-10.7-24-24c0-44.2 35.8-80 80-80s80 35.8 80 80c0 47.2-36 67.2-56 74.5l0 3.8c0 13.3-10.7 24-24 24s-24-10.7-24-24l0-8.1c0-20.5 14.8-35.2 30.1-40.2 6.4-2.1 13.2-5.5 18.2-10.3 4.3-4.2 7.7-10 7.7-19.6 0-17.7-14.3-32-32-32zM224 368a32 32 0 1 1 64 0 32 32 0 1 1 -64 0z"/></svg>',
			'Warning' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M256 0c14.7 0 28.2 8.1 35.2 21l216 400c6.7 12.4 6.4 27.4-.8 39.5S486.1 480 472 480L40 480c-14.1 0-27.2-7.4-34.4-19.5s-7.5-27.1-.8-39.5l216-400c7-12.9 20.5-21 35.2-21zm0 352a32 32 0 1 0 0 64 32 32 0 1 0 0-64zm0-192c-18.2 0-32.7 15.5-31.4 33.7l7.4 104c.9 12.5 11.4 22.3 23.9 22.3 12.6 0 23-9.7 23.9-22.3l7.4-104c1.3-18.2-13.1-33.7-31.4-33.7z"/></svg>',
			'Search' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M416 208c0 45.9-14.9 88.3-40 122.7L502.6 457.4c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376C296.3 401.1 253.9 416 208 416 93.1 416 0 322.9 0 208S93.1 0 208 0 416 93.1 416 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z"/></svg>',
			'Gear' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M195.1 9.5C198.1-5.3 211.2-16 226.4-16l59.8 0c15.2 0 28.3 10.7 31.3 25.5L332 79.5c14.1 6 27.3 13.7 39.3 22.8l67.8-22.5c14.4-4.8 30.2 1.2 37.8 14.4l29.9 51.8c7.6 13.2 4.9 29.8-6.5 39.9L447 233.3c.9 7.4 1.3 15 1.3 22.7s-.5 15.3-1.3 22.7l53.4 47.5c11.4 10.1 14 26.8 6.5 39.9l-29.9 51.8c-7.6 13.1-23.4 19.2-37.8 14.4l-67.8-22.5c-12.1 9.1-25.3 16.7-39.3 22.8l-14.4 69.9c-3.1 14.9-16.2 25.5-31.3 25.5l-59.8 0c-15.2 0-28.3-10.7-31.3-25.5l-14.4-69.9c-14.1-6-27.2-13.7-39.3-22.8L73.5 432.3c-14.4 4.8-30.2-1.2-37.8-14.4L5.8 366.1c-7.6-13.2-4.9-29.8 6.5-39.9l53.4-47.5c-.9-7.4-1.3-15-1.3-22.7s.5-15.3 1.3-22.7L12.3 185.8c-11.4-10.1-14-26.8-6.5-39.9L35.7 94.1c7.6-13.2 23.4-19.2 37.8-14.4l67.8 22.5c12.1-9.1 25.3-16.7 39.3-22.8L195.1 9.5zM256.3 336a80 80 0 1 0 -.6-160 80 80 0 1 0 .6 160z"/></svg>',
			'Lock' => '<svg width="24" height="24" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M128 96l0 64 128 0 0-64c0-35.3-28.7-64-64-64s-64 28.7-64 64zM64 160l0-64C64 25.3 121.3-32 192-32S320 25.3 320 96l0 64c35.3 0 64 28.7 64 64l0 224c0 35.3-28.7 64-64 64L64 512c-35.3 0-64-28.7-64-64L0 224c0-35.3 28.7-64 64-64z"/></svg>',
			'User' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M224 248a120 120 0 1 0 0-240 120 120 0 1 0 0 240zm-29.7 56C95.8 304 16 383.8 16 482.3 16 498.7 29.3 512 45.7 512l356.6 0c16.4 0 29.7-13.3 29.7-29.7 0-98.5-79.8-178.3-178.3-178.3l-59.4 0z"/></svg>',
			'Users' => '<svg width="24" height="24" viewBox="0 0 640 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M320 16a104 104 0 1 1 0 208 104 104 0 1 1 0-208zM96 88a72 72 0 1 1 0 144 72 72 0 1 1 0-144zM0 416c0-70.7 57.3-128 128-128 12.8 0 25.2 1.9 36.9 5.4-32.9 36.8-52.9 85.4-52.9 138.6l0 16c0 11.4 2.4 22.2 6.7 32L32 480c-17.7 0-32-14.3-32-32l0-32zm521.3 64c4.3-9.8 6.7-20.6 6.7-32l0-16c0-53.2-20-101.8-52.9-138.6 11.7-3.5 24.1-5.4 36.9-5.4 70.7 0 128 57.3 128 128l0 32c0 17.7-14.3 32-32 32l-86.7 0zM472 160a72 72 0 1 1 144 0 72 72 0 1 1 -144 0zM160 432c0-88.4 71.6-160 160-160s160 71.6 160 160l0 16c0 17.7-14.3 32-32 32l-256 0c-17.7 0-32-14.3-32-32l0-16z"/></svg>',
			'Briefcase' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M200 48l112 0c4.4 0 8 3.6 8 8l0 40-128 0 0-40c0-4.4 3.6-8 8-8zm-56 8l0 40-80 0C28.7 96 0 124.7 0 160l0 96 512 0 0-96c0-35.3-28.7-64-64-64l-80 0 0-40c0-30.9-25.1-56-56-56L200 0c-30.9 0-56 25.1-56 56zM512 304l-192 0 0 16c0 17.7-14.3 32-32 32l-64 0c-17.7 0-32-14.3-32-32l0-16-192 0 0 112c0 35.3 28.7 64 64 64l384 0c35.3 0 64-28.7 64-64l0-112z"/></svg>',
			'Dollar' => '<svg width="24" height="24" viewBox="0 0 320 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M136 24c0-13.3 10.7-24 24-24s24 10.7 24 24l0 40 56 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-114.9 0c-24.9 0-45.1 20.2-45.1 45.1 0 22.5 16.5 41.5 38.7 44.7l91.6 13.1c53.8 7.7 93.7 53.7 93.7 108 0 60.3-48.9 109.1-109.1 109.1l-10.9 0 0 40c0 13.3-10.7 24-24 24s-24-10.7-24-24l0-40-72 0c-17.7 0-32-14.3-32-32s14.3-32 32-32l130.9 0c24.9 0 45.1-20.2 45.1-45.1 0-22.5-16.5-41.5-38.7-44.7l-91.6-13.1C55.9 273.5 16 227.4 16 173.1 16 112.9 64.9 64 125.1 64l10.9 0 0-40z"/></svg>',
			'Tag' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M32.5 96l0 149.5c0 17 6.7 33.3 18.7 45.3l192 192c25 25 65.5 25 90.5 0L483.2 333.3c25-25 25-65.5 0-90.5l-192-192C279.2 38.7 263 32 246 32L96.5 32c-35.3 0-64 28.7-64 64zm112 16a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>',
			'Gift' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M321.5 68.8C329.1 55.9 342.9 48 357.8 48l2.2 0c22.1 0 40 17.9 40 40s-17.9 40-40 40l-73.3 0 34.8-59.2zm-131 0l34.8 59.2-73.3 0c-22.1 0-40-17.9-40-40s17.9-40 40-40l2.2 0c14.9 0 28.8 7.9 36.3 20.8zm89.6-24.3l-24.1 41-24.1-41C215.7 16.9 186.1 0 154.2 0L152 0c-48.6 0-88 39.4-88 88 0 14.4 3.5 28 9.6 40L32 128c-17.7 0-32 14.3-32 32l0 32c0 17.7 14.3 32 32 32l448 0c17.7 0 32-14.3 32-32l0-32c0-17.7-14.3-32-32-32l-41.6 0c6.1-12 9.6-25.6 9.6-40 0-48.6-39.4-88-88-88l-2.2 0c-31.9 0-61.5 16.9-77.7 44.4zM480 272l-200 0 0 208 136 0c35.3 0 64-28.7 64-64l0-144zm-248 0l-200 0 0 144c0 35.3 28.7 64 64 64l136 0 0-208z"/></svg>',
			'Calendar' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M128 0C110.3 0 96 14.3 96 32l0 32-32 0C28.7 64 0 92.7 0 128l0 48 448 0 0-48c0-35.3-28.7-64-64-64l-32 0 0-32c0-17.7-14.3-32-32-32s-32 14.3-32 32l0 32-128 0 0-32c0-17.7-14.3-32-32-32zM0 224L0 416c0 35.3 28.7 64 64 64l320 0c35.3 0 64-28.7 64-64l0-192-448 0z"/></svg>',
			'File Lines' => '<svg width="24" height="24" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M0 64C0 28.7 28.7 0 64 0L213.5 0c17 0 33.3 6.7 45.3 18.7L365.3 125.3c12 12 18.7 28.3 18.7 45.3L384 448c0 35.3-28.7 64-64 64L64 512c-35.3 0-64-28.7-64-64L0 64zm208-5.5l0 93.5c0 13.3 10.7 24 24 24L325.5 176 208 58.5zM120 256c-13.3 0-24 10.7-24 24s10.7 24 24 24l144 0c13.3 0 24-10.7 24-24s-10.7-24-24-24l-144 0zm0 96c-13.3 0-24 10.7-24 24s10.7 24 24 24l144 0c13.3 0 24-10.7 24-24s-10.7-24-24-24l-144 0z"/></svg>',
			'Book Open' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M256 141.3l0 309.3 .5-.2C311.1 427.7 369.7 416 428.8 416l19.2 0 0-320-19.2 0c-42.2 0-84.1 8.4-123.1 24.6-16.8 7-33.4 13.9-49.7 20.7zM230.9 61.5L256 72 281.1 61.5C327.9 42 378.1 32 428.8 32L464 32c26.5 0 48 21.5 48 48l0 352c0 26.5-21.5 48-48 48l-35.2 0c-50.7 0-100.9 10-147.7 29.5l-12.8 5.3c-7.9 3.3-16.7 3.3-24.6 0l-12.8-5.3C184.1 490 133.9 480 83.2 480L48 480c-26.5 0-48-21.5-48-48L0 80C0 53.5 21.5 32 48 32l35.2 0c50.7 0 100.9 10 147.7 29.5z"/></svg>',
			'Camera' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M149.1 64.8L138.7 96 64 96C28.7 96 0 124.7 0 160L0 416c0 35.3 28.7 64 64 64l384 0c35.3 0 64-28.7 64-64l0-256c0-35.3-28.7-64-64-64l-74.7 0-10.4-31.2C356.4 45.2 338.1 32 317.4 32L194.6 32c-20.7 0-39 13.2-45.5 32.8zM256 192a96 96 0 1 1 0 192 96 96 0 1 1 0-192z"/></svg>',
			'Image' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M64 32C28.7 32 0 60.7 0 96L0 416c0 35.3 28.7 64 64 64l320 0c35.3 0 64-28.7 64-64l0-320c0-35.3-28.7-64-64-64L64 32zm64 80a48 48 0 1 1 0 96 48 48 0 1 1 0-96zM272 224c8.4 0 16.1 4.4 20.5 11.5l88 144c4.5 7.4 4.7 16.7 .5 24.3S368.7 416 360 416L88 416c-8.9 0-17.2-5-21.3-12.9s-3.5-17.5 1.6-24.8l56-80c4.5-6.4 11.8-10.2 19.7-10.2s15.2 3.8 19.7 10.2l26.4 37.8 61.4-100.5c4.4-7.1 12.1-11.5 20.5-11.5z"/></svg>',
			'Play' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M91.2 36.9c-12.4-6.8-27.4-6.5-39.6 .7S32 57.9 32 72l0 368c0 14.1 7.5 27.2 19.6 34.4s27.2 7.5 39.6 .7l336-184c12.8-7 20.8-20.5 20.8-35.1s-8-28.1-20.8-35.1l-336-184z"/></svg>',
			'Bolt' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M338.8-9.9c11.9 8.6 16.3 24.2 10.9 37.8L271.3 224 416 224c13.5 0 25.5 8.4 30.1 21.1s.7 26.9-9.6 35.5l-288 240c-11.3 9.4-27.4 9.9-39.3 1.3s-16.3-24.2-10.9-37.8L176.7 288 32 288c-13.5 0-25.5-8.4-30.1-21.1s-.7-26.9 9.6-35.5l288-240c11.3-9.4 27.4-9.9 39.3-1.3z"/></svg>',
			'Clipboard Check' => '<svg width="24" height="24" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M256 0c23.7 0 44.4 12.9 55.4 32l8.6 0c35.3 0 64 28.7 64 64l0 352c0 35.3-28.7 64-64 64L64 512c-35.3 0-64-28.7-64-64L0 96C0 60.7 28.7 32 64 32l8.6 0C83.6 12.9 104.3 0 128 0L256 0zm26.9 212.6c-10.7-7.8-25.7-5.4-33.5 5.3l-85.6 117.7-26.5-27.4c-9.2-9.5-24.4-9.8-33.9-.6s-9.8 24.4-.6 33.9l46.4 48c4.9 5.1 11.8 7.8 18.9 7.3s13.6-4.1 17.8-9.8L288.2 246.1c7.8-10.7 5.4-25.7-5.3-33.5zM136 64c-13.3 0-24 10.7-24 24s10.7 24 24 24l112 0c13.3 0 24-10.7 24-24s-10.7-24-24-24L136 64z"/></svg>',
			'Quote' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M0 216C0 149.7 53.7 96 120 96l8 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-8 0c-30.9 0-56 25.1-56 56l0 8 64 0c35.3 0 64 28.7 64 64l0 64c0 35.3-28.7 64-64 64l-64 0c-35.3 0-64-28.7-64-64L0 216zm256 0c0-66.3 53.7-120 120-120l8 0c17.7 0 32 14.3 32 32s-14.3 32-32 32l-8 0c-30.9 0-56 25.1-56 56l0 8 64 0c35.3 0 64 28.7 64 64l0 64c0 35.3-28.7 64-64 64l-64 0c-35.3 0-64-28.7-64-64l0-136z"/></svg>',
			// — Social (Brands) —
			'Facebook' => '<svg width="24" height="24" viewBox="0 0 320 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M80 299.3l0 212.7 116 0 0-212.7 86.5 0 18-97.8-104.5 0 0-34.6c0-51.7 20.3-71.5 72.7-71.5 16.3 0 29.4 .4 37 1.2l0-88.7C291.4 4 256.4 0 236.2 0 129.3 0 80 50.5 80 159.4l0 42.1-66 0 0 97.8 66 0z"/></svg>',
			'Instagram' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M224.3 141a115 115 0 1 0 -.6 230 115 115 0 1 0 .6-230zm-.6 40.4a74.6 74.6 0 1 1 .6 149.2 74.6 74.6 0 1 1 -.6-149.2zm93.4-45.1a26.8 26.8 0 1 1 53.6 0 26.8 26.8 0 1 1 -53.6 0zm129.7 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM399 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"/></svg>',
			'LinkedIn' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M100.3 448l-92.9 0 0-299.1 92.9 0 0 299.1zM53.8 108.1C24.1 108.1 0 83.5 0 53.8 0 39.5 5.7 25.9 15.8 15.8s23.8-15.8 38-15.8 27.9 5.7 38 15.8 15.8 23.8 15.8 38c0 29.7-24.1 54.3-53.8 54.3zM447.9 448l-92.7 0 0-145.6c0-34.7-.7-79.2-48.3-79.2-48.3 0-55.7 37.7-55.7 76.7l0 148.1-92.8 0 0-299.1 89.1 0 0 40.8 1.3 0c12.4-23.5 42.7-48.3 87.9-48.3 94 0 111.3 61.9 111.3 142.3l0 164.3-.1 0z"/></svg>',
			'X (Twitter)' => '<svg width="24" height="24" viewBox="0 0 448 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M357.2 48L427.8 48 273.6 224.2 455 464 313 464 201.7 318.6 74.5 464 3.8 464 168.7 275.5-5.2 48 140.4 48 240.9 180.9 357.2 48zM332.4 421.8l39.1 0-252.4-333.8-42 0 255.3 333.8z"/></svg>',
			'YouTube' => '<svg width="24" height="24" viewBox="0 0 576 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M549.7 124.1C543.5 100.4 524.9 81.8 501.4 75.5 458.9 64 288.1 64 288.1 64S117.3 64 74.7 75.5C51.2 81.8 32.7 100.4 26.4 124.1 15 167 15 256.4 15 256.4s0 89.4 11.4 132.3c6.3 23.6 24.8 41.5 48.3 47.8 42.6 11.5 213.4 11.5 213.4 11.5s170.8 0 213.4-11.5c23.5-6.3 42-24.2 48.3-47.8 11.4-42.9 11.4-132.3 11.4-132.3s0-89.4-11.4-132.3zM232.2 337.6l0-162.4 142.7 81.2-142.7 81.2z"/></svg>',
			'Google' => '<svg width="24" height="24" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M500 261.8C500 403.3 403.1 504 260 504 122.8 504 12 393.2 12 256S122.8 8 260 8c66.8 0 123 24.5 166.3 64.9l-67.5 64.9c-88.3-85.2-252.5-21.2-252.5 118.2 0 86.5 69.1 156.6 153.7 156.6 98.2 0 135-70.4 140.8-106.9l-140.8 0 0-85.3 236.1 0c2.3 12.7 3.9 24.9 3.9 41.4z"/></svg>',
			'Yelp' => '<svg width="24" height="24" viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M42.9 240.3l99.6 48.6c19.2 9.4 16.2 37.5-4.5 42.7L30.5 358.5c-3.2 .8-6.4 .9-9.6 .3s-6.2-1.8-8.9-3.7-4.9-4.3-6.6-7.1-2.7-5.9-3.1-9.2c-3.3-28.8-.2-57.9 9-85.3 1-3.1 2.7-5.9 4.9-8.3s4.9-4.2 7.9-5.5 6.2-1.8 9.5-1.8 6.4 .9 9.3 2.3zm44 239.3c23.8 16.3 50.9 27.3 79.4 32.1 3.2 .6 6.5 .4 9.6-.4s6.1-2.3 8.6-4.4 4.6-4.6 6-7.5 2.3-6.1 2.4-9.4l3.9-110.8c.7-21.3-25.5-31.9-39.8-16.1L82.8 445.5c-2.2 2.4-3.8 5.3-4.8 8.4s-1.3 6.4-.9 9.6 1.5 6.3 3.1 9.1 3.9 5.2 6.6 7l0 0zM232.2 369.7l58.8 94c1.7 2.8 4 5.1 6.8 6.9s5.8 3 9 3.5 6.5 .3 9.7-.5 6.1-2.4 8.6-4.4c22.3-18.4 40.3-41.5 52.7-67.6 1.4-2.9 2.1-6.1 2.2-9.4s-.6-6.5-1.9-9.4-3.2-5.7-5.6-7.8-5.2-3.9-8.3-4.9L258.7 335.7c-20.3-6.5-37.8 15.8-26.5 33.9zM380.6 237.4c-11.5-26.5-28.7-50.2-50.4-69.3-2.4-2.1-5.3-3.7-8.4-4.7s-6.4-1.2-9.6-.8-6.3 1.5-9.1 3.2-5.1 4-6.9 6.7l-62 91.9c-11.9 17.7 4.7 40.6 25.2 34.7L366 268.6c3.1-.9 6-2.5 8.5-4.6s4.5-4.7 5.8-7.7 2.1-6.2 2.2-9.4-.6-6.5-1.9-9.5l0 0zM62.1 30.2c-2.8 1.4-5.4 3.3-7.4 5.7s-3.6 5.2-4.5 8.2-1.2 6.2-.9 9.3 1.3 6.1 2.9 8.9L156.3 242.6c11.7 20.2 42.6 11.9 42.6-11.4l0-208.3c0-3.1-.6-6.3-1.8-9.2s-3.1-5.5-5.4-7.6-5-3.8-8-4.8-6.1-1.4-9.3-1.2c-39 3.1-77 13.3-112.3 30.1z"/></svg>',
		];
	}
}
