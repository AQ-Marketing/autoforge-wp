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
    <div class="features">
      <?php foreach ((array) ($s['items'] ?? []) as $item) : ?>
      <div class="feat"><i class="fa-solid <?php echo esc_attr($item['fa'] ?? ''); ?>"></i><h3><?php echo esc_html($item['title'] ?? ''); ?></h3><p><?php echo wp_kses_post($item['body'] ?? ''); ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
