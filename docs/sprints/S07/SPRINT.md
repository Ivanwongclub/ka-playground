# SPRINT KAP-S07 — Team finance (record-only)

> **RECONCILED 2026-08-01** per `docs/sprints/S07/PROPOSED-S07-REVIEW.md` and Leo's rulings:
> **D-15** — this card is **team-PROJECT finance only** (team budgets / transactions / verification /
> P&L / charity). The **OD-25 school-payer enrolment consolidated invoicing** that the S04E hand-off
> parked here is a DIFFERENT domain (the S04B money model) and has been **split out to its own card,
> `S04F`** — done FIRST because it is small and closes the live S04E hand-off. This card (Track B) gets
> its **own think-first pass later**, separately.
> **D-16** — the "one approval engine" consolidation is **DESCOPED**. It would re-home 6 money-critical,
> DB-enforced approval mechanisms (incl BI-9 separation-of-duties, a fraud control) onto a new engine —
> exactly the change that risks the invariants. Six explicit verified DB-enforced mechanisms are SAFER
> than one clever engine for a fraud control. S07's team-finance approvals may be their own mechanism;
> the existing S04B/S05 money approvals are **left alone** (adapter-only at most, likely not even that).
> **OD-4** confirmed RESOLVED — the STOP precondition is clear (charity is a valid `project_type`).

## GOAL
Budgets, transactions and verification for money the platform never touches — evidence-first.
(Approval-engine consolidation DESCOPED — D-16. OD-25 invoicing SPLIT to S04F — D-15.)

## PRECONDITIONS  S06 gate PASSED · OD-4 (charity project_type) decided — **STOP if open**.

## IMPLEMENTS  Spec team-finance sections

## SCOPE
1. Budgets + approval · transactions with receipt upload (S00 service) · verification workflow.
2. Sponsorship/charity records (`project_type` per OD-4) · P&L reports with drill-down to evidence.
3. **Approval engine consolidation**: formation / gates / budgets / transactions / deliverables /
   refunds all on one engine by sprint end — refactor S04B/S05 approvals onto it.

## NON-SCOPE
Any real money movement · AP module (Phase 2) · QFPay.

## KEY VERIFICATIONS
- Verified entry without evidence → impossible (server-side), paste rejection.
- P&L drill-down reaches the uploaded evidence file for a seed transaction.
- Refund approval (S04B) now runs on the consolidated engine — regression suite green.

## AUDIT ELEMENT
**Team Finance Verification Report** — budget vs actual vs verified per team · unverified-entry
aging · approval chain per transaction · P&L export with evidence drill-down.

## ASSERTIONS (--tag=S07)
Budget actuals == sum of approved transactions · every Verified entry has evidence attached.

## EXIT GATE  Tests + tag + full-suite green (consolidation touched earlier sprints). AUDIT.md, gate commit.
