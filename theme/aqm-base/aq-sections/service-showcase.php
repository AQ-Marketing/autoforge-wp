<?php
/** Service Showcase — two animated mocks plus the service-card grid.
 *  SERP mock (home.js data-mock="serp") climbs "you" to #1; it needs exactly 4
 *  rows in DOM order: you, comp, comp, dimmed-below (divider auto-emits above the
 *  dimmed row). Chat mock (data-mock="chat") plays the conversation; the typing
 *  indicator is injected after the first message. Phone/SERP/star/caret glyphs
 *  are structural; every readable label is editable. */
$s     = $args['s'] ?? [];
$num   = (string) ($s['num'] ?? '');
$rows  = array_values(array_filter((array) ($s['serp_rows'] ?? []), fn($r) => is_array($r) && ($r['name'] ?? '') !== ''));
$msgs  = array_values(array_filter((array) ($s['chat_lines'] ?? []), fn($m) => is_array($m) && ($m['text'] ?? '') !== ''));
$cards = array_values(array_filter((array) ($s['svc_cards'] ?? []), fn($c) => is_array($c) && ($c['title'] ?? '') !== ''));
?>
<section class="hm-sec hm-soft">
	<?php if ($num !== '') : ?><span class="hm-ghost" aria-hidden="true"><?php echo esc_html($num); ?></span><?php endif; ?>
	<div class="wrap">
		<div class="hm-head hm-head-split">
			<div>
				<span class="hm-kicker"><?php if ($num !== '') : ?><i><?php echo esc_html($num); ?></i><?php endif; ?><span<?php echo ka_field_attr('kicker'); ?>><?php echo esc_html($s['kicker'] ?? ''); ?></span></span>
				<h2 class="hm-title" data-split><span<?php echo ka_field_attr('heading'); ?>><?php echo esc_html($s['heading'] ?? ''); ?></span><?php if (($s['heading_accent'] ?? '') !== '') : ?> <em<?php echo ka_field_attr('heading_accent'); ?>><?php echo esc_html($s['heading_accent']); ?></em><?php endif; ?></h2>
			</div>
			<div class="hm-head-aside" data-rv>
				<?php if (($s['aside_text'] ?? '') !== '') : ?><p<?php echo ka_field_attr('aside_text'); ?>><?php echo esc_html($s['aside_text']); ?></p><?php endif; ?>
				<?php if (($s['aside_link_href'] ?? '') !== '') : ?><a class="hm-arrow-link" href="<?php echo esc_url($s['aside_link_href']); ?>"<?php echo ka_field_attr('aside_link_text'); ?>><?php echo esc_html($s['aside_link_text'] ?? ''); ?> <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
			</div>
		</div>
		<div class="hm-duo">
			<figure class="hm-mock" data-mock="serp">
				<div class="hm-serp" aria-hidden="true">
					<div class="hm-serp-bar"><span class="hm-serp-g">G</span><span class="hm-serp-q"<?php echo ka_field_attr('serp_query'); ?>><?php echo esc_html($s['serp_query'] ?? ''); ?><span class="hm-caret"></span></span><i class="fa-solid fa-magnifying-glass"></i></div>
					<div class="hm-serp-map"><span class="hm-map-pin"><i class="fa-solid fa-location-dot"></i><span class="hm-pin-ring"></span></span><span class="hm-map-tag"<?php echo ka_field_attr('serp_map_tag'); ?>><?php echo esc_html($s['serp_map_tag'] ?? ''); ?></span></div>
					<ol class="hm-serp-list">
						<?php foreach ($rows as $i => $r) :
							$you = !empty($r['is_you']); $dim = !empty($r['dim']); ?>
						<?php if ($dim) : ?><li class="hm-serp-divider" aria-hidden="true"<?php echo ka_field_attr('serp_divider'); ?>><?php echo esc_html($s['serp_divider'] ?? ''); ?></li><?php endif; ?>
						<li class="hm-serp-item<?php echo $you ? ' hm-you' : ''; ?><?php echo $dim ? ' hm-dimrow' : ''; ?>"<?php echo ka_field_attr('serp_rows', $i); ?>>
							<div class="hm-serp-name"><b><?php echo esc_html($r['name']); ?></b><?php if ($you) : ?><span class="hm-you-tag">You</span><?php endif; ?></div>
							<div class="hm-serp-meta"><span class="hm-serp-stars"><?php echo $you ? '★★★★★' : '★★★★<i>★</i>'; ?></span><?php echo esc_html($r['rating'] ?? ''); ?></div>
							<?php if (($r['badge'] ?? '') !== '') : ?><span class="hm-serp-badge"><?php echo esc_html($r['badge']); ?></span><?php endif; ?>
						</li>
						<?php endforeach; ?>
					</ol>
				</div>
				<figcaption>
					<b<?php echo ka_field_attr('serp_cap_title'); ?>><?php echo esc_html($s['serp_cap_title'] ?? ''); ?></b>
					<span<?php echo ka_field_attr('serp_cap_text'); ?>><?php echo esc_html($s['serp_cap_text'] ?? ''); ?></span>
					<?php if (($s['serp_cap_link_href'] ?? '') !== '') : ?><a class="hm-arrow-link" href="<?php echo esc_url($s['serp_cap_link_href']); ?>"<?php echo ka_field_attr('serp_cap_link_text'); ?>><?php echo esc_html($s['serp_cap_link_text'] ?? ''); ?> <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
				</figcaption>
			</figure>
			<figure class="hm-mock" data-mock="chat">
				<div class="hm-phone" aria-hidden="true">
					<div class="hm-chat-head"><span class="hm-chat-ava"<?php echo ka_field_attr('chat_avatar'); ?>><?php echo esc_html($s['chat_avatar'] ?? ''); ?></span><div class="hm-chat-id"><b<?php echo ka_field_attr('chat_title'); ?>><?php echo esc_html($s['chat_title'] ?? ''); ?></b><span><i class="hm-dot"></i><?php echo esc_html($s['chat_status'] ?? ''); ?></span></div><i class="fa-solid fa-phone-volume"></i></div>
					<div class="hm-chat-body">
						<span class="hm-chat-time"<?php echo ka_field_attr('chat_timestamp'); ?>><?php echo esc_html($s['chat_timestamp'] ?? ''); ?></span>
						<?php foreach ($msgs as $i => $m) :
							$who = ($m['who'] ?? 'a') === 'u' ? 'hm-bub-u' : 'hm-bub-a'; ?>
						<div class="hm-bub <?php echo $who; ?>"<?php echo ka_field_attr('chat_lines', $i); ?>><?php echo esc_html($m['text']); ?></div>
						<?php if ($i === 0) : ?><div class="hm-typing"><span></span><span></span><span></span></div><?php endif; ?>
						<?php endforeach; ?>
						<div class="hm-chat-card"><i class="fa-solid fa-calendar-check"></i><div><b<?php echo ka_field_attr('chat_card_title'); ?>><?php echo esc_html($s['chat_card_title'] ?? ''); ?></b><span<?php echo ka_field_attr('chat_card_sub'); ?>><?php echo esc_html($s['chat_card_sub'] ?? ''); ?></span></div></div>
					</div>
				</div>
				<figcaption>
					<b<?php echo ka_field_attr('chat_cap_title'); ?>><?php echo esc_html($s['chat_cap_title'] ?? ''); ?></b>
					<span<?php echo ka_field_attr('chat_cap_text'); ?>><?php echo esc_html($s['chat_cap_text'] ?? ''); ?></span>
					<?php if (($s['chat_cap_link_href'] ?? '') !== '') : ?><a class="hm-arrow-link" href="<?php echo esc_url($s['chat_cap_link_href']); ?>"<?php echo ka_field_attr('chat_cap_link_text'); ?>><?php echo esc_html($s['chat_cap_link_text'] ?? ''); ?> <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
				</figcaption>
			</figure>
		</div>
		<?php if ($cards) : ?>
		<div class="hm-svcgrid">
			<?php foreach ($cards as $i => $c) :
				$cn    = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
				$feats = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($c['features'] ?? ''))), fn($f) => $f !== '')); ?>
			<article class="hm-svc" data-rv<?php echo ka_field_attr('svc_cards', $i); ?>><span class="hm-svc-i" aria-hidden="true"><?php echo esc_html($cn); ?></span><span class="hm-svc-tag"<?php echo ka_field_attr('tag'); ?>><?php echo esc_html($c['tag'] ?? ''); ?></span><h3<?php echo ka_field_attr('title'); ?>><?php echo esc_html($c['title'] ?? ''); ?></h3><p<?php echo ka_field_attr('body'); ?>><?php echo esc_html($c['body'] ?? ''); ?></p><?php if ($feats) : ?><ul<?php echo ka_field_attr('features'); ?>><?php foreach ($feats as $f) : ?><li><?php echo esc_html($f); ?></li><?php endforeach; ?></ul><?php endif; ?></article>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</section>
