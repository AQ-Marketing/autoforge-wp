<?php
/** AQM icon card grid — .sec-head + a grid of .card tiles (.ico FA icon + H3 +
 *  body). The grid container is selectable (grid-3 / grid-2 / grid-4 / pain) to
 *  match the static "What you get" and "What's broken" sections. */
$s         = $args['s'] ?? [];
$container = (string) ($s['container'] ?? 'grid-3');
$allowed   = ['grid-3', 'grid-2', 'grid-4', 'pain'];
if (!in_array($container, $allowed, true)) {
	$container = 'grid-3';
}
$cards = array_values(array_filter((array) ($s['cards'] ?? []), fn($c) => is_array($c) && (($c['title'] ?? '') !== '' || ($c['body'] ?? '') !== '')));
?>
<section>
	<div class="wrap">
		<?php require __DIR__ . '/_sec-head.php'; ?>
		<?php if ($cards) : ?>
		<div class="<?php echo esc_attr($container); ?>">
			<?php foreach ($cards as $i => $c) : $fa = (string) ($c['fa'] ?? ''); ?>
			<div class="card"<?php echo ka_field_attr('cards', $i); ?>><?php if ($fa !== '') : ?><div class="ico"><i class="fa-solid <?php echo esc_attr($fa); ?>"></i></div><?php endif; ?><h3><?php echo esc_html($c['title'] ?? ''); ?></h3><p><?php echo wp_kses_post($c['body'] ?? ''); ?></p></div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>
