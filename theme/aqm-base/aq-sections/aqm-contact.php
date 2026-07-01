<?php
/** AQM Contact — lead-capture form + info cards + map embed. Transliterated 1:1
 *  from the static /contact/ page. The form POSTs (fetch) to the aqm/v1/contact
 *  REST route registered in blocks/aqm-blocks.php (honeypot + required-field
 *  validation + wp_mail). Editable: form heading/sub copy, the service dropdown
 *  options, consent text, submit + success copy, the contact info cards, and the
 *  Google Maps query. Scoped CSS/JS travel with the block so it is self-contained
 *  on any page that uses it. The renderer auto-injects data-aq-section into the
 *  first <section>. */
if (!defined('ABSPATH')) {
	exit;
}
$s         = $args['s'] ?? [];
$heading   = (string) ($s['heading'] ?? 'Request a free audit');
$sub       = (string) ($s['sub'] ?? "Takes under a minute. We'll reply within 2 business days.");
$services  = array_values(array_filter((array) ($s['services'] ?? []), fn($o) => is_array($o) && ($o['label'] ?? '') !== ''));
$info      = array_values(array_filter((array) ($s['info'] ?? []), fn($c) => is_array($c) && (($c['title'] ?? '') !== '' || ($c['body'] ?? '') !== '')));
$consent   = (string) ($s['consent'] ?? 'I agree to receive a one-time audit response at the email and phone I provided. AQ Marketing will never sell or share my info.');
$submit    = (string) ($s['submit_label'] ?? 'Send my free audit request');
$success   = (string) ($s['success_msg'] ?? "Got it — we'll be in touch within 2 business days.");
$map_q     = (string) ($s['map_query'] ?? '400 Tradecenter Dr, Woburn, MA 01801');
$map_label = (string) ($s['map_label'] ?? 'Map showing the AQ Marketing office in Woburn, MA');
$rest      = esc_url_raw(rest_url('aqm/v1/contact'));
$map_src   = 'https://www.google.com/maps?q=' . rawurlencode($map_q) . '&output=embed';

// Thank-you redirect + admin test-fill button (AutoForge -> Forms). Both are
// off-by-default-safe: no thank-you URL = the existing inline success message;
// the test button is gated server-side on manage_options, so its markup and
// mock data never reach anonymous visitors at all.
$forms_cfg  = class_exists('AQ_Lead_Capture') ? AQ_Lead_Capture::get_settings() : ['thankyou_url' => '', 'test_button' => false];
$thankyou   = (string) $forms_cfg['thankyou_url'];
$thankyou_url = $thankyou !== '' && $thankyou[0] === '/' ? home_url($thankyou) : $thankyou;
$show_test_btn = current_user_can('manage_options') && !empty($forms_cfg['test_button']);
?>
<section>
	<div class="wrap">
		<div class="contact-grid">
			<form class="contact-form" id="contactForm" action="<?php echo esc_url($rest); ?>" method="POST" data-success="<?php echo esc_attr($success); ?>" data-thankyou="<?php echo esc_attr($thankyou_url); ?>" novalidate
				toolname="contact_form" tooldescription="<?php echo esc_attr($heading !== '' ? $heading : 'Submit a contact request'); ?>">
				<?php if ($show_test_btn) : ?>
				<button type="button" id="contactFormTestFill" style="display:block;width:100%;margin:0 0 16px;padding:9px 14px;background:#0d1014;color:#fff;border:0;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">🔧 Fill with test data (admin only)</button>
				<script>window.AQ_CONTACT_TEST = <?php echo wp_json_encode([
					'name'     => (string) ($forms_cfg['test_name'] ?? 'Test Tester'),
					'email'    => (string) ($forms_cfg['test_email'] ?? 'test@example.com'),
					'phone'    => (string) ($forms_cfg['test_phone'] ?? ''),
					'business' => (string) ($forms_cfg['test_business'] ?? ''),
					'website'  => 'https://example.com',
					'message'  => (string) ($forms_cfg['test_message'] ?? ''),
				]); ?>;</script>
				<?php endif; ?>
				<h2 style="font-size:22px;margin-bottom:6px"<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($heading); ?></h2>
				<?php if ($sub !== '') : ?><p style="margin-bottom:24px;font-size:14px"<?php echo ka_field_attr('sub'); ?>><?php echo esc_html($sub); ?></p><?php endif; ?>

				<div class="row2">
					<div class="field">
						<label for="firstName">First name <span class="req">*</span></label>
						<input type="text" id="firstName" name="firstName" autocomplete="given-name" required toolparamdescription="The visitor's first name">
					</div>
					<div class="field">
						<label for="lastName">Last name <span class="req">*</span></label>
						<input type="text" id="lastName" name="lastName" autocomplete="family-name" required toolparamdescription="The visitor's last name">
					</div>
				</div>

				<div class="row2">
					<div class="field">
						<label for="email">Work email <span class="req">*</span></label>
						<input type="email" id="email" name="email" autocomplete="email" required toolparamdescription="The visitor's email address">
					</div>
					<div class="field">
						<label for="phone">Phone</label>
						<input type="tel" id="phone" name="phone" autocomplete="tel" placeholder="(555) 123-4567" toolparamdescription="The visitor's phone number (optional)">
					</div>
				</div>

				<div class="row2">
					<div class="field">
						<label for="business">Business name <span class="req">*</span></label>
						<input type="text" id="business" name="business" autocomplete="organization" required toolparamdescription="The name of the visitor's business">
					</div>
					<div class="field">
						<label for="website">Current website</label>
						<input type="url" id="website" name="website" autocomplete="url" placeholder="https://" toolparamdescription="The visitor's current website URL (optional)">
					</div>
				</div>

				<div class="field">
					<label for="service">What are you looking for? <span class="req">*</span></label>
					<select id="service" name="service" required toolparamdescription="Which service or offering the visitor is interested in"<?php echo ka_field_attr('services'); ?>>
						<option value="">Choose one…</option>
						<?php if ($services) : foreach ($services as $o) : ?>
						<option><?php echo esc_html($o['label']); ?></option>
						<?php endforeach; else : ?>
						<option>Not sure — I need guidance</option>
						<?php endif; ?>
					</select>
				</div>

				<div class="field">
					<label for="message">Anything else we should know?</label>
					<textarea id="message" name="message" placeholder="Biggest challenge right now? Competitors you want to beat? Timeline?" toolparamdescription="Additional context from the visitor (optional)"></textarea>
				</div>

				<!-- honeypot — leave blank; bots fill it -->
				<div class="hp" aria-hidden="true"><label>Leave this empty<input type="text" name="company_hp" tabindex="-1" autocomplete="off"></label></div>

				<div class="consent">
					<input type="checkbox" id="consent" name="consent" required>
					<label for="consent" style="font-weight:400;margin:0"<?php echo ka_field_attr('consent'); ?>><?php echo esc_html($consent); ?></label>
				</div>

				<button type="submit" class="btn btn-primary btn-lg"><i class="fa-solid fa-paper-plane"></i> <?php echo esc_html($submit); ?></button>
				<p id="formStatus" role="status" aria-live="polite" style="margin-top:14px;font-size:13px;min-height:1em"></p>
			</form>

			<aside class="contact-info" aria-label="Contact details">
				<?php foreach ($info as $i => $c) : $cfa = (string) ($c['fa'] ?? ''); ?>
				<div class="info-card"<?php echo ka_field_attr('info', $i); ?>>
					<h3><?php if ($cfa !== '') : ?><i class="fa-solid <?php echo esc_attr($cfa); ?>"></i> <?php endif; ?><?php echo esc_html($c['title'] ?? ''); ?></h3>
					<p><?php echo wp_kses_post($c['body'] ?? ''); ?></p>
				</div>
				<?php endforeach; ?>
				<div class="map-embed" aria-label="<?php echo esc_attr($map_label); ?>">
					<iframe loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="<?php echo esc_url($map_src); ?>" title="<?php echo esc_attr($map_label); ?>"></iframe>
				</div>
			</aside>
		</div>
	</div>
</section>
<style>
	.contact-grid{display:grid;grid-template-columns:1.3fr 1fr;gap:48px;align-items:start}
	.contact-form{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:36px}
	.contact-form .field{margin-bottom:18px}
	.contact-form label{display:block;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:6px}
	.contact-form label .req{color:var(--teal)}
	.contact-form input,.contact-form select,.contact-form textarea{
		width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:6px;
		font-family:inherit;font-size:15px;color:var(--ink);background:#fff;transition:border-color .15s,box-shadow .15s
	}
	.contact-form input:focus,.contact-form select:focus,.contact-form textarea:focus{
		outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(200,16,46,.12)
	}
	.contact-form textarea{resize:vertical;min-height:120px}
	.contact-form .row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
	.contact-form .consent{font-size:13px;color:var(--muted);line-height:1.5;display:flex;align-items:flex-start;gap:10px;margin-bottom:22px}
	.contact-form .consent input{width:auto;margin-top:4px}
	.contact-form button{width:100%;padding:14px 20px;font-size:15px;justify-content:center}
	.contact-form .hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
	.contact-info{display:flex;flex-direction:column;gap:20px;position:sticky;top:112px}
	.info-card{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:26px}
	.info-card h3{font-size:16px;margin:0 0 14px;display:flex;align-items:center;gap:10px}
	.info-card h3 i{color:var(--teal);font-size:16px}
	.info-card p,.info-card a{font-size:14px;color:var(--muted);line-height:1.55;margin:0}
	.info-card a{color:var(--ink);font-weight:600;text-decoration:none}
	.info-card a:hover{color:var(--teal)}
	.info-card .muted{color:var(--muted);font-weight:400}
	.map-embed{border-radius:var(--radius);overflow:hidden;border:1px solid var(--line);background:#eee;aspect-ratio:4/3}
	.map-embed iframe{width:100%;height:100%;border:0;display:block}
	@media (max-width:1024px){.contact-grid{grid-template-columns:1fr}.contact-info{position:static}.contact-form{padding:28px}}
	@media (max-width:720px){.contact-form .row2{grid-template-columns:1fr}}
</style>
<script>
	(function(){
		var form=document.getElementById('contactForm');
		var status=document.getElementById('formStatus');
		if(!form||!status) return;
		var okMsg=form.getAttribute('data-success')||"Got it — we'll be in touch shortly.";
		var thankYouUrl=form.getAttribute('data-thankyou')||'';
		form.addEventListener('submit',async function(e){
			if(form.elements['company_hp'] && form.elements['company_hp'].value){e.preventDefault();return;}
			if(!form.checkValidity()){return;}
			e.preventDefault();
			status.style.color='var(--muted)';
			status.textContent='Sending…';
			try{
				var fd=new FormData(form);
				var res=await fetch(form.action,{method:'POST',body:fd,headers:{'Accept':'application/json'}});
				if(res.ok){
					if(thankYouUrl){ window.location.href=thankYouUrl; return; }
					status.style.color='var(--green)';
					status.textContent='✓ '+okMsg;
					form.reset();
				}else{
					throw new Error('Network');
				}
			}catch(err){
				status.style.color='var(--teal)';
				status.innerHTML='Something went wrong. Please <a href="tel:+17817306971" style="color:inherit;text-decoration:underline">call (781) 730-6971</a> or email <a href="mailto:hello@aqmarketing.com" style="color:inherit;text-decoration:underline">hello@aqmarketing.com</a>.';
			}
		});

		// Admin-only "fill with test data" button (AutoForge -> Forms). The button
		// markup + AQ_CONTACT_TEST data only exist in the page at all when the
		// server already verified the visitor is a logged-in admin; nothing extra
		// to gate here.
		var fillBtn=document.getElementById('contactFormTestFill');
		if(fillBtn && window.AQ_CONTACT_TEST){
			fillBtn.addEventListener('click',function(){
				var d=window.AQ_CONTACT_TEST;
				var nameParts=(d.name||'').split(/\s+/);
				var set=function(name,val){ var el=form.elements[name]; if(el && val) el.value=val; };
				set('firstName', nameParts[0]||'');
				set('lastName', nameParts.slice(1).join(' ')||'');
				set('email', d.email);
				set('phone', d.phone);
				set('business', d.business);
				set('website', d.website);
				set('message', d.message);
				var svc=form.elements['service'];
				if(svc && svc.options.length>1){ svc.selectedIndex=1; }
				var consent=form.elements['consent'];
				if(consent){ consent.checked=true; }
			});
		}
	})();
</script>
