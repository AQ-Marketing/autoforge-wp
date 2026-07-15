<?php
/** AQM Logo Wall (theme override of the engine `logos` section) — each logo sits in
 *  a card: subtle grey border, rounded corners, padding, centered. Card background is
 *  white by default; logos flagged `dark` (light/white artwork) get a charcoal card so
 *  they contrast. Client-agnostic engine template stays untouched; this is AQM-only. */
if (!defined('ABSPATH')) {
	exit;
}
$s      = $args['s'] ?? [];
$logos  = array_values(array_filter((array) ($s['logos'] ?? []), fn($l) => is_array($l) && !empty($l['image'])));
$lg     = (int) ($s['columns'] ?? 4); if ($lg < 2 || $lg > 6) { $lg = 4; }
$eyebrow = trim((string) ($s['eyebrow'] ?? ''));
$heading = trim((string) ($s['heading'] ?? ''));
$intro   = trim((string) ($s['intro'] ?? ''));
$tint    = ($s['bg'] ?? 'white') === 'brand-50';
?>
<section class="aqm-lw<?php echo $tint ? ' aqm-lw--tint' : ''; ?>">
	<div class="aqm-lw-wrap">
		<?php if ($eyebrow !== '' || $heading !== '' || $intro !== '') : ?>
		<div class="aqm-lw-head">
			<?php if ($eyebrow !== '') : ?><span class="aqm-lw-eyebrow"<?php echo ka_field_attr('eyebrow'); ?>><?php echo esc_html($eyebrow); ?></span><?php endif; ?>
			<?php if ($heading !== '') : ?><h2<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($heading); ?></h2><?php endif; ?>
			<?php if ($intro !== '') : ?><p<?php echo ka_field_attr('intro'); ?>><?php echo esc_html($intro); ?></p><?php endif; ?>
		</div>
		<?php endif; ?>
		<ul class="aqm-lw-grid" style="--lw-cols:<?php echo (int) $lg; ?>">
			<?php foreach ($logos as $i => $logo) :
				$alt  = trim((string) ($logo['alt'] ?? ''));
				$dark = !empty($logo['dark']);
				$bg   = trim((string) ($logo['bg'] ?? ''));
				$bgok = (bool) preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $bg);
				$img  = ka_picture_field($logo['image'] ?? null, ['class' => 'aqm-lw-img']);
				if ($img === '') { continue; }
				if ($alt !== '') {
					$img = preg_replace('/\salt="[^"]*"/', '', $img, 1);
					$img = preg_replace('/<img\b/', '<img alt="' . esc_attr($alt) . '"', $img, 1);
				}
				$href = trim((string) ($logo['href'] ?? ''));
			?>
			<li<?php echo ka_field_attr('logos', $i); ?> class="aqm-logo-card<?php echo (!$bgok && $dark) ? ' aqm-logo-card--dark' : ''; ?>"<?php echo $bgok ? ' style="background:' . esc_attr($bg) . ';border-color:' . esc_attr($bg) . '"' : ''; ?><?php if ($alt !== '') { echo ' title="' . esc_attr($alt) . '"'; } ?>>
				<?php if ($href !== '') : ?><a href="<?php echo esc_url($href); ?>" target="_blank" rel="noopener nofollow"><?php echo $img; ?></a><?php else : echo $img; endif; ?>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
<style>
	.aqm-lw{padding:56px 0}
	.aqm-lw--tint{background:#faf7f2}
	.aqm-lw-wrap{max-width:1200px;margin:0 auto;padding:0 24px}
	.aqm-lw-head{text-align:center;max-width:720px;margin:0 auto 34px}
	.aqm-lw-eyebrow{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#c8102e;margin-bottom:8px}
	.aqm-lw-head h2{font-size:30px;line-height:1.15;margin:0 0 8px;color:#0d1014;letter-spacing:-.02em}
	.aqm-lw-head p{font-size:15px;color:#5b6471;line-height:1.55;margin:0}
	.aqm-lw-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;list-style:none;padding:0;margin:0}
	.aqm-logo-card{display:flex;align-items:center;justify-content:center;background:#fff;border:1px solid #e6e9ee;border-radius:14px;padding:24px;min-height:128px;box-shadow:0 1px 2px rgba(13,16,20,.04);transition:box-shadow .16s ease,border-color .16s ease,transform .16s ease}
	.aqm-logo-card:hover{box-shadow:0 8px 22px rgba(13,16,20,.09);border-color:#d3d8de;transform:translateY(-2px)}
	.aqm-logo-card--dark{background:#0e1116;border-color:#242c34}
	.aqm-logo-card--dark:hover{border-color:#3a444e}
	.aqm-logo-card a{display:flex;align-items:center;justify-content:center;width:100%;height:100%}
	.aqm-lw-img{max-height:58px;max-width:100%;width:auto;object-fit:contain;display:block}
	@media (min-width:640px){.aqm-lw-grid{grid-template-columns:repeat(3,1fr)}}
	@media (min-width:1024px){.aqm-lw-grid{grid-template-columns:repeat(var(--lw-cols,4),1fr);gap:18px}}
	@media (max-width:640px){.aqm-logo-card{min-height:104px;padding:18px}.aqm-lw-head h2{font-size:24px}}
</style>
