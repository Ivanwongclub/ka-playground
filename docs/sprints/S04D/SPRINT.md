# SPRINT KAP-S04D — Linkage approval, S01 retrofits & bulk creation (2.30)

> New card per the approved 2026-07-24 re-plan. Runs AFTER S04C (which shipped the
> pending_approval state and the queue this card feeds).

## GOAL
Every relationship on the platform reaches `active` only through an admin's audited decision
(2.30); the S01 ceremonies survive as mutual-intent evidence but complete nothing; school
vouching is the model's one named single-actor exception (OD-30) and is never silent (OD-24);
schools can create their students in bulk.

## PRECONDITIONS
- [ ] S04C gate PASSED · OD-24 rule confirmed in force · OD-30 recorded (done 2026-07-24)

## IMPLEMENTS  2.30 · OD-23 (point 5, 6) · OD-24 · OD-27 (flow transformation) · OD-30 · FR003 · FR005

## SCOPE CLASSIFICATION PLAN
| Table | Classification | Read set / justification |
|---|---|---|
| `guardian_links` / `teacher_links` / `school_links` (state machine) | already scoped | Gain `requested → pending_approval → active \| rejected` (guardian_links partially done in S04C). Existing ACTIVE links are backfilled `legacy-approved` in the migration, audited — the assertion below must not fire on history. Read sets unchanged; write policies amended so ONLY the approval decision (or system) activates |
| `link_visibility_events` (or notification-event reuse) | **scoped** | OD-24: every guardian-addition activation (INCLUDING vouched, OD-30) produces a visibility record addressed to EVERY existing guardian of the student. Read: system · the addressed guardian · ops/audit. Write: system. S09 delivers; the RECORD exists now — "never silent" must be assertable before channels exist |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **2.30 state machine across all three link tables** + backfill migration (`legacy-approved`,
   audited) + policy amendments. VERIFY: direct `active` insert refused at the DB in every
   non-system context; five-branch re-run on all three tables; backfill audit paste.
2. **S01 ceremony retrofit (OD-27):** pairing codes and parent-initiated email flow produce
   `pending_approval` after student confirmation — never `active`; queue rows appear with their
   ceremony origin. VERIFY: full pairing flow paste ending in pending_approval; approval →
   active + audit; rejection → rejected + audited reason.
3. **School vouch (OD-30) + OD-24.** Vouch = the vouching school admin's single audited act
   (initiation + approval) for students on their roll ONLY; vouch origin marked forever;
   **every guardian-addition activation — vouched included — writes visibility records to ALL
   existing guardians (OD-24, never silent)**; additional-guardian ceremonies require the
   existing guardian's initiating action. VERIFY: vouch paste showing origin + immediate
   activation + visibility records to each existing guardian; cross-school vouch refused;
   second-guardian addition WITHOUT existing-guardian initiation refused (paste).
4. **Bulk student creation by schools (OD-23 point 5).** School admin creates students on their
   roll via the retained system primitive (rows, not CSV ceremony — batches are S04E); accounts
   born unverified, invitation-verification per OD-29's bulk clause; school-student links created
   `active` by the creating school admin's act (their roll, their authority — same OD-30 basis,
   same audit). VERIFY: bulk create paste; created accounts cannot log in before verification;
   per-school report shows creations.

## NON-SCOPE
CSV batch intake, per-row states, batch dashboard, consolidated invoicing (S04E) · registration
forms/queue mechanics (S04C, done) · notification delivery (S09 — the records exist, delivery
follows).

## KEY VERIFICATIONS
Five-branch per touched table after every policy amendment · OD-24 visibility paste is the one
that matters most: a vouched second guardian appears to the FIRST guardian's session (their own
visibility record) while consent evidence isolation stays intact · all prior tags green each step.

## AUDIT ELEMENT
Linkage Approval Report — pending by school with age; ceremony-origin breakdown (pairing / email /
vouch / registration form / bulk); **per-school vouch usage (OD-30 exception visible to the
academy)**; OD-24 visibility coverage.

## ASSERTIONS (--tag=S04D)
- `links.no_active_without_approval` — every active link carries an approving decision or the
  audited legacy-approved backfill marker; no third path.
- `links.guardian_addition_visibility` — every 2nd+ guardian activation has visibility records
  for every guardian active at activation time (OD-24; vouched links included).
- `links.vouch_scope` — every vouched link's student was on the voucher's school roll at vouch
  time.
- S04C's assertions keep running; S01 guardian-coverage now exercises the new states.

## EXIT GATE
Tests + `--tag=S04D` + all prior tags green + the vouch/visibility pastes + five-branch pastes +
AUDIT.md (record OD-30 usage baseline), gate commit.
