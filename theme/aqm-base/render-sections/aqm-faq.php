<?php
/** AQM FAQ — .sec-head + native <details> accordion (first row open). Optional
 *  FAQPage JSON-LD from the rows. Transliterated from the static interior pages. */
$s      = $args['s'] ?? [];
$items  = array_values(array_filter((array) ($s['items'] ?? []), fn($q) => is_array($q) && (($q['q'] ?? '') !== '')));
$schema = !empty($s['schema']);
?>
<section>
	<div class="wrap">
		<?php require __DIR__ . '/_sec-head.php'; ?>
		<?php if ($items) : ?>
		<div>
			<?php foreach ($items as $i => $q) : ?>
			<details<?php echo $i === 0 ? ' open' : ''; ?><?php echo ka_field_attr('items', $i); ?>><summary><?php echo esc_html($q['q'] ?? ''); ?></summary><p><?php echo wp_kses_post($q['a'] ?? ''); ?></p></details>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>
<?php
if ($schema && $items) {
	$qa = [];
	foreach ($items as $q) {
		$qa[] = [
			'@type'          => 'Question',
			'name'           => wp_strip_all_tags((string) ($q['q'] ?? '')),
			'acceptedAnswer' => ['@type' => 'Answer', 'text' => wp_strip_all_tags((string) ($q['a'] ?? ''))],
		];
	}
	$ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $qa];
	echo '<script type="application/ld+json">' . wp_json_encode($ld) . '</script>';
}
?>
