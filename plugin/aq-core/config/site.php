<?php
/**
 * Client-agnostic DEFAULTS for site/brand config. The distributed plugin ships
 * these EMPTY — a client's real values are delivered per site via the content
 * import (content/brand.json) into the `aq_site_config` wp_option, which
 * AQ_Site_Config deep-merges ON TOP of this file (so anything a client omits
 * falls back here).
 *
 * This is the single schema for what aq_site('…') can return: NAP, license,
 * logo, fonts, nav/footer menus, the header mega-menu, and blog labels. Keep a
 * key here (with an empty value) for every path templates read, so lookups
 * resolve to a safe blank instead of null surprises.
 *
 * See a client content repo's content/brand.json for a fully-populated example.
 */

if (!defined('ABSPATH')) {
	// Allow `require` from tooling without WordPress, but never expose publicly.
}

return [
	'name'        => '',
	'legalName'   => '',
	'shortName'   => '',
	'tagline'     => '',
	'description' => '', // used for JSON-LD business description; falls back to tagline
	'url'         => '',

	// schema.org business node tuning.
	'schema' => [
		'businessType'  => 'LocalBusiness', // e.g. HomeInspector, Plumber, Dentist
		'serviceRadius' => '60000',          // GeoCircle radius in meters
	],

	// Appended to SEO <title> (e.g. " | Brand Name"). Empty = no suffix.
	'seoSuffix' => '',
	// Browser theme-color meta (brand color). Empty = platform default.
	'themeColor' => '',
	// Optional region phrase used in mega-menu labels (e.g. " in Massachusetts").
	'regionSuffix' => '',

	// Chrome presentation (header/footer). Client-overridable so a marketing
	// agency can run a dark header with a "Book a call" CTA while an inspection
	// client keeps the white header + "Schedule Inspection" defaults below.
	'headerStyle' => 'light',  // 'light' | 'dark' — toggles header[data-header] CSS.
	'showTopbar'  => true,     // false hides the address/experience top strip.
	'stickyBar'   => true,     // false removes the floating sticky call bar.
	// Header primary CTA button (label + href). Defaults to the inspection flow.
	'headerCta'   => ['label' => 'Schedule Inspection', 'href' => '/schedule/'],
	// Footer + sticky-bar CTA (label + href). Defaults to the inspection flow.
	'footerCta'   => ['label' => 'Request a Call Back', 'href' => '/schedule/'],

	// NAP — must match the Google Business Profile exactly.
	'phone'    => '',
	'phoneTel' => '',
	'email'    => '',

	'address' => [
		'street'     => '',
		'locality'   => '',
		'region'     => '',
		'postalCode' => '',
		'country'    => 'US',
	],

	'geo' => [
		'latitude'  => null,
		'longitude' => null,
	],

	'hours' => [
		'monFri' => null,
		'sat'    => null,
		'sun'    => null,
	],

	'license' => [
		'number'         => '',
		'state'          => '',
		'issuingBody'    => '',
		'credentialName' => '', // full schema credential name; falls back to "{state} License #{number}"
		'yearLicensed'   => null,
	],

	'founded' => null,

	// Logo attachment IDs in the WP media library (resolved during image import).
	'logo' => [
		'id'     => 0,
		'idDark' => 0,
	],

	// Optional web-font stylesheet URL (e.g. a Google Fonts CSS2 link). When
	// empty, no external font link is emitted (clients may self-host in their CSS).
	'fonts' => [
		'googleCss' => '',
	],

	'social' => [],

	// Areas served — used for LocalBusiness "areaServed".
	'areas'    => [],
	'counties' => [],
	'regions'  => [],

	// Towns for the header Areas mega panel + footer (slug/name/county).
	'towns' => [],

	// Booking calendar URL embedded on the schedule page.
	'bookingUrl' => '',

	// Blog/article chrome labels.
	'blog' => [
		'author'    => '',
		'authorUrl' => '/about/',
		'label'     => 'Resources',
		'base'      => '/blog/',
	],

	// Header primary nav (AutoForge → Navigation). Each item is one of:
	//   plain  → ['label'=>'Pricing', 'href'=>'/pricing/']
	//   auto   → ['label'=>'Services','href'=>'/services/','panel'=>'services','id'=>'nav-services']
	//            ('panel' = services|specialty|areas, auto-filled from 'megamenu'/'towns')
	//   manual → ['label'=>'About','href'=>'/about/','children'=>[
	//                ['label'=>'…','href'=>'/…/','tagline'=>'…'], … ],
	//             'promo'=>['eyebrow'=>'…','text'=>'…','ctaLabel'=>'…','ctaHref'=>'/…/',
	//                       'cta2Label'=>'…','cta2Href'=>'/…/'], 'linkLabel'=>'View all']
	// A manual item renders as the same rich dropdown panel as the auto ones,
	// built from its own 'children' (+ optional 'promo').
	'nav' => [],

	// Header mega-menu panels. Each panel: base path, heading, "view all" label,
	// a promo card, and a list of items {slug,label,tagline,icon(svg paths)}.
	// Empty by default → a client with no mega-menu data just gets flat nav.
	'megamenu' => [
		'services'  => ['base' => '/services/',             'heading' => '', 'viewAllHref' => '/services/',             'promo' => [], 'items' => []],
		'specialty' => ['base' => '/testing-and-specialty/', 'heading' => '', 'viewAllHref' => '/testing-and-specialty/', 'promo' => [], 'items' => []],
		'areas'     => ['base' => '/service-area/',          'heading' => '', 'viewAllHref' => '/service-area/',          'promo' => []],
	],

	// Footer link columns + social. 'about' is the descriptive blurb under the
	// footer logo (full sentence; empty hides it).
	// Header CTA button (desktop nav, mega-menu promo, mobile nav).
	'headerCta' => ['label' => 'Schedule Inspection', 'href' => '/schedule/'],

	// Footer + sticky bar CTA button.
	'footerCta' => ['label' => 'Request a Call Back', 'href' => '/schedule/'],

	// Sticky call bar (bottom of viewport).
	'stickyBar' => ['label' => 'Questions? Call us:'],

	'footer' => [
		'about'       => '',
		'contact'     => ['heading' => 'Contact Us'],
		'company'     => ['heading' => 'Company', 'links' => []],
		'inspections' => ['heading' => '', 'links' => []],
		'legal'       => [],
		'social'      => [],
	],
];
