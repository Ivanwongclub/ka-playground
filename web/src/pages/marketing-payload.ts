// The pure trilingual draft model for the marketing wizard section (S-MARKETPLACE-A / OD-19).
//
// Extracted so the DS2 FormLanguageSwitcher (form-level switch) drives the IDENTICAL saveSection payload
// the old stacked trilingual inputs did — the completeness gate + OD-19 + marketing-completeness fire on
// the same data. The equivalence is proven by scripts/marketing-payload.test.mjs (which imports THIS
// module, so the proof is over the real reducer, not a mirror). Pure — no React, erasable-syntax only.

export type Lang = 'en' | 'tc' | 'sc';
export const MARKETING_FIELDS = ['tagline', 'category', 'age_range', 'duration'] as const;

export type TriValue = Partial<Record<Lang, string>>;
export type MarketingDraft = Record<string, unknown>; // { [field]: TriValue, brand_color?: string }

export function triOf(draft: MarketingDraft, field: string): TriValue {
  return (draft[field] as TriValue | undefined) ?? {};
}

// THE payload reducer — the one place a trilingual value is written. Identical output shape whether the
// edit came from a stacked input (old) or the form-level switcher (new): { ...draft, [field]: {..., [lang]: v } }.
export function setMarketingLang(draft: MarketingDraft, field: string, lang: Lang, value: string): MarketingDraft {
  return { ...draft, [field]: { ...triOf(draft, field), [lang]: value } };
}

// Per-language completeness for the switcher's dots: a language is complete when EVERY marketing field has
// a non-empty value in it — the field-level mirror of the server's OD-19 all-three-languages gate.
export function marketingLangComplete(draft: MarketingDraft): Record<Lang, boolean> {
  const langs: Lang[] = ['en', 'tc', 'sc'];
  const out = {} as Record<Lang, boolean>;
  for (const l of langs) {
    out[l] = MARKETING_FIELDS.every((f) => (triOf(draft, f)[l] ?? '').trim().length > 0);
  }
  return out;
}
