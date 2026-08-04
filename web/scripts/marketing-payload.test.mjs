#!/usr/bin/env node
// PAYLOAD-EQUIVALENCE TEST (anchors STEP 2, the wizard) — the behavior-preservation proof for the wizard.
//
// The restyle swaps the marketing section's INPUT MECHANISM (12 stacked trilingual <Input>s → one
// form-level FormLanguageSwitcher). This asserts the new mechanism drives the IDENTICAL saveSection payload
// the old one did — so the completeness gate + OD-19 + marketing-completeness fire on the SAME data. It
// imports the REAL reducer (src/pages/marketing-payload.ts, which AdminProgrammes uses), not a mirror.
import assert from 'node:assert/strict';
import { MARKETING_FIELDS, setMarketingLang, marketingLangComplete } from '../src/pages/marketing-payload.ts';

// The payload the OLD stacked inputs produced (documented from AdminProgrammes.tsx pre-restyle):
//   { [field]: { en, tc, sc } } for tagline/category/age_range/duration, plus brand_color.
const OLD_STACKED_PAYLOAD = {
  brand_color: '#7A3B57',
  tagline: { en: 'Build a robot', tc: '製作機械人', sc: '制作机器人' },
  category: { en: 'STEM', tc: 'STEM', sc: 'STEM' },
  age_range: { en: '10-14', tc: '10-14', sc: '10-14' },
  duration: { en: '10 weeks', tc: '十週', sc: '十周' },
};

// Rebuild the SAME payload the way the FormLanguageSwitcher drives it: pick a language, fill every field in
// it, switch language, repeat — through the extracted reducer the switcher's inputs wire to.
let draft = { brand_color: '#7A3B57' };
for (const lang of ['en', 'tc', 'sc']) {
  for (const field of MARKETING_FIELDS) {
    draft = setMarketingLang(draft, field, lang, OLD_STACKED_PAYLOAD[field][lang]);
  }
}
assert.deepEqual(draft, OLD_STACKED_PAYLOAD,
  'FormLanguageSwitcher must produce the IDENTICAL saveSection payload the stacked inputs did');

// OD-19 completeness: all-three-present → complete; a language gap is detected (switcher dot ⇄ server gate).
assert.deepEqual(marketingLangComplete(draft), { en: true, tc: true, sc: true }, 'all three languages complete');
const withGap = setMarketingLang(OLD_STACKED_PAYLOAD, 'tagline', 'sc', ''); // clear one 简 field
assert.equal(marketingLangComplete(withGap).sc, false, 'a 简 gap is detected → the 422 language_incomplete gate still fires');
assert.equal(marketingLangComplete(withGap).en, true, 'the other languages remain complete');

// The reducer never mutates its input (the draft is React state).
const before = JSON.stringify(OLD_STACKED_PAYLOAD);
setMarketingLang(OLD_STACKED_PAYLOAD, 'tagline', 'en', 'mutated?');
assert.equal(JSON.stringify(OLD_STACKED_PAYLOAD), before, 'reducer is pure (no input mutation)');

console.log('marketing-payload PASSED — FormLanguageSwitcher drives the IDENTICAL saveSection payload; OD-19 preserved.');
