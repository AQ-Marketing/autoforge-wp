/**
 * AQ Assistant — front-end panel for the admin-only, live-site SEO-guardian.
 *
 * Runs on the real published page for a logged-in admin. The PHP side
 * (AQ_Assistant) enqueues this, prints section/field markers, and exposes
 * window.AQ_ASSIST = { restRoot, knowledge, builder, nonce, pageId, labels, stickyBar }.
 *
 * Vanilla JS, no libraries, no build step. All classes are namespaced .aq-asst-*.
 * Endpoints: GET  restRoot/context/{pageId}
 *            POST restRoot/message   { page_id, selection|null, message }
 *            POST restRoot/apply     { page_id, proposalId, alternativeIndex? }
 *            POST restRoot/undo      { logId }
 */
(function () {
	'use strict';

	var CFG = window.AQ_ASSIST;
	if (!CFG || !CFG.restRoot) { return; }

	var LABELS = CFG.labels || {};
	var REDUCE = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ------------------------------------------------------------------ *
	 * Small DOM helpers
	 * ------------------------------------------------------------------ */

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text != null) { n.textContent = text; }
		return n;
	}
	function titleize(s) {
		return String(s || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}
	function snip(s, max) {
		s = String(s == null ? '' : s).replace(/\s+/g, ' ').trim();
		return s.length > max ? s.slice(0, max - 1) + '…' : s;
	}

	/* Guarded JSON fetch — always same-origin + nonce; never throws. */
	function api(path, method, body) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': CFG.nonce, 'Content-Type': 'application/json' }
		};
		if (body !== undefined) { opts.body = JSON.stringify(body); }
		return fetch(CFG.restRoot + path, opts).then(function (r) {
			return r.json().then(function (j) { return j; }, function () { return { ok: false, _httpError: true }; });
		});
	}

	/* ------------------------------------------------------------------ *
	 * Selection: resolve a clicked node to an editable field
	 * ------------------------------------------------------------------ */

	function sectionOf(node) {
		while (node && node !== document.body) {
			if (node.nodeType === 1 && node.hasAttribute && node.hasAttribute('data-aq-section')) { return node; }
			node = node.parentNode;
		}
		return null;
	}

	/**
	 * Walk UP from a clicked node (bounded by its section). The deepest
	 * data-aq-field that is NOT the repeater-item wrapper is the leaf field;
	 * the nearest data-aq-rindex ancestor is the repeater item.
	 */
	function resolveFrom(target) {
		var sectionEl = sectionOf(target);
		if (!sectionEl) { return null; }
		var node = target, fieldEl = null, itemEl = null;
		while (node && node.nodeType === 1) {
			if (node.hasAttribute) {
				if (!fieldEl && node.hasAttribute('data-aq-field')) { fieldEl = node; }
				if (!itemEl && node.hasAttribute('data-aq-rindex')) { itemEl = node; }
			}
			if (node === sectionEl) { break; }
			node = node.parentNode;
		}
		var field = fieldEl ? fieldEl.getAttribute('data-aq-field') : null;
		var repeater = null, rindex = null;
		if (itemEl) {
			repeater = itemEl.getAttribute('data-aq-field'); // item wrapper carries the repeater name
			rindex = parseInt(itemEl.getAttribute('data-aq-rindex'), 10);
			if (fieldEl === itemEl) { field = null; } // clicked the wrapper itself, no real subfield
		}
		if (!field) { return null; } // require a real text field
		var sel = {
			sectionIndex: parseInt(sectionEl.getAttribute('data-aq-section'), 10),
			layout: sectionEl.getAttribute('data-aq-layout') || '',
			field: field,
			repeater: repeater,
			rindex: (repeater && rindex === rindex) ? rindex : null
		};
		sel._el = fieldEl;
		sel._text = (fieldEl.innerText || fieldEl.textContent || '').trim();
		return sel;
	}

	/* Find the live DOM element for a stored selection (for apply/undo). */
	function elementFor(sel) {
		if (!sel || sel.kind === 'seo') { return null; }
		var sec = document.querySelector('[data-aq-section="' + sel.sectionIndex + '"]');
		if (!sec) { return null; }
		if (sel.repeater != null && sel.rindex != null) {
			var item = sec.querySelector('[data-aq-field="' + sel.repeater + '"][data-aq-rindex="' + sel.rindex + '"]');
			if (!item) { return null; }
			return sel.field ? (item.querySelector('[data-aq-field="' + sel.field + '"]') || item) : item;
		}
		return sel.field ? sec.querySelector('[data-aq-field="' + sel.field + '"]') : null;
	}

	/* Human label for a selection: "Hero › Heading". */
	function labelFor(sel) {
		if (!sel) { return ''; }
		if (sel.kind === 'seo') { return sel.field === 'seo_description' ? 'Page SEO — description' : 'Page SEO — title'; }
		var secLabel = LABELS[sel.layout] || titleize(sel.layout || 'section');
		if (sel.repeater) { return secLabel + ' › ' + titleize(sel.repeater) + ' › ' + titleize(sel.field); }
		return secLabel + ' › ' + titleize(sel.field);
	}

	/* The wire selection object the server expects. */
	function wireSelection(sel) {
		if (!sel) { return null; }
		if (sel.kind === 'seo') { return { kind: 'seo', field: sel.field }; }
		var out = { sectionIndex: sel.sectionIndex, layout: sel.layout, field: sel.field, repeater: null, rindex: null };
		if (sel.repeater) { out.repeater = sel.repeater; out.rindex = sel.rindex; }
		else { delete out.repeater; delete out.rindex; }
		return out;
	}

	/* ------------------------------------------------------------------ *
	 * State + root nodes
	 * ------------------------------------------------------------------ */

	var state = {
		open: false,
		selection: null,
		selectMode: false,
		contextLoaded: false,
		rehydrated: false,
		threadCache: [],
		hoverSel: null
	};

	/* Per-page localStorage mirror key (instant paint before the network). */
	var MIRROR_KEY = 'aq_asst_' + (location && location.pathname ? location.pathname : '');
	function mirrorSave(arr) { try { localStorage.setItem(MIRROR_KEY, JSON.stringify(arr)); } catch (e) { /* private window */ } }
	function mirrorLoad() {
		try {
			var raw = localStorage.getItem(MIRROR_KEY);
			var a = raw ? JSON.parse(raw) : null;
			return Array.isArray(a) ? a : null;
		} catch (e) { return null; }
	}
	function mirrorClear() { try { localStorage.removeItem(MIRROR_KEY); } catch (e) { /* private window */ } }

	var root, launcher, panel, threadEl, textarea, chipArea, noteArea, sendBtn, seoPopover;
	var overlay, hiBox, hiLabel, tipEl;

	/* ------------------------------------------------------------------ *
	 * Build the launcher + panel (once)
	 * ------------------------------------------------------------------ */

	function build() {
		root = el('div', 'aq-asst-root');
		root.setAttribute('data-aq-asst', '1');

		/* Launcher */
		launcher = el('button', 'aq-asst-launcher');
		launcher.type = 'button';
		launcher.setAttribute('aria-label', 'Open the site assistant');
		launcher.appendChild(el('span', 'aq-asst-launcher-ico', '💬'));
		launcher.appendChild(el('span', 'aq-asst-launcher-txt', 'Assistant'));
		if (CFG.stickyBar) { launcher.classList.add('aq-asst-launcher--sticky'); }
		launcher.addEventListener('click', openPanel);
		root.appendChild(launcher);

		/* Panel */
		panel = el('div', 'aq-asst-panel');
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-label', 'Site assistant');
		panel.setAttribute('aria-modal', 'false');
		panel.hidden = true;
		if (REDUCE) { panel.classList.add('aq-asst-noanim'); }

		var header = el('div', 'aq-asst-header');
		header.appendChild(el('div', 'aq-asst-title', 'Assistant'));
		var hactions = el('div', 'aq-asst-hactions');
		var clearBtn = el('button', 'aq-asst-clear');
		clearBtn.type = 'button';
		clearBtn.textContent = 'Clear';
		clearBtn.setAttribute('aria-label', 'Clear this conversation');
		clearBtn.addEventListener('click', clearThread);
		hactions.appendChild(clearBtn);
		var closeBtn = el('button', 'aq-asst-x');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', 'Close assistant');
		closeBtn.textContent = '✕';
		closeBtn.addEventListener('click', closePanel);
		hactions.appendChild(closeBtn);
		header.appendChild(hactions);
		panel.appendChild(header);

		noteArea = el('div', 'aq-asst-notes');
		noteArea.hidden = true;
		panel.appendChild(noteArea);

		threadEl = el('div', 'aq-asst-thread');
		threadEl.setAttribute('aria-live', 'polite');
		threadEl.setAttribute('role', 'log');
		panel.appendChild(threadEl);

		/* Composer */
		var composer = el('div', 'aq-asst-composer');

		var tools = el('div', 'aq-asst-tools');
		var pointBtn = el('button', 'aq-asst-tool');
		pointBtn.type = 'button';
		pointBtn.textContent = '⌖ Point at something';
		pointBtn.addEventListener('click', function () { toggleSelectMode(); });
		tools.appendChild(pointBtn);

		var seoBtn = el('button', 'aq-asst-tool');
		seoBtn.type = 'button';
		seoBtn.textContent = 'Page SEO';
		seoBtn.setAttribute('aria-haspopup', 'true');
		seoBtn.addEventListener('click', function (e) { e.stopPropagation(); toggleSeoPopover(seoBtn); });
		tools.appendChild(seoBtn);
		composer.appendChild(tools);

		seoPopover = el('div', 'aq-asst-seopop');
		seoPopover.hidden = true;
		['seo_title', 'seo_description'].forEach(function (f) {
			var b = el('button', 'aq-asst-seopop-opt');
			b.type = 'button';
			b.textContent = f === 'seo_title' ? 'SEO title' : 'SEO description';
			b.addEventListener('click', function () {
				setSelection({ kind: 'seo', field: f });
				seoPopover.hidden = true;
			});
			seoPopover.appendChild(b);
		});
		composer.appendChild(seoPopover);

		chipArea = el('div', 'aq-asst-chiparea');
		chipArea.hidden = true;
		composer.appendChild(chipArea);

		var inputRow = el('div', 'aq-asst-inputrow');
		textarea = el('textarea', 'aq-asst-textarea');
		textarea.setAttribute('rows', '2');
		textarea.setAttribute('placeholder', 'Tell me what to change, or ask a question…');
		textarea.setAttribute('aria-label', 'Message the assistant');
		textarea.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); send(); }
		});
		inputRow.appendChild(textarea);

		sendBtn = el('button', 'aq-asst-send');
		sendBtn.type = 'button';
		sendBtn.textContent = 'Send';
		sendBtn.addEventListener('click', send);
		inputRow.appendChild(sendBtn);
		composer.appendChild(inputRow);

		panel.appendChild(composer);
		root.appendChild(panel);

		document.body.appendChild(root);

		/* Select-mode overlay + highlight + tip live at body level. */
		overlay = el('div', 'aq-asst-selveil');
		overlay.hidden = true;
		hiBox = el('div', 'aq-asst-hibox');
		hiBox.hidden = true;
		hiLabel = el('div', 'aq-asst-hilabel');
		hiLabel.hidden = true;
		tipEl = el('div', 'aq-asst-tip');
		tipEl.hidden = true;
		document.body.appendChild(overlay);
		document.body.appendChild(hiBox);
		document.body.appendChild(hiLabel);
		document.body.appendChild(tipEl);

		/* Global key + click-away handling. */
		document.addEventListener('keydown', onKeydown, true);
		document.addEventListener('click', function (e) {
			if (seoPopover && !seoPopover.hidden && !seoPopover.contains(e.target)) { seoPopover.hidden = true; }
		});
	}

	function toggleSeoPopover(anchor) {
		seoPopover.hidden = !seoPopover.hidden;
		if (!seoPopover.hidden && anchor) {
			seoPopover.style.left = anchor.offsetLeft + 'px';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Open / close
	 * ------------------------------------------------------------------ */

	function openPanel() {
		if (!root) { build(); }
		state.open = true;
		panel.hidden = false;
		launcher.classList.add('aq-asst-launcher--hidden');
		requestAnimationFrame(function () { panel.classList.add('aq-asst-panel--in'); });
		if (textarea) { textarea.focus(); }
		if (!state.contextLoaded) { loadContext(); }
		if (!state.rehydrated) { rehydrate(); }
	}

	function closePanel() {
		if (state.selectMode) { exitSelectMode(); }
		state.open = false;
		panel.classList.remove('aq-asst-panel--in');
		if (seoPopover) { seoPopover.hidden = true; }
		var finish = function () { panel.hidden = true; };
		if (REDUCE) { finish(); } else { setTimeout(finish, 180); }
		launcher.classList.remove('aq-asst-launcher--hidden');
		launcher.focus();
	}

	function onKeydown(e) {
		if (e.key !== 'Escape') { return; }
		if (state.selectMode) { e.preventDefault(); exitSelectMode(); return; }
		if (state.open) { e.preventDefault(); closePanel(); }
	}

	function loadContext() {
		state.contextLoaded = true;
		api('/context/' + CFG.pageId, 'GET').then(function (j) {
			if (!j || j.ok === false) { return; }
			var notes = [];
			if (!j.hasFullPlan) { notes.push('This page has a basic plan only.'); }
			if (!j.hasBrief) { notes.push('No client brief yet.'); }
			if (notes.length) {
				noteArea.textContent = '';
				notes.forEach(function (t) { noteArea.appendChild(el('div', 'aq-asst-note', t)); });
				noteArea.hidden = false;
			}
		}, function () { /* non-fatal */ });
	}

	/* ------------------------------------------------------------------ *
	 * Selection chip
	 * ------------------------------------------------------------------ */

	function setSelection(sel) {
		state.selection = sel;
		renderChip();
	}
	function clearSelection() {
		state.selection = null;
		renderChip();
	}
	function renderChip() {
		chipArea.textContent = '';
		if (!state.selection) { chipArea.hidden = true; return; }
		var sel = state.selection;
		var chip = el('div', 'aq-asst-chip');
		var lbl = el('span', 'aq-asst-chip-lbl', 'Selected: ' + labelFor(sel));
		chip.appendChild(lbl);
		var snippet = sel.kind === 'seo' ? '' : (sel._text || '');
		if (snippet) { chip.appendChild(el('span', 'aq-asst-chip-snip', '“' + snip(snippet, 60) + '”')); }
		var x = el('button', 'aq-asst-chip-x');
		x.type = 'button';
		x.setAttribute('aria-label', 'Clear selection');
		x.textContent = '✕';
		x.addEventListener('click', clearSelection);
		chip.appendChild(x);
		chipArea.appendChild(chip);
		chipArea.hidden = false;
	}

	/* ------------------------------------------------------------------ *
	 * Point-and-ask select mode
	 * ------------------------------------------------------------------ */

	function toggleSelectMode() {
		if (state.selectMode) { exitSelectMode(); } else { enterSelectMode(); }
	}

	function enterSelectMode() {
		state.selectMode = true;
		overlay.hidden = false;
		document.body.classList.add('aq-asst-selecting');
		document.addEventListener('mousemove', onSelectHover, true);
		document.addEventListener('click', onSelectClick, true);
		window.addEventListener('scroll', hideHighlight, true);
	}

	function exitSelectMode() {
		state.selectMode = false;
		state.hoverSel = null;
		overlay.hidden = true;
		hideHighlight();
		hideTip();
		document.body.classList.remove('aq-asst-selecting');
		document.removeEventListener('mousemove', onSelectHover, true);
		document.removeEventListener('click', onSelectClick, true);
		window.removeEventListener('scroll', hideHighlight, true);
	}

	function isOurs(node) {
		while (node) {
			if (node === root || (node.classList && node.classList.contains && node.classList.contains('aq-asst-tip'))) { return true; }
			node = node.parentNode;
		}
		return false;
	}

	function onSelectHover(e) {
		if (isOurs(e.target)) { hideHighlight(); return; }
		var sel = resolveFrom(e.target);
		state.hoverSel = sel;
		if (sel && sel._el) { showHighlight(sel._el, labelFor(sel)); }
		else { hideHighlight(); }
	}

	function onSelectClick(e) {
		if (isOurs(e.target)) { return; }
		e.preventDefault();
		e.stopPropagation();
		var sel = resolveFrom(e.target);
		if (sel) {
			setSelection(sel);
			exitSelectMode();
			if (state.open && textarea) { textarea.focus(); }
		} else {
			showTip(e.clientX, e.clientY);
		}
	}

	function showHighlight(target, text) {
		var r = target.getBoundingClientRect();
		hiBox.hidden = false;
		hiBox.style.top = (r.top + window.scrollY) + 'px';
		hiBox.style.left = (r.left + window.scrollX) + 'px';
		hiBox.style.width = r.width + 'px';
		hiBox.style.height = r.height + 'px';
		hiLabel.hidden = false;
		hiLabel.textContent = text;
		var ly = (r.top + window.scrollY) - 22;
		if (ly < window.scrollY + 2) { ly = r.top + window.scrollY + 2; }
		hiLabel.style.top = ly + 'px';
		hiLabel.style.left = (r.left + window.scrollX) + 'px';
	}
	function hideHighlight() { hiBox.hidden = true; hiLabel.hidden = true; }

	function showTip(x, y) {
		tipEl.textContent = '';
		tipEl.appendChild(el('span', null, "That text isn't editable here yet — open it in the builder. "));
		var a = el('a', 'aq-asst-tip-link', 'Open the builder');
		a.href = CFG.builder;
		a.target = '_blank';
		a.rel = 'noopener';
		tipEl.appendChild(a);
		tipEl.hidden = false;
		tipEl.style.left = Math.min(x + window.scrollX, window.scrollX + window.innerWidth - 280) + 'px';
		tipEl.style.top = (y + window.scrollY + 14) + 'px';
		clearTimeout(showTip._t);
		showTip._t = setTimeout(hideTip, 5000);
	}
	function hideTip() { tipEl.hidden = true; }

	/* ------------------------------------------------------------------ *
	 * Thread bubbles
	 * ------------------------------------------------------------------ */

	function addBubble(role, text) {
		var b = el('div', 'aq-asst-bubble aq-asst-bubble--' + role);
		b.textContent = text;
		threadEl.appendChild(b);
		scrollThread();
		return b;
	}
	function scrollThread() { threadEl.scrollTop = threadEl.scrollHeight; }

	function addThinking() {
		var t = el('div', 'aq-asst-bubble aq-asst-bubble--assistant aq-asst-thinking');
		t.setAttribute('aria-label', 'Assistant is thinking');
		t.appendChild(el('span', 'aq-asst-dot'));
		t.appendChild(el('span', 'aq-asst-dot'));
		t.appendChild(el('span', 'aq-asst-dot'));
		threadEl.appendChild(t);
		scrollThread();
		return t;
	}

	/* ------------------------------------------------------------------ *
	 * Send a message
	 * ------------------------------------------------------------------ */

	function send() {
		var msg = (textarea.value || '').trim();
		if (!msg) { textarea.focus(); return; }
		var selWire = wireSelection(state.selection);
		var selSnapshot = state.selection; // remember for DOM updates after apply

		addBubble('user', msg);
		cachePush({ role: 'user', text: msg });
		textarea.value = '';
		var thinking = addThinking();
		setBusy(true);

		api('/message', 'POST', { page_id: CFG.pageId, selection: selWire, message: msg })
			.then(function (j) {
				thinking.remove();
				setBusy(false);
				if (!j || j.ok === false) {
					addBubble('assistant', friendlyError(j));
					return;
				}
				handleReply(j, selSnapshot);
			}, function () {
				thinking.remove();
				setBusy(false);
				addBubble('assistant', "Something went wrong reaching the assistant. Please try again.");
			});
	}

	function friendlyError(j) {
		if (j && j.message) { return String(j.message); }
		return "I couldn't complete that just now. Please try again.";
	}

	function setBusy(on) {
		sendBtn.disabled = on;
		sendBtn.textContent = on ? 'Sending…' : 'Send';
	}

	/* Live reply: record it in the cache/mirror, then render (side effects on). */
	function handleReply(j, selSnapshot) {
		var entry = { role: 'assistant', text: j.text || '', kind: j.kind };
		if (j.card) { entry.card = j.card; }
		cachePush(entry);
		renderAssistant(j, selSnapshot, true);
	}

	/**
	 * Shared assistant renderer — used for BOTH live replies and rehydrated
	 * entries. `live` controls side effects (need_selection auto-enters select
	 * mode only for a fresh reply, never on rehydrate). Rehydrated proposal
	 * cards pass a null selSnapshot: Apply still works via proposalId; only the
	 * optimistic in-place DOM update is skipped (the true render returns on reload).
	 */
	function renderAssistant(j, selSnapshot, live) {
		var kind = j.kind;
		if (kind === 'answer') {
			addBubble('assistant', j.text || 'OK.');
			return;
		}
		if (kind === 'need_selection') {
			addBubble('assistant', j.text || "Click the text you'd like to change, then tell me what to do.");
			if (live) { enterSelectMode(); }
			return;
		}
		if (kind === 'safe' || kind === 'adjusted') {
			if (j.text) { addBubble('assistant', j.text); }
			renderProposalCard(j.card || {}, kind, selSnapshot);
			return;
		}
		if (kind === 'blocked') {
			if (j.text) { addBubble('assistant', j.text); }
			renderBlockedCard(j.card || {});
			return;
		}
		addBubble('assistant', j.text || 'OK.');
	}

	/* Render one stored thread entry through the same paths as a live reply. */
	function renderEntry(entry) {
		if (!entry) { return; }
		if (entry.role === 'user') { addBubble('user', entry.text || ''); return; }
		renderAssistant({ kind: entry.kind, text: entry.text, card: entry.card }, null, false);
	}

	/* Repaint the whole thread from an array of stored entries (no duplicates). */
	function paintThread(arr) {
		if (!threadEl) { return; }
		threadEl.textContent = '';
		state.threadCache = Array.isArray(arr) ? arr.slice() : [];
		state.threadCache.forEach(renderEntry);
		scrollThread();
	}

	function cachePush(entry) {
		state.threadCache.push(entry);
		mirrorSave(state.threadCache);
	}

	/**
	 * Rehydrate the conversation: paint instantly from the localStorage mirror,
	 * then reconcile with the server (source of truth). Runs once.
	 */
	function rehydrate() {
		if (state.rehydrated) { return; }
		state.rehydrated = true;

		var cached = mirrorLoad();
		if (cached && cached.length) { paintThread(cached); }

		api('/thread/' + CFG.pageId, 'GET').then(function (j) {
			if (!j || j.ok === false || !Array.isArray(j.thread)) { return; }
			if (j.thread.length) {
				paintThread(j.thread);
				mirrorSave(j.thread);
			} else {
				// Server authoritative: no conversation → clear any stale mirror.
				paintThread([]);
				mirrorClear();
			}
		}, function () { /* keep whatever the mirror painted; leave an empty thread otherwise */ });
	}

	/* Clear button: wipe server + UI + selection + mirror. */
	function clearThread() {
		if (!window.confirm('Clear this conversation?')) { return; }
		api('/clear', 'POST', { page_id: CFG.pageId }).then(function () {}, function () {});
		state.threadCache = [];
		if (threadEl) { threadEl.textContent = ''; }
		clearSelection();
		mirrorClear();
	}

	/* ------------------------------------------------------------------ *
	 * Proposal card (safe / adjusted)
	 * ------------------------------------------------------------------ */

	function renderProposalCard(card, kind, selSnapshot) {
		var wrap = el('div', 'aq-asst-card aq-asst-card--' + kind);

		var head = el('div', 'aq-asst-card-head');
		head.appendChild(el('div', 'aq-asst-card-field', card.field || labelFor(selSnapshot)));
		var pill = el('span', 'aq-asst-pill aq-asst-pill--' + (kind === 'adjusted' ? 'adjusted' : 'safe'));
		pill.textContent = kind === 'adjusted' ? 'Adjusted' : 'Safe';
		head.appendChild(pill);
		wrap.appendChild(head);

		if (card.reason) { wrap.appendChild(el('p', 'aq-asst-card-reason', card.reason)); }

		/* before → after diff */
		var diff = el('div', 'aq-asst-diff');
		var before = el('div', 'aq-asst-diff-before');
		before.appendChild(el('span', 'aq-asst-diff-tag', 'Now'));
		before.appendChild(el('span', 'aq-asst-diff-txt', card.before || ''));
		var after = el('div', 'aq-asst-diff-after');
		after.appendChild(el('span', 'aq-asst-diff-tag', 'New'));
		after.appendChild(el('span', 'aq-asst-diff-txt', card.after || ''));
		diff.appendChild(before);
		diff.appendChild(after);
		wrap.appendChild(diff);

		/* alternatives */
		var alts = card.alternatives || [];
		if (alts.length) {
			var altWrap = el('div', 'aq-asst-alts');
			altWrap.appendChild(el('div', 'aq-asst-alts-title', 'Other options'));
			alts.forEach(function (a, i) {
				var row = el('button', 'aq-asst-alt');
				row.type = 'button';
				row.appendChild(el('span', 'aq-asst-alt-val', a.new_value || ''));
				if (a.why) { row.appendChild(el('span', 'aq-asst-alt-why', a.why)); }
				row.addEventListener('click', function () { doApply(card, selSnapshot, i, wrap); });
				altWrap.appendChild(row);
			});
			wrap.appendChild(altWrap);
		}

		/* Apply the main proposal */
		var actions = el('div', 'aq-asst-card-actions');
		var applyBtn = el('button', 'aq-asst-apply');
		applyBtn.type = 'button';
		applyBtn.textContent = 'Apply';
		applyBtn.addEventListener('click', function () { doApply(card, selSnapshot, -1, wrap); });
		actions.appendChild(applyBtn);
		wrap.appendChild(actions);

		var status = el('div', 'aq-asst-card-status');
		status.hidden = true;
		wrap.appendChild(status);

		threadEl.appendChild(wrap);
		scrollThread();
	}

	function renderBlockedCard(card) {
		var wrap = el('div', 'aq-asst-card aq-asst-card--blocked');
		var head = el('div', 'aq-asst-card-head');
		head.appendChild(el('div', 'aq-asst-card-field', card.field || ''));
		var pill = el('span', 'aq-asst-pill aq-asst-pill--blocked');
		pill.textContent = 'Blocked';
		head.appendChild(pill);
		wrap.appendChild(head);

		if (card.reason) { wrap.appendChild(el('p', 'aq-asst-card-reason', card.reason)); }
		if (card.planRule) {
			wrap.appendChild(el('p', 'aq-asst-card-plan', 'This collides with your SEO plan: ' + card.planRule));
		}
		var a = el('a', 'aq-asst-planlink', 'Update the plan');
		a.href = CFG.knowledge;
		a.target = '_blank';
		a.rel = 'noopener';
		wrap.appendChild(a);

		threadEl.appendChild(wrap);
		scrollThread();
	}

	/* ------------------------------------------------------------------ *
	 * Apply / Undo
	 * ------------------------------------------------------------------ */

	function cardStatus(wrap) {
		var s = wrap.querySelector('.aq-asst-card-status');
		if (s) { s.hidden = false; }
		return s;
	}

	function doApply(card, selSnapshot, altIndex, wrap) {
		var payload = { page_id: CFG.pageId, proposalId: card.proposalId };
		if (altIndex >= 0) { payload.alternativeIndex = altIndex; }

		var buttons = wrap.querySelectorAll('button');
		[].forEach.call(buttons, function (b) { b.disabled = true; });
		var status = cardStatus(wrap);
		if (status) { status.textContent = 'Applying…'; }

		api('/apply', 'POST', payload).then(function (j) {
			if (j && j.ok === true) {
				applyToDom(selSnapshot, j.value);
				wrap.classList.add('aq-asst-card--applied');
				if (status) {
					status.textContent = '';
					status.appendChild(el('span', 'aq-asst-applied', '✓ Applied'));
					var undo = el('button', 'aq-asst-undo');
					undo.type = 'button';
					undo.textContent = 'Undo';
					undo.addEventListener('click', function () { doUndo(j.logId, selSnapshot, card.before, wrap, undo); });
					status.appendChild(undo);
				}
				return;
			}
			/* stale / expired / blocked / generic failure — no DOM change */
			[].forEach.call(buttons, function (b) { b.disabled = false; });
			if (status) {
				status.textContent = (j && j.message) ? j.message : "That couldn't be applied.";
				status.classList.add('aq-asst-card-status--err');
			}
		}, function () {
			[].forEach.call(buttons, function (b) { b.disabled = false; });
			if (status) {
				status.textContent = "Couldn't reach the server to apply that. Please try again.";
				status.classList.add('aq-asst-card-status--err');
			}
		});
	}

	function doUndo(logId, selSnapshot, beforeValue, wrap, undoBtn) {
		undoBtn.disabled = true;
		undoBtn.textContent = 'Undoing…';
		api('/undo', 'POST', { logId: logId }).then(function (j) {
			if (j && j.ok === true) {
				applyToDom(selSnapshot, j.value != null ? j.value : beforeValue);
				var status = undoBtn.parentNode;
				if (status) { status.textContent = ''; status.appendChild(el('span', 'aq-asst-applied', 'Undone')); }
				wrap.classList.remove('aq-asst-card--applied');
			} else {
				undoBtn.disabled = false;
				undoBtn.textContent = 'Undo';
				var s = undoBtn.parentNode;
				if (s) {
					var msg = el('div', 'aq-asst-undo-msg', (j && j.message) ? j.message : "Couldn't undo that.");
					s.appendChild(msg);
				}
			}
		}, function () {
			undoBtn.disabled = false;
			undoBtn.textContent = 'Undo';
		});
	}

	/* Update the live DOM element in place (true render returns on reload). */
	function applyToDom(sel, value) {
		if (value == null) { return; }
		var target = elementFor(sel);
		if (target) { target.textContent = value; }
	}

	/* ------------------------------------------------------------------ *
	 * Boot
	 * ------------------------------------------------------------------ */

	function init() { build(); rehydrate(); }
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* The admin-bar "💬 Assistant" node calls this. */
	window.AQAssistantOpen = openPanel;

})();
