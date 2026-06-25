<?php
/**
 * AQM site footer — the per-design footer, mirroring the static source markup
 * (footer > .wrap > .cols + .legal) so the theme CSS applies. Dynamic values
 * (logo, name, phone, email, address) read from aq_site() with static-source
 * fallbacks; the link columns reproduce the static footer 1:1.
 *
 * This override exists because the engine default (render/parts/site-footer.php)
 * is the original home-inspection template footer; without this file the active
 * theme would fall back to it. See AQ_Renderer::part()'s locate_template().
 */
if (!defined('ABSPATH')) { exit; }

$name  = (string) (aq_site('name') ?: get_bloginfo('name') ?: 'AQ Marketing');
$phone = (string) (aq_site('phone') ?: '(781) 730-6971');
$ptel  = (string) (aq_site('phoneTel') ?: '+17817306971');
$email = (string) (aq_site('email') ?: 'hello@aqmarketing.com');

// Logo: same resolution chain as the header override.
$logo_id  = (int) aq_site('logo.id');
$logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';
if (!$logo_url) {
	$lf = (string) aq_site('logo.file');
	if ($lf) { $logo_url = content_url('uploads/' . ltrim($lf, '/')); }
}

// Address: prefer config, else the static two-line address.
$addr_lines = (array) (aq_site('footer.address') ?: ['400 Tradecenter Dr, Suite 5900', 'Woburn, MA 01801']);
?>
<footer>
	<div class="wrap">
		<div class="cols">
			<div>
				<a class="logo" href="/" aria-label="<?php echo esc_attr($name); ?> home">
					<?php if ($logo_url) : ?>
						<img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($name); ?>" width="71" height="44" loading="lazy">
					<?php else : ?>
						<span><?php echo esc_html($name); ?></span>
					<?php endif; ?>
				</a>
				<p style="margin-top:14px;max-width:280px;color:#8a94a1">Local SEO &amp; AI Websites for Massachusetts Small Businesses. Helping Massachusetts small businesses win the map pack since 2003.</p>
			</div>
			<div>
				<h4>Services</h4>
				<ul>
					<li><a href="/services/local-seo/">Local SEO</a></li>
					<li><a href="/services/web-design/">Web Design</a></li>
					<li><a href="/services/ai-websites/">AI Websites</a></li>
					<li><a href="/services/google-ads/">Google Ads</a></li>
					<li><a href="/services/reputation-management/">Reputation</a></li>
					<li><a href="/services/">All services</a></li>
				</ul>
			</div>
			<div>
				<h4>Locations</h4>
				<ul>
					<li><a href="/locations/woburn-ma/">Woburn, MA</a></li>
					<li><a href="/locations/boston-ma/">Boston, MA</a></li>
					<li><a href="/locations/">All locations</a></li>
				</ul>
			</div>
			<div>
				<h4>Company</h4>
				<ul>
					<li><a href="/about/">About</a></li>
					<li><a href="/industries/">Industries</a></li>
					<li><a href="/blog/">Blog</a></li>
					<li><a href="/contact/">Contact</a></li>
				</ul>
			</div>
			<div>
				<h4>Contact</h4>
				<ul>
					<li><?php echo wp_kses_post(implode('<br>', array_map('esc_html', $addr_lines))); ?></li>
					<li><a href="tel:<?php echo esc_attr($ptel); ?>"><?php echo esc_html($phone); ?></a></li>
					<li><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></li>
				</ul>
			</div>
		</div>
		<div class="legal">
			<span>&copy; 2026 <?php echo esc_html($name); ?>, Inc. Serving Massachusetts since 2003.</span>
			<span><a href="/privacy/">Privacy</a> &middot; <a href="/terms/">Terms</a> &middot; <a href="/cookies/">Cookies</a></span>
		</div>
	</div>
</footer>
