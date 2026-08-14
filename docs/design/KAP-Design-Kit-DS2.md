# KA Playground — Design Kit (DS2 v3 proposal)
**Deliverable ② of the design phase. Formatted for `src/theme/theme.ts` + `src/ds2/tokens.css` (drift-gated).**
14 Aug 2026 · supersedes the live palette per the owner's sign-off · React 19 + Ant Design 5.29

Companion docs: `KAP-Journey-Audit.md` v1.2 (gap register + tiers) · `KAP-Design-Changes-for-Verification.md` + the reconciliation reply (schema amendments + 4 mandatory build conditions) · `KAP-UIUX-Proposal.md` (IA + record compositions) · `KAP-Prototype.html` (visual reference only — never copy its markup).

Owner sign-offs baked in: (1) outline ban is total, form controls excepted via fill/focus rule §2.4; (2) this palette supersedes live `theme.ts`; (3) cards carry a slight violet tint, statuses remain the only loud hues; (4) locked radii 10/6 and type 32/24/18/14 adopted.

---

## 1 · Tokens — `kaColors` (paste-ready shape)

```ts
// src/theme/theme.ts — DS2 v3 source of truth
export const kaColors = {
  background:      '#0F0D14', // violet-black canvas (ambient gradient sits on top, §2.1)
  card:            '#191521', // container — slight violet tint (sign-off 3)
  muted:           '#1E1927', // recessed / secondary surface, filled form controls
  foreground:      '#FFFFFF', // headings, key values
  foregroundSoft:  '#D6D9E0', // DEFAULT reading text (new key)
  mutedForeground: '#9BA1AC', // metadata only — never body copy
  gold:            '#E0A83B', // primary accent — actions & active states only
  goldHover:       '#EAB652',
  border:          '#26232E', // hairline DIVIDERS only (border-bottom/top/right) — never outlines
  borderStrong:    '#726889', // focus rings & control focus border ONLY (3:1-compliant, kept from live)
  success:         '#4FB477',
  warning:         '#E8863C', // orange — never gold; deadline/attention class
  danger:          '#E5646E',
  pending:         '#4D7CF0', // new key — informational in-flight state (deepened, anti-pastel)
} as const;

export const kaCategoryAccents = { // unchanged from live — programme identity, NEVER status
  language:'#6366F1', stem:'#A855F7', arts:'#EC4899', maths:'#F97316', featured:'#06B6D4',
} as const;
```

Delta vs live theme.ts (what this supersedes): gold `#C9A962→#E0A83B` (warmer, decisive) · background `#0F0B15→#0F0D14` · card `#1A1326→#191521` (tint retained, chroma lowered) · foreground `#F4F4F5→#FFFFFF` + **new `foregroundSoft #D6D9E0`** as the reading tier · warning `#FBBF24→#E8863C` · success `#22C55E→#4FB477` · danger `#EF4444→#E5646E` · **new `pending`** · `border` demoted to dividers-only; `borderStrong` demoted to focus-only (§2.4). Category accents unchanged.

### 1.1 Mirrored `--ka-*` set (tokens.css)

`--ka-background · --ka-card · --ka-muted · --ka-foreground · --ka-foreground-soft · --ka-muted-foreground · --ka-gold · --ka-gold-hover · --ka-border · --ka-border-strong · --ka-success · --ka-warning · --ka-danger · --ka-pending · --ka-cat-language · --ka-cat-stem · --ka-cat-arts · --ka-cat-maths · --ka-cat-featured`

Tint recipes (derive, don't hardcode): status pill fill = status color at 15% over card; accent card wash = gold at 10–12% gradient; danger notice fill = danger at 12%.

## 2 · AntD ThemeConfig

### 2.1 `token` overrides

```ts
token: {
  colorPrimary: kaColors.gold,
  colorBgBase: kaColors.background,
  colorBgContainer: kaColors.card,
  colorBgElevated: kaColors.muted,
  colorText: kaColors.foregroundSoft,        // three-tier text mapping
  colorTextHeading: kaColors.foreground,
  colorTextTertiary: kaColors.mutedForeground,
  colorBorder: 'transparent',                // outline ban at the token layer
  colorBorderSecondary: kaColors.border,     // hairline dividers (Table lines, Divider)
  colorSuccess: kaColors.success, colorWarning: kaColors.warning,
  colorError: kaColors.danger, colorInfo: kaColors.pending,
  borderRadius: 10, borderRadiusSM: 6,       // locked (sign-off 4)
  fontSize: 14, fontSizeHeading1: 32, fontSizeHeading2: 24, fontSizeHeading3: 18, // locked
  fontFamily: `'Inter','Noto Sans HK','Noto Sans SC',sans-serif`,
}
```

Layout ambient (body, not a token — document in ds2 css): four fixed-attachment radial glows over `background` — `radial(2400px 1350px at 24% -8%, #6242CC/.30)` + top-right `#6E44CE/.20` + bottom-left `#4256CE/.18` + bottom `#7C8CF8/.09`. Sidebar/header are **zero-fill glass** (transparent + `backdrop-filter: blur(18px)` + hairline divider) so the ambient passes through — any fill re-creates the seam. Warning: `backdrop-filter` makes chrome the containing block for `position:fixed` children — anchor dropdowns `absolute` to the chrome.

### 2.2 `components` overrides (the gold-led set, adjusted)

```ts
components: {
  Button:  { primaryColor: kaColors.background, defaultBg: '#2A2536', defaultBorderColor: 'transparent' },
  Menu:    { itemSelectedColor: kaColors.gold, itemSelectedBg: 'rgba(224,168,59,.10)', itemColor: kaColors.foreground },
  Tabs:    { inkBarColor: kaColors.gold, itemSelectedColor: kaColors.gold, itemColor: kaColors.mutedForeground },
  Table:   { headerColor: kaColors.mutedForeground, headerBg: 'transparent', borderColor: kaColors.border, rowHoverBg: 'rgba(255,255,255,.025)' },
  Steps:   { colorPrimary: kaColors.gold, finishIconBorderColor: kaColors.success },
  Input:   { colorBgContainer: kaColors.muted, colorBorder: 'transparent', hoverBorderColor: 'transparent', activeBorderColor: kaColors.borderStrong },
  Select:  { /* same fill/focus rule as Input */ },
  Card:    { colorBorderSecondary: 'transparent' },        // elevation only — see §2.3
  Modal:   { colorBgElevated: kaColors.card },
  Tag:     { defaultBg: 'transparent', defaultColor: kaColors.foregroundSoft }, // status pills via preset classes §3.1
  Progress:{ defaultColor: kaColors.gold },
  Badge:   { colorError: kaColors.warning },                // queue counts are warning-orange, not red
  Drawer:  { colorBgElevated: kaColors.card },
}
```

### 2.3 Elevation (replaces outlines)

`--ka-sh-1: 0 8px 28px rgba(0,0,0,.35)` (resting card) · `--ka-sh-2: 0 16px 48px rgba(0,0,0,.45)` (raised: hover-lift, dropdowns, sheets). Clickable cards animate `translateY(-2px)` + sh-2 on hover. **No `border: 1px` anywhere**; hairline *dividers* (single-edge `border-bottom/top/right` in `--ka-border`) are the sanctioned exception.

### 2.4 Form-control rule (sign-off 1 — the accessibility exception)

Controls are **filled surfaces** (`muted` fill, no border). WCAG 1.4.11 compliance comes from either: the fill contrasting ≥3:1 against its surrounding container, **or** the `borderStrong` focus border/ring on `:focus-visible` (1.5px). Build check: `muted #1E1927` on `card #191521` does **not** reach 3:1 → the **focus-border path is mandatory**, and resting controls must carry a visible affordance (placeholder + fill delta + icon). Drag-and-drop interactions must ship a **button-equivalent path** (add/remove buttons in composition edit mode) — legal requirement, not preference.

## 3 · Component specs (AntD component + config + composition — no HTML)

**3.1 Status pill** — `Tag` presets `ka-ok / ka-warn / ka-danger / ka-pend / ka-neutral`: filled 15% tint of the status color, colored 12.5px/600 text, radius 999, **no dot, no border, no icon**. Usage rule: pills mark **actionable/attention states only**; informative values (team names, session times, tracker position, completed items) are `Typography.Text` right-aligned in `foreground` — max 1–2 colored elements per card.

**3.2 Glance card** — `Card` (no border, sh-1, hover-lift when clickable): image band (programme photo, one shared neutral overlay `rgba(15,13,20,.22→.78)`; identity = different image per programme, never per-card color grades) → status pill row → **labeled segbar** (3.3) → label/value rows: `Row(label: Text strong; value: Text right | Tag | action Button on the owning row)`. Same anatomy for student programmes, guardian children/child, mentor teams.

**3.3 Labeled segbar** — custom `Ds2SegBar` (not `Progress`): 5 segments 5px, gap 7, labels 10.5px under each; done=`success`, current=`gold`+glow, rest=`#2A2536`; done labels `foregroundSoft`, current gold 700.

**3.4 Journey stepper** — `Steps` (gold ink) with generous padding (32/26), stage **dates** under completed knots, preceded by three stat tiles (ghost inner `Card` on `muted`: Team / Next session / Consent), followed by a "What happens next" inner tile. No instructional copy elsewhere.

**3.5 Action-required list** — guardian home leads with it: warning accent bar, 19px heading with count, rows = 44px glyph tile (lucide icon) + 16.5px/700 title + who-line + **deadline as the loudest element** (15.5px/700 warning + small caps sub-label) + chevron; whole row navigates.

**3.6 Page tabs & multi-programme** — `Tabs` underline style. Every multi-programme staff surface defaults to **All programmes** (summary table: one row per programme, funnel counts as plain values, window state, single attention pill; row click drills in) with per-programme tabs. Cross-programme **queues stay unified** with a Programme column per row — the queue *is* the overview.

**3.7 Programme band header** — scoped spaces open with the programme's image band (name, status pill, switcher chevron on the photo); switching swaps band + pill.

**3.8 Board (kanban)** — column `Card`s with count chip; item cards: monogram `Avatar` for people, member-count meter (5 dots, success-filled) for teams, attention pill where true; hover-lift; ****never** dragged between columns** — funnel transitions are ceremonies.

**3.9 Composition (roster editing)** — detail page, never a popup: pool left / roster slots right; **Edit → drag-to-stage (or button-equivalent) → Save opens consequence `Modal`** (staged changes + invariants: lobby, capacity, consents) → "Request changes" submits (`team_change_requests`); Cancel reverts. Same engine for ops/school/mentor; framing line differs by role. Concurrent-edit rule: save carries a version; stale saves are rejected with current state and re-staged, never merged.

**3.10 Ceremonies** — consent signing: full-screen (never a sheet), scroll-gate → affirmation → **separate optional media-consent toggle** → signature pad → immutability line; **step-up re-auth** here and on four-eyes confirms. Irreversible confirms: centered `Modal`, explicit buttons, reason `TextArea` where ruled, **swipe-dismiss disabled** (`data-nosheet`). Consent withdrawal: superseding-action modal (original never edited; consequences + academy contact + reason).

**3.11 Mobile sheets** — family-phone modals = bottom sheets: `Drawer placement="bottom"` composition with grabber bar + drag-down-to-dismiss (≈90px threshold); notifications become a sheet on phone, right drawer on desktop. Swipe-to-close is for recoverable surfaces only. Staff surfaces: desktop-first; **no four-eyes ceremony renders on a phone**.

**3.12 Elastic search** — transparent header search (no fill); typeahead dropdown (sh-2, grouped by record kind), results **entitlement-scoped per role**, empty state "No matches in your entitled records"; Enter opens top hit.

**3.13 Navigation chrome** — glass sidebar over the ambient; groups with **right-aligned** chevrons (one indicator column with the badges); queue items (only) carry warning count badges, collapsing to dots in the mini rail; mini rail shows hover label chips (plus `aria-label`s — tooltips are visual-only); 36px edge collapse handle at top; footer on every role: staff = env dot + version + **elapsed session timer** + logout icon; family = **last sign-in** (safeguarding) + timer + logout. Header brand zone = sidebar width, collapse-aware.

**3.14 Notifications** — bell with numeric warning-count badge (hidden at zero); drawer items: lucide glyph tile + title + meta + status pill + **Snooze** (deadline class: max 3h, always returns 24h pre-expiry) + **Mute** (informational classes only — absent on deadline/safety rows) + Mark-all-read. "Reschedule" is never a notification action — items deep-link to the owning surface.

**3.15 Marketplace** — image-led cards (price, enrolled chip; category accent chip from `kaCategoryAccents` — the accents' only sanctioned home besides charts); detail page with real programme data + Full-details sheet; guardian variant CTA "Enrol [child]…"; student CTA routes to guardian.

**3.16 Empty states & first-run** — every list surface ships one (register in audit doc §4C); empty student home = Market-Place-led composition. No instructional copy anywhere else in the product; pedagogy lives in onboarding only.

**3.17 Icons** — lucide-react (confirmed in stack), stroke 1.9, 19–20px in nav within a 22px column. **Emoji are prohibited everywhere.**

## 4 · Interaction rulings (binding, from the session log)

One language per render (EN / zh-TC / zh-SC via i18next — never mixed in one string) · entitlement-iff rendering (a thing exists iff the viewer's read returns it; unentitled deep links 404) · server owns all transitions; UI requests + shows consequence first · drag stages, never commits; never crosses funnel states · overview-first for multi-programme; unified queues with programme visible · every queue decision writes an audit row (actor, reason, before/after) · color budget: statuses are the only loud hues; category accents = identity; gold = action · zero outlines; dividers + elevation only · deadline notifications are never mutable · co-guardians never see each other · no mentor↔student messaging, ever.

## 5 · Registers (pointers, not duplicates)

Gaps & tiers: `KAP-Journey-Audit.md` v1.2 (§4D register; Tier-0 all answered 14 Aug). Schema amendments + the four **mandatory build conditions** (invite-code hardening · batch-reason enum · waitlist own-row scoping · RLS line-review for `incident_notes` + `mentor_checkins`): the verification doc + reconciliation reply, folding into engineering's Step-6 list.
