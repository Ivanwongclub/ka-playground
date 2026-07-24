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

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | **CLIENT QUESTION (from the card): are programme fees or refund terms ever negotiated per school?** Phase 1 assumes NO (uniform per programme). Gates the S04A consumer fee-read clause; fee_items/withdrawal tables ship scoped (steps 2–3) under the tie-breaker | **High — client** | before S04A |
| 2 | Wizard section payloads are validated minimally (status + shape); deep per-section validation tightens as the real config tables land in steps 2–3 | Low | S02B steps 2–3 |
