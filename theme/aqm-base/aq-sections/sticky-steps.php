<?php
/** Sticky Steps — stacking step cards that scale back as the next arrives
 *  (home.js matchMedia scrub, ≥721px). Structural/computed: the ghost numeral,
 *  the "0X / 0N" count, and the --i inline offset (required by the CSS sticky
 *  stack). Editable: header + each step (tag/title/body/meta). `meta` is a
 *  newline list rendered to <li> chips. JS: home.js data-split. */
$s     = $args['s'] ?? [];
$num   = (string) ($s['num'] ?? '');
$steps = array_values(array_filter((array) ($s['steps'] ?? []), fn($st) => is_array($st) && (($st['title'] ?? '') !== '' || ($st['body'] ?? '') !== '')));
$total = count($steps);
?>
<section class="hm-sec hm-light">
	<?php if ($num !== '') : ?><span class="hm-ghost" aria-hidden="true"><?php echo esc_html($num); ?></span><?php endif; ?>
	<div class="wrap">
		<div class="hm-head">
			<span class="hm-kicker"><?php if ($num !== '') : ?><i><?php echo esc_html($num); ?></i><?php endif; ?><span<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($s['kicker'] ?? ''); ?></span></span>
			<h2 class="hm-title" data-split><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <em<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></em><?php endif; ?></h2>
		</div>
		<div class="hm-stack">
			<?php foreach ($steps as $i => $st) :
				$gn    = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
				$tot   = str_pad((string) $total, 2, '0', STR_PAD_LEFT);
				$final = $i === $total - 1 ? ' hm-step-final' : '';
				$meta  = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($st['meta'] ?? ''))), fn($m) => $m !== '')); ?>
			<article class="hm-step<?php echo $final; ?>" style="--i:<?php echo (int) $i; ?>"<?php echo ka_field_attr('steps', $i); ?>>
				<span class="hm-step-ghost" aria-hidden="true"><?php echo esc_html($gn); ?></span>
				<div class="hm-step-top"><span class="hm-step-tag"<?php echo ka_field_attr('tag'); ?>><?php echo esc_html($st['tag'] ?? ''); ?></span><span class="hm-step-count"><?php echo esc_html($gn . ' / ' . $tot); ?></span></div>
				<div class="hm-step-body">
					<h3<?php echo ka_field_attr('title'); ?>><?php echo esc_html($st['title'] ?? ''); ?></h3>
					<div>
						<?php if (($st['body'] ?? '') !== '') : ?><p<?php echo ka_field_attr('body'); ?>><?php echo esc_html($st['body']); ?></p><?php endif; ?>
						<?php if ($meta) : ?><ul class="hm-step-meta"<?php echo ka_field_attr('meta'); ?>><?php foreach ($meta as $m) : ?><li><?php echo esc_html($m); ?></li><?php endforeach; ?></ul><?php endif; ?>
					</div>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
