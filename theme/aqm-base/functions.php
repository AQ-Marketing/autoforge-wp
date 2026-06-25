<?php
/**
 * AQM Base — client-agnostic stub theme.
 *
 * Rendering (sections, header/footer chrome, the visual builder, image sizes,
 * the LCP hero preload) lives in the AutoForge plugin (AQ_Renderer), which
 * takes over via the template_include hook. This theme's only job is to enqueue
 * the per-client compiled assets that the content import delivers into
 * assets/css/main.css and assets/js/site.js.
 *
 * The PHP here is identical on every client; only the compiled CSS differs.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Soft fallback when the AutoForge plugin is inactive: pages keep loading
 * (with blank business values) instead of fataling. The plugin's own aq_site()
 * wins when active because plugins load before the theme.
 */
if (!function_exists('aq_site')) {
	function aq_site(?string $path = null) {
		return null;
	}
}

add_action('wp_enqueue_scripts', function () {
	$css = get_theme_file_path('assets/css/main.css');
	wp_enqueue_style(
		'aqm-base',
		get_theme_file_uri('assets/css/main.css'),
		[],
		file_exists($css) ? (string) filemtime($css) : null
	);

	$js = get_theme_file_path('assets/js/site.js');
	wp_enqueue_script(
		'aqm-base',
		get_theme_file_uri('assets/js/site.js'),
		[],
		file_exists($js) ? (string) filemtime($js) : null,
		['in_footer' => true, 'strategy' => 'defer']
	);
});

// AQM custom section layouts — registered via engine's aq_section_layouts filter (update-safe).
//
// Importer mapping (aq-core/includes/class-content-sync.php, apply_sections()):
//   For each JSON section, $row = section; unset type/v; $row['acf_fc_layout'] = $section['type']
//   (the JSON `type` string VERBATIM — NOT prefixed/renamed); then one
//   update_field('field_aq_sections', $rows, $id) writes the whole flexible-content field.
//   ACF resolves each row's values by matching the layout whose `name` === acf_fc_layout,
//   then maps the row's keys to that layout's sub_field NAMES. So: layout `name` MUST equal
//   the JSON `type` EXACTLY (the interior types already carry the literal "aqm_" prefix in
//   their JSON, e.g. "aqm_stat_row"), and every sub_field `name` MUST equal the JSON key /
//   the renderer's $s['key'] / $row['subkey']. Nested arrays (stats, cards, ctas, crumbs,
//   steps, items, aside_items) are ACF repeaters whose sub_fields match the array element keys.
//
// 17 layouts registered: 8 home (local_hero, stat_split, problem_panel, sticky_steps,
//   service_showcase, proof_story, spotlight_grid, faq_split) + 9 interior (aqm_page_hero,
//   aqm_stat_row, aqm_icon_card_grid, aqm_feature_grid, aqm_process_steps, aqm_prose_aside,
//   aqm_faq, aqm_svc_grid, aqm_cta_band).
//
// NOTE on Part 1: the 8 home types are NOT static $layouts['...']=[] blocks in
//   sections.php — that file registers them DYNAMICALLY from AQ_Editor::field_schema().
//   The field names/types below are mirrored verbatim from that schema
//   (autoforge-aqm/plugin/aq-core/includes/class-editor.php, field_schema()).

// Self-contained field builder (does NOT depend on the theme/plugin aqm_lf()
// helper, which is not guaranteed to be in scope when functions.php loads).
// Deterministic key: field_aq_{ctx}_{name}, matching the engine's own scheme.
if (!function_exists('aqm_lf')) {
	function aqm_lf(string $ctx, string $name, string $type = 'text', array $extra = []): array {
		return array_merge([
			'key'   => "field_aq_{$ctx}_{$name}",
			'name'  => $name,
			'label' => ucwords(str_replace('_', ' ', $name)),
			'type'  => $type,
		], $extra);
	}
}

add_filter('aq_section_layouts', function (array $layouts) {

	/* =====================================================================
	 * PART 1 — Home layouts (8). Field names/types taken VERBATIM from the
	 * authoritative AQ_Editor::field_schema() (autoforge-aqm class-editor.php):
	 * these 8 .hm-* types are registered DYNAMICALLY there (no static array
	 * blocks exist in sections.php), so the schema below mirrors that exactly.
	 * Key scheme matches the dynamic builder: field_aq_{type}[_{repeater}]_{name}.
	 * select options -> choices; toggle -> true_false; url -> url.
	 * ===================================================================== */

	/* local_hero */
	$layouts['local_hero'] = [
		'key' => 'layout_aq_local_hero',
		'name' => 'local_hero',
		'label' => 'Local Hero (home hero)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('local_hero', 'badge_fa'),
			aqm_lf('local_hero', 'badge'),
			aqm_lf('local_hero', 'heading'),
			aqm_lf('local_hero', 'heading_accent'),
			aqm_lf('local_hero', 'lede', 'textarea'),
			aqm_lf('local_hero', 'ctas', 'repeater', [
				'sub_fields' => [
					aqm_lf('local_hero_ctas', 'label'),
					aqm_lf('local_hero_ctas', 'sublabel'),
					aqm_lf('local_hero_ctas', 'href', 'url'),
					aqm_lf('local_hero_ctas', 'style', 'select', ['choices' => ['dark' => 'Dark solid', 'outline' => 'Outline']]),
				],
			]),
			aqm_lf('local_hero', 'notes', 'repeater', [
				'sub_fields' => [
					aqm_lf('local_hero_notes', 'fa'),
					aqm_lf('local_hero_notes', 'text'),
				],
			]),
		],
	];

	/* stat_split */
	$layouts['stat_split'] = [
		'key' => 'layout_aq_stat_split',
		'name' => 'stat_split',
		'label' => 'Stat Split (stats + map)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('stat_split', 'num'),
			aqm_lf('stat_split', 'kicker'),
			aqm_lf('stat_split', 'heading'),
			aqm_lf('stat_split', 'heading_accent'),
			aqm_lf('stat_split', 'aside_text', 'textarea'),
			aqm_lf('stat_split', 'aside_link_text'),
			aqm_lf('stat_split', 'aside_link_href', 'url'),
			aqm_lf('stat_split', 'show_map', 'true_false', ['ui' => 1]),
			aqm_lf('stat_split', 'stats', 'repeater', [
				'sub_fields' => [
					aqm_lf('stat_split_stats', 'value'),
					aqm_lf('stat_split_stats', 'value_from'),
					aqm_lf('stat_split_stats', 'suffix'),
					aqm_lf('stat_split_stats', 'label'),
				],
			]),
		],
	];

	/* problem_panel */
	$layouts['problem_panel'] = [
		'key' => 'layout_aq_problem_panel',
		'name' => 'problem_panel',
		'label' => 'Problem Panel',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('problem_panel', 'num'),
			aqm_lf('problem_panel', 'kicker'),
			aqm_lf('problem_panel', 'heading'),
			aqm_lf('problem_panel', 'heading_accent'),
			aqm_lf('problem_panel', 'note'),
			aqm_lf('problem_panel', 'search_term'),
			aqm_lf('problem_panel', 'comp_rows', 'repeater', [
				'sub_fields' => [
					aqm_lf('problem_panel_comp_rows', 'av'),
					aqm_lf('problem_panel_comp_rows', 'name'),
				],
			]),
			aqm_lf('problem_panel', 'badge_title'),
			aqm_lf('problem_panel', 'badge_sub'),
			aqm_lf('problem_panel', 'you_av'),
			aqm_lf('problem_panel', 'you_name'),
			aqm_lf('problem_panel', 'pains', 'repeater', [
				'sub_fields' => [
					aqm_lf('problem_panel_pains', 'fa'),
					aqm_lf('problem_panel_pains', 'title'),
					aqm_lf('problem_panel_pains', 'body', 'textarea'),
				],
			]),
		],
	];

	/* sticky_steps */
	$layouts['sticky_steps'] = [
		'key' => 'layout_aq_sticky_steps',
		'name' => 'sticky_steps',
		'label' => 'Sticky Steps',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('sticky_steps', 'num'),
			aqm_lf('sticky_steps', 'kicker'),
			aqm_lf('sticky_steps', 'heading'),
			aqm_lf('sticky_steps', 'heading_accent'),
			aqm_lf('sticky_steps', 'steps', 'repeater', [
				'sub_fields' => [
					aqm_lf('sticky_steps_steps', 'tag'),
					aqm_lf('sticky_steps_steps', 'title'),
					aqm_lf('sticky_steps_steps', 'body', 'textarea'),
					aqm_lf('sticky_steps_steps', 'meta', 'textarea'),
				],
			]),
		],
	];

	/* service_showcase */
	$layouts['service_showcase'] = [
		'key' => 'layout_aq_service_showcase',
		'name' => 'service_showcase',
		'label' => 'Service Showcase (SERP + chat)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('service_showcase', 'num'),
			aqm_lf('service_showcase', 'kicker'),
			aqm_lf('service_showcase', 'heading'),
			aqm_lf('service_showcase', 'heading_accent'),
			aqm_lf('service_showcase', 'aside_text', 'textarea'),
			aqm_lf('service_showcase', 'aside_link_text'),
			aqm_lf('service_showcase', 'aside_link_href', 'url'),
			aqm_lf('service_showcase', 'serp_query'),
			aqm_lf('service_showcase', 'serp_map_tag'),
			aqm_lf('service_showcase', 'serp_divider'),
			aqm_lf('service_showcase', 'serp_rows', 'repeater', [
				'sub_fields' => [
					aqm_lf('service_showcase_serp_rows', 'name'),
					aqm_lf('service_showcase_serp_rows', 'rating'),
					aqm_lf('service_showcase_serp_rows', 'is_you', 'true_false', ['ui' => 1]),
					aqm_lf('service_showcase_serp_rows', 'badge'),
					aqm_lf('service_showcase_serp_rows', 'dim', 'true_false', ['ui' => 1]),
				],
			]),
			aqm_lf('service_showcase', 'serp_cap_title'),
			aqm_lf('service_showcase', 'serp_cap_text'),
			aqm_lf('service_showcase', 'serp_cap_link_text'),
			aqm_lf('service_showcase', 'serp_cap_link_href', 'url'),
			aqm_lf('service_showcase', 'chat_avatar'),
			aqm_lf('service_showcase', 'chat_title'),
			aqm_lf('service_showcase', 'chat_status'),
			aqm_lf('service_showcase', 'chat_timestamp'),
			aqm_lf('service_showcase', 'chat_lines', 'repeater', [
				'sub_fields' => [
					aqm_lf('service_showcase_chat_lines', 'who', 'select', ['choices' => ['u' => 'User', 'a' => 'AI']]),
					aqm_lf('service_showcase_chat_lines', 'text', 'textarea'),
				],
			]),
			aqm_lf('service_showcase', 'chat_card_title'),
			aqm_lf('service_showcase', 'chat_card_sub'),
			aqm_lf('service_showcase', 'chat_cap_title'),
			aqm_lf('service_showcase', 'chat_cap_text'),
			aqm_lf('service_showcase', 'chat_cap_link_text'),
			aqm_lf('service_showcase', 'chat_cap_link_href', 'url'),
			aqm_lf('service_showcase', 'svc_cards', 'repeater', [
				'sub_fields' => [
					aqm_lf('service_showcase_svc_cards', 'tag'),
					aqm_lf('service_showcase_svc_cards', 'title'),
					aqm_lf('service_showcase_svc_cards', 'body'),
					aqm_lf('service_showcase_svc_cards', 'features', 'textarea'),
				],
			]),
		],
	];

	/* proof_story */
	$layouts['proof_story'] = [
		'key' => 'layout_aq_proof_story',
		'name' => 'proof_story',
		'label' => 'Proof Story (review)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('proof_story', 'num'),
			aqm_lf('proof_story', 'kicker'),
			aqm_lf('proof_story', 'heading'),
			aqm_lf('proof_story', 'heading_accent'),
			aqm_lf('proof_story', 'quote', 'textarea'),
			aqm_lf('proof_story', 'author_initials'),
			aqm_lf('proof_story', 'author_name'),
			aqm_lf('proof_story', 'author_role'),
			aqm_lf('proof_story', 'review_rating'),
			aqm_lf('proof_story', 'review_count'),
			aqm_lf('proof_story', 'review_snip_initials'),
			aqm_lf('proof_story', 'review_snip_text'),
			aqm_lf('proof_story', 'bars', 'repeater', [
				'sub_fields' => [
					aqm_lf('proof_story_bars', 'star'),
					aqm_lf('proof_story_bars', 'pct'),
				],
			]),
			aqm_lf('proof_story', 'metrics', 'repeater', [
				'sub_fields' => [
					aqm_lf('proof_story_metrics', 'fa'),
					aqm_lf('proof_story_metrics', 'label'),
					aqm_lf('proof_story_metrics', 'prefix'),
					aqm_lf('proof_story_metrics', 'from'),
					aqm_lf('proof_story_metrics', 'to'),
					aqm_lf('proof_story_metrics', 'suffix'),
					aqm_lf('proof_story_metrics', 'old_value'),
					aqm_lf('proof_story_metrics', 'caption'),
				],
			]),
		],
	];

	/* spotlight_grid */
	$layouts['spotlight_grid'] = [
		'key' => 'layout_aq_spotlight_grid',
		'name' => 'spotlight_grid',
		'label' => 'Spotlight Grid (platform)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('spotlight_grid', 'num'),
			aqm_lf('spotlight_grid', 'kicker'),
			aqm_lf('spotlight_grid', 'heading'),
			aqm_lf('spotlight_grid', 'heading_accent'),
			aqm_lf('spotlight_grid', 'aside_text', 'textarea'),
			aqm_lf('spotlight_grid', 'dash_chart_label'),
			aqm_lf('spotlight_grid', 'dash_ring_value'),
			aqm_lf('spotlight_grid', 'dash_ring_label'),
			aqm_lf('spotlight_grid', 'dash_kpis', 'repeater', [
				'sub_fields' => [
					aqm_lf('spotlight_grid_dash_kpis', 'value'),
					aqm_lf('spotlight_grid_dash_kpis', 'label'),
				],
			]),
			aqm_lf('spotlight_grid', 'cells', 'repeater', [
				'sub_fields' => [
					aqm_lf('spotlight_grid_cells', 'number'),
					aqm_lf('spotlight_grid_cells', 'title'),
					aqm_lf('spotlight_grid_cells', 'body', 'textarea'),
				],
			]),
		],
	];

	/* faq_split */
	$layouts['faq_split'] = [
		'key' => 'layout_aq_faq_split',
		'name' => 'faq_split',
		'label' => 'FAQ Split (sticky side)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('faq_split', 'num'),
			aqm_lf('faq_split', 'kicker'),
			aqm_lf('faq_split', 'heading'),
			aqm_lf('faq_split', 'heading_accent'),
			aqm_lf('faq_split', 'aside_note', 'textarea'),
			aqm_lf('faq_split', 'cta_label'),
			aqm_lf('faq_split', 'cta_href', 'url'),
			aqm_lf('faq_split', 'items', 'repeater', [
				'sub_fields' => [
					aqm_lf('faq_split_items', 'q'),
					aqm_lf('faq_split_items', 'a', 'textarea'),
				],
			]),
			aqm_lf('faq_split', 'schema', 'true_false', ['ui' => 1]),
		],
	];

	/* =====================================================================
	 * PART 2 — Interior layouts (9): built from JSON keys + renderer reads.
	 * Layout name === JSON `type` (already carries the literal "aqm_" prefix).
	 * ===================================================================== */

	/* aqm_page_hero — renders: badge_fa, badge, heading, lede, ctas[], crumbs[] */
	$layouts['aqm_page_hero'] = [
		'key' => 'layout_aqm_page_hero',
		'name' => 'aqm_page_hero',
		'label' => 'AQM Page Hero',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_page_hero', 'badge_fa'),
			aqm_lf('aqm_page_hero', 'badge'),
			aqm_lf('aqm_page_hero', 'heading'),
			aqm_lf('aqm_page_hero', 'lede', 'textarea'),
			aqm_lf('aqm_page_hero', 'ctas', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_page_hero_cta', 'label'),
					aqm_lf('aqm_page_hero_cta', 'href'),
					aqm_lf('aqm_page_hero_cta', 'style', 'select', ['choices' => ['dark' => 'Dark solid', 'outline' => 'Outline dark', 'ghost' => 'Ghost', 'primary' => 'Primary'], 'default_value' => 'primary']),
					aqm_lf('aqm_page_hero_cta', 'fa'),
				],
			]),
			aqm_lf('aqm_page_hero', 'crumbs', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_page_hero_crumb', 'label'),
					aqm_lf('aqm_page_hero_crumb', 'url'),
				],
			]),
		],
	];

	/* aqm_stat_row — renders: kicker, heading, intro, stats[value,label] */
	$layouts['aqm_stat_row'] = [
		'key' => 'layout_aqm_stat_row',
		'name' => 'aqm_stat_row',
		'label' => 'AQM Stat Row',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_stat_row', 'kicker'),
			aqm_lf('aqm_stat_row', 'heading'),
			aqm_lf('aqm_stat_row', 'intro', 'textarea'),
			aqm_lf('aqm_stat_row', 'stats', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_stat_row_stat', 'value'),
					aqm_lf('aqm_stat_row_stat', 'label'),
				],
			]),
		],
	];

	/* aqm_icon_card_grid — renders: kicker, heading, intro, container, cards[fa,title,body] */
	$layouts['aqm_icon_card_grid'] = [
		'key' => 'layout_aqm_icon_card_grid',
		'name' => 'aqm_icon_card_grid',
		'label' => 'AQM Icon Card Grid',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_icon_card_grid', 'kicker'),
			aqm_lf('aqm_icon_card_grid', 'heading'),
			aqm_lf('aqm_icon_card_grid', 'intro', 'textarea'),
			aqm_lf('aqm_icon_card_grid', 'container', 'text', ['instructions' => 'Grid wrapper class, e.g. "pain" or "grid-3" (default).']),
			aqm_lf('aqm_icon_card_grid', 'cards', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_icon_card_grid_card', 'fa'),
					aqm_lf('aqm_icon_card_grid_card', 'title'),
					aqm_lf('aqm_icon_card_grid_card', 'body', 'textarea'),
				],
			]),
		],
	];

	/* aqm_feature_grid — renders: kicker, heading, intro, items[fa,title,body] */
	$layouts['aqm_feature_grid'] = [
		'key' => 'layout_aqm_feature_grid',
		'name' => 'aqm_feature_grid',
		'label' => 'AQM Feature Grid',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_feature_grid', 'kicker'),
			aqm_lf('aqm_feature_grid', 'heading'),
			aqm_lf('aqm_feature_grid', 'intro', 'textarea'),
			aqm_lf('aqm_feature_grid', 'items', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_feature_grid_item', 'fa'),
					aqm_lf('aqm_feature_grid_item', 'title'),
					aqm_lf('aqm_feature_grid_item', 'body', 'textarea'),
				],
			]),
		],
	];

	/* aqm_process_steps — renders: kicker, heading, steps[step_label,title,body] */
	$layouts['aqm_process_steps'] = [
		'key' => 'layout_aqm_process_steps',
		'name' => 'aqm_process_steps',
		'label' => 'AQM Process Steps',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_process_steps', 'kicker'),
			aqm_lf('aqm_process_steps', 'heading'),
			aqm_lf('aqm_process_steps', 'steps', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_process_steps_step', 'step_label'),
					aqm_lf('aqm_process_steps_step', 'title'),
					aqm_lf('aqm_process_steps_step', 'body', 'textarea'),
				],
			]),
		],
	];

	/* aqm_prose_aside — renders: kicker, heading, prose_html,
	 * aside_items[note_title,note_body, map_src, img_src, img_alt].
	 * prose_html kept as textarea (renderer uses wp_kses_post — wysiwyg's wpautop would alter parity). */
	$layouts['aqm_prose_aside'] = [
		'key' => 'layout_aqm_prose_aside',
		'name' => 'aqm_prose_aside',
		'label' => 'AQM Prose + Aside',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_prose_aside', 'kicker'),
			aqm_lf('aqm_prose_aside', 'heading'),
			aqm_lf('aqm_prose_aside', 'prose_html', 'textarea', ['rows' => 12, 'new_lines' => '', 'instructions' => 'Prose HTML (rendered via wp_kses_post; inline links allowed).']),
			aqm_lf('aqm_prose_aside', 'aside_items', 'repeater', [
				'instructions' => 'Each item renders as a map iframe (map_src), an image (img_src/img_alt), or a note card (note_title/note_body).',
				'sub_fields' => [
					aqm_lf('aqm_prose_aside_item', 'note_title'),
					aqm_lf('aqm_prose_aside_item', 'note_body', 'textarea'),
					aqm_lf('aqm_prose_aside_item', 'map_src', 'text', ['instructions' => 'Optional map embed URL (renders an iframe).']),
					aqm_lf('aqm_prose_aside_item', 'img_src', 'text', ['instructions' => 'Optional image URL (renders an img).']),
					aqm_lf('aqm_prose_aside_item', 'img_alt', 'text', ['instructions' => 'Alt / iframe title for the media.']),
				],
			]),
		],
	];

	/* aqm_faq — renders: kicker, heading, schema, items[q,a] */
	$layouts['aqm_faq'] = [
		'key' => 'layout_aqm_faq',
		'name' => 'aqm_faq',
		'label' => 'AQM FAQ',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_faq', 'kicker'),
			aqm_lf('aqm_faq', 'heading'),
			aqm_lf('aqm_faq', 'schema', 'true_false', ['default_value' => 1, 'instructions' => 'Emit FAQPage JSON-LD from these rows.']),
			aqm_lf('aqm_faq', 'items', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_faq_item', 'q'),
					aqm_lf('aqm_faq_item', 'a', 'textarea', ['instructions' => 'Answer HTML (inline links allowed).']),
				],
			]),
		],
	];

	/* aqm_svc_grid — renders: kicker, heading, intro(optional),
	 * cards[tag,title,body,href,more_label, features]. features is a newline-split
	 * string the renderer explodes into <li>s, so it is a textarea (not a repeater). */
	$layouts['aqm_svc_grid'] = [
		'key' => 'layout_aqm_svc_grid',
		'name' => 'aqm_svc_grid',
		'label' => 'AQM Service Grid',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_svc_grid', 'kicker'),
			aqm_lf('aqm_svc_grid', 'heading'),
			aqm_lf('aqm_svc_grid', 'intro', 'textarea'),
			aqm_lf('aqm_svc_grid', 'cards', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_svc_grid_card', 'tag'),
					aqm_lf('aqm_svc_grid_card', 'title'),
					aqm_lf('aqm_svc_grid_card', 'body', 'textarea'),
					aqm_lf('aqm_svc_grid_card', 'href'),
					aqm_lf('aqm_svc_grid_card', 'more_label'),
					aqm_lf('aqm_svc_grid_card', 'features', 'textarea', ['instructions' => 'Optional bullet list, one feature per line (rendered as <li>s).']),
				],
			]),
		],
	];

	/* aqm_cta_band — renders: anchor, eyebrow_fa, eyebrow, heading, body,
	 * ctas[label,href,style,fa], stats[fa,value,label] */
	$layouts['aqm_cta_band'] = [
		'key' => 'layout_aqm_cta_band',
		'name' => 'aqm_cta_band',
		'label' => 'AQM CTA Band',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_cta_band', 'anchor', 'text', ['instructions' => 'Optional section id anchor (e.g. "book").']),
			aqm_lf('aqm_cta_band', 'eyebrow_fa'),
			aqm_lf('aqm_cta_band', 'eyebrow'),
			aqm_lf('aqm_cta_band', 'heading'),
			aqm_lf('aqm_cta_band', 'body', 'textarea'),
			aqm_lf('aqm_cta_band', 'ctas', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_cta_band_cta', 'label'),
					aqm_lf('aqm_cta_band_cta', 'href'),
					aqm_lf('aqm_cta_band_cta', 'style', 'select', ['choices' => ['primary' => 'Primary', 'ghost' => 'Ghost'], 'default_value' => 'primary']),
					aqm_lf('aqm_cta_band_cta', 'fa'),
				],
			]),
			aqm_lf('aqm_cta_band', 'stats', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_cta_band_stat', 'fa'),
					aqm_lf('aqm_cta_band_stat', 'value'),
					aqm_lf('aqm_cta_band_stat', 'label'),
				],
			]),
		],
	];

	return $layouts;
});

// AQM custom section layouts — PART 2 (the remaining 9), registered via the
// engine's aq_section_layouts filter (update-safe). Companion to
// aqm-layouts-filter.php (which registers the first 17).
//
// Importer mapping (aq-core/includes/class-content-sync.php, apply_sections()):
//   For each JSON section, $row = section; unset type/v; $row['acf_fc_layout'] = $section['type']
//   (the JSON `type` string VERBATIM); then update_field('field_aq_sections', $rows, $id).
//   ACF resolves each row's values by matching the layout whose `name` === acf_fc_layout,
//   then maps the row's keys to that layout's sub_field NAMES. So: layout `name` MUST equal
//   the JSON `type` EXACTLY, and every sub_field `name` MUST equal the JSON key /
//   the renderer's $s['key'] / $row['subkey']. Nested arrays-of-objects (ctas, stats,
//   rows, logos, chips, cards, info, services, notes) are ACF repeaters whose sub_fields
//   match the array element keys.
//
// 9 layouts registered here:
//   6 from AQ_Editor::field_schema() (autoforge-aqm/plugin/aq-core/includes/class-editor.php):
//     cta_banner, chip_marquee, scrub_quote, network_hero, logo_marquee, compare_table
//   3 from the theme block catalog aqm_block_catalog() (autoforge-aqm/theme/aqm-base/
//     blocks/aqm-blocks.php — these are theme-only blocks, not in the plugin field_schema):
//     aqm_split_media, aqm_contact, aqm_termly
//
// Field names/types are mirrored VERBATIM from those two authoritative sources and
// cross-checked against the UNION of keys actually used across content/pages/*.json.
// Source 'toggle' -> ACF 'true_false'; 'select' options -> 'choices'; 'url' -> 'url';
// 'richtext' is registered as 'textarea' (matching the existing filter's convention for
// aqm_feature_grid/aqm_prose_aside body fields; the renderers emit them via wp_kses_post,
// so wysiwyg's wpautop is intentionally avoided to preserve markup parity).

// Reuse the self-contained field builder from aqm-layouts-filter.php if loaded first;
// otherwise define it here (identical signature / key scheme).
if (!function_exists('aqm_lf')) {
	function aqm_lf(string $ctx, string $name, string $type = 'text', array $extra = []): array {
		return array_merge([
			'key'   => "field_aq_{$ctx}_{$name}",
			'name'  => $name,
			'label' => ucwords(str_replace('_', ' ', $name)),
			'type'  => $type,
		], $extra);
	}
}

add_filter('aq_section_layouts', function (array $layouts) {

	/* =====================================================================
	 * Group A — from AQ_Editor::field_schema() (class-editor.php).
	 * ===================================================================== */

	/* cta_banner — renders: eyebrow_fa, eyebrow, heading, heading_accent, body,
	 * ctas[fa,label,href,style], stats[fa,value,label] */
	$layouts['cta_banner'] = [
		'key' => 'layout_aqm_cta_banner',
		'name' => 'cta_banner',
		'label' => 'CTA Banner (.cta-band)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('cta_banner', 'eyebrow_fa'),
			aqm_lf('cta_banner', 'eyebrow'),
			aqm_lf('cta_banner', 'heading'),
			aqm_lf('cta_banner', 'heading_accent'),
			aqm_lf('cta_banner', 'body', 'textarea'),
			aqm_lf('cta_banner', 'ctas', 'repeater', [
				'sub_fields' => [
					aqm_lf('cta_banner_ctas', 'fa'),
					aqm_lf('cta_banner_ctas', 'label'),
					aqm_lf('cta_banner_ctas', 'href', 'url'),
					aqm_lf('cta_banner_ctas', 'style', 'select', ['choices' => ['primary' => 'Primary', 'ghost' => 'Ghost']]),
				],
			]),
			aqm_lf('cta_banner', 'stats', 'repeater', [
				'sub_fields' => [
					aqm_lf('cta_banner_stats', 'fa'),
					aqm_lf('cta_banner_stats', 'value'),
					aqm_lf('cta_banner_stats', 'label'),
				],
			]),
		],
	];

	/* chip_marquee — renders: num, kicker, heading, heading_accent, aside_text,
	 * aside_link_text, aside_link_href, row1_speed, row1_chips[fa,label,href],
	 * row2_speed, row2_chips[fa,label,href] */
	$layouts['chip_marquee'] = [
		'key' => 'layout_aqm_chip_marquee',
		'name' => 'chip_marquee',
		'label' => 'Chip Marquee (industries)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('chip_marquee', 'num'),
			aqm_lf('chip_marquee', 'kicker'),
			aqm_lf('chip_marquee', 'heading'),
			aqm_lf('chip_marquee', 'heading_accent'),
			aqm_lf('chip_marquee', 'aside_text', 'textarea'),
			aqm_lf('chip_marquee', 'aside_link_text'),
			aqm_lf('chip_marquee', 'aside_link_href', 'url'),
			aqm_lf('chip_marquee', 'row1_speed'),
			aqm_lf('chip_marquee', 'row1_chips', 'repeater', [
				'sub_fields' => [
					aqm_lf('chip_marquee_row1_chips', 'fa'),
					aqm_lf('chip_marquee_row1_chips', 'label'),
					aqm_lf('chip_marquee_row1_chips', 'href', 'url'),
				],
			]),
			aqm_lf('chip_marquee', 'row2_speed'),
			aqm_lf('chip_marquee', 'row2_chips', 'repeater', [
				'sub_fields' => [
					aqm_lf('chip_marquee_row2_chips', 'fa'),
					aqm_lf('chip_marquee_row2_chips', 'label'),
					aqm_lf('chip_marquee_row2_chips', 'href', 'url'),
				],
			]),
		],
	];

	/* scrub_quote — renders: num, kicker, quote, author_initials, author_name, author_role */
	$layouts['scrub_quote'] = [
		'key' => 'layout_aqm_scrub_quote',
		'name' => 'scrub_quote',
		'label' => 'Scrub Quote (mission)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('scrub_quote', 'num'),
			aqm_lf('scrub_quote', 'kicker'),
			aqm_lf('scrub_quote', 'quote', 'textarea'),
			aqm_lf('scrub_quote', 'author_initials'),
			aqm_lf('scrub_quote', 'author_name'),
			aqm_lf('scrub_quote', 'author_role'),
		],
	];

	/* network_hero — renders: badge, heading, heading_accent, intro,
	 * ctas[label,href,style,note], notes[text] */
	$layouts['network_hero'] = [
		'key' => 'layout_aqm_network_hero',
		'name' => 'network_hero',
		'label' => 'Network Hero (3D, about)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('network_hero', 'badge'),
			aqm_lf('network_hero', 'heading'),
			aqm_lf('network_hero', 'heading_accent'),
			aqm_lf('network_hero', 'intro', 'textarea'),
			aqm_lf('network_hero', 'ctas', 'repeater', [
				'sub_fields' => [
					aqm_lf('network_hero_ctas', 'label'),
					aqm_lf('network_hero_ctas', 'href', 'url'),
					aqm_lf('network_hero_ctas', 'style', 'select', ['choices' => ['dark' => 'Dark solid', 'outline' => 'Outline']]),
					aqm_lf('network_hero_ctas', 'note'),
				],
			]),
			aqm_lf('network_hero', 'notes', 'repeater', [
				'sub_fields' => [
					aqm_lf('network_hero_notes', 'text'),
				],
			]),
		],
	];

	/* logo_marquee — renders: label_lead, label_strong, logos[name] */
	$layouts['logo_marquee'] = [
		'key' => 'layout_aqm_logo_marquee',
		'name' => 'logo_marquee',
		'label' => 'Logo Marquee (trust band)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('logo_marquee', 'label_lead'),
			aqm_lf('logo_marquee', 'label_strong'),
			aqm_lf('logo_marquee', 'logos', 'repeater', [
				'sub_fields' => [
					aqm_lf('logo_marquee_logos', 'name'),
				],
			]),
		],
	];

	/* compare_table — renders: num, kicker, heading, heading_accent, col_feature,
	 * col_us, col_us_flag, col_b, col_c, rows[feature,us_text,us_check,col_b_text,col_c_text] */
	$layouts['compare_table'] = [
		'key' => 'layout_aqm_compare_table',
		'name' => 'compare_table',
		'label' => 'Compare Table',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('compare_table', 'num'),
			aqm_lf('compare_table', 'kicker'),
			aqm_lf('compare_table', 'heading'),
			aqm_lf('compare_table', 'heading_accent'),
			aqm_lf('compare_table', 'col_feature'),
			aqm_lf('compare_table', 'col_us'),
			aqm_lf('compare_table', 'col_us_flag'),
			aqm_lf('compare_table', 'col_b'),
			aqm_lf('compare_table', 'col_c'),
			aqm_lf('compare_table', 'rows', 'repeater', [
				'sub_fields' => [
					aqm_lf('compare_table_rows', 'feature'),
					aqm_lf('compare_table_rows', 'us_text'),
					aqm_lf('compare_table_rows', 'us_check', 'true_false', ['ui' => 1]),
					aqm_lf('compare_table_rows', 'col_b_text'),
					aqm_lf('compare_table_rows', 'col_c_text'),
				],
			]),
		],
	];

	/* =====================================================================
	 * Group B — theme block catalog (aqm-blocks.php). Not in plugin field_schema;
	 * field defs taken verbatim from aqm_block_catalog().
	 * ===================================================================== */

	/* aqm_split_media — renders (sec-head): kicker, heading, intro;
	 * cards[fa,title,body]; img_src, img_alt */
	$layouts['aqm_split_media'] = [
		'key' => 'layout_aqm_split_media',
		'name' => 'aqm_split_media',
		'label' => 'AQM Split (cards + image)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_split_media', 'kicker'),
			aqm_lf('aqm_split_media', 'heading'),
			aqm_lf('aqm_split_media', 'intro', 'textarea'),
			aqm_lf('aqm_split_media', 'cards', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_split_media_cards', 'fa'),
					aqm_lf('aqm_split_media_cards', 'title'),
					aqm_lf('aqm_split_media_cards', 'body', 'textarea'),
				],
			]),
			aqm_lf('aqm_split_media', 'img_src'),
			aqm_lf('aqm_split_media', 'img_alt'),
		],
	];

	/* aqm_contact — renders: heading, sub, services[label], consent, submit_label,
	 * success_msg, info[fa,title,body], map_query, map_label.
	 * info.body is wp_kses_post (registered textarea per the existing filter convention). */
	$layouts['aqm_contact'] = [
		'key' => 'layout_aqm_contact',
		'name' => 'aqm_contact',
		'label' => 'AQM Contact (form + info + map)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_contact', 'heading'),
			aqm_lf('aqm_contact', 'sub', 'textarea'),
			aqm_lf('aqm_contact', 'services', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_contact_services', 'label'),
				],
			]),
			aqm_lf('aqm_contact', 'consent', 'textarea'),
			aqm_lf('aqm_contact', 'submit_label'),
			aqm_lf('aqm_contact', 'success_msg'),
			aqm_lf('aqm_contact', 'info', 'repeater', [
				'sub_fields' => [
					aqm_lf('aqm_contact_info', 'fa'),
					aqm_lf('aqm_contact_info', 'title'),
					aqm_lf('aqm_contact_info', 'body', 'textarea'),
				],
			]),
			aqm_lf('aqm_contact', 'map_query'),
			aqm_lf('aqm_contact', 'map_label'),
		],
	];

	/* aqm_termly — renders: data_id (Termly policy UUID) */
	$layouts['aqm_termly'] = [
		'key' => 'layout_aqm_termly',
		'name' => 'aqm_termly',
		'label' => 'AQM Legal Policy (Termly embed)',
		'display' => 'block',
		'sub_fields' => [
			aqm_lf('aqm_termly', 'data_id', 'text', ['instructions' => 'Termly policy ID (UUID from app.termly.io).']),
		],
	];

	return $layouts;
});
