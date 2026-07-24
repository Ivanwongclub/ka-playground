# AUDIT KAP-S03 — Consent engine

**Result:** IN PROGRESS · **Date:** started 2026-07-25 · **HEAD at gate:** `<pending>`

> Opened at STEP 1 per the live-fill pattern.

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | **R15 named S10 check:** no live consent template version may still carry placeholder text at go-live (placeholder is EXPECTED during build — deliberately NOT a nightly assertion, per Leo: permanently-red assertions train people to ignore red) | High | **S10 readiness** |
| 2 | **Timestamp trust (Leo, design-fresh note for S10):** signature evidence rests on a server NTP timestamp — contestable if anyone argues the clock was wrong or set retroactively. Options noted now, impossible to retrofit onto collected signatures: (a) RFC-3161 trusted timestamp authority countersigning each evidence bundle hash at signing time; (b) periodic anchoring of the audit_events/signature hash chain to an external immutable reference (e.g. daily digest published outside the platform). Either can be added FORWARD from the moment adopted; S10 should pick one before real signatures exist | **High — S10 design decision** | S10 |
| 3 | Client question (fees/terms per school) still open — gates S04A consumer read clauses | High — client | before S04A |
