/**
 * AQ Assistant — chat panel for the admin-only, live-site SEO-guardian.
 *
 * Runs on the real published page AND inside wp-admin for a logged-in admin. The
 * PHP side (AQ_Assistant) enqueues this and exposes
 * window.AQ_ASSIST = { restRoot, knowledge, builder, nonce, pageId, context,
 *                      labels, stickyBar, prefs }.
 *
 * A plain chat panel: the admin types what they want changed and the server
 * decides which text it refers to, proposes a change with a verdict, and the
 * admin applies it. No point-and-click, no field pickers, no overlays.
 *
 * The edited page is chosen by a header dropdown (front end: defaults to the
 * current page; admin: to the page being edited, else empty). Every REST call is
 * routed through one `activePostId`. The floating launcher is draggable and its
 * position persists per-user (server + localStorage).
 *
 * Vanilla JS, no libraries, no build step. All classes namespaced .aq-asst-*.
 * Endpoints: GET  restRoot/pages
 *            GET  restRoot/prefs        POST restRoot/prefs { launcher }
 *            GET  restRoot/context/{id} GET  restRoot/thread/{id}
 *            POST restRoot/message   { page_id, selection:null, message }
 *            POST restRoot/apply     { page_id, proposalId, alternativeIndex? }
 *            POST restRoot/undo      { logId }   POST restRoot/clear { page_id }
 */
(function () {
	'use strict';

	var CFG = window.AQ_ASSIST;
	if (!CFG || !CFG.restRoot) { return; }

	var REDUCE = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* The page every REST call targets. Starts at the bootstrapped page (may be 0
	 * in wp-admin when no page is being edited) and follows the header selector. */
	var activePostId = parseInt(CFG.pageId, 10) || 0;
	var DRAG_THRESHOLD = 4;
	var LAUNCH_KEY = 'aq_asst_launcher';

	/* ------------------------------------------------------------------ *
	 * Small helpers
	 * ------------------------------------------------------------------ */

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text != null) { n.textContent = text; }
		return n;
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
	 * State + persistence mirror
	 * ------------------------------------------------------------------ */

	var state = {
		open: false,
		pagesLoaded: false,
		contextLoaded: false,
		rehydrated: false,
		threadCache: []
	};

	/* Per-page localStorage mirror key (instant paint before the network). Keyed by
	 * the ACTIVE post so switching pages in the selector shows the right thread. */
	function mirrorKey() { return 'aq_asst_thread_' + activePostId; }
	function mirrorSave(arr) { try { localStorage.setItem(mirrorKey(), JSON.stringify(arr)); } catch (e) { /* private window */ } }
	function mirrorLoad() {
		try {
			var raw = localStorage.getItem(mirrorKey());
			var a = raw ? JSON.parse(raw) : null;
			return Array.isArray(a) ? a : null;
		} catch (e) { return null; }
	}
	function mirrorClear() { try { localStorage.removeItem(mirrorKey()); } catch (e) { /* private window */ } }

	/* Launcher-position localStorage cache (per browser; server is source of truth). */
	function launchLoad() {
		try {
			var raw = localStorage.getItem(LAUNCH_KEY);
			var o = raw ? JSON.parse(raw) : null;
			return (o && (o.side === 'left' || o.side === 'right')) ? o : null;
		} catch (e) { return null; }
	}
	function launchSave(o) { try { localStorage.setItem(LAUNCH_KEY, JSON.stringify(o)); } catch (e) { /* private window */ } }

	var root, launcher, panel, threadEl, textarea, noteArea, sendBtn, pageSelect;
	var currentSide = 'right';

	/* ------------------------------------------------------------------ *
	 * Build the launcher + panel (once)
	 * ------------------------------------------------------------------ */

	function build() {
		root = el('div', 'aq-asst-root');
		root.setAttribute('data-aq-asst', '1');

		/* Launcher */
		launcher = el('button', 'aq-asst-launcher');
		launcher.type = 'button';
		launcher.setAttribute('aria-label', 'Open the site assistant (drag to move)');
		launcher.appendChild(el('span', 'aq-asst-launcher-ico', '💬'));
		launcher.appendChild(el('span', 'aq-asst-launcher-txt', 'Assistant'));
		if (CFG.stickyBar) { launcher.classList.add('aq-asst-launcher--sticky'); }
		makeDraggable(launcher);
		root.appendChild(launcher);

		/* Panel */
		panel = el('div', 'aq-asst-panel');
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-label', 'Site assistant');
		panel.setAttribute('aria-modal', 'false');
		panel.hidden = true;
		if (REDUCE) { panel.classList.add('aq-asst-noanim'); }

		var header = el('div', 'aq-asst-header');
		var htop = el('div', 'aq-asst-headtop');
		htop.appendChild(el('div', 'aq-asst-title', 'Assistant'));
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
		htop.appendChild(hactions);
		header.appendChild(htop);

		/* Page selector — which page the assistant edits. */
		var selRow = el('div', 'aq-asst-pagerow');
		var selLabel = el('label', 'aq-asst-pagelabel', 'Editing');
		selLabel.setAttribute('for', 'aq-asst-page');
		pageSelect = el('select', 'aq-asst-page');
		pageSelect.id = 'aq-asst-page';
		pageSelect.setAttribute('aria-label', 'Choose which page to edit');
		var ph = el('option', null, 'Choose a page to edit');
		ph.value = '0';
		pageSelect.appendChild(ph);
		pageSelect.addEventListener('change', function () {
			setActivePost(parseInt(pageSelect.value, 10) || 0);
		});
		selRow.appendChild(selLabel);
		selRow.appendChild(pageSelect);
		header.appendChild(selRow);
		panel.appendChild(header);

		noteArea = el('div', 'aq-asst-notes');
		noteArea.hidden = true;
		panel.appendChild(noteArea);

		threadEl = el('div', 'aq-asst-thread');
		threadEl.setAttribute('aria-live', 'polite');
		threadEl.setAttribute('role', 'log');
		panel.appendChild(threadEl);

		/* Composer — just a textarea + Send. */
		var composer = el('div', 'aq-asst-composer');
		var inputRow = el('div', 'aq-asst-inputrow');
		textarea = el('textarea', 'aq-asst-textarea');
		textarea.setAttribute('rows', '2');
		textarea.setAttribute('placeholder', 'Ask me to change any text on this page…');
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

		/* Restore the launcher position: server bootstrap → localStorage → default. */
		var pref = (CFG.prefs && CFG.prefs.launcher) ? CFG.prefs.launcher : launchLoad();
		if (pref && (pref.side === 'left' || pref.side === 'right')) { applyLauncherPos(pref); }

		document.addEventListener('keydown', onKeydown, true);
		window.addEventListener('resize', clampLauncher);
	}

	/* ------------------------------------------------------------------ *
	 * Draggable launcher (drag past a threshold = move; otherwise = click)
	 * ------------------------------------------------------------------ */

	function makeDraggable(btn) {
		var startX = 0, startY = 0, baseLeft = 0, baseTop = 0;
		var dragging = false, pointerId = null, suppressClick = false;

		btn.addEventListener('pointerdown', function (e) {
			if (e.button != null && e.button !== 0) { return; }
			pointerId = e.pointerId;
			var r = btn.getBoundingClientRect();
			startX = e.clientX; startY = e.clientY;
			baseLeft = r.left; baseTop = r.top;
			dragging = false;
			try { btn.setPointerCapture(pointerId); } catch (err) { /* older browsers */ }
		});

		btn.addEventListener('pointermove', function (e) {
			if (pointerId === null) { return; }
			var dx = e.clientX - startX, dy = e.clientY - startY;
			if (!dragging) {
				if (Math.abs(dx) < DRAG_THRESHOLD && Math.abs(dy) < DRAG_THRESHOLD) { return; }
				dragging = true;
				btn.classList.add('aq-asst-dragging');
				document.body.classList.add('aq-asst-noselect');
			}
			var w = btn.offsetWidth, h = btn.offsetHeight;
			var left = clamp(baseLeft + dx, 0, window.innerWidth - w);
			var top  = clamp(baseTop + dy, 0, window.innerHeight - h);
			btn.style.left = left + 'px';
			btn.style.top = top + 'px';
			btn.style.right = 'auto';
			btn.style.bottom = 'auto';
		});

		function end() {
			if (pointerId !== null) { try { btn.releasePointerCapture(pointerId); } catch (err) {} }
			pointerId = null;
			if (dragging) {
				dragging = false;
				suppressClick = true;
				btn.classList.remove('aq-asst-dragging');
				document.body.classList.remove('aq-asst-noselect');
				persistLauncher(btn);
			}
		}
		btn.addEventListener('pointerup', end);
		btn.addEventListener('pointercancel', end);

		/* A real click (mouse or keyboard Enter/Space) opens the panel — unless it
		 * was the tail of a drag, in which case swallow this one click. */
		btn.addEventListener('click', function (e) {
			if (suppressClick) { suppressClick = false; e.preventDefault(); e.stopPropagation(); return; }
			openPanel();
		});
	}

	function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

	/* Apply an edge-anchored position {side,x,y}, clamped to the viewport. */
	function applyLauncherPos(pref) {
		if (!launcher) { return; }
		currentSide = pref.side === 'left' ? 'left' : 'right';
		var w = launcher.offsetWidth || 0, h = launcher.offsetHeight || 0;
		var x = clamp(parseInt(pref.x, 10) || 0, 0, Math.max(0, window.innerWidth - w));
		var y = clamp(parseInt(pref.y, 10) || 0, 0, Math.max(0, window.innerHeight - h));
		launcher.style.top = 'auto';
		launcher.style.bottom = y + 'px';
		if (currentSide === 'left') {
			launcher.style.left = x + 'px';
			launcher.style.right = 'auto';
		} else {
			launcher.style.right = x + 'px';
			launcher.style.left = 'auto';
		}
	}

	/* Turn the launcher's current pixel rect into an edge-anchored pref, then save
	 * it to CSS (normalized), localStorage, and the server. */
	function persistLauncher(btn) {
		var r = btn.getBoundingClientRect();
		var vw = window.innerWidth, vh = window.innerHeight;
		var side = (r.left + r.width / 2) < vw / 2 ? 'left' : 'right';
		var x = side === 'left' ? r.left : (vw - r.right);
		var y = vh - r.bottom;
		var pref = { side: side, x: Math.round(clamp(x, 0, 2000)), y: Math.round(clamp(y, 0, 2000)) };
		applyLauncherPos(pref);
		launchSave(pref);
		api('/prefs', 'POST', { launcher: pref }).then(function () {}, function () {});
	}

	/* Keep the launcher on-screen after a viewport resize. */
	function clampLauncher() {
		if (!launcher) { return; }
		applyLauncherPos({ side: currentSide, x: launcherEdgeX(), y: launcherEdgeY() });
	}
	function launcherEdgeX() {
		var r = launcher.getBoundingClientRect();
		return currentSide === 'left' ? r.left : (window.innerWidth - r.right);
	}
	function launcherEdgeY() {
		var r = launcher.getBoundingClientRect();
		return window.innerHeight - r.bottom;
	}

	/* ------------------------------------------------------------------ *
	 * Open / close
	 * ------------------------------------------------------------------ */

	function openPanel() {
		if (!root) { build(); }
		state.open = true;
		panel.hidden = false;
		/* Anchor the panel to the launcher's current side (open toward center). */
		panel.classList.toggle('aq-asst-panel--left', currentSide === 'left');
		launcher.classList.add('aq-asst-launcher--hidden');
		requestAnimationFrame(function () { panel.classList.add('aq-asst-panel--in'); });
		if (textarea) { textarea.focus(); }
		if (!state.pagesLoaded) { loadPages(); }
		if (!state.contextLoaded) { loadContext(); }
		if (!state.rehydrated) { rehydrate(); }
	}

	/* Fill the page selector from the server; default to the active/bootstrapped page. */
	function loadPages() {
		state.pagesLoaded = true;
		api('/pages', 'GET').then(function (list) {
			if (!Array.isArray(list) || !pageSelect) { return; }
			list.forEach(function (p) {
				var o = el('option', null, p.title + ' (' + p.path + ')');
				o.value = String(p.id);
				pageSelect.appendChild(o);
			});
			pageSelect.value = String(activePostId || 0);
		}, function () { /* non-fatal; selector keeps the placeholder */ });
	}

	/* Switch the page every REST call targets; reset + reload the conversation. */
	function setActivePost(id) {
		id = parseInt(id, 10) || 0;
		if (id === activePostId) { return; }
		activePostId = id;
		state.contextLoaded = false;
		state.rehydrated = false;
		state.threadCache = [];
		if (threadEl) { threadEl.textContent = ''; }
		if (noteArea) { noteArea.hidden = true; noteArea.textContent = ''; }
		if (activePostId) {
			loadContext();
			rehydrate();
		}
	}

	function closePanel() {
		state.open = false;
		panel.classList.remove('aq-asst-panel--in');
		var finish = function () { panel.hidden = true; };
		if (REDUCE) { finish(); } else { setTimeout(finish, 180); }
		launcher.classList.remove('aq-asst-launcher--hidden');
		launcher.focus();
	}

	function onKeydown(e) {
		if (e.key !== 'Escape') { return; }
		if (state.open) { e.preventDefault(); closePanel(); }
	}

	function loadContext() {
		if (!activePostId) { return; }
		state.contextLoaded = true;
		api('/context/' + activePostId, 'GET').then(function (j) {
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

		/* No page chosen yet → say so before hitting the server. */
		if (!activePostId) {
			addBubble('user', msg);
			textarea.value = '';
			addBubble('assistant', 'Pick a page to edit first — use the "Editing" dropdown at the top of this panel.');
			return;
		}

		addBubble('user', msg);
		cachePush({ role: 'user', text: msg });
		textarea.value = '';
		var thinking = addThinking();
		setBusy(true);

		/* No client-side selection — the server resolves the target from the words. */
		api('/message', 'POST', { page_id: activePostId, selection: null, message: msg })
			.then(function (j) {
				thinking.remove();
				setBusy(false);
				if (!j || j.ok === false) {
					addBubble('assistant', friendlyError(j));
					return;
				}
				handleReply(j);
			}, function () {
				thinking.remove();
				setBusy(false);
				addBubble('assistant', 'Something went wrong reaching the assistant. Please try again.');
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

	/* ------------------------------------------------------------------ *
	 * Reply dispatch (shared by live replies and rehydrated entries)
	 * ------------------------------------------------------------------ */

	/* Live reply: record it in the cache/mirror, then render. */
	function handleReply(j) {
		var entry = { role: 'assistant', text: j.text || '', kind: j.kind };
		if (j.card) { entry.card = j.card; }
		cachePush(entry);
		renderAssistant(j);
	}

	function renderAssistant(j) {
		var kind = j.kind;
		if (kind === 'answer' || kind === 'need_selection') {
			addBubble('assistant', j.text || 'OK.');
			return;
		}
		if (kind === 'safe' || kind === 'adjusted') {
			if (j.text) { addBubble('assistant', j.text); }
			renderProposalCard(j.card || {}, kind);
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
		renderAssistant({ kind: entry.kind, text: entry.text, card: entry.card });
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
		if (!activePostId) { return; }
		state.rehydrated = true;

		var cached = mirrorLoad();
		if (cached && cached.length) { paintThread(cached); }

		api('/thread/' + activePostId, 'GET').then(function (j) {
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

	/* Clear button: wipe server + UI + mirror. */
	function clearThread() {
		if (!activePostId) { return; }
		if (!window.confirm('Clear this conversation?')) { return; }
		api('/clear', 'POST', { page_id: activePostId }).then(function () {}, function () {});
		state.threadCache = [];
		if (threadEl) { threadEl.textContent = ''; }
		mirrorClear();
	}

	/* ------------------------------------------------------------------ *
	 * Proposal card (safe / adjusted)
	 * ------------------------------------------------------------------ */

	function renderProposalCard(card, kind) {
		var wrap = el('div', 'aq-asst-card aq-asst-card--' + kind);

		var head = el('div', 'aq-asst-card-head');
		head.appendChild(el('div', 'aq-asst-card-field', card.field || 'Suggested change'));
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
				row.addEventListener('click', function () { doApply(card, i, wrap); });
				altWrap.appendChild(row);
			});
			wrap.appendChild(altWrap);
		}

		/* Apply the main proposal */
		var actions = el('div', 'aq-asst-card-actions');
		var applyBtn = el('button', 'aq-asst-apply');
		applyBtn.type = 'button';
		applyBtn.textContent = 'Apply';
		applyBtn.addEventListener('click', function () { doApply(card, -1, wrap); });
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
	 * Apply / Undo (server ids only; no client-side DOM swap)
	 * ------------------------------------------------------------------ */

	function cardStatus(wrap) {
		var s = wrap.querySelector('.aq-asst-card-status');
		if (s) { s.hidden = false; }
		return s;
	}

	function doApply(card, altIndex, wrap) {
		var payload = { page_id: activePostId, proposalId: card.proposalId };
		if (altIndex >= 0) { payload.alternativeIndex = altIndex; }

		var buttons = wrap.querySelectorAll('button');
		[].forEach.call(buttons, function (b) { b.disabled = true; });
		var status = cardStatus(wrap);
		if (status) { status.textContent = 'Applying…'; }

		api('/apply', 'POST', payload).then(function (j) {
			if (j && j.ok === true) {
				wrap.classList.add('aq-asst-card--applied');
				if (status) {
					status.textContent = '';
					status.appendChild(el('span', 'aq-asst-applied', '✓ Applied'));
					var undo = el('button', 'aq-asst-undo');
					undo.type = 'button';
					undo.textContent = 'Undo';
					undo.addEventListener('click', function () { doUndo(j.logId, wrap, undo); });
					status.appendChild(undo);
				}
				return;
			}
			/* stale / expired / blocked / generic failure */
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

	function doUndo(logId, wrap, undoBtn) {
		undoBtn.disabled = true;
		undoBtn.textContent = 'Undoing…';
		api('/undo', 'POST', { logId: logId }).then(function (j) {
			if (j && j.ok === true) {
				var status = undoBtn.parentNode;
				if (status) { status.textContent = ''; status.appendChild(el('span', 'aq-asst-applied', 'Undone')); }
				wrap.classList.remove('aq-asst-card--applied');
			} else {
				undoBtn.disabled = false;
				undoBtn.textContent = 'Undo';
				var s = undoBtn.parentNode;
				if (s) { s.appendChild(el('div', 'aq-asst-undo-msg', (j && j.message) ? j.message : "Couldn't undo that.")); }
			}
		}, function () {
			undoBtn.disabled = false;
			undoBtn.textContent = 'Undo';
		});
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
