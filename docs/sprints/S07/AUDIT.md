# AUDIT KAP-S07 — Team-project finance (record-only)

**Result:** PASS · **Date:** 2026-08-01 · **HEAD at gate:** `d5e7397`

> Written by Claude Code at the sprint's end. Honesty outranks looking good. This is the BUILD audit;
> the in-product audit element (the Team Finance Verification Report) is separate.

## 1. What S07 is
A **greenfield record-only ledger** for team-PROJECT money — a team spending its project budget with
scanned evidence, verified against offline reality by a second person, its P&L as portfolio evidence.
**WHOLLY SEPARATE** from the enrolment Order module (§A3/GR006 — programme fees and team funds never
mix). Track A (OD-25 school-payer invoicing) was split to S04F (D-15); the approval-engine
consolidation was DESCOPED (D-16 — the existing BI-9 SoD controls left alone). Domain design +
rulings in `docs/sprints/S07/PROPOSED-S07-TRACKB-REVIEW.md` and `SPRINT.md`.

## 2. Files changed (31 across four steps)
| Area | Files | Why |
|------|-------|-----|
| **Budgets (STEP 1)** | `team_budgets`/`budget_lines` migration + `budget_categories` seed · `TeamBudget`/`BudgetLine` · `BudgetService` · `BudgetController` · `BudgetApprovedProvenanceAssertion` | §P1 budget state machine; DB-enforced line immutability once Active (BI-5); teacher approval reusing S05 `approverKindFor` (read-only); the Plan-gate budget precondition. |
| **Transactions + verification (STEP 2)** | `team_transactions` migration (SoD CHECK + immutability trigger) · `TeamTransaction` · `TransactionService` · `TransactionController` · `VerifiedHasEvidenceAssertion` · `TransactionVerificationSodAssertion` | §P1 transaction state machine; evidence-before-submit; recorder≠verifier SoD (CHECK, not RLS WITH-CHECK); BI-5 immutability once recorded; over-budget FLAG. |
| **Sponsorship/charity (STEP 3)** | `team_fundraising` migration · `TeamFundraising` · `FundraisingService` · `FundraisingController` · `CharityNoDistributionAssertion` · (`TransactionService` OD-4 refusal, `TrackerService` Pitch precondition) | project_type + funding target; OD-4 charity no-distribution (app + path-independent assertion); Pitch gate reads target live. |
| **P&L + report (STEP 4)** | `FinanceReportController` · `BudgetActualsMatchAssertion` | Team Finance Verification Report (P&L, budget/actual/verified, aging, evidence drill-down); actuals reconcile to approved spend. |
| **Cross-cutting** | `TrackerService` (public `approverKindFor` + Plan/Pitch preconditions) · `scope-map`/`scope-elevations` · `ReconciliationServiceProvider` · `routes/api.php` · S05 test seeds | reuse S05 authority read-only; classify the new scoped tables; register 5 assertions. |

## 3. Step verification (real output, pasted)

### STEP 1 — budgets · `d6fddb5`
```
DRAFT line update => ALLOWED (editable)
ACTIVE line update => REFUSED by trigger: … budget_lines is immutable once the budget is active (BI-5) …
ACTIVE line delete => REFUSED by trigger
```
§P1 state machine (all edges audited incl the changes-requested loop); DB-enforced line immutability
(refuses superuser); Plan gate green only when budget Active; `finance.budget_approved_provenance`
red→green. S05 tracker regression green with the precondition. Result: **PASS**.

### STEP 2 — transactions + verification (the SoD core) · `a1f8997`
```
SoD (verifier=recorder) => REFUSED by CHECK: SQLSTATE[23514] … "tt_sod_check"
recorded amount change => REFUSED by trigger: … financial fields are immutable once recorded (BI-5)
```
Recorder≠verifier via a **CHECK constraint** (not RLS WITH-CHECK — the services write under system
elevation, which a WITH-CHECK's system arm would bypass; a CHECK binds every writer incl superuser) +
app 403. Evidence-before-submit → Verified-without-evidence structurally impossible. Over-budget →
422 without ack, recorded with ack. Result: **PASS**.

### STEP 3 — sponsorship/charity (OD-4) · `ed5a8f1`
Charity + expense + `beneficiary_member_id` → refused (422) at record; `finance.charity_no_distribution`
path-independent (forged charity-expense-to-member → red). Pitch gate reads the declared funding
target live (Σ verified income ≥ target); conditional on a target so S05 Pitch tests unaffected.
Result: **PASS**.

### STEP 4 — P&L + report · `d5e7397`
Report: P&L (Σ verified income − Σ verified expense, no cash position), budget/actual/verified per
line, unverified aging, approval chain + evidence drill-down; non-member → empty (RLS).
`finance.budget_actuals_match` (cross-team forge → red). Result: **PASS**.

## 4. Assertions registered this sprint (--tag=S07)
| Assertion | Step | First green pasted? |
|-----------|------|---------------------|
| `finance.budget_approved_provenance` | 1 | Yes (§6) |
| `finance.verified_has_evidence` | 2 | Yes (§6) |
| `finance.verification_sod` | 2 | Yes (§6) |
| `finance.charity_no_distribution` | 3 | Yes (§6) |
| `finance.budget_actuals_match` | 4 | Yes (§6) |

## 5. Deviations from SPRINT.md
| Card said | Actually happened | Why |
|-----------|-------------------|-----|
| Approval-engine consolidation | **DESCOPED** | D-16 — re-homing BI-9-enforced SoD onto a new engine risks the fraud control; the 6 existing money controls left alone. |
| `sponsorship_records`/`sponsorship_agreements` tables | **Folded into income transactions** (agreement = the transaction's evidence) | D-B8 (Leo-confirmed) — model money once; a second income table would duplicate the ledger and break P&L single-source. `team_fundraising` holds project metadata only. |
| `finance.ledger_separation` (optional) | **Not built** | The separation is structural/by-construction (no shared columns; distinct `App\Services\Finance` namespace) — a data assertion would be vacuous. The structure IS the enforcement. |
| "Finance Manager" seed in role_library | **Not a global seed** | `role_library` is per-programme config; STEP 1's approver is the teacher (spec:54). Finance Manager is a per-programme role (STEP 2 chain). |

## 6. Exit gate
```
$ php artisan reconcile:run --tag=S07
  PASS  finance.budget_approved_provenance   [FR061 · Spec §P1 · STEP 1]
  PASS  finance.verified_has_evidence         [FR061 · BI-10 · STEP 2]
  PASS  finance.verification_sod              [FR061 · D-16 · STEP 2]
  PASS  finance.charity_no_distribution       [OD-4 · FR057 · STEP 3]
  PASS  finance.budget_actuals_match          [FR061 · spec:1776 · STEP 4]
RECONCILE PASS — 5 assertion(s), 5 passed, 0 failed

$ php artisan reconcile:run          # all prior tags + S07
RECONCILE PASS — 58 assertion(s), 58 passed, 0 failed

$ php -d memory_limit=1G vendor/bin/phpunit --filter 'BudgetTest|TransactionTest|CharityFundraisingTest|FinanceReportTest'
OK (17 tests, 435 assertions)

$ php -d memory_limit=1G vendor/bin/phpunit --filter '/^(?!.*ClamAv).*/'   # full suite, ex-clamd
OK (425 tests, 5365 assertions)

# ClamAv INTEGRATION suite excluded — pre-existing infra flake (S10 acceptance item). S07 uses the
# evidence upload via BI-10 but proves it on the EicarOnlyScanner double, like every other sprint:
kap-clamav-1  Up (unhealthy)
```
**Verdict:** PASS. Five S07 assertions green, full 58-assertion battery green, full suite (425) green
ex-clamd, 17 S07 feature cases green. The only red is the ClamAv **integration** suite (unhealthy
local clamd) — infra, the S10 item.

## 7. Invariant check
| BI / control | Held? | Evidence |
|--------------|-------|----------|
| **New SoD (recorder ≠ verifier)** | Yes — DB CHECK + app 403 | `tt_sod_check` (superuser-proof) + `verify_refused` audit; `finance.verification_sod`. On a NEW table (D-16). |
| BI-5 (immutable once committed) | Yes — extended twice | budget_lines immutable once active (trigger); team_transaction financial fields immutable once recorded (trigger). Both refuse superuser. |
| BI-10 (evidence scanned) | Yes | receipts ride the `evidence` context; `finance.verified_has_evidence` requires scan-clean. |
| **BI-9 (existing money SoD)** | **Untouched (D-16)** | S07 re-homed nothing; payments/refunds controls left alone. |
| **Ledger separation (§A3/GR006)** | Yes — structural | `App\Services\Finance` references no Order-module table; team-finance tables carry no order/receipt column. |
| Scope-elevation discipline | Yes | `ScopeElevationTest` green — Budget/Transaction/Fundraising `elevated` sites allowlisted. |
| Scope coverage / public confinement | Yes | 4 new scoped tables + 1 seeded reference classified; `scope.public_context_confinement` green. |
