<?php
/**
 * Sticky call bar — transliterated from StickyCallBar.astro.
 * Styles live in global.css (folded in from the Astro scoped block);
 * behavior lives in assets/js/site.js.
 */

$phone     = aq_site('phone');
$phone_tel = aq_site('phoneTel');
?>
<div
	id="sticky-call-bar"
	role="complementary"
	aria-label="Request a callback"
	class="sticky-bar"
>
	<div class="sticky-bar__inner">
		<div class="sticky-bar__left">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="text-accent-500">
				<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 10.8a19.79 19.79 0 01-3.07-8.68A2 2 0 012 0h3a2 2 0 012 1.72c.127.96.36 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.34 1.85.573 2.81.7A2 2 0 0122 14.92v2z"/>
			</svg>
			<span class="sticky-bar__label">Questions? Call us:</span>
			<a href="tel:<?php echo esc_attr($phone_tel); ?>" class="sticky-bar__phone"><?php echo esc_html($phone); ?></a>
		</div>
		<div class="sticky-bar__right">
			<a href="/schedule/" class="sticky-bar__cta">Request a Call Back</a>
			<button
				id="sticky-call-bar-dismiss"
				class="sticky-bar__dismiss"
				aria-label="Dismiss"
				type="button"
			>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
					<line x1="18" y1="6" x2="6" y2="18"/>
					<line x1="6" y1="6" x2="18" y2="18"/>
				</svg>
			</button>
		</div>
	</div>
</div>
