<?php
/** Spacer — a structured, editable vertical-spacing block. No text, no imagery,
 *  no JavaScript. A size select maps to a fixed set of on-brand responsive height
 *  utilities; an optional thin centered divider rule (brand-100) can separate the
 *  sections above and below; an optional background tint lets the gap blend into
 *  either neighbor. New building block for future pages. */
$s = $args['s'] ?? [];

$size = (string) ($s['size'] ?? 'md');
$heights = [
	'sm' => 'h-8 md:h-10',
	'md' => 'h-12 md:h-16',
	'lg' => 'h-16 md:h-24',
	'xl' => 'h-24 md:h-36',
];
$height_cls = $heights[$size] ?? $heights['md'];

$bg      = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$divider = !empty($s['divider']);
?>
<section<?php echo ka_field_attr('size'); ?> class="<?php echo esc_attr($bg); ?>" aria-hidden="true">
	<?php if ($divider) : ?>
	<div class="<?php echo esc_attr($height_cls); ?> flex items-center">
		<hr class="w-full max-w-content mx-auto border-0 border-t border-brand-100" />
	</div>
	<?php else : ?>
	<div class="<?php echo esc_attr($height_cls); ?>"></div>
	<?php endif; ?>
</section>
