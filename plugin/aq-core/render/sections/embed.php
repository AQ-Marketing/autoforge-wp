<?php
/** Embed — generic responsive iframe for maps, calendars, scheduler iframes,
 *  video, or any embeddable URL. Optional centered header (eyebrow / H2 /
 *  subheading + intro) sits above a fixed-aspect-ratio frame held by a static
 *  CSS wrapper (aspect-ratio utility — NO JavaScript). The iframe width is
 *  constrained by a selectable max-width and centered; an optional caption
 *  renders below. If no URL is set the frame is skipped so an empty section
 *  never shows a broken iframe. */
$s = $args['s'] ?? [];

$bg          = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$url         = trim((string) ($s['embed_url'] ?? ''));
$has_header  = !empty($s['eyebrow']) || !empty($s['heading']) || !empty($s['subheading']) || !empty($s['intro']);

$aspect_map  = ['16x9' => 'aspect-[16/9]', '4x3' => 'aspect-[4/3]', '1x1' => 'aspect-square'];
$aspect      = $aspect_map[$s['aspect'] ?? '16x9'] ?? 'aspect-[16/9]';

$mw_map      = ['full' => '', '4xl' => 'max-w-4xl mx-auto', '3xl' => 'max-w-3xl mx-auto', '2xl' => 'max-w-2xl mx-auto'];
$mw          = $mw_map[$s['max_width'] ?? 'full'] ?? '';

$title       = (string) ($s['iframe_title'] ?? '');
if ($title === '') { $title = (string) ($s['heading'] ?? ''); }
if ($title === '') { $title = 'Embedded content'; }
$allow_fs    = !empty($s['allow_fullscreen']);
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<?php if ($has_header) : ?>
		<div class="max-w-3xl mx-auto text-center mb-10 md:mb-12">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<?php if (!empty($s['heading'])) : ?>
			<h2<?php echo ka_field_attr('heading'); ?> class="!mt-4">
				<?php echo esc_html($s['heading']); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php elseif (!empty($s['subheading'])) : ?>
			<p<?php echo ka_field_attr('subheading'); ?> class="h2-sub"><?php echo esc_html($s['subheading']); ?></p>
			<?php endif; ?>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<div class="<?php echo esc_attr(trim($mw)); ?>">
			<?php if ($url !== '') : ?>
			<div<?php echo ka_field_attr('embed_url'); ?> class="<?php echo esc_attr($aspect); ?> w-full overflow-hidden rounded-lg shadow-lg bg-brand-50">
				<iframe
					src="<?php echo esc_url($url); ?>"
					title="<?php echo esc_attr($title); ?>"
					loading="lazy"
					class="w-full h-full border-0"
					referrerpolicy="no-referrer-when-downgrade"
					<?php if ($allow_fs) : ?>allowfullscreen<?php endif; ?>></iframe>
			</div>
			<?php else : ?>
			<div class="<?php echo esc_attr($aspect); ?> w-full flex items-center justify-center rounded-lg border-2 border-dashed border-brand-200 bg-brand-50 text-brand-500 text-sm">
				Add an embed URL to display content here.
			</div>
			<?php endif; ?>
			<?php if (!empty($s['caption'])) : ?>
			<p<?php echo ka_field_attr('caption'); ?> class="text-sm text-brand-700 mt-4 text-center"><?php echo wp_kses_post($s['caption']); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
