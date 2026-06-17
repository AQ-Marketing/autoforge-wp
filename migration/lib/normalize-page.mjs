/**
 * Canonicalizing normalizer for page JSON — the round-trip safety net.
 *
 * The problem (see project memory "serialize_page not round-trip stable"):
 * ACF returns flexible-content sub-fields in *registration* order and emits
 * EVERY declared sub-field even when empty. So a naive WordPress->JSON export
 * reorders keys and floods each section with empty fields, which would rewrite
 * (corrupt) the hand-curated content/pages/*.json on every reconcile.
 *
 * normalizePage() maps ANY page-shaped object to one canonical form:
 *   - top-level keys ordered per the spec
 *   - seo keys ordered; empty seo keys dropped
 *   - each section emitted as { type, v, ...fields-in-registration-order }
 *   - repeater rows ordered by their sub-field registration order
 *   - empty values (null, '', false, 0, [], {}) dropped everywhere
 *   - the section's real `v` preserved (falls back to the type's schema version)
 *
 * Applied to BOTH sides of the round-trip, normalize(export) === normalize(file),
 * so reconcile is stable. This JS implementation mirrors the PHP
 * AQ_Content_Sync::normalize_page() exactly; both read the same field-order.json.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SPEC_PATH = resolve(__dirname, '../../plugin/aq-core/config/field-order.json');

export const SPEC = JSON.parse(readFileSync(SPEC_PATH, 'utf8'));

/** A value carries no information and should be dropped from canonical output. */
export function isEmpty(v) {
  if (v === null || v === undefined) return true;
  if (v === '' || v === false || v === 0) return true;
  if (Array.isArray(v)) return v.length === 0;
  if (typeof v === 'object') return Object.keys(v).length === 0;
  return false;
}

/** Cast an ACF-ish truthy/falsy (true/1/"1") to a real boolean. */
function castBool(v) {
  return v === true || v === 1 || v === '1';
}

/** Cast a numeric string ("930") to a number; leave non-numerics untouched. */
function castInt(v) {
  if (typeof v === 'number') return v;
  if (typeof v === 'string' && v.trim() !== '' && !Number.isNaN(Number(v))) return Number(v);
  return v;
}

/** Recursively clean an un-spec'd value: drop empties, keep insertion order. */
function clean(v) {
  if (Array.isArray(v)) return v.map(clean).filter((x) => !isEmpty(x));
  if (v && typeof v === 'object') {
    const out = {};
    for (const k of Object.keys(v)) {
      const cv = clean(v[k]);
      if (!isEmpty(cv)) out[k] = cv;
    }
    return out;
  }
  return v;
}

/** Order an object's keys by `order` first, then any extras alphabetically;
 *  drop empty values. Used for repeater rows and seo.services entries. */
function orderObject(obj, order, extras) {
  const out = {};
  if (!obj || typeof obj !== 'object') return out;
  for (const k of order) {
    if (k in obj) {
      const cv = clean(obj[k]);
      if (!isEmpty(cv)) out[k] = cv;
    }
  }
  for (const k of Object.keys(obj).sort()) {
    if (order.includes(k) || k in out) continue;
    extras?.push(k);
    const cv = clean(obj[k]);
    if (!isEmpty(cv)) out[k] = cv;
  }
  return out;
}

function normalizeSeo(seo, warnings) {
  if (!seo || typeof seo !== 'object') return {};
  const out = {};
  for (const k of SPEC.seo) {
    if (!(k in seo)) continue;
    if (k === 'services') {
      const arr = (Array.isArray(seo.services) ? seo.services : [])
        .map((svc) => orderObject(svc, SPEC.seoService, warnings.seoServiceExtras))
        .filter((s) => !isEmpty(s));
      if (arr.length) out.services = arr;
    } else if (SPEC.seoBool?.includes(k)) {
      if (castBool(seo[k])) out[k] = true; // drop when false
    } else {
      const cv = clean(seo[k]);
      if (!isEmpty(cv)) out[k] = cv;
    }
  }
  for (const k of Object.keys(seo).sort()) {
    if (SPEC.seo.includes(k) || k in out) continue;
    warnings.unknownSeoKeys.add(k);
    const cv = clean(seo[k]);
    if (!isEmpty(cv)) out[k] = cv;
  }
  return out;
}

function normalizeSection(section, warnings) {
  if (!section || typeof section !== 'object' || isEmpty(section.type)) {
    return null;
  }
  const type = section.type;
  const spec = SPEC.sections[type];
  const out = { type };
  out.v = section.v != null && section.v !== '' ? section.v : spec ? spec.v : 1;

  if (!spec) {
    warnings.unknownTypes.add(type);
    for (const k of Object.keys(section).sort()) {
      if (k === 'type' || k === 'v') continue;
      const cv = clean(section[k]);
      if (!isEmpty(cv)) out[k] = cv;
    }
    return out;
  }

  const repeaters = spec.repeaters || {};
  for (const f of spec.fields) {
    if (!(f in section)) continue;
    let val;
    if (repeaters[f]) {
      val = (Array.isArray(section[f]) ? section[f] : [])
        .map((row) => orderObject(row, repeaters[f], warnings.repeaterExtras))
        .filter((r) => !isEmpty(r));
    } else if (spec.bool?.includes(f)) {
      val = castBool(section[f]) ? true : false;
    } else if (spec.int?.includes(f)) {
      val = castInt(clean(section[f]));
    } else {
      val = clean(section[f]);
    }
    if (!isEmpty(val)) out[f] = val;
  }

  // Any field present on the section but absent from the registration order =
  // a gap in field-order.json. Keep it (never lose data) and flag it.
  for (const k of Object.keys(section).sort()) {
    if (k === 'type' || k === 'v' || spec.fields.includes(k) || k in out) continue;
    warnings.unknownFields.add(`${type}.${k}`);
    const cv = clean(section[k]);
    if (!isEmpty(cv)) out[k] = cv;
  }

  return out;
}

/**
 * Normalize a whole page object. Returns { page, warnings }.
 * `page` is the canonical form; `warnings` reports any spec gaps (unknown
 * section types / fields) that should be fixed in field-order.json.
 */
export function normalizePage(data) {
  const warnings = {
    unknownTypes: new Set(),
    unknownFields: new Set(),
    unknownSeoKeys: new Set(),
    unknownTopKeys: new Set(),
    repeaterExtras: [],
    seoServiceExtras: [],
  };
  const out = {};
  if (!data || typeof data !== 'object') return { page: out, warnings };

  for (const k of SPEC.topLevel) {
    if (!(k in data)) continue;
    if (k === 'seo') {
      out.seo = normalizeSeo(data.seo, warnings);
    } else if (k === 'sections') {
      out.sections = (Array.isArray(data.sections) ? data.sections : [])
        .map((s) => normalizeSection(s, warnings))
        .filter((s) => s !== null);
    } else {
      // scalar / structural top-level keys (path, title, status, post fields…)
      out[k] = clean(data[k]);
    }
  }
  for (const k of Object.keys(data).sort()) {
    if (SPEC.topLevel.includes(k) || k in out) continue;
    warnings.unknownTopKeys.add(k);
    out[k] = clean(data[k]);
  }

  return { page: out, warnings };
}

/** Stable pretty JSON identical to how the repo stores page files. */
export function toJson(page) {
  return JSON.stringify(page, null, 2) + '\n';
}
