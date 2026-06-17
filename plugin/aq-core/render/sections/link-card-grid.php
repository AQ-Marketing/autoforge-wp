<?php
/** Grid of linked cards.
 *  Variants:
 *   - "bare"  city × service: plain H2, sm:2-col, no arrow/aria.
 *   - "dark"  city-hub service grid: bg-brand-900, centered header
 *             (eyebrow + H2 + intro), translucent cards, gold "Learn More".
 *   - "light" city-hub specialty grid: bg-brand-50, centered header,
 *             .card cards, gold "Learn More". */
$s       = $args['s'] ?? [];
$cards   = (array) ($s['cards'] ?? []);
$variant = $s['variant'] ?? 'bare';
?>
<?php if ($variant === 'dark' || $variant === 'light') :
	$dark        = $variant === 'dark';
	$cols        = ((string) ($s['cols'] ?? '3')) === '4' ? '4' : '3';
	$light_bg    = ($s['bg'] ?? 'brand-50') === 'white' ? 'bg-white' : 'bg-brand-50';
	$section_cls = $dark ? 'bg-brand-900 text-white py-12 md:py-16 lg:py-20' : $light_bg . ' py-12 md:py-16 lg:py-20';
	$h2_cls      = $dark ? '!mt-4 text-white' : '!mt-4';
	$intro_cls   = $dark ? 'text-brand-100 mt-4' : 'text-brand-700 mt-4';
	// lg:grid-cols-3 and lg:grid-cols-4 both appear literally elsewhere, so JIT emits them.
	$grid_cls    = $dark ? "grid md:grid-cols-2 lg:grid-cols-$cols gap-5" : "grid sm:grid-cols-2 lg:grid-cols-$cols gap-5";
	$card_cls    = $dark
		? 'bg-white/[0.05] border border-white/[0.13] rounded-md p-5 no-underline block group hover:bg-white/[0.08] transition'
		: 'card no-underline block group hover:border-accent-300 transition';
	$card_h3     = $dark ? '!mt-0 text-base font-bold text-white group-hover:text-accent-300' : '!mt-0 text-base font-bold text-brand-900 group-hover:text-accent-600';
	$card_p      = $dark ? 'text-sm mt-2 leading-snug text-on-dark-muted' : 'text-brand-600 text-sm mt-2 leading-snug';
?>
<section class="<?php echo esc_attr($section_cls); ?>">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo esc_attr($h2_cls); ?>">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="<?php echo esc_attr($intro_cls); ?>"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr($grid_cls); ?>">
			<?php foreach ($cards as $cardIdx => $card) :
				$aria = (string) ($card['aria'] ?? ''); ?>
			<a<?php echo ka_field_attr('cards', $cardIdx); ?> href="<?php echo esc_url($card['href'] ?? '#'); ?>"<?php echo $aria ? ' aria-label="' . esc_attr($aria) . '"' : ''; ?> class="<?php echo esc_attr($card_cls); ?>">
				<h3<?php echo ka_field_attr('title'); ?> class="<?php echo esc_attr($card_h3); ?>"> <?php echo esc_html($card['title'] ?? ''); ?> </h3>
				<p<?php echo ka_field_attr('body'); ?> class="<?php echo esc_attr($card_p); ?>"><?php echo wp_kses_post($card['body'] ?? ''); ?></p>
				<?php if (!empty($card['note'])) : ?>
				<p<?php echo ka_field_attr('note'); ?> class="text-xs text-brand-400 mt-2"><?php echo wp_kses_post($card['note']); ?></p>
				<?php endif; ?>
				<span class="inline-flex items-center gap-1.5 mt-4 text-sm font-semibold text-accent-500">
					Learn More
					<svg width="12" height="12" viewBox="0 0 448 512" fill="#f9ab3d"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"></path></svg>
				</span>
			</a>
			<?php endforeach; ?>
		</div>
		<?php if (!empty($s['cta_label'])) : ?>
		<div class="mt-8 text-center">
			<a<?php echo ka_field_attr('cta_label'); ?> href="<?php echo esc_url($s['cta_href'] ?? '#'); ?>" class="btn-primary text-xs sm:text-sm uppercase tracking-wide sm:tracking-wider"><?php echo esc_html($s['cta_label']); ?></a>
		</div>
		<?php endif; ?>
	</div>
</section>
<?php else : ?>
<section class="<?php echo esc_attr($s['wrapper_class'] ?? 'mt-10 max-w-4xl mx-auto'); ?>">
	<?php if (!empty($s['heading'])) : ?>
	<h2<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading']); ?></h2>
	<?php endif; ?>
	<div class="grid sm:grid-cols-2 gap-3 mt-4">
		<?php foreach ($cards as $cardIdx => $card) : ?>
		<a<?php echo ka_field_attr('cards', $cardIdx); ?> href="<?php echo esc_url($card['href'] ?? '#'); ?>" class="card no-underline block group">
			<h3<?php echo ka_field_attr('title'); ?> class="!mt-0 text-base group-hover:text-accent-700"><?php echo esc_html($card['title'] ?? ''); ?></h3>
			<p<?php echo ka_field_attr('body'); ?> class="text-brand-700 text-sm mt-1"><?php echo wp_kses_post($card['body'] ?? ''); ?></p>
		</a>
		<?php endforeach; ?>
	</div>
</section>
<?php if (!empty($s['close_article'])) : ?>
</article>
<?php endif; ?>
<?php endif; ?>
