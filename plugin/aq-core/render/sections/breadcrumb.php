<?php
/** Breadcrumb nav. BreadcrumbList JSON-LD is emitted by aq-core from page ancestry. */
$s       = $args['s'] ?? [];
$items   = (array) ($s['items'] ?? []);
$variant = $s['variant'] ?? 'plain';
$wide    = $variant === 'wide';
if (!$items) {
	return;
}
?>
<?php if ($variant === 'wide_index') : ?>
<nav aria-label="Breadcrumb" class="bg-brand-50 border-b border-brand-100">
	<div class="container-edge container-edge--wide py-2.5">
		<ol class="flex items-center gap-1.5 text-sm text-brand-500 flex-wrap">
			<?php foreach ($items as $i => $it) :
				$label = (string) ($it['label'] ?? '');
				$url   = (string) ($it['url'] ?? ''); ?>
			<li<?php echo $i > 0 ? ' class="flex items-center gap-1.5"' : ''; ?>>
				<?php if ($i > 0) : ?><span aria-hidden="true" class="text-brand-300">/</span><?php endif; ?>
				<?php if ($url === '') : ?>
				<span<?php echo ka_field_attr('items', $i); ?> class="text-brand-700 font-medium"><?php echo esc_html($label); ?></span>
				<?php else : ?>
				<a<?php echo ka_field_attr('items', $i); ?> href="<?php echo esc_url($url); ?>" class="hover:text-accent-600 no-underline text-brand-500"><?php echo esc_html($label); ?></a>
				<?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ol>
	</div>
</nav>
<?php elseif ($wide) : ?>
<nav aria-label="Breadcrumb" class="bg-brand-50 border-b border-brand-100">
	<div class="container-edge container-edge--wide py-2.5">
		<ol class="flex items-center gap-1.5 text-sm text-brand-500 flex-wrap">
			<?php foreach ($items as $i => $it) :
				$label = (string) ($it['label'] ?? '');
				$url   = (string) ($it['url'] ?? ''); ?>
			<li class="flex items-center gap-1.5">
				<?php if ($i > 0) : ?><span aria-hidden="true" class="text-brand-300">/</span><?php endif; ?>
				<?php if ($url === '') : ?>
				<span<?php echo ka_field_attr('items', $i); ?> class="text-brand-700 font-medium"><?php echo esc_html($label); ?></span>
				<?php else : ?>
				<a<?php echo ka_field_attr('items', $i); ?> href="<?php echo esc_url($url); ?>" class="hover:text-accent-600 no-underline text-brand-500"><?php echo esc_html($label); ?></a>
				<?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ol>
	</div>
</nav>
<?php else : ?>
<nav aria-label="Breadcrumb" class="container-x text-sm text-brand-600 py-3">
	<ol class="flex flex-wrap items-center gap-1.5">
		<?php foreach ($items as $i => $it) :
			$label = (string) ($it['label'] ?? '');
			$url   = (string) ($it['url'] ?? ''); ?>
		<li class="flex items-center gap-1.5">
			<?php if ($i > 0) : ?><span aria-hidden="true" class="text-brand-300">/</span><?php endif; ?>
			<?php if ($url === '') : ?>
			<span aria-current="page"<?php echo ka_field_attr('items', $i); ?> class="text-brand-700 font-medium"><?php echo esc_html($label); ?></span>
			<?php else : ?>
			<a<?php echo ka_field_attr('items', $i); ?> href="<?php echo esc_url($url); ?>" class="no-underline hover:underline"><?php echo esc_html($label); ?></a>
			<?php endif; ?>
		</li>
		<?php endforeach; ?>
	</ol>
</nav>
<?php endif; ?>
