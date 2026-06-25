<?php
/** City hero — full-bleed bg image + navy overlay, eyebrow, two-line H1, buttons.
 *  Distinct from the home `hero`: plain `pill-eyebrow`, no `capitalize`, the
 *  second H1 line uses `h1-sub` (not text-accent-500), and there is no intro. */
$s = $args['s'] ?? [];
// Fall back to the global hero buttons (aq_site('hero_ctas')) when a page
// defines no ctas of its own, so the buttons are editable site-wide.
if (empty($s['ctas']) && function_exists('aq_site')) {
	$s['ctas'] = aq_site('hero_ctas') ?: [];
}
?>
<section class="relative bg-brand-900 text-white overflow-hidden">
	<?php echo ka_picture_field($s['image'] ?? null, [
		'size' => 'ka-1280',
		'sizes' => '100vw',
		'class' => 'absolute inset-0 w-full h-full object-cover',
		'loading' => 'eager',
		'fetchpriority' => 'high',
	]); ?>
	<div class="absolute inset-0 overlay-hero"></div>
	<div class="relative container-edge container-edge--wide py-12 md:py-[72px] lg:py-[100px]">
		<div class="flex flex-col gap-6 items-start">
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
			<h1 class="!mt-0 !mb-0 font-serif font-bold leading-[1.2] text-[22px] sm:text-[32px] lg:text-[42px]">
				<span<?php echo ka_field_attr('heading'); ?> class="block text-white"><?php echo esc_html($s['heading'] ?? ''); ?></span>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block mt-1 <?php echo ($s['sub_style'] ?? '') === 'text-accent-500' ? 'text-accent-500' : 'h1-sub'; ?>"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h1>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="max-w-[860px] text-on-dark"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
			<?php if (!empty($s['badges'])) : ?>
			<div class="flex flex-wrap gap-3">
				<?php foreach ($s['badges'] as $badgeIdx => $badge) : ?>
				<span<?php echo ka_field_attr('badges', $badgeIdx); ?> class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold bg-white/10 text-white/80 border border-white/20"><?php echo wp_kses_post($badge['text'] ?? ''); ?></span>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<?php if (!empty($s['ctas'])) : ?>
			<div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
				<?php foreach ($s['ctas'] as $ctaIdx => $cta) :
					$style = ($cta['style'] ?? 'primary') === 'secondary' ? 'hero-btn hero-btn--secondary' : 'hero-btn hero-btn--primary'; ?>
				<a<?php echo ka_field_attr('ctas', $ctaIdx); ?> href="<?php echo esc_url($cta['href'] ?? '#'); ?>" class="<?php echo esc_attr($style); ?>"><?php echo esc_html($cta['label'] ?? ''); ?></a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</div>
</section>
