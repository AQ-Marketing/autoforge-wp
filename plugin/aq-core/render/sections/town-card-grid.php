<?php
/** Town card grid — directory of linked .card cards with a county eyebrow.
 *  Used by the service-area index: the "Most-Inspected Towns" grid (text-xl
 *  headings + "View Town Profile" link) and the "All Towns We Serve" grid
 *  (text-base headings, line-clamped body, no link row). Distinct from
 *  link_card_grid (which has aria-labels + a "Learn More" row, no eyebrow). */
$s     = $args['s'] ?? [];
$cards = (array) ($s['cards'] ?? []);
$bg    = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$grid  = (string) ($s['grid_class'] ?? 'grid sm:grid-cols-2 lg:grid-cols-3 gap-5');
$h3sz  = ($s['card_heading_size'] ?? 'base') === 'xl' ? 'text-xl' : 'text-base';
$clamp = !empty($s['line_clamp']) ? ' line-clamp-3' : '';
$cta   = (string) ($s['cta_label'] ?? '');
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
			<h2<?php echo ka_field_attr('heading'); ?> class="!mt-4">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr($grid); ?>">
			<?php foreach ($cards as $cardIdx => $card) : ?>
			<a<?php echo ka_field_attr('cards', $cardIdx); ?> href="<?php echo esc_url($card['href'] ?? '#'); ?>" class="card no-underline block group hover:border-accent-300 transition">
				<?php if (!empty($card['county'])) : ?>
				<p<?php echo ka_field_attr('county'); ?> class="text-xs uppercase tracking-wider text-accent-700 font-semibold"><?php echo esc_html($card['county']); ?></p>
				<?php endif; ?>
				<h3<?php echo ka_field_attr('title'); ?> class="!mt-1 <?php echo $h3sz; ?> group-hover:text-accent-600"><?php echo esc_html($card['title'] ?? ''); ?></h3>
				<p<?php echo ka_field_attr('body'); ?> class="text-sm text-brand-700 mt-2<?php echo $clamp; ?>"><?php echo wp_kses_post($card['body'] ?? ''); ?></p>
				<?php if ($cta !== '') : ?>
				<span<?php echo ka_field_attr('cta_label'); ?> class="inline-flex items-center gap-1.5 mt-4 text-sm font-semibold text-accent-500">
					<?php echo esc_html($cta); ?>
					<svg width="12" height="12" viewBox="0 0 448 512" fill="#f9ab3d"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"></path></svg>
				</span>
				<?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
