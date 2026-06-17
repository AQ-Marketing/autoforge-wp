<?php
/** Columns — optional centered header (eyebrow / H2 + gold sub / intro) above a
 *  static responsive CSS grid of 2/3/4 equal-width columns, each holding its own
 *  rich-text body. Column count, gap, and vertical alignment are configurable.
 *  No JavaScript — pure CSS grid that stacks to a single column on mobile and
 *  expands to the chosen count at the sm (2) / lg (3-4) breakpoints. A NEW
 *  building block for future pages; on-brand classes only. */
$s = $args['s'] ?? [];

$bg    = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$h2_mt = ($s['h2_mt'] ?? 'mt-0') === 'mt-4' ? '!mt-4' : '!mt-0';

// Column count -> responsive grid template (mobile = 1 column always).
$cols    = (string) ($s['cols'] ?? '3');
$col_map = [
	'2' => 'grid-cols-1 sm:grid-cols-2',
	'3' => 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
	'4' => 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4',
];
$cols_cls = $col_map[$cols] ?? $col_map['3'];

// Gap + vertical alignment selects -> fixed Tailwind tokens.
$gap_map = ['sm' => 'gap-6', 'md' => 'gap-8', 'lg' => 'gap-10'];
$gap_cls = $gap_map[$s['gap'] ?? 'md'] ?? 'gap-8';

$align_map = ['start' => 'items-start', 'center' => 'items-center', 'stretch' => 'items-stretch'];
$align_cls = $align_map[$s['align'] ?? 'start'] ?? 'items-start';

$has_header = !empty($s['eyebrow']) || !empty($s['heading']) || !empty($s['subheading']) || !empty($s['intro']);

// Filter empty column rows: ACF emits '' for an unset repeater body.
$cols_data = array_values(array_filter((array) ($s['columns'] ?? []), fn($c) => is_array($c) && trim((string) ($c['body'] ?? '')) !== ''));
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<?php if ($has_header) : ?>
		<div class="max-w-3xl mx-auto text-center mb-12">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<?php if (!empty($s['heading'])) : ?>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo esc_attr($h2_mt); ?>">
				<?php echo esc_html($s['heading']); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php endif; ?>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php if ($cols_data) : ?>
		<div class="grid <?php echo esc_attr($cols_cls . ' ' . $gap_cls . ' ' . $align_cls); ?>">
			<?php foreach ($cols_data as $colIdx => $col) : ?>
			<div<?php echo ka_field_attr('columns', $colIdx); ?> class="prose-content text-brand-700"><?php echo wp_kses_post($col['body'] ?? ''); ?></div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>