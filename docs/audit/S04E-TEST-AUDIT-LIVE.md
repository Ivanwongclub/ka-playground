# S04E TEST AUDIT — LIVE (observed against the running instance)

**Date:** 2026-08-01 · **HEAD:** S04E gate `252a8aa` · **Runner:** local (colima/Docker), app role
`kap_app` (NOSUPERUSER NOBYPASSRLS → RLS live), dev DB `kap` @ `127.0.0.1:54329`, test DB `kap_test`.
**Method:** same as `docs/audit/S04D-TEST-AUDIT-LIVE.md` / S05 / S06 — every S04E case is enumerated
from the **committed** suite and run **live now**; "PASS-OBSERVED" = the committed scenario executed
against the running stack this session. The REFUSAL/safety cases are run as raw drives and their
observations pasted, not summarised. `PASS-OBSERVED / FAIL-OBSERVED / BLOCKED` — never PASS without an
observation.

## 0. How the cases were run
- Behavioural cases: `php -d memory_limit=1G vendor/bin/phpunit --testdox --filter '<S04E classes>'`
  against `kap_test` (real HTTP kernel + RLS-enforcing runtime role). Queue is `sync` — the async
  scan→parse chain runs inline, so an upload reaches its terminal batch state within the request.
- Assertion cases: `php artisan reconcile:run --tag=S04E` + the full `reconcile:run`.
- First-class refusal/safety: raw service drives on the dev DB under `kap_app` (RLS live) — the scan
  gate via the `EicarOnlyScanner` double, the fail-closed probe against the **actually-unhealthy dev
  clamd**, idempotent re-commit, and the intent-only order-count check.
- **clamd INTEGRATION suite excluded** (S10 hand-off, AUDIT §7): `kap-clamav-1` is `Up (unhealthy)`
  (clamd OOM in the 3.8 GB VM). S04E ships its scan-gate on the double; the real-daemon `batch-csv`
  check is the named S10 acceptance item. Excluding it suppresses no S04E case (no S04E case needs the
  live daemon).

## 1. Behavioural cases — PASS-OBSERVED (live)
Aggregate live run of the three S04E classes: **18 tests, 152 assertions, OK** (0 failures).

### STEP 1 · EnrolmentBatchIntakeTest — 8/8 PASS-OBSERVED
✔ **eicar_csv_is_quarantined_and_never_parsed** (§2.1) · ✔ **unreachable_scanner_refuses_with_503_and_persists_nothing** (§2.2)
· ✔ upload_without_a_programme_is_rejected (D-10) · ✔ wrong_columns_rejects_whole_file (structural → 0 rows)
· ✔ row_defects_are_per_row_and_clean_rows_disposition (formula/bad-email failed, dup skipped, new/existing disposed)
· ✔ **admin_of_another_school_sees_nothing** (five-branch, §2.6) · ✔ scan_gated_reds_on_a_row_under_a_non_clean_upload_then_greens
· ✔ public_context_confinement_stays_green. — all PASS-OBSERVED.

### STEP 2 · EnrolmentBatchCommitTest — 6/6 PASS-OBSERVED
✔ mixed_batch_enrols_guardian_rows_and_marks_the_rest_not_enrolled (§2.3) · ✔ **recommit_is_idempotent_no_duplicate_accounts_or_enrolments** (§2.4)
· ✔ **the_unique_constraint_itself_blocks_a_second_live_enrolment** (DB, not app — §2.4) · ✔ **a_student_who_gains_a_guardian_after_preview_enrols_on_commit** (§2.5)
· ✔ **a_student_who_loses_the_guardian_after_preview_does_not_enrol** (§2.5) · ✔ row_conservation_reds_on_an_undispositioned_committed_row_then_greens. — all PASS-OBSERVED.

### STEP 3 · EnrolmentBatchDashboardTest — 4/4 PASS-OBSERVED
✔ index_lists_batches_and_surfaces_failed_as_exceptions (D-13 ledger) · ✔ **show_enriches_enrolled_rows_with_live_enrolment_status_and_lists_not_enrolled**
(stored disposition vs live status — advancing the enrolment changes `show` while the stored row is untouched) · ✔ **another_schools_admin_sees_no_batches** (five-branch, §2.6)
· ✔ no_stuck_reds_on_a_stale_transient_batch_then_greens (fresh→green, aged→red, complete→green). — all PASS-OBSERVED.

## 2. First-class REFUSAL / safety observations (raw)

### 2.1 Scan gates the parse — an EICAR CSV never reaches a parsed row
Raw drive on dev (bound `EicarOnlyScanner` double), intake → `ScanUpload` → `ValidateEnrolmentBatch`:
```
EICAR => status=failed reason='scan not clean (quarantined)' counts[total=0 …]
  EICAR rows parsed => 0 (expect 0)
```
**Observed:** the parser has one entry point, chained off the CLEAN transition, and reads bytes via
`UploadService::contents()` which throws unless CLEAN (BI-10). A quarantined file is never parsed —
zero rows. **PASS-OBSERVED.**

### 2.2 Fail-closed probe — clamd unreachable → 503, nothing persisted
The probe runs BEFORE intake. Against the **real, unhealthy dev clamd**:
```
REAL clamd isAvailable() on dev => false (→ upload would 503, fail-closed)
```
And the committed test asserts the endpoint returns **503** with `uploads` count unchanged and **zero
`enrolment_batches`**. **Observed:** an unreachable scanner refuses at the edge — no half-created
batch stuck pending. **PASS-OBSERVED.**

### 2.3 Mixed batch — guardian rows enrol, guardian-less land not_enrolled (intent)
Raw commit drive on dev (a guardian-having student + a guardian-less one):
```
COMMIT 1 => {"enrolled":1,"not_enrolled":1,"skipped":0,"failed":0,"total":2}
NO-guardian row => status=not_enrolled reason='awaiting guardian & consent'
```
**Observed:** enrolment is guardian-consent-gated (OD-10) — only the guardianed row enrols; the other
is reasoned, never silent. **PASS-OBSERVED.**

### 2.4 Idempotent re-commit — the DB unique, not an app check
Same drive, committed twice:
```
COMMIT 2 => {"enrolled":1,"not_enrolled":1,…}   (same as COMMIT 1)
enrolments after commit1=1 after commit2=1 (idempotent: yes)
users after commit1=20 after commit2=20 (no dup accounts: yes)
```
And the committed test `the_unique_constraint_itself_blocks_a_second_live_enrolment` forces a raw
second insert and observes `UniqueConstraintViolationException` (via a savepoint) — **the constraint
itself**, not the app filter, is the backstop. **Observed:** a double-commit is a clean no-op. **PASS-OBSERVED.**

### 2.5 Live guardian re-check — not the frozen dry-run verdict
Committed tests, run live: a student who **gains** a guardian between preview and commit → `enrolled=1`;
one who **loses** it → `enrolled=0, not_enrolled=1`. **Observed:** the commit re-queries `guardian_links`
LIVE per row; the stale disposition is not trusted. **PASS-OBSERVED.**

### 2.6 Intent only — no orders / obligations / seats / waitlist at commit
After the commit drive, for the batch's programme:
```
orders | obligations
     0 |           0
```
**Observed:** the commit creates enrolment INTENT only (OD-31, D-9) — no order, no payment obligation,
no seat, no waitlist. Money/allocation is S05 成團. **PASS-OBSERVED.**

### 2.7 Five-branch — another school's admin sees nothing
Committed tests (`admin_of_another_school_sees_nothing`, `another_schools_admin_sees_no_batches`), run
live: the list endpoint returns `[]` and `show` returns 404 for a non-owning school admin. RLS scopes
both batch tables. **PASS-OBSERVED.**

## 3. Assertion-guarded cases — live `reconcile:run`
```
$ php artisan reconcile:run --tag=S04E
  PASS  batches.scan_gated        [2.12 · BI-10 · S04E STEP 1]   no row parsed under a non-CLEAN upload
  PASS  batches.row_conservation  [Spec Part H P4 · OD-31 · S04E STEP 2]   bucketed + reasoned, no "waiting"
  PASS  batches.no_stuck          [Spec Part H H2 · S04E STEP 3]   no batch stuck past its window
RECONCILE PASS — 3 assertion(s), 3 passed, 0 failed

$ php artisan reconcile:run          # all prior tags + S04E
RECONCILE PASS — 50 assertion(s), 50 passed, 0 failed
```
All three S04E assertions **PASS-OBSERVED** live; red→green teeth re-observed in §1
(`*_reds_then_greens` cases). Full battery 50/50.

## 4. Summary counts
| Group | Cases | PASS-OBSERVED | FAIL-OBSERVED | BLOCKED |
|-------|-------|---------------|---------------|---------|
| STEP 1 — EnrolmentBatchIntakeTest | 8 | 8 | 0 | 0 |
| STEP 2 — EnrolmentBatchCommitTest | 6 | 6 | 0 | 0 |
| STEP 3 — EnrolmentBatchDashboardTest | 4 | 4 | 0 | 0 |
| **Behavioural total** | **18** | **18** | **0** | **0** |
| First-class refusal/safety drives (§2) | 7 | 7 | 0 | 0 |
| Assertions (`--tag=S04E`) | 3 | 3 | 0 | 0 |
| Full battery (`reconcile:run`) | 50 | 50 | 0 | 0 |

**Verdict: S04E LIVE = PASS.** 18/18 behavioural, 7/7 first-class refusal/safety, 3/3 S04E assertions,
50/50 full battery — every one observed, none asserted without an observation.

## 5. Divergences
- **No product divergence observed.** Every enumerated S04E case behaved as its committed test/drive
  specifies.
- **Live-drive note (not a defect):** the STEP-1/STEP-2 drives created synthetic accounts on dev
  (`%@ex.test`, `Batch */Commit *` schools, `CD-*` programmes). Cleaned up via superuser DELETE
  afterwards (audit rows retained — BI-1 refuses `audit_events` deletion); battery restored to **50/50**.
  Same instrument behaviour recorded in the S04D live audit; not an S04E defect.
- **Infra (AUDIT §7, S10 hand-off):** the ClamAv **integration** suite is infra-blocked
  (`kap-clamav-1` unhealthy) and excluded. S04E's scan-gate is proven on the EICAR double (§2.1); the
  real-daemon `batch-csv` check is the named **S10** acceptance item. The exclusion suppresses no S04E
  case — the audit does not claim the live daemon passed; it defers that check honestly.
