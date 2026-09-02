/**
 * AQ Assistant — front-end chat panel for the admin-only, live-site SEO-guardian.
 *
 * Runs on the real published page for a logged-in admin. The PHP side
 * (AQ_Assistant) enqueues this and exposes
 * window.AQ_ASSIST = { restRoot, knowledge, builder, nonce, pageId, labels, stickyBar }.
 *
 * A plain chat panel: the admin types what they want changed and the server
 * decides which text it refers to, proposes a change with a verdict, and the
 * admin applies it. No point-and-click, no field pickers, no overlays.
 *
 * Vanilla JS, no libraries, no build step. All classes namespaced .aq-asst-*.
 * Endpoints: GET  restRoot/context/{pageId}
 *            GET  restRoot/thread/{pageId}
 *            POST restRoot/message   { page_id, selection:null, message }
 *            POST restRoot/apply     { page_id, proposalId, alternativeIndex? }
 *            POST restRoot/undo      { logId }
 *            POST restRoot/clear     { page_id }
 */
(function () {
	'use strict';

	var CFG = window.AQ_ASSIST;
	if (!CFG || !CFG.restRoot) { return; }

	var REDUCE = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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
		contextLoaded: false,
		rehydrated: false,
		threadCache: []
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

	var root, launcher, panel, threadEl, textarea, noteArea, sendBtn;

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

		document.addEventListener('keydown', onKeydown, true);
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

		addBubble('user', msg);
		cachePush({ role: 'user', text: msg });
		textarea.value = '';
		var thinking = addThinking();
		setBusy(true);

		/* No client-side selection — the server resolves the target from the words. */
		api('/message', 'POST', { page_id: CFG.pageId, selection: null, message: msg })
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

	/* Clear button: wipe server + UI + mirror. */
	function clearThread() {
		if (!window.confirm('Clear this conversation?')) { return; }
		api('/clear', 'POST', { page_id: CFG.pageId }).then(function () {}, function () {});
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
		var payload = { page_id: CFG.pageId, proposalId: card.proposalId };
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
