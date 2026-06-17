<?php
/** Generic prose-content section: H2 + variant paragraphs (inline links allowed). */
$s          = $args['s'] ?? [];
$margin_top = $s['margin_top'] ?? 'mt-10';
?>
<section class="prose-content max-w-3xl <?php echo esc_attr($margin_top); ?> mx-auto">
	<?php if (!empty($s['heading'])) : ?>
	<h2<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading']); ?></h2>
	<?php endif; ?>
	<?php foreach ((array) ($s['blocks'] ?? []) as $bIdx => $b) :
		$class = ($b['variant'] ?? 'normal') === 'lead' ? 'text-lg text-brand-700' : ''; ?>
	<p<?php echo ka_field_attr('blocks', $bIdx); ?><?php echo $class ? ' class="' . esc_attr($class) . '"' : ''; ?>><?php echo wp_kses_post($b['html'] ?? ''); ?></p>
	<?php endforeach; ?>
</section>
