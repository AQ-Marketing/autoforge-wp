<?php
/** Video — responsive video embed. Centered optional header (eyebrow / H2 /
 *  subheading / intro) above a single video frame locked to a 16:9 or 4:3
 *  aspect ratio via a static CSS aspect box (NO JS). Provider = youtube|vimeo
 *  renders a privacy-friendly iframe built from the video id alone; provider =
 *  file renders a native <video controls> from a direct .mp4/.webm URL with an
 *  optional media-library poster. Optional caption beneath the frame; max_width
 *  caps and centers the player. Uses the same on-brand container/pill-eyebrow/
 *  h2-sub idioms as feature_cards / step_cards. */
$s        = $args['s'] ?? [];
$provider = (string) ($s['provider'] ?? 'youtube');
$video_id = trim((string) ($s['video_id'] ?? ''));
$file_url = trim((string) ($s['file_url'] ?? ''));

$bg          = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$section_cls = !empty($s['section_class']) ? (string) $s['section_class'] : ($bg . ' py-12 md:py-16 lg:py-20');

$aspect_cls = ($s['aspect'] ?? '16/9') === '4/3' ? 'aspect-[4/3]' : 'aspect-video';
$max_map    = ['3xl' => 'max-w-3xl', '4xl' => 'max-w-4xl', '5xl' => 'max-w-5xl', 'full' => ''];
$max_cls    = $max_map[$s['max_width'] ?? '4xl'] ?? 'max-w-4xl';
$wrap_cls   = trim('mx-auto ' . $max_cls);

// Build the iframe src for hosted providers from the id alone.
$iframe_src = '';
if ($provider === 'youtube' && $video_id !== '') {
	$iframe_src = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($video_id) . '?rel=0';
} elseif ($provider === 'vimeo' && $video_id !== '') {
	$iframe_src = 'https://player.vimeo.com/video/' . rawurlencode($video_id);
}

$has_header = !empty($s['eyebrow']) || !empty($s['heading']) || !empty($s['subheading']) || !empty($s['intro']);
?>
<section class="<?php echo esc_attr($section_cls); ?>">
	<div class="container-edge container-edge--wide">
		<?php if ($has_header) : ?>
		<div class="max-w-3xl mx-auto text-center mb-12">
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
			<?php endif; ?>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<div class="<?php echo esc_attr($wrap_cls); ?>">
			<div class="<?php echo esc_attr($aspect_cls); ?> w-full overflow-hidden rounded-lg shadow-lg bg-brand-900">
				<?php if ($provider === 'file' && $file_url !== '') : ?>
				<video
					class="w-full h-full object-cover"
					controls
					preload="metadata"
					<?php if (!empty($s['poster'])) : $poster = wp_get_attachment_image_url((int) $s['poster'], 'large'); if ($poster) : ?>poster="<?php echo esc_url($poster); ?>"<?php endif; endif; ?>
				>
					<source src="<?php echo esc_url($file_url); ?>" />
				</video>
				<?php elseif ($iframe_src !== '') : ?>
				<iframe
					class="w-full h-full"
					src="<?php echo esc_url($iframe_src); ?>"
					title="<?php echo esc_attr($s['heading'] ?? 'Video'); ?>"
					loading="lazy"
					frameborder="0"
					allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
					allowfullscreen
				></iframe>
				<?php else : ?>
				<div class="w-full h-full flex items-center justify-center text-center p-6">
					<p class="text-brand-50 text-sm">Add a video ID or file URL to display the player.</p>
				</div>
				<?php endif; ?>
			</div>
			<?php if (!empty($s['caption'])) : ?>
			<p<?php echo ka_field_attr('caption'); ?> class="text-center text-sm text-brand-700 mt-4"><?php echo wp_kses_post($s['caption']); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>