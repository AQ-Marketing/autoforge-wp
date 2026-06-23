<?php
/** AQM interior page hero (.page-hero) + breadcrumb (.crumbs). Transliterated 1:1
 *  from the static interior pages (e.g. services/local-seo/index.html). Editable:
 *  badge (+ FA icon), H1 (inline <em> accent allowed), lede, CTA buttons (each an
 *  optional FA icon + dark/outline style), and the breadcrumb trail. The first tag
 *  (<header>) auto-gets the data-aq-section anchor. */
$s        = $args['s'] ?? [];
$badge_fa = (string) ($s['badge_fa'] ?? '');
$ctas     = array_values(array_filter((array) ($s['ctas'] ?? []),   fn($c) => is_array($c) && (($c['label'] ?? '') !== '')));
$crumbs   = array_values(array_filter((array) ($s['crumbs'] ?? []), fn($c) => is_array($c) && (($c['label'] ?? '') !== '')));
?>
<header class="page-hero">
	<div class="wrap">
		<?php if (($s['badge'] ?? '') !== '' || $badge_fa !== '') : ?>
		<span class="badge"<?php echo ka_field_attr('badge'); ?>><?php if ($badge_fa !== '') : ?><i class="fa-solid <?php echo esc_attr($badge_fa); ?>"></i> <?php endif; ?><?php echo esc_html($s['badge'] ?? ''); ?></span>
		<?php endif; ?>
		<h1<?php echo ka_field_attr('heading'); ?>><?php echo wp_kses_post($s['heading'] ?? ''); ?></h1>
		<?php if (($s['lede'] ?? '') !== '') : ?><p class="lede"<?php echo ka_field_attr('lede'); ?>><?php echo esc_html($s['lede']); ?></p><?php endif; ?>
		<?php if ($ctas) : ?>
		<div class="cta">
			<?php foreach ($ctas as $i => $c) :
				$style = ($c['style'] ?? 'dark') === 'outline' ? 'btn btn-outline-dark btn-lg' : 'btn btn-dark-solid btn-lg';
				$cfa   = (string) ($c['fa'] ?? ''); ?>
			<a class="<?php echo esc_attr($style); ?>" href="<?php echo esc_url($c['href'] ?? '#'); ?>"<?php echo ka_field_attr('ctas', $i); ?>><?php if ($cfa !== '') : ?><i class="fa-solid <?php echo esc_attr($cfa); ?>"></i> <?php endif; ?><?php echo esc_html($c['label'] ?? ''); ?></a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</header>
<?php if ($crumbs) : ?>
<nav class="crumbs"><div class="wrap"><ol>
	<?php foreach ($crumbs as $i => $c) :
		$url = (string) ($c['url'] ?? ''); ?>
	<li<?php echo ka_field_attr('crumbs', $i); ?>><?php if ($url !== '') : ?><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($c['label'] ?? ''); ?></a><?php else : ?><?php echo esc_html($c['label'] ?? ''); ?><?php endif; ?></li>
	<?php endforeach; ?>
</ol></div></nav>
<?php endif; ?>
