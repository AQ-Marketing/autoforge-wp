<?php
/** FAQ accordion — bg-brand-600. FAQPage JSON-LD is emitted by aq-core. */
$s = $args['s'] ?? [];
$h2mt = ($s['h2_mt'] ?? 'mt-0') === 'mt-4' ? '!mt-4' : '!mt-0';
?>
<section class="bg-brand-600 text-white py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo $h2mt; ?> text-white">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block text-accent-300 mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
		</div>
		<div class="max-w-4xl mx-auto space-y-3">
			<?php foreach ((array) ($s['items'] ?? []) as $itemIdx => $item) : ?>
			<div<?php echo ka_field_attr('items', $itemIdx); ?> class="faq-item bg-brand-700/60 border border-white/10 rounded-md overflow-hidden" data-open="false">
				<button type="button" class="faq-toggle w-full flex items-center justify-between text-left px-5 py-4 text-white hover:bg-brand-700" aria-expanded="false">
					<span<?php echo ka_field_attr('q'); ?> class="faq-question"><?php echo esc_html($item['q'] ?? ''); ?></span>
					<span class="faq-icon text-accent-300 text-xl">+</span>
				</button>
				<div class="faq-content">
					<div class="faq-content-inner">
						<div<?php echo ka_field_attr('a'); ?> class="px-5 pb-5 text-sm text-white/90 leading-relaxed faq-answer"><?php echo wp_kses_post($item['a'] ?? ''); ?></div>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
