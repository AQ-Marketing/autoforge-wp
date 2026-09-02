/**
 * AQ Editor — canvas runtime.
 *
 * Runs INSIDE the editor iframe (front end in ?aq_canvas=1 mode). Draws hover
 * outlines + a selection overlay over each [data-aq-section], intercepts clicks
 * (so the page doesn't navigate while editing), and talks to the builder shell
 * in the parent window via postMessage. Structured selection only — it never
 * mutates the page; the builder owns edits + re-renders on save.
 */
(function () {
	'use strict';

	var ORIGIN = window.location.origin;
	var parentWin = window.parent;
	if (!parentWin || parentWin === window) { return; }

	// --- overlays ---
	var hoverBox = document.createElement('div');
	hoverBox.className = 'aq-cv-box aq-cv-box--hover';
	var fieldBox = document.createElement('div');
	fieldBox.className = 'aq-cv-box aq-cv-box--field';
	var selBox = document.createElement('div');
	selBox.className = 'aq-cv-box aq-cv-box--sel';
	var tag = document.createElement('div');
	tag.className = 'aq-cv-tag';
	var fieldTag = document.createElement('div');
	fieldTag.className = 'aq-cv-tag aq-cv-tag--field';
	[hoverBox, fieldBox, selBox, tag, fieldTag].forEach(function (el) {
		el.style.display = 'none';
		document.body.appendChild(el);
	});

	function sectionOf(node) {
		while (node && node !== document.body) {
			if (node.nodeType === 1 && node.hasAttribute && node.hasAttribute('data-aq-section')) { return node; }
			node = node.parentNode;
		}
		return null;
	}
	function indexOf(el) { return el ? parseInt(el.getAttribute('data-aq-section'), 10) : -1; }
	function elFor(index) { return document.querySelector('[data-aq-section="' + index + '"]'); }

	/**
	 * Walk up from a clicked node (bounded by its section) and resolve which
	 * editable field it belongs to. Returns the deepest data-aq-field (the leaf
	 * field or repeater subfield) plus the enclosing repeater item, if any.
	 */
	function fieldInfo(target, sectionEl) {
		var node = target, fieldEl = null, itemEl = null;
		while (node && node.nodeType === 1) {
			if (node.hasAttribute) {
				if (!fieldEl && node.hasAttribute('data-aq-field')) { fieldEl = node; }
				if (!itemEl && node.hasAttribute('data-aq-rindex')) { itemEl = node; }
			}
			if (node === sectionEl) { break; }
			node = node.parentNode;
		}
		var info = { field: null, repeater: null, rindex: null, el: fieldEl };
		if (fieldEl) { info.field = fieldEl.getAttribute('data-aq-field'); }
		if (itemEl) {
			info.repeater = itemEl.getAttribute('data-aq-field'); // item wrapper carries the repeater field name
			info.rindex = parseInt(itemEl.getAttribute('data-aq-rindex'), 10);
			if (fieldEl === itemEl) { info.field = null; } // clicked the item wrapper itself, not a subfield
		}
		return info;
	}
	function fieldLabel(info) {
		if (info.repeater && info.field) { return info.repeater.replace(/_/g, ' ') + ' › ' + info.field.replace(/_/g, ' '); }
		if (info.repeater) { return info.repeater.replace(/_/g, ' ') + ' item'; }
		return (info.field || '').replace(/_/g, ' ');
	}

	function position(box, el) {
		if (!el) { box.style.display = 'none'; return; }
		var r = el.getBoundingClientRect();
		box.style.display = 'block';
		box.style.top = (r.top + window.scrollY) + 'px';
		box.style.left = (r.left + window.scrollX) + 'px';
		box.style.width = r.width + 'px';
		box.style.height = r.height + 'px';
	}
	function showTag(el, text) {
		if (!el) { tag.style.display = 'none'; return; }
		var r = el.getBoundingClientRect();
		tag.textContent = text;
		tag.style.display = 'block';
		tag.style.top = Math.max(0, r.top + window.scrollY - 22) + 'px';
		tag.style.left = (r.left + window.scrollX) + 'px';
	}
	function showFieldTag(el, text) {
		if (!el || !text) { fieldTag.style.display = 'none'; return; }
		var r = el.getBoundingClientRect();
		fieldTag.textContent = text;
		fieldTag.style.display = 'block';
		fieldTag.style.top = Math.max(0, r.top + window.scrollY - 20) + 'px';
		fieldTag.style.left = (r.right + window.scrollX - 4) + 'px';
		fieldTag.style.transform = 'translateX(-100%)';
	}

	var selectedIndex = -1;
	var schema = null;          // field schema, sent by the builder on ready
	var editingEl = null;       // element currently being edited in place
	var editInfo = null;        // { index, field, repeater, rindex, type, mode }
	var lastClick = { x: 0, y: 0 };
	function reposition() {
		if (selectedIndex >= 0) { position(selBox, elFor(selectedIndex)); }
		if (editingEl) { position(selBox, elFor(selectedIndex)); }
	}

	// Resolve a field's editor type from the schema (text/textarea/richtext are
	// editable in place; select/image/icon/code/toggle are inspector-only).
	function fieldType(layout, field, repeater) {
		if (!schema || !layout || !field) { return null; }
		var def = schema[layout];
		var fields = def && def.fields;
		if (!fields) { return null; }
		var i, f;
		if (repeater) {
			for (i = 0; i < fields.length; i++) {
				if (fields[i].name === repeater && fields[i].type === 'repeater') {
					var subs = fields[i].subfields || [];
					for (var j = 0; j < subs.length; j++) { if (subs[j].name === field) { return subs[j].type; } }
					return null;
				}
			}
			return null;
		}
		for (i = 0; i < fields.length; i++) { f = fields[i]; if (f.name === field) { return f.type; } }
		return null;
	}
	function isEditableType(t) { return t === 'text' || t === 'textarea' || t === 'richtext'; }

	// --- hover ---
	document.addEventListener('mousemove', function (e) {
		var el = sectionOf(e.target);
		if (!el) { hoverBox.style.display = 'none'; fieldBox.style.display = 'none'; fieldTag.style.display = 'none'; return; }
		position(hoverBox, el);
		showTag(el, (el.getAttribute('data-aq-layout') || 'section').replace(/_/g, ' '));
		// Inner field affordance: outline the exact editable element under the cursor.
		var info = fieldInfo(e.target, el);
		if (info.el && info.el !== el) {
			position(fieldBox, info.el);
			showFieldTag(info.el, fieldLabel(info));
		} else {
			fieldBox.style.display = 'none';
			fieldTag.style.display = 'none';
		}
	});
	document.addEventListener('mouseleave', function () {
		hoverBox.style.display = 'none'; fieldBox.style.display = 'none'; tag.style.display = 'none'; fieldTag.style.display = 'none';
	});

	// --- click to select / edit (and block navigation) ---
	document.addEventListener('mousedown', function (e) { lastClick = { x: e.clientX, y: e.clientY }; }, true);
	document.addEventListener('click', function (e) {
		// Clicking inside the element we're already editing: let the browser place
		// the caret / select text normally — don't hijack.
		if (editingEl && editingEl.contains(e.target)) { return; }
		var el = sectionOf(e.target);
		if (!el) { return; }
		e.preventDefault();
		e.stopPropagation();
		var idx = indexOf(el);
		var info = fieldInfo(e.target, el);
		var rindex = (info.rindex != null && !isNaN(info.rindex)) ? info.rindex : null;
		var type = info.field ? fieldType(el.getAttribute('data-aq-layout'), info.field, info.repeater) : null;
		var willEdit = !!(info.el && info.el !== el && isEditableType(type));

		// Commit any edit on a different element before moving on.
		if (editingEl && editingEl !== info.el) { endEdit(); }

		select(idx, true);
		parentWin.postMessage({
			source: 'aq-canvas', type: 'select', index: idx,
			field: info.field, repeater: info.repeater, rindex: rindex,
			editing: willEdit // builder must NOT focus the inspector input (it would blur the canvas editor)
		}, ORIGIN);

		if (willEdit) {
			startEdit(info.el, { index: idx, field: info.field, repeater: info.repeater, rindex: rindex, type: type, mode: (type === 'richtext' ? 'rich' : 'plain') });
		}
	}, true);

	/* ---------------- in-place text editing ---------------- */
	function startEdit(el, info) {
		if (editingEl === el) { return; }
		endEdit();
		editingEl = el;
		editInfo = info;
		el.setAttribute('data-aq-editing', '1');
		// Nested tagged fields (e.g. a subheading span inside a heading) stay atomic
		// so they aren't co-edited; their own click still edits them separately.
		var nested = el.querySelectorAll ? el.querySelectorAll('[data-aq-field]') : [];
		for (var i = 0; i < nested.length; i++) { nested[i].contentEditable = 'false'; }
		el.contentEditable = (info.mode === 'plain') ? 'plaintext-only' : 'true';
		if (el.contentEditable !== 'plaintext-only' && info.mode === 'plain') { el.contentEditable = 'true'; }
		el.addEventListener('input', onEditInput);
		el.addEventListener('keydown', onEditKey);
		el.addEventListener('paste', onEditPaste);
		el.addEventListener('blur', onEditBlur);
		el.focus();
		placeCaret(lastClick.x, lastClick.y);
		position(selBox, elFor(selectedIndex));
		fieldBox.style.display = 'none';
		fieldTag.style.display = 'none';
	}
	function placeCaret(x, y) {
		try {
			var range = null;
			if (document.caretRangeFromPoint) { range = document.caretRangeFromPoint(x, y); }
			else if (document.caretPositionFromPoint) {
				var p = document.caretPositionFromPoint(x, y);
				if (p) { range = document.createRange(); range.setStart(p.offsetNode, p.offset); range.collapse(true); }
			}
			if (range && editingEl.contains(range.startContainer)) {
				var sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange(range);
			}
		} catch (err) { /* caret placement is best-effort */ }
	}
	function readValue(el, mode) {
		// If the element wraps other tagged fields, edit only its own direct text.
		if (el.querySelector && el.querySelector('[data-aq-field]')) {
			var t = '';
			for (var i = 0; i < el.childNodes.length; i++) {
				if (el.childNodes[i].nodeType === 3) { t += el.childNodes[i].nodeValue; }
			}
			return t.replace(/\s+/g, ' ').trim();
		}
		if (mode === 'rich') { return el.innerHTML.trim(); }
		return (el.innerText != null ? el.innerText : (el.textContent || '')).replace(/ /g, ' ');
	}
	function applyValue(el, value, mode) {
		if (el.querySelector && el.querySelector('[data-aq-field]')) {
			var kids = Array.prototype.slice.call(el.childNodes);
			for (var i = 0; i < kids.length; i++) { if (kids[i].nodeType === 3) { el.removeChild(kids[i]); } }
			el.insertBefore(document.createTextNode(value + ' '), el.firstChild);
			return;
		}
		if (mode === 'rich') { el.innerHTML = value; } else { el.textContent = value; }
	}
	function postEdit(done) {
		if (!editInfo) { return; }
		parentWin.postMessage({
			source: 'aq-canvas', type: 'edit',
			index: editInfo.index, field: editInfo.field, repeater: editInfo.repeater, rindex: editInfo.rindex,
			value: readValue(editingEl, editInfo.mode), done: !!done
		}, ORIGIN);
	}
	function onEditInput() { postEdit(false); reposition(); }
	function onEditKey(e) {
		if (e.key === 'Escape') { e.preventDefault(); endEdit(); }
		else if (e.key === 'Enter' && editInfo && editInfo.type === 'text') { e.preventDefault(); endEdit(); } // single-line fields commit on Enter
	}
	function onEditPaste(e) {
		e.preventDefault();
		var text = (e.clipboardData || window.clipboardData).getData('text/plain');
		try { document.execCommand('insertText', false, text); } catch (err) { /* noop */ }
	}
	function onEditBlur() { endEdit(); }
	function endEdit() {
		if (!editingEl) { return; }
		var el = editingEl;
		postEdit(true);
		el.removeEventListener('input', onEditInput);
		el.removeEventListener('keydown', onEditKey);
		el.removeEventListener('paste', onEditPaste);
		el.removeEventListener('blur', onEditBlur);
		el.removeAttribute('contenteditable');
		el.removeAttribute('data-aq-editing');
		editingEl = null;
		editInfo = null;
		reposition();
	}

	function select(index, scroll) {
		selectedIndex = index;
		var el = elFor(index);
		position(selBox, el);
		hoverBox.style.display = 'none';
		tag.style.display = 'none';
		if (scroll && el) {
			var r = el.getBoundingClientRect();
			if (r.top < 0 || r.bottom > window.innerHeight) {
				el.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
		}
	}

	window.addEventListener('scroll', reposition, { passive: true });
	window.addEventListener('resize', reposition);

	// Locate (and briefly flash) a specific field's element inside a section,
	// so clicking a field in the inspector also points it out on the canvas.
	function findFieldEl(sectionEl, field, repeater, rindex) {
		if (!sectionEl) { return null; }
		if (repeater != null && rindex != null && !isNaN(rindex)) {
			var item = sectionEl.querySelector('[data-aq-field="' + repeater + '"][data-aq-rindex="' + rindex + '"]');
			if (!item) { return null; }
			return field ? (item.querySelector('[data-aq-field="' + field + '"]') || item) : item;
		}
		return field ? sectionEl.querySelector('[data-aq-field="' + field + '"]') : null;
	}
	function flashField(m) {
		var el = findFieldEl(elFor(selectedIndex), m.field, m.repeater, m.rindex);
		if (!el) { return; }
		var r = el.getBoundingClientRect();
		if (r.top < 0 || r.bottom > window.innerHeight) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
		position(fieldBox, el);
		fieldBox.classList.add('is-flash');
		setTimeout(function () { fieldBox.classList.remove('is-flash'); fieldBox.style.display = 'none'; }, 1200);
	}

	// --- live image swap (no reload) ---
	// Reduce a URL/filename to a comparable stem: basename, minus extension and
	// WordPress's "-WxH" size suffix. So "irrigation.webp", "irrigation-1024x683.webp",
	// and ".../uploads/2026/08/irrigation.webp" all compare equal.
	function imgStem(u) {
		u = String(u || '').split('?')[0].split('#')[0];
		u = u.substring(u.lastIndexOf('/') + 1);
		u = u.replace(/\.[a-z0-9]+$/i, '');
		u = u.replace(/-\d+x\d+$/, '');
		return u.toLowerCase();
	}
	function imgInField(sectionEl, field, repeater, rindex) {
		var fEl = findFieldEl(sectionEl, field, repeater, rindex);
		if (!fEl) { return null; }
		return fEl.tagName === 'IMG' ? fEl : fEl.querySelector('img');
	}
	function imgByOldName(sectionEl, oldName) {
		if (!oldName) { return null; }
		var want = imgStem(oldName), imgs = sectionEl.querySelectorAll('img'), i;
		for (i = 0; i < imgs.length; i++) { if (imgs[i].getAttribute('data-aq-imgstem') === want) { return imgs[i]; } }
		for (i = 0; i < imgs.length; i++) {
			if (imgStem(imgs[i].getAttribute('src') || imgs[i].src) === want) { return imgs[i]; }
			var ss = imgs[i].getAttribute('srcset') || '';
			if (ss && ss.toLowerCase().indexOf(want) > -1) { return imgs[i]; }
		}
		return null;
	}
	function swapImage(sectionEl, m) {
		var img = imgInField(sectionEl, m.field, m.repeater, m.rindex) || imgByOldName(sectionEl, m.oldName);
		if (!img) { var all = sectionEl.querySelectorAll('img'); if (all.length === 1) { img = all[0]; } }
		if (!img || !m.url) { return; }
		// Neutralize <picture><source> + srcset so the new src wins; the true
		// responsive render comes back on Save (which reloads the canvas).
		var p = img.parentNode;
		while (p && p !== sectionEl && p.tagName !== 'PICTURE') { p = p.parentNode; }
		if (p && p.tagName === 'PICTURE') { var srcs = p.querySelectorAll('source'); for (var i = 0; i < srcs.length; i++) { srcs[i].removeAttribute('srcset'); } }
		img.removeAttribute('srcset');
		img.removeAttribute('sizes');
		img.setAttribute('src', m.url);
		img.setAttribute('data-aq-imgstem', imgStem(m.name || m.url)); // so the next swap of this field finds it
		reposition();
	}

	// --- gallery drag-to-reorder (on the real tiles in the canvas) ---
	// Only the SELECTED aq_gallery whose order_by is "manual" is reorderable; the
	// renderer marks the container [data-aq-gallery] + tiles [data-aq-gallery-item].
	var galFrom = null;      // original image index currently being dragged
	function galleryOf(node) {
		while (node && node !== document.body) {
			if (node.nodeType === 1 && node.hasAttribute && node.hasAttribute('data-aq-gallery')) { return node; }
			node = node.parentNode;
		}
		return null;
	}
	function galTileOf(node, galEl) {
		while (node && node !== galEl) {
			if (node.nodeType === 1 && node.hasAttribute && node.hasAttribute('data-aq-gallery-item')) { return node; }
			node = node.parentNode;
		}
		return null;
	}
	// Drag is live only on the selected gallery in manual order. The [data-aq-gallery]
	// element may BE the section (engine renderer) or nested inside it (a bespoke
	// renderer like photo_gallery), so resolve the section index by walking up to the
	// nearest [data-aq-section] rather than reading it off the gallery element itself.
	function galleryDraggable(galEl) {
		if (!galEl) { return false; }
		if ((galEl.getAttribute('data-aq-gallery-order') || 'manual') !== 'manual') { return false; }
		return indexOf(sectionOf(galEl)) === selectedIndex;
	}
	function galTiles(galEl) { return galEl ? galEl.querySelectorAll('[data-aq-gallery-item]') : []; }
	function galClearOver(galEl) {
		var t = galTiles(galEl);
		for (var i = 0; i < t.length; i++) { t[i].classList.remove('aqg-over'); }
	}
	// Current DOM order of original indices, then move `fromVal` to `toVal`'s slot.
	function galReorder(galEl, fromVal, toVal) {
		var tiles = galTiles(galEl), order = [], i;
		for (i = 0; i < tiles.length; i++) { order.push(parseInt(tiles[i].getAttribute('data-aq-gallery-item'), 10)); }
		var fi = order.indexOf(fromVal);
		if (fi < 0) { return order; }
		order.splice(fi, 1);
		if (toVal == null) { order.push(fromVal); return order; }
		var ti = order.indexOf(toVal);
		if (ti < 0) { order.push(fromVal); } else { order.splice(ti, 0, fromVal); }
		return order;
	}
	function galEnd() {
		// Class-agnostic cleanup: bespoke galleries (e.g. .gal-item) use their own tile
		// class, so clear the drag/over cues by the state classes alone — never scoped
		// to the engine's .aq-gallery__item markup.
		var dragging = document.querySelectorAll('.aqg-dragging');
		for (var i = 0; i < dragging.length; i++) { dragging[i].classList.remove('aqg-dragging'); }
		var overs = document.querySelectorAll('.aqg-over');
		for (var j = 0; j < overs.length; j++) { overs[j].classList.remove('aqg-over'); }
		galFrom = null;
	}
	document.addEventListener('dragstart', function (e) {
		var gal = galleryOf(e.target);
		if (!gal || !galleryDraggable(gal)) { return; }
		var tile = galTileOf(e.target, gal);
		if (!tile) { return; }
		galFrom = parseInt(tile.getAttribute('data-aq-gallery-item'), 10);
		tile.classList.add('aqg-dragging');
		try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(galFrom)); } catch (err) { /* noop */ }
	}, true);
	document.addEventListener('dragover', function (e) {
		if (galFrom == null) { return; }
		var gal = galleryOf(e.target);
		if (!gal || !galleryDraggable(gal)) { return; }
		e.preventDefault();
		try { e.dataTransfer.dropEffect = 'move'; } catch (err) { /* noop */ }
		galClearOver(gal);
		var tile = galTileOf(e.target, gal);
		if (tile) { tile.classList.add('aqg-over'); }
	}, true);
	document.addEventListener('drop', function (e) {
		if (galFrom == null) { return; }
		var gal = galleryOf(e.target);
		if (!gal || !galleryDraggable(gal)) { galEnd(); return; }
		e.preventDefault();
		var tile = galTileOf(e.target, gal);
		var toVal = tile ? parseInt(tile.getAttribute('data-aq-gallery-item'), 10) : null;
		if (toVal !== galFrom) {
			var order = galReorder(gal, galFrom, toVal);
			parentWin.postMessage({ source: 'aq-canvas', type: 'gallery-reorder', index: indexOf(sectionOf(gal)), order: order }, ORIGIN);
		}
		galEnd();
	}, true);
	document.addEventListener('dragend', galEnd, true);

	// --- messages from the builder ---
	window.addEventListener('message', function (e) {
		if (e.origin !== ORIGIN || !e.data || e.data.source !== 'aq-builder') { return; }
		var m = e.data;
		if (m.type === 'schema') { schema = m.schema || null; }
		else if (m.type === 'highlight') {
			select(m.index, true);
			if (m.field || m.repeater) { setTimeout(function () { flashField(m); }, 60); }
		}
		else if (m.type === 'settext') {
			// Inspector edited a field → reflect it live on the canvas (unless that
			// element is the one being edited in place, to avoid clobbering the caret).
			var sec = elFor(m.index != null ? m.index : selectedIndex);
			var el = findFieldEl(sec, m.field, m.repeater, m.rindex);
			if (el && el !== editingEl) {
				var t = fieldType(sec ? sec.getAttribute('data-aq-layout') : null, m.field, m.repeater);
				applyValue(el, m.value || '', t === 'richtext' ? 'rich' : 'plain');
				reposition();
			}
		}
		else if (m.type === 'setimage') {
			// Inspector picked a new image → swap it on the canvas immediately.
			var isec = elFor(m.index != null ? m.index : selectedIndex);
			if (isec) { swapImage(isec, m); }
		}
		else if (m.type === 'clear') { selectedIndex = -1; selBox.style.display = 'none'; }
	});

	// announce ready (after layout settles)
	function ready() {
		parentWin.postMessage({
			source: 'aq-canvas', type: 'ready',
			count: document.querySelectorAll('[data-aq-section]').length
		}, ORIGIN);
		reposition();
	}
	if (document.readyState === 'complete') { setTimeout(ready, 60); }
	else { window.addEventListener('load', function () { setTimeout(ready, 60); }); }
})();
