# PROPOSED — DS2 Component Library (the root every page-restyle imports)

**Think-first. Plan only — no code, no commit.** First card of the DS2 rollout. Turns the blessed anchor
prototypes (`dda3690`) into a **real shared component library** so the rollout is *"apply the components,"*
not *"re-implement the design 20 times."* The design rules **D5** (honesty — evidential facts preserved),
**D6** (zones-not-cages), **D7** (visual-first, prose-last) are **binding and ENFORCED by the components**,
not left to per-page discipline.

> Source of truth: `docs/sprints/S-UX-POLISH/prototypes/{ds2.css, anchor-prototypes.html}` (the VISUAL
> truth) + `AUDIT-AND-DIRECTION.md` (the rules D1–D7). Integration truth: `web/src/theme/theme.ts`,
> `web/src/display/status.tsx`, `web/src/index.css`, the `components/` + `display/` + `api/` layout.

---

## 0. Headline — what this card is (and is NOT)

- **IS:** a shared library at **`web/src/ds2/`** with one import root (`@/ds2`) — the atom kit, the structure
  primitives, the token layer, and a **dev-only gallery** to view them. Additive: **new files only.**
- **IS NOT:** a restyle of any built surface. **Zero production pages change in this card.** Per-surface
  restyle cards come *after*, each importing `@/ds2`. (Aligns with Leo's note: the functional-baseline tags
  are laid before this BUILD begins; this card is additive on top of that baseline.)
- **The prototype is the VISUAL truth, not the code truth.** Production components **compose antd + DS2
  tokens** (keeping darkAlgorithm theming, a11y, behaviour) — they do **not** copy the prototype's raw
  `.btn/.tag/.input` CSS. The prototype's hand-CSS was a prototyping convenience; the library realizes the
  same look through antd where antd has the primitive, and bespoke CSS only where it doesn't (ProgressRing,
  StateBadge, the seal, density scopes).

---

## 1. Component inventory (from the prototypes)

### 1a. The ATOM KIT (mostly bespoke; pure-visual; reuse `StatusTag`)
| Component | API (props) | Renders | Rule enforced | Basis |
|---|---|---|---|---|
| **StatusAtom** | `status: ReactNode` (loud), `detail?: ReactNode` (muted), `icon?`, `tone?: 'default'\|'attested'\|'action'` | a **loud display-weight headline** + a **demoted `subatom`** under it; hierarchy baked in — you *cannot* make them equal weight | **D5** (hierarchy) | Typography composition |
| **StatChip** | `value: string\|number`, `label: ReactNode` | `[**value** label]` compact stat (tabular-nums) | D7 (data-as-atom) | bespoke span |
| **MetaChip** | `icon?`, `children` | small meta pill (session time, location, "Starts 2 Sep") | D7 | bespoke span |
| **StateBadge** | `state: 'sealed'\|'action'\|'ok'\|'warn'`, `size?` | avatar-corner **seal/warn badge + ring** | D7 (state as visual) | bespoke |
| **ProgressRing** | `value: number`, `total: number`, `label?` | conic-gradient **ring** with `n/total` | D7 | bespoke (no antd equiv) |
| **DatedBadge** | `date: string` (ISO), `labelKey: string`, `locale` | a chip: icon + **localized date** ("Signed 28 Jul") — takes a **date value, never a sentence** | **D5+D7** (the evidential fact as an atom) | bespoke, uses `display/date` |
| **Seal** | `size?` | the gold attestation seal (the D3 signature) | D3 | bespoke |
| **StatusTag** *(reuse existing)* | `domain`, `value` | the status pill (i18n label + colour) — **already in `display/status.tsx`**, re-exported from `@/ds2` | D7 | antd `Tag` |

### 1b. The STRUCTURE primitives (enforcement-critical; wrap antd)
| Component | API (props) | Renders | Rule enforced | Basis |
|---|---|---|---|---|
| **ZoneStack** | `children: SubPanel[]`, `density?` | a vertical stack of sub-panels with **gap** — the D6 separator | **D6** (vertical) | flex container |
| **SubPanel** | `tone?: 'neutral'\|'attested'\|'action'`, `children` | a **shade-banded zone** (no border, no box-in-box) | **D6** | styled div |
| **Attest** | `atom: {status, detail?}`, `dated?: {date, labelKey}`, `onViewRecord?`, `action?` | the consent/attestation bar: **StatusAtom + optional DatedBadge + View-record link + action** — the API takes **structured facts**, so a prose reassurance is *impossible* to pass | **D5+D7** (fact-as-atom, detail on demand) | SubPanel(attested) + atoms |
| **ZebraTable** | `columns` (with per-column `type: 'text'\|'money'\|'status'\|'action'`), `data`, `zones` | antd Table + **zebra row-banding + column-type alignment + defined Status/Action zones**, no column rules | **D6** (horizontal) | wraps antd `Table` |
| **WizardRail** | `phases: {title, steps:{label, state, locked?}}[]` | the dependency-aware step rail — **state by icon+colour only** (done/current/wip/blocked/deferred/optional), **no captions accepted** | **D2+D7** | bespoke |
| **FormLanguageSwitcher** | `value: {en,tc,sc}` per field via context, `fields`, `onChange`, `requiredLangs` | ONE **form-level** switcher (completeness dots) + single inputs bound to the active content-language; **no per-field triplet component exists to import** | **D1+D7** | antd `Segmented` + Form context |

**One import root.** `@/ds2` (a barrel) re-exports all the above **plus** the existing display helpers
(`StatusTag`, `formatMoney`, `formatHkt`, `personName`) and `useResource`/`mutate`. A page imports design
from exactly one place; there is no second way to render a status, a zone, or a trilingual field.

---

## 2. How the components ENFORCE the rules (by construction, not discipline)

The enforcement principle: **APIs accept STRUCTURED DATA, never free-text prose.** A page author cannot
violate a rule because the component gives them no prop to do it with.

- **D1 (trilingual)** — `FormLanguageSwitcher` is the **only** trilingual primitive exported. There is **no
  `TrilingualField` / per-field tab** component to import → per-field triplets are impossible. Completeness
  dots + `requiredLangs` make the OD-19 empty-state structural, not a page's job.
- **D5 (honesty + hierarchy)** — `StatusAtom` has separate `status` (loud) and `detail` (muted) slots →
  equal-weight prose can't be authored. `Attest` takes a `dated: {date}` (a **value**) + `onViewRecord`
  (the on-demand detail) — you pass the **fact**, the component renders the atom and the record link; there
  is **no free-text reassurance prop**, so "sealed and audited…" as a sentence cannot be written, yet the
  evidential date and the record link are always present. Honesty preserved by shape.
- **D6 (zones-not-cages)** — stacking content sections = `ZoneStack` of `SubPanel`s (shade + gap, no
  borders); a dense table = `ZebraTable` (zebra + column-type alignment). The separation is in the
  component, so a page can't produce a bleeding stack or a column-rule spreadsheet.
- **D7 (visual-first)** — `WizardRail` steps accept a `state` enum (icon+colour), **not** a caption string →
  "Locks when published" / "In progress" prose can't be added. `StateBadge`, `Seal`, `ProgressRing`,
  `StatChip`, `MetaChip`, `DatedBadge` are the vocabulary for "show, don't narrate." No component exposes a
  "subtitle/description" slot for narrating prose.

**The gallery is the guard.** The dev-only DS2 gallery renders every component in every state; it doubles as
the visual-regression anchor and the "is this rule still enforced?" reference. (A lint rule can later flag
raw `<table>`/hand-rolled trilingual inputs in `pages/`, but the primary enforcement is "there's no other
component to import.")

---

## 3. Tokens & theme — DS2 **extends** v2.1, does not replace it

**v2.1 stays. `theme.ts` remains the ConfigProvider source of truth** (`kaColors`, `kaCategoryAccents`,
`kaTheme` with `algorithm: darkAlgorithm`, `cssVar: true`). DS2 adds a **token layer**, it does not fork the
palette.

- **`web/src/ds2/tokens.css`** — a single `:root` exposing **`--ka-*`** custom properties: the neutrals /
  gold / semantic colours **mirrored from `kaColors`** (one value, one place) **plus the DS2 additions**
  from the prototype: `--ka-gold-tint`, `--ka-gold-line`, the `--ka-seal` gradient, and the **density
  scales** (`--admin` / `--product` scopes: `--pad/--gap/--row/--page-x/--stack`). DS2 component CSS
  references `--ka-*` only.
- **Anti-drift:** a unit test asserts every colour `--ka-*` equals its `kaColors` counterpart, so
  `tokens.css` can never silently diverge from `theme.ts`. (The prototype's standalone `ds2.css` `--bg/…`
  becomes this `--ka-*` layer; the prototype values already match `kaColors`.)
- **Migration:** built surfaces keep using antd + `kaTheme` unchanged. DS2 pages *also* read `--ka-*`. When a
  surface is restyled to DS2, it swaps its ad-hoc `.ka-*`/inline styles for DS2 components — no theme change,
  no palette change, no light mode (client decision stands). v2.1 §-numbers gain DS2 subsections (D1–D7);
  the DS2 spec is the "how it's built" companion to v2.1's "what it is."

---

## 4. darkAlgorithm integration

- DS2 components **sit on the existing antd theme**: `ConfigProvider theme={kaTheme}` (darkAlgorithm,
  `cssVar`) already wraps the app. Components that wrap antd (`ZebraTable`→Table, `FormLanguageSwitcher`
  →Segmented/Form, buttons→`ka-cta`, pills→`StatusTag`/Tag) inherit the theme automatically.
- Bespoke components (Seal, StateBadge, ProgressRing, SubPanel, WizardRail) are **CSS-only over `--ka-*`**,
  which mirror the same palette antd renders → they match by construction, in one theme, dark-only.
- No component introduces a colour outside the token set; category accents come from `kaCategoryAccents`.
- Charts remain the separate `kaChartTheme` concern (v2.1 §7) — out of DS2's scope.

---

## 5. Trilingual / i18n-native (matches the built discipline)

- **No hardcoded user-facing strings.** Every built-in bit of chrome (the switcher's "Editing" label, the
  "简 incomplete" warning, a default "View record", empty/loading text) comes from **i18n keys** via
  `useTranslation`; content passed *into* components is an already-translated `ReactNode` (caller owns
  `t()`), matching how `StatusTag` (labelKey in the registry) and the pages already work. Keys are
  **whole sentences**, never concatenated fragments (v2.1 §18).
- **Two languages, kept distinct:** the **UI display language** (i18n locale — EN/繁/简 chrome) vs the
  **content languages** a `FormLanguageSwitcher` edits (the `_en/_tc/_sc` field *values*). The component
  must never conflate them: the switcher edits content values while its own chrome renders in the user's UI
  locale.
- The **i18n:check parity gate stays green** — any new keys ship in all three locales; the gallery exercises
  every key.

---

## 6. Build split + depth (the ROOT — reviewed carefully once)

Mixed depth: **line-by-line for the token layer and the enforcement-critical primitives; frontend-scan for
the pure-visual atoms** — because everything imports this, it is reviewed carefully, once.

- **STEP 1 — TOKENS/THEME (LINE-BY-LINE).** `ds2/tokens.css` (`--ka-*` mirror + DS2 additions + density
  scopes) + the anti-drift test + the `@/ds2` barrel skeleton. Nothing renders right without this; it's the
  foundation. Reviewed line-by-line (it's the root of the root).
- **STEP 2 — ATOM KIT (FRONTEND-SCAN) + gallery-1.** StatusAtom, StatChip, MetaChip, StateBadge,
  ProgressRing, DatedBadge, Seal; re-export StatusTag. A dev-only **`/ds2-gallery`** page (DEV-gated like the
  existing StyleGuide) renders each in every state. Pure visual → frontend-scan.
- **STEP 3 — STRUCTURE primitives (LINE-BY-LINE) + gallery-2.** ZoneStack, SubPanel, Attest, ZebraTable,
  WizardRail, FormLanguageSwitcher. These *enforce* D1/D5/D6/D7 → line-by-line (the enforcement is the whole
  point). Gallery gains the structure demos (a mini wizard, a zebra table, a zoned card, a language
  switcher).

Each step: `tsc` clean · `npm run build` (bundle budget) · i18n parity green · gallery renders (one
screenshot per gallery step, since the gallery *is* a new visual surface). **0 migrations, 0 backend, 0
built-page changes.** The reconciliation battery is untouched (frontend-only). Commit HELD per step.

**Why not one batch:** it's the design root; the token layer and the four enforcement primitives deserve
line-by-line eyes once, so every downstream restyle card can then be frontend-scan "apply the components."

---

## 7. Scope boundaries (explicit)

- **No built surface is restyled here.** Additive library + dev gallery only. `AppShell`, pages, RLS,
  reads/writes — untouched.
- **No new capability, no schema, no backend.** Pure presentation library.
- **Charts, mobile shell, PWA** — already built (v2.1 §7/§17), out of scope; DS2 may later provide chart/
  mobile atoms as follow-ons.
- **Per-surface restyle cards come after**, in a ruled order (likely: Wizard → dense-admin → product/portal
  → public), each importing `@/ds2` at frontend-scan depth.

---

## 8. Open questions for Leo

1. **Location** — `web/src/ds2/` as a dedicated library dir with a `@/ds2` barrel (recommended), or fold
   into the existing `components/` + `display/`?
2. **Anti-drift** — the `--ka-*` ↔ `kaColors` **sync test** (recommended), or generate `tokens.css` from
   `theme.ts` at build time?
3. **Gallery** — extend the existing dev-only **StyleGuide** page, or a new **`/ds2-gallery`** route (both
   DEV-only, dead-code-eliminated from prod)?
4. **antd-wrap vs bespoke** — confirm the principle: **wrap antd wherever it has the primitive** (Table,
   Segmented, Form, Tag, Button), **bespoke only for the non-antd visuals** (Seal, StateBadge, ProgressRing,
   SubPanel, WizardRail).
5. **Step split** — the 3-step split above (tokens → atoms → structure), or tokens+atoms together then
   structure?
6. **ZebraTable base** — wrap antd `Table` (keeps sort/pagination/sticky, recommended), or a lighter bespoke
   table for the densest finance views?

---

### One-line recommendation

Build DS2 as **`web/src/ds2/`** with a single `@/ds2` barrel, in **three steps** — tokens/theme
(line-by-line) → atom kit (frontend-scan) → structure primitives (line-by-line) — each with a dev-only
gallery. The library **extends** v2.1 (`theme.ts` stays the palette source; `--ka-*` mirrors it + adds the
DS2 tokens), **composes antd** (not raw CSS), is **i18n-native**, and **enforces D1/D5/D6/D7 by API shape**
so downstream restyles are "apply the components," reviewed once because it's the root everything imports.
No built surface changes in this card.
