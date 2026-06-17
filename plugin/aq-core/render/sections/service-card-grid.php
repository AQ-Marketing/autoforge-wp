<?php
/** Service card grid — bg-brand-900 flex-col cards used by the services and
 *  testing-and-specialty index hubs. Each card: icon-badge + title, a flex-1
 *  body, an optional price row (two pill spans), and a "Full Details" link.
 *  Distinct from dark_card_grid (home-style: no flex-col, no price, sr-only). */
$s     = $args['s'] ?? [];
$cards = (array) ($s['cards'] ?? []);
?>
<section class="bg-brand-900 text-white py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
			<h2<?php echo ka_field_attr('heading'); ?> class="!mt-4 text-white">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-100 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-5">
			<?php foreach ($cards as $cardIdx => $card) :
				$has_price = !empty($card['price_primary']) || !empty($card['price_secondary']); ?>
			<div<?php echo ka_field_attr('cards', $cardIdx); ?> class="bg-white/[0.05] border border-white/[0.13] rounded-md p-5 flex flex-col">
				<div class="flex items-center gap-4 mb-3">
					<div<?php echo ka_field_attr('icon_svg'); ?> class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 icon-badge">
						<?php echo $card['icon_svg'] ?? ''; ?>
					</div>
					<h3<?php echo ka_field_attr('title'); ?> class="!mt-0 text-[19px] leading-tight font-bold text-white"><?php echo wp_kses_post($card['title'] ?? ''); ?></h3>
				</div>
				<p<?php echo ka_field_attr('body'); ?> class="text-[15px] leading-[1.7em] flex-1 text-on-dark-base"><?php echo wp_kses_post($card['body'] ?? ''); ?></p>
				<?php if ($has_price) : ?>
				<div class="border-t border-white/10 flex flex-wrap gap-x-4 gap-y-1 mt-4 pt-4">
					<?php if (!empty($card['price_primary'])) : ?>
					<span<?php echo ka_field_attr('price_primary'); ?> class="font-semibold text-accent-300 text-xs"><?php echo esc_html($card['price_primary']); ?></span>
					<?php endif; ?>
					<?php if (!empty($card['price_secondary'])) : ?>
					<span<?php echo ka_field_attr('price_secondary'); ?> class="text-white/60 text-xs"><?php echo esc_html($card['price_secondary']); ?></span>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<?php if (!empty($card['link_href'])) : ?>
				<a<?php echo ka_field_attr('link_label'); ?> href="<?php echo esc_url($card['link_href']); ?>" class="inline-flex items-center gap-2 mt-4 no-underline link-learn-more">
					<?php echo esc_html($card['link_label'] ?: 'Full Details'); ?>
					<svg width="13" height="13" viewBox="0 0 448 512" fill="#f9ab3d"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"></path></svg>
				</a>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
