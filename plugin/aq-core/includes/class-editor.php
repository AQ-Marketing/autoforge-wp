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

			'button_group' => ['fields' => [['name' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'], ['name' => 'heading', 'label' => 'Heading', 'type' => 'text'], ['name' => 'intro', 'label' => 'Intro', 'type' => 'textarea'], ['name' => 'align', 'label' => 'Alignment', 'type' => 'select', 'options' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'], 'group' => 'design'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design'], ['name' => 'buttons', 'label' => 'Buttons', 'type' => 'repeater', 'subfields' => [['name' => 'label', 'label' => 'Label', 'type' => 'text'], ['name' => 'href', 'label' => 'Link', 'type' => 'url'], ['name' => 'style', 'label' => 'Style', 'type' => 'select', 'options' => ['primary' => 'Primary (gold)', 'secondary' => 'Secondary (navy)', 'ghost' => 'Ghost (outlined)']]]]]],

			'callout' => ['fields' => [['name' => 'style', 'label' => 'Style', 'type' => 'select', 'options' => ['info' => 'Info (navy)', 'success' => 'Success (forest)', 'warning' => 'Warning (copper)', 'danger' => 'Danger (deep copper)'], 'group' => 'design'], ['name' => 'title', 'label' => 'Title', 'type' => 'text'], ['name' => 'icon_svg', 'label' => 'Icon', 'type' => 'icon'], ['name' => 'body', 'label' => 'Body', 'type' => 'richtext'], ['name' => 'bg', 'label' => 'Background', 'type' => 'select', 'options' => ['white' => 'White', 'brand-50' => 'Light tint'], 'group' => 'design']]],

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
	 * value is complete <svg> markup in the site's 24×24 stroke style using
	 * currentColor, so it inherits the card badge's color. Editors can still
	 * paste custom SVG via the picker's advanced box.
	 */
	public static function icon_library(): array {
		$svg = static function (string $inner): string {
			return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
		};
		return [
			'Home'        => $svg('<path d="M3 12l9-9 9 9"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>'),
			'Checklist'   => $svg('<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>'),
			'Building'    => $svg('<path d="M2 20h20"/><path d="M5 20V8l7-5 7 5v12"/><path d="M10 20v-6h4v6"/>'),
			'Shield'      => $svg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'),
			'Location'    => $svg('<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>'),
			'Clock'       => $svg('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
			'Phone'       => $svg('<path d="M22 16.92v3a2 2 0 01-2.18 2 19.86 19.86 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.86 19.86 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.13.96.36 1.9.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0122 16.92z"/>'),
			'Droplet'     => $svg('<path d="M12 2s7 8 7 13a7 7 0 01-14 0c0-5 7-13 7-13z"/>'),
			'Thermometer' => $svg('<path d="M14 14.76V3.5a2.5 2.5 0 00-5 0v11.26a4 4 0 105 0z"/>'),
			'Magnifier'   => $svg('<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>'),
			'Document'    => $svg('<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/>'),
			'Star'        => $svg('<path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/>'),
			'Wrench'      => $svg('<path d="M14.7 6.3a4 4 0 00-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 005.4-5.4l-2.3 2.3-2.3-2.3z"/>'),
			'Atom'        => $svg('<circle cx="12" cy="12" r="2"/><path d="M12 2c5 6 5 14 0 20M12 2C7 8 7 16 12 22M2 12c6-5 14-5 20 0M2 12c6 5 14 5 20 0"/>'),
			'Leaf'        => $svg('<path d="M11 20A7 7 0 014 13c0-6 7-11 16-11 0 9-4 16-9 18z"/><path d="M11 20c0-5 3-9 7-11"/>'),
			'Bolt'        => $svg('<path d="M13 2L3 14h7l-1 8 10-12h-7z"/>'),
		];
	}
}
