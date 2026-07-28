# S05 TEST AUDIT — LIVE (observed against the running instance)

**Date:** 2026-07-28 · **HEAD:** S05 gate `796e6df` · **Runner:** local (colima/Docker), DB `kap_test`

## 0. Provenance note (read first — a divergence in itself)
The instruction was to convert a diff-based audit at `docs/audit/S05-TEST-AUDIT.md` (cases marked
`VERIFIED-IN-DIFF`) into live-observed results. **That source document does not exist** — searched
the repo, git history (all branches), `~/Downloads`, and the scratchpad; no file contains
`VERIFIED-IN-DIFF`, `ASSERTION-GUARDED`, or `S05-L.1`. Only `docs/audit/SYSTEM-AUDIT-S00-S04B.md`
predates this. I did **not** fabricate the predecessor.

Instead, the authoritative record of what was "verified in diff" is the **committed S05 test suite**
(the diffs Leo cleared step-by-step). I enumerated every S05 case from those committed test files and
ran each **live**. So "PASS-OBSERVED" here = the committed case's scenario, executed now against the
running instance. There is no diff-based audit to compute "divergence" against; §4 flags divergences
between the committed expectation and live behaviour instead (one real finding surfaced).

## 1. How the cases were run (and a runner gotcha)
Run with `php -d memory_limit=1G vendor/bin/phpunit --filter '<S05 classes>'` — the **`--filter`**
form loads ALL test files then runs only the matched S05 ones. This matters: the shared virus-scanner
test double **`EicarOnlyScanner` is defined only in `tests/Feature/UploadServiceTest.php`**, and the
S05 tests bind it in `setUp()` without importing it. Running the S05 files as a *subset that excludes
UploadServiceTest* makes the class unresolvable → HTTP 500 on the consent-upload path → **37 spurious
failures**. Under `--filter` (or the full suite), all files load and the same tests are **green**. See
§4 finding F-1.

Assertion-guarded cases were checked with `php artisan reconcile:run --tag=S05` (live, §3).

## 2. Behavioural cases — PASS-OBSERVED (live)
Aggregate live run: **38 tests, 1066 assertions, OK** (0 failures) across the five S05 classes.

### TeamConfirmationTest (成團 concurrency) — 8/8 PASS-OBSERVED
| Case | Observed |
|------|----------|
| seizes_seats_confirms_members_and_writes_family_obligations | PASS-OBSERVED |
| partial_claim_is_impossible_over_capacity_refuses_whole_team | PASS-OBSERVED |
| capacity_row_serializes_claimants_across_connections (twin-team race) | PASS-OBSERVED |
| supersede_before_成團 (stale-consent block) | PASS-OBSERVED |
| unconsented_member_refuses_成團 | PASS-OBSERVED |
| confirm_refused_without_capacity_configured | PASS-OBSERVED |
| lower_capacity_below_claimed_is_refused | PASS-OBSERVED |
| non_approver_cannot_confirm | PASS-OBSERVED |

### DeadlineMatchingTest (deadline machinery) — 8/8 PASS-OBSERVED
auto-submit-compliant/flag-noncompliant · match · roll+double-park-refused · release+paid-refused ·
backstop-full-refund-release(paid) · backstop-release(unpaid) · backstop_provenance teeth (red-green) ·
matching-requires-operations — **all PASS-OBSERVED**.

### TeamResilienceTest (four terminal actions + lapse) — 10/10 PASS-OBSERVED
lapse-suspends-on-team_members(enrolment stays confirmed, SYSTEM actor) · grace-once(2nd→409) ·
assign · waive · dissolve-repools-paid+backstop-fires-naturally · **repooled-paid-re-teams-no-recharge
(the #3 fix)** · dissolve-cancels-unpaid · no_silent_lapse teeth(red-green) · school-leave(team stands) ·
resolution-requires-operations — **all PASS-OBSERVED**.

### RolesTrackerTest (roles & tracker) — 4/4 PASS-OBSERVED
gate_approval_five_branch (team-linked teacher 200 · lobby school-admin 200 · different-team teacher 403 ·
guardian 403 · student 403) · ceo_role_rotates_not_stacks(one active, handover, 409 re-assign) ·
five_stages_are_fixed(off-list→422) · gate_and_tenure_writes_carry_the_acting_human(not system) —
**all PASS-OBSERVED**.

### S05GateAssertionsTest (assertion teeth + audit element) — 7/7 PASS-OBSERVED
capacity_conservation(planted overbook→red) · claims_are_whole(partial-claim audit→red) ·
consent_complete_at_confirm(no-consent→red) · consent_complete_at_confirm(**superseded-before-confirm→
red, after→green**) · size_or_waiver(below-min→red, waiver→green) · team_capacity_report(audit element) ·
no_expired_parking(unswept→red, sweep→green) — **all PASS-OBSERVED**.

## 3. Assertion-guarded cases — live `reconcile:run --tag=S05`
```
RECONCILE PASS — 7 assertion(s), 7 passed, 0 failed
  PASS  refunds.backstop_provenance          [OD-35 · OD-47 · OD-48]
  PASS  deadlines.no_silent_lapse            [OD-45 · FR066 · 2.19]
  PASS  capacity.conservation                [OD-31 · OD-32 · BI-3]
  PASS  capacity.claims_are_whole            [OD-32 · BI-3]
  PASS  teams.consent_complete_at_confirm    [OD-57 · OD-58 · BI-6]
  PASS  teams.size_or_waiver                 [OD-40 · OD-37]
  PASS  pool.no_expired_parking              [OD-35]
```
All seven **PASS-OBSERVED** live. (Full battery `reconcile:run` = 33/33 at the gate.)

## 4. S05-L.1 — `requires_all_guardians`: live 成團 enforcement vs the narrower at-rest assertion
**Confirmed: the live 成團 path ENFORCES `requires_all_guardians`.** `TeamConfirmationService::confirm`
re-checks `ConsentSigningService::consentSatisfied(programme, student)` for every member under the FOR
SHARE lock and aborts 422 if unsatisfied. `consentSatisfied` with the flag on collects **all active
`guardian_links`** and requires every one among the signed signers
(`activeGuardians->diff($signedSigners)->isEmpty()` AND non-empty).

Live observation — `ConsentSigningTest::test_consent_satisfied_honours_requires_all_guardians`
(via `--filter`): **PASS-OBSERVED (1 test, 18 assertions, OK)** — with the flag on and only one of two
guardians signed, `consentSatisfied` returns **false** (would block 成團). This is the exact method the
成團 gate calls, so the live 成團 path blocks a not-all-signed team.

**The narrowing is only AT-REST:** the `teams.consent_complete_at_confirm` reconciliation assertion
checks each confirmed enrolment had **≥1** non-stale signature by confirm — it does **not** reproduce
the all-guardians rule at rest. This gap is already recorded in `docs/sprints/S05/AUDIT.md §8` and is
proposed for hardening in S06 (see `docs/sprints/S06/PROPOSED-S06-REVIEW.md §4`, using immutable
guardian-link lifecycle audit events). **Verdict for S05-L.1: live-enforced (PASS-OBSERVED); at-rest
backstop narrower by known, logged design — not a live vulnerability.**

## 5. Divergences flagged
| # | Divergence | Severity | Note |
|---|-----------|----------|------|
| **F-0** | The diff-based source `docs/audit/S05-TEST-AUDIT.md` does not exist | process | No artefact to convert/compare against; cases taken from the committed test suite (§0). |
| **F-1** | **`EicarOnlyScanner` shared test-double is file-order-fragile** — defined only in `UploadServiceTest.php`; S05/consent tests bind it in `setUp()` without importing it, so any subset run excluding UploadServiceTest yields spurious 500s (37 here). Full-suite/`--filter` green. | test-infra (not product) | Surfaced *during* this live audit. Recommend extracting `EicarOnlyScanner` to an autoloaded `tests/Support/` class so results are file-order-independent. Proposed: an S06-adjacent chore. |
| **F-2** | S05-L.1 at-rest assertion narrower than the live 成團 gate (requires_all) | low | Already logged (AUDIT.md §8); S06 hardening proposed. Not a divergence in behaviour — a backstop-scope note. |

No divergence found between any committed S05 case's expectation and its **live** outcome — every case
is PASS-OBSERVED. The only real finding is the test-harness fragility (F-1), which produces false reds
under subsetting but never false greens, and touches no product code.

## 6. Verdict
**S05 test audit: PASS-OBSERVED, live.** 38 behavioural cases green (1066 assertions), 7 `--tag=S05`
assertions green live, requires_all_guardians enforced on the live 成團 path. One test-infra finding
(F-1) and one known at-rest scope gap (F-2) recorded; neither is a product defect.
