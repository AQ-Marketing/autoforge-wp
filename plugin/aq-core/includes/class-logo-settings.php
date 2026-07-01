<?php
/**
 * AQ Logo Settings — one dashboard screen (AutoForge → Logo) for the three logo
 * slots a site can use: the default header logo, an OPTIONAL sticky-header logo
 * (swapped in once the header is in its scrolled/sticky state — e.g. a light
 * logo over a transparent hero that becomes a dark logo on a solid bar), and
 * the footer logo. All three are stored in aq_site_config (`logo.id` /
 * `logo.idSticky` / `logo.idDark`), the same option brand.json import writes
 * to, so a dashboard edit and a content-repo import never fight each other —
 * whichever ran most recently wins, same as every other aq_site_config field.
 *
 * The sticky slot is the ONLY one with no prior admin UI before this screen
 * (header/footer logos were already import-only); it is purely additive and
 * falls back to the default logo when empty, so existing sites render
 * byte-identical until an admin opts in here.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Logo_Settings {

	const CAP  = 'manage_options';
	const SLUG = 'aq-logo';

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 24);
		add_action('admin_post_aq_logo_save', [__CLASS__, 'save']);
	}

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Logo', 'Logo', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	/** Current logo IDs, merged config (file defaults + saved overlay). */
	private static function get(): array {
		$logo = (array) (aq_site('logo') ?: []);
		return [
			'id'       => (int) ($logo['id'] ?? 0),
			'idSticky' => (int) ($logo['idSticky'] ?? 0),
			'idDark'   => (int) ($logo['idDark'] ?? 0),
		];
	}

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$cur = self::get();
		wp_enqueue_media();
		AQ_Admin_Hub::open('Logo', 'Set the logo for each spot it appears — including an optional swap for the sticky header.', self::SLUG);
		?>
		<style>
			.aq-logo-card { background:#fff; border:1px solid #dcdfe3; border-radius:10px; padding:18px 20px; margin:0 0 18px; max-width:660px; }
			.aq-logo-card h2 { margin:0 0 6px; font-size:15px; }
			.aq-logo-card p.aq-logo-hint { margin:0 0 14px; color:#5b6471; font-size:13px; }
			.aq-logo-row { display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
			.aq-logo-prev { min-width:150px; min-height:64px; display:flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:8px; background:#f3f4f5; }
			.aq-logo-prev img { max-height:60px; width:auto; }
		</style>
		<?php if (isset($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>Logo settings saved.</p></div><?php endif; ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="aq_logo_save">
			<?php wp_nonce_field('aq_logo_save'); ?>

			<?php
			$field = function (string $key, string $title, string $hint) use ($cur) {
				$id  = (int) $cur[$key];
				$img = $id ? wp_get_attachment_image($id, 'medium', false, ['class' => '']) : '<em style="color:#888">Not set' . ($key !== 'id' ? ' — falls back to the header logo' : '') . '.</em>';
				?>
				<div class="aq-logo-card">
					<h2><?php echo esc_html($title); ?></h2>
					<?php if ($hint) : ?><p class="aq-logo-hint"><?php echo esc_html($hint); ?></p><?php endif; ?>
					<div class="aq-logo-row">
						<div class="aq-logo-prev"><?php echo $img; ?></div>
						<div>
							<input type="hidden" name="<?php echo esc_attr($key); ?>" id="aq-logo-<?php echo esc_attr($key); ?>" value="<?php echo (int) $id; ?>">
							<button type="button" class="button aq-logo-pick" data-target="<?php echo esc_attr($key); ?>">Choose / change</button>
							<button type="button" class="button-link aq-logo-clear" data-target="<?php echo esc_attr($key); ?>" style="margin-left:10px;color:#b3261e">Remove</button>
						</div>
					</div>
				</div>
				<?php
			};
			$field('id', 'Header logo', '');
			$field('idSticky', 'Sticky-header logo (optional)', 'Swapped in once a visitor scrolls and the header is in its sticky/compact state. Leave unset to keep the header logo everywhere.');
			$field('idDark', 'Footer logo', 'Shown on the dark footer background. Leave unset to fall back to the header logo.');
			?>

			<?php submit_button('Save logo settings'); ?>
		</form>
		<script>
		(function () {
			function card(key) { return document.getElementById('aq-logo-' + key).closest('.aq-logo-card'); }
			document.querySelectorAll('.aq-logo-pick').forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.preventDefault();
					var key = btn.getAttribute('data-target');
					var frame = wp.media({ title: 'Select logo', multiple: false, library: { type: ['image'] } });
					frame.on('select', function () {
						var a = frame.state().get('selection').first().toJSON();
						document.getElementById('aq-logo-' + key).value = a.id;
						var url = (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url);
						card(key).querySelector('.aq-logo-prev').innerHTML = '<img src="' + url + '">';
					});
					frame.open();
				});
			});
			document.querySelectorAll('.aq-logo-clear').forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.preventDefault();
					var key = btn.getAttribute('data-target');
					document.getElementById('aq-logo-' + key).value = '';
					card(key).querySelector('.aq-logo-prev').innerHTML = '<em style="color:#888">Not set.</em>';
				});
			});
		})();
		</script>
		<?php
		AQ_Admin_Hub::close();
	}

	public static function save(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_logo_save')) {
			wp_die('Not allowed.');
		}
		AQ_Site_Config::update([
			'logo' => [
				'id'       => (int) ($_POST['id'] ?? 0),
				'idSticky' => (int) ($_POST['idSticky'] ?? 0),
				'idDark'   => (int) ($_POST['idDark'] ?? 0),
			],
		]);
		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => '1'], admin_url('admin.php')));
		exit;
	}
}
