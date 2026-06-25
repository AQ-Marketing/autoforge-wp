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
    </div>
    <div>
      <?php foreach ((array) ($s['items'] ?? []) as $i => $item) : ?>
      <details<?php echo $i === 0 ? ' open' : ''; ?>><summary><?php echo esc_html($item['q'] ?? ''); ?></summary><p><?php echo wp_kses_post($item['a'] ?? ''); ?></p></details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
