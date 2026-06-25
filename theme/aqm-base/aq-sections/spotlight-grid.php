<?php
/** Spotlight Grid — capability cells with a cursor-following spotlight (home.js
 *  #hmGrid, fine-pointer only) and one live SVG dashboard cell (line draw + donut
 *  sweep, data-viz="dash") inserted after the 2nd capability cell. SVG paths,
 *  gradients, and the donut are structural; cell number/title/body and the
 *  dashboard labels/KPIs are editable. */
$s     = $args['s'] ?? [];
$num   = (string) ($s['num'] ?? '');
$cells = array_values(array_filter((array) ($s['cells'] ?? []), fn($c) => is_array($c) && (($c['title'] ?? '') !== '' || ($c['body'] ?? '') !== '')));
$kpis  = array_values(array_filter((array) ($s['dash_kpis'] ?? []), fn($k) => is_array($k)));
$dash_after = 1; // dashboard cell sits after the 2nd capability cell (source-order parity)
?>
<section class="hm-sec hm-dark">
	<?php if ($num !== '') : ?><span class="hm-ghost" aria-hidden="true"><?php echo esc_html($num); ?></span><?php endif; ?>
	<div class="wrap">
		<div class="hm-head hm-head-split">
			<div>
				<span class="hm-kicker"><?php if ($num !== '') : ?><i><?php echo esc_html($num); ?></i><?php endif; ?><span<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($s['kicker'] ?? ''); ?></span></span>
				<h2 class="hm-title" data-split><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <em<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></em><?php endif; ?></h2>
			</div>
			<div class="hm-head-aside" data-rv><?php if (($s['aside_text'] ?? '') !== '') : ?><p<?php echo ka_field_attr('aside_text'); ?>><?php echo esc_html($s['aside_text']); ?></p><?php endif; ?></div>
		</div>
		<div class="hm-grid" id="hmGrid">
			<?php foreach ($cells as $i => $c) : ?>
			<div class="hm-cell" data-rv<?php echo ka_field_attr('cells', $i); ?>><span aria-hidden="true"<?php echo ka_field_attr('number'); ?>><?php echo esc_html($c['number'] ?? ''); ?></span><h3<?php echo ka_field_attr('title'); ?>><?php echo esc_html($c['title'] ?? ''); ?></h3><p<?php echo ka_field_attr('body'); ?>><?php echo esc_html($c['body'] ?? ''); ?></p></div>
			<?php if ($i === $dash_after) : ?>
			<div class="hm-cell hm-cell-art hm-cell-dash hm-viz hm-viz-dash" data-rv data-viz="dash" aria-hidden="true">
				<div class="hm-dash-top">
					<div class="hm-dash-chart">
						<span class="hm-dash-l"<?php echo ka_field_attr('dash_chart_label'); ?>><?php echo esc_html($s['dash_chart_label'] ?? ''); ?></span>
						<svg viewBox="0 0 220 72" preserveAspectRatio="none"><defs><linearGradient id="hmDLine" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#f26b3a"/><stop offset="1" stop-color="#ff4d68"/></linearGradient><linearGradient id="hmDArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="rgba(200,16,46,.45)"/><stop offset="1" stop-color="rgba(200,16,46,0)"/></linearGradient></defs><path class="hm-dash-area" d="M0 56 L31 50 L63 53 L94 38 L126 43 L157 26 L189 30 L220 12 L220 72 L0 72 Z"/><path class="hm-dash-line" d="M0 56 L31 50 L63 53 L94 38 L126 43 L157 26 L189 30 L220 12"/></svg>
					</div>
					<div class="hm-dash-ring">
						<svg viewBox="0 0 100 100"><circle class="hm-dash-ring-track" cx="50" cy="50" r="40"/><circle class="hm-dash-ring-val" cx="50" cy="50" r="40"/></svg>
						<span class="hm-dash-ring-c"><b<?php echo ka_field_attr('dash_ring_value'); ?>><?php echo esc_html($s['dash_ring_value'] ?? ''); ?></b><span<?php echo ka_field_attr('dash_ring_label'); ?>><?php echo esc_html($s['dash_ring_label'] ?? ''); ?></span></span>
					</div>
				</div>
				<div class="hm-dash-kpis">
					<?php foreach ($kpis as $j => $k) : ?>
					<div<?php echo ka_field_attr('dash_kpis', $j); ?>><b><?php echo esc_html($k['value'] ?? ''); ?></b><span><?php echo esc_html($k['label'] ?? ''); ?></span></div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
