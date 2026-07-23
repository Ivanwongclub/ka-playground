# SPRINT KAP-S04A — Enrolment, seats, waitlist, orders & receipts

> S04 is split: S04A = the ordered happy path (enrolment → order → receipt).
> S04B = money's exception reality (payments, refunds, late/wrong/unmatched).

## GOAL
An enrolment can be created exactly once, hold exactly one seat under concurrency, produce an
immutable order with a gapless receipt path, and a freed seat goes to the waitlist — never to luck.

## PRECONDITIONS
- [ ] S03 gate PASSED · OD-6 (multi-guardian authority) confirmed — **STOP if open**

## IMPLEMENTS  2.7 · 2.8 (enrolment) · 2.18 · 2.22 · BI-3 · BI-4 · BI-5 · GR-linked FRs per register

## SCOPE
1. Enrolment state machine with guardian + consent preconditions (uses S01 links, S03 consent).
   Acting guardian recorded on every action (2.22); conflicting guardian actions → Academy Admin
   exception, never auto-executed.
2. **Seat locking (2.7 / BI-3)**: capacity check + insert in one transaction, `SELECT FOR UPDATE`
   on the programme counter.
3. **Idempotency (2.8 / BI-4)**: partial unique index — one enrolment per (student, programme)
   outside terminal states; duplicate submit returns the original.
4. **Waitlist lifecycle (2.18)**: Waiting → Offered(48h) → Accepted/Expired/Declined/Withdrawn.
   Seat release promotes head-of-queue inside the same lock; offer-expiry job releases to next.
5. Hold-window expiry job (deadline → terminal; seat release runs the 2.18 promotion).
6. Orders with immutable lines (BI-5) · gapless in-transaction receipt numbering (BI-2 — the
   sequence mechanism lands here; issuance against payments completes in S04B) · payer-party routing.
7. Student status timeline UI.

## NON-SCOPE
Payment recording, refunds, credit notes, all exception queues (S04B) · teams (S05) · sessions (S06).

## KEY VERIFICATIONS
- Two concurrent enrolments on capacity 1 (parallel test): exactly one wins; loser gets waitlist
  offer or clear "full" — paste both responses.
- Double-submit returns the original enrolment id (BI-4).
- Withdraw a seed enrolment (state change only; policy math is S04B) → head-of-waitlist becomes
  Offered inside the same transaction; 49h later fixture → Expired, next Offered.
- Receipt sequence probe: gapless under 50 parallel issuances.

## AUDIT ELEMENT (part 1 of the Financial Integrity Report)
Enrolment & Seat Report — state timeline per enrolment with acting guardian; seat ledger per
programme (capacity vs held vs waitlisted); waitlist history with offer outcomes.

## ASSERTIONS (--tag=S04A)
No free seat while a Waiting entry exists and booking is open · no Offered entry past expires_at ·
no enrolment past hold deadline in a non-terminal state · receipt sequence gapless · S01's
guardian-link assertion now fires non-vacuously.

## EXIT GATE  Tests + `reconcile:run --tag=S04A` (and S01) green + concurrency tests pasted. AUDIT.md, gate commit.
