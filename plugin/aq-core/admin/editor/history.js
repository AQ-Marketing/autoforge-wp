/**
 * AQ Editor — pure undo/redo history helpers.
 *
 * Kept in their own file so they are dependency-free and unit-testable both in
 * the browser (window.AQHistory) and under Node (module.exports) — see
 * tests/builder-history-test.mjs. The builder holds a history object
 * { stack: snapshot[], ptr: int, cap: int } and drives it through these:
 *
 *   historyPush(stack, snapshot, cap) -> new stack (append + trim oldest past cap)
 *   historyUndo({stack, ptr})         -> { ptr, snapshot, changed }
 *   historyRedo({stack, ptr})         -> { ptr, snapshot, changed }
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

	var api = { historyPush: historyPush, historyUndo: historyUndo, historyRedo: historyRedo };
	if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
	root.AQHistory = api;
})(typeof window !== 'undefined' ? window : globalThis);
