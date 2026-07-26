# PROPOSED SPRINT KAP-S05 — Teams, 成團, seat claim & the payment trigger

> DRAFT (2026-07-27) — rewrite to the boundary table: S05 owns the team lifecycle and the single
> moment everything converges — 成團: consent gate → atomic seat claim → status flip → payment
> trigger. Original S05 scope (lobbies, roles, tracker, tenure) retained. Not live until Leo
> approves.

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
1. Approver authority per routing (OD-39); size within rules OR waiver field (OD-40).
2. **Consent re-verified INSIDE the transaction under `SELECT … FOR SHARE` on each member's
   satisfying consent_request rows (Q2).** A plain in-transaction read is NOT sufficient under
   READ COMMITTED — a supersede fan-out could commit between check and 成團-commit. The share
   locks serialise that race: a concurrent material-change supersede blocks until 成團 resolves
   (or 成團 waits, re-reads, and refuses on stale — OD-57/58). Lock order is fixed (consent rows
   before the capacity counter) so the two writers cannot deadlock.
3. `SELECT … FOR UPDATE` on the programme capacity counter → claim N seats for N members
   **atomically — all or "insufficient capacity", never partial, never overbooked (OD-32,
   BI-3 reshaped: one lock, N seats)**.
4. Member enrolments `In Pool/Teamed → Confirmed` (S04A machine).
5. **One `payment_obligations` OUTBOX row per member — written in this same transaction (Q1):
   the payment OBLIGATION is atomic with the seat claim; the ISSUANCE is not.** No order, no
   invoice line, no provider call, no `PaymentTriggerPort` invocation happens under the lock.
6. Audit: one 成團 event + per-member transitions, approver identity throughout (BI-8).
AFTER COMMIT: the S04B outbox consumer (system context) issues orders / invoice lines per
obligation — idempotent, re-scanned on failure, `payment_obligations.completeness` asserting
nightly that no Confirmed enrolment outlives the issuance window without its money artifact.
Concurrency verification mirrors S04A-old's twin test, team-shaped: two teams racing the last
seats — exactly one confirms, the loser fails whole with a clean refusal. A second race test
covers Q2: supersede-vs-成團 on the same member, both orders of arrival, no stale confirm.

## SCOPE CLASSIFICATION PLAN (read sets pre-stated)
| Table | Classification | Read set / justification |
|---|---|---|
| `teams` | **scoped** | Read: system · ops/audit · members (student+guardians) · lobby's school admin · team-linked teacher · academy. Write: system flows; post-成團 membership changes ACADEMY-ONLY, reasoned, notified (OD-41); waiver reason a FIELD (OD-40). One lobby for life (TEAM-CATEGORIES) |
| `team_members` | **scoped** | Same read set; join/leave ceremonies pre-成團; paid-member removal only via withdrawal workflow (OD-41). Submitter transient; CEO a rotating role from the role library (OD-42) |
| `team_events` (history) | **scoped** | OD-41 visibility: every membership change visible in team history to members' families |
| `teacher_team_links` (OD-61) | **scoped** | Teacher ↔ TEAM (never students); created by school/academy admin; linked teacher may approve that team's gates; required before first stage gate; school-link removal blocked while mentoring (OD-60 guard) |
| `tenures` (OD-15) | **scoped** | Rotation recording, manual Phase 1; ledger is system-of-record for S08 badges |
| stage gates / tracker rows | **scoped** | Five fixed stages; gate approval by team-linked teacher, else school admin (OD-39/61) |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Formation in lobbies (TEAM-CATEGORIES §4–§8).** Create/join per assignment_rule; school-link
   enforcement inline; retired-lobby and revoked-link edge cases per §8. VERIFY: lobby resolution
   pastes; cross-lobby join refused.
2. **成團 (the transaction above) + approval routing (OD-39).** School approves normal; academy
   handles exceptions and is notified of every approval; consent gate both modes (OD-57/58 —
   incl. the stale-consent block, live: material change → team blocked until re-consent).
   VERIFY: the twin-team race paste (both raw responses); unconsented-member refusal paste;
   stale-consent block paste; payment fired per member (family-paid task + school-settled
   invoice line pastes); partial claim IMPOSSIBLE (paste the all-or-nothing).
3. **Deadline machinery (OD-33/35/36).** Auto-submit compliant at deadline; non-compliant →
   admin alert; matching screen (under-strength teams beside unplaced students): match / roll
   (PARKED, 90-day auto-refund backstop — the loop-breaker, SYSTEM actor) / release; failed
   assignment → flagged, academy-decided (OD-36). VERIFY: deadline job pastes incl. the 90-day
   backstop firing on fixture (full refund + release, audited).
4. **Team resilience (OD-37/38/45/62).** Below-minimum after suspension → exception with FOUR
   terminal actions, grace extendable ONCE; dissolution → re-pool in-lobby, paid status kept,
   no re-charge; non-payment consequence wiring; mid-programme school-leave → team stands +
   exception (OD-62). VERIFY: each action terminal (pastes); dissolved member's paid status
   survives re-pool (paste).
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
Team & Capacity Report — per programme: capacity vs claimed (by team) vs pool depth vs deadline;
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

## EXIT GATE
Tests + `--tag=S05` + all prior tags green + the 成團 race paste + five-branch pastes + AUDIT.md,
gate commit.
