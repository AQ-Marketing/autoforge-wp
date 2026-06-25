<?php
/** Network Hero — three.js particle-network hero (About page). Structural: the
 *  <canvas id="abHeroCanvas">, veil, and scroll cue. JS: about.js (gated on
 *  body.about) draws the network; degrades to the CSS hero if WebGL/THREE absent.
 *  Editable: badge, heading + gold accent, intro, CTAs, trust check items. */
$s     = $args['s'] ?? [];
$ctas  = array_values(array_filter((array) ($s['ctas'] ?? []), fn($c) => is_array($c) && ($c['label'] ?? '') !== ''));
$notes = array_values(array_filter((array) ($s['notes'] ?? []), fn($n) => is_array($n) && ($n['text'] ?? '') !== ''));
?>
<header class="ab-hero" id="abHero">
	<canvas class="ab-hero-canvas" id="abHeroCanvas" aria-hidden="true"></canvas>
	<div class="ab-hero-veil" aria-hidden="true"></div>
	<div class="wrap">
		<div class="ab-hero-inner">
			<span class="badge"<?php echo ka_field_attr('badge'); ?>><i class="fa-solid fa-location-dot"></i> <?php echo esc_html($s['badge'] ?? ''); ?></span>
			<h1><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <span class="accent"<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></span><?php endif; ?></h1>
			<?php if (($s['intro'] ?? '') !== '') : ?><p class="lede"<?php echo ka_field_attr('intro'); ?>><?php echo esc_html($s['intro']); ?></p><?php endif; ?>
			<?php if ($ctas) : ?>
			<div class="cta">
				<?php foreach ($ctas as $i => $c) :
					$cls = ($c['style'] ?? 'dark') === 'outline' ? 'btn btn-outline-dark btn-lg' : 'btn btn-dark-solid btn-lg'; ?>
				<a class="<?php echo esc_attr($cls); ?>" href="<?php echo esc_url($c['href'] ?? '#'); ?>"<?php echo ka_field_attr('ctas', $i); ?>><?php echo esc_html($c['label'] ?? ''); ?><?php if (($c['note'] ?? '') !== '') : ?> <span style="opacity:.6;font-weight:400"><?php echo esc_html($c['note']); ?></span><?php endif; ?></a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<?php if ($notes) : ?>
			<div class="ab-hero-note">
				<?php foreach ($notes as $i => $n) : ?>
				<span<?php echo ka_field_attr('notes', $i); ?>><i class="fa-solid fa-circle-check"></i> <?php echo esc_html($n['text']); ?></span>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<div class="ab-hero-scroll" aria-hidden="true">Scroll <i class="fa-solid fa-chevron-down"></i></div>
</header>
