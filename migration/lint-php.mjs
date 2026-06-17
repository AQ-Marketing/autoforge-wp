/**
 * lint-php.mjs — syntax-check every .php file under plugin/aq-core (incl. thrust/)
 * using the glayzzle php-parser (no local PHP binary exists on this machine).
 *
 * The parser is not a perfect PHP 8 implementation, so we keep a BASELINE of
 * files it already failed to parse BEFORE any of our edits. Only NEW failures
 * (regressions we introduced) fail the run.
 *
 * Usage:
 *   node migration/lint-php.mjs --baseline   # record current failures as baseline
 *   node migration/lint-php.mjs              # fail only on non-baseline errors
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import PhpParser from 'php-parser';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../plugin/aq-core');
const BASELINE_FILE = path.join(__dirname, 'lint-php.baseline.json');

const parser = new PhpParser.Engine({
  parser: { php8: true, suppressErrors: false },
  ast: { withPositions: false },
});

const files = [];
(function walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full);
    else if (e.name.endsWith('.php')) files.push(full);
  }
})(ROOT);

const failures = {};
for (const f of files) {
  const rel = path.relative(ROOT, f).replace(/\\/g, '/');
  try {
    parser.parseCode(fs.readFileSync(f, 'utf8'), rel);
  } catch (err) {
    failures[rel] = String(err.message || err).split('\n')[0];
  }
}

if (process.argv.includes('--baseline')) {
  fs.writeFileSync(BASELINE_FILE, JSON.stringify(failures, null, 2) + '\n');
  console.log(`Parsed ${files.length} files. Baseline saved: ${Object.keys(failures).length} pre-existing parser complaints.`);
  process.exit(0);
}

const baseline = fs.existsSync(BASELINE_FILE) ? JSON.parse(fs.readFileSync(BASELINE_FILE, 'utf8')) : {};
const fresh = Object.entries(failures).filter(([rel]) => !(rel in baseline));
const fixed = Object.keys(baseline).filter(rel => !(rel in failures));

console.log(`Parsed ${files.length} files. ${Object.keys(failures).length} parser complaints (${Object.keys(baseline).length} baselined).`);
if (fixed.length) console.log(`Baselined files now parsing clean (deleted or fixed): ${fixed.length}`);
if (fresh.length) {
  console.log('\nNEW failures (introduced by our edits):');
  fresh.forEach(([rel, msg]) => console.log(`  ${rel}: ${msg}`));
  process.exit(1);
}
console.log('No new syntax errors.');
