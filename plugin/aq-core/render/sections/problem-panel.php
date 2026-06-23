<?php
/** Problem Panel — pain list beside a self-building "buried listings" viz
 *  (home.js data-viz="buried" animates competitor rows in, drops the badge, then
 *  sinks the "you" row; .hm-pain rows light up via toggleClass). Structural:
 *  stars, trend/search glyphs, pain numerals. Editable: header, note, buried
 *  mock labels, and each pain (FA icon class / title / body). */
$s     = $args['s'] ?? [];
$num   = (string) ($s['num'] ?? '');
$comps = array_values(array_filter((array) ($s['comp_rows'] ?? []), fn($c) => is_array($c) && ($c['name'] ?? '') !== ''));
$pains = array_values(array_filter((array) ($s['pains'] ?? []), fn($p) => is_array($p) && (($p['title'] ?? '') !== '' || ($p['body'] ?? '') !== '')));
?>
<section class="hm-sec hm-dark">
	<?php if ($num !== '') : ?><span class="hm-ghost" aria-hidden="true"><?php echo esc_html($num); ?></span><?php endif; ?>
	<div class="wrap">
		<div class="hm-problem-grid">
			<div class="hm-problem-side">
				<span class="hm-kicker"><?php if ($num !== '') : ?><i><?php echo esc_html($num); ?></i><?php endif; ?><span<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($s['kicker'] ?? ''); ?></span></span>
				<h2 class="hm-title" data-split><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <em<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></em><?php endif; ?></h2>
				<?php if (($s['note'] ?? '') !== '') : ?><p class="hm-aside-note" data-rv<?php echo ka_field_attr('note'); ?>><?php echo esc_html($s['note']); ?></p><?php endif; ?>
				<div class="hm-viz hm-viz-buried" data-rv data-viz="buried" aria-hidden="true">
					<div class="hm-bsearch"><i class="fa-solid fa-magnifying-glass"></i><span<?php echo ka_field_attr('search_term'); ?>><?php echo esc_html($s['search_term'] ?? ''); ?></span></div>
					<ul class="hm-blist">
						<?php foreach ($comps as $i => $c) : ?>
						<li class="hm-brow"<?php echo ka_field_attr('comp_rows', $i); ?>><span class="hm-bav"><?php echo esc_html($c['av'] ?? ''); ?></span><div><b><?php echo esc_html($c['name']); ?></b><span class="hm-bstars">★★★★★</span></div><i class="fa-solid fa-arrow-trend-up"></i></li>
						<?php endforeach; ?>
					</ul>
					<div class="hm-bbadge"><i class="fa-solid fa-arrow-down-long"></i><div><b<?php echo ka_field_attr('badge_title'); ?>><?php echo esc_html($s['badge_title'] ?? ''); ?></b><span<?php echo ka_field_attr('badge_sub'); ?>><?php echo esc_html($s['badge_sub'] ?? ''); ?></span></div></div>
					<div class="hm-brow hm-byou"><span class="hm-bav"<?php echo ka_field_attr('you_av'); ?>><?php echo esc_html($s['you_av'] ?? ''); ?></span><div><b<?php echo ka_field_attr('you_name'); ?>><?php echo esc_html($s['you_name'] ?? ''); ?></b><span class="hm-bstars dim">★★★★★</span></div></div>
				</div>
			</div>
			<ol class="hm-painlist">
				<?php foreach ($pains as $i => $p) :
					$pn = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?>
				<li class="hm-pain"<?php echo ka_field_attr('pains', $i); ?>>
					<span class="hm-pain-num" aria-hidden="true"><?php echo esc_html($pn); ?></span>
					<div>
						<h3><span class="hm-pain-ico"><?php if (($p['fa'] ?? '') !== '') : ?><i class="fa-solid <?php echo esc_attr($p['fa']); ?>"></i><?php endif; ?></span><span<?php echo ka_field_attr('title'); ?>><?php echo esc_html($p['title'] ?? ''); ?></span></h3>
						<p<?php echo ka_field_attr('body'); ?>><?php echo esc_html($p['body'] ?? ''); ?></p>
					</div>
				</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</div>
</section>
