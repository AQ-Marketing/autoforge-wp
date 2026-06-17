<?php
/** Text Block — a generic rich-text paragraph block for free-flow copy on
 *  future pages. Optional pill eyebrow + H2 heading above a single rich-text
 *  body, with configurable alignment, max-width preset, background, and
 *  vertical padding. Body renders in the on-brand `.prose-content` styles.
 *  Static — no JavaScript. The heading gap (!mt-4) is applied only when an
 *  eyebrow sits above it. */
$s = $args['s'] ?? [];

$bg  = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$pad = $s['pad'] ?? 'normal';
$pad_cls = $pad === 'compact'
	? 'py-8 md:py-10'
	: ($pad === 'spacious' ? 'py-16 md:py-24 lg:py-28' : 'py-12 md:py-16 lg:py-20');

$align = $s['align'] ?? 'left';
switch ($align) {
	case 'center':
		$align_cls = 'text-center';
		$mx_cls    = 'mx-auto';
		break;
	case 'right':
		$align_cls = 'text-right';
		$mx_cls    = 'ml-auto';
		break;
	default:
		$align_cls = 'text-left';
		$mx_cls    = '';
		break;
}

$max_width = $s['max_width'] ?? 'prose';
switch ($max_width) {
	case 'narrow':
		$mw_cls = 'max-w-2xl';
		break;
	case 'wide':
		$mw_cls = 'max-w-4xl';
		break;
	case 'full':
		$mw_cls = 'max-w-none';
		break;
	default:
		$mw_cls = 'max-w-prose';
		break;
}
$wrap_cls = trim($mw_cls . ' ' . $mx_cls . ' ' . $align_cls);

$head_mt = !empty($s['eyebrow']) ? '!mt-4' : '!mt-0';
?>
<section class="<?php echo esc_attr($bg . ' ' . $pad_cls); ?>">
	<div class="container-edge container-edge--wide">
		<div class="<?php echo esc_attr($wrap_cls); ?>">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<?php if (!empty($s['heading'])) : ?>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo esc_attr($head_mt); ?>"><?php echo esc_html($s['heading']); ?></h2>
			<?php endif; ?>
			<?php if (!empty($s['body'])) : ?>
			<div<?php echo ka_field_attr('body'); ?> class="prose-content <?php echo (!empty($s['eyebrow']) || !empty($s['heading'])) ? 'mt-6' : ''; ?>"><?php echo wp_kses_post($s['body']); ?></div>
			<?php endif; ?>
		</div>
	</div>
</section>
