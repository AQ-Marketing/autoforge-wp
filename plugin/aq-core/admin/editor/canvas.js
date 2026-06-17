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
