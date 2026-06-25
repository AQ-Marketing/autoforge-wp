<?php
/** Proof Story — scrub-brightening pull quote + a Google-review viz (rating bars
 *  sweep out, data-viz="reviews") + count-up metric tiles (one counts DOWN via
 *  data-count-from). Structural: the G glyph, stars, and bar geometry. Editable:
 *  every number, label, and quote. JS: home.js data-words / data-viz="reviews" /
 *  [data-count]. Non-numeric metric values fall back to static text. */
$s       = $args['s'] ?? [];
$num     = (string) ($s['num'] ?? '');
$bars    = array_values(array_filter((array) ($s['bars'] ?? []), fn($b) => is_array($b)));
$metrics = array_values(array_filter((array) ($s['metrics'] ?? []), fn($m) => is_array($m)));
?>
<section class="hm-sec hm-light">
	<?php if ($num !== '') : ?><span class="hm-ghost" aria-hidden="true"><?php echo esc_html($num); ?></span><?php endif; ?>
	<div class="wrap">
		<div class="hm-head">
			<span class="hm-kicker"><?php if ($num !== '') : ?><i><?php echo esc_html($num); ?></i><?php endif; ?><span<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($s['kicker'] ?? ''); ?></span></span>
			<h2 class="hm-title hm-title-sm" data-split><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <em<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></em><?php endif; ?></h2>
		</div>
		<div class="hm-results-grid">
			<figure class="hm-quote" data-rv>
				<span class="hm-quote-mark" aria-hidden="true">&#8221;</span>
				<div class="hm-stars" aria-label="5 out of 5 stars">★★★★★</div>
				<blockquote data-words<?php echo ka_field_attr('quote'); ?>><?php echo esc_html($s['quote'] ?? ''); ?></blockquote>
				<figcaption><span class="hm-quote-ava" aria-hidden="true"<?php echo ka_field_attr('author_initials'); ?>><?php echo esc_html($s['author_initials'] ?? ''); ?></span><div><b<?php echo ka_field_attr('author_name'); ?>><?php echo esc_html($s['author_name'] ?? ''); ?></b><span<?php echo ka_field_attr('author_role'); ?>><?php echo esc_html($s['author_role'] ?? ''); ?></span></div></figcaption>
			</figure>
			<div class="hm-results-side">
				<div class="hm-viz hm-viz-reviews" data-rv data-viz="reviews" aria-hidden="true">
					<div class="hm-rv-head">
						<span class="hm-rv-g">G</span>
						<div><b<?php echo ka_field_attr('review_rating'); ?>><?php echo esc_html($s['review_rating'] ?? ''); ?></b><span class="hm-rv-stars">★★★★★</span></div>
						<span class="hm-rv-count"<?php echo ka_field_attr('review_count'); ?>><?php echo esc_html($s['review_count'] ?? ''); ?></span>
					</div>
					<div class="hm-rv-bars">
						<?php foreach ($bars as $i => $bar) :
							$pct = is_numeric($bar['pct'] ?? '') ? max(0, min(100, (float) $bar['pct'])) . '%' : '0%'; ?>
						<div class="hm-rv-bar"<?php echo ka_field_attr('bars', $i); ?>><span><?php echo esc_html($bar['star'] ?? ''); ?></span><i><b style="--w:<?php echo esc_attr($pct); ?>"></b></i></div>
						<?php endforeach; ?>
					</div>
					<div class="hm-rv-snip">
						<span class="hm-rv-av"<?php echo ka_field_attr('review_snip_initials'); ?>><?php echo esc_html($s['review_snip_initials'] ?? ''); ?></span>
						<div><span class="hm-rv-stars sm">★★★★★</span><p<?php echo ka_field_attr('review_snip_text'); ?>><?php echo esc_html($s['review_snip_text'] ?? ''); ?></p></div>
					</div>
				</div>
				<?php foreach ($metrics as $i => $m) :
					$to       = (string) ($m['to'] ?? '');
					$from     = (string) ($m['from'] ?? '');
					$num_to   = preg_replace('/[^0-9.]/', '', $to);
					$num_from = preg_replace('/[^0-9.]/', '', $from);
					$count    = $num_to !== '' ? ' data-count="' . esc_attr($num_to) . '"' : '';
					if ($count && $num_from !== '' && $num_from !== $num_to) $count .= ' data-count-from="' . esc_attr($num_from) . '"'; ?>
				<div class="hm-metric" data-rv<?php echo ka_field_attr('metrics', $i); ?>>
					<span class="hm-metric-l"><?php if (($m['fa'] ?? '') !== '') : ?><i class="fa-solid <?php echo esc_attr($m['fa']); ?>"></i><?php endif; ?><?php echo esc_html($m['label'] ?? ''); ?></span>
					<div class="hm-metric-v"><?php if (($m['old_value'] ?? '') !== '') : ?><s><?php echo esc_html($m['old_value']); ?></s><?php endif; ?><b><?php echo esc_html($m['prefix'] ?? ''); ?><span<?php echo $count; ?>><?php echo esc_html($num_to !== '' ? $num_to : $to); ?></span><?php if (($m['suffix'] ?? '') !== '') : ?><em><?php echo esc_html($m['suffix']); ?></em><?php endif; ?></b></div>
					<span class="hm-metric-c"><?php echo esc_html($m['caption'] ?? ''); ?></span>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</section>
