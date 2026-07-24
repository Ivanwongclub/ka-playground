# AUDIT KAP-S02A — Access foundation

**Result:** IN PROGRESS · **Date:** started 2026-07-24 · **HEAD at gate:** `<pending>`

> Opened early to record STEP 1 findings while fresh (S00/S01 pattern). Filled per step;
> gate verdict last.

## 2. Step-by-step verification
### STEP 1 — bootstrap:super-admin · `4e81387`
Live: REFUSED (pre-existing synthetic grant, exit 1) → created after synthetic retirement
(user id 14, audit rows `bootstrap.super_admin` + `capability.granted{bootstrap:true}`) →
REFUSED again (exit 1). 79 tests passing. Result: PASS

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | **Bootstrap credential is a standing go-live item**: rotate or remove before production | High | S10 readiness |
| 2 | **STEP 1 one-time password appeared UNREDACTED in local verify output** (redaction pattern missed special chars). Burned, synthetic, local-only DB — but on the record alongside the rotation item. Verify tooling must redact BEFORE display, not after | Medium (hygiene) | Now noted; redact-first in future verifies |
