<?php
$s = $args['s'] ?? [];
?>
<section>
  <div class="wrap">
    <div class="sec-head">
      <?php if (!empty($s['kicker'])) : ?>
      <span class="sec-num"><?php echo esc_html($s['kicker']); ?></span>
      <?php endif; ?>
      <?php if (!empty($s['heading'])) : ?>
      <h2><?php echo wp_kses_post($s['heading']); ?></h2>
      <?php endif; ?>
      <?php if (!empty($s['intro'])) : ?>
      <p><?php echo wp_kses_post($s['intro']); ?></p>
      <?php endif; ?>
    </div>
    <div class="stats">
      <?php foreach ((array) ($s['stats'] ?? []) as $stat) : ?>
      <div class="stat"><b><?php echo esc_html($stat['value'] ?? ''); ?></b><span><?php echo esc_html($stat['label'] ?? ''); ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
