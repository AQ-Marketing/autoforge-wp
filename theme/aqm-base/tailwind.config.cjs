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

// Platform default palette/fonts. A professional navy + copper starting point;
// every client overrides these via content/design.json.
const DEFAULTS = {
  colors: {
    brand: {
      50: "#f4f5fa", 100: "#e6e8f1", 200: "#c8cce0", 300: "#9da4c2",
      400: "#6e779f", 500: "#4d5680", 600: "#363e63", 700: "#2B3158",
      800: "#252A46", 900: "#252a46", 950: "#131a40",
    },
    accent: {
      50: "#fdf6e9", 100: "#fae8c4", 200: "#f4d088", 300: "#F9AB3D",
      400: "#e39336", 500: "#f9ab3d", 600: "#e39336", 700: "#c47f0a",
      800: "#6b441b", 900: "#583918", 950: "#321d09",
    },
    forest: { 500: "#5e7d44", 600: "#455c36", 700: "#374829" },
  },
  fontFamily: {
    serif: ["Merriweather", "Georgia", "Cambria", "serif"],
    display: ["Merriweather", "Georgia", "serif"],
    sans: ["Inter", "system-ui", "-apple-system", "Segoe UI", "Roboto", "sans-serif"],
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
