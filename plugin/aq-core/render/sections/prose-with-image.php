<?php
/** Prose + image — eyebrow/H2/paragraphs/checklist beside an image.
 *  Configurable: image_side (left|right), col_ratio (e.g. "55fr_40fr"),
 *  align (start|center). Used by the city-hub "Local Knowledge" (text left,
 *  image right, items-start) and "Why Local Knowledge Matters" (image left,
 *  text right, items-center) blocks. The H2 uses !mt-4 (vs the home
 *  why_overview/trust_image_left which use !mt-0). */
$s          = $args['s'] ?? [];
$ratio      = (string) ($s['col_ratio'] ?? '55fr_40fr');
$align      = ($s['align'] ?? 'start') === 'center' ? 'items-center' : 'items-start';
$image_left = ($s['image_side'] ?? 'right') === 'left';

$img_extra = trim((string) ($s['image_wrap_class'] ?? ''));
$text_div  = $image_left ? '<div class="order-1 lg:order-2">' : '<div>';
$image_div = $image_left
	? '<div' . ka_field_attr('image') . ' class="order-2 lg:order-1' . ($img_extra ? ' ' . esc_attr($img_extra) : '') . '">'
	: ($img_extra ? '<div' . ka_field_attr('image') . ' class="' . esc_attr($img_extra) . '">' : '<div' . ka_field_attr('image') . '>');

ob_start(); ?>
		<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow'] ?? ''); ?></span>
		<h2<?php echo ka_field_attr('heading'); ?> class="!mt-4">
			<?php echo esc_html($s['heading'] ?? ''); ?>
			<?php if (!empty($s['subheading'])) : ?>
			<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
			<?php endif; ?>
		</h2>
		<?php
		// Filter empties: ACF returns '' for an unset repeater and (array)'' === [''],
		// which would otherwise render one stray empty <p>.
		$pwi_paras = array_values(array_filter((array) ($s['paragraphs'] ?? []), fn($p) => is_array($p) && ($p['html'] ?? '') !== ''));
		foreach ($pwi_paras as $i => $p) : ?>
		<p<?php echo ka_field_attr('paragraphs', $i); ?> class="text-brand-700 <?php echo $i === 0 ? 'mt-6' : 'mt-4'; ?>"><?php echo wp_kses_post($p['html'] ?? ''); ?></p>
		<?php endforeach; ?>
		<?php if (!empty($s['checklist'])) : ?>
		<ul class="mt-6 space-y-3 text-brand-700">
			<?php foreach ($s['checklist'] as $clIdx => $item) : ?>
			<li<?php echo ka_field_attr('checklist', $clIdx); ?> class="flex items-start gap-3">
				<span class="mt-1 inline-flex items-center justify-center w-5 h-5 rounded-full bg-accent-500 text-white flex-shrink-0">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"></path></svg>
				</span>
				<span<?php echo ka_field_attr('text'); ?>><?php echo wp_kses_post($item['text'] ?? ''); ?></span>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<?php if (!empty($s['link_list'])) : ?>
		<ul class="mt-8 space-y-3">
			<?php foreach ($s['link_list'] as $llIdx => $item) : ?>
			<li<?php echo ka_field_attr('link_list', $llIdx); ?>>
				<a href="<?php echo esc_url($item['href'] ?? '#'); ?>" class="flex items-center justify-between gap-4 rounded-md border border-brand-200 px-4 py-3 no-underline hover:border-accent-400 hover:bg-brand-50 transition group">
					<span<?php echo ka_field_attr('label'); ?> class="text-brand-800 font-medium group-hover:text-accent-600"><?php echo esc_html($item['label'] ?? ''); ?></span>
					<span<?php echo ka_field_attr('link_text'); ?> class="text-sm font-semibold whitespace-nowrap flex items-center gap-1.5 text-accent-500">
						<?php echo esc_html($item['link_text'] ?? ''); ?>
						<svg width="12" height="12" viewBox="0 0 448 512" fill="#f9ab3d"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"></path></svg>
					</span>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<?php if (!empty($s['footnote'])) : ?>
		<p<?php echo ka_field_attr('footnote'); ?> class="<?php echo esc_attr($s['footnote_mt'] ?? 'mt-4'); ?> text-brand-700 text-sm"><?php echo wp_kses_post($s['footnote']); ?></p>
		<?php endif; ?>
		<?php if (!empty($s['cta_href'])) : ?>
		<p class="mt-8">
			<a<?php echo ka_field_attr('cta_label'); ?> href="<?php echo esc_url($s['cta_href']); ?>" class="btn-primary text-xs sm:text-sm uppercase tracking-wide sm:tracking-wider"><?php echo esc_html($s['cta_label'] ?? ''); ?></a>
		</p>
		<?php endif; ?>
<?php $text = ob_get_clean();

$image = ka_picture_field($s['image'] ?? null, [
	'class' => 'w-full h-auto rounded-lg shadow-lg object-cover aspect-[4/3]',
]);
?>
<section class="<?php echo ($s['bg'] ?? '') === 'brand-50' ? 'bg-brand-50' : 'bg-white'; ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="grid lg:grid-cols-[<?php echo esc_attr($ratio); ?>] gap-10 lg:gap-[5%] <?php echo $align; ?>">
			<?php if ($image_left) : ?>
			<?php echo $image_div; ?><?php echo $image; ?></div>
			<?php echo $text_div . $text; ?></div>
			<?php else : ?>
			<?php echo $text_div . $text; ?></div>
			<?php echo $image_div; ?><?php echo $image; ?></div>
			<?php endif; ?>
		</div>
	</div>
</section>
