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
    <div class="<?php echo esc_attr($s['container'] ?? 'grid-3'); ?>">
      <?php foreach ((array) ($s['cards'] ?? []) as $card) :
        $href = (string) ($card['href'] ?? '');
        $tag  = $href !== '' ? 'a' : 'div';
        $attr = $href !== '' ? ' href="' . esc_url($href) . '"' : '';
      ?>
      <<?php echo $tag; ?> class="card"<?php echo $attr; ?>><div class="ico"><i class="fa-solid <?php echo esc_attr($card['fa'] ?? ''); ?>"></i></div><h3><?php echo esc_html($card['title'] ?? ''); ?></h3><p><?php echo wp_kses_post($card['body'] ?? ''); ?></p></<?php echo $tag; ?>>
      <?php endforeach; ?>
    </div>
  </div>
</section>
