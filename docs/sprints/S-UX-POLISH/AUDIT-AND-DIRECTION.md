# S-UX-POLISH — UI/UX Audit & Design Direction (Pass 1)

**Documentation + prototypes only. NO production code, NO changes to built surfaces.** This is the pass Leo
reacts to; the Design System v2 spec + per-surface build cards come *after* the direction is blessed.

> Method: read the real `web/src` surfaces, the schema/endpoints, and Design System v2.1. Every critique is
> tied to a named practice principle, not "looks dated." Grounded in a full surface survey (quoted below).
> Companion artifact: **`prototypes/anchor-prototypes.html`** — three interactive anchor screens (switch
> screens + live EN/繁/简 toggle). Screenshots referenced throughout.

---

## The one-line finding

**The build was function-first and review-gated; UI polish was deliberately deferred to here — so most gaps
are not "bad UI", they are *unrealized Design System*.** DS v2.1 already specifies a status timeline,
programme cards with imagery, "no asterisks / labels always visible", table density rules, autosave stamps.
The built screens mostly predate or under-apply that. Three things, though, are **genuinely missing from the
DS itself** and are where the real design work is: (1) a **trilingual INPUT** pattern (§18 covers *display*,
not entry), (2) a **multi-step wizard FLOW** pattern (§16 has an enrolment *timeline*, nothing for config),
(3) a formal **density system** (compact admin vs airy product). The direction below closes both the
spec↔build gap and these three DS gaps, and evolves v2.1 → **v2**.

---

## PART 1 — AUDIT (best-practice critique, by surface family)

Each row: the concrete issue in the *built* UI, and the **principle** it violates.

### 1.1 Admin cockpit — the programme WIZARD (`AdminProgrammes.tsx`) — the worst offender
| Issue (from source) | Principle |
|---|---|
| 11 sections render as a **flat `List`**, each opening a 480px `Drawer`; a single `Progress` bar is the only orientation. No `Steps`, no phase grouping, no "what's blocked by what". | **Progressive disclosure & wayfinding** (Nielsen "visibility of system status"; Wizard pattern) — a multi-step task needs a step model, not a modal-per-row round-trip. |
| The **dependency graph exists in the data** (`WizardService::SECTIONS` — tracker needs role_library; certification needs tracker+learning) but the UI shows none of it — a user opens a blocked section and only then learns it's blocked. | **Prevent errors before they happen** (Nielsen #5). Surface dependencies as structure. |
| **`fees`/`consent` lock on publish** (`LOCKED_WHEN_PUBLISHED`) — a one-way door — is invisible until you try. | **Match between system and the real world; forgiveness.** A one-way door must be signposted *before* the click. |
| Trilingual entry (marketing section, the app's **only** typed-trilingual surface) = **three stacked `<Input>`s labelled by placeholder only** — 12 stacked inputs in one drawer, and the language label *disappears once you type*. | **Labels are not placeholders** (DS v2.1 §15, already binding — violated in build). **Data integrity**: you cannot see at a glance which languages are filled. |
| Programme `name_en/tc/sc` are **not editable anywhere** — display-only. Section editors are inconsistent shapes (an `InputNumber` here, a `Switch` there). `<Title level={1}>` on this page vs `level={3}` everywhere else. | **Consistency & standards** (Nielsen #4). |

### 1.2 Admin cockpit — dense data screens (Payments, Team 成團, Approvals, Financial Integrity, Refunds, Audit)
| Issue | Principle |
|---|---|
| **Readiness is spoken in three different visual languages**: a `Progress` bar (wizard), prose "X of N signed" + colour `Tag`s (Teams), `Statistic` cards (Financial/Dashboard) — the *same concept* (how-ready-is-this) looks different on every screen. | **Consistency**; **one concept → one representation.** |
| **Team 成團 `ResolutionConsole` stacks SIX full-width tables in one card**; counts are inline strings (`` `${count} / min ${min}` ``). Enormous scan cost, no summary. | **Aesthetic-minimalist / signal-to-noise** (Nielsen #8); **overview-first, then detail** (Shneiderman's mantra). |
| Money is only distinguished by a **bold** cell; no totals/`Statistic` header on Payments; no visual weight for the money that matters. | **Visual hierarchy** — the most important number should look like it. |
| The BI-9 recorder≠confirmer rule is enforced server-side but the UI **shows "Recorded by" as a plain column** — the segregation-of-duty moment isn't dramatized where the officer acts. | **Make system state legible at the point of action.** |
| Status is consistently `StatusTag` (good) but every tag is default-density AntD with no semantics beyond colour+word; no "attested/sealed" treatment for the states that are *evidential* (confirmed payment, issued receipt, signed consent). | **Encode meaning structurally**, not just chromatically (design skill: "structure is information"). |

### 1.3 Student / guardian / member / teacher (portal surfaces)
| Issue | Principle |
|---|---|
| Same **flat, table-or-list, imagery-free** density as admin — a guardian's "My Children" and a member's "Events" carry the same visual weight as a finance queue. Portal users are not operators; the density punishes them. | **Density should match task & audience** (compact for operators, airier for occasional users). |
| **Zero imagery/illustration in production** (a single `AssetImage` exists, used only in the dev StyleGuide; the DS §16 *specifies* programme cards with 16:9 images + gradient fallback — unrealized). Empty states use bare AntD `<Empty>`. | **Emotional design / recognition** — product surfaces for families need warmth and recognition, not spreadsheet chrome. DS §16 already asks for this. |
| Consent — an *evidential, high-stakes* act — renders as ordinary `Descriptions` rows; nothing signals "this is sealed and audited." | **Signifiers should carry gravity proportional to consequence.** |
| Readiness/blockers (e.g. "consent needed before the seat confirms") are `Alert` banners in some places, tags in others. | **Consistency of the call-to-action for the same situation.** |

### 1.4 Public — Marketplace, /pay, Register
| Issue | Principle |
|---|---|
| **The Marketplace / public catalogue does not exist** (routes stop at register/pay/activate; "marketing" is only an optional wizard section, imagery "deferred"). The single richest brand moment — a family's first look — is unbuilt. | This is **net-new capability**, not a restyle (see Part 2 — flagged *net-new-card*, not folded into polish). |
| `/pay` (public, exists) is a plain `Descriptions` read — functional but gives the one *unauthenticated, forwarded-to-a-stranger* surface no trust cues (it's where money is about to move). | **Trust & credibility signals** matter most on payment surfaces. |
| `Register` is a two-kind form; adequate, but shares the imagery-free, hierarchy-flat treatment. | **First-impression hierarchy.** |

### 1.5 Chrome (`AppShell`)
| Issue | Principle |
|---|---|
| `ProLayout` with `menu={{ defaultOpenAll: true }}` — **every nav group expanded by default**; a long role (admin) gets a wall of items. | **Recognition over recall, but not everything at once** — progressive disclosure in nav too. |
| The shell is solid (real mobile app-shell, bottom tabs, no hamburger — DS §17 realized). This is the **strongest-built** area; leave it, evolve tokens only. | — (credit where due). |

---

## PART 2 — SCHEMA CROSS-REFERENCE (capability the UI underuses)

**CRITICAL RULE (Leo's):** these are **findings, not a licence to build.** The revamp *redesigns what
exists*; net-new capability is a separate card with its own think-first. Each is flagged
**{surface · defer · net-new-card}**.

| # | The system supports… (source) | The UI… | Flag |
|---|---|---|---|
| S1 | `wizard_sections.status` ∈ {not_started, incomplete, complete, deferred} + a full **dependency graph** + `LOCKED_WHEN_PUBLISHED` | shows one flat progress bar; hides deps and locks | **surface it** — it's redesign, no new data |
| S2 | `programmes.name_en/tc/sc` are stored but there is **no edit UI** for them | never lets you edit the programme's own name | **surface it** — an editor for existing fields |
| S3 | Orders carry `payer_party` (guardian/student/**school**), `payment_due_at`; deadlines <7d are DS-specified countdown chips | shows amount + status only; no due/countdown, no payer distinction | **surface it** |
| S4 | `receipts` are gapless + immutable (BI-2); `payment_links` carry status/expiry; the mint returns a forwardable `/pay` URL | receipt numbers shown plainly; no "sealed/attested" treatment | **surface it** (the seal motif, Part 3) |
| S5 | Attendance/Learn-gate produce a **per-team eligibility** (`LearnGateService`: qualifying/active, thresholds) | S-UX3-4 shows counts; the team-gate *eligibility widget* isn't built | **defer** — a richer read, its own polish sub-card |
| S6 | `session_versions` (immutable reschedule history), `audit_events` (full actor trail), `unmatched_payments` | audit report exists; reschedule history + unmatched aren't surfaced to the client screens | **defer** |
| S7 | A public **catalogue** read exists behind marketing-completeness (S-MARKETPLACE-A decoupling) but **no catalogue page** | no public browse at all | **net-new-card** — a real feature with its own think-first, NOT part of the restyle |
| S8 | Consent template **versioning + SHA-256 + language-scoped hashes** (BI-6) | consent renders as plain rows | **surface it** — the evidential treatment (seal), no new data |
| S9 | Guardian can hold **multiple children**; enrolment status is a 9-state lifecycle | My Children (just built) shows it, but flat | **surface it** — the airier per-child treatment (prototype C) |

Net-new items (S5 partial, S7) are **explicitly walled off** from this phase. The restyle touches only
**surface-it** findings — same data, better shown.

---

## PART 3 — DESIGN DIRECTION (decisions made once, applied everywhere)

**Design thesis.** The subject is a **governance instrument for minors, guardians and money** — enrolment,
consent, audit, receipts. Its world is *attestation*: signed consent, sealed receipts, an audited trail; and
its house — "Armour Academy / Kings Network", aubergine + gold — is **heraldic**. So the aesthetic point of
view, and the one aesthetic risk I'll defend, is:

> **Gold is not "primary." Gold is attestation.** A gold **seal** marks the states that carry evidential
> weight — a confirmed payment, a gapless receipt, a signed consent, a completed section. Everywhere else
> gold is used with restraint (focus rings, the one CTA). This gives a finance/child-safety platform a
> distinctive, earned visual language instead of gold-as-decoration — and it's *true* to the data (these
> states really are sealed and audited). This is the signature element; everything around it stays quiet.

This **evolves v2.1, it does not replace it** — same palette, type, tokens. The new layer is four decisions:

### D1 — Trilingual INPUT: segmented tri-tab with completeness dots *(the #1 gap)*
Three languages become **one control**: a 3-tab segment (English / 繁體中文 / 简体中文), each tab carrying a
**completeness dot** (green = has content, grey = empty). One field height, not three stacked inputs; and the
**at-a-glance-empty data-integrity property is preserved and improved** — you see which languages are missing
without reading, and a field-level summary states it in words ("1 language empty — 简体 required before
publish, OD-19"). Rationale: collapses the "wall of inputs", keeps labels visible (not placeholder-only),
and makes the OD-19 completeness rule *visible* rather than a submit-time surprise.
*Considered and rejected:* side-by-side 3-columns (breaks on narrow admin drawers, triples vertical scan);
primary+collapsible (hides the empty-state the governance rule needs visible).
→ **Prototype A** (the wizard), live tri-tabs.

### D2 — Multi-step FLOW: dependency-aware left rail + phase grouping + save-and-continue *(the #2 gap)*
The 11 sections become a **left rail stepper**, grouped into phases (Setup · Money & consent · Teams & roles
· Learning · Optional), each step showing its **completeness state** (done ✓ / current / incomplete / blocked
!) and its **dependency + lock** ("Needs Role library first"; a lock glyph on fees/consent). A persistent
**autosave stamp** ("Saved · 14:32", DS §15) and a **pre-flight bar** ("2 blockers before publish — 简体 name
· Role library") replace the blind progress bar. Rationale: turns the hidden dependency graph into the
navigation model; a one-way door is signposted before the click.
→ **Prototype A**.

### D3 — One readiness language: the **Statistic strip** + the **seal**, retire prose-counts
Every "how-ready / how-much" concept uses **one** representation: a compact `Statistic` strip at the top of
operator screens (Outstanding / Awaiting confirmation / Confirmed today — tabular-nums), and the **gold seal**
for the attested terminal state. Prose counts ("X of N signed") and ad-hoc tag-math are retired. The BI-9
recorder≠confirmer moment is **dramatized at the point of action** — the row you recorded shows "You" in
warning and its Confirm is disabled with the reason. Rationale: consistency; the money that matters looks
like it; segregation-of-duty is legible where the officer acts.
→ **Prototype B** (Payments).

### D4 — A density system: `--admin` (compact) vs `--product` (airy), one token set
A single `data-density` scope flips spacing/row-height/page-padding: **admin** = 40px rows, tight gaps,
dense tables for operators; **product** = 48–52px, generous cards, category-accent bars, room for imagery, for
guardians/students/members/public. Same palette and type — only rhythm changes. Plus: **realize the DS §16
imagery** (programme cards 16:9 + gradient fallback — the `AssetImage` fallback already exists) on product
surfaces; keep operator surfaces text-dense. Rationale: density must match task and audience; one platform,
two rhythms, zero token forks.
→ **Prototype C** (My Children) vs **B** (Payments) — the contrast is the point.

### D5 — Hierarchy · atoms · visual signal (the corrected ratio — now the STANDARD) *(Leo, revision 1)*
The blessed direction read **too text-heavy** — status, detail and reassurance sat at similar weight, so
screens scanned as prose. The corrected ratio is now the house style, applied to **every** surface (admin,
operator, product):
- **Stronger hierarchy.** The **status is the loud atom** (a short bold line or a pill); **detail and
  reassurance are demoted** to small muted supporting text beneath it. Example: "Consent complete for both
  enrolments. You signed on 28 Jul 2026 — sealed and audited" → a bold **"Consent complete"** headline atom
  with **"Signed 28 Jul 2026 · sealed & audited"** as small muted text under it.
- **Atoms over sentences.** Explanatory prose becomes **label + action + (smaller) rationale**. "Your
  signature is needed. Coding Explorers requires consent before the seat is confirmed" → a compact alert:
  bold **"Signature needed"**, a prominent **Review & sign** button, and the rationale as small secondary text.
- **More non-text signal.** State renders as **visual atoms** — the consent seal becomes an avatar **badge +
  ring**; counts (programmes, age) become **stat chips**; session timing becomes **meta chips**; wizard
  readiness becomes a **progress ring + per-phase counts**; the money amount becomes a **display-weight
  figure**; the BI-9 recorder becomes a **warning pill**. Text carries only what a visual cannot.

**The honesty guardrail (binding, overrides the density instinct).** This is a child-safety / financial-
integrity product where **specificity is the trust**. The consent reassurance ("signed 28 Jul · sealed &
audited"), the money/status precision, the exact-state language **must remain present and readable** — the
fix makes them **smaller and secondary (muted, beneath the headline atom), never deletes them**. Compress and
de-emphasize; do not remove. A guardian must still be able to read "sealed and audited" — just not have it
competing with the headline. *This guardrail applies to every future build card: no restyle may drop an
evidential detail to reduce text.*

### D6 — Separation & structure: "zones, not cages" (both axes — part of the STANDARD) *(Leo, revision 2)*
The density and atom work was right, but dense content **bled together** — stacked sections merged
vertically, and the Payments columns ran together horizontally. The fix is the **minimum structure that
makes zones unambiguous — shade + space + alignment, never heavy borders or boxes-in-boxes** (which would
fight the clean direction). The treatment differs by axis:

**Vertical — sections stacked within a card** (My Children, the wizard section panel):
- Each section becomes a legible **sub-panel** via a **subtle background shade-step + generous internal
  padding + a clear gap between sections** — extending the logic the consent alert already uses (its faint
  tint) to every section. Neutral sections use a barely-there neutral shade (`rgba(255,255,255,.035)`);
  semantic sections keep their tint (gold for attested, amber for action-needed).
- The divider between sections is **space + the shade-step** (content-section stacks) or **space + a single
  subtle rule** (form field-groups) — never a heavy line, never a nested box.

**Horizontal — dense tables** (the Payments queue): the "columns run together" problem is solved by making
the **row**, not the column, the tracked unit:
- **Zebra row-banding** — alternating row background so the eye tracks *across* a row without losing the line.
- **Alignment discipline** — text columns left; **money right-aligned** (amounts form one clean scannable
  vertical column, tabular-nums); **Status and Action in defined-width zones** so the grid is stable row-to-row.
- **Generous, even column gap; `nowrap` atoms; NO vertical column rules** (the dated spreadsheet look).
- The money/status atoms and the BI-9 "You"-row disabled-confirm are unchanged.

Both are the same principle — **structure by shade, space and alignment** — and both are now house standard:
every card with stacked sections bands them; every dense table zebra-bands and aligns by column-type.

### Design-system-v2 scope (what the eventual spec will add to v2.1)
- New component specs: **TrilingualInput** (D1), **WizardRail** (D2), **StatisticStrip**, **Seal / attested
  row** (D3), **density scopes** (D4), **ProgrammeCard realized** (imagery), **PaymentTrust panel** (/pay),
  and the D5 atom kit — **StatusAtom** (loud headline + demoted `subatom`), **StatChip**, **MetaChip**,
  **StateBadge** (avatar seal/warning + ring), **ProgressRing** — and the D6 structure primitives —
  **SubPanel** (shade-banded zone), **ZoneStack** (banded sections + gap), **ZebraTable** (row-banding +
  column-type alignment + defined Status/Action zones).
- Token additions: `--gold-tint`, `--gold-line`, the `--seal` gradient, the `--admin/--product` density scales.
- No palette change, no type change, no light mode (client decision stands). **v2 is disciplined evolution.**

---

## PART 4 — ANCHOR PROTOTYPES (renderable — react to these)

**`prototypes/anchor-prototypes.html`** — one self-contained, interactive file. Switch screens (top),
toggle display language **EN / 繁 / 简** (top-right), click the trilingual tabs. Built on the proposed v2
tokens. Realistic data shapes only — every field maps to a real read (no fabricated capability).

| # | Screen | Carries | Demonstrates |
|---|---|---|---|
| **A** | Programme **Wizard** (worst offender) | trilingual input + step-flow + readiness | D1 tri-tab (English/繁 filled, 简 empty → "1 language empty"); D2 dependency rail with locks + blocked steps + pre-flight bar |
| **B** | **Payments** (dense admin) | data-density + money + status language | D3 Statistic strip; the **seal** on confirmed rows + gapless receipt no. (mono); BI-9 "You"-recorded row with Confirm disabled |
| **C** | **My Children** (airy product) | product-grade density | D4 airy density; category-accent bars; the **seal** on "Consent complete"; a warning-callout "Review & sign" for pending consent; live 繁/简 name flip |

*Screenshots:* `~/Downloads/proto-A-wizard.png`, `proto-B-payments.png`, `proto-C-children.png`,
`proto-C-children-tc.png` (the 繁體 toggle).

---

## What this pass is NOT

- **Not production code.** No `web/src` file changed; no built surface touched. These are docs + one HTML
  prototype.
- **Not feature-building.** Part 2's net-new items (public catalogue, team-gate widget) are walled off as
  their own cards with their own think-firsts. The restyle redesigns *what exists*.
- **Not the final spec.** After Leo reacts, the next deliverables are the **Design System v2 spec** (evolving
  v2.1 §-by-§) and **per-surface build cards** — each still review-gated, trilingual, accessible, with the
  child-safety/money invariants untouched (this is presentation only; no read/write/RLS changes).

### Open questions for Leo
1. **The seal / attestation thesis** — is "gold = attested, not primary" the right identity risk to take?
2. **Trilingual input** — tri-tab (recommended) confirmed, or do you want side-by-side for wide admin forms?
3. **Density split** — is guardian/student/member "product-airy" the right call, or keep one density?
4. **Imagery** — realize DS §16 programme imagery now (rescued MVP assets), or stay text-first for Phase 1?
5. Scope of the v2 spec + the order of per-surface build cards, once direction is blessed.
