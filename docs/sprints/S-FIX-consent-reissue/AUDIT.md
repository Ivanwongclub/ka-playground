# AUDIT KAP-S-FIX-consent-reissue — consent re-issuance on guardian activation

**Result:** PASS · **Date:** 2026-08-02 · **HEAD at commit:** `68cf0b9` · **Card:** `SPRINT.md` ·
**Think-first:** `PROPOSED-REVIEW.md`

> Consent + linkage fix, child-safety adjacent — full review. Closes the gap S-UX3-1 surfaced:
> guardian-link activation did not issue consent to the newly-active guardian.

## 1. What it fixes

A guardian activated on a student who already has a pre-team enrolment received **no** consent request
for that enrolment. Consequences (mapped in the think-first state map): `consent.issuance_completeness`
reds ("not asked") on non-requires_all programmes, and a **dead loop / 成團 block** on requires_all
programmes (the new guardian could never sign → `consentSatisfied` never true). Both activation seams
were affected.

## 2. Files changed (7; +358)

| File | Change |
|------|--------|
| `Events/GuardianLinkActivated.php` (new) | the one explicit activation event (studentId, guardianId, linkId, origin, actorId) |
| `Listeners/ReissueConsentOnGuardianActivation.php` (new) | synchronous listener; own allowlisted `asSystem`; reuses `issueRequest` + `evaluateConsentGate` |
| `LinkController::schoolVouch` | dispatch the event after the direct active INSERT (`:50`) |
| `LinkageService::approveLink` | closure returns activation-success; dispatch on a fresh CAS activation (`:220`); **D5** docblock corrected |
| `AppServiceProvider::boot` | `Event::listen(GuardianLinkActivated → ReissueConsentOnGuardianActivation)` |
| `config/scope-elevations.php` | allowlist the listener's `asSystem` (self-documenting reason) |
| `tests/…/ConsentReissueOnGuardianActivationTest.php` (new) | the six required checks |

## 3. Design (rulings honored)

- **D1 — explicit event, one sync listener.** Two dispatch points (approveLink, schoolVouch), one
  implementation. The listener runs synchronously in its **own** `asSystem` — no consent semantics
  hidden in the OD-24 visibility helper. The dispatch fires in request context after activation; the
  listener elevates itself (allowlisted, reason-matched — `ScopeElevationTest` green).
- **D2 — scope constant `{submitted, pending_consent, in_pool, teamed}`.** Reuses
  `ConsentSigningService::issueRequest` (no re-implemented issuance) with the **identical idempotency
  guard** as first issuance (no open/signed request per programme+student+signer).
- **D3 — REOPEN.** After issuing, `evaluateConsentGate` per programme regresses a requires_all in_pool
  enrolment → pending_consent until the new guardian signs; non-requires_all stays satisfied. `teamed`
  is not state-regressed (no legal transition) — the request is issued and the **live 成團
  `consentSatisfied` gate** carries the block (existing S05/S06 machinery). Accepted as correct.
- **D4** — post-成團 states untouched. **D5** — docblock corrected.

## 4. Verification (real output)

```
$ phpunit --testdox tests/Feature/ConsentReissueOnGuardianActivationTest.php
 ✔ Approvelink reissues and issuance completeness is green because of the fix
 ✔ Requires all reissue breaks dead loop and reopens the gate
 ✔ Schoolvouch seam reissues consent
 ✔ Reissue is idempotent
OK (4 tests, 77 assertions)

$ phpunit --filter ScopeElevation   → OK (4 tests)        # listener's asSystem allowlisted, reason matches
$ php artisan reconcile:run         → RECONCILE PASS — 58/58
$ phpunit --filter '/^(?!.*ClamAv).*/'  → OK (439 tests, 5686 assertions)
```

**Causality proven (red-green teeth):** in the Sam regression, the reissued request makes
`ConsentIssuanceCompletenessAssertion` green when the enrolment is aged into scope; **deleting** that
request (the pre-fix state) makes it **red**. The fix is demonstrably why it's green.

## 5. Deviations

None. Scope held to the two guardian-link activation seams; no migration, no schema change, **no
assertion definition touched** — the fix makes `issuance_completeness` green by issuing the missing
requests, never by narrowing the check.

## 6. Invariant check

| Control | Held? | Evidence |
|---------|-------|----------|
| `consent.issuance_completeness` | Yes — now satisfied by construction | reissue at activation; red-green teeth prove causality |
| requires_all consent (OD-57/58, `consent_complete_at_confirm`) | Yes — strengthened | D3 reopen: a late guardian regresses in_pool → pending_consent and blocks 成團 until signed |
| BI-1 / audit | Yes | `issueRequest` records `consent_request.issued`; no new write path |
| Scope-elevation discipline | Yes | listener's `asSystem` allowlisted + reason-matched; `ScopeElevationTest` green |
| Idempotency | Yes | identical guard to first issuance; re-dispatch = one request (test) |
| No migration / schema change | Yes | event + listener + two dispatches + docblock |
