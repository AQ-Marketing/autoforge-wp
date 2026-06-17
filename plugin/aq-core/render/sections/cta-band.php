<?php
/** Inline schedule CTA band (bg-brand-800). Distinct from final_cta (full-bleed image band).
 *  Mirrors the Astro ScheduleCTA "block" variant. */
$s         = $args['s'] ?? [];
$phone     = aq_site('phone');
$phone_tel = aq_site('phoneTel');

$secondary_label = $s['secondary_label'] ?? '';
if ($secondary_label === '') {
	$secondary_label = 'Call ' . $phone;
}
$secondary_href = $s['secondary_href'] ?? '';
if ($secondary_href === '') {
	$secondary_href = 'tel:' . $phone_tel;
}
?>
<section class="my-16">
	<div class="container-x">
		<div class="rounded-lg bg-brand-800 text-white p-8 md:p-12 flex flex-col md:flex-row items-start md:items-center gap-6 md:gap-10">
			<div class="flex-1">
				<h2<?php echo ka_field_attr('headline'); ?> class="text-white !mt-0"><?php echo esc_html($s['headline'] ?? 'Ready to schedule your inspection?'); ?></h2>
				<?php if (!empty($s['body'])) : ?>
				<p<?php echo ka_field_attr('body'); ?> class="text-brand-50 mb-0"><?php echo wp_kses_post($s['body']); ?></p>
				<?php endif; ?>
			</div>
			<div class="flex flex-wrap gap-3">
				<a<?php echo ka_field_attr('primary_label'); ?> href="<?php echo esc_url($s['primary_href'] ?? '/schedule/'); ?>" class="btn-primary"><?php echo esc_html($s['primary_label'] ?? 'Schedule Online'); ?></a>
				<a<?php echo ka_field_attr('secondary_label'); ?> href="<?php echo esc_url($secondary_href); ?>" class="btn-outline-light"><?php echo esc_html($secondary_label); ?></a>
			</div>
		</div>
	</div>
</section>
