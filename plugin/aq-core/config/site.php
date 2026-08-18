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

	// Legacy-URL 301 redirects (from-with-trailing-slash => to). Per-client data
	// from brand.json; AQ_Redirects reads aq_site('redirects'). Empty by default.
	'redirects' => [],

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
	// idSticky is OPTIONAL — a logo swapped in once the header is in its sticky/
	// scrolled state. Empty falls back to the main `id` logo (no front-end change).
	'logo' => [
		'id'       => 0,
		'idDark'   => 0,
		'idSticky' => 0,
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
		'author'         => '',
		'authorUrl'      => '/about/',
		'label'          => 'Resources',
		'base'           => '/blog/',
		'readMore'       => 'Read article',
		'moreHeading'    => 'More articles',
		'relatedHeading' => 'Keep reading',
		'tocLabel'       => 'In this article',
		'featuredLabel'  => 'Latest',
	],

	// Post CTA (navy banner at the bottom of every blog post).
	'postCta' => [
		'heading' => '',
		'body'    => '',
		'label'   => '',
		'href'    => '',
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

	// Weather widget — a sticky, expand/collapse floating panel that pulls a live
	// forecast (Open-Meteo, keyless) for the client's SERVICE AREA and turns the
	// coming week's conditions into a reason-to-book CTA (rain → drainage, wind →
	// tree-limb check, heat → irrigation, snow → winter prep, …). Client-agnostic
	// mechanism, per-client data. Ships DISABLED fleet-wide (enabled=false) so no
	// existing site shows it until it opts in via brand.json. Rendered in
	// body-close.php behind `aq_site('weather.enabled')`; markup + styles +
	// behavior are all self-contained in render/parts/weather-widget.php.
	'weather' => [
		'enabled'      => false,          // fleet-wide gate — unset/false = fully inert
		'position'     => 'bottom-right', // bottom-right | bottom-left | top-right | top-left
		                                  // NB: a site with a chat widget (usually bottom-right)
		                                  // should place this bottom-left to avoid the collision.
		'units'        => 'fahrenheit',   // fahrenheit | celsius
		'days'         => 5,              // forecast days to fetch/evaluate (1–7)
		'refreshHours' => 3,              // client-side localStorage cache TTL
		'startOpen'    => false,          // expanded on first load (else collapsed pill)
		'heading'      => 'Your local forecast',
		'intro'        => '',             // optional line under the panel heading

		// Default location = the service area. lat/lon resolved once at config
		// time (Open-Meteo geocoding) and stored here; falls back to top-level
		// `geo` + address.locality when omitted.
		'location'     => ['label' => '', 'lat' => null, 'lon' => null],

		// Per-town coordinates for geo/location pages. The widget matches the
		// current page (title/slug) against these keys and, on a hit, shows that
		// town's forecast instead of the service-area default. Key by town name.
		//   'Danvers' => ['lat' => 42.575, 'lon' => -70.93], …
		'townCoords'   => [],

		// Optional brand colors for the widget chrome. When set they print as
		// inline custom properties; when omitted the CSS falls back to common
		// brand tokens (--accent / --brand-700 / --ink) then a neutral default,
		// so the widget looks native on any site with zero config.
		'theme'        => ['accent' => '', 'accentInk' => '', 'panel' => '', 'ink' => ''],

		// Forecast → service "selling rules" (the per-client data layer). The
		// widget evaluates the next `days`, picks the highest-priority MATCHING
		// rule, and shows its message + CTA. `when` is a condition group:
		//   storm | rain | snow | freeze | heat | wind | clear
		// Each rule: ['when','priority','title','text','ctaLabel','ctaHref'].
		'rules'        => [],

		// Shown when no rule matches the forecast (calm/mild week).
		'fallbackCta'  => ['title' => '', 'text' => '', 'ctaLabel' => '', 'ctaHref' => ''],
	],

	// Shared UI labels (used across header, footer, blog templates).
	'labels' => [
		'licensePrefix'   => 'License #',
		'experienceLabel' => 'Years Experience',
		'callPrefix'      => 'Call',
		'viewAll'         => 'View all',
		'copyright'       => 'All rights reserved.',
		'countySuffix'    => 'County',
		'homeLabel'       => 'Home',
	],

	'footer' => [
		'about'       => '',
		'contact'     => ['heading' => 'Contact Us'],
		'company'     => ['heading' => 'Company', 'links' => []],
		'inspections' => ['heading' => '', 'links' => []],
		'legal'       => [],
		'social'      => [],
	],
];
