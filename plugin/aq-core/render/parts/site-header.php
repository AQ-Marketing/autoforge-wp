<?php
/**
 * Site header. Markup, class names, and DOM order are kept identical for pixel
 * parity. ALL business data is client config (aq_site): the mega-menu service /
 * specialty / areas panels, base paths, promo copy, NAP, and logo. A client
 * with no mega-menu data simply renders the flat primary nav.
 */

$mm           = aq_site('megamenu') ?: [];
$mm_services  = $mm['services']  ?? [];
$mm_specialty = $mm['specialty'] ?? [];
$mm_areas     = $mm['areas']     ?? [];

$services  = array_values((array) ($mm_services['items']  ?? []));
$specialty = array_values((array) ($mm_specialty['items'] ?? []));

$svc_base  = $mm_services['base']  ?? '/services/';
$spc_base  = $mm_specialty['base'] ?? '/testing-and-specialty/';
$area_base = $mm_areas['base']     ?? '/service-area/';

$svc_promo  = $mm_services['promo']  ?? [];
$spc_promo  = $mm_specialty['promo'] ?? [];
$area_promo = $mm_areas['promo']     ?? [];

$towns = aq_site('towns') ?? [];

// Primary nav comes from site config (AutoForge → Navigation). Items with a
// 'panel' ('services' | 'specialty' | 'areas') open the mega-menu built below.
$nav_items = array_values((array) aq_site('nav'));

// Per-panel "hide View all" flags, read from the matching nav item so the auto
// Services / Specialty / Areas panels can each suppress their top-right link
// (editor: AutoForge → Navigation → "Show the 'View all' link" toggle).
$panel_hide_va = [];
foreach ($nav_items as $ni) {
	if (isset($ni['panel'])) {
		$panel_hide_va[(string) $ni['panel']] = !empty($ni['hideViewAll']);
	}
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$is_active = static fn(string $href): bool => $path === $href || ($href !== '/' && str_starts_with($path, $href));

$phone     = aq_site('phone');
$phone_tel = aq_site('phoneTel');
$license   = aq_site('license.number');
$years     = aq_site('founded') ? ((int) date('Y') - (int) aq_site('founded')) : '';
$addr      = aq_site('address') ?: [];
$region    = $addr['region'] ?? '';
$logo_id   = (int) aq_site('logo.id');
?>
<header class="sticky top-0 z-40 bg-white border-b-4 border-accent-500">
	<div class="hidden md:block bg-brand-900 text-white text-xs">
		<div class="container-edge flex flex-wrap items-center justify-between gap-2 py-2">
			<div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-brand-100">
				<?php if ($license !== '' && $license !== null) : ?>
				<span class="inline-flex items-center gap-1.5">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-accent-400"><path d="M20 6L9 17l-5-5"/></svg>
					<?php echo esc_html(trim(($region ? $region . ' ' : '') . 'License #' . $license)); ?>
				</span>
				<?php endif; ?>
				<?php if ($years !== '') : ?>
				<span class="inline-flex items-center gap-1.5">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-accent-400"><path d="M20 6L9 17l-5-5"/></svg>
					<?php echo esc_html($years); ?> Years Experience
				</span>
				<?php endif; ?>
			</div>
			<span class="inline-flex items-center gap-1.5 text-brand-100">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-accent-400"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
				<?php echo esc_html(trim(($addr['street'] ?? '') . ', ' . ($addr['locality'] ?? '') . ', ' . ($addr['region'] ?? '') . ' ' . ($addr['postalCode'] ?? ''), ', ')); ?>
			</span>
		</div>
	</div>

	<div class="container-edge flex items-center justify-between py-4 gap-4">
		<a href="/" class="no-underline flex items-center" aria-label="<?php echo esc_attr(aq_site('shortName') . ' home'); ?>">
			<?php echo $logo_id ? wp_get_attachment_image($logo_id, 'full', false, [
				'sizes'    => '(max-width: 767px) 192px, 256px',
				'alt'      => aq_site('name'),
				'class'    => 'h-12 md:h-16 w-auto',
				'loading'  => 'eager',
				'decoding' => 'async',
			]) : ''; ?>
		</a>

		<button
			type="button"
			class="md:hidden inline-flex items-center justify-center w-10 h-10 rounded border border-brand-200 text-brand-800"
			aria-label="Toggle menu"
			aria-expanded="false"
			aria-controls="mobile-nav"
			data-nav-toggle
		>
			<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
		</button>

		<nav class="hidden md:flex flex-wrap items-center gap-x-6 gap-y-2 text-sm font-semibold uppercase tracking-wider" aria-label="Primary" data-megamenu>
			<?php $manual_panels = []; foreach ($nav_items as $i => $item) :
				$href      = $item['href'] ?? '#';
				$active    = $is_active($href);
				$is_auto   = isset($item['panel']);
				$is_manual = !$is_auto && !empty($item['children']) && is_array($item['children']);
				if ($is_auto || $is_manual) :
					// Trigger/panel key: auto panels keep their fixed name
					// (services|specialty|areas); a manual dropdown gets a per-item
					// key so the generic mega-menu JS pairs trigger ↔ panel itself.
					$mkey = $is_auto ? (string) $item['panel'] : ('mm' . $i);
					$mpid = $is_auto ? ((string) ($item['id'] ?? ('nav-' . $item['panel'])) . '-panel') : ('nav-' . $mkey . '-panel');
					if ($is_manual) { $manual_panels[$mkey] = $item; } ?>
					<div class="relative" data-mega-item>
						<button
							type="button"
							class="inline-flex items-center gap-1 no-underline transition-colors uppercase tracking-wider font-semibold <?php echo $active ? 'text-accent-700' : 'text-brand-800 hover:text-accent-700'; ?>"
							aria-haspopup="true"
							aria-expanded="false"
							aria-controls="<?php echo esc_attr($mpid); ?>"
							data-mega-trigger="<?php echo esc_attr($mkey); ?>"
						>
							<?php echo esc_html($item['label']); ?>
							<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M6 9l6 6 6-6"/></svg>
						</button>
					</div>
				<?php else : ?>
					<a
						href="<?php echo esc_url($href); ?>"
						class="no-underline transition-colors <?php echo $active ? 'text-accent-700' : 'text-brand-800 hover:text-accent-700'; ?>"
						<?php echo $active ? 'aria-current="page"' : ''; ?>
					><?php echo esc_html($item['label']); ?></a>
				<?php endif;
			endforeach; ?>
			<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="inline-flex items-center gap-2 bg-brand-800 text-white no-underline font-semibold normal-case tracking-normal py-2.5 px-4 rounded hover:bg-accent-600 transition-colors">
				<svg width="14" height="16" viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M16 64C16 28.7 44.7 0 80 0H304c35.3 0 64 28.7 64 64V448c0 35.3-28.7 64-64 64H80c-35.3 0-64-28.7-64-64V64zM144 448c0 8.8 7.2 16 16 16h64c8.8 0 16-7.2 16-16s-7.2-16-16-16H160c-8.8 0-16 7.2-16 16zM304 64H80V384H304V64z"/></svg>
				<?php echo esc_html($phone); ?>
			</a>
			<a href="/schedule/" class="btn-primary text-xs uppercase tracking-wider py-2.5 px-5">Schedule Inspection</a>
		</nav>
	</div>

	<div class="hidden md:block">
		<div
			id="nav-services-panel"
			class="mega-panel absolute left-0 right-0 bg-white shadow-lg z-50 border-t-4 border-accent-500"
			data-mega-panel="services"
			role="region"
			aria-label="Services menu"
		>
			<div class="container-edge container-edge--wide py-5 grid grid-cols-12 gap-6">
				<div class="col-span-8">
					<div class="flex items-center justify-between border-b border-brand-100 pb-2 mb-3">
						<span class="text-xs uppercase tracking-wider text-brand-500 font-semibold"><?php echo esc_html($mm_services['heading'] ?? 'Services'); ?></span>
						<?php if (empty($panel_hide_va['services'])) : ?>
						<a href="<?php echo esc_url($mm_services['viewAllHref'] ?? $svc_base); ?>" class="text-xs uppercase tracking-wider text-accent-700 font-semibold no-underline hover:text-accent-700 normal-case">View all &rarr;</a>
						<?php endif; ?>
					</div>
					<div class="grid grid-cols-1 lg:grid-cols-2 gap-x-6 gap-y-2">
						<?php foreach ($services as $s) :
							$href   = $svc_base . $s['slug'] . '/';
							$active = $path === $href; ?>
							<a href="<?php echo esc_url($href); ?>" class="group flex items-start gap-3 no-underline normal-case tracking-normal py-1" <?php echo $active ? 'aria-current="page"' : ''; ?>>
								<span class="flex-shrink-0 w-9 h-9 rounded-md flex items-center justify-center transition-colors <?php echo $active ? 'bg-accent-500 text-white' : 'bg-accent-50 text-accent-700 group-hover:bg-accent-500 group-hover:text-white'; ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $s['icon'] ?? ''; ?></svg>
								</span>
								<span class="block">
									<span class="block font-semibold text-sm transition-colors leading-tight <?php echo $active ? 'text-accent-700' : 'text-brand-800 group-hover:text-accent-700'; ?>"><?php echo esc_html($s['label'] ?? ''); ?></span>
									<span class="block text-xs text-brand-500 font-normal mt-0.5"><?php echo esc_html($s['tagline'] ?? ''); ?></span>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="col-span-4 bg-brand-50 rounded-lg p-5 normal-case tracking-normal">
					<p class="text-xs uppercase tracking-wider text-accent-700 font-semibold"><?php echo esc_html($svc_promo['eyebrow'] ?? 'Ready to schedule?'); ?></p>
					<p class="mt-2 text-sm text-brand-700"><?php echo esc_html($svc_promo['text'] ?? ''); ?></p>
					<a href="/schedule/" class="btn-primary text-xs uppercase tracking-wider mt-4 py-2.5 px-5 inline-block">Schedule Inspection</a>
					<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="block mt-3 text-brand-800 font-semibold no-underline hover:text-accent-700 text-sm">Call <?php echo esc_html($phone); ?></a>
					<?php if ($license !== '' && $license !== null) : ?>
					<p class="mt-3 text-xs text-brand-500"><?php echo esc_html(trim(($region ? $region . ' ' : '') . 'License #' . $license)); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div
			id="nav-specialty-panel"
			class="mega-panel absolute left-0 right-0 bg-white shadow-lg z-50 border-t-4 border-accent-500"
			data-mega-panel="specialty"
			role="region"
			aria-label="Specialty testing menu"
		>
			<div class="container-edge container-edge--wide py-5 grid grid-cols-12 gap-6">
				<div class="col-span-8">
					<div class="flex items-center justify-between border-b border-brand-100 pb-2 mb-3">
						<span class="text-xs uppercase tracking-wider text-brand-500 font-semibold"><?php echo esc_html($mm_specialty['heading'] ?? 'Specialty Testing'); ?></span>
						<?php if (empty($panel_hide_va['specialty'])) : ?>
						<a href="<?php echo esc_url($mm_specialty['viewAllHref'] ?? $spc_base); ?>" class="text-xs uppercase tracking-wider text-accent-700 font-semibold no-underline hover:text-accent-700 normal-case">View all &rarr;</a>
						<?php endif; ?>
					</div>
					<div class="grid grid-cols-1 lg:grid-cols-2 gap-x-6 gap-y-2">
						<?php foreach ($specialty as $s) :
							$href   = $spc_base . $s['slug'] . '/';
							$active = $path === $href; ?>
							<a href="<?php echo esc_url($href); ?>" class="group flex items-start gap-3 no-underline normal-case tracking-normal py-1" <?php echo $active ? 'aria-current="page"' : ''; ?>>
								<span class="flex-shrink-0 w-9 h-9 rounded-md flex items-center justify-center transition-colors <?php echo $active ? 'bg-accent-500 text-white' : 'bg-accent-50 text-accent-700 group-hover:bg-accent-500 group-hover:text-white'; ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $s['icon'] ?? ''; ?></svg>
								</span>
								<span class="block">
									<span class="block font-semibold text-sm transition-colors leading-tight <?php echo $active ? 'text-accent-700' : 'text-brand-800 group-hover:text-accent-700'; ?>"><?php echo esc_html($s['label'] ?? ''); ?></span>
									<span class="block text-xs text-brand-500 font-normal mt-0.5"><?php echo esc_html($s['tagline'] ?? ''); ?></span>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="col-span-4 bg-brand-50 rounded-lg p-5 normal-case tracking-normal">
					<p class="text-xs uppercase tracking-wider text-accent-700 font-semibold"><?php echo esc_html($spc_promo['eyebrow'] ?? 'Bundle &amp; save'); ?></p>
					<p class="mt-2 text-sm text-brand-700"><?php echo esc_html($spc_promo['text'] ?? ''); ?></p>
					<a href="/pricing/" class="btn-primary text-xs uppercase tracking-wider mt-4 py-2.5 px-5 inline-block">See Pricing</a>
					<a href="/schedule/" class="block mt-3 text-brand-800 font-semibold no-underline hover:text-accent-700 text-sm">Schedule online &rarr;</a>
				</div>
			</div>
		</div>

		<div
			id="nav-areas-panel"
			class="mega-panel absolute left-0 right-0 bg-white shadow-lg z-50 border-t-4 border-accent-500"
			data-mega-panel="areas"
			role="region"
			aria-label="Service area menu"
		>
			<div class="container-edge container-edge--wide py-5 grid grid-cols-12 gap-6">
				<div class="col-span-8 normal-case tracking-normal">
					<div class="flex items-center justify-between border-b border-brand-100 pb-2 mb-3">
						<span class="text-xs uppercase tracking-wider text-brand-500 font-semibold"><?php echo esc_html($mm_areas['heading'] ?? 'Service Area'); ?></span>
						<?php if (empty($panel_hide_va['areas'])) : ?>
						<a href="<?php echo esc_url($mm_areas['viewAllHref'] ?? $area_base); ?>" class="text-xs uppercase tracking-wider text-accent-700 font-semibold no-underline hover:text-accent-700">View all &rarr;</a>
						<?php endif; ?>
					</div>
					<div class="grid grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-2">
						<?php foreach ($towns as $t) :
							$href   = $area_base . $t['slug'] . '/';
							$active = $path === $href; ?>
							<a href="<?php echo esc_url($href); ?>" class="group flex items-start gap-3 no-underline py-1" <?php echo $active ? 'aria-current="page"' : ''; ?>>
								<span class="flex-shrink-0 w-9 h-9 rounded-md flex items-center justify-center transition-colors <?php echo $active ? 'bg-accent-500 text-white' : 'bg-accent-50 text-accent-700 group-hover:bg-accent-500 group-hover:text-white'; ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
								</span>
								<span class="block">
									<span class="block font-semibold text-sm transition-colors leading-tight <?php echo $active ? 'text-accent-700' : 'text-brand-800 group-hover:text-accent-700'; ?>"><?php echo esc_html($t['name'] . ($region ? ', ' . $region : '')); ?></span>
									<span class="block text-xs text-brand-500 font-normal mt-0.5"><?php echo esc_html($t['county']); ?> County</span>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="col-span-4 bg-brand-50 rounded-lg p-5 normal-case tracking-normal">
					<p class="text-xs uppercase tracking-wider text-accent-700 font-semibold"><?php echo esc_html($area_promo['eyebrow'] ?? "Don't see your town?"); ?></p>
					<p class="mt-2 text-sm text-brand-700"><?php echo esc_html($area_promo['text'] ?? ''); ?></p>
					<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="btn-primary text-xs uppercase tracking-wider mt-4 py-2.5 px-5 inline-block">Call <?php echo esc_html($phone); ?></a>
				</div>
			</div>
		</div>

		<?php /* Manual dropdowns: any nav item the editor gave its own sub-links.
		         Same rich panel styling as the auto panels; keyed mm{index} to
		         match the trigger emitted in the primary nav above. */
		foreach ($manual_panels as $mkey => $mp) :
			$kids   = array_values((array) ($mp['children'] ?? []));
			$mpromo = is_array($mp['promo'] ?? null) ? $mp['promo'] : [];
			$haspro = ($mpromo && ((($mpromo['eyebrow'] ?? '') !== '') || (($mpromo['text'] ?? '') !== '')));
			$vlabel = (($mp['linkLabel'] ?? '') !== '') ? $mp['linkLabel'] : 'View all';
		?>
		<div
			id="nav-<?php echo esc_attr($mkey); ?>-panel"
			class="mega-panel absolute left-0 right-0 bg-white shadow-lg z-50 border-t-4 border-accent-500"
			data-mega-panel="<?php echo esc_attr($mkey); ?>"
			role="region"
			aria-label="<?php echo esc_attr($mp['label'] . ' menu'); ?>"
		>
			<div class="container-edge container-edge--wide py-5 grid grid-cols-12 gap-6">
				<div class="<?php echo $haspro ? 'col-span-8' : 'col-span-12'; ?>">
					<div class="flex items-center justify-between border-b border-brand-100 pb-2 mb-3">
						<span class="text-xs uppercase tracking-wider text-brand-500 font-semibold"><?php echo esc_html($mp['label']); ?></span>
						<?php if (empty($mp['hideViewAll'])) : ?>
						<a href="<?php echo esc_url($mp['href'] ?? '#'); ?>" class="text-xs uppercase tracking-wider text-accent-700 font-semibold no-underline hover:text-accent-700 normal-case"><?php echo esc_html($vlabel); ?> &rarr;</a>
						<?php endif; ?>
					</div>
					<div class="grid grid-cols-1 lg:grid-cols-2 gap-x-6 gap-y-2">
						<?php foreach ($kids as $c) :
							$chref   = $c['href'] ?? '#';
							$cactive = $path === $chref; ?>
							<a href="<?php echo esc_url($chref); ?>" class="group flex items-start gap-3 no-underline normal-case tracking-normal py-1" <?php echo $cactive ? 'aria-current="page"' : ''; ?>>
								<?php if (!empty($c['icon'])) : ?>
								<span class="flex-shrink-0 w-9 h-9 rounded-md flex items-center justify-center transition-colors <?php echo $cactive ? 'bg-accent-500 text-white' : 'bg-accent-50 text-accent-700 group-hover:bg-accent-500 group-hover:text-white'; ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $c['icon']; ?></svg>
								</span>
								<?php endif; ?>
								<span class="block">
									<span class="block font-semibold text-sm transition-colors leading-tight <?php echo $cactive ? 'text-accent-700' : 'text-brand-800 group-hover:text-accent-700'; ?>"><?php echo esc_html($c['label'] ?? ''); ?></span>
									<?php if (($c['tagline'] ?? '') !== '') : ?>
									<span class="block text-xs text-brand-500 font-normal mt-0.5"><?php echo esc_html($c['tagline']); ?></span>
									<?php endif; ?>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
				<?php if ($haspro) : ?>
				<div class="col-span-4 bg-brand-50 rounded-lg p-5 normal-case tracking-normal">
					<?php if (($mpromo['eyebrow'] ?? '') !== '') : ?><p class="text-xs uppercase tracking-wider text-accent-700 font-semibold"><?php echo esc_html($mpromo['eyebrow']); ?></p><?php endif; ?>
					<?php if (($mpromo['text'] ?? '') !== '') : ?><p class="mt-2 text-sm text-brand-700"><?php echo esc_html($mpromo['text']); ?></p><?php endif; ?>
					<?php if (($mpromo['ctaLabel'] ?? '') !== '') : ?><a href="<?php echo esc_url($mpromo['ctaHref'] ?? '#'); ?>" class="btn-primary text-xs uppercase tracking-wider mt-4 py-2.5 px-5 inline-block"><?php echo esc_html($mpromo['ctaLabel']); ?></a><?php endif; ?>
					<?php if (($mpromo['cta2Label'] ?? '') !== '') : ?><a href="<?php echo esc_url($mpromo['cta2Href'] ?? '#'); ?>" class="block mt-3 text-brand-800 font-semibold no-underline hover:text-accent-700 text-sm"><?php echo esc_html($mpromo['cta2Label']); ?></a><?php endif; ?>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
	</div>

	<nav id="mobile-nav" class="md:hidden hidden border-t border-brand-100" aria-label="Mobile" data-nav-mobile>
		<div class="container-edge py-2 flex flex-col">
			<?php foreach ($nav_items as $item) :
				$is_auto   = isset($item['panel']);
				$is_manual = !$is_auto && !empty($item['children']) && is_array($item['children']);
				if ($is_auto || $is_manual) : ?>
					<details class="border-b border-brand-100">
						<summary class="cursor-pointer flex items-center justify-between text-brand-800 py-4 list-none">
							<span class="font-semibold text-base"><?php echo esc_html($item['label']); ?></span>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
						</summary>
						<div class="pl-4 pb-3 flex flex-col gap-0">
							<a href="<?php echo esc_url($item['href'] ?? '#'); ?>" class="text-brand-700 no-underline font-semibold py-3 border-b border-brand-50">All <?php echo esc_html($item['label']); ?> &rarr;</a>
							<?php if ($is_manual) :
								foreach (array_values((array) $item['children']) as $c) : ?>
									<a href="<?php echo esc_url($c['href'] ?? '#'); ?>" class="text-brand-700 no-underline py-3 border-b border-brand-50"><?php echo esc_html($c['label'] ?? ''); ?></a>
								<?php endforeach;
							elseif ($item['panel'] === 'services') :
								foreach ($services as $s) : ?>
									<a href="<?php echo esc_url($svc_base . $s['slug'] . '/'); ?>" class="text-brand-700 no-underline py-3 border-b border-brand-50"><?php echo esc_html($s['label'] ?? ''); ?></a>
								<?php endforeach;
							elseif ($item['panel'] === 'specialty') :
								foreach ($specialty as $s) : ?>
									<a href="<?php echo esc_url($spc_base . $s['slug'] . '/'); ?>" class="text-brand-700 no-underline py-3 border-b border-brand-50"><?php echo esc_html($s['label'] ?? ''); ?></a>
								<?php endforeach;
							elseif ($item['panel'] === 'areas') :
								foreach ($towns as $t) : ?>
									<a href="<?php echo esc_url($area_base . $t['slug'] . '/'); ?>" class="text-brand-700 no-underline py-3 border-b border-brand-50"><?php echo esc_html($t['name'] . ($region ? ', ' . $region : '')); ?></a>
								<?php endforeach;
							endif; ?>
						</div>
					</details>
				<?php else : ?>
					<a href="<?php echo esc_url($item['href']); ?>" class="text-brand-800 no-underline py-4 border-b border-brand-100 font-semibold text-base"><?php echo esc_html($item['label']); ?></a>
				<?php endif;
			endforeach; ?>
			<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="text-brand-800 no-underline py-4 border-b border-brand-100 font-semibold text-base">Call <?php echo esc_html($phone); ?></a>
			<a href="/schedule/" class="btn-primary text-sm mt-4">Schedule Inspection</a>
		</div>
	</nav>
</header>
