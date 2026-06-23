<?php
/**
 * AQM block pack — the interior-page design library for this theme.
 *
 * Each entry is declared ONCE here (label + editable fields). Three filters wire
 * it into the AutoForge engine without touching the shared plugin:
 *   - aq_field_schema  → editor inspector controls + auto-registered ACF layout
 *                        (so values persist) — the high-leverage registry.
 *   - aq_layout_labels → the block's name in the "Add section" picker.
 *   - aq_field_order   → import/normalizer field-order (derived from the schema
 *                        via AQ_Editor::field_order_from_schema(), so no manual
 *                        duplication).
 * Markup lives in ../render-sections/{type}.php (resolved by the plugin's
 * AQ_Renderer::locate_section() override chain). Adding a block = one entry here
 * + one render template. The shared plugin is never edited per design.
 *
 * This file is required from functions.php (theme load), which runs before
 * acf/init and the editor, so the filters are registered in time.
 */

if (!defined('ABSPATH')) {
	exit;
}

/** Field-definition shorthands (match AQ_Editor::field_schema() field shapes). */
function aqm_f(string $name, string $label, string $type = 'text', array $extra = []): array {
	return array_merge(['name' => $name, 'label' => $label, 'type' => $type], $extra);
}
/** The shared .sec-head fields (kicker + heading + intro) every content block carries. */
function aqm_sec_head_fields(): array {
	return [
		aqm_f('kicker', 'Section label (e.g. "01 — WHY US")'),
		aqm_f('heading', 'Heading (inline <em> allowed)'),
		aqm_f('intro', 'Intro paragraph', 'textarea'),
	];
}

/**
 * The AQM interior block catalog: type => [ 'label' => …, 'fields' => [ … ] ].
 */
function aqm_block_catalog(): array {
	$cta_btn = fn(array $styles) => aqm_f('ctas', 'Buttons', 'repeater', ['subfields' => [
		aqm_f('label', 'Label'),
		aqm_f('href', 'Link', 'url'),
		aqm_f('style', 'Style', 'select', ['options' => $styles]),
		aqm_f('fa', 'Icon (FA class, optional)'),
	]]);

	return [
		'aqm_page_hero' => [
			'label'  => 'AQM · Page Hero (+ breadcrumb)',
			'fields' => [
				aqm_f('badge_fa', 'Badge icon (FA class)'),
				aqm_f('badge', 'Badge text'),
				aqm_f('heading', 'H1 (inline <em> allowed)'),
				aqm_f('lede', 'Lede paragraph', 'textarea'),
				$cta_btn(['dark' => 'Dark solid', 'outline' => 'Outline']),
				aqm_f('crumbs', 'Breadcrumb trail', 'repeater', ['subfields' => [
					aqm_f('label', 'Label'),
					aqm_f('url', 'Link (blank = current page)', 'url'),
				]]),
			],
		],
		'aqm_stat_row' => [
			'label'  => 'AQM · Stat Row',
			'fields' => array_merge(aqm_sec_head_fields(), [
				aqm_f('stats', 'Stat tiles', 'repeater', ['subfields' => [
					aqm_f('value', 'Value (e.g. 5.0★, Since 2003)'),
					aqm_f('label', 'Label'),
				]]),
			]),
		],
		'aqm_feature_grid' => [
			'label'  => 'AQM · Feature Grid',
			'fields' => array_merge(aqm_sec_head_fields(), [
				aqm_f('items', 'Features', 'repeater', ['subfields' => [
					aqm_f('fa', 'Icon (FA class)'),
					aqm_f('title', 'Title'),
					aqm_f('body', 'Body (inline links allowed)', 'richtext'),
				]]),
			]),
		],
		'aqm_process_steps' => [
			'label'  => 'AQM · Process Steps',
			'fields' => array_merge(aqm_sec_head_fields(), [
				aqm_f('steps', 'Steps', 'repeater', ['subfields' => [
					aqm_f('step_label', 'Step label (e.g. WEEK 1)'),
					aqm_f('title', 'Title'),
					aqm_f('body', 'Body', 'textarea'),
				]]),
			]),
		],
		'aqm_prose_aside' => [
			'label'  => 'AQM · Prose + Aside',
			'fields' => array_merge(aqm_sec_head_fields(), [
				aqm_f('prose_html', 'Prose (full HTML: H3s, paragraphs, links)', 'richtext'),
				aqm_f('aside_items', 'Aside (media + notes)', 'repeater', ['subfields' => [
					aqm_f('img_src', 'Image path (e.g. /assets/generated/x.png)'),
					aqm_f('img_alt', 'Image alt'),
					aqm_f('note_title', 'Note title (e.g. Did you know?)'),
					aqm_f('note_body', 'Note body', 'textarea'),
				]]),
			]),
		],
		'aqm_svc_grid' => [
			'label'  => 'AQM · Service / Link Cards',
			'fields' => array_merge(aqm_sec_head_fields(), [
				aqm_f('cards', 'Cards', 'repeater', ['subfields' => [
					aqm_f('tag', 'Tag (e.g. Search)'),
					aqm_f('title', 'Title'),
					aqm_f('body', 'Body', 'textarea'),
					aqm_f('href', 'Link', 'url'),
					aqm_f('more_label', '"More" label (arrow added automatically)'),
					aqm_f('features', 'Feature list (one per line, optional)', 'textarea'),
				]]),
			]),
		],
		'aqm_split_media' => [
			'label'  => 'AQM · Split (cards + image)',
			'fields' => array_merge(aqm_sec_head_fields(), [
				aqm_f('cards', 'Cards', 'repeater', ['subfields' => [
					aqm_f('fa', 'Icon (FA class)'),
					aqm_f('title', 'Title'),
					aqm_f('body', 'Body', 'textarea'),
				]]),
				aqm_f('img_src', 'Image path'),
				aqm_f('img_alt', 'Image alt'),
			]),
		],
		'aqm_icon_card_grid' => [
			'label'  => 'AQM · Icon Card Grid',
			'fields' => array_merge(aqm_sec_head_fields(), [
				aqm_f('container', 'Layout', 'select', ['options' => [
					'grid-3' => '3 columns',
					'grid-2' => '2 columns',
					'grid-4' => '4 columns',
					'pain'   => 'Pain grid (problem cards)',
				]]),
				aqm_f('cards', 'Cards', 'repeater', ['subfields' => [
					aqm_f('fa', 'Icon (FA class)'),
					aqm_f('title', 'Title'),
					aqm_f('body', 'Body', 'textarea'),
				]]),
			]),
		],
		'aqm_faq' => [
			'label'  => 'AQM · FAQ (accordion)',
			'fields' => array_merge(aqm_sec_head_fields(), [
				aqm_f('schema', 'Emit FAQPage JSON-LD', 'toggle'),
				aqm_f('items', 'Questions', 'repeater', ['subfields' => [
					aqm_f('q', 'Question'),
					aqm_f('a', 'Answer (inline links allowed)', 'richtext'),
				]]),
			]),
		],
		'aqm_cta_band' => [
			'label'  => 'AQM · CTA Band',
			'fields' => [
				aqm_f('anchor', 'Anchor id (e.g. book)'),
				aqm_f('eyebrow_fa', 'Eyebrow icon (FA class)'),
				aqm_f('eyebrow', 'Eyebrow text'),
				aqm_f('heading', 'Heading (inline <em> allowed)'),
				aqm_f('body', 'Body', 'textarea'),
				$cta_btn(['primary' => 'Primary', 'ghost' => 'Ghost']),
				aqm_f('stats', 'Side stat tiles', 'repeater', ['subfields' => [
					aqm_f('fa', 'Icon (FA class)'),
					aqm_f('value', 'Value'),
					aqm_f('label', 'Label'),
				]]),
			],
		],
	];
}

/* ---- Register the pack into the engine via the three filters ---- */

add_filter('aq_layout_labels', function (array $labels): array {
	foreach (aqm_block_catalog() as $type => $def) {
		$labels[$type] = $def['label'];
	}
	return $labels;
});

add_filter('aq_field_schema', function (array $schema): array {
	foreach (aqm_block_catalog() as $type => $def) {
		$schema[$type] = ['fields' => $def['fields']];
	}
	return $schema;
});

add_filter('aq_field_order', function (array $spec): array {
	if (!is_callable(['AQ_Editor', 'field_order_from_schema'])) {
		return $spec;
	}
	if (!isset($spec['sections']) || !is_array($spec['sections'])) {
		$spec['sections'] = [];
	}
	foreach (aqm_block_catalog() as $type => $def) {
		$spec['sections'][$type] = AQ_Editor::field_order_from_schema(['fields' => $def['fields']]);
	}
	return $spec;
});
