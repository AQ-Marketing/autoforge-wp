<?php
/** Stats — centered header (eyebrow / H2 / subheading / intro) above a
 *  responsive grid of stat figures. Each figure is a big serif value with
 *  optional small prefix/suffix glyphs (e.g. "$", "+", "%") and a caption
 *  label beneath. Column count (2/3/4 at lg) is editor-selectable; always
 *  1-up on mobile and 2-up at sm. Pure static CSS grid — no JavaScript.
 *  A NEW building block (no existing page to match) for "by the numbers"
 *  proof rows. Each figure keeps valid <dt>-before-<dd> source order; the
 *  big value is lifted above its label via flex order. */
$s     = $args['s'] ?? [];
$stats = array_values(array_filter((array) ($s['stats'] ?? []), fn($st) => is_array($st) && (($st['value'] ?? '') !== '' || ($st['label'] ?? '') !== '')));
$bg    = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$h2_mt = ($s['h2_mt'] ?? 'mt-0') === 'mt-4' ? '!mt-4' : '!mt-0';

$cols     = (string) ($s['cols'] ?? '3');
$col_map  = ['2' => 'lg:grid-cols-2 max-w-3xl', '3' => 'lg:grid-cols-3 max-w-4xl', '4' => 'lg:grid-cols-4 max-w-5xl'];
$col_cls  = $col_map[$cols] ?? $col_map['3'];
$has_head = ($s['eyebrow'] ?? '') !== '' || ($s['heading'] ?? '') !== '' || ($s['intro'] ?? '') !== '';
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<?php if ($has_head) : ?>
		<div class="max-w-3xl mx-auto text-center mb-12">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo esc_attr($h2_mt); ?>">
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
		<dl class="grid grid-cols-1 sm:grid-cols-2 <?php echo esc_attr($col_cls); ?> gap-8 mx-auto text-center">
			<?php foreach ($stats as $i => $stat) : ?>
			<div<?php echo ka_field_attr('stats', $i); ?> class="px-2 flex flex-col">
				<?php if (!empty($stat['label'])) : ?>
				<dt class="order-2 mt-3 text-sm font-medium uppercase tracking-wide text-brand-700"><?php echo esc_html($stat['label']); ?></dt>
				<?php endif; ?>
				<dd class="order-1 text-4xl md:text-5xl font-serif font-bold text-accent-500 leading-none flex items-baseline justify-center gap-0.5">
					<?php if (!empty($stat['prefix'])) : ?><span class="text-2xl md:text-3xl"><?php echo esc_html($stat['prefix']); ?></span><?php endif; ?>
					<span><?php echo esc_html($stat['value'] ?? ''); ?></span>
					<?php if (!empty($stat['suffix'])) : ?><span class="text-2xl md:text-3xl"><?php echo esc_html($stat['suffix']); ?></span><?php endif; ?>
				</dd>
			</div>
			<?php endforeach; ?>
		</dl>
	</div>
</section>
