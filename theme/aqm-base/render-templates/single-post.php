<?php
/**
 * AQM single blog post — design-consistent article layout built from the site's
 * own classes (.page-hero / .crumbs / .prose) so posts match the rest of the
 * AQM site rather than the engine's default (Tailwind) post template. Routed
 * here by the theme's own template_include override (functions.php, priority 60),
 * so the shared plugin is untouched. Chrome comes from AQ_Renderer head/footer.
 */
if (!defined('ABSPATH')) {
	exit;
}
AQ_Renderer::head_open();

while (have_posts()) :
	the_post();
	$pid  = get_the_ID();
	$cats = get_the_category();
	$cat  = $cats ? $cats[0] : null;
	$mins = max(1, (int) round(str_word_count(wp_strip_all_tags(get_the_content())) / 200));
	$aqm_byline_fallback = (function_exists('aq_site') ? (aq_site('blog.author') ?: aq_site('name')) : '') ?: get_bloginfo('name');
	$author = function_exists('aqm_blog_setting') ? aqm_blog_setting('byline', $aqm_byline_fallback) : $aqm_byline_fallback;
	?>
	<header class="page-hero">
		<div class="wrap">
			<?php if ($cat) : ?><span class="badge"><i class="fa-solid fa-newspaper"></i> <?php echo esc_html($cat->name); ?></span><?php endif; ?>
			<h1><?php the_title(); ?></h1>
			<p class="lede"><?php echo esc_html(get_the_date('F j, Y')); ?> &middot; <?php echo (int) $mins; ?> min read &middot; By <?php echo esc_html($author); ?></p>
		</div>
	</header>
	<nav class="crumbs"><div class="wrap"><ol><li><a href="/">Home</a></li><li><a href="/blog/">Blog</a></li><li><?php the_title(); ?></li></ol></div></nav>

	<?php if (has_post_thumbnail()) : ?>
	<div class="wrap post-featured"><?php the_post_thumbnail('large', ['alt' => esc_attr(get_the_title())]); ?></div>
	<?php endif; ?>

	<section>
		<div class="wrap">
			<div class="prose article-body"><?php the_content(); ?></div>
			<p class="post-back"><a href="/blog/"><i class="fa-solid fa-arrow-left"></i> Back to all articles</a></p>
		</div>
	</section>
	<?php
endwhile;
?>
<style>
	.post-featured{margin:32px auto 0;max-width:1000px}
	.post-featured img{display:block;width:100%;height:auto;border-radius:var(--radius);border:1px solid var(--line)}
	.article-body{margin:0 auto}
	.article-body img{max-width:100%;height:auto;border-radius:var(--radius);margin:24px 0}
	.article-body h2{font-size:28px;margin:48px 0 16px}
	.article-body h4{font-size:17px;margin:24px 0 8px;font-weight:700}
	.article-body table{width:100%;border-collapse:collapse;margin:24px 0;font-size:15px}
	.article-body th,.article-body td{border:1px solid var(--line);padding:10px 14px;text-align:left;vertical-align:top;line-height:1.55}
	.article-body th{background:#f6f8fa;font-weight:700;color:var(--ink)}
	.article-body figure{margin:24px 0}
	.article-body figcaption{font-size:13px;color:var(--muted);margin-top:8px;text-align:center}
	.post-back{max-width:780px;margin:40px auto 0;padding-top:24px;border-top:1px solid var(--line)}
	.post-back a{color:var(--teal);text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:8px}
	.post-back a:hover{text-decoration:underline}
</style>
<?php
AQ_Renderer::body_close();
