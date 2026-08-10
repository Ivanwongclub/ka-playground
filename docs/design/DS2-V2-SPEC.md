# DS2 v2 — Design Spec (R0)

> **Status: DRAFT for external review. No code in this card.** This document is the design
> foundation for the full UI/UX revamp (Layers A + B + C, all personas, dark + gold enriched).
> Nothing is built here; build cards are cut *after* this spec is approved.
>
> **Precedence:** DS2 v2 is **additive on Design System v2.1** and does not replace it. Where this
> spec and `docs/design/DESIGN-SYSTEM.md` (v2.1) touch the same rule, v2.1 wins until a build card
> supersedes it. The palette single-source remains `web/src/theme/theme.ts`.

---

## 0. Scope & non-goals

**In scope:** new DS2 primitives (PageCard/AuthCard, HeroBanner, TaskCard, EmptyState, one
consolidated StatCard), an urgency language, a documented mobile strategy, testable layout
conventions, density/persona scopes, additive tokens, and the governance that carries the v1
allowlist model forward.

**Out of scope (hard constraints, do not propose in any build card):**
- **No light mode, no theme toggle** (client decision, 23 Jul 2026). `darkAlgorithm` only.
- **No palette change.** `theme.ts` `kaColors` / `kaCategoryAccents` are frozen.
- **No new colours** beyond **tint / line / gradient derivatives** of the existing tokens
  (e.g. a `rgba()` of `--ka-danger`). Every new token in §6 is such a derivative or a
  non-colour value (radius, width, gradient, layout).
- **`ds2-tokens-check.mjs` must still pass unchanged** — the 17 drift-checked colour tokens are not
  touched; all v2 tokens are DS2-owned additions with no `theme.ts` counterpart (§6).
- **Interactive AntD components are never converted** (the M4 ruling, carried forward verbatim — §7).

---

## 1. Ground truth (DS2 v1 as it stands)

Reviewers' baseline — this is what exists today.

**Tokens (`ds2/tokens.css`, `:root`):** neutrals/gold/semantic mirror `theme.ts` and are drift-checked
(`--ka-bg #0F0B15`, `--ka-card #1A1326`, `--ka-muted #1E1729`, `--ka-fg #F4F4F5`, `--ka-muted-fg #A1A1AA`,
`--ka-gold #C9A962`, `--ka-gold-hover #D4B876`, `--ka-border #2A2235`, `--ka-border-strong #726889`,
`--ka-success #22C55E`, `--ka-warning #FBBF24`, `--ka-danger #EF4444`; category accents language/stem/
arts/maths/featured). DS2-owned, **not** drift-checked: `--ka-gold-tint`, `--ka-gold-line`, `--ka-seal`.
Type (`--ka-font-display/body/mono`), radius (`--ka-r-sm 6 / -md 8 / -lg 10 / -xl 16`), shadow
(`--ka-shadow-sm/md/gold`), density (`--ka-pad/gap/row/page-x/stack`, `[data-density="admin|product"]`).

**Atoms (`ds2/atoms.tsx`):** `Seal`, `StateBadge(state, title)`, `StatusAtom(status, detail, icon, tone)`,
`StatChip(value, label)`, `MetaChip(icon, children)`, `ProgressRing(value, total, label)`,
`DatedBadge(label, date, locale, icon)`. Rule enforced by API shape — structured props, no free-text slot.

**Structure (`ds2/structure.tsx`):** `SubPanel(tone, children)`, `ZoneStack`, `StatCard(label, value, icon,
accent, alert, seal, sub)`, `Attest`, `ZebraTable`, `WizardRail`, `FormLanguageSwitcher`.

**Bespoke `.ka-*` layer (`index.css`) — the un-DS2'd surfaces:** the mobile shell family
(`.ka-mobile-shell/-header/-avatar/-title/-content`, `.ka-tabbar(-item/-label)`, `.ka-drawer-usercard`,
`.ka-sheet(-scrim/-handle/-header/-body)`), the public-tier cards (`.ka-login`+`-form`+`-hero`+`-quote`,
`.ka-register`/`.ka-pay` wrappers, `.ka-register-card` `width:min(560px)`, `.ka-pay-card`
`width:min(460px)`), `.ka-empty`, `.ka-route-loading` (shimmer), `.ka-cta`, `.ka-dash-kpi`.

**Governance:** `ds2-import-guard.mjs` (only `ds2/`, the dev gallery, and per-card allowlisted adopters
import `@/ds2`) and `ds2-tokens-check.mjs` (colour tokens mirror `theme.ts`, no drift).

### The five findings this spec answers

| # | Finding (from the rollout + P1/P2/M-tier work) | Answered by |
|---|---|---|
| **F1** | DS2 has **no standalone top-level card**. `SubPanel` is a *zone* whose translucent bg (`rgba(255,255,255,0.035)`) assumes a darker panel behind it; on a bare page it renders wrong. The public tier (P1/P2) was therefore a forced **no-op**. | **PageCard / AuthCard** (§2.1) |
| **F2** | `StatCard` exists **twice** — the DS2 `structure.tsx` one (30px value, `sub` slot) and Payments' **local** `StatCard(count, unit, value)` (26px). Drift risk; the 26px/30px split is unresolved. | **StatCard consolidation** (§2.5) |
| **F3** | **No consistent urgency treatment.** `consent.expires_at`, `order.payment_due_at`, `approvals.threshold_days` each render ad hoc; nothing signals *expiring / overdue / SLA-breach* uniformly. | **Urgency language** (§3) |
| **F4** | The **mobile shell is a bespoke, undocumented layer** outside DS2 (`ka-mobile-*`, `ka-tabbar`, `ka-sheet`), with no rules for Steps/Drawer/table degradation under 768px. | **Mobile strategy** (§4) |
| **F5** | **Empty / loading / detail / table idioms are inconsistent** across surfaces — `ka-empty` is CSS-only, some loads use `Spin`, some lists have headerless columns, detail views vary. | **EmptyState** (§2.4) + **Conventions** (§5) |

---

## 2. New primitives

Each primitive is specified as **Purpose · API shape · Token usage · Adoption rules · MUST NOT**.
All primitives follow the v1 atom contract: **i18n-native** (all text arrives as already-translated
`ReactNode`; the primitive holds no hardcoded string), styled only through `--ka-*` tokens, dark-native.

### 2.1 `PageCard` (and `AuthCard` preset) — closes F1

**Purpose.** The top-level, standalone, width-constrained card that `SubPanel` is *not*: a **solid**
`var(--ka-card)` surface that sits directly on the page background (no parent panel). It generalises the
bespoke public-tier `.ka-register-card` / `.ka-pay-card` / login form into one DS2 primitive so the
public tier (and any future standalone surface — a focused wizard, an interstitial, a 404) becomes
DS2-native. **This is the missing top-level container that made P1/P2 a no-op.**

**API shape.**
```ts
type PageCardWidth = 'auth' | 'form' | 'wide' | number;  // 380 / 460 / 560 / custom px
function PageCard(props: {
  width?: PageCardWidth;        // default 'form' (min(460px,100%))
  children: ReactNode;
  center?: boolean;             // default true — centres the card in a min-height:100dvh column
  elevated?: boolean;           // default true — --ka-shadow-md; false = flat (embedded)
}): JSX.Element;

// Preset — the auth/public surface. Fixes header (logo + optional LocaleSwitcher) + width + centring.
function AuthCard(props: {
  logoAlt: ReactNode;
  localeSwitcher?: boolean;     // default true (public pages carry it)
  width?: PageCardWidth;        // default 'auth' for pay, 'form' for register (caller sets)
  children: ReactNode;
}): JSX.Element;
```

**Token usage.** Background `var(--ka-card)` (solid — the key difference from SubPanel); border
`var(--ka-border)`; radius `var(--ka-r-xl)`; shadow `var(--ka-shadow-md)` when `elevated`; padding
`var(--ka-pad)` scaled by density; widths from `--ka-w-auth/-form/-wide` (§6). The centring column uses
`min-height:100dvh` + `background:var(--ka-bg)` (mirrors `.ka-register`/`.ka-pay`).

**Adoption rules.** Migrates the three public cards: `Register.tsx` (`.ka-register-card` → `AuthCard
width="form"`), `Activate.tsx` and `PublicPay.tsx` (`.ka-pay-card` → `AuthCard width="auth"`). `Login.tsx`
keeps its bespoke split-screen hero but its **left column** may adopt `AuthCard elevated={false}` (the
form panel already has its own bg). Each migration is its own build card with a **byte-identical behaviour
proof** on the auth/pay/activation chains (public-money + consent-critical — treat as M-tier / P-tier
strictness). The bespoke `.ka-*-card` CSS is retired **only** when its last consumer migrates.

**MUST NOT.** Not a zone inside another panel (that is `SubPanel`). Not a replacement for `SubPanel`
inside the app shell. Never used to wrap an entire admin console body (those are `SubPanel` sections in
the shell). Does not own routing, auth, or form logic — it is a frame.

### 2.2 `HeroBanner` — imagery/banner surface

**Purpose.** The imagery-forward banner for the marketplace/catalogue and persona homes (a welcome band,
a programme feature strip). Carries an image with the §11.2 aubergine **duotone scrim** (generalised from
`.ka-login-hero::before`) and foreground content (title, subtitle, optional CTA).

**API shape.**
```ts
function HeroBanner(props: {
  image: { src: string; alt: ReactNode };
  height?: 'band' | 'tall';     // band = 180px, tall = 320px; default 'band'
  scrim?: 'duotone' | 'bottom' | 'none';   // default 'duotone'
  children: ReactNode;          // foreground content (Title/Paragraph/CTA)
  fallback?: ReactNode;         // rendered if the image fails / while loading (see below)
}): JSX.Element;
```

**Image-loading / fallback behaviour (required).** (1) The container reserves its height *before* the
image loads (no layout shift) using the `height` token, with `background:var(--ka-muted)` as the base.
(2) While loading, the base shows the `.ka-route-loading` shimmer treatment (reuse, §4). (3) On `error`
(image 404 / blocked — Artifacts/CSP, offline), the component swaps to `fallback` if given, else to a
**flat gradient** `var(--ka-scrim-flat)` (§6) with the foreground content still legible. (4) Foreground
text contrast is guaranteed by the scrim, not the image — text remains ≥ AA on the scrim alone. (5)
`loading="lazy"` for below-the-fold heroes; `decoding="async"`.

**Token usage.** Scrim `var(--ka-scrim-duotone)` / `var(--ka-scrim-bottom)` (§6, gradient derivatives of
`--ka-card`); base `var(--ka-muted)`; heights `var(--ka-hero-band/-tall)`; radius `var(--ka-r-xl)`.

**Adoption rules.** Marketplace/catalogue header and each persona home top band. New surfaces only — no
existing surface is retrofitted in the same card that introduces it.

**MUST NOT.** Not a decorative wrapper for arbitrary content — it must carry an image (or its fallback).
Never places business-critical, must-read text *only* over live imagery (the scrim + fallback rule).
Not used for money/consent decision surfaces (those stay text-first).

### 2.3 `TaskCard` — the action-first dashboard unit

**Purpose.** The composable unit persona homes are built from: **one task, one action.** Icon, title, a
context line, an optional urgency chip, and exactly one CTA. Where `StatCard` answers "how many?",
`TaskCard` answers "what should I do next?".

**API shape.**
```ts
function TaskCard(props: {
  icon: ReactNode;
  title: ReactNode;
  context?: ReactNode;              // one demoted line (e.g. "Summer STEM · due Aug 15")
  urgency?: UrgencyLevel;           // §3 — drives the chip + card emphasis; omit = none
  urgencyLabel?: ReactNode;         // the chip text (already-translated, e.g. "Due in 2 days")
  cta: { label: ReactNode; to: string } | { label: ReactNode; onClick: () => void };
  seal?: boolean;                   // an attested/done task (rare — mostly for "complete" states)
}): JSX.Element;
```

**Token usage.** Frame like `StatCard` (a `SubPanel`-backed zone *inside* a persona-home grid — the home
page itself is the panel, so a zone bg is correct here, unlike PageCard). Icon in `var(--ka-gold)`; title
`--ka-font-display`; context `var(--ka-muted-fg)`; urgency chip + emphasis from §3 tokens; CTA uses
`.ka-cta` (gold) for the primary task, secondary tone otherwise.

**Adoption rules.** The atom of every persona home (student / guardian / teacher / school-admin /
academy-ops / member). A home is a responsive grid of `TaskCard` + `StatCard` + one optional `HeroBanner`.
`data-density="product"` for the family homes (§6).

**MUST NOT.** Never more than one CTA (if a task needs two actions, it is two TaskCards or a detail view).
No free-text body/prose slot (`context` is one line). Not a table row substitute — lists of records use a
table/`ZebraTable` that degrades to a list (§4), not a wall of TaskCards.

### 2.4 `EmptyState` — promotes `.ka-empty` — closes F5

**Purpose.** The one designed empty/zero surface: icon + message + optional sub + optional single CTA.
Replaces the CSS-only `.ka-empty` and the scattered `<Empty>` usages so every persona home and every list
shows the same considered zero-state (and can offer the *next action* rather than a dead end).

**API shape.**
```ts
function EmptyState(props: {
  icon?: ReactNode;                 // default: a quiet DS2 glyph
  message: ReactNode;               // the headline (translated)
  detail?: ReactNode;              // one muted sub-line
  cta?: { label: ReactNode; to: string } | { label: ReactNode; onClick: () => void };
  size?: 'inline' | 'page';         // inline = a table/section empty; page = a full route empty
}): JSX.Element;
```

**Token usage.** Layout from the `.ka-empty` rules (centred column, 64px pad for `page`, 24px for
`inline`); icon `var(--ka-muted-fg)`; message `var(--ka-fg)` 18px `--ka-font-display`; detail
`var(--ka-muted-fg)` 13px; CTA `.ka-cta`.

**Adoption rules.** The `locale.emptyText` of every DS2/AntD table becomes `<EmptyState size="inline">`;
every route that can be empty renders `<EmptyState size="page">` inside its `DataBoundary` empty branch.
`.ka-empty` retires when its last consumer migrates.

**MUST NOT.** Not an error surface (errors keep the `Alert`/`DataBoundary error` path — an empty result is
not a failure). Not used to hide a permission denial (a 403 is surfaced, per the shown-not-hidden rule).

### 2.5 `StatCard` consolidation — closes F2

**Purpose.** **One** `StatCard` family, absorbing the DS2 `structure.tsx` primitive **and** the local
`Payments.tsx` variant. The Payments `count + unit + value` shape becomes **first-class props** (rather
than a hand-assembled `sub={<StatChip/>}`), so both call sites share one component and one CSS rule.

**Consolidated API shape** (superset — additive to the current DS2 signature, so `Dashboard.tsx`'s
existing calls compile unchanged):
```ts
function StatCard(props: {
  label: ReactNode;
  value: ReactNode;                 // count OR a formatted money string — caller formats (money = formatMoney)
  icon?: ReactNode;
  accent?: 'default' | 'gold' | 'warn';
  alert?: boolean;                  // danger value colour + action-tone zone
  seal?: boolean;
  // NEW — absorbs Payments' shape; when present, renders the StatChip sub-line internally:
  count?: number;
  unit?: ReactNode;
  // sub stays as the escape hatch for a non-count sub-line; count/unit take precedence when both given
  sub?: ReactNode;
  // NEW — absorbs the .ka-dash-kpi clickability wrapper; drill-down is built in (see §5 R1):
  to?: string;                      // navigate on click/Enter/Space
  onClick?: () => void;             // or a handler; `to` and `onClick` are mutually exclusive
}): JSX.Element;
```
`count` + `unit` render exactly today's `<StatChip value={count} label={unit} />` in the `.ds2-statcard__sub`
slot — so Payments no longer hand-builds it.

**Clickability is built in (absorbs `.ka-dash-kpi`).** When `to` or `onClick` is set, the StatCard is
itself the interactive element — `role="button"`, `tabIndex={0}`, Enter/Space activation, a visible
`:focus-visible` gold outline, and the hover lift — the exact behaviour `Dashboard.tsx` today hand-wires
in a `.ka-dash-kpi` wrapper `<div>`. The `.ka-dash-kpi` CSS moves into the StatCard's own rule and the
wrapper divs are **deleted** in the consolidation card. A StatCard with neither `to` nor `onClick` is a
plain (non-interactive) figure. **This is the mechanism convention R1 (§5) mandates** — a StatCard's
number reaches its rows through its own `to`/`onClick`, not a bolted-on wrapper.

**Value size ruling (resolved).** **30px is the single standard** (`.ds2-statcard__value`). Payments
migrates **up** from 26px to 30px. **No 26px variant is added.** Money values keep `tabular-nums` (already
in the rule) and are passed as pre-formatted strings via `value` (never a raw number for money).

**Migration — both call sites.**
- `Dashboard.tsx`: each metric passes `to={m.to}`; the surrounding `.ka-dash-kpi` wrapper `<div>` (with
  its hand-wired `role="button"`/`tabIndex`/`onKeyDown`/`onClick`) is **deleted** — the StatCard is now the
  interactive element. The `.ka-dash-kpi` CSS block in `index.css` retires with it (its `__value` comment
  already points at the DS2 rule).
- `Payments.tsx`: delete the local `function StatCard`; the 3 call sites become
  `<StatCard label=… value={formatMoney(...)} count={n} unit={t(...)} accent="warn|gold" seal? />`.
  Its local `import { Seal, StatChip }` for that component is dropped. This is a **money-tier** change:
  the money `value`, `count`, and all labels render **byte-identical** in content (only the size moves
  26→30px, which is the intended visual change) — a build card ships it with the M-tier discipline.

**Token usage.** Unchanged from v1: `SubPanel` frame (action tone when `alert`), `MetaChip` header (+`Seal`
when `seal`), `.ds2-statcard__value` (+`--gold/--danger/--warn`), `.ds2-statcard__sub`. When interactive
(`to`/`onClick`): `cursor:pointer`, hover lift (`translateY(-1px)` + `--ka-shadow-md`), and
`:focus-visible` outline `2px solid var(--ka-gold)` — the `.ka-dash-kpi` rules, now owned by the StatCard.

**MUST NOT.** Not a `TaskCard` (StatCard has no CTA). Not used for a single free-standing number in prose
(use `StatChip`). Money `value` is always a formatted string — never raw minor units.

---

## 3. Urgency language — closes F3

**One token-level treatment** for *expiring / overdue / SLA-breach*, applied uniformly to
`consent.expires_at`, `order.payment_due_at`, and `approvals.threshold_days` (and any future deadline).

**Levels (an enum, thresholds parameterised — not hardcoded per surface):**
```ts
type UrgencyLevel = 'none' | 'soon' | 'due' | 'overdue';
// Derivation (pure, shared helper — proposed web/src/display/urgency.ts):
//   overdue : deadline < now                          → --ka-danger family
//   due     : deadline ≤ now + dueWithin              → --ka-warning family
//   soon    : deadline ≤ now + soonWithin             → --ka-gold family (advisory, not alarming)
//   none    : otherwise
// Thresholds are PARAMETERS per domain, not constants baked into the component:
//   consent  : soonWithin 7d,  dueWithin 2d
//   payment  : soonWithin 5d,  dueWithin 1d   (mirrors OD-11 hold-window feel; confirm in build card)
//   approval : soonWithin = threshold_days − 2,  dueWithin = 0 (i.e. at/over threshold = overdue)
```
The **thresholds live with the domain**, the **treatment lives with DS2** — a surface passes a computed
`UrgencyLevel` (or a deadline + a threshold config) and gets the identical chip + emphasis everywhere.

**Chip.** A `MetaChip`-shaped urgency chip: `<UrgencyChip level label />` where `label` is the
already-translated countdown/overdue text ("Due in 2 days", "Overdue by 3 days"). Colour by level:
`none`→ no chip; `soon`→ gold tint; `due`→ warning tint; `overdue`→ danger tint. Icon: a clock for
soon/due, an alert for overdue.

**Row emphasis (tables/lists).** A left **severity stripe** + subtle row tint, class-driven:
`.ds2-urgent--soon|due|overdue` sets `box-shadow: inset 3px 0 0 var(--ka-<level>-line)` and, for
`overdue` only, a `background: var(--ka-danger-tint)`. `soon`/`due` get the stripe **without** a row tint
(emphasis proportional to severity — the page doesn't light up amber for everything).

**Token usage.** `--ka-warning-tint/-line`, `--ka-danger-tint/-line`, `--ka-gold-tint/-line` (the last two
exist; the rest are §6 additions — all `rgba()` of existing semantic colours). No new hue.

**Rules (testable).** (R-U1) A surface **must not** invent its own overdue colour — it uses
`UrgencyLevel` → the DS2 treatment. (R-U2) Semantic urgency colour is **separate from the gold accent**;
a gold `StatCard` is not "urgent". (R-U3) `overdue` is the only level that tints a whole row.

**MUST NOT.** Urgency is never applied to non-deadline state (a `submitted` team is not "urgent"). Not a
substitute for `StatusTag` — status says *what state*, urgency says *how close to a deadline*.

---

## 4. Mobile strategy — closes F4

**Absorb the bespoke mobile shell into DS2 v2 as documented primitives** (no behaviour change; this is
formalisation + rules). The existing `.ka-mobile-*` / `.ka-tabbar` / `.ka-sheet` CSS becomes the styling
layer behind named DS2 components; the §17 rules become part of the design system rather than folklore.

**Primitives (wrapping the existing shell):**
- `MobileShell` = `.ka-mobile-shell` + `.ka-mobile-header` + `.ka-mobile-content` (sticky header, safe-area
  padding, 72px bottom inset for the tab bar).
- `TabBar` / `TabBarItem` = `.ka-tabbar(-item/-label)` — fixed 56px + safe-area, z-index 20; **max 5 items**
  (the flat mobile projection of `visibleLeaves`); overflow → the nav drawer.
- `BottomSheet` = `.ka-sheet(-scrim/-handle/-header/-body)` — z-index 100; the mobile stand-in for a
  side `Drawer` and for `modal.confirm` on small screens (drag handle, 16px top radius).
- `NavDrawer user card` = `.ka-drawer-usercard`.

**Responsive rules (testable):**
- (R-M1) **Breakpoint = 768px.** `< 768px` is "mobile" (matches the existing `.ka-login-hero` media query
  and AntD `md`).
- (R-M2) **`WizardRail` / `Steps` direction:** horizontal `≥ 768px`, **vertical `< 768px`**
  (`WizardRail` gains a `direction?: 'auto' | 'vertical' | 'horizontal'`, default `auto` = this rule).
- (R-M3) **`Drawer` width:** `min(560px, 100vw)` on every Drawer (the Teams detail Drawer's `560` becomes
  this token, `--ka-drawer-w`). Full-bleed on phones, capped on tablets/desktop.
- (R-M4) **Table → list degradation:** any dense table adopts a `ZebraTable`/`Table` that, `< 768px`,
  renders each row as a stacked `List.Item` (label: value pairs, the primary column as the item title,
  the action column as the item's trailing control). Column priority is declared, not guessed — a
  `priority?: 'primary' | 'secondary' | 'detail'` per column decides what survives the collapse
  (`detail` columns drop to an expandable row on mobile).
- (R-M5) `BottomSheet` replaces side `Drawer` and centre `Modal` for **confirm/detail** flows `< 768px`;
  the desktop component is unchanged (this is a projection, not a fork of the logic).
- (R-M6) **Confirm semantics are preserved when `BottomSheet` projects a `modal.confirm` on a
  money / consent / 成團 / auth decision.** The sheet renders **explicit ok and cancel buttons**; **any
  dismissal — scrim tap, swipe-down, back gesture/button — is CANCEL, never confirm** (it resolves the
  same as pressing cancel: no mutation fires). The **consequence-stating copy renders in full above the
  buttons** (never truncated behind a scroll or a "more" affordance). *Testable:* on such a sheet, only
  the explicit ok button resolves the confirm; every dismissal path routes to the cancel handler.

**Token additions:** `--ka-drawer-w: min(560px, 100vw)`, `--ka-tabbar-h: 56px`, `--ka-sheet-radius: 16px`
(formalising the literals already in `.ka-sheet`/`.ka-tabbar`).

**MUST NOT.** The mobile projection **never changes a write chain** — it re-renders the same handlers in a
sheet/list. No mobile-only endpoint, no mobile-only permission. Interactive AntD components inside the
shell are still not converted (§7).

---

## 5. Conventions (testable rules)

Written as rules a lint/review check *could* assert. Each build card must satisfy the ones it touches.

- **(R1) Every aggregate number links to its rows (drill-down).** **Mechanism:** the consolidated
  `StatCard`'s built-in `to`/`onClick` (§2.5) — the drill-down is a first-class prop carrying
  `role="button"`, keyboard activation and focus, absorbing the old `.ka-dash-kpi` wrapper rather than
  bolting navigation on around the card. *Testable:* no interactive `StatCard` renders without `to` or
  `onClick`, and no `.ka-dash-kpi`-style clickability wrapper survives the consolidation.
- **(R2) Enumerable filters render as `Select`/`Segmented`, never a free `Input`.** A filter over a known
  set (status, programme, role, language) uses a closed control. Free `Input` is only for genuine free
  text (name/email search). *Testable:* no `Input` bound to a state that is compared against an enum set.
- **(R3) No headerless table columns.** Every `columns[]` entry has a non-empty `title` **or** is an
  explicit `actions` column typed as such (a DS2 `Ds2Column` gains `type:'actions'`, which renders no
  header legitimately). *Testable:* no `{ title: '' }` outside a `type:'actions'` column. (Retrofits the
  `title:''` status/act columns in Refunds/Withdrawals/Approvals/Teams to typed action columns.)
- **(R4) `Skeleton` over `Spin` for composed dashboard loads.** A page that composes ≥ 2 async reads uses
  `Skeleton`/the `.ka-route-loading` shimmer, not a centred `Spin`. `Spin` is allowed only for a single
  inline control's pending state. *Testable:* no top-level `<Spin>` in a multi-`useResource` page.
- **(R5) `Descriptions` is the detail idiom for orders/registrations.** Record detail (an order, a
  registration request, a held claim, a refund) renders as AntD `Descriptions` (label→value rows), not a
  bespoke `<div>` grid. (PublicPay already does this — it becomes the convention.)
- **(R6) Evidence-beside-the-button.** Any decision UI renders its **proof in the same view**: the
  reason/note, the uploaded evidence (thumbnail/link via the scanned upload service), the policy position
  (e.g. the withdrawal band, the BI-9 recorder), or the consent hash — beside the approve/reject control,
  never one click away. *Testable (review-level):* every mutation control on a decision surface has a
  sibling element rendering the record it decides on. Extends the Approvals OD-28 pattern (name both
  parties) and the Refunds BI-9 "confirmer sees who approved" pattern to a system-wide rule.

---

## 6. Density & persona scopes — extends F5/§2

**Two density scopes stay** (`[data-density="admin"]` default, `[data-density="product"]` roomier). v2
assigns personas and states what changes.

| Scope | Personas / surfaces | Feel | Tokens (from `tokens.css`) |
|---|---|---|---|
| `admin` (default) | academy-ops, school-admin, teacher **consoles**; every `/admin/*` surface | dense, table-first, more rows per screen | `--ka-pad 16 / gap 12 / row 40 / page-x 28 / stack 16` |
| `product` | student · guardian · member **homes**, the marketplace/catalogue, the public tier | roomier, card-first, TaskCard/HeroBanner-led | `--ka-pad 22 / gap 16 / row 46 / page-x 40 / stack 22` |

**What changes per scope:** panel padding (`--ka-pad`), inter-zone gap/stack (`--ka-gap`/`--ka-stack`),
table row height (`--ka-row`), page gutter (`--ka-page-x`). **Colour, type scale, and radius do NOT change
by scope** (only spacing/density does). Persona homes set `data-density="product"` on their root (as
`MyChildren` already does); admin consoles inherit the default. **No third scope is introduced** — "home"
is `product`. New primitives read the density tokens, so a `TaskCard` in a product home is automatically
roomier than the same markup would be in an admin console.

**Rule (R-D1):** a surface declares its density **once, at its route root**; primitives never hardcode
padding that a density token could provide.

---

## 7. Token additions

**All additive, DS2-owned, no `theme.ts` counterpart → `ds2-tokens-check.mjs` passes unchanged** (it only
checks the 17 colour tokens that mirror `theme.ts`; none of those are touched). Every colour value below
is an **`rgba()`/gradient derivative of an existing token** — no new hue (satisfies the §0 constraint).

```css
:root {
  /* ── urgency (§3) — rgba() of existing semantic colours ── */
  --ka-warning-tint: rgba(251, 191, 36, 0.10);   /* derivative of --ka-warning */
  --ka-warning-line: rgba(251, 191, 36, 0.32);   /* already used inline in structure.css; formalised */
  --ka-danger-tint:  rgba(239, 68,  68,  0.10);  /* derivative of --ka-danger */
  --ka-danger-line:  rgba(239, 68,  68,  0.30);
  /* (--ka-gold-tint / --ka-gold-line already exist and are reused for the 'soon' level) */

  /* ── HeroBanner scrims (§2.2) — gradient derivatives of --ka-card ── */
  --ka-scrim-duotone: linear-gradient(160deg, rgba(26, 19, 38, 0.82) 0%, rgba(26, 19, 38, 0.55) 100%);
  --ka-scrim-bottom:  linear-gradient(0deg,   rgba(26, 19, 38, 0.90) 0%, rgba(26, 19, 38, 0.00) 60%);
  --ka-scrim-flat:    linear-gradient(160deg, var(--ka-card), var(--ka-muted));  /* image-fail fallback */
  --ka-hero-band: 180px;
  --ka-hero-tall: 320px;

  /* ── PageCard widths (§2.1) — non-colour ── */
  --ka-w-auth: min(380px, 100%);
  --ka-w-form: min(460px, 100%);
  --ka-w-wide: min(560px, 100%);

  /* ── mobile (§4) — formalising existing literals, non-colour ── */
  --ka-drawer-w: min(560px, 100vw);
  --ka-tabbar-h: 56px;
  --ka-sheet-radius: 16px;
}
```

**Zero-drift confirmation.** No existing token value changes. `--ka-warning-line` promotes a literal that
already appears inline (`rgba(251,191,36,0.32)` in `.ds2-subpanel--action`) into a named token — same
value. The drift checker's set of 17 colour tokens is untouched, so it passes as-is.

---

## 8. Adoption governance

The v1 model carries forward **verbatim**, extended for v2:

- **(G1) Import-guard allowlist.** `ds2-import-guard.mjs` remains the gate: only `ds2/`, the dev gallery,
  and **per-card allowlisted adopters** import `@/ds2`. Every new primitive lands in the barrel
  (`ds2/index.ts`) and every surface that adopts it is added to `ALLOWED` **in its own build card's slot**
  — never speculatively. A v2 build card that adopts, e.g., `PageCard` on `Register.tsx` adds
  `src/pages/Register.tsx` to the allowlist in that card.
- **(G2) Tokens-check unchanged.** `ds2-tokens-check.mjs` continues to fail the build on any colour drift
  from `theme.ts`. v2 tokens (§7) are DS2-owned additions it does not check.
- **(G3) Interactive AntD components are never converted (the M4 ruling — verbatim).** `Form`, `Input`,
  `Select`, `Segmented`, `DatePicker`, `Button`, `Table`, `Tabs`, `Drawer`, `Modal`, `Result`, `Spin`,
  `Descriptions` are used, never re-implemented in DS2. DS2 v2 restyles the **frame** around them; the
  mobile strategy (§4) **projects** them (sheet/list), it does not fork their logic.
- **(G4) Sensitive-tier discipline persists.** Any v2 card that touches a money / consent / 成團 /
  auth / activation surface ships with the **byte-identical behaviour proof** (whitespace-stripped handler
  sha, unchanged mutation URLs, unchanged i18n keys) established in the M-tier and S-FIX cards. A frame
  swap never edits a handler.
- **(G5) i18n discipline.** Every new user-facing string lands in `en.json` + `zh-TC.json` + `zh-SC.json`
  in the same card; `i18n:check` (708-key parity) must pass. Primitives hold **no** hardcoded strings —
  text arrives as translated `ReactNode`.
- **(G6) One primitive per rule-of-three.** A new primitive is only cut when a shape recurs ≥ 3× (the rule
  that produced v1's `StatCard`). Otherwise it stays a local composition until it earns promotion.

---

## 9. Sequencing note (for the build-card planner — not part of the spec surface)

Suggested build order once approved, low-risk-first, mirroring the completed-tier cadence:
1. **Tokens + primitives (no adoption)** — land §7 tokens and the §2 primitives in `ds2/` + the dev
   gallery, guard unchanged (nothing adopts yet → every surface byte-identical, like v1's Ruling 2).
2. **StatCard consolidation** (§2.5) — Payments migration (money-tier proof).
3. **EmptyState + Conventions** (§2.4, §5) — low-risk display swaps, per surface.
4. **Urgency language** (§3) — consent, then payments, then approvals.
5. **PageCard / AuthCard** (§2.1) — the public tier finally becomes DS2-native (P-tier proof).
6. **Persona homes** (§2.3 TaskCard, §2.2 HeroBanner, §6) — the new build, product density.
7. **Mobile absorption** (§4) — formalise the shell, add the responsive rules.

Each is its own reviewed card. **This document defines the vocabulary; it authorises no build.**

*— End of DS2 v2 spec (R0). External review gate before any build card is cut.*
