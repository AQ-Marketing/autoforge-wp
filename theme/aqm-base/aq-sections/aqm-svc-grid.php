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
    <div class="services">
      <?php foreach ((array) ($s['cards'] ?? []) as $card) : ?>
      <a class="svc" href="<?php echo esc_url($card['href'] ?? ''); ?>"><span class="tag"><?php echo esc_html($card['tag'] ?? ''); ?></span><h3><?php echo esc_html($card['title'] ?? ''); ?></h3><p><?php echo wp_kses_post($card['body'] ?? ''); ?></p><?php
        if (!empty($card['features'])) {
            $feats = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $card['features'])), 'strlen');
            if ($feats) {
                echo '<ul>';
                foreach ($feats as $feat) {
                    echo '<li>' . esc_html($feat) . '</li>';
                }
                echo '</ul>';
            }
        }
      ?><span class="more"><?php echo esc_html($card['more_label'] ?? ''); ?> →</span></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
