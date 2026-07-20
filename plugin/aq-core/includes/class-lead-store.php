<?php
/**
 * AQ_Lead_Store — durable first-party record of every form submission.
 *
 * The engine already emails each lead and pushes it to the CRM
 * (see AQ_Lead_Capture), but BOTH of those are best-effort and off-site: a
 * filtered/screened inbox or a CRM outage can lose a lead with no trace. This
 * class writes every successful submission to a hidden `aq_lead` post so there
 * is ALWAYS a copy inside WordPress itself — browsable, searchable and
 * re-sendable — no matter what email or the CRM did with it.
 *
 * Design notes:
 *  - Fully self-contained: its own option, admin screen, cron and hooks. It
 *    hangs off the `aq_lead_captured` action that AQ_Lead_Capture fires, so the
 *    capture handler never has to know storage exists.
 *  - Storage is additive and defensive — it is wrapped so a failure here can
 *    never block or change the visitor's submission response.
 *  - Records are read-only in the admin (they are evidence, not editable posts):
 *    manual creation is disabled and the detail screen shows the data verbatim.
 *  - Retention is opt-in. Default is keep-forever; set a day count to have a
 *    daily cron purge older records (these are real people's contact details,
 *    so a business may want a defined retention window).
 *
 * @package aq-core
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Lead_Store {

	/** Hidden post type that holds one submission per post. */
	const CPT = 'aq_lead';

	/** Capability required to view/manage stored leads (same bar as Forms). */
	const CAP = 'manage_options';

	/** Non-secret settings (retention window). */
	const OPTION = 'aq_lead_store';

	/** Daily retention-purge cron hook. */
	const PURGE_CRON = 'aq_lead_store_purge';

	/** Meta key prefix for the stored fields (leading underscore = hidden meta). */
	const META = '_aql_';

	/** Standard scalar fields carried on the $f payload, in display order. */
	const FIELDS = ['first', 'last', 'email', 'phone', 'company', 'website', 'address', 'city', 'state', 'zip', 'service', 'message', 'source'];

	public static function register(): void {
		add_action('init', [__CLASS__, 'register_cpt']);

		// Capture: store every successful submission the engine reports.
		add_action('aq_lead_captured', [__CLASS__, 'store'], 10, 3);

		// Admin list table (native edit.php for the CPT) customisation.
		add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'columns']);
		add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'render_column'], 10, 2);
		add_filter('manage_edit-' . self::CPT . '_sortable_columns', [__CLASS__, 'sortable_columns']);
		add_filter('post_row_actions', [__CLASS__, 'row_actions'], 10, 2);
		add_filter('bulk_actions-edit-' . self::CPT, [__CLASS__, 'bulk_actions']);
		add_filter('disable_months_dropdown', [__CLASS__, 'keep_months_dropdown'], 10, 2);

		// Read-only detail view + resend controls.
		add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
		add_action('admin_post_aq_lead_resend', [__CLASS__, 'handle_resend']);

		// Retention settings + daily purge.
		add_action('admin_post_aq_lead_store_settings', [__CLASS__, 'save_settings']);
		add_action('init', [__CLASS__, 'reconcile_purge_schedule'], 20);
		add_action(self::PURGE_CRON, [__CLASS__, 'purge']);

		// Notices (settings card + action results) on the Submissions screen.
		add_action('admin_notices', [__CLASS__, 'admin_notices']);
	}

	/** Whether storage is active. Filterable so a site can opt out in code. */
	public static function enabled(): bool {
		return (bool) apply_filters('aq_lead_store_enabled', true);
	}

	/* ---------------- settings ---------------- */

	public static function get_settings(): array {
		$o = get_option(self::OPTION, []);
		$o = is_array($o) ? $o : [];
		return array_merge([
			'retention_days' => 0, // 0 = keep forever
		], $o);
	}

	/** Days to keep leads (0 = forever). Filterable for code-locked fleets. */
	public static function retention_days(): int {
		return (int) apply_filters('aq_lead_store_retention_days', (int) self::get_settings()['retention_days']);
	}

	/* ---------------- custom post type ---------------- */

	public static function register_cpt(): void {
		register_post_type(self::CPT, [
			'labels' => [
				'name'          => 'Submissions',
				'singular_name' => 'Submission',
				'menu_name'     => 'Submissions',
				'all_items'     => 'Submissions',
				'search_items'  => 'Search submissions',
				'not_found'     => 'No submissions yet.',
				'not_found_in_trash' => 'No submissions in Trash.',
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'aq-dashboard', // sits under the AutoForge menu
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => ['title'],
			'menu_icon'           => 'dashicons-email-alt',
			'map_meta_cap'        => true,
			// Records are written programmatically only — no "Add New".
			'capabilities'        => ['create_posts' => 'do_not_allow'],
		]);
	}

	/* ---------------- capture / storage ---------------- */

	/**
	 * Persist one submission. Fired from AQ_Lead_Capture::handle() via
	 * do_action('aq_lead_captured', $f, $ghl_ok, $mail_ok).
	 *
	 * @param array $f       The assembled submission payload.
	 * @param bool  $ghl_ok  Whether the CRM push succeeded.
	 * @param bool  $mail_ok Whether the notification email was accepted.
	 */
	public static function store(array $f, bool $ghl_ok = false, bool $mail_ok = false): void {
		if (!self::enabled()) {
			return;
		}
		try {
			$name  = trim((string) ($f['first'] ?? '') . ' ' . (string) ($f['last'] ?? ''));
			$title = $name !== '' ? $name : ((string) ($f['email'] ?? '') ?: ((string) ($f['phone'] ?? '') ?: 'Lead'));

			$post_id = wp_insert_post([
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => wp_strip_all_tags($title),
			], true);
			if (is_wp_error($post_id) || !$post_id) {
				error_log('[aq-lead-store] insert failed: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'no id'));
				return;
			}

			foreach (self::FIELDS as $k) {
				$v = (string) ($f[$k] ?? '');
				if ($v !== '') {
					update_post_meta($post_id, self::META . $k, $v);
				}
			}
			$tracking = (isset($f['tracking']) && is_array($f['tracking'])) ? $f['tracking'] : [];
			$custom   = (isset($f['custom']) && is_array($f['custom'])) ? $f['custom'] : [];
			if ($tracking) { update_post_meta($post_id, self::META . 'tracking', wp_json_encode($tracking)); }
			if ($custom)   { update_post_meta($post_id, self::META . 'custom', wp_json_encode($custom)); }

			update_post_meta($post_id, self::META . 'delivered_email', $mail_ok ? 1 : 0);
			update_post_meta($post_id, self::META . 'delivered_ghl', $ghl_ok ? 1 : 0);

			// Light request context — useful when triaging a suspicious lead.
			$ref = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw((string) $_SERVER['HTTP_REFERER']) : '';
			$ua  = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']) : '';
			$ip  = self::client_ip();
			if ($ref !== '') { update_post_meta($post_id, self::META . 'referer', $ref); }
			if ($ua !== '')  { update_post_meta($post_id, self::META . 'ua', mb_substr($ua, 0, 300)); }
			if ($ip !== '')  { update_post_meta($post_id, self::META . 'ip', $ip); }

			do_action('aq_lead_stored', $post_id, $f);
		} catch (\Throwable $e) {
			error_log('[aq-lead-store] ' . $e->getMessage());
		}
	}

	/** Best-effort client IP (honours a single trusted forwarded hop). */
	private static function client_ip(): string {
		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$xff = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
			if ($xff !== '' && filter_var($xff, FILTER_VALIDATE_IP)) { $ip = $xff; }
		}
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
	}

	/**
	 * Rebuild the $f payload (the shape email_tokens() expects) from a stored
	 * lead, so a record can be re-emailed exactly as it first arrived.
	 */
	public static function payload(int $post_id): array {
		$f = [];
		foreach (self::FIELDS as $k) {
			$f[$k] = (string) get_post_meta($post_id, self::META . $k, true);
		}
		$tracking = json_decode((string) get_post_meta($post_id, self::META . 'tracking', true), true);
		$custom   = json_decode((string) get_post_meta($post_id, self::META . 'custom', true), true);
		$f['tracking'] = is_array($tracking) ? $tracking : [];
		$f['custom']   = is_array($custom) ? $custom : [];
		return $f;
	}

	/* ---------------- admin list table ---------------- */

	public static function columns(array $cols): array {
		$date = $cols['date'] ?? 'Date';
		return [
			'cb'         => $cols['cb'] ?? '<input type="checkbox" />',
			'title'      => 'Name',
			'aql_email'  => 'Email',
			'aql_phone'  => 'Phone',
			'aql_service'=> 'Service',
			'aql_status' => 'Delivered',
			'date'       => 'Received',
		];
	}

	public static function sortable_columns(array $cols): array {
		$cols['aql_email'] = 'aql_email';
		return $cols;
	}

	public static function render_column(string $col, int $post_id): void {
		switch ($col) {
			case 'aql_email':
				$e = (string) get_post_meta($post_id, self::META . 'email', true);
				echo $e !== '' ? '<a href="mailto:' . esc_attr($e) . '">' . esc_html($e) . '</a>' : '&mdash;';
				break;
			case 'aql_phone':
				$p = (string) get_post_meta($post_id, self::META . 'phone', true);
				echo $p !== '' ? esc_html($p) : '&mdash;';
				break;
			case 'aql_service':
				$s = (string) get_post_meta($post_id, self::META . 'service', true);
				echo $s !== '' ? esc_html($s) : '&mdash;';
				break;
			case 'aql_status':
				echo self::badge('Email', (int) get_post_meta($post_id, self::META . 'delivered_email', true) === 1)
					. ' ' . self::badge('CRM', (int) get_post_meta($post_id, self::META . 'delivered_ghl', true) === 1);
				break;
		}
	}

	private static function badge(string $label, bool $ok): string {
		$bg = $ok ? '#eaf0ea' : '#fdecec';
		$fg = $ok ? '#1a6f3f' : '#a12b2b';
		$br = $ok ? '#b9dcc4' : '#eabcbc';
		$mk = $ok ? '&#10003;' : '&times;';
		return '<span style="display:inline-block;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:600;background:' . $bg . ';color:' . $fg . ';border:1px solid ' . $br . '">' . esc_html($label) . ' ' . $mk . '</span>';
	}

	/** Keep the by-month filter dropdown on the Submissions list. */
	public static function keep_months_dropdown($disabled, $post_type) {
		return ($post_type === self::CPT) ? false : $disabled;
	}

	/** Replace the default row actions with View + Resend (no Quick Edit). */
	public static function row_actions(array $actions, $post): array {
		if (!$post || $post->post_type !== self::CPT) {
			return $actions;
		}
		unset($actions['inline hide-if-no-js']); // Quick Edit makes no sense for a record
		$resend = wp_nonce_url(
			admin_url('admin-post.php?action=aq_lead_resend&lead=' . (int) $post->ID),
			'aq_lead_resend_' . (int) $post->ID
		);
		$new = [];
		if (isset($actions['edit'])) {
			// Relabel "Edit" as "View" — the detail screen is read-only.
			$new['edit'] = str_replace('>Edit<', '>View<', $actions['edit']);
		}
		$new['aql_resend'] = '<a href="' . esc_url($resend) . '">Resend to admin</a>';
		if (isset($actions['trash'])) { $new['trash'] = $actions['trash']; }
		return $new;
	}

	public static function bulk_actions(array $actions): array {
		unset($actions['edit']);
		return $actions;
	}

	/* ---------------- detail view (metaboxes) ---------------- */

	public static function meta_boxes(): void {
		add_meta_box('aql_detail', 'Submission', [__CLASS__, 'render_detail'], self::CPT, 'normal', 'high');
		add_meta_box('aql_resend', 'Resend notification', [__CLASS__, 'render_resend_box'], self::CPT, 'side', 'high');
	}

	public static function render_detail($post): void {
		$labels = [
			'first' => 'First name', 'last' => 'Last name', 'email' => 'Email', 'phone' => 'Phone',
			'company' => 'Company', 'website' => 'Website', 'address' => 'Address', 'city' => 'City',
			'state' => 'State', 'zip' => 'Zip', 'service' => 'Service', 'message' => 'Message', 'source' => 'Source',
		];
		echo '<style>.aql-tbl{width:100%;border-collapse:collapse}.aql-tbl th,.aql-tbl td{text-align:left;vertical-align:top;padding:8px 10px;border-bottom:1px solid #eef0f2;font-size:13px}.aql-tbl th{width:150px;color:#5b6471;font-weight:600;text-transform:uppercase;font-size:11px;letter-spacing:.04em}</style>';
		echo '<table class="aql-tbl">';
		foreach ($labels as $k => $label) {
			$v = (string) get_post_meta($post->ID, self::META . $k, true);
			if ($v === '') { continue; }
			$cell = ($k === 'message') ? nl2br(esc_html($v))
				: (($k === 'email') ? '<a href="mailto:' . esc_attr($v) . '">' . esc_html($v) . '</a>'
				: (($k === 'website') ? '<a href="' . esc_url($v) . '" target="_blank" rel="noopener">' . esc_html($v) . '</a>'
				: esc_html($v)));
			echo '<tr><th>' . esc_html($label) . '</th><td>' . $cell . '</td></tr>';
		}
		// Qualifying answers / any extra submitted fields.
		$custom = json_decode((string) get_post_meta($post->ID, self::META . 'custom', true), true);
		if (is_array($custom)) {
			foreach ($custom as $ck => $cv) {
				if ((string) $cv === '') { continue; }
				$label = ucfirst(str_replace(['_', '-'], ' ', (string) $ck));
				echo '<tr><th>' . esc_html($label) . '</th><td>' . nl2br(esc_html((string) $cv)) . '</td></tr>';
			}
		}
		echo '</table>';

		// Attribution + request context (collapsed under a subheading).
		$tracking = json_decode((string) get_post_meta($post->ID, self::META . 'tracking', true), true);
		$ref = (string) get_post_meta($post->ID, self::META . 'referer', true);
		$ua  = (string) get_post_meta($post->ID, self::META . 'ua', true);
		$ip  = (string) get_post_meta($post->ID, self::META . 'ip', true);
		if ((is_array($tracking) && $tracking) || $ref || $ua || $ip) {
			echo '<h4 style="margin:16px 0 6px;color:#5b6471">Attribution &amp; context</h4><table class="aql-tbl">';
			if (is_array($tracking)) {
				foreach ($tracking as $tk => $tv) {
					if ((string) $tv === '') { continue; }
					echo '<tr><th>' . esc_html((string) $tk) . '</th><td style="word-break:break-all">' . esc_html((string) $tv) . '</td></tr>';
				}
			}
			if ($ref) { echo '<tr><th>Referrer</th><td style="word-break:break-all">' . esc_html($ref) . '</td></tr>'; }
			if ($ip)  { echo '<tr><th>IP</th><td>' . esc_html($ip) . '</td></tr>'; }
			if ($ua)  { echo '<tr><th>User agent</th><td style="word-break:break-all">' . esc_html($ua) . '</td></tr>'; }
			echo '</table>';
		}
	}

	public static function render_resend_box($post): void {
		$email_ok = (int) get_post_meta($post->ID, self::META . 'delivered_email', true) === 1;
		$ghl_ok   = (int) get_post_meta($post->ID, self::META . 'delivered_ghl', true) === 1;
		echo '<p style="margin:0 0 8px;color:#5b6471;font-size:12px">When first received &mdash; email: ' . self::badge('', $email_ok) . ' &nbsp; CRM: ' . self::badge('', $ghl_ok) . '</p>';
		$base = admin_url('admin-post.php');
		echo '<form method="post" action="' . esc_url($base) . '" style="margin:0">';
		echo '<input type="hidden" name="action" value="aq_lead_resend">';
		echo '<input type="hidden" name="lead" value="' . (int) $post->ID . '">';
		wp_nonce_field('aq_lead_resend_' . (int) $post->ID);
		echo '<p style="margin:0 0 6px"><label for="aql_to" style="font-weight:600;font-size:12px">Send to (blank = your Forms notify address)</label></p>';
		echo '<input type="text" id="aql_to" name="to" placeholder="name@example.com" style="width:100%;margin-bottom:10px" />';
		submit_button('Resend notification', 'primary', 'submit', false);
		echo '</form>';
	}

	/* ---------------- resend ---------------- */

	public static function handle_resend(): void {
		$id = isset($_REQUEST['lead']) ? (int) $_REQUEST['lead'] : 0;
		if (!current_user_can(self::CAP) || !$id || !check_admin_referer('aq_lead_resend_' . $id)) {
			wp_die('Not allowed.');
		}
		if (get_post_type($id) !== self::CPT) {
			wp_die('Not a submission.');
		}
		$to = isset($_REQUEST['to']) ? sanitize_text_field(wp_unslash($_REQUEST['to'])) : '';
		if ($to !== '' && !is_email($to)) { $to = ''; } // ignore a malformed override → falls back to configured admin

		$ok = false;
		if (class_exists('AQ_Lead_Capture') && method_exists('AQ_Lead_Capture', 'resend')) {
			$ok = AQ_Lead_Capture::resend(self::payload($id), $to);
		}
		$back = get_edit_post_link($id, 'url');
		if (!$back) { $back = admin_url('edit.php?post_type=' . self::CPT); }
		wp_safe_redirect(add_query_arg('aql_resent', $ok ? '1' : '0', $back));
		exit;
	}

	/* ---------------- retention ---------------- */

	public static function save_settings(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_lead_store_settings')) {
			wp_die('Not allowed.');
		}
		$days = isset($_POST['retention_days']) ? (int) $_POST['retention_days'] : 0;
		$days = max(0, min(3650, $days)); // 0..10 years
		$opt = self::get_settings();
		$opt['retention_days'] = $days;
		update_option(self::OPTION, $opt, false);
		self::reconcile_purge_schedule();
		wp_safe_redirect(add_query_arg(['post_type' => self::CPT, 'aql_saved' => '1'], admin_url('edit.php')));
		exit;
	}

	/** Schedule the daily purge only when a retention window is set. */
	public static function reconcile_purge_schedule(): void {
		$has = self::retention_days() > 0;
		$scheduled = (bool) wp_next_scheduled(self::PURGE_CRON);
		if ($has && !$scheduled) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE_CRON);
		} elseif (!$has && $scheduled) {
			wp_clear_scheduled_hook(self::PURGE_CRON);
		}
	}

	/** Delete stored leads older than the retention window (best-effort, batched). */
	public static function purge(): void {
		$days = self::retention_days();
		if ($days <= 0) {
			return;
		}
		$before = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
		$ids = get_posts([
			'post_type'        => self::CPT,
			'post_status'      => 'any',
			'date_query'       => [['column' => 'post_date_gmt', 'before' => $before]],
			'numberposts'      => 200,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		]);
		foreach ($ids as $id) {
			wp_delete_post((int) $id, true);
		}
	}

	/* ---------------- notices (settings + results) ---------------- */

	public static function admin_notices(): void {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen) { return; }

		// Resend result on the single-lead screen.
		if ($screen->id === self::CPT && isset($_GET['aql_resent'])) {
			$ok = $_GET['aql_resent'] === '1';
			echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>'
				. ($ok ? 'Notification re-sent.' : 'Could not resend &mdash; check the Forms notify address.')
				. '</p></div>';
		}

		// Retention settings card on the list screen only.
		if ($screen->id === 'edit-' . self::CPT) {
			if (isset($_GET['aql_saved'])) {
				echo '<div class="notice notice-success is-dismissible"><p>Retention setting saved.</p></div>';
			}
			$days = (int) self::get_settings()['retention_days'];
			echo '<div class="notice notice-info" style="padding:10px 12px">';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0">';
			echo '<input type="hidden" name="action" value="aq_lead_store_settings">';
			wp_nonce_field('aq_lead_store_settings');
			echo '<strong>Keep submissions for</strong> ';
			echo '<input type="number" name="retention_days" min="0" max="3650" value="' . esc_attr((string) $days) . '" style="width:90px"> days ';
			echo '<span style="color:#5b6471">(0 = keep forever; older records are auto-deleted daily)</span> ';
			submit_button('Save', 'secondary', 'submit', false);
			echo '</form></div>';
		}
	}
}
