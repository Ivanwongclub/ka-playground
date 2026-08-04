# AUDIT — DS2 Component Library (the design-system-v2 spec build)

**Result:** PASS · **Date:** 2026-08-04 · **HEAD at gate:** `f1b9226`
**Commits:** STEP 1 `0ec08f9` (tokens) · STEP 2 `e7657f9` (atoms + gallery) · STEP 3 `f1b9226` (structure)

> Written by Claude Code at the spec's end. Honesty outranks looking good. This audits the **DS2 library
> build** — the root every page-restyle will import. Planning: `CARD-DS2-COMPONENTS.md` +
> `PROPOSED-DS2-COMPONENTS.md`; design rules D1–D7 in `AUDIT-AND-DIRECTION.md`. Does NOT rewrite any prior
> AUDIT. The library restyled **nothing** — it introduced capability; the rollout adopts it.

## 0. Scope

Turned the blessed anchor prototypes (`dda3690`) into a real shared library at **`web/src/ds2/`** (one import
root `@/ds2`): a token layer + the atom kit + the structure primitives + a DEV-only gallery. Built in three
gated steps, each **commit HELD**, gated on Leo's functional-baseline tags (the first production change of
the rollout). **This card changed no product surface** — it landed the root additively and proved so.

## 1. Architecture — DS2 EXTENDS v2.1, additive

`theme.ts` (antd `darkAlgorithm`, `cssVar`, `kaColors`/`kaCategoryAccents`) **stays the palette source of
truth**. DS2 adds, never replaces:
- **`ds2/tokens.css`** — a `--ka-*` layer: neutrals/gold/semantic + category accents **mirrored from
  theme.ts**, plus the DS2 additions (`--ka-gold-tint/-line`, the `--ka-seal` gradient, the
  `--admin`/`--product` density scopes). No new colour, no light mode, no theme change.
- **`@/ds2` barrel** — imports the tokens and re-exports the existing display/data helpers (StatusTag,
  formatMoney/Hkt, personName, useResource, mutate) — **not reinvented** — plus the DS2 components.
- Components **compose antd** where it has the primitive (Table→ZebraTable, Segmented→FormLanguageSwitcher,
  Tag→StatusTag) and are **bespoke CSS-on-`--ka-*`** only for the non-antd visuals (Seal, StateBadge,
  ProgressRing, SubPanel, WizardRail). darkAlgorithm-native by construction (the tokens mirror what antd
  renders).

**One-palette reconciliation (found + fixed in STEP 2):** `index.css` already carried a `--ka-*` utility
layer using `--ka-muted-fg`; STEP 1 had used `--ka-fg-muted`. Aligned DS2 to the existing name (no
`index.css` change) so the two layers are one coherent palette.

## 2. The two executable guards (enforcement, not prose)

Both are wired into `npm run build` — they fail CI, they are not documentation.

- **Anti-drift** (`scripts/ds2-tokens-check.mjs`): parses the `--ka-*` colour tokens and asserts each equals
  its `theme.ts` value. STEP 2 **strengthened** it to pin **both** `--ka-*` layers — `ds2/tokens.css` AND
  `index.css` `:root` — to theme.ts, so they can never diverge from the source or each other. It reads only
  the `:root` block, ignoring `index.css`'s intentional `prefers-contrast` a11y overrides (v2.1 §19) — an
  earlier naive pass flagged those as drift; scoping to `:root` fixed the false positive.
- **Import-guard** (`scripts/ds2-import-guard.mjs`): walks all `src/**` and fails if any file **outside an
  ALLOWED list** imports `@/ds2`. This makes **adoption a deliberate, per-card allowlist entry** — a rollout
  card adds its surface to ALLOWED in its own gated slot; **accidental early adoption is impossible**.
  ALLOWED at spec-complete = `{ src/ds2/, src/pages/Ds2Gallery.tsx }` — the library and the one DEV adopter.

## 3. The changes-nothing-yet proof (Ruling 2) — across all three steps

The load-bearing guarantee that landing the root did **not** silently restyle the money / consent /
child-data surfaces:
- **theme.ts and index.css unchanged** across all three steps (mirrored, never modified).
- The **only** existing app file touched is `main.tsx` (STEP 2) — a **DEV-gated** `/ds2-gallery` route, like
  the Style Guide, **dead-code-eliminated from production**. STEP 1 and STEP 3 touched no existing app file
  outside `ds2/`.
- The prod bundle carries **no DS2 code** (`ls dist/assets | grep -iE 'ds2|gallery|atom|structure'` → NONE).
- The import-guard proves **0 external importers** of `@/ds2`.
- ⇒ Every existing surface renders **byte-identical**. The library is **inert** until a surface adopts it.

## 4. Rule-enforcement by API shape (the design principle)

Each component makes its rule **un-violatable** because the API offers no prop to violate it — structured
data in, never a free-text prose slot.

| Component | Enforces | How the API forbids the violation |
|---|---|---|
| **Attest** *(the honesty core)* | **D5** | A **two-sided discriminated union**: `AttestCommon` has **no prose/description prop** (cannot narrate); `AttestAttested` **requires** `onViewRecord`+`viewRecordLabel` (the audited record is always reachable — cannot bury); `AttestAction` **forbids** them via `onViewRecord?: never` (a pending state cannot smuggle a record). **The type system enforces D5** — violating either side fails `tsc`. |
| StatusAtom | D5 hierarchy | separate loud `status` + demoted `detail` slots; no single narrating-text prop |
| StatChip · MetaChip · DatedBadge | D7 | data-as-atoms — a `value`/`date` + a short label, never a sentence |
| StateBadge · ProgressRing · Seal | D7 | a `state` enum / `value`+`total` numbers → a visual; the only text is an a11y label |
| **SubPanel · ZoneStack** | **D6** | shade + gap; **no border/divider prop exists** (cannot cage) |
| **ZebraTable** | **D6** | alignment is driven by column `type` (money→right, no per-column border option) — cannot misalign money or draw a spreadsheet grid |
| **WizardRail** | **D2/D7** | steps take a `state` **enum**; counts are **computed** — no caption prop, so "Needs X" / "In progress" prose is un-passable |
| **FormLanguageSwitcher** | **D1/D7** | it is the **only** trilingual primitive exported — there is no per-field trilingual component to import, so per-field EN/繁/简 triplets are **un-buildable** |

i18n-native throughout: all textual content arrives as already-translated `ReactNode` (caller owns `t()`);
the primitives hold no hardcoded strings. The DEV gallery's developer-facing labels are excluded from the
hardcoded-string scan (dead-code-eliminated, never shipped).

## 5. Terminology copy-standard (rides the rollout)

Recorded, not applied here: **成團 → "Team Formation" (EN)** adopts **when a surface is restyled in its own
slot** (one touch per surface, terminology + DS2 together — never a bulk find-replace); the zh locales get a
proper Chinese term proposed per surface, not a mixed "Team 成團"; **committed AUDITs keep 成團** (the
no-rewrite rule). This card ships **no page copy**.

## 6. Exit gate (every step)

```
$ npm run build   # = i18n:check && ds2:check && tsc -b && vite build && bundle-budget
  i18n:check    PASSED — 675 / 675 / 675, parity complete
  ds2:tokens    PASSED — 17 --ka-* mirror theme.ts (+ index.css :root cross-checked), no drift
  ds2:import-guard PASSED — 0 external importers of @/ds2; every built surface byte-identical
  tsc -b        clean (generics + the Attest discriminated union)
  vite build · bundle-budget PASSED
$ ls dist/assets | grep -iE 'ds2|gallery|atom|structure'   → NONE (no DS2 code in production)
$ php artisan reconcile:run                                 → 58 / 58 (frontend-only; battery untouched)
```
**Verdict: PASS.** 0 product-surface changes · 0 backend · 0 migrations · battery 58/58.
Each step's VERIFY + diff + gallery screenshot are in `~/Downloads/DS2-STEP{1,2,3}-*` (uploaded per step).

**Honest note (STEP 2, viewing the DEV gallery):** `authFetch` redirects to `/login` on a 401, so a real
Sanctum token was minted for a seeded user to open the DEV route, then **revoked** each time (revoked=1);
reconcile re-confirmed 58/58 after. No demo data persisted.

## 7. Invariant check

| Discipline | State | Evidence |
|---|---|---|
| Additive / changes-nothing (Ruling 1+2) | held | theme.ts+index.css unchanged; import-guard 0; prod DS2-free; every surface byte-identical |
| Single palette source | enforced | anti-drift pins both `--ka-*` layers to theme.ts (build-wired) |
| Deliberate adoption | enforced | import-guard ALLOWED = {ds2/, gallery}; adoption is a per-card allowlist edit |
| D1–D7 | enforced by API shape | §4; Attest by the type system |
| Reconciliation battery | untouched | 58/58 (frontend-only) |

## 8. Hand-offs forward
- **First rollout card: THE THREE ANCHORS FOR REAL (wizard, Payments, My Children)** — build the anchor
  screens as production, validating the DS2 components against the three hardest pattern-families
  (trilingual + step-flow · dense money/SoD · airy product) before any other page depends on them. Each
  adopts `@/ds2`, **adds its surface to the import-guard ALLOWED list**, and applies 成團 → "Team Formation"
  where it touches team surfaces.
- Subsequent rollout cards restyle the remaining surfaces in the ruled dependency order, one gated slot each.
- The DEV `/ds2-gallery` is the standing visual-regression + enforcement reference for every rollout card.
