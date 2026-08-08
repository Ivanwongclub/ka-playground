#!/usr/bin/env node
// DS2 "changes-nothing-yet" guard (Ruling 2 — the load-bearing assertion of the DS2 root landing).
//
// NOTHING outside web/src/ds2/ (and, from STEP 2, the dev-only gallery) may import @/ds2. If no existing
// surface imports the barrel, then landing DS2 restyled NOTHING — every money / consent / child-data page
// renders byte-identical, because none has adopted DS2. Adopting DS2 is a DELIBERATE, per-surface act: a
// rollout card, in its own gated slot, adds that surface to ALLOWED below (and swaps its markup). The guard
// then permits exactly that surface — accidental adoption stays impossible.
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const srcDir = join(root, 'src');

// Allowed importers of @/ds2. Grows ONLY when a rollout card deliberately adopts DS2 on a surface.
const ALLOWED = [
  'src/ds2/', // the library itself
  'src/pages/Ds2Gallery.tsx', // the DEV-only gallery (STEP 2) — dead-code-eliminated from production
  // ── restyle rollout: each surface is a deliberate adoption in its own gated card ──
  'src/pages/SelfService.tsx', // ANCHORS STEP 1 — My Children (MyPayments/MyStudents in-file are untouched)
  'src/pages/AdminProgrammes.tsx', // ANCHORS STEP 2 — the wizard
  'src/pages/Payments.tsx', // ANCHORS STEP 3 — the money surface
  // ── rollout D1 (Dashboards & lists, display tier) ──
  'src/pages/Dashboard.tsx', // D1 — role-scoped KPI grid (SubPanel/MetaChip; reads unchanged)
  'src/pages/Enrolments.tsx', // D1 — enrolment list (SubPanel/StateBadge; read-only, no mutation)
  // ── rollout D2 (Audit report views, display tier) — all read-only ──
  'src/pages/EnrolmentPool.tsx', // D2 — pool/timelines/withdrawals report (SubPanel/StateBadge)
  'src/pages/AccessIdentity.tsx', // D2 — access & identity report (SubPanel/MetaChip stat tiles)
  'src/pages/AdminAudit.tsx', // D2 — append-only audit log (SubPanel; filters are GET, no mutation)
  'src/pages/ConsentEvidence.tsx', // D2 — consent evidence report (SubPanel/MetaChip; download is a GET export)
  // ── rollout D3 (Member surfaces, display tier) — Community.tsx (Events/Directory/Profile) ──
  'src/pages/Community.tsx', // D3 — member events/directory/profile (SubPanel/MetaChip; RSVP + profile mutations byte-identical)
];

const walk = (d) => readdirSync(d).flatMap((n) => {
  const p = join(d, n);
  return statSync(p).isDirectory() ? walk(p) : [p];
});
// matches:  from '@/ds2'  ·  from '@/ds2/atoms'  ·  from '../ds2'  ·  import '../../ds2/x'
const IMPORTS_DS2 = /(?:from|import)\s+['"](@\/ds2(?:\/[^'"]*)?|(?:\.\.?\/)+ds2(?:\/[^'"]*)?)['"]/;

const offenders = [];
for (const f of walk(srcDir).filter((f) => /\.(ts|tsx)$/.test(f))) {
  const rel = relative(root, f).split('\\').join('/');
  if (ALLOWED.some((a) => rel.startsWith(a))) continue;
  if (IMPORTS_DS2.test(readFileSync(f, 'utf8'))) offenders.push(rel);
}

if (offenders.length) {
  console.error('ds2:import-guard FAIL — these files import @/ds2 but are not an allowed adopter:');
  offenders.forEach((o) => console.error('  ' + o));
  console.error('\nAdopting DS2 is a deliberate rollout step: add the surface to ALLOWED in scripts/ds2-import-guard.mjs within its gated card.');
  process.exit(1);
}
console.log('ds2:import-guard PASSED — 0 external importers of @/ds2; every built surface is byte-identical post-landing.');
