<?php
/**
 * AQ Marketing site footer — DARK 5-column footer.
 *
 * THEME-LEVEL chrome override (resolved by AQ_Renderer::part() before the
 * plugin default). Transliterated 1:1 from the static site's <footer> markup
 * (index.html) to pair with assets/css/main.css (footer .cols grid is
 * 1.4fr 1fr 1fr 1fr 1fr → brand col + N link columns + a contact column).
 *
 * ALL content is client data via aq_site(): logo, the "about" blurb, the
 * footer link columns (footer.columns[]), NAP for the contact column, and the
 * legal links. Nothing is hardcoded — a brand with no footer config renders the
 * dark band with just the logo.
 */

if (!defined('ABSPATH')) {
	exit;
}

$logo_id   = (int) (aq_site('logo.idDark') ?: aq_site('logo.id'));
$brand     = aq_site('name') ?: get_bloginfo('name');
$about     = (string) (aq_site('footer.about') ?? '');
$columns   = array_values((array) aq_site('footer.columns'));
$legal     = array_values((array) aq_site('footer.legal'));
$copy_note = (string) (aq_site('footer.copyrightNote') ?? '');

$phone     = aq_site('phone');
$phone_tel = aq_site('phoneTel');
$email     = aq_site('email');
$addr      = (array) (aq_site('address') ?: []);
$year      = date('Y');

$addr_line1 = trim((string) ($addr['street'] ?? ''));
$addr_line2 = trim(($addr['locality'] ?? '') . ', ' . ($addr['region'] ?? '') . ' ' . ($addr['postalCode'] ?? ''), ', ');
?>
<footer>
	<div class="wrap">
		<div class="cols">
			<div>
				<a class="logo" href="/" aria-label="<?php echo esc_attr($brand . ' home'); ?>"><?php
					echo $logo_id
						? wp_get_attachment_image($logo_id, 'full', false, ['alt' => $brand, 'width' => 71, 'height' => 44, 'loading' => 'lazy'])
						: '<span class="logo-text">' . esc_html($brand) . '</span>'; ?></a>
				<?php if ($about !== '') : ?>
				<p style="margin-top:14px;max-width:280px;color:#8a94a1"><?php echo esc_html($about); ?></p>
				<?php endif; ?>
			</div>

			<?php foreach ($columns as $col) :
				$heading = (string) ($col['heading'] ?? '');
				$links   = array_values((array) ($col['links'] ?? [])); ?>
			<div>
				<?php if ($heading !== '') : ?><h4><?php echo esc_html($heading); ?></h4><?php endif; ?>
				<ul>
					<?php foreach ($links as $link) : ?>
					<li><a href="<?php echo esc_url($link['href'] ?? '#'); ?>"><?php echo esc_html($link['label'] ?? ''); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endforeach; ?>

			<div>
				<h4>Contact</h4>
				<ul>
					<?php if ($addr_line1 !== '' || $addr_line2 !== '') : ?>
					<li><?php echo esc_html($addr_line1); ?><?php if ($addr_line1 !== '' && $addr_line2 !== '') : ?><br><?php endif; ?><?php echo esc_html($addr_line2); ?></li>
					<?php endif; ?>
					<?php if ($phone) : ?><li><a href="tel:<?php echo esc_attr($phone_tel); ?>"><?php echo esc_html($phone); ?></a></li><?php endif; ?>
					<?php if ($email) : ?><li><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></li><?php endif; ?>
				</ul>
			</div>
		</div>

		<div class="legal">
			<span>&copy; <?php echo esc_html($year); ?> <?php echo esc_html(aq_site('legalName') ?: $brand); ?>.<?php echo $copy_note !== '' ? ' ' . esc_html($copy_note) : ''; ?></span>
			<?php if ($legal) : ?>
			<span><?php foreach ($legal as $li => $link) : echo $li ? ' &middot; ' : ''; ?><a href="<?php echo esc_url($link['href'] ?? '#'); ?>"><?php echo esc_html($link['label'] ?? ''); ?></a><?php endforeach; ?></span>
			<?php endif; ?>
		</div>
	</div>
</footer>
