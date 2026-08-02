# SPRINT S-FIX-consent-reissue — consent re-issuance on guardian activation

> Consent + linkage fix, child-safety adjacent. **Full review.** Origin + analysis:
> `PROPOSED-REVIEW.md` (think-first). Rulings folded below. Closes the gap S-UX3-1 surfaced: guardian-
> link activation does not issue consent requests to the newly-active guardian → `issuance_completeness`
> reds and, on requires_all programmes, a dead loop / 成團 block. Builds **before** S-UX3-2 (money).

## 1. Rulings (Leo, 2026-08-02) — folded

- **D1 — EXPLICIT EVENT.** A `GuardianLinkActivated` domain event + a
  `ReissueConsentOnGuardianActivation` listener (**synchronous**), dispatched from the **two** activation
  sites. One implementation; **no consent semantics hidden in the OD-24 visibility helper.**
- **D2 — enrolment scope = {submitted, pending_consent, in_pool, teamed}** (every pre-confirm,
  non-terminal state).
- **D3 — REOPEN.** For requires_all programmes, a late guardian regresses in_pool/teamed →
  pending_consent and **blocks 成團 until they sign** — the configured protection holds; grandfathering
  would weaken `consent_complete_at_confirm`.
- **D4 — post-成團 (confirmed/active/completed): no request** (the gate has passed; nothing to consent to).
- **D5 — fix the false `LinkageService` docblock** ("only place active is written") in-card.

## 2. The seam (two dispatch points, one implementation)

| Path | Site | Dispatch `GuardianLinkActivated(studentId, guardianId, linkId, origin)` |
|------|------|------|
| approveLink | `LinkageService::approveLink()` | inside the `asSystem` closure, **after** the `to_state='active'` audit + visibility record |
| schoolVouch | `LinkController::schoolVouch()` | inside its `asSystem` closure, **after** the `to_state='active'` audit + visibility record |

Both fire the same event with the just-activated link's `(student_id, guardian_id, id, origin)`. The
event is registered to the listener in the event map. (Invitation/bulk/registration-approve activate
teacher/school links — NOT guardian_links — and are correctly untouched.)

## 3. The listener — `ReissueConsentOnGuardianActivation` (synchronous)

For the activated `(studentId, guardianId)`:
1. Select the student's enrolments with status ∈ **{submitted, pending_consent, in_pool, teamed}**
   (skip confirmed/active/completed/withdrawn/released — D2/D4).
2. For each, read the programme's consent `template_ref` (from `wizard_sections`, as
   `IssueConsentRequests` does).
3. **Idempotency:** issue only if no request exists for (template_id, programme_id, student_id,
   signer_id=guardianId) in status `sent/viewed/signed`. Reuse
   `ConsentSigningService::issueRequest(...)` — same atomic issuance + `consent_request.issued` audit
   (provenance identical to first issuance).
4. **D3 reopen:** after issuing, call `EnrolmentService::evaluateConsentGate(programmeId, studentId,
   actor)` — for requires_all this regresses in_pool/teamed → pending_consent (via the legal back-edges)
   until the new guardian signs; for non-requires_all it stays put (one guardian already satisfies).
5. Synchronous, inside the activation's system context — issuance lands well within
   `issuance_completeness`'s 10-minute grace.

**Actor / provenance:** the activation actor (approving admin / vouching school admin) is the issuer;
`issueRequest` records the normal audit. No new audit shape, no schema change.

## 4. STOP / guardrails

- No migration, no schema change (event + listener + two dispatch lines + a docblock fix).
- Reuse `issueRequest` + `evaluateConsentGate` — do **not** re-implement issuance or gate logic.
- The listener must be **idempotent** and safe to run on a link that was already active (approveLink is
  idempotent; a re-dispatch must not double-issue).
- Touch **no** invariant/assertion definitions — the fix makes `issuance_completeness` green by issuing
  the missing requests, never by narrowing the assertion.

## 5. VERIFY (all required by Leo)

1. **Resurrected Sam regression:** a 2nd guardian activated (approveLink) on a student with a
   pending_consent/in_pool enrolment → the new guardian gets a `consent_request` →
   **`consent.issuance_completeness` GREEN because of the fix** (red without it — assert the before/after).
2. **requires_all dead-loop broken:** requires_all programme, 2nd guardian activated on a
   pending_consent enrolment → request issued → the guardian **can sign** → `consentSatisfied` becomes
   reachable (no dead loop); assert the request exists and a signature then satisfies the gate.
3. **D3 reopen observed:** requires_all, enrolment **in_pool** (consent complete), activate a new
   guardian → enrolment **regresses to pending_consent**; 成團 (confirm) is **blocked** while the new
   guardian is unsigned; after the new guardian signs → gate re-satisfies → 成團 **proceeds**.
4. **Both seams:** the reissue fires from **approveLink AND schoolVouch** (assert a request appears for
   the new guardian via each path).
5. **Idempotency:** activating/dispatching twice issues **exactly one** request per (student, enrolment,
   guardian).
6. **Battery 58/58** (`reconcile:run`) + **full suite green** (ex-clamd) after the fix.
7. Register the new assertions? None new — this fix *satisfies* existing ones; the tests above are the proof.

## 6. Definition of done

Event + listener + two dispatches + docblock fix; VERIFY 1–6 green with pasted output; battery 58/58;
suite green; no schema/migration; no assertion weakened. Then plan → build → VERIFY → **full review** →
commit. `AUDIT.md` at the end. S-UX3-2 (money) builds after this lands.
