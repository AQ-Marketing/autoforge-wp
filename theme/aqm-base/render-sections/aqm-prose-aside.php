<?php
/** AQM prose + aside — .sec-head + .prose-split: a long-form .prose column (full
 *  HTML: H3s, paragraphs, inline emphasis) beside an aside.prose-side of supporting
 *  media cards and "Did you know?" notes. Transliterated from the static interior
 *  pages (e.g. the Local SEO "details" section). */
$s     = $args['s'] ?? [];
$prose = (string) ($s['prose_html'] ?? '');
$aside = array_values(array_filter((array) ($s['aside_items'] ?? []), fn($a) => is_array($a) && (($a['img_src'] ?? '') !== '' || ($a['note_title'] ?? '') !== '' || ($a['note_body'] ?? '') !== '')));
?>
<section>
	<div class="wrap">
		<?php require __DIR__ . '/_sec-head.php'; ?>
		<div class="prose-split">
			<div class="prose"<?php echo ka_field_attr('prose_html'); ?>><?php echo wp_kses_post($prose); ?></div>
			<?php if ($aside) : ?>
			<aside class="prose-side" aria-label="Supporting media">
				<?php foreach ($aside as $i => $a) :
					$src = (string) ($a['img_src'] ?? '');
					$nt  = (string) ($a['note_title'] ?? '');
					$nb  = (string) ($a['note_body'] ?? ''); ?>
				<?php if ($src !== '') : ?>
				<div class="side-media"<?php echo ka_field_attr('aside_items', $i); ?>><img src="<?php echo esc_url($src); ?>" alt="<?php echo esc_attr($a['img_alt'] ?? ''); ?>" loading="lazy" decoding="async"></div>
				<?php endif; ?>
				<?php if ($nt !== '' || $nb !== '') : ?>
				<div class="side-note"><?php if ($nt !== '') : ?><h4><i class="fa-solid fa-circle-info"></i> <?php echo esc_html($nt); ?></h4><?php endif; ?><?php if ($nb !== '') : ?><p><?php echo wp_kses_post($nb); ?></p><?php endif; ?></div>
				<?php endif; ?>
				<?php endforeach; ?>
			</aside>
			<?php endif; ?>
		</div>
	</div>
</section>
