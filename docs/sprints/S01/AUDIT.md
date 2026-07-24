# AUDIT KAP-S01 — Identity, auth & invitations

**Result:** PASS (one verification pending Leo's push — §4/D3) · **Date:** 2026-07-24 · **HEAD at gate:** gate commit; last content commit `2e6eeb8`

> ⚠️ **ANNOTATION (2026-07-24, OD-23 client model change — this AUDIT is NOT rewritten; a passed
> gate records what was true then):** the guardian-creates-student path, the auto-completing
> pairing/email/vouch linking flows, and the D2 born-login-verified ruling verified below were
> correct as built and are SUPERSEDED by OD-23/OD-27/OD-29 + amendment 2.30. Their retirement/
> transformation lands in the proposed S04C/S04D cards; until that step lands the flows remain
> operative against synthetic data only.

> Written by Claude Code at the sprint's end. Honesty outranks looking good. Steps 2–8 ran
> unattended under Leo's standing rules (2026-07-24): per-step VERIFY + commit, STOP conditions
> live, judgement calls recorded below.

## 1. Files changed
| Path | A/M/D | Why |
|------|-------|-----|
| `api/config/permission-matrix.php` | A | Single source of truth: roles × permissions, capabilities × permissions |
| `api/database/migrations/*authz*|*invitations*|*lockout*|*pairing*` | A | Roles/permissions/capabilities · invitations + link entities · lockout columns · pairing + exceptions |
| `api/app/Services/Authz/**` · `Identity/**` | A | PermissionResolver, CapabilityService (audited refusals), Invitation/Auth/Pairing/GuardianStudent/LinkRevocation services, EnrolmentStatusPort |
| `api/app/Http/**` | A/M | EnsurePermission/EnsureRole middleware; Auth, Onboarding, Capability, Link, AccessIdentityReport controllers; audit-events secured |
| `api/app/Services/Reconciliation/Assertions/*` | A | PermissionMatrixProbe · GuardianLinkCoverageAssertion (both --tag=S01) |
| `web/src/pages/{Login,AccessIdentity}.tsx` · `auth/session.ts` | A | Split-screen login (manifest §4), report page, bearer session + authFetch |
| `web/src/main.tsx` (lazy) · locales ×3 | M | Route-level code-splitting; login/audit/report keys, parity 3× |
| `.github/workflows/ci.yml` | M | ClamAV step with cached signature DB; skip = CI failure |

## 2. Step-by-step verification (real output pasted per step; commits listed)

### STEP 1 — roles, matrix, capabilities · `a3df5a5` (+ post-gate defect fix, §4a)
Full seeded matrix, live from the platform DB (grid re-verified after the consent.sign fix;
capability rows 32 → 31):
```
PERMISSION               | student   guardian  teacher   school_admin academy_admin member  || super_admin configuration finance operations audit_read
======================================================================================================================================================
audit.read               |    ·         ·         ·         ·            ·            ·     ||     ✓          ·           ·        ·          ✓
capabilities.grant       |    ·         ·         ·         ·            ·            ·     ||     ✓          ·           ·        ·          ·
configuration.manage     |    ·         ·         ·         ·            ·            ·     ||     ✓          ✓          ·        ·          ·
consent.sign             |    ·         ✓        ·         ·            ·            ·     ||     ·          ·           ·        ·          ·
consent.view             |    ✓        ✓        ·         ✓           ·            ·     ||     ✓          ·           ·        ✓         ·
member_directory.view    |    ·         ·         ·         ·            ✓           ✓    ||     ✓          ·           ·        ·          ·   (renamed 24 Jul, §4a)
enrolment.create         |    ·         ✓        ·         ✓           ·            ·     ||     ✓          ·           ·        ✓         ·
enrolment.view           |    ✓        ✓        ✓        ✓           ·            ·     ||     ✓          ·           ·        ✓         ·
events.rsvp              |    ✓        ·         ·         ·            ·            ✓    ||     ✓          ·           ·        ·          ·
events.view              |    ✓        ✓        ✓        ✓           ✓           ✓    ||     ✓          ·           ·        ✓         ·
finance.confirm          |    ·         ·         ·         ·            ·            ·     ||     ✓          ·           ✓       ·          ·
finance.record           |    ·         ·         ·         ·            ·            ·     ||     ✓          ·           ✓       ·          ·
finance.view             |    ·         ✓        ·         ✓           ·            ·     ||     ✓          ·           ✓       ·          ·
operations.manage        |    ·         ·         ·         ·            ·            ·     ||     ✓          ·           ·        ✓         ·
student_records.manage   |    ·         ·         ·         ✓           ·            ·     ||     ✓          ·           ·        ✓         ·
student_records.view     |    ✓        ✓        ✓        ✓           ·            ·     ||     ✓          ·           ·        ✓         ·
teams.approve            |    ·         ·         ✓        ·            ·            ·     ||     ✓          ·           ·        ✓         ·
teams.view               |    ✓        ·         ✓        ✓           ·            ·     ||     ✓          ·           ·        ✓         ·
role rows: 31 · capability rows: 31 (consent.sign: guardian ONLY — §4a)
```
The four Member 403s, live (bearer session, compose nginx):
```
GET /api/students   -> 403 {"message": "Missing permission: student_records.view", ...}
GET /api/consents   -> 403 {"message": "Missing permission: consent.view", ...}
GET /api/enrolments -> 403 {"message": "Missing permission: enrolment.view", ...}
GET /api/payments   -> 403 {"message": "Missing permission: finance.view", ...}
```
Grant, revoke, escalation refusal, live:
```
POST /api/admin/capabilities/grant  (super_admin -> grantee, finance)   {"status":"ok"} [200]
POST /api/admin/capabilities/revoke (super_admin -> grantee, finance)   {"status":"ok"} [200]
POST /api/admin/capabilities/grant  (finance-holder -> operations)      [403]
          action          | actor_id |  actor_role   | grantee | to_state | reason                                    | grantor
 capability.granted       |        3 | academy_admin | 5       | finance  |                                           | 3
 capability.revoked       |        3 | academy_admin | 5       |          |                                           | 3
 capability.granted       |        3 | academy_admin | 4       | finance  |                                           | 3
 capability.grant_refused |        4 | academy_admin | 5       |          | actor lacks capabilities.grant (super_a…) |
```
Migration ordering bug (users FK added before roles seed) caught by real compose data, fixed
seed-before-alter within the step. Result: PASS
### Remaining steps (full live outputs)
- **STEP 2** `7e3686e` — invitation issue 201 (single-use sha256-hashed token, 14 d); accept → user unverified + `invitation_accepted`; signed verify link → `email_verified`; second accept 422; expired 422; **Member invitation 422 citing OD-22**; student invitation 422 (guardian-led); guardian creates student → active `guardian_link` + audit. 43 tests.
- **STEP 3** `89352b9` — live outputs:
```
POST /api/auth/login (unverified)  -> 403 {"message": "Email not verified. Verify your email before signing in."}
attempts 1-5 (wrong password)      -> [422] [422] [422] [422] [422]
attempt 6 (CORRECT password)       -> [423] {"message": "Account locked. Try again later or contact an administrator."}
    action    | actor_role |                     reason
 failed_login | guardian   | email not verified — first login refused (2.11)
 failed_login | guardian   | invalid password (failure 1 of 5)
 failed_login | guardian   | invalid password (failure 2 of 5)
 failed_login | guardian   | invalid password (failure 3 of 5)
 failed_login | guardian   | invalid password (failure 4 of 5)
 failed_login | guardian   | invalid password (failure 5 of 5)
 lockout      | guardian   | 5 consecutive failed logins — locked 15 minutes
 failed_login | guardian   | account locked until 2026-07-23T17:32:35+00:00
```
Reset 1 h single-use invalidates sessions; 12 h idle vs 30 d remember proven by token aging
(tests). Login page screenshot: AA logo, duotone auth-assets hero, quote, no sign-up affordance.
- **STEP 4** `cb609ee` — live: 5×422 then **429** from one IP; API 60/min tested (61st → 429); pairing limiter registered 5/hour. Found: Laravel 12's slim `api` group ships WITHOUT `throttle:api` — enabled explicitly.
- **STEP 5** `257e2f4` — live:
```
POST /api/my/pairing-codes (student)          -> code issued: 4SDVDc
POST /api/pairing-codes/redeem (guardian)     -> {"link_id":"019f9002-…","status":"pending_confirmation"}
POST /api/my/guardian-requests/{id}/confirm   -> {"status":"active"}
         action          | actor_role
 pairing_code.generated  | student
 guardian_link.requested | guardian
 guardian_link.confirmed | student
```
Tests: max-5 codes, case sensitivity, consume-on-use, **10 global fails (ten different accounts) → hard-invalidated → 11th attempt refused "invalidated"**; continuity fixture: guardian revoke of sole link → 403 + refusal audited; ops-admin without reason 422; with reason → revoked + 14-day exception row + `guardian_replacement.opened`.
- **STEP 6** `cec9fd4` — live three-way on `/api/audit-events`:
```
no session:              [401]
session, no audit_read:  [403]
session + audit_read:    [200]
```
- **STEP 7** `f4d7476` — entry chunk 873 → **104.4 kB gz** (charts ride the 437 kB style-guide chunk); every chunk ≪ 1 MB budget; lazy routes render, 0 console errors.
- **STEP 8** `fc6159d` — CI clamav step with actions/cache'd signature DB + KAP test signature; API test step fails if the clamd tests skip. Cannot execute CI myself (D3).
- **Audit element** `2e6eeb8` — Access & Identity Report behind audit_read; funnel from REAL seeded flow (issued 2 → accepted 1 → verified 1); screenshot shows funnel, lockout ladder, links, sole-guardian list, capability log incl. the refusal.

## 3. Assertions registered this sprint (--tag=S01)
| Assertion | First green run pasted? |
|-----------|------------------------|
| `authz.permission_matrix` (OD-1 · OD-17 · B7) | Yes — §2 STEP 5 live run + gate |
| `links.guardian_coverage` (B8 · 2.2) | Yes — vacuous-by-construction until S04A (card wording); self-activates when `enrolments` exists |
| `authz.consent_sign_exclusive` (FR036 · BI-6 · ETO Cap. 553) | Yes — §4a; proven to catch an unfixed DB before the corrective migration ran |
| `authz.member_directory_exclusive` (FR056 · FR058 · OD-1) | Yes — holder set exactly {member, academy_admin} + {super_admin}; widening by a later sprint = nightly alarm |

## 4. Deviations from SPRINT.md
| # | Card said | Actually happened | Why | Status |
|---|-----------|-------------------|-----|--------|
| D1 | "hard invalidation after 10 global failed attempts" (2.13) | Implemented as per-code-string attribution: failures that reference the code string count (any account = "global"); misses matching nothing are throttle-only | The card's own verification ("11th global failed attempt → code hard-invalidated") requires per-code attribution, which forces this reading. Flagged for Leo's review rather than stopped: the interpretation is the strictest implementable one | **Review requested** |
| D2 | Guardian-led student creation (L4) | Student accounts are created email-verified (guardian-led act vouches them, B10 rule 2: students don't verify their own email) | Young students may have no usable mailbox; the guardian is authenticated and verified | Review requested |
| D3 | "CI run shows the four real-clamd tests executing" | Workflow written + YAML-parsed; **first executed run requires Leo's push** — I never push (CLAUDE.md §2.1) | Verification impossible without push rights | **OPEN — verify on first push** |
| D4 | Invitation delivery email | Plain token returned once to the issuing admin (201 body); email delivery rides the notification engine (S09). Verification emails DO send (log mailer locally) | K-engine is S09 scope; no silent scaffold of it | Recorded |

## 4a. Post-gate review (Leo, 24 Jul) — two defects, two questions
**DEFECT 1 — `consent.sign` was capability-reachable (super_admin '*').** Fixed: `capability_forbidden`
list in the matrix source (no capability may ever carry consent.sign); expansion subtracts it in the
seeding migration AND the matrix probe; corrective migration removes the row from already-seeded DBs
(its `down()` is deliberately irreversible); `authz.consent_sign_exclusive` assertion registered —
reintroduction is a nightly alarm. `permission.denied` audit events added to the guard middleware.
Live: super_admin sign attempt → `[403] Missing permission: consent.sign` + audited denial; holders
now `roles=[guardian]`, `capabilities=[]`. The new assertion FAILED against the un-migrated local DB
before the corrective migration ran — first live catch.
**DEFECT 2 — `directory.view` exposure.** It returns NOTHING today: no endpoint serves it anywhere
(grep-verified; it is a seeded permission key only). Its doctrine is now written into the matrix
source: MEMBER directory only (first-generation adults, FR058/OD-1, surfaces S06); students never
appear; any future student/peer directory requires its OWN permission, default-off per FR056.
**Rename APPROVED and applied (Leo, 24 Jul): `directory.view` → `member_directory.view`** — matrix
source, permission key, and an FK-safe corrective migration (insert new → repoint children → delete
old; no-op on freshly-seeded DBs; deliberately irreversible). Live: roles [academy_admin, member],
capabilities [super_admin], zero old-key rows. Guarded by `authz.member_directory_exclusive`.
**Q1 — first super_admin:** NO seeder mints it. Local/dev instances were created ad hoc via tinker
(synthetic). There is currently no production bootstrap path — see §5 item 7.
**Q2 — FR006 scope layer:** does NOT exist yet. The matrix is global; school_admin's
`student_records.manage` is platform-wide today (no data is reachable — endpoints are 501 stubs).
No test can prove school isolation because there is nothing to scope. Raised as §5 item 8.
**D1 follow-ups (approved reading):** (a) an unauthenticated attacker CANNOT touch the failure
counter — 401 precedes it (tested: counter untouched after unauthenticated attempts); reaching it
requires an academy-issued guardian account (invitation-only), and failing against an ACTIVE code
additionally requires being already-linked to that student — an attempt by a stranger who knows the
code SUCCEEDS into pending-confirmation (burning the code) and is student-rejectable + audited. No
unauthenticated targeted-DoS path exists. (b) Recovery: invalidated codes do not count toward the
max-5, so the student regenerates immediately (tested); the parent-initiated email flow and
school-mediated vouching remain independent routes. No support ticket needed; no S09 leftover.
**D2 (narrowed):** guardian-created students stay born login-verified; **SR019** added to the
register — no delivery to a student address that is not independently verified; gates S09.

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | D3: confirm the four real-clamd tests execute on the first CI run after push | Medium | Leo, next push |
| 2 | D1/D2 interpretations need Leo's yes/no | Medium | Review of this AUDIT |
| 3 | Frontend has no route guard beyond 401-bounce (a logged-out user sees empty shells on non-admin pages until an API call 401s) — acceptable while pages are placeholders; revisit when S02 ships real content | Low | S02 |
| 4 | `POST /admin/invitations` requires `operations.manage`; per-school invitation rights (School Admin inviting teachers, B1) arrive with their surfaces | Low | S02+ |
| 5 | Guardian replacement exception has no scheduled 14-day suspension job yet — the deadline is recorded; the enforcement job belongs with enrolment suspension (2.2 step 3, needs enrolments) | Medium | S04A |
| 6 | `schools` table is seeded empty; school link flows tested with synthetic rows. School CRUD arrives with programme/school admin surfaces | Low | S02 |
| 7 | **No first-super_admin bootstrap exists** (Q1). Proposal: an `artisan bootstrap:super-admin` command (refuses if any super_admin exists, audited). Once created in any real environment it is a **standing credential — rotate or remove before go-live (S10 readiness item)** | **High** | S02 (build) · S10 (rotation check) |
| 8 | **FR006 scope layer absent** (Q2): permissions are global; per-link overrides column exists but no scoping query layer or school-isolation test. **Must be a STEP in the S02 card, not a note** (Leo, 24 Jul): it lands WITH the first real data surfaces or it never lands at all — a school_admin must never reach another school's students | **High** | **S02 (card step)** |
| 9 | SR019 (student delivery gating) recorded in the register — S09's pipeline must enforce delivery-verification at the send layer | Medium | S09 |

## 6. Exit gate
```
$ php artisan test                       →  70 passed (288 assertions)
$ php artisan reconcile:run --tag=S01    →  RECONCILE PASS — 2 assertion(s), 2 passed, 0 failed (exit 0)
$ php artisan reconcile:run              →  4 assertions green (S00 + S01)   [test suite asserts this too]
$ php artisan migrate --pretend          →  Nothing to migrate (all 4 S01 migrations Ran, reversible)
$ npm run build                          →  i18n:check PASSED (3×) · bundle-budget PASSED
                                            entry 105.2 kB gz · largest chunk 427.8 kB gz (style guide)
$ docker compose config -q               →  OK
Audit element renders the funnel from real seed events    →  §2 screenshot (2 → 1 → 1)
Three-way auth check pasted                               →  §2 STEP 6 (401 / 403 / 200)
```
**Verdict:** PASS, with D3 (CI execution) open until Leo's first push — same caveat class as S00's §6.

## 7. Invariant check
| BI | Touched? | Evidence |
|----|----------|----------|
| BI-8 | Built out | Every auth event (2.11 set + lockout_cleared), every capability outcome INCLUDING refusals, every link transition audited with actor identity; `actor_role` now populated (closes S00 §5 item 7) |
| BI-1 | Untouched (guarded) | S00 assertions still green in the full run |
| BI-2..7, BI-9, BI-10 | Not this sprint | BI-10 hardening from S00 unchanged; upload tests green |
