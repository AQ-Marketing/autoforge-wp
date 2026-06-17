<?php
/**
 * JSON-LD emitters mirroring the four Astro components:
 *  - LocalBusiness (HomeInspector) — every page, from config/site.php
 *  - BreadcrumbList — from page ancestry (skipped on the front page)
 *  - FAQPage — built from the page's `faq` section rows (single source)
 *  - Service — from the page's `jsonld_services` repeater
 */

class AQ_JsonLd {

	public static function register(): void {
		add_action('wp_head', [__CLASS__, 'local_business'], 5);
		add_action('wp_footer', [__CLASS__, 'breadcrumbs'], 5);
		add_action('wp_footer', [__CLASS__, 'faq'], 6);
		add_action('wp_footer', [__CLASS__, 'services'], 7);
		add_action('wp_footer', [__CLASS__, 'article'], 8);
	}

	private static function print_block(array $data): void {
		echo '<script type="application/ld+json">'
			. wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			. '</script>' . "\n";
	}

	private static function base(): string {
		return rtrim((string) aq_site('url'), '/');
	}

	private static function area_served(): array {
		return array_map(function (string $a): array {
			[$name, $region] = array_pad(explode(', ', $a), 2, '');
			return [
				'@type' => 'City',
				'name' => $name,
				'containedInPlace' => ['@type' => 'State', 'name' => $region],
			];
		}, (array) aq_site('areas'));
	}

	public static function local_business(): void {
		$base = self::base();
		$site = aq_site();

		$hours = [];
		if (!empty($site['hours']['monFri'])) {
			$hours[] = [
				'@type' => 'OpeningHoursSpecification',
				'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
				'opens' => $site['hours']['monFri']['opens'] ?? '',
				'closes' => $site['hours']['monFri']['closes'] ?? '',
			];
		}
		if (!empty($site['hours']['sat'])) {
			$hours[] = [
				'@type' => 'OpeningHoursSpecification',
				'dayOfWeek' => 'Saturday',
				'opens' => $site['hours']['sat']['opens'] ?? '',
				'closes' => $site['hours']['sat']['closes'] ?? '',
			];
		}

		$business_type = (string) ($site['schema']['businessType'] ?? '') ?: 'LocalBusiness';
		$description   = (string) ($site['description'] ?? ($site['tagline'] ?? ''));

		self::print_block([
			'@context' => 'https://schema.org',
			'@type' => $business_type,
			'@id' => "{$base}/#business",
			'name' => $site['name'],
			'alternateName' => $site['shortName'],
			'description' => $description,
			'url' => $base . '/',
			'telephone' => $site['phone'],
			'email' => $site['email'],
			'priceRange' => '$$',
			'foundingDate' => (string) $site['founded'],
			'address' => [
				'@type' => 'PostalAddress',
				'streetAddress' => $site['address']['street'],
				'addressLocality' => $site['address']['locality'],
				'addressRegion' => $site['address']['region'],
				'postalCode' => $site['address']['postalCode'],
				'addressCountry' => $site['address']['country'],
			],
			'geo' => [
				'@type' => 'GeoCoordinates',
				'latitude' => $site['geo']['latitude'],
				'longitude' => $site['geo']['longitude'],
			],
			'areaServed' => self::area_served(),
			'serviceArea' => [
				'@type' => 'GeoCircle',
				'geoMidpoint' => [
					'@type' => 'GeoCoordinates',
					'latitude' => $site['geo']['latitude'],
					'longitude' => $site['geo']['longitude'],
				],
				'geoRadius' => (string) ($site['schema']['serviceRadius'] ?? '') ?: '60000',
			],
			'hasCredential' => [
				'@type' => 'EducationalOccupationalCredential',
				'credentialCategory' => 'license',
				'name' => (string) ($site['license']['credentialName'] ?? '')
					?: trim((($site['license']['state'] ?? '') ? $site['license']['state'] . ' ' : '') . 'License #' . ($site['license']['number'] ?? '')),
				'recognizedBy' => [
					'@type' => 'Organization',
					'name' => $site['license']['issuingBody'],
				],
			],
			'openingHoursSpecification' => $hours,
		]);
	}

	public static function breadcrumbs(): void {
		$base = self::base();
		$id   = get_queried_object_id();

		if (is_singular('post')) {
			// Posts live under the blog base → Home / <blog label> / Title.
			$blog_label = (string) (aq_site('blog.label') ?: 'Resources');
			$blog_base  = (string) (aq_site('blog.base') ?: '/blog/');
			$trail = [
				['name' => 'Home', 'url' => $base . '/'],
				['name' => $blog_label, 'url' => $base . $blog_base],
				['name' => get_the_title($id), 'url' => $base . parse_url((string) get_permalink($id), PHP_URL_PATH)],
			];
		} elseif (is_singular('page') && !is_front_page()) {
			$trail   = [];
			$trail[] = ['name' => 'Home', 'url' => $base . '/'];
			foreach (array_reverse(get_post_ancestors($id)) as $ancestor) {
				$trail[] = [
					'name' => get_the_title($ancestor),
					'url'  => $base . parse_url((string) get_permalink($ancestor), PHP_URL_PATH),
				];
			}
			$trail[] = ['name' => get_the_title($id), 'url' => $base . parse_url((string) get_permalink($id), PHP_URL_PATH)];
		} else {
			return;
		}

		$items = [];
		foreach ($trail as $i => $crumb) {
			$items[] = [
				'@type' => 'ListItem',
				'position' => $i + 1,
				'name' => $crumb['name'],
				'item' => $crumb['url'],
			];
		}

		self::print_block([
			'@context' => 'https://schema.org',
			'@type' => 'BreadcrumbList',
			'itemListElement' => $items,
		]);
	}

	/** Article JSON-LD for blog posts — mirrors the Astro article schema. */
	public static function article(): void {
		if (!is_singular('post')) {
			return;
		}
		$base = self::base();
		$id   = get_queried_object_id();
		$url  = $base . parse_url((string) get_permalink($id), PHP_URL_PATH);

		$desc = function_exists('get_field') ? (string) get_field('seo_description', $id) : '';
		if ($desc === '') {
			$desc = wp_strip_all_tags((string) get_the_excerpt($id));
		}

		self::print_block([
			'@context' => 'https://schema.org',
			'@type' => 'Article',
			'headline' => get_the_title($id),
			'description' => $desc,
			'datePublished' => (string) get_post_time('c', true, $id),
			'dateModified' => (string) get_post_modified_time('c', true, $id),
			'author' => [
				'@type' => 'Person',
				'name' => (string) (aq_site('blog.author') ?: aq_site('name')),
				'url' => $base . (string) (aq_site('blog.authorUrl') ?: '/about/'),
			],
			'publisher' => ['@id' => "{$base}/#business"],
			'mainEntityOfPage' => $url,
		]);
	}

	public static function faq(): void {
		if (!is_singular() || !function_exists('get_field')) {
			return;
		}
		$sections = get_field('sections', get_queried_object_id());
		if (!is_array($sections)) {
			return;
		}
		foreach ($sections as $section) {
			$layout = $section['acf_fc_layout'] ?? '';
			if (!in_array($layout, ['faq', 'faq_dl'], true) || empty($section['schema']) || empty($section['items'])) {
				continue;
			}
			$entities = [];
			foreach ($section['items'] as $item) {
				$entities[] = [
					'@type' => 'Question',
					'name' => wp_strip_all_tags((string) ($item['q'] ?? '')),
					'acceptedAnswer' => [
						'@type' => 'Answer',
						'text' => wp_strip_all_tags((string) ($item['a'] ?? '')),
					],
				];
			}
			self::print_block([
				'@context' => 'https://schema.org',
				'@type' => 'FAQPage',
				'mainEntity' => $entities,
			]);
		}
	}

	public static function services(): void {
		if (!is_singular() || !function_exists('get_field')) {
			return;
		}
		$rows = get_field('jsonld_services', get_queried_object_id());
		if (!is_array($rows)) {
			return;
		}
		$base = self::base();
		foreach ($rows as $row) {
			if (empty($row['name'])) {
				continue;
			}
			$url = (string) ($row['url'] ?? '');
			if ($url && !str_starts_with($url, 'http')) {
				$url = $base . $url;
			}
			self::print_block([
				'@context' => 'https://schema.org',
				'@type' => 'Service',
				'name' => $row['name'],
				'description' => (string) ($row['description'] ?? ''),
				'url' => $url,
				'serviceType' => (string) ($row['service_type'] ?? ''),
				'provider' => ['@id' => "{$base}/#business"],
				'areaServed' => self::area_served(),
			]);
		}
	}
}
