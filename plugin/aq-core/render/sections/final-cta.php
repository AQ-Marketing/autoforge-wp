<?php
/** Final CTA — bg image + navy overlay, centered, schedule + call buttons. */
$s = $args['s'] ?? [];
$phone     = aq_site('phone');
$phone_tel = aq_site('phoneTel');
?>
<section class="relative bg-brand-900 text-white overflow-hidden">
	<?php echo ka_picture_field($s['image'] ?? null, [
		'sizes' => '100vw',
		'class' => 'absolute inset-0 w-full h-full object-cover',
	]); ?>
	<div class="absolute inset-0 overlay-cta"></div>
	<div class="relative container-edge container-edge--wide py-16 md:py-20 lg:py-24 text-center">
		<h2<?php echo ka_field_attr('heading'); ?> class="!mt-0 text-white max-w-3xl mx-auto">
			<?php echo esc_html($s['heading'] ?? ''); ?>
			<?php if (!empty($s['subheading'])) : ?>
			<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
			<?php endif; ?>
		</h2>
		<?php if (!empty($s['body'])) : ?>
		<p<?php echo ka_field_attr('body'); ?> class="text-brand-100 mt-6 max-w-2xl mx-auto"><?php echo wp_kses_post($s['body']); ?></p>
		<?php endif; ?>
		<div class="flex flex-col sm:flex-row sm:flex-wrap justify-center gap-3 mt-8">
			<a<?php echo ka_field_attr('cta_label'); ?> href="<?php echo esc_url($s['cta_href'] ?? '/schedule/'); ?>" class="btn-primary w-full sm:w-auto text-xs sm:text-sm uppercase tracking-wide sm:tracking-wider"><?php echo esc_html($s['cta_label'] ?? 'Schedule Your Inspection'); ?></a>
			<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="cta-call-btn w-full sm:w-auto text-xs sm:text-sm uppercase tracking-wide sm:tracking-wider">Call <?php echo esc_html($phone); ?></a>
		</div>
		<?php if (!empty($s['footnote'])) : ?>
		<p<?php echo ka_field_attr('footnote'); ?> class="text-xs text-brand-200 mt-8"><?php echo wp_kses_post($s['footnote']); ?></p>
		<?php endif; ?>
	</div>
</section>
