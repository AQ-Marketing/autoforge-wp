<?php
/**
 * local_proof — full-bleed photographic "local proof" band: a Greater-Boston
 * editorial photo behind a dark scrim with an overlaid headline + CTA. Additive
 * sales/marketing hook (sales-team feedback 2026-06-25). Background image is a
 * docroot asset (/assets/generated/<basename>), matching the AQM home's existing
 * CSS-bg pattern (hero-boston, cta-street); the scrim keeps white text legible.
 */
$s   = $args['s'] ?? [];
$img = (string) ($s['bg'] ?? '');
$bg  = $img !== '' ? '/assets/generated/' . ltrim($img, '/') : '';
$scrim = 'linear-gradient(90deg,rgba(8,10,14,.95) 0%,rgba(8,10,14,.86) 34%,rgba(8,10,14,.55) 66%,rgba(8,10,14,.62) 100%)';
$style = $bg !== '' ? ' style="background-image:' . $scrim . ",url('" . esc_url($bg) . "')\"" : '';
?>
<section class="hm-proofband"<?php echo $style; ?>>
  <div class="wrap">
    <div class="hm-proofband-inner">
      <?php if (!empty($s['kicker'])) : ?>
      <span class="hm-proofband-kicker"><?php echo esc_html($s['kicker']); ?></span>
      <?php endif; ?>
      <?php if (!empty($s['heading'])) : ?>
      <h2 class="hm-proofband-h"><?php echo wp_kses_post($s['heading']); ?><?php if (!empty($s['heading_accent'])) : ?> <em><?php echo wp_kses_post($s['heading_accent']); ?></em><?php endif; ?></h2>
      <?php endif; ?>
      <?php if (!empty($s['subtext'])) : ?>
      <p class="hm-proofband-sub"><?php echo wp_kses_post($s['subtext']); ?></p>
      <?php endif; ?>
      <?php if (!empty($s['chip'])) : ?>
      <span class="hm-proofband-chip"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?php echo esc_html($s['chip']); ?></span>
      <?php endif; ?>
      <?php if (!empty($s['cta_label'])) : ?>
      <div class="hm-proofband-cta">
        <a class="btn btn-primary btn-lg" href="<?php echo esc_url($s['cta_href'] ?? '/contact/'); ?>"><?php echo esc_html($s['cta_label']); ?></a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
