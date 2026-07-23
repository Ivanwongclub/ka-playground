# AUDIT KAP-S01 — Identity, auth & invitations

**Result:** PASS (one verification pending Leo's push — §4/D3) · **Date:** 2026-07-24 · **HEAD at gate:** gate commit; last content commit `2e6eeb8`

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
- **STEP 1** `a3df5a5` — full matrix printed (18 permissions × 6 roles ‖ 5 capability groups; 31 + 32 rows); live: 4× Member 403 (`students/consents/enrolments/payments`, each naming the missing permission); grant + revoke `{"status":"ok"} [200]` with audit rows (grantor 3 → grantee 5); escalation refusal `[403]` + `capability.grant_refused` row; `actor_role` populated (`academy_admin` on every capability row). Migration ordering bug (users FK before roles seed) caught by real data on compose, fixed seed-before-alter.
- **STEP 2** `7e3686e` — invitation issue 201 (single-use sha256-hashed token, 14 d); accept → user unverified + `invitation_accepted`; signed verify link → `email_verified`; second accept 422; expired 422; **Member invitation 422 citing OD-22**; student invitation 422 (guardian-led); guardian creates student → active `guardian_link` + audit. 43 tests.
- **STEP 3** `89352b9` — live: unverified login → 403 "Email not verified…"; 5×422 wrong password → attempt 6 with CORRECT password → **423 locked**; audit ladder pasted (failures 1–5, `lockout`, locked-until refusal). Reset 1 h single-use invalidates sessions; idle 12 h vs remember 30 d proven by token aging. Login page screenshot: AA logo, duotone auth-assets hero, quote, no sign-up affordance.
- **STEP 4** `cb609ee` — live: 5×422 then **429** from one IP; API 60/min tested (61st → 429); pairing limiter registered 5/hour. Found: Laravel 12's slim `api` group ships WITHOUT `throttle:api` — enabled explicitly.
- **STEP 5** `257e2f4` — live: generate `4SDVDc` → redeem → pending_confirmation → student confirms → active, audit trail pasted; tests: max-5 codes, case sensitivity, consume-on-use, **10 global fails (ten different accounts) → hard-invalidated → 11th attempt refused "invalidated"**; continuity fixture: guardian revoke of sole link → 403 + refusal audited; ops-admin without reason 422; with reason → revoked + 14-day exception row + `guardian_replacement.opened`.
- **STEP 6** `cec9fd4` — live three-way on `/api/audit-events`: no session **[401]** · session without audit_read **[403]** · with audit_read **[200]**.
- **STEP 7** `f4d7476` — entry chunk 873 → **104.4 kB gz** (charts ride the 437 kB style-guide chunk); every chunk ≪ 1 MB budget; lazy routes render, 0 console errors.
- **STEP 8** `fc6159d` — CI clamav step with actions/cache'd signature DB + KAP test signature; API test step fails if the clamd tests skip. Cannot execute CI myself (D3).
- **Audit element** `2e6eeb8` — Access & Identity Report behind audit_read; funnel from REAL seeded flow (issued 2 → accepted 1 → verified 1); screenshot shows funnel, lockout ladder, links, sole-guardian list, capability log incl. the refusal.

## 3. Assertions registered this sprint (--tag=S01)
| Assertion | First green run pasted? |
|-----------|------------------------|
| `authz.permission_matrix` (OD-1 · OD-17 · B7) | Yes — §2 STEP 5 live run + gate |
| `links.guardian_coverage` (B8 · 2.2) | Yes — vacuous-by-construction until S04A (card wording); self-activates when `enrolments` exists |

## 4. Deviations from SPRINT.md
| # | Card said | Actually happened | Why | Status |
|---|-----------|-------------------|-----|--------|
| D1 | "hard invalidation after 10 global failed attempts" (2.13) | Implemented as per-code-string attribution: failures that reference the code string count (any account = "global"); misses matching nothing are throttle-only | The card's own verification ("11th global failed attempt → code hard-invalidated") requires per-code attribution, which forces this reading. Flagged for Leo's review rather than stopped: the interpretation is the strictest implementable one | **Review requested** |
| D2 | Guardian-led student creation (L4) | Student accounts are created email-verified (guardian-led act vouches them, B10 rule 2: students don't verify their own email) | Young students may have no usable mailbox; the guardian is authenticated and verified | Review requested |
| D3 | "CI run shows the four real-clamd tests executing" | Workflow written + YAML-parsed; **first executed run requires Leo's push** — I never push (CLAUDE.md §2.1) | Verification impossible without push rights | **OPEN — verify on first push** |
| D4 | Invitation delivery email | Plain token returned once to the issuing admin (201 body); email delivery rides the notification engine (S09). Verification emails DO send (log mailer locally) | K-engine is S09 scope; no silent scaffold of it | Recorded |

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | D3: confirm the four real-clamd tests execute on the first CI run after push | Medium | Leo, next push |
| 2 | D1/D2 interpretations need Leo's yes/no | Medium | Review of this AUDIT |
| 3 | Frontend has no route guard beyond 401-bounce (a logged-out user sees empty shells on non-admin pages until an API call 401s) — acceptable while pages are placeholders; revisit when S02 ships real content | Low | S02 |
| 4 | `POST /admin/invitations` requires `operations.manage`; per-school invitation rights (School Admin inviting teachers, B1) arrive with their surfaces | Low | S02+ |
| 5 | Guardian replacement exception has no scheduled 14-day suspension job yet — the deadline is recorded; the enforcement job belongs with enrolment suspension (2.2 step 3, needs enrolments) | Medium | S04A |
| 6 | `schools` table is seeded empty; school link flows tested with synthetic rows. School CRUD arrives with programme/school admin surfaces | Low | S02 |

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
