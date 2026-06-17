<?php
/** Step cards — same centered header + grid as feature_cards, but each card is a
 *  STRUCTURED numbered step (number / title / text) rather than a raw-HTML sink.
 *  Transliterates the original feature_cards markup token-for-token so pixel
 *  parity holds, while making the body editable as three plain fields.
 *  Used by /thank-you/ ("What happens next"). */
$s     = $args['s'] ?? [];
$cards = (array) ($s['cards'] ?? []);
$bg          = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$section_cls = !empty($s['section_class']) ? (string) $s['section_class'] : ($bg . ' py-12 md:py-16 lg:py-20');
$mb          = ($s['header_mb'] ?? 'mb-12') === 'mb-10' ? 'mb-10' : 'mb-12';
$grid        = (string) ($s['grid_class'] ?? 'grid sm:grid-cols-3 gap-6 max-w-4xl mx-auto');
?>
<section class="<?php echo esc_attr($section_cls); ?>">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center <?php echo $mb; ?>">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="<?php echo esc_attr($s['eyebrow_class'] ?? 'pill-eyebrow mb-6'); ?>"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo esc_attr($s['h2_class'] ?? '!mt-4'); ?>">
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
			<div<?php echo ka_field_attr('cards', $cardIdx); ?> class="<?php echo esc_attr($card['wrapper_class'] ?? 'bg-white rounded-lg p-6 text-center'); ?>">
				<?php if (isset($card['number']) && $card['number'] !== '') : ?><p class="text-3xl font-serif font-bold text-accent-500"><?php echo esc_html($card['number']); ?></p><?php endif; ?>
				<?php if (!empty($card['title'])) : ?><h3 class="!mt-2 text-lg font-bold text-brand-900"><?php echo esc_html($card['title']); ?></h3><?php endif; ?>
				<?php if (!empty($card['text'])) : ?><p class="text-sm text-brand-700 mt-2"><?php echo wp_kses_post($card['text']); ?></p><?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
