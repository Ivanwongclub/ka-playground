# SPRINT KAP-S07 — Team finance (record-only)

## GOAL
Budgets, transactions and verification for money the platform never touches — evidence-first, one
approval engine for everything.

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
