<?php
/** Legal / utility doc page — <article class="container-x py-10"> with an H1,
 *  optional meta line ("Last updated…"), and a .prose-content body. Used by the
 *  accessibility, privacy, and terms pages. The body is verbatim HTML (stored in
 *  a textarea with new_lines='' so WP applies no wpautop/wptexturize) echoed
 *  through wp_kses_post — editor/AI-safe, pixel-faithful to the source markup. */
$s = $args['s'] ?? [];
?>
<article class="container-x py-10">
	<header class="max-w-3xl mx-auto">
		<h1<?php echo ka_field_attr('heading'); ?> class="!mt-0"><?php echo esc_html($s['heading'] ?? ''); ?></h1>
		<?php if (!empty($s['meta'])) : ?>
		<p<?php echo ka_field_attr('meta'); ?> class="text-sm text-brand-500 mt-2"><?php echo esc_html($s['meta']); ?></p>
		<?php endif; ?>
	</header>
	<div<?php echo ka_field_attr('body'); ?> class="prose-content mt-8 max-w-3xl mx-auto"><?php echo wp_kses_post($s['body'] ?? ''); ?></div>
</article>
