<?php
$s = $args['s'] ?? [];
?>
<header class="page-hero">
  <div class="wrap">
    <?php if (!empty($s['badge'])) : ?>
    <span class="badge"><?php if (!empty($s['badge_fa'])) : ?><i class="fa-solid <?php echo esc_attr($s['badge_fa']); ?>"></i> <?php endif; ?><?php echo esc_html($s['badge']); ?></span>
    <?php endif; ?>
    <?php if (!empty($s['heading'])) : ?>
    <h1><?php echo wp_kses_post($s['heading']); ?></h1>
    <?php endif; ?>
    <?php if (!empty($s['lede'])) : ?>
    <p class="lede"><?php echo wp_kses_post($s['lede']); ?></p>
    <?php endif; ?>
    <?php if (!empty($s['ctas'])) : ?>
    <div class="cta">
      <?php foreach ((array) ($s['ctas'] ?? []) as $cta) :
        $style = $cta['style'] ?? '';
        if ($style === 'dark') {
            $btn = 'btn-dark-solid';
        } elseif ($style === 'outline') {
            $btn = 'btn-outline-dark';
        } elseif ($style === 'ghost') {
            $btn = 'btn-ghost';
        } else {
            $btn = 'btn-primary';
        }
      ?>
      <a class="btn <?php echo esc_attr($btn); ?> btn-lg" href="<?php echo esc_url($cta['href'] ?? ''); ?>"><?php if (!empty($cta['fa'])) : ?><i class="fa-solid <?php echo esc_attr($cta['fa']); ?>"></i> <?php endif; ?><?php echo esc_html($cta['label'] ?? ''); ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</header>
<?php if (!empty($s['crumbs'])) : ?>
<nav class="crumbs"><div class="wrap"><ol><?php foreach ((array) ($s['crumbs'] ?? []) as $crumb) : ?><li><?php if (!empty($crumb['url'])) : ?><a href="<?php echo esc_url($crumb['url']); ?>"><?php echo esc_html($crumb['label'] ?? ''); ?></a><?php else : ?><?php echo esc_html($crumb['label'] ?? ''); ?><?php endif; ?></li><?php endforeach; ?></ol></div></nav>
<?php endif; ?>
