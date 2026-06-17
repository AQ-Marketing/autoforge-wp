<?php
/** Accordion — expandable FAQ-style items using native <details>/<summary>
 *  (NO JavaScript; the chevron animates via the group-open: variant). Centered
 *  header (eyebrow / H2 / subheading / intro) above a vertical stack of items,
 *  each a title + rich-text body. Optional first_open opens the first item on
 *  load. Distinct from the faq accordion (JS toggle, schema) and
 *  faq_dl (bare <dl>): this is a general-purpose white/tint panel set
 *  with no JSON-LD coupling, for future content pages. */
$s          = $args['s'] ?? [];
$items      = (array) ($s['items'] ?? []);
$bg         = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$h2mt       = ($s['h2_mt'] ?? 'mt-0') === 'mt-4' ? '!mt-4' : '!mt-0';
$first_open = !empty($s['first_open']);
?>
<section class="<?php echo $bg; ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo $h2mt; ?>">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<div class="max-w-3xl mx-auto space-y-4">
			<?php foreach ($items as $itemIdx => $item) : ?>
			<details<?php echo ka_field_attr('items', $itemIdx); ?> class="group rounded-lg border border-brand-200 bg-white px-5 py-4 open:shadow-sm"<?php echo ($first_open && $itemIdx === 0) ? ' open' : ''; ?>>
				<summary class="cursor-pointer list-none flex items-center justify-between gap-4">
					<span<?php echo ka_field_attr('title'); ?> class="text-lg font-bold text-brand-900"><?php echo esc_html($item['title'] ?? ''); ?></span>
					<span aria-hidden="true" class="flex-shrink-0 inline-flex items-center justify-center w-6 h-6 rounded-full bg-brand-50 text-accent-500 text-xl leading-none transition-transform duration-200 group-open:rotate-45">+</span>
				</summary>
				<?php if (!empty($item['body'])) : ?>
				<div<?php echo ka_field_attr('body'); ?> class="prose-content mt-3"><?php echo wp_kses_post($item['body']); ?></div>
				<?php endif; ?>
			</details>
			<?php endforeach; ?>
		</div>
	</div>
</section>
