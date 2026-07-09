<?php
/**
 * Generates /llms.txt — the AI-agent guide file (llmstxt.org), a machine output
 * alongside robots.txt and the sitemap. Served on demand from the site's OWN
 * data (brand summary + published pages + service/area config), so every
 * AutoForge site exposes a valid, structured llms.txt with zero per-client work.
 *
 * This satisfies the Lighthouse / PageSpeed "Agentic Browsing" llms.txt audit:
 * an H1 is always present, the body is non-trivial, and it contains links.
 *
 * Client-agnostic: every value comes from aq_site() + the page tree — nothing is
 * hardcoded. Off production (staging/local — see aq_noindex_active()) a minimal
 * placeholder is emitted instead, so unpublished sites never advertise their
 * structure to agents.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_LLMs {

	public static function register(): void {
		// template_redirect fires for /llms.txt (no rewrite flush needed) before
		// the renderer's template_include and before the 404 template loads.
		add_action('template_redirect', [__CLASS__, 'maybe_serve'], 0);
	}

	/** Intercept GET /llms.txt and emit the plain-text guide. */
	public static function maybe_serve(): void {
		$path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
		if ($path !== 'llms.txt') {
			return;
		}
		status_header(200); // else WP's 404 (this path has no matching query) stands and agents discard the body
		nocache_headers();
		header('Content-Type: text/plain; charset=utf-8');
		header('X-Robots-Tag: noindex');
		echo self::build();
		exit;
	}

	/** Build the llms.txt body from site config + published pages. */
	public static function build(): string {
		$name    = (string) (aq_site('name') ?: aq_site('shortName') ?: get_bloginfo('name'));
		$summary = (string) (aq_site('description') ?: aq_site('tagline') ?: '');
		$base    = rtrim((string) (aq_site('url') ?: home_url('/')), '/');

		$out = '# ' . self::clean($name !== '' ? $name : 'Website') . "\n";
		if ($summary !== '') {
			$out .= "\n> " . self::clean($summary) . "\n";
		}

		// Staging/local: keep it minimal, don't enumerate the site.
		if (function_exists('aq_noindex_active') && aq_noindex_active()) {
			return $out . "\nThis site is not yet published.\n";
		}

		// Key pages — published, indexable, menu-ordered.
		$pages = self::page_links();
		if ($pages) {
			$out .= "\n## Pages\n";
			foreach ($pages as $p) {
				$out .= '- [' . self::clean($p['title']) . '](' . $p['url'] . ")\n";
			}
		}

		// Services + specialty offerings (from the header mega-menu config) —
		// rich, tagline'd entries that read well as agent context.
		$out .= self::config_section('Services', 'services', $base);
		$out .= self::config_section('Specialty Services', 'specialty', $base);

		// Service areas — towns may carry an explicit 'href' (brand.json shape) or
		// just a 'slug'; support both. Build rows first so an empty list never
		// leaves a dangling "## Service Areas" header.
		$area_base = (string) (aq_site('megamenu.areas.base') ?: '/service-area/');
		$rows = '';
		foreach ((array) (aq_site('towns') ?: []) as $t) {
			$nm = self::clean((string) ($t['name'] ?? ''));
			if ($nm === '') {
				continue;
			}
			$href = (string) ($t['href'] ?? '');
			if ($href === '') {
				$slug = (string) ($t['slug'] ?? '');
				if ($slug === '') {
					continue;
				}
				$href = self::path($area_base, $slug);
			}
			$url   = (strpos($href, 'http') === 0) ? $href : $base . '/' . ltrim($href, '/');
			$rows .= '- [' . $nm . '](' . $url . ")\n";
		}
		if ($rows !== '') {
			$out .= "\n## Service Areas\n" . $rows;
		}

		return $out;
	}

	/** A "## {heading}" list from a mega-menu panel's items (slug/label/tagline). */
	private static function config_section(string $heading, string $panel, string $base): string {
		$items = (array) (aq_site("megamenu.{$panel}.items") ?: []);
		if (!$items) {
			return '';
		}
		$pbase = (string) (aq_site("megamenu.{$panel}.base") ?: '/');
		$s     = "\n## " . $heading . "\n";
		foreach ($items as $it) {
			$slug  = (string) ($it['slug'] ?? '');
			$label = (string) ($it['label'] ?? '');
			if ($slug === '' || $label === '') {
				continue;
			}
			$tag = self::clean((string) ($it['tagline'] ?? ''));
			$s  .= '- [' . self::clean($label) . '](' . $base . self::path($pbase, $slug) . ')'
				. ($tag !== '' ? ': ' . $tag : '') . "\n";
		}
		return $s;
	}

	/**
	 * Published, indexable pages as {title,url} for the curated "## Pages" list.
	 * Skips noindex pages and EVERYTHING nested under the /service-area/ hub —
	 * the individual city pages (and city×service pages) are near-duplicate at
	 * scale and are already represented by the dedicated "## Service Areas"
	 * section, so listing all 84 here would just be noise for an agent.
	 */
	private static function page_links(): array {
		$ids = get_posts([
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'menu_order title',
			'order'       => 'ASC',
			'fields'      => 'ids',
		]);
		$out = [];
		foreach ($ids as $pid) {
			$pid = (int) $pid;
			if (get_post_meta($pid, 'seo_noindex', true)) {
				continue;
			}
			$anc = get_post_ancestors($pid); // nearest-first; furthest (root) is end()
			if ($anc && get_post_field('post_name', (int) end($anc)) === 'service-area') {
				continue; // any page under the /service-area/ hub → covered by "## Service Areas"
			}
			$url = get_permalink($pid);
			if ($url) {
				$out[] = ['title' => (string) get_the_title($pid), 'url' => (string) $url];
			}
		}
		return $out;
	}

	/** Join a base path + slug into a clean "/base/slug/". */
	private static function path(string $base, string $slug): string {
		return '/' . trim($base, '/') . '/' . trim($slug, '/') . '/';
	}

	/** Collapse to a single clean line (no tags, no HTML entities, no newlines). */
	private static function clean(string $s): string {
		$s = wp_strip_all_tags($s);
		$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); // "&amp;" -> "&" for plain text
		$s = (string) preg_replace('/\s+/', ' ', $s);
		return trim($s);
	}
}
