<?php
/** AQM stat row — a .sec-head followed by a .stats strip of figure tiles
 *  (b = value, span = label). Transliterated from the static interior pages. */
$s     = $args['s'] ?? [];
$stats = array_values(array_filter((array) ($s['stats'] ?? []), fn($t) => is_array($t) && (($t['value'] ?? '') !== '' || ($t['label'] ?? '') !== '')));
?>
<section>
	<div class="wrap">
		<?php require __DIR__ . '/_sec-head.php'; ?>
		<?php if ($stats) : ?>
		<div class="stats">
			<?php foreach ($stats as $i => $t) : ?>
			<div class="stat"<?php echo ka_field_attr('stats', $i); ?>><b><?php echo esc_html($t['value'] ?? ''); ?></b><span><?php echo esc_html($t['label'] ?? ''); ?></span></div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>
