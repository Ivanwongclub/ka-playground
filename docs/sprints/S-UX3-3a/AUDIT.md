# AUDIT KAP-S-UX3-3a — Ops-facing 成團 core (consent-status · 成團 view · roles/tenures · resolution)

**Result:** PASS · **Date:** 2026-08-03 · **HEAD at gate:** `d92119f`

> Written by Claude Code at the card's end. Honesty outranks looking good. This is the BUILD audit; the
> in-product audit elements (the ops 成團 view, the roles roster, the resolution console) are the separate
> client-facing screens. Card + planning live in the sibling dir `docs/sprints/S-UX3-3/`
> (`CARD-S-UX3-3a.md`, `PROPOSED-S-UX3-3.md`, `PROPOSED-S-UX3-3a-consent-endpoint.md`) — this AUDIT was
> placed under `S-UX3-3a/` as instructed; flagging the split so it can be consolidated if desired.

## 0. Scope

A UX + read-layer over the built-and-live-audited **S05 teams engine**, in four reviewed steps:
- **STEP 1** — the child-safety-adjacent per-member **consent-status read** (`GET /teams/{team}/consent-status`).
- **STEP 2** — the **enabled + advisory 成團 view** (submitted queue + consent-status detail + confirm).
- **STEP 3** — **roles / tenures** (`GET /teams/{team}/roles` + assignRole tenure-change UI).
- **STEP 4** — **below-min / matching + resolution** (B4/B5 reads + the five OD-37/OD-62 terminal actions).

No new engine was written: 成團 confirm, seat-claim, matching, tenure rotation and the resolution actions
all pre-exist in S05. This card is the ops UI + the reads that back it + their tests.

## 1. Files changed (by step)

| Path | A/M | Step · Why |
|------|-----|-----|
| `docs/sprints/S-UX3-3/CARD-S-UX3-3a.md` + 2 PROPOSED | A | 0 · approved planning (think-first ×2 + build card) |
| `api/app/Services/Consent/ConsentSigningService.php` | M | 1 · `consentSummary()` — single source, delegates to `consentSatisfied` |
| `api/app/Services/Teams/TeamConfirmationService.php` | M | 1 · `assertApprover` made public (reused by the consent-status read) |
| `api/app/Http/Controllers/TeamConsentStatusController.php` | A | 1 · the booleans/counts-only read, OD-39 gate + allowlisted `asSystem` |
| `api/config/scope-elevations.php` | M | 1 · the new `asSystem` allowlist entry (reason-matched) |
| `api/app/Http/Controllers/FormationController.php` | M | 2 · B1 additive names + member_count on `GET /teams` |
| `api/app/Http/Controllers/TeamRolesController.php` | A | 3 · B3 roles/tenure read (member-readable, no elevation) |
| `api/app/Http/Controllers/MatchingController.php` | M | 4 · B4 additive student names + min_team_size |
| `api/app/Http/Controllers/TeamCapacityReportController.php` | M | 4 · B5 additive approver/student/waived_by names |
| `api/routes/api.php` | M | 1,3 · `GET /teams/{team}/consent-status`, `GET /teams/{team}/roles` |
| `web/src/pages/Teams.tsx` | A→M | 2,3,4 · the ops 成團 view: queue, tabbed detail drawer, resolution console |
| `web/src/nav.tsx` · `web/src/main.tsx` | M | 2 · `/team` route + nav item revealed (operations.manage) |
| `web/src/display/status.tsx` | M | 2 · `teamStatus` StatusTag domain |
| `web/src/i18n/locales/{en,zh-TC,zh-SC}.json` | M | 2,3,4 · trilingual `teams.*` (parity 553/553/553) |
| `api/tests/Feature/TeamConsentStatusTest.php` | A | 1 · 4 tests |
| `api/tests/Feature/TeamListNamesTest.php` | A | 2 · 2 tests (B1 names) |
| `api/tests/Feature/TeamRolesReadTest.php` | A | 3 · 3 tests |
| `api/tests/Feature/TeamResolutionScreensTest.php` | A | 4 · 4 tests |

## 2. Step-by-step verification

### STEP 1 — consent-status read · commit `c57d2e4`
`consentSummary(programmeId, studentId)` is the **single source**: its `satisfied` **delegates** to
`consentSatisfied(...)` — never re-derived, so the read and the confirm-time gate agree by construction.
`signed_count`/`guardian_count` are `->count()` on the same queries; **guardian ids never leave the
method** — only a `blocker` enum (`null | awaiting_signature | stale | not_requested`).

The endpoint response is **exactly** the key allowlist `{team_id, mode, all_satisfied, blocking_count,
members:[{student_id, student_name, satisfied, signed_count, guardian_count, blocker}]}`. **Forbidden and
proven-absent:** `guardian_id`, `signer_id`, any guardian name, per-guardian request rows, request ids,
`signed_at`/timestamps, signing order. Authority = **OD-39** `assertApprover` (lobby school-admin / academy
ops·super) checked in-service **before** the elevation; a guardian who can see the team → **403**; an
unaffiliated caller → **404** (RLS-shaped absence, no existence leak). The read runs under an
**allowlisted `asSystem`** whose reason string matches `config/scope-elevations.php` verbatim
(`ScopeElevationTest` green). The **dual privacy tooth** — a serialized-JSON string-search (no guardian
name/id/`signer`/`signed_at`) AND an exact key-allowlist assertion — reds if a `signer_id` is ever added.
```
Team Consent Status ✔ privacy tooth + key allowlist ✔ five-branch authority
                    ✔ teamed-unsatisfied blocker ✔ satisfied delegates to consentSatisfied
```
Result: **PASS**

### STEP 2 — enabled + advisory 成團 view · commit `263b941`
B1 additive S-UX2b names (programme / category / `created_by`) + active `member_count` on `GET /teams` —
LEFT joins, count-preserving, `created_by_name` double-gated by `users_read`. The 成團 confirm is **SHOWN
and ENABLED** (never client-disabled); the server's **FOR SHARE re-check is authoritative**, a `422`
consent-unsatisfied / capacity refusal is **rendered** (S-UX3-1 error surface), and the count "X of N
signed" is the primary signal with the coarse `blocker` subordinate. **B2 (member-readable roster) was
deferred to S-UX3-3b** (the ops detail is driven by the STEP-1 consent-status endpoint, which already
returns the member list with names — no separate roster needed for the ops view).
```
Team List Names ✔ additive names + member count ✔ count-preserving under a hiding users_read
```
Result: **PASS**

### STEP 3 — roles / tenures · commit `25b0df3`
`GET /teams/{team}/roles` (B3). **Authority stated explicitly, NOT inherited from B0:** MEMBER-READABLE,
resolved **within the caller's RLS** — **no `assertApprover`, no `asSystem`**. `tenures_read` admits the
holder, the holder's active guardian, academy ops/audit/super, and the lobby school-admin — a **different
five-branch than B0** (holder → **200** where B0 returns 403). Role definitions come from `role_library`
(not RLS-scoped); only holders (tenures) are RLS-scoped, holder name double-gated by `users_read`. The
**one-active-holder invariant** rests on the DB partial-unique `tenures_one_active_role (team_id, role_id)
WHERE state='active'`: `current` is the single active tenure, `past` are ended tenures — proven across a
rotation (prior holder → `past` with `ended_at`; new holder → `current`; DB active count = 1). The
assignRole UI carries the tenure-change confirm ("…ends [current]'s tenure and starts a new one — a role
has one active holder").
```
Team Roles Read ✔ member-readable within RLS ✔ reflects one active holder after a rotation
                ✔ holder name double-gated and row preserved
```
Result: **PASS**

### STEP 4 — below-min / matching + resolution · commit `d92119f`
B4 (matching screen) + B5 (capacity report): **additive S-UX2b names, each a double-gated LEFT join,
count-preserving**, resolved within the caller's RLS — **no elevation**. B4 adds `student_name` on unplaced
+ parked, plus `min_team_size`; B5 adds `approver_name` (confirm log), `student_name` (exception ledger),
`waived_by_name` (waiver register). The **NULL-name tooth**: a team-scoped `below_min` exception (no
enrolment) survives the ledger with `student_name = null` — the join never drops the row nor surfaces the
raw id. B4/B5 are RLS-shaped (no gate); the exception ledger is academy-ops/audit-only per
`team_exceptions.te_read = system OR opsAudit`. The **five OD-37/OD-62 terminal actions** (assign, waive,
dissolve, extend-grace, school-leave) are each SHOWN + ENABLED with a **consequence-stating confirm**;
the server (academy operations, OD-37) is authoritative and **every refusal is rendered**; the **RAISE
stays system-automatic** (deadline sweep) — no UI trigger.

Per-action refusals (from `TeamResolutionService`, tested):
| Action | Refusals | Consequence copy |
|--------|----------|------------------|
| assign | 404 team/enrolment · 409 team-not-confirmed · 422 not-in_pool / cross-programme / lobby-ineligible / no-capacity · 409 no-free-seat · 403 authority | claims a seat + issues that member's obligation |
| waive | 422 reason-required · 404 team · 409 team-not-confirmed · 403 | overrides the below-min rule; stands below strength (reason modal) |
| dissolve | 404 team · 409 only-a-confirmed-team · 403 | ends the confirmed team; seats released; cannot be undone |
| extend-grace | 404 member · 409 not-suspended · 409 already-extended-once · 403 | one-time (OD-37); cannot be repeated |
| school-leave | 404 member · 409 exception-already-open · 403 | the team STANDS (OD-62); not a withdrawal |
```
Team Resolution Screens ✔ B4 names + min + count-preserving ✔ B5 names + NULL-name count-preservation
                        ✔ all five writes require an academy operator (403) ✔ each action's representative refusal
```
Result: **PASS**

## 3. Assertions registered this card

**None.** The card ships reads + UI over the built S05 engine; its guarantees are proven by the 13
STEP-1..4 feature tests, not by a new nightly assertion. The reconciliation battery is therefore
**unchanged at 58** (no runner-count guard bump). *(Confirmed: `reconcile:run` → 58 assertion(s).)*

## 4. Deviations from the card

| Card said | Actually happened | Why |
|-----------|-------------------|-----|
| STEP 2 lists B2 (member-readable roster) | **B2 deferred to S-UX3-3b** | The ops detail is driven by the STEP-1 consent-status endpoint (already returns member names + consent); a separate roster is a student-view (3b) concern, not needed for the ops view. |
| STEP-2 drawer title "{team} — consent status" | **Neutralised to "{team} — team detail"** in STEP 3 | The drawer gained a Roles tab; the consent-specific title was stale for a two-tab drawer. Cosmetic; risk shot re-taken to match committed code. |
| STEP 4 "resolve" actions on confirmed teams | **Actionable confirmed-team list sourced from RLS `GET /teams`, not the audit-gated `confirm_log`** | `confirm_log` reads `audit_events` (needs `audit.read`); OD-37 authority is `operations`. Driving the list off `confirm_log` would hide actionable teams from a pure-ops admin. Deliberate, correct: **who-can-act-can-see**. `confirm_log`/`approver_name` remains a tested B5 delta but is not the action surface. |
| (process) screenshots need data the seed lacks | **Synthetic local-only fixtures stood up, then torn down**; teardown twice reddened `account.provenance` (an un-audited demo user lingered), fixed by removing the orphan and **re-confirming 58/58** | Un-audited demo accounts trip the provenance battery; the teardown-then-reconcile discipline caught it both times. |

## 5. Leftovers & newly discovered risks (input to the next cards)

| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | **Student formation view** (create/join lobby, my-team); **B2 member-readable roster lives here** | planned | **S-UX3-3b** |
| 2 | **`users_read` co-member widening** for team-project finance visibility — child-safety RLS, heavy standalone think-first — **HELD, does not move forward casually** | HELD | **S-UX3-5** |
| 3 | Waiver-register person column reuses the "Confirmed by" label (minor mislabel; the person is the waiver) | cosmetic | any later teams touch |
| 4 | `teacher`/`school_admin` still unseeded — the lobby-admin OD-39 approver path and gate-approval have no demo account | provisioning | **S-UX4** (fold into re-seed) |

## 6. Exit gate

```
# the four step test files + ScopeElevation
$ php vendor/bin/phpunit tests/Feature/TeamConsentStatusTest.php tests/Feature/TeamListNamesTest.php \
    tests/Feature/TeamRolesReadTest.php tests/Feature/TeamResolutionScreensTest.php tests/Feature/ScopeElevationTest.php
OK (17 tests, 101 assertions)

# reconciliation battery — unchanged (this card registers no assertion)
$ php artisan reconcile:run
RECONCILE PASS — 58 assertion(s), 58 passed, 0 failed

# full suite (ex-clamav)
$ php artisan test --exclude-group=clamav
Tests:    457 passed (5820 assertions)

# frontend
$ cd web && npx tsc --noEmit && npm run build
TSC CLEAN · bundle-budget PASSED · i18n parity 553/553/553
```
**Verdict:** **PASS.** Battery 58/58, suite 457/5820, tsc/build/i18n parity green, `ScopeElevationTest`
green (the only new `asSystem` site — the STEP-1 consent read — is allowlisted and reason-matched; STEPs
2–4 added **no** new elevation).

## 7. Invariant check

| BI | Touched? | Evidence |
|----|----------|----------|
| BI-1 (audit INSERT-only) | indirectly | reads only; the STEP-4 confirm-log reads immutable `team.confirmed` events — no write path added |
| BI-3 (capacity FOR UPDATE) | no (reused) | 成團 confirm + `assign` claim seats via the pre-built S05 service; STEP 4 wires the UI, not the lock |
| BI-6 (consent hash / language-scoped) | reflected | STEP-1 `consentSummary` delegates to `consentSatisfied` (the BI-6 gate); no signature path added |
| BI-8 (status transitions audited) | reused | tenure rotation, 成團, resolution transitions all audit via the pre-built services |
| BI-9 (SoD) | not in scope | no manual-payment/refund path in this card |
| Scope elevation discipline | **yes** | one new `asSystem` (STEP 1), allowlisted + reason-matched; `ScopeElevationTest` green; STEPs 2–4 resolve within caller RLS (no elevation) |

## 8. Hand-offs forward
- **S-UX3-3b** — student formation view; **B2 (member-readable roster) belongs there**.
- **S-UX3-5** — team-project finance UI; the **`users_read` co-member widening is HELD** for its heavy
  child-safety RLS think-first — do not advance casually.
- **S-UX4** — seed `teacher`/`school_admin` accounts (the OD-39 lobby-admin approver + gate-approval demo).
- Per the remaining-build plan, the next build is **BAND 2 · S-MARKETPLACE-A**, beginning with its focused
  **marketing-section think-first sub-pass** (presentation data = full trilingual marketing section, part
  of publish-completeness).
