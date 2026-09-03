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
    // Sanitize: drop null/undefined entries and any field without a usable name,
    // so nothing downstream can throw. `fields` may come from inferFields() or
    // arbitrary theme schema, and this ships to every AutoForge site.
    fields = (Array.isArray(fields) ? fields : []).filter(function (f) { return f && f.name; });
    var keyBase = 'sec';
    var content = fields.filter(function (f) { return f.group !== 'design'; });
    var design = fields.filter(function (f) { return f.group === 'design'; });
    // byName maps CONTENT fields only. Design fields are surfaced solely through
    // the Design group node, so a hint's fields/prefix can never pull a design
    // field into a second node (which would make it editable from two places).
    var byName = {};
    content.forEach(function (f) { byName[f.name] = f; });

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
          var matched = resolveFields(h.fields, byName, content, consumed);
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
  // Browser reads window.AQTree; Node tests import for the globalThis side effect
  // (mirrors history.js → globalThis.AQHistory). window === globalThis in browsers.
  if (typeof module !== 'undefined' && module.exports) { module.exports = AQTree; }
  if (typeof globalThis !== 'undefined') { globalThis.AQTree = AQTree; }
  if (typeof window !== 'undefined') { window.AQTree = AQTree; }
})();
