'use strict';
const test = require('node:test');
const assert = require('node:assert');
const AQTree = require('./tree-model.js');

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

test('auto: content fields become field nodes, design collected last', () => {
  const nodes = AQTree.deriveNodes(fields, null, section);
  const kinds = nodes.map(n => n.kind);
  assert.deepStrictEqual(
    nodes.filter(n => n.kind === 'field').map(n => n.path.field),
    ['eyebrow', 'heading']
  );
  const design = nodes.find(n => n.kind === 'group' && n.path.group === 'design');
  assert.ok(design, 'has a design group node');
  assert.strictEqual(design.fields.length, 1);
  assert.strictEqual(kinds[kinds.length - 1], 'group'); // design is last
});

test('auto: repeater expands to one item node per row', () => {
  const nodes = AQTree.deriveNodes(fields, null, section);
  const rep = nodes.find(n => n.kind === 'repeater' && n.path.repeater === 'services');
  assert.ok(rep && rep.expandable);
  assert.strictEqual(rep.children.length, 2);
  assert.deepStrictEqual(rep.children.map(c => c.path.rindex), [0, 1]);
  assert.strictEqual(rep.children[0].label, 'Irrigation'); // label from first text subfield value
  assert.strictEqual(rep.children[0].fields.length, 1);
});

test('hint: order + labels + grouping + prefix match', () => {
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
  assert.deepStrictEqual(nodes.map(n => n.label),
    ['Intro', 'Sidebar: Help', 'Services', 'Design']);
  assert.strictEqual(nodes[0].kind, 'group');      // 2 fields bundled
  assert.strictEqual(nodes[0].fields.length, 2);
  assert.strictEqual(nodes[1].fields.length, 2);    // help_* matched both
  assert.strictEqual(nodes[2].kind, 'repeater');
});

test('hint: fields omitted from the hint are auto-appended (never hidden)', () => {
  const hint = [{ label: 'Just heading', icon: 'text', fields: ['heading'] }];
  const nodes = AQTree.deriveNodes(fields, hint, section);
  const names = nodes.flatMap(n => n.fields.map(f => f.name));
  assert.ok(names.includes('eyebrow'), 'omitted eyebrow still present');
  assert.ok(names.includes('bg'), 'omitted design field still present');
});

test('fixed node renders no fields', () => {
  const hint = [{ label: 'Form', icon: 'form', fixed: true }];
  const nodes = AQTree.deriveNodes(fields, hint, section);
  const fixed = nodes.find(n => n.kind === 'fixed');
  assert.ok(fixed);
  assert.strictEqual(fixed.fields.length, 0);
  assert.strictEqual(fixed.expandable, false);
});
