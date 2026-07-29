# AUDIT KAP-S06 — Learning: activation, sessions, attendance, Learn gate, assessment, Member surfaces

**Result:** PASS (pending Leo's gate clearance) · **Date:** 2026-07-29 · **HEAD before gate:** `08ed451` (S06-6)

> Build audit for Leo. Honesty outranks looking good. The in-product audit element (Attendance & Session
> Report) is separate. The gate commit is HELD for joint review.

## 1. Steps
| Step | Commit | What |
|------|--------|------|
| S06-1 | `380d818` | enrolment activation (R3) + tracker lock (FR012) + `enrolments.activation_liveness` |
| card | `a553242` | ratified S06 card |
| S06-2 | `1f4f6ed` | session lifecycle (2.3) + reschedule/clash (2.24) + mentor (2.6) |
| S06-3 | `b118ab5` (+card `1926a26`) | bookings + waitlist auto-promotion + attendance capture |
| S06-4 | `ba50770`, `1e4377d` | Learn computation + gate (Option B) + suspended-excluded ruling |
| S06-4b | `863405d` | assessment lifecycle (2.5) + result embargo at read |
| S06-5 | `3994e12` | Member surfaces (OD-22): events, RSVP, directory |
| S06-6 | `08ed451` | requires_all consent hardening + ladder-liveness guard + withdrawal booking cascade |
| S06-7 | HELD | 4 assertions + session-advancement job + Attendance & Session Report + this AUDIT.md |

## 2. Every sprint ships three things — the S06 check
Module ✅ · in-product **audit element** (Attendance & Session Report, `GET /admin/programmes/{id}/attendance-report`) ✅ · **reconciliation assertions** (7 under `--tag=S06`) ✅.

## 3. Assertions (`--tag=S06`, 7) — with red-then-green teeth
| Assertion | Judges | Teeth |
|-----------|--------|-------|
| `enrolments.activation_liveness` (S06-1) | no confirmed-in-started enrolment un-activated | job-not-run → red, job → green (proved live on dev) |
| `teams.consent_complete_at_confirm` (hardened S06-6) | ≥1 non-stale sig AND (requires_all) every active-as-of-confirm guardian signed | backdated-active-unsigned guardian → red; revoked-before-confirm → green |
| `ladders.liveness` (S06-6) | no open reminder ladder overdue >1h | `hasTable`-guarded, vacuous until S09 (teeth land with S09) |
| `teams.learn_gate_integrity` (S06-7) | every Learn gate met threshold **at pass time** (immutable snapshot) | below-threshold snapshot → red |
| `sessions.no_stale_published` (S06-7) | no published/full/in-progress session past its end | past-end session → red, `sessions:advance` → green |
| `sessions.attendance_integrity` (S06-7) | attendance only on a session that ran | mark on a never-run session → red |
| `bookings.cascade_live` (S06-7) | no Withdrawn enrolment holds a future booking | withdrawn+future booking → red, cascade → green |

**Snapshot-time discipline** (your caution): `learn_gate_integrity` judges the **immutable eligibility snapshot** written to the `stage_gate.passed` audit at approval (`qualifying/active_members/team_gate_pass_pct`), never a live recompute — a team whose attendance/roster legitimately changes after the gate passed does not false-red. `no_stale_published` is backed by the new `sessions:advance` job (its healthy state is reachable). `attendance_integrity`'s red is a mark on a never-run session (the bypass), not a structurally-impossible orphan.

## 4. Elevation review — 49 `asSystem()` sites (18 new in S06)
Every elevation is allowlisted; the phpunit scan fails the build on any un-declared site. The 18 S06 sites, each **authority-checked-first, narrow, system-only write**:

| Call site | Why system, what it touches |
|-----------|-----------------------------|
| `EnrolmentActivationService::run` | scheduled: confirmed→active for started programmes; enrolment state is system-only. |
| `SessionService::create/transition/reschedule/clashPreview` | organiser (academy ops) session lifecycle + reschedule snapshot; authority before elevation. |
| `MentorService::setStatus` | mentor lifecycle; departed blocked while future sessions exist. |
| `BookingService::book/cancel` | student self-service; capacity FOR UPDATE (BI-3), own booking only. |
| `BookingService::cascadeWithdrawal` | 2.21: withdrawn enrolment's future bookings cancelled + waitlist released. |
| `SessionAdvancementService::run` | scheduled: sessions past their time → in_progress/completed. |
| `AttendanceService::mark` | mentor/academy records attendance on a run session; authority resolved in-elevation (403 not 404). |
| `EventService::create/transition` | academy network events. |
| `MemberSurfaceService::rsvp/upsertProfile` | Member self-service, own row only. |
| `AssessmentService::create/transition/grade` | academy assessment lifecycle; grading writes results (embargo enforced at READ). |

The pre-S06 31 are unchanged from the S05 gate.

## 5. OD / amendment / Build-Invariant trace
| Decision / rule | Enforced where | Guarding assertion |
|-----------------|----------------|--------------------|
| R3 activation = programme start (payment-decoupled) | `EnrolmentActivationService` (SYSTEM) | `enrolments.activation_liveness` |
| FR012 tracker locked until Active | `TrackerService::approveGate` start-lock | — |
| R2/OD-12 Learn gate = Option B (computed-eligible, teacher-confirmed) | `LearnGateService` + `approveGate('Learn')` precondition + pass-time snapshot | `teams.learn_gate_integrity` |
| R1 threshold in `certification_rules` | `LearnGateService` reads it | (via learn_gate_integrity) |
| Suspended EXCLUDED from Learn denominator (ruling) | `LearnGateService` counts status='active' | (product ruling, §6) |
| 2.3 session state machine + advancement | `SessionService`, `SessionAdvancementService` | `sessions.no_stale_published` |
| 2.24 reschedule clash | `SessionService::reschedule/clashPreview` | — |
| 2.6 mentor departed-blocked | `MentorService` | — |
| 2.5 assessment lifecycle + embargo | `AssessmentService` + `assessment_results` RLS | (embargo test) |
| 2.21 withdrawal cascade to bookings | `BookingService::cascadeWithdrawal` in `ApplyWithdrawal` | `bookings.cascade_live` |
| 2.10 ladder liveness | `LadderLivenessAssertion` (hasTable-guarded) | `ladders.liveness` (vacuous→S09) |
| OD-22/FR058/OD-1 Member surfaces | events/event_rsvps/member_profiles + `member` scope branch | `authz.member_directory_exclusive` (S01) |
| OD-57/58 requires_all consent at rest | `consent_complete_at_confirm` hardened branch | `teams.consent_complete_at_confirm` |
| **BI-3** seat/capacity FOR UPDATE | booking claim FOR UPDATE on session row | (cross-connection race test) |
| **BI-6** consent hash/language | (S03) + confirm-time consent | `teams.consent_complete_at_confirm` |
| **BI-7** Withdrawn only via workflow | `ApplyWithdrawal` (only path) | `enrolments.no_status_bypass` (S04A) |
| **BI-8** every transition audits actor | all transitions via audit service; SYSTEM attribution (OD-64) | across the battery |

## 6. Deviations (honestly recorded)
| # | Item | Why / status |
|---|------|--------------|
| **flag #2 — DEPENDENCY for S04C/D/E** | The requires_all hardening reads "active-as-of-confirm" from `guardian_link` audit events with `to_state='active'`. The two known creation paths (`GuardianStudentService`, `PairingService`) audit it. **A future onboarding path (S04C/D/E) that creates a link WITHOUT that audit would read its guardians as not-active** — a false green for that link. **The onboarding sprints MUST audit link activation with `to_state='active'`.** Recorded here so they find it. |
| suspended-excluded Learn denominator | Product ruling (Leo, 2026-07-29): a payment lapse must not penalise a team's learning pass rate. Code counts `status='active'` only. |
| ladder liveness `hasTable`-guarded, not a built table | Honors "2.10 register · S09 full" without guessing S09's schema; vacuous now, activates + gets teeth when S09 lands the ladder. |
| booking on live enrolment / attendance on in_progress split | Deliberate (card note, `1926a26`): a student may reserve a seat on a session published before the programme starts; attendance requires In Progress/Completed. |
| `programme_sessions` (not `sessions`) | `sessions` is the framework session store; the domain table is renamed to avoid collision. |
| `session_bookings` table shipped S06-2, workflow S06-3 | reschedule/clash need bookings to exist; the table lands with sessions, the workflow with bookings. |
| grader = academy operations | no teacher-grader model built (un-asked); the embargo's "grader/academy sees all" is the academy capability set. |
| Learn eligibility snapshot added at S06-7 | there was no immutable record of eligibility at pass time; added to the `stage_gate.passed` audit so `learn_gate_integrity` judges snapshot-time. |

## 7. Exit gate (real output)
```
php -d memory_limit=1G vendor/bin/phpunit        →  OK (323 tests, 4343 assertions)
php artisan reconcile:run                        →  RECONCILE PASS — 39 assertion(s), 39 passed, 0 failed
php artisan reconcile:run --tag=S06              →  7 passed (activation_liveness, consent_complete_at_confirm [hardened],
                                                     ladders.liveness, learn_gate_integrity, no_stale_published,
                                                     attendance_integrity, cascade_live)
php artisan migrate --pretend                    →  Nothing to migrate
ScopeElevationTest                               →  every asSystem call site allowlisted (49 elevations)
sessions:advance / enrolments:run-activations    →  scheduled (5-min / 02:15 HKT)
```
**Verdict:** PASS — pending Leo's joint gate clearance.

## 8. Leftovers & newly discovered risks
| # | Item | Severity | Proposed |
|---|------|----------|----------|
| 1 | Onboarding paths must audit `guardian_link` activation `to_state='active'` (flag #2) | med | **S04C/D/E** |
| 2 | Reminder-ladder mechanism + `ladders.liveness` teeth | — | **S09** |
| 3 | Teacher-grader model for assessments (only academy grades today) | low | if the client wants mentor grading |
| 4 | `RolesTrackerTest::pooledStudent` nullable-param deprecation (PHP 8.4 notice, not a failure) | low | tidy when convenient |
