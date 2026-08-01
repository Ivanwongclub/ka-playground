# AUDIT KAP-S04F — School-settled consolidated invoicing (OD-25)

**Result:** PASS · **Date:** 2026-08-01 · **HEAD at gate:** `b8dc3a5`

> Written by Claude Code at the sprint's end. Honesty outranks looking good. This is the BUILD audit;
> the in-product audit element (the invoice register in the Financial Integrity Report) is separate.

## 1. What S04F is
The single named hand-off inherited from the S04E gate (OD-25). Split out of the S07 team-project
card (D-15) because it is a *different money domain* — school-payer **enrolment** finance, not team
budgets. When a programme's payer is the school (E6 `payer_party='school'`), its 成團 orders aggregate
into ONE `(school, programme)` consolidated invoice — a receivable the academy is owed (the school is
a payer, never a collector). A school's non-payment ages the **invoice**, never a child. Buildable now
because S05 成團 orders exist. All rulings recorded in `docs/sprints/S04F/SPRINT.md` +
`docs/sprints/S07/PROPOSED-S07-REVIEW.md`.

## 2. Files changed (20)
| Path | A/M/D | Why |
|------|-------|-----|
| `Services/Money/PayerResolver.php` + `UnresolvablePayerException.php` | A | STEP 1 — the ONE E6→payer mapping; total, throws on unresolvable school (D-18). |
| `Services/Teams/TeamConfirmationService.php` · `TeamResolutionService.php` | M | STEP 1 — both obligation sites resolve payer via the helper (hardcoded `'guardian'` removed). |
| `Services/Money/ConsolidatedInvoiceService.php` | A→M | STEP 2 — find-or-create the invoice, cover orders, recompute original; STEP 3 — due_at at issuance + re-read on the UNIQUE. |
| `Services/Money/PaymentObligationConsumer.php` | M | STEP 2 — the school seam: a school order is covered into its invoice. |
| `Services/Money/InvoiceAgingService.php` | A | STEP 3 — aging sweep + extendTerms/markPaid; writes ONLY `consolidated_invoices`. |
| `Console/Commands/RunInvoiceAging.php` + `routes/console.php` | A/M | STEP 3 — `invoices:age-school-settled`, scheduled daily 02:45 HKT (before the 03:00 reconciliation). |
| `database/migrations/2026_08_02_100000_invoice_aging.php` | A | STEP 3 — UNIQUE(school,programme) + `due_at` + status CHECK `issued\|paid\|overdue`. |
| `config/finance.php` | A | terms=30 / grace=7 (overridable). |
| `Reconciliation/Assertions/{ObligationPayerMatchesProgramme,InvoiceLineReconciliation,NoSilentOverdue}Assertion.php` | A | the 3 S04F assertions. |
| `Providers/ReconciliationServiceProvider.php` · `config/scope-elevations.php` | M | register the 3 assertions; allowlist coverOrder/aging elevations. |
| `tests/Feature/{PayerWire,InvoiceIssuance,InvoiceAging}Test.php` | A | 16 feature cases (213 assertions). |
| `tests/Feature/ReconciliationRunnerTest.php` | M | count guard 50→53. |

## 3. Step-by-step verification (real output, pasted)

### STEP 1 — the E6-payer wire · commit `830d8ad`
```
E6 parent  →  {"payer_party":"guardian","payer_school_id":null}
E6 student →  {"payer_party":"student","payer_school_id":null}
E6 school (on roll) →  {"payer_party":"school","payer_school_id":11}
E6 school (NO roll) => LOUD FAILURE (no silent guardian): … has 0 active school rolls …
```
Both obligation sites (成團 confirm + below-min assign) proven to write `school` at runtime; roll-less
school aborts (no silent guardian). `obligations.payer_matches_programme` green. Result: **PASS**.

### STEP 2 — consolidated invoice issuance · commit `d0a2f62`
```
invoices=1 original=500000 balance=500000        (2 × 250000 covered orders)
after re-cover: invoices=1 original=500000 (idempotent: yes)
line_reconciliation => PASS
```
One `(school,programme)` invoice; original RECOMPUTED from the covered set (idempotent); covered order
is a receivable — not paid, no receipt (BI-2). `invoices.line_reconciliation` + `invoices.balance`
green. Result: **PASS**.

### STEP 3 — invoice aging + UNIQUE · commits `bdb36a2`, `b8dc3a5`
```
after sweep: aged=1 invoice status=overdue
enrolment status => confirmed (must be confirmed)
order status => covered_by_invoice (must be covered_by_invoice)
lapse audits against the child => 0 (must be 0)
```
Students ENTIRELY out (structural: `InvoiceAgingService` writes only `consolidated_invoices`; behavioural:
live participating child untouched, zero lapse audits). `due_at` set once at issuance, immutable except
extendTerms. UNIQUE(school,programme) blocks double-create (coverOrder re-reads on violation). Terminal
fates extendTerms/markPaid. `invoices.no_silent_overdue` green. Result: **PASS**.

## 4. Assertions registered this sprint
| Assertion | Tag | First green run pasted? |
|-----------|-----|-------------------------|
| `obligations.payer_matches_programme` | S04F | Yes (STEP 1; §6) |
| `invoices.line_reconciliation` (the S04E hand-off) | S04F | Yes (STEP 2; §6) |
| `invoices.no_silent_overdue` | S04F | Yes (STEP 3; §6) |

## 5. Deviations from SPRINT.md
| Card said | Actually happened | Why |
|-----------|-------------------|-----|
| STEP 3 "FR066-family exception" for aging | Invoice **status is the ledger** (`overdue`) | Neither `team_exceptions` (enrolment-keyed) nor `onboarding_exceptions` (CHECK/no-resolve) hosts a `(school,programme)` invoice covering many enrolments — same mismatch as FR066. Status-is-ledger per D-13 (Leo ruling). |
| (none other) | — | The three think-first splits (D-15..D-18) were applied before build; no mid-build surprises. |

## 6. Exit gate
```
$ php artisan reconcile:run --tag=S04F
  PASS  obligations.payer_matches_programme  [OD-25 · D-18 · S04F STEP 1]
  PASS  invoices.line_reconciliation         [OD-25 · OD-18 · S04F STEP 2]
  PASS  invoices.no_silent_overdue           [OD-55 · S04F STEP 3]
RECONCILE PASS — 3 assertion(s), 3 passed, 0 failed

$ php artisan reconcile:run          # all prior tags + S04F
RECONCILE PASS — 53 assertion(s), 53 passed, 0 failed

$ php -d memory_limit=1G vendor/bin/phpunit --filter 'PayerWireTest|InvoiceIssuanceTest|InvoiceAgingTest'
OK (16 tests, 213 assertions)

$ php -d memory_limit=1G vendor/bin/phpunit --filter '/^(?!.*ClamAv).*/'   # full suite, ex-clamd
OK (408 tests, 4925 assertions)

$ php artisan schedule:list | grep invoices:age
  45  18 * * *  php artisan invoices:age-school-settled   # 02:45 HKT, before 03:00 reconciliation

# ClamAv INTEGRATION suite excluded — pre-existing infra flake (S10 acceptance item), not S04F:
kap-clamav-1  Up (unhealthy)
```
**Verdict:** PASS. Three S04F assertions green, full 53-assertion battery green, full suite (408) green
ex-clamd, 16 S04F feature cases green, the aging sweep scheduled. The only red is the ClamAv
**integration** suite (unhealthy local clamd) — infra, not S04F code. **The OD-25 hand-off is CLOSED.**

## 7. Notes & residual items
- **OD-25 hand-off (from S04E) — CLOSED.** School-payer orders now aggregate into `(school,programme)`
  invoices; `invoices.line_reconciliation` moved here from S04E and is green.
- **The `parent`↔`guardian` bridge** is a documented mapping in `PayerResolver`, asserted by
  `obligations.payer_matches_programme` — NOT a schema rename (out of scope by design).
- **Record-school-payment endpoint** is OUT (offline / record-only; `markPaid` provides the terminal
  transition, the trigger endpoint is a thin future wire — and NOT a QFPay path; QFPay is the
  family-paid gateway, Phase 2).
- **"Withdraw the cohort"** (OD-55) stays a **manual** academy op — never automatic (per OD-55).
- **FIR overdue breakdown:** the register reads `consolidated_invoices.status` already, so overdue
  invoices surface today; an *explicit* overdue breakdown block was **not** added (optional, per Leo's
  gate flag) — a candidate polish, not a gap.
- **S07 (Track B, team-project finance)** remains a separate card with its own think-first pass pending;
  the approval-engine consolidation is descoped (D-16).

## 8. Invariant check
| BI | Touched? | Evidence |
|----|----------|----------|
| BI-2 (receipts only on money received) | Yes — upheld | a `covered_by_invoice` order carries NO receipt and is not `paid` until the school settles (tested). |
| BI-5 (order lines immutable; corrections are new records) | Yes — extended to invoices | invoice `original` is monotonic/recomputed from immutable `order_lines`; corrections are credit notes, never edits. |
| BI-9 (refund/payment SoD) | **Untouched (D-16)** | S04F re-homed no BI-9 SoD approval; the working controls are left alone. |
| Child welfare (school-settled core) | Yes — the point | aging writes ONLY `consolidated_invoices`; a school's non-payment never lapses a child (structural + behavioural test). |
| Scope-elevation discipline | Yes | `ScopeElevationTest` green — payer/coverOrder/aging sites allowlisted with exact reasons. |
| Scope coverage | Yes | `scope.coverage` + `scope.public_context_confinement` green — no new table, no public policy. |
