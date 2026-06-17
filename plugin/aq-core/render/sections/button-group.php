<?php
/** Button group — a standalone, alignable row of one or more on-brand buttons,
 *  with an optional eyebrow / heading / intro above them. Each button maps its
 *  `style` choice to the theme's component button classes:
 *    primary   -> .btn-primary   (gold)
 *    secondary -> .btn-secondary (navy)
 *    ghost     -> .btn-outline    (outlined)
 *  Pure static markup — the row is a flex container (stacked full-width on
 *  mobile, inline wrapping row from sm:), no JavaScript. Mirrors the cta_band /
 *  hero CTA button idioms for a freeform call-to-action block on future pages.
 *  The eyebrow gap (!mt-4 on the H2) is applied only when an eyebrow is set, so
 *  a heading with no eyebrow does not gain an unintended top gap. */
$s       = $args['s'] ?? [];
$buttons = array_values(array_filter((array) ($s['buttons'] ?? []), fn($b) => is_array($b) && ($b['label'] ?? '') !== ''));

$align = (string) ($s['align'] ?? 'center');
$align_block = $align === 'left' ? 'mr-auto' : ($align === 'right' ? 'ml-auto' : 'mx-auto');
$align_text  = $align === 'left' ? 'text-left' : ($align === 'right' ? 'text-right' : 'text-center');
$align_btns  = $align === 'left' ? 'sm:justify-start' : ($align === 'right' ? 'sm:justify-end' : 'sm:justify-center');

$bg = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';

$h2_mt = !empty($s['eyebrow']) ? '!mt-4' : '!mt-0';

$style_map = [
	'primary'   => 'btn-primary',
	'secondary' => 'btn-secondary',
	'ghost'     => 'btn-outline',
];
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl <?php echo esc_attr($align_block . ' ' . $align_text); ?>">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo esc_html($s['eyebrow']); ?></span>
			<?php endif; ?>
			<?php if (!empty($s['heading'])) : ?>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo esc_attr($h2_mt); ?>"><?php echo esc_html($s['heading']); ?></h2>
			<?php endif; ?>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
			<?php if (!empty($buttons)) : ?>
			<div class="mt-8 flex flex-col sm:flex-row flex-wrap gap-3 <?php echo esc_attr($align_btns); ?>">
				<?php foreach ($buttons as $btnIdx => $btn) :
					$cls = $style_map[$btn['style'] ?? 'primary'] ?? 'btn-primary'; ?>
				<a<?php echo ka_field_attr('buttons', $btnIdx); ?> href="<?php echo esc_url($btn['href'] ?? '#'); ?>" class="<?php echo esc_attr($cls); ?>"><?php echo esc_html($btn['label'] ?? ''); ?></a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</div>
</section>
