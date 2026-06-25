<?php
/** CTA Banner — AQM full-width .cta-band: eyebrow (+ FA icon) + split heading
 *  (the gradient <em> part) + body + action buttons, beside a column of
 *  .cta-stat tiles. Transliterated 1:1 from index.html. Editable throughout.
 *  Distinct from the engine's stock `cta_band` (inspection chrome). First tag
 *  (<section>) auto-gets the data-aq-section anchor. JS: home.js animates
 *  .cta-band on scroll. */
$s      = $args['s'] ?? [];
$ctas   = array_values(array_filter((array) ($s['ctas'] ?? []),  fn($c) => is_array($c) && (($c['label'] ?? '') !== '')));
$stats  = array_values(array_filter((array) ($s['stats'] ?? []), fn($t) => is_array($t) && ((($t['value'] ?? '') !== '') || (($t['label'] ?? '') !== ''))));
$eyb_fa = (string) ($s['eyebrow_fa'] ?? '');
?>
<section class="cta-band">
	<div class="wrap">
		<div>
			<?php if (($s['eyebrow'] ?? '') !== '' || $eyb_fa !== '') : ?>
			<div class="eyebrow"<?php echo ka_field_attr('eyebrow'); ?>><?php if ($eyb_fa !== '') : ?><i class="fa-solid <?php echo esc_attr($eyb_fa); ?>"></i> <?php endif; ?><?php echo esc_html($s['eyebrow'] ?? ''); ?></div>
			<?php endif; ?>
			<h2><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <em<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></em><?php endif; ?></h2>
			<?php if (($s['body'] ?? '') !== '') : ?><p<?php echo ka_field_attr('body'); ?>><?php echo esc_html($s['body']); ?></p><?php endif; ?>
			<?php if ($ctas) : ?>
			<div class="actions">
				<?php foreach ($ctas as $i => $c) :
					$style = ($c['style'] ?? 'primary') === 'ghost' ? 'btn btn-ghost btn-lg' : 'btn btn-primary btn-lg';
					$cfa   = (string) ($c['fa'] ?? ''); ?>
				<a class="<?php echo esc_attr($style); ?>" href="<?php echo esc_url($c['href'] ?? '#'); ?>"<?php echo ka_field_attr('ctas', $i); ?>><?php if ($cfa !== '') : ?><i class="fa-solid <?php echo esc_attr($cfa); ?>"></i> <?php endif; ?><?php echo esc_html($c['label'] ?? ''); ?></a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php if ($stats) : ?>
		<div class="cta-side">
			<?php foreach ($stats as $i => $t) : $tfa = (string) ($t['fa'] ?? ''); ?>
			<div class="cta-stat"<?php echo ka_field_attr('stats', $i); ?>><b><?php if ($tfa !== '') : ?><i class="fa-solid <?php echo esc_attr($tfa); ?>"></i><?php endif; ?><?php echo esc_html($t['value'] ?? ''); ?></b><span><?php echo esc_html($t['label'] ?? ''); ?></span></div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>
