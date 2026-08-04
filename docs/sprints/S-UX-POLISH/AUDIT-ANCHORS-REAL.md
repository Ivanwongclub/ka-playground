# AUDIT — The Three Anchors For Real (wizard · Payments · My Children)

**Result:** PASS · **Date:** 2026-08-04
**Commits:** STEP 1 `c2806dd` (My Children) · STEP 2 `68ac755` (wizard) · STEP 3 `beb5a1e` (Payments)

> Written by Claude Code at the card's end. Honesty outranks looking good. This is the first rollout card
> that changed a product surface's appearance — reviewed for BEHAVIOR-PRESERVATION, not just look. Planning:
> `PROPOSED-ANCHORS-REAL.md` + `CARD-ANCHORS-REAL.md` (this dir). Does NOT rewrite any prior AUDIT.

## 0. Scope

The first restyle-rollout card. It makes the blessed anchor prototypes REAL over the LIVE data/behaviour of
three product surfaces — chosen because they carry the **three hardest DS2 pattern-families**, validating
the library against each before any other page depends on it:

| Anchor | Pattern-family | Depth | Nature |
|---|---|---|---|
| **My Children** | airy product (SubPanel/ZoneStack/Attest/StatChip) | frontend-scan | markup-only |
| **Wizard** | trilingual + step-flow (WizardRail/FormLanguageSwitcher/ProgressRing) | **line-by-line** | input-mechanism → **payload-equivalence** |
| **Payments** | dense money (ZebraTable/StatCard, BI-9) | **line-by-line** | markup-only |

Ascending risk; **three separate reviewed steps, gated surfaces never batched.** Across all three: **0
backend · 0 migrations · battery 58/58 throughout.** The import-guard grew by **exactly three entries**
(`SelfService.tsx`, `AdminProgrammes.tsx`, `Payments.tsx`) — every other product surface **byte-identical**.

## 1. My Children (`c2806dd`) — markup-only; tests green UNMODIFIED

Adopted Card + avatar + **StatChip** header · **ZoneStack** of per-enrolment zones · **Attest** consent
advisory · Seal, over the LIVE reads UNCHANGED (`/api/enrolments` grouped by child + per-enrolment
`/my/students/{id}/consent-status` derivedStatus). No mutate/endpoint/data-shape change.
- **Proof:** `SelfServiceUxTest` is **UNMODIFIED (git)** and green (6/32) — the restyle touched only React
  markup, so no behaviour moved. (A restyle forcing a test change would be a STOP flag.)
- **Fidelity, not fabrication:** consent is **per-programme** in the live data (the authoritative
  derivedStatus, per student+programme), so the advisory renders **per enrolment** via Attest (attested →
  `onViewRecord`; action → Review & sign) — NOT the prototype's single per-child aggregate bar (which the
  per-programme reads don't give without extra fetching). Age/session chips **omitted** — that data is not
  in the reads (no fabrication). D5 held live: `Attest` attested REQUIRES `onViewRecord`, so the record is
  always reachable.
- No risk shot (display surface — reads only; the sole action is a Link). Live render on real seed (6
  children) is the proof.

## 2. Wizard (`68ac755`) — the payload-equivalence proof (line-by-line)

Adopted **WizardRail** (grouped stepper, clickable → opens the SAME Drawer) + **ProgressRing** +
**FormLanguageSwitcher** (marketing trilingual). The Drawer editors were **kept** (lowest churn, tightest
behaviour-preservation); `saveSection`/`pre-flight`/`publish` endpoints UNCHANGED.

**The gated case:** the marketing INPUT MECHANISM changed (12 stacked trilingual inputs → one form-level
switcher) — so "tests stay green unmodified" is not sufficient; the payload must be proven identical.
- **The NEW test** (`scripts/marketing-payload.test.mjs`, wired into `npm run build` — breaks CI if
  equivalence breaks) imports the **REAL shared reducer** (`marketing-payload.ts`, the one the wizard uses —
  not a mirror) and asserts: rebuilding the payload the form-level way === the exact `{[field]:{en,tc,sc},
  brand_color}` shape the old stacked inputs produced; a 简 gap is DETECTED (the 422 language_incomplete
  still fires); the reducer is pure. (Node 24 strip-types the `.ts`.)
- **Existing tests green UNMODIFIED:** `WizardTest` + `WizardMarketingTest` (11/101); `git status api/`
  empty. The publish gate + OD-19 are server-enforced and untouched.
- **Risk shot (live, a draft programme):** WizardRail(11) + ProgressRing(0/9) + **Publish DISABLED** (the
  publish gate) + FormLanguageSwitcher ("English incomplete"); filling only EN + Mark complete → the server
  **422 "marketing.language_incomplete: missing tagline.tc, tagline.sc, …"** surfaced — and **`tagline.en`
  is NOT in the missing list**, i.e. the EN input flowed through the shared reducer exactly as before. OD-19
  fires IDENTICALLY through the new mechanism, shown-not-hidden.

## 3. Payments (`beb5a1e`) — dense money; markup-only

Adopted **ZebraTable** (money-right + status/action zones, zebra) for the awaiting-orders +
pending-confirmation tables + a summary **StatCard** strip (Outstanding / Awaiting / Confirmed) with
**StatChip** counts + the **Seal** on Confirmed. The record Modal (OD-5, evidence BI-10), reject
ReasonModal, and every mutate call are UNCHANGED. `git status api/` empty; `ManualPaymentTest` green (7/142)
UNMODIFIED.
- **BI-9 — blessed as server-authority-preserving defence-in-depth:** BI-9 stays SERVER-enforced (403 on a
  same-person **confirm OR reject** — the server blocks both; the reconcile SoD assertion untouched). The
  restyle ADDS a **shown-not-hidden DISABLED cue**: the recorder's own Confirm/Reject are SHOWN but disabled
  with the reason, and Recorded-by shows "You". It disables **on the same fact the server checks**
  (`meId === recorded_by`), fails safe, and turns a post-click 403 into a proactive reason — the server
  remains the sole authority, so the BI-9 test is unmodified + green. (This supersedes the old "never
  disable" comment — an intentional UX shift per the STEP-3 ruling.)
- **Stat-cards — frontend-computed, NO new aggregate:** Outstanding = Σ awaiting orders (`/orders`),
  Awaiting = Σ pending (`/payments`), Confirmed = Σ confirmed (`/payments`) — all over reads already
  fetched. **"Confirmed" is all-time, not "today"** (the read carries no reliable confirm-date); a "today"
  filter is a deferred separate money-data backend line-item, correctly NOT folded in.
- **Risk shot (live, finance1 viewing a REAL payment they recorded):** the pending ZebraTable shows Amount
  HK$2,500 (right, display-weight), Recorded-by "You" (amber), and **both Confirm AND Reject DISABLED** —
  shown-not-hidden. token_hash never fetched/shown (the ZebraTable columns are an explicit allowlist).

## 4. Honest deviations

| Item | What & why |
|---|---|
| **Per-enrolment consent (My Children)** | Rendered per programme (fidelity to the authoritative derivedStatus), not the prototype's per-child aggregate — the reads don't support the aggregate without fabrication. |
| **WizardRail `onStep`** | The first real wizard adoption needed clickable steps; WizardRail gained an OPTIONAL `onStep` — additive + backward-compatible (the DS2 gallery, passing none, renders identically). |
| **BI-9 shown-disabled (Payments)** | The recorder's Confirm/Reject went from shown-ENABLED (post-click 403) to shown-DISABLED-with-reason. **Server unchanged** — defense-in-depth, not enforcement; preserves shown-not-hidden. Blessed. |
| **"Confirmed" all-time (Payments)** | Not "Confirmed today" — the /payments read has no reliable confirm-date; a "today" filter is a deferred backend line-item. |
| **Attest deferred (Payments)** | No attested-receipt display currently exists to restyle; a confirmed-receipts view (+ a /receipts read) is new scope, deferred. The Confirmed stat-card carries the Seal motif. |
| **成團** | Present in none of the three anchors (it lives in Teams.tsx/StudentTeam.tsx) — no terminology change this card; it rides the Teams-surface rollout. |

## 5. Exit gates (per step)

```
STEP 1 (My Children)  SelfServiceUxTest UNMODIFIED + green (6/32) · ds2:check · i18n 678 · tsc · build
STEP 2 (wizard)       marketing-payload PASSED (new, in CI) · WizardTest+WizardMarketingTest UNMODIFIED (11/101)
                      · ds2:check · i18n 685 · tsc · build · risk shot: OD-19 422 via FormLanguageSwitcher
STEP 3 (Payments)     ManualPaymentTest UNMODIFIED + green (7/142) · ds2:check · i18n 693 · tsc · build
                      · risk shot: BI-9 Confirm+Reject disabled ("You")
Across all three:     0 backend · 0 migrations · reconcile 58/58 (every step) · every other surface byte-identical
```
**Verdict: PASS.** The import-guard grew by exactly the three anchor files; the DS2 library is validated
against all three pattern-families on live product surfaces.

## 6. Invariant check

| Discipline | State | Evidence |
|---|---|---|
| Markup-only / server untouched | held | 0 api/ change all three steps; BI-9/wizard/consent tests UNMODIFIED + green |
| Payload-equivalence (wizard) | proven | the CI-wired test over the REAL reducer; OD-19 422 fires identically (live) |
| BI-9 server authority | untouched | 403 on same-person confirm OR reject; ManualPaymentTest green; disabled cue is defense-in-depth |
| No new money aggregate | held | stat-cards Σ over already-fetched reads; "today" deferred |
| Deliberate adoption | enforced | import-guard ALLOWED += exactly 3; every other surface byte-identical |
| Reconciliation battery | untouched | 58/58 every step (frontend-only; tokens minted-then-revoked; no demo data persisted) |

## 7. Hand-offs forward
- **The anchors validate DS2 end-to-end** — the components hold on airy product, trilingual/step-flow, and
  dense money surfaces over live data. Subsequent rollout cards restyle the remaining surfaces in the ruled
  order, each a gated slot adding exactly its file(s) to the import-guard ALLOWED.
- **成團 → "Team Formation"** rides the **Teams-surface** rollout card (Teams.tsx / StudentTeam.tsx) — the EN
  locale to pure English, zh-TC 成團 / zh-SC 成团 kept.
- **Candidate DS2 additions surfaced by real adoption:** a **StatCard** component (Payments composed one from
  tokens); a **confirmed-receipts / Attest** money view (deferred); a **"Confirmed today"** read field
  (a money-data backend line-item) if the summary wants a date filter.
