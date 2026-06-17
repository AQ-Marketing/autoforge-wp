<?php
/** CTA — generic call-to-action band. Optional eyebrow / heading / subheading /
 *  body above a row of buttons (repeater: label / href / style). Background is
 *  selectable (white | brand-50 | brand-900); on the navy background the text
 *  and secondary button flip to light/on-dark variants. Alignment is center
 *  (default) or left. Distinct from cta_band (fixed schedule/call band) and
 *  final_cta (full-bleed image band). Static markup, no JS. */
$s    = $args['s'] ?? [];
// Require a non-empty label: a button rendered from an href alone would be an
// anchor with no accessible name (matches button_group's filter).
$btns = array_values(array_filter((array) ($s['buttons'] ?? []), fn($b) => is_array($b) && ($b['label'] ?? '') !== ''));

$bg = (string) ($s['bg'] ?? 'brand-50');
$dark = $bg === 'brand-900';
$bg_cls = $dark ? 'bg-brand-900' : ($bg === 'white' ? 'bg-white' : 'bg-brand-50');

$left = ($s['align'] ?? 'center') === 'left';
$wrap_align = $left ? '' : 'text-center';
$inner_max  = $left ? 'max-w-3xl' : 'max-w-3xl mx-auto';
$btn_row    = $left ? 'flex flex-col sm:flex-row flex-wrap gap-3 mt-8' : 'flex flex-col sm:flex-row flex-wrap justify-center gap-3 mt-8';

// Heading gap only when an eyebrow sits above it (parity with other headers).
$heading_mt  = !empty($s['eyebrow']) ? '!mt-4' : '!mt-0';
$heading_cls = trim(($dark ? 'text-white ' : '') . $heading_mt);
$body_cls    = $dark ? 'text-brand-100 mt-4' : 'text-brand-700 mt-4';
?>
<section class="<?php echo esc_attr($bg_cls); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="<?php echo esc_attr(trim($inner_max . ' ' . $wrap_align)); ?>">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo esc_attr($heading_cls); ?>">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($s['body'])) : ?>
			<p<?php echo ka_field_attr('body'); ?> class="<?php echo esc_attr($body_cls); ?>"><?php echo wp_kses_post($s['body']); ?></p>
			<?php endif; ?>
			<?php if (!empty($btns)) : ?>
			<div class="<?php echo esc_attr($btn_row); ?>">
				<?php foreach ($btns as $btnIdx => $btn) :
					$is_secondary = ($btn['style'] ?? 'primary') === 'secondary';
					if ($is_secondary) {
						$btn_cls = $dark ? 'btn-outline-light' : 'btn-secondary';
					} else {
						$btn_cls = 'btn-primary';
					}
					$btn_cls .= ' text-xs sm:text-sm uppercase tracking-wide sm:tracking-wider';
					if (!$left) { $btn_cls .= ' w-full sm:w-auto'; }
				?>
				<a<?php echo ka_field_attr('buttons', $btnIdx); ?> href="<?php echo esc_url($btn['href'] ?? '#'); ?>" class="<?php echo esc_attr($btn_cls); ?>"><?php echo esc_html($btn['label'] ?? ''); ?></a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</div>
</section>
