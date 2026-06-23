<?php
/** AQM service/link card grid — .sec-head + .services grid of linked a.svc cards
 *  (tag + H3 + body + optional feature list + "more" link). Used for the services
 *  hub and the "Related / Pair with…" rows. Features are one-per-line in a textarea
 *  (no nested repeater). Transliterated from the static interior pages. */
$s     = $args['s'] ?? [];
$cards = array_values(array_filter((array) ($s['cards'] ?? []), fn($c) => is_array($c) && (($c['title'] ?? '') !== '')));
?>
<section>
	<div class="wrap">
		<?php require __DIR__ . '/_sec-head.php'; ?>
		<?php if ($cards) : ?>
		<div class="services">
			<?php foreach ($cards as $i => $c) :
				$href     = (string) ($c['href'] ?? '');
				$more     = (string) ($c['more_label'] ?? '');
				$features = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($c['features'] ?? ''))), fn($x) => $x !== '')); ?>
			<a class="svc" href="<?php echo esc_url($href ?: '#'); ?>"<?php echo ka_field_attr('cards', $i); ?>><?php if (($c['tag'] ?? '') !== '') : ?><span class="tag"><?php echo esc_html($c['tag']); ?></span><?php endif; ?><h3><?php echo esc_html($c['title'] ?? ''); ?></h3><?php if (($c['body'] ?? '') !== '') : ?><p><?php echo esc_html($c['body']); ?></p><?php endif; ?><?php if ($features) : ?><ul><?php foreach ($features as $f) : ?><li><?php echo esc_html($f); ?></li><?php endforeach; ?></ul><?php endif; ?><?php if ($more !== '') : ?><span class="more"><?php echo esc_html($more); ?> &rarr;</span><?php endif; ?></a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>
