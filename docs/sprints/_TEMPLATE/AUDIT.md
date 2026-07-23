# AUDIT KAP-<sprint> — <TITLE>

**Result:** PASS / FAIL · **Date:** <YYYY-MM-DD> · **HEAD at gate:** `<sha>`

> Written by Claude Code at the sprint's end. Honesty outranks looking good — a documented FAIL is
> worth more than an untrue PASS. This is the BUILD audit; the in-product audit element is separate.

## 1. Files changed
| Path | A/M/D | Why |
|------|-------|-----|

## 2. Step-by-step verification (real output, pasted)
### STEP 1 — <title> · commit `<sha>`
```
<actual command + actual output>
```
Result: PASS / FAIL

## 3. Assertions registered this sprint
| Assertion | Tag | First green run output pasted? |
|-----------|-----|-------------------------------|

## 4. Deviations from SPRINT.md
| Card said | Actually happened | Why |
|-----------|-------------------|-----|
(If none: write "None.")

## 5. Leftovers & newly discovered risks  ← input to the next card's adjustment
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|

## 6. Exit gate
```
<gate commands + actual output, incl. php artisan reconcile:run --tag=<sprint>>
```
**Verdict:** PASS / FAIL. If FAIL: what blocks, smallest next step.

## 7. Invariant check
| BI | Touched? | Evidence (test/assertion name) |
|----|----------|-------------------------------|
