<?php
/** Journey — bg-brand-50 numbered 4-up process cards. */
$s = $args['s'] ?? [];
$h2mt = ($s['h2_mt'] ?? 'mt-0') === 'mt-4' ? '!mt-4' : '!mt-0';
?>
<section class="bg-brand-50 py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo $h2mt; ?>">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
			<?php foreach ((array) ($s['cards'] ?? []) as $cardIdx => $card) : ?>
			<div<?php echo ka_field_attr('cards', $cardIdx); ?> class="bg-white rounded-lg shadow-sm border-t-4 border-accent-500 p-6">
				<p<?php echo ka_field_attr('number'); ?> class="font-serif text-5xl font-bold h2-sub leading-none mb-4"><?php echo esc_html($card['number'] ?? ''); ?></p>
				<h3<?php echo ka_field_attr('title'); ?> class="!mt-0 text-lg font-semibold"><?php echo esc_html($card['title'] ?? ''); ?></h3>
				<p<?php echo ka_field_attr('body'); ?> class="text-sm text-brand-700 mt-3"><?php echo wp_kses_post($card['body'] ?? ''); ?></p>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
