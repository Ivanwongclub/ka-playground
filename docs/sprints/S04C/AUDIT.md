# AUDIT KAP-S04C — Self-registration, approval, orphan pairs, the queue & retirement

**Result:** PASS (pending Leo's gate clearance) · **Date:** 2026-07-30 · **HEAD before gate:** `6ad88c0` (S04C-4)

> Build audit for Leo. Honesty outranks looking good. The in-product audit element (Access & Identity
> report extensions) is separate and shipped. The gate commit is HELD for joint review.

## 1. Steps
| Step | Commit | What |
|------|--------|------|
| plan | `53477be` | reconciled card to shipped state + think-first review (D-i…D-iv) |
| S04C-1 | `8371194` | anonymous self-registration + public payment page — the `public` context, one confined `WITH CHECK` INSERT (D-iii) |
| S04C-2 | `0f22368` | approval → UNVERIFIED account + OD-29 activation (model B: verify-and-set-password, CAS burn) |
| S04C-3 | `c3f26d7` | orphan pairs + held links + FLAG #2 activation audit (structural, path-independent) |
| S04C-4 | `6ad88c0` | the ONE queue + D-i school affiliation + OD-27 retirement |
| gate | HELD | 5 assertions + five-branch + this AUDIT.md + the report element |

## 2. Every sprint ships three things — the S04C check
Module ✅ · in-product **audit element** (Access & Identity report: onboarding funnel, queue age, open
escalations, held-link ledger — `GET /api/reports/access-identity`, `onboarding` block; trilingual UI) ✅ ·
**reconciliation assertions** (5 under `--tag=S04C`) ✅.

## 3. Assertions (`--tag=S04C`, 5) — with red-then-green teeth
| Assertion | Judges | Teeth |
|-----------|--------|-------|
| `scope.public_context_confinement` (S1) | exactly ONE policy platform-wide admits `public`, INSERT-only, status-pinned, RLS-forced | a 2nd table admitting public → red |
| `links.activation_audited` (S3, tagged S04C+S06) | every **active** guardian_link has a `to_state='active'` audit — **path-independent** (WHERE status='active' AND NOT EXISTS the audit) | active link with no audit → red; **caught 4 un-audited seed links on first run** |
| `links.no_unverified_materialisation` (S3) | every **materialised** held link has a VERIFIED counterpart account | materialised vs a ghost address → red |
| `queue.escalation_liveness` (S4) | no pending item older than threshold without an open escalation | stale item, no exception → red; sweep → green |
| `account.provenance` (S4) | every account traces to approval / bootstrap / accepted invitation | an origin-less account → red |

**Live re-run (this session):** `reconcile:run --tag=S04C` → **RECONCILE PASS — 5/5**. Full battery **44/44**.

## 4. Five-branch RLS — the new scoped tables (`RegistrationRlsTest`, 3 tests / 19 assertions)
Verified at the policy layer (these tables have no list endpoint — count under each actor's scope):

- **`registration_requests`:** [1] routed-school admin = 1 (own school-routed) · [2] other school = 0 ·
  [3] academy ops = 2 (incl. the direct one) · [4] guardian/student/Member = 0 · [5] anonymous
  (public + empty) = 0.
- **`held_links`:** [1] routed-school admin = 1 · [2] other school = 0 · [3] ops = 1 · [4] the claimant
  themselves = 0 (reviewer set only) / Member = 0 · [5] anonymous = 0.
- **`school_links` (D-i affiliation):** [1] the student reads their own (→ Active) · [2] routed school = 1 ·
  [3] other school = 0 · [4] ops = 1 · [5] Member = 0 / anonymous = 0.

## 5. The counterpart-email oracle — constant shape (live)
Four enumeration cases, one response shape, byte-identical (the counterpart-email is the subtle oracle):
```
registrant-new:     202 | keys=['reference','status'] status=received
registrant-exists:  202 | keys=['reference','status'] status=received
counterpart-new:    202 | keys=['reference','status'] status=received
counterpart-exists: 202 | keys=['reference','status'] status=received
```
No read policy admits `public` (confinement), so there is no path to probe "does this email exist".

## 6. The typo scenario (STEP 3, live) + FLAG #2 pastes
Typo scenario: guardian claims an address → `held` (0 guardian_links); an unrelated stranger holds that
address unverified → still `held`, no materialisation; the stranger **verifies** → materialises to a
`form_claimed` **pending** link (never a clean pending). Then `approveLink` — the ONE activation path:

```
SCHOOL_LINK   audit -> action=school_link.created    to_state=active actor_role=academy_admin actor_id=3   (student is Active)
GUARDIAN_LINK audit -> action=guardian_link.activated to_state=active actor_role=academy_admin actor_id=3
```
Both write `to_state='active'` with the reviewer's identity (BI-8). `links.activation_audited` proves this
holds for EVERY active link, path-independently.

## 7. OD-27 retirement — total, no orphan (live)
```
POST /api/my/students (unauthenticated) -> 404   (route removed)
```
- `POST /my/students` route removed · `OnboardingController::createStudent` + DI + `use` import removed ·
  `GuardianStudentService.php` **deleted** (file → /dev/null) · allowlist entry removed.
- **No orphan call site:** `ScopeElevationTest` green (every `asSystem` site allowlisted) — nothing
  constructs, injects, or calls the deleted service. `OnboardingTest` migrated to assert the path is gone.

## 8. Elevation review — actual **51** (card predicted 48; reconciled)
The card predicted `49 → 48` for the retirement, counting only the removal. It predated S04C's OWN
additions. Reconciliation: **49 (post-S06 baseline) + 3 (S04C) − 1 (retirement) = 51.**

| # | S04C elevation | Why it must elevate |
|---|---|---|
| +1 (S2) | `RegistrationApprovalService::approve` | the reviewer creates an account for a person outside their scope until it exists (INSERT..RETURNING checks the new row) |
| +2 (S3) | `LinkageService::materialiseFor` | at activation (guest context), a held claim materialises into a pending link — held_links are system-write; the claimant is outside the just-verified account's scope |
| +3 (S3) | `LinkageService::approveLink` | the FLAG #2 activation write must run for a not-yet-affiliated student (outside the reviewer's derived scope); CAS + the un-skippable audit |
| −1 (S4) | `GuardianStudentService::createStudent` **REMOVED** | retired with the guardian-creates-student path (OD-27) |

Each new site audits `scope.elevated`; each is justified by an exact allowlist reason; `ScopeElevationTest`
fails the build on any un-allowlisted site.

## 9. OD / BI trace
- **OD-23** (self-registration + approval; two deliberate decisions) — the whole spine.
- **D-iii / OD-23** — the `public` context: a real DB-confined anonymous writer, not `asSystem` (S1).
- **OD-29** — account born unverified; activation verifies + sets password in one act (model B, Leo 2026-07-30).
- **OD-28 / D-i** — account state is derived; a school-routed student reaches Active via the `school_links`
  affiliation minted at approval (S4).
- **OD-27** — guardian-creates-student retired (S4).
- **FLAG #2** (S06 AUDIT §8) — every link activation audits `to_state='active'`; `links.activation_audited`
  makes it a proof, not a promise. **BI-8** — every status transition audits the actor.
- **BI-1** — the audit spine is INSERT-only; the public write audits `registration.submitted` as
  `actor_role='public'` (attribution never a hole, OD-64 extended). **BI-4** — approve idempotent
  (FOR UPDATE), activation a CAS burn (exactly one wins).

## 10. D7 / D8 / D9 reconciliations
- **D7 (sequencing):** self-registration went live in STEP 1–2; guardian-creates-student retired in STEP 4.
  All work lands on `main` and the sprint tags at the end (OD-8), so there was never a *released* moment
  without a creation path — "never gapless" (OD-27) satisfied by the sprint boundary, not same-step removal.
- **D8:** `registration_requests` is greenfield (v1) — the deleted S06B card never shipped a table; the
  "v2/replaces" language in the draft card was corrected.
- **D9:** `guardian_links` gained `pending_approval` (additive CHECK), distinct from the shipped
  `pending_confirmation` (the old B5 counterpart-confirmation ceremony); the new state waits on an ADMIN.

## 11. Deviation & honesty record
- **`account.provenance` landed at STEP 4, not earlier** — as ruled (it needs invitation + bootstrap
  origins, which all exist by S4). It runs **vacuously** in `ReconciliationRunnerTest` (empty DB) and against
  **conformant** data live; both seeders (`PreviewSeeder`, `DatabaseSeeder`) now write `user.created`
  provenance audits — honest-seed, the same principle as the FLAG #2 seed fix.
- **`links.activation_audited` red-flagged 4 un-audited seed links on day one** — the `PreviewSeeder`
  created active `guardian_links` (Wendy's kids) with no activation audit: exactly the silent gap FLAG #2
  exists to catch, in synthetic form. Seeder fixed; this is the assertion earning its keep.
- **Credential model B** (verify-and-set-password) was Leo's ruling (2026-07-30) over model A (password at
  signup); no secret ever touches the anonymous surface.
- **Elevation count 48→51** — reconciled in §8 (card prediction predated S04C's own machinery).
- **`APP_PUBLIC_URL`** is a REQUIRED production env var (S10) — the forwardable payment-page URL must not
  fall back to localhost (recorded in memory + the S04C-1 commit).
- **clamd caveat:** the full-suite run mid-build showed 4 `ClamAvIntegrationTest` errors from an unhealthy
  clamd daemon (connection-level, zero upload code touched). Per Leo, a clean full-suite run (0 errored)
  with a healthy daemon is confirmed at this gate — see §12.

## 12. Full suite — clean, clamd HEALTHY
`php artisan test` (clamd up, `PING → PONG`): **OK — 362 tests, 4500 assertions, 0 failures, 0 errors.**

Gate item 1 closed cleanly. The 4 `ClamAvIntegrationTest` tests **pass with the daemon healthy** —
confirming the earlier errors were purely environmental (the clamd daemon could not load its
**3.2M-signature** DB into RAM in the 3.8 GB VM while unrelated `supabase_*` containers held ~1.5 GB).
Per Leo's decision (2026-07-30) the stale `supabase_*` containers were stopped (reversible, not part of
KA Playground), clamd loaded with headroom, and the full suite ran 0-errored. STEP 4 touches zero upload
code; this was never a regression — the true clamd-up run proves it.

## 13. What S04C did NOT do (deferred, on the record)
The school-link **state machine** + teacher/school link ceremonies (S04D) · bulk creation (S04D/E) ·
batch enrolment (S04E) · notification **channels** + reminder ladders (S09) · Logto/OIDC (S11). The
minimal `school_links` affiliation (D-i) is here; its richer states are S04D.
