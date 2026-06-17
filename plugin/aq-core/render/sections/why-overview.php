<?php
/** Why / Overview — 2-col [55fr_40fr] text left, image right. */
$s    = $args['s'] ?? [];
$bg   = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$pads = ['normal' => 'py-12 md:py-16 lg:py-20', 'compact' => 'py-8 md:py-12', 'spacious' => 'py-16 md:py-24 lg:py-28'];
$pad  = $pads[$s['pad'] ?? 'normal'] ?? $pads['normal'];
?>
<section class="<?php echo esc_attr($bg . ' ' . $pad); ?>">
	<div class="container-edge container-edge--wide">
		<div class="grid lg:grid-cols-[55fr_40fr] gap-10 lg:gap-[5%] items-center">
			<div>
				<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
				<h2<?php echo ka_field_attr('heading'); ?> class="!mt-0">
					<?php echo esc_html($s['heading'] ?? ''); ?>
					<?php if (!empty($s['subheading'])) : ?>
					<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
					<?php endif; ?>
				</h2>
				<?php foreach ((array) ($s['paragraphs'] ?? []) as $i => $p) : ?>
				<p<?php echo ka_field_attr('paragraphs', $i); ?> class="text-brand-700 <?php echo $i === 0 ? 'mt-6' : 'mt-4'; ?>"><?php echo wp_kses_post($p['html'] ?? ''); ?></p>
				<?php endforeach; ?>
			</div>
			<div<?php echo ka_field_attr('image'); ?>>
				<?php echo ka_picture_field($s['image'] ?? null, [
					'class' => 'w-full h-auto rounded-lg shadow-lg object-cover aspect-[4/3]',
				]); ?>
			</div>
		</div>
	</div>
</section>
