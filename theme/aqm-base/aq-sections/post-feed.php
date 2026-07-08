<?php
/**
 * THEME OVERRIDE (aqm-base) of the engine `post_feed` section.
 *
 * Renders ONLY the featured lead article + the post-card grid — NO hero, NO
 * breadcrumb, and NO trailing CTA. On this site the page's own `aqm_page_hero`
 * section supplies the hero (so the blog index matches every other page) and
 * `aqm_cta_band` supplies the closing CTA. Cards reuse the engine's shared
 * `post-card` render part, so styling stays identical to related-article cards.
 *
 * Paging: shows the latest post as a large featured card + 9 smaller grid cards
 * on first paint (10 posts total), then infinite-scrolls the rest 9 at a time.
 * The REST endpoint (aq/v1/more-posts) and the infinite-scroll script both live
 * in the engine (AQ_Blog_Feed, aq-core >= 0.3.4); this override only supplies the
 * AQM-specific chrome (.wrap width, no hero/CTA — the page's own aqm_page_hero
 * and aqm_cta_band sections provide those). If the plugin predates AQ_Blog_Feed
 * the grid degrades gracefully to its first 10 posts (no infinite scroll).
 *
 * Editable fields (same as the engine layout): intro (unused here), limit (unused
 * now that the feed pages through every post).
 */
if (!defined('ABSPATH')) {
	exit;
}

$INITIAL = 10;                 // posts visible on first paint (1 featured + 9 grid)
$BATCH   = 9;                  // posts loaded per infinite-scroll step (3 rows of 3)
$grid_first = $INITIAL - 1;    // grid cards on first paint (featured is the +1)

$feed = new WP_Query([
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => $INITIAL,
	'ignore_sticky_posts' => true,
	'no_found_rows'       => true,
]);
$posts = $feed->posts;
$lead  = $posts ? array_shift($posts) : null;
wp_reset_postdata();

if (!$lead) {
	return;
}

$total    = (int) wp_count_posts('post')->publish;
$has_more = $total > $INITIAL;
?>
<section class="bg-white py-12 md:py-16 lg:py-20">
	<div class="wrap">
		<?php AQ_Renderer::part('post-card', ['pid' => $lead->ID, 'featured' => true, 'eager' => true]); ?>
	</div>
</section>

<?php if ($posts) : ?>
<section class="bg-brand-50 py-12 md:py-16 lg:py-20">
	<div class="wrap">
		<div class="mb-8 flex items-end justify-between gap-4 md:mb-10">
			<h2 class="!mt-0 text-2xl text-brand-800 md:text-3xl"><?php echo esc_html(aq_site('blog.moreHeading') ?: 'More articles'); ?></h2>
			<span class="hidden h-px flex-1 bg-brand-200 sm:block"></span>
		</div>
		<div id="aq-post-grid" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 lg:gap-8">
			<?php foreach ($posts as $p) : ?>
			<?php AQ_Renderer::part('post-card', ['pid' => $p->ID]); ?>
			<?php endforeach; ?>
		</div>

		<?php if ($has_more && class_exists('AQ_Blog_Feed')) : ?>
		<?php echo AQ_Blog_Feed::sentinel($INITIAL, '#aq-post-grid', $BATCH); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts ?>
		<?php endif; ?>
	</div>
</section>
<?php endif; ?>
