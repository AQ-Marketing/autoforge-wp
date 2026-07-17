<?php
/**
 * AQ Editor Review — the AI SEO/brand guardian that gates visual-editor saves.
 *
 * The visual builder normally writes straight to the page (aq/v1/editor/save →
 * AQ_Content_Sync::update_sections). For NON-agency editors we insert a review
 * gate first, so a well-meaning client can't quietly break the site's SEO:
 *
 *   1) POST aq/v1/editor/review  — diff the edit (before → after), assemble full
 *      company + SEO context, ask Claude to rate EACH change OK / Caution /
 *      High-risk with a plain-English reason. Writes NOTHING; stores the review.
 *   2) The builder shows an allow/deny panel. The user approves/rejects each
 *      change (High-risk needs an explicit "I understand" confirm).
 *   3) POST aq/v1/editor/commit  — the server rebuilds the final section set
 *      from the STORED before/after + the user's decisions (never trusting a
 *      client-sent final set), enforces the High-risk confirms, and writes
 *      through the one true write path.
 *
 * Bypass: agency admins (manage_options AND an @{AQ_AGENCY_EMAIL_DOMAIN} email)
 * skip the gate and keep the direct Save; everyone else is gated. The domain is
 * a constant + filter so the client-agnostic engine isn't wedded to one agency.
 *
 * Enforcement: the existing /save endpoint 403s gated users (see AQ_Editor),
 * so the gate cannot be POSTed around — gated writes only happen via /commit.
 *
 * No AI key? review() falls back to a deterministic rules_review() so the gate
 * always works; a key just makes the feedback smarter and friendlier.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Editor_Review {

	const CAP              = 'manage_options'; // the whole editor is admin-gated today
	const TRANSIENT_PREFIX = 'aq_review_';
	const TRANSIENT_TTL    = 1800; // 30 min — a review must be committed reasonably promptly
	const MAX_AI_CHANGES   = 60;   // cost guard: AI-review at most this many changes/run

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	/* ============================================================
	 * Config: bypass, enablement, settings
	 * ============================================================ */

	/** Agency email domain whose admins bypass the review (filterable/constant). */
	public static function agency_domain(): string {
		$d = defined('AQ_AGENCY_EMAIL_DOMAIN') ? (string) AQ_AGENCY_EMAIL_DOMAIN : 'aqmarketing.com';
		return strtolower(trim($d));
	}

	/**
	 * Does the given (or current) user bypass the review gate?
	 * True only for administrators whose email is on the agency domain.
	 */
	public static function can_bypass(?int $user_id = null): bool {
		$user   = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
		$bypass = false;
		if ($user && $user->exists() && user_can($user, 'manage_options')) {
			$domain = self::agency_domain();
			$email  = strtolower((string) $user->user_email);
			$suffix = '@' . $domain;
			$bypass = $domain !== '' && substr($email, -strlen($suffix)) === $suffix;
		}
		return (bool) apply_filters('aq_editor_bypass_review', $bypass, $user);
	}

	/** Master on/off for the review gate (default on). */
	public static function is_enabled(): bool {
		$o       = self::settings();
		$enabled = !array_key_exists('review_enabled', $o) || !empty($o['review_enabled']);
		return (bool) apply_filters('aq_editor_review_enabled', $enabled);
	}

	/** Should THIS request be gated? Enabled AND the user can't bypass. */
	public static function should_gate(?int $user_id = null): bool {
		return self::is_enabled() && !self::can_bypass($user_id);
	}

	/** Review settings live alongside the SEO Agent's option (shared SEO home). */
	public static function settings(): array {
		$o = get_option('aq_seo_agent', []);
		return is_array($o) ? $o : [];
	}

	private static function model(): string {
		$o     = self::settings();
		$model = isset($o['review_model']) ? (string) $o['review_model'] : '';
		if ($model === '') {
			$model = 'claude-sonnet-5'; // interactive latency default
		}
		return (string) apply_filters('aq_review_model', $model);
	}

	/* ============================================================
	 * REST
	 * ============================================================ */

	public static function rest_routes(): void {
		$can = function () { return current_user_can(self::CAP); };
		register_rest_route('aq/v1', '/editor/review', [
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => [__CLASS__, 'rest_review'],
		]);
		register_rest_route('aq/v1', '/editor/commit', [
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => [__CLASS__, 'rest_commit'],
		]);
	}

	/**
	 * POST /editor/review — diff + AI verdicts. Writes nothing.
	 * Body: { id, base:[…sections], proposed:[…sections] } (sections carry a
	 * transient _uid the builder assigned, used only to match before↔after).
	 */
	public static function rest_review(WP_REST_Request $req) {
		$body     = $req->get_json_params();
		$id       = (int) ($body['id'] ?? 0);
		$base     = isset($body['base']) && is_array($body['base']) ? $body['base'] : null;
		$proposed = isset($body['proposed']) && is_array($body['proposed']) ? $body['proposed'] : null;

		$err = self::guard($id, $base, $proposed);
		if ($err) { return $err; }

		// The client must be editing from CURRENT live content, else its "before"
		// (which we revert denied changes to) would be stale. Ask it to reload.
		$live = self::live_sections($id);
		if (self::canon($live) !== self::canon($base)) {
			return rest_ensure_response(['ok' => false, 'stale' => true,
				'message' => 'This page changed since you opened it. Reload the editor to get the latest version, then try again.']);
		}

		$diff = self::diff($base, $proposed);
		if (!$diff) {
			return rest_ensure_response(['ok' => true, 'empty' => true, 'reviewId' => '',
				'overall' => ['summary' => 'No changes to review.', 'recommendation' => ''],
				'changes' => [], 'usedAi' => false]);
		}

		$company    = self::company_profile();
		$seo        = self::seo_context($id);
		$beforeText = self::text_of_page($base);
		$afterText  = self::text_of_page($proposed);

		$review = self::run_review($diff, $company, $seo, $beforeText, $afterText);
		$byId   = $review['byId'];

		// Public change list = diff enriched with the verdict.
		$changes = [];
		foreach ($diff as $c) {
			$v = $byId[$c['id']] ?? ['severity' => 'caution', 'title' => $c['label'], 'reason' => 'Changed — please review.', 'suggestion' => ''];
			$changes[] = [
				'id'          => $c['id'],
				'kind'        => $c['kind'],
				'label'       => $c['label'],
				'sectionType' => $c['sectionType'] ?? '',
				'before'      => $c['before'],
				'after'       => $c['after'],
				'severity'    => self::norm_severity($v['severity'] ?? 'caution'),
				'title'       => (string) ($v['title'] ?? $c['label']),
				'reason'      => (string) ($v['reason'] ?? ''),
				'suggestion'  => (string) ($v['suggestion'] ?? ''),
			];
		}

		$reviewId = self::store_review($id, $base, $proposed, $diff, $byId, self::canon($live));

		return rest_ensure_response([
			'ok'       => true,
			'reviewId' => $reviewId,
			'overall'  => $review['overall'],
			'changes'  => $changes,
			'counts'   => self::counts($changes),
			'usedAi'   => (bool) $review['usedAi'],
		]);
	}

	/**
	 * POST /editor/commit — apply the user's allow/deny decisions and write.
	 * Body: { id, reviewId, decisions:{changeId:'allow'|'deny'}, confirmedHighRisk:[changeId] }
	 */
	public static function rest_commit(WP_REST_Request $req) {
		$body      = $req->get_json_params();
		$id        = (int) ($body['id'] ?? 0);
		$reviewId  = isset($body['reviewId']) ? sanitize_key((string) $body['reviewId']) : '';
		$decisions = isset($body['decisions']) && is_array($body['decisions']) ? $body['decisions'] : [];
		$confirmed = isset($body['confirmedHighRisk']) && is_array($body['confirmedHighRisk']) ? array_map('strval', $body['confirmedHighRisk']) : [];

		$post = $id ? get_post($id) : null;
		if (!$post || $post->post_type !== 'page') {
			return new WP_Error('aq_not_found', 'Page not found.', ['status' => 404]);
		}
		if (!current_user_can('edit_post', $id)) {
			return new WP_Error('aq_forbidden', 'You cannot edit this page.', ['status' => 403]);
		}
		$rec = $reviewId ? get_transient(self::TRANSIENT_PREFIX . $reviewId) : false;
		if (!is_array($rec) || (int) ($rec['post_id'] ?? 0) !== $id || (int) ($rec['user_id'] ?? 0) !== get_current_user_id()) {
			return rest_ensure_response(['ok' => false, 'expired' => true,
				'message' => 'This review has expired. Please run the review again.']);
		}

		// Concurrency guard: the live page must be unchanged since the review ran.
		if (self::canon(self::live_sections($id)) !== (string) ($rec['base_live_hash'] ?? '')) {
			delete_transient(self::TRANSIENT_PREFIX . $reviewId);
			return rest_ensure_response(['ok' => false, 'stale' => true,
				'message' => 'This page changed while you were reviewing. Reload the editor and try again.']);
		}

		// Enforce: every ALLOWED High-risk change must be explicitly confirmed.
		$needConfirm = [];
		foreach ((array) $rec['diff'] as $c) {
			$cid   = $c['id'];
			$sev   = self::norm_severity($rec['verdicts'][$cid]['severity'] ?? 'caution');
			$allow = (($decisions[$cid] ?? 'deny') === 'allow');
			if ($allow && $sev === 'high' && !in_array($cid, $confirmed, true)) {
				$needConfirm[] = $cid;
			}
		}
		if ($needConfirm) {
			return rest_ensure_response(['ok' => false, 'needConfirm' => $needConfirm,
				'message' => 'Please confirm the high-risk changes before publishing.']);
		}

		$final = self::reconstruct((array) $rec['base'], (array) $rec['proposed'], (array) $rec['diff'], $decisions);
		$final = self::sanitize_sections($final);

		if (!class_exists('AQ_Content_Sync')) {
			return new WP_Error('aq_no_sync', 'Content sync unavailable.', ['status' => 500]);
		}
		AQ_Content_Sync::update_sections($id, $final);
		delete_transient(self::TRANSIENT_PREFIX . $reviewId);

		return rest_ensure_response([
			'ok'       => true,
			'count'    => count($final),
			'sections' => AQ_Content_Sync::read_sections($id),
		]);
	}

	/** Shared request validation for /review. */
	private static function guard(int $id, $base, $proposed) {
		$post = $id ? get_post($id) : null;
		if (!$post || $post->post_type !== 'page') {
			return new WP_Error('aq_not_found', 'Page not found.', ['status' => 404]);
		}
		if (!current_user_can('edit_post', $id)) {
			return new WP_Error('aq_forbidden', 'You cannot edit this page.', ['status' => 403]);
		}
		if (!is_array($base) || !is_array($proposed)) {
			return new WP_Error('aq_bad_body', 'Missing base/proposed sections.', ['status' => 400]);
		}
		return null;
	}

	/* ============================================================
	 * Diff (before → after), matched by the builder's transient _uid
	 * ============================================================ */

	private static function diff(array $base, array $proposed): array {
		$changes = [];
		$n       = 0;
		$mk      = function () use (&$n) { return 'c' . (++$n); };

		[$baseByUid, $baseOrder] = self::index_by_uid($base, 'b');
		[$propByUid, $propOrder] = self::index_by_uid($proposed, 'p');

		// Removed: present in base, absent from proposed.
		foreach ($baseOrder as $uid) {
			if (isset($propByUid[$uid])) { continue; }
			$s = $baseByUid[$uid];
			$changes[] = [
				'id' => $mk(), 'kind' => 'section-removed', 'uid' => $uid,
				'sectionType' => (string) ($s['type'] ?? ''),
				'label' => 'Removed: ' . self::layout_label((string) ($s['type'] ?? '')),
				'before' => self::section_summary($s), 'after' => null,
			];
		}
		// Added: present in proposed, absent from base.
		foreach ($propOrder as $uid) {
			if (isset($baseByUid[$uid])) { continue; }
			$s = $propByUid[$uid];
			$changes[] = [
				'id' => $mk(), 'kind' => 'section-added', 'uid' => $uid,
				'sectionType' => (string) ($s['type'] ?? ''),
				'label' => 'Added: ' . self::layout_label((string) ($s['type'] ?? '')),
				'before' => null, 'after' => self::section_summary($s),
			];
		}
		// Modified: present in both — compare fields.
		foreach ($propOrder as $uid) {
			if (!isset($baseByUid[$uid])) { continue; }
			$b = $baseByUid[$uid];
			$p = $propByUid[$uid];
			foreach (self::field_diff((string) ($p['type'] ?? ''), $b, $p) as $fc) {
				$changes[] = [
					'id' => $mk(), 'kind' => 'field-changed', 'uid' => $uid,
					'sectionType' => (string) ($p['type'] ?? ''), 'field' => $fc['field'],
					'label' => self::layout_label((string) ($p['type'] ?? '')) . ' — ' . $fc['label'],
					'before' => $fc['before'], 'after' => $fc['after'],
				];
			}
		}
		// Reordered: same common set, different order (informational — low risk).
		$baseCommon = array_values(array_filter($baseOrder, function ($u) use ($propByUid) { return isset($propByUid[$u]); }));
		$propCommon = array_values(array_filter($propOrder, function ($u) use ($baseByUid) { return isset($baseByUid[$u]); }));
		if ($baseCommon !== $propCommon) {
			$changes[] = [
				'id' => $mk(), 'kind' => 'section-reordered', 'uid' => null,
				'sectionType' => '', 'label' => 'Sections reordered', 'before' => null, 'after' => null,
			];
		}
		return $changes;
	}

	/** [ uid => section, [uid,…in order] ] — falls back to a positional key. */
	private static function index_by_uid(array $sections, string $side): array {
		$byUid = [];
		$order = [];
		foreach ($sections as $i => $s) {
			if (!is_array($s)) { continue; }
			$uid = isset($s['_uid']) && $s['_uid'] !== '' ? (string) $s['_uid'] : ($side . $i);
			// Guard against duplicate/missing uids so one never clobbers another.
			if (isset($byUid[$uid])) { $uid .= '_' . $i; }
			$byUid[$uid] = $s;
			$order[]     = $uid;
		}
		return [$byUid, $order];
	}

	/** Per-field before/after for a modified section (top level + one repeater level). */
	private static function field_diff(string $type, array $b, array $p): array {
		$out  = [];
		$keys = array_unique(array_merge(array_keys($b), array_keys($p)));
		foreach ($keys as $k) {
			if ($k === 'type' || $k === 'v' || (is_string($k) && isset($k[0]) && $k[0] === '_')) {
				continue;
			}
			$bv = $b[$k] ?? null;
			$pv = $p[$k] ?? null;
			if (self::canon_value($bv) === self::canon_value($pv)) {
				continue;
			}
			$out[] = [
				'field'  => (string) $k,
				'label'  => self::field_label($type, (string) $k),
				'before' => self::snip(self::flatten_text($bv)),
				'after'  => self::snip(self::flatten_text($pv)),
			];
		}
		return $out;
	}

	/* ============================================================
	 * Context: what the guardian knows about the business + SEO
	 * ============================================================ */

	/** Curated company profile from site config (client-agnostic). */
	public static function company_profile(): array {
		if (!function_exists('aq_site')) { return []; }
		$loc = trim((string) (aq_site('address.locality') ?: '') . ', ' . (string) (aq_site('address.region') ?: ''), ', ');
		$services = [];
		$mega = aq_site('megamenu');
		if (is_array($mega)) {
			foreach ($mega as $panel) {
				foreach ((array) ($panel['groups'] ?? []) as $g) {
					foreach ((array) ($g['links'] ?? []) as $lnk) {
						if (!empty($lnk['title'])) { $services[] = (string) $lnk['title']; }
					}
				}
				foreach ((array) ($panel['items'] ?? []) as $it) {
					if (!empty($it['label'])) { $services[] = (string) $it['label']; }
				}
			}
		}
		$towns = [];
		foreach ((array) (aq_site('towns') ?: []) as $t) {
			if (!empty($t['name'])) { $towns[] = (string) $t['name']; }
		}
		return array_filter([
			'name'        => (string) (aq_site('name') ?: ''),
			'legalName'   => (string) (aq_site('legalName') ?: ''),
			'tagline'     => (string) (aq_site('tagline') ?: ''),
			'description' => (string) (aq_site('description') ?: ''),
			'industry'    => (string) (aq_site('industry') ?: ''),
			'url'         => (string) (aq_site('url') ?: ''),
			'founded'     => (string) (aq_site('founded') ?: ''),
			'location'    => $loc,
			'regions'     => array_values(array_filter((array) (aq_site('regions') ?: []))),
			'towns'       => array_slice(array_values(array_unique($towns)), 0, 20),
			'services'    => array_slice(array_values(array_unique($services)), 0, 30),
		]);
	}

	/** SEO context: this page's meta + the site's tracked keywords/rankings + brief. */
	public static function seo_context(int $id): array {
		$meta = [];
		if (function_exists('get_field')) {
			$meta = array_filter([
				'title'       => (string) get_field('seo_title', $id),
				'description' => (string) get_field('seo_description', $id),
				'canonical'   => (string) get_field('seo_canonical', $id),
				'noindex'     => (bool) get_field('seo_noindex', $id),
			], function ($v) { return $v !== '' && $v !== false; });
		}
		$agent    = self::settings();
		$keywords = isset($agent['keywords']) && is_array($agent['keywords'])
			? array_values(array_filter(array_map('strval', $agent['keywords']))) : [];
		$history  = get_option('aq_seo_agent_history', []);
		$snapshot = is_array($history) && $history ? end($history) : null;

		return [
			'path'            => (string) (parse_url((string) get_permalink($id), PHP_URL_PATH) ?: '/'),
			'title'           => (string) get_the_title($id),
			'isFrontPage'     => ((int) get_option('page_on_front') === $id),
			'meta'            => $meta,
			'trackedKeywords' => $keywords,
			'rankingSnapshot' => is_array($snapshot)
				? ['ranked_count' => $snapshot['ranked_count'] ?? null, 'tracked' => $snapshot['tracked'] ?? []]
				: null,
			'brief'           => isset($agent['review_brief']) ? (string) $agent['review_brief'] : '',
		];
	}

	/* ============================================================
	 * The review: AI first, deterministic rules as fallback
	 * ============================================================ */

	/** @return array{overall:array,byId:array,usedAi:bool} */
	private static function run_review(array $diff, array $company, array $seo, string $beforeText, string $afterText): array {
		if (class_exists('AQ_Claude') && AQ_Claude::is_ready()) {
			$ai = self::ai_review($diff, $company, $seo, $beforeText, $afterText);
			if (is_array($ai)) {
				return ['overall' => $ai['overall'], 'byId' => $ai['byId'], 'usedAi' => true];
			}
		}
		$rules = self::rules_review($diff, $seo, $afterText);
		return ['overall' => $rules['overall'], 'byId' => $rules['byId'], 'usedAi' => false];
	}

	/** Ask Claude for structured per-change verdicts. Returns null on failure. */
	private static function ai_review(array $diff, array $company, array $seo, string $beforeText, string $afterText): ?array {
		$slice = array_slice($diff, 0, self::MAX_AI_CHANGES);
		$payloadChanges = [];
		foreach ($slice as $c) {
			$payloadChanges[] = [
				'id'     => $c['id'],
				'kind'   => $c['kind'],
				'label'  => $c['label'],
				'before' => $c['before'],
				'after'  => $c['after'],
			];
		}
		$context = wp_json_encode([
			'company'         => $company,
			'seo'             => $seo,
			'changes'         => $payloadChanges,
			'pageTextBefore'  => self::snip($beforeText, 4000),
			'pageTextAfter'   => self::snip($afterText, 4000),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		$system = implode("\n", [
			'You are the SEO & brand guardian for a LOCAL BUSINESS website built on the AutoForge platform.',
			'A NON-TECHNICAL user has edited a page in the visual builder. Review each change BEFORE it goes live and flag anything that could hurt the site\'s Google / AI-search visibility or drift from the brand.',
			'You are given: the company profile, this page\'s SEO context (tracked keywords, current rankings, meta), and a list of changes (before → after).',
			'',
			'Rate EVERY change with a severity:',
			'- "high": likely to hurt SEO or brand — removing/emptying a main heading (H1/H2), deleting a section or paragraph containing a tracked keyword or key selling content, removing internal links or CTAs, adding noindex, changing the canonical URL, emptying the meta title/description, replacing specific descriptive copy with vague or empty text, removing services/schema, or clearly off-brand/unprofessional wording.',
			'- "caution": worth a look but not clearly harmful — notable rewrites, tone/voice drift, substantially shortening content, changing a CTA label, wording that weakens a target keyword.',
			'- "ok": safe — typo/grammar fixes, minor wording, adding content, image swaps, reordering.',
			'',
			'For EACH change return: a short "title" (what changed, plain words), a one-sentence "reason" (no jargon; the reader is not technical), and a "suggestion" (how to keep the SEO/brand value; empty if none needed). Name the specific tracked keyword when one is affected.',
			'Also return an "overall" { summary, recommendation } (1–2 sentences each).',
			'Use ONLY the data provided — never invent rankings or numbers. Respond by calling the report_seo_review tool ONLY.',
		]);

		$tool = AQ_Claude::tool(
			'report_seo_review',
			'Return the SEO/brand review: an overall verdict plus one entry per change (keyed by the change id).',
			[
				'overall' => [
					'type'       => 'object',
					'properties' => [
						'summary'        => ['type' => 'string', 'description' => 'One or two plain-English sentences summarizing the edit.'],
						'recommendation' => ['type' => 'string', 'description' => 'What you recommend the user do (publish, revise, etc.).'],
					],
					'required'   => ['summary'],
				],
				'changes' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'         => ['type' => 'string', 'description' => 'The change id being rated (must match an input change id).'],
							'severity'   => ['type' => 'string', 'enum' => ['ok', 'caution', 'high']],
							'title'      => ['type' => 'string'],
							'reason'     => ['type' => 'string'],
							'suggestion' => ['type' => 'string'],
						],
						'required'   => ['id', 'severity', 'reason'],
					],
				],
			],
			['overall', 'changes']
		);

		$res = AQ_Claude::message([
			'model'       => self::model(),
			'max_tokens'  => 2200,
			'system'      => $system,
			'messages'    => [['role' => 'user', 'content' => "Review this edit and return the tool call.\n\n" . $context]],
			'tools'       => [$tool],
			'tool_choice' => ['type' => 'tool', 'name' => 'report_seo_review'],
			'timeout'     => 60,
		]);
		if (is_wp_error($res) || empty($res['tool_input']) || !is_array($res['tool_input'])) {
			return null;
		}

		$input = $res['tool_input'];
		$byId  = [];
		foreach ((array) ($input['changes'] ?? []) as $row) {
			if (!is_array($row) || empty($row['id'])) { continue; }
			$byId[(string) $row['id']] = [
				'severity'   => self::norm_severity((string) ($row['severity'] ?? 'caution')),
				'title'      => (string) ($row['title'] ?? ''),
				'reason'     => (string) ($row['reason'] ?? ''),
				'suggestion' => (string) ($row['suggestion'] ?? ''),
			];
		}
		// Any change the model skipped (or beyond the AI slice) defaults to caution.
		foreach ($diff as $c) {
			if (!isset($byId[$c['id']])) {
				$byId[$c['id']] = ['severity' => 'caution', 'title' => $c['label'], 'reason' => 'Changed — please review.', 'suggestion' => ''];
			}
		}
		$overall = is_array($input['overall'] ?? null) ? $input['overall'] : [];
		return [
			'overall' => [
				'summary'        => (string) ($overall['summary'] ?? 'Reviewed your changes.'),
				'recommendation' => (string) ($overall['recommendation'] ?? ''),
			],
			'byId' => $byId,
		];
	}

	/**
	 * Deterministic fallback reviewer — used when no Claude key is set or the API
	 * fails, so the gate always produces verdicts. Conservative: flags the clear
	 * high-risk patterns and leaves the rest as caution/ok.
	 */
	private static function rules_review(array $diff, array $seo, string $afterText): array {
		$kw        = array_map('strtolower', (array) ($seo['trackedKeywords'] ?? []));
		$afterLow  = strtolower($afterText);
		$headingFs = ['heading', 'subheading', 'eyebrow', 'title'];
		$byId      = [];

		$lostKeyword = function (string $before) use ($kw, $afterLow): string {
			$b = strtolower($before);
			foreach ($kw as $k) {
				if ($k !== '' && strpos($b, $k) !== false && strpos($afterLow, $k) === false) {
					return $k;
				}
			}
			return '';
		};

		foreach ($diff as $c) {
			$sev = 'ok'; $reason = ''; $sug = '';
			switch ($c['kind']) {
				case 'section-removed':
					$lost = $lostKeyword((string) ($c['before'] ?? ''));
					if ($lost !== '') {
						$sev = 'high';
						$reason = 'This section mentioned your target search term "' . $lost . '", which no longer appears on the page.';
						$sug = 'Keep this section, or make sure "' . $lost . '" still appears elsewhere on the page.';
					} elseif (trim((string) ($c['before'] ?? '')) !== '') {
						$sev = 'high';
						$reason = 'Removing a whole section deletes content Google and visitors may rely on.';
						$sug = 'Confirm this content isn\'t needed for search or conversions before removing it.';
					} else {
						$sev = 'caution';
						$reason = 'A section was removed.';
					}
					break;
				case 'field-changed':
					$field  = (string) ($c['field'] ?? '');
					$before = (string) ($c['before'] ?? '');
					$after  = (string) ($c['after'] ?? '');
					$lost   = $lostKeyword($before);
					if ($field === 'noindex' && $after) {
						$sev = 'high'; $reason = 'This hides the page from Google entirely.'; $sug = 'Only enable "noindex" if you truly want this page out of search.';
					} elseif ($field === 'canonical' && $before !== '' && $after !== $before) {
						$sev = 'high'; $reason = 'Changing the canonical URL tells Google a different page is the original.'; $sug = 'Leave the canonical alone unless you know this page duplicates another.';
					} elseif ($lost !== '') {
						$sev = 'high'; $reason = 'Your target search term "' . $lost . '" was removed from this text.'; $sug = 'Keep "' . $lost . '" in the wording where it reads naturally.';
					} elseif (in_array($field, $headingFs, true) && trim($before) !== '' && trim($after) === '') {
						$sev = 'high'; $reason = 'A heading was emptied — headings are important for SEO and readability.'; $sug = 'Give the heading clear, keyword-relevant text.';
					} elseif ($before !== '' && strlen($after) < strlen($before) * 0.5) {
						$sev = 'caution'; $reason = 'This text was shortened by more than half — you may be dropping useful content.'; $sug = 'Check the shorter version still covers the key points and terms.';
					} else {
						$sev = 'ok'; $reason = 'Wording updated.';
					}
					break;
				case 'section-added':
					$after = strtolower((string) ($c['after'] ?? ''));
					if (($c['sectionType'] ?? '') === 'raw_html' || ($c['sectionType'] ?? '') === 'rich_section') {
						$sev = (strpos($after, '<script') !== false) ? 'high' : 'caution';
						$reason = $sev === 'high' ? 'This adds custom code (a script) to the page.' : 'A raw/advanced HTML block was added — review it renders correctly.';
					} else {
						$sev = 'ok'; $reason = 'New content section added.';
					}
					break;
				case 'section-reordered':
					$sev = 'ok'; $reason = 'Section order changed — rarely affects SEO.';
					break;
			}
			$byId[$c['id']] = ['severity' => $sev, 'title' => $c['label'], 'reason' => $reason, 'suggestion' => $sug];
		}

		$high = 0; $caution = 0;
		foreach ($byId as $v) { $v['severity'] === 'high' ? $high++ : ($v['severity'] === 'caution' ? $caution++ : null); }
		$summary = $high ? ($high . ' change(s) look risky for SEO and need your confirmation.')
			: ($caution ? ($caution . ' change(s) are worth a quick look.') : 'These changes look safe.');
		return [
			'overall' => ['summary' => $summary, 'recommendation' => $high ? 'Review the high-risk items before publishing.' : 'You can publish these changes.'],
			'byId'    => $byId,
		];
	}

	/* ============================================================
	 * Reconstruct the final section set from decisions (authoritative)
	 * ============================================================ */

	/**
	 * Build the section set to write: start from PROPOSED order, drop denied
	 * additions, revert denied field changes to their base value, and re-insert
	 * denied removals near their original neighbours. Never trusts a client set.
	 */
	private static function reconstruct(array $base, array $proposed, array $diff, array $decisions): array {
		[$baseByUid, $baseOrder] = self::index_by_uid($base, 'b');

		$deniedAdd = []; $deniedRemove = []; $reverts = [];
		foreach ($diff as $c) {
			$allow = (($decisions[$c['id']] ?? 'deny') === 'allow');
			if ($allow) { continue; }
			if ($c['kind'] === 'section-added')   { $deniedAdd[$c['uid']] = true; }
			if ($c['kind'] === 'section-removed') { $deniedRemove[$c['uid']] = true; }
			if ($c['kind'] === 'field-changed')   { $reverts[$c['uid']][(string) $c['field']] = true; }
		}

		// Proposed sections, minus denied additions, with denied field-changes reverted.
		$final = [];
		[$propByUid, $propOrder] = self::index_by_uid($proposed, 'p');
		foreach ($propOrder as $uid) {
			if (isset($deniedAdd[$uid])) { continue; }
			$s = $propByUid[$uid];
			if (!empty($reverts[$uid]) && isset($baseByUid[$uid])) {
				foreach (array_keys($reverts[$uid]) as $f) {
					if (array_key_exists($f, $baseByUid[$uid])) { $s[$f] = $baseByUid[$uid][$f]; }
					else { unset($s[$f]); }
				}
			}
			$final[] = ['__uid' => $uid, 'row' => $s];
		}

		// Re-insert denied removals near their base neighbours.
		if ($deniedRemove) {
			foreach ($baseOrder as $bi => $uid) {
				if (!isset($deniedRemove[$uid])) { continue; }
				$insertAt = count($final);
				for ($j = $bi + 1; $j < count($baseOrder); $j++) {
					$pos = self::pos_of($final, $baseOrder[$j]);
					if ($pos >= 0) { $insertAt = $pos; break; }
				}
				array_splice($final, $insertAt, 0, [['__uid' => $uid, 'row' => $baseByUid[$uid]]]);
			}
		}

		return array_map(function ($e) { return $e['row']; }, $final);
	}

	private static function pos_of(array $list, string $uid): int {
		foreach ($list as $i => $e) {
			if (($e['__uid'] ?? null) === $uid) { return $i; }
		}
		return -1;
	}

	/* ============================================================
	 * Persistence + sanitize
	 * ============================================================ */

	private static function store_review(int $id, array $base, array $proposed, array $diff, array $byId, string $liveHash): string {
		// Random, unguessable id (no Math.random on the PHP side needed).
		$reviewId = strtolower(wp_generate_password(20, false, false));
		set_transient(self::TRANSIENT_PREFIX . $reviewId, [
			'user_id'        => get_current_user_id(),
			'post_id'        => $id,
			'base'           => $base,
			'proposed'       => $proposed,
			'diff'           => $diff,
			'verdicts'       => $byId,
			'base_live_hash' => $liveHash,
			'created'        => time(),
		], self::TRANSIENT_TTL);
		return $reviewId;
	}

	/** Live sections in the canonical editor shape (type-keyed, no _uid). */
	private static function live_sections(int $id): array {
		return class_exists('AQ_Content_Sync') ? AQ_Content_Sync::read_sections($id) : [];
	}

	/** Keep only known layouts + drop transient client keys (mirrors AQ_Editor::rest_save). */
	private static function sanitize_sections(array $sections): array {
		$allowed = class_exists('AQ_Editor') ? array_keys(AQ_Editor::field_schema()) : [];
		$clean   = [];
		foreach ($sections as $s) {
			if (!is_array($s) || empty($s['type'])) { continue; }
			if ($allowed && !in_array($s['type'], $allowed, true)) { continue; }
			foreach (array_keys($s) as $k) {
				if (is_string($k) && isset($k[0]) && $k[0] === '_') { unset($s[$k]); }
			}
			$clean[] = $s;
		}
		return $clean;
	}

	/* ============================================================
	 * Small helpers
	 * ============================================================ */

	private static function norm_severity(string $s): string {
		$s = strtolower(trim($s));
		return in_array($s, ['ok', 'caution', 'high'], true) ? $s : 'caution';
	}

	private static function counts(array $changes): array {
		$out = ['ok' => 0, 'caution' => 0, 'high' => 0];
		foreach ($changes as $c) {
			$sev = self::norm_severity($c['severity'] ?? 'caution');
			$out[$sev]++;
		}
		return $out;
	}

	private static function layout_label(string $type): string {
		$labels = class_exists('AQ_Editor') ? AQ_Editor::layout_labels() : [];
		return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
	}

	private static function field_label(string $type, string $field): string {
		if (class_exists('AQ_Editor')) {
			$schema = AQ_Editor::field_schema();
			foreach ((array) ($schema[$type]['fields'] ?? []) as $f) {
				if (($f['name'] ?? '') === $field) { return (string) ($f['label'] ?? $field); }
			}
		}
		return ucwords(str_replace('_', ' ', $field));
	}

	/** A short human/AI summary of a section: its main heading + a text snippet. */
	private static function section_summary(array $s): string {
		$bits = [];
		foreach (['heading', 'subheading', 'title', 'eyebrow'] as $k) {
			if (!empty($s[$k]) && is_string($s[$k])) { $bits[] = $s[$k]; }
		}
		$text = self::flatten_text($s);
		if ($text !== '') { $bits[] = $text; }
		return self::snip(trim(implode(' — ', array_unique(array_filter($bits)))));
	}

	/** Recursively collect string values (skip type/v/_uid) into one text blob. */
	private static function flatten_text($v): string {
		if (is_string($v)) {
			return trim(wp_strip_all_tags($v));
		}
		if (!is_array($v)) {
			return '';
		}
		$out = [];
		foreach ($v as $k => $vv) {
			if (is_string($k) && ($k === 'type' || $k === 'v' || (isset($k[0]) && $k[0] === '_'))) {
				continue;
			}
			$t = self::flatten_text($vv);
			if ($t !== '') { $out[] = $t; }
		}
		return trim(implode(' · ', $out));
	}

	private static function text_of_page(array $sections): string {
		$out = [];
		foreach ($sections as $s) {
			if (is_array($s)) { $out[] = self::flatten_text($s); }
		}
		return trim(implode("\n", array_filter($out)));
	}

	private static function snip(string $s, int $max = 500): string {
		$s = trim(preg_replace('/\s+/', ' ', $s));
		if (function_exists('mb_strlen')) {
			return mb_strlen($s) > $max ? (mb_substr($s, 0, $max - 1) . '…') : $s;
		}
		return strlen($s) > $max ? (substr($s, 0, $max - 1) . '…') : $s;
	}

	/** Canonical, order-stable value for equality checks + hashing (drops _keys). */
	private static function canon_value($v) {
		if (is_array($v)) {
			$isList = array_keys($v) === range(0, count($v) - 1);
			$out = [];
			foreach ($v as $k => $vv) {
				if (is_string($k) && isset($k[0]) && $k[0] === '_') { continue; }
				$out[$k] = self::canon_value($vv);
			}
			if (!$isList) { ksort($out); }
			return $out;
		}
		return $v;
	}

	private static function canon(array $sections): string {
		$norm = [];
		foreach ($sections as $s) {
			if (is_array($s)) { $norm[] = self::canon_value($s); }
		}
		return md5((string) wp_json_encode($norm));
	}
}
