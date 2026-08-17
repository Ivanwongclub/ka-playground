#!/usr/bin/env node
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════
// W2-FIDELITY-RIG — prototype-fidelity comparison rig (LOCAL DEV TOOL, not product code, not CI).
//
// Screenshots a BUILT surface (driven through a real browser + real login) beside the matching PROTOTYPE
// screen, at the same viewport, and emits a DOM-derived structural table.
//
//   node scripts/fidelity.mjs --screen gua-home --route / --role guardian --viewport mobile --card W2-XXX
//
// PROTOTYPE SOURCE: docs/design/KAP-Prototype.html (repo-TRACKED). NOTE: a root-level `design/KAP-Prototype.html`
//   also exists but is an UNTRACKED duplicate the owner created 17 Aug — do NOT point the rig at it; it will be
//   removed. Override the path only to TEST the freshness gate: FIDELITY_PROTO=/tmp/short.html.
//
// FRESHNESS GATE: PRIMARY = size ≥ 380 KB (the good 17 Aug prototype is ~387 KB; the stale 13 Aug copy is
//   ~135 KB — a 3× gap, so size alone separates them and catches truncation). SECONDARY = ≥ 2600 lines, a sanity
//   check only. An EXACT line count was DROPPED as brittle: `wc -l` counts newline chars (2635) while this
//   reader's split('\n') yields 2636 on a trailing newline, and a one-line edit would false-fail. A smaller/
//   shorter file is REFUSED — the rig stops. Screen count is reported (diagnostic), never asserted.
//
// CREDENTIALS: the demo password is read from env FIDELITY_PASSWORD ONLY — never hardcoded, never committed,
//   never the GCP secret. The local demo accounts were reset to a local-only password. Export it before running.
//
// VIEWPORT: desktop 1440×900 for STAFF screens, mobile 390×844 for FAMILY screens — matching the prototype's own
//   framing on BOTH sides. This is load-bearing: a guardian-home comparison run desktop-on-both would compare the
//   wrong grammar entirely (the prototype frames family as a phone; the built family surface is .ka-mobile-shell).
//
// STRUCTURAL TABLE: a DOM-derived HEURISTIC and a guided index into the two screenshots — NOT a fidelity verdict.
//   The two PNGs are the ground truth. A green table is NEVER a passed fidelity check.
//
// PREREQUISITES (manual, one-time — deliberately NOT a postinstall hook, so no install/CI bloat):
//   npm i -D playwright   (done)      ·      npx playwright install chromium   (the ~150 MB browser binary)
// The built app must be running locally (default http://localhost:8080 — override with FIDELITY_BASE).
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════
import { chromium } from 'playwright';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { statSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, '../..'); // web/scripts → repo root
const PROTO_TRACKED = join(REPO, 'docs/design/KAP-Prototype.html');
const BASE = process.env.FIDELITY_BASE ?? 'http://localhost:8080';

const VIEWPORTS = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } };

// role → seeded demo email (DemoSeeder). The password is env-only (never here).
const ROLE_EMAIL = {
  guardian: 'guardianA@example.com',
  student: 'student.a1@example.com',
  school_admin: 'schooladmin@example.com',
  teacher: 'teacher@example.com',
  ops: 'ops@example.com',
  finance: 'finance1@example.com',
  audit: 'audit@example.com',
  super: 'super@example.com',
};

const HEURISTIC_NOTE =
  'HEURISTIC INDEX — not a fidelity verdict. The two screenshots are the ground truth; a green table is NOT a pass.';

function parseArgs(argv) {
  const a = {};
  for (let i = 0; i < argv.length; i += 2) a[argv[i].replace(/^--/, '')] = argv[i + 1];
  return a;
}

function assertFreshProto(path) {
  const src = readFileSync(path, 'utf8');
  const lines = src.split('\n').length;
  const bytes = statSync(path).size;
  const screens = new Set(src.match(/data-p="[a-z0-9-]+"/g) ?? []).size;
  // PRIMARY gate = size: the good 17 Aug prototype is ~387 KB, the stale 13 Aug copy is ~135 KB — a 3× gap, so
  // bytes alone separates them unambiguously and catches any truncation. SECONDARY = lines >= 2600, a sanity
  // check only. An EXACT line count was dropped: it is brittle across counting conventions — `wc -l` counts
  // newline chars (2635) while this `split('\n')` reader yields 2636 on a trailing newline — and a one-line
  // edit would false-fail. Screen count stays diagnostic (42 data-p blocks ≠ the nominal 57).
  if (bytes < 380_000 || lines < 2600) {
    console.error(
      `FRESHNESS GATE FAILED — ${path}\n  bytes=${bytes} (need ≥ 380000, PRIMARY)  lines=${lines} (need ≥ 2600, sanity)\n` +
        '  This is a stale/truncated prototype (the 13 Aug copy is ~135 KB). REFUSING to compare.',
    );
    process.exit(2);
  }
  console.log(`Freshness gate PASSED — ${(bytes / 1024).toFixed(1)} KB, ${lines} lines, ${screens} data-p screens (diagnostic).`);
}

// One extraction shape, two selector sets. Runs in the browser (page.evaluate). Returns an ordered list of
// { kind, label, chip, gold } plus a gold count. `goldish` is a cross-vocabulary computed-style test.
const EXTRACT = (rootSel, sel) => {
  const root = document.querySelector(rootSel) ?? document.body;
  const goldish = (el) => {
    for (const prop of ['backgroundColor', 'color', 'borderColor']) {
      const m = getComputedStyle(el)[prop].match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
      if (!m) continue;
      const [r, g, b] = [+m[1], +m[2], +m[3]];
      if (r > 180 && g > 120 && g < 200 && b < 110 && r > g && g > b) return true; // ≈ gold ladder
    }
    return false;
  };
  const items = [];
  let gold = 0;
  const seen = new Set();
  for (const [kind, s] of Object.entries(sel)) {
    for (const el of root.querySelectorAll(s)) {
      if (seen.has(el)) continue;
      seen.add(el);
      const label = (el.getAttribute('aria-label') || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 48);
      if (!label) continue;
      const chip = kind === 'chip';
      const isGold = (kind === 'button' || kind === 'action') && goldish(el);
      if (isGold) gold++;
      items.push({ kind, label, chip, gold: isGold });
    }
  }
  return { items, gold };
};

// Built (AntD/DS2) vocabulary.
const BUILT_SEL = {
  nav: '.ant-menu-item .ant-menu-title-content, .ka-tabbar a, .ka-tabbar button',
  card: '.ds2-glance__title, .ant-card-head-title, .ds2-rhdr__name, h1, h2, h3, .ant-typography h3, h4, h5',
  row: '.ds2-glance__lbl, .ds2-record__hl .ds2-hl__lbl',
  chip: '.ant-tag, .ds2-uchip, .ka-tag',
  button: '.ant-btn',
};
// Prototype vocabulary (broad — the prototype's own classes vary; anchor on structural roles).
const PROTO_SEL = {
  nav: '.btabs *, .btab, .side .item .lbl, .item .lbl',
  card: '.card h3, .card h4, .card .t, .ttl, h1, h2, h3, h4',
  row: '.row .k, .kv .k, .lbl',
  chip: '.chip, .pill, .tag, .badge',
  button: 'button, .btn, .cta, a.act',
};

function diffTable(proto, built) {
  const norm = (s) => s.toLowerCase().replace(/[^a-z0-9]/g, '');
  const bByLabel = new Map(built.items.map((b) => [norm(b.label), b]));
  const rows = [];
  const usedBuilt = new Set();
  for (const p of proto.items) {
    const b = bByLabel.get(norm(p.label));
    if (b) { usedBuilt.add(b); rows.push({ proto: `${p.kind}:${p.label}`, built: `${b.kind}:${b.label}`, verdict: 'match' }); }
    else rows.push({ proto: `${p.kind}:${p.label}`, built: '—', verdict: 'MISSING' });
  }
  for (const b of built.items) if (!usedBuilt.has(b)) rows.push({ proto: '—', built: `${b.kind}:${b.label}`, verdict: 'EXTRA' });
  return rows;
}

function renderTable(rows, proto, built) {
  const w = (s, n) => (s.length > n ? s.slice(0, n - 1) + '…' : s).padEnd(n);
  const lines = [
    HEURISTIC_NOTE,
    '',
    `${w('PROTOTYPE element', 44)} ${w('BUILT element', 44)} VERDICT`,
    `${'-'.repeat(44)} ${'-'.repeat(44)} -------`,
    ...rows.map((r) => `${w(r.proto, 44)} ${w(r.built, 44)} ${r.verdict}`),
    '',
    `chips  — prototype: ${proto.items.filter((i) => i.chip).length}   built: ${built.items.filter((i) => i.chip).length}`,
    `GOLD action count — prototype: ${proto.gold}   built: ${built.gold}   (rule: ≤ 1 per surface) ${built.gold <= 1 && proto.gold <= 1 ? 'OK' : '⚠ VIOLATION'}`,
  ];
  return lines.join('\n');
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const { screen, route, role, card } = args;
  const viewport = args.viewport ?? 'desktop';
  const protoPath = process.env.FIDELITY_PROTO ?? PROTO_TRACKED; // override ONLY to test the gate
  if (!screen || !route || !role || !card) {
    console.error('usage: node scripts/fidelity.mjs --screen <data-p> --route </path> --role <role> --card <ID> [--viewport desktop|mobile]');
    process.exit(1);
  }
  if (!ROLE_EMAIL[role]) { console.error(`unknown role '${role}'. known: ${Object.keys(ROLE_EMAIL).join(', ')}`); process.exit(1); }
  if (!VIEWPORTS[viewport]) { console.error(`unknown viewport '${viewport}'. use desktop|mobile`); process.exit(1); }
  const password = process.env.FIDELITY_PASSWORD;
  if (!password) { console.error('FIDELITY_PASSWORD is not set — export the local-only demo password (never hardcode).'); process.exit(1); }

  assertFreshProto(protoPath);

  const outDir = join(HERE, '..', 'fidelity', card);
  mkdirSync(outDir, { recursive: true });
  const builtPng = join(outDir, `${screen}.built.png`);
  const protoPng = join(outDir, `${screen}.proto.png`);
  const tablePath = join(outDir, `${screen}.table.md`);

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: VIEWPORTS[viewport], deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  // ── BUILT: real login (the app's own POST /api/auth/login → token → navigate), then the route ──
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.fill('#login-email', ROLE_EMAIL[role]);
  await page.fill('#login-password', password);
  await page.click('button[type="submit"], .ka-login-form button');
  await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 }).catch(() => {});
  await page.goto(`${BASE}${route}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(600); // let charts/skeletons settle
  const evalStruct = (rootSel, sel) =>
    page.evaluate(({ rootSel, sel, src }) => eval('(' + src + ')')(rootSel, sel), { rootSel, sel, src: EXTRACT.toString() });
  const builtStruct = await evalStruct('main, .ka-mobile-content, body', BUILT_SEL);
  await page.screenshot({ path: builtPng, fullPage: true });

  // ── PROTOTYPE: isolate the one screen by class-toggle, screenshot its block ──
  await page.goto(pathToFileURL(protoPath).href, { waitUntil: 'networkidle' });
  const isolated = await page.evaluate((key) => {
    document.querySelectorAll('.app.on, .page.on').forEach((e) => e.classList.remove('on'));
    const pg = document.querySelector(`[data-p="${key}"]`);
    if (!pg) return false;
    const app = pg.closest('.app');
    if (app) app.classList.add('on');
    pg.classList.add('on');
    pg.scrollIntoView();
    return true;
  }, screen);
  if (!isolated) { console.error(`prototype screen [data-p="${screen}"] not found in ${protoPath}`); await browser.close(); process.exit(3); }
  await page.waitForTimeout(200);
  const protoStruct = await evalStruct(`[data-p="${screen}"]`, PROTO_SEL);
  const el = await page.$(`[data-p="${screen}"]`);
  await (el ?? page).screenshot({ path: protoPng });

  await browser.close();

  const table = renderTable(diffTable(protoStruct, builtStruct), protoStruct, builtStruct);
  writeFileSync(tablePath, `# Fidelity — ${card} / ${screen}\n\nroute \`${route}\` · role \`${role}\` · viewport \`${viewport}\`\n\nbuilt: \`${builtPng}\`\nproto: \`${protoPng}\`\n\n\`\`\`\n${table}\n\`\`\`\n`);

  console.log(`\nBUILT screenshot : ${builtPng}`);
  console.log(`PROTO screenshot : ${protoPng}`);
  console.log(`table (md)       : ${tablePath}\n`);
  console.log(table);
}

main().catch((e) => { console.error('fidelity rig error:', e); process.exit(1); });
