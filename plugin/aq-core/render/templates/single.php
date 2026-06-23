<?php
/**
 * Single blog post (plugin-owned) — editorial article layout:
 *   navy (brand-900) hero (breadcrumb + category pill + H1 + meta) → showcased
 *   featured photo → 820px article body with an auto table-of-contents in a
 *   sticky right sidebar (inline box above the body on mobile, shown only when
 *   the post has 4+ H2 sections) → related articles → CTA.
 * Body lives in post_content; chrome is the template's job. External links in
 * the body open in a new tab via the the_content filter (render/helpers.php).
 * SEO meta + Article/BreadcrumbList JSON-LD are emitted by aq-core.
 *
 * Client data: byline author = aq_site('blog.author') (falls back to site name);
 * blog index label/base = aq_site('blog.label') / aq_site('blog.base').
 */

if (!defined('ABSPATH')) {
	exit;
}

$aq_author     = (function_exists('aq_site') ? (aq_site('blog.author') ?: aq_site('name')) : '') ?: get_bloginfo('name');
$aq_blog_label = (function_exists('aq_site') ? aq_site('blog.label') : '') ?: 'Resources';
$aq_blog_base  = (function_exists('aq_site') ? aq_site('blog.base') : '') ?: '/blog/';
$aq_home_label = (function_exists('aq_site') ? aq_site('labels.homeLabel') : '') ?: 'Home';
$aq_related_h  = (function_exists('aq_site') ? aq_site('blog.relatedHeading') : '') ?: 'Keep reading';
$aq_back_pfx   = 'Back to all';

AQ_Renderer::head_open();

while (have_posts()) :
	the_post();
	$pid   = get_the_ID();
	$cats  = get_the_category();
	$cat   = $cats ? $cats[0] : null;
	$mins  = ka_reading_time($pid);
	?>
	<article>
		<header class="bg-brand-900 text-white">
			<div class="container-edge container-edge--wide pt-4 pb-12 md:pb-16">
				<nav aria-label="Breadcrumb" class="text-sm text-white/60">
					<ol class="flex flex-wrap items-center gap-1.5">
						<li class="flex items-center gap-1.5"><a href="/" class="text-white/60 no-underline hover:text-white hover:underline"><?php echo esc_html($aq_home_label); ?></a></li>
						<li class="flex items-center gap-1.5"><span aria-hidden="true" class="text-white/30">/</span><a href="<?php echo esc_url($aq_blog_base); ?>" class="text-white/60 no-underline hover:text-white hover:underline"><?php echo esc_html($aq_blog_label); ?></a></li>
						<li class="flex items-center gap-1.5"><span aria-hidden="true" class="text-white/30">/</span><span aria-current="page" class="font-medium text-white"><?php the_title(); ?></span></li>
					</ol>
				</nav>
				<div class="mx-auto max-w-[820px] pt-8 text-center md:pt-10">
					<?php if ($cat) : ?>
					<a href="<?php echo esc_url(get_category_link($cat->term_id)); ?>" class="inline-flex items-center rounded-full bg-white/10 px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider text-accent-400 no-underline ring-1 ring-inset ring-white/15 transition hover:bg-white/15"><?php echo esc_html($cat->name); ?></a>
					<?php endif; ?>
					<h1 class="!mt-5 text-3xl leading-[1.15] text-white md:text-4xl lg:text-5xl"><?php the_title(); ?></h1>
					<div class="mt-5 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-sm text-white/60">
						<span><?php echo esc_html(get_the_date('F j, Y')); ?></span>
						<span aria-hidden="true" class="text-white/30">&middot;</span>
						<span><?php echo (int) $mins; ?> min read</span>
						<span aria-hidden="true" class="text-white/30">&middot;</span>
						<span>By <?php echo esc_html($aq_author); ?></span>
					</div>
				</div>
			</div>
		</header>

		<?php if (has_post_thumbnail()) : ?>
		<figure class="container-edge container-edge--wide mt-8 md:mt-10">
			<div class="mx-auto max-w-[1000px] overflow-hidden rounded-2xl shadow-lg">
				<?php echo ka_picture(get_post_thumbnail_id(), [
					'size'          => 'full',
					'sizes'         => '(min-width: 1024px) 1000px, 100vw',
					'class'         => 'aspect-[3/2] w-full object-cover',
					'loading'       => 'eager',
					'fetchpriority' => 'high',
				]); ?>
			</div>
		</figure>
		<?php endif; ?>

		<div class="container-edge container-edge--wide py-10 md:py-14">
			<?php
			// Render the body via the_content filters, then derive a jump-link TOC
			// (only when the article has 4+ H2 sections). Desktop = sticky right
			// sidebar; mobile = inline box above the body (source order: aside, main).
			list($aq_toc, $aq_body) = ka_article_toc(apply_filters('the_content', get_the_content()));
			?>
			<div class="ka-article-layout">
				<?php if ($aq_toc !== '') : ?>
				<aside class="ka-toc-aside"><?php echo $aq_toc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in ka_article_toc ?></aside>
				<?php endif; ?>
				<div class="ka-article-main">
					<div class="article-body mx-auto max-w-[820px]">
						<?php echo $aq_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content output ?>
					</div>
					<div class="mx-auto mt-10 max-w-[820px] border-t border-brand-100 pt-6">
						<a href="<?php echo esc_url($aq_blog_base); ?>" class="inline-flex items-center gap-2 text-sm font-semibold text-accent-700 no-underline hover:underline">
							<svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M16 10H4m0 0l5 5m-5-5l5-5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
							<?php echo esc_html($aq_back_pfx); ?> <?php echo esc_html(strtolower($aq_blog_label)); ?>
						</a>
					</div>
				</div>
			</div>
		</div>
	</article>

	<?php
	// Related articles — same category first, then top up with recent posts,
	// excluding the current one. Strong internal linking for SEO + discovery.
	$related_ids = [];
	if ($cat) {
		$related_ids = get_posts([
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 3,
			'post__not_in'        => [$pid],
			'category__in'        => [$cat->term_id],
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		]);
	}
	if (count($related_ids) < 3) {
		$fill = get_posts([
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 3 - count($related_ids),
			'post__not_in'        => array_merge([$pid], $related_ids),
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		]);
		$related_ids = array_merge($related_ids, $fill);
	}
	if ($related_ids) :
	?>
	<section class="bg-brand-50 py-12 md:py-16">
		<div class="container-edge container-edge--wide">
			<div class="mb-8 flex items-end justify-between gap-4 md:mb-10">
				<h2 class="!mt-0 text-2xl text-brand-800 md:text-3xl"><?php echo esc_html($aq_related_h); ?></h2>
				<a href="<?php echo esc_url($aq_blog_base); ?>" class="hidden text-sm font-semibold text-accent-700 no-underline hover:underline sm:inline"><?php echo esc_html(aq_site('labels.viewAll') ?: 'View all'); ?> <?php echo esc_html(strtolower($aq_blog_label)); ?> &rarr;</a>
			</div>
			<div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 lg:gap-8">
				<?php foreach ($related_ids as $rid) : ?>
				<?php AQ_Renderer::part('post-card', ['pid' => $rid]); ?>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php AQ_Renderer::part('post-cta'); ?>
	<?php
endwhile;

AQ_Renderer::body_close();
