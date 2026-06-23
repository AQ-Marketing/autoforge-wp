<?php
/**
 * Post feed — the Resources index, redesigned as an editorial blog landing:
 *   1. Full-bleed hero (library image + navy overlay): breadcrumb, eyebrow,
 *      two-line H1 (white + gold sub), intro.
 *   2. Featured lead article (newest post) as a large split card.
 *   3. Responsive image-card grid of the remaining posts.
 * Cards come from a live WP_Query, so new posts appear automatically. Every
 * editable field is tagged with ka_field_attr() for the visual builder.
 */
$s       = $args['s'] ?? [];
$eyebrow = $s['eyebrow'] ?? 'Resources';
$heading = $s['heading'] ?? 'Resources & Articles';
$subhead = $s['subheading'] ?? '';
$intro   = $s['intro'] ?? '';
$limit   = (int) ($s['limit'] ?? 24);
$image   = $s['image'] ?? null;

$feed = new WP_Query([
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => $limit > 0 ? $limit : 24,
	'ignore_sticky_posts' => true,
	'no_found_rows'       => true,
]);

$posts = $feed->posts;
$lead  = $posts ? array_shift($posts) : null;
wp_reset_postdata();
?>
<section class="relative overflow-hidden bg-brand-900 text-white">
	<?php if ($image) : ?>
	<?php echo ka_picture_field($image, [
		'size'          => 'ka-1280',
		'sizes'         => '100vw',
		'class'         => 'absolute inset-0 h-full w-full object-cover',
		'loading'       => 'eager',
		'fetchpriority' => 'high',
	]); ?>
	<?php endif; ?>
	<div class="absolute inset-0 bg-gradient-to-br from-brand-900/95 via-brand-900/80 to-brand-900/50"></div>
	<div class="relative container-edge container-edge--wide pb-12 pt-6 md:pb-16 md:pt-8 lg:pb-20">
		<nav aria-label="Breadcrumb" class="text-sm text-white/70">
			<ol class="flex flex-wrap items-center gap-1.5">
				<li class="flex items-center gap-1.5"><a href="/" class="text-white/70 no-underline hover:text-white hover:underline"><?php echo esc_html(aq_site('labels.homeLabel') ?: 'Home'); ?></a></li>
				<li class="flex items-center gap-1.5"><span aria-hidden="true" class="text-white/40">/</span><span aria-current="page" class="font-medium text-white"><?php echo esc_html(aq_site('blog.label') ?: 'Resources'); ?></span></li>
			</ol>
		</nav>
		<div class="mt-8 max-w-[820px] md:mt-12">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow"><?php echo esc_html($eyebrow); ?></span>
			<h1 class="!mt-5 !mb-0 font-serif font-bold leading-[1.15] text-[26px] sm:text-[36px] lg:text-[46px]">
				<span<?php echo ka_field_attr('heading'); ?> class="block text-white"><?php echo esc_html($heading); ?></span>
				<?php if ($subhead !== '') : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="mt-1 block h1-sub"><?php echo esc_html($subhead); ?></span>
				<?php endif; ?>
			</h1>
			<?php if ($intro !== '') : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="mt-5 max-w-[760px] text-lg leading-relaxed text-on-dark"><?php echo wp_kses_post($intro); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>

<?php if ($lead) : ?>
<section class="bg-white py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<?php AQ_Renderer::part('post-card', ['pid' => $lead->ID, 'featured' => true, 'eager' => true]); ?>
	</div>
</section>
<?php endif; ?>

<?php if ($posts) : ?>
<section class="bg-brand-50 py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="mb-8 flex items-end justify-between gap-4 md:mb-10">
			<h2 class="!mt-0 text-2xl text-brand-800 md:text-3xl"><?php echo esc_html(aq_site('blog.moreHeading') ?: 'More articles'); ?></h2>
			<span class="hidden h-px flex-1 bg-brand-200 sm:block"></span>
		</div>
		<div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 lg:gap-8">
			<?php foreach ($posts as $p) : ?>
			<?php AQ_Renderer::part('post-card', ['pid' => $p->ID]); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php AQ_Renderer::part('post-cta'); ?>
