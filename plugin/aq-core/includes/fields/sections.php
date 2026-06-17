<?php
/**
 * ACF field groups, registered in PHP so the schema lives in git.
 * The section layouts here MUST match content/schema/components.json
 * in the repo (the manifest is the documentation contract; this file
 * is the runtime registration). qa tooling diffs the two.
 */

if (!function_exists('acf_add_local_field_group')) {
	return;
}

/** Build a field array with a deterministic key: field_aq_{ctx}_{name}. */
function aq_field(string $ctx, string $name, string $type = 'text', array $extra = []): array {
	return array_merge([
		'key'   => "field_aq_{$ctx}_{$name}",
		'name'  => $name,
		'label' => ucwords(str_replace('_', ' ', $name)),
		'type'  => $type,
	], $extra);
}

/** Image field — uses the WordPress media library (returns attachment ID). */
function aq_image_field(string $ctx): array {
	return aq_field($ctx, 'image', 'image', [
		'return_format' => 'id',
		'preview_size'  => 'medium',
		'library'       => 'all',
	]);
}

function aq_eyebrow_heading(string $ctx, bool $subheading = true): array {
	$fields = [
		aq_field($ctx, 'eyebrow'),
		aq_field($ctx, 'heading'),
	];
	if ($subheading) {
		$fields[] = aq_field($ctx, 'subheading');
	}
	return $fields;
}

/** Centered-header H2 top margin. Home pages use !mt-0; the converted
 *  service/testing/city families use !mt-4 (gap below the eyebrow). */
function aq_h2_mt(string $ctx): array {
	return aq_field($ctx, 'h2_mt', 'select', [
		'choices'       => ['mt-0' => '!mt-0 (home default)', 'mt-4' => '!mt-4 (service/testing/city)'],
		'default_value' => 'mt-0',
	]);
}

$layouts = [];

/* hero */
$layouts['hero'] = [
	'key' => 'layout_aq_hero',
	'name' => 'hero',
	'label' => 'Hero',
	'sub_fields' => array_merge(aq_eyebrow_heading('hero'), [
		aq_field('hero', 'intro', 'textarea'),
		aq_field('hero', 'intro_max', 'number', ['default_value' => 860, 'instructions' => 'max-width px of intro paragraph (home uses 930)']),
		aq_image_field('hero'),
		aq_field('hero', 'ctas', 'repeater', [
			'sub_fields' => [
				aq_field('hero_cta', 'label'),
				aq_field('hero_cta', 'href'),
				aq_field('hero_cta', 'style', 'select', ['choices' => ['primary' => 'Primary (gold)', 'secondary' => 'Secondary (translucent)'], 'default_value' => 'primary']),
			],
		]),
	]),
];

/* why_overview — 2-col [55fr_40fr] text left, image right */
$layouts['why_overview'] = [
	'key' => 'layout_aq_why_overview',
	'name' => 'why_overview',
	'label' => 'Why / Overview (text + image)',
	'sub_fields' => array_merge(aq_eyebrow_heading('why'), [
		aq_field('why', 'bg', 'select', ['choices' => ['white' => 'bg-white', 'brand-50' => 'bg-brand-50'], 'default_value' => 'white']),
		aq_field('why', 'pad', 'select', ['choices' => ['normal' => 'Normal', 'compact' => 'Compact', 'spacious' => 'Spacious'], 'default_value' => 'normal']),
		aq_field('why', 'paragraphs', 'repeater', [
			'sub_fields' => [aq_field('why_p', 'html', 'textarea', ['instructions' => 'Paragraph HTML (inline links allowed)'])],
		]),
		aq_image_field('why'),
	]),
];

/* journey_cards — bg-brand-50, numbered 4-up cards */
$layouts['journey_cards'] = [
	'key' => 'layout_aq_journey_cards',
	'name' => 'journey_cards',
	'label' => 'Journey Cards (numbered)',
	'sub_fields' => array_merge(aq_eyebrow_heading('journey'), [
		aq_h2_mt('journey'),
		aq_field('journey', 'intro', 'textarea'),
		aq_field('journey', 'cards', 'repeater', [
			'sub_fields' => [
				aq_field('journey_card', 'number'),
				aq_field('journey_card', 'title'),
				aq_field('journey_card', 'body', 'textarea'),
			],
		]),
	]),
];

/* dark_card_grid — bg-brand-900, icon cards */
$layouts['dark_card_grid'] = [
	'key' => 'layout_aq_dark_card_grid',
	'name' => 'dark_card_grid',
	'label' => 'Dark Card Grid',
	'sub_fields' => array_merge(aq_eyebrow_heading('dark'), [
		aq_h2_mt('dark'),
		aq_field('dark', 'compact', 'true_false', ['default_value' => 0, 'instructions' => 'Compact cards (service pages): h3 text-[20px], text-[15px] body, link-learn-more + 13px arrow. Off = home full size (22px / --lg / 14px).']),
		aq_field('dark', 'intro', 'textarea'),
		aq_field('dark', 'cards', 'repeater', [
			'sub_fields' => [
				aq_field('dark_card', 'icon_svg', 'textarea', ['instructions' => 'Complete <svg>…</svg> markup']),
				aq_field('dark_card', 'title'),
				aq_field('dark_card', 'body', 'textarea'),
				aq_field('dark_card', 'link_label'),
				aq_field('dark_card', 'link_href'),
				aq_field('dark_card', 'link_aria'),
			],
		]),
	]),
];

/* trust_image_left — image left, text + checklist right */
$layouts['trust_image_left'] = [
	'key' => 'layout_aq_trust_image_left',
	'name' => 'trust_image_left',
	'label' => 'Trust (image left + checklist)',
	'sub_fields' => array_merge(aq_eyebrow_heading('trust'), [
		aq_field('trust', 'bg', 'select', ['choices' => ['white' => 'bg-white', 'brand-50' => 'bg-brand-50'], 'default_value' => 'white']),
		aq_field('trust', 'pad', 'select', ['choices' => ['normal' => 'Normal', 'compact' => 'Compact', 'spacious' => 'Spacious'], 'default_value' => 'normal']),
		aq_field('trust', 'paragraphs', 'repeater', [
			'sub_fields' => [aq_field('trust_p', 'html', 'textarea')],
		]),
		aq_field('trust', 'checklist', 'repeater', [
			'sub_fields' => [aq_field('trust_check', 'text')],
		]),
		aq_field('trust', 'cta_label'),
		aq_field('trust', 'cta_href'),
		aq_image_field('trust'),
	]),
];

/* testimonials — bg-brand-50, 3-up quote cards */
$layouts['testimonials'] = [
	'key' => 'layout_aq_testimonials',
	'name' => 'testimonials',
	'label' => 'Testimonials',
	'sub_fields' => array_merge(aq_eyebrow_heading('testi', false), [
		aq_h2_mt('testi'),
		aq_field('testi', 'bg', 'select', ['choices' => ['brand-50' => 'bg-brand-50 (home)', 'white' => 'bg-white (service)'], 'default_value' => 'brand-50']),
		aq_field('testi', 'intro', 'textarea'),
		aq_field('testi', 'items', 'repeater', [
			'sub_fields' => [
				aq_field('testi_item', 'quote', 'textarea'),
				aq_field('testi_item', 'name'),
				aq_field('testi_item', 'role'),
			],
		]),
		aq_field('testi', 'cta_label'),
		aq_field('testi', 'cta_href'),
	]),
];

/* faq — bg-brand-600 accordion */
$layouts['faq'] = [
	'key' => 'layout_aq_faq',
	'name' => 'faq',
	'label' => 'FAQ Accordion',
	'sub_fields' => array_merge(aq_eyebrow_heading('faq'), [
		aq_h2_mt('faq'),
		aq_field('faq', 'items', 'repeater', [
			'sub_fields' => [
				aq_field('faq_item', 'q'),
				aq_field('faq_item', 'a', 'textarea', ['instructions' => 'Answer HTML (inline links allowed)']),
			],
		]),
		aq_field('faq', 'schema', 'true_false', ['default_value' => 1, 'instructions' => 'Emit FAQPage JSON-LD from these rows']),
	]),
];

/* final_cta — bg-brand-900 with background image */
$layouts['final_cta'] = [
	'key' => 'layout_aq_final_cta',
	'name' => 'final_cta',
	'label' => 'Final CTA',
	'sub_fields' => array_merge(aq_eyebrow_heading('cta'), [
		aq_field('cta', 'body', 'textarea'),
		aq_image_field('cta'),
		aq_field('cta', 'cta_label', 'text', ['default_value' => 'Schedule Your Inspection']),
		aq_field('cta', 'cta_href', 'text', ['default_value' => '/schedule/']),
		aq_field('cta', 'footnote'),
	]),
];

/* breadcrumb — visible breadcrumb nav (BreadcrumbList JSON-LD comes from aq-core) */
$layouts['breadcrumb'] = [
	'key' => 'layout_aq_breadcrumb',
	'name' => 'breadcrumb',
	'label' => 'Breadcrumb',
	'sub_fields' => [
		aq_field('crumb', 'variant', 'select', ['choices' => ['plain' => 'Plain (container-x bar)', 'wide' => 'Wide (bg-brand-50 band)', 'wide_index' => 'Wide — index hubs (bare first li, no aria-hidden)'], 'default_value' => 'plain']),
		aq_field('crumb', 'items', 'repeater', [
			'instructions' => 'Last row with an empty URL renders as the current-page (leaf) label.',
			'sub_fields' => [
				aq_field('crumb_item', 'label'),
				aq_field('crumb_item', 'url'),
			],
		]),
	],
];

/* page_header — plain article header: eyebrow + H1 + free-form meta line */
$layouts['page_header'] = [
	'key' => 'layout_aq_page_header',
	'name' => 'page_header',
	'label' => 'Page Header (eyebrow + H1 + meta)',
	'sub_fields' => [
		aq_field('phead', 'eyebrow'),
		aq_field('phead', 'heading'),
		aq_field('phead', 'meta', 'text', ['instructions' => 'Free-form meta line, verbatim (the leading "·" is content, not a separator).']),
		aq_field('phead', 'open_article', 'true_false', ['default_value' => 0, 'instructions' => 'Open the <article class="container-x py-10"> wrapper (city × service pages). Closed by a later section with "Close article".']),
	],
];

/* prose — generic prose-content section: H2 + variant paragraphs */
$layouts['prose'] = [
	'key' => 'layout_aq_prose',
	'name' => 'prose',
	'label' => 'Prose (heading + paragraphs)',
	'sub_fields' => [
		aq_field('prose', 'heading'),
		aq_field('prose', 'margin_top', 'select', ['choices' => ['mt-8' => 'mt-8 (first section)', 'mt-10' => 'mt-10 (subsequent)'], 'default_value' => 'mt-10']),
		aq_field('prose', 'blocks', 'repeater', [
			'sub_fields' => [
				aq_field('prose_b', 'html', 'textarea', ['instructions' => 'Paragraph HTML (inline links allowed).']),
				aq_field('prose_b', 'variant', 'select', ['choices' => ['normal' => 'Normal', 'lead' => 'Lead (text-lg text-brand-700)'], 'default_value' => 'normal']),
			],
		]),
	],
];

/* faq_dl — plain <dl>/<details> FAQ (distinct from the JS faq accordion) */
$layouts['faq_dl'] = [
	'key' => 'layout_aq_faq_dl',
	'name' => 'faq_dl',
	'label' => 'FAQ (plain list)',
	'sub_fields' => [
		aq_field('fdl', 'heading', 'text', ['default_value' => 'Frequently Asked Questions']),
		aq_field('fdl', 'items', 'repeater', [
			'sub_fields' => [
				aq_field('fdl_item', 'q'),
				aq_field('fdl_item', 'a', 'textarea', ['instructions' => 'Answer HTML (inline links allowed).']),
			],
		]),
		aq_field('fdl', 'schema', 'true_false', ['default_value' => 1, 'instructions' => 'Emit FAQPage JSON-LD from these rows.']),
	],
];

/* link_card_grid — grid of linked cards (bare/light/dark variants) */
$layouts['link_card_grid'] = [
	'key' => 'layout_aq_link_card_grid',
	'name' => 'link_card_grid',
	'label' => 'Link Card Grid',
	'sub_fields' => [
		aq_field('lcg', 'variant', 'select', ['choices' => ['bare' => 'Bare (plain H2, sm:2-col)', 'light' => 'Light (bg-brand-50, centered header)', 'dark' => 'Dark (bg-brand-900, centered header)'], 'default_value' => 'bare']),
		aq_field('lcg', 'wrapper_class', 'text', ['instructions' => 'Bare variant section wrapper, e.g. "mt-12 max-w-4xl mx-auto".']),
		aq_field('lcg', 'eyebrow', 'text', ['instructions' => 'light/dark variants only — centered pill-eyebrow.']),
		aq_field('lcg', 'heading'),
		aq_field('lcg', 'subheading', 'text', ['instructions' => 'light/dark variants only — h2-sub second line.']),
		aq_field('lcg', 'intro', 'textarea', ['instructions' => 'light/dark variants only — lead paragraph under the heading.']),
		aq_field('lcg', 'cols', 'select', ['choices' => ['3' => '3 columns (lg)', '4' => '4 columns (lg)'], 'default_value' => '3', 'instructions' => 'light/dark variants only — lg grid column count.']),
		aq_field('lcg', 'bg', 'select', ['choices' => ['brand-50' => 'bg-brand-50', 'white' => 'bg-white'], 'default_value' => 'brand-50', 'instructions' => 'light variant only — section background.']),
		aq_field('lcg', 'cards', 'repeater', [
			'sub_fields' => [
				aq_field('lcg_card', 'title'),
				aq_field('lcg_card', 'body', 'textarea'),
				aq_field('lcg_card', 'note', 'text', ['instructions' => 'Optional small note line (text-xs text-brand-400) before "Learn More".']),
				aq_field('lcg_card', 'href'),
				aq_field('lcg_card', 'aria', 'text', ['instructions' => 'light/dark variants only — aria-label for the card link.']),
			],
		]),
		aq_field('lcg', 'cta_label', 'text', ['instructions' => 'light/dark variants only — optional centered button below the grid (e.g. "View All Inspection Services").']),
		aq_field('lcg', 'cta_href'),
		aq_field('lcg', 'close_article', 'true_false', ['default_value' => 0, 'instructions' => 'Close the <article> wrapper opened by a page_header (last in-article section on city × service pages).']),
	],
];

/* city_hero — full-bleed image hero for city-hub pages (distinct from hero) */
$layouts['city_hero'] = [
	'key' => 'layout_aq_city_hero',
	'name' => 'city_hero',
	'label' => 'City Hero (image + two-line H1)',
	'sub_fields' => array_merge(aq_eyebrow_heading('cityhero'), [
		aq_field('cityhero', 'sub_style', 'select', ['choices' => ['h1-sub' => 'h1-sub (city/service/testing pages)', 'text-accent-500' => 'text-accent-500 (index hubs)'], 'default_value' => 'h1-sub', 'instructions' => 'Second H1 line color treatment.']),
		aq_field('cityhero', 'intro', 'textarea', ['instructions' => 'Optional intro paragraph (max-w-[860px]) between the H1 and the buttons.']),
		aq_image_field('cityhero'),
		aq_field('cityhero', 'badges', 'repeater', [
			'instructions' => 'Optional translucent pill badges (testing/specialty heroes) shown above the buttons.',
			'sub_fields'   => [aq_field('cityhero_badge', 'text')],
		]),
		aq_field('cityhero', 'ctas', 'repeater', [
			'sub_fields' => [
				aq_field('cityhero_cta', 'label'),
				aq_field('cityhero_cta', 'href'),
				aq_field('cityhero_cta', 'style', 'select', ['choices' => ['primary' => 'Primary (gold)', 'secondary' => 'Secondary (translucent)'], 'default_value' => 'primary']),
			],
		]),
	]),
];

/* prose_with_image — eyebrow/H2/paragraphs/checklist beside an image */
$layouts['prose_with_image'] = [
	'key' => 'layout_aq_prose_with_image',
	'name' => 'prose_with_image',
	'label' => 'Prose + Image (text + photo, optional checklist)',
	'sub_fields' => array_merge(aq_eyebrow_heading('pwi'), [
		aq_field('pwi', 'bg', 'select', ['choices' => ['white' => 'bg-white', 'brand-50' => 'bg-brand-50'], 'default_value' => 'white']),
		aq_field('pwi', 'image_side', 'select', ['choices' => ['right' => 'Image right (text left)', 'left' => 'Image left (text right)'], 'default_value' => 'right']),
		aq_field('pwi', 'col_ratio', 'text', ['default_value' => '55fr_40fr', 'instructions' => 'lg grid columns, e.g. "55fr_40fr" or "45fr_55fr".']),
		aq_field('pwi', 'align', 'select', ['choices' => ['start' => 'items-start', 'center' => 'items-center'], 'default_value' => 'start']),
		aq_field('pwi', 'paragraphs', 'repeater', [
			'sub_fields' => [aq_field('pwi_p', 'html', 'textarea', ['instructions' => 'Paragraph HTML (inline links allowed).'])],
		]),
		aq_field('pwi', 'checklist', 'repeater', [
			'sub_fields' => [aq_field('pwi_check', 'text')],
		]),
		aq_field('pwi', 'link_list', 'repeater', [
			'instructions' => 'Optional bordered link rows (services index "Which inspection do you need?"). Rendered after the paragraphs.',
			'sub_fields' => [
				aq_field('pwi_link', 'label', 'text', ['instructions' => 'Left label (e.g. "Buying a home").']),
				aq_field('pwi_link', 'link_text', 'text', ['instructions' => 'Right link text (e.g. "Buyer Inspections").']),
				aq_field('pwi_link', 'href'),
			],
		]),
		aq_field('pwi', 'footnote', 'textarea', ['instructions' => 'Optional small paragraph (text-sm) after the checklist, e.g. a "reach out if your town isn\'t listed" note.']),
		aq_field('pwi', 'footnote_mt', 'text', ['default_value' => 'mt-4', 'instructions' => 'Footnote top-margin utility (mt-4 / mt-6).']),
		aq_field('pwi', 'cta_label', 'text', ['instructions' => 'Optional button below the text (e.g. "About Us").']),
		aq_field('pwi', 'cta_href'),
		aq_field('pwi', 'image_wrap_class', 'text', ['instructions' => 'Extra classes on the image column wrapper (e.g. "lg:pt-16").']),
		aq_image_field('pwi'),
	]),
];

/* service_card_grid — bg-brand-900 flex-col cards (icon, title, body, optional price
 * row, "Full Details" link). Used by the services + testing-and-specialty index hubs.
 * Distinct from dark_card_grid (home-style cards: no flex-col, no price, sr-only aria). */
$layouts['service_card_grid'] = [
	'key' => 'layout_aq_service_card_grid',
	'name' => 'service_card_grid',
	'label' => 'Service Card Grid (index hubs)',
	'sub_fields' => array_merge(aq_eyebrow_heading('scg'), [
		aq_field('scg', 'intro', 'textarea'),
		aq_field('scg', 'cards', 'repeater', [
			'sub_fields' => [
				aq_field('scg_card', 'icon_svg', 'textarea', ['instructions' => 'Complete <svg>…</svg> markup.']),
				aq_field('scg_card', 'title'),
				aq_field('scg_card', 'body', 'textarea'),
				aq_field('scg_card', 'price_primary', 'text', ['instructions' => 'Optional price line 1 (e.g. "$150 standalone").']),
				aq_field('scg_card', 'price_secondary', 'text', ['instructions' => 'Optional price line 2 (e.g. "$125 with home inspection").']),
				aq_field('scg_card', 'link_label', 'text', ['default_value' => 'Full Details']),
				aq_field('scg_card', 'link_href'),
			],
		]),
	]),
];

/* town_card_grid — directory of linked .card cards with a county eyebrow, used by the
 * service-area index (primary-towns + full-coverage grids). Distinct from link_card_grid:
 * county eyebrow above the title, optional "View Town Profile" link row, no aria/Learn-More. */
$layouts['town_card_grid'] = [
	'key' => 'layout_aq_town_card_grid',
	'name' => 'town_card_grid',
	'label' => 'Town Card Grid (directory cards)',
	'sub_fields' => array_merge(aq_eyebrow_heading('tcg'), [
		aq_field('tcg', 'intro', 'textarea'),
		aq_field('tcg', 'bg', 'select', ['choices' => ['white' => 'bg-white', 'brand-50' => 'bg-brand-50'], 'default_value' => 'white']),
		aq_field('tcg', 'grid_class', 'text', ['default_value' => 'grid sm:grid-cols-2 lg:grid-cols-3 gap-5', 'instructions' => 'Exact grid wrapper classes.']),
		aq_field('tcg', 'card_heading_size', 'select', ['choices' => ['base' => 'text-base', 'xl' => 'text-xl'], 'default_value' => 'base']),
		aq_field('tcg', 'line_clamp', 'true_false', ['default_value' => 0, 'instructions' => 'Clamp card body to 3 lines (line-clamp-3).']),
		aq_field('tcg', 'cta_label', 'text', ['instructions' => 'Optional per-card link text (e.g. "View Town Profile"). Empty = no link row.']),
		aq_field('tcg', 'cards', 'repeater', [
			'sub_fields' => [
				aq_field('tcg_card', 'county', 'text', ['instructions' => 'Small uppercase eyebrow above the title (e.g. "Franklin County").']),
				aq_field('tcg_card', 'title'),
				aq_field('tcg_card', 'body', 'textarea'),
				aq_field('tcg_card', 'href'),
			],
		]),
	]),
];

/* cta_band — inline schedule CTA band (bg-brand-800), distinct from final_cta */
$layouts['cta_band'] = [
	'key' => 'layout_aq_cta_band',
	'name' => 'cta_band',
	'label' => 'CTA Band (inline)',
	'sub_fields' => [
		aq_field('band', 'headline', 'text', ['default_value' => 'Ready to schedule your inspection?']),
		aq_field('band', 'body', 'textarea'),
		aq_field('band', 'primary_label', 'text', ['default_value' => 'Schedule Online']),
		aq_field('band', 'primary_href', 'text', ['default_value' => '/schedule/']),
		aq_field('band', 'secondary_label', 'text', ['instructions' => 'Defaults to "Call <site phone>".']),
		aq_field('band', 'secondary_href', 'text', ['instructions' => 'Defaults to the site tel: link.']),
	],
];

/* prose_article — long-form article (.prose) + optional sticky sidebar */
$layouts['prose_article'] = [
	'key' => 'layout_aq_prose_article',
	'name' => 'prose_article',
	'label' => 'Prose Article (long-form + sidebar)',
	'sub_fields' => [
		aq_field('part', 'col_ratio', 'text', ['default_value' => '60fr_35fr', 'instructions' => 'lg grid columns when a sidebar is present.']),
		// textarea with new_lines='' returns HTML verbatim — no wpautop/wptexturize (which the wysiwyg field applies and which breaks parity).
		aq_field('part', 'body', 'textarea', ['rows' => 12, 'new_lines' => '', 'instructions' => 'Long-form article HTML (rendered inside .prose prose-brand).']),
		aq_field('part', 'aside', 'textarea', ['rows' => 8, 'new_lines' => '', 'instructions' => 'Optional sticky sidebar HTML (At-a-Glance card, CTA box).']),
	],
];

/* legal_doc — long-form legal/utility page: <article> with H1 + meta + .prose-content body */
$layouts['legal_doc'] = [
	'key' => 'layout_aq_legal_doc',
	'name' => 'legal_doc',
	'label' => 'Legal / Doc Page (H1 + prose-content body)',
	'sub_fields' => [
		aq_field('legal', 'heading'),
		aq_field('legal', 'meta', 'text', ['instructions' => 'Optional sub-line under the H1, e.g. "Last updated: April 27, 2026".']),
		// textarea with new_lines='' returns HTML verbatim — no wpautop/wptexturize (parity).
		aq_field('legal', 'body', 'textarea', ['rows' => 20, 'new_lines' => '', 'instructions' => 'Body HTML rendered inside .prose-content (h2 / p / ul / inline links).']),
	],
];

/* feature_cards — centered header + grid of cards with verbatim inner markup
 * (about contact cards, sample-reports download cards). card.html is a raw sink. */
$layouts['feature_cards'] = [
	'key' => 'layout_aq_feature_cards',
	'name' => 'feature_cards',
	'label' => 'Feature Cards (freeform card grid)',
	'sub_fields' => array_merge(aq_eyebrow_heading('fcards'), [
		aq_field('fcards', 'intro', 'textarea'),
		aq_field('fcards', 'section_class', 'text', ['instructions' => 'Full <section> class (overrides bg). e.g. "bg-brand-50 py-12 md:py-16".']),
		aq_field('fcards', 'bg', 'select', ['choices' => ['white' => 'bg-white', 'brand-50' => 'bg-brand-50'], 'default_value' => 'white', 'instructions' => 'Legacy fallback when section_class is empty.']),
		aq_field('fcards', 'header_mb', 'select', ['choices' => ['mb-12' => 'mb-12', 'mb-10' => 'mb-10'], 'default_value' => 'mb-12']),
		aq_field('fcards', 'eyebrow_class', 'text', ['default_value' => 'pill-eyebrow mb-6', 'instructions' => 'Eyebrow span classes (home pages use "inline-block uppercase rounded-full ... pill-eyebrow").']),
		aq_field('fcards', 'h2_class', 'text', ['default_value' => '!mt-4', 'instructions' => 'H2 classes (!mt-4 service style, !mt-0 home style).']),
		aq_field('fcards', 'grid_class', 'text', ['default_value' => 'grid sm:grid-cols-3 gap-6 max-w-4xl mx-auto', 'instructions' => 'Exact grid wrapper classes.']),
		aq_field('fcards', 'cards', 'repeater', [
			'sub_fields' => [
				aq_field('fcards_card', 'wrapper_class', 'text', ['default_value' => 'bg-brand-50 rounded-lg p-5 text-center']),
				aq_field('fcards_card', 'html', 'textarea', ['rows' => 6, 'new_lines' => '', 'instructions' => 'Card inner HTML (verbatim; may include inline SVG). Code-mode only.']),
			],
		]),
	]),
];

/* step_cards — structured numbered-step cards (number / title / text). Same
 * centered header + grid as feature_cards, but the card body is three plain
 * fields instead of a raw-HTML sink (the thank-you "What happens next" row). */
$layouts['step_cards'] = [
	'key' => 'layout_aq_step_cards',
	'name' => 'step_cards',
	'label' => 'Step Cards (numbered)',
	'sub_fields' => array_merge(aq_eyebrow_heading('steps'), [
		aq_field('steps', 'intro', 'textarea'),
		aq_field('steps', 'section_class', 'text', ['instructions' => 'Full <section> class (overrides bg). e.g. "bg-brand-50 py-12 md:py-16".']),
		aq_field('steps', 'bg', 'select', ['choices' => ['white' => 'bg-white', 'brand-50' => 'bg-brand-50'], 'default_value' => 'white', 'instructions' => 'Legacy fallback when section_class is empty.']),
		aq_field('steps', 'header_mb', 'select', ['choices' => ['mb-12' => 'mb-12', 'mb-10' => 'mb-10'], 'default_value' => 'mb-12']),
		aq_field('steps', 'eyebrow_class', 'text', ['default_value' => 'pill-eyebrow mb-6', 'instructions' => 'Eyebrow span classes.']),
		aq_field('steps', 'h2_class', 'text', ['default_value' => '!mt-4', 'instructions' => 'H2 classes (!mt-4 service style, !mt-0 home style).']),
		aq_field('steps', 'grid_class', 'text', ['default_value' => 'grid sm:grid-cols-3 gap-6 max-w-4xl mx-auto', 'instructions' => 'Exact grid wrapper classes.']),
		aq_field('steps', 'cards', 'repeater', [
			'sub_fields' => [
				aq_field('steps_card', 'wrapper_class', 'text', ['default_value' => 'bg-white rounded-lg p-6 text-center']),
				aq_field('steps_card', 'number', 'text', ['instructions' => 'Step number/label, e.g. "1".']),
				aq_field('steps_card', 'title'),
				aq_field('steps_card', 'text', 'textarea', ['instructions' => 'Step description (inline links allowed).']),
			],
		]),
	]),
];

/* rich_section — styled <section> wrapper around a verbatim body (raw sink).
 * Bespoke one-off bodies: pricing fee tables, scheduler iframe, contact form,
 * reviews widget, thank-you steps. Image-bearing heroes use media_hero. */
$layouts['rich_section'] = [
	'key' => 'layout_aq_rich_section',
	'name' => 'rich_section',
	'label' => 'Rich Section (styled wrapper + raw body)',
	'sub_fields' => [
		aq_field('rich', 'section_class', 'text', ['default_value' => 'bg-white py-12 md:py-16 lg:py-20', 'instructions' => 'Full <section> class string.']),
		aq_field('rich', 'body', 'textarea', ['rows' => 12, 'new_lines' => '', 'instructions' => 'Verbatim inner HTML (tables / iframes / widgets). Code-mode only.']),
	],
];

/* media_hero — library image + inline-style overlay + verbatim content body.
 * For the bespoke contact / thank-you heroes (inline-styled overlay/sub/intro). */
$layouts['media_hero'] = [
	'key' => 'layout_aq_media_hero',
	'name' => 'media_hero',
	'label' => 'Media Hero (image + raw content)',
	'sub_fields' => [
		aq_image_field('mhero'),
		aq_field('mhero', 'image_class', 'text', ['default_value' => 'absolute inset-0 w-full h-full object-cover']),
		aq_field('mhero', 'overlay_class', 'text', ['instructions' => 'Overlay tint div class beyond "absolute inset-0" (e.g. "overlay-hero"). Empty = inline-style overlay.']),
		aq_field('mhero', 'overlay_style', 'text', ['instructions' => 'Inline style for the overlay tint div, e.g. "background-color: rgba(38,43,71,0.9);".']),
		aq_field('mhero', 'content_class', 'text', ['default_value' => 'relative container-edge container-edge--wide py-12 md:py-[72px] lg:py-[100px]']),
		aq_field('mhero', 'body', 'textarea', ['rows' => 10, 'new_lines' => '', 'instructions' => 'Verbatim hero content (eyebrow / H1 / intro / CTAs / badge). Code-mode only.']),
	],
];

/* raw_html — escape hatch for bespoke utility pages */
$layouts['raw_html'] = [
	'key' => 'layout_aq_raw_html',
	'name' => 'raw_html',
	'label' => 'Raw HTML',
	'sub_fields' => [
		aq_field('raw', 'html', 'textarea', ['rows' => 20]),
	],
];

/* post_feed — Resources index: heading block + live grid of post cards */
$layouts['post_feed'] = [
	'key' => 'layout_aq_post_feed',
	'name' => 'post_feed',
	'label' => 'Post Feed (blog index)',
	'sub_fields' => [
		aq_field('post_feed', 'heading', 'text', ['default_value' => 'Resources & Articles']),
		aq_field('post_feed', 'intro', 'textarea', ['instructions' => 'Lead paragraph under the heading']),
		aq_field('post_feed', 'limit', 'number', ['default_value' => 24, 'instructions' => 'Max posts to list (0 = default 24)']),
	],
];

acf_add_local_field_group([
	'key' => 'group_aq_sections',
	'title' => 'Page Sections',
	'fields' => [
		[
			'key' => 'field_aq_sections',
			'name' => 'sections',
			'label' => 'Sections',
			'type' => 'flexible_content',
			'button_label' => 'Add Section',
			'layouts' => $layouts,
		],
	],
	'location' => [
		[['param' => 'post_type', 'operator' => '==', 'value' => 'page']],
	],
	'position' => 'normal',
	'hide_on_screen' => ['the_content'],
]);

acf_add_local_field_group([
	'key' => 'group_aq_seo',
	'title' => 'SEO',
	'fields' => [
		aq_field('seo', 'seo_title', 'text', ['instructions' => 'Page title WITHOUT the brand suffix (the brand name is appended automatically) unless the brand is intentionally included.']),
		aq_field('seo', 'seo_description', 'textarea', ['rows' => 3, 'instructions' => '≤160 characters']),
		aq_field('seo', 'seo_canonical', 'text', ['instructions' => 'Absolute canonical URL. City × service pages MUST point at the parent service page.']),
		aq_field('seo', 'seo_noindex', 'true_false'),
		aq_field('seo', 'seo_og_image', 'text', ['instructions' => 'Absolute URL or path for og:image']),
		aq_field('seo', 'jsonld_services', 'repeater', [
			'label' => 'JSON-LD Services',
			'sub_fields' => [
				aq_field('seo_svc', 'name'),
				aq_field('seo_svc', 'description', 'textarea'),
				aq_field('seo_svc', 'url'),
				aq_field('seo_svc', 'service_type'),
			],
		]),
	],
	'location' => [
		[['param' => 'post_type', 'operator' => '==', 'value' => 'page']],
		[['param' => 'post_type', 'operator' => '==', 'value' => 'post']],
	],
	'position' => 'normal',
]);
