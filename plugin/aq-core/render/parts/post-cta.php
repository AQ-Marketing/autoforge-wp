<?php
/**
 * Shared blog-post call-to-action — the navy rounded box that closes every
 * article ("Ready to schedule your inspection?"). Transliterated from the
 * Astro BaseLayout post CTA; phone + license pulled from config/site.php.
 */
$phone     = aq_site('phone');
$phone_tel = aq_site('phoneTel');
$license   = aq_site('license');
$founded   = aq_site('founded');
$lic_no    = is_array($license) ? ($license['number'] ?? '') : '';
?>
<section class="my-16">
	<div class="container-x">
		<div class="rounded-lg bg-brand-800 text-white p-8 md:p-12 flex flex-col md:flex-row items-start md:items-center gap-6 md:gap-10">
			<div class="flex-1">
				<h2 class="text-white !mt-0">Ready to schedule your inspection?</h2>
				<p class="text-brand-50 mb-0">Comprehensive digital reports. MA License #<?php echo esc_html($lic_no); ?>. <?php echo esc_html($founded); ?>-present, owner-operated.</p>
			</div>
			<div class="flex flex-wrap gap-3">
				<a href="/schedule/" class="btn-primary">Schedule Online</a>
				<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="btn-outline-light">Call <?php echo esc_html($phone); ?></a>
			</div>
		</div>
	</div>
</section>
