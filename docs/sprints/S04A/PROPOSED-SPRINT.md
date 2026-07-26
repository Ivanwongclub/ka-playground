# PROPOSED SPRINT KAP-S04A — Enrolment as intent, consent gating & the awaiting-a-team pool

> DRAFT (2026-07-27) — replaces the committed S04A card, which was written to the individual-seat
> model. Rewritten to team-based capacity per OD-31/32/34/43 and the boundary table
> (PROPOSED-BOUNDARY-S04A-S04B-S05.md). Not live until Leo approves.

## GOAL
An enrolment is an INTENT, created exactly once per student × programme (OD-63), gated into the
awaiting-a-team pool by consent — never by a seat. No money moves, no seat is claimed, nothing
here touches capacity: seats belong to teams at 成團 (S05), payment follows the trigger (S04B).
The S03 consent-INSERT widening is reversed here, as named.

## PRECONDITIONS
- [x] S03 gate PASSED (`9dc6fbd`) · OD-31..66 applied + reconciled (`9ccf780`) · Model B closed
- [x] OD-26 confirmed (withdrawal approver fixed to academy operations)
- No client-question dependency: the fee-terms question moves to S04B with the orders.

## IMPLEMENTS  2.8 · 2.22 · OD-31 (the "no seat at enrolment" half) · OD-33 (config+validation) ·
OD-34 · OD-43 (deadline field only) · OD-63 · OD-64 · OD-65 · OD-66 (events) · BI-4 · BI-7 (state
machine) · BI-8 · S03 §5 item 4 (consent-INSERT narrowing)

## ENROLMENT STATE MACHINE (E5 reshaped for team capacity)
`Submitted → Pending Consent → In Pool → Teamed → Confirmed → Active → Completed`
· terminal exits: `Withdrawn` (workflow only, BI-7) · `Released` (OD-35/36 outcomes) ·
`Completed` is terminal — no post-completion workflow (OD-65).
- `Pending Consent → In Pool` fires automatically when `consentSatisfied` (S03, OD-10/OD-50a).
- `In Pool → Teamed → Confirmed` are S05's transitions (成團); `Confirmed → Active` is S04B's
  (payment / covered-by-invoice). S04A ships the machine and guards every transition's writer.
- THE POOL IS A STATE, NOT A TABLE: `In Pool` = consented, unteamed. One queue (OD-34). No
  `waitlist_entries`, no hold window, no seat columns.

## SCOPE CLASSIFICATION PLAN (read sets pre-stated)
| Table | Classification | Read set / justification |
|---|---|---|
| `enrolments` | **scoped** | A child's participation intent. Read: system · ops/audit · the student · their ACTIVE guardians · school_admin of the student's school. Finance reads arrive with orders (S04B clause). Write: system state machine only; every transition audited with acting guardian (2.22) or SYSTEM actor (OD-64). Partial unique (student, programme) outside terminal states (2.8/BI-4) |
| `withdrawal_requests` | **scoped** | Read: system · ops/audit · the acting guardian · the student · school_admin + team-linked teacher (pastoral view per 2.29 — status and endorsement, never money). Write: system; approval decision = academy operations only (OD-26); pastoral endorsements are non-authoritative records |
| `consent_requests` (policy change) | already scoped | **INSERT narrows to system-only — STEP 1, the reversal S04A was named for** |
| programme config (formation deadline, OD-33) | wizard field on existing tables | Ordering validated at pre-flight AND on post-publish edit: enrolment close < formation deadline < programme start |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Consent-INSERT narrowing (S03 §5 item 4).** Migration drops the ops branch from `cr_insert`;
   manual issuance endpoint removed; S03 fixtures migrate to a system-context helper; void→
   re-issue re-routed through the issuance job. VERIFY: ops-context INSERT refused at the DB
   (paste); removed route 404 (paste); S03 suite + `--tag=S03` green.
2. **Enrolment creation + state machine + consent gate.** Guardian creates (acting guardian
   2.22, OD-6 conflict → exception, never auto-executed); S03 issuance job fires per active
   guardian; `consentSatisfied` → `In Pool` automatically; OD-63 independence (two programmes =
   two fully independent enrolments). NO capacity check — over-subscription is normal. VERIFY:
   enrol → requests to every guardian (paste); sign → automatic `In Pool` transition with audit
   (paste); duplicate submit returns the ORIGINAL id (BI-4 paste); two-programme independence
   paste; enrolment response carries no consent data.
3. **Formation-deadline config + ordering validation (OD-33).** Wizard field; pre-flight error on
   ordering violation; re-validated on post-publish edit. VERIFY: bad ordering blocked at
   publish AND at edit (pastes).
4. **Withdrawal workflow (BI-7, state only).** Request (guardian, reasoned) → pastoral
   endorsement records (2.29, non-authoritative) → academy-ops approval (OD-26) → `Withdrawn` +
   pool/team notification event. Money = S04B; team side-effects = S05 (ports stubbed). VERIFY:
   no direct status write path (DB paste); non-ops approval refused (paste); full audit chain.
5. **OD-64 SYSTEM-actor convention + OD-66 events.** Scheduled/queued jobs write audit events
   with a SYSTEM actor, never null — convention + retrofit of existing jobs (consent issuance,
   scan, generation); every S04A transition raises its catalogued notification event (fired,
   not delivered — S09). VERIFY: job-driven transition audit paste showing SYSTEM actor;
   event-raise paste.
6. **Pool & status surfaces + audit element.** Guardian/student timeline UI (E5 reshaped);
   school-admin pool view; trilingual; budget green.

## NON-SCOPE
Seats, capacity, 成團 (S05) · orders, payments, refund money, invoices (S04B) · teams (S05) ·
batch (S04E) · matching screen (S05, OD-35) · notification delivery (S09).

## KEY VERIFICATIONS
Five-branch per scoped table (enrolments: guardian sees own students' · co-guardian same student
sees · other guardian zero · student own · school_admin of school; other-school zero · Member
zero; withdrawal_requests likewise + teacher branch) · consent-narrowing pastes · all prior tags
green each step.

## AUDIT ELEMENT
Enrolment & Pool Report — state timeline per enrolment with acting guardian; pool depth per
programme (consented-unteamed count vs capacity vs formation deadline); consent-issuance health
(carried from the old card); withdrawal pipeline with approver identity.

## ASSERTIONS (--tag=S04A)
- `enrolments.one_per_student_programme` — 2.8/BI-4 partial-unique holds (probe).
- `enrolments.pool_integrity` — every `In Pool` enrolment has consent satisfied; every
  `Pending Consent` one does not (the gate never leaks).
- `enrolments.no_status_bypass` — every transition has its audit event with an actor (human or
  SYSTEM, never null — OD-64); `Withdrawn` rows all trace to an approved withdrawal request.
- `consent.issuance_completeness` — carried from the old card (every non-terminal enrolment's
  guardians all hold requests).
- `deadline.ordering` — no published programme violates enrolment close < formation < start.

## EXIT GATE
Tests + `--tag=S04A` + all prior tags green + five-branch pastes + narrowing pastes + SYSTEM-actor
paste + AUDIT.md (record: OD-11/2.18 formally superseded here), gate commit.
