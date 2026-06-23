<?php
/** AQM feature grid — .sec-head + .features grid of .feat tiles (FA icon + H3 +
 *  rich body with inline links). Transliterated from the static interior pages. */
$s     = $args['s'] ?? [];
$items = array_values(array_filter((array) ($s['items'] ?? []), fn($f) => is_array($f) && (($f['title'] ?? '') !== '' || ($f['body'] ?? '') !== '')));
?>
<section>
	<div class="wrap">
		<?php require __DIR__ . '/_sec-head.php'; ?>
		<?php if ($items) : ?>
		<div class="features">
			<?php foreach ($items as $i => $f) : $fa = (string) ($f['fa'] ?? ''); ?>
			<div class="feat"<?php echo ka_field_attr('items', $i); ?>><?php if ($fa !== '') : ?><i class="fa-solid <?php echo esc_attr($fa); ?>"></i><?php endif; ?><h3><?php echo esc_html($f['title'] ?? ''); ?></h3><p><?php echo wp_kses_post($f['body'] ?? ''); ?></p></div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>
