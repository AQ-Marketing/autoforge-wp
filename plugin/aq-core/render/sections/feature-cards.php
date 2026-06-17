<?php
/** Feature cards — centered header (eyebrow / H2 / intro) above a grid of cards
 *  whose inner markup is stored verbatim. Used for heterogeneous one-off card
 *  rows (about "Service Area" contact cards, sample-reports download cards):
 *  each card keeps its own wrapper class + freeform body so a single section
 *  type reproduces several bespoke layouts pixel-for-pixel. The card body is a
 *  raw echo sink (may contain inline <svg>) — keep it code-mode-only / AI-blocked
 *  like raw_html and dark_card_grid.icon_svg. */
$s     = $args['s'] ?? [];
$cards = (array) ($s['cards'] ?? []);
// section_class takes precedence; fall back to the legacy bg + standard padding.
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
			<div<?php echo ka_field_attr('cards', $cardIdx); ?> class="<?php echo esc_attr($card['wrapper_class'] ?? 'bg-brand-50 rounded-lg p-5 text-center'); ?>"><?php echo $card['html'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — raw card markup sink (inline SVG), code-mode-only ?></div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
