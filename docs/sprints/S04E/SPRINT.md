# SPRINT KAP-S04E — Bulk enrolment (Spec Part H)

> New card per the approved 2026-07-24 re-plan (Leo change 2: Part H gets its own card, not a
> tail on S04D). Runs AFTER S04D. Position rationale: consolidated invoicing needs S04B's orders
> and receipts; batch rows need S04D's bulk-created students; batch enrolment needs S04A's seat
> and consent machinery. S04E is where all three meet, immediately before S05 teams consume the
> resulting enrolments.

## GOAL
A school administrator enrols a cohort in one auditable batch: CSV in, per-row outcomes out,
seats and consent and orders behaving exactly as they do for a single enrolment — and one
consolidated invoice to the school when the school is the payer (OD-25).

## PRECONDITIONS
- [ ] S04D gate PASSED · OD-25 recorded (school = payer, never collector) · client fee-terms
  answer applied in S04A step 6 (its outcome shapes the consolidated invoice's read set)

## IMPLEMENTS  Spec Part H (H1–H4) · 2.7/2.8/2.18 (per row, via S04A machinery) · OD-25 · OD-18 · FR066 (exceptions reuse)

## SCOPE CLASSIFICATION PLAN
| Table | Classification | Read set / justification |
|---|---|---|
| `enrolment_batches` | **scoped** | A school's cohort operation. Read: system · the owning school's admins · academy ops/finance/audit. Write: system (state machine H2: Draft → Validating → Ready → Committing → Complete \| Failed \| Partially Complete) |
| `enrolment_batch_rows` | **scoped** | Per-row child data (H3: Pending → Validated → Enrolled \| Skipped(reason) \| Failed(reason)). Same read set as the batch. Write: system. Row outcomes NEVER silently dropped — every non-Enrolled row carries its reason (P4) |
| `consolidated_invoices` | **scoped** | Money document addressed to a school (payer_party = school, OD-25 — the school PAYS, never collects). Read: system · finance/audit · the addressed school's admins. Write: system. OD-18 minor units + currency; lines snapshot per enrolment order |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Batch intake + validation (H1).** CSV upload through the S00 upload service (BI-10 — scanned
   before parsing, new `batch-csv` context, hard caps); server-side validation to Validated/
   Skipped per row (existing student matched by school roll, new student → S04D bulk creation
   path); dry-run report before commit. VERIFY: hostile CSV (formula injection, oversize,
   wrong-type) refused pastes; dry-run paste.
2. **Batch commit (H2/H3).** Row-by-row through the REAL S04A machinery — seat lock (2.7),
   idempotency (2.8), waitlist on full (2.18), consent issuance job per enrolment; batch is
   resumable, never half-silent; failures → per-row reasons + FR066 exception on batch failure.
   VERIFY: batch spanning capacity boundary — some Enrolled, overflow Waiting, reasons pasted;
   re-commit idempotent (no duplicate enrolments) paste.
3. **Batch dashboard (H4) + consolidated invoicing (OD-25).** School admin sees Active |
   Complete | Exceptions with per-row drill-down (2.28/Q4 3.4); when payer_party = school, one
   consolidated invoice aggregates the batch's orders (OD-18 fields; academy is the recipient of
   funds, always); guardian-payer rows bill individually as S04A built. VERIFY: invoice totals
   equal the sum of member order lines (paste); OD-18 schema paste; school admin of ANOTHER
   school sees zero (five-branch).

## NON-SCOPE
Payment recording against consolidated invoices (S04B machinery consumes them; if S04B gated
before this card, wire-up only — no new payment paths) · teams (S05) · any linkage flow (S04D).

## KEY VERIFICATIONS
Five-branch per scoped table · batch of N produces exactly N audited outcomes (no silent rows) ·
consent issuance fired per enrolled row (`consent.issuance_completeness` goes non-vacuous at
volume here) · all prior tags green each step.

## AUDIT ELEMENT (Financial Integrity Report, part 1b)
Batch ledger — batches by school/status/age; per-row outcome distribution; consolidated invoice
register with order-line reconciliation.

## ASSERTIONS (--tag=S04E)
- `batches.row_conservation` — every committed batch's rows sum to Enrolled + Skipped + Failed +
  Waiting, each non-Enrolled with a reason.
- `invoices.line_reconciliation` — every consolidated invoice total equals the sum of its member
  order lines (integer minor units, same currency).
- `batches.no_stuck` — no batch in Validating/Committing older than its job-timeout window.

## EXIT GATE
Tests + `--tag=S04E` + all prior tags green + capacity-boundary batch paste + invoice
reconciliation paste + five-branch pastes + AUDIT.md, gate commit.
