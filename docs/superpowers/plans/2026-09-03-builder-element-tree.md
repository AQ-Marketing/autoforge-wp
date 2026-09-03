# Builder Nested Element Tree — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the AutoForge builder's left panel into a nested element tree (section → its editable elements) where clicking a node shows only that element's controls on the right, driven automatically by each section's existing field schema plus an optional per-section curation hint.

**Architecture:** A new pure JS module (`tree-model.js`, exposed as `window.AQTree`) turns a section's fields + optional `elements` hint into an ordered node list (the only unit-tested piece). `builder.js` renders that node list as a tree and renders the selected node's controls, reusing the existing `renderField`/`renderRepeater`/`postCanvas` helpers. No content data-model, save, or canvas-protocol changes. PHP change is limited to enqueuing the module; the `elements` hint rides through the existing `CFG.schema` localization. The pilot adds a curation hint for PJP's `pjp_estimate` in the PJP theme.

**Tech Stack:** Vanilla JS (no bundler; scripts expose globals like `window.AQHistory`), Node's built-in `node:test` for the pure module, PHP 8 (WordPress plugin), WP-CLI (`wp eval`) for PHP verification, real Chrome (claude-in-chrome) for builder QC.

**Fleet-wide — this ships to EVERY AutoForge site in 0.3.64, not just PJP.** The engine change must therefore be safe for every section type on every site with zero per-site config: schema-defined sections, sections with only inferred fields (no schema), and sections with no hint. `AQTree.deriveNodes` must never throw and must always yield a usable tree (empty fields → empty child list, section root still selectable). The Estimate curation hint (Task 7) is the ONLY site-specific piece and lives in the PJP theme; the fleet gets the auto tree with no hint. QC (Task 8) must therefore verify at least one hint-less auto section in addition to the curated Estimate, and the fleet release stays gated (Task 9).

**Repos/paths:**
- Engine: `C:\Users\justi\Apps\Work\AutoForge WP` (branch `feat/builder-element-tree`, off `v0.3.63`).
- PJP site (pilot + deploy target): `C:\Users\justi\Apps\Work\Websites\PJ Pappas`.
- Prod deploy for QC uses the PJP Pressable SSH (see the project memory `pjp-production-access`); QC page is `page_id=28`.

---

## File Structure

**Create**
- `plugin/aq-core/admin/editor/tree-model.js` — pure node-derivation module (`window.AQTree`). One responsibility: fields (+hint+section) → ordered node list.
- `plugin/aq-core/admin/editor/tree-model.test.js` — `node:test` unit tests for the module.

**Modify (engine)**
- `plugin/aq-core/includes/class-editor.php` — enqueue `tree-model.js` before `builder.js`; add it to `builder.js` deps. (No `field_schema()` semantic change.)
- `plugin/aq-core/admin/editor/builder.js` — selection model (`state.node`), `selectNode()`, `renderTree()` (replaces `renderStructure()`), `renderNodeInspector()` (replaces `renderInspector()`), canvas-select→node mapping, history snapshot includes `node`.
- `plugin/aq-core/admin/editor/builder.css` — tree rows, disclosure, indent, active state, node icons, collapsible right-panel groups.
- `plugin/aq-core/aq-core.php` + `package.json` — version bump to 0.3.64 (final task).

**Modify (PJP site — pilot only)**
- `theme/pjp/functions.php` — `aq_editor_field_schema` entry for `pjp_estimate` (labeled fields + `elements` hint).

---

## Node descriptor contract (used across tasks)

`AQTree.deriveNodes(fields, elementsHint, section)` returns an ordered array of nodes. Each node:

```
{
  kind: 'field' | 'repeater' | 'item' | 'group' | 'fixed',
  key:   string,        // stable id for DOM + expand-state, unique within the section
  label: string,
  icon:  string,        // icon name: 'text','list','check','card','form','image','link','gear','section'
  path:  { field?: string, repeater?: string, rindex?: number, group?: string },
  expandable: boolean,  // true for 'repeater'
  fields: Array,        // field descriptors this node's inspector renders (may be [])
  children: Array       // child nodes (item nodes for a 'repeater'); else []
}
```

- `field`: one control. `fields = [thatFieldDescriptor]`.
- `group`: several controls under one label (e.g. Design, or a hint bundle). `fields = [thoseDescriptors]`.
- `repeater`: expandable; `fields = []`; `children` = one `item` node per row in `section[repeaterName]`.
- `item`: one repeater row; `fields = repeater.subfields`; `path = {repeater, rindex}`.
- `fixed`: informational leaf (e.g. the form); `fields = []`.

---

## Task 1: Pure node-derivation module (`tree-model.js`)

**Files:**
- Create: `plugin/aq-core/admin/editor/tree-model.js`
- Test: `plugin/aq-core/admin/editor/tree-model.test.js`

- [ ] **Step 1: Write the failing tests**

Create `plugin/aq-core/admin/editor/tree-model.test.js`:

```js
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `node --test plugin/aq-core/admin/editor/tree-model.test.js`
Expected: FAIL — `Cannot find module './tree-model.js'`.

- [ ] **Step 3: Implement the module**

Create `plugin/aq-core/admin/editor/tree-model.js`:

```js
/**
 * AQTree — pure derivation of a section's editable "element" nodes from its
 * field schema (+ optional per-section `elements` curation hint). No DOM, no
 * globals beyond the export shim at the bottom. builder.js renders the result.
 */
(function () {
  'use strict';

  function humanize(name) {
    return String(name || '').replace(/[_-]+/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  // Icon name for an auto field, by known name then type.
  function autoIcon(f) {
    var n = (f && f.name || '').toLowerCase();
    var t = (f && f.type || '').toLowerCase();
    if (/image|photo|bg|background/.test(n) || t === 'image') { return 'image'; }
    if (/href|link|url/.test(n) || t === 'url') { return 'link'; }
    if (t === 'repeater') { return 'list'; }
    return 'text';
  }

  // First non-empty short text value in a repeater row, for its node label.
  function itemLabel(subfields, row, fallback) {
    for (var i = 0; i < subfields.length; i++) {
      var sf = subfields[i];
      if ((sf.type === 'text' || sf.type === 'textarea') && row && typeof row[sf.name] === 'string' && row[sf.name].trim()) {
        return row[sf.name].trim();
      }
    }
    return fallback;
  }

  function repeaterNode(f, section, keyBase, label, icon) {
    var rows = (section && Array.isArray(section[f.name])) ? section[f.name] : [];
    var subs = f.subfields || [];
    var children = rows.map(function (row, ri) {
      return {
        kind: 'item', key: keyBase + '/rep:' + f.name + '/i:' + ri,
        label: itemLabel(subs, row, (label || humanize(f.name)) + ' ' + (ri + 1)),
        icon: 'text', path: { repeater: f.name, rindex: ri },
        expandable: false, fields: subs.slice(), children: []
      };
    });
    return {
      kind: 'repeater', key: keyBase + '/rep:' + f.name,
      label: label || f.label || humanize(f.name), icon: icon || 'list',
      path: { repeater: f.name }, expandable: true, fields: [], children: children
    };
  }

  function fieldNode(f, keyBase, label, icon) {
    return {
      kind: 'field', key: keyBase + '/f:' + f.name,
      label: label || f.label || humanize(f.name), icon: icon || autoIcon(f),
      path: { field: f.name }, expandable: false, fields: [f], children: []
    };
  }

  function groupNode(matched, keyBase, label, icon, groupKey) {
    return {
      kind: 'group', key: keyBase + '/g:' + (groupKey || label),
      label: label, icon: icon || 'gear',
      path: groupKey ? { group: groupKey } : { field: matched[0] && matched[0].name },
      expandable: false, fields: matched.slice(), children: []
    };
  }

  // Resolve a hint entry's `fields` (exact names or `name*` prefixes) to descriptors.
  function resolveFields(names, byName, allFields, consumed) {
    var out = [];
    names.forEach(function (spec) {
      if (spec.slice(-1) === '*') {
        var pre = spec.slice(0, -1);
        allFields.forEach(function (f) {
          if (f.name.indexOf(pre) === 0 && !consumed[f.name]) { out.push(f); consumed[f.name] = true; }
        });
      } else if (byName[spec] && !consumed[spec]) {
        out.push(byName[spec]); consumed[spec] = true;
      }
    });
    return out;
  }

  function deriveNodes(fields, elementsHint, section) {
    fields = Array.isArray(fields) ? fields : [];
    var keyBase = 'sec';
    var byName = {};
    fields.forEach(function (f) { if (f && f.name) { byName[f.name] = f; } });
    var content = fields.filter(function (f) { return f.group !== 'design'; });
    var design = fields.filter(function (f) { return f.group === 'design'; });

    // ---- Curated (hint) ----
    if (Array.isArray(elementsHint) && elementsHint.length) {
      var nodes = [];
      var consumed = {};
      var designPlaced = false;
      elementsHint.forEach(function (h) {
        if (h.fixed) {
          nodes.push({ kind: 'fixed', key: keyBase + '/x:' + (h.label || 'fixed'),
            label: h.label || 'Fixed element', icon: h.icon || 'form',
            path: {}, expandable: false, fields: [], children: [] });
          return;
        }
        if (h.group === 'design') {
          designPlaced = true;
          if (design.length) { nodes.push(groupNode(design, keyBase, h.label || 'Design', h.icon || 'gear', 'design')); }
          return;
        }
        if (h.repeater && byName[h.repeater]) {
          consumed[h.repeater] = true;
          nodes.push(repeaterNode(byName[h.repeater], section, keyBase, h.label, h.icon));
          return;
        }
        if (Array.isArray(h.fields)) {
          var matched = resolveFields(h.fields, byName, fields, consumed);
          if (matched.length === 1) { nodes.push(fieldNode(matched[0], keyBase, h.label, h.icon)); }
          else if (matched.length > 1) { nodes.push(groupNode(matched, keyBase, h.label || 'Group', h.icon)); }
        }
      });
      // Auto-append any content field the hint did not consume (never hide data).
      content.forEach(function (f) {
        if (consumed[f.name]) { return; }
        if (f.type === 'repeater') { nodes.push(repeaterNode(f, section, keyBase)); }
        else { nodes.push(fieldNode(f, keyBase)); }
        consumed[f.name] = true;
      });
      if (!designPlaced && design.length) { nodes.push(groupNode(design, keyBase, 'Design', 'gear', 'design')); }
      return nodes;
    }

    // ---- Auto (no hint) ----
    var auto = [];
    content.forEach(function (f) {
      if (f.type === 'repeater') { auto.push(repeaterNode(f, section, keyBase)); }
      else { auto.push(fieldNode(f, keyBase)); }
    });
    if (design.length) { auto.push(groupNode(design, keyBase, 'Design', 'gear', 'design')); }
    return auto;
  }

  var AQTree = { deriveNodes: deriveNodes, humanize: humanize };
  if (typeof module !== 'undefined' && module.exports) { module.exports = AQTree; }
  if (typeof window !== 'undefined') { window.AQTree = AQTree; }
})();
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `node --test plugin/aq-core/admin/editor/tree-model.test.js`
Expected: PASS — 5 tests, 0 failures.

- [ ] **Step 5: Commit**

```bash
git add plugin/aq-core/admin/editor/tree-model.js plugin/aq-core/admin/editor/tree-model.test.js
git commit -m "feat(builder): pure AQTree node-derivation module + tests"
```

---

## Task 2: Enqueue the module (PHP)

**Files:**
- Modify: `plugin/aq-core/includes/class-editor.php:128-129`

- [ ] **Step 1: Add the enqueue and dependency**

At `plugin/aq-core/includes/class-editor.php`, the two lines currently read:

```php
		wp_enqueue_script('aq-history', $base . 'history.js', [], self::ver($dir . 'history.js'), true);
		wp_enqueue_script('aq-builder', $base . 'builder.js', ['jquery', 'aq-history'], self::ver($dir . 'builder.js'), true);
```

Replace them with:

```php
		wp_enqueue_script('aq-history', $base . 'history.js', [], self::ver($dir . 'history.js'), true);
		wp_enqueue_script('aq-tree', $base . 'tree-model.js', [], self::ver($dir . 'tree-model.js'), true);
		wp_enqueue_script('aq-builder', $base . 'builder.js', ['jquery', 'aq-history', 'aq-tree'], self::ver($dir . 'builder.js'), true);
```

- [ ] **Step 2: Lint the PHP**

Run: `php -l plugin/aq-core/includes/class-editor.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify the `elements` hint rides through `field_schema()`**

This proves a theme-registered `elements` key reaches `CFG.schema` unmodified. Run against any local/staging WP with aq-core active (or PJP prod per the memory), via a temp eval file:

```php
add_filter('aq_editor_field_schema', function ($s) {
  $s['__probe__'] = ['fields' => [['name'=>'x','label'=>'X','type'=>'text']], 'elements' => [['label'=>'One','fields'=>['x']]]];
  return $s;
});
$schema = AQ_Editor::field_schema();
echo isset($schema['__probe__']['elements']) ? "PASS elements preserved\n" : "FAIL elements dropped\n";
```

Run: `wp eval-file <that file>`
Expected: `PASS elements preserved`.

- [ ] **Step 4: Commit**

```bash
git add plugin/aq-core/includes/class-editor.php
git commit -m "feat(builder): enqueue tree-model.js before builder.js"
```

---

## Task 3: Selection model + canvas→node mapping (builder.js)

**Files:**
- Modify: `plugin/aq-core/admin/editor/builder.js` (state block ~line 21; snapshot/restore ~123-147; canvas message handler ~1435)

- [ ] **Step 1: Add node state and schema/node helpers**

In the `state` object (line 21), add `node: null` and `tree: { expanded: {} }`. Immediately after the `HIST` line (line 22), add:

```js
	// --- element-tree helpers ---
	function schemaEntry(type) { return (CFG.schema && CFG.schema[type]) || null; }
	function fieldsForType(type, s) {
		var e = schemaEntry(type);
		var f = (e && e.fields) ? e.fields : [];
		return f.length ? f : inferFields(s);
	}
	function elementsHintFor(type) { var e = schemaEntry(type); return (e && e.elements) ? e.elements : null; }
	function nodesForSection(i) {
		var s = state.sections[i];
		if (!s) { return []; }
		return window.AQTree.deriveNodes(fieldsForType(s.type, s), elementsHintFor(s.type), s);
	}
	function samePath(a, b) {
		a = a || {}; b = b || {};
		return a.field === b.field && a.repeater === b.repeater &&
			(a.rindex == null ? b.rindex == null : a.rindex === b.rindex) && a.group === b.group;
	}
	// Find the node in section i whose path matches m {field,repeater,rindex}. Falls back to null.
	function findNodePath(i, m) {
		var want = { field: m && m.field, repeater: m && m.repeater, rindex: (m && m.rindex != null) ? m.rindex : null, group: undefined };
		var hit = null;
		nodesForSection(i).forEach(function (n) {
			if (!hit && samePath(n.path, want)) { hit = n.path; }
			(n.children || []).forEach(function (c) { if (!hit && samePath(c.path, want)) { hit = c.path; } });
			// a field that lives inside a group node
			if (!hit && n.fields) { n.fields.forEach(function (f) { if (want.field && f.name === want.field) { hit = n.path; } }); }
		});
		return hit;
	}
```

- [ ] **Step 2: Include `node` in history snapshots**

Line 123 currently:

```js
	function snapshot() { return { sections: clone(state.sections), selected: state.selected }; }
```

Replace with:

```js
	function snapshot() { return { sections: clone(state.sections), selected: state.selected, node: state.node }; }
```

Line 146-147 (inside `applySnapshot`) currently:

```js
		state.sections = (snap.sections || []).map(function (s) { s._uid = ++uid; return s; });
		state.selected = (snap.selected != null && snap.selected < state.sections.length) ? snap.selected : -1;
```

Add immediately after those two lines:

```js
		state.node = (snap.node && snap.node.section === state.selected) ? snap.node : (state.selected >= 0 ? { section: state.selected } : null);
```

- [ ] **Step 3: Add `selectNode()` and map canvas select → node**

Add this function next to `selectSection` (after line 732):

```js
	function selectNode(i, path) {
		state.selected = i;
		state.node = Object.assign({ section: i }, path || {});
		renderTree();
		renderNodeInspector();
		postCanvas({ type: 'highlight', index: i, field: state.node.field || null,
			repeater: state.node.repeater || null, rindex: (state.node.rindex != null) ? state.node.rindex : null });
	}
```

Find the canvas message handler that currently reads (around line 1435):

```js
		if (state.selected !== m.index) { selectSection(m.index, false); }
```

Replace it with:

```js
		var np = findNodePath(m.index, m);
		selectNode(m.index, np || {});
```

- [ ] **Step 4: Verify no syntax errors (Node parse check)**

Run: `node --check plugin/aq-core/admin/editor/builder.js`
Expected: no output (exit 0). If it errors on `window`/browser globals, ignore only parser-level issues — `--check` is syntax-only and passes for browser JS.

- [ ] **Step 5: Commit**

```bash
git add plugin/aq-core/admin/editor/builder.js
git commit -m "feat(builder): node selection model + canvas->node mapping"
```

---

## Task 4: Render the tree (builder.js `renderTree`)

**Files:**
- Modify: `plugin/aq-core/admin/editor/builder.js` — replace `renderStructure` (lines 681-718); update its callers.

- [ ] **Step 1: Replace `renderStructure` with `renderTree` (+ icon + node-row helpers)**

Replace the whole `renderStructure` function (lines 681-718) with:

```js
	function treeIcon(name) {
		var d = {
			text: 'M4 6h16M4 12h16M4 18h10', list: 'M8 6h12M8 12h12M8 18h12M3 6h.01M3 12h.01M3 18h.01',
			check: 'M4 12l4 4L20 6', card: 'M4 5h16v14H4z M4 9h16', form: 'M5 4h14v16H5z M8 8h8M8 12h8M8 16h4',
			image: 'M4 5h16v14H4z M8 13l3-3 5 5', link: 'M10 13a5 5 0 007 0l2-2a5 5 0 00-7-7l-1 1',
			gear: 'M12 8a4 4 0 100 8 4 4 0 000-8z M2 12h3M19 12h3M12 2v3M12 19v3', section: 'M4 5h16v4H4z M4 13h16v6H4z'
		};
		var el = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		el.setAttribute('class', 'aqb-nodeico'); el.setAttribute('viewBox', '0 0 24 24');
		el.setAttribute('fill', 'none'); el.setAttribute('stroke', 'currentColor'); el.setAttribute('stroke-width', '1.7');
		el.setAttribute('stroke-linecap', 'round'); el.setAttribute('stroke-linejoin', 'round');
		var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		p.setAttribute('d', d[name] || d.text); el.appendChild(p);
		return el;
	}

	function isExpanded(key) { return !!state.tree.expanded[key]; }
	function toggleExpand(key) { state.tree.expanded[key] = !state.tree.expanded[key]; renderTree(); }

	// One clickable node row (used for element nodes and repeater items).
	function nodeRow(i, n, depth) {
		var active = state.node && state.node.section === i && samePath(state.node, n.path);
		var row = ce('div', 'aqb-node' + (active ? ' is-active' : '') + (n.kind === 'fixed' ? ' is-fixed' : ''));
		row.style.paddingLeft = (10 + depth * 14) + 'px';
		if (n.expandable) {
			var tw = ce('button', 'aqb-tw' + (isExpanded(n.key) ? ' is-open' : ''), '▸');
			tw.title = 'Expand'; tw.addEventListener('click', function (e) { e.stopPropagation(); toggleExpand(n.key); });
			row.appendChild(tw);
		} else {
			row.appendChild(ce('span', 'aqb-tw aqb-tw--leaf', ''));
		}
		row.appendChild(treeIcon(n.icon));
		var name = ce('button', 'aqb-nodename', n.label + (n.expandable && n.children.length ? ' (' + n.children.length + ')' : ''));
		name.addEventListener('click', function () {
			if (n.kind === 'repeater') { toggleExpand(n.key); }
			selectNode(i, n.path);
		});
		row.appendChild(name);
		// Repeater-item tools (add handled at the group; item gets remove/reorder).
		if (n.kind === 'item') {
			var tools = ce('div', 'aqb-sectools');
			tools.appendChild(iconBtn('↑', 'Move up', function () { moveItem(i, n.path.repeater, n.path.rindex, -1); }));
			tools.appendChild(iconBtn('↓', 'Move down', function () { moveItem(i, n.path.repeater, n.path.rindex, 1); }));
			tools.appendChild(iconBtn('✕', 'Remove', function () { removeItem(i, n.path.repeater, n.path.rindex); }, true));
			row.appendChild(tools);
		}
		return row;
	}

	function renderTree() {
		var p = els.structure;
		p.innerHTML = '';
		p.appendChild(ce('h3', 'aqb-h', 'Structure'));
		var list = ce('div', 'aqb-seclist');
		state.sections.forEach(function (s, i) {
			var secKey = 'sec:' + i;
			var openSec = i === state.selected || isExpanded(secKey);
			var srow = ce('div', 'aqb-secrow' + (i === state.selected ? ' is-active' : ''));
			var stw = ce('button', 'aqb-tw' + (openSec ? ' is-open' : ''), '▸');
			stw.title = 'Expand section';
			stw.addEventListener('click', function (e) { e.stopPropagation(); state.tree.expanded[secKey] = !openSec; renderTree(); });
			srow.appendChild(stw);
			var name = ce('button', 'aqb-secname', labelFor(s.type));
			name.addEventListener('click', function () { selectNode(i, {}); });
			var tools = ce('div', 'aqb-sectools');
			tools.appendChild(iconBtn('↑', 'Move up', function () { move(i, -1); }));
			tools.appendChild(iconBtn('↓', 'Move down', function () { move(i, 1); }));
			tools.appendChild(iconBtn('⧉', 'Duplicate', function () { duplicate(i); }));
			tools.appendChild(iconBtn('✕', 'Delete', function () { removeSection(i); }, true));
			srow.appendChild(name); srow.appendChild(tools);
			list.appendChild(srow);
			if (openSec) {
				nodesForSection(i).forEach(function (n) {
					list.appendChild(nodeRow(i, n, 1));
					if (n.expandable && isExpanded(n.key)) {
						n.children.forEach(function (c) { list.appendChild(nodeRow(i, c, 2)); });
					}
				});
			}
		});
		p.appendChild(list);

		var addWrap = ce('div', 'aqb-addwrap');
		var sel = ce('select', 'aqb-addsel');
		sel.appendChild(new Option('+ Add section…', ''));
		Object.keys(CFG.labels || {}).sort(function (a, b) { return labelFor(a).localeCompare(labelFor(b)); })
			.forEach(function (type) { sel.appendChild(new Option(labelFor(type), type)); });
		sel.addEventListener('change', function () { if (sel.value) { addSection(sel.value); sel.value = ''; } });
		addWrap.appendChild(sel);
		p.appendChild(addWrap);
	}
```

- [ ] **Step 2: Add `moveItem` / `removeItem` (repeater item ops used by the tree)**

`renderRepeater` (lines 996+) already contains add/remove/move handlers for repeater rows; extract the array ops so the tree can call them. Add these functions next to `renderRepeater`:

```js
	function repArray(i, rep) {
		var s = state.sections[i];
		if (!Array.isArray(s[rep])) { s[rep] = []; }
		return s[rep];
	}
	function moveItem(i, rep, ri, dir) {
		var arr = repArray(i, rep), j = ri + dir;
		if (j < 0 || j >= arr.length) { return; }
		var t = arr[ri]; arr[ri] = arr[j]; arr[j] = t;
		setDirty(true); renderTree(); renderNodeInspector(); pushChange();
	}
	function removeItem(i, rep, ri) {
		var arr = repArray(i, rep);
		arr.splice(ri, 1);
		if (state.node && state.node.repeater === rep && state.node.rindex === ri) { state.node = { section: i }; }
		setDirty(true); renderTree(); renderNodeInspector(); pushChange();
	}
	function addItem(i, rep) {
		repArray(i, rep).push({});
		setDirty(true); renderTree(); renderNodeInspector(); pushChange();
	}
```

- [ ] **Step 3: Point every `renderStructure()` caller at `renderTree()`**

Run: `grep -n "renderStructure(" plugin/aq-core/admin/editor/builder.js`
For each hit (e.g. lines ~729, ~1029, ~1035, ~1040, and inside `move`/`duplicate`/`removeSection`/`addSection`), replace `renderStructure()` with `renderTree()`. Also replace any `renderInspector()` calls paired with them with `renderNodeInspector()` (Task 5 defines it).

Verify none remain: `grep -n "renderStructure\b" plugin/aq-core/admin/editor/builder.js` → expected: only the definition is gone (0 hits for calls).

- [ ] **Step 4: Syntax check**

Run: `node --check plugin/aq-core/admin/editor/builder.js`
Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
git add plugin/aq-core/admin/editor/builder.js
git commit -m "feat(builder): nested element tree in the structure panel"
```

---

## Task 5: Per-node inspector (builder.js `renderNodeInspector`)

**Files:**
- Modify: `plugin/aq-core/admin/editor/builder.js` — replace `renderInspector` (lines 772-808); update `boot`/`selectSection` callers.

- [ ] **Step 1: Replace `renderInspector` with `renderNodeInspector`**

Replace the whole `renderInspector` function (lines 772-808) with:

```js
	// Find the active node object (not just its path) for the current selection.
	function activeNode() {
		if (state.selected < 0 || !state.node) { return null; }
		var found = null, want = state.node;
		nodesForSection(state.selected).forEach(function (n) {
			if (!found && samePath(n.path, want)) { found = n; }
			(n.children || []).forEach(function (c) { if (!found && samePath(c.path, want)) { found = c; } });
		});
		return found;
	}

	function collapsibleGroup(title, fieldsArr, s, ctx) {
		var grp = ce('div', 'aqb-group is-open');
		var head = ce('button', 'aqb-grouph', title);
		head.addEventListener('click', function () { grp.classList.toggle('is-open'); });
		grp.appendChild(head);
		var bodyEl = ce('div', 'aqb-groupbody');
		fieldsArr.forEach(function (f) { bodyEl.appendChild(renderField(s, f, ctx)); });
		grp.appendChild(bodyEl);
		return grp;
	}

	function renderNodeInspector() {
		var p = els.inspector;
		p.innerHTML = '';
		if (state.selected < 0 || !state.sections[state.selected]) {
			p.appendChild(ce('div', 'aqb-empty', 'Click a section on the page to edit it.'));
			return;
		}
		var s = state.sections[state.selected];

		// aq_gallery stays on-canvas (unchanged behavior).
		if (inplaceOf(s.type) === 'gallery') { p.appendChild(ce('h3', 'aqb-h', labelFor(s.type))); renderGalleryInspector(p, s); return; }

		var node = activeNode();

		// Section root: summary only.
		if (!node) {
			p.appendChild(ce('h3', 'aqb-h', labelFor(s.type)));
			var count = nodesForSection(state.selected).length;
			p.appendChild(ce('p', 'aqb-muted', count + (count === 1 ? ' element' : ' elements') + ' — pick one from the tree on the left to edit it.'));
			return;
		}

		p.appendChild(ce('h3', 'aqb-h', node.label));

		if (node.kind === 'fixed') {
			p.appendChild(ce('p', 'aqb-muted', 'This element is built in code and maintained by your developer. It has no editable settings here.'));
			return;
		}
		if (node.kind === 'repeater') {
			p.appendChild(ce('p', 'aqb-muted', node.children.length + ' item(s). Expand this in the tree to edit each one.'));
			var add = ce('button', 'aqb-btn', '+ Add item');
			add.addEventListener('click', function () { addItem(state.selected, node.path.repeater); });
			p.appendChild(add);
			return;
		}
		if (node.kind === 'item') {
			collapsibleGroupInto(p, 'Content', node.fields, s, { repeater: node.path.repeater, rindex: node.path.rindex });
			return;
		}
		// field or group node
		var title = node.path.group === 'design' ? 'Design' : 'Content';
		collapsibleGroupInto(p, title, node.fields, s, null);
	}

	function collapsibleGroupInto(p, title, fieldsArr, s, ctx) {
		if (!fieldsArr.length) { p.appendChild(ce('p', 'aqb-muted', 'No editable settings.')); return; }
		p.appendChild(collapsibleGroup(title, fieldsArr, s, ctx));
	}
```

- [ ] **Step 2: Repoint callers of `renderInspector`/`selectSection`**

Run: `grep -n "renderInspector(\|selectSection(" plugin/aq-core/admin/editor/builder.js`
- Replace every `renderInspector()` call with `renderNodeInspector()`.
- In `boot` (line ~1538), after `state.sections = …`, set the initial node to the first section root: leave `state.node = null` and `state.selected = -1` as today (empty inspector until a click) — no change needed there beyond swapping `renderInspector()`→`renderNodeInspector()` and `renderStructure()`→`renderTree()`.
- `selectSection` (line 727) may remain for the canvas "select whole section" case, but change its body to call `selectNode(i, {})`:

```js
	function selectSection(i, tellCanvas) {
		selectNode(i, {});
		if (tellCanvas) { postCanvas({ type: 'highlight', index: i }); }
	}
```

- [ ] **Step 3: Syntax check**

Run: `node --check plugin/aq-core/admin/editor/builder.js`
Expected: exit 0.

- [ ] **Step 4: Re-run the module tests (guard against accidental shared edits)**

Run: `node --test plugin/aq-core/admin/editor/tree-model.test.js`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add plugin/aq-core/admin/editor/builder.js
git commit -m "feat(builder): per-node inspector (content/design groups, fixed & repeater nodes)"
```

---

## Task 6: Tree + inspector styles (builder.css)

**Files:**
- Modify: `plugin/aq-core/admin/editor/builder.css` (append)

- [ ] **Step 1: Append the styles**

Append to `plugin/aq-core/admin/editor/builder.css`:

```css
/* ---- element tree ---- */
.aqb-node { display:flex; align-items:center; gap:6px; padding:5px 8px; cursor:default; border-radius:6px; }
.aqb-node:hover { background:rgba(0,0,0,.05); }
.aqb-node.is-active { background:rgba(20,120,80,.14); }
.aqb-node.is-fixed { opacity:.72; }
.aqb-nodename { flex:1; text-align:left; background:none; border:0; font:inherit; cursor:pointer; color:inherit; padding:0; }
.aqb-nodeico { width:15px; height:15px; flex:0 0 auto; opacity:.7; }
.aqb-tw { width:16px; height:16px; line-height:16px; text-align:center; background:none; border:0; cursor:pointer; color:inherit; transition:transform .12s; }
.aqb-tw.is-open { transform:rotate(90deg); }
.aqb-tw--leaf { visibility:hidden; }
.aqb-secrow .aqb-tw { opacity:.8; }
/* ---- collapsible inspector groups ---- */
.aqb-group .aqb-grouph { display:block; width:100%; text-align:left; background:none; border:0; font-weight:600; padding:8px 0; cursor:pointer; color:inherit; }
.aqb-group .aqb-grouph::before { content:'▾ '; }
.aqb-group:not(.is-open) .aqb-grouph::before { content:'▸ '; }
.aqb-group:not(.is-open) .aqb-groupbody { display:none; }
```

- [ ] **Step 2: Commit**

```bash
git add plugin/aq-core/admin/editor/builder.css
git commit -m "style(builder): element-tree rows, node icons, collapsible inspector groups"
```

---

## Task 7: Pilot — Estimate curation hint (PJP theme)

**Files:**
- Modify: `C:\Users\justi\Apps\Work\Websites\PJ Pappas\theme\pjp\functions.php`

Context: `pjp_estimate` has no editor schema today (its inspector is `inferFields`-driven). This registers labeled controls **and** the `elements` hint, so its tree matches the agreed sketch. Fields mirror the data keys read in `theme/pjp/aq-sections/pjp-estimate.php` (eyebrow, heading, body, checklist[text], form_title, form_sub, services[label], sidebar_help_*, sidebar_policies_*/policies[html], sidebar_towns_*/towns[name], bg, variant).

- [ ] **Step 1: Register the schema + hint**

Add to `theme/pjp/functions.php` (anywhere after the theme's existing `add_filter` block; do NOT edit aq-core):

```php
/**
 * Editor field schema + element-tree grouping for the bespoke pjp_estimate
 * section, so the visual builder shows a curated nested tree (see aq-core
 * tree-model.js / AQ_Editor::field_schema 'elements' hint). Per-site only.
 */
add_filter('aq_editor_field_schema', function (array $schema): array {
	$schema['pjp_estimate'] = [
		'fields' => [
			['name' => 'eyebrow',    'label' => 'Eyebrow',       'type' => 'text'],
			['name' => 'heading',    'label' => 'Heading',       'type' => 'text'],
			['name' => 'body',       'label' => 'Body text',     'type' => 'textarea'],
			['name' => 'call_html',  'label' => 'Call line (HTML)', 'type' => 'text'],
			['name' => 'checklist',  'label' => 'Checklist',     'type' => 'repeater',
				'subfields' => [['name' => 'text', 'label' => 'Item', 'type' => 'text']]],
			['name' => 'form_title', 'label' => 'Form heading',  'type' => 'text'],
			['name' => 'form_sub',   'label' => 'Form subtitle', 'type' => 'text'],
			['name' => 'services',   'label' => 'Service options', 'type' => 'repeater',
				'subfields' => [['name' => 'label', 'label' => 'Service', 'type' => 'text']]],
			['name' => 'sidebar_help_heading', 'label' => 'Help card: heading', 'type' => 'text'],
			['name' => 'sidebar_help_text',    'label' => 'Help card: text',    'type' => 'textarea'],
			['name' => 'sidebar_help_phone',   'label' => 'Help card: phone',   'type' => 'text'],
			['name' => 'sidebar_help_biz',     'label' => 'Help card: business','type' => 'text'],
			['name' => 'sidebar_help_addr',    'label' => 'Help card: address', 'type' => 'text'],
			['name' => 'sidebar_towns_heading','label' => 'Towns: heading',     'type' => 'text'],
			['name' => 'sidebar_towns_sub',    'label' => 'Towns: subtext',     'type' => 'text'],
			['name' => 'sidebar_towns',        'label' => 'Towns',              'type' => 'repeater',
				'subfields' => [['name' => 'name', 'label' => 'Town', 'type' => 'text']]],
			['name' => 'submit_label', 'label' => 'Submit button label', 'type' => 'text'],
			['name' => 'fineprint',    'label' => 'Fine print',   'type' => 'text'],
			['name' => 'success_msg',  'label' => 'Success message', 'type' => 'text'],
			['name' => 'bg',      'label' => 'Background', 'type' => 'select', 'group' => 'design',
				'options' => ['' => 'Default (red)', 'light' => 'Light']],
			['name' => 'variant', 'label' => 'Variant', 'type' => 'select', 'group' => 'design',
				'options' => ['form' => 'CTA + form', 'band' => 'CTA band (no form)']],
		],
		'elements' => [
			['label' => 'Intro',            'icon' => 'text',  'fields' => ['eyebrow', 'heading', 'body', 'call_html']],
			['label' => 'Checklist',        'icon' => 'check', 'repeater' => 'checklist'],
			['label' => 'Form heading',     'icon' => 'text',  'fields' => ['form_title', 'form_sub']],
			['label' => 'Service options',  'icon' => 'check', 'repeater' => 'services'],
			['label' => 'Sidebar: Help card','icon' => 'card', 'fields' => ['sidebar_help_*']],
			['label' => 'Sidebar: Service towns', 'icon' => 'card', 'fields' => ['sidebar_towns_heading', 'sidebar_towns_sub']],
			['label' => 'Service towns list', 'icon' => 'list', 'repeater' => 'sidebar_towns'],
			['label' => 'Submit & messages', 'icon' => 'form', 'fields' => ['submit_label', 'fineprint', 'success_msg']],
			['label' => 'Estimate form',    'icon' => 'form',  'fixed' => true],
			['label' => 'Design',           'icon' => 'gear',  'group' => 'design'],
		],
	];
	return $schema;
}, 20);
```

- [ ] **Step 2: Lint**

Run: `php -l "C:\Users\justi\Apps\Work\Websites\PJ Pappas\theme\pjp\functions.php"`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit (PJP repo)**

```bash
cd "C:/Users/justi/Apps/Work/Websites/PJ Pappas"
git add theme/pjp/functions.php 2>/dev/null || true   # PJP theme is deployed, not necessarily git-tracked; commit if it is
```
If the PJP project is not a git repo, skip the commit; the file is deployed in Task 8.

---

## Task 8: Deploy to PJP staging/prod and QC in real Chrome

**Files:** none (deploy + verify)

Deploy the six changed files to a PJP environment (staging preferred; prod per memory `pjp-production-access`, surgical single-file copy, back up first): `tree-model.js`, `builder.js`, `builder.css`, `includes/class-editor.php`, and the theme `functions.php`. Flush cache (`wp cache flush`).

- [ ] **Step 1: Deploy + lint on server**

For each PHP file, run `php -l <path>` on the server; expected `No syntax errors detected`. Back up each target (`cp <f> <f>.bak-elementtree-<ts>`) before overwriting.

- [ ] **Step 2: Open the builder in real Chrome**

Use claude-in-chrome (the in-app browser pane cannot composite the builder — see memory `inapp-browser-pane-no-composite`). Navigate to `https://pjpappas.com/wp-admin/admin.php?page=aq-pages&page_id=28`.

- [ ] **Step 3: Work the QC checklist (all must pass)**

1. Left panel shows **Structure**; the Estimate section expands to the curated nodes: Intro, Checklist, Form heading, Service options, Sidebar: Help card, Sidebar: Service towns, Service towns list, Submit & messages, Estimate form (dimmed/fixed), Design.
2. Clicking each node shows only that element's controls on the right (Intro shows 4 fields; a Service option item shows its Service text; Estimate form shows the "built in code" note; Design shows Background + Variant).
3. Edit a field (e.g. Heading) → the canvas updates; **Save**, reload → the change persisted.
4. Click an element on the canvas → its tree node selects and its settings open.
5. Every other section on the page shows a working **auto** tree (spot-check the hero and service-cards sections).
6. Repeater item add / remove / reorder work from the tree (Service options).
7. Section add / duplicate / delete / reorder still work.
8. Undo / redo across a node edit works.
9. A save that would drop all content is still refused (0.3.63 guard) — unchanged.

- [ ] **Step 4: If all pass, commit a QC note**

```bash
cd "C:/Users/justi/Apps/Work/AutoForge WP"
git commit --allow-empty -m "test(builder): element tree verified in Chrome on PJP page 28"
```

---

## Task 9: Version bump + build (release gated on user go)

**Files:**
- Modify: `plugin/aq-core/aq-core.php` (header + `AQ_CORE_VERSION`), `package.json`

- [ ] **Step 1: Bump to 0.3.64**

In `plugin/aq-core/aq-core.php` change `Version: 0.3.63` → `Version: 0.3.64` and `define('AQ_CORE_VERSION', '0.3.63')` → `'0.3.64'`. In `package.json` change `"version": "0.3.63"` → `"0.3.64"`.

- [ ] **Step 2: Build the zips**

Run: `node migration/build-release.mjs`
Expected: `✓ Built dist/aq-core-0.3.64.zip` and `✓ Built dist/aqm-base-1.0.3.zip`.

- [ ] **Step 3: Verify the module shipped in the zip**

Run: `unzip -l dist/aq-core-0.3.64.zip | grep tree-model.js`
Expected: `aq-core/admin/editor/tree-model.js` listed.

- [ ] **Step 4: Commit**

```bash
git add plugin/aq-core/aq-core.php package.json
git commit -m "chore(release): aq-core 0.3.64 — nested builder element tree"
```

- [ ] **Step 5: STOP — do not cut the GitHub release or roll the fleet without explicit user approval.**

Releasing auto-updates all fleet sites. Present the built zip + a summary and wait for a go, then follow the same release path as 0.3.63 (`gh release create v0.3.64 …`).

---

## Self-Review

- **Spec coverage:** node model (Task 1) ✓; left tree (Task 4) ✓; per-node right panel with Content/Design collapsible groups (Task 5/6) ✓; selection + canvas two-way binding (Task 3) ✓; auto derivation + curation hint incl. prefix match & auto-append (Task 1) ✓; Estimate pilot (Task 7) ✓; no data-model change / save untouched (Tasks reference only render + schema) ✓; Non-goals (no Advanced/CSS/tag; form is a fixed node) honored (Task 7 hint + Task 5 fixed branch) ✓; QC in real Chrome (Task 8) ✓; rollout 0.3.64 gated (Task 9) ✓.
- **Type consistency:** node shape (`kind/key/label/icon/path/expandable/fields/children`) is identical across Tasks 1, 3, 4, 5. `selectNode(i, path)`, `samePath`, `nodesForSection`, `activeNode`, `moveItem/removeItem/addItem` names are used consistently. Canvas highlight message keeps the existing `{index, field, repeater, rindex}` shape.
- **Placeholders:** none — every code step is complete; browser-QC steps in Task 8 are explicit and unavoidably manual (builder DOM has no headless harness).
- **Open risk:** exact line numbers (e.g. 1435, 772-808) are from the `feat/builder-element-tree` checkout of `v0.3.63`; if they drift, locate by function name (`grep -n "function renderInspector"`).
```
