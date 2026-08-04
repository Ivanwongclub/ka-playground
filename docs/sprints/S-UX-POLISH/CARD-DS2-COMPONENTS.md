# CARD — DS2 Component Library (the root every page-restyle imports)

Approved from `PROPOSED-DS2-COMPONENTS.md` with two rulings (below). First card of the DS2 rollout.
**DO NOT BUILD YET** — see the **hard gate** at the bottom. Card now; build on Leo's go.

> Builds the atom kit + structure primitives into a shared library at **`web/src/ds2/`** (one import root
> `@/ds2`), realizing the blessed prototypes (`dda3690`) as **antd-composed, i18n-native, darkAlgorithm-
> integrated** components that **enforce D1/D5/D6/D7 by API shape**. Full rationale + inventory + APIs:
> `PROPOSED-DS2-COMPONENTS.md`. Design rules: `AUDIT-AND-DIRECTION.md` (D1–D7).

---

## RULING 1 — DS2 EXTENDS v2.1, additive; NO surface changes as a side-effect
DS2 does **not** replace the token base the built surfaces render on. New components + a new `--ka-*` tokens
layer sit **on top of** v2.1 (`theme.ts` stays the ConfigProvider source) + antd darkAlgorithm. **A surface's
appearance changes ONLY when it deliberately adopts DS2 components in its own gated rollout slot — never as a
side-effect of this spec landing.** This card introduces *capability*; each later rollout card *adopts* it.

## RULING 2 — "CHANGES-NOTHING-YET" PROOF (mandatory exit criterion)
Because DS2 is additive and is the root everything *will* import, the build must **prove it changes nothing
on landing**: after DS2 lands, **every existing built surface renders identically** (none has adopted DS2).
The proof is an **import-guard assertion** — a test that asserts **no file outside `web/src/ds2/` and the
dev-only gallery imports from `@/ds2` / `ds2/`**. If nothing existing imports it, every current page is
byte-identical post-landing. (A visual-regression spot-check on 2–3 key surfaces — Payments, a consent
screen, My Children — may accompany it, but the import-guard is the guarantee.) **This is the guardrail that
landing the root did not silently restyle the money / consent / child-data surfaces.**

---

## Copy standard recorded — 成團 → "Team Formation" (EN)
The English copy standard going forward: **成團 → "Team Formation."** It **rides the rollout** — every page
adopts it **when it is restyled in its own slot** (one touch per surface, terminology + DS2 together), never
as a bulk find-replace. The **zh locales use a proper Chinese term** (proposed per surface at restyle time),
**not** a mixed "Team 成團". **Committed AUDITs keep 成團** (historical record — the no-rewrite rule holds).
This card ships **no page copy** (it changes no surface); it only records the standard for the rollout cards.

---

## Component scope (each ENFORCES its rule structurally)
**Atom kit** — StatusAtom (D5 hierarchy: loud status + demoted detail), StatChip, MetaChip, DatedBadge
(D5+D7: the evidential fact as an atom, detail on demand), StateBadge, Seal, ProgressRing (D7 state-as-
visual); **re-export the existing `StatusTag`**.
**Structure primitives** — SubPanel + ZoneStack + Attest (D6 zones + D5 honesty-by-shape), ZebraTable (D6
horizontal, wraps antd Table), WizardRail (D2/D7 state-by-icon, no captions), FormLanguageSwitcher (D1/D7:
the only trilingual primitive — per-field triplets impossible to import).
**Enforcement principle:** APIs accept **structured data, never free-text prose** — no component exposes a
narrating-subtitle slot; `Attest` takes `dated:{date}` + `onViewRecord`, not a reassurance sentence; the rule
can't be violated because there's no prop to violate it with. One barrel `@/ds2` re-exports all of the above
plus `formatMoney/formatHkt/personName` + `useResource/mutate` — one design import, no second way to render.

**Baked-in choices (PROPOSED §8, recommendations stand):** location `web/src/ds2/` + `@/ds2` barrel ·
anti-drift = a `--ka-*` ↔ `kaColors` **sync test** · gallery = a new **DEV-only `/ds2-gallery`** route
(dead-code-eliminated from prod, like StyleGuide) · **wrap antd** where it has the primitive (Table,
Segmented, Form, Tag, Button), **bespoke** only for Seal/StateBadge/ProgressRing/SubPanel/WizardRail ·
ZebraTable **wraps antd Table** (keeps sort/pagination/sticky).

---

## Build split (mixed depth; reviewed-once-because-imported-everywhere → the once is THOROUGH)
- **STEP 1 — TOKENS/THEME (LINE-BY-LINE).** `ds2/tokens.css` (`--ka-*` mirrored from `kaColors` + the DS2
  additions: `--ka-gold-tint/-line`, `--ka-seal`, the `--admin/--product` density scopes) + the anti-drift
  sync test + the `@/ds2` barrel skeleton. The root of the root.
- **STEP 2 — ATOM KIT (THOROUGH FRONTEND READ) + gallery-1.** StatusAtom, StatChip, MetaChip, StateBadge,
  ProgressRing, DatedBadge, Seal; re-export StatusTag. `/ds2-gallery` renders each in every state.
- **STEP 3 — STRUCTURE primitives (LINE-BY-LINE) + gallery-2.** SubPanel, ZoneStack, Attest, ZebraTable,
  WizardRail, FormLanguageSwitcher — the enforcement primitives; the enforcement IS the review. Gallery
  gains the structure demos (mini wizard, zebra table, zoned card, language switcher).

Depth: **thorough frontend read of every component's API + its rule-enforcement** (line-by-line on tokens +
the four enforcement primitives; thorough-scan on the pure-visual atoms). No backend, no schema, no built-
page change.

---

## Mandatory verification (every step)
1. `npx tsc --noEmit` clean · `npm run build` (bundle budget PASSED) · **i18n parity green** (any new keys in
   all three locales; the gallery exercises every key).
2. **The changes-nothing-yet import-guard (RULING 2):** an automated assertion that `@/ds2` / `ds2/` is
   imported **only** by files inside `web/src/ds2/` and the dev-only gallery — **no existing page imports
   it** → every built surface is byte-identical post-landing. (Optional: a 2–3 surface visual-regression
   spot-check.)
3. The `/ds2-gallery` renders — **one screenshot per gallery step** (the gallery IS a new dev surface;
   the built surfaces get NO screenshot because they are unchanged, proven by #2).
4. **0 migrations · 0 backend · 0 built-page changes** · reconciliation battery untouched (frontend-only).
5. i18n-native: no hardcoded user-facing strings (content = translated ReactNode; chrome = i18n keys); the
   content-language a FormLanguageSwitcher edits stays distinct from the UI display-language.

## Exit gate
tsc/build/i18n-parity green · **import-guard proves no existing surface adopted DS2 (RULING 2)** · gallery
renders · 0 migrations/backend/built-page changes. Diff + VERIFY → `~/Downloads` as files. **Commit HELD.**

---

## ⛔ HARD GATE — build waits for Leo's baseline tags
This build is the **first production change of the DS2 rollout**. It does **not** begin until:
**Leo has pushed + laid the annotated tag baseline of the functional run** (S-UX3-2 → S-UX3-4 → S-MARKETPLACE-A
→ S-UX3-3b → S-UX3-8 → S-UX3-9 + the S-UX-POLISH direction docs). **Confirm the tags are laid before writing
a line of DS2.** Until then: carded, not built. Stop after carding.
