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
 *   applyCategory(images, indices, category) -> new images w/ category set on those
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

	// Bulk-set `category` on the image rows at the given `indices` (a list of
	// positions into `images`). Returns a NEW array; changed rows are shallow
	// copies so the input array and its untouched rows are never mutated.
	// Out-of-range / non-number indices are ignored; the category is trimmed.
	function applyCategory(images, indices, category) {
		if (!Array.isArray(images)) { return images; }
		var cat = (category == null ? '' : String(category)).trim();
		var want = {};
		(Array.isArray(indices) ? indices : []).forEach(function (i) {
			if (typeof i === 'number' && i >= 0 && i < images.length) { want[i] = true; }
		});
		return images.map(function (img, i) {
			if (!want[i]) { return img; }
			var next = {};
			for (var k in img) { if (Object.prototype.hasOwnProperty.call(img, k)) { next[k] = img[k]; } }
			next.category = cat;
			return next;
		});
	}

	var api = { historyPush: historyPush, historyUndo: historyUndo, historyRedo: historyRedo, reorder: reorder, applyCategory: applyCategory };
	if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
	root.AQHistory = api;
})(typeof window !== 'undefined' ? window : globalThis);
