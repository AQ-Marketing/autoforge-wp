<?php
/** Logo Marquee — CSS-scrolled trust band. The .marquee-track set is duplicated
 *  in-template (second copy aria-hidden) for a seamless CSS loop — home.js does
 *  NOT clone .marquee-track. Stars are structural; the lead/bold label and the
 *  client names are editable. First tag (<section>) gets data-aq-section. */
$s     = $args['s'] ?? [];
$logos = array_values(array_filter((array) ($s['logos'] ?? []), fn($l) => is_array($l) && ($l['name'] ?? '') !== ''));
?>
<section class="trust" style="padding:36px 0;border-top:1px solid var(--line)">
	<div class="wrap">
		<div class="label"><span class="stars"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></span> <span<?php echo ka_field_attr('label_lead'); ?>><?php echo esc_html($s['label_lead'] ?? ''); ?></span> <b<?php echo ka_field_attr('label_strong'); ?>><?php echo esc_html($s['label_strong'] ?? ''); ?></b></div>
		<div class="marquee" aria-hidden="true">
			<div class="marquee-track">
				<?php foreach ($logos as $i => $l) : ?>
				<span class="client-logo"<?php echo ka_field_attr('logos', $i); ?>><?php echo esc_html($l['name']); ?></span>
				<?php endforeach; ?>
				<?php foreach ($logos as $l) : ?>
				<span class="client-logo" aria-hidden="true"><?php echo esc_html($l['name']); ?></span>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</section>
