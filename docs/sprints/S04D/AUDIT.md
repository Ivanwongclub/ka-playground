# AUDIT KAP-S04D — Linkage approval, S01 retrofits & bulk creation (2.30)

**Result:** PASS · **Date:** 2026-07-31 · **HEAD at gate:** `d86d11d`

> Written by Claude Code at the sprint's end. Honesty outranks looking good. This is the BUILD audit;
> the in-product audit element (Linkage Approval Report) is separate.

## 1. Files changed
| Path | A/M/D | Why |
|------|-------|-----|
| `app/Services/Identity/AccountMintingService.php` | A | **D-i-2** — the born-UNVERIFIED + activation-token primitive extracted to ONE place; `approve`, `doAccept`, bulk all call it (`mintPendingActivation` / `mintWithPassword`). Credential-only-at-activation cannot diverge. |
| `app/Services/Identity/BulkStudentCreationService.php` | A | STEP 4 — row-by-row bulk student creation with a full report, per-row roll authority, idempotent, active `school_links` audited (`origin='bulk'`). |
| `app/Services/Identity/RegistrationApprovalService.php` | M | STEP 1 — inline mint replaced by `AccountMintingService`. |
| `app/Services/Identity/InvitationService.php` | M | STEP 1 — `doAccept` inline mint → service; the previously **un-audited** active `teacher_links` write now emits `teacher_link.created` to_state='active'. |
| `app/Services/Identity/PairingService.php` | M | STEP 2 — `confirm` now pends (`pending_approval`), not self-activates; `redeem` refuses an uninitiated second guardian (OD-24). |
| `app/Services/Identity/LinkageService.php` | M | STEP 3 — `recordGuardianAdditionVisibility` (OD-24, per existing guardian); `isUninitiatedSecondGuardian`; `rejectLink` → `rejected`. |
| `app/Http/Controllers/LinkController.php` | M | STEP 3 — `schoolVouch` wraps guardian lookup + active create + audit + visibility in ONE `asSystem` (OD-30); `requestByEmail` refuses second-guardian self-add. |
| `app/Http/Controllers/SchoolAdminController.php` | M | STEP 4 — `bulkStudents` endpoint (validate rows, delegate, 201 + report). |
| `app/Http/Controllers/AccessIdentityReportController.php` | M | STEP 3/4 — audit element: onboarding block gains `bulk_created_by_school`. |
| `app/Services/Reconciliation/Assertions/NoActiveWithoutApprovalAssertion.php` | A | STEP 1 — the all-three-tables provenance backstop. |
| `app/Services/Reconciliation/Assertions/GuardianAdditionVisibilityAssertion.php` | A | STEP 3 — no silent guardian addition (OD-24, vouched included). |
| `app/Services/Reconciliation/Assertions/VouchScopeAssertion.php` | A | STEP 3 — vouched links stay within the voucher's roll (OD-30). |
| `database/migrations/2026_07_31_120000_link_state_machine_hardening.php` | A | STEP 1 — `school/teacher_links` enum += `pending_approval`; `origin` columns; audited `legacy-approved` backfill on real `created_at`. |
| `database/migrations/2026_07_31_130000_guardian_link_rejected_state.php` | A | STEP 2 — guardian_links enum += `rejected`. |
| `database/migrations/2026_07_31_140000_link_visibility_and_hardening.php` | A | STEP 3 — `link_visibility_events` (scoped) + the deferred write-policy hardening (only system writes `status='active'`). |
| `config/scope-elevations.php` | M | Allowlisted the new/changed sanctioned elevations (`schoolVouch`, `requestByEmail`, `redeem`, `BulkStudentCreationService::create`); removed retired `GuardianStudentService::createStudent`. |
| `config/scope-map.php`, `Providers/ReconciliationServiceProvider.php` | M | Scope entry for `link_visibility_events`; register 3 new assertions. |
| `routes/api.php` | M | `POST /school/bulk-students` (role:school_admin). |
| `tests/Feature/{LinkStateMachineTest,VouchVisibilityTest,BulkStudentCreationTest}.php` | A | STEP 1/3/4 feature coverage. |
| `tests/Feature/{LinkingFlowsTest,LinkageTest}.php` + 8 files | M | Retrofit: `confirm` now pends; setup helpers that seed active links switched `set($this->ops)` → `setSystem()` (the hardening requires system context for active writes — a setup change, not an assertion change). |
| `docs/sprints/S04D/SPRINT.md` | M | Plan reconciled to shipped state (D-i-1/2/3 rulings). |

## 2. Step-by-step verification (real output, pasted)

### STEP 1 — state-machine enum + origin + AccountMintingService + doAccept audit + all-three assertion · commit `2075072`
Enum + `origin` columns added to `school/teacher_links`; `AccountMintingService` extracted (approve +
doAccept behaviour-preserving); `doAccept`'s active `teacher_links` write now audited; backfill keyed
on real `created_at`; `links.no_active_without_approval` registered and green from first run. The
gate-authority guardrail confirmed: `TrackerService::gateApproverKind` reads `team_teacher_links` only —
a school-linked teacher gains NO Learn-gate power (see §6). Result: **PASS**.

### STEP 2 — guardian ceremony retrofit: confirm pends · commit `7c6e2b6`
`PairingService::confirm` accept → `pending_approval` (not active); `rejectLink` → `rejected`. Only an
admin's `approveLink` reaches active. Result: **PASS**.

### STEP 3 — vouch elevated + OD-24 visibility + write-policy hardening · commit `e6ced93`
`schoolVouch` collapses initiate+approve into one audited `asSystem` act (OD-30) and records visibility
to every existing guardian (OD-24); `requestByEmail` refuses an uninitiated second-guardian self-add
(Leo's refuse-only ruling); the write-policy hardening lands here (deferred from STEP 1 per Leo's
ruling) so only system context writes `status='active'`. Two assertions added. Result: **PASS**.

### STEP 4 — bulk student creation via AccountMintingService · commit `d86d11d`
```
$ php -d memory_limit=1G vendor/bin/phpunit --filter BulkStudentCreationTest
....                                                                4 / 4 (100%)
OK (4 tests, 26 assertions)

$ php -d memory_limit=1G vendor/bin/phpunit --filter ScopeElevationTest
....                                                                4 / 4 (100%)
OK (4 tests, 7 assertions)
```
Live drive on the dev DB (mixed A/B batch, then re-upload; cleaned up after):
```
REPORT: created=1 skipped=0 rejected=1
  rejected reason: not a school you administer (OD-30 roll authority)
RE-UPLOAD: created=0 skipped=1 rejected=1
ANN verified? no (born-unverified, correct)
ANN token? yes
ANN school_link: status=active origin=bulk
```
Defined failure mode (row-by-row, DB::transaction INSIDE the loop, catch→reject-this-row+continue,
batch-level `bulk.students_created` audit); per-row roll authority; idempotent; `mintPendingActivation`
(no third inline copy); active `school_link` audited to_state='active' via system context. Result: **PASS**.

## 3. Assertions registered this sprint
| Assertion | Tag | First green run output pasted? |
|-----------|-----|-------------------------------|
| `links.no_active_without_approval` (all three tables) | S04D | Yes (STEP 1; green here §6) |
| `links.guardian_addition_visibility` | S04D | Yes (STEP 3; green here §6) |
| `links.vouch_scope` | S04D | Yes (STEP 3; green here §6) |
| `links.activation_audited` stays guardian-only | S06 | Unchanged — deliberately NOT broadened (the tag reflects its consent dependency) |

## 4. Deviations from SPRINT.md
| Card said | Actually happened | Why |
|-----------|-------------------|-----|
| Write-policy hardening in STEP 1 | Deferred to STEP 3 | Leo's ruling — the hardening broke `confirm`'s (pre-STEP-2) active write and vouch's (pre-STEP-3) active-outside-elevation write; harden ONCE after both are fixed. AskUserQuestion → "Defer hardening to STEP 3." |
| Existing-guardian-initiates co-guardian flow (OD-24 initiation) | Refuse-only; ceremony deferred | Leo's ruling — no such flow existed in Phase 1; vouch is the named single-actor path. AskUserQuestion → "Refuse-only (vouch is the Phase-1 path)." |
| Bulk intake shape | JSON rows (max 500), NOT CSV/file-upload | **Gate-record scope note (Leo):** CSV parsing + the ClamAv-scanned intake is **S04E**, which feeds parsed rows into the same `create()`. The authority / idempotency / minting guarantees are file-format-independent — a documented boundary, not a silent assumption. |

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | CSV/xlsx parsing + ClamAv-scanned bulk intake (feeds parsed rows into `BulkStudentCreationService::create`) | Planned | S04E |
| 2 | Existing-guardian-initiates co-guardian ceremony (currently refuse-only) | Deferred (by ruling) | Post-Phase-1 if the client wants it |
| 3 | `kap-clamav-1` runs **unhealthy** in the 3.8 GB dev VM (clamd OOMs loading the 3.2M-sig DB) — ClamAv **integration** tests error locally on empty scan responses. Benign now (false-reds an unrelated suite; S04D touched no upload path), but the scanner becomes **load-bearing** when S04E ships the ClamAv-scanned upload intake. Has flaked across S04C / S04D-STEP4 / this gate. The VM-memory/CI fix must land **before go-live**, not sit as a standing flake. | Infra (load-bearing from S04E) | **S10 (go-live)** |

## 6. Exit gate
```
$ php artisan reconcile:run --tag=S04D
  PASS  links.no_active_without_approval [2.30 · OD-23 · OD-27 · S04D STEP 1] ...
  PASS  links.guardian_addition_visibility [OD-24 · OD-30 · S04D STEP 3] ...
  PASS  links.vouch_scope            [OD-30 · S04D STEP 3] ...
RECONCILE PASS — 3 assertion(s), 3 passed, 0 failed

$ php artisan reconcile:run          # all prior tags + S04D
RECONCILE PASS — 47 assertion(s), 47 passed, 0 failed

$ php -d memory_limit=1G vendor/bin/phpunit --filter '/^(?!.*ClamAv).*/'   # full suite, ex-clamd (see §5 #3)
...........................................................     374 / 374 (100%)
OK (374 tests, 4554 assertions)

# gate-authority guardrail — the Learn-gate authority still reads team_teacher_links ONLY:
app/Services/Teams/TrackerService.php:101
    $linked = DB::table('team_teacher_links')->where('team_id', $team->id)
        ->where('teacher_id', $approver->id)->where('status', 'active')->exists();
# a school-linked teacher (teacher_links) gains NO Learn-gate power — unchanged from S05.

# doAccept teacher_link activation now audited (STEP 1):
app/Services/Identity/InvitationService.php:114
    $this->audit->record(entityType: 'teacher_link', ... action: 'teacher_link.created', toState: 'active' ...)

# ClamAv INTEGRATION tests (excluded above) — pre-existing infra flake, NOT a code regression:
$ php -d memory_limit=1G vendor/bin/phpunit --filter ClamAv
RuntimeException: clamd scan failed: ''        # kap-clamav-1 is "Up (unhealthy)"; clamd OOM in the 3.8GB VM
Tests: 4, Errors: 4.                           # S04D touched no upload path; unrelated to this sprint
```
**Verdict:** PASS. The three S04D assertions green, the full 47-assertion battery green, the full test
suite (374 tests) green ex-clamd, the gate-authority guardrail intact, `doAccept` now audited. The only
red is the ClamAv **integration** suite, which is an unhealthy local clamd container (infra, §5 #3), not
a defect in any S04D code path.

## 7. Invariant check
| BI | Touched? | Evidence (test/assertion name) |
|----|----------|-------------------------------|
| BI-1 (audit INSERT-only) | Yes — all activations audited | Every active link writes `to_state='active'`; `links.no_active_without_approval` |
| BI-7 (no direct status write) | Yes — extended to links | Write-policy hardening: only system writes `status='active'`; `confirm` pends, only `approveLink` activates |
| BI-8 (status transition audits actor) | Yes — the core of this sprint | `doAccept` teacher_link now audited; `links.no_active_without_approval`, `links.guardian_addition_visibility`, `links.vouch_scope` |
| Scope-elevation discipline | Yes | `ScopeElevationTest` green — every `asSystem` site (incl. `BulkStudentCreationService::create`) allowlisted with an exact reason |
| Account provenance | Yes | `account.provenance` green — bulk students carry `user.created` (`origin='bulk_creation'`); no back door |
