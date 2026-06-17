<?php
/** Testimonials — bg-brand-50, 3-up 5-star quote cards. */
$s = $args['s'] ?? [];
$star = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
$h2mt = ($s['h2_mt'] ?? 'mt-0') === 'mt-4' ? '!mt-4' : '!mt-0';
$bg   = ($s['bg'] ?? 'brand-50') === 'white' ? 'white' : 'brand-50';
?>
<section class="bg-<?php echo $bg; ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo $h2mt; ?>"><?php echo esc_html($s['heading'] ?? ''); ?></h2>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<div class="grid md:grid-cols-3 gap-6">
			<?php foreach ((array) ($s['items'] ?? []) as $itemIdx => $item) : ?>
			<div<?php echo ka_field_attr('items', $itemIdx); ?> class="bg-white rounded-lg shadow-md border border-brand-100 p-6">
				<div class="flex gap-1 mb-4 text-accent-500">
					<?php echo str_repeat($star, 5); ?>
				</div>
				<blockquote<?php echo ka_field_attr('quote'); ?> class="text-brand-700 italic"><?php echo esc_html($item['quote'] ?? ''); ?></blockquote>
				<div class="mt-5">
					<p<?php echo ka_field_attr('name'); ?> class="font-semibold text-accent-700 font-poppins"><?php echo esc_html($item['name'] ?? ''); ?></p>
					<p<?php echo ka_field_attr('role'); ?> class="text-sm text-brand-600"><?php echo esc_html($item['role'] ?? ''); ?></p>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php if (!empty($s['cta_href'])) : ?>
		<div class="text-center mt-10">
			<a<?php echo ka_field_attr('cta_label'); ?> href="<?php echo esc_url($s['cta_href']); ?>" class="btn-primary text-xs sm:text-sm uppercase tracking-wide sm:tracking-wider"><?php echo esc_html($s['cta_label'] ?? ''); ?></a>
		</div>
		<?php endif; ?>
	</div>
</section>
