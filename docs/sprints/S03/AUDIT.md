# AUDIT KAP-S03 — Consent engine

**Result:** IN PROGRESS · **Date:** started 2026-07-25 · **HEAD at gate:** `<pending>`

> Opened at STEP 1 per the live-fill pattern.

## 2. Step-by-step verification
### STEP 1 — Templates + language-scoped versions · commit (this step)
```
$ php artisan test → 137 passed (623 assertions)
$ reconcile:run --tag=S02A → PASS 2/2 (consent_templates global justified,
  consent_template_versions SCOPED with policies, in the creating migration)

Placeholder (R15), live: three languages, each published, each flagged, DISTINCT hashes:
 en    | v1 | published | placeholder=t | 4f554a9ceb4c7ad5…
 zh-SC | v1 | published | placeholder=t | 4ec8ba826220b09e…
 zh-TC | v1 | published | placeholder=t | d54b7443b8added3…

OD-20a drift, live: material EN v2 alone →
 error — Consent template language versions have drifted apart: {"en":2,"zh-TC":1,"zh-SC":1}
         — a material change must be applied to ALL THREE languages together
TC+SC brought to v2 → consent findings: NONE — publishable: True

Five-branch on consent_template_versions, live:
 [1] academy staff: 6 rows (all published langs; drafts additionally visible — test-proven 4-vs-3)
 [2] guardian:      6 rows, published only     [3] student: 6      [4] school_admin: 6
 [5] Member:        0 rows
Published immutability at the DB:
 ERROR: published consent template versions are immutable (BI-6/OD-20): UPDATE blocked
No-anchor publish blocked (G3, tested); unselected-template versions hidden from
bound parties (tested — read flows only through a published programme's selection).
```
Result: PASS

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | **R15 named S10 check:** no live consent template version may still carry placeholder text at go-live (placeholder is EXPECTED during build — deliberately NOT a nightly assertion, per Leo: permanently-red assertions train people to ignore red) | High | **S10 readiness** |
| 2 | **Timestamp trust (Leo, design-fresh note for S10):** signature evidence rests on a server NTP timestamp — contestable if anyone argues the clock was wrong or set retroactively. Options noted now, impossible to retrofit onto collected signatures: (a) RFC-3161 trusted timestamp authority countersigning each evidence bundle hash at signing time; (b) periodic anchoring of the audit_events/signature hash chain to an external immutable reference (e.g. daily digest published outside the platform). Either can be added FORWARD from the moment adopted; S10 should pick one before real signatures exist | **High — S10 design decision** | S10 |
| 3 | Client question (fees/terms per school) still open — gates S04A consumer read clauses | High — client | before S04A |
