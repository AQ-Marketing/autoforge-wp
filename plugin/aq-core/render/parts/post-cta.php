<?php
/**
 * Shared blog-post call-to-action — the navy rounded box that closes every
 * article. All text is editable via AutoForge config (postCta / headerCta).
 */
$phone     = aq_site('phone');
$phone_tel = aq_site('phoneTel');
$license   = aq_site('license');
$founded   = aq_site('founded');
$lic_no    = is_array($license) ? ($license['number'] ?? '') : '';
$region    = aq_site('address.region') ?: '';
$lic_pfx   = aq_site('labels.licensePrefix') ?: 'License #';
$call_pfx  = aq_site('labels.callPrefix') ?: 'Call';

$pcta_heading = aq_site('postCta.heading') ?: (aq_site('headerCta.label') ?: 'Schedule Inspection');
$pcta_body    = aq_site('postCta.body');
$pcta_label   = aq_site('postCta.label') ?: (aq_site('headerCta.label') ?: 'Schedule Inspection');
$pcta_href    = aq_site('postCta.href') ?: (aq_site('headerCta.href') ?: '/schedule/');

if (!$pcta_body && $lic_no) {
	$pcta_body = 'Comprehensive digital reports. ' . trim(($region ? $region . ' ' : '') . $lic_pfx . $lic_no) . '. ' . $founded . '-present, owner-operated.';
}
?>
<section class="my-16">
	<div class="container-x">
		<div class="rounded-lg bg-brand-800 text-white p-8 md:p-12 flex flex-col md:flex-row items-start md:items-center gap-6 md:gap-10">
			<div class="flex-1">
				<h2 class="text-white !mt-0"><?php echo esc_html($pcta_heading); ?></h2>
				<?php if ($pcta_body) : ?>
				<p class="text-brand-50 mb-0"><?php echo esc_html($pcta_body); ?></p>
				<?php endif; ?>
			</div>
			<div class="flex flex-wrap gap-3">
				<a href="<?php echo esc_url($pcta_href); ?>" class="btn-primary"><?php echo esc_html($pcta_label); ?></a>
				<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="btn-outline-light"><?php echo esc_html($call_pfx); ?> <?php echo esc_html($phone); ?></a>
			</div>
		</div>
	</div>
</section>
