<?php
/** Timeline — a static vertical timeline: centered header (eyebrow / H2 + gold
 *  sub / intro) above a single-column list of items. Each item is a dated entry
 *  (date/label pill, bold title, rich-text body) hung off a left accent rail
 *  with a gold dot marker. Pure CSS (border rail + absolutely-positioned dots);
 *  no JavaScript. On-brand tokens only (brand-* / accent-* + component classes). */
$s     = $args['s'] ?? [];
$items = (array) ($s['items'] ?? []);
$bg    = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$h2_mt = ($s['h2_mt'] ?? 'mt-0') === 'mt-4' ? '!mt-4' : '!mt-0';
// Dot ring matches the section background so the rail reads as "behind" the dot.
$dot_ring = $bg === 'bg-brand-50' ? 'ring-brand-50' : 'ring-white';
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo esc_attr($h2_mt); ?>">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<?php if (!empty($items)) : ?>
		<ol class="relative max-w-2xl mx-auto border-l-2 border-brand-100 pl-8 sm:pl-10 space-y-10">
			<?php foreach ($items as $itemIdx => $item) : ?>
			<li<?php echo ka_field_attr('items', $itemIdx); ?> class="relative">
				<span class="absolute -left-[2.55rem] sm:-left-[3.05rem] top-1.5 w-4 h-4 rounded-full bg-accent-500 ring-4 <?php echo esc_attr($dot_ring); ?>" aria-hidden="true"></span>
				<?php if (!empty($item['date'])) : ?>
				<p<?php echo ka_field_attr('date'); ?> class="text-xs font-semibold uppercase tracking-wider text-accent-600 mb-1"><?php echo esc_html($item['date']); ?></p>
				<?php endif; ?>
				<?php if (!empty($item['title'])) : ?>
				<h3<?php echo ka_field_attr('title'); ?> class="!mt-0 text-lg font-bold text-brand-900"><?php echo esc_html($item['title']); ?></h3>
				<?php endif; ?>
				<?php if (!empty($item['body'])) : ?>
				<div<?php echo ka_field_attr('body'); ?> class="prose-content mt-2"><?php echo wp_kses_post($item['body']); ?></div>
				<?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ol>
		<?php endif; ?>
	</div>
</section>
