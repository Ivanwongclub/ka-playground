# SPRINT KAP-S05 — Teams, 成團, seat claim & the payment trigger

> PROMOTED 2026-07-27 on Leo Q1/Q2 verdicts (outbox approved; FOR SHARE + STATED BINDING LOCK ORDER approved; single_reader accepted). Replaces the individual-seat card.
> RECONCILED 2026-07-27 to what S04A/S04B actually shipped (PROPOSED-S05-REVIEW.md + 4 Leo rulings):
> the illegal `in_pool→confirmed` transition corrected to `teamed→confirmed`; the non-existent
> "capacity counter" made a real `programme_capacity` table sourced from an eligibility field;
> guardian_links added to the lock slot; STEP 2 VERIFY made family-paid-only; suspension placed on
> team_members. Threaded through the sections below.

## GOAL
A team forms in a lobby, submits when complete AND fully consented, and at approval claims its
seats atomically — the whole team or none. 成團 is the only place capacity is ever consumed and
the only thing that fires payment. Every wait state this sprint creates has a time-boxed exit.

## PRECONDITIONS
- [ ] S04B gate PASSED (PaymentTriggerPort + invoice machinery live, fixture-proven)
- [ ] OD-31..66 all resolved (done) · `docs/TEAM-CATEGORIES.md` canonical, unchanged

## IMPLEMENTS  OD-31/32 (seat claim) · OD-33 (deadline jobs) · OD-35..42 · OD-43 (firing) ·
OD-45 (consequences) · OD-53 (issuance at 成團) · OD-57/58 (consent gates 成團) · OD-61/62 ·
OD-15 (tenure ledger) · OD-64/66 · BI-3 (reshaped) · TEAM-CATEGORIES §4–§8 · Activity Tracker
(five fixed stages)

## 成團 — THE TRANSACTION (stated before build; revised per Leo Q1/Q2, 2026-07-27)
One database transaction at team approval — LOCKS AND STATE ONLY, no issuance inside it:
0. **HARD PRECONDITION (Leo, S04A gate): the programme's formation deadline (OD-33) must be
   SET before any 成團 runs — S04A treats absent dates as a pre-flight warning; S05 treats
   them as a refusal. No deadline, no formation.**
1. Approver authority per routing (OD-39); size within rules OR waiver field (OD-40).
2. **Consent re-verified INSIDE the transaction under `SELECT … FOR SHARE` (Q2).** A plain
   in-transaction read is NOT sufficient under READ COMMITTED — a supersede fan-out could commit
   between check and 成團-commit. The share locks serialise that race: a concurrent material-change
   supersede (`consent_requests SET status='superseded'`) blocks until 成團 resolves, or 成團
   re-reads and refuses on stale (OD-57/58). **LOCK ORDER — STATED, BINDING (Leo, 2026-07-27):
   FIRST slot — `FOR SHARE` on (a) each member's SIGNED `consent_requests` rows AND (b), when the
   programme sets `requires_all_guardians`, the members' active `guardian_links` rows (closes the
   stale-completeness TOCTOU — `consentSatisfied` reads both), ALL ordered by id; SECOND slot —
   `FOR UPDATE` on the `programme_capacity` row. Release together at commit. Every future edit
   preserves this order — the only guard against the deadlock; any step needing both acquires them
   in this order, no exceptions.**
3. `SELECT … FOR UPDATE` on the **`programme_capacity`** row → claim N seats for N members by
   `claimed := claimed + N` **only if `claimed + N ≤ capacity`, else "insufficient capacity" and
   the whole team fails — never partial, never overbooked (OD-32, BI-3 reshaped: one lock, N seats)**.
4. Member enrolments **`Teamed → Confirmed`** (S04A machine — members are already `teamed` from
   formation, STEP 1; `in_pool→confirmed` is NOT a legal transition, corrected from the pre-build draft).
5. **One `payment_obligations` OUTBOX row per member — written in this same transaction (Q1),
   `payer_party = 'guardian'`** (the family/online route; school-settled is an S04E batch concern,
   never S05 individual 成團). The payment OBLIGATION is atomic with the seat claim; the ISSUANCE is
   not — no order, no provider call, no `PaymentTriggerPort` invocation happens under the lock.
6. Audit: one 成團 event + per-member transitions, approver identity throughout (BI-8).
AFTER COMMIT: dispatch `ConsumePaymentObligations`; the S04B outbox consumer (system context) issues
the order + sets the OD-43 deadline clock + fires `PaymentRequested` per obligation — idempotent,
re-scanned on failure, `payment_obligations.completeness` asserting nightly that no Confirmed
enrolment outlives the issuance window without its order. **S05 reimplements NONE of this — it writes
the obligation row and dispatches the existing consumer.**
Concurrency verification mirrors S04A-old's twin test, team-shaped: two teams racing the last
seats — exactly one confirms, the loser fails whole with a clean refusal. A second race test
covers Q2: supersede-vs-成團 on the same member, both orders of arrival, no stale confirm.

## SCOPE CLASSIFICATION PLAN (read sets pre-stated)
| Table | Classification | Read set / justification |
|---|---|---|
| `programme_capacity` | **scoped (internal)** | NEW (S04A built no capacity by design — enrolment-as-intent). `programme_id` PK · `capacity` int · `claimed` int. The narrow row the 成團 tx locks FOR UPDATE. Read: system · finance/ops/audit. Write: system only (成團 claim; STEP 2 seeds it). **SOURCE (ruling 1): `capacity` is a REQUIRED field in the ELIGIBILITY wizard section, validated at publish (`> 0` AND `≥ min team size`); STEP 2 seeds the counter row from it. Post-publish edit: RAISING allowed; LOWERING below current `claimed` is BLOCKED.** `capacity.conservation` reads `claimed ≤ capacity` |
| `teams` | **scoped** | Read: system · ops/audit · members (student+guardians) · lobby's school admin · team-linked teacher · academy. Write: system flows; post-成團 membership changes ACADEMY-ONLY, reasoned, notified (OD-41); waiver reason a FIELD (OD-40). One lobby for life (TEAM-CATEGORIES) |
| `team_members` | **scoped** | Same read set; join/leave ceremonies pre-成團; paid-member removal only via withdrawal workflow (OD-41). Submitter transient; CEO a rotating role from the role library (OD-42). **Carries the member `status` incl. `suspended` (OD-45 non-payment): suspension lives HERE, not on the enrolment — the enrolment stays `confirmed` (ruling 4)** |
| `team_events` (history) | **scoped** | OD-41 visibility: every membership change visible in team history to members' families |
| `teacher_team_links` (OD-61) | **scoped** | Teacher ↔ TEAM (never students); created by school/academy admin; linked teacher may approve that team's gates; required before first stage gate; school-link removal blocked while mentoring (OD-60 guard) |
| `tenures` (OD-15) | **scoped** | Rotation recording, manual Phase 1; ledger is system-of-record for S08 badges |
| stage gates / tracker rows | **scoped** | Five fixed stages; gate approval by team-linked teacher, else school admin (OD-39/61) |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Formation in lobbies (TEAM-CATEGORIES §4–§8).** Create/join per assignment_rule; school-link
   enforcement inline; retired-lobby and revoked-link edge cases per §8. **Team JOIN transitions the
   member's enrolment `in_pool → teamed` (this is where teaming happens; 成團 in STEP 2 only does
   `teamed → confirmed`).** VERIFY: lobby resolution pastes; cross-lobby join refused; join → member
   enrolment observed at `teamed`.
2. **`programme_capacity` + 成團 (the transaction above) + approval routing (OD-39).** Create the
   `programme_capacity` table; add the eligibility `capacity` field + its publish validation (`>0`,
   `≥ min team size`) and the raise-ok/lower-below-claimed-blocked edit rule; seed the counter from
   it. Then 成團: school approves normal; academy handles exceptions and is notified; consent gate
   both modes (OD-57/58 — incl. the stale-consent block, live: material change → team blocked until
   re-consent). VERIFY: the twin-team race paste (both raw responses, exactly one confirms);
   unconsented-member refusal paste; stale-consent block paste; **family-paid obligation→order→
   `PaymentRequested` fired per member (family-paid ONLY — school-settled/invoice is S04E, not here)**;
   partial claim IMPOSSIBLE (paste the all-or-nothing); lower-capacity-below-claimed refused (paste).
3. **Deadline machinery (OD-33/35/36).** Auto-submit compliant at deadline; non-compliant →
   admin alert; matching screen (under-strength teams beside unplaced students): match / roll
   (PARKED, 90-day auto-refund backstop — the loop-breaker, SYSTEM actor) / release; failed
   assignment → flagged, academy-decided (OD-36). VERIFY: deadline job pastes incl. the 90-day
   backstop firing on fixture (full refund + release, audited).
4. **Team resilience (OD-37/38/45/62) + the non-payment LAPSE machinery.** Below-minimum after
   suspension → exception with FOUR terminal actions, grace extendable ONCE; dissolution → re-pool
   in-lobby, paid status kept, no re-charge; mid-programme school-leave → team stands + exception
   (OD-62). **OWNS the lapse-detection job (OD-45): a scheduled job scans FAMILY-PAID orders where
   `payment_due_at + grace < now` and unpaid → writes a SYSTEM-actor lapse audit event, SUSPENDS the
   member on `team_members` (status=`suspended`), and raises an FR066 exception; below-min after
   suspension routes to the OD-37 four-action exception.** This is the resolution machinery that
   makes `deadlines.no_silent_lapse` (STEP 6) assertable, not a permanent red. VERIFY: each action
   terminal (pastes); dissolved member's paid status survives re-pool (paste); **lapse job on a
   fixture past-due family order → SYSTEM-actor audit + team_members suspension + FR066 exception
   (paste).**
5. **Roles & tracker.** CEO/captain rotating role, tenure ledger entries (OD-15); five stages;
   gate approvals (teacher-linked or school admin). VERIFY: role rotation recorded; gate
   approval identity pastes.
6. **Audit element + assertions.**

## NON-SCOPE
Sessions/attendance (S06) · batch import (S04E) · team finance (S07) · badges (S08) · delivery
(S09) · QFPay (S-QFPAY).

## KEY VERIFICATIONS
Five-branch per scoped table (member guardian sees team · NON-member guardian in same programme
zero · other-school lobby isolation · Member zero) · the 成團 race paste is this sprint's
signature verification · all prior tags green each step.

## AUDIT ELEMENT
Team & Capacity Report — per programme: **`programme_capacity` capacity vs claimed** vs pool depth vs deadline;
成團 log with approver + seat math; exception ledger (below-min, parked roll-forwards with age
against the 90-day backstop); waiver register (reason field, OD-40); vouch-style per-school
approval usage.

## ASSERTIONS (--tag=S05)
- `capacity.conservation` — Σ seats claimed by confirmed teams ≤ programme capacity, per
  programme, always (the successor of the old free-seat assertion).
- `capacity.claims_are_whole` — every claim event's seat count = its team's member count at
  claim time; no partial claims exist.
- `teams.consent_complete_at_confirm` — no confirmed team has a member whose consent was
  unsatisfied or stale at 成團 (OD-57/58).
- `pool.no_expired_parking` — no parked roll-forward older than the backstop window without its
  auto-refund + release (OD-35, SYSTEM actor).
- `teams.size_or_waiver` — every confirmed team meets size rules OR carries a waiver reason
  (OD-40).
- 2.21 Withdrawal Cascade extends here: no Withdrawn enrolment holds membership or open tenure.
- `deadlines.no_silent_lapse` — **MOVED HERE FROM S04B (Leo ruling, 2026-07-27); registered at
  STEP 6 after STEP 4 builds its resolution.** EXACT PREDICATE: no FAMILY-PAID order past
  `payment_due_at + grace`, still unpaid, WITHOUT both (a) its SYSTEM-actor lapse audit event AND
  (b) a `team_members` suspension record OR an FR066 exception. Vacuous-aware until volume. The
  deadline clock is S04B's (`orders.payment_due_at`); the resolution (suspension on team_members +
  exception) is STEP 4's — so this is never a permanently-red trap (the R15 anti-pattern).

## EXIT GATE
Tests + `--tag=S05` + all prior tags green + the 成團 race paste + five-branch pastes + AUDIT.md,
gate commit.
