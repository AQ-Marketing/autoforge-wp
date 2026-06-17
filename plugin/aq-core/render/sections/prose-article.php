<?php
/** Prose article — long-form .prose body with an optional sticky sidebar.
 *  bg-white section; the body renders inside .prose prose-brand. When an aside
 *  is present the two sit in an lg [col_ratio] grid (default 60fr/35fr). */
$s     = $args['s'] ?? [];
$ratio = (string) ($s['col_ratio'] ?? '60fr_35fr');
$aside = trim((string) ($s['aside'] ?? ''));
?>
<section class="bg-white py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<?php if ($aside !== '') : ?>
		<div class="grid lg:grid-cols-[<?php echo esc_attr($ratio); ?>] gap-10 lg:gap-[5%] items-start">
			<div<?php echo ka_field_attr('body'); ?> class="prose prose-brand max-w-none"><?php echo wp_kses_post($s['body'] ?? ''); ?></div>
			<aside<?php echo ka_field_attr('aside'); ?> class="lg:sticky lg:top-8"><?php echo wp_kses_post($aside); ?></aside>
		</div>
		<?php else : ?>
		<div<?php echo ka_field_attr('body'); ?> class="prose prose-brand max-w-none"><?php echo wp_kses_post($s['body'] ?? ''); ?></div>
		<?php endif; ?>
	</div>
</section>
