# AutoForge Builder — Nested Element Tree (design)

- **Date:** 2026-09-03
- **Status:** Approved design → ready for implementation plan
- **Target release:** aq-core 0.3.64
- **Pilot page:** https://pjpappas.com/wp-admin/admin.php?page=aq-pages&page_id=28 (Contact → Estimate section)

## Summary

Upgrade the AutoForge visual builder from a **flat section list + full-field inspector** to a **nested element tree**: the left panel shows each section with its editable elements nested underneath, and clicking any node shows **only that element's controls** in the right panel. This is a builder-UX change only — it reads the section field data that already exists and writes it back unchanged. No content data model changes, no changes to rendering, save/import, or SEO.

Inspiration: Breakdance's layers panel + per-element control tabs. We copy the *navigation* model (tree on the left, contextual settings on the right) but deliberately keep the *controls* curated and safe — no raw CSS/tag/attribute freedom (see Non-goals).

## Background — how the builder works today

The builder is a vanilla-JS app in `plugin/aq-core/admin/editor/` (`builder.js`, `canvas.js`, `history.js`) with a PHP field schema from `AQ_Editor::field_schema()` (engine curated) unioned with the `aq_editor_field_schema` filter (per-theme).

- **Left pane (`els.structure`, `renderStructure()`):** a flat list of sections. Each row = a name button (`selectSection(i)`) + tools (move up/down, duplicate, delete), plus an "Add section" picker.
- **Right pane (`els.inspector`, `renderInspector()`):** for the one selected section, renders every field from `schemaFor(type)` via `renderField()` / `renderRepeater()`, split into a Content list and a "Design" group (`f.group === 'design'`). Sections with no schema fall back to `inferFields()` (fields auto-detected from the data).
- **Selection model:** a single integer `state.selected` = the section index.
- **Canvas two-way binding (already exists):** clicking an element on the canvas posts `{ type:'select', index, field, repeater, rindex }`; `focusField()` scrolls/flashes the matching inspector field. Focusing an inspector field posts `{ type:'highlight', index, field, repeater, rindex }` back to the canvas.

**The limitation:** the client sees *every* field of a section in one long inspector and cannot navigate the section's structure. There is no per-element view. (Form inputs are a separate matter — they are built in code and edited by AQ, out of scope here.)

## Scope

**In scope**
- Left panel becomes a nested tree: section → its editable elements (fields; repeaters expand to their items; a Design node; a fixed node for code-built pieces like the form).
- Right panel shows only the selected node's controls, grouped Content / Design as labeled, collapsible sections.
- Selection model extends from "section index" to "node = section index + path".
- Tree derives **automatically** from the existing field schema for every section type; a section may **optionally** supply a curation hint to group/label/icon its nodes.
- Pilot the curation hint on the PJP `pjp_estimate` section so its tree matches the agreed sketch.
- Preserve every existing behavior: canvas ↔ panel highlighting, add/duplicate/delete/reorder, repeater item add/remove/reorder, undo/redo, live preview, save, and the `aq_gallery` in-place editor.

**Out of scope / Non-goals**
- **No content data-model change.** The saved section JSON shape is identical; `apply_sections`/`update_sections` are untouched. This keeps the 0.3.63 save-wipe hardening intact.
- **No form-field editing.** Forms and their fields are built in code by AQ; the form appears as a single fixed leaf node.
- **No Breakdance-style "Advanced" tab.** No raw CSS classes, custom HTML tags, arbitrary attributes, or free element nesting. That per-element freedom is precisely what lets users break a page and go off-brand — the guardrail AutoForge exists to keep. Intentional difference, not an oversight.
- **No free drag-anywhere nesting.** Nodes reflect the section's existing structure; you cannot move a heading into a repeater, etc.

## Design

### 1. Node model

A **node** identifies one editable element within a section. Node kinds and their right-panel behavior:

| Kind | Tree appearance | Right panel shows |
|---|---|---|
| `section` (root) | Section label, expandable, section tools | Short summary only (type + element count). All section styling lives under the Design node, so settings have exactly one home. |
| `field` | Leaf, field label + icon | That one field's control |
| `repeater` (group) | Expandable, label + item count, "add item" | Guidance + add/reorder for the group |
| `item` (repeater row) | Leaf under its repeater, item label | That item's sub-controls |
| `group` (e.g. Design) | Expandable or leaf | The grouped fields (e.g. background, spacing, variant) |
| `fixed` (e.g. form) | Leaf, distinct icon | Read-only note: "Built in code — edited by your developer" |

**Nesting rule (mirrors Breakdance's `nestingRule()`):** `section` and `repeater` and `group` are expandable; `field`, `item`, and `fixed` are `final` (leaves). Derived, not authored, unless a curation hint overrides.

**Node path** (extends the existing highlight message shape so canvas binding is reused verbatim):
```
{ section: <int>,                       // section index
  field?:  <string>,                    // top-level field OR subfield name
  repeater?: <string>, rindex?: <int>,  // repeater group / item
  group?: <string> }                    // e.g. 'design'
```
`state.selected` (int) is kept for back-compat where a whole section is meant; a new `state.node` holds the active node path. `state.selected` derives from `state.node.section`.

### 2. Left panel — the tree (`renderTree()`, replaces `renderStructure()`)

- Each section renders as a parent row: disclosure triangle + label + existing section tools (↑ ↓ ⧉ ✕) + Add-section picker at the bottom (unchanged behavior).
- Expanding a section renders its element nodes (from §5). Repeaters render as sub-parents whose children are their items; item rows carry the repeater's existing add/remove/reorder tools (reuse `renderRepeater`'s handlers, relocated).
- The selected section auto-expands. Expand/collapse state is per-session in `state.tree.expanded` (keyed by section index / node path); not persisted server-side.
- Clicking any node → `selectNode(path)`: sets `state.node`, re-renders the tree (active highlight) and the inspector, and posts `{ type:'highlight', …path }` to the canvas.
- Each node shows an **icon** (from the curation hint, else a kind-default) and a **friendly label** (hint label, else humanized field name; repeater items labeled by a title-ish subfield when available, else "Item N").

### 3. Right panel — per-node inspector (`renderNodeInspector()`, replaces `renderInspector()`)

Renders controls for `state.node` only:
- `field` → `renderField(section, f)` (single control).
- `item` → the item's subfields via `renderField(section, subf, ctx)` with the existing repeater `ctx` (`{repeater, rindex}`).
- `repeater` group → an "add item / reorder" summary (items are edited via their child nodes).
- `group` (Design) → the grouped fields.
- `section` root → a short summary only (styling lives under the Design node).
- `fixed` (form) → informational note, no controls.

Controls are wrapped in **labeled, collapsible groups** ("Content", "Design"). This reuses `renderField`/`renderRepeater` unchanged; only the *selection of which fields to render* and the *grouping chrome* are new. Canvas `focusin` → `highlight` binding is preserved on each rendered field.

### 4. Selection & canvas two-way binding

- Canvas → panel: the existing `select` message (`{index, field, repeater, rindex}`) is mapped to a node path and `selectNode()` is called, so clicking an element on the page selects its tree node and opens its settings.
- Panel → canvas: `selectNode()` and field `focusin` post `highlight` with the node path (same shape as today). No canvas.js protocol change required.
- Optional polish (nice-to-have, can be a follow-up): a small label chip on the highlighted canvas element (Breakdance's element badge). Not required for v1.

### 5. Auto-derivation + optional curation hint

**Auto (default, every section):** build the node list from `schemaFor(type)` (or `inferFields` fallback):
- top-level content fields (schema order) → `field` nodes;
- repeaters → `repeater` group nodes with `item` children;
- fields with `group === 'design'` → collected under one `group:'design'` node;
- unknown/code-built markers → `fixed` nodes (see hint).

**Curation hint (optional, per section type):** a section's schema entry may carry an `elements` array that curates the tree — order, labels, icons, grouping several fields under one named node, and marking `fixed` nodes. Shape:
```php
'elements' => [
  ['label' => 'Intro',   'icon' => 'text',   'fields' => ['eyebrow','heading','body']],
  ['label' => 'Checklist','icon' => 'list',  'repeater' => 'checklist'],
  ['label' => 'Form heading', 'icon' => 'text', 'fields' => ['form_title','form_sub']],
  ['label' => 'Service options', 'icon' => 'check', 'repeater' => 'services'],
  ['label' => 'Sidebar: Help card', 'icon' => 'card', 'fields' => ['sidebar_help_*']],
  ['label' => 'Estimate form', 'icon' => 'form', 'fixed' => true],
  ['label' => 'Design', 'icon' => 'gear', 'group' => 'design'],
],
```
A `fields` entry matches by exact name, or by prefix when it ends in `*` (e.g. `sidebar_help_*` groups all `sidebar_help_…` fields under one node). `builder.js` reads `CFG.schema[type].elements` when present, else auto. The hint is passed through by `AQ_Editor` alongside `fields` (no new endpoint). When a hint omits a field that exists in the data, that field still appears (auto-appended) so a stale hint can never *hide* editable content.

### 6. Pilot — `pjp_estimate` curation hint (PJP theme)

`pjp_estimate` currently has **no** editor field schema (its inspector is `inferFields`-driven). The pilot registers an `aq_editor_field_schema` entry for `pjp_estimate` in the PJP theme (`theme/pjp/functions.php`) that provides friendly field controls **and** the `elements` hint above, so its tree reads like the agreed sketch: Intro, Checklist, Form heading, Service options, Sidebar (Help / Policies / Towns), the fixed Estimate form node, and Design. This is per-site work; the engine change is client-agnostic.

## Files touched

**Engine (`plugin/aq-core`)**
- `admin/editor/builder.js` — new tree model + `renderTree()`, `renderNodeInspector()`, `selectNode()`, node-path selection; reuse `renderField`/`renderRepeater`.
- `admin/editor/builder.css` — tree rows, disclosure, indentation, active state, collapsible right-panel groups, node icons.
- `includes/class-editor.php` — pass an optional `elements` schema key through to `CFG.schema` (small, additive). No change to `field_schema()` semantics.

**PJP theme (pilot only)**
- `theme/pjp/functions.php` — `aq_editor_field_schema` entry for `pjp_estimate` (fields + `elements` hint).

**Untouched:** `class-content-sync.php` (data/save), rendering templates, SEO, `canvas.js` protocol.

## Persistence & safety

No change to how sections are stored or saved. The tree reads `state.sections` (already loaded by `read_sections`) and writes through the same `renderField` bindings and `/editor/save` path. The 0.3.63 save-wipe guards remain in force and untouched.

## Preserved behaviors (regression checklist)

Section add / duplicate / delete / reorder; repeater item add / remove / reorder; undo / redo (history snapshots include `state.node`); live canvas preview; save + reload; `aq_gallery` in-place editor (its section is one `fixed`/in-place node — gallery editing stays on the canvas); schemaless sections still editable via the auto (inferred) tree.

## Testing & QC

The builder is browser JS; the in-app browser pane cannot composite it, so QC runs in **real Chrome** (claude-in-chrome) on page 28. Checklist:
1. Estimate section expands to its curated nodes; labels/icons correct.
2. Clicking each node shows only that element's controls; edits reflect on the canvas and persist after save + reload.
3. Canvas click selects the matching tree node and opens its settings.
4. Every other section type shows a working **auto** tree (spot-check hero, service cards, faq, gallery).
5. Add / duplicate / delete / reorder sections; add / remove / reorder repeater items.
6. Undo / redo across node edits.
7. A save that would drop content is still refused (0.3.63 guard) — unchanged.

## Rollout

Ship in aq-core **0.3.64**. Every fleet site gets the auto tree immediately; only PJP gets the curated Estimate hint at first. Other sections/sites can add hints incrementally. Release via `node migration/build-release.mjs` + GitHub release (same path as 0.3.63).

## Risks & mitigations

- **Right-panel churn feels different to users.** Mitigate: section root and each node always render *something*; auto-append any hint-omitted field so nothing disappears.
- **Curation hint drift** (fields renamed in code): stale hints never hide data (auto-append rule); missing hint → auto tree.
- **Two-way binding regressions.** Mitigate: reuse the existing message shape verbatim; the node path is a superset of today's `{index, field, repeater, rindex}`.
- **Undo/redo losing node focus.** Mitigate: include `state.node` in history snapshots (already snapshots `selected`).

## Open questions

None blocking. Optional follow-ups (not in v1): on-canvas element label chip; drag-to-reorder inside the tree; collapsible-group memory across sessions.
