# AUDIT KAP-S02B — Programme configuration

**Result:** IN PROGRESS · **Date:** started 2026-07-25 · **HEAD at gate:** `<pending>`

> Opened at STEP 1 per the live-fill pattern. Gate verdict last.

## 2. Step-by-step verification
### STEP 1 — Hub-and-spoke wizard · commit (this step)
```
$ php artisan test → 109 passed (449 assertions)
$ php artisan reconcile:run --tag=S02A → RECONCILE PASS — 2/2 (new tables classified in-commit)
Live walk (compose): pre-flight → publishable: False (9 section errors incl.
consent.template_missing path) → nine sections completed [200 ×9] → publish →
{"status":"published"} + version snapshot → locked fees edit → [423]
Audit trail: section_saved ×9 · preflight_ran blocked → publishable ·
programme.published · programme.locked_field_attempt
```
Wizard hub UI screenshot (zh-TC): ten trilingual sections, readiness 9/9, deferred
integration, publish disabled post-publish. Tables wizard_sections + pre_flight_results
classified GLOBAL in the same commit (justifications state the every-authenticated-session
definition). Result: PASS

### STEP 2 — Config tables: first plan-scoped RLS · commit (this step)
```
$ php artisan test → 119 passed (476 assertions)
$ reconcile:run --tag=S02A → PASS 2/2 (six new tables classified in-commit;
  team_categories + fee_items SCOPED with policies in the same migration)

team_categories — the five read branches, LIVE (Leo item 1):
[1] academy staff (configuration):     ['Open Lobby', 'School B Lobby', 'St. Paul Lobby']
[2] school-linked (School A admin):    ['Open Lobby', 'St. Paul Lobby']
[3] guardian via student's school (B): ['Open Lobby', 'School B Lobby']
[4] student (School A links):          ['Open Lobby', 'St. Paul Lobby']
[5] Member:                            []            <- zero, as designed

fee_items — OD-18 at the schema (Leo item 2), live \d:
 amount_minor | bigint       | not null            <- INTEGER MINOR UNITS
 currency     | character(3) | not null | 'HKD'    <- explicit ISO code
 CHECK fee_items_amount_nonneg (amount_minor >= 0)
 CHECK fee_items_currency_phase1 (currency = 'HKD')   <- widening = migration
Float sweep across all migrations: zero float/double/decimal/numeric money
columns (the two grep hits are the word 'alphanumeric' and the OD-18 comment
itself). Float-shaped API input 422s; USD insert rejected by the DB (tested).
fee_items isolation: finance-capability admin sees rows; school_admin holds
finance.view so the ROUTE passes but RLS answers zero commercial rows
(capability present, terms absent — the sharper proof); tested.

OD-13b live: second default lobby ->
ERROR: duplicate key value violates unique constraint "team_categories_one_default"
API surfaces it as 409; exactly one default persists (tested).
OD-12: thresholds edited after creation, audited (tested). OD-21: column-list
test proves certification_rules has no partner/cobrand/signatory/logo columns.
```
**Found by the five-branch requirement:** guardians could not read their own
students' school_links (S02A policy gap) — the lobby subquery silently returned
nothing. Amended by migration (guardian branch added to school_links_read);
correct in its own right. The verification caught a real defect before it
shipped — exactly why live refusals beat existence checks.
withdrawal_policies/bands get identical isolation treatment in STEP 3 (card order).
Result: PASS

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | **CLIENT QUESTION (from the card): are programme fees or refund terms ever negotiated per school?** Phase 1 assumes NO (uniform per programme). Gates the S04A consumer fee-read clause; fee_items/withdrawal tables ship scoped (steps 2–3) under the tie-breaker | **High — client** | before S04A |
| 2 | Wizard section payloads are validated minimally (status + shape); deep per-section validation tightens as the real config tables land in steps 2–3 | Low | S02B steps 2–3 |
