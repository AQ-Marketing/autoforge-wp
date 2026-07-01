<?php
/**
 * AQM site header — the `.has-mega` design, rendered from aq_site() config
 * (nav + megamenu + phone + headerCta + logo). Mirrors the static source markup
 * so the theme CSS applies. Mobile drawer + mega menu are wired by the theme JS
 * (#menuToggle / #mobileMenu, nav.top .has-mega).
 */
if (!defined('ABSPATH')) { exit; }

$nav   = array_values((array) (aq_site('nav') ?: []));
$mega  = (array) (aq_site('megamenu') ?: []);
$phone = (string) aq_site('phone');
$ptel  = (string) aq_site('phoneTel');
$cta_l = (string) (aq_site('headerCta.label') ?: 'Book a call');
$cta_h = (string) (aq_site('headerCta.href') ?: '/contact/');

$logo_id  = (int) aq_site('logo.id');
$logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';
if (!$logo_url) {
	$lf = (string) aq_site('logo.file');
	if ($lf) { $logo_url = content_url('uploads/' . ltrim($lf, '/')); }
}
// Optional: a different logo once the header is in its sticky/scrolled state
// (AutoForge -> Logo). Empty falls back to $logo_url, so unset sites render
// byte-identical to before this existed.
$sticky_logo_id  = (int) aq_site('logo.idSticky');
$sticky_logo_url = $sticky_logo_id ? wp_get_attachment_image_url($sticky_logo_id, 'full') : '';
$site = (string) (aq_site('name') ?: get_bloginfo('name'));

$logo_html = function ($swappable = false) use ($logo_url, $sticky_logo_url, $site) {
	echo '<a class="logo" href="/" aria-label="' . esc_attr($site) . ' home">';
	if ($logo_url) {
		$swap_attrs = ($swappable && $sticky_logo_url)
			? ' data-logo-default="' . esc_url($logo_url) . '" data-logo-sticky="' . esc_url($sticky_logo_url) . '"'
			: '';
		echo '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site) . '" width="84" height="52"' . $swap_attrs . '>';
	} else {
		echo '<span>' . esc_html($site) . '</span>';
	}
	echo '</a>';
};
?>
<div class="scroll-progress" id="scrollProgress" aria-hidden="true"></div>
<button class="to-top" id="toTop" aria-label="Back to top"><i class="fa-solid fa-arrow-up"></i></button>

<nav class="top">
  <div class="wrap row">
    <?php $logo_html(true); ?>
    <ul class="nav-main">
      <?php foreach ($nav as $item) :
        $label = (string) ($item['label'] ?? '');
        $href  = (string) ($item['href'] ?? '#');
        $mkey  = (string) ($item['mega'] ?? '');
        $panel = $mkey && isset($mega[$mkey]) ? (array) $mega[$mkey] : null;
        $slug  = sanitize_title($label);
      ?>
      <li<?php echo $panel ? ' class="has-mega"' : ''; ?> data-nav="<?php echo esc_attr($slug); ?>">
        <a href="<?php echo esc_url($href); ?>"><?php echo esc_html($label); ?><?php if ($panel) : ?> <i class="fa-solid fa-chevron-down"></i><?php endif; ?></a>
        <?php if ($panel) :
          $groups  = (array) ($panel['groups'] ?? []);
          $feature = (array) ($panel['feature'] ?? []);
        ?>
        <div class="mega">
          <div class="mega-inner">
            <div class="mega-cols">
              <?php foreach ($groups as $g) : ?>
              <div class="mega-col">
                <span class="mega-label"><?php echo esc_html($g['label'] ?? ''); ?></span>
                <?php foreach ((array) ($g['links'] ?? []) as $ln) : ?>
                <a class="mega-link" href="<?php echo esc_url($ln['href'] ?? '#'); ?>"><i class="fa-solid <?php echo esc_attr($ln['fa'] ?? ''); ?>"></i><div><b><?php echo esc_html($ln['title'] ?? ''); ?></b><span><?php echo esc_html($ln['tagline'] ?? ''); ?></span></div></a>
                <?php endforeach; ?>
              </div>
              <?php endforeach; ?>
              <?php if ($feature) : ?>
              <div class="mega-col mega-feature">
                <div class="mega-card">
                  <?php if (!empty($feature['tag'])) : ?><span class="tag"><?php echo esc_html($feature['tag']); ?></span><?php endif; ?>
                  <h4><?php echo esc_html($feature['title'] ?? ''); ?></h4>
                  <p><?php echo esc_html($feature['text'] ?? ''); ?></p>
                  <a class="btn btn-primary" href="<?php echo esc_url($feature['ctaHref'] ?? '/contact/'); ?>"><?php echo esc_html($feature['ctaLabel'] ?? 'Learn more'); ?></a>
                </div>
                <a class="mega-all" href="<?php echo esc_url($feature['allHref'] ?? $href); ?>"><?php echo esc_html($feature['allLabel'] ?? 'See all'); ?> <i class="fa-solid fa-arrow-right"></i></a>
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
      <?php if ($phone) : ?><a class="btn btn-phone" href="tel:<?php echo esc_attr($ptel ?: $phone); ?>"><i class="fa-solid fa-phone"></i> <?php echo esc_html($phone); ?></a><?php endif; ?>
      <a class="btn btn-primary" href="<?php echo esc_url($cta_h); ?>"><?php echo esc_html($cta_l); ?></a>
    </div>
    <button class="hamburger" id="menuToggle" aria-label="Open menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
  </div>
</nav>

<div class="mobile-menu" id="mobileMenu" role="dialog" aria-modal="true" aria-label="Menu">
  <div class="mobile-head">
    <?php $logo_html(); ?>
    <button class="mobile-close" id="menuClose" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="mobile-body">
    <ul>
      <?php foreach ($nav as $item) : ?>
      <li><a href="<?php echo esc_url($item['href'] ?? '#'); ?>"><?php echo esc_html($item['label'] ?? ''); ?></a></li>
      <?php endforeach; ?>
      <li><a href="<?php echo esc_url($cta_h); ?>">Contact</a></li>
      <?php if ($phone) : ?><li><a href="tel:<?php echo esc_attr($ptel ?: $phone); ?>">Call <?php echo esc_html($phone); ?></a></li><?php endif; ?>
    </ul>
    <div class="mobile-cta">
      <a class="btn btn-primary btn-lg" href="<?php echo esc_url($cta_h); ?>"><?php echo esc_html($cta_l); ?></a>
    </div>
  </div>
</div>
