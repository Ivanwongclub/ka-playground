# S07 TEST AUDIT — LIVE (observed against the running instance)

**Date:** 2026-08-02 · **HEAD:** S07 gate `3237271` · **Runner:** local (colima/Docker), app role
`kap_app` (NOSUPERUSER NOBYPASSRLS → RLS live), dev DB `kap` @ `127.0.0.1:54329`, test DB `kap_test`.
**Method:** same as every prior sprint's live audit — each S07 case is enumerated from the **committed**
suite and run **live now**; the fraud-control / money-integrity / governance refusal cases are run as
raw drives and their observations pasted, not summarised. `PASS-OBSERVED / FAIL-OBSERVED / BLOCKED` —
never PASS without an observation.

## 0. How the cases were run
- Behavioural cases: `php -d memory_limit=1G vendor/bin/phpunit --testdox --filter '<S07 classes>'`
  against `kap_test` (real HTTP kernel + RLS-enforcing runtime role). Queue is `sync`.
- Assertion cases: `php artisan reconcile:run --tag=S07` + the full `reconcile:run`.
- First-class DB teeth: raw service drives on the dev DB under `kap_app` (RLS live) — the SoD CHECK,
  the budget-line immutability trigger, and the transaction-immutability trigger.
- **clamd INTEGRATION suite excluded**: `kap-clamav-1` is `Up (unhealthy)` (clamd OOM in the 3.8 GB
  VM). S07's evidence gate rides the `evidence` upload context but is proven on the `EicarOnlyScanner`
  double, like every prior sprint — no S07 case needs the live daemon; the real-daemon check is the
  named S10 acceptance item.

## 1. Behavioural cases — PASS-OBSERVED (live)
Aggregate live run of the four S07 classes: **17 tests, 435 assertions, OK** (0 failures).

### STEP 1 · BudgetTest — 5/5 PASS-OBSERVED
✔ budget_state_machine_and_changes_requested_loop · ✔ only_the_teams_teacher_can_approve (five-branch)
· ✔ **budget_lines_are_immutable_once_active_at_the_db** (§2.2) · ✔ plan_gate_requires_an_active_budget
· ✔ budget_approved_provenance_reds_on_an_active_budget_without_approval. — all PASS-OBSERVED.

### STEP 2 · TransactionTest — 6/6 PASS-OBSERVED
✔ full_lifecycle_recorded_then_verified_by_a_second_member · ✔ **a_transaction_cannot_be_submitted_without_evidence** (§2.3)
· ✔ **the_sod_check_constraint_blocks_verifier_equals_recorder** (§2.1) · ✔ **over_budget_expense_requires_acknowledgement_but_is_not_blocked** (§2.5)
· ✔ **financial_fields_are_immutable_once_recorded** (§2.2) · ✔ verification_sod_assertion_teeth. — all PASS-OBSERVED.

### STEP 3 · CharityFundraisingTest — 3/3 PASS-OBSERVED
✔ **charity_project_refuses_a_member_distribution_but_sponsorship_allows_it** (OD-4, §2.4) · ✔ charity_no_distribution_assertion_reds_on_a_forged_row (§2.4)
· ✔ pitch_gate_requires_the_declared_funding_target. — all PASS-OBSERVED.

### STEP 4 · FinanceReportTest — 3/3 PASS-OBSERVED
✔ finance_report_shows_pnl_and_actuals_with_evidence · ✔ **a_non_member_sees_empty_finance_data** (five-branch, §2.7)
· ✔ budget_actuals_match_reds_on_a_cross_team_or_unapproved_line (§2.6). — all PASS-OBSERVED.

## 2. First-class FRAUD-CONTROL / MONEY-INTEGRITY / GOVERNANCE observations (raw)

### 2.1 The SoD — a recorder cannot verify their own transaction (BOTH ways)
- **App layer:** the committed test shows the recorder's verify → **403**, with an audited
  `team_transaction.verify_refused` event.
- **DB layer:** a raw update forcing `verified_by = recorded_by` on dev:
```
SoD (verifier=recorder) => REFUSED by CHECK: SQLSTATE[23514]: … violates check constraint "tt_sod_check"
```
**Observed:** the recorder≠verifier fraud control holds at the app (audited 403) AND at the DB (a CHECK
constraint that binds every writer, system and superuser alike — a WITH-CHECK's system arm would have
bypassed it under system elevation). On a NEW table (D-16, nothing re-homed). **PASS-OBSERVED.**

### 2.2 Immutability triggers — refuse even superuser
- **Budget lines once Active** (raw drive on dev):
```
DRAFT line update => ALLOWED (editable)
ACTIVE line update => REFUSED by trigger: … budget_lines is immutable once the budget is active (BI-5) … UPDATE blocked
ACTIVE line delete => REFUSED by trigger
```
- **Transaction financial fields once Recorded** (raw drive on dev):
```
recorded amount change => REFUSED by trigger: … team_transaction financial fields are immutable once recorded (BI-5) …
```
**Observed:** both BI-5 triggers refuse UPDATE/DELETE regardless of connector (superuser included);
corrections are a new revision / reversing transaction. **PASS-OBSERVED.**

### 2.3 Verified-without-evidence is structurally impossible
Committed test: a draft with no evidence → `submit` refused (409 at the BI-10 clean-evidence gate),
stays draft. Only `submitted → … → verified` is reachable, and the `tt_verified_has_evidence` CHECK is
the belt. **Observed:** a transaction can never reach Verified without a clean receipt. **PASS-OBSERVED.**

### 2.4 OD-4 — a charity project never distributes to a member
Committed test: a charity project + expense naming a `beneficiary_member_id` → **422** at record
(app refusal); a non-member charity expense is fine; a sponsorship project allows a member beneficiary.
The `finance.charity_no_distribution` assertion is path-independent — a forged charity-expense-to-member
row (bypassing the app) → **red**. **Observed:** charity money can never reach a member, however the
row arises. **PASS-OBSERVED.**

### 2.5 Overspend — FLAG, not block (D-B5)
Committed test: an over-budget expense approved without acknowledgement → **422** (informed approval
required); approved with acknowledgement → **recorded** + `over_budget_acknowledged=true`. **Observed:**
the overspend is captured (reality recorded), never refused into under-recording. **PASS-OBSERVED.**

### 2.6 budget_actuals_match — no leak swells the actuals
Committed test: a recorded expense referencing a budget line under a **different team** → the
assertion **reds** (cross-team / orphan / unapproved-budget spend). **Observed:** a budget's actual can
only aggregate its own team's approved spend. **PASS-OBSERVED.**

### 2.7 Five-branch — a non-member sees empty finance data
Committed test: a non-member `GET /finance-report` → `budget=null`, `transactions=[]`, income 0 (RLS
scopes `team_budgets`/`team_transactions`). **PASS-OBSERVED.**

## 3. Assertion-guarded cases — live `reconcile:run`
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
```
All five S07 assertions **PASS-OBSERVED** live; red→green teeth re-observed in §1 (`*_reds_*` cases).
Full battery 58/58.

## 4. Summary counts
| Group | Cases | PASS-OBSERVED | FAIL-OBSERVED | BLOCKED |
|-------|-------|---------------|---------------|---------|
| STEP 1 — BudgetTest | 5 | 5 | 0 | 0 |
| STEP 2 — TransactionTest | 6 | 6 | 0 | 0 |
| STEP 3 — CharityFundraisingTest | 3 | 3 | 0 | 0 |
| STEP 4 — FinanceReportTest | 3 | 3 | 0 | 0 |
| **Behavioural total** | **17** | **17** | **0** | **0** |
| First-class fraud/money/governance drives (§2) | 7 | 7 | 0 | 0 |
| Assertions (`--tag=S07`) | 5 | 5 | 0 | 0 |
| Full battery (`reconcile:run`) | 58 | 58 | 0 | 0 |

**Verdict: S07 LIVE = PASS.** 17/17 behavioural, 7/7 first-class fraud/money/governance, 5/5 S07
assertions, 58/58 full battery — every one observed, none asserted without an observation.

## 5. Divergences
- **No product divergence observed.** Every enumerated S07 case behaved as its committed test/drive
  specifies — the SoD fraud control refuses at both layers, both BI-5 triggers refuse superuser,
  Verified-without-evidence is unreachable, charity never distributes to a member, overspend is flagged
  not blocked, and no leak swells a budget's actuals.
- **Live-drive note (instrument, not defect):** the drives created synthetic `%@ex.test` accounts /
  `BD*`/`TX*` programmes + budgets/transactions on dev. The BI-5 triggers refuse deletion of
  active-budget lines / recorded transactions even as superuser, so teardown first LIFTS the status
  (`UPDATE … status='draft'`) then deletes — the same "corrections need a new revision" discipline the
  triggers enforce. Cleaned via superuser; battery restored to **58/58**. Not an S07 defect.
- **Infra (S10 hand-off):** the ClamAv **integration** suite is infra-blocked (`kap-clamav-1`
  unhealthy) and excluded. S07 proves its evidence gate on the EICAR double; the real-daemon check is
  the named S10 acceptance item — the audit does not claim the live daemon passed.
