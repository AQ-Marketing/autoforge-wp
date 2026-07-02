<?php
/** Policy Embed (Termly) — renders a Termly hosted legal document (privacy
 *  policy, cookie policy, terms of service, disclaimer, etc.) from just the
 *  policy's data-id. Paste the ID from your Termly dashboard into the single
 *  "Termly policy ID" field; nothing else is needed. Termly hosts and updates
 *  the document, so the page stays current automatically.
 *
 *  The Termly loader is self-guarding (it injects the SDK once per page via the
 *  'termly-jssdk' id check), so multiple policy sections on one page are safe.
 *  Uses an inline-styled centred column so it renders consistently on any site,
 *  Tailwind or bespoke. */
$s  = $args['s'] ?? [];
$id = trim((string) ($s['policy_id'] ?? ''));

if ($id === '') {
	// Empty state — only shown inside the editor canvas, never on the live site.
	if (function_exists('ka_is_editing') && ka_is_editing()) {
		echo '<section style="padding:48px 20px;"><div style="max-width:880px;margin:0 auto;padding:40px;border:2px dashed #c9cfd6;border-radius:12px;text-align:center;color:#5b6471;font-family:system-ui,sans-serif;">Add your <strong>Termly policy ID</strong> to display the document.</div></section>';
	}
	return;
}
?>
<section class="aq-policy-embed" style="padding:48px 20px;">
	<div class="aq-policy-embed__inner" style="max-width:880px;margin:0 auto;">
		<div name="termly-embed" data-id="<?php echo esc_attr($id); ?>"<?php echo ka_field_attr('policy_id'); ?>></div>
		<script type="text/javascript">(function(d, s, id) {
		var js, tjs = d.getElementsByTagName(s)[0];
		if (d.getElementById(id)) return;
		js = d.createElement(s); js.id = id;
		js.src = "https://app.termly.io/embed-policy.min.js";
		tjs.parentNode.insertBefore(js, tjs);
		}(document, 'script', 'termly-jssdk'));</script>
	</div>
</section>
