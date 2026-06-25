<?php
/**
 * Generic fallback template (plugin-owned) — 404, search, archives, home. Every
 * real route is a page (page.php) or post (single.php). A 404 renders a proper
 * branded "page not found" screen (NOT the loop, which can pull stray content);
 * other fallbacks render the loop content when present.
 */

if (!defined('ABSPATH')) {
	exit;
}

if (is_404()) {
	// The 404 has no queried object, so give it a real document title.
	add_filter('pre_get_document_title', static function () {
		$brand = function_exists('aq_site') ? (string) aq_site('shortName') : get_bloginfo('name');
		return trim('Page Not Found' . ($brand !== '' ? ' | ' . $brand : ''));
	}, 99);
}

AQ_Renderer::head_open();

if (is_404()) : ?>
	<section class="container-edge py-20 text-center">
		<p class="text-accent-700 font-semibold uppercase tracking-wider text-sm">404 &mdash; Page not found</p>
		<h1 class="font-serif font-bold text-4xl text-brand-900 mt-3">We couldn&rsquo;t find that page</h1>
		<p class="max-w-xl mx-auto mt-4 text-brand-600">The page you were looking for may have moved or no longer exists. Try one of these instead:</p>
		<div class="flex flex-col sm:flex-row gap-3 justify-center mt-8">
			<a href="/" class="btn-primary py-3 px-6">Back to home</a>
			<a href="/contact/" class="font-semibold text-brand-800 hover:text-accent-700 no-underline py-3 px-6">Contact us</a>
		</div>
		<nav class="flex flex-wrap gap-x-6 gap-y-2 justify-center mt-10 text-sm" aria-label="Helpful links">
			<a href="/services/" class="text-brand-700 hover:text-accent-700 no-underline">Services</a>
			<a href="/testing-and-specialty/" class="text-brand-700 hover:text-accent-700 no-underline">Specialty Testing</a>
			<a href="/service-area/" class="text-brand-700 hover:text-accent-700 no-underline">Service Area</a>
			<a href="/pricing/" class="text-brand-700 hover:text-accent-700 no-underline">Pricing</a>
			<a href="/blog/" class="text-brand-700 hover:text-accent-700 no-underline">Blog</a>
		</nav>
	</section>
<?php elseif (have_posts()) :
	while (have_posts()) {
		the_post();
		the_content();
	}
endif;

AQ_Renderer::body_close();
