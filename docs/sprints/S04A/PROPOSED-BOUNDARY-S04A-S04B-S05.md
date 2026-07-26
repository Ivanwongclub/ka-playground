# PROPOSED — S04A / S04B / S05 boundary under team-based capacity (OD-31/32/43)

> DRAFT for Leo's review, 2026-07-27. Planning only — no live card touched, nothing built.
> Inputs: OD-31..66 (workflow handoff as reconciled), OD-50a/50b (task-driven), OD-25/26,
> OD-48 (full fee/refund), 2.8/2.22 (retained), 2.7/2.18 (reshaped/superseded).

## The one-sentence split
**S04A owns the enrolment as INTENT (no money, no seats) · S04B owns the MONEY MACHINERY
(no trigger) · S05 owns the TEAM and fires everything at 成團 (seat claim + payment trigger).**

## Boundary table

| Concern | S04A | S04B | S05 |
|---|---|---|---|
| Enrolment lifecycle (student × programme, OD-63) | **OWNS** — states, idempotency (2.8), acting guardian (2.22), consent issuance via the S03 job | reads status | flips `In Pool → Teamed → Confirmed` via 成團 |
| Consent gate | **OWNS pool entry**: an enrolment enters the pool only when `consentSatisfied` (S03, OD-50a) | — | **OWNS 成團 gate**: consent completeness + no stale consent are submission preconditions (OD-57/58) |
| Awaiting-a-team pool (OD-34) | **OWNS** — the pool IS enrolments in state `In Pool`; no `waitlist_entries` table exists, ever | — | consumes: formation, matching screen (OD-35), assignment |
| Seats / capacity (OD-31/32) | **NOTHING** — no seat locking, no hold window (OD-11 superseded), no capacity check at enrolment; over-subscription is the pool's normal state | — | **OWNS the ONLY capacity mutation**: atomic multi-row claim at team approval, one `FOR UPDATE` on the programme counter, N seats, all-or-refuse (BI-3 reshaped) |
| Formation deadline (OD-33) | **OWNS config + ordering validation** (wizard field; pre-flight: enrolment close < formation < start; re-validated on edit) | — | **OWNS the jobs**: auto-submit compliant, alert non-compliant, unteamed resolution (OD-35/36), 90-day parked backstop |
| Orders, lines, receipts (BI-2/BI-5, OD-18/25/48) | — | **OWNS** — full fee only, gapless sequence, payer_party, immutable lines | calls issuance at 成團 |
| Payment trigger (OD-43) | — | **exposes `PaymentTriggerPort`** — order + `payment.requested` + deadline clock, callable, fixture-tested | **OWNS the firing**: 成團 (or late assignment) calls the port; family-paid → portal task (OD-50b); school-settled → consolidated invoice + covered-by-invoice (OD-53) |
| Payment recording | — | **OWNS** — PaymentProvider + MockProvider (OD-46); manual recording under BI-9 (OD-47); forwardable link (OD-44) | — |
| Non-payment cascade (OD-45) | — | **OWNS machinery** (grace → suspend jobs) | **OWNS consequences** (below-minimum exception, OD-37) |
| Withdrawal (BI-7, OD-26/48) | **OWNS the state machine** — request → academy-ops approval (fixed, OD-26), pastoral endorsement records (2.29) | **OWNS the money** — full refund only, 2.17, credit notes, school-settled precedence (OD-54) | **OWNS team side-effects** — member removal via withdrawal only (OD-41), below-min exception |
| Consolidated invoice (OD-53) | — | **OWNS machinery** (receivable, ≠ Paid, aging) | issues at 成團 (wired here; batch volume via S04E) |
| Scheduled-job SYSTEM actor (OD-64) | **ESTABLISHES the convention** (first deadline jobs; retrofits existing jobs' audit actor) | inherits | inherits |
| Teams, lobbies, roles, waivers, teacher links (OD-39/40/41/42/61) | — | — | **OWNS** |
| 成團 itself | — | — | **OWNS** — one transaction: consent gate → seat claim → status flip → trigger port |

## Port seams (each sprint testable without the next)
- `PaymentTriggerPort` (S04B → consumed by S05): S04B proves order issuance with a fixture
  trigger; S05 wires 成團 to it. Mirrors the S01 `EnrolmentStatusPort` precedent.
- Pool queries (S04A → consumed by S05): the matching screen reads S04A's pool state.
- Consent gate: both sprints call S03's `consentSatisfied` — one implementation, two gates.

## Explicitly dead under this boundary
`waitlist_entries` (OD-34) · the 7-day individual hold (OD-11's object is gone; its successor is
the OD-43 payment deadline) · seat locking at enrolment (2.7 reshaped into the S05 team claim) ·
pro-rata withdrawal bands (OD-48 — S02B band schema remains as unused data) · individual-seat
assertions from the old S04A card (replaced in the drafts).

## Open items surfacing at card review
- Fee-terms client question now gates **S04B** (consumer fee-read clause moved with orders).
- OD-33 deadline validation touches published-programme pre-flight — additive check, S04A.
- The payment link (OD-44) is a NEW anonymous READ surface — S04B card carries an
  anonymous-surface design section (token-scoped, initials-only, no RLS read policy for
  anonymous; service-side token lookup), reviewed like S04C's anonymous write.
