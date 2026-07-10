<?php
/**
 * Site footer — transliterated from the Astro repo's Footer.astro.
 */

$phone     = aq_site('phone');
$phone_tel = aq_site('phoneTel');
$license   = aq_site('license.number');
$founded   = aq_site('founded');
$addr      = aq_site('address') ?: [];
$logo_id   = (int) aq_site('logo.idDark');
$year      = date('Y');

// Footer link columns + social come from site config (AutoForge → Navigation);
// the baked-in defaults in config/site.php reproduce this footer 1:1.
$f_company     = aq_site('footer.company') ?: [];
$f_inspections = aq_site('footer.inspections') ?: [];
$f_legal       = (array) aq_site('footer.legal');
// footer.social is a list of {network, url} rows (AutoForge → Navigation →
// Footer — Social). A site still holding the old fixed {facebook, instagram}
// shape (pre social-icon-repeater) is normalized here so it keeps rendering
// until the admin screen is re-saved.
$f_social_raw = aq_site('footer.social') ?: [];
if (array_key_exists('facebook', $f_social_raw) || array_key_exists('instagram', $f_social_raw)) {
	$f_social = [];
	foreach (['facebook', 'instagram'] as $network) {
		$url = (string) ($f_social_raw[$network] ?? '#');
		if ($url !== '' && $url !== '#') {
			$f_social[] = ['network' => $network, 'url' => $url];
		}
	}
} else {
	$f_social = array_values((array) $f_social_raw);
}
$f_about       = (string) (aq_site('footer.about') ?? '');
$f_contact_h   = aq_site('footer.contact.heading') ?: 'Contact Us';
$region        = $addr['region'] ?? '';

$fcta_label = aq_site('footerCta.label') ?: 'Request a Call Back';
$fcta_href  = aq_site('footerCta.href') ?: '/schedule/';
$lic_pfx    = aq_site('labels.licensePrefix') ?: 'License #';
$call_pfx   = aq_site('labels.callPrefix') ?: 'Call';
$copyright  = aq_site('labels.copyright') ?: 'All rights reserved.';
?>
<footer class="mt-0 bg-brand-900 text-brand-50 border-t-4 border-accent-500">
	<div class="container-edge container-edge--wide py-14 grid gap-10 md:grid-cols-12">
		<div class="md:col-span-4">
			<?php echo $logo_id ? wp_get_attachment_image($logo_id, 'full', false, [
				'sizes'   => '(max-width: 767px) 240px, 285px',
				'alt'     => aq_site('name'),
				'class'   => 'h-20 w-auto mb-5',
				'loading' => 'lazy',
			]) : ''; ?>
			<?php if ($f_about !== '') : ?>
			<p class="text-sm text-brand-200 leading-relaxed max-w-sm">
				<?php echo esc_html($f_about); ?>
			</p>
			<?php endif; ?>
			<?php if ($f_social) : ?>
			<div class="flex gap-3 mt-5">
				<?php foreach ($f_social as $s) :
					$network = (string) ($s['network'] ?? '');
					$icon    = function_exists('aq_social_icon_svg') ? aq_social_icon_svg($network) : '';
					if ($icon === '') continue;
					$label = aq_social_networks()[$network]['label'] ?? ucfirst($network);
				?>
				<a href="<?php echo esc_url($s['url'] ?? '#'); ?>" class="w-9 h-9 rounded-full bg-black/30 hover:bg-accent-500 transition flex items-center justify-center text-white no-underline" aria-label="<?php echo esc_attr($label); ?>">
					<?php echo $icon; ?>
				</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>

		<div class="md:col-span-2">
			<p class="font-semibold text-accent-500 mb-4 uppercase text-sm tracking-wider"><?php echo esc_html($f_company['heading'] ?? 'Company'); ?></p>
			<ul class="text-sm space-y-2.5 text-brand-200">
				<?php foreach ((array) ($f_company['links'] ?? []) as $f_link) : ?>
				<li><a class="text-brand-200 no-underline hover:text-accent-400" href="<?php echo esc_url($f_link['href'] ?? '#'); ?>"><?php echo esc_html($f_link['label'] ?? ''); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="md:col-span-3">
			<p class="font-semibold text-accent-500 mb-4 uppercase text-sm tracking-wider"><?php echo esc_html($f_inspections['heading'] ?? 'Inspections'); ?></p>
			<ul class="text-sm grid grid-cols-2 gap-x-4 gap-y-2.5 text-brand-200">
				<?php foreach ((array) ($f_inspections['links'] ?? []) as $f_link) : ?>
				<li><a class="text-brand-200 no-underline hover:text-accent-400" href="<?php echo esc_url($f_link['href'] ?? '#'); ?>"><?php echo esc_html($f_link['label'] ?? ''); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="md:col-span-3">
			<p class="font-semibold text-accent-500 mb-4 uppercase text-sm tracking-wider"><?php echo esc_html($f_contact_h); ?></p>
			<ul class="text-sm space-y-3 text-brand-200">
				<li class="flex items-start gap-2.5">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-accent-400 mt-0.5 flex-shrink-0"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.86 19.86 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.86 19.86 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
					<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="text-brand-200 no-underline hover:text-accent-400"><?php echo esc_html($phone); ?></a>
				</li>
				<li class="flex items-start gap-2.5">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-accent-400 mt-0.5 flex-shrink-0"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
					<span><?php echo esc_html(($addr['street'] ?? '') . ', ' . ($addr['locality'] ?? '') . ', ' . ($addr['region'] ?? '') . ' ' . ($addr['postalCode'] ?? '')); ?></span>
				</li>
				<li class="flex items-start gap-2.5">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-accent-400 mt-0.5 flex-shrink-0"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
					<span><?php echo esc_html(trim(($region ? $region . ' ' : '') . $lic_pfx . $license)); ?></span>
				</li>
			</ul>
			<div class="mt-5 flex flex-col gap-3 max-w-[260px]">
				<a href="<?php echo esc_url($fcta_href); ?>" class="btn-primary text-xs uppercase tracking-wider py-3 px-6 block text-center"><?php echo esc_html($fcta_label); ?></a>
				<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="block py-3 px-6 text-xs uppercase tracking-wider text-center text-white font-semibold no-underline border border-white/30 rounded hover:bg-accent-500 hover:border-accent-500 transition"><?php echo esc_html($call_pfx); ?> <?php echo esc_html($phone); ?></a>
			</div>
		</div>
	</div>

	<div class="border-t border-brand-800">
		<div class="container-edge py-5 flex flex-wrap items-center justify-between text-xs text-brand-300 gap-2">
			<span>&copy; <?php echo esc_html($year); ?> <?php echo esc_html(aq_site('name')); ?>. <?php echo esc_html($copyright); ?></span>
			<span class="flex gap-6">
				<?php foreach ($f_legal as $f_link) : ?>
				<a class="text-brand-300 no-underline hover:text-accent-400" href="<?php echo esc_url($f_link['href'] ?? '#'); ?>"><?php echo esc_html($f_link['label'] ?? ''); ?></a>
				<?php endforeach; ?>
			</span>
		</div>
	</div>
</footer>
