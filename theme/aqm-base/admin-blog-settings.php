<?php
/**
 * AQM Blog settings — a small global-options screen under the AutoForge admin
 * menu for editable blog text: the "Read Article" button label on the archive
 * and the article byline. Theme-owned (no plugin edit): adds a submenu to the
 * AutoForge parent and stores values in one option. Templates read them via
 * aqm_blog_setting(). Falls back to sensible defaults so nothing breaks if unset.
 */

if (!defined('ABSPATH')) {
	exit;
}

const AQM_BLOG_OPTION = 'aqm_blog_settings';

/** Editable fields: key => [label, help, default]. Default '' means "fall back". */
function aqm_blog_fields(): array {
	return [
		'read_label' => ['Read Article button', 'Label on each card in the blog index (and category pages).', 'Read Article'],
		'byline'     => ['Article byline', 'Author shown on each post ("By …"). Leave blank to use the site name.', ''],
	];
}

/** Read a blog setting, falling back to its default (then, for byline, site name). */
function aqm_blog_setting(string $key, string $fallback = ''): string {
	$opt = get_option(AQM_BLOG_OPTION, []);
	$val = is_array($opt) && isset($opt[$key]) ? trim((string) $opt[$key]) : '';
	if ($val !== '') {
		return $val;
	}
	$def = aqm_blog_fields()[$key][2] ?? '';
	return $def !== '' ? $def : $fallback;
}

/* ---- admin screen (under the AutoForge menu) ---- */

add_action('admin_menu', function (): void {
	add_submenu_page('aq-dashboard', 'Blog', 'Blog', 'manage_options', 'aq-blog', 'aqm_blog_settings_render');
}, 30);

add_action('admin_post_aqm_blog_settings_save', function (): void {
	if (!current_user_can('manage_options') || !check_admin_referer('aqm_blog_settings_save')) {
		wp_die('Not allowed.');
	}
	$store = [];
	foreach (aqm_blog_fields() as $key => $f) {
		$raw = isset($_POST[$key]) ? sanitize_text_field((string) wp_unslash($_POST[$key])) : '';
		if ($raw !== '') {
			$store[$key] = $raw;
		}
	}
	$store ? update_option(AQM_BLOG_OPTION, $store) : delete_option(AQM_BLOG_OPTION);
	wp_safe_redirect(add_query_arg(['page' => 'aq-blog', 'updated' => '1'], admin_url('admin.php')));
	exit;
});

function aqm_blog_settings_render(): void {
	if (!current_user_can('manage_options')) {
		return;
	}
	$opt = get_option(AQM_BLOG_OPTION, []);
	$hub = class_exists('AQ_Admin_Hub');
	if ($hub) {
		AQ_Admin_Hub::open('Blog', 'Text labels for the blog index and articles. Each falls back to a sensible default when left blank.', 'aq-blog');
	} else {
		echo '<div class="wrap"><h1>Blog</h1>';
	}
	if (isset($_GET['updated'])) {
		echo '<div class="notice notice-success is-dismissible"><p>Blog settings saved.</p></div>';
	}
	?>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"<?php echo $hub ? ' class="aq-panel"' : ''; ?>>
		<input type="hidden" name="action" value="aqm_blog_settings_save">
		<?php wp_nonce_field('aqm_blog_settings_save'); ?>
		<table class="form-table" role="presentation">
			<?php foreach (aqm_blog_fields() as $key => $f) :
				$val = is_array($opt) && isset($opt[$key]) ? (string) $opt[$key] : ''; ?>
			<tr>
				<th scope="row"><label for="aqm-<?php echo esc_attr($key); ?>"><?php echo esc_html($f[0]); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="aqm-<?php echo esc_attr($key); ?>"
						name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($val); ?>"
						placeholder="<?php echo esc_attr($f[2] !== '' ? $f[2] : 'Site name'); ?>">
					<p class="description"><?php echo esc_html($f[1]); ?></p>
				</td>
			</tr>
			<?php endforeach; ?>
		</table>
		<?php submit_button('Save blog settings'); ?>
	</form>
	<?php
	if ($hub) {
		AQ_Admin_Hub::close();
	} else {
		echo '</div>';
	}
}
