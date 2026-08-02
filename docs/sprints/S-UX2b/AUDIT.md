# AUDIT KAP-S-UX2b — API display-name additions

**Result:** PASS · **Date:** 2026-08-02 · **HEAD at commit:** `6370a33` · **Card:** `SPRINT.md`

> Written at the card's end. Honesty outranks looking good. First card of the UX phase (ruled
> sequence S-UX2b → S-UX1 → S-UX2a → S-UX3 → S-UX4). Backend-only; supplies the display layer's
> names so S-UX2a's frontend can render labels instead of raw FK IDs. Origin: `docs/product/UI-INVENTORY.md` §3a.

## 1. What S-UX2b is

Additive display fields on the list/read endpoints that returned bare foreign-key IDs — names joined
in, mirroring the report endpoints' existing pattern (`EnrolmentPoolReportController`). **No
migration, no UI, no schema change.** Every change is a widened `SELECT` with LEFT JOINs.

## 2. Files changed (6 across one step)

| File | Change |
|------|--------|
| `EnrolmentController@index` | LEFT JOIN programmes + users×2 → `programme_name_en/tc/sc`, `student_name`, `acting_guardian` |
| `ConsentRequestController@index` | LEFT JOIN programmes + users×2 → programme triple, `student_name`, `signer_name` |
| `ConsentRequestController@signatures` | LEFT JOIN users → `signer_name` |
| `ConsentRequestController@documents` | LEFT JOIN users → `signer_name` |
| `ConsentEvidenceReportController` (`$byStatus`) | LEFT JOIN programmes + users×2 → programme triple, `student_name`, `signer_name` (all four status buckets) |
| `AuditEventController@index` | LEFT JOIN users → `actor_name`; filters qualified to `audit_events.*`; `entity_id` left raw (deferred) |
| `FinanceReportController@show` (transactions) | LEFT JOIN users×2 → `recorded_by_name`, `verified_by_name` |
| `tests/Feature/DisplayNamesTest.php` (new) | T1/T2/T3 — 8 tests, 188 assertions |

**Nine display joins, all LEFT.** No INNER join added anywhere. Zero touches to pay/payment-link
surfaces (`grep` on the diff: 0).

## 3. The three constraints — held

1. **Additive only.** T2 asserts, per endpoint, that every pre-existing key remains with unchanged
   name/type; the new keys are added, never renamed or removed. A current client keeps working.
2. **RLS / PII — double-gated (stronger than carded).** Names ride rows the parent table's RLS
   already returns *and* each name is independently gated by the **joined table's own RLS**
   (`users_read` for people, `programmes` for programme names). A name resolves **iff the caller could
   already SELECT that row**, else NULL. So the change exposes **no new row and no new name**.
3. **Language-scoped programme names.** Returned as the `name_en/tc/sc` triple for the frontend to
   localize — never a single pre-picked language, never the code alone.

## 4. Step verification (real output, pasted)

**T1 — isolation GREEN before names (probe-first, strongest form).** The 6 edits were `git stash`ed
(controllers reverted to bare-ID selects); the probe ran against the un-named controllers:
```
$ git stash push -- api/app/Http/Controllers        # names removed
$ phpunit --filter 'test_t1_cross_family_isolation_counts_only'
 ✔ T1 cross family isolation counts only
OK (1 test, 21 assertions)                            # guardian A: 1+1; stranger: 0; admin: 2, no fan-out
$ git stash pop                                       # names restored
```
**T2 + T3 — full class GREEN:**
```
$ phpunit --testdox tests/Feature/DisplayNamesTest.php
 ✔ T1 · ✔ T2 enrolments · ✔ T2 consent requests · ✔ T2 consent signatures/documents
 ✔ T2 consent evidence report · ✔ T2 audit events · ✔ T2 finance report · ✔ T3 left join keeps row
OK (8 tests, 188 assertions)
```
**T4 — battery + OD-44 guardrail:**
```
$ php artisan reconcile:run
  PASS  payment_links.no_pii   [OD-44] …
RECONCILE PASS — 58 assertion(s), 58 passed, 0 failed
```
**T5 — full suite ex-clamd:**
```
$ phpunit --filter '/^(?!.*ClamAv).*/'
OK (433 tests, 5553 assertions)     # 425 prior + 8 new
```
**T6 — live read (running instance, container runs the edited code):** `GET /api/enrolments` returns
IDs **and** `programme_name_*` + `student_name` + `acting_guardian`; `GET /api/audit-events` returns
`actor_name` (null/system → NULL → frontend "System"). Full JSON in the review bundle's VERIFY-OUTPUT.md.

## 5. Design notes (findings recorded per Leo's review)

### Finding 1 — the LEFT JOIN is audit-integrity-critical, not merely null-safety
Live `/audit-events` surfaced actors with a **non-null `actor_id` but no name** — **dangling FKs**:
the user rows were deleted (S07 live-audit cleanup) but the audit events remain, because **BI-1 makes
`audit_events` append-only/immutable while `users` is not**. Confirmed by direct lookup (ids
49/50/51/52/64 → no such user row; 2/7 → real users). **Consequence:** an INNER join on `actor_id`
would **silently drop those historical events from the audit view** — hiding events from an
append-only log, a governance regression. The LEFT JOIN keeps every event; a since-deleted actor
renders as unknown/"System", never vanishes. This is now a **standing reason** the audit-events name
join must remain LEFT.

### Finding 2 — the `users_read` co-member wall (flagged, not widened)
`finance-report`'s `recorded_by_name` / `verified_by_name` resolve through `users_read`, which admits
**self + ops/audit admins only** — **team co-members are mutually invisible in the `users` table**. So
between co-members these fields are NULL (the LEFT JOIN keeps the row; the name is simply absent). This
was **correctly NOT widened here** — a `users_read` branch for **active team co-membership** is a
child-safety-reviewed RLS change, out of scope for an additive card. **Named flag carried onto the
S-UX3 team-finance card:** the co-member name-visibility ruling is made when that screen is carded.
The field is in place and correct today.

## 6. Deviations from SPRINT.md

| Card said | Actually happened | Why |
|-----------|-------------------|-----|
| PII gated by parent-table RLS | **Double-gated** — also by each joined table's RLS | `users`/`programmes` are themselves RLS-forced; the join inherits their policy. Stronger guarantee, no extra work. |
| VERIFY "with screenshots" (standing UX rule) | **Live `curl` JSON** instead | S-UX2b ships no visible UI; its observable surface is JSON. Screenshot discipline begins at S-UX1. |
| `#7 finance-report` "cleanest RLS story: member-only names" | Names resolve for actor-as-caller / admins; **NULL between co-members** | The `users_read` wall (Finding 2). Field shipped additively; full co-member display deferred to S-UX3 with a ruling. |

## 7. Exit gate

```
$ php artisan reconcile:run          # full battery incl payment_links.no_pii
RECONCILE PASS — 58 assertion(s), 58 passed, 0 failed

$ phpunit --testdox tests/Feature/DisplayNamesTest.php
OK (8 tests, 188 assertions)

$ phpunit --filter '/^(?!.*ClamAv).*/'   # full suite ex-clamd
OK (433 tests, 5553 assertions)
```
ClamAv **integration** suite excluded — the S10 env item ([[clamd-oom-foreign-supabase]]); no S-UX2b
case needs the live daemon.

**Verdict:** PASS. Nine LEFT display joins, additive and double-RLS-gated; isolation proven green
before names; battery 58/58 with the OD-44 exclusion intact; full suite 433/5553. No migration, no UI,
no schema change.

## 8. Invariant check

| BI / control | Held? | Evidence |
|--------------|-------|----------|
| **OD-44 / `payment_links.no_pii`** | Yes — the card's guardrail | 58/58 incl this assertion; 0 pay-surface edits in the diff. |
| **BI-1 (audit append-only)** | Untouched, and reinforced | No write path added; the LEFT join preserves every historical event in the view (Finding 1). |
| **RLS scope / confinement** | Yes — strengthened | Names double-gated by `users_read`/`programmes`; cross-family probe green (T1); T3 proves a hidden user → NULL name, row survives. |
| **No migration on ran DBs** | Yes | Pure read-shape change; no migration file. |
