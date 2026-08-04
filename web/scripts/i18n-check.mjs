#!/usr/bin/env node
// i18n gate (OD-19) — run in CI and before commit:
// 1. Key parity: every key exists in all three locales (a missing key is a defect).
// 2. Hardcoded-string scan: JSX text literals outside the i18n layer fail the build.
//    Allowlist: brand marks and non-linguistic glyphs only.
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const localesDir = join(root, 'src/i18n/locales');
const locales = ['en.json', 'zh-TC.json', 'zh-SC.json'];

const flatten = (obj, prefix = '') =>
  Object.entries(obj).flatMap(([k, v]) =>
    typeof v === 'object' && v !== null ? flatten(v, `${prefix}${k}.`) : [`${prefix}${k}`],
  );

let failed = false;

// 1 — parity
const keySets = Object.fromEntries(
  locales.map((f) => [f, new Set(flatten(JSON.parse(readFileSync(join(localesDir, f), 'utf8'))))]),
);
const union = new Set(locales.flatMap((f) => [...keySets[f]]));
for (const f of locales) {
  const missing = [...union].filter((k) => !keySets[f].has(k));
  if (missing.length) {
    failed = true;
    console.error(`FAIL ${f} missing ${missing.length} key(s): ${missing.join(', ')}`);
  } else {
    console.log(`OK   ${f} — ${keySets[f].size} keys, parity complete`);
  }
}

// 2 — hardcoded JSX text literals (brand mark "KA" and pure punctuation allowed)
const ALLOW = new Set(['KA']);
const files = [];
const walk = (dir) => {
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) walk(p);
    // Ds2Gallery is a DEV-only component reference (dead-code-eliminated from prod); its labels are
    // developer-facing component/prop-state names, not translatable product copy — excluded from the scan.
    else if (/\.(tsx|ts)$/.test(entry) && !p.includes('/i18n/') && !p.includes('Ds2Gallery')) files.push(p);
  }
};
walk(join(root, 'src'));

const literalRe = /(?<!=)>\s*([A-Za-z一-鿿][^<>{}\n]*?)\s*</g;
for (const file of files) {
  const src = readFileSync(file, 'utf8');
  for (const match of src.matchAll(literalRe)) {
    const text = match[1].trim();
    if (ALLOW.has(text)) continue;
    failed = true;
    console.error(`FAIL hardcoded string in ${relative(root, file)}: "${text}"`);
  }
}

if (failed) {
  console.error('i18n:check FAILED');
  process.exit(1);
}
console.log('i18n:check PASSED — parity complete, no hardcoded user-facing strings');
