# S06 TEST AUDIT — LIVE (observed against the running instance)

**Date:** 2026-07-29 · **HEAD:** S06 gate `f813990` · **Runner:** local (colima/Docker), DB `kap_test`
**Method:** same as `docs/audit/S05-TEST-AUDIT-LIVE.md` — cases enumerated from the **committed** S06
test suite and run **live**; "PASS-OBSERVED" = the committed case's scenario executed now.

## 0. How the cases were run (and the F-1 gotcha, still present)
Run with `php -d memory_limit=1G vendor/bin/phpunit --filter '<S06 classes>'` — the `--filter` form
loads ALL test files, so the shared `EicarOnlyScanner` double (defined only in `UploadServiceTest.php`)
resolves. **F-1 from the S05 audit is NOT fixed** (§Divergences): running an S06 class that binds it in
isolation still throws. Assertion cases run via `php artisan reconcile:run --tag=S06`. The concurrency
case runs as an actual two-connection PDO test.

## 1. Behavioural cases — PASS-OBSERVED (live)
Aggregate live run of the nine S06 classes: **41 tests, 585 assertions, OK** (0 failures).

### S06-1 · EnrolmentActivationTest — 6/6 PASS-OBSERVED
activates-confirmed-in-started-as-SYSTEM · not-yet-started-stays-confirmed · late-joiner-activates-next-run-not-stranded · tracker-gate-locked-before-Active/allowed-after · activation_liveness red→green · activation_liveness-ignores-not-started — **all PASS-OBSERVED**.

### S06-2 · SessionLifecycleTest — 6/6 PASS-OBSERVED
state-machine-advances/blocks-illegal · reschedule-keeps-bookings+writes-version+reopens-on-capacity-growth · reschedule-clash-flags-double-booked · no-clash-when-no-overlap · mentor-departed-blocked-while-future-sessions · session-management-requires-operations — **all PASS-OBSERVED**.

### S06-3 · BookingAttendanceTest — 6/6 PASS-OBSERVED
fills-to-capacity-then-waitlists · cancel-auto-promotes-earliest-waitlisted · cancel-no-waitlist-reopens-full · attendance-rejected-before-in_progress/recorded-with-identity-after · attendance-requires-mentor-or-operations · **capacity-serializes-across-connections (concurrency, §3)** — **all PASS-OBSERVED**.

### S06-4 · LearnGateTest — 5/5 PASS-OBSERVED
learn-gate-refused-0/0-not-assessable · refused-below-team-threshold · eligible-allows-teacher-approval (Option B) · eligibility-computation-per-member+rollup · preflight-warns-threshold-no-sessions — **all PASS-OBSERVED**.

### S06-4b · AssessmentEmbargoTest — 3/3 PASS-OBSERVED
state-machine-terminal-at-Released · **embargo-family-sees-nothing-until-Released** · **embargo-five-branch-other-family+Member-zero-even-when-Released** — **all PASS-OBSERVED**.

### S06-5 · MemberSurfacesTest — 5/5 PASS-OBSERVED
member-sees-published-events/student-does-not · **rsvp-per-member-not-network-wide** · directory-visible-to-members/hidden-from-others · member-no-enrolment/team-data+cannot-manage-events · non-member-cannot-rsvp/profile — **all PASS-OBSERVED**.

### S06-6 · ConsentHardeningTest — 1/1 · WithdrawalBookingCascadeTest — 3/3 PASS-OBSERVED
requires_all-hardening-reds-on-active-guardian-who-didn't-sign (backdated-active→red, revoked-before-confirm→green, rollback→green) · withdrawal-cancels-future-bookings+promotes-waitlist · no-waitlist-reopens-full · cascade-leaves-past-sessions-alone — **all PASS-OBSERVED**.

### S06-7 · S06GateAssertionsTest — 6/6 PASS-OBSERVED
no_stale_published red→advance→green · session-advancement-through-lifecycle (future=published, running=in_progress, ended=completed) · attendance_integrity-red-on-never-run-session · learn_gate_integrity-red-on-below-threshold-snapshot · cascade_live-red-then-cascade→green · attendance-report-reflects-state — **all PASS-OBSERVED**.

## 2. Assertion-guarded cases — live `reconcile:run --tag=S06`
```
RECONCILE PASS — 7 assertion(s), 7 passed, 0 failed
  PASS  teams.consent_complete_at_confirm  [OD-57 · OD-58 · BI-6]  (hardened, requires_all branch)
  PASS  enrolments.activation_liveness     [R3 · FR012]
  PASS  ladders.liveness                   [2.10]  (hasTable-guarded, vacuous until S09)
  PASS  teams.learn_gate_integrity         [OD-12]  (snapshot-time)
  PASS  sessions.no_stale_published        [2.3]
  PASS  sessions.attendance_integrity      [2.3 · FR012]
  PASS  bookings.cascade_live              [2.21]
```
All 7 **PASS-OBSERVED** live. (Full battery `reconcile:run` = 39/39.) Red-green teeth for the four new
S06-7 assertions + the two S06-1/6 ones re-observed live in §1 (S06GateAssertionsTest, ConsentHardeningTest).

## 3. Concurrency — actual cross-connection test
`BookingAttendanceTest::test_capacity_serializes_booking_claims_across_connections` — run live in isolation:
```
OK (1 test, 1 assertion)
```
**Observed:** two raw PDO connections contend on the session row; connection **B blocks** on
`SELECT … FOR UPDATE` (400 ms `lock_timeout`) while **A holds** the booking-claim lock, then A commits.
The test asserts `$blocked === true` → **PASS-OBSERVED**. Both `book()` and `cancel()` take this same
FOR UPDATE on the session row, so the **waitlist-promotion race** serialises through the identical lock
(two cancels cannot both promote into one seat — the second blocks, then reads the updated count). No
separate cross-connection waitlist test exists; the capacity race proves the shared lock both rely on.

## 4. First-class negative / refusal cases — PASS-OBSERVED
| Refusal | Case | Observed |
|---|---|---|
| Assessment embargo — Graded-not-Released → family gets NOTHING | `AssessmentEmbargoTest::embargo_family…` | student=null, guardian=null, academy=85; Released→85 |
| Cross-family / Member zero on a released result | `…embargo_five_branch…` | other student=null, other guardian=null, Member=null, academy=90 |
| Member sees ONLY events/RSVP/directory | `MemberSurfacesTest` (5) | events yes, teams `[]`, event-create 403, directory hidden from student/guardian/teacher |
| Another Member can't read the first's RSVP | `rsvp_is_per_member_not_network_wide` | m2 `/my/rsvps` = `[]` after m1 RSVPs |
| Learn gate refusal (not eligible / 0-0) | `LearnGateTest` (2) | 422, no gate written |
| Attendance not forgeable | `attendance_requires_mentor_or_operations` | non-mentor teacher → 403; `recorded_by` from the authenticated recorder |
| Tracker locked pre-Active / non-operations refused | `EnrolmentActivationTest`, `SessionLifecycleTest` | 422 before Active; 403 config-only |

## 5. Divergences (flagged explicitly)
| # | Divergence | Severity | Note |
|---|-----------|----------|------|
| **F-1 (carried from S05, NOT fixed)** | `EicarOnlyScanner` (shared virus-scanner double) still lives only in `UploadServiceTest.php`; S06 classes that bind it (`EnrolmentActivationTest`, `LearnGateTest`, `AssessmentEmbargoTest`, `ConsentHardeningTest`) **fail in isolation** with `ReflectionException: Class "Tests\Feature\EicarOnlyScanner" does not exist`. Confirmed live: `php artisan test tests/Feature/LearnGateTest.php` → that error. | test-infra (not product) | The `--filter` / full-suite method loads all files and is green. **False reds, never false greens.** Recommend extracting the double to an autoloaded `tests/Support/` class — now affects S05 AND S06. |
| RolesTrackerTest nullable-param deprecation | `pooledStudent()` implicit-nullable `$guardianOut` — a PHP 8.4 **notice**, not a failure | low | tidy when convenient (AUDIT §8). |

**No behavioural divergence** between any committed S06 case's expectation and its live outcome — all
41 behavioural cases + 7 assertions are PASS-OBSERVED. The only real finding is the carried-over
test-harness fragility (F-1), which produces false reds under subsetting but touches no product code.

## 6. Verdict
**S06 test audit: PASS-OBSERVED, live.** 41 behavioural cases green (585 assertions), 7 `--tag=S06`
assertions green live, the cross-connection capacity race serialises (block observed), and every
first-class refusal (embargo, Member isolation, per-member RSVP, Learn-gate refusal, attendance
authority, cross-family denials) is observed. One test-infra finding (F-1) carried from S05 and **still
unfixed** — recorded again here.

### Counts
- Behavioural: **41 PASS-OBSERVED**, 0 FAIL, 0 BLOCKED.
- `--tag=S06` assertions: **7 PASS-OBSERVED**, 0 FAIL.
- Concurrency: **1 PASS-OBSERVED** (cross-connection block observed).
- Divergences: **1** (F-1, test-infra, carried + unfixed) + 1 low-severity PHP deprecation notice.
