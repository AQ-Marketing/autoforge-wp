<?php
/** Divider — a static, on-brand horizontal rule for separating sections.
 *  Three editable controls (style / width / spacing) plus an optional accent
 *  color. No images, no repeaters, no JavaScript — pure CSS via Tailwind
 *  tokens. ka_field_attr() tags the rule so the block is selectable on canvas. */
$s = $args['s'] ?? [];

$style   = ($s['style'] ?? 'solid') === 'dashed' ? 'border-dashed' : 'border-solid';
$accent  = !empty($s['accent']);
$color   = $accent ? 'border-accent-500' : 'border-brand-200';

$spacing_choice = $s['spacing'] ?? 'normal';
$spacing_map = [
	'compact'  => 'my-6',
	'normal'   => 'my-10 md:my-12',
	'spacious' => 'my-16 md:my-20',
];
$spacing = $spacing_map[$spacing_choice] ?? $spacing_map['normal'];

$narrow = ($s['width'] ?? 'full') === 'narrow';
// Narrow rules are short and centered; accent narrow rules read a touch thicker.
if ($narrow) {
	$width_cls  = 'w-full max-w-xs mx-auto';
	$weight_cls = $accent ? 'border-t-2' : 'border-t';
} else {
	$width_cls  = 'w-full';
	$weight_cls = 'border-t';
}
?>
<section class="container-edge container-edge--wide <?php echo esc_attr($spacing); ?>">
	<hr<?php echo ka_field_attr('style'); ?> class="<?php echo esc_attr($width_cls . ' ' . $weight_cls . ' ' . $style . ' ' . $color); ?> border-0" />
</section>
