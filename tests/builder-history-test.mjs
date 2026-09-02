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

t('module loaded and exposes the helpers', function () {
	ok(H && typeof H.historyPush === 'function' && typeof H.historyUndo === 'function'
		&& typeof H.historyRedo === 'function' && typeof H.reorder === 'function'
		&& typeof H.applyCategory === 'function' && typeof H.galleryCfg === 'function');
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

/* ---- reorder (gallery tile drag) ---- */
t('reorder applies a full permutation', function () {
	eq(['c', 'a', 'b'], H.reorder(['a', 'b', 'c'], [2, 0, 1]));
});

t('reorder identity order returns the same sequence', function () {
	eq(['a', 'b', 'c'], H.reorder(['a', 'b', 'c'], [0, 1, 2]));
});

t('reorder moving one item to the front', function () {
	// order built by canvas when dragging index 2 before index 0
	eq(['c', 'a', 'b'], H.reorder(['a', 'b', 'c'], [2, 0, 1]));
});

t('reorder appends items missing from the index list (nothing dropped)', function () {
	eq(['b', 'a', 'c'], H.reorder(['a', 'b', 'c'], [1, 0]));
});

t('reorder ignores out-of-range and duplicate indices', function () {
	eq(['b', 'a', 'c'], H.reorder(['a', 'b', 'c'], [1, 9, 1, 0]));
});

t('reorder is pure (input list untouched)', function () {
	const src = ['a', 'b', 'c'];
	H.reorder(src, [2, 1, 0]);
	eq(['a', 'b', 'c'], src);
});

t('reorder with a non-array order returns a copy of the list', function () {
	const src = ['a', 'b'];
	const out = H.reorder(src, null);
	eq(['a', 'b'], out);
	ok(out !== src, 'returns a new array');
});

/* ---- applyCategory (bulk category editing) ---- */
t('applyCategory sets the category on the given indices only', function () {
	var imgs = [{ id: 1, category: '' }, { id: 2, category: 'Old' }, { id: 3, category: '' }];
	var out = H.applyCategory(imgs, [0, 2], 'House');
	eq('House', out[0].category);
	eq('Old', out[1].category);
	eq('House', out[2].category);
});

t('applyCategory preserves other fields on changed rows', function () {
	var out = H.applyCategory([{ id: 7, caption: 'Front', category: '' }], [0], 'Roof');
	eq({ id: 7, caption: 'Front', category: 'Roof' }, out[0]);
});

t('applyCategory with an empty selection changes nothing', function () {
	var imgs = [{ id: 1, category: 'A' }, { id: 2, category: 'B' }];
	eq(imgs, H.applyCategory(imgs, [], 'X'));
});

t('applyCategory ignores out-of-range / non-number indices', function () {
	var imgs = [{ id: 1, category: '' }, { id: 2, category: '' }];
	var out = H.applyCategory(imgs, [5, -1, '0', 1], 'Deck');
	eq('', out[0].category, 'string "0" is not a numeric index');
	eq('Deck', out[1].category);
});

t('applyCategory trims the category', function () {
	var out = H.applyCategory([{ id: 1, category: '' }], [0], '  Patio  ');
	eq('Patio', out[0].category);
});

t('applyCategory can clear a category (empty string)', function () {
	var out = H.applyCategory([{ id: 1, category: 'House' }], [0], '');
	eq('', out[0].category);
});

t('applyCategory is pure (input array + untouched rows not mutated)', function () {
	var imgs = [{ id: 1, category: 'A' }, { id: 2, category: 'B' }];
	var out = H.applyCategory(imgs, [0], 'Z');
	eq('A', imgs[0].category, 'input row unchanged');
	ok(out !== imgs, 'returns a new array');
	ok(out[1] === imgs[1], 'unchanged row keeps its reference');
});

t('applyCategory returns the input when images is not an array', function () {
	eq(null, H.applyCategory(null, [0], 'X'));
});

t('applyCategory writes a custom field name when given one', function () {
	var out = H.applyCategory([{ file: 'a.webp', tag: '' }], [0], 'Roof', 'tag');
	eq('Roof', out[0].tag);
	ok(!('category' in out[0]), 'did not touch the default field');
});

/* ---- galleryCfg (gallery_editor config merge) ---- */
t('galleryCfg with no override returns the aq_gallery defaults', function () {
	eq({
		items: 'images', image: 'id', image_format: 'id', category: 'category',
		caption: 'caption', categories: 'categories', filters: 'filters_enabled',
		columns: 'columns', gap: 'gap', order_by: 'order_by', lightbox: 'lightbox'
	}, H.galleryCfg());
});

t('galleryCfg with null/non-object returns defaults', function () {
	eq('images', H.galleryCfg(null).items);
	eq('images', H.galleryCfg('nope').items);
});

t('galleryCfg merges a partial override over the defaults', function () {
	var c = H.galleryCfg({ items: 'photos', image: 'file', image_format: 'basename', categories: 'derive', filters: 'always' });
	eq('photos', c.items);
	eq('file', c.image);
	eq('basename', c.image_format);
	eq('derive', c.categories);
	eq('always', c.filters);
	eq('caption', c.caption, 'untouched key keeps its default');
	eq('order_by', c.order_by);
	eq('lightbox', c.lightbox);
});

t('galleryCfg preserves an explicit null (hidden control)', function () {
	var c = H.galleryCfg({ gap: null, lightbox: null, order_by: null });
	eq(null, c.gap);
	eq(null, c.lightbox);
	eq(null, c.order_by);
	eq('columns', c.columns, 'other layout keys unaffected');
});

t('galleryCfg supports turning off the category feature', function () {
	eq('', H.galleryCfg({ category: '' }).category);
});

/* ---- filterPages (page-switcher typeahead matcher) ---- */
var PAGES = [
	{ id: 1, title: 'About Us', path: '/about/' },
	{ id: 2, title: 'Contact', path: '/contact/' },
	{ id: 3, title: 'House Washing', path: '/house-pressure-washing/' },
	{ id: 4, title: 'Pressure Washing Nashua NH', path: '/pressure-washing-nashua-nh/' },
	{ id: 5, title: 'Home', path: '/' }
];

t('filterPages exposed', function () { ok(typeof H.filterPages === 'function'); });

t('filterPages empty query returns all (capped)', function () {
	eq(5, H.filterPages(PAGES, '').length);
	eq(2, H.filterPages(PAGES, '', 2).length);
});

t('filterPages matches title (case-insensitive substring)', function () {
	var r = H.filterPages(PAGES, 'wash');
	eq(2, r.length);
	eq([3, 4], r.map(function (p) { return p.id; }));
});

t('filterPages matches path when title does not', function () {
	var r = H.filterPages(PAGES, 'nashua');
	eq(1, r.length); eq(4, r[0].id);
});

t('filterPages trims and lowercases the query', function () {
	eq(1, H.filterPages(PAGES, '  CONTACT  ').length);
});

t('filterPages caps results at the limit (default 10)', function () {
	var many = [];
	for (var i = 0; i < 25; i++) { many.push({ id: i, title: 'Service ' + i, path: '/s' + i + '/' }); }
	eq(10, H.filterPages(many, 'service').length);
	eq(3, H.filterPages(many, 'service', 3).length);
});

t('filterPages preserves input order', function () {
	var r = H.filterPages(PAGES, '/');
	eq([1, 2, 3, 4, 5], r.map(function (p) { return p.id; }));
});

t('filterPages is null-safe on list and rows', function () {
	eq([], H.filterPages(null, 'x'));
	eq([], H.filterPages(undefined, ''));
	eq(1, H.filterPages([null, { id: 9, title: 'Roof Cleaning', path: '/roof/' }], 'roof').length);
});

t('filterPages no match returns empty', function () {
	eq([], H.filterPages(PAGES, 'zzznothing'));
});

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail > 0 ? 1 : 0);
