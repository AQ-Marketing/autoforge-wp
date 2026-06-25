<?php
/** FAQ Split — AQM .hm-faq-grid: a sticky side column (kicker / split title /
 *  note / CTA) beside a list of native <details name="faq"> rows. Transliterated
 *  from index.html. Editable: header, aside note, CTA, and each Q/A; the 0X
 *  numeral is computed. Native <details> need no JS. FAQPage JSON-LD is emitted
 *  by aq-core when the `schema` toggle is on (default). JS: home.js data-split /
 *  data-rv. First tag (<section>) auto-gets the data-aq-section anchor. */
$s     = $args['s'] ?? [];
$num   = (string) ($s['num'] ?? '');
$items = array_values(array_filter((array) ($s['items'] ?? []), fn($it) => is_array($it) && (($it['q'] ?? '') !== '')));
$cta_l = (string) ($s['cta_label'] ?? '');
?>
<section class="hm-sec hm-light">
	<?php if ($num !== '') : ?><span class="hm-ghost" aria-hidden="true"><?php echo esc_html($num); ?></span><?php endif; ?>
	<div class="wrap">
		<div class="hm-faq-grid">
			<div class="hm-faq-side">
				<span class="hm-kicker"><?php if ($num !== '') : ?><i><?php echo esc_html($num); ?></i><?php endif; ?><span<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($s['kicker'] ?? ''); ?></span></span>
				<h2 class="hm-title" data-split><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <em<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></em><?php endif; ?></h2>
				<?php if (($s['aside_note'] ?? '') !== '') : ?><p data-rv<?php echo ka_field_attr('aside_note'); ?>><?php echo esc_html($s['aside_note']); ?></p><?php endif; ?>
				<?php if ($cta_l !== '') : ?><a class="btn btn-primary" href="<?php echo esc_url($s['cta_href'] ?? '#'); ?>" data-rv<?php echo ka_field_attr('cta_label'); ?>><?php echo esc_html($cta_l); ?></a><?php endif; ?>
			</div>
			<div class="hm-faq-list">
				<?php foreach ($items as $i => $it) : $n = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?>
				<details name="faq" data-rv<?php echo ka_field_attr('items', $i); ?>><summary><span class="hm-faq-n" aria-hidden="true"><?php echo esc_html($n); ?></span><span class="hm-faq-q"><?php echo esc_html($it['q'] ?? ''); ?></span><span class="hm-faq-x" aria-hidden="true"></span></summary><p><?php echo esc_html($it['a'] ?? ''); ?></p></details>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</section>
