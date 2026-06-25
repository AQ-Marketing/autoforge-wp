<?php
/** Stat Split — split header (heading + aside link) above a 2×2 stat grid and
 *  an optional decorative "ranked #1" map figure. Pure-numeric stat values count
 *  up (data-count; data-count-from for non-zero starts); mixed values like 24/7
 *  render static. Map SVG/CSS chrome is structural (decorative, aria-hidden).
 *  JS: home.js data-split / data-rv / [data-count]. */
$s     = $args['s'] ?? [];
$num   = (string) ($s['num'] ?? '');
$stats = array_values(array_filter((array) ($s['stats'] ?? []), fn($t) => is_array($t) && (($t['value'] ?? '') !== '' || ($t['label'] ?? '') !== '')));
$show_map = !empty($s['show_map']);
?>
<section class="hm-sec hm-light">
	<?php if ($num !== '') : ?><span class="hm-ghost" aria-hidden="true"><?php echo esc_html($num); ?></span><?php endif; ?>
	<div class="wrap">
		<div class="hm-head hm-head-split">
			<div>
				<span class="hm-kicker"><?php if ($num !== '') : ?><i><?php echo esc_html($num); ?></i><?php endif; ?><span<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($s['kicker'] ?? ''); ?></span></span>
				<h2 class="hm-title" data-split><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <em<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></em><?php endif; ?></h2>
			</div>
			<div class="hm-head-aside" data-rv>
				<?php if (($s['aside_text'] ?? '') !== '') : ?><p<?php echo ka_field_attr('aside_text'); ?>><?php echo esc_html($s['aside_text']); ?></p><?php endif; ?>
				<?php if (($s['aside_link_href'] ?? '') !== '') : ?><a class="hm-arrow-link" href="<?php echo esc_url($s['aside_link_href']); ?>"<?php echo ka_field_attr('aside_link_text'); ?>><?php echo esc_html($s['aside_link_text'] ?? ''); ?> <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
			</div>
		</div>
		<div class="hm-why-grid">
			<div class="hm-stats hm-stats-2x2">
				<?php foreach ($stats as $i => $t) :
					$val     = (string) ($t['value'] ?? '');
					$digits  = preg_replace('/[^0-9.]/', '', $val);
					$count   = $digits !== '' && $digits === $val; // pure number → animate
					$from    = preg_replace('/[^0-9.]/', '', (string) ($t['value_from'] ?? '')); ?>
				<div class="hm-stat" data-rv<?php echo ka_field_attr('stats', $i); ?>>
					<b><?php if ($count) : ?><span data-count="<?php echo esc_attr($digits); ?>"<?php echo ($from !== '' && $from !== $digits) ? ' data-count-from="' . esc_attr($from) . '"' : ''; ?>><?php echo esc_html($digits); ?></span><?php else : ?><?php echo esc_html($val); ?><?php endif; ?><?php if (($t['suffix'] ?? '') !== '') : ?>&nbsp;<em><?php echo esc_html($t['suffix']); ?></em><?php endif; ?></b>
					<span><?php echo esc_html($t['label'] ?? ''); ?></span>
				</div>
				<?php endforeach; ?>
			</div>
			<?php if ($show_map) : ?>
			<figure class="hm-viz hm-viz-map" data-rv aria-hidden="true">
				<div class="hm-vmap">
					<span class="hm-vmap-water"></span>
					<span class="hm-vmap-park"></span>
					<span class="hm-vmap-road r1"></span>
					<span class="hm-vmap-road r2"></span>
					<span class="hm-vmap-dot" style="left:24%;top:64%"></span>
					<span class="hm-vmap-dot" style="left:74%;top:32%"></span>
					<span class="hm-vmap-dot" style="left:62%;top:76%"></span>
					<span class="hm-vmap-ping" style="left:47%;top:47%"></span>
					<span class="hm-vmap-pin" style="left:47%;top:47%"><b>#1</b></span>
					<span class="hm-vmap-tag"><i class="fa-solid fa-location-dot"></i>Woburn, MA &middot; Ranked&nbsp;#1</span>
					<span class="hm-vmap-chip c1"><i class="fa-solid fa-magnifying-glass-location"></i>Local SEO</span>
					<span class="hm-vmap-chip c2"><i class="fa-solid fa-headset"></i>AI Receptionist</span>
				</div>
			</figure>
			<?php endif; ?>
		</div>
	</div>
</section>
