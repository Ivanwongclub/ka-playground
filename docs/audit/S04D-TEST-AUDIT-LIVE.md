# S04D TEST AUDIT — LIVE (observed against the running instance)

**Date:** 2026-07-31 · **HEAD:** S04D gate `35b3d96` · **Runner:** local (colima/Docker), app role `kap_app`
(NOSUPERUSER NOBYPASSRLS → RLS live), dev DB `kap` @ `127.0.0.1:54329`, test DB `kap_test`.
**Method:** same as `docs/audit/S05-TEST-AUDIT-LIVE.md` / `S06-TEST-AUDIT-LIVE.md` — every S04D case is
enumerated from the **committed** test suite and run **live now**; "PASS-OBSERVED" = the committed
scenario executed against the running stack this session. The REFUSAL/confinement cases are run as
raw drives and their observations pasted, not summarised. `PASS-OBSERVED / FAIL-OBSERVED / BLOCKED` —
never PASS without an observation.

## 0. How the cases were run
- Behavioural cases: `php -d memory_limit=1G vendor/bin/phpunit --testdox --filter '<S04D classes>'`
  against `kap_test` (the app's real HTTP kernel + RLS-enforcing runtime role).
- Assertion cases: `php artisan reconcile:run --tag=S04D` and the full `reconcile:run` (47).
- The write-policy hardening (first-class) is run as a **raw DB drive** on the dev DB under the
  RLS-enforcing `kap_app` role, so the Postgres refusal is observed directly (`SQLSTATE[42501]`).
- The bulk per-row roll authority + idempotency (first-class) are run as a **raw service drive** on
  the dev DB.
- **clamd suite excluded** (§Divergences, AUDIT §5#3): `kap-clamav-1` is `Up (unhealthy)` — clamd OOMs
  loading the 3.2M-sig DB in the 3.8 GB VM. S04D touches no upload path; the exclusion does not
  suppress any S04D case.

## 1. Behavioural cases — PASS-OBSERVED (live)
Aggregate live runs: **STEP 1/3/4 classes 16/16 OK (61 assertions)** · **STEP 2 retrofit + linkage
state classes 25/25 OK (103 assertions)**. 0 failures, 0 skips.

### STEP 1 · LinkStateMachineTest — 5/5 PASS-OBSERVED
✔ mint_pending_activation_never_leaves_a_usable_password · ✔ doaccept_audits_the_teacher_link_activation
· ✔ backfill_audits_active_links_keyed_on_real_creation · ✔ no_active_without_approval_reds_then_greens
· ✔ **a_school_linked_teacher_gains_no_gate_authority** (the gate-authority guardrail — a `teacher_links`
row confers NO Learn-gate power; the check reads `team_teacher_links` only). — all PASS-OBSERVED.

### STEP 2 · ceremony retrofit — LinkingFlowsTest 15/15 + LinkageTest 10/10 PASS-OBSERVED
LinkingFlowsTest: ✔ student_generates_at_most_five_active_codes · ✔ **redeem_confirm_pends_for_admin_then_approval_activates**
(confirm no longer self-activates — §2.5) · ✔ code_is_case_sensitive · ✔ eleventh_global_failed_attempt_hard_invalidated
· ✔ pairing_redemption_throttled_5_per_hour · ✔ unauthenticated_attacker_cannot_touch_failure_counter
· ✔ recovery_after_invalidation_regenerate · ✔ **parent_initiated_request_pends_and_never_leaks_existence**
· ✔ school_vouched_link_auto_activates_for_own_school_student · ✔ **school_vouch_for_another_schools_student_is_denied_and_audited**
· ✔ sole_link_with_enrolment_cannot_be_revoked_by_guardian+refusal_audited · ✔ admin_revocation_of_sole_link_needs_reason+opens_14_day_exception
· ✔ non_sole_link_revokes_freely_no_exception · ✔ without_enrolments_sole_link_revokes_freely · ✔ pairing_service_resolves_from_container.
LinkageTest: ✔ road_a_existing_verified_counterpart_creates_a_pending_link · ✔ road_b_unregistered_counterpart_creates_a_held_link
· ✔ typo_scenario_no_materialisation_before_verification · ✔ flag2_audit_written_on_road_a_activation · ✔ flag2_audit_written_on_road_b_materialised
· ✔ **a_guardian_cannot_self_activate_their_own_pending_link** · ✔ reject_a_pending_link_is_terminal_and_audited
· ✔ held_link_expiry_is_terminal · ✔ activation_audited_reds_then_greens · ✔ no_unverified_materialisation_reds_then_greens. — all PASS-OBSERVED.

### STEP 3 · VouchVisibilityTest — 7/7 PASS-OBSERVED
✔ **vouch_activates_and_writes_visibility_to_existing_guardian** (OD-24, §2.6) · ✔ **pairing_redeem_refuses_an_uninitiated_second_guardian** (§2.2)
· ✔ **request_by_email_second_guardian_is_silent_202_no_link** (§2.2) · ✔ **stray_direct_active_write_is_refused_but_system_activates** (§2.1)
· ✔ **non_system_may_still_write_a_pending_row_and_system_writes_active** (§2.1) · ✔ guardian_addition_visibility_reds_on_a_silent_addition_then_greens
· ✔ vouch_scope_reds_on_a_rollless_vouch_then_greens. — all PASS-OBSERVED.

### STEP 4 · BulkStudentCreationTest — 4/4 PASS-OBSERVED
✔ **bulk_creates_unverified_students_with_active_audited_school_links** · ✔ **mid_batch_failure_is_reported_row_by_row_never_silent** (§2.3/2.4)
· ✔ **reuploading_the_same_batch_skips_and_does_not_duplicate** · ✔ a_non_school_admin_cannot_bulk_create (endpoint 403). — all PASS-OBSERVED.

## 2. First-class REFUSAL / confinement observations (raw)

### 2.1 Write-policy hardening — non-system CANNOT write `status='active'`, CAN write pending
Raw drive on dev DB as `kap_app` (RLS live); actor = academy_admin holding `operations`. Both arms pasted:
```
-- ARM 1: NON-SYSTEM actor attempts status='active' -> must be REFUSED --
  guardian_links active => REFUSED: SQLSTATE[42501]: Insufficient privilege: 7 ERROR:  new row violates row-level security policy for table "guardian_links"
  school_links   active => REFUSED: SQLSTATE[42501]: Insufficient privilege: 7 ERROR:  new row violates row-level security policy for table "school_links"
  teacher_links  active => REFUSED: SQLSTATE[42501]: Insufficient privilege: 7 ERROR:  new row violates row-level security policy for table "teacher_links"
-- ARM 2: SAME non-system actor writes status='pending_approval' -> must be ALLOWED --
  guardian_links pending => WROTE (row committed)
  observed row status => 'pending_approval'
-- ARM 3: SYSTEM context activates the pending row -> must be ALLOWED --
  system UPDATE pending->active => WROTE (row committed)
  observed row status => 'active'
```
**Observed:** all three link tables refuse a non-system `active` write **at the database** (not UI
hiding); the same actor freely writes a `pending_approval` row; only the system context flips it to
`active`. The `{system} OR (({arm}) AND status <> 'active')` WITH CHECK holds on all three. **PASS-OBSERVED.**

### 2.2 Second-guardian self-add — refused without leaking that a guardian already exists
Committed VouchVisibilityTest, run live:
```
POST /api/pairing-codes/redeem  (uninitiated 2nd guardian)  => assertStatus(422)
    DB: guardian_links for student == 1  (no second link created)
POST /api/my/link-requests      (2nd guardian by email)     => assertStatus(202)  // constant shape
    DB: guardian_links for student == 1  (no second link created, existence never leaked)
```
**Observed:** the pairing path hard-refuses (422) an uninitiated co-guardian; the email path returns
the **same 202 constant shape** a first request gets — an attacker cannot distinguish "student already
has a guardian" from "request accepted". Neither path creates a second link. **PASS-OBSERVED.**

### 2.3 Bulk per-row roll authority — own-school rows create, other-school rows reject, per-row
Raw service drive on dev DB (admin administers school A only; batch mixes A + B):
```
REPORT: created=1 skipped=0 rejected=1
  rejected reason: not a school you administer (OD-30 roll authority)
```
**Observed:** the school A row is created; the school B row is rejected **on its own row** (the check
runs inside the loop against THIS row's `school_id`, not once per batch). **PASS-OBSERVED.**

### 2.4 Bulk failure isolation — a mid-batch failure rejects only that row, no silent partial
Committed `test_mid_batch_failure_is_reported_row_by_row_never_silent`, run live (batch = good |
wrong-school | already-exists):
```
report.created  == [good@example.com]      (good row IS created)
report.rejected == [wrong@example.com]     (roll authority, NOT created)
report.skipped  == [exists@example.com]    (idempotent skip)
DB: good exists == true ; wrong exists == false
audit: bulk.students_created by admin == 1  (persistent batch proof)
```
**Observed:** every row lands in exactly one bucket; the failing (wrong-school) row is isolated —
the good row still commits, the rest proceed, and a batch-level audit records the counts. No silent
partial. **PASS-OBSERVED.**

### 2.5 confirm() no longer self-activates — pends for admin
`test_redeem_confirm_pends_for_admin_then_approval_activates` (LinkingFlows) +
`a_guardian_cannot_self_activate_their_own_pending_link` (Linkage), run live.
**Observed:** pairing confirm lands the link in `pending_approval`; the guardian cannot self-activate;
only an admin's `approveLink` reaches `active`. **PASS-OBSERVED.**

### 2.6 OD-24 never-silent — visibility to every existing guardian at each addition
`vouch_activates_and_writes_visibility_to_existing_guardian` (vouch path) +
`guardian_addition_visibility_reds_on_a_silent_addition_then_greens` (assertion teeth), run live.
**Observed:** adding a 2nd guardian (vouch included) writes a `link_visibility_events` record for the
FIRST guardian; a deliberately silent addition reds the assertion, and recording the visibility greens
it. **PASS-OBSERVED.**

## 3. Assertion-guarded cases — live `reconcile:run`
```
$ php artisan reconcile:run --tag=S04D
  PASS  links.no_active_without_approval   [2.30 · OD-23 · OD-27 · S04D STEP 1]  (all three link tables)
  PASS  links.guardian_addition_visibility [OD-24 · OD-30 · S04D STEP 3]
  PASS  links.vouch_scope                  [OD-30 · S04D STEP 3]
RECONCILE PASS — 3 assertion(s), 3 passed, 0 failed

$ php artisan reconcile:run          # full battery, all tags
RECONCILE PASS — 47 assertion(s), 47 passed, 0 failed
```
All three S04D assertions **PASS-OBSERVED** live; red→green teeth for each re-observed in §1
(`*_reds_then_greens` cases). Full battery 47/47.

## 4. Summary counts
| Group | Cases | PASS-OBSERVED | FAIL-OBSERVED | BLOCKED |
|-------|-------|---------------|---------------|---------|
| STEP 1 — LinkStateMachineTest | 5 | 5 | 0 | 0 |
| STEP 2 — LinkingFlowsTest + LinkageTest | 25 | 25 | 0 | 0 |
| STEP 3 — VouchVisibilityTest | 7 | 7 | 0 | 0 |
| STEP 4 — BulkStudentCreationTest | 4 | 4 | 0 | 0 |
| **Behavioural total** | **41** | **41** | **0** | **0** |
| First-class refusal/confinement drives (§2) | 6 | 6 | 0 | 0 |
| Assertions (`--tag=S04D`) | 3 | 3 | 0 | 0 |
| Full battery (`reconcile:run`) | 47 | 47 | 0 | 0 |

**Verdict: S04D LIVE = PASS.** 41/41 behavioural, 6/6 first-class refusal/confinement, 3/3 S04D
assertions, 47/47 full battery — every one observed, none asserted without an observation.

## 5. Divergences
- **DIV-1 (instrument, not product — and BI-1 working as designed).** The §2.1 raw hardening drive's
  *own* teardown attempted `DELETE FROM audit_events` for its synthetic actors; the DB refused it —
  `audit_events is INSERT-only (BI-1): DELETE blocked`. So the drive left 16 synthetic accounts, which
  (created via raw `User::create`, no provenance audit) reddened `account.provenance`
  (`RECONCILE FAIL — 46 passed, 1 failed`) until a manual superuser cleanup removed the accounts
  (leaving their audit rows intact, as BI-1 requires). Battery restored to **47/47**. This is an
  observation-harness lesson, not an S04D product defect — and it is a live confirmation that BI-1 and
  `account.provenance` both bite. No product behaviour diverged.
- **Infra (AUDIT §5#3, retagged S10).** `kap-clamav-1` runs `Up (unhealthy)`; the ClamAv **integration**
  suite is infra-blocked and excluded from this run. S04D touches no upload path, so no S04D case is
  suppressed. The fix is scheduled S10 (go-live) — it becomes load-bearing when S04E ships the
  ClamAv-scanned upload intake.
- **No product divergence observed.** Every enumerated S04D case behaved as its committed test specifies.
