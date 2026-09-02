/**
 * AQ Editor — pure builder helpers (undo/redo history + list reorder).
 *
 * Kept in their own file so they are dependency-free and unit-testable both in
 * the browser (window.AQHistory) and under Node (module.exports) — see
 * tests/builder-history-test.mjs. The builder holds a history object
 * { stack: snapshot[], ptr: int, cap: int } and drives it through these:
 *
 *   historyPush(stack, snapshot, cap) -> new stack (append + trim oldest past cap)
 *   historyUndo({stack, ptr})         -> { ptr, snapshot, changed }
 *   historyRedo({stack, ptr})         -> { ptr, snapshot, changed }
 *   reorder(list, orderIndexes)       -> new list ordered by the given indices
 *   applyCategory(images, indices, category, field?) -> new images w/ field set on those
 *   galleryCfg(override)              -> gallery_editor config merged over defaults
 *
 * A snapshot is opaque to these helpers (the builder uses
 * { sections: clone, selected: int }); they only move the pointer.
 */
(function (root) {
	'use strict';

	// Append a snapshot; when a positive cap is exceeded, drop the OLDEST entries
	// so the stack never grows without bound. Pure: returns a new array.
	function historyPush(stack, snapshot, cap) {
		var s = stack.concat([snapshot]);
		if (cap > 0 && s.length > cap) { s = s.slice(s.length - cap); }
		return s;
	}

	// Step the pointer back one. No-op (changed:false) when already at the start.
	function historyUndo(state) {
		var ptr = state.ptr;
		if (ptr <= 0) { return { ptr: ptr, snapshot: state.stack[ptr] || null, changed: false }; }
		ptr = ptr - 1;
		return { ptr: ptr, snapshot: state.stack[ptr], changed: true };
	}

	// Step the pointer forward one. No-op (changed:false) when already at the end.
	function historyRedo(state) {
		var ptr = state.ptr;
		if (ptr >= state.stack.length - 1) { return { ptr: ptr, snapshot: state.stack[ptr] || null, changed: false }; }
		ptr = ptr + 1;
		return { ptr: ptr, snapshot: state.stack[ptr], changed: true };
	}

	// Reorder `list` by `orderIndexes` (a sequence of original positions into
	// `list`). Robust to duplicates / out-of-range / missing indices: each valid
	// index is taken once in the given order, then any items never referenced are
	// appended in their original order so nothing is ever dropped. Pure.
	function reorder(list, orderIndexes) {
		if (!Array.isArray(list)) { return list; }
		if (!Array.isArray(orderIndexes)) { return list.slice(); }
		var out = [], used = {}, i, idx;
		for (i = 0; i < orderIndexes.length; i++) {
			idx = orderIndexes[i];
			if (typeof idx === 'number' && idx >= 0 && idx < list.length && !used[idx]) {
				out.push(list[idx]); used[idx] = true;
			}
		}
		for (i = 0; i < list.length; i++) { if (!used[i]) { out.push(list[i]); } }
		return out;
	}

	// Bulk-set the category `field` (default "category") on the image rows at the
	// given `indices` (positions into `images`). Returns a NEW array; changed rows
	// are shallow copies so the input array and its untouched rows are never
	// mutated. Out-of-range / non-number indices ignored; the value is trimmed.
	function applyCategory(images, indices, category, field) {
		if (!Array.isArray(images)) { return images; }
		var key = (field == null || field === '') ? 'category' : String(field);
		var cat = (category == null ? '' : String(category)).trim();
		var want = {};
		(Array.isArray(indices) ? indices : []).forEach(function (i) {
			if (typeof i === 'number' && i >= 0 && i < images.length) { want[i] = true; }
		});
		return images.map(function (img, i) {
			if (!want[i]) { return img; }
			var next = {};
			for (var k in img) { if (Object.prototype.hasOwnProperty.call(img, k)) { next[k] = img[k]; } }
			next[key] = cat;
			return next;
		});
	}

	// Merge a section's `gallery_editor` override over the engine defaults. Keys
	// present in the override win (including an explicit null, which HIDES that
	// control). Absent keys fall back to the default that reproduces aq_gallery.
	function galleryCfg(override) {
		var d = {
			items: 'images', image: 'id', image_format: 'id',
			category: 'category', caption: 'caption', categories: 'categories',
			filters: 'filters_enabled', columns: 'columns', gap: 'gap',
			order_by: 'order_by', lightbox: 'lightbox'
		};
		if (!override || typeof override !== 'object') { return d; }
		var out = {};
		for (var k in d) {
			out[k] = Object.prototype.hasOwnProperty.call(override, k) ? override[k] : d[k];
		}
		return out;
	}

	// Filter a page list for the builder's page-switcher typeahead. Matches the
	// (trimmed, case-insensitive) query as a substring of EITHER the title or the
	// path, preserves the input order (server sorts by title), and returns at most
	// `limit` (default 10) results. An empty query returns the first `limit` pages.
	// Pure: never mutates the input. Null-safe on the list and on each row.
	function filterPages(pages, query, limit) {
		var list = Array.isArray(pages) ? pages : [];
		var cap  = (typeof limit === 'number' && limit > 0) ? limit : 10;
		var q    = String(query == null ? '' : query).trim().toLowerCase();
		var out  = [];
		for (var i = 0; i < list.length && out.length < cap; i++) {
			var p = list[i];
			if (!p) { continue; }
			if (q === '') { out.push(p); continue; }
			var t    = String(p.title == null ? '' : p.title).toLowerCase();
			var path = String(p.path == null ? '' : p.path).toLowerCase();
			if (t.indexOf(q) > -1 || path.indexOf(q) > -1) { out.push(p); }
		}
		return out;
	}

	var api = { historyPush: historyPush, historyUndo: historyUndo, historyRedo: historyRedo, reorder: reorder, applyCategory: applyCategory, galleryCfg: galleryCfg, filterPages: filterPages };
	if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
	root.AQHistory = api;
})(typeof window !== 'undefined' ? window : globalThis);
