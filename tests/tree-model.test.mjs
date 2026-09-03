/**
 * Unit tests for the builder's PURE element-tree derivation
 * (plugin/aq-core/admin/editor/tree-model.js). No DOM — the module sets
 * globalThis.AQTree as a side effect when imported.
 *
 *   node tests/tree-model.test.mjs
 */
import '../plugin/aq-core/admin/editor/tree-model.js';

const AQTree = globalThis.AQTree;
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

const fields = [
	{ name: 'eyebrow', label: 'Eyebrow', type: 'text' },
	{ name: 'heading', label: 'Heading', type: 'text' },
	{ name: 'services', label: 'Services', type: 'repeater',
		subfields: [{ name: 'label', label: 'Label', type: 'text' }] },
	{ name: 'bg', label: 'Background', type: 'select', group: 'design' },
];
const section = {
	type: 'demo', eyebrow: 'Hi', heading: 'Yo',
	services: [{ label: 'Irrigation' }, { label: 'Lighting' }],
	bg: 'white',
};

t('module loaded and exposes deriveNodes', function () {
	ok(AQTree && typeof AQTree.deriveNodes === 'function', 'AQTree.deriveNodes is a function');
});

t('auto: content fields become field nodes, design collected last', function () {
	const nodes = AQTree.deriveNodes(fields, null, section);
	const kinds = nodes.map(n => n.kind);
	eq(['eyebrow', 'heading'], nodes.filter(n => n.kind === 'field').map(n => n.path.field));
	const design = nodes.find(n => n.kind === 'group' && n.path.group === 'design');
	ok(design, 'has a design group node');
	eq(1, design.fields.length);
	eq('group', kinds[kinds.length - 1], 'design is last');
});

t('auto: repeater expands to one item node per row', function () {
	const nodes = AQTree.deriveNodes(fields, null, section);
	const rep = nodes.find(n => n.kind === 'repeater' && n.path.repeater === 'services');
	ok(rep && rep.expandable, 'repeater node is expandable');
	eq(2, rep.children.length);
	eq([0, 1], rep.children.map(c => c.path.rindex));
	eq('Irrigation', rep.children[0].label, 'item label from first text subfield value');
	eq(1, rep.children[0].fields.length);
});

t('hint: order + labels + grouping + prefix match', function () {
	const wide = fields.concat([
		{ name: 'help_title', label: 'Help title', type: 'text' },
		{ name: 'help_text', label: 'Help text', type: 'textarea' },
	]);
	const hint = [
		{ label: 'Intro', icon: 'text', fields: ['eyebrow', 'heading'] },
		{ label: 'Sidebar: Help', icon: 'card', fields: ['help_*'] },
		{ label: 'Services', icon: 'check', repeater: 'services' },
		{ label: 'Design', icon: 'gear', group: 'design' },
	];
	const nodes = AQTree.deriveNodes(wide, hint, Object.assign({}, section, { help_title: 'H', help_text: 'T' }));
	eq(['Intro', 'Sidebar: Help', 'Services', 'Design'], nodes.map(n => n.label));
	eq('group', nodes[0].kind, '2 fields bundled into a group');
	eq(2, nodes[0].fields.length);
	eq(2, nodes[1].fields.length, 'help_* matched both');
	eq('repeater', nodes[2].kind);
});

t('hint: fields omitted from the hint are auto-appended (never hidden)', function () {
	const hint = [{ label: 'Just heading', icon: 'text', fields: ['heading'] }];
	const nodes = AQTree.deriveNodes(fields, hint, section);
	const names = nodes.flatMap(n => n.fields.map(f => f.name));
	ok(names.includes('eyebrow'), 'omitted eyebrow still present');
	ok(names.includes('bg'), 'omitted design field still present');
});

t('fixed node renders no fields', function () {
	const hint = [{ label: 'Form', icon: 'form', fixed: true }];
	const nodes = AQTree.deriveNodes(fields, hint, section);
	const fixed = nodes.find(n => n.kind === 'fixed');
	ok(fixed, 'has a fixed node');
	eq(0, fixed.fields.length);
	eq(false, fixed.expandable);
});

t('robust: never throws on malformed field arrays (auto + hint)', function () {
	const junk = [null, undefined, {}, { name: 'ok', label: 'OK', type: 'text' }, { label: 'noName', type: 'text' }];
	// auto path
	let nodes = AQTree.deriveNodes(junk, null, { ok: 'v' });
	eq(1, nodes.length, 'only the one valid field survives (auto)');
	eq('ok', nodes[0].path.field);
	// hint path with a prefix that would hit a nameless field if unguarded
	nodes = AQTree.deriveNodes(junk, [{ label: 'G', fields: ['ok', 'no*'] }], { ok: 'v' });
	ok(Array.isArray(nodes), 'hint path returns an array without throwing');
	// non-array / null fields
	eq([], AQTree.deriveNodes(null, null, {}), 'null fields → []');
	eq([], AQTree.deriveNodes(undefined, [{ label: 'X', fields: ['y'] }], {}), 'undefined fields → []');
});

t('hint: a design field named in a non-design entry is NOT duplicated', function () {
	// 'bg' is group:design. A hint that names it in a custom entry must not pull it
	// into that entry AND the Design group — it belongs to Design only.
	const hint = [
		{ label: 'Custom', fields: ['heading', 'bg'] },
		{ label: 'Design', group: 'design' },
	];
	const nodes = AQTree.deriveNodes(fields, hint, section);
	const allFieldNames = nodes.flatMap(n => n.fields.map(f => f.name));
	const bgCount = allFieldNames.filter(n => n === 'bg').length;
	eq(1, bgCount, 'bg appears exactly once');
	const custom = nodes.find(n => n.label === 'Custom');
	ok(!custom.fields.some(f => f.name === 'bg'), 'bg not in the custom group');
	const design = nodes.find(n => n.path.group === 'design');
	ok(design.fields.some(f => f.name === 'bg'), 'bg is under Design');
});

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
