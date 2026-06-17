<?php
/** Gallery — static responsive image grid (2/3/4 columns) of media-library
 *  images, each with an optional caption. Optional centered header
 *  (eyebrow / H2 / subheading / intro) above the grid. Pure CSS grid: 1 col on
 *  mobile, 2 at sm, then 2/3/4 at lg per the `columns` select. No lightbox / JS.
 *  Each image is a <figure> (ka_picture_field, lazy, 4/3) + optional <figcaption>. */
$s     = $args['s'] ?? [];
$items = array_values(array_filter((array) ($s['items'] ?? []), fn($it) => is_array($it) && !empty($it['image'])));

$bg      = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$columns = (string) ($s['columns'] ?? '3');
$lg_cols = ['2' => 'lg:grid-cols-2', '3' => 'lg:grid-cols-3', '4' => 'lg:grid-cols-4'][$columns] ?? 'lg:grid-cols-3';
$grid    = 'grid grid-cols-1 sm:grid-cols-2 ' . $lg_cols . ' gap-6';

$has_header = !empty($s['eyebrow']) || !empty($s['heading']) || !empty($s['subheading']) || !empty($s['intro']);
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<?php if ($has_header) : ?>
		<div class="max-w-3xl mx-auto text-center mb-12">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<h2<?php echo ka_field_attr('heading'); ?> class="!mt-4">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php if ($items) : ?>
		<div class="<?php echo esc_attr($grid); ?>">
			<?php foreach ($items as $i => $item) :
				$img = ka_picture_field($item['image'] ?? null, [
					'class' => 'w-full h-auto object-cover aspect-[4/3] rounded-lg shadow-md',
				]);
				if ($img === '') {
					continue;
				}
				?>
			<figure<?php echo ka_field_attr('items', $i); ?> class="m-0">
				<?php echo $img; ?>
				<?php if (!empty($item['caption'])) : ?>
				<figcaption<?php echo ka_field_attr('caption'); ?> class="mt-3 text-sm text-brand-700 text-center"><?php echo esc_html($item['caption']); ?></figcaption>
				<?php endif; ?>
			</figure>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>