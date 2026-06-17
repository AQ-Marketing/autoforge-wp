<?php
/**
 * AutoForge — per-user Page Folders (organizes the Pages list).
 *
 * A PURELY ORGANIZATIONAL overlay on admin.php?page=aq-pages: each logged-in
 * user keeps their OWN folders and their OWN page→folder assignments in user
 * meta. Creating / renaming / deleting a folder, or moving pages between
 * folders, never touches the pages themselves — it only changes how THIS user
 * sees the list. Deleting a folder simply drops this user's assignments to it
 * (the pages become "Unfiled").
 *
 * Storage (per user):
 *   aq_page_folders     => [ {id, name}, … ]   ordered folder list
 *   aq_page_folder_map  => { "<pageId>": "<folderId>" }   one folder per page
 *
 * REST: POST aq/v1/page-folders {op: create|rename|delete|assign|reorder, …}
 * Gated on the Pages capability + the WP REST nonce; always scoped to the
 * current user. SQLite-safe (user-meta get/update only). Vanilla JS, no build.
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Page_Folders {

	const CAP          = 'manage_options';
	const META_FOLDERS = 'aq_page_folders';
	const META_MAP     = 'aq_page_folder_map';

	/* ============================ register ============================ */

	public static function register(): void {
		add_action('rest_api_init', [__CLASS__, 'rest_routes']);
	}

	public static function rest_routes(): void {
		register_rest_route('aq/v1', '/page-folders', [
			'methods'             => 'POST',
			'permission_callback' => static fn() => current_user_can(self::CAP),
			'callback'            => [__CLASS__, 'rest_save'],
		]);
	}

	/* ============================ data ============================ */

	private static function uid(): int {
		return get_current_user_id();
	}

	/** This user's folders, normalized to [{id, name}, …]. */
	public static function folders(): array {
		$raw = get_user_meta(self::uid(), self::META_FOLDERS, true);
		$out = [];
		if (is_array($raw)) {
			foreach ($raw as $row) {
				if (is_array($row) && !empty($row['id']) && isset($row['name'])) {
					$out[] = ['id' => (string) $row['id'], 'name' => (string) $row['name']];
				}
			}
		}
		return $out;
	}

	/** This user's {pageId(string) => folderId(string)} assignments. */
	public static function map(): array {
		$raw = get_user_meta(self::uid(), self::META_MAP, true);
		$out = [];
		if (is_array($raw)) {
			foreach ($raw as $pid => $fid) {
				$out[(string) $pid] = (string) $fid;
			}
		}
		return $out;
	}

	private static function save_folders(array $folders): void {
		update_user_meta(self::uid(), self::META_FOLDERS, array_values($folders));
	}

	private static function save_map(array $map): void {
		update_user_meta(self::uid(), self::META_MAP, $map);
	}

	private static function new_id(): string {
		return 'f' . substr(md5(uniqid('', true)), 0, 12);
	}

	private static function has_folder(array $folders, string $id): bool {
		foreach ($folders as $f) {
			if ($f['id'] === $id) {
				return true;
			}
		}
		return false;
	}

	/* ============================ REST save ============================ */

	public static function rest_save(WP_REST_Request $req) {
		$body = $req->get_json_params();
		if (!is_array($body)) {
			$body = $req->get_params();
		}
		$op      = isset($body['op']) ? (string) $body['op'] : '';
		$folders = self::folders();
		$map     = self::map();

		switch ($op) {
			case 'create':
				$name = trim(sanitize_text_field((string) ($body['name'] ?? '')));
				if ($name === '') {
					return new WP_Error('aq_name', 'Folder name is required.', ['status' => 400]);
				}
				$folders[] = ['id' => self::new_id(), 'name' => $name];
				self::save_folders($folders);
				break;

			case 'rename':
				$id   = (string) ($body['id'] ?? '');
				$name = trim(sanitize_text_field((string) ($body['name'] ?? '')));
				if ($id === '' || $name === '') {
					return new WP_Error('aq_name', 'Folder id and name are required.', ['status' => 400]);
				}
				foreach ($folders as &$f) {
					if ($f['id'] === $id) {
						$f['name'] = $name;
					}
				}
				unset($f);
				self::save_folders($folders);
				break;

			case 'delete':
				$id      = (string) ($body['id'] ?? '');
				$folders = array_values(array_filter($folders, static fn($f) => $f['id'] !== $id));
				// Drop this user's assignments to the deleted folder — pages untouched.
				foreach ($map as $pid => $fid) {
					if ($fid === $id) {
						unset($map[$pid]);
					}
				}
				self::save_folders($folders);
				self::save_map($map);
				break;

			case 'assign':
				$pid = (int) ($body['pageId'] ?? 0);
				$fid = (string) ($body['folderId'] ?? '');
				if ($pid <= 0) {
					return new WP_Error('aq_page', 'A pageId is required.', ['status' => 400]);
				}
				if ($fid === '') {
					unset($map[(string) $pid]);          // → Unfiled
				} elseif (self::has_folder($folders, $fid)) {
					$map[(string) $pid] = $fid;
				} else {
					return new WP_Error('aq_folder', 'Unknown folder.', ['status' => 400]);
				}
				self::save_map($map);
				break;

			case 'reorder':
				$ids  = array_map('strval', (array) ($body['ids'] ?? []));
				$byId = [];
				foreach ($folders as $f) {
					$byId[$f['id']] = $f;
				}
				$new = [];
				foreach ($ids as $id) {
					if (isset($byId[$id])) {
						$new[] = $byId[$id];
						unset($byId[$id]);
					}
				}
				foreach ($byId as $f) {
					$new[] = $f; // any not listed keep their order at the end
				}
				self::save_folders($new);
				break;

			default:
				return new WP_Error('aq_op', 'Unknown operation.', ['status' => 400]);
		}

		return rest_ensure_response(['ok' => true, 'folders' => self::folders(), 'map' => self::map()]);
	}

	/* ============================ render ============================ */

	/** Per-row folder picker (JS fills the options + selects the current value). */
	public static function row_select_html(int $pageId, string $current = ''): string {
		return '<select class="aq-folder-select" data-page="' . $pageId
			. '" data-current="' . esc_attr($current) . '" aria-label="Move page to folder"></select>';
	}

	/** The folder sidebar shell (the list itself is rendered by JS from the data). */
	public static function sidebar_html(): string {
		return '<aside class="aq-folders" aria-label="Page folders">'
			. '<div class="aq-folders__head"><span>Folders</span>'
			. '<button type="button" class="aq-iconbtn" id="aq-folder-new" title="New folder">+</button></div>'
			. '<ul class="aq-folders__list" id="aq-folders-list"></ul>'
			. '<p class="aq-folders__hint">Folders are yours only — organizing them never changes or deletes the pages.</p>'
			. '</aside>';
	}

	public static function styles(): void {
		?>
		<style>
			.aq-hub .aq-pages-layout { display:flex; gap:18px; align-items:flex-start; }
			.aq-hub .aq-pages-main { flex:1 1 auto; min-width:0; }
			.aq-hub .aq-folders { flex:0 0 224px; border:1px solid #e6e8eb; border-radius:10px; padding:10px; background:#fbfbfc; }
			.aq-hub .aq-folders__head { display:flex; align-items:center; justify-content:space-between; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#5b6471; padding:4px 6px 8px; }
			.aq-hub .aq-folders__list { list-style:none; margin:0; padding:0; }
			.aq-hub .aq-folder { display:flex; align-items:center; gap:6px; padding:7px 8px; border-radius:8px; cursor:pointer; font-size:13px; color:#0d1014; }
			.aq-hub .aq-folder:hover { background:#f0f2f5; }
			.aq-hub .aq-folder.is-active { background:#c8102e; color:#fff; }
			.aq-hub .aq-folder.is-active .aq-folder__count { background:rgba(255,255,255,.25); color:#fff; }
			.aq-hub .aq-folder.is-active .aq-iconbtn { color:#fff; border-color:rgba(255,255,255,.5); background:transparent; }
			.aq-hub .aq-folder__name { flex:1 1 auto; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
			.aq-hub .aq-folder__count { flex:0 0 auto; font-size:11px; font-weight:700; background:#e6e8eb; color:#5b6471; border-radius:20px; padding:1px 8px; }
			.aq-hub .aq-folder__tools { display:none; gap:4px; flex:0 0 auto; }
			.aq-hub .aq-folder:hover .aq-folder__tools, .aq-hub .aq-folder.is-active .aq-folder__tools { display:flex; }
			.aq-hub .aq-folder .aq-iconbtn { width:22px; height:22px; font-size:12px; }
			.aq-hub .aq-folders__hint { font-size:11px; color:#8a94a1; margin:12px 6px 2px; line-height:1.5; }
			.aq-hub .aq-folder-select { font-size:12px; padding:4px 6px; border:1px solid #c9cfd6; border-radius:7px; background:#fff; color:#0d1014; max-width:150px; }
			.aq-hub .aq-folder-select:focus { outline:0; border-color:#c8102e; box-shadow:0 0 0 2px rgba(200,16,46,.18); }
			.aq-hub .aq-iconbtn { background:#fff; border:1px solid #c9cfd6; color:#15191f; width:24px; height:24px; border-radius:6px; cursor:pointer; font-size:13px; line-height:1; padding:0; }
			.aq-hub .aq-iconbtn:hover { background:#f4f6fc; }
			@media (max-width:900px){ .aq-hub .aq-pages-layout { flex-direction:column; } .aq-hub .aq-folders { flex-basis:auto; width:100%; box-sizing:border-box; } }
		</style>
		<?php
	}

	/** Combined sidebar + per-row assignment + search filter behaviour. */
	public static function script(): void {
		$data = ['folders' => self::folders(), 'map' => self::map()];
		?>
		<script>
		(function () {
			var DATA  = <?php echo wp_json_encode($data); ?>;
			var REST  = <?php echo wp_json_encode(esc_url_raw(rest_url('aq/v1/page-folders'))); ?>;
			var NONCE = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;

			var table = document.getElementById('aq-pages-table');
			var list  = document.getElementById('aq-folders-list');
			if (!table || !list) { return; }

			var input   = document.getElementById('aq-page-search');
			var empty   = document.getElementById('aq-pages-empty');
			var heading = document.querySelector('.aq-pages-main h2') || document.querySelector('.aq-hub h2');
			var rows    = Array.prototype.slice.call(table.tBodies[0].rows);
			var total   = rows.length;
			var active  = 'all'; // all | unfiled | <folderId>

			function $(s, c) { return (c || document).querySelector(s); }
			function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
			function folderOf(row) { return DATA.map[row.getAttribute('data-aq-page')] || ''; }

			function counts() {
				var c = { all: total, unfiled: 0, byId: {} };
				DATA.folders.forEach(function (f) { c.byId[f.id] = 0; });
				rows.forEach(function (r) {
					var fid = folderOf(r);
					if (!fid || !(fid in c.byId)) { c.unfiled++; }
					else { c.byId[fid]++; }
				});
				return c;
			}

			function renderSidebar() {
				var c = counts();
				list.innerHTML = '';
				function item(key, name, count, folder) {
					var li = el('li', 'aq-folder' + (active === key ? ' is-active' : ''));
					li.setAttribute('data-folder', key);
					li.appendChild(el('span', 'aq-folder__name', name));
					if (folder) {
						var tools = el('span', 'aq-folder__tools');
						var ren = el('button', 'aq-iconbtn', '✎'); ren.title = 'Rename'; ren.type = 'button';
						var del = el('button', 'aq-iconbtn', '×'); del.title = 'Delete'; del.type = 'button';
						ren.addEventListener('click', function (e) { e.stopPropagation(); rename(folder); });
						del.addEventListener('click', function (e) { e.stopPropagation(); remove(folder); });
						tools.appendChild(ren); tools.appendChild(del);
						li.appendChild(tools);
					}
					li.appendChild(el('span', 'aq-folder__count', String(count)));
					li.addEventListener('click', function () { active = key; renderSidebar(); applyFilter(); });
					list.appendChild(li);
				}
				item('all', 'All pages', c.all, null);
				item('unfiled', 'Unfiled', c.unfiled, null);
				DATA.folders.forEach(function (f) { item(f.id, f.name, c.byId[f.id] || 0, f); });
			}

			function renderSelects() {
				Array.prototype.slice.call(table.querySelectorAll('.aq-folder-select')).forEach(function (sel) {
					var page = sel.getAttribute('data-page');
					var cur  = DATA.map[page] || '';
					sel.innerHTML = '';
					sel.appendChild(new Option('— Unfiled —', ''));
					DATA.folders.forEach(function (f) { sel.appendChild(new Option(f.name, f.id)); });
					sel.value = cur;
				});
			}

			function applyFilter() {
				var q = (input && input.value.trim().toLowerCase()) || '';
				var shown = 0;
				rows.forEach(function (r) {
					var fid = folderOf(r);
					var inFolder = active === 'all' || (active === 'unfiled' ? !fid : fid === active);
					var inSearch = !q || (r.getAttribute('data-aq-search') || '').indexOf(q) !== -1;
					var hit = inFolder && inSearch;
					r.style.display = hit ? '' : 'none';
					if (hit) { shown++; }
				});
				if (empty) { empty.style.display = shown ? 'none' : 'block'; }
				if (heading) {
					var label = active === 'all' ? 'pages' : 'in folder';
					heading.textContent = (q || active !== 'all') ? (shown + ' ' + label) : (total + ' pages');
				}
			}

			function post(payload) {
				return fetch(REST, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
					body: JSON.stringify(payload)
				}).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
			}

			function refresh(res) {
				if (res && res.ok && res.body && res.body.ok) {
					DATA.folders = res.body.folders || [];
					DATA.map = res.body.map || {};
					if (active !== 'all' && active !== 'unfiled' && !DATA.folders.some(function (f) { return f.id === active; })) { active = 'all'; }
					renderSidebar(); renderSelects(); applyFilter();
				} else {
					window.alert('Could not save folder change. Please try again.');
				}
			}

			function create() {
				var name = window.prompt('New folder name:');
				if (name && name.trim()) { post({ op: 'create', name: name.trim() }).then(refresh); }
			}
			function rename(f) {
				var name = window.prompt('Rename folder:', f.name);
				if (name && name.trim() && name.trim() !== f.name) { post({ op: 'rename', id: f.id, name: name.trim() }).then(refresh); }
			}
			function remove(f) {
				if (window.confirm('Delete the folder "' + f.name + '"? Your pages are not affected — they just become Unfiled.')) {
					post({ op: 'delete', id: f.id }).then(refresh);
				}
			}

			table.addEventListener('change', function (e) {
				var sel = e.target;
				if (!sel.classList || !sel.classList.contains('aq-folder-select')) { return; }
				var page = sel.getAttribute('data-page');
				var fid  = sel.value;
				if (fid) { DATA.map[page] = fid; } else { delete DATA.map[page]; }
				post({ op: 'assign', pageId: parseInt(page, 10), folderId: fid }).then(function (res) {
					if (!res.ok || !res.body || !res.body.ok) { refresh(res); return; }
					DATA.map = res.body.map || DATA.map;
					renderSidebar(); applyFilter();
				});
			});

			var addBtn = document.getElementById('aq-folder-new');
			if (addBtn) { addBtn.addEventListener('click', create); }
			if (input) {
				input.addEventListener('input', applyFilter);
				input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { input.value = ''; applyFilter(); } });
			}

			renderSidebar();
			renderSelects();
			applyFilter();
		})();
		</script>
		<?php
	}
}
