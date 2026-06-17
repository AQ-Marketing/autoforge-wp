<?php
/** Dark card grid — bg-brand-900, icon-badge cards, gold Learn More links. */
$s = $args['s'] ?? [];
$h2mt    = ($s['h2_mt'] ?? 'mt-0') === 'mt-4' ? '!mt-4' : '!mt-0';
$compact = !empty($s['compact']);
$h3_cls  = $compact ? '!mt-0 text-[20px] leading-tight font-bold text-white' : '!mt-0 text-[22px] leading-tight font-bold text-white';
$body_cls = $compact ? 'text-[15px] leading-[1.7em] mb-3 text-on-dark-base' : 'text-[16px] leading-[1.7em] mb-3 text-on-dark-base';
$link_cls = $compact ? 'inline-flex items-center gap-2 no-underline link-learn-more' : 'inline-flex items-center gap-2 no-underline link-learn-more--lg';
$arrow_sz = $compact ? 13 : 14;
?>
<section class="bg-brand-900 text-white py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo $h2mt; ?> text-white">
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
			<?php foreach ((array) ($s['cards'] ?? []) as $cardIdx => $card) :
				$title = (string) ($card['title'] ?? '');
				$aria  = $card['link_aria'] ?: ('Learn more about ' . wp_strip_all_tags($title)); ?>
			<div<?php echo ka_field_attr('cards', $cardIdx); ?> class="bg-white/[0.05] border border-white/[0.13] rounded-md p-5">
				<div class="flex items-center gap-4 mb-2.5">
					<div<?php echo ka_field_attr('icon_svg'); ?> class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 icon-badge">
						<?php echo $card['icon_svg'] ?? ''; ?>
					</div>
					<h3<?php echo ka_field_attr('title'); ?> class="<?php echo esc_attr($h3_cls); ?>"><?php echo wp_kses_post($title); ?></h3>
				</div>
				<p<?php echo ka_field_attr('body'); ?> class="<?php echo esc_attr($body_cls); ?>"><?php echo wp_kses_post($card['body'] ?? ''); ?></p>
				<?php if (!empty($card['link_href'])) : ?>
				<a<?php echo ka_field_attr('link_label'); ?> href="<?php echo esc_url($card['link_href']); ?>" aria-label="<?php echo esc_attr($aria); ?>" class="<?php echo esc_attr($link_cls); ?>">
					<span class="sr-only"><?php echo esc_html($aria); ?> &mdash; </span><?php echo esc_html($card['link_label'] ?: 'Learn More'); ?>
					<svg width="<?php echo $arrow_sz; ?>" height="<?php echo $arrow_sz; ?>" viewBox="0 0 448 512" fill="#f9ab3d"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"/></svg>
				</a>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
