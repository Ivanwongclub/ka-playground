# AUDIT KAP-S05 — Teams, 成團, seat claim, deadline machinery, resilience, roles & tracker

**Result:** PASS (pending Leo's gate clearance) · **Date:** 2026-07-28 · **HEAD before gate:** `2fe3414` (S05-5)

> Build audit for Leo. Honesty outranks looking good. The in-product audit element (Team & Capacity
> Report) is separate — this is the build record. The gate commit is HELD for joint review.

## 1. Files changed (by step)
| Step | Commit | Key paths |
|------|--------|-----------|
| S05-1 formation in lobbies | `689873f`, `5a513ba` | `teams`, `team_members` migration; `FormationService`; ADR-0001 |
| S05-2 programme_capacity + 成團 | `71b7b8e` | `programme_capacity`; `TeamConfirmationService`; `WizardService` capacity |
| S05-3 deadline machinery | `dc0cd04` | `team_exceptions` + refund system-path; `FormationDeadlineService`, `MatchingService`, `ParkingBackstopService`; `refunds.backstop_provenance` |
| S05-4 resilience | `fa3e581` | team_resilience migration; `LapseDetectionService`, `TeamResolutionService`; `deadlines.no_silent_lapse`; #3 re-team fix |
| S05-5 roles & tracker | `2fe3414` | `team_teacher_links`, `tenures`, `stage_gates`; `RoleRotationService`, `TrackerService`, `TeamTeacherLinkService` |
| S05-6 gate (this) | HELD | 5 assertions + `TeamCapacityReportController`; `S05GateAssertionsTest`; this AUDIT.md |

## 2. Every sprint ships three things — the S05 check
Module ✅ · in-product **audit element** (Team & Capacity Report, `GET /admin/programmes/{id}/team-capacity-report`) ✅ · **reconciliation assertions** (7, below) ✅.

## 3. Assertions registered this sprint (`--tag=S05`, 7 total)
| Assertion | Judges | Red-then-green teeth |
|-----------|--------|----------------------|
| `refunds.backstop_provenance` (S05-3) | backstop_auto refund traces to a lapsed parked cause | `S05...`/`DeadlineMatchingTest` planted orphan refund → red |
| `deadlines.no_silent_lapse` (S05-4) | lapsed family order has SYSTEM audit + suspension/exception | `TeamResilienceTest` overdue-unrecorded → red, job → green |
| `capacity.conservation` | Σ confirmed-team members ≤ capacity (real overbook backstop) | planted 3rd member over cap-2 → red (OVERBOOK) |
| `capacity.claims_are_whole` | team.confirmed audit: seats_claimed = member_count (**confirm-time fact**) | planted partial-claim audit → red |
| `teams.consent_complete_at_confirm` | every enrolment.confirmed had a signature signed ≤ confirm AND **not superseded by then** (**past facts, not live**) | planted confirm w/ no consent → red; signature **superseded BEFORE confirm** → red (the stale case, Leo gate catch); superseded AFTER confirm → green |
| `teams.size_or_waiver` | confirmed team meets min (active+suspended) OR waiver_reason | removed member below min → red, waiver → green |
| `pool.no_expired_parking` | no open parked_rollforward past its backstop | rolled with past backstop → red, sweep → green |

**Design note (per Leo):** `claims_are_whole` and `consent_complete_at_confirm` judge the PAST decision by PAST facts (audit payload / signature timestamp vs confirm timestamp), never a live join — so a legitimate post-成團 change (assign, dissolve, a later material supersede handled by re-consent) does not false-red a correctly-confirmed team. `capacity.conservation`'s red is an actual planted overbook (members > capacity).

## 4. Elevation review — all 31 `asSystem()` call sites (the biggest sprint: +19 in S05)
Every elevation is allowlisted in `config/scope-elevations.php`; the phpunit scan `every_as_system_call_site_in_the_codebase_is_allowlisted` fails the build on any un-declared site. The 19 S05 elevations:

| # | Call site | Why system, and what it touches |
|---|-----------|--------------------------------|
| 13 | `FormationService::addMember` | join transitions the member's enrolment in_pool→teamed (enr_update is system-only); student's pooled+eligibility authority checked in their own context first. One enrolment. |
| 14 | `WizardService::seedCapacity` | publish seeds programme_capacity (system-only table) from eligibility.capacity; one insert in the publish tx. |
| 15 | `WizardService::saveSection` | post-publish capacity raise/lower edit on programme_capacity (system-only), after the OD-31 lower-below-claimed guard; never claimed. |
| 16 | `TeamConfirmationService::submit` | forming→submitted (teams.status system-only); submitter authority checked. |
| 17 | `TeamConfirmationService::confirm` | the 成團 seat-claim tx — FOR SHARE consent(+guardian_links), FOR UPDATE capacity, teamed→confirmed, obligations; approver authority (OD-39) established first; only members' rows. |
| 18 | `FormationDeadlineService::run` | scheduled: auto-submit compliant forming teams / raise deadline_noncompliant; past-deadline programmes only. |
| 19–21 | `MatchingService::match/roll/release` | admin matching-screen writes (in_pool→teamed / park / →released); academy-operator + lobby eligibility checked first. |
| 22 | `ParkingBackstopService::run` | scheduled loop-breaker: full auto-refund (backstop_auto, out of BI-9 per OD-47) + release of expired parking; expired rows only. |
| 23 | `LapseDetectionService::run` | scheduled: suspend lapsed members, SYSTEM lapse audit, FR066 + below_min exceptions. |
| 24–28 | `TeamResolutionService::assign/extendGrace/waive/dissolve/recordSchoolLeave` | the four terminal actions + school-leave; academy-operator checked first; each resolves its exception. |
| 29 | `TeamTeacherLinkService::link` | teacher↔team link (OD-61); lobby school admin / academy authority checked first. |
| 30 | `RoleRotationService::assignRole` | tenure ledger handover (system-only); recorder authority checked first; atomic end-prior→open-new. |
| 31 | `TrackerService::approveGate` | gate pass; **authority resolved INSIDE the elevation** via explicit actor-id filters (a linked teacher may not be able to read the team via RLS, but OD-61 authority is a policy call, not a visibility one → clean 403, not 404). |

The pre-S05 13 (rows 1–12 + FormationService predecessor) are unchanged from earlier gates.

## 5. OD / Build-Invariant trace
| Decision / BI | Enforced where | Guarding assertion |
|---------------|----------------|--------------------|
| OD-31/32 team-based capacity | `programme_capacity` + CHECK; 成團 FOR UPDATE claim | `capacity.conservation`, `capacity.claims_are_whole` |
| OD-33 formation deadline | `FormationDeadlineService`; publish/edit ordering | `deadline.ordering` (S04A) |
| OD-34 awaiting-a-team pool | in_pool pool; matching screen | (report: pool_depth) |
| OD-35 match/roll/release + 90-day backstop | `MatchingService`, `ParkingBackstopService` | `pool.no_expired_parking`, `refunds.backstop_provenance` |
| OD-36 failed assignment terminal | `team_exceptions` failed_assignment; academy-decided | — |
| OD-37 four terminal actions, grace-once | `TeamResolutionService`; grace_extended flag | `teams.size_or_waiver` |
| OD-38 dissolution re-pool paid, no re-charge | `dissolve` + confirmed→in_pool; **#3 obligation-skip** | (test: re-team no-recharge) |
| OD-39 approver authority | `assertApprover` (成團), resolution operators | — |
| OD-40 size waiver = field | `teams.waiver_reason` | `teams.size_or_waiver` |
| OD-45 non-payment lapse cascade | `LapseDetectionService` (SYSTEM actor) | `deadlines.no_silent_lapse` |
| OD-47 gateway/system money out of BI-9 | backstop_auto refund path | `refunds.backstop_provenance` |
| OD-48 full-only refund | backstop auto-refund = order total | `refunds.full_only` (S04B) |
| OD-57/58 consent at 成團 (no stale) | confirm re-verifies under FOR SHARE | `teams.consent_complete_at_confirm` |
| OD-61 teacher team-linked gate approval | `team_teacher_links`; `TrackerService` | — (five-branch test) |
| OD-62 school-leave, team stands | `recordSchoolLeave` | — |
| OD-15/36 role rotation, no stacking | `tenures_one_active_role` partial-unique; `assignRole` | — (rotation test) |
| **BI-3** seat-lock FOR UPDATE | 成團 + assign FOR UPDATE on programme_capacity | `capacity.conservation`, twin-team race test |
| **BI-6** consent hash / language | (S03) + confirm-time consent | `teams.consent_complete_at_confirm`, `consent.bi6_...` |
| **BI-8** every transition audits actor | all transitions via audit service; SYSTEM attribution (OD-64) | `enrolments.no_status_bypass` |
| **BI-9** SoD manual money; gateway/system out | backstop auto-refund out of BI-9 (OD-47), guarded | `refunds.backstop_provenance`, `payments.bi9_manual_sod` |

## 6. Deviations from SPRINT.md (honestly recorded)
| Card said | Actually happened | Why / status |
|-----------|-------------------|--------------|
| STEP 3 backstop "full refund + release" | Added a NEW `refunds` system-path (nullable withdrawal_request_id + `origin`, biconditional CHECK) + `pc_delete` policy | The existing refund path is BI-9 two-person + withdrawal-tied; the system auto-refund needed its own vehicle. **Leo-ruled** (option 1, both guardrails). |
| — | `deadlines.no_silent_lapse` + `refunds.backstop_provenance` registered EARLY (S05-3/4, not S05-6) | Leo rulings — the provenance assertion is the replacement control for an out-of-BI-9 path; no_silent_lapse lands with its resolution machinery. |
| OD-38 "no re-charge" implied | **#3 fix**: 成團 loop (and assign) skip the obligation for an already-paid enrolment; consumer dispatched only when one was written | Re-pooled paid member re-teaming would otherwise fire a spurious PaymentRequested against the paid order. **Leo's re-review catch — closed in S05-4.** |
| Grace period | Global config `teams.lapse_grace_days` (7), shared by the job and the assertion — not per-programme | Keeps job/assertion from drifting; per-programme grace is a later refinement. **Flagged.** |
| `assign` resolves below-min | `assign` claims a FRESH seat (FOR UPDATE); refused if capacity full (→ waive/dissolve); does not auto-evict the suspended member | Honest seat accounting; the suspended member still holds theirs. **Flagged.** |
| OD-62 school-leave | `recordSchoolLeave` is academy-INITIATED (no school-link revocation flow exists to hook) | Revocation trigger wiring lands with the school-link lifecycle. **Flagged.** |
| Gate approval by teacher | Authority resolved INSIDE the elevation; no teacher branch added to `teams_read` (teacher roster-read is out of S05 scope) | A linked teacher can't read the team via RLS, but OD-61 is a policy call → clean 403. **Flagged.** |

Process note: on the S05-4 re-upload, a same-filename zip caused a stale-extract round (Leo reviewed the pre-fix diff). Fixed by re-uploading under a fresh name; **S05-5+ bundles use distinct names.**

## 7. Exit gate (real output)
```
php -d memory_limit=1G vendor/bin/phpunit
  OK (281 tests, 3730 assertions)

php artisan reconcile:run
  RECONCILE PASS — 33 assertion(s), 33 passed, 0 failed

php artisan reconcile:run --tag=S05
  RECONCILE PASS — 7 assertion(s), 7 passed, 0 failed
  (refunds.backstop_provenance, deadlines.no_silent_lapse, capacity.conservation,
   capacity.claims_are_whole, teams.consent_complete_at_confirm, teams.size_or_waiver,
   pool.no_expired_parking)

php artisan migrate --pretend   →   INFO  Nothing to migrate.

ScopeElevationTest   →   every asSystem call site allowlisted (31 elevations)
```
**Verdict:** PASS — pending Leo's joint gate clearance.

## 8. Leftovers & newly discovered risks (input to later cards)
| # | Item | Severity | Proposed |
|---|------|----------|----------|
| 1 | Re-pooled paid member RE-TEAM handled (no re-charge); a paid member's **new** fee change on re-team is not modelled | low | when programme fee edits post-成團 are specced |
| 2 | `requires_all_guardians` at-confirm backstop: `consent_complete_at_confirm` checks ≥1 signature by confirm; the all-guardians nuance is enforced live at 成團 only | low | a stricter historical backstop if audit demands |
| 3 | Per-programme grace (vs global 7d) | low | config refinement |
| 4 | School-leave trigger wiring (school-link revocation) | med | school-link lifecycle sprint |
| 5 | Teacher team-roster read (teams_read teacher branch) | low | if teachers need a roster screen |
| 6 | Learn per-member % threshold (OD-12) | — | **S06** (out of S05 scope) |
