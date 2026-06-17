<?php
/** Plain article header: eyebrow + H1 + free-form meta line.
 *  When open_article is set, opens the <article class="container-x py-10"> wrapper
 *  that a later section (link_card_grid with close_article) closes — used by
 *  city × service pages to reproduce the original single-article DOM. */
$s = $args['s'] ?? [];
?>
<?php if (!empty($s['open_article'])) : ?>
<article class="container-x py-10">
<?php endif; ?>
	<header class="max-w-3xl mx-auto">
		<?php if (!empty($s['eyebrow'])) : ?>
		<p<?php echo ka_field_attr('eyebrow'); ?> class="text-xs uppercase tracking-wider text-accent-700 font-semibold"><?php echo esc_html($s['eyebrow']); ?></p>
		<?php endif; ?>
		<h1<?php echo ka_field_attr('heading'); ?> class="!mt-2"><?php echo esc_html($s['heading'] ?? ''); ?></h1>
		<?php if (!empty($s['meta'])) : ?>
		<p<?php echo ka_field_attr('meta'); ?> class="text-sm text-brand-500 mt-2"><?php echo esc_html($s['meta']); ?></p>
		<?php endif; ?>
	</header>
