<?php
/** Media hero — full-bleed library image + (inline-style) overlay + verbatim
 *  content body. For the bespoke contact / thank-you heroes whose overlay tint,
 *  subheading, and intro use inline styles instead of the theme's hero classes
 *  (city_hero). The image goes through the media library via ka_picture_field
 *  (so no hard-coded /uploads URL); the content body is a raw echo sink that
 *  keeps the inline styles, checkmark badge, and footnote — code-mode-only. */
$s = $args['s'] ?? [];
?>
<section class="relative bg-brand-900 text-white overflow-hidden">
	<?php echo ka_picture_field($s['image'] ?? null, [
		'class' => $s['image_class'] ?? 'absolute inset-0 w-full h-full object-cover',
		'loading' => 'eager',
		'fetchpriority' => 'high',
		'sizes' => '100vw',
	]); ?>
	<div class="absolute inset-0<?php echo !empty($s['overlay_class']) ? ' ' . esc_attr($s['overlay_class']) : ''; ?>"<?php echo !empty($s['overlay_style']) ? ' style="' . esc_attr($s['overlay_style']) . '"' : ''; ?>></div>
	<div<?php echo ka_field_attr('body'); ?> class="<?php echo esc_attr($s['content_class'] ?? 'relative container-edge container-edge--wide py-12 md:py-[72px] lg:py-[100px]'); ?>"><?php echo $s['body'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — raw hero content sink ?></div>
</section>
