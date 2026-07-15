<?php
/** AQM Contact — lead-capture form + info cards + map embed (bespoke redesign 2026-07-15).
 *  The form POSTs (fetch) to the aqm/v1/contact REST route (AQ_Lead_Capture): honeypot +
 *  required-field validation + wp_mail + optional GHL push. Editable via the builder:
 *  heading/sub copy, consent text, submit + success copy, the info cards, and the Google
 *  Maps query. The "What can we help with?" chips mirror the site's Services + Solutions
 *  offerings (defined below) and are multi-select: their labels are joined into the single
 *  hidden `service` field the engine already understands, so no handler change is needed.
 *  The admin-only "Fill with test data" button is injected globally by AQ_Lead_Capture
 *  (footer) because this form's action contains aqm/v1/contact — no per-template markup.
 *  Self-contained scoped CSS/JS travels with the block. */
if (!defined('ABSPATH')) {
	exit;
}
$s         = $args['s'] ?? [];
$heading   = (string) ($s['heading'] ?? 'Request a free audit');
$sub       = (string) ($s['sub'] ?? "Tell us what you need help with. We'll reply within 2 business days — from a real person, not a bot.");
$info      = array_values(array_filter((array) ($s['info'] ?? []), fn($c) => is_array($c) && (($c['title'] ?? '') !== '' || ($c['body'] ?? '') !== '')));
$consent   = (string) ($s['consent'] ?? 'I agree to receive a one-time audit response at the email and phone I provided. AQ Marketing will never sell or share my info.');
$submit    = (string) ($s['submit_label'] ?? 'Send my free audit request');
$success   = (string) ($s['success_msg'] ?? "Got it — we'll be in touch within 2 business days.");
$map_q     = (string) ($s['map_query'] ?? '400 Tradecenter Dr, Woburn, MA 01801');
$map_label = (string) ($s['map_label'] ?? 'Map showing the AQ Marketing office in Woburn, MA');
$rest      = esc_url_raw(rest_url('aqm/v1/contact'));
$map_src   = 'https://www.google.com/maps?q=' . rawurlencode($map_q) . '&output=embed';

// Offerings that appear as selectable chips, mirroring the site's Services + Solutions
// menus. Editable here; the visitor may pick one or several. Each maps to a clean label
// that is joined into the hidden `service` field on submit.
$offerings = [
	'Services'  => ['Local SEO', 'Web Design', 'AI Websites', 'Google Ads', 'Google Business Profile', 'Social Media', 'Reputation Management', 'Branding'],
	'Solutions' => ['AI Chatbot', 'AI Receptionist', 'Call Tracking', 'CRM & Pipeline', 'Email & SMS', 'Online Booking', 'Reporting & Analytics', 'Review Automation'],
];
?>
<section>
	<div class="wrap">
		<div class="contact-grid">
			<form class="contact-form" id="contactForm" action="<?php echo esc_url($rest); ?>" method="POST" data-success="<?php echo esc_attr($success); ?>" novalidate>
				<?php if (current_user_can('manage_options')) : ?>
				<button type="button" class="cf-testfill" data-aq-testfill="1" id="contactFormTestFill">&#9889; Fill with test data (admin only)</button>
				<?php endif; ?>
				<div class="cf-head">
					<h2<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($heading); ?></h2>
					<?php if ($sub !== '') : ?><p class="cf-sub"<?php echo ka_field_attr('sub'); ?>><?php echo esc_html($sub); ?></p><?php endif; ?>
				</div>

				<div class="cf-trust" aria-hidden="true">
					<span class="cf-stars">★★★★★</span>
					<span><strong>5.0</strong> · 34 Google reviews</span>
					<span class="cf-dot">•</span>
					<span>Serving Massachusetts since <strong>2003</strong></span>
					<span class="cf-dot">•</span>
					<span>One reply, from a human</span>
				</div>

				<div class="row2">
					<div class="field">
						<label for="firstName">First name <span class="req">*</span></label>
						<input type="text" id="firstName" name="firstName" autocomplete="given-name" required>
					</div>
					<div class="field">
						<label for="lastName">Last name <span class="req">*</span></label>
						<input type="text" id="lastName" name="lastName" autocomplete="family-name" required>
					</div>
				</div>

				<div class="row2">
					<div class="field">
						<label for="email">Work email <span class="req">*</span></label>
						<input type="email" id="email" name="email" autocomplete="email" required>
					</div>
					<div class="field">
						<label for="phone">Phone</label>
						<input type="tel" id="phone" name="phone" autocomplete="tel" placeholder="(555) 123-4567">
					</div>
				</div>

				<div class="row2">
					<div class="field">
						<label for="business">Business name <span class="req">*</span></label>
						<input type="text" id="business" name="business" autocomplete="organization" required>
					</div>
					<div class="field">
						<label for="website">Current website</label>
						<input type="url" id="website" name="website" autocomplete="url" placeholder="https://">
					</div>
				</div>

				<div class="field cf-interests">
					<label id="interestsLabel">What can we help with? <span class="req">*</span> <span class="cf-hint">Pick all that apply</span></label>
					<?php foreach ($offerings as $group => $items) : ?>
						<div class="cf-group-label"><?php echo esc_html($group); ?></div>
						<div class="chips" role="group" aria-labelledby="interestsLabel">
							<?php foreach ($items as $label) : ?>
								<label class="chip"><input type="checkbox" data-svc value="<?php echo esc_attr($label); ?>"><span><?php echo esc_html($label); ?></span></label>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
					<div class="chips chips-wide">
						<label class="chip chip-alt"><input type="checkbox" data-svc value="Not sure — help me choose"><span>Not sure — help me choose</span></label>
					</div>
					<input type="hidden" id="service" name="service" value="">
				</div>

				<div class="field">
					<label for="message">Anything else we should know?</label>
					<textarea id="message" name="message" placeholder="Biggest challenge right now? Competitors you want to beat? Timeline?"></textarea>
				</div>

				<!-- honeypot — leave blank; bots fill it -->
				<div class="hp" aria-hidden="true"><label>Leave this empty<input type="text" name="company_hp" tabindex="-1" autocomplete="off"></label></div>

				<div class="consent">
					<input type="checkbox" id="consent" name="consent" required>
					<label for="consent"<?php echo ka_field_attr('consent'); ?>><?php echo esc_html($consent); ?></label>
				</div>

				<button type="submit" class="btn btn-primary btn-lg cf-submit"><i class="fa-solid fa-paper-plane"></i> <?php echo esc_html($submit); ?></button>
				<p id="formStatus" role="status" aria-live="polite"></p>
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
	.contact-form{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:36px 36px 34px;box-shadow:0 1px 3px rgba(13,16,20,.05)}
	.contact-form .cf-testfill{display:block;width:100%;margin:0 0 18px;padding:10px 14px;background:#0d1014;color:#fff;border:1px dashed #4b5563;border-radius:8px;font:600 13px/1.2 inherit;cursor:pointer}
	.contact-form .cf-testfill:hover{background:#1b2229}
	.contact-form .cf-head h2{font-size:26px;line-height:1.15;margin:0 0 8px;letter-spacing:-.02em}
	.contact-form .cf-sub{margin:0 0 20px;font-size:14.5px;color:var(--muted);line-height:1.5}
	.cf-trust{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin:0 0 26px;padding:11px 16px;background:#fbf8f2;border:1px solid #f1e7d4;border-radius:10px;font-size:12.5px;color:var(--ink)}
	.cf-trust .cf-stars{color:#f5b301;letter-spacing:1px;font-size:13px}
	.cf-trust .cf-dot{color:#d8c9ad}
	.contact-form .field{margin-bottom:18px}
	.contact-form label{display:block;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:6px}
	.contact-form label .req{color:var(--teal)}
	.contact-form .cf-hint{font-weight:500;color:var(--muted);font-size:12px}
	.contact-form input[type=text],.contact-form input[type=email],.contact-form input[type=tel],.contact-form input[type=url],.contact-form textarea{
		width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;
		font-family:inherit;font-size:15px;color:var(--ink);background:#fff;transition:border-color .15s,box-shadow .15s
	}
	.contact-form input:focus,.contact-form textarea:focus{
		outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(200,16,46,.12)
	}
	.contact-form textarea{resize:vertical;min-height:110px}
	.contact-form .row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
	/* interest chips */
	.cf-interests .cf-group-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700;margin:12px 0 8px}
	.cf-interests .cf-group-label:first-of-type{margin-top:2px}
	.chips{display:flex;flex-wrap:wrap;gap:8px}
	.chips-wide{margin-top:12px}
	.chip{position:relative;display:inline-flex}
	.chip input{position:absolute;inset:0;width:100%;height:100%;margin:0;opacity:0;cursor:pointer}
	.chip span{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border:1px solid var(--line);border-radius:999px;
		font-size:13.5px;font-weight:600;color:var(--ink);background:#fff;transition:background .15s,border-color .15s,color .15s;user-select:none}
	.chip span::before{content:"+";font-weight:700;font-size:14px;line-height:1;opacity:.5;transition:transform .15s}
	.chip:hover span{border-color:#c9a9ae}
	.chip input:focus-visible+span{box-shadow:0 0 0 3px rgba(200,16,46,.18)}
	.chip input:checked+span{background:var(--teal);border-color:var(--teal);color:#fff}
	.chip input:checked+span::before{content:"✓";opacity:1;transform:none}
	.chip-alt span{border-style:dashed}
	/* consent + submit */
	.contact-form .consent{font-size:13px;color:var(--muted);line-height:1.5;display:flex;align-items:flex-start;gap:10px;margin:6px 0 22px}
	.contact-form .consent input{width:auto;margin-top:3px;flex:0 0 auto}
	.contact-form .consent label{font-weight:400;margin:0}
	.contact-form .cf-submit{width:100%;padding:15px 20px;font-size:15px;justify-content:center}
	.contact-form #formStatus{margin-top:14px;font-size:13px;min-height:1em}
	.contact-form #formStatus.is-ok{color:var(--ink);font-weight:600}
	.contact-form #formStatus.is-ok::before{content:"✓ ";color:var(--teal);font-weight:800}
	.contact-form #formStatus.is-err{color:var(--teal)}
	.contact-form .hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
	/* sidebar */
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
		var hidden=form.querySelector('#service');
		var chips=Array.prototype.slice.call(form.querySelectorAll('input[data-svc]'));

		function syncService(){
			var picked=chips.filter(function(c){return c.checked;}).map(function(c){return c.value;});
			if(hidden) hidden.value=picked.join(', ');
			return picked.length;
		}
		chips.forEach(function(c){c.addEventListener('change',function(){syncService();if(syncService()>0){status.textContent='';status.className='';}});});

		form.addEventListener('submit',async function(e){
			if(form.elements['company_hp'] && form.elements['company_hp'].value){e.preventDefault();return;}
			// native required fields first
			if(!form.checkValidity()){return;}
			// at least one offering chosen
			if(syncService()<1){
				e.preventDefault();
				status.className='is-err';
				status.textContent='Please pick at least one thing we can help with.';
				var box=form.querySelector('.cf-interests');
				if(box) box.scrollIntoView({behavior:'smooth',block:'center'});
				return;
			}
			e.preventDefault();
			status.className='';status.style.color='';
			status.textContent='Sending…';
			try{
				var fd=new FormData(form);
				var res=await fetch(form.action,{method:'POST',body:fd,headers:{'Accept':'application/json'}});
				if(res.ok){
					status.className='is-ok';
					status.textContent=okMsg;
					form.reset();
					chips.forEach(function(c){c.checked=false;});
					syncService();
				}else{
					throw new Error('Network');
				}
			}catch(err){
				status.className='is-err';
				status.innerHTML='Something went wrong. Please <a href="tel:<?php echo esc_attr((string)(aq_site('phoneTel') ?: '+17817306971')); ?>" style="color:inherit;text-decoration:underline">call <?php echo esc_html((string)(aq_site('phone') ?: '(781) 730-6971')); ?></a> or email us at <?php echo esc_html((string)(aq_site('email') ?: 'hello@aqmarketing.com')); ?>.';
			}
		});

		// Admin-only "Fill with test data" button — the button is rendered server-side
		// only when current_user_can('manage_options'), so this listener no-ops for
		// everyone else. Fills every field (incl. the first offering chip + consent)
		// so an admin can submit and confirm delivery in one click.
		var tf=document.getElementById('contactFormTestFill');
		if(tf){
			tf.addEventListener('click',function(){
				function set(n,v){var el=form.elements[n];if(el){el.value=v;el.dispatchEvent(new Event('input',{bubbles:true}));}}
				set('firstName','Test');set('lastName','Tester');set('email','test@example.com');set('phone','(781) 555-0123');
				set('business','Test Company LLC');set('website','https://example.com');
				set('message','TEST submission — please ignore.');
				if(chips[0]){chips[0].checked=true;chips[0].dispatchEvent(new Event('change',{bubbles:true}));}
				if(form.elements['consent'])form.elements['consent'].checked=true;
				syncService();
				status.className='';status.textContent='Test data filled — review, then submit.';
			});
		}
	})();
</script>
