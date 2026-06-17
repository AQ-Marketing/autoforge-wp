<?php
/** Pricing Table — centered header (eyebrow / H2 / subheading / intro) above a
 *  responsive 2/3/4-column grid of plan cards. Each card: name, large price +
 *  period suffix, a rich-text feature list (authored as a <ul>), and a CTA
 *  button. A per-plan "featured" toggle lifts the card with an accent ring and a
 *  copper badge (label from featured_label). Static markup only — no JS. */
$s     = $args['s'] ?? [];
$plans = (array) ($s['plans'] ?? []);

$bg   = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$mb   = ($s['header_mb'] ?? 'mb-12') === 'mb-10' ? 'mb-10' : 'mb-12';
$cols = (string) ($s['cols'] ?? '3');
$col_map = [
	'2' => 'grid gap-6 sm:grid-cols-2 max-w-3xl mx-auto',
	'3' => 'grid gap-6 sm:grid-cols-2 lg:grid-cols-3 max-w-5xl mx-auto',
	'4' => 'grid gap-6 sm:grid-cols-2 lg:grid-cols-4 max-w-6xl mx-auto',
];
$grid  = $col_map[$cols] ?? $col_map['3'];
$badge = (string) ($s['featured_label'] ?? 'Most Popular');
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center <?php echo esc_attr($mb); ?>">
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
			<?php foreach ($plans as $planIdx => $plan) :
				$featured  = !empty($plan['featured']);
				$base_card = $featured
					? 'relative flex flex-col bg-white rounded-lg p-8 ring-2 ring-accent-500 shadow-lg'
					: 'relative flex flex-col card p-8';
				$card_cls  = !empty($plan['wrapper_class']) ? (string) $plan['wrapper_class'] : $base_card;
			?>
			<div<?php echo ka_field_attr('plans', $planIdx); ?> class="<?php echo esc_attr($card_cls); ?>">
				<?php if ($featured && $badge !== '') : ?>
				<span class="absolute -top-3 left-1/2 -translate-x-1/2 inline-block bg-accent-500 text-brand-900 text-xs font-bold uppercase tracking-wider px-4 py-1 rounded-full shadow-sm"><?php echo esc_html($badge); ?></span>
				<?php endif; ?>
				<?php if (!empty($plan['name'])) : ?>
				<h3 class="text-lg font-bold text-brand-900 text-center"><?php echo esc_html($plan['name']); ?></h3>
				<?php endif; ?>
				<?php if (isset($plan['price']) && $plan['price'] !== '') : ?>
				<p class="mt-4 text-center">
					<span class="text-4xl font-serif font-bold text-brand-900"><?php echo esc_html($plan['price']); ?></span>
					<?php if (!empty($plan['period'])) : ?>
					<span class="text-sm text-brand-500 ml-1"><?php echo esc_html($plan['period']); ?></span>
					<?php endif; ?>
				</p>
				<?php endif; ?>
				<?php if (!empty($plan['features'])) : ?>
				<div class="prose-content text-sm text-left mt-6 mb-2 flex-grow"><?php echo wp_kses_post($plan['features']); ?></div>
				<?php endif; ?>
				<?php if (!empty($plan['cta_href'])) : ?>
				<p class="mt-6 text-center">
					<a href="<?php echo esc_url($plan['cta_href']); ?>" class="<?php echo $featured ? 'btn-primary' : 'btn-outline'; ?> w-full text-xs sm:text-sm uppercase tracking-wide sm:tracking-wider"><?php echo esc_html($plan['cta_label'] ?? 'Get Started'); ?></a>
				</p>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
