<?php
/** Team / staff grid — centered header (eyebrow / H2 / optional subheading /
 *  optional intro) above a responsive 2/3/4-column grid of member cards.
 *  Each card: square media-library photo, name, role, optional bio, optional
 *  link. Static CSS grid (column count from $cols); NO JavaScript. A NEW
 *  building block — markup follows the feature_cards / prose_with_image idioms
 *  for brand parity. */
$s       = $args['s'] ?? [];
$members = (array) ($s['members'] ?? []);

$bg   = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
$h2mt = ($s['h2_mt'] ?? 'mt-0') === 'mt-4' ? '!mt-4' : '!mt-0';

// Column count → responsive grid classes (mobile 1-col, tablet 2-col, desktop N).
$cols = (string) ($s['cols'] ?? '3');
$grid_map = [
	'2' => 'grid sm:grid-cols-2 gap-8 max-w-4xl mx-auto',
	'3' => 'grid sm:grid-cols-2 lg:grid-cols-3 gap-8',
	'4' => 'grid sm:grid-cols-2 lg:grid-cols-4 gap-8',
];
$grid = $grid_map[$cols] ?? $grid_map['3'];
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto text-center mb-12">
			<?php if (!empty($s['eyebrow'])) : ?>
			<span<?php echo ka_field_attr('eyebrow'); ?> class="pill-eyebrow mb-6"><?php echo wp_kses_post($s['eyebrow']); ?></span>
			<?php endif; ?>
			<h2<?php echo ka_field_attr('heading'); ?> class="<?php echo esc_attr($h2mt); ?>">
				<?php echo esc_html($s['heading'] ?? ''); ?>
				<?php if (!empty($s['subheading'])) : ?>
				<span<?php echo ka_field_attr('subheading'); ?> class="block h2-sub mt-1"><?php echo esc_html($s['subheading']); ?></span>
				<?php endif; ?>
			</h2>
			<?php if (!empty($s['intro'])) : ?>
			<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 mt-4"><?php echo wp_kses_post($s['intro']); ?></p>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr($grid); ?>">
			<?php foreach ($members as $mIdx => $m) : ?>
			<div<?php echo ka_field_attr('members', $mIdx); ?> class="card text-center p-6">
				<?php
				$photo = ka_picture_field($m['photo'] ?? null, [
					'class' => 'w-32 h-32 rounded-full object-cover mx-auto shadow-md',
					'sizes' => '128px',
				]);
				if ($photo) : ?>
				<div class="mb-5"><?php echo $photo; ?></div>
				<?php endif; ?>
				<?php if (!empty($m['name'])) : ?>
				<h3 class="!mt-0 text-lg font-bold text-brand-900"><?php echo esc_html($m['name']); ?></h3>
				<?php endif; ?>
				<?php if (!empty($m['role'])) : ?>
				<p class="mt-1 text-sm font-semibold uppercase tracking-wide text-accent-500"><?php echo esc_html($m['role']); ?></p>
				<?php endif; ?>
				<?php if (!empty($m['bio'])) : ?>
				<p class="mt-3 text-sm text-brand-700"><?php echo wp_kses_post($m['bio']); ?></p>
				<?php endif; ?>
				<?php if (!empty($m['link_href'])) : ?>
				<p class="mt-4">
					<a href="<?php echo esc_url($m['link_href']); ?>" class="inline-flex items-center gap-1.5 text-sm font-semibold text-accent-600 hover:text-accent-700 no-underline">
						<?php echo esc_html($m['link_label'] ?? 'Learn more'); ?>
						<svg width="12" height="12" viewBox="0 0 448 512" fill="currentColor" aria-hidden="true"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"></path></svg>
					</a>
				</p>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
