<?php
/** AQM Termly embed — renders a Termly-hosted legal policy (Privacy / Terms /
 *  Cookie). The `data_id` field accepts EITHER the full Termly embed snippet
 *  (paste it verbatim — a genuine third-party embed, output raw, admin-entered)
 *  OR a bare policy UUID from app.termly.io (we then build the standard embed div
 *  and load Termly's script, guarded so it loads once). Wrapped in the site .wrap
 *  so it sits in the normal content column. The renderer auto-injects
 *  data-aq-section into the first <section>. */
if (!defined('ABSPATH')) {
	exit;
}
$s   = $args['s'] ?? [];
$raw = trim((string) ($s['data_id'] ?? ''));
if ($raw === '') {
	return;
}
// A full pasted embed snippet contains HTML; a bare policy UUID does not.
$is_embed = strpos($raw, '<') !== false;
?>
<section>
	<div class="wrap" style="max-width:880px"<?php echo ka_field_attr('data_id'); ?>>
		<?php if ($is_embed) : ?>
		<?php echo $raw; // full third-party Termly embed, admin-entered — output verbatim (raw HTML, like the Legal embed; wp_kses would strip the <script> Termly needs) ?>
		<?php else : ?>
		<div name="termly-embed" data-id="<?php echo esc_attr($raw); ?>"></div>
		<noscript>Please enable JavaScript to view this policy, or contact us at hello@aqmarketing.com for a copy.</noscript>
		<?php endif; ?>
	</div>
</section>
<?php if (!$is_embed) : ?>
<script type="text/javascript">
(function (d, s, id) {
	var js, tjs = d.getElementsByTagName(s)[0];
	if (d.getElementById(id)) { return; }
	js = d.createElement(s); js.id = id;
	js.src = "https://app.termly.io/embed-policy.min.js";
	tjs.parentNode.insertBefore(js, tjs);
}(document, "script", "termly-jssdk"));
</script>
<?php endif; ?>
