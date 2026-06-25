<?php
/** Local Hero — the AQM home hero (header.hero / .hero-grid). Transliterated 1:1
 *  from index.html. Editable: badge (+ FA icon), split heading (+ the red
 *  .accent part), lede, CTA buttons (each with an optional faded sub-label), and
 *  the trust notes. `.hero-visual` is decorative (CSS-controlled). The first tag
 *  (<header>) auto-gets the data-aq-section anchor. */
$s        = $args['s'] ?? [];
$ctas     = array_values(array_filter((array) ($s['ctas'] ?? []),  fn($c) => is_array($c) && (($c['label'] ?? '') !== '')));
$notes    = array_values(array_filter((array) ($s['notes'] ?? []), fn($n) => is_array($n) && (($n['text'] ?? '') !== '')));
$badge_fa = (string) ($s['badge_fa'] ?? '');
?>
<header class="hero">
	<div class="wrap hero-grid">
		<div>
			<?php if (($s['badge'] ?? '') !== '' || $badge_fa !== '') : ?>
			<span class="badge"<?php echo ka_field_attr('badge'); ?>><?php if ($badge_fa !== '') : ?><i class="fa-solid <?php echo esc_attr($badge_fa); ?>"></i> <?php endif; ?><?php echo esc_html($s['badge'] ?? ''); ?></span>
			<?php endif; ?>
			<h1 style="margin-top:20px"><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <span class="accent"<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></span><?php endif; ?></h1>
			<?php if (($s['lede'] ?? '') !== '') : ?><p class="lede"<?php echo ka_field_attr('lede'); ?>><?php echo esc_html($s['lede']); ?></p><?php endif; ?>
			<?php if ($ctas) : ?>
			<div class="cta">
				<?php foreach ($ctas as $i => $c) :
					$style = ($c['style'] ?? 'dark') === 'outline' ? 'btn btn-outline-dark btn-lg' : 'btn btn-dark-solid btn-lg';
					$sub   = (string) ($c['sublabel'] ?? ''); ?>
				<a class="<?php echo esc_attr($style); ?>" href="<?php echo esc_url($c['href'] ?? '#'); ?>"<?php echo ka_field_attr('ctas', $i); ?>><?php echo esc_html($c['label'] ?? ''); ?><?php if ($sub !== '') : ?> <span style="opacity:.6;font-weight:400"><?php echo esc_html($sub); ?></span><?php endif; ?></a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<?php if ($notes) : ?>
			<div class="tinynote">
				<?php foreach ($notes as $i => $n) : $nfa = (string) ($n['fa'] ?? 'fa-circle-check'); ?>
				<span<?php echo ka_field_attr('notes', $i); ?>><i class="fa-solid <?php echo esc_attr($nfa); ?>"></i> <?php echo esc_html($n['text'] ?? ''); ?></span>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		<div class="hero-visual" aria-hidden="true"></div>
	</div>
</header>
