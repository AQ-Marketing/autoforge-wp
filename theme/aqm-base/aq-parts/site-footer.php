<?php
/**
 * Site footer — fully data-driven from aq_site(), so every client renders THEIR
 * OWN footer (logo, about, link columns, address, phone, legal links) with zero
 * hardcoded agency content. Mirrors the static source markup
 * (footer > .wrap > .cols + .legal) so the compiled theme CSS applies.
 *
 * Two fixed rows always render in .legal:
 *   1. Copyright:  © {year} | All Rights Reserved | {client, linked home}
 *                  | Website Design, SEO & Hosting by AQ Marketing, Inc.
 *      (the agency credit links to aqmarketing.com in a new tab)
 *   2. Legal links (Privacy · Terms · …) from aq_site('footer.legal')
 *
 * No email is ever printed (agency policy: contact via form only).
 *
 * This override exists because the engine default (render/parts/site-footer.php)
 * is a generic template footer; without this the active theme falls back to it.
 */
if (!defined('ABSPATH')) { exit; }

$name  = (string) (aq_site('name') ?: get_bloginfo('name'));
$legal = (string) (aq_site('legalName') ?: $name);
$phone = (string) aq_site('phone');
$ptel  = (string) aq_site('phoneTel');
$home  = home_url('/');

// Logo: same resolution chain as the header override.
$logo_id  = (int) aq_site('logo.id');
$logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';
if (!$logo_url) {
	$lf = (string) aq_site('logo.file');
	if ($lf) { $logo_url = content_url('uploads/' . ltrim($lf, '/')); }
}

$about   = (string) aq_site('footer.about');
$columns = array_values((array) (aq_site('footer.columns') ?: []));
$legals  = array_values((array) (aq_site('footer.legal') ?: []));
$note    = (string) aq_site('footer.copyrightNote');

// Address lines: prefer an explicit footer.address array, else build them from
// the structured address object; skip entirely if the client has no address.
$addr_lines = (array) (aq_site('footer.address') ?: []);
if (!$addr_lines) {
	$a      = (array) (aq_site('address') ?: []);
	$street = trim((string) ($a['street'] ?? ''));
	$cityln = trim(trim((string) ($a['locality'] ?? '') . ', ' . (string) ($a['region'] ?? '')) . ' ' . (string) ($a['postalCode'] ?? ''), ' ,');
	if ($street !== '') { $addr_lines[] = $street; }
	if ($cityln !== '') { $addr_lines[] = $cityln; }
}

$year = (int) current_time('Y');
?>
<footer>
	<div class="wrap">
		<div class="cols">
			<div>
				<a class="logo" href="<?php echo esc_url($home); ?>" aria-label="<?php echo esc_attr($name); ?> home">
					<?php if ($logo_url) : ?>
						<img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($name); ?>" width="71" height="44" loading="lazy">
					<?php else : ?>
						<span><?php echo esc_html($name); ?></span>
					<?php endif; ?>
				</a>
				<?php if ($about !== '') : ?>
					<p style="margin-top:14px;max-width:280px;color:#8a94a1"><?php echo esc_html($about); ?></p>
				<?php endif; ?>
			</div>

			<?php foreach ($columns as $col) :
				$heading = (string) ($col['heading'] ?? '');
				$links   = array_values((array) ($col['links'] ?? []));
				if ($heading === '' && !$links) { continue; } ?>
				<div>
					<?php if ($heading !== '') : ?><h4><?php echo esc_html($heading); ?></h4><?php endif; ?>
					<?php if ($links) : ?>
						<ul>
							<?php foreach ($links as $ln) :
								$label = (string) ($ln['label'] ?? '');
								$href  = (string) ($ln['href'] ?? '');
								if ($label === '' || $href === '') { continue; } ?>
								<li><a href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?></a></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ($addr_lines || $phone !== '') : ?>
				<div>
					<h4>Contact</h4>
					<ul>
						<?php if ($addr_lines) : ?>
							<li><?php echo wp_kses_post(implode('<br>', array_map('esc_html', $addr_lines))); ?></li>
						<?php endif; ?>
						<?php if ($phone !== '') : ?>
							<li><a href="tel:<?php echo esc_attr($ptel ?: $phone); ?>"><?php echo esc_html($phone); ?></a></li>
						<?php endif; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>

		<div class="legal">
			<span>
				Copyright &copy; <?php echo esc_html($year); ?> | All Rights Reserved |
				<a href="<?php echo esc_url($home); ?>"><?php echo esc_html($legal); ?></a> |
				<a href="https://www.aqmarketing.com/" target="_blank" rel="noopener">Website Design, SEO &amp; Hosting by AQ Marketing, Inc.</a>
			</span>
			<?php if ($legals) : ?>
				<span>
					<?php $parts = [];
					foreach ($legals as $lg) {
						$label = (string) ($lg['label'] ?? '');
						$href  = (string) ($lg['href'] ?? '');
						if ($label === '' || $href === '') { continue; }
						$parts[] = '<a href="' . esc_url($href) . '">' . esc_html($label) . '</a>';
					}
					echo wp_kses_post(implode(' &middot; ', $parts)); ?>
				</span>
			<?php endif; ?>
		</div>
	</div>
</footer>
