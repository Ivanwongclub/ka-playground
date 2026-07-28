# PROPOSED — S05 draft reconciliation against built S04A/S04B

**Think-first pass, 2026-07-27. No code, no commits.** The S05 draft was approved before S04A/S04B
were built; this re-examines it against the ACTUAL shipped artifacts (schema pulled live from the
running DB, service code read from the tree). Every claim below is grounded in an observed artifact,
cited. Verdict: **the draft's core is sound — the outbox seam and the lock order match reality — but
there are 3 real drift points and 4 build-it-here gaps to reconcile before STEP 1.**

## Verdict at a glance
| Draft element | Against reality | Status |
|---|---|---|
| 成團 writes payment_obligations + S04B consumer issues after commit | Matches the built outbox exactly | ✅ CONFIRMED |
| Lock order: FOR SHARE consent rows → FOR UPDATE capacity counter | Correct; no deadlock; matches supersede's write path | ✅ CONFIRMED (with one secondary note) |
| Seat claim "In Pool/Teamed → Confirmed" | `in_pool → confirmed` is NOT a valid transition | ⚠️ DRIFT — must be `Teamed → Confirmed` |
| "programme capacity counter" (FOR UPDATE target) | No capacity column/table exists | ⚠️ GAP — S05 must build it (capacity source too) |
| `payment_obligations.payer_party` at 成團 | No payer_party on enrolments | ⚠️ GAP — state the rule (family = guardian; school = S04E) |
| deadlines.no_silent_lapse resolution in STEP 4 | Machinery is S05's, but "suspend" ≠ an enrolment state | ⚠️ REFINE — suspension on team_members; explicit lapse job |
| STEP 2 VERIFY "school-settled invoice line" | School-settled + invoice is S04E, not S05 | ⚠️ DRIFT — S05 individual 成團 is family-paid only |
| OD references (OD-31/32/33/37–42/43/45/57/58/61/62/64/66) | Match the applied (renumbered) register | ✅ CONFIRMED |

---

## 1. Does 成團 match the ACTUAL outbox? — ✅ CONFIRMED, reimplements nothing

**Built (observed):** `payment_obligations` (`id, enrolment_id, programme_id, student_id, payer_party,
payer_school_id, consumed_at, order_id`; unique `po_one_unconsumed` on `enrolment_id WHERE
consumed_at IS NULL`). `PaymentObligationConsumer::consume(?limit)` reads unconsumed rows ordered by
`created_at`, calls `OrderService::issueForEnrolment(payer_party, payer_school_id)`, marks
`consumed_at + order_id`, fires `PaymentRequested` for family payers. `ConsumePaymentObligations`
job dispatches it. `payment_obligations.completeness` asserts no Confirmed enrolment outlives the
window without its order.

**So S05's 成團 does exactly what the draft says and nothing more:** inside the transaction, INSERT
one `payment_obligations` row per member; after commit, `ConsumePaymentObligations::dispatch()`. The
issuance, deadline clock (`orders.payment_due_at`), and `PaymentRequested` event are all the
consumer's job, already built. **No order/receipt/link code is reimplemented in S05.** ✅

**GAP 1 — `payer_party` determination.** The obligation column is NOT NULL and there is no
`payer_party` on `enrolments`. The draft doesn't say where 成團 gets it. **Rule to state:** for S05's
individual (online) route, `payer_party = 'guardian'` (or `'student'` where the student is the
payer), `payer_school_id = null`. **School-settled is NOT an S05 concern** — it arrives only through
the S04E bulk batch, which sets `payer_party='school'`. This also fixes the STEP 2 VERIFY drift
(below).

## 2. FOR SHARE + FOR UPDATE lock order — ✅ CONFIRMED against the built tables

**The draft's binding order:** lock `consent_request` rows FOR SHARE (ordered by request id) FIRST,
THEN the capacity counter FOR UPDATE.

**Grounded against reality:**
- `consentSatisfied(programmeId, studentId)` (observed) reads `consent_requests WHERE status='signed'`
  (plus `guardian_links` when `requires_all_guardians`). So the FOR SHARE target is **precisely the
  member's `signed` consent_request rows** — the rows a supersede would flip.
- The supersede path `ConsentTemplateService::supersedeForLanguage` does `UPDATE consent_requests SET
  status='superseded'`. `FOR SHARE` on a row blocks a concurrent `UPDATE` of that row → the supersede
  **blocks until 成團 commits, or 成團 sees the superseded status and refuses (stale-consent, OD-58).**
- **No deadlock:** 成團 acquires consent rows → capacity counter, always in that order; the supersede
  fan-out touches only consent rows (never the counter); two 成團 txs for different teams lock
  disjoint member rows, then contend on the single counter row in the same order — no cycle. The
  "ordered by request id" within a tx prevents two txs sharing a member from crossing on consent
  rows. ✅

**SECONDARY NOTE (confirm, not a blocker):** under `requires_all_guardians`, `consentSatisfied` also
depends on `guardian_links`. A guardian-link revocation racing 成團 is NOT serialized by the
consent-row FOR SHARE. In practice guardian continuity (2.2/S01) routes a last-guardian revocation
through an exception that suspends enrolments, so it can't silently flip satisfaction under 成團 —
but STEP 2 should state whether to additionally `FOR SHARE` the student's `guardian_links` rows (same
lock-order slot, before the counter) or rely on the continuity exception. Recommend: lock
`guardian_links` too when `requires_all_guardians`, in the same first slot, ordered by id.

## 3. Seat claim against the ACTUAL enrolment states — ⚠️ DRIFT

**Built transition map (observed, `EnrolmentService::TRANSITIONS`):**
`in_pool → [pending_consent, teamed, withdrawn, released]` · `teamed → [in_pool, confirmed,
withdrawn]` · `confirmed → [active, withdrawn]`.

**The draft says 成團 does "In Pool/Teamed → Confirmed" (step 4).** But **`in_pool → confirmed` is NOT
a legal transition** — a member must be `teamed` first. This is a genuine drift, and the fix is
clean: **成團 confirms TEAMED members (`teamed → confirmed`).** Joining a team is the `in_pool →
teamed` transition, which happens at **formation (STEP 1)**, not at 成團. By the time a team is
submitted for 成團, all its members are `teamed`. So:
- STEP 1 (formation): a member joining a team → `in_pool → teamed`.
- STEP 2 (成團): all members `teamed → confirmed`, atomically.

**The atomic N-seat model still holds:** N teamed members → N `confirmed` transitions + N obligation
rows, one transaction, all-or-refuse against the counter (OD-32). ✅ Only the state label in step 4
needs correcting from "In Pool/Teamed" to "Teamed".

## 4. deadlines.no_silent_lapse — resolution EXISTS in STEP 4, but REFINE

**Deferred from S04B (Leo ruling b) because its resolution machinery is here.** The order's
`payment_due_at` is set by the outbox consumer at issuance (post-成團, family-paid only; school-settled
is `null`). So a "lapsed deadline" = a family-paid order past `payment_due_at`, unpaid.

**S05 STEP 4 (Team resilience, OD-45 non-payment cascade)** is where "grace → member suspended →
academy exception" lives — so the assertion DOES have real resolution to assert against, **no
permanently-red trap.** ✅ Two refinements before it's assertable:
- **"Suspend" is not an enrolment state.** The map has no `suspended`; `confirmed → [active,
  withdrawn]` only. **Member suspension lives on `team_members`** (an S05 table), not on the
  enrolment — the enrolment stays `confirmed`. So the assertion must check for a **team-membership
  suspension record AND/OR an FR066 exception**, keyed off the lapsed order, not an enrolment status.
- **STEP 4 must explicitly own the lapse-DETECTION job** (a scheduled job scanning family-paid orders
  where `payment_due_at + grace < now` and unpaid → SYSTEM-actor audit + suspend + exception). The
  draft's STEP 4 phrase "non-payment consequence wiring" is too vague to build the assertion against.
- **Exact assertion predicate to register at STEP 6:** *no family-paid order past `payment_due_at +
  grace`, still unpaid, without (a) its SYSTEM-actor lapse audit event AND (b) a team_members
  suspension or an FR066 exception.* Vacuous-aware until volume.

## 5. Step boundaries + what S04A/B built differently

**GAP 2 — the capacity counter does not exist.** Observed: `programmes` has **no** capacity/seat/max
column, and there is no counter table. S04A deliberately built NO capacity (enrolment-as-intent). So
S05 STEP 2 must **create the lockable counter** — recommend a dedicated `programme_capacity`
(`programme_id` PK, `capacity` int, `claimed` int) so the FOR UPDATE locks one narrow row, and the
`capacity.conservation` assertion reads `claimed ≤ capacity`. **And state where `capacity` comes
from** — it must be a programme config field (a wizard section value); it is not configured anywhere
today, so STEP 1 or the card must add it to programme config (S02B wizard) or STEP 2 seeds it. Flag
for the ruling: capacity as a new `basics`/`eligibility` wizard field vs a dedicated config surface.

**DRIFT — STEP 2 VERIFY over-reaches into S04E.** It lists "school-settled invoice line pastes". Per
GAP 1, S05 individual 成團 is **family-paid only**; the consolidated invoice + `covered_by_invoice` is
the S04E bulk path (consolidated_invoices were FIXTURE in S04B; live issuance is S04E). **Fix:** STEP 2
VERIFY pastes family-paid obligation→order→PaymentRequested only; drop the invoice-line paste (or note
it as exercised at S04E).

**Confirmed-sound (do not churn):** the 成團 transaction shape (locks+state only, issuance after
commit), the outbox seam, the twin-team race test, the supersede-vs-成團 race test, OD references,
the hard formation-deadline precondition, and steps 1/3/5/6 boundaries.

## Proposed reconciled step plan (for your approval before STEP 1)
| Step | Scope (reconciled) | Key reconciliation |
|---|---|---|
| **S05-1** | Formation in lobbies (TEAM-CATEGORIES §4–§8). **Team JOIN = `in_pool → teamed`.** Cross-lobby/school-link refusals. | Owns the `in_pool→teamed` transition (fixes drift #3's front half) |
| **S05-2** | 成團: create `programme_capacity` + its FOR UPDATE claim; FOR SHARE consent rows (+ guardian_links if requires_all) → counter; **`teamed → confirmed`**; N obligations (`payer_party='guardian'`); dispatch consumer after commit. | Builds the counter (GAP 2); correct states (drift #3); payer_party rule (GAP 1); family-paid VERIFY only (drift STEP-2) |
| **S05-3** | Deadline machinery (OD-33/35/36): auto-submit, matching screen, roll/park/release, 90-day auto-refund backstop. | Unchanged |
| **S05-4** | Team resilience (OD-37/38/45/62): **explicit lapse-detection job → SYSTEM-actor audit + `team_members` suspension + FR066 exception**; below-min four actions; dissolution re-pool. | Builds deadlines.no_silent_lapse resolution (point 4) |
| **S05-5** | Roles & tracker: CEO/captain rotating role, tenure ledger (OD-15), five stages, gate approvals. | Unchanged |
| **S05-6** | Audit element + assertions: register `capacity.conservation`, `capacity.claims_are_whole`, `teams.consent_complete_at_confirm`, `pool.no_expired_parking`, `teams.size_or_waiver`, **`deadlines.no_silent_lapse`** (predicate per point 4), 2.21 cascade extension. | deadlines assertion lands AFTER STEP 4 builds its resolution |

## Decisions I need from you before STEP 1
1. **Capacity source:** new wizard config field (which section?) vs a dedicated capacity surface — and confirm the `programme_capacity` counter-table shape.
2. **`requires_all_guardians` lock:** also `FOR SHARE` the student's `guardian_links` in the first lock slot, or rely on the continuity exception (2.2)? (Recommend: lock them.)
3. **Confirm** `payer_party='guardian'` for all S05 成團 members (school-settled deferred to S04E).
4. **Confirm** suspension lives on `team_members`, and the `deadlines.no_silent_lapse` predicate as stated in point 4.

None of these are code yet — they reconcile the draft to what shipped. On your rulings I update the S05 card, then build STEP 1.
