<?php
/** Image Block — a single media-library image inside a <figure>, with an
 *  optional caption, an optional link wrap, selectable alignment + max-width,
 *  a rounded-corners toggle, a drop-shadow toggle, and an optional fixed crop.
 *  A NEW building block for future pages (no Astro source to match). Fully
 *  static — NO JavaScript. On-brand: container-edge--wide, brand/accent tokens,
 *  rounded-lg / shadow-lg idioms, accent ring on linked images. */
$s = $args['s'] ?? [];

$image_id = $s['image'] ?? null;

// Section background + vertical padding (design group; first option = default = parity).
$bg  = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$pad = $s['pad'] ?? 'normal';
$pad_cls = $pad === 'compact' ? 'py-8 md:py-10' : ($pad === 'spacious' ? 'py-16 md:py-24 lg:py-28' : 'py-12 md:py-16 lg:py-20');

// Horizontal alignment of the capped image block.
$align = $s['align'] ?? 'center';
$align_cls = $align === 'left' ? 'mr-auto' : ($align === 'right' ? 'ml-auto' : 'mx-auto');

// Max width of the image block.
$mw = $s['max_width'] ?? 'lg';
$mw_cls = $mw === 'sm' ? 'max-w-md' : ($mw === 'md' ? 'max-w-2xl' : ($mw === 'full' ? 'max-w-none' : 'max-w-4xl'));

// Optional fixed crop ratio. When a ratio is set the image fills the box
// (object-cover) and the natural-height (h-auto) rule is dropped so the crop wins.
$aspect = $s['aspect'] ?? 'auto';
$aspect_map = ['16/9' => 'aspect-[16/9] object-cover', '4/3' => 'aspect-[4/3] object-cover', '1/1' => 'aspect-square object-cover', '3/4' => 'aspect-[3/4] object-cover'];
$aspect_cls = $aspect_map[$aspect] ?? '';
$size_cls = $aspect_cls !== '' ? 'w-full ' . $aspect_cls : 'w-full h-auto';

// Toggles. ACF true_false returns 1/0 (or absent); default-on for rounded.
$rounded_cls = (!isset($s['rounded']) || !empty($s['rounded'])) ? 'rounded-lg' : '';
$shadow_cls  = !empty($s['shadow']) ? 'shadow-lg' : '';

// Build the <img> via the media library (no hard-coded /uploads paths).
$img_classes = trim($size_cls . ' ' . $rounded_cls . ' ' . $shadow_cls);
$img_opts = ['class' => $img_classes];
if (!empty($s['alt'])) {
	$img_opts['alt'] = (string) $s['alt'];
}
$image_html = ka_picture_field($image_id, $img_opts);

if ($image_html === '') {
	return; // Nothing to render without a chosen image.
}

// Optional link wrap. External (http/https) links open in a new tab safely.
$href = trim((string) ($s['link_href'] ?? ''));
$is_external = $href !== '' && preg_match('#^https?://#i', $href);
?>
<section class="<?php echo esc_attr($bg . ' ' . $pad_cls); ?>">
	<div class="container-edge container-edge--wide">
		<figure class="<?php echo esc_attr(trim($mw_cls . ' ' . $align_cls)); ?>">
			<?php if ($href !== '') : ?>
			<a<?php echo ka_field_attr('link_href'); ?> href="<?php echo esc_url($href); ?>"<?php echo $is_external ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> class="block overflow-hidden <?php echo $rounded_cls ? 'rounded-lg' : ''; ?> ring-0 hover:ring-2 hover:ring-accent-500 transition">
				<span<?php echo ka_field_attr('image'); ?> class="block"><?php echo $image_html; // ka_picture_field -> wp_get_attachment_image, escaped internally ?></span>
			</a>
			<?php else : ?>
			<span<?php echo ka_field_attr('image'); ?> class="block"><?php echo $image_html; // ka_picture_field -> wp_get_attachment_image, escaped internally ?></span>
			<?php endif; ?>
			<?php if (!empty($s['caption'])) : ?>
			<figcaption<?php echo ka_field_attr('caption'); ?> class="mt-3 text-sm text-brand-500 text-center"><?php echo wp_kses_post($s['caption']); ?></figcaption>
			<?php endif; ?>
		</figure>
	</div>
</section>
