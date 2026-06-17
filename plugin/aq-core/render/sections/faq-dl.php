<?php
/** Plain <dl>/<details> FAQ. FAQPage JSON-LD is emitted by aq-core when schema=true.
 *  Native <details> toggle — no JS required (distinct from the forest-green faq accordion). */
$s = $args['s'] ?? [];
?>
<section class="prose-content max-w-3xl mt-10 mx-auto">
	<h2<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? 'Frequently Asked Questions'); ?></h2>
	<dl class="divide-y divide-brand-100 border-y border-brand-100 my-6">
		<?php foreach ((array) ($s['items'] ?? []) as $itIdx => $it) : ?>
		<details<?php echo ka_field_attr('items', $itIdx); ?> class="group py-4">
			<summary class="cursor-pointer list-none flex items-start justify-between gap-4">
				<h3<?php echo ka_field_attr('q'); ?> class="!mt-0 !mb-0 text-lg font-semibold text-brand-900"><?php echo esc_html($it['q'] ?? ''); ?></h3>
				<span aria-hidden="true" class="text-accent-600 text-xl leading-none transition-transform group-open:rotate-45">+</span>
			</summary>
			<dd<?php echo ka_field_attr('a'); ?> class="mt-3 text-brand-800 leading-relaxed"><?php echo wp_kses_post($it['a'] ?? ''); ?></dd>
		</details>
		<?php endforeach; ?>
	</dl>
</section>
