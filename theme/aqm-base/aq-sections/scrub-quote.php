<?php
/** Scrub Quote — a single pull-quote whose words brighten on scroll (`data-words`).
 *  Transliterated 1:1 from the About page "Our mission" section (`#mission`).
 *  Structural chrome: the section shell, ghost numeral, kicker numeral `<i>`, the
 *  quote mark glyph, and the figcaption avatar wrapper. Every readable token —
 *  number, kicker label, quote, author initials/name/role — is an editable field.
 *  JS: the home.js `data-words` scrub IIFE (runs under body.home / body.about).
 *  The renderer auto-injects data-aq-section into the first <section> tag. */
$s   = $args['s'] ?? [];
$num = (string) ($s['num'] ?? '');
?>
<section class="hm-sec hm-soft">
	<?php if ($num !== '') : ?><span class="hm-ghost" aria-hidden="true"><?php echo esc_html($num); ?></span><?php endif; ?>
	<div class="wrap">
		<div class="hm-head">
			<span class="hm-kicker"><?php if ($num !== '') : ?><i><?php echo esc_html($num); ?></i><?php endif; ?><span<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($s['kicker'] ?? ''); ?></span></span>
		</div>
		<figure class="hm-quote" data-rv>
			<span class="hm-quote-mark" aria-hidden="true">&#8221;</span>
			<blockquote data-words<?php echo ka_field_attr('quote'); ?>><?php echo esc_html($s['quote'] ?? ''); ?></blockquote>
			<figcaption>
				<span class="hm-quote-ava" aria-hidden="true"<?php echo ka_field_attr('author_initials'); ?>><?php echo esc_html($s['author_initials'] ?? ''); ?></span>
				<div>
					<b<?php echo ka_field_attr('author_name'); ?>><?php echo esc_html($s['author_name'] ?? ''); ?></b>
					<span<?php echo ka_field_attr('author_role'); ?>><?php echo esc_html($s['author_role'] ?? ''); ?></span>
				</div>
			</figcaption>
		</figure>
	</div>
</section>
