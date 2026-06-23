<?php
/**
 * Reusable blog post card. Shared by the Resources index grid (post-feed.php)
 * and the related-articles block (single.php). Two variants:
 *   featured = true  → large 2-up split card (image + text), used for the lead post
 *   featured = false → vertical image card for the grid
 *
 * Args: ['pid' => int, 'featured' => bool, 'eager' => bool]
 * Each card is a single <a> so the whole card is one click target.
 */
$pid      = (int) ($args['pid'] ?? get_the_ID());
$featured = !empty($args['featured']);
$eager    = !empty($args['eager']);

$url     = get_permalink($pid);
$title   = get_the_title($pid);
$excerpt = get_the_excerpt($pid);
$date    = get_the_date('M j, Y', $pid);
$cats    = get_the_category($pid);
$cat     = $cats ? $cats[0]->name : '';
$thumb   = get_post_thumbnail_id($pid);

$read_more = aq_site('blog.readMore') ?: 'Read article';
$feat_label = aq_site('blog.featuredLabel') ?: 'Latest';

$arrow = '<svg class="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m0 0l-5-5m5 5l-5 5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>';

// Image (or an on-brand gradient fallback when a post has no featured image yet).
$img_class = 'h-full w-full object-cover transition-transform duration-[600ms] ease-out group-hover:scale-[1.04]';
if ($thumb) {
	$img = ka_picture($thumb, [
		'size'          => $featured ? 'ka-1280' : 'ka-768',
		'sizes'         => $featured
			? '(min-width: 1024px) 50vw, 100vw'
			: '(min-width: 1024px) 360px, (min-width: 640px) 50vw, 100vw',
		'class'         => $img_class,
		'loading'       => $eager ? 'eager' : 'lazy',
		'fetchpriority' => $eager ? 'high' : '',
	]);
} else {
	$img = '<div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-brand-800 to-brand-900 text-accent-500">'
		. '<svg class="h-12 w-12 opacity-80" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5M5 10v9h14v-9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
		. '</div>';
}

$cat_pill = $cat !== ''
	? '<span class="absolute left-4 top-4 inline-flex items-center rounded-full bg-brand-900/80 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-white backdrop-blur-sm">'
		. ($featured ? esc_html($feat_label) . ' &middot; ' : '') . esc_html($cat) . '</span>'
	: '';
?>
<?php if ($featured) : ?>
<a href="<?php echo esc_url($url); ?>" class="group grid overflow-hidden rounded-2xl border border-brand-100 bg-white shadow-sm no-underline transition-shadow duration-300 hover:shadow-xl lg:grid-cols-2">
	<div class="relative aspect-[16/10] overflow-hidden bg-brand-100 lg:aspect-auto lg:min-h-[440px]">
		<?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo $cat_pill; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<div class="flex flex-col justify-center p-7 md:p-10 lg:p-12">
		<p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-500"><?php echo esc_html($date); ?></p>
		<h2 class="!mt-3 text-2xl text-brand-800 transition-colors duration-200 group-hover:text-accent-700 md:text-3xl"><?php echo esc_html($title); ?></h2>
		<p class="mt-4 text-base leading-relaxed text-brand-700 line-clamp-3"><?php echo esc_html($excerpt); ?></p>
		<span class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-accent-700"><?php echo esc_html($read_more); ?> <?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	</div>
</a>
<?php else : ?>
<a href="<?php echo esc_url($url); ?>" class="group flex flex-col overflow-hidden rounded-xl border border-brand-100 bg-white shadow-sm no-underline transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg">
	<div class="relative aspect-[16/10] overflow-hidden bg-brand-100">
		<?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo $cat_pill; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<div class="flex flex-1 flex-col p-6">
		<p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-500"><?php echo esc_html($date); ?></p>
		<h3 class="!mt-2 text-lg text-brand-800 transition-colors duration-200 group-hover:text-accent-700"><?php echo esc_html($title); ?></h3>
		<p class="mt-3 text-sm leading-relaxed text-brand-700 line-clamp-3"><?php echo esc_html($excerpt); ?></p>
		<span class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-accent-700"><?php echo esc_html($read_more); ?> <?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	</div>
</a>
<?php endif; ?>
