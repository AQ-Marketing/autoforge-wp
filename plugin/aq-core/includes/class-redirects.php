<?php
/**
 * Legacy-URL 301 redirects, ported from the Astro repo's public/_redirects.
 * Pure PHP map applied on template_redirect — no Redirection plugin.
 *
 * NOTE: a handful of clearly-erroneous rules from the old _redirects were
 * intentionally dropped because they would 301 real, live pages:
 *   /terms -> /privacy, /accessibility -> /privacy,
 *   /services/new-construction-inspection -> /services/buyer-home-inspection,
 *   /services/pre-listing-inspection -> /services/buyer-home-inspection
 * Those target pages exist and must resolve normally.
 */

class AQ_Redirects {

	public static function register(): void {
		add_action('template_redirect', [__CLASS__, 'maybe_redirect'], 1);
	}

	public static function maybe_redirect(): void {
		$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
		if ($path === '') {
			return; // home
		}
		$map = self::map();
		$key = $path . '/';
		if (isset($map[$key])) {
			wp_redirect(home_url($map[$key]), 301);
			exit;
		}
	}

	/** from (with trailing slash) => to */
	private static function map(): array {
		return [
			// Old areas/{city} pages -> consolidated /service-area/ hub w/ anchor
			'/areas/deerfield/'        => '/service-area/#deerfield',
			'/deerfield-ma/'           => '/service-area/#deerfield',
			'/south-deerfield-ma/'     => '/service-area/#deerfield',
			'/areas/amherst/'          => '/service-area/#amherst',
			'/amherst-ma/'             => '/service-area/#amherst',
			'/areas/northampton/'      => '/service-area/#northampton',
			'/northampton-ma/'         => '/service-area/#northampton',
			'/areas/greenfield/'       => '/service-area/#greenfield',
			'/greenfield-ma/'          => '/service-area/#greenfield',
			'/areas/hadley/'           => '/service-area/#hadley',
			'/hadley-ma/'              => '/service-area/#hadley',
			'/areas/holyoke/'          => '/service-area/#holyoke',
			'/holyoke-ma/'             => '/service-area/#holyoke',
			'/areas/chicopee/'         => '/service-area/#chicopee',
			'/chicopee-ma/'            => '/service-area/#chicopee',
			'/areas/westfield/'        => '/service-area/#westfield',
			'/westfield-ma/'           => '/service-area/#westfield',
			'/areas/easthampton/'      => '/service-area/#easthampton',
			'/easthampton-ma/'         => '/service-area/#easthampton',
			'/areas/sunderland/'       => '/service-area/#sunderland',
			'/sunderland-ma/'          => '/service-area/#sunderland',
			'/whately-ma/'             => '/service-area/',
			'/hatfield-ma/'            => '/service-area/',
			'/gill-ma/'                => '/service-area/',
			'/northfield-ma/'          => '/service-area/',
			'/areas/'                  => '/service-area/',

			// Old service URLs (WordPress plurals/long-form) -> new singular slugs
			'/home-inspection-services-in-massachusetts/'            => '/services/',
			'/services/buyer-home-inspections/'                      => '/services/buyer-home-inspection/',
			'/buyer-home-inspections-in-massachusetts/'              => '/services/buyer-home-inspection/',
			'/services/seller-pre-listing-home-inspections/'         => '/services/pre-listing-inspection/',
			'/seller-pre-listing-home-inspections-in-massachusetts/' => '/services/pre-listing-inspection/',
			'/services/new-construction-home-inspections/'           => '/services/new-construction-inspection/',
			'/new-construction-home-inspections-in-massachusetts/'   => '/services/new-construction-inspection/',
			'/services/condo-and-townhouse-inspections/'             => '/services/condo-townhouse-inspection/',
			'/condo-and-townhouse-inspections-in-massachusetts/'     => '/services/condo-townhouse-inspection/',
			'/services/multi-family-home-inspections/'               => '/services/multi-family-inspection/',
			'/multi-family-home-inspections-in-massachusetts/'       => '/services/multi-family-inspection/',
			'/services/annual-and-preventative-inspections/'         => '/services/buyer-home-inspection/#annual',
			'/annual-and-preventative-home-inspections-in-massachusetts/' => '/services/buyer-home-inspection/#annual',

			// Old specialty pages -> new /testing-and-specialty/ tree
			'/services/radon-testing/'                          => '/testing-and-specialty/radon-testing/',
			'/specialized/radon-testing/'                       => '/testing-and-specialty/radon-testing/',
			'/radon-testing-in-massachusetts/'                  => '/testing-and-specialty/radon-testing/',
			'/services/termite-and-wdi-inspections/'            => '/testing-and-specialty/termite-wdi/',
			'/specialized/termite-wdi/'                         => '/testing-and-specialty/termite-wdi/',
			'/termite-and-wdi-inspections-in-massachusetts/'    => '/testing-and-specialty/termite-wdi/',
			'/services/mold-inspections/'                       => '/testing-and-specialty/mold-inspection/',
			'/specialized/mold-inspection/'                     => '/testing-and-specialty/mold-inspection/',
			'/mold-inspections-in-massachusetts/'               => '/testing-and-specialty/mold-inspection/',
			'/services/well-water-testing/'                     => '/testing-and-specialty/well-water-testing/',
			'/specialized/well-water-testing/'                  => '/testing-and-specialty/well-water-testing/',
			'/well-water-testing-in-massachusetts/'             => '/testing-and-specialty/well-water-testing/',
			'/services/septic-system-inspections/'              => '/testing-and-specialty/septic-inspection/',
			'/specialized/septic-inspection/'                   => '/testing-and-specialty/septic-inspection/',
			'/septic-system-inspections-in-massachusetts/'      => '/testing-and-specialty/septic-inspection/',
			'/services/thermal-imaging-inspections/'            => '/testing-and-specialty/thermal-imaging/',
			'/specialized/thermal-imaging/'                     => '/testing-and-specialty/thermal-imaging/',
			'/thermal-imaging-home-inspections-in-massachusetts/' => '/testing-and-specialty/thermal-imaging/',
			'/specialized/'                                     => '/testing-and-specialty/',
			'/specialized-inspection-services-in-massachusetts/' => '/testing-and-specialty/',

			// Service-x-city combo pages -> canonical service/specialty page
			'/buyer-home-inspection-amherst-ma/'        => '/services/buyer-home-inspection/',
			'/buyer-home-inspection-northampton-ma/'    => '/services/buyer-home-inspection/',
			'/buyer-home-inspection-deerfield-ma/'      => '/services/buyer-home-inspection/',
			'/condo-inspection-amherst-ma/'             => '/services/condo-townhouse-inspection/',
			'/condo-inspection-northampton-ma/'         => '/services/condo-townhouse-inspection/',
			'/condo-inspection-deerfield-ma/'           => '/services/condo-townhouse-inspection/',
			'/mold-inspection-amherst-ma/'              => '/testing-and-specialty/mold-inspection/',
			'/mold-inspection-northampton-ma/'          => '/testing-and-specialty/mold-inspection/',
			'/mold-inspection-deerfield-ma/'            => '/testing-and-specialty/mold-inspection/',
			'/multi-family-inspection-amherst-ma/'      => '/services/multi-family-inspection/',
			'/multi-family-inspection-northampton-ma/'  => '/services/multi-family-inspection/',
			'/multi-family-inspection-deerfield-ma/'    => '/services/multi-family-inspection/',
			'/pre-listing-home-inspection-amherst-ma/'     => '/services/pre-listing-inspection/',
			'/pre-listing-home-inspection-northampton-ma/' => '/services/pre-listing-inspection/',
			'/pre-listing-home-inspection-deerfield-ma/'   => '/services/pre-listing-inspection/',
			'/radon-testing-amherst-ma/'                => '/testing-and-specialty/radon-testing/',
			'/radon-testing-northampton-ma/'            => '/testing-and-specialty/radon-testing/',
			'/radon-testing-deerfield-ma/'              => '/testing-and-specialty/radon-testing/',
			'/septic-inspection-amherst-ma/'            => '/testing-and-specialty/septic-inspection/',
			'/septic-inspection-northampton-ma/'        => '/testing-and-specialty/septic-inspection/',
			'/septic-inspection-deerfield-ma/'          => '/testing-and-specialty/septic-inspection/',
			'/termite-inspection-amherst-ma/'           => '/testing-and-specialty/termite-wdi/',
			'/termite-inspection-northampton-ma/'       => '/testing-and-specialty/termite-wdi/',
			'/termite-inspection-deerfield-ma/'         => '/testing-and-specialty/termite-wdi/',
			'/thermal-imaging-inspection-amherst-ma/'     => '/testing-and-specialty/thermal-imaging/',
			'/thermal-imaging-inspection-northampton-ma/' => '/testing-and-specialty/thermal-imaging/',
			'/thermal-imaging-inspection-deerfield-ma/'   => '/testing-and-specialty/thermal-imaging/',
			'/well-water-testing-amherst-ma/'           => '/testing-and-specialty/well-water-testing/',
			'/well-water-testing-northampton-ma/'       => '/testing-and-specialty/well-water-testing/',
			'/well-water-testing-deerfield-ma/'         => '/testing-and-specialty/well-water-testing/',

			// Other legacy URLs
			'/about-ken-arnold-home-inspection-llc/'   => '/about/',
			'/contact-home-inspector-massachusetts/'   => '/contact/',
			'/home-inspection-pricing-and-scheduling/' => '/pricing/',
			'/pricing-and-scheduling/'                 => '/pricing/',
			'/sample-home-inspection-reports/'         => '/sample-reports/',
			'/reviews-massachusetts-home-inspector/'   => '/reviews/',
			'/home-inspection-faqs-massachusetts/'     => '/faqs/',
			'/home-inspection-blog-and-resources/'     => '/blog/',

			// Legacy sitemap URLs -> WP core sitemap
			'/sitemap.xml/'        => '/wp-sitemap.xml',
			'/sitemap-index.xml/'  => '/wp-sitemap.xml',
			'/sitemap-0.xml/'      => '/wp-sitemap.xml',
		];
	}
}
