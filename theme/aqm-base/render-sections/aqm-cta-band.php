<?php
/** AQM CTA band (section.cta-band) — eyebrow + H2 (inline <em>) + body + action
 *  buttons, with an optional .cta-side strip of stat tiles. Transliterated from
 *  the static interior pages. `anchor` sets the section id (the static pages use
 *  id="book" as the on-page CTA target). */
$s        = $args['s'] ?? [];
$anchor   = (string) ($s['anchor'] ?? '');
$ey_fa    = (string) ($s['eyebrow_fa'] ?? '');
$ctas     = array_values(array_filter((array) ($s['ctas'] ?? []),  fn($c) => is_array($c) && (($c['label'] ?? '') !== '')));
$stats    = array_values(array_filter((array) ($s['stats'] ?? []), fn($t) => is_array($t) && (($t['value'] ?? '') !== '')));
?>
<section class="cta-band"<?php echo $anchor !== '' ? ' id="' . esc_attr($anchor) . '"' : ''; ?>>
	<div class="wrap">
		<div>
			<?php if (($s['eyebrow'] ?? '') !== '' || $ey_fa !== '') : ?>
			<div class="eyebrow"<?php echo ka_field_attr('eyebrow'); ?>><?php if ($ey_fa !== '') : ?><i class="fa-solid <?php echo esc_attr($ey_fa); ?>"></i> <?php endif; ?><?php echo esc_html($s['eyebrow'] ?? ''); ?></div>
			<?php endif; ?>
			<h2<?php echo ka_field_attr('heading'); ?>><?php echo wp_kses_post($s['heading'] ?? ''); ?></h2>
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
