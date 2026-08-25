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

	var state = { sections: [], base: [], selected: -1, dirty: false, device: 'desktop', rehighlight: -1, images: {}, review: null, decisions: {}, confirmed: {} };
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

		var mid = ce('div', 'aqb-topbar__m');
		['desktop', 'tablet', 'mobile'].forEach(function (d) {
			var b = ce('button', 'aqb-dev' + (d === 'desktop' ? ' is-active' : ''), d.charAt(0).toUpperCase() + d.slice(1));
			b.addEventListener('click', function () { setDevice(d); });
			b.setAttribute('data-dev', d);
			mid.appendChild(b);
		});
		els.dev = mid;

		var right = ce('div', 'aqb-topbar__r');
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
		Object.keys(CFG.labels || {}).forEach(function (type) {
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
			cb.addEventListener('change', function () { obj[f.name] = cb.checked; setDirty(true); });
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
			wrap.appendChild(renderImage(obj, f));
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
		});
		wrap.appendChild(input);
		if (f.type === 'richtext') { wrap.appendChild(ce('span', 'aqb-hint', 'Basic HTML allowed (links, bold, italic).')); }
		return wrap;
	}

	/* ---------------- image field (media library) ---------------- */
	function imageBasename(url) {
		return (url || '').split('?')[0].split('#')[0].split('/').pop();
	}
	function renderImage(obj, f) {
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
		choose.addEventListener('click', function () { openMedia(obj, f, paint); });
		btns.appendChild(choose);
		var clear = ce('button', 'aqb-btn aqb-btn--ghost', 'Remove');
		clear.type = 'button';
		clear.addEventListener('click', function () { obj[f.name] = ''; setDirty(true); paint(); });
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
			sw.addEventListener('click', function () { obj[f.name] = icons[name]; setDirty(true); paint(); grid.style.display = 'none'; });
			grid.appendChild(sw);
		});

		var btns = ce('div', 'aqb-img__btns');
		var choose = ce('button', 'aqb-btn aqb-btn--ghost', 'Choose icon'); choose.type = 'button';
		choose.addEventListener('click', function () { grid.style.display = grid.style.display === 'none' ? 'grid' : 'none'; });
		btns.appendChild(choose);
		var clear = ce('button', 'aqb-btn aqb-btn--ghost', 'Remove'); clear.type = 'button';
		clear.addEventListener('click', function () { obj[f.name] = ''; setDirty(true); paint(); });
		btns.appendChild(clear);
		box.appendChild(btns);
		box.appendChild(grid);

		var adv = ce('details', 'aqb-iconadv');
		adv.appendChild(ce('summary', null, 'Paste custom SVG'));
		var ta = ce('textarea', 'aqb-input aqb-textarea aqb-code'); ta.rows = 4;
		ta.value = obj[f.name] != null ? obj[f.name] : '';
		ta.addEventListener('input', function () { obj[f.name] = ta.value; setDirty(true); paint(); });
		adv.appendChild(ta);
		box.appendChild(adv);
		return box;
	}

	function openMedia(obj, f, paint) {
		if (!window.wp || !wp.media) {
			var fn = window.prompt('Image filename from the media library:', obj[f.name] || '');
			if (fn != null) { obj[f.name] = fn; setDirty(true); paint(); }
			return;
		}
		var frame = wp.media({ title: 'Select image', button: { text: 'Use image' }, multiple: false, library: { type: 'image' } });
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			var url = att.url || '';
			var name = imageBasename(url);
			var thumbUrl = (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) ? att.sizes.thumbnail.url : url;
			obj[f.name] = name;
			state.images[name] = { id: att.id, url: url, thumb: thumbUrl };
			setDirty(true);
			paint();
		});
		frame.open();
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
			tools.appendChild(iconBtn('↑', 'Up', function () { if (ri > 0) { rows.splice(ri - 1, 0, rows.splice(ri, 1)[0]); setDirty(true); renderInspector(); } }));
			tools.appendChild(iconBtn('↓', 'Down', function () { if (ri < rows.length - 1) { rows.splice(ri + 1, 0, rows.splice(ri, 1)[0]); setDirty(true); renderInspector(); } }));
			tools.appendChild(iconBtn('✕', 'Remove', function () { rows.splice(ri, 1); setDirty(true); renderInspector(); }, true));
			head.appendChild(tools);
			card.appendChild(head);
			(f.subfields || []).forEach(function (sf) { card.appendChild(renderField(row, sf, { repeater: f.name, rindex: ri })); });
			box.appendChild(card);
		});
		var add = ce('button', 'aqb-btn aqb-btn--ghost aqb-addrow', '+ Add ' + (f.label || 'item').toLowerCase().replace(/s$/, ''));
		add.addEventListener('click', function () {
			var blank = {};
			(f.subfields || []).forEach(function (sf) { blank[sf.name] = sf.type === 'toggle' ? false : ''; });
			rows.push(blank); setDirty(true); renderInspector();
		});
		box.appendChild(add);
		return box;
	}

	/* ---------------- structure ops ---------------- */
	function move(i, dir) {
		var j = i + dir;
		if (j < 0 || j >= state.sections.length) { return; }
		var tmp = state.sections[i]; state.sections[i] = state.sections[j]; state.sections[j] = tmp;
		state.selected = j; setDirty(true); renderStructure(); renderInspector();
	}
	function duplicate(i) {
		var copy = JSON.parse(JSON.stringify(state.sections[i]));
		copy._uid = ++uid;
		state.sections.splice(i + 1, 0, copy);
		state.selected = i + 1; setDirty(true); renderStructure(); renderInspector();
	}
	function removeSection(i) {
		if (!window.confirm('Remove this ' + labelFor(state.sections[i].type) + ' section?')) { return; }
		state.sections.splice(i, 1);
		if (state.selected >= state.sections.length) { state.selected = state.sections.length - 1; }
		setDirty(true); renderStructure(); renderInspector();
	}
	function addSection(type) {
		var s = { type: type, v: 1, _uid: ++uid };
		var at = state.selected >= 0 ? state.selected + 1 : state.sections.length;
		state.sections.splice(at, 0, s);
		state.selected = at; setDirty(true); renderStructure(); renderInspector();
	}

	/* ---------------- save ---------------- */
	function save() {
		els.save.disabled = true; els.save.textContent = 'Saving…';
		var payload = state.sections.map(function (s) { var c = {}; for (var k in s) { if (k.charAt(0) !== '_') { c[k] = s[k]; } } return c; });
		api('/save', { method: 'POST', body: { id: CFG.pageId, sections: payload } })
			.then(function (d) {
				if (d && d.ok) {
					setDirty(false);
					state.base = clone(state.sections); // committed → next (optional) review diffs from here
					state.rehighlight = state.selected;
					els.iframe.src = CFG.canvasUrl; // reload to show the true render
				} else {
					els.save.disabled = false; els.save.textContent = 'Save changes';
					window.alert('Save failed: ' + ((d && (d.message || d.code)) || 'unknown error'));
				}
			})
			.catch(function (e) {
				els.save.disabled = false; els.save.textContent = 'Save changes';
				window.alert('Save failed: ' + e.message);
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
			state.sections = (d && d.sections ? d.sections : []).map(function (s) { s._uid = ++uid; return s; });
			state.base = clone(state.sections); // snapshot the loaded page to diff against at review time
			renderStructure();
			renderInspector();
			setDirty(false);
		});
		window.addEventListener('beforeunload', function (e) {
			if (state.dirty) { e.preventDefault(); e.returnValue = ''; }
		});
	}
	if (document.readyState !== 'loading') { boot(); }
	else { document.addEventListener('DOMContentLoaded', boot); }
})();
