<?php
/** Pull quote — a single emphasized blockquote with a decorative serif quote
 *  mark, optional eyebrow/heading, and an optional attribution (name + role).
 *  align=center is symmetric (centered glyph); align=left adds an accent-500
 *  left bar. bg toggles white / brand-50. Static, no JS. A NEW building block
 *  for future pages (no existing page to match). */
$s = $args['s'] ?? [];

$center = ($s['align'] ?? 'center') === 'center';
$bg     = ($s['bg'] ?? 'brand-50') === 'white' ? 'bg-white' : 'bg-brand-50';

$wrap_align  = $center ? 'mx-auto text-center' : '';
$block_align = $center ? '' : ' border-l-4 border-accent-500 pl-6 md:pl-8';
$glyph_align = $center ? 'mx-auto' : '';
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<figure class="max-w-3xl <?php echo esc_attr($wrap_align); ?>">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<?php if (!empty($s['heading'])) : ?>
			<p<?php echo ka_field_attr('heading'); ?> class="h2-sub mt-2 mb-4"><?php echo esc_html($s['heading']); ?></p>
			<?php endif; ?>
			<svg class="<?php echo esc_attr(trim('w-10 h-10 text-accent-500/40 mb-4 ' . $glyph_align)); ?>" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.13 8.6c.51-.86.99-1.42 1.42-1.68l-.74-1.34c-1.2.62-2.18 1.5-2.94 2.64-.76 1.14-1.14 2.5-1.14 4.08 0 1.42.36 2.55 1.07 3.4.71.85 1.65 1.27 2.82 1.27.99 0 1.81-.31 2.46-.93.65-.62.97-1.4.97-2.34 0-.9-.29-1.65-.87-2.25-.58-.6-1.3-.9-2.16-.9-.26 0-.53.04-.81.11.05-.71.34-1.43.87-2.16zm8.49 0c.51-.86.99-1.42 1.42-1.68l-.74-1.34c-1.2.62-2.18 1.5-2.94 2.64-.76 1.14-1.14 2.5-1.14 4.08 0 1.42.36 2.55 1.07 3.4.71.85 1.65 1.27 2.82 1.27.99 0 1.81-.31 2.46-.93.65-.62.97-1.4.97-2.34 0-.9-.29-1.65-.87-2.25-.58-.6-1.3-.9-2.16-.9-.26 0-.53.04-.81.11.05-.71.34-1.43.87-2.16z"/></svg>
			<blockquote<?php echo ka_field_attr('quote'); ?> class="font-serif italic text-2xl md:text-3xl leading-snug text-brand-900<?php echo $block_align; ?>"><?php echo esc_html($s['quote'] ?? ''); ?></blockquote>
			<?php if (!empty($s['name']) || !empty($s['role'])) : ?>
			<figcaption class="mt-6 not-italic<?php echo $center ? '' : ' pl-6 md:pl-8'; ?>">
				<?php if (!empty($s['name'])) : ?>
				<p<?php echo ka_field_attr('name'); ?> class="font-semibold text-accent-700"><?php echo esc_html($s['name']); ?></p>
				<?php endif; ?>
				<?php if (!empty($s['role'])) : ?>
				<p<?php echo ka_field_attr('role'); ?> class="text-sm text-brand-600"><?php echo esc_html($s['role']); ?></p>
				<?php endif; ?>
			</figcaption>
			<?php endif; ?>
		</figure>
	</div>
</section>
