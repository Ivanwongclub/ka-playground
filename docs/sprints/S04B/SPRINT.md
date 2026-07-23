# SPRINT KAP-S04B — Payments, refunds & money exceptions

## GOAL
Offline money handled as it actually arrives: late, wrong, duplicated, contested — every path
closing in a defined state with evidence and segregation of duty.

## PRECONDITIONS
- [ ] S04A gate PASSED · OD-5 (partial payments) decided — **STOP if open** · OD-2 values seeded

## IMPLEMENTS  2.1 (workflow) · 2.8 (payments) · 2.17 · 2.19 · 2.20 · 2.21 (register) · BI-2 · BI-7 · BI-9

## SCOPE
1. Offline payment recording: evidence upload (S00 service), client idempotency key (2.8),
   **recorder ≠ confirmer (BI-9)**; confirmation issues the gapless receipt (BI-2).
2. **Withdrawal workflow (2.1 / BI-7)**: request (acting guardian per 2.22) → approval → policy-
   computed refund → Refund record opens. `Withdrawn` reachable only here.
3. **Refund state machine (2.17)**: Requested → Approved → Paid Out → Confirmed/Rejected; payout
   evidence mandatory; SoD; destination = original payer party; credit note on completion.
4. **Late Payment exception (2.19)**: recording against a non-recordable enrolment → exception;
   seat still free → admin reinstate; taken → refund via 2.17 + guardian notified.
5. **Wrong amounts (2.20)** per OD-5: underpayment → Unmatched + shortfall notice; overpayment →
   receipt at order amount + credit note or refund; `unmatched_payments` queue with aging + resolver.
6. Register the **Withdrawal Cascade assertion (2.21)** — extended by S05/S06/S09.

## NON-SCOPE
QFPay or any gateway (Phase 2) · team finance (S07) · notification channels (fire events only).

## KEY VERIFICATIONS
- Same recorder attempts to confirm own payment → server-side rejection (BI-9), paste.
- Withdrawal at each policy band fixture (before / pro-rata / after) → refund amounts match policy
  math exactly; paste computation inputs from the audit trail.
- Payment recorded against an expired enrolment: seat free → reinstate path; seat taken (waitlist
  promoted) → refund path. Both demonstrated.
- Duplicate payment submit with same idempotency key → original record returned.
- Direct status write to Withdrawn (test) → rejected (BI-7).

## AUDIT ELEMENT (completes the Financial Integrity Report)
Receipt sequence audit (gap probe) · payments-vs-receipts reconciliation · refund lifecycle with
evidence links · unmatched/late-payment queues with aging · who-recorded/who-confirmed listing.

## ASSERTIONS (--tag=S04B)
Receipts total == confirmed payments · every Active enrolment has signed consent + paid/waived order ·
every computed refund reaches terminal or is <14d old · every Confirmed refund has evidence · no
Unmatched >7d without resolver · no payment without recordable order or linked exception ·
**Withdrawal Cascade v1** (no Withdrawn enrolment with active team/tenure/booking/waitlist/ladder —
vacuous parts activate as later sprints land).

## EXIT GATE  Tests + `reconcile:run` FULL suite green (this sprint touches everything financial). AUDIT.md, gate commit.
