#!/usr/bin/env node
// Performance budget (S00 exit gate): no built chunk may exceed 1 MB gzipped.
// Runs as part of `npm run build`, so every later sprint inherits the check.
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';

const BUDGET_BYTES = 1024 * 1024; // 1 MB gzipped per chunk
const assetsDir = new URL('../dist/assets/', import.meta.url).pathname;

let failed = false;
const rows = [];
for (const f of readdirSync(assetsDir).filter((f) => /\.(js|css)$/.test(f))) {
  const gz = gzipSync(readFileSync(join(assetsDir, f))).length;
  rows.push([f, gz]);
  if (gz > BUDGET_BYTES) failed = true;
}
rows.sort((a, b) => b[1] - a[1]);
for (const [f, gz] of rows) {
  const mark = gz > BUDGET_BYTES ? 'FAIL' : 'ok  ';
  console.log(`${mark} ${(gz / 1024).toFixed(1).padStart(8)} kB gz  ${f}`);
}
if (failed) {
  console.error(`bundle-budget FAILED — chunk(s) above ${BUDGET_BYTES / 1024} kB gzipped`);
  process.exit(1);
}
console.log('bundle-budget PASSED');
