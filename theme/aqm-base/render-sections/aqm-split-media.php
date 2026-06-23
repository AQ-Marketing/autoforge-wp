<?php
/** AQM split (text + image) — .split with a .sec-head + .grid-2 of .card tiles on
 *  the left and a supporting image on the right. Used by the services hub "Why it
 *  works" section. Transliterated from the static markup (inline spacing tweaks
 *  preserved for pixel parity). */
$s     = $args['s'] ?? [];
$cards = array_values(array_filter((array) ($s['cards'] ?? []), fn($c) => is_array($c) && (($c['title'] ?? '') !== '' || ($c['body'] ?? '') !== '')));
$img   = (string) ($s['img_src'] ?? '');
$kick  = (string) ($s['kicker'] ?? '');
$head  = (string) ($s['heading'] ?? '');
$intro = (string) ($s['intro'] ?? '');
?>
<section>
	<div class="wrap">
		<div class="split">
			<div>
				<?php if ($kick !== '' || $head !== '' || $intro !== '') : ?>
				<div class="sec-head" style="margin-bottom:32px">
					<?php if ($kick !== '') : ?><span class="sec-num"<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($kick); ?></span><?php endif; ?>
					<?php if ($head !== '') : ?><h2<?php echo ka_field_attr('heading'); ?>><?php echo wp_kses_post($head); ?></h2><?php endif; ?>
					<?php if ($intro !== '') : ?><p<?php echo ka_field_attr('intro'); ?>><?php echo esc_html($intro); ?></p><?php endif; ?>
				</div>
				<?php endif; ?>
				<?php if ($cards) : ?>
				<div class="grid-2" style="gap:16px">
					<?php foreach ($cards as $i => $c) : $fa = (string) ($c['fa'] ?? ''); ?>
					<div class="card"<?php echo ka_field_attr('cards', $i); ?>><?php if ($fa !== '') : ?><div class="ico"><i class="fa-solid <?php echo esc_attr($fa); ?>"></i></div><?php endif; ?><h3><?php echo esc_html($c['title'] ?? ''); ?></h3><p><?php echo wp_kses_post($c['body'] ?? ''); ?></p></div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>
			<div>
				<?php if ($img !== '') : ?>
				<img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($s['img_alt'] ?? ''); ?>"<?php echo ka_field_attr('img_src'); ?> loading="lazy" decoding="async" style="width:100%;height:auto;border-radius:var(--radius);border:1px solid var(--line);box-shadow:0 20px 60px -30px rgba(0,0,0,.35)">
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
