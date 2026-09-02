<?php
/**
 * Unit tests for AQ_Image_Optimizer's PURE helpers (no WordPress bootstrap):
 *   - target_width()     no-upscale, cap applies, no-cap
 *   - should_process()   enabled/disabled × mime types (gif/svg false)
 *   - webp_path()        every extension + case-insensitivity
 *   - clamp_quality()    bounds + default
 *   - sanitize_settings() bad values → defaults; enabled stays false when absent
 *   - swap_url()         basename swap on a URL
 * The upload hook + backfill CLI delegate their decisions to exactly these.
 */
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/lib/mini-test.php';
if (!class_exists('AQ_Image_Optimizer')) { require dirname(__DIR__) . '/plugin/aq-core/includes/class-image-optimizer.php'; }

/* ---- target_width ---- */
t('target_width(): never upscales; caps only when wider; cap<=0 means no cap', function () {
	eq(1960, AQ_Image_Optimizer::target_width(4000, 1960), 'wider → capped');
	eq(1600, AQ_Image_Optimizer::target_width(1600, 1960), 'narrower → unchanged (no upscale)');
	eq(1960, AQ_Image_Optimizer::target_width(1960, 1960), 'equal → unchanged');
	eq(800,  AQ_Image_Optimizer::target_width(800, 1960), 'small stays small');
	eq(4000, AQ_Image_Optimizer::target_width(4000, 0), 'cap 0 → no cap');
	eq(4000, AQ_Image_Optimizer::target_width(4000, -5), 'negative cap → no cap');
});

/* ---- should_process ---- */
t('should_process(): only enabled + handled raster mimes; gif/svg/others false', function () {
	foreach (['image/jpeg', 'image/png', 'image/webp', 'IMAGE/JPEG', ' image/png '] as $m) {
		ok(AQ_Image_Optimizer::should_process($m, true), "yes: $m");
	}
	foreach (['image/gif', 'image/svg+xml', 'image/avif', 'application/pdf', ''] as $m) {
		ok(!AQ_Image_Optimizer::should_process($m, true), "no: $m");
	}
	eq(false, AQ_Image_Optimizer::should_process('image/jpeg', false), 'disabled → never process');
});

/* ---- webp_path ---- */
t('webp_path(): swaps jpg/jpeg/png/webp (any case), leaves the directory intact', function () {
	eq('/up/loads/pic.webp', AQ_Image_Optimizer::webp_path('/up/loads/pic.jpg'));
	eq('/up/loads/pic.webp', AQ_Image_Optimizer::webp_path('/up/loads/pic.jpeg'));
	eq('/up/loads/pic.webp', AQ_Image_Optimizer::webp_path('/up/loads/pic.png'));
	eq('/up/loads/pic.webp', AQ_Image_Optimizer::webp_path('/up/loads/pic.webp'), 'already webp → unchanged path');
	eq('/up/loads/PIC.webp', AQ_Image_Optimizer::webp_path('/up/loads/PIC.JPG'), 'uppercase ext');
	eq('/a.b/c-1.webp', AQ_Image_Optimizer::webp_path('/a.b/c-1.PnG'), 'mixed case, dotted dir');
	eq('/up/loads/noext', AQ_Image_Optimizer::webp_path('/up/loads/noext'), 'no ext → unchanged');
});

/* ---- clamp_quality ---- */
t('clamp_quality(): 1..100 pass; 0/negative/>100 → default 82', function () {
	eq(82, AQ_Image_Optimizer::clamp_quality(82));
	eq(1,  AQ_Image_Optimizer::clamp_quality(1));
	eq(100, AQ_Image_Optimizer::clamp_quality(100));
	eq(60, AQ_Image_Optimizer::clamp_quality(60));
	eq(82, AQ_Image_Optimizer::clamp_quality(0), 'zero → default');
	eq(82, AQ_Image_Optimizer::clamp_quality(-10), 'negative → default');
	eq(82, AQ_Image_Optimizer::clamp_quality(150), 'over 100 → default');
});

/* ---- defaults ---- */
t('defaults(): OFF by default, 1960/webp-on/82/strip-on', function () {
	$d = AQ_Image_Optimizer::defaults();
	eq(false, $d['enabled'], 'feature is off until an admin opts in');
	eq(1960, $d['max_width']);
	eq(true, $d['webp']);
	eq(82, $d['quality']);
	eq(true, $d['strip_meta']);
});

/* ---- sanitize_settings ---- */
t('sanitize_settings(): bad values → defaults; enabled stays false when absent', function () {
	$s = AQ_Image_Optimizer::sanitize_settings(['max_width' => -5, 'quality' => 999]);
	eq(false, $s['enabled'], 'absent enabled → false');
	eq(1960, $s['max_width'], 'invalid width → default');
	eq(82, $s['quality'], 'invalid quality → default');
	eq(false, $s['webp'], 'absent checkbox → false');
	eq(false, $s['strip_meta'], 'absent checkbox → false');

	$on = AQ_Image_Optimizer::sanitize_settings(['enabled' => '1', 'max_width' => '2400', 'webp' => '1', 'quality' => '70', 'strip_meta' => '1']);
	eq(true, $on['enabled']);
	eq(2400, $on['max_width']);
	eq(true, $on['webp']);
	eq(70, $on['quality']);
	eq(true, $on['strip_meta']);

	$huge = AQ_Image_Optimizer::sanitize_settings(['max_width' => 999999]);
	eq(20000, $huge['max_width'], 'absurd width is capped');
});

/* ---- swap_url ---- */
t('swap_url(): replaces the final path segment with the new basename', function () {
	eq('https://x.test/wp-content/uploads/2026/09/pic.webp',
		AQ_Image_Optimizer::swap_url('https://x.test/wp-content/uploads/2026/09/pic.jpg', 'pic.webp'));
	eq('pic.webp', AQ_Image_Optimizer::swap_url('pic.jpg', 'pic.webp'), 'no slash → just the basename');
});

exit(aq_tests_done());
