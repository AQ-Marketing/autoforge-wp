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
    <div class="steps">
      <?php foreach ((array) ($s['steps'] ?? []) as $step) : ?>
      <div class="step"><div class="num"><?php echo esc_html($step['step_label'] ?? ''); ?></div><h3><?php echo esc_html($step['title'] ?? ''); ?></h3><p><?php echo wp_kses_post($step['body'] ?? ''); ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
