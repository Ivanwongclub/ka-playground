#!/usr/bin/env node
// Emoji-ban gate (DS2 §3.17 — "Emoji are prohibited everywhere") — runs in CI/CD (embedded in `npm run build`)
// and before commit. Scans src for emoji codepoints and FAILS loud on any hit. Mirrors scripts/i18n-check.mjs
// (same node:fs walk + fail-loud + process.exit(1)). The ONLY escape hatch is an ALLOWLIST of specific
// codepoints (matching i18n-check's ALLOW-set convention) — there is NO inline per-line opt-out, because
// i18n-check has none. Use a lucide-react icon instead of an emoji (§3.17).
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = new URL('..', import.meta.url).pathname;

// Emoji codepoint ranges (P0-3c). Arrows U+2190–21FF are DELIBERATELY excluded (too broad — plain arrows are
// not emoji). U+FE0F (variation selector-16) + U+200D (ZWJ) catch emoji sequences.
const EMOJI = /[\u{1F300}-\u{1F5FF}\u{1F600}-\u{1F6FF}\u{1F900}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}\u{200D}]/gu;

// Sanctioned functional TEXT glyphs (monochrome symbols, NOT pictograph emoji) that fall inside the ranges —
// allowlisted like i18n-check's `new Set(['KA'])`. Everything else in the ranges fails.
const ALLOW = new Set([
  // TODO: migrate to lucide when FinancialIntegrity(U-7)/StudentTeam(U-2) are rebuilt; then remove from ALLOW.
  0x2713, // ✓  recon pass mark   (FinancialIntegrity)
  0x2717, // ✗  recon fail mark   (FinancialIntegrity)
  0x2605, // ★  school-bound team marker (StudentTeam)
]);

let failed = false;
const files = [];
const walk = (dir) => {
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) walk(p);
    else if (/\.(tsx|ts|css)$/.test(entry)) files.push(p);
  }
};
walk(join(root, 'src'));

for (const file of files) {
  const lines = readFileSync(file, 'utf8').split('\n');
  lines.forEach((line, i) => {
    for (const m of line.matchAll(EMOJI)) {
      const cp = m[0].codePointAt(0);
      if (ALLOW.has(cp)) continue;
      failed = true;
      console.error(`FAIL emoji in ${relative(root, file)}:${i + 1} — U+${cp.toString(16).toUpperCase()} «${m[0]}»`);
    }
  });
}

if (failed) {
  console.error('emoji:check FAILED — emoji are prohibited (DS2 §3.17). Use a lucide-react icon instead.');
  process.exit(1);
}
console.log(`emoji:check PASSED — ${files.length} files scanned, no emoji (3 functional glyphs allowlisted).`);
