#!/usr/bin/env node
// DS2 anti-drift gate — the --ka-* COLOUR tokens in src/ds2/tokens.css MUST equal theme.ts kaColors /
// kaCategoryAccents. theme.ts is the single palette source; tokens.css only mirrors it (plus DS2-owned
// additions that have no theme.ts counterpart and are not checked here). A divergence is a defect: the
// built surfaces (antd, via theme.ts) and DS2 surfaces (via --ka-*) would render different golds.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const tokens = readFileSync(join(root, 'src/ds2/tokens.css'), 'utf8');
const theme = readFileSync(join(root, 'src/theme/theme.ts'), 'utf8');

// --ka-* token  →  theme.ts key (kaColors + kaCategoryAccents)
const MAP = {
  '--ka-bg': 'background', '--ka-card': 'card', '--ka-muted': 'muted', '--ka-fg': 'foreground',
  '--ka-fg-muted': 'mutedForeground', '--ka-gold': 'gold', '--ka-gold-hover': 'goldHover',
  '--ka-border': 'border', '--ka-border-strong': 'borderStrong',
  '--ka-success': 'success', '--ka-warning': 'warning', '--ka-danger': 'danger',
  '--ka-cat-language': 'language', '--ka-cat-stem': 'stem', '--ka-cat-arts': 'arts',
  '--ka-cat-maths': 'maths', '--ka-cat-featured': 'featured',
};

const themeHex = {};
for (const m of theme.matchAll(/(\w+):\s*'(#[0-9A-Fa-f]{3,8})'/g)) themeHex[m[1]] = m[2].toLowerCase();
const tokHex = {};
for (const m of tokens.matchAll(/(--ka-[\w-]+):\s*(#[0-9A-Fa-f]{3,8})/g)) tokHex[m[1]] = m[2].toLowerCase();

let failed = false;
for (const [tok, key] of Object.entries(MAP)) {
  const want = themeHex[key];
  const got = tokHex[tok];
  if (!got) { console.error(`  MISSING  ${tok} not defined in src/ds2/tokens.css`); failed = true; continue; }
  if (!want) { console.error(`  MISSING  theme.ts has no '${key}'`); failed = true; continue; }
  if (got !== want) { console.error(`  DRIFT    ${tok} = ${got}  but theme.ts ${key} = ${want}`); failed = true; }
}

if (failed) {
  console.error('\nDS2 token drift — src/ds2/tokens.css must mirror theme.ts (one palette, one source). FAIL.');
  process.exit(1);
}
console.log(`ds2:tokens PASSED — ${Object.keys(MAP).length} colour tokens mirror theme.ts, no drift.`);
