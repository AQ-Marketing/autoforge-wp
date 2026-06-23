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
$f_social      = aq_site('footer.social') ?: [];
$f_about       = (string) (aq_site('footer.about') ?? '');
$region        = $addr['region'] ?? '';
$cta_label     = aq_str('footerCta.label', 'Request a Call Back');
$cta_href      = aq_str('footerCta.href', '/schedule/');
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
			<div class="flex gap-3 mt-5">
				<a href="<?php echo esc_url($f_social['facebook'] ?? '#'); ?>" class="w-9 h-9 rounded-full bg-black/30 hover:bg-accent-500 transition flex items-center justify-center text-white no-underline" aria-label="Facebook">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12.07C22 6.51 17.52 2 12 2S2 6.51 2 12.07c0 5 3.66 9.15 8.44 9.93v-7.02H7.9v-2.91h2.54v-2.21c0-2.51 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.45 2.91h-2.33V22c4.78-.78 8.43-4.93 8.43-9.93z"/></svg>
				</a>
				<a href="<?php echo esc_url($f_social['instagram'] ?? '#'); ?>" class="w-9 h-9 rounded-full bg-black/30 hover:bg-accent-500 transition flex items-center justify-center text-white no-underline" aria-label="Instagram">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41-.56-.22-.96-.48-1.38-.9-.42-.42-.68-.82-.9-1.38-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.39C1.34 2.69.93 3.36.62 4.15.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.39 2.13.67.67 1.34 1.08 2.13 1.39.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.31 1.46-.72 2.13-1.39.67-.67 1.08-1.34 1.39-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91-.31-.79-.72-1.46-1.39-2.13C21.31 1.34 20.64.93 19.85.62c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84A6.16 6.16 0 1 0 12 18.16 6.16 6.16 0 0 0 12 5.84zm0 10.16A4 4 0 1 1 12 8a4 4 0 0 1 0 8zm6.41-11.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88z"/></svg>
				</a>
			</div>
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
			<p class="font-semibold text-accent-500 mb-4 uppercase text-sm tracking-wider">Contact Us</p>
			<ul class="text-sm space-y-3 text-brand-200">
				<li class="flex items-start gap-2.5">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-accent-400 mt-0.5 flex-shrink-0"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.86 19.86 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.86 19.86 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
					<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="text-brand-200 no-underline hover:text-accent-400"><?php echo esc_html($phone); ?></a>
				</li>
				<li class="flex items-start gap-2.5">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-accent-400 mt-0.5 flex-shrink-0"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
					<span><?php echo esc_html(($addr['street'] ?? '') . ', ' . ($addr['locality'] ?? '') . ', ' . ($addr['region'] ?? '') . ' ' . ($addr['postalCode'] ?? '')); ?></span>
				</li>
				<?php if ($license !== '' && $license !== null) : ?>
				<li class="flex items-start gap-2.5">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-accent-400 mt-0.5 flex-shrink-0"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
					<span><?php echo esc_html(trim(($region ? $region . ' ' : '') . 'License #' . $license)); ?></span>
				</li>
				<?php endif; ?>
			</ul>
			<div class="mt-5 flex flex-col gap-3 max-w-[260px]">
				<a href="<?php echo esc_url($cta_href); ?>" class="btn-primary text-xs uppercase tracking-wider py-3 px-6 block text-center"><?php echo esc_html($cta_label); ?></a>
				<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="block py-3 px-6 text-xs uppercase tracking-wider text-center text-white font-semibold no-underline border border-white/30 rounded hover:bg-accent-500 hover:border-accent-500 transition">Call <?php echo esc_html($phone); ?></a>
			</div>
		</div>
	</div>

	<div class="border-t border-brand-800">
		<div class="container-edge py-5 flex flex-wrap items-center justify-between text-xs text-brand-300 gap-2">
			<span>&copy; <?php echo esc_html($year); ?> <?php echo esc_html(aq_site('name')); ?>. All rights reserved.</span>
			<span class="flex gap-6">
				<?php foreach ($f_legal as $f_link) : ?>
				<a class="text-brand-300 no-underline hover:text-accent-400" href="<?php echo esc_url($f_link['href'] ?? '#'); ?>"><?php echo esc_html($f_link['label'] ?? ''); ?></a>
				<?php endforeach; ?>
			</span>
		</div>
	</div>
</footer>
