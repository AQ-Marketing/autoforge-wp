<?php
/** Heading — a standalone, self-contained section header: optional eyebrow pill,
 *  an H2 or H3 heading, an optional gold subheading line (h2-sub), and an
 *  optional intro line. No cards, no image, no JS — just an on-brand heading you
 *  can drop in to introduce the content that follows. Uses the same eyebrow / H2
 *  / subheading / intro idiom as feature_cards & step_cards so it matches the
 *  rest of the site's section headers. The heading gap (!mt-4) is applied only
 *  when an eyebrow sits above it, so a bare heading gains no unintended gap. */
$s = $args['s'] ?? [];

$level = ($s['level'] ?? 'h2') === 'h3' ? 'h3' : 'h2';
$align = ($s['align'] ?? 'center') === 'left' ? 'text-left' : 'text-center';
$bg    = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';

$pad_map = [
	'compact'  => 'py-8 md:py-10',
	'normal'   => 'py-12 md:py-16 lg:py-20',
	'spacious' => 'py-16 md:py-20 lg:py-28',
];
$pad = $pad_map[$s['pad'] ?? 'normal'] ?? $pad_map['normal'];

// Center alignment also centers the block horizontally; left alignment keeps it left.
$wrap_align = ($align === 'text-center') ? 'mx-auto ' . $align : $align;

$head_mt = !empty($s['eyebrow']) ? '!mt-4' : '!mt-0';
// H3 sits a touch smaller than the default H2 styling.
$heading_size = $level === 'h3' ? 'text-2xl md:text-3xl' : '';
$heading_cls  = trim($head_mt . ' ' . $heading_size);
?>
<section class="<?php echo esc_attr($bg . ' ' . $pad); ?>">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl <?php echo esc_attr($wrap_align); ?>">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<<?php echo $level; ?><?php echo ka_field_attr('heading'); ?> class="<?php echo esc_attr($heading_cls); ?>">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</<?php echo $level; ?>>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
