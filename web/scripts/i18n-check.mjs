#!/usr/bin/env node
// i18n gate (OD-19) — run in CI and before commit:
// 1. Key parity: every key exists in all three locales (a missing key is a defect).
// 2. Hardcoded-string scan: JSX text literals outside the i18n layer fail the build.
// 3. COVERAGE (T-I18N-COMPLETE): every key SOURCE REFERENCES exists in every locale.
//
//    Parity and coverage are different properties, and the gap between them shipped twice: B1R deleted two
//    LIVE keys (selfService.viewSessions, studentTeam.formOrJoin) from all three locales — parity intact,
//    two rendered strings broken — and B4 added a t('enrolSpace.tab.fees') call while missing the key in all
//    three — parity intact again, a raw key on screen. Both passed every gate. Parity says the locales
//    AGREE; coverage says they COVER what the code asks for. This section is the second half.
//
//    HOW KEYS ARE FOUND (four reference shapes, all real in this codebase):
//      · t('a.b.c')                    — a static call
//      · t(`a.b.${expr}`)              — a dynamic call: the STATIC PREFIX is extracted and satisfied when
//                                        at least one key in the locale starts with it. The prefix may end
//                                        mid-segment (t(`accessReport.onb_${stage}`) -> "accessReport.onb_"),
//                                        so the test is startsWith, not an exact segment match.
//      · labelKey/i18nKey/titleKey: '…' — key literals in data (StatusTag's registry, NAV, Placeholder),
//                                        later passed to t() as a variable, so the call site itself is opaque.
//      · titleKey="…"                  — the same, as a JSX prop.
//
//    PLURALS: a referenced key is satisfied by its CLDR FAMILY. t('urgency.inDays', {count}) resolves to
//    urgency.inDays_one/_other and the bare key never exists — checking raw keys would fail the very
//    pluralisation the parity section is built to respect.
//
//    DYNAMIC MEMBERS (the half that matters): a bare prefix test would still miss the B4 case, where
//    t(`enrolSpace.tab.${k}`) resolved because SOME tab keys existed while the newly-added one did not.
//    So for every `as const` string array in the same file, if at least TWO of its members already resolve
//    under a prefix, that array is treated as that prefix's VALUE SET and EVERY member must resolve. The
//    two-member floor is what stops an unrelated array from being paired with a prefix by coincidence;
//    demanding 100% thereafter is what catches the member someone forgot to translate.
//
//    UNUSED keys are REPORTED, never failed: a key can be reached only through a dynamic prefix, and this
//    scanner cannot prove absence of use. Deleting on its say-so is how B1R broke two live strings.
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
// CLDR plural collapse (i18next JSON-v4) — PARITY ONLY. A key ending in a CLDR plural suffix belongs to
// a FAMILY, and parity is checked per family: English carries _one AND _other, but Chinese (zh-TC/zh-SC)
// carries ONLY _other (Chinese has a single CLDR plural category). Checking raw keys would wrongly flag
// zh for "missing" the _one form it legitimately never uses. A GENUINELY missing translation still fails —
// a whole family absent in a locale is still absent after the collapse (families are compared, not raw
// keys). Only these six CLDR suffixes collapse; nothing else. The hardcoded-string scan below is untouched.
// Do NOT revert this to raw-key parity.
const CLDR_PLURAL = /_(zero|one|two|few|many|other)$/;
const familyOf = (k) => k.replace(CLDR_PLURAL, '');
const famSets = Object.fromEntries(locales.map((f) => [f, new Set([...keySets[f]].map(familyOf))]));
const union = new Set(locales.flatMap((f) => [...famSets[f]]));
for (const f of locales) {
  const missing = [...union].filter((k) => !famSets[f].has(k));
  if (missing.length) {
    failed = true;
    console.error(`FAIL ${f} missing ${missing.length} key family(ies): ${missing.join(', ')}`);
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

// 3 — coverage: every key the SOURCE references must exist in every locale
const STATIC_T = /\bt\(\s*['"]([\w.-]+)['"]/g;                 // t('a.b.c')
const DYNAMIC_T = /\bt\(\s*`([^`$]*)\$\{/g;                    // t(`a.b.${x}`) -> "a.b."
const KEY_PROP = /\b(?:labelKey|i18nKey|titleKey)\s*[:=]\s*['"]([\w.-]+)['"]/g; // data-carried keys
const staticRefs = new Map();   // key -> first file that references it
const prefixRefs = new Map();   // prefix -> first file
for (const file of files) {
  const src = readFileSync(file, 'utf8');
  const where = relative(root, file);
  for (const m of src.matchAll(STATIC_T)) if (!staticRefs.has(m[1])) staticRefs.set(m[1], where);
  for (const m of src.matchAll(KEY_PROP)) if (!staticRefs.has(m[1])) staticRefs.set(m[1], where);
  for (const m of src.matchAll(DYNAMIC_T)) {
    const prefix = m[1];
    if (prefix && !prefixRefs.has(prefix)) prefixRefs.set(prefix, where);
  }
}

// `as const` string arrays, per file — the candidate VALUE SETS for a dynamic prefix in that same file.
const CONST_ARRAY = /=\s*\[([^\]]*)\]\s*as const/g;
const STRING_MEMBER = /['"]([\w-]+)['"]/g;
const arrayPools = new Map(); // file -> string[][]
for (const file of files) {
  const src = readFileSync(file, 'utf8');
  const pools = [];
  for (const m of src.matchAll(CONST_ARRAY)) {
    const members = [...m[1].matchAll(STRING_MEMBER)].map((x) => x[1]);
    if (members.length) pools.push(members);
  }
  if (pools.length) arrayPools.set(relative(root, file), pools);
}

const hasKey = (locale, key) =>
  keySets[locale].has(key) || [...keySets[locale]].some((k) => familyOf(k) === key); // CLDR family
const hasPrefix = (locale, prefix) => [...keySets[locale]].some((k) => k.startsWith(prefix));

let memberChecks = 0;
for (const f of locales) {
  const missingStatic = [...staticRefs].filter(([k]) => !hasKey(f, k));
  const missingPrefix = [...prefixRefs].filter(([p]) => !hasPrefix(f, p));
  for (const [k, where] of missingStatic) { failed = true; console.error(`FAIL ${f} is missing key "${k}" — referenced in ${where}`); }
  for (const [p, where] of missingPrefix) { failed = true; console.error(`FAIL ${f} has no key starting "${p}" — dynamic reference in ${where}`); }

  // dynamic MEMBERS: a value set that mostly resolves must resolve entirely (see header)
  for (const [prefix, where] of prefixRefs) {
    for (const pool of arrayPools.get(where) ?? []) {
      const resolved = pool.filter((m) => hasKey(f, prefix + m));
      if (resolved.length < 2 || resolved.length === pool.length) continue; // not this prefix's set, or complete
      memberChecks++;
      failed = true;
      for (const m of pool.filter((x) => !hasKey(f, prefix + x))) {
        console.error(`FAIL ${f} is missing key "${prefix}${m}" — ${resolved.length}/${pool.length} of that value set resolve, referenced dynamically in ${where}`);
      }
    }
  }
}
if (!failed) {
  console.log(`OK   coverage — ${staticRefs.size} referenced key(s) + ${prefixRefs.size} dynamic prefix(es) (incl. their value sets) resolve in all ${locales.length} locales`);
}

// 3b — REPORT-ONLY: keys present everywhere but referenced nowhere. Never fails the build (see header).
const referenced = (k) => staticRefs.has(k) || staticRefs.has(familyOf(k)) || [...prefixRefs.keys()].some((p) => k.startsWith(p));
const unused = [...keySets['en.json']].filter((k) => !referenced(k)).sort();
if (unused.length) {
  console.log(`note  ${unused.length} key(s) present in all locales but not statically referenced (report only — dynamic prefixes make deletion unsafe):`);
  console.log(`      ${unused.slice(0, 12).join(', ')}${unused.length > 12 ? `, …+${unused.length - 12} more` : ''}`);
}

if (failed) {
  console.error('i18n:check FAILED');
  process.exit(1);
}
console.log('i18n:check PASSED — parity complete, every referenced key covered, no hardcoded user-facing strings');
