# CARD — The Three Anchors For Real (wizard · Payments · My Children)

Approved from `PROPOSED-ANCHORS-REAL.md` with Leo's rulings (2026-08-04). First card of the restyle rollout —
**the first card that changes a product surface's appearance.** Three steps, ascending risk, each its own
reviewed commit HELD; **do NOT batch the gated surfaces.**

## Build order (ascending risk)
- **STEP 1 — My Children (FRONTEND-SCAN).** The low-risk warm-up.
- **STEP 2 — Wizard (LINE-BY-LINE, payload-equivalence).** FormLanguageSwitcher must drive the IDENTICAL
  `saveSection` payload — a **NEW test** proves it, so OD-19 + marketing-completeness fire on the same data.
- **STEP 3 — Payments (LINE-BY-LINE, money).** BI-9 stays **server-enforced** (the restyle just renders the
  disabled/refused Confirm state; shown-not-hidden preserved).

## Behavior-preservation (per anchor)
- **My Children + Payments — MARKUP-ONLY.** The mutate calls, server authority, RLS reads and data shapes
  are UNTOUCHED, so the existing money/consent tests stay green **UNMODIFIED**. **If adopting DS2 requires
  changing a mutate call, an authority check, or a data shape → STOP and flag** (a restyle must not touch
  logic).
- **Wizard — INPUT MECHANISM changes, payload does not.** FormLanguageSwitcher replaces the stacked
  trilingual inputs but drives the **identical `saveSection` payload** (`{en,tc,sc}` per field). Server
  behavior preserved; only data-entry UI changes. Reviewed line-by-line at the payload-equivalence point,
  with a NEW test asserting the switcher → identical payload.

## Firm constraints (all anchors)
- The restyle changes **markup, not server authority** — BI-9 server-enforced, publish gate server-enforced,
  own-child reads unchanged.
- **Import-guard ALLOWED grows by exactly the three anchor files** (`SelfService.tsx`, `AdminProgrammes.tsx`,
  `Payments.tsx`); EVERY other product surface stays **byte-identical** (verify the guard passes with only
  these three added). `SelfService.tsx` also holds MyPayments/MyStudents — this card restyles **only
  MyChildren**; those stay unchanged (confirmed in the diff).
- **成團 → "Team Formation"**: NOT present in the three anchors (it lives in `Teams.tsx`/`StudentTeam.tsx` +
  the EN locale). So **this card touches no 成團 text** — the copy-standard rides the Teams-surface card.
  (Proposed treatment recorded in the PROPOSED §4: EN pure English; zh-TC keeps 成團, zh-SC keeps 成团.)

## STEP-3 note — the Payments summary stat-cards
Before STEP 3: check from source whether **Outstanding / Awaiting-confirmation / Confirmed-today** can be
computed from what the Payments reads ALREADY return. If yes → pure frontend, folded in. If they need a NEW
aggregate → that aggregate is a **separate line-item reviewed as a money-data backend read (line-by-line)**,
NOT folded silently into the restyle. State which at STEP 3.

---

## STEP 1 — My Children (this step)
Adopt **SubPanel/ZoneStack + Attest + StatChip** (and Seal/StatusTag) per the prototype, over the LIVE reads
(`/api/enrolments` grouped by child + per-enrolment `/my/students/{id}/consent-status` derivedStatus) —
**unchanged**. Set the surface to `data-density="product"`.
- **Consent is per-programme in the live data** (derivedStatus per student+programme — the authoritative
  read). So consent renders **per enrolment**: the advisory via **Attest** (attested → onViewRecord to the
  consent record; action → Review & sign) — not the prototype's per-child aggregate bar (which the
  per-programme reads don't give without extra fetching). Faithful to the reads; no new endpoint.
- Header per child: avatar (initials) + name + **StatChip [N programmes]** + a "View sessions" link.
  (Age/session-timing chips are OMITTED — that data is not in the reads.)
- Add `src/pages/SelfService.tsx` to the import-guard ALLOWED (the one STEP-1 adopter).
- **No data-shape change; no mutate/authority change.** Markup-only.

### STEP 1 verification
- The existing **My-Children behavior tests stay green UNMODIFIED** (markup-only proof) — `SelfServiceUxTest`
  is not touched.
- `ds2:import-guard` passes with **SelfService.tsx** added; no other surface adopts DS2.
- `tsc` clean · `npm run build` · i18n parity.
- The surface renders correctly on live data (screenshot).
- Risk shot: it is a **display surface** (reads only; the sole action is a Link to /consents) — **no
  shown-not-hidden write element → no risk shot required** (the render screenshot is the proof).
- **0 backend · 0 migrations · every other product surface byte-identical.** Commit HELD.
