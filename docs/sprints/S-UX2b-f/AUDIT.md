# AUDIT KAP-S-UX2b-f — Access-identity report display names

**Result:** PASS · **Date:** 2026-08-02 · **HEAD at commit:** `823b229` · **Card:** `SPRINT.md`

> Micro-card follow-up to S-UX2a's flagged gap (ruling a). Same additive LEFT-join pattern + proofs as
> S-UX2b. Backend + a thin frontend consume. Built before S-UX3 so no admin page is left names-broken.

## 1. What S-UX2b-f is

`/reports/access-identity` returned raw `actor_id`/`student_id` on three surfaces (S-UX2a could not
resolve them frontend-only because this report was outside S-UX2b's scope). This card adds the display
names additively and adopts them in `AccessIdentity.tsx`.

## 2. Files changed (3)

| File | Change |
|------|--------|
| `AccessIdentityReportController` | 3 LEFT-joins → `auth_events.actor_name`, `capability_log.actor_name`, `replacement_exceptions.student_name` |
| `AccessIdentity.tsx` | consumes the names (null → "System" for actors, "—" for student); S-UX2a raw-id FLAG removed |
| `DisplayNamesTest.php` | +1 test (`access identity report carries actor and student names`) |

**All three new joins are LEFT.** The pre-existing INNER joins (funnel `verified`, `links_per_student`,
`sole_guardian`, `bulk_created_by_school`) are untouched — they join reference rows that always exist
and are gated to admins.

## 3. Why LEFT is load-bearing (not cosmetic)

`auth_events`/`capability_log` come from `audit_events`, which is append-only/immutable (BI-1) while
`users` is not — and actors can be the system actor or null. An INNER join on `actor_id` would **drop
historical audit rows** whenever the actor was later deleted, hiding events from the audit surface. The
LEFT join keeps every row; a since-deleted/system actor renders as "System"/"—", never vanishes. Same
lesson as S-UX2b Finding 1.

## 4. Verification (real output)

```
$ phpunit --testdox tests/Feature/DisplayNamesTest.php
 ✔ Access identity report carries actor and student names            (+ the 8 prior cases)
OK (9 tests, 216 assertions)

$ php artisan reconcile:run
RECONCILE PASS — 58 assertion(s), 58 passed, 0 failed

$ phpunit --filter '/^(?!.*ClamAv).*/'
OK (434 tests, 5581 assertions)

$ cd web && npm run build
i18n:check PASSED — parity complete, no hardcoded user-facing strings · tsc -b · vite · bundle-budget PASSED
```

**Both proofs present** (S-UX2b discipline):
- **Additive** — every pre-existing key of `auth_events` (`occurred_at`/`action`/`actor_id`/
  `actor_role`/`reason`) intact; `actor_name`/`student_name` added and correctly valued ("Log Actor",
  "Student Alpha").
- **Count preservation** — `auth_events` count == the source `audit_events` (auth-action, cap 50) count:
  the LEFT join neither dropped nor multiplied a row.

**Screenshot** (review bundle): the Actor column shows names ("Ada Super (demo)", "Sam Chan (demo)",
"Aria Audit (demo)"…) where S-UX2a left `2/8/7/6/4`. Gap closed.

## 5. Deviations

None. Scope held to the three flagged columns; no other surface touched; no schema/migration.

## 6. Invariant check

| Control | Held? | Evidence |
|---------|-------|----------|
| BI-1 (audit append-only) | Untouched, reinforced | Read-only join; LEFT preserves every historical row in the view. |
| RLS scope | Yes | Report is `audit_read`-scoped; name join inherits `users_read` (admins resolve all). |
| Additive / non-breaking | Yes | Pre-existing keys intact (test); new keys only. |
| No migration / schema change | Yes | Read-shape additions only. |
