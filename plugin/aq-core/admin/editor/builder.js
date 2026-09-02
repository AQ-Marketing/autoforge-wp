/**
 * AQ Editor — builder shell.
 *
 * Full-screen, three-pane structured page builder mounted at
 * AutoForge → Pages → Open editor. Left: section structure (add / reorder /
 * delete). Center: the REAL page in an iframe canvas (click a section to select
 * it). Right: an inspector that edits the selected section's defined fields.
 * Save persists through aq/v1/editor/save and reloads the canvas to show the
 * true rendered result. No arbitrary CSS — structured fields only.
 */
(function () {
	'use strict';

	var CFG = window.AQ_EDITOR;
	if (!CFG) { return; }
	var ORIGIN = window.location.origin;
	// GATED = the SEO review gate applies to this user (review feature on AND not an
	// agency admin). Gated users get "Review & Publish"; bypass users keep "Save".
	var GATED = !!CFG.reviewEnabled && !CFG.canBypass;

	var state = { sections: [], base: [], selected: -1, dirty: false, device: 'desktop', rehighlight: -1, images: {}, galleryImages: {}, review: null, decisions: {}, confirmed: {}, hist: { stack: [], ptr: -1, cap: 50 }, previewTimer: null, histTimer: null, gallerySel: null, gallerySelUid: null, galleryBulkCat: '' };
	var HIST = (typeof window !== 'undefined' && window.AQHistory) ? window.AQHistory : null;
	var uid = 0;
	var els = {};
	function clone(x) { return JSON.parse(JSON.stringify(x)); }

	/* ---------------- helpers ---------------- */
	function ce(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text != null) { e.textContent = text; }
		return e;
	}
	function api(path, opts) {
		opts = opts || {};
		return fetch(CFG.restRoot + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: opts.body ? JSON.stringify(opts.body) : undefined
		}).then(function (r) { return r.json(); });
	}
	function schemaFor(type) { return (CFG.schema && CFG.schema[type]) ? CFG.schema[type].fields : []; }
	function labelFor(type) {
		if (CFG.labels && CFG.labels[type]) { return CFG.labels[type]; }
		return humanizeSlug(type, true);
	}
	// Turn a machine slug/key into a human label. With stripPrefix, drop a leading
	// "<prefix>_" segment (pjp_trust_strip -> "Trust Strip") — every bespoke section
	// type is named <clientPrefix>_<name>, so the structure panel and add-section
	// menu read cleanly on ANY site with zero per-site config.
	function humanizeSlug(slug, stripPrefix) {
		var s = String(slug || '');
		if (stripPrefix && s.indexOf('_') > -1) { s = s.slice(s.indexOf('_') + 1); }
		s = s.replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
		if (!s) { return String(slug || ''); }
		return s.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}
	function looksLikeImage(key, v) {
		if (v && typeof v === 'object' && !Array.isArray(v) && (v.url || v.id)) { return true; }
		if (typeof v === 'string' && /\.(jpe?g|png|webp|gif|svg|avif)(\?|#|$)/i.test(v)) { return true; }
		// Key hint ONLY when the value is empty or a bare attachment id — so layout
		// keys like image_side / image_position (which hold "left"/"top", not files)
		// are never mistaken for an image picker.
		var keyIsImage = key === 'image' || /(^|_)(image|img|photo|logo|thumbnail|avatar|picture)$/i.test(key);
		if (keyIsImage && (v === null || v === '' || typeof v === 'undefined' || typeof v === 'number')) { return true; }
		return false;
	}
	// Best-effort editable fields for a section the engine has NO schema for
	// (bespoke <prefix>_* layouts). Inferred from the section's OWN live data, so
	// images and repeaters are captured — not just text. A section can still ship a
	// precise schema via the aq_editor_field_schema filter, which overrides this.
	function inferFields(obj) {
		var out = [];
		if (!obj || typeof obj !== 'object') { return out; }
		var skip = { type: 1, anchor: 1, id: 1, variant: 1, uid: 1, v: 1 };
		Object.keys(obj).forEach(function (k) {
			if (skip[k] || k.charAt(0) === '_') { return; }
			var v = obj[k], label = humanizeSlug(k, false);
			if (Array.isArray(v)) {
				var row = v.find(function (r) { return r && typeof r === 'object' && !Array.isArray(r); });
				// Only object-row arrays become repeaters — they round-trip on the
				// same keys. Arrays of scalars are skipped rather than rewritten into
				// objects (which would change the saved shape and could break render).
				if (row) { out.push({ name: k, label: label, type: 'repeater', subfields: inferFields(row) }); }
				return;
			}
			if (typeof v === 'boolean') {
				out.push({ name: k, label: label, type: 'toggle' });
			} else if (looksLikeImage(k, v)) {
				out.push({ name: k, label: label, type: 'image' });
			} else if (/(^|_)icon($|_)/.test(k) && typeof v === 'string') {
				out.push({ name: k, label: label, type: 'icon' });
			} else if (/(^|_)(href|url)($|_)/.test(k) || /(^|_)link$/.test(k)) {
				// href/url anywhere, or "link" only as the trailing word (cta_link) —
				// so link_label / link_text stay text, not a URL box.
				out.push({ name: k, label: label, type: 'url' });
			} else if (typeof v === 'string' && (v.length > 90 || v.indexOf('\n') > -1)) {
				out.push({ name: k, label: label, type: 'textarea' });
			} else if (v === null || typeof v === 'string' || typeof v === 'number') {
				out.push({ name: k, label: label, type: 'text' });
			}
		});
		return out;
	}
	function setDirty(v) {
		state.dirty = v;
		if (els.save) {
			els.save.disabled = !v;
			els.save.textContent = GATED ? 'Review & Publish' : (v ? 'Save changes' : 'Saved');
		}
		if (els.reviewBtn) { els.reviewBtn.disabled = !v; }
		if (els.dirty) { els.dirty.style.display = v ? 'inline' : 'none'; }
	}

	/* ---------------- working-state payload ---------------- */
	// Strip transient client keys (_uid, etc.) for the save / preview payloads.
	function stripSections() {
		return state.sections.map(function (s) { var c = {}; for (var k in s) { if (k.charAt(0) !== '_') { c[k] = s[k]; } } return c; });
	}

	/* ---------------- undo / redo history ---------------- */
	function snapshot() { return { sections: clone(state.sections), selected: state.selected }; }
	function histSeed() { state.hist = { stack: [snapshot()], ptr: 0, cap: 50 }; updateHistButtons(); }
	// Record the current working state as a new history step (dropping any redo
	// tail first, then trimming the oldest past the cap). Coalesces rapid typing
	// via histRecordDebounced().
	function histRecord() {
		if (!HIST) { return; }
		var h = state.hist;
		h.stack = h.stack.slice(0, h.ptr + 1);
		h.stack = HIST.historyPush(h.stack, snapshot(), h.cap);
		h.ptr = h.stack.length - 1;
		updateHistButtons();
	}
	function histRecordDebounced() {
		clearTimeout(state.histTimer);
		state.histTimer = setTimeout(histRecord, 500);
	}
	function updateHistButtons() {
		if (els.undo) { els.undo.disabled = !(state.hist.ptr > 0); }
		if (els.redo) { els.redo.disabled = !(state.hist.ptr < state.hist.stack.length - 1); }
	}
	function restoreSnapshot(snap) {
		if (!snap) { return; }
		state.sections = (snap.sections || []).map(function (s) { s._uid = ++uid; return s; });
		state.selected = (snap.selected != null && snap.selected < state.sections.length) ? snap.selected : -1;
		renderStructure();
		renderInspector();
	}
	function undo() {
		if (!HIST) { return; }
		clearTimeout(state.histTimer);
		var r = HIST.historyUndo(state.hist);
		if (!r.changed) { return; }
		state.hist.ptr = r.ptr;
		restoreSnapshot(r.snapshot);
		setDirty(true); updateHistButtons(); livePreview();
	}
	function redo() {
		if (!HIST) { return; }
		var r = HIST.historyRedo(state.hist);
		if (!r.changed) { return; }
		state.hist.ptr = r.ptr;
		restoreSnapshot(r.snapshot);
		setDirty(true); updateHistButtons(); livePreview();
	}

	/* ---------------- live (non-persisting) preview ---------------- */
	// A discrete change: snapshot history now + refresh the canvas preview.
	function pushChange() { histRecord(); livePreview(); }
	// A keystroke-level change: coalesce a history snapshot; the canvas already
	// reflects text/image edits live, so no full reload here (commit on blur).
	function pushTyping() { histRecordDebounced(); }

	function livePreview() {
		clearTimeout(state.previewTimer);
		state.previewTimer = setTimeout(doLivePreview, 300);
	}
	function doLivePreview() {
		api('/preview', { method: 'POST', body: { id: CFG.pageId, sections: stripSections() } })
			.then(function (d) { if (d && d.ok) { refreshCanvasPreview(); } })
			.catch(function () { /* graceful: preview unavailable → leave canvas as-is */ });
	}
	function refreshCanvasPreview() {
		state.rehighlight = state.selected; // canvas re-highlights + re-syncs the gallery panel on ready
		var sep = CFG.canvasUrl.indexOf('?') > -1 ? '&' : '?';
		els.iframe.src = CFG.canvasUrl + sep + 'aq_preview=1&t=' + Date.now();
	}

	/* ---------------- shell ---------------- */
	function buildShell() {
		var root = document.getElementById('aq-builder-root');
		root.innerHTML = '';

		// Topbar
		var bar = ce('div', 'aqb-topbar');
		var left = ce('div', 'aqb-topbar__l');
		var exit = ce('a', 'aqb-btn aqb-btn--ghost', '← Exit');
		exit.href = CFG.pagesUrl;
		exit.addEventListener('click', function (e) {
			if (state.dirty && !window.confirm('You have unsaved changes. Leave anyway?')) { e.preventDefault(); }
		});
		left.appendChild(exit);
		left.appendChild(ce('span', 'aqb-title', CFG.pageTitle || 'Editor'));
		els.dirty = ce('span', 'aqb-dirty', '● unsaved');
		els.dirty.style.display = 'none';
		left.appendChild(els.dirty);
		left.appendChild(buildSwitcher());

		var mid = ce('div', 'aqb-topbar__m');
		['desktop', 'tablet', 'mobile'].forEach(function (d) {
			var b = ce('button', 'aqb-dev' + (d === 'desktop' ? ' is-active' : ''), d.charAt(0).toUpperCase() + d.slice(1));
			b.addEventListener('click', function () { setDevice(d); });
			b.setAttribute('data-dev', d);
			mid.appendChild(b);
		});
		els.dev = mid;

		var right = ce('div', 'aqb-topbar__r');
		// Undo / redo — reversible working-state history (not persisted until Save).
		els.undo = ce('button', 'aqb-btn aqb-btn--ghost', '↶ Undo');
		els.undo.title = 'Undo (Ctrl/Cmd+Z)';
		els.undo.disabled = true;
		els.undo.addEventListener('click', undo);
		els.redo = ce('button', 'aqb-btn aqb-btn--ghost', '↷ Redo');
		els.redo.title = 'Redo (Ctrl/Cmd+Shift+Z)';
		els.redo.disabled = true;
		els.redo.addEventListener('click', redo);
		right.appendChild(els.undo);
		right.appendChild(els.redo);
		var view = ce('a', 'aqb-btn aqb-btn--ghost', 'View live ↗');
		view.href = CFG.permalink; view.target = '_blank'; view.rel = 'noopener';
		right.appendChild(view);
		// Agency admins (bypass) keep a direct Save, plus an OPTIONAL "Review" button
		// for a second opinion. Gated users get only "Review & Publish" — their sole
		// write path (the direct /save endpoint 403s them server-side).
		if (!GATED && CFG.reviewEnabled) {
			els.reviewBtn = ce('button', 'aqb-btn aqb-btn--ghost', 'Review');
			els.reviewBtn.title = 'Check these changes for SEO / brand issues (optional)';
			els.reviewBtn.disabled = true;
			els.reviewBtn.addEventListener('click', startReview);
			right.appendChild(els.reviewBtn);
		}
		els.save = ce('button', 'aqb-btn aqb-btn--primary', GATED ? 'Review & Publish' : 'Saved');
		els.save.disabled = true;
		els.save.addEventListener('click', GATED ? startReview : save);
		right.appendChild(els.save);

		bar.appendChild(left); bar.appendChild(mid); bar.appendChild(right);

		// Body: structure | canvas | inspector
		var body = ce('div', 'aqb-body');
		els.structure = ce('div', 'aqb-pane aqb-structure');
		var canvasWrap = ce('div', 'aqb-canvaswrap');
		els.canvasInner = ce('div', 'aqb-canvasinner');
		els.iframe = ce('iframe', 'aqb-canvas');
		els.iframe.src = CFG.canvasUrl;
		els.canvasInner.appendChild(els.iframe);
		canvasWrap.appendChild(els.canvasInner);
		els.inspector = ce('div', 'aqb-pane aqb-inspector');

		body.appendChild(els.structure);
		body.appendChild(canvasWrap);
		body.appendChild(els.inspector);

		root.appendChild(bar);
		root.appendChild(body);

		els.reviewPanel = ce('div', 'aqb-review');
		els.reviewPanel.style.display = 'none';
		root.appendChild(els.reviewPanel);
	}

	/* ---------------- page switcher (jump to any page) ---------------- */
	// A combobox in the header: type to filter every builder-editable page by title
	// or path, then Enter/click to jump there. Unsaved edits are guarded by a 3-way
	// Save / Discard / Cancel prompt before navigating. Works on any site — the page
	// list comes from aq/v1/editor/pages and the target URL is derived from the
	// CURRENT location (same admin slug, swapped id param).
	var SW = { pages: null, filtered: [], active: -1, open: false, loading: false };
	var SW_OPT_PREFIX = 'aqb-swopt-';

	function buildSwitcher() {
		var wrap = ce('div', 'aqb-switch');
		var input = ce('input', 'aqb-switch__input');
		input.type = 'text';
		input.placeholder = 'Go to page…';
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', 'aqb-switch-list');
		input.setAttribute('aria-label', 'Go to another page');
		input.autocomplete = 'off';
		input.spellcheck = false;
		var list = ce('ul', 'aqb-switch__list');
		list.id = 'aqb-switch-list';
		list.setAttribute('role', 'listbox');
		list.style.display = 'none';
		els.switchInput = input;
		els.switchList = list;

		input.addEventListener('focus', function () { swEnsurePages(function () { swRender(input.value); swOpen(); }); });
		input.addEventListener('input', function () { swEnsurePages(function () { swRender(input.value); swOpen(); }); });
		input.addEventListener('keydown', swKeydown);
		// Close when focus leaves the whole control (allow a click on an option first).
		wrap.addEventListener('focusout', function () {
			setTimeout(function () { if (!wrap.contains(document.activeElement)) { swClose(); } }, 120);
		});

		wrap.appendChild(input);
		wrap.appendChild(list);
		return wrap;
	}

	function swEnsurePages(cb) {
		if (Array.isArray(SW.pages)) { if (cb) { cb(); } return; }
		if (SW.loading) { return; }
		SW.loading = true;
		api('/pages').then(function (d) {
			SW.pages = Array.isArray(d) ? d : [];
			SW.loading = false;
			if (cb) { cb(); }
		}).catch(function () { SW.pages = []; SW.loading = false; if (cb) { cb(); } });
	}

	function swFilter(query) {
		if (HIST && typeof HIST.filterPages === 'function') { return HIST.filterPages(SW.pages, query, 10); }
		return (SW.pages || []).slice(0, 10);
	}

	function swRender(query) {
		var list = els.switchList;
		list.innerHTML = '';
		SW.filtered = swFilter(query);
		SW.active = -1;
		if (!SW.filtered.length) {
			var empty = ce('li', 'aqb-switch__empty', SW.pages && SW.pages.length ? 'No matching pages' : 'No pages');
			empty.setAttribute('role', 'presentation');
			list.appendChild(empty);
			els.switchInput.removeAttribute('aria-activedescendant');
			return;
		}
		SW.filtered.forEach(function (p, i) {
			var opt = ce('li', 'aqb-switch__opt');
			opt.id = SW_OPT_PREFIX + i;
			opt.setAttribute('role', 'option');
			opt.setAttribute('aria-selected', 'false');
			var isCurrent = String(p.id) === String(CFG.pageId);
			opt.appendChild(ce('span', 'aqb-switch__t', p.title + (isCurrent ? ' (current)' : '')));
			opt.appendChild(ce('span', 'aqb-switch__p', p.path || ''));
			opt.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep input focus
			opt.addEventListener('click', function () { swChoose(i); });
			opt.addEventListener('mousemove', function () { swSetActive(i); });
			list.appendChild(opt);
		});
	}

	function swOpen() {
		els.switchList.style.display = 'block';
		els.switchInput.setAttribute('aria-expanded', 'true');
		SW.open = true;
	}
	function swClose() {
		els.switchList.style.display = 'none';
		els.switchInput.setAttribute('aria-expanded', 'false');
		els.switchInput.removeAttribute('aria-activedescendant');
		SW.open = false;
		SW.active = -1;
	}
	function swSetActive(i) {
		var opts = els.switchList.querySelectorAll('.aqb-switch__opt');
		for (var j = 0; j < opts.length; j++) {
			var on = j === i;
			opts[j].classList.toggle('is-active', on);
			opts[j].setAttribute('aria-selected', on ? 'true' : 'false');
		}
		SW.active = i;
		if (i >= 0 && opts[i]) {
			els.switchInput.setAttribute('aria-activedescendant', opts[i].id);
			if (opts[i].scrollIntoView) { opts[i].scrollIntoView({ block: 'nearest' }); }
		} else {
			els.switchInput.removeAttribute('aria-activedescendant');
		}
	}
	function swMove(delta) {
		if (!SW.filtered.length) { return; }
		var n = SW.filtered.length;
		var i = SW.active < 0 ? (delta > 0 ? 0 : n - 1) : (SW.active + delta + n) % n;
		swSetActive(i);
	}
	function swKeydown(e) {
		if (e.key === 'ArrowDown') { e.preventDefault(); if (!SW.open) { swEnsurePages(function () { swRender(els.switchInput.value); swOpen(); }); } else { swMove(1); } }
		else if (e.key === 'ArrowUp') { e.preventDefault(); swMove(-1); }
		else if (e.key === 'Enter') {
			if (SW.open && SW.active >= 0) { e.preventDefault(); swChoose(SW.active); }
		}
		else if (e.key === 'Escape') {
			if (SW.open) { e.preventDefault(); e.stopPropagation(); swClose(); }
		}
	}
	function swChoose(i) {
		var p = SW.filtered[i];
		if (!p) { return; }
		if (String(p.id) === String(CFG.pageId)) { swClose(); els.switchInput.blur(); return; } // already here
		guardedNavigate(p.id);
	}

	// Build the target builder URL from the CURRENT location: same admin page slug,
	// swap the id param (whatever it is named) to the chosen page id. Robust to a
	// differently-named id param — it finds the param currently holding this page id.
	function buildPageUrl(id) {
		var url = new URL(window.location.href);
		var params = url.searchParams;
		var idParam = 'page_id';
		params.forEach(function (v, k) { if (k !== 'page' && v === String(CFG.pageId)) { idParam = k; } });
		params.set(idParam, String(id));
		return url.toString();
	}
	function navigateToPage(id) { window.location.href = buildPageUrl(id); }

	// Navigate to another page, guarding unsaved edits with a 3-way prompt.
	function guardedNavigate(id) {
		if (!state.dirty) { navigateToPage(id); return; }
		openSwitchDialog(id);
	}

	function openSwitchDialog(id) {
		closeSwitchDialog();
		var overlay = ce('div', 'aqb-confirm');
		var panel = ce('div', 'aqb-confirm__panel');
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-modal', 'true');
		panel.setAttribute('aria-labelledby', 'aqb-confirm-title');
		var h = ce('h3', 'aqb-confirm__title', 'Unsaved changes');
		h.id = 'aqb-confirm-title';
		panel.appendChild(h);
		panel.appendChild(ce('p', 'aqb-confirm__msg', GATED
			? 'You have unsaved changes on this page. Review & publish them, discard them, or stay here?'
			: 'You have unsaved changes on this page. Save them before leaving, discard them, or stay here?'));
		var row = ce('div', 'aqb-confirm__btns');

		var saveBtn = ce('button', 'aqb-btn aqb-btn--primary', GATED ? 'Review & Publish' : 'Save & go');
		saveBtn.addEventListener('click', function () {
			if (GATED) {
				// Gated users cannot write directly — send them through the review panel;
				// navigation happens manually after they publish.
				closeSwitchDialog(); swClose(); startReview();
			} else {
				saveBtn.disabled = true;
				save(function (ok) {
					if (ok) { closeSwitchDialog(); navigateToPage(id); return true; }
					saveBtn.disabled = false;
					return false;
				});
			}
		});
		var discardBtn = ce('button', 'aqb-btn aqb-btn--ghost aqb-confirm__discard', 'Discard & go');
		discardBtn.addEventListener('click', function () { closeSwitchDialog(); navigateToPage(id); });
		var cancelBtn = ce('button', 'aqb-btn aqb-btn--ghost aqb-confirm__cancel', 'Cancel');
		cancelBtn.addEventListener('click', function () { closeSwitchDialog(); if (els.switchInput) { els.switchInput.focus(); swOpen(); } });

		row.appendChild(saveBtn); row.appendChild(discardBtn); row.appendChild(cancelBtn);
		panel.appendChild(row);
		overlay.appendChild(panel);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) { cancelBtn.click(); } });
		overlay.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); cancelBtn.click(); } });
		document.getElementById('aq-builder-root').appendChild(overlay);
		els.switchDialog = overlay;
		saveBtn.focus();
	}
	function closeSwitchDialog() {
		if (els.switchDialog && els.switchDialog.parentNode) { els.switchDialog.parentNode.removeChild(els.switchDialog); }
		els.switchDialog = null;
	}

	/* ---------------- SEO review gate ---------------- */

	// Strip transient client keys but KEEP _uid — the server matches before↔after
	// sections by _uid to build an accurate change list.
	function reviewPayload(list) {
		return list.map(function (s) {
			var c = {};
			for (var k in s) { if (k === '_uid' || k.charAt(0) !== '_') { c[k] = s[k]; } }
			return c;
		});
	}

	function openReview() { if (els.reviewPanel) { els.reviewPanel.style.display = 'flex'; } }
	function closeReview() { if (els.reviewPanel) { els.reviewPanel.style.display = 'none'; } }

	function reviewHead(title) {
		var head = ce('div', 'aqb-review__head');
		head.appendChild(ce('span', 'aqb-review__title', title || 'Review changes'));
		var x = ce('button', 'aqb-icon', '✕'); x.title = 'Close';
		x.addEventListener('click', closeReview);
		head.appendChild(x);
		return head;
	}
	function reviewMessage(msg, isErr) {
		var p = els.reviewPanel; p.innerHTML = '';
		p.appendChild(reviewHead('Review'));
		p.appendChild(ce('div', 'aqb-review__msg' + (isErr ? ' is-error' : ''), msg));
	}
	function reviewLoading() {
		var p = els.reviewPanel; p.innerHTML = '';
		p.appendChild(reviewHead('Review changes'));
		p.appendChild(ce('div', 'aqb-review__loading', 'Checking your changes for SEO & brand issues…'));
	}
	function sevLabel(s) { return s === 'high' ? 'High-risk' : (s === 'caution' ? 'Caution' : 'OK'); }
	function pill(sev, text) { return ce('span', 'aqb-sev aqb-sev--' + sev, text); }

	// Run the AI review of the pending edit (base → current). Writes nothing.
	function startReview() {
		if (!state.dirty) { return; }
		openReview();
		reviewLoading();
		api('/review', { method: 'POST', body: { id: CFG.pageId, base: reviewPayload(state.base), proposed: reviewPayload(state.sections) } })
			.then(function (d) {
				if (!d || d.ok === false) {
					reviewMessage((d && (d.message || d.code)) || 'Review failed. Please try again.', true);
					return;
				}
				if (d.empty) { reviewMessage('No changes to review yet.'); return; }
				state.review = d;
				state.decisions = {};
				state.confirmed = {};
				// Sensible defaults: safe changes pre-allowed, high-risk pre-denied.
				d.changes.forEach(function (c) { state.decisions[c.id] = (c.severity === 'high') ? 'deny' : 'allow'; });
				renderReview(d);
			})
			.catch(function (e) { reviewMessage('Review failed: ' + e.message, true); });
	}

	function renderReview(d) {
		var p = els.reviewPanel; p.innerHTML = '';
		p.appendChild(reviewHead('Review changes'));

		var sum = ce('div', 'aqb-review__summary');
		var counts = d.counts || { ok: 0, caution: 0, high: 0 };
		var row = ce('div', 'aqb-review__counts');
		row.appendChild(pill('ok', counts.ok + ' OK'));
		row.appendChild(pill('caution', counts.caution + ' Caution'));
		row.appendChild(pill('high', counts.high + ' High-risk'));
		sum.appendChild(row);
		if (d.overall && d.overall.summary) { sum.appendChild(ce('p', 'aqb-review__overall', d.overall.summary)); }
		if (!d.usedAi) { sum.appendChild(ce('p', 'aqb-review__ainote', 'No AI key set — using built-in SEO checks. Add a Claude key under AutoForge → Integrations for smarter reviews.')); }
		p.appendChild(sum);

		var list = ce('div', 'aqb-review__list');
		d.changes.forEach(function (c) { list.appendChild(reviewCard(c)); });
		p.appendChild(list);

		var foot = ce('div', 'aqb-review__foot');
		var keep = ce('button', 'aqb-btn aqb-btn--ghost', 'Keep editing');
		keep.addEventListener('click', closeReview);
		var safe = ce('button', 'aqb-btn aqb-btn--ghost', 'Allow all safe');
		safe.addEventListener('click', function () {
			d.changes.forEach(function (c) { if (c.severity !== 'high') { state.decisions[c.id] = 'allow'; } });
			renderReview(d);
		});
		els.publish = ce('button', 'aqb-btn aqb-btn--primary', 'Publish allowed changes');
		els.publish.addEventListener('click', commitReview);
		foot.appendChild(keep); foot.appendChild(safe); foot.appendChild(els.publish);
		p.appendChild(foot);
	}

	function reviewCard(c) {
		var card = ce('div', 'aqb-rev aqb-rev--' + c.severity);
		var top = ce('div', 'aqb-rev__top');
		top.appendChild(pill(c.severity, sevLabel(c.severity)));
		top.appendChild(ce('span', 'aqb-rev__title', c.title || c.label));
		card.appendChild(top);
		if (c.reason) { card.appendChild(ce('p', 'aqb-rev__reason', c.reason)); }

		if (c.before || c.after) {
			var ba = ce('div', 'aqb-rev__ba');
			if (c.before) {
				var b = ce('div', 'aqb-rev__side aqb-rev__side--before');
				b.appendChild(ce('span', 'aqb-rev__lbl', 'Before'));
				b.appendChild(ce('span', 'aqb-rev__txt', c.before));
				ba.appendChild(b);
			}
			if (c.after) {
				var a = ce('div', 'aqb-rev__side aqb-rev__side--after');
				a.appendChild(ce('span', 'aqb-rev__lbl', 'After'));
				a.appendChild(ce('span', 'aqb-rev__txt', c.after));
				ba.appendChild(a);
			}
			card.appendChild(ba);
		}
		if (c.suggestion) { card.appendChild(ce('p', 'aqb-rev__sug', '💡 ' + c.suggestion)); }

		var confirmWrap = null;
		var ctrl = ce('div', 'aqb-rev__ctrl');
		var allowBtn = ce('button', 'aqb-choice aqb-choice--allow', 'Allow');
		var denyBtn = ce('button', 'aqb-choice aqb-choice--deny', 'Deny');
		function paint() {
			var dec = state.decisions[c.id];
			allowBtn.classList.toggle('is-on', dec === 'allow');
			denyBtn.classList.toggle('is-on', dec === 'deny');
			if (confirmWrap) { confirmWrap.style.display = (dec === 'allow') ? 'flex' : 'none'; }
		}
		allowBtn.addEventListener('click', function () { state.decisions[c.id] = 'allow'; paint(); });
		denyBtn.addEventListener('click', function () { state.decisions[c.id] = 'deny'; paint(); });
		ctrl.appendChild(allowBtn); ctrl.appendChild(denyBtn);
		card.appendChild(ctrl);

		if (c.severity === 'high') {
			confirmWrap = ce('label', 'aqb-rev__confirm');
			var cb = ce('input'); cb.type = 'checkbox'; cb.checked = !!state.confirmed[c.id];
			cb.addEventListener('change', function () { state.confirmed[c.id] = cb.checked; });
			confirmWrap.appendChild(cb);
			confirmWrap.appendChild(ce('span', null, 'I understand this may hurt SEO'));
			card.appendChild(confirmWrap);
		}
		paint();
		return card;
	}

	// Apply the user's allow/deny decisions: the server rebuilds the final set and
	// writes it. High-risk allows must be confirmed (also re-checked server-side).
	function commitReview() {
		var d = state.review;
		if (!d) { return; }
		var missing = d.changes.filter(function (c) {
			return c.severity === 'high' && state.decisions[c.id] === 'allow' && !state.confirmed[c.id];
		});
		if (missing.length) {
			window.alert('Please tick “I understand this may hurt SEO” for the high-risk change(s) you want to allow — ' + missing.length + ' still unconfirmed.');
			return;
		}
		var confirmedHighRisk = d.changes.filter(function (c) {
			return c.severity === 'high' && state.decisions[c.id] === 'allow';
		}).map(function (c) { return c.id; });

		if (els.publish) { els.publish.disabled = true; els.publish.textContent = 'Publishing…'; }
		api('/commit', { method: 'POST', body: { id: CFG.pageId, reviewId: d.reviewId, decisions: state.decisions, confirmedHighRisk: confirmedHighRisk } })
			.then(function (r) {
				if (r && r.ok) {
					closeReview();
					reloadFromServer(r.sections);
					setDirty(false);
					state.rehighlight = state.selected;
					els.iframe.src = CFG.canvasUrl; // reload to show the true render
					return;
				}
				if (els.publish) { els.publish.disabled = false; els.publish.textContent = 'Publish allowed changes'; }
				if (r && (r.stale || r.expired)) { reviewMessage((r.message || 'Please run the review again.'), true); }
				else if (r && r.needConfirm) { window.alert('Please confirm the high-risk changes before publishing.'); }
				else { window.alert('Publish failed: ' + ((r && (r.message || r.code)) || 'unknown error')); }
			})
			.catch(function (e) {
				if (els.publish) { els.publish.disabled = false; els.publish.textContent = 'Publish allowed changes'; }
				window.alert('Publish failed: ' + e.message);
			});
	}

	// After a commit, adopt the server's committed sections as the new working set
	// + base, so denied changes visibly revert and the next review diffs correctly.
	function reloadFromServer(sections) {
		state.sections = (sections || []).map(function (s) { s._uid = ++uid; return s; });
		state.base = clone(state.sections);
		state.review = null; state.decisions = {}; state.confirmed = {};
		if (state.selected >= state.sections.length) { state.selected = -1; }
		renderStructure();
		renderInspector();
		histSeed(); // committed state becomes the new undo baseline
	}

	function setDevice(d) {
		state.device = d;
		var w = d === 'mobile' ? '390px' : (d === 'tablet' ? '768px' : '100%');
		els.canvasInner.style.maxWidth = w;
		Array.prototype.forEach.call(els.dev.children, function (b) {
			b.classList.toggle('is-active', b.getAttribute('data-dev') === d);
		});
	}

	/* ---------------- structure pane ---------------- */
	function renderStructure() {
		var p = els.structure;
		p.innerHTML = '';
		p.appendChild(ce('h3', 'aqb-h', 'Sections'));

		var list = ce('div', 'aqb-seclist');
		state.sections.forEach(function (s, i) {
			var row = ce('div', 'aqb-secrow' + (i === state.selected ? ' is-active' : ''));
			var name = ce('button', 'aqb-secname', labelFor(s.type));
			name.addEventListener('click', function () { selectSection(i, true); });
			var tools = ce('div', 'aqb-sectools');
			tools.appendChild(iconBtn('↑', 'Move up', function () { move(i, -1); }));
			tools.appendChild(iconBtn('↓', 'Move down', function () { move(i, 1); }));
			tools.appendChild(iconBtn('⧉', 'Duplicate', function () { duplicate(i); }));
			tools.appendChild(iconBtn('✕', 'Delete', function () { removeSection(i); }, true));
			row.appendChild(name);
			row.appendChild(tools);
			list.appendChild(row);
		});
		p.appendChild(list);

		// Add-section
		var addWrap = ce('div', 'aqb-addwrap');
		var sel = ce('select', 'aqb-addsel');
		sel.appendChild(new Option('+ Add section…', ''));
		// Alphabetize the add-section picker by human label (A→Z) — only this list;
		// the page's section structure above stays in page order.
		Object.keys(CFG.labels || {})
			.sort(function (a, b) { return labelFor(a).localeCompare(labelFor(b)); })
			.forEach(function (type) {
				sel.appendChild(new Option(labelFor(type), type));
			});
		sel.addEventListener('change', function () {
			if (sel.value) { addSection(sel.value); sel.value = ''; }
		});
		addWrap.appendChild(sel);
		p.appendChild(addWrap);
	}
	function iconBtn(glyph, title, fn, danger) {
		var b = ce('button', 'aqb-icon' + (danger ? ' aqb-icon--danger' : ''), glyph);
		b.title = title;
		b.addEventListener('click', function (e) { e.stopPropagation(); fn(); });
		return b;
	}

	/* ---------------- inspector ---------------- */
	function selectSection(i, tellCanvas) {
		state.selected = i;
		renderStructure();
		renderInspector();
		if (tellCanvas) { postCanvas({ type: 'highlight', index: i }); }
	}

	// Whether a section type is edited via bespoke inspector controls (aq_gallery).
	function inplaceOf(type) { return (CFG.schema && CFG.schema[type] && CFG.schema[type].inplace) || ''; }

	/**
	 * Jump the inspector to the exact field that was clicked on the canvas:
	 * expand the right repeater row, scroll it into view, flash it, and focus
	 * the input. m = { field, repeater, rindex } from the canvas select message.
	 */
	function focusField(m) {
		var insp = els.inspector, target = null;
		if (m.repeater != null && m.rindex != null) {
			var card = insp.querySelector('[data-aqi="' + cssEsc(m.repeater) + ':' + m.rindex + '"]');
			if (card) {
				target = (m.field ? card.querySelector('[data-aqf="' + cssEsc(m.field) + '"]') : null) || card;
			}
		}
		if (!target && m.field) {
			target = insp.querySelector('.aqb-field--top[data-aqf="' + cssEsc(m.field) + '"]');
		}
		if (!target) { return; }
		try { target.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) { target.scrollIntoView(); }
		flashWrap(target);
		// When the canvas is starting in-place editing, do NOT focus the inspector
		// input — moving focus to the parent window would blur (and end) the
		// contentEditable edit in the iframe.
		if (m && m.editing) { return; }
		var input = target.querySelector('input, textarea, select');
		if (input && input.focus) { try { input.focus({ preventScroll: true }); } catch (e2) { input.focus(); } }
	}
	function flashWrap(w) {
		w.classList.add('aqb-flash');
		setTimeout(function () { w.classList.remove('aqb-flash'); }, 1400);
	}
	function cssEsc(s) {
		if (window.CSS && CSS.escape) { return CSS.escape(s); }
		return String(s).replace(/["\\\]\[]/g, '\\$&');
	}

	function renderInspector() {
		var p = els.inspector;
		p.innerHTML = '';
		if (state.selected < 0 || !state.sections[state.selected]) {
			p.appendChild(ce('div', 'aqb-empty', 'Click a section on the page to edit it.'));
			return;
		}
		var s = state.sections[state.selected];
		p.appendChild(ce('h3', 'aqb-h', labelFor(s.type)));
		// In-place sections (aq_gallery) are edited on the canvas via a floating
		// overlay, not here. Show a short pointer + a re-open button instead of the
		// raw persistence fields (which would surface attachment IDs as text boxes).
		if (inplaceOf(s.type) === 'gallery') {
			renderGalleryInspector(p, s);
			return;
		}
		var fields = schemaFor(s.type);
		var inferred = false;
		if (!fields.length) { fields = inferFields(s); inferred = true; }
		if (!fields.length) {
			p.appendChild(ce('p', 'aqb-muted', 'This section has no editable fields.'));
			return;
		}
		if (inferred) {
			var note = ce('p', 'aqb-muted aqb-auto-note', 'Fields detected automatically from this section.');
			p.appendChild(note);
		}
		var content = fields.filter(function (f) { return f.group !== 'design'; });
		var design = fields.filter(function (f) { return f.group === 'design'; });
		content.forEach(function (f) { p.appendChild(renderField(s, f)); });
		if (design.length) {
			var grp = ce('div', 'aqb-group');
			grp.appendChild(ce('h4', 'aqb-grouph', 'Design'));
			p.appendChild(grp);
			design.forEach(function (f) { p.appendChild(renderField(s, f)); });
		}
	}

	function renderField(obj, f, ctx) {
		var wrap = ce('div', 'aqb-field' + (ctx ? '' : ' aqb-field--top'));
		wrap.setAttribute('data-aqf', f.name);
		// Two-way binding with the canvas: focusing this field flashes the matching
		// element on the page. ctx carries the repeater context for subfields.
		var info = { index: -1, field: f.name, repeater: ctx ? ctx.repeater : null, rindex: ctx ? ctx.rindex : null };
		wrap.addEventListener('focusin', function (e) {
			// Only the innermost field wrapper reacts (focusin bubbles through nested repeaters).
			if (e.target.closest && e.target.closest('.aqb-field') !== wrap) { return; }
			postCanvas({ type: 'highlight', index: state.selected, field: info.field, repeater: info.repeater, rindex: info.rindex });
		});
		if (f.type === 'toggle') {
			var lab = ce('label', 'aqb-toggle');
			var cb = ce('input'); cb.type = 'checkbox'; cb.checked = !!obj[f.name];
			cb.addEventListener('change', function () { obj[f.name] = cb.checked; setDirty(true); pushChange(); });
			lab.appendChild(cb); lab.appendChild(ce('span', null, f.label));
			wrap.appendChild(lab);
			return wrap;
		}
		wrap.appendChild(ce('label', 'aqb-label', f.label));
		if (f.type === 'repeater') {
			wrap.appendChild(renderRepeater(obj, f));
			return wrap;
		}
		if (f.type === 'image') {
			wrap.appendChild(renderImage(obj, f, ctx));
			return wrap;
		}
		if (f.type === 'icon') {
			wrap.appendChild(renderIcon(obj, f));
			return wrap;
		}
		var input;
		if (f.type === 'textarea' || f.type === 'richtext' || f.type === 'code') {
			input = ce('textarea', 'aqb-input aqb-textarea' + (f.type === 'code' ? ' aqb-code' : ''));
			input.rows = f.type === 'code' ? 8 : 3;
			input.value = obj[f.name] != null ? obj[f.name] : '';
		} else if (f.type === 'select') {
			input = ce('select', 'aqb-input');
			Object.keys(f.options || {}).forEach(function (v) { input.appendChild(new Option(f.options[v], v)); });
			input.value = obj[f.name] != null ? obj[f.name] : Object.keys(f.options || {})[0];
		} else {
			input = ce('input', 'aqb-input');
			input.type = (f.type === 'url') ? 'text' : 'text';
			input.value = obj[f.name] != null ? obj[f.name] : '';
		}
		input.addEventListener('input', function () {
			obj[f.name] = input.value;
			setDirty(true);
			// Reflect text edits live on the canvas (no-op there for non-text fields).
			if (f.type === 'text' || f.type === 'textarea' || f.type === 'richtext') {
				postCanvas({ type: 'settext', index: state.selected, field: info.field, repeater: info.repeater, rindex: info.rindex, value: input.value });
			}
			// Selects apply at once (snapshot + preview reload). Typed fields keep the
			// canvas smooth (live text above, no reload) and commit on blur (change).
			if (f.type === 'select') { pushChange(); } else { pushTyping(); }
		});
		input.addEventListener('change', function () {
			if (f.type !== 'select') { histRecord(); livePreview(); }
		});
		wrap.appendChild(input);
		if (f.type === 'richtext') { wrap.appendChild(ce('span', 'aqb-hint', 'Basic HTML allowed (links, bold, italic).')); }
		return wrap;
	}

	/* ---------------- image field (media library) ---------------- */
	function imageBasename(url) {
		return (url || '').split('?')[0].split('#')[0].split('/').pop();
	}
	function renderImage(obj, f, ctx) {
		var box = ce('div', 'aqb-img');
		var thumb = ce('div', 'aqb-img__thumb');
		var choose = ce('button', 'aqb-btn aqb-btn--ghost', 'Choose image');
		choose.type = 'button';
		function paint() {
			var filename = obj[f.name] != null ? String(obj[f.name]) : '';
			var meta = state.images[filename];
			thumb.innerHTML = '';
			thumb.classList.toggle('is-empty', !filename);
			if (filename && meta && meta.thumb) {
				var im = ce('img'); im.src = meta.thumb; im.alt = filename; thumb.appendChild(im);
			} else if (filename) {
				thumb.appendChild(ce('span', 'aqb-img__name', filename));
			} else {
				thumb.appendChild(ce('span', 'aqb-img__none', 'No image'));
			}
			choose.textContent = filename ? 'Replace' : 'Choose image';
		}
		paint();
		box.appendChild(thumb);
		var btns = ce('div', 'aqb-img__btns');
		choose.addEventListener('click', function () { openMedia(obj, f, paint, ctx); });
		btns.appendChild(choose);
		var clear = ce('button', 'aqb-btn aqb-btn--ghost', 'Remove');
		clear.type = 'button';
		clear.addEventListener('click', function () { obj[f.name] = ''; setDirty(true); paint(); pushChange(); });
		btns.appendChild(clear);
		box.appendChild(btns);
		box.appendChild(ce('span', 'aqb-hint', 'Pick from the media library.'));
		return box;
	}
	/* ---------------- icon field (curated picker) ---------------- */
	function renderIcon(obj, f) {
		var box = ce('div', 'aqb-icon-field');
		var preview = ce('div', 'aqb-iconprev');
		function paint() {
			preview.innerHTML = obj[f.name] ? String(obj[f.name]) : '';
			preview.classList.toggle('is-empty', !obj[f.name]);
			if (!obj[f.name]) { preview.appendChild(ce('span', 'aqb-img__none', 'No icon')); }
		}
		paint();
		box.appendChild(preview);

		var grid = ce('div', 'aqb-icongrid');
		grid.style.display = 'none';
		var icons = CFG.icons || {};
		Object.keys(icons).forEach(function (name) {
			var sw = ce('button', 'aqb-iconsw'); sw.type = 'button'; sw.title = name;
			sw.innerHTML = icons[name];
			sw.addEventListener('click', function () { obj[f.name] = icons[name]; setDirty(true); paint(); grid.style.display = 'none'; pushChange(); });
			grid.appendChild(sw);
		});

		var btns = ce('div', 'aqb-img__btns');
		var choose = ce('button', 'aqb-btn aqb-btn--ghost', 'Choose icon'); choose.type = 'button';
		choose.addEventListener('click', function () { grid.style.display = grid.style.display === 'none' ? 'grid' : 'none'; });
		btns.appendChild(choose);
		var clear = ce('button', 'aqb-btn aqb-btn--ghost', 'Remove'); clear.type = 'button';
		clear.addEventListener('click', function () { obj[f.name] = ''; setDirty(true); paint(); pushChange(); });
		btns.appendChild(clear);
		box.appendChild(btns);
		box.appendChild(grid);

		var adv = ce('details', 'aqb-iconadv');
		adv.appendChild(ce('summary', null, 'Paste custom SVG'));
		var ta = ce('textarea', 'aqb-input aqb-textarea aqb-code'); ta.rows = 4;
		ta.value = obj[f.name] != null ? obj[f.name] : '';
		ta.addEventListener('input', function () { obj[f.name] = ta.value; setDirty(true); paint(); pushTyping(); });
		ta.addEventListener('change', function () { histRecord(); livePreview(); });
		adv.appendChild(ta);
		box.appendChild(adv);
		return box;
	}

	function openMedia(obj, f, paint, ctx) {
		if (!window.wp || !wp.media) {
			var fn = window.prompt('Image filename from the media library:', obj[f.name] || '');
			if (fn != null) {
				var oldp = obj[f.name] != null ? String(obj[f.name]) : '';
				obj[f.name] = fn; setDirty(true); paint();
				var metap = state.images[fn];
				postImage(ctx, f.name, oldp, fn, metap && metap.url ? metap.url : '');
				pushChange();
			}
			return;
		}
		var frame = wp.media({ title: 'Select image', button: { text: 'Use image' }, multiple: false, library: { type: 'image' } });
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			var url = att.url || '';
			var name = imageBasename(url);
			var thumbUrl = (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) ? att.sizes.thumbnail.url : url;
			var old = obj[f.name] != null ? String(obj[f.name]) : '';
			obj[f.name] = name;
			state.images[name] = { id: att.id, url: url, thumb: thumbUrl };
			setDirty(true);
			paint();
			postImage(ctx, f.name, old, name, url);
			pushChange();
		});
		frame.open();
	}
	// Live-preview an image swap on the canvas — the sibling of the settext bridge.
	// The canvas finds the <img> by its data-aq-field marker, or (for unmarked
	// images like heroes) by matching the OLD filename's stem, so this works on
	// every site with no per-template changes. The true responsive/<picture>
	// render returns on Save (which reloads the canvas).
	function postImage(ctx, field, oldName, name, url) {
		if (!url) { return; }
		postCanvas({
			type: 'setimage', index: state.selected, field: field,
			repeater: ctx ? ctx.repeater : null, rindex: ctx ? ctx.rindex : null,
			oldName: oldName, name: name, url: url
		});
	}

	function renderRepeater(obj, f) {
		if (!Array.isArray(obj[f.name])) { obj[f.name] = []; }
		var rows = obj[f.name];
		var box = ce('div', 'aqb-rep');
		rows.forEach(function (row, ri) {
			var card = ce('div', 'aqb-repitem');
			card.setAttribute('data-aqi', f.name + ':' + ri); // canvas click → jump to this row
			var head = ce('div', 'aqb-rephead');
			head.appendChild(ce('span', 'aqb-repnum', '#' + (ri + 1)));
			var tools = ce('div', 'aqb-sectools');
			tools.appendChild(iconBtn('↑', 'Up', function () { if (ri > 0) { rows.splice(ri - 1, 0, rows.splice(ri, 1)[0]); setDirty(true); renderInspector(); pushChange(); } }));
			tools.appendChild(iconBtn('↓', 'Down', function () { if (ri < rows.length - 1) { rows.splice(ri + 1, 0, rows.splice(ri, 1)[0]); setDirty(true); renderInspector(); pushChange(); } }));
			tools.appendChild(iconBtn('✕', 'Remove', function () { rows.splice(ri, 1); setDirty(true); renderInspector(); pushChange(); }, true));
			head.appendChild(tools);
			card.appendChild(head);
			(f.subfields || []).forEach(function (sf) { card.appendChild(renderField(row, sf, { repeater: f.name, rindex: ri })); });
			box.appendChild(card);
		});
		var add = ce('button', 'aqb-btn aqb-btn--ghost aqb-addrow', '+ Add ' + (f.label || 'item').toLowerCase().replace(/s$/, ''));
		add.addEventListener('click', function () {
			var blank = {};
			(f.subfields || []).forEach(function (sf) { blank[sf.name] = sf.type === 'toggle' ? false : ''; });
			rows.push(blank); setDirty(true); renderInspector(); pushChange();
		});
		box.appendChild(add);
		return box;
	}

	/* ---------------- structure ops ---------------- */
	function move(i, dir) {
		var j = i + dir;
		if (j < 0 || j >= state.sections.length) { return; }
		var tmp = state.sections[i]; state.sections[i] = state.sections[j]; state.sections[j] = tmp;
		state.selected = j; setDirty(true); renderStructure(); renderInspector(); pushChange();
	}
	function duplicate(i) {
		var copy = JSON.parse(JSON.stringify(state.sections[i]));
		copy._uid = ++uid;
		state.sections.splice(i + 1, 0, copy);
		state.selected = i + 1; setDirty(true); renderStructure(); renderInspector(); pushChange();
	}
	function removeSection(i) {
		if (!window.confirm('Remove this ' + labelFor(state.sections[i].type) + ' section?')) { return; }
		state.sections.splice(i, 1);
		if (state.selected >= state.sections.length) { state.selected = state.sections.length - 1; }
		setDirty(true); renderStructure(); renderInspector(); pushChange();
	}
	function addSection(type) {
		var s = { type: type, v: 1, _uid: ++uid };
		var at = state.selected >= 0 ? state.selected + 1 : state.sections.length;
		state.sections.splice(at, 0, s);
		// Select the new section so its controls appear in the sidebar immediately
		// (gallery controls render there now). Then snapshot history + push a live
		// preview so the new (possibly empty) section appears on the canvas.
		selectSection(at, true);
		setDirty(true); pushChange();
	}

	/* ---------------- gallery editor (config-driven; in the sidebar) ---------------- */
	// Any section whose editor schema has inplace:'gallery' gets this editor. Its
	// gallery_editor config (merged over defaults by AQHistory.galleryCfg) maps the
	// engine's generic UI onto that section's own field names / conventions, so a
	// bespoke gallery section can adopt the full editor. See class-editor.php's
	// "GALLERY_EDITOR CONFIG" block for the keys + a worked example.
	//
	// Reordering happens by dragging the REAL tiles on the canvas (canvas.js +
	// gallery-reorder message); the sidebar owns everything else.
	function galSec() { return state.sections[state.selected]; }
	function catLabel(row) { return typeof row === 'string' ? row : ((row && row.label) || ''); }
	function galleryCfg(type) {
		var entry = CFG.schema && CFG.schema[type];
		return (HIST && typeof HIST.galleryCfg === 'function') ? HIST.galleryCfg(entry && entry.gallery_editor) : (entry && entry.gallery_editor) || {};
	}
	// Resolve items array for the configured field, creating it if missing.
	function galItems(sec, cfg) { if (!Array.isArray(sec[cfg.items])) { sec[cfg.items] = []; } return sec[cfg.items]; }
	function galEnsure(sec, cfg) {
		galItems(sec, cfg);
		// A stored categories field (not 'derive'/empty) is an array we manage.
		if (cfg.categories && cfg.categories !== 'derive' && !Array.isArray(sec[cfg.categories])) { sec[cfg.categories] = []; }
		// Only default a layout field if it is actually configured (a real field name).
		if (cfg.columns && (sec[cfg.columns] == null || sec[cfg.columns] === '')) { sec[cfg.columns] = '3'; }
		if (cfg.gap && (sec[cfg.gap] == null || sec[cfg.gap] === '')) { sec[cfg.gap] = 'md'; }
		if (cfg.order_by && (sec[cfg.order_by] == null || sec[cfg.order_by] === '')) { sec[cfg.order_by] = 'manual'; }
		if (cfg.lightbox && sec[cfg.lightbox] == null) { sec[cfg.lightbox] = true; }
		if (cfg.filters && cfg.filters !== 'always' && cfg.filters !== 'off' && sec[cfg.filters] == null) { sec[cfg.filters] = false; }
	}
	function galThumb(value) {
		if (value == null || value === '') { return ''; }
		var m = state.galleryImages[String(value)] || state.galleryImages[value];
		return m && m.thumb ? m.thumb : '';
	}
	// Is manual drag-reorder active? (order_by unset ⇒ always manual.)
	function galManual(sec, cfg) { return !cfg.order_by || String(sec[cfg.order_by] || 'manual') === 'manual'; }
	// Is the category feature on for this gallery? (category sub-field configured.)
	function galHasCats(cfg) { return !!cfg.category; }
	// Whether the filter bar is on: 'always' | 'off' | a section bool field.
	function galFiltersOn(sec, cfg) {
		if (cfg.filters === 'always') { return true; }
		if (cfg.filters === 'off' || !cfg.filters) { return false; }
		return !!sec[cfg.filters];
	}
	// The resolved list of category LABELS: from a section field, or DERIVED from
	// the distinct categories used across items (first-appearance order).
	function galCatLabels(sec, cfg) {
		if (!galHasCats(cfg)) { return []; }
		if (cfg.categories === 'derive' || !cfg.categories) {
			var seen = {}, out = [];
			galItems(sec, cfg).forEach(function (img) {
				var c = (img[cfg.category] || '').trim();
				if (c && !seen[c.toLowerCase()]) { seen[c.toLowerCase()] = true; out.push(c); }
			});
			return out;
		}
		return (sec[cfg.categories] || []).map(catLabel).filter(function (l) { return l; });
	}

	function gRow(label, control) {
		var row = ce('div', 'aqb-grow');
		row.appendChild(ce('label', 'aqb-glabel', label));
		row.appendChild(control);
		return row;
	}
	function gSelect(value, options, onchange) {
		var sel = ce('select', 'aqb-ginput');
		Object.keys(options).forEach(function (v) { sel.appendChild(new Option(options[v], v)); });
		sel.value = value;
		sel.addEventListener('change', function () { onchange(sel.value); });
		return sel;
	}
	function gToggle(checked, onchange) {
		var lab = ce('label', 'aqb-gtoggle');
		var cb = ce('input'); cb.type = 'checkbox'; cb.checked = !!checked;
		cb.addEventListener('change', function () { onchange(cb.checked); });
		lab.appendChild(cb);
		return lab;
	}

	// Transient (never-persisted) multi-select of image indices for the current
	// gallery. Resets when the selected section changes (matched by _uid).
	function galSelSet(sec) {
		if (state.gallerySelUid !== sec._uid || !state.gallerySel) {
			state.gallerySel = {};        // plain object as an index set { "3": true }
			state.gallerySelUid = sec._uid;
			state.galleryBulkCat = '';
		}
		return state.gallerySel;
	}
	function galSelClear() { state.gallerySel = {}; state.galleryBulkCat = ''; }
	function galSelCount() {
		var n = 0, s = state.gallerySel || {};
		for (var k in s) { if (s[k]) { n++; } }
		return n;
	}
	function galSelIndices() {
		var out = [], s = state.gallerySel || {};
		for (var k in s) { if (s[k]) { out.push(parseInt(k, 10)); } }
		return out;
	}

	// Render the gallery's full control set into the inspector pane `p`.
	function renderGalleryInspector(p, sec) {
		var cfg = galleryCfg(sec.type);
		galEnsure(sec, cfg);
		var items = galItems(sec, cfg);
		var sel = galSelSet(sec);

		// Add images (bulk upload + bulk select from the media library).
		var add = ce('button', 'aqb-btn aqb-btn--primary aqb-gadd', '+ Add images');
		add.addEventListener('click', addGalleryImages);
		p.appendChild(add);

		p.appendChild(ce('p', 'aqb-muted aqb-ghint', galManual(sec, cfg)
			? 'Drag the images on the page to reorder them.'
			: 'Images are auto-sorted. Switch “Order by” to Manual to drag-reorder on the page.'));

		// Bulk toolbar (multi-select → set/clear category, bulk remove).
		if (items.length) { p.appendChild(galBulkBar(sec, cfg)); }

		// Per-image rows: checkbox + thumbnail + category + caption + remove.
		var list = ce('div', 'aqb-gimglist');
		if (!items.length) {
			list.appendChild(ce('p', 'aqb-muted', 'No images yet. Use “Add images” to bulk-add from the media library.'));
		}
		items.forEach(function (img, idx) { list.appendChild(galImageRow(sec, cfg, img, idx, sel)); });
		p.appendChild(list);

		// Layout controls — each rendered ONLY when its config maps to a real field.
		if (cfg.order_by) {
			p.appendChild(gRow('Order by', gSelect(String(sec[cfg.order_by]), {
				manual: 'Manual (drag on page)', title: 'Title A–Z', date_desc: 'Newest first',
				date_asc: 'Oldest first', filename: 'Filename A–Z', random: 'Random'
			}, function (v) { sec[cfg.order_by] = v; setDirty(true); renderInspector(); pushChange(); })));
		}
		if (cfg.columns) {
			p.appendChild(gRow('Columns', gSelect(String(sec[cfg.columns]), { '2': '2', '3': '3', '4': '4', '5': '5' }, function (v) { sec[cfg.columns] = v; setDirty(true); pushChange(); })));
		}
		if (cfg.gap) {
			p.appendChild(gRow('Gap', gSelect(String(sec[cfg.gap]), { sm: 'Small', md: 'Medium', lg: 'Large' }, function (v) { sec[cfg.gap] = v; setDirty(true); pushChange(); })));
		}
		if (cfg.lightbox) {
			p.appendChild(gRow('Click to enlarge', gToggle(sec[cfg.lightbox], function (v) { sec[cfg.lightbox] = v; setDirty(true); pushChange(); })));
		}
		// Filter-bar toggle only when `filters` maps to a real bool field (not always/off).
		var filtersField = cfg.filters && cfg.filters !== 'always' && cfg.filters !== 'off';
		if (galHasCats(cfg) && filtersField) {
			p.appendChild(gRow('Category filter bar', gToggle(sec[cfg.filters], function (v) { sec[cfg.filters] = v; setDirty(true); renderInspector(); pushChange(); })));
		}

		// Categories manager (tab-order editor) — only for a STORED categories field,
		// and only when the bar is on. In 'derive' mode categories come from items.
		if (galHasCats(cfg) && cfg.categories && cfg.categories !== 'derive' && galFiltersOn(sec, cfg)) {
			p.appendChild(galCategories(sec, cfg));
		}
	}

	// Bulk toolbar: shown above the image list; the category + action buttons act
	// on the current multi-selection (transient, never saved).
	function galBulkBar(sec, cfg) {
		var items = galItems(sec, cfg);
		var count = galSelCount();
		var bar = ce('div', 'aqb-gbulk' + (count ? ' is-active' : ''));

		var top = ce('div', 'aqb-gbulk__top');
		var allBtn = ce('button', 'aqb-btn aqb-btn--ghost aqb-gbulk__sm', 'Select all'); allBtn.type = 'button';
		allBtn.addEventListener('click', function () {
			state.gallerySel = {};
			for (var i = 0; i < items.length; i++) { state.gallerySel[i] = true; }
			renderInspector();
		});
		var clrBtn = ce('button', 'aqb-btn aqb-btn--ghost aqb-gbulk__sm', 'Clear'); clrBtn.type = 'button';
		clrBtn.disabled = !count;
		clrBtn.addEventListener('click', function () { galSelClear(); renderInspector(); });
		top.appendChild(ce('span', 'aqb-gbulk__count', count + ' selected'));
		top.appendChild(allBtn);
		top.appendChild(clrBtn);
		bar.appendChild(top);

		var actions = ce('div', 'aqb-gbulk__actions');

		// Bulk category picker — only when the gallery has a category feature.
		if (galHasCats(cfg)) {
			var catSel = ce('select', 'aqb-ginput');
			catSel.appendChild(new Option('— set category —', ''));
			galCatLabels(sec, cfg).forEach(function (l) { catSel.appendChild(new Option(l, l)); });
			catSel.appendChild(new Option('＋ New…', '__new'));
			var curBulk = state.galleryBulkCat || '';
			if (curBulk && !Array.prototype.some.call(catSel.options, function (o) { return o.value === curBulk; })) {
				catSel.insertBefore(new Option(curBulk, curBulk), catSel.options[catSel.options.length - 1]);
			}
			catSel.value = curBulk;
			catSel.disabled = !count;
			catSel.addEventListener('change', function () {
				if (catSel.value === '__new') {
					var nv = window.prompt('New category label:', '');
					nv = nv ? nv.trim() : '';
					if (nv) {
						// A stored categories field also records the new label; in derive
						// mode it becomes usable once applied to an item (below).
						if (cfg.categories && cfg.categories !== 'derive'
							&& !(sec[cfg.categories] || []).some(function (r) { return catLabel(r) === nv; })) {
							sec[cfg.categories].push({ label: nv });
						}
						state.galleryBulkCat = nv; setDirty(true); renderInspector();
					} else { catSel.value = state.galleryBulkCat || ''; }
					return;
				}
				state.galleryBulkCat = catSel.value;
			});

			var applyBtn = ce('button', 'aqb-btn aqb-btn--primary aqb-gbulk__apply', 'Apply to ' + count + ' selected'); applyBtn.type = 'button';
			applyBtn.disabled = !count;
			applyBtn.addEventListener('click', function () {
				if (!galSelCount() || !HIST || typeof HIST.applyCategory !== 'function') { return; }
				sec[cfg.items] = HIST.applyCategory(items, galSelIndices(), state.galleryBulkCat || '', cfg.category);
				galSelClear();
				setDirty(true); renderInspector(); pushChange();
			});
			actions.appendChild(catSel);
			actions.appendChild(applyBtn);
		}

		var rmBtn = ce('button', 'aqb-btn aqb-btn--ghost aqb-gbulk__remove', 'Remove ' + count + ' selected'); rmBtn.type = 'button';
		rmBtn.disabled = !count;
		rmBtn.addEventListener('click', function () {
			if (!galSelCount()) { return; }
			if (!window.confirm('Remove ' + galSelCount() + ' selected image(s) from this gallery?')) { return; }
			var drop = state.gallerySel || {};
			sec[cfg.items] = items.filter(function (_img, i) { return !drop[i]; });
			galSelClear();
			setDirty(true); renderInspector(); pushChange();
		});
		actions.appendChild(rmBtn);
		bar.appendChild(actions);
		return bar;
	}

	function galImageRow(sec, cfg, img, idx, sel) {
		var selected = !!(sel && sel[idx]);
		var row = ce('div', 'aqb-gimgrow' + (selected ? ' is-sel' : ''));

		var check = ce('input', 'aqb-gimgrow__check'); check.type = 'checkbox'; check.checked = selected;
		check.title = 'Select for bulk actions';
		check.addEventListener('change', function () {
			var s = galSelSet(sec);
			if (check.checked) { s[idx] = true; } else { delete s[idx]; }
			renderInspector();
		});
		row.appendChild(check);

		var val = img[cfg.image];
		var thumb = ce('div', 'aqb-gimgrow__thumb');
		var url = galThumb(val);
		if (url) { var im = ce('img'); im.src = url; im.alt = ''; thumb.appendChild(im); }
		else { thumb.appendChild(ce('span', 'aqb-gthumb__id', (val ? '' : '?') + (val != null && val !== '' ? String(val) : ''))); }
		row.appendChild(thumb);

		var fields = ce('div', 'aqb-gimgrow__fields');
		if (galHasCats(cfg)) { fields.appendChild(galCatSelect(sec, cfg, img)); }
		if (cfg.caption) {
			var cap = ce('input', 'aqb-ginput'); cap.type = 'text'; cap.placeholder = 'Caption (optional)';
			cap.value = img[cfg.caption] != null ? img[cfg.caption] : '';
			cap.addEventListener('input', function () { img[cfg.caption] = cap.value; setDirty(true); pushTyping(); });
			cap.addEventListener('change', function () { histRecord(); livePreview(); });
			fields.appendChild(cap);
		}
		row.appendChild(fields);

		var rm = ce('button', 'aqb-gimgrow__x', '×'); rm.title = 'Remove'; rm.type = 'button';
		rm.addEventListener('click', function () { galItems(sec, cfg).splice(idx, 1); galSelClear(); setDirty(true); renderInspector(); pushChange(); });
		row.appendChild(rm);
		return row;
	}

	function galCatSelect(sec, cfg, img) {
		var sel = ce('select', 'aqb-gcatsel');
		sel.appendChild(new Option('— category —', ''));
		galCatLabels(sec, cfg).forEach(function (l) { sel.appendChild(new Option(l, l)); });
		sel.appendChild(new Option('＋ New…', '__new'));
		var cur = img[cfg.category] || '';
		if (cur && !Array.prototype.some.call(sel.options, function (o) { return o.value === cur; })) {
			sel.insertBefore(new Option(cur, cur), sel.options[sel.options.length - 1]);
		}
		sel.value = cur;
		sel.addEventListener('change', function () {
			if (sel.value === '__new') {
				var nv = window.prompt('New category label:', '');
				nv = nv ? nv.trim() : '';
				if (nv) {
					if (cfg.categories && cfg.categories !== 'derive'
						&& !(sec[cfg.categories] || []).some(function (r) { return catLabel(r) === nv; })) {
						sec[cfg.categories].push({ label: nv });
					}
					img[cfg.category] = nv;
				} else { sel.value = img[cfg.category] || ''; return; }
			} else { img[cfg.category] = sel.value; }
			setDirty(true); renderInspector(); pushChange();
		});
		return sel;
	}

	function galCategories(sec, cfg) {
		var store = sec[cfg.categories] || [];
		var wrap = ce('div', 'aqb-gcats');
		wrap.appendChild(ce('div', 'aqb-glabel', 'Categories (tab order)'));
		var list = ce('div', 'aqb-gcatlist');
		store.forEach(function (row, i) {
			var chip = ce('span', 'aqb-gchip', catLabel(row));
			var x = ce('button', 'aqb-gchip__x', '×'); x.title = 'Remove';
			x.addEventListener('click', function () { store.splice(i, 1); setDirty(true); renderInspector(); pushChange(); });
			chip.appendChild(x);
			list.appendChild(chip);
		});
		wrap.appendChild(list);
		var addWrap = ce('div', 'aqb-gcatadd');
		var input = ce('input', 'aqb-ginput'); input.type = 'text'; input.placeholder = 'Add category…';
		function commit() {
			var v = input.value.trim();
			if (v && !store.some(function (r) { return catLabel(r) === v; })) { store.push({ label: v }); setDirty(true); renderInspector(); pushChange(); }
			else { input.value = ''; }
		}
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
		var addBtn = ce('button', 'aqb-btn aqb-btn--ghost', 'Add');
		addBtn.addEventListener('click', commit);
		addWrap.appendChild(input); addWrap.appendChild(addBtn);
		wrap.appendChild(addWrap);
		return wrap;
	}

	// Shape a new image row using the configured sub-field names.
	function galNewRow(cfg, value) {
		var row = {};
		row[cfg.image] = value;
		if (cfg.caption) { row[cfg.caption] = ''; }
		if (cfg.category) { row[cfg.category] = ''; }
		return row;
	}

	function addGalleryImages() {
		var sec = galSec();
		if (!sec) { return; }
		var cfg = galleryCfg(sec.type);
		galEnsure(sec, cfg);
		var items = galItems(sec, cfg);
		var basenameFmt = cfg.image_format === 'basename';
		if (!window.wp || !wp.media) {
			var raw = window.prompt(basenameFmt ? 'Image filenames (comma-separated):' : 'Media library attachment IDs (comma-separated):', '');
			if (raw) {
				raw.split(',').forEach(function (x) {
					var t = String(x).trim(); if (!t) { return; }
					var v = basenameFmt ? t : parseInt(t, 10);
					if (basenameFmt ? !!v : v > 0) { items.push(galNewRow(cfg, v)); }
				});
				galSelClear(); setDirty(true); renderInspector(); pushChange();
			}
			return;
		}
		var frame = wp.media({ title: 'Add gallery images', button: { text: 'Add to gallery' }, multiple: true, library: { type: 'image' } });
		frame.on('select', function () {
			frame.state().get('selection').each(function (att) {
				var a = att.toJSON();
				var thumb = (a.sizes && a.sizes.thumbnail && a.sizes.thumbnail.url) ? a.sizes.thumbnail.url : a.url;
				var value = basenameFmt ? (a.filename || imageBasename(a.url)) : a.id;
				state.galleryImages[String(value)] = { id: a.id, url: a.url, thumb: thumb, alt: a.alt || '' };
				items.push(galNewRow(cfg, value));
			});
			galSelClear(); setDirty(true); renderInspector(); pushChange();
		});
		frame.open();
	}

	// Apply a canvas tile-drag reorder: reorder the section's items by the received
	// original-index order, then snapshot history + refresh the preview.
	function applyGalleryReorder(m) {
		var sec = state.sections[m.index];
		if (!sec || !Array.isArray(m.order)) { return; }
		var cfg = galleryCfg(sec.type);
		if (!Array.isArray(sec[cfg.items]) || !HIST || typeof HIST.reorder !== 'function') { return; }
		sec[cfg.items] = HIST.reorder(sec[cfg.items], m.order);
		galSelClear(); // indices shifted → drop the transient multi-selection
		if (state.selected !== m.index) { selectSection(m.index, false); }
		else { renderInspector(); }
		setDirty(true); pushChange();
	}

	/* ---------------- save ---------------- */
	// onDone(ok) — optional; called after the save resolves so callers (e.g. the page
	// switcher's "Save & go") can act on success. When it returns truthy the caller
	// has taken over the post-save UI (e.g. navigating away) so we skip the canvas
	// reload that would otherwise run.
	function save(onDone) {
		els.save.disabled = true; els.save.textContent = 'Saving…';
		api('/save', { method: 'POST', body: { id: CFG.pageId, sections: stripSections() } })
			.then(function (d) {
				if (d && d.ok) {
					setDirty(false);
					state.base = clone(state.sections); // committed → next (optional) review diffs from here
					histSeed(); // saved state becomes the new undo baseline
					state.rehighlight = state.selected;
					var handled = (typeof onDone === 'function') && onDone(true);
					if (!handled) { els.iframe.src = CFG.canvasUrl; } // reload to show the true render
				} else {
					els.save.disabled = false; els.save.textContent = 'Save changes';
					window.alert('Save failed: ' + ((d && (d.message || d.code)) || 'unknown error'));
					if (typeof onDone === 'function') { onDone(false); }
				}
			})
			.catch(function (e) {
				els.save.disabled = false; els.save.textContent = 'Save changes';
				window.alert('Save failed: ' + e.message);
				if (typeof onDone === 'function') { onDone(false); }
			});
	}

	/* ---------------- canvas bridge ---------------- */
	function postCanvas(msg) {
		try {
			msg.source = 'aq-builder';
			els.iframe.contentWindow.postMessage(msg, ORIGIN);
		} catch (e) { /* iframe not ready */ }
	}
	window.addEventListener('message', function (e) {
		if (e.origin !== ORIGIN || !e.data || e.data.source !== 'aq-canvas') { return; }
		var m = e.data;
		if (m.type === 'select') {
			selectSection(m.index, false);
			if (m.field || m.repeater) { focusField(m); }
		} else if (m.type === 'gallery-reorder') {
			// Tiles were drag-reordered on the canvas → apply the new image order.
			applyGalleryReorder(m);
		} else if (m.type === 'edit') {
			applyEdit(m);
		} else if (m.type === 'ready') {
			postCanvas({ type: 'schema', schema: CFG.schema }); // let the canvas decide which fields edit in place
			if (state.rehighlight >= 0) {
				postCanvas({ type: 'highlight', index: state.rehighlight });
				state.rehighlight = -1;
			}
		}
	});

	/** Apply an in-place edit coming from the canvas into the working state. */
	function applyEdit(m) {
		var s = state.sections[m.index];
		if (!s) { return; }
		if (m.repeater != null && m.rindex != null) {
			if (!Array.isArray(s[m.repeater]) || !s[m.repeater][m.rindex]) { return; }
			s[m.repeater][m.rindex][m.field] = m.value;
		} else if (m.field) {
			s[m.field] = m.value;
		} else {
			return;
		}
		setDirty(true);
		syncInspectorInput(m); // keep the inspector field in step (no re-render → no focus loss)
		// The canvas already shows this text live. Snapshot history (coalesced while
		// typing); on commit (blur/Enter → m.done) refresh the true render too.
		if (m.done) { histRecord(); livePreview(); } else { histRecordDebounced(); }
	}
	function syncInspectorInput(m) {
		if (state.selected !== m.index) { return; }
		var wrap;
		if (m.repeater != null && m.rindex != null) {
			var card = els.inspector.querySelector('[data-aqi="' + cssEsc(m.repeater) + ':' + m.rindex + '"]');
			wrap = card && card.querySelector('[data-aqf="' + cssEsc(m.field) + '"]');
		} else {
			wrap = els.inspector.querySelector('.aqb-field--top[data-aqf="' + cssEsc(m.field) + '"]');
		}
		if (!wrap) { return; }
		var input = wrap.querySelector('input, textarea');
		if (input && input.value !== m.value) { input.value = m.value; }
	}

	/* ---------------- boot ---------------- */
	function boot() {
		buildShell();
		els.inspector.appendChild(ce('div', 'aqb-empty', 'Loading…'));
		api('/page/' + CFG.pageId).then(function (d) {
			state.images = (d && d.images) ? d.images : {};
			state.galleryImages = (d && d.galleryImages) ? d.galleryImages : {};
			state.sections = (d && d.sections ? d.sections : []).map(function (s) { s._uid = ++uid; return s; });
			state.base = clone(state.sections); // snapshot the loaded page to diff against at review time
			renderStructure();
			renderInspector();
			setDirty(false);
			histSeed(); // baseline history step (undo returns here)
		});
		window.addEventListener('beforeunload', function (e) {
			if (state.dirty) { e.preventDefault(); e.returnValue = ''; }
		});
		// Keyboard: Ctrl/Cmd+Z = undo, Ctrl/Cmd+Shift+Z or Ctrl+Y = redo.
		window.addEventListener('keydown', function (e) {
			var mod = e.ctrlKey || e.metaKey;
			if (!mod) { return; }
			var k = (e.key || '').toLowerCase();
			if (k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
			else if ((k === 'z' && e.shiftKey) || k === 'y') { e.preventDefault(); redo(); }
		});
	}
	if (document.readyState !== 'loading') { boot(); }
	else { document.addEventListener('DOMContentLoaded', boot); }
})();
