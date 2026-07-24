# AUDIT KAP-S02A — Access foundation

**Result:** IN PROGRESS · **Date:** started 2026-07-24 · **HEAD at gate:** `<pending>`

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

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | **Bootstrap credential is a standing go-live item**: rotate or remove before production | High | S10 readiness |
| 2 | **STEP 1 one-time password appeared UNREDACTED in local verify output** (redaction pattern missed special chars). Burned, synthetic, local-only DB — but on the record alongside the rotation item. Verify tooling must redact BEFORE display, not after | Medium (hygiene) | Now noted; redact-first in future verifies |
| 3 | `users` and the token-gated tables are classified `global` with recorded justifications (auth bootstrap precedes context). Every sprint adding user-adjacent or student-profile tables must classify them `scoped` — the assertion forces the conversation but the RIGHT classification is a review point each gate | Medium | every gate, explicit at S03 |
| 4 | Local nginx resolves the app container IP at start — recreating `app` needs `docker compose restart nginx` (bit us in verify). Consider a resolver directive in the nginx conf | Low | S02B or whenever nginx conf next opens |
