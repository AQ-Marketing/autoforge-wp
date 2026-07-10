<?php
/**
 * contact_form — native lead-capture form + direct-contact sidebar. Replaces
 * a third-party embed (e.g. a GHL iframe widget) with a real, editor-native
 * form that posts (fetch → JSON) to the engine's generic lead endpoint
 * /wp-json/aqm/v1/contact (AQ_Lead_Capture), which emails the address set on
 * AutoForge -> Forms and — once a client configures a GHL Private Integration
 * Token + Location ID there — pushes the same lead into GoHighLevel. No
 * per-client JS dependency: the submit handler is self-contained inline.
 *
 * Pattern ported from the So Clean / Zacarius client builds (data-endpoint
 * fetch handler, honeypot, native validation, redirect-to-thank-you on
 * success) and restyled in this site's own Tailwind design tokens.
 */
if (!defined('ABSPATH')) {
	exit;
}
$s = $args['s'] ?? [];

$phone      = (string) aq_site('phone');
$phone_tel  = (string) (aq_site('phoneTel') ?: $phone);
$email      = (string) aq_site('email');
$addr       = (array) (aq_site('address') ?: []);
$addr_line1 = (string) ($addr['street'] ?? '');
$addr_line2 = trim(implode(', ', array_filter([$addr['locality'] ?? '', trim(($addr['region'] ?? '') . ' ' . ($addr['postalCode'] ?? ''))])));
$name       = (string) aq_site('name');
$lic_pfx    = (string) (aq_site('labels.licensePrefix') ?: 'License #');
$lic_num    = (string) aq_site('license.number');

$inspection_types    = (array) ($s['inspection_types'] ?? []);
$specialty_services  = (array) ($s['specialty_services'] ?? []);
$endpoint            = esc_url(rest_url('aqm/v1/contact'));
$thankyou            = (string) ($s['thankyou_href'] ?? '') ?: '/thank-you/';
$privacy_href        = (string) ($s['privacy_href'] ?? '') ?: '/privacy/';
$terms_href          = (string) ($s['terms_href'] ?? '') ?: '/terms/';
$consent_text        = (string) ($s['consent_text'] ?? '');
?>
<section class="bg-white py-12 md:py-16 lg:py-20">
	<div class="container-edge container-edge--wide">
		<div class="grid lg:grid-cols-3 gap-10">

			<section class="lg:col-span-2">
				<?php if (($s['heading'] ?? '') !== '') : ?>
				<h2<?php echo ka_field_attr('heading'); ?> class="!mt-0 text-2xl md:text-3xl">
					<?php echo esc_html($s['heading']); ?>
				</h2>
				<?php endif; ?>
				<?php if (($s['intro'] ?? '') !== '') : ?>
				<p<?php echo ka_field_attr('intro'); ?> class="text-brand-700 leading-relaxed mb-6">
					<?php echo esc_html($s['intro']); ?>
				</p>
				<?php endif; ?>

				<form class="js-contact-form space-y-5" data-endpoint="<?php echo $endpoint; ?>" data-thankyou="<?php echo esc_attr($thankyou); ?>" novalidate>

					<div class="grid sm:grid-cols-2 gap-4">
						<div>
							<label for="cf-first" class="block text-sm font-semibold text-brand-800 mb-1.5">First Name *</label>
							<input id="cf-first" name="firstName" required autocomplete="given-name" placeholder="First name" class="w-full rounded-lg border border-brand-200 px-3.5 py-2.5 text-brand-900 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
						</div>
						<div>
							<label for="cf-last" class="block text-sm font-semibold text-brand-800 mb-1.5">Last Name *</label>
							<input id="cf-last" name="lastName" required autocomplete="family-name" placeholder="Last name" class="w-full rounded-lg border border-brand-200 px-3.5 py-2.5 text-brand-900 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
						</div>
					</div>

					<div class="grid sm:grid-cols-2 gap-4">
						<div>
							<label for="cf-phone" class="block text-sm font-semibold text-brand-800 mb-1.5">Phone *</label>
							<input id="cf-phone" name="phone" type="tel" required autocomplete="tel" placeholder="(413) 000-0000" class="w-full rounded-lg border border-brand-200 px-3.5 py-2.5 text-brand-900 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
						</div>
						<div>
							<label for="cf-email" class="block text-sm font-semibold text-brand-800 mb-1.5">Email *</label>
							<input id="cf-email" name="email" type="email" required autocomplete="email" placeholder="you@email.com" class="w-full rounded-lg border border-brand-200 px-3.5 py-2.5 text-brand-900 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
						</div>
					</div>

					<div>
						<label for="cf-address" class="block text-sm font-semibold text-brand-800 mb-1.5">Property Street Address *</label>
						<input id="cf-address" name="address" required autocomplete="address-line1" placeholder="Enter your full address" class="w-full rounded-lg border border-brand-200 px-3.5 py-2.5 text-brand-900 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
					</div>

					<div class="grid sm:grid-cols-3 gap-4">
						<div class="sm:col-span-1">
							<label for="cf-city" class="block text-sm font-semibold text-brand-800 mb-1.5">City *</label>
							<input id="cf-city" name="city" required autocomplete="address-level2" placeholder="Enter your city" class="w-full rounded-lg border border-brand-200 px-3.5 py-2.5 text-brand-900 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
						</div>
						<div>
							<label for="cf-state" class="block text-sm font-semibold text-brand-800 mb-1.5">State *</label>
							<input id="cf-state" name="state" required autocomplete="address-level1" value="MA" class="w-full rounded-lg border border-brand-200 px-3.5 py-2.5 text-brand-900 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
						</div>
						<div>
							<label for="cf-zip" class="block text-sm font-semibold text-brand-800 mb-1.5">Postal Code *</label>
							<input id="cf-zip" name="zip" required inputmode="numeric" autocomplete="postal-code" placeholder="ZIP or postal code" class="w-full rounded-lg border border-brand-200 px-3.5 py-2.5 text-brand-900 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500">
						</div>
					</div>

					<?php if ($inspection_types) : ?>
					<div>
						<label<?php echo ka_field_attr('inspection_types_label'); ?> class="block text-sm font-semibold text-brand-800 mb-2">
							<?php echo esc_html(($s['inspection_types_label'] ?? '') ?: 'Inspection Type *'); ?>
						</label>
						<div class="grid sm:grid-cols-2 gap-2">
							<?php foreach ($inspection_types as $i => $it) :
								$lbl = (string) ($it['label'] ?? '');
								if ($lbl === '') continue;
							?>
							<label<?php echo ka_field_attr('inspection_types', $i); ?> class="flex items-center gap-2.5 rounded-lg border border-brand-200 px-3.5 py-2.5 text-sm text-brand-800 cursor-pointer hover:border-accent-500 has-[:checked]:border-accent-500 has-[:checked]:bg-accent-50">
								<input type="radio" name="inspectionType" value="<?php echo esc_attr($lbl); ?>" required class="accent-accent-500">
								<?php echo esc_html($lbl); ?>
							</label>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<?php if ($specialty_services) : ?>
					<div>
						<label<?php echo ka_field_attr('specialty_services_label'); ?> class="block text-sm font-semibold text-brand-800 mb-2">
							<?php echo esc_html(($s['specialty_services_label'] ?? '') ?: 'Specialty Services Needed'); ?>
						</label>
						<div class="grid sm:grid-cols-2 gap-2">
							<?php foreach ($specialty_services as $i => $sv) :
								$lbl = (string) ($sv['label'] ?? '');
								if ($lbl === '') continue;
							?>
							<label<?php echo ka_field_attr('specialty_services', $i); ?> class="flex items-center gap-2.5 rounded-lg border border-brand-200 px-3.5 py-2.5 text-sm text-brand-800 cursor-pointer hover:border-accent-500 has-[:checked]:border-accent-500 has-[:checked]:bg-accent-50">
								<input type="checkbox" name="specialty" value="<?php echo esc_attr($lbl); ?>" class="accent-accent-500">
								<?php echo esc_html($lbl); ?>
							</label>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<div>
						<label for="cf-message" class="block text-sm font-semibold text-brand-800 mb-1.5">Note &mdash; Additional Details</label>
						<textarea id="cf-message" name="message" rows="4" placeholder="Anything else we should know?" class="w-full rounded-lg border border-brand-200 px-3.5 py-2.5 text-brand-900 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500"></textarea>
					</div>

					<?php if ($consent_text !== '') : ?>
					<label class="flex items-start gap-2.5 text-sm text-brand-700">
						<input type="checkbox" name="consent" required class="mt-0.5 accent-accent-500">
						<span<?php echo ka_field_attr('consent_text'); ?>><?php echo esc_html($consent_text); ?></span>
					</label>
					<?php endif; ?>

					<div class="hidden" aria-hidden="true">
						<label for="cf-hp">Company (leave blank)</label>
						<input id="cf-hp" name="company_hp" tabindex="-1" autocomplete="off">
					</div>

					<button type="submit" class="btn-primary w-full sm:w-auto text-xs uppercase tracking-wider py-3 px-8">
						<?php echo esc_html(($s['submit_label'] ?? '') ?: 'Send'); ?>
					</button>

					<p class="form-err text-sm font-semibold text-red-600" role="alert" hidden></p>

					<p class="text-xs text-brand-500">
						<a href="<?php echo esc_url($privacy_href); ?>" class="underline hover:text-accent-700">Privacy Policy</a>
						&nbsp;|&nbsp;
						<a href="<?php echo esc_url($terms_href); ?>" class="underline hover:text-accent-700">Terms of Service</a>
					</p>
				</form>

				<div class="js-contact-form-done hidden text-center py-10">
					<p class="text-xl font-serif font-bold text-brand-900 mb-2">Thanks &mdash; request received!</p>
					<p class="text-brand-700">We&rsquo;ll be in touch shortly to confirm your inspection.</p>
				</div>
			</section>

			<aside>
				<div class="rounded-2xl bg-brand-700 text-white p-6">
					<h2<?php echo ka_field_attr('sidebar_heading'); ?> class="!mt-0 text-white text-xl">
						<?php echo esc_html(($s['sidebar_heading'] ?? '') ?: 'Direct Contact'); ?>
					</h2>
					<?php if ($phone !== '') : ?>
					<p class="mt-3 text-brand-50">
						<strong>Phone:</strong><br>
						<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="text-white text-2xl font-serif"><?php echo esc_html($phone); ?></a>
					</p>
					<?php endif; ?>
					<?php if ($email !== '') : ?>
					<p class="mt-4 text-brand-50">
						<strong>Email:</strong><br>
						<a href="mailto:<?php echo esc_attr($email); ?>" class="text-white"><?php echo esc_html($email); ?></a>
					</p>
					<?php endif; ?>
					<?php if ($addr_line1 !== '') : ?>
					<address class="not-italic mt-4 text-brand-50">
						<strong>Office:</strong><br>
						<?php echo esc_html($addr_line1); ?><br>
						<?php echo esc_html($addr_line2); ?>
					</address>
					<?php endif; ?>
				</div>
				<?php if ($name !== '') : ?>
				<div class="card mt-4">
					<p class="text-sm text-brand-700">
						<?php echo esc_html($name); ?> is a one-inspector firm — every inspection is performed personally.
						Independent, owner-operated<?php echo $lic_num !== '' ? ', ' . esc_html($lic_pfx . $lic_num) : ''; ?>.
					</p>
				</div>
				<?php endif; ?>
			</aside>

		</div>
	</div>
</section>
<script>
(function () {
	var section = document.currentScript.previousElementSibling;
	var form = section ? section.querySelector('.js-contact-form') : null;
	if (!form || form.dataset.bound) { return; }
	form.dataset.bound = '1';

	var wrap    = form.closest('section');
	var done    = wrap ? wrap.querySelector('.js-contact-form-done') : null;
	var errBox  = form.querySelector('.form-err');
	var btn     = form.querySelector('button[type=submit]');
	var btnTxt  = btn ? btn.textContent : '';

	function showErr(msg) {
		if (!errBox) return;
		errBox.textContent = msg;
		errBox.hidden = false;
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		if (errBox) errBox.hidden = true;
		if (!form.checkValidity()) { form.reportValidity(); return; }

		var g = function (n) { var el = form.elements[n]; return el ? el.value.trim() : ''; };
		var specialty = Array.prototype.slice.call(form.querySelectorAll('input[name=specialty]:checked')).map(function (c) { return c.value; });
		var service = [g('inspectionType')].concat(specialty).filter(Boolean).join(', ');

		var payload = {
			firstName: g('firstName'),
			lastName:  g('lastName'),
			phone:     g('phone'),
			email:     g('email'),
			address:   g('address'),
			city:      g('city'),
			state:     g('state'),
			zip:       g('zip'),
			service:   service,
			message:   g('message'),
			company_hp: g('company_hp'),
			source:    'Website contact form'
		};

		if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }

		fetch(form.dataset.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify(payload)
		}).then(function (res) {
			if (res.ok) {
				var thankyou = form.dataset.thankyou;
				if (thankyou) {
					window.location.assign(thankyou);
					return;
				}
				form.hidden = true;
				if (done) done.classList.remove('hidden');
				return;
			}
			throw new Error('bad_status');
		}).catch(function () {
			showErr('Sorry — something went wrong sending your request. Please call us at <?php echo esc_js($phone); ?> and we will help right away.');
			if (btn) { btn.disabled = false; btn.textContent = btnTxt; }
		});
	});
})();
</script>
