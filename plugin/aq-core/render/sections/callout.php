<?php
/** Callout — a self-contained alert / notice box: a colored left border + tinted
 *  panel holding an optional leading icon, optional bold title, and a rich-text
 *  body. Four styles selected via a static PHP class map — NO JavaScript. They
 *  map onto the engine's neutral tokens: info & success = brand (slate), warning
 *  & danger = accent (the strongest "attention" tone). A client repaints these
 *  via design tokens. Leans on the theme's Tailwind tokens + component classes
 *  (container-edge, prose-content). */
$s     = $args['s'] ?? [];
$style = (string) ($s['style'] ?? 'info');

// Static style map → border + panel tint + icon color. Class strings are written
// out in full (not interpolated) so Tailwind's content scanner keeps them.
$styles = [
	'info' => [
		'panel' => 'bg-brand-50 border-brand-300',
		'icon'  => 'text-brand-700',
		'title' => 'text-brand-900',
	],
	'success' => [
		'panel' => 'bg-brand-500/10 border-brand-500',
		'icon'  => 'text-brand-600',
		'title' => 'text-brand-700',
	],
	'warning' => [
		'panel' => 'bg-accent-50 border-accent-400',
		'icon'  => 'text-accent-700',
		'title' => 'text-accent-700',
	],
	'danger' => [
		'panel' => 'bg-accent-50 border-accent-700',
		'icon'  => 'text-accent-800',
		'title' => 'text-accent-800',
	],
];
$cls = $styles[$style] ?? $styles['info'];

$bg = ($s['bg'] ?? 'white') === 'brand-50' ? 'bg-brand-50' : 'bg-white';
?>
<section class="<?php echo esc_attr($bg); ?> py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="max-w-3xl mx-auto rounded-lg border-l-4 p-6 md:p-7 shadow-sm <?php echo esc_attr($cls['panel']); ?>">
			<div class="flex items-start gap-4">
				<?php if (!empty($s['icon_svg'])) : ?>
				<div<?php echo ka_field_attr('icon_svg'); ?> class="flex-shrink-0 w-6 h-6 mt-0.5 <?php echo esc_attr($cls['icon']); ?>">
					<?php echo $s['icon_svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — curated inline SVG from the icon picker (code-mode-only, like dark_card_grid.icon_svg) ?>
				</div>
				<?php endif; ?>
				<div class="flex-1 min-w-0">
					<?php if (!empty($s['title'])) : ?>
					<p<?php echo ka_field_attr('title'); ?> class="font-bold text-lg leading-snug <?php echo esc_attr($cls['title']); ?>"><?php echo esc_html($s['title']); ?></p>
					<?php endif; ?>
					<?php if (!empty($s['body'])) : ?>
					<div<?php echo ka_field_attr('body'); ?> class="prose-content <?php echo !empty($s['title']) ? 'mt-2' : ''; ?>"><?php echo wp_kses_post($s['body']); ?></div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</section>
