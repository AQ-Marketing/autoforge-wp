<?php
/** Icon list — centered header (eyebrow / H2 / subheading / intro) above a list
 *  of icon + text rows. Each row pairs an inline-SVG icon badge with a bold
 *  title and a description paragraph. Renders as a static CSS grid in 1 or 2
 *  columns (columns select), always a single column on mobile. On-brand:
 *  pill-eyebrow / h2-sub / container-edge--wide / icon-badge component. No JS. */
$s     = $args['s'] ?? [];
$items = (array) ($s['items'] ?? []);
$bg    = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$cols  = ((string) ($s['columns'] ?? '2')) === '1'
	? 'grid grid-cols-1 gap-6 max-w-2xl mx-auto'
	: 'grid grid-cols-1 sm:grid-cols-2 gap-6 max-w-4xl mx-auto';
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
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
		<ul class="<?php echo esc_attr($cols); ?> list-none p-0 m-0">
			<?php foreach ($items as $itemIdx => $item) : ?>
			<li<?php echo ka_field_attr('items', $itemIdx); ?> class="flex items-start gap-4">
				<span<?php echo ka_field_attr('icon_svg'); ?> class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 icon-badge">
					<?php echo $item['icon_svg'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — inline SVG icon sink, code-mode-only ?>
				</span>
				<div class="flex-1">
					<?php if (!empty($item['title'])) : ?>
					<h3<?php echo ka_field_attr('title'); ?> class="!mt-0 text-lg font-bold text-brand-900"><?php echo esc_html($item['title']); ?></h3>
					<?php endif; ?>
					<?php if (!empty($item['text'])) : ?>
					<p<?php echo ka_field_attr('text'); ?> class="text-sm text-brand-700 mt-1"><?php echo wp_kses_post($item['text']); ?></p>
					<?php endif; ?>
				</div>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
