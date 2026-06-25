<?php
/** Compare Table — feature comparison whose header + rows cascade in (home.js
 *  .hm-compare). Each AQM cell shows an optional green check (us_check toggle)
 *  plus optional text; competitor cells fall back to an em-dash when blank. The
 *  check glyph is structural; every header and cell value is editable. */
$s    = $args['s'] ?? [];
$num  = (string) ($s['num'] ?? '');
$rows = array_values(array_filter((array) ($s['rows'] ?? []), fn($r) => is_array($r) && ($r['feature'] ?? '') !== ''));
?>
<section class="hm-sec hm-soft">
	<?php if ($num !== '') : ?><span class="hm-ghost" aria-hidden="true"><?php echo esc_html($num); ?></span><?php endif; ?>
	<div class="wrap">
		<div class="hm-head">
			<span class="hm-kicker"><?php if ($num !== '') : ?><i><?php echo esc_html($num); ?></i><?php endif; ?><span<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($s['kicker'] ?? ''); ?></span></span>
			<h2 class="hm-title" data-split><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <em<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></em><?php endif; ?></h2>
		</div>
		<div class="hm-compare">
			<table>
				<thead><tr>
					<th<?php echo ka_field_attr('col_feature'); ?>><?php echo esc_html($s['col_feature'] ?? ''); ?></th>
					<th><?php if (($s['col_us_flag'] ?? '') !== '') : ?><span class="hm-th-flag"<?php echo ka_field_attr('col_us_flag'); ?>><?php echo esc_html($s['col_us_flag']); ?></span><?php endif; ?><span<?php echo ka_field_attr('col_us'); ?>><?php echo esc_html($s['col_us'] ?? ''); ?></span></th>
					<th<?php echo ka_field_attr('col_b'); ?>><?php echo esc_html($s['col_b'] ?? ''); ?></th>
					<th<?php echo ka_field_attr('col_c'); ?>><?php echo esc_html($s['col_c'] ?? ''); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ($rows as $i => $r) : $chk = !empty($r['us_check']); ?>
					<tr<?php echo ka_field_attr('rows', $i); ?>>
						<td<?php echo ka_field_attr('feature'); ?>><?php echo esc_html($r['feature']); ?></td>
						<td class="yes"><?php if ($chk) : ?><i class="fa-solid fa-circle-check"></i> <?php endif; ?><?php echo esc_html($r['us_text'] ?? ''); ?></td>
						<td class="no"><?php echo esc_html(($r['col_b_text'] ?? '') !== '' ? $r['col_b_text'] : '—'); ?></td>
						<td class="no"><?php echo esc_html(($r['col_c_text'] ?? '') !== '' ? $r['col_c_text'] : '—'); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</section>
