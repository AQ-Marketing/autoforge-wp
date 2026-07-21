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
 * The `aq_lead` post type is UI-LESS (show_ui=false): the whole admin experience
 * is a custom "Submissions" screen rendered inside the AutoForge hub chrome
 * (AQ_Admin_Hub::open/close) so it matches the rest of the plugin instead of
 * dumping the user onto the unbranded native edit.php list. List, single-lead
 * detail, resend and delete all live on admin.php?page=aq-submissions.
 *
 * Storage is additive and defensive — a failure here can never block or change
 * the visitor's submission response. Retention is opt-in (a daily purge).
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

	/** Admin screen slug (inside the AutoForge hub). */
	const SLUG = 'aq-submissions';

	/** Daily retention-purge cron hook. */
	const PURGE_CRON = 'aq_lead_store_purge';

	/** Meta key prefix for the stored fields (leading underscore = hidden meta). */
	const META = '_aql_';

	/** Standard scalar fields carried on the $f payload, in display order. */
	const FIELDS = ['first', 'last', 'email', 'phone', 'company', 'website', 'address', 'city', 'state', 'zip', 'service', 'message', 'source'];

	/** Rows shown per page on the list screen. */
	const PER_PAGE = 25;

	public static function register(): void {
		add_action('init', [__CLASS__, 'register_cpt']);

		// Capture: store every successful submission the engine reports.
		add_action('aq_lead_captured', [__CLASS__, 'store'], 10, 3);

		// Custom Submissions screen inside the AutoForge hub.
		add_action('admin_menu', [__CLASS__, 'menu'], 27);

		// Actions.
		add_action('admin_post_aq_lead_resend', [__CLASS__, 'handle_resend']);
		add_action('admin_post_aq_lead_delete', [__CLASS__, 'handle_delete']);
		add_action('admin_post_aq_lead_store_settings', [__CLASS__, 'save_settings']);

		// Retention: daily purge when a window is set.
		add_action('init', [__CLASS__, 'reconcile_purge_schedule'], 20);
		add_action(self::PURGE_CRON, [__CLASS__, 'purge']);
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

	/* ---------------- custom post type (UI-less) ---------------- */

	public static function register_cpt(): void {
		register_post_type(self::CPT, [
			'labels'              => ['name' => 'Submissions', 'singular_name' => 'Submission'],
			'public'              => false,
			'show_ui'             => false, // no native edit.php UI — the hub screen owns it
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => ['title'],
		]);
	}

	/* ---------------- capture / storage ---------------- */

	/**
	 * Persist one submission. Fired from AQ_Lead_Capture::handle() via
	 * do_action('aq_lead_captured', $f, $ghl_ok, $mail_ok).
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

	/** Rebuild the $f payload (the shape email_tokens() expects) from a stored lead. */
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

	/* ---------------- admin screen ---------------- */

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Submissions', 'Submissions', self::CAP, self::SLUG, [__CLASS__, 'render_screen']);
	}

	public static function url(array $args = []): string {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	/** Router: single-lead detail when ?lead=N, otherwise the list. */
	public static function render_screen(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		$lead = isset($_GET['lead']) ? (int) $_GET['lead'] : 0;
		if ($lead && get_post_type($lead) === self::CPT) {
			self::render_detail($lead);
		} else {
			self::render_list();
		}
	}

	private static function badge(string $label, bool $ok): string {
		$bg = $ok ? '#eaf0ea' : '#fbe7e7';
		$fg = $ok ? '#1a8f4f' : '#a30d25';
		$mk = $ok ? '&#10003;' : '&times;';
		return '<span class="aq-badge ' . ($ok ? 'aq-badge--ok' : 'aq-badge--off') . '">' . esc_html($label) . ' ' . $mk . '</span>';
	}

	/** Matching lead IDs (newest first), optionally filtered by a search term. */
	private static function list_ids(string $search): array {
		global $wpdb;
		if ($search !== '') {
			$like = '%' . $wpdb->esc_like($search) . '%';
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_aql_email','_aql_phone','_aql_company','_aql_service')
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND (p.post_title LIKE %s OR m.meta_value LIKE %s)
				 ORDER BY p.post_date DESC LIMIT 2000",
				self::CPT, $like, $like
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status = 'publish' ORDER BY p.post_date DESC LIMIT 2000",
				self::CPT
			);
		}
		return array_map('intval', (array) $wpdb->get_col($sql));
	}

	public static function render_list(): void {
		$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
		$paged  = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
		$ids    = self::list_ids($search);
		$total  = count($ids);
		$pages  = max(1, (int) ceil($total / self::PER_PAGE));
		$paged  = min($paged, $pages);
		$slice  = array_slice($ids, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);
		if ($slice) { update_meta_cache('post', $slice); }

		AQ_Admin_Hub::open('Submissions', 'Every website form submission, stored safely in WordPress.', self::SLUG);

		// notices from actions
		if (isset($_GET['aql_resent'])) {
			$ok = $_GET['aql_resent'] === '1';
			echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . ($ok ? 'Notification re-sent.' : 'Could not resend — check the Forms notify address.') . '</p></div>';
		}
		if (isset($_GET['aql_deleted'])) {
			echo '<div class="notice notice-success is-dismissible"><p>Submission deleted.</p></div>';
		}
		if (isset($_GET['aql_saved'])) {
			echo '<div class="notice notice-success is-dismissible"><p>Retention setting saved.</p></div>';
		}

		// retention card
		$days = (int) self::get_settings()['retention_days'];
		echo '<div class="aq-panel" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0;">';
		echo '<input type="hidden" name="action" value="aq_lead_store_settings">';
		wp_nonce_field('aq_lead_store_settings');
		echo '<strong style="font-size:13px;">Keep submissions for</strong> ';
		echo '<input type="number" name="retention_days" min="0" max="3650" value="' . esc_attr((string) $days) . '" style="width:88px;height:32px;border:1px solid #c9cfd6;border-radius:8px;padding:4px 8px;"> <span style="color:#5b6471;font-size:13px;">days ' . AQ_Admin_Hub::tip('0 = keep forever. Older submissions are automatically deleted once a day.') . '</span> ';
		echo '<button type="submit" class="aq-btn aq-btn--ghost">Save</button>';
		echo '</form>';
		echo '<span style="color:#5b6471;font-size:13px;">' . (int) $total . ' stored</span>';
		echo '</div>';

		// search
		echo '<div class="aq-panel">';
		echo '<form method="get" class="aq-pages__bar" style="margin:0 0 14px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
		echo '<h2 style="margin:0;">' . ($search !== '' ? 'Results for &ldquo;' . esc_html($search) . '&rdquo;' : 'All submissions') . '</h2>';
		echo '<input type="search" name="s" value="' . esc_attr($search) . '" class="aq-search" placeholder="Search name, email, phone, company…" aria-label="Search submissions">';
		echo '</form>';

		if (!$slice) {
			echo '<p class="aq-search__empty">' . ($search !== '' ? 'No submissions match your search.' : 'No submissions yet — they’ll appear here as your forms are used.') . '</p>';
			echo '</div>';
			AQ_Admin_Hub::close();
			return;
		}

		echo '<table class="aq-table"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Service</th><th>Delivered</th><th>Received</th><th></th></tr></thead><tbody>';
		foreach ($slice as $id) {
			$name  = get_the_title($id) ?: '(no name)';
			$email = (string) get_post_meta($id, self::META . 'email', true);
			$phone = (string) get_post_meta($id, self::META . 'phone', true);
			$svc   = (string) get_post_meta($id, self::META . 'service', true);
			$em_ok = (int) get_post_meta($id, self::META . 'delivered_email', true) === 1;
			$gh_ok = (int) get_post_meta($id, self::META . 'delivered_ghl', true) === 1;
			$when  = get_post_datetime($id) ? wp_date('M j, Y g:i a', get_post_timestamp($id)) : get_the_date('', $id);
			echo '<tr>';
			echo '<td><a href="' . esc_url(self::url(['lead' => $id])) . '"><strong>' . esc_html($name) . '</strong></a></td>';
			echo '<td>' . ($email !== '' ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '&mdash;') . '</td>';
			echo '<td>' . ($phone !== '' ? esc_html($phone) : '&mdash;') . '</td>';
			echo '<td>' . ($svc !== '' ? esc_html($svc) : '&mdash;') . '</td>';
			echo '<td>' . self::badge('Email', $em_ok) . ' ' . self::badge('CRM', $gh_ok) . '</td>';
			echo '<td style="color:#5b6471;font-size:12px;">' . esc_html((string) $when) . '</td>';
			echo '<td><a class="aq-btn aq-btn--ghost" href="' . esc_url(self::url(['lead' => $id])) . '">View</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// pager
		if ($pages > 1) {
			echo '<div style="margin-top:14px;display:flex;gap:8px;align-items:center;font-size:13px;color:#5b6471;">';
			if ($paged > 1) { echo '<a class="aq-btn aq-btn--ghost" href="' . esc_url(self::url(array_filter(['s' => $search, 'paged' => $paged - 1]))) . '">&larr; Newer</a>'; }
			echo '<span>Page ' . (int) $paged . ' of ' . (int) $pages . '</span>';
			if ($paged < $pages) { echo '<a class="aq-btn aq-btn--ghost" href="' . esc_url(self::url(array_filter(['s' => $search, 'paged' => $paged + 1]))) . '">Older &rarr;</a>'; }
			echo '</div>';
		}
		echo '</div>';
		AQ_Admin_Hub::close();
	}

	public static function render_detail(int $id): void {
		$labels = [
			'first' => 'First name', 'last' => 'Last name', 'email' => 'Email', 'phone' => 'Phone',
			'company' => 'Company', 'website' => 'Website', 'address' => 'Address', 'city' => 'City',
			'state' => 'State', 'zip' => 'Zip', 'service' => 'Service', 'message' => 'Message', 'source' => 'Source',
		];
		$name  = get_the_title($id) ?: 'Submission';
		$em_ok = (int) get_post_meta($id, self::META . 'delivered_email', true) === 1;
		$gh_ok = (int) get_post_meta($id, self::META . 'delivered_ghl', true) === 1;

		AQ_Admin_Hub::open('Submission', 'A single stored form submission.', self::SLUG);

		if (isset($_GET['aql_resent'])) {
			$ok = $_GET['aql_resent'] === '1';
			echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . ($ok ? 'Notification re-sent.' : 'Could not resend — check the Forms notify address.') . '</p></div>';
		}

		echo '<p><a href="' . esc_url(self::url()) . '" class="aq-btn aq-btn--ghost">&larr; All submissions</a></p>';

		echo '<div class="aq-panel">';
		echo '<h2 style="margin:0 0 4px;">' . esc_html($name) . '</h2>';
		echo '<p style="margin:0 0 14px;color:#5b6471;font-size:13px;">Delivered — email: ' . self::badge('', $em_ok) . ' &nbsp; CRM: ' . self::badge('', $gh_ok) . '</p>';
		echo '<style>.aql-tbl{width:100%;border-collapse:collapse}.aql-tbl th,.aql-tbl td{text-align:left;vertical-align:top;padding:9px 10px;border-bottom:1px solid #eef1f5;font-size:13px}.aql-tbl th{width:150px;color:#5b6471;font-weight:600;text-transform:uppercase;font-size:11px;letter-spacing:.04em}</style>';
		echo '<table class="aql-tbl">';
		foreach ($labels as $k => $label) {
			$v = (string) get_post_meta($id, self::META . $k, true);
			if ($v === '') { continue; }
			$cell = ($k === 'message') ? nl2br(esc_html($v))
				: (($k === 'email') ? '<a href="mailto:' . esc_attr($v) . '">' . esc_html($v) . '</a>'
				: (($k === 'website') ? '<a href="' . esc_url($v) . '" target="_blank" rel="noopener">' . esc_html($v) . '</a>'
				: esc_html($v)));
			echo '<tr><th>' . esc_html($label) . '</th><td>' . $cell . '</td></tr>';
		}
		$custom = json_decode((string) get_post_meta($id, self::META . 'custom', true), true);
		if (is_array($custom)) {
			foreach ($custom as $ck => $cv) {
				if ((string) $cv === '') { continue; }
				$label = ucfirst(str_replace(['_', '-'], ' ', (string) $ck));
				echo '<tr><th>' . esc_html($label) . '</th><td>' . nl2br(esc_html((string) $cv)) . '</td></tr>';
			}
		}
		echo '</table>';

		$tracking = json_decode((string) get_post_meta($id, self::META . 'tracking', true), true);
		$ref = (string) get_post_meta($id, self::META . 'referer', true);
		$ua  = (string) get_post_meta($id, self::META . 'ua', true);
		$ip  = (string) get_post_meta($id, self::META . 'ip', true);
		if ((is_array($tracking) && $tracking) || $ref || $ua || $ip) {
			echo '<h3 style="margin:18px 0 6px;color:#5b6471;font-size:13px;">Attribution &amp; context</h3><table class="aql-tbl">';
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
		echo '</div>';

		// resend + delete
		echo '<div class="aq-panel"><h2>Resend notification</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
		echo '<input type="hidden" name="action" value="aq_lead_resend"><input type="hidden" name="lead" value="' . (int) $id . '">';
		wp_nonce_field('aq_lead_resend_' . (int) $id);
		echo '<input type="text" name="to" placeholder="Leave blank for your Forms notify address" style="flex:1;min-width:260px;max-width:440px;height:36px;border:1px solid #c9cfd6;border-radius:8px;padding:6px 11px;">';
		echo '<button type="submit" class="aq-btn">Resend</button>';
		echo '</form></div>';

		echo '<div class="aq-panel"><h2>Delete</h2><p style="color:#5b6471;font-size:13px;margin:0 0 12px;">Permanently remove this submission from WordPress. This does not affect your CRM.</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this submission permanently?\');">';
		echo '<input type="hidden" name="action" value="aq_lead_delete"><input type="hidden" name="lead" value="' . (int) $id . '">';
		wp_nonce_field('aq_lead_delete_' . (int) $id);
		echo '<button type="submit" class="aq-btn" style="background:#a30d25;">Delete submission</button>';
		echo '</form></div>';

		AQ_Admin_Hub::close();
	}

	/* ---------------- actions ---------------- */

	public static function handle_resend(): void {
		$id = isset($_REQUEST['lead']) ? (int) $_REQUEST['lead'] : 0;
		if (!current_user_can(self::CAP) || !$id || !check_admin_referer('aq_lead_resend_' . $id)) {
			wp_die('Not allowed.');
		}
		if (get_post_type($id) !== self::CPT) {
			wp_die('Not a submission.');
		}
		$to = isset($_REQUEST['to']) ? sanitize_text_field(wp_unslash($_REQUEST['to'])) : '';
		if ($to !== '' && !is_email($to)) { $to = ''; }

		$ok = false;
		if (class_exists('AQ_Lead_Capture') && method_exists('AQ_Lead_Capture', 'resend')) {
			$ok = AQ_Lead_Capture::resend(self::payload($id), $to);
		}
		wp_safe_redirect(self::url(['lead' => $id, 'aql_resent' => $ok ? '1' : '0']));
		exit;
	}

	public static function handle_delete(): void {
		$id = isset($_REQUEST['lead']) ? (int) $_REQUEST['lead'] : 0;
		if (!current_user_can(self::CAP) || !$id || !check_admin_referer('aq_lead_delete_' . $id)) {
			wp_die('Not allowed.');
		}
		if (get_post_type($id) === self::CPT) {
			wp_delete_post($id, true);
		}
		wp_safe_redirect(self::url(['aql_deleted' => '1']));
		exit;
	}

	public static function save_settings(): void {
		if (!current_user_can(self::CAP) || !check_admin_referer('aq_lead_store_settings')) {
			wp_die('Not allowed.');
		}
		$days = isset($_POST['retention_days']) ? (int) $_POST['retention_days'] : 0;
		$days = max(0, min(3650, $days));
		$opt = self::get_settings();
		$opt['retention_days'] = $days;
		update_option(self::OPTION, $opt, false);
		self::reconcile_purge_schedule();
		wp_safe_redirect(self::url(['aql_saved' => '1']));
		exit;
	}

	/* ---------------- retention ---------------- */

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
}
