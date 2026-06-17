<?php
/** Trust — image left, text + gold-check list right. */
$s    = $args['s'] ?? [];
$bg   = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$pads = ['normal' => 'py-12 md:py-16 lg:py-20', 'compact' => 'py-8 md:py-12', 'spacious' => 'py-16 md:py-24 lg:py-28'];
$pad  = $pads[$s['pad'] ?? 'normal'] ?? $pads['normal'];
?>
<section class="<?php echo esc_attr($bg . ' ' . $pad); ?>">
	<div class="container-edge container-edge--wide">
		<div class="grid lg:grid-cols-[45fr_55fr] gap-10 lg:gap-[5%] items-center">
			<div<?php echo ka_field_attr('image'); ?> class="order-2 lg:order-1">
				<?php echo ka_picture_field($s['image'] ?? null, [
					'class' => 'w-full h-auto rounded-lg shadow-lg object-cover aspect-[4/3]',
				]); ?>
			</div>
			<div class="order-1 lg:order-2">
				<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
				<h2<?php echo ka_field_attr('heading'); ?> class="!mt-0">
					<?php echo esc_html($s['heading'] ?? ''); ?>
					<?php if (!empty($s['subheading'])) : ?>
					<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
					<?php endif; ?>
				</h2>
				<?php foreach ((array) ($s['paragraphs'] ?? []) as $i => $p) : ?>
				<p<?php echo ka_field_attr('paragraphs', $i); ?> class="text-brand-700 <?php echo $i === 0 ? 'mt-6' : 'mt-4'; ?>"><?php echo wp_kses_post($p['html'] ?? ''); ?></p>
				<?php endforeach; ?>
				<?php if (!empty($s['checklist'])) : ?>
				<ul class="mt-6 space-y-3 text-brand-700">
					<?php foreach ($s['checklist'] as $itemIdx => $item) : ?>
					<li<?php echo ka_field_attr('checklist', $itemIdx); ?> class="flex items-start gap-3">
						<span class="mt-1 inline-flex items-center justify-center w-5 h-5 rounded-full bg-accent-500 text-white flex-shrink-0">
							<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
						</span>
						<span<?php echo ka_field_attr('text'); ?>><?php echo wp_kses_post($item['text'] ?? ''); ?></span>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<?php if (!empty($s['cta_href']) && !empty($s['cta_label'])) : ?>
				<p class="mt-8">
					<a<?php echo ka_field_attr('cta_label'); ?> href="<?php echo esc_url($s['cta_href']); ?>" class="btn-primary w-full sm:w-auto text-xs sm:text-sm uppercase tracking-wide sm:tracking-wider"><?php echo esc_html($s['cta_label'] ?? ''); ?></a>
				</p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
