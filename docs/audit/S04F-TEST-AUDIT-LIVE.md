# S04F TEST AUDIT — LIVE (observed against the running instance)

**Date:** 2026-08-01 · **HEAD:** S04F gate `a457776` · **Runner:** local (colima/Docker), app role
`kap_app` (NOSUPERUSER NOBYPASSRLS → RLS live), dev DB `kap` @ `127.0.0.1:54329`, test DB `kap_test`.
**Method:** same as every prior sprint's live audit (`docs/audit/S04E-TEST-AUDIT-LIVE.md` etc.) — each
S04F case is enumerated from the **committed** suite and run **live now**; the money-integrity +
child-welfare refusal cases are run as raw drives and their observations pasted, not summarised.
`PASS-OBSERVED / FAIL-OBSERVED / BLOCKED` — never PASS without an observation.

## 0. How the cases were run
- Behavioural cases: `php -d memory_limit=1G vendor/bin/phpunit --testdox --filter '<S04F classes>'`
  against `kap_test` (real HTTP kernel + RLS-enforcing runtime role). Queue is `sync`.
- Assertion cases: `php artisan reconcile:run --tag=S04F` + the full `reconcile:run`.
- First-class money/child-welfare: raw service drives on the dev DB under `kap_app` (RLS live) — the
  E6 payer mapping + roll-less loud failure, invoice aggregation + idempotency, the UNIQUE
  double-create refusal, the aging sweep with a live participating child, and the terminal fates.
- **clamd INTEGRATION suite excluded**: `kap-clamav-1` is `Up (unhealthy)` (clamd OOM in the 3.8 GB
  VM). **S04F touches no upload path** — the exclusion suppresses no S04F case; the real-daemon check
  is the named S10 acceptance item.

## 1. Behavioural cases — PASS-OBSERVED (live)
Aggregate live run of the three S04F classes: **16 tests, 213 assertions, OK** (0 failures).

### STEP 1 · PayerWireTest — 6/6 PASS-OBSERVED
✔ **resolver_maps_each_e6_and_throws_on_unresolvable_school** (§2.1) · ✔ **confirm_school_programme_writes_school_obligations** (site 1, §2.2)
· ✔ confirm_parent_programme_still_writes_guardian (unchanged) · ✔ **confirm_rollless_school_student_is_a_loud_failure** (§2.1)
· ✔ **assign_school_programme_writes_school_obligation** (site 2, §2.2) · ✔ payer_matches_programme_assertion_reds_on_a_mismap_then_greens. — all PASS-OBSERVED.

### STEP 2 · InvoiceIssuanceTest — 4/4 PASS-OBSERVED
✔ **school_orders_aggregate_into_one_invoice** (§2.3) · ✔ **recovering_an_order_is_idempotent** (§2.3)
· ✔ **another_schools_admin_cannot_read_the_invoice** (five-branch, §2.7) · ✔ line_reconciliation_reds_on_a_tampered_original_then_greens (§2.5). — all PASS-OBSERVED.

### STEP 3 · InvoiceAgingTest — 6/6 PASS-OBSERVED
✔ due_at_is_set_at_issuance_and_immutable_on_recompute · ✔ **unique_blocks_a_second_invoice_for_the_pair** (§2.4)
· ✔ **aging_marks_overdue_and_never_touches_the_enrolment** (child-welfare, §2.6) · ✔ **extend_terms_returns_to_issued_and_resets_the_clock** (§2.8)
· ✔ **mark_paid_is_the_resolved_on_pay_terminal_fate** (§2.8) · ✔ no_silent_overdue_reds_on_an_unaged_invoice_then_greens. — all PASS-OBSERVED.

## 2. First-class MONEY / CHILD-WELFARE observations (raw)

### 2.1 The payer wire refuses to guess — roll-less school → LOUD failure, no silent guardian
Raw resolver drive on dev (all four E6 branches + the loud throw):
```
E6 parent  →  {"payer_party":"guardian","payer_school_id":null}
E6 student →  {"payer_party":"student","payer_school_id":null}
E6 school (on roll) →  {"payer_party":"school","payer_school_id":14}
E6 school (NO roll) => LOUD FAILURE (no silent guardian): student 59 on school-paid programme 11 has 0 active school rolls — cannot resolve payer_…
```
**Observed:** the mapping is total; a school programme whose student has no single active roll throws
`UnresolvablePayerException` (with a `Log::critical` that survives rollback) rather than falling back
to guardian. The committed `confirm_rollless_school` test shows 成團 aborts with **0 obligations** and
the team left `submitted`. **PASS-OBSERVED.**

### 2.2 Both obligation sites route through the resolver
Committed tests, run live: `confirm_school_programme_writes_school_obligations` (the 成團 confirm
path) and `assign_school_programme_writes_school_obligation` (the below-min resolution path) — **each**
produces a `school` obligation with `payer_school_id`. **Observed:** neither path silently keeps
`guardian`; wiring only one would have been caught. **PASS-OBSERVED.**

### 2.3 Invoice idempotency — recompute-from-set, not blind-increment
Raw issuance drive (two school orders for one (school, programme), then a re-cover):
```
invoices=1 original=500000 balance=500000
after re-cover: invoices=1 original=500000 (idempotent: yes)
line_reconciliation => PASS
```
**Observed:** one invoice; `original` recomputed from the covered set — a re-cover leaves it unchanged
(no double-count). **PASS-OBSERVED.**

### 2.4 UNIQUE (school, programme) blocks a double-create
Raw drive — after `coverOrder` created the invoice, a raw second insert for the pair:
```
invoice created (one per pair)
double-create => REFUSED by UNIQUE(school,programme): SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique…
```
**Observed:** one-invoice-per-pair is DB-enforced, not resting on the serial consumer; `coverOrder`
re-reads the winner on the violation. **PASS-OBSERVED.**

### 2.5 line_reconciliation — original = Σ covered orders
`invoices.line_reconciliation` green live (§3); the committed test tampers the original and observes
red, then recompute → green. **PASS-OBSERVED.**

### 2.6 CHILD-WELFARE (the hardest) — a school's non-payment ages the INVOICE, never a child
Raw aging drive with a **live participating child** (confirmed enrolment, covered order), invoice
pushed 40 days overdue, sweep run:
```
after sweep: aged=1 invoice status=overdue
enrolment status => confirmed (must be confirmed)
order status => covered_by_invoice (must be covered_by_invoice)
lapse audits against the child => 0 (must be 0)
```
**Observed:** the invoice ages to `overdue`; the child's enrolment, order and (in the committed test)
team membership are ALL unchanged, with ZERO lapse audits against the child. Structural backing:
`InvoiceAgingService` writes only `consolidated_invoices` — there is no code path from invoice-overdue
to enrolment-lapsed. **PASS-OBSERVED.**

### 2.7 Five-branch — another school's admin reads zero invoices
Committed `another_schools_admin_cannot_read_the_invoice`, run live: under a non-owning school admin's
context the `(school,programme)` invoice count is 0 (RLS). **PASS-OBSERVED.**

### 2.8 Terminal fates — extendTerms and markPaid
Raw drive on the overdue invoice:
```
overdue status before => overdue
after extendTerms => issued (overdue→issued)
after markPaid  => paid (terminal)
```
**Observed:** `extendTerms` returns overdue→issued (clock reset); `markPaid` reaches the terminal
`paid`; `paid` is terminal (the committed test shows extendTerms on paid throws). Both write only the
invoice. **PASS-OBSERVED.**

## 3. Assertion-guarded cases — live `reconcile:run`
```
$ php artisan reconcile:run --tag=S04F
  PASS  obligations.payer_matches_programme  [OD-25 · D-18 · S04F STEP 1]   payer matches programme E6
  PASS  invoices.line_reconciliation         [OD-25 · OD-18 · S04F STEP 2]  original = Σ covered orders
  PASS  invoices.no_silent_overdue           [OD-55 · S04F STEP 3]          no school invoice past due+grace un-aged
RECONCILE PASS — 3 assertion(s), 3 passed, 0 failed

$ php artisan reconcile:run          # all prior tags + S04F
RECONCILE PASS — 53 assertion(s), 53 passed, 0 failed
```
All three S04F assertions **PASS-OBSERVED** live; red→green teeth re-observed in §1
(`*_reds_*_then_greens` cases). Full battery 53/53.

## 4. Summary counts
| Group | Cases | PASS-OBSERVED | FAIL-OBSERVED | BLOCKED |
|-------|-------|---------------|---------------|---------|
| STEP 1 — PayerWireTest | 6 | 6 | 0 | 0 |
| STEP 2 — InvoiceIssuanceTest | 4 | 4 | 0 | 0 |
| STEP 3 — InvoiceAgingTest | 6 | 6 | 0 | 0 |
| **Behavioural total** | **16** | **16** | **0** | **0** |
| First-class money/child-welfare drives (§2) | 8 | 8 | 0 | 0 |
| Assertions (`--tag=S04F`) | 3 | 3 | 0 | 0 |
| Full battery (`reconcile:run`) | 53 | 53 | 0 | 0 |

**Verdict: S04F LIVE = PASS.** 16/16 behavioural, 8/8 first-class money/child-welfare, 3/3 S04F
assertions, 53/53 full battery — every one observed, none asserted without an observation.

## 5. Divergences
- **No product divergence observed.** Every enumerated S04F case behaved as its committed test/drive
  specifies — including the child-welfare confinement (the invoice ages, never a child) both
  structurally (no write path to enrolments) and behaviourally (live child untouched, zero lapse audits).
- **Live-drive note (instrument, not defect):** the drives created synthetic `%@ex.test` accounts /
  `Inv */Ag */Uq *` schools / `IV*/AG*/UQ*` programmes + invoices on dev. `consolidated_invoices` and
  `programmes` have no DELETE policy for the runtime role, so app-connection teardown could not remove
  them; cleaned via **superuser** DELETE afterwards (audit rows retained — BI-1). Battery restored to
  **53/53**. Same instrument behaviour as prior live audits; not an S04F defect.
- **Infra (S10 hand-off):** the ClamAv **integration** suite is infra-blocked (`kap-clamav-1`
  unhealthy) and excluded. S04F touches no upload path, so nothing S04F is suppressed — the audit does
  not claim the live daemon passed; that check is the named S10 acceptance item.
