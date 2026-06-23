<?php
/**
 * AQ Marketing site header — DARK mega-menu chrome.
 *
 * This is a THEME-LEVEL chrome override: AQ_Renderer::part() resolves
 * render-parts/site-header.php from the active theme BEFORE the plugin's
 * default, so this AQM-specific markup replaces the engine's stock header
 * without touching the plugin. Transliterated 1:1 from the static site's
 * `nav.top` markup (index.html) for pixel parity with assets/css/main.css.
 *
 * ALL content is client data via aq_site(): logo, primary nav, the two
 * mega-menu panels (services / solutions), NAP phone, and the "Book a call"
 * CTA. A brand with no nav config simply renders an empty bar — nothing is
 * hardcoded here. Mega icons are Font Awesome class names (e.g. "fa-robot");
 * the FA kit is enqueued by the theme. Behaviour (menu toggle, mega hover,
 * scroll progress, back-to-top, .scrolled state) lives in assets/js/site.js.
 */

if (!defined('ABSPATH')) {
	exit;
}

// Coerce config leaves to strings: a mis-authored brand.json (e.g. a value
// written as an array/object) must never reach esc_html()/esc_url(), which
// would throw a TypeError and blank the page. Non-scalars collapse to the
// fallback instead.
$aq_str = static fn($path, $default = '') => is_scalar($v = aq_site($path)) ? (string) $v : $default;

$logo_id   = (int) aq_site('logo.id');
$brand     = $aq_str('name') ?: get_bloginfo('name');
$phone     = $aq_str('phone');
$phone_tel = $aq_str('phoneTel');
$cta_label = $aq_str('headerCta.label', 'Book a call');
$cta_href  = $aq_str('headerCta.href', '/contact/');

$nav   = array_values((array) aq_site('nav'));
$mega  = (array) aq_site('megamenu');

$path      = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$is_active = static fn(string $href): bool => $href !== '#' && ($path === $href || ($href !== '/' && str_starts_with($path, $href)));

/** Render the brand logo (media-library attachment) with a text fallback. */
$render_logo = static function (int $w, int $h, string $loading) use ($logo_id, $brand) {
	if ($logo_id) {
		echo wp_get_attachment_image($logo_id, 'full', false, [
			'alt'           => $brand,
			'width'         => $w,
			'height'        => $h,
			'loading'       => $loading,
			'fetchpriority' => $loading === 'eager' ? 'high' : 'auto',
		]);
	} else {
		echo '<span class="logo-text">' . esc_html($brand) . '</span>';
	}
};
?>
<div class="scroll-progress" id="scrollProgress" aria-hidden="true"></div>
<button class="to-top" id="toTop" aria-label="Back to top"><i class="fa-solid fa-arrow-up"></i></button>

<nav class="top">
	<div class="wrap row">
		<a class="logo" href="/" aria-label="<?php echo esc_attr($brand . ' home'); ?>"><?php $render_logo(84, 52, 'eager'); ?></a>
		<ul class="nav-main">
			<?php foreach ($nav as $item) :
				$label    = (string) ($item['label'] ?? '');
				$href     = (string) ($item['href'] ?? '#');
				$mega_key = (string) ($item['mega'] ?? '');
				$panel    = $mega_key !== '' ? ($mega[$mega_key] ?? null) : null;
				$groups   = is_array($panel) ? array_values((array) ($panel['groups'] ?? [])) : [];
				$feature  = is_array($panel) ? (array) ($panel['feature'] ?? []) : [];
				$has_mega = $groups || $feature;
				// data-nav key drives the active-nav highlight in site.js (PART 1):
				// explicit 'key', else the mega key, else the href's first segment.
				$nkey   = (string) ($item['key'] ?? ($mega_key ?: trim((string) parse_url($href, PHP_URL_PATH), '/')));
				$active = $is_active($href);
			?>
			<li<?php echo $has_mega ? ' class="has-mega"' : ''; ?><?php echo $nkey !== '' ? ' data-nav="' . esc_attr($nkey) . '"' : ''; ?>>
				<a href="<?php echo esc_url($href); ?>"<?php echo $active ? ' class="active" aria-current="page"' : ''; ?>><?php echo esc_html($label); ?><?php if ($has_mega) : ?> <i class="fa-solid fa-chevron-down"></i><?php endif; ?></a>
				<?php if ($has_mega) : ?>
				<div class="mega">
					<div class="mega-inner">
						<div class="mega-cols">
							<?php foreach ($groups as $group) :
								$glabel = (string) ($group['label'] ?? '');
								$links  = array_values((array) ($group['links'] ?? [])); ?>
							<div class="mega-col">
								<?php if ($glabel !== '') : ?><span class="mega-label"><?php echo esc_html($glabel); ?></span><?php endif; ?>
								<?php foreach ($links as $link) :
									$lfa = (string) ($link['fa'] ?? '');
									$lt  = (string) ($link['title'] ?? '');
									$ld  = (string) ($link['tagline'] ?? '');
									$lh  = (string) ($link['href'] ?? '#'); ?>
								<a class="mega-link" href="<?php echo esc_url($lh); ?>"><?php if ($lfa !== '') : ?><i class="fa-solid <?php echo esc_attr($lfa); ?>"></i><?php endif; ?><div><b><?php echo esc_html($lt); ?></b><span><?php echo esc_html($ld); ?></span></div></a>
								<?php endforeach; ?>
							</div>
							<?php endforeach; ?>
							<?php if ($feature) :
								$f_tag      = (string) ($feature['tag'] ?? '');
								$f_title    = (string) ($feature['title'] ?? '');
								$f_text     = (string) ($feature['text'] ?? '');
								$f_cta_l    = (string) ($feature['ctaLabel'] ?? '');
								$f_cta_h    = (string) ($feature['ctaHref'] ?? '#');
								$f_all_l    = (string) ($feature['allLabel'] ?? '');
								$f_all_h    = (string) ($feature['allHref'] ?? $href); ?>
							<div class="mega-col mega-feature">
								<div class="mega-card">
									<?php if ($f_tag !== '') : ?><span class="tag"><?php echo esc_html($f_tag); ?></span><?php endif; ?>
									<?php if ($f_title !== '') : ?><h4><?php echo esc_html($f_title); ?></h4><?php endif; ?>
									<?php if ($f_text !== '') : ?><p><?php echo esc_html($f_text); ?></p><?php endif; ?>
									<?php if ($f_cta_l !== '') : ?><a class="btn btn-primary" href="<?php echo esc_url($f_cta_h); ?>"><?php echo esc_html($f_cta_l); ?></a><?php endif; ?>
								</div>
								<?php if ($f_all_l !== '') : ?><a class="mega-all" href="<?php echo esc_url($f_all_h); ?>"><?php echo esc_html($f_all_l); ?> <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ul>
		<div class="nav-cta">
			<?php if ($phone) : ?><a class="btn btn-phone" href="tel:<?php echo esc_attr($phone_tel); ?>"><i class="fa-solid fa-phone"></i> <?php echo esc_html($phone); ?></a><?php endif; ?>
			<a class="btn btn-primary" href="<?php echo esc_url($cta_href); ?>"><?php echo esc_html($cta_label); ?></a>
		</div>
		<button class="hamburger" id="menuToggle" aria-label="Open menu" aria-expanded="false">
			<i class="fa-solid fa-bars"></i>
		</button>
	</div>
</nav>

<div class="mobile-menu" id="mobileMenu" role="dialog" aria-modal="true" aria-label="Menu">
	<div class="mobile-head">
		<a class="logo" href="/" aria-label="<?php echo esc_attr($brand . ' home'); ?>"><?php $render_logo(84, 52, 'lazy'); ?></a>
		<button class="mobile-close" id="menuClose" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
	</div>
	<div class="mobile-body">
		<ul>
			<?php foreach ($nav as $item) : ?>
			<li><a href="<?php echo esc_url($item['href'] ?? '#'); ?>"><?php echo esc_html($item['label'] ?? ''); ?></a></li>
			<?php endforeach; ?>
			<li><a href="<?php echo esc_url($cta_href); ?>"><?php echo esc_html($cta_label); ?></a></li>
			<?php if ($phone) : ?><li><a href="tel:<?php echo esc_attr($phone_tel); ?>">Call <?php echo esc_html($phone); ?></a></li><?php endif; ?>
		</ul>
		<div class="mobile-cta">
			<a class="btn btn-primary btn-lg" href="<?php echo esc_url($cta_href); ?>"><?php echo esc_html($cta_label); ?></a>
		</div>
	</div>
</div>
