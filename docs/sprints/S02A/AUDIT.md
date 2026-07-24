# AUDIT KAP-S02A — Access foundation

**Result:** PASS · **Date:** 2026-07-24 · **HEAD at gate:** gate commit; steps `4e81387` · `170dde3` · `3aacd3b` · `3499434` + gate-review fixes (this commit)

> Opened early to record STEP 1 findings while fresh (S00/S01 pattern). Filled per step;
> gate verdict last.

## 2. Step-by-step verification
### STEP 1 — bootstrap:super-admin · `4e81387`
Live: REFUSED (pre-existing synthetic grant, exit 1) → created after synthetic retirement
(user id 14, audit rows `bootstrap.super_admin` + `capability.granted{bootstrap:true}`) →
REFUSED again (exit 1). 79 tests passing. Result: PASS

### STEP 2 — Schools + programme entity + versioning · commit (this step)
```
$ php artisan test → 86 passed (342 assertions)
Live: school {"id":1} (trilingual enforced — EN-only 422 in tests)
      programme id 1 (STEM-CAR-2026, jurisdiction HK, status draft, hold 7d)
      snapshots v1, v2 (sequential, programme-row lock serialises numbering)
$ psql UPDATE programme_versions SET version = 99;
ERROR:  programme_versions is INSERT-only (D5 snapshot immutability): UPDATE blocked
```
jurisdiction CHECK-constrained (HK|CN) at API and DB (UK rejected both layers, tested).
Found: Postgres refuses FOR UPDATE with aggregates — numbering serialised via the
programme row lock instead. Result: PASS

### STEP 3 — FR006 scope layer (RLS) · commits `3aacd3b` + (this step)
**Full VERIFY battery (Leo: in full, not summarised):**
```
[1] Admin A lists students (own school only)
    {"data":[{"id":18,"name":"Student Alpha","school_id":2}]}
[2] A fetches School B's student            -> [404]
[3] A fetches own student                   -> [200]
[4] Admin B immediately after (sequence)    -> {"data":[{"id":19,"name":"Student Beta","school_id":3}]}
[5] Ops contrast (platform owner):          -> "Student Alpha", "Student Beta"
[6] Forgery — header X-App-School-Ids: 2, X-App-Context: system, cookie app.school_ids=2,
    query app_school_ids=2, all at once:
    listing -> {"data":[{"id":18,"name":"Student Alpha","school_id":2}]}   (scope UNMOVED)
    forged fetch of Beta -> [404]
    audit: scope.denied | actor 16 | entity 19 | 'student detail requested outside the acting scope (FR006)'
[7] Widening override at write time:
    ERROR:  permission_overrides may only NARROW (key "deny"); "allow" would widen and is rejected (FR006/B7)
[8] Narrowing override resolve-time diff:
    allowsFor(consent.sign, 18)  = false  <- denied by link override
    allowsFor(consent.view, 18)  = true   <- untouched
    allowsFor(finance.confirm)   = false  <- role lacks it; no override could add it
[9] Fail-closed (kap_app, NO context): guardian_links_visible = 0
    with system context:                   with_system_context   = 2
[10] scope.coverage baseline: PASS (all tables classified; scoped RLS-forced; globals justified;
     role kap_app RLS-subject; fail-closed probe 0 rows)
[11] DELIBERATE FAILURE — CREATE TABLE stray_probe_table:
     FAIL scope.coverage: "table 'stray_probe_table' is UNCLASSIFIED in config/scope-map.php"
     RECONCILE FAIL — exit=1        <- the guard has been SEEN to fire
[12] DROP TABLE -> RECONCILE PASS — exit=0
[worker boundary] test_a_job_after_a_request_does_not_inherit_the_requests_context: 1 passed (7 assertions)
    (request as admin A -> job context = system, actor '', school_ids '' -> request as admin B sees only B)
$ php artisan test          -> 98 passed (378 assertions)
$ php artisan reconcile:run -> RECONCILE PASS — 8 assertion(s), 8 passed, 0 failed
```
**Enforcement:** Postgres RLS, ENABLE+FORCE, read/insert/update/delete policies keyed on app.*
session context; runtime is kap_app (non-superuser, NOBYPASSRLS — RLS is inert for superusers, so
compose/phpunit/.env all switched; migrations run as the owner role per the runbook split).
Context derived exclusively from the authenticated user's links; structural lifecycle (middleware
terminate / Queue::before/after/failing / CommandStarting). asSystem() elevation for integrity
checks — RLS itself caught two service bugs during the build (sole-guardian check reading the
actor's view; unaffiliated vouch), both hardened. Immutability probes now accept either DB
rejection layer (privilege revoke 42501 fires before the trigger for kap_app). Result: PASS

### Gate review (Leo) — asSystem constrained · users under RLS
**Item 1 — the sanctioned bypass, constrained:** `asSystem(reason, fn)` now REFUSES any call site
absent from `config/scope-elevations.php` (runtime LogicException), refuses a reason that does not
match the declared one, and writes a `scope.elevated` audit event on every elevation (call site +
reason + restored context). A phpunit scan fails when app/ contains an asSystem call site not in
the allowlist (or a stale entry). Six sanctioned sites, each with its recorded justification:
sole-guardian check (2.2) · parent-initiated lookup (B4) · school-vouch guardian lookup (B4) ·
guardian-led student creation (L4; INSERT..RETURNING checks SELECT policies on the new row) ·
login token issuance (bootstrap act; account switching) · invitation acceptance (2.11 bootstrap).
```
✓ unlisted call site throws · ✓ reason mismatch throws · ✓ sanctioned elevation runs and audits
✓ every asSystem call site in the codebase is allowlisted
live: scope.elevated | LinkController::requestByEmail | 'B4 parent-initiated flow: pre-link…'
```
**Item 2 — users was a data-protection defect; fixed this sprint.** Before: every authenticated
session could read ALL users (names+emails platform-wide) at the DB layer, though HTTP surfaces
were link-bounded. Now `users` and `personal_access_tokens` are `scoped` with RLS: self ·
academy staff (ops/audit/super; academy peers) · guardian→their students · school_admin/teacher→
their school's students, teachers and co-admins · guest/auth-bootstrap phase (empty context) ·
system. Live answer to "what can a school_admin session read from users":
```
context: request / actor 16 / school_admin / school_ids 2
users_visible_to_school_admin = 2   →  (16, Synthetic AdminA)  (18, Student Alpha)
users_total_platform (system) = 20
```
RLS's INSERT..RETURNING semantics surfaced three legitimate bootstrap flows that became
allowlisted elevations rather than policy holes. Result: PASS

## 6. Exit gate
```
$ php artisan test                        →  102 passed (385 assertions)
$ php artisan reconcile:run               →  RECONCILE PASS — 8 assertion(s), 8 passed, 0 failed
$ php artisan reconcile:run --tag=S02A    →  2 assertions green (scope.coverage · version_immutability)
$ php artisan migrate --pretend           →  Nothing to migrate (all S02A migrations Ran)
$ npm run build                           →  i18n:check PASSED · bundle-budget PASSED
$ docker compose config -q                →  OK
$ check-audit-owner.sh (kap_app on kap)   →  OK: app role does not own audit_events
Full STEP 3 battery incl. deliberate assertion failure: §2.
```
**Verdict:** PASS.

## 7. Invariant check
| BI | Touched? | Evidence |
|----|----------|----------|
| BI-1 | Strengthened | Runtime is now the non-owner kap_app everywhere; owner-guard passes locally; probes accept revoke-layer rejection |
| BI-8 | Extended | scope.denied, scope.elevated, permission.denied — every boundary event audited with actor |
| BI-10 | Untouched (guarded) | Upload suite green under RLS (uploads scoped: owner/ops/system) |
| FR006 | Built | This sprint's centrepiece — RLS + context lifecycle + structural coverage assertion |

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | **Bootstrap credential is a standing go-live item**: rotate or remove before production | High | S10 readiness |
| 2 | **STEP 1 one-time password appeared UNREDACTED in local verify output** (redaction pattern missed special chars). Burned, synthetic, local-only DB — but on the record alongside the rotation item. Verify tooling must redact BEFORE display, not after | Medium (hygiene) | Now noted; redact-first in future verifies |
| 3 | ~~users/tokens global~~ **RESOLVED at gate review** — both now `scoped` (see gate-review section). Standing rule unchanged: every sprint classifies its new tables, and the RIGHT classification is a review point each gate | Closed | — |
| 3a | **Auth-bootstrap window (residual):** users/personal_access_tokens policies admit the EMPTY context — required for Sanctum resolution and guest flows, replaced by middleware before controllers on every authenticated request. A context-free direct DB session can read users. Hardening option: move token→user resolution behind SECURITY DEFINER functions and drop the bootstrap clause | Medium | hardening review, S10 window |
| 3b | Six sanctioned asSystem elevations exist (allowlist + audit + CI scan). Review the list at every gate — additions need the same scrutiny as a new `global` classification | Medium | every gate |
| 4 | Local nginx resolves the app container IP at start — recreating `app` needs `docker compose restart nginx` (bit us in verify). Consider a resolver directive in the nginx conf | Low | S02B or whenever nginx conf next opens |
