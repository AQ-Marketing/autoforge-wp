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

	var state = { sections: [], selected: -1, dirty: false, device: 'desktop', rehighlight: -1, images: {} };
	var uid = 0;
	var els = {};
	var asstBusy = false;

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
	function labelFor(type) { return (CFG.labels && CFG.labels[type]) || type; }
	function setDirty(v) {
		state.dirty = v;
		if (els.save) { els.save.disabled = !v; els.save.textContent = v ? 'Save changes' : 'Saved'; }
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
		els.asstBtn = ce('button', 'aqb-btn aqb-btn--ghost', '✨ Assistant');
		els.asstBtn.addEventListener('click', function () { toggleAssistant(); });
		right.appendChild(els.asstBtn);
		var view = ce('a', 'aqb-btn aqb-btn--ghost', 'View live ↗');
		view.href = CFG.permalink; view.target = '_blank'; view.rel = 'noopener';
		els.save = ce('button', 'aqb-btn aqb-btn--primary', 'Saved');
		els.save.disabled = true;
		els.save.addEventListener('click', save);
		right.appendChild(view);
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

		els.assistant = ce('div', 'aqb-asst');
		els.assistant.style.display = 'none';
		root.appendChild(els.assistant);
	}

	/* ---------------- AI assistant ---------------- */
	function toggleAssistant(force) {
		if (!els.assistant) { return; }
		var open = typeof force === 'boolean' ? force : els.assistant.style.display === 'none';
		if (open && !els.asstLog) { buildAssistant(); }
		els.assistant.style.display = open ? 'flex' : 'none';
		if (els.asstBtn) { els.asstBtn.classList.toggle('is-active', open); }
		if (open && els.asstInput) { els.asstInput.focus(); }
	}
	function buildAssistant() {
		var p = els.assistant;
		p.innerHTML = '';
		var head = ce('div', 'aqb-asst__head');
		head.appendChild(ce('span', 'aqb-asst__title', '✨ AI Assistant'));
		var close = ce('button', 'aqb-icon', '✕'); close.title = 'Close';
		close.addEventListener('click', function () { toggleAssistant(false); });
		head.appendChild(close);
		p.appendChild(head);

		if (!CFG.assistant) {
			var setup = ce('div', 'aqb-asst__log');
			setup.appendChild(ce('div', 'aqb-asst__hint', 'The assistant needs an OpenAI API key. Add one under AutoForge → Integrations, then reopen this editor.'));
			p.appendChild(setup);
			return;
		}

		els.asstLog = ce('div', 'aqb-asst__log');
		els.asstLog.appendChild(ce('div', 'aqb-asst__hint',
			'Describe a change in plain English — e.g. "change the hero heading to …", "add an FAQ about radon", "remove the testimonials section". I\'ll propose edits for you to review, then Save.'));
		p.appendChild(els.asstLog);

		var form = ce('div', 'aqb-asst__form');
		els.asstInput = ce('textarea', 'aqb-input aqb-asst__input'); els.asstInput.rows = 2;
		els.asstInput.placeholder = 'Ask the assistant to edit this page… (Ctrl/⌘+Enter to send)';
		els.asstInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); sendAssistant(); }
		});
		form.appendChild(els.asstInput);
		els.asstSend = ce('button', 'aqb-btn aqb-btn--primary', 'Send');
		els.asstSend.addEventListener('click', sendAssistant);
		form.appendChild(els.asstSend);
		p.appendChild(form);
	}
	function asstAppend(role, text) {
		var m = ce('div', 'aqb-asst__msg aqb-asst__msg--' + role);
		m.appendChild(ce('div', 'aqb-asst__who', role === 'user' ? 'You' : 'Assistant'));
		m.appendChild(ce('div', 'aqb-asst__text', text));
		els.asstLog.appendChild(m);
		els.asstLog.scrollTop = els.asstLog.scrollHeight;
		return m;
	}
	function sendAssistant() {
		if (asstBusy) { return; }
		var text = (els.asstInput.value || '').trim();
		if (!text) { return; }
		asstAppend('user', text);
		els.asstInput.value = '';
		asstBusy = true; els.asstSend.disabled = true; els.asstSend.textContent = 'Thinking…';
		var payload = state.sections.map(function (s) { var c = {}; for (var k in s) { if (k.charAt(0) !== '_') { c[k] = s[k]; } } return c; });
		api('/assistant', { method: 'POST', body: { id: CFG.pageId, message: text, sections: payload } })
			.then(function (d) {
				asstBusy = false; els.asstSend.disabled = false; els.asstSend.textContent = 'Send';
				if (!d || d.ok === false) {
					asstAppend('bot', 'Sorry — ' + ((d && (d.message || d.code)) || 'something went wrong.'));
					return;
				}
				var m = asstAppend('bot', d.reply || 'Here is a proposed change.');
				if (d.proposal && d.proposal.length) {
					var apply = ce('button', 'aqb-btn aqb-btn--primary aqb-asst__apply', 'Apply change (' + d.proposal.length + ' sections)');
					apply.addEventListener('click', function () {
						applyProposal(d.proposal);
						apply.disabled = true; apply.textContent = 'Applied — review & Save';
					});
					m.appendChild(apply);
				}
			})
			.catch(function (e) {
				asstBusy = false; els.asstSend.disabled = false; els.asstSend.textContent = 'Send';
				asstAppend('bot', 'Request failed: ' + e.message);
			});
	}
	function applyProposal(sections) {
		state.sections = sections.map(function (s) { s._uid = ++uid; return s; });
		state.selected = -1;
		setDirty(true);
		renderStructure();
		renderInspector();
		asstAppend('bot', 'Loaded the changes into the editor. Review on the left/center, then click Save to publish.');
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
		if (!fields.length) {
			p.appendChild(ce('p', 'aqb-muted', 'This section has no editable fields.'));
			return;
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
