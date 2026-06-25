<?php
$s = $args['s'] ?? [];
?>
<section<?php if (!empty($s['anchor'])) : ?> id="<?php echo esc_attr($s['anchor']); ?>"<?php endif; ?> class="cta-band">
  <div class="wrap">
    <div>
      <?php if (!empty($s['eyebrow'])) : ?>
      <div class="eyebrow"><?php if (!empty($s['eyebrow_fa'])) : ?><i class="fa-solid <?php echo esc_attr($s['eyebrow_fa']); ?>"></i> <?php endif; ?><?php echo esc_html($s['eyebrow']); ?></div>
      <?php endif; ?>
      <?php if (!empty($s['heading'])) : ?>
      <h2><?php echo wp_kses_post($s['heading']); ?></h2>
      <?php endif; ?>
      <?php if (!empty($s['body'])) : ?>
      <p><?php echo wp_kses_post($s['body']); ?></p>
      <?php endif; ?>
      <?php if (!empty($s['ctas'])) : ?>
      <div class="actions">
        <?php foreach ((array) ($s['ctas'] ?? []) as $cta) :
          $btn = ($cta['style'] ?? '') === 'ghost' ? 'btn-ghost' : 'btn-primary';
        ?>
        <a class="btn <?php echo esc_attr($btn); ?> btn-lg" href="<?php echo esc_url($cta['href'] ?? ''); ?>"><?php if (!empty($cta['fa'])) : ?><i class="fa-solid <?php echo esc_attr($cta['fa']); ?>"></i> <?php endif; ?><?php echo esc_html($cta['label'] ?? ''); ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if (!empty($s['stats'])) : ?>
    <div class="cta-side">
      <?php foreach ((array) ($s['stats'] ?? []) as $stat) : ?>
      <div class="cta-stat"><b><?php if (!empty($stat['fa'])) : ?><i class="fa-solid <?php echo esc_attr($stat['fa']); ?>"></i><?php endif; ?><?php echo esc_html($stat['value'] ?? ''); ?></b><span><?php echo esc_html($stat['label'] ?? ''); ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
