<?php
/** AQM Termly embed — renders a Termly-hosted legal policy (Privacy / Terms /
 *  Cookie) by its policy UUID, wrapped in the site .wrap so it sits in the normal
 *  content column. The policy text itself is maintained in Termly (always current,
 *  legally managed) and loaded by Termly's embed script. `data_id` is the policy
 *  UUID from app.termly.io. The loader script is guarded by its own id check, so
 *  multiple embeds on one page load it only once. The renderer auto-injects
 *  data-aq-section into the first <section>. */
if (!defined('ABSPATH')) {
	exit;
}
$s  = $args['s'] ?? [];
$id = (string) ($s['data_id'] ?? '');
if ($id === '') {
	return;
}
?>
<section>
	<div class="wrap" style="max-width:880px"<?php echo ka_field_attr('data_id'); ?>>
		<div name="termly-embed" data-id="<?php echo esc_attr($id); ?>"></div>
		<noscript>Please enable JavaScript to view this policy, or contact us at <a href="mailto:hello@aqmarketing.com">hello@aqmarketing.com</a> for a copy.</noscript>
	</div>
</section>
<script type="text/javascript">
(function (d, s, id) {
	var js, tjs = d.getElementsByTagName(s)[0];
	if (d.getElementById(id)) { return; }
	js = d.createElement(s); js.id = id;
	js.src = "https://app.termly.io/embed-policy.min.js";
	tjs.parentNode.insertBefore(js, tjs);
}(document, "script", "termly-jssdk"));
</script>
