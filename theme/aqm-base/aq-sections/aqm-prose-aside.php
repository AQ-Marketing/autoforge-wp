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
    <div class="prose-split">
      <div class="prose"><?php echo wp_kses_post($s['prose_html'] ?? ''); ?></div>
      <aside class="prose-side" aria-label="Supporting media">
        <?php foreach ((array) ($s['aside_items'] ?? []) as $item) : ?>
          <?php if (!empty($item['map_src'])) : ?>
        <div class="side-media">
          <iframe src="<?php echo esc_url($item['map_src']); ?>"<?php if (!empty($item['img_alt'])) : ?> title="<?php echo esc_attr($item['img_alt']); ?>"<?php endif; ?> loading="lazy"></iframe>
        </div>
          <?php elseif (!empty($item['img_src'])) : ?>
        <div class="side-media">
          <img src="<?php echo esc_url($item['img_src']); ?>" alt="<?php echo esc_attr($item['img_alt'] ?? ''); ?>" loading="lazy" decoding="async">
        </div>
          <?php elseif (!empty($item['note_title']) || !empty($item['note_body'])) : ?>
        <div class="side-note">
          <h4><?php echo esc_html($item['note_title'] ?? ''); ?></h4>
          <p><?php echo wp_kses_post($item['note_body'] ?? ''); ?></p>
        </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </aside>
    </div>
  </div>
</section>
