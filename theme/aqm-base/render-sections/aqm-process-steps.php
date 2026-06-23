<?php
/** AQM process steps — .sec-head + .steps row of .step cards (.num tag + H3 +
 *  body). Transliterated from the static interior pages (e.g. the 90-day playbook). */
$s     = $args['s'] ?? [];
$steps = array_values(array_filter((array) ($s['steps'] ?? []), fn($st) => is_array($st) && (($st['title'] ?? '') !== '' || ($st['body'] ?? '') !== '')));
?>
<section>
	<div class="wrap">
		<?php require __DIR__ . '/_sec-head.php'; ?>
		<?php if ($steps) : ?>
		<div class="steps">
			<?php foreach ($steps as $i => $st) : ?>
			<div class="step"<?php echo ka_field_attr('steps', $i); ?>><div class="num"><?php echo esc_html($st['step_label'] ?? ''); ?></div><h3><?php echo esc_html($st['title'] ?? ''); ?></h3><p><?php echo wp_kses_post($st['body'] ?? ''); ?></p></div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>
