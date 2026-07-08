<?php
/**
 * AQ_Blog_Feed — shared blog-index infinite scroll for the post_feed section.
 *
 * Provides:
 *   1. A public REST route (GET aq/v1/more-posts?offset&number) that returns the
 *      next page of posts as server-rendered `post-card` markup — byte-identical
 *      to the initial server render, so appended cards match exactly.
 *   2. A generic client script (printed once in the footer, only when a feed is
 *      on the page) that turns any element carrying `data-aq-more` into an
 *      infinite-scroll loader. The engine's post-feed.php uses it, and any
 *      theme override can too by emitting AQ_Blog_Feed::sentinel().
 *
 * Read-only, published posts only — safe to expose without a nonce.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Blog_Feed {

	/** Grid cards shown on first paint (the featured lead is an extra +1). */
	const INITIAL = 9;
	/** Cards fetched per infinite-scroll step (3 rows of 3). */
	const BATCH = 9;

	/** Set true once a feed emits a sentinel, so the footer script prints once. */
	protected static $active = false;

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'routes']);
		add_action('wp_footer', [__CLASS__, 'print_script'], 20);
	}

	public static function routes(): void {
		register_rest_route('aq/v1', '/more-posts', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => [
				'offset' => ['sanitize_callback' => 'absint'],
				'number' => ['sanitize_callback' => 'absint'],
			],
			'callback'            => [__CLASS__, 'rest_more_posts'],
		]);
	}

	/** GET aq/v1/more-posts — a page of post-card HTML + paging metadata. */
	public static function rest_more_posts(WP_REST_Request $req) {
		$offset = max(0, (int) $req->get_param('offset'));
		$number = (int) $req->get_param('number');
		if ($number < 1 || $number > 24) {
			$number = self::BATCH;
		}
		$q = new WP_Query([
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $number,
			'offset'              => $offset,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		]);
		$html = '';
		if (class_exists('AQ_Renderer')) {
			foreach ($q->posts as $p) {
				ob_start();
				AQ_Renderer::part('post-card', ['pid' => $p->ID]);
				$html .= (string) ob_get_clean();
			}
		}
		wp_reset_postdata();

		$total = (int) wp_count_posts('post')->publish;
		$next  = $offset + count($q->posts);
		return new WP_REST_Response([
			'html'  => $html,
			'next'  => $next,
			'more'  => ($next < $total) && count($q->posts) > 0,
			'total' => $total,
		], 200);
	}

	/**
	 * Sentinel markup the footer script converts into an infinite-scroll loader.
	 *
	 * @param int    $next          Absolute post offset to request first (i.e. how
	 *                              many posts are already on the page).
	 * @param string $grid_selector CSS selector of the grid to append cards into.
	 * @param int    $batch         Posts per fetch.
	 */
	public static function sentinel(int $next, string $grid_selector, int $batch = self::BATCH): string {
		self::$active = true;
		$endpoint = esc_url(rest_url('aq/v1/more-posts'));
		return '<div class="aq-more mt-10 flex justify-center" data-aq-more'
			. ' data-endpoint="' . esc_attr($endpoint) . '"'
			. ' data-next="' . (int) $next . '"'
			. ' data-batch="' . (int) $batch . '"'
			. ' data-grid="' . esc_attr($grid_selector) . '">'
			. '<span class="aq-more__spinner inline-block h-7 w-7 animate-spin rounded-full border-2 border-brand-200 border-t-accent-600" aria-hidden="true"></span>'
			. '<span class="sr-only">' . esc_html__('Loading more articles…', 'aq-core') . '</span>'
			. '</div>';
	}

	/** Print the generic infinite-scroll script once, only if a feed is present. */
	public static function print_script(): void {
		if (!self::$active) {
			return;
		}
		?>
<style id="aq-blog-feed-css">/* self-contained so the loader works on any theme, Tailwind or not */
.aq-more{display:flex;justify-content:center;margin-top:2.5rem}
.aq-more__spinner{display:inline-block;width:1.75rem;height:1.75rem;border-radius:9999px;border:2px solid rgba(0,0,0,.15);border-top-color:currentColor;animation:aq-more-spin .7s linear infinite}
@keyframes aq-more-spin{to{transform:rotate(360deg)}}
</style>
<script id="aq-blog-feed-js">/* AQ blog infinite scroll */
(function () {
	var nodes = document.querySelectorAll('[data-aq-more]');
	if (!nodes.length || !('IntersectionObserver' in window)) {
		nodes.forEach(function (n) { n.remove(); });
		return;
	}
	nodes.forEach(function (s) {
		var grid = document.querySelector(s.getAttribute('data-grid'));
		if (!grid) { s.remove(); return; }
		var loading = false, done = false;
		var next  = parseInt(s.getAttribute('data-next'), 10) || 0;
		var batch = parseInt(s.getAttribute('data-batch'), 10) || 9;
		var endpoint = s.getAttribute('data-endpoint');
		function finish() { done = true; io.disconnect(); s.remove(); }
		function load() {
			if (loading || done) { return; }
			loading = true;
			var url = endpoint + (endpoint.indexOf('?') > -1 ? '&' : '?') + 'offset=' + next + '&number=' + batch;
			fetch(url, { headers: { 'Accept': 'application/json' } })
				.then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
				.then(function (d) {
					if (d && d.html) {
						var t = document.createElement('div');
						t.innerHTML = d.html;
						while (t.firstElementChild) { grid.appendChild(t.firstElementChild); }
					}
					next = (d && typeof d.next === 'number') ? d.next : next + batch;
					loading = false;
					if (!d || !d.more) { finish(); }
				})
				.catch(function () { finish(); });
		}
		var io = new IntersectionObserver(function (entries) {
			if (entries.some(function (e) { return e.isIntersecting; })) { load(); }
		}, { rootMargin: '600px 0px' });
		io.observe(s);
	});
})();
</script>
		<?php
	}
}
