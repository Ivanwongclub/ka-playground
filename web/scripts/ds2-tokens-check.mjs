#!/usr/bin/env node
// DS2 anti-drift gate — the --ka-* COLOUR tokens in src/ds2/tokens.css MUST equal theme.ts kaColors /
// kaCategoryAccents. theme.ts is the single palette source; tokens.css only mirrors it (plus DS2-owned
// additions that have no theme.ts counterpart and are not checked here). A divergence is a defect: the
// built surfaces (antd, via theme.ts) and DS2 surfaces (via --ka-*) would render different golds.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const theme = readFileSync(join(root, 'src/theme/theme.ts'), 'utf8');

// --ka-* token  →  theme.ts key (kaColors + kaCategoryAccents)
const MAP = {
  '--ka-bg': 'background', '--ka-card': 'card', '--ka-muted': 'muted', '--ka-fg': 'foreground',
  '--ka-muted-fg': 'mutedForeground', '--ka-gold': 'gold', '--ka-gold-hover': 'goldHover',
  '--ka-border': 'border', '--ka-border-strong': 'borderStrong',
  '--ka-success': 'success', '--ka-warning': 'warning', '--ka-danger': 'danger',
  '--ka-cat-language': 'language', '--ka-cat-stem': 'stem', '--ka-cat-arts': 'arts',
  '--ka-cat-maths': 'maths', '--ka-cat-featured': 'featured',
};

const themeHex = {};
for (const m of theme.matchAll(/(\w+):\s*'(#[0-9A-Fa-f]{3,8})'/g)) themeHex[m[1]] = m[2].toLowerCase();
// Parse ONLY the :root block of a CSS file (ignore @media overrides, e.g. index.css's intentional
// prefers-contrast a11y steps — v2.1 §19 — which legitimately differ and are NOT drift).
const rootHexIn = (file) => {
  const css = readFileSync(join(root, file), 'utf8');
  const rootBlock = (css.match(/:root\s*\{([^}]*)\}/) || [, ''])[1];
  const out = {};
  for (const m of rootBlock.matchAll(/(--ka-[\w-]+):\s*(#[0-9A-Fa-f]{3,8})/g)) out[m[1]] = m[2].toLowerCase();
  return out;
};

// The DS2 token layer (src/ds2/tokens.css) MUST mirror theme.ts for every mapped token. The pre-existing
// utility layer (src/index.css :root) is cross-checked for whatever it overlaps, so the two --ka-* layers
// (which co-exist once a surface adopts DS2) can never diverge from theme.ts or each other.
const ds2 = rootHexIn('src/ds2/tokens.css');
const idx = rootHexIn('src/index.css');

let failed = false;
let checked = 0;
for (const [tok, key] of Object.entries(MAP)) {
  const want = themeHex[key];
  if (!want) { console.error(`  MISSING  theme.ts has no '${key}'`); failed = true; continue; }
  const g2 = ds2[tok];
  if (!g2) { console.error(`  MISSING  ${tok} not defined in src/ds2/tokens.css`); failed = true; }
  else if (g2 !== want) { console.error(`  DRIFT    tokens.css ${tok} = ${g2}  but theme.ts ${key} = ${want}`); failed = true; }
  else checked++;
  const gi = idx[tok]; // index.css only needs to match what it defines in :root
  if (gi && gi !== want) { console.error(`  DRIFT    index.css :root ${tok} = ${gi}  but theme.ts ${key} = ${want}`); failed = true; }
}

if (failed) {
  console.error('\nDS2 token drift — the --ka-* layers must mirror theme.ts (one palette, one source). FAIL.');
  process.exit(1);
}
console.log(`ds2:tokens PASSED — ${checked} tokens.css --ka-* mirror theme.ts (+ index.css :root cross-checked), no drift.`);
