# SPRINT KAP-S04F — School-settled consolidated invoicing (OD-25)

> **New card, split from S07 on 2026-08-01** (D-15) per `docs/sprints/S07/PROPOSED-S07-REVIEW.md`. This
> is the **S04B money-model domain** — school-payer *enrolment* finance — NOT the S07 team-project
> finance card. It is the single named hand-off inherited from the S04E gate (D-17: there is ONE
> hand-off, OD-25; the E6-payer wire is its load-bearing mechanism, not a separate item). It runs
> **now** (after S05/S06) because it consumes the **成團 orders** that did not exist at S04E time.
> Naming: continues the S04 money/enrolment family; rename if you prefer.

## GOAL
When a programme's payer is the **school** (OD-25 · programme E6 `payer_party='school'`), the school's
成團 orders aggregate into **one `(school, programme)` consolidated invoice** — a receivable the
academy is owed. Every invoiced amount traces to a covered order; nothing is invoiced without an order;
re-running never double-counts. All money is still received by the academy; the school is a payer,
never a collector (OD-25).

## PRECONDITIONS
- [ ] S04E gate PASSED (done — `252a8aa`) · S05 成團 pipeline live (obligations → orders) · OD-25 /
  OD-54 recorded · **OD-4 not required here** (that is S07/Track B)

## IMPLEMENTS  OD-25 · OD-54 (builds on) · OD-18 (minor units / currency) · FR-finance · the S04E `invoices.line_reconciliation` hand-off

## BUILDS ON (reuse — do NOT re-implement or re-home)
- `orders` / `order_lines` — immutable lines (BI-5); `status` already includes `covered_by_invoice`
  (defined, currently unwritten); `orders.consolidated_invoice_id` FK already exists.
- `consolidated_invoices` — the `(school_id, programme_id)` table already exists and is **scoped**
  (system · finance/audit · owning school admin). Currently **dormant** — only balance-updated by
  `WithdrawalSettlementService` and read by the FIR. S04F gives it an **issuance** path.
- `OrderService::issueForEnrolment(...)` — already creates a `school`-payer order (`status=issued`,
  `payment_due_at=NULL`) when handed `payer_party='school'` + `payer_school_id`. S04F does not change it.
- `PaymentObligationConsumer::consume()` — the outbox that turns an obligation into an order; the seam
  where S04F attaches a school order to its invoice.
- `WithdrawalSettlementService` — already keeps `balance_minor = original − Σ credit_notes` (OD-54);
  `invoices.balance` assertion guards it. S04F must not contradict this.
- **The BI-9 SoD refund/payment approvals are OUT OF SCOPE and untouched** (D-16).

## SCOPE CLASSIFICATION PLAN
No new tables. All three touched tables are **already scoped** (`orders`, `payment_obligations`,
`consolidated_invoices`) with existing RLS. No new migration is expected (all columns exist:
`orders.consolidated_invoice_id`, `covered_by_invoice`, `consolidated_invoices.*`). **`scope.coverage`
and `scope.public_context_confinement` must stay green — S04F adds no table and no public policy.**

## SCOPE (steps in this order; each = VERIFY + commit + stop)

1. **The E6-payer wire (A1) — make school-payer orders reachable.** Today both obligation-creation
   sites hardcode `payer_party='guardian'` (`TeamConfirmationService.php:122`,
   `TeamResolutionService.php:78`), so a `payer_party='school'` programme still mints a *guardian*
   obligation → family order → the invoice branch is **unreachable**. Wire both sites to resolve the
   payer from the programme's **E6 `payer_party`** via ONE mapping helper:
   - `parent → 'guardian'` (bridges the enum mismatch — programme says `parent`, orders/obligations
     say `guardian`; D-18) · `student → 'student'` · `school → 'school'` + `payer_school_id` = the
     student's active `school_links` roll.
   - **A `school` programme whose student has no resolvable school roll must NOT silently fall back to
     guardian** (that would drop the order from the invoice branch) — it raises a loud, audited failure
     / academy exception instead (D-18). The mapping is total and explicit; an unmapped E6 value throws.
   - **Assertion `obligations.payer_matches_programme` (D-18):** no `payment_obligation` (or its order)
     whose programme E6 is `school` carries a non-`school` `payer_party`, and vice-versa — an
     unmapped/mismapped E6 can never silently produce the wrong payer and drop a school order from the
     invoice branch.
   VERIFY: a `school` programme → a `school` obligation → a `school`-payer order (`covered_by_invoice`
   candidate, `payment_due_at=NULL`), pasted; a `parent`/`student` programme → unchanged
   (`guardian`/`student`), pasted; a `school` programme with a roll-less student → loud failure, no
   silent guardian, pasted; `obligations.payer_matches_programme` red→green teeth.

2. **Invoice issuance / aggregation (A2) — one invoice per (school, programme).** A new
   system-context `ConsolidatedInvoiceService`: when a **school**-payer order is issued (the
   `PaymentObligationConsumer` seam), **find-or-create** the `(school_id, programme_id)` invoice, attach
   the order (`orders.consolidated_invoice_id = invoice.id`, `orders.status = 'covered_by_invoice'`,
   audited `order.covered`), and set `original_amount_minor = Σ (total_amount_minor of the invoice's
   covered orders)`, `balance_minor = original − Σ credit_notes`. **Idempotent by construction** —
   original is RECOMPUTED from the covered-order set each run; an order already `covered_by_invoice`
   with a `consolidated_invoice_id` is never re-attached; one invoice per pair (find-or-create); one
   live order per enrolment (existing unique index). A covered order is a **receivable** — NOT `paid`,
   **no receipt** until the school actually pays (BI-2). `original_amount_minor` is **monotonic /
   write-once-then-grow** — corrections are credit notes, never edits (BI-5 extended to invoices).
   - **Assertion `invoices.line_reconciliation` (the S04E hand-off):** every consolidated invoice's
     `original_amount_minor == Σ (orders.total_amount_minor WHERE consolidated_invoice_id = invoice.id
     AND status = 'covered_by_invoice')`, same currency, integer minor units. Distinct from the
     existing `invoices.balance` (`balance = original − Σ credit_notes`); the two together close the
     chain order-line → order → invoice original → invoice balance.
   VERIFY: N school-payer orders for one (school, programme) → ONE invoice, `original = Σ orders`
   (paste); re-run the consumer → idempotent, no double-count, no second invoice (paste); a covered
   order carries NO receipt and is not `paid` (paste); `invoices.line_reconciliation` red→green teeth;
   five-branch (another school's admin sees only its own invoice); `invoices.balance` stays green.

3. **(FLAGGED — pending your ruling) Invoice aging → single academy exception (OD-55).** OD-55: a
   school invoice carries a due date + reminder ladder; past due + grace → **one academy exception**
   (extend terms or withdraw the cohort), students participate throughout, withdrawal never automatic.
   This is *aging*, downstream of *issuance*. **Is OD-55 in S04F, or its own follow-on?** OD-55 was
   gated "S06-batch" originally. My lean: **include it as STEP 3** (it completes the school-settled
   loop), but only on your say — it is not part of the OD-25 hand-off itself.

## NON-SCOPE
Any change to the **BI-9 refund/manual-payment SoD** approvals (D-16 — left alone) · team-project
finance (S07 / Track B) · real money movement / QFPay · the `parent`↔`guardian` rename across the
schema (the wire *bridges* it; a global rename is not this card) · new tables/migrations (none expected).

## KEY VERIFICATIONS
E6-payer wire is total and explicit — no silent guardian fallback for a school programme (D-18) ·
`obligations.payer_matches_programme` · one invoice per (school, programme), `original = Σ covered
orders` · idempotent re-run (no double-count, no second invoice) · covered order is a receivable (no
receipt, not paid) until the school pays (BI-2) · immutability: original monotonic, corrections are
credit notes (BI-5) · five-branch on the invoice · `invoices.balance` (OD-54) stays green ·
`scope.public_context_confinement` green · all prior tags green each step.

## AUDIT ELEMENT (Financial Integrity Report — the invoice-register half, now live)
The FIR already reads `consolidated_invoices`; S04F makes the register non-vacuous: invoices by
school/programme with `original / balance / status`, each invoice's covered-order list reconciling to
its original (order-line drill-down), and school-payer coverage vs family-paid split.

## ASSERTIONS (--tag=S04F)
- `obligations.payer_matches_programme` — **[STEP 1]** obligation/order payer is consistent with its
  programme's E6 payer_party (school↔school, parent→guardian, student→student); no silent mismap (D-18).
- `invoices.line_reconciliation` — **[STEP 2]** invoice original = Σ its covered orders' totals (the
  S04E hand-off). Complements the existing `invoices.balance` (OD-54).

## EXIT GATE
Tests + `--tag=S04F` + all prior tags green + STEP 1 E6-wire pastes (school/parent/student + roll-less
loud failure) + STEP 2 one-invoice/idempotent/receivable pastes + five-branch + `invoices.balance`
stays green + AUDIT.md (record: OD-25 hand-off CLOSED; the `parent`↔`guardian` bridge as a documented
mapping, not a rename; OD-55 aging status per your STEP-3 ruling), gate commit.
