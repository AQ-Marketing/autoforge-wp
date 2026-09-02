/**
 * Unit tests for the builder's PURE undo/redo helpers
 * (plugin/aq-core/admin/editor/history.js). No DOM — the module sets
 * globalThis.AQHistory as a side effect when imported.
 *
 *   node tests/builder-history-test.mjs
 */
import '../plugin/aq-core/admin/editor/history.js';

const H = globalThis.AQHistory;
let pass = 0, fail = 0;
const J = (x) => JSON.stringify(x);
function t(name, fn) {
	try { fn(); pass++; console.log('  ok   ' + name); }
	catch (e) { fail++; console.log('  FAIL ' + name + '\n       ' + e.message); }
}
function eq(expected, actual, msg) {
	if (J(expected) !== J(actual)) {
		throw new Error((msg ? msg + ': ' : '') + 'expected ' + J(expected) + ', got ' + J(actual));
	}
}
function ok(cond, msg) { if (!cond) { throw new Error(msg || 'expected truthy'); } }

t('module loaded and exposes the three helpers', function () {
	ok(H && typeof H.historyPush === 'function' && typeof H.historyUndo === 'function' && typeof H.historyRedo === 'function');
});

t('historyPush appends within cap', function () {
	let s = [];
	s = H.historyPush(s, 'a', 50);
	s = H.historyPush(s, 'b', 50);
	eq(['a', 'b'], s);
});

t('historyPush trims the OLDEST past the cap', function () {
	let s = [];
	for (let i = 1; i <= 5; i++) { s = H.historyPush(s, i, 3); }
	eq([3, 4, 5], s, 'cap 3 keeps the newest three');
});

t('historyPush with cap<=0 never trims', function () {
	let s = [];
	for (let i = 1; i <= 4; i++) { s = H.historyPush(s, i, 0); }
	eq([1, 2, 3, 4], s);
});

t('historyPush is pure (does not mutate the input stack)', function () {
	const original = ['a'];
	const next = H.historyPush(original, 'b', 50);
	eq(['a'], original, 'input unchanged');
	eq(['a', 'b'], next);
});

t('undo steps the pointer back and returns that snapshot', function () {
	const st = { stack: ['a', 'b', 'c'], ptr: 2 };
	const r = H.historyUndo(st);
	eq(1, r.ptr);
	eq('b', r.snapshot);
	ok(r.changed === true);
});

t('redo steps the pointer forward and returns that snapshot', function () {
	const st = { stack: ['a', 'b', 'c'], ptr: 1 };
	const r = H.historyRedo(st);
	eq(2, r.ptr);
	eq('c', r.snapshot);
	ok(r.changed === true);
});

t('undo then redo returns to the same snapshot', function () {
	let st = { stack: ['a', 'b', 'c'], ptr: 2 };
	const u = H.historyUndo(st);       // → ptr 1 (b)
	st = { stack: st.stack, ptr: u.ptr };
	const r = H.historyRedo(st);       // → ptr 2 (c)
	eq(2, r.ptr);
	eq('c', r.snapshot);
	ok(u.changed && r.changed);
});

t('undo at the start is a no-op (changed:false, pointer unmoved)', function () {
	const st = { stack: ['a', 'b'], ptr: 0 };
	const r = H.historyUndo(st);
	eq(0, r.ptr);
	ok(r.changed === false);
});

t('redo at the end is a no-op (changed:false, pointer unmoved)', function () {
	const st = { stack: ['a', 'b'], ptr: 1 };
	const r = H.historyRedo(st);
	eq(1, r.ptr);
	ok(r.changed === false);
});

t('single-entry stack: both undo and redo are no-ops', function () {
	const st = { stack: ['only'], ptr: 0 };
	ok(H.historyUndo(st).changed === false);
	ok(H.historyRedo(st).changed === false);
});

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail > 0 ? 1 : 0);
