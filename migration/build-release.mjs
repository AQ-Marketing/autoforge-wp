/**
 * build-release.mjs — package the AutoForge WP plugin (aq-core) AND the
 * companion stub theme (aqm-base) into distributable zips for WordPress.
 *
 * The AQ_Updater (plugin/aq-core/includes/class-updater.php) downloads the
 * PLUGIN asset on one-click update; attach dist/autoforge-wp-<version>.zip to
 * the GitHub release. The theme zip (dist/aqm-base-<version>.zip) is installed
 * once via Appearance → Themes → Add New → Upload.
 *
 * Each archive's top-level folder matches the installed slug (aq-core/,
 * aqm-base/) so WordPress installs/updates in place. Boost (thrust/) ships
 * inside the plugin. Dev-only files are excluded.
 *
 * Usage: node migration/build-release.mjs   (or: npm run build:release)
 * Output: dist/autoforge-wp-<version>.zip, dist/aqm-base-<version>.zip
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import archiver from 'archiver';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const PLUGIN_DIR = path.join(ROOT, 'plugin', 'aq-core');
const THEME_DIR = path.join(ROOT, 'theme', 'aqm-base');
const DIST_DIR = path.join(ROOT, 'dist');

// Read a "Version:" header from a plugin main file or a theme style.css.
// Plugin headers prefix lines with ` * `; theme style.css does not — the
// optional `\*?` handles both.
function readVersion(file, label) {
  const txt = fs.readFileSync(file, 'utf8');
  const m = txt.match(/^\s*\*?\s*Version:\s*([0-9][^\s]*)/m);
  if (!m) throw new Error(`Could not find "Version:" in ${label} (${file}).`);
  return m[1];
}

// Dev-only paths that must NOT ship in a release.
const IGNORE = [
  '**/.git/**', '**/.github/**', '**/node_modules/**',
  '**/tests/**', '**/test/**', '**/*.dist', '**/.DS_Store',
  '**/*.map', '**/.idea/**', '**/.vscode/**',
];

// Zip srcDir into outFile under a single top-level folder = topFolder.
function zipDir(srcDir, topFolder, outFile) {
  return new Promise((resolve, reject) => {
    const output = fs.createWriteStream(outFile);
    const archive = archiver('zip', { zlib: { level: 9 } });
    output.on('close', resolve);
    archive.on('warning', (err) => { if (err.code !== 'ENOENT') reject(err); });
    archive.on('error', reject);
    archive.pipe(output);
    archive.directory(srcDir, topFolder, (entry) => {
      const rel = entry.name.replace(/\\/g, '/');
      if (IGNORE.some((g) => globMatch(g, rel))) return false;
      return entry;
    });
    archive.finalize();
  });
}

function report(file, version) {
  const kb = Math.round(fs.statSync(file).size / 1024);
  console.log(`✓ Built ${path.relative(ROOT, file)} (${kb} KB) — v${version}`);
}

async function main() {
  if (!fs.existsSync(PLUGIN_DIR)) throw new Error('plugin/aq-core not found at ' + PLUGIN_DIR);
  if (!fs.existsSync(THEME_DIR)) throw new Error('theme/aqm-base not found at ' + THEME_DIR);
  fs.mkdirSync(DIST_DIR, { recursive: true });

  // Plugin — the updater downloads this asset; top-level folder = aq-core/.
  const pluginVer = readVersion(path.join(PLUGIN_DIR, 'aq-core.php'), 'plugin/aq-core/aq-core.php');
  const pluginZip = path.join(DIST_DIR, `autoforge-wp-${pluginVer}.zip`);
  await zipDir(PLUGIN_DIR, 'aq-core', pluginZip);
  report(pluginZip, pluginVer);

  // Companion stub theme — top-level folder = aqm-base/.
  const themeVer = readVersion(path.join(THEME_DIR, 'style.css'), 'theme/aqm-base/style.css');
  const themeZip = path.join(DIST_DIR, `aqm-base-${themeVer}.zip`);
  await zipDir(THEME_DIR, 'aqm-base', themeZip);
  report(themeZip, themeVer);

  console.log('  Next: create a GitHub release tagged v' + pluginVer + ' and attach the plugin zip as an asset.');
  console.log('        Install the theme once via Appearance → Themes → Add New → Upload.');
}

// Minimal glob matcher for the simple ** patterns above (no external dep).
function globMatch(glob, str) {
  const re = new RegExp(
    '^' + glob
      .replace(/[.+^${}()|[\]\\]/g, '\\$&')
      .replace(/\*\*/g, ' ')
      .replace(/\*/g, '[^/]*')
      .replace(/ /g, '.*') + '$'
  );
  return re.test(str);
}

main().catch((err) => { console.error('build-release failed:', err.message); process.exit(1); });
