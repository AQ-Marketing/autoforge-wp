<?php
/** Shared .sec-head (kicker + H2 + intro) for AQM interior content blocks.
 *  require'd inside a section template's scope; reads $s. Not a section type
 *  itself (leading underscore + never registered), so it is never routed. */
if (!defined('ABSPATH')) {
	exit;
}
$_kicker  = (string) ($s['kicker'] ?? '');
$_heading = (string) ($s['heading'] ?? '');
$_intro   = (string) ($s['intro'] ?? '');
if ($_kicker !== '' || $_heading !== '' || $_intro !== '') : ?>
<div class="sec-head">
	<?php if ($_kicker !== '') : ?><span class="sec-num"<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($_kicker); ?></span><?php endif; ?>
	<?php if ($_heading !== '') : ?><h2<?php echo ka_field_attr('heading'); ?>><?php echo wp_kses_post($_heading); ?></h2><?php endif; ?>
	<?php if ($_intro !== '') : ?><p<?php echo ka_field_attr('intro'); ?>><?php echo esc_html($_intro); ?></p><?php endif; ?>
</div>
<?php endif; ?>
