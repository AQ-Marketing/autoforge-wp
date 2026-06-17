<?php
/** Rich section — a styled <section> wrapper around verbatim inner markup, for
 *  bespoke one-off bodies (pricing fee tables, the scheduler iframe, the contact
 *  form embed, the reviews widget, the thank-you steps grid). The body is a raw
 *  echo sink — code-mode-only / AI-blocked, like raw_html. Image-bearing
 *  sections use media_hero instead (a raw <img> here would bypass the library). */
$s = $args['s'] ?? [];
?>
<section<?php echo ka_field_attr('body'); ?> class="<?php echo esc_attr($s['section_class'] ?? 'bg-white py-12 md:py-16 lg:py-20'); ?>"><?php echo $s['body'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — raw section body sink ?></section>
