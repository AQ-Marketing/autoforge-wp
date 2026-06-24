<?php
/**
 * AQM blog archive — the /blog/ posts index, category archives, and search
 * results, built from the site's own classes so it matches the AQM design.
 * Routed here by the theme template_include override (functions.php). Cards link
 * to single posts (single-post.php). Chrome from AQ_Renderer head/footer.
 */
if (!defined('ABSPATH')) {
	exit;
}
AQ_Renderer::head_open();

if (is_category()) {
	$aq_title = single_cat_title('', false);
	$aq_lede  = 'Articles in ' . $aq_title . '.';
	$aq_crumb = $aq_title;
} elseif (is_search()) {
	$aq_title = 'Search results';
	$aq_lede  = 'Showing articles for “' . esc_html(get_search_query()) . '”.';
	$aq_crumb = 'Search';
} else {
	$aq_title = 'Blog';
	$aq_lede  = 'Plain-English guides on Local SEO, AI websites, reviews, and Google Business Profile for Massachusetts small businesses.';
	$aq_crumb = 'Blog';
}
?>
<header class="page-hero">
	<div class="wrap">
		<span class="badge"><i class="fa-solid fa-newspaper"></i> Insights</span>
		<h1><?php echo esc_html($aq_title); ?></h1>
		<p class="lede"><?php echo esc_html($aq_lede); ?></p>
	</div>
</header>
<nav class="crumbs"><div class="wrap"><ol><li><a href="/">Home</a></li><li><?php echo esc_html($aq_crumb); ?></li></ol></div></nav>

<section>
	<div class="wrap">
		<?php if (have_posts()) : ?>
		<div class="post-grid">
			<?php while (have_posts()) : the_post(); $c = get_the_category(); $c = $c ? $c[0] : null; ?>
			<article class="post-card">
				<a class="post-card-media" href="<?php the_permalink(); ?>" aria-hidden="true" tabindex="-1">
					<?php if (has_post_thumbnail()) : the_post_thumbnail('medium_large', ['alt' => esc_attr(get_the_title())]); else : ?><span class="post-card-ph"><i class="fa-solid fa-newspaper"></i></span><?php endif; ?>
				</a>
				<div class="post-card-body">
					<?php if ($c) : ?><a class="post-card-cat" href="<?php echo esc_url(get_category_link($c->term_id)); ?>"><?php echo esc_html($c->name); ?></a><?php endif; ?>
					<h2 class="post-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<p class="post-card-excerpt"><?php echo esc_html(wp_trim_words(get_the_excerpt(), 24)); ?></p>
					<div class="post-card-foot">
						<span class="post-card-date"><?php echo esc_html(get_the_date('M j, Y')); ?></span>
						<?php $aqm_read = function_exists('aqm_blog_setting') ? aqm_blog_setting('read_label', 'Read Article') : 'Read Article'; ?>
						<a class="post-card-link" href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr($aqm_read . ': ' . get_the_title()); ?>"><?php echo esc_html($aqm_read); ?> <i class="fa-solid fa-arrow-right"></i></a>
					</div>
				</div>
			</article>
			<?php endwhile; ?>
		</div>
		<div class="post-pagination">
			<?php echo paginate_links(['mid_size' => 1, 'prev_text' => '← Prev', 'next_text' => 'Next →']); ?>
		</div>
		<?php else : ?>
		<p>No articles found. <a href="/blog/">Back to all articles</a>.</p>
		<?php endif; ?>
	</div>
</section>
<style>
	.post-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:28px}
	@media (max-width:960px){.post-grid{grid-template-columns:repeat(2,1fr)}}
	@media (max-width:620px){.post-grid{grid-template-columns:1fr}}
	.post-card{display:flex;flex-direction:column;background:#fff;border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;transition:box-shadow .15s,transform .15s}
	.post-card:hover{box-shadow:0 12px 36px -18px rgba(0,0,0,.25);transform:translateY(-2px)}
	.post-card-media{display:block;aspect-ratio:3/2;overflow:hidden;background:#f0f2f5}
	.post-card-media img{width:100%;height:100%;object-fit:cover;display:block}
	.post-card-ph{display:flex;align-items:center;justify-content:center;height:100%;color:#c2c9d2;font-size:34px}
	.post-card-body{display:flex;flex-direction:column;gap:10px;padding:22px;flex:1}
	.post-card-cat{align-self:flex-start;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--teal);text-decoration:none}
	.post-card-title{font-size:18px;line-height:1.3;margin:0}
	.post-card-title a{color:var(--ink);text-decoration:none}
	.post-card-title a:hover{color:var(--teal)}
	.post-card-excerpt{font-size:14px;color:var(--muted);line-height:1.6;margin:0;flex:1}
	.post-card-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:6px;padding-top:14px;border-top:1px solid var(--line)}
	.post-card-date{font-size:12px;color:var(--muted)}
	.post-card-link{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:700;color:#fff;background:var(--teal);border-radius:8px;padding:8px 14px;text-decoration:none;white-space:nowrap;transition:background .15s,transform .15s}
	.post-card-link:hover{background:var(--teal-600);transform:translateX(1px)}
	.post-card-link i{font-size:11px;transition:transform .15s}
	.post-card-link:hover i{transform:translateX(2px)}
	.post-pagination{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:48px}
	.post-pagination .page-numbers{display:inline-flex;min-width:40px;height:40px;align-items:center;justify-content:center;padding:0 12px;border:1px solid var(--line);border-radius:8px;color:var(--ink);text-decoration:none;font-size:14px;font-weight:600}
	.post-pagination .page-numbers.current{background:var(--teal);color:#fff;border-color:var(--teal)}
	.post-pagination a.page-numbers:hover{border-color:var(--teal);color:var(--teal)}
	.post-pagination .page-numbers.current:hover{color:#fff}
</style>
<?php
AQ_Renderer::body_close();
