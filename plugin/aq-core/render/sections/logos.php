<?php
/** Logo Grid — centered header (eyebrow / H2 / subheading / intro) above a
 *  responsive grid of client/partner logos. Each logo is a media-library image
 *  with its own alt text and an optional link. Column count is selectable
 *  (3-6, default 4) and an optional grayscale toggle mutes logos until hover.
 *  Static only: plain CSS grid + CSS-only hover, no JavaScript. */
$s     = $args['s'] ?? [];
$logos = array_values(array_filter((array) ($s['logos'] ?? []), fn($l) => is_array($l) && !empty($l['image'])));

$bg  = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';

// Desktop columns (mobile = 2, tablet = 3, then the chosen count at lg).
$cols_map = [
	'3' => 'grid-cols-2 sm:grid-cols-3',
	'4' => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4',
	'5' => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5',
	'6' => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6',
];
$cols = $cols_map[(string) ($s['columns'] ?? '4')] ?? $cols_map['4'];

$gray = !empty($s['grayscale'])
	? 'grayscale opacity-70 transition duration-200 hover:grayscale-0 hover:opacity-100'
	: '';
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<?php if (!empty($s['eyebrow']) || !empty($s['heading']) || !empty($s['subheading']) || !empty($s['intro'])) : ?>
		<div class="max-w-3xl mx-auto text-center mb-12">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<h2<?php echo ka_field_attr('heading'); ?> class="!mt-4">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php if ($logos) : ?>
		<ul class="grid <?php echo esc_attr($cols); ?> gap-x-8 gap-y-10 items-center max-w-5xl mx-auto list-none p-0 m-0">
			<?php foreach ($logos as $i => $logo) :
				$alt    = trim((string) ($logo['alt'] ?? ''));
				$img    = ka_picture_field($logo['image'] ?? null, [
					'class' => 'max-h-12 md:max-h-14 w-auto object-contain' . ($gray ? ' ' . $gray : ''),
					'sizes' => '(min-width: 1024px) 16vw, (min-width: 640px) 30vw, 45vw',
				]);
				if ($img === '') {
					continue;
				}
				// Inject a custom alt when the editor supplied one (overrides media-library alt).
				if ($alt !== '') {
					$img = preg_replace('/\salt="[^"]*"/', '', $img, 1);
					$img = preg_replace('/<img\b/', '<img alt="' . esc_attr($alt) . '"', $img, 1);
				}
				$href = trim((string) ($logo['href'] ?? ''));
			?>
			<li<?php echo ka_field_attr('logos', $i); ?> class="flex items-center justify-center">
				<?php if ($href !== '') : ?>
				<a href="<?php echo esc_url($href); ?>" class="inline-flex items-center justify-center no-underline"<?php echo $alt !== '' ? ' aria-label="' . esc_attr($alt) . '"' : ''; ?>><?php echo $img; ?></a>
				<?php else : ?>
				<?php echo $img; ?>
				<?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
	</div>
</section>
