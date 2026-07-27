# AUDIT KAP-S04A — Enrolment as intent, consent gating & the awaiting-a-team pool

**Result:** PASS · **Date:** 2026-07-27 · **HEAD at gate:** gate commit; last content commit `2947c37`

> Steps 3–6 ran unattended under Leo's standing rules (2026-07-27): per-step VERIFY + commit,
> STOP conditions honoured, judgment calls surfaced not assumed. Steps 1–2 were reviewed live.
> All three judgment calls were ACCEPTED at Leo's review (see §4).

## 1. Scope shipped
The first sprint on the team-based-capacity model (OD-31, handoff-reconciled): enrolment as
INTENT — no seat, no money, no capacity anywhere in this sprint. Consent-INSERT narrowing
(S03 §5 item 4 reversal) · enrolment state machine with the pool as a STATE (OD-34) · the
design-answer issuance job · consent gate BOTH directions · OD-33 formation-deadline config +
ordering validation · withdrawal workflow state-only (BI-7, OD-26 fixed approver) · OD-64
SYSTEM-actor convention · OD-66 events · trilingual surfaces + Enrolment & Pool audit element ·
five S04A assertions.

## 2. Step-by-step verification (evidence pasted to Leo per step; commits carry it)
```
STEP 1 (0f4f4f5): ops-context INSERT → "new row violates row-level security policy for
  table consent_requests"; system-context contrast INSERT 0 1 (rolled back); route 404 vs
  void 401; 175 passed. Reviewed live by Leo before commit.
STEP 2 (ce43a8e): live HTTP enrol → 201 (no consent fields) → requests to BOTH guardians →
  audit submitted→pending_consent→in_pool; gate BOTH directions (supersede pulls back);
  BI-4 duplicate = original incl. co-guardian; OD-63 independence; guardian-context UPDATE = 0
  rows; five-branch enrolments. 184 passed. Reviewed live by Leo before commit.
STEP 3 (f21410c): misordered dates block publish; PARTIAL dates block; post-publish edit
  breaking order refused at save (A12); absent dates warn only. 188 passed.
STEP 4 (7fced3f): full chain request→endorse(recorded, status untouched)→ops decision→system
  job→Withdrawn, audited per hop; config-only admin 403 (OD-26); co-guardian cancel → 409 +
  withdrawal.conflict_referred (OD-6, never auto-executed); guardian AND ops direct UPDATE →
  0 rows (BI-7); five-branch withdrawal_requests. 194 passed.
STEP 5 (ab160be): live dev row actor_id=NULL actor_role=system (OD-64); human attribution
  untouched by the fallback (tested); EnrolmentTransitioned per transition (OD-66). 197 passed.
STEP 6 (2947c37): five assertions registered and green (--tag=S04A 5/5); pool report +
  timeline UI trilingual; i18n parity; bundle budget PASSED.
GATE battery (this commit): 197 passed (1734 assertions) · reconcile FULL 18/18 ·
  --tag=S02A 2/2 · --tag=S03 3/3 · --tag=S04A 5/5 · migrate --pretend: Nothing to migrate ·
  tsc clean · bundle-budget PASSED.
```

## 3. Formal supersessions recorded at this gate
- **OD-11 (7-day individual hold window): SUPERSEDED.** Its object — an individual seat held
  at enrolment — no longer exists under OD-31. Successor: the OD-43 payment deadline running
  from 成團 (S04B/S05).
- **Amendment 2.18 (individual waitlist lifecycle): SUPERSEDED** by OD-34 — the awaiting-a-team
  pool is a state on enrolments; `waitlist_entries` was never created and never will be.
- 2.7's FOR-UPDATE discipline SURVIVES reshaped: the lock moves to S05's 成團 multi-row claim
  (BI-3, stated binding lock order on the S05 card).

## 4. Deviations, judgment calls & notes
- **Three autonomous judgment calls, all ACCEPTED by Leo at review:** (1) OD-33 dates
  all-or-none with absent-as-WARNING (requiring them would retroactively break published
  fixtures); S05 now HARD-REQUIRES the deadline as an explicit 成團 precondition (card updated
  at this gate). (2) Teacher endorsement deferred to S05 (team-linked teachers do not exist
  yet); `endorser_role` column seated for it. (3) Withdrawal cancel authority = requester only;
  any other guardian's cancel is an OD-6 conflict referral.
- **Previously-green tests touched (2), both mechanical, both flagged when touched:**
  (1) AuthLifecycleTest + ThrottlingTest expected 501 from the GET /enrolments stub they had
  borrowed as an authenticated endpoint — the stub became the real RLS-shaped surface in
  STEP 2, expectations 501→200. (2) ReconciliationRunnerTest totals 13→18 — the five S04A
  assertion registrations (same cause as the S03 10→13 change).
- One transient red during STEP 6: reconcile ran before the dev migration and scope.coverage
  flagged the mapped-but-absent enrolments table — correct behaviour, green after migrating.
- **Elevation review (gate ritual): NO new asSystem sites this sprint** — nine entries,
  unchanged from S03. The design answer held: enrolment writes into consent territory ride
  the queue's structural system context, not elevations.
- Notification events (OD-66) are RAISED (EnrolmentTransitioned, WithdrawalDecided); delivery
  is S09's. SR019 delivery gating untouched.

## 5. Leftovers & carried risks
| # | Item | Severity | Sprint |
|---|------|----------|--------|
| 1 | S05 must hard-refuse 成團 without a formation deadline (card precondition added at this gate) | High | S05 |
| 2 | Teacher endorsement branch on withdrawal_requests (endorser_role seated) | Medium | S05 |
| 3 | payment_obligations outbox + completeness assertion per Q1 verdict | High | S04B |
| 4 | Carried from S03: timestamp trust · PDF/A strictness · R15 placeholder-gone · bootstrap credential rotation | High | S10 |
| 5 | OD-67 fee ruling to be confirmed together at S04B STEP 1 (Leo, at this gate) | High | S04B |
