<?php
/** Chip Marquee — two horizontally drifting chip rows (industries). home.js's
 *  rAF marquee clones the chip set at runtime, so emit ONE set per row. Each
 *  row has its own data-speed (negative = reverse). Structural: .hm-rows/.hm-row
 *  wrappers. Editable: header + each chip (FA icon class / label / href). */
$s    = $args['s'] ?? [];
$num  = (string) ($s['num'] ?? '');
$row1 = array_values(array_filter((array) ($s['row1_chips'] ?? []), fn($c) => is_array($c) && ($c['label'] ?? '') !== ''));
$row2 = array_values(array_filter((array) ($s['row2_chips'] ?? []), fn($c) => is_array($c) && ($c['label'] ?? '') !== ''));
$render_chips = static function (array $chips, string $field) {
	foreach ($chips as $i => $c) {
		$href = ($c['href'] ?? '') !== '' ? $c['href'] : '#';
		$fa   = ($c['fa'] ?? '') !== '' ? '<i class="fa-solid ' . esc_attr($c['fa']) . '"></i>' : '';
		echo '<a class="hm-chip" href="' . esc_url($href) . '"' . ka_field_attr($field, $i) . '>' . $fa . esc_html($c['label']) . '</a>' . "\n";
	}
};
?>
<section class="hm-sec hm-light hm-industries">
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
		<?php
		$photos = array_values(array_filter((array) ($s['photos'] ?? []), fn($p) => is_array($p) && ($p['bg'] ?? '') !== ''));
		if ($photos) : ?>
		<div class="hm-indgrid">
			<?php foreach ($photos as $i => $p) :
				$href = ($p['href'] ?? '') !== '' ? $p['href'] : '#';
				$bgu  = '/assets/generated/' . ltrim((string) $p['bg'], '/');
			?>
			<a class="hm-indcard" href="<?php echo esc_url($href); ?>"<?php echo ka_field_attr('photos', $i); ?> style="background-image:linear-gradient(180deg,rgba(8,10,14,.08) 0%,rgba(8,10,14,.5) 56%,rgba(8,10,14,.92) 100%),url('<?php echo esc_url($bgu); ?>')">
				<span class="hm-indcard-label"><?php echo esc_html($p['label'] ?? ''); ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
			</a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
	<div class="hm-rows">
		<div class="hm-row"><div class="hm-row-track" data-speed="<?php echo esc_attr($s['row1_speed'] ?? '0.5'); ?>">
			<?php $render_chips($row1, 'row1_chips'); ?>
		</div></div>
		<div class="hm-row"><div class="hm-row-track" data-speed="<?php echo esc_attr($s['row2_speed'] ?? '-0.45'); ?>">
			<?php $render_chips($row2, 'row2_chips'); ?>
		</div></div>
	</div>
</section>
