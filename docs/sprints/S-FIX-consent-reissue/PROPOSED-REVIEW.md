# PROPOSED REVIEW — S-FIX-consent-reissue (think-first, no code)

> Consent + linkage, child-safety adjacent. Origin: S-UX3-1 surfaced that approving a 2nd guardian on
> a student with a pre-team enrolment reds `consent.issuance_completeness` — guardian-link activation
> does **not** issue consent requests to the newly-active guardian. This artefact maps the seam, the
> per-state failure modes, idempotency/provenance, and the product rulings needed — **before any code.**

---

## 1. THE SEAM — how many activation paths, and can ONE hook cover them?

**Finding: exactly TWO code sites activate a `guardian_link` (not the five Leo listed — the other three
activate different link types).**

| # | Path | Site | Active write | Activation audit |
|---|------|------|--------------|------------------|
| 1 | **approveLink** (admin approval; also the terminus of road A/B form-claims, held-link materialisation, pairing/D-i) | `LinkageService::approveLink()` | `:145` CAS `pending_approval → active` | `:157` `guardian_link.activated`, to_state='active' |
| 2 | **schoolVouch** (OD-30 / OD-24 second-guardian add) | `LinkController::schoolVouch()` | `:130` direct INSERT `status='active'` (bypasses pending) | `:134` `guardian_link.created`, to_state='active' |

**NOT guardian_links** (so out of scope): invitation-accept activates a *teacher_link*
(`InvitationService`), bulk activates a *school_link* (`BulkStudentCreationService`), registration
("D-i") approval activates a *school_link* (`RegistrationApprovalService`) and only ever creates
*pending/held* guardian_links — which reach active via Path 1.

> The `LinkageService` docblock claiming approveLink is "the ONLY place a guardian_link reaches active"
> is **false** — `schoolVouch` contradicts it (direct INSERT, own inline audit). Worth correcting.

**The single-seam candidate (recommended):** both paths already call, post-activation,
`recordGuardianAdditionVisibility(studentId, newGuardianId, linkId, origin)` (`approveLink:162`,
`schoolVouch:141`) — the OD-24 helper that fires on *every* guardian addition. That is the one funnel
both active paths share.

- **D1 (seam) — proposed:** introduce **one** internal `onGuardianActivated(studentId, guardianId,
  linkId, origin)` that BOTH paths call (rename/extend the shared visibility call, or have it call a new
  `reissueConsentForNewGuardian(...)` alongside the visibility record). Reissue logic lives **once**.
  - **Pre-condition to verify in build:** `recordGuardianAdditionVisibility` is invoked **only** at
    these two *post-activation* points (never on pending-link creation). If that holds, hosting the
    reissue there is safe. If it's ever called pre-activation, use a dedicated `GuardianLinkActivated`
    event dispatched from the two active-write sites instead (still one listener = one implementation).
  - Alternative (cleaner separation, 2 dispatch lines): a `GuardianLinkActivated` domain event + a
    `ReissueConsentOnGuardianActivation` listener. Same single implementation; explicit coupling.

## 2. THE STATE MAP — what missing consent DOES, per enrolment state

Enrolment states + transitions (`EnrolmentService::TRANSITIONS`): `submitted → pending_consent → in_pool
→ teamed → confirmed → active` (+ withdrawn/released terminal; in_pool↔pending_consent and teamed→in_pool
back-edges exist). Consent requests are issued by `IssueConsentRequests` **at enrolment creation** — and
that job **guards `status === 'submitted'`**, so it will NOT re-fire for a guardian added later. Two
predicates govern the failure:
- **`consent.issuance_completeness`** — scopes enrolments in **`pending_consent` + `in_pool`** (>10 min
  old): every active guardian must have a request (`sent/viewed/signed`).
- **`teams.consent_complete_at_confirm`** (requires_all branch) — at 成團, **every guardian _active as
  of confirm_** must have their own non-stale signature. Live 成團 also re-checks `consentSatisfied`
  (all active guardians) under a `FOR SHARE` lock.

| State | Missing request from a newly-active guardian → what happens | Fix covers? |
|-------|-------------------------------------------------------------|-------------|
| **submitted** | Edge: if the guardian activates *before* `IssueConsentRequests` runs, the job reads live guardians and includes them (self-heals). If *after* the job ran → same gap as pending_consent. | **Yes** (include for safety; idempotent) |
| **pending_consent** | **non-requires_all:** the other guardian's signature already met consent → no functional block, but the request is never issued → **`issuance_completeness` REDS ("not asked" / consent-visibility gap).** **requires_all:** the new guardian can't sign (no request) → `consentSatisfied` never true → **DEAD LOOP — stuck in pending_consent forever.** | **Yes** |
| **in_pool** | **non-requires_all:** stays in_pool; **`issuance_completeness` REDS.** **requires_all:** `consentSatisfied` now false → next `evaluateConsentGate` **REGRESSES in_pool → pending_consent**; and 成團 is **blocked** (lock re-check) — **DEAD LOOP at 成團**; plus completeness reds. | **Yes** |
| **teamed** (pre-成團) | **non-requires_all:** none. **requires_all:** guardian is "active as of confirm" → 成團 **blocked** by the live lock; `consent_complete_at_confirm` would red if forced → **DEAD LOOP at 成團.** (Not in issuance_completeness scope, but a real block.) | **Yes** |
| **confirmed / active / completed** | 成團 already passed; `consent_complete_at_confirm` judges "active as of confirm" so a *later* guardian doesn't retro-red; not in issuance scope → **no failure.** | **No** (see D4 policy) |
| **withdrawn / released** | terminal — no consent relevant. | No |

**Both failure modes exist** — a **consent-visibility gap ("not asked", reds completeness)** for
non-requires_all, and a **dead loop / 成團 block** for requires_all. **The fix must cover
{submitted, pending_consent, in_pool, teamed} — every pre-confirm non-terminal state — not just
pending_consent.**

- **D2 (scope) — proposed:** reissue for the student's enrolments with status ∈
  {submitted, pending_consent, in_pool, teamed}. Reuse `ConsentSigningService::issueRequest(templateRef,
  programmeId, studentId, newGuardianId, actor)` (the same atomic issuance the job uses), with the
  same idempotency guard (no existing `sent/viewed/signed` request for that signer). template_ref read
  per programme from `wizard_sections` consent data, exactly as `IssueConsentRequests` does.

## 3. Idempotency, provenance, gate re-evaluation

- **Idempotency:** for each target enrolment, issue **only if** no request exists for
  (template_id, programme_id, student_id, signer_id) in status `sent/viewed/signed` — the identical
  guard `IssueConsentRequests` uses. A double activation (idempotent approveLink returns early on an
  already-active link) never double-issues; running the seam twice is a no-op. Natural key:
  (programme_id, student_id, signer_id).
- **Provenance:** `issueRequest` records the normal `consent_request.issued` audit (actor = the
  approving/vouching admin, or the activation actor) — same trail as first issuance. No new audit shape.
- **Gate re-evaluation (important):** after issuing, call `EnrolmentService::evaluateConsentGate` per
  affected (programme, student). For **requires_all**, `consentSatisfied` is now false → the gate
  **regresses in_pool → pending_consent** until the new guardian signs. That regression is the honest
  state — but whether it's *desired* is a policy question (**D3** below).
- **Timing:** issue **synchronously** inside the activation flow (both sites already run inside an
  `asSystem` elevation). `issuance_completeness` has a 10-minute grace; a synchronous issue never trips
  it. (An async job would need to finish well within 10 min; synchronous is simpler and small — one
  request per consent-live enrolment.)

## 4. Regression + verification plan (for the build card)

- **Resurrect the Sam scenario as the regression test:** a 2nd guardian activated (approveLink) on Sam,
  who has a `pending_consent`/`in_pool` enrolment → after the fix, the new guardian has a
  `consent_request` and **`consent.issuance_completeness` is GREEN**. (This is the exact scenario that
  reddened it in S-UX3-1.)
- **requires_all dead-loop test:** a requires_all programme, 2nd guardian activated on a
  `pending_consent` enrolment → the new guardian gets a request, can sign, and `consentSatisfied`
  becomes reachable (no dead loop). And a `teamed`/`in_pool` requires_all case → the gate reflects the
  new outstanding consent.
- **Both activation paths:** cover approveLink AND schoolVouch (the two seams) in the test.
- **Idempotency test:** running the seam twice issues exactly one request per (student, enrolment,
  guardian).
- **Full battery 58/58 + suite green** after the fix; the seed's Theo scenario stays green, and a
  restored Sam-style scenario goes green *because of* the fix (not by avoiding it).

## 5. PRODUCT RULINGS NEEDED — surfaced, not assumed

- **D3 — reopen the consent gate for a late guardian on requires_all?** For a **requires_all**
  programme, adding a guardian to an **in_pool** (consent-complete) or **teamed** enrolment makes
  `consentSatisfied` false. Options: **(a) REOPEN** — regress in_pool → pending_consent (and block 成團)
  until the new guardian signs — the literal reading of "requires ALL guardians"; or **(b) GRANDFATHER**
  — honour the completion that was valid when it was made, and require the new guardian only for future
  gates / not at all pre-成團. The current gate logic implies **(a)**. This is a genuine child-safety /
  consent-scope policy call — **Leo's ruling.** (For non-requires_all, no reopen — one guardian suffices;
  the fix only closes the completeness "not-asked" gap.)
- **D4 — guardians added AFTER 成團 (confirmed/active):** they get **no** consent request (the gate
  already passed; `consent_complete_at_confirm` won't retro-red). Proposed: **accept** — consent is a
  pre-成團 gate; a guardian joining after it has nothing to consent to for that enrolment. Confirm.
- **D5 — the docblock lie:** `LinkageService`'s "only place active is written" comment is false
  (schoolVouch). Fix the comment as part of this card (doc-only), or leave for a separate sweep?

## 6. Proposed shape of the fix (subject to the rulings)

One `reissueConsentForNewGuardian(studentId, guardianId, actor)` invoked from the single seam (D1):
for each of the student's enrolments in {submitted, pending_consent, in_pool, teamed}, if the new
guardian has no open/signed request, `issueRequest(...)` it (provenance-audited), then
`evaluateConsentGate(...)` per (programme, student) — subject to the D3 reopen ruling. Idempotent,
synchronous, inside the existing `asSystem` elevation. Two dispatch points (approveLink, schoolVouch),
one implementation.

**Nothing is built until Leo rules D3 (and confirms D1/D2/D4/D5).** A live consent-completeness gap does
not sit open — this is next after the rulings, before S-UX3-2 (money) builds.
