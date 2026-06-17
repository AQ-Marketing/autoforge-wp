/**
 * AQM Base — per-client Tailwind build.
 *
 * The section markup now lives in the AutoForge plugin, so the build MUST
 * scan plugin/aq-core/render (where the utility classes are) in addition to the
 * theme and the client's page JSON. Missing that glob would purge every class
 * used only in section templates and the site would lose its styling.
 *
 * Design tokens are DATA: a client ships content/design.json (colors, fonts,
 * maxWidth). This build merges that over the platform defaults and compiles a
 * per-client main.css that the content import delivers into the stub theme.
 * Falls back to a local tailwind.tokens.json, then to the built-in defaults, so
 * the build always succeeds even before a client provides tokens.
 */

const path = require("path");
const fs = require("fs");

function loadTokens() {
  const candidates = [
    path.join(__dirname, "../../content/design.json"),
    path.join(__dirname, "tailwind.tokens.json"),
  ];
  for (const file of candidates) {
    try {
      if (fs.existsSync(file)) {
        const json = JSON.parse(fs.readFileSync(file, "utf8"));
        if (json && typeof json === "object") return json;
      }
    } catch (e) {
      // eslint-disable-next-line no-console
      console.warn("[tailwind] ignoring unparseable design tokens at " + file + ": " + e.message);
    }
  }
  return {};
}

// Platform default palette/fonts — a deliberately BARE, brand-neutral starting
// point (neutral slate + a single blue accent, system fonts). It is NOT any
// client's brand; every client repaints it via content/design.json. The engine
// must never ship a real client's colors or fonts as these defaults.
//   brand  = neutral slate (dark sections, headings, tints, borders)
//   accent = one neutral accent (links, buttons, highlights)
const DEFAULTS = {
  colors: {
    brand: {
      50: "#f8fafc", 100: "#f1f5f9", 200: "#e2e8f0", 300: "#cbd5e1",
      400: "#94a3b8", 500: "#64748b", 600: "#475569", 700: "#334155",
      800: "#1e293b", 900: "#0f172a", 950: "#020617",
    },
    accent: {
      50: "#eff6ff", 100: "#dbeafe", 200: "#bfdbfe", 300: "#93c5fd",
      400: "#60a5fa", 500: "#3b82f6", 600: "#2563eb", 700: "#1d4ed8",
      800: "#1e40af", 900: "#1e3a8a", 950: "#172554",
    },
  },
  fontFamily: {
    serif: ["Georgia", "Cambria", "Times New Roman", "serif"],
    display: ["Georgia", "Cambria", "Times New Roman", "serif"],
    sans: ["system-ui", "-apple-system", "Segoe UI", "Roboto", "Helvetica", "Arial", "sans-serif"],
  },
  maxWidth: { prose: "70ch", content: "1400px", "content-wide": "1600px" },
};

const tokens = loadTokens();
const colors = Object.assign({}, DEFAULTS.colors, tokens.colors || {});
const fontFamily = Object.assign({}, DEFAULTS.fontFamily, tokens.fontFamily || tokens.fonts || {});
const maxWidth = Object.assign({}, DEFAULTS.maxWidth, tokens.maxWidth || {});

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    path.join(__dirname, "**/*.php"),
    path.join(__dirname, "assets/js/**/*.js"),
    path.join(__dirname, "../../plugin/aq-core/render/**/*.php"),
    path.join(__dirname, "../../content/**/*.json"),
  ],
  theme: {
    extend: {
      colors,
      fontFamily,
      maxWidth,
      typography: {
        DEFAULT: { css: { maxWidth: "70ch" } },
      },
    },
  },
  plugins: [],
};
