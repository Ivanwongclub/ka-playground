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

### STEP 2 — Signing flow (FR036) · commit (this step)
```
$ php artisan test → 152 passed (907 assertions)   (15 new in ConsentSigningTest)
$ reconcile:run --tag=S02A → RECONCILE PASS — 2 assertion(s), 2 passed, 0 failed
  (consent_requests + consent_signatures classified SCOPED with policies in the
   creating migration; kap_app on consent_signatures = SELECT+INSERT only: "kap_app=ar")

THREE GATES, LIVE, AS API RESPONSES (server-side against the SERVER-recorded event
sequence — the sign endpoint never trusts a client claim):
 [GATE 1] render en, POST /sign directly (no scroll event) →
   HTTP 422 {"errors":{"scroll":["The document has not been read to the end in the
   language displayed — no server-recorded scroll-to-end event follows the last
   render (FR036 gate 1)"]}}
 [GATE 2] scroll recorded, sign WITHOUT affirmation →
   HTTP 422 {"errors":{"affirmed":["The affirmation of consent has not been given (FR036 gate 2)"]}}
 [GATE 3] scroll + affirmation, empty stroke set →
   HTTP 422 {"errors":{"signature":["No signature capture — drawn strokes or a
   typed name is required (FR036 gate 3)"]}}

SESSION BINDING (FR036), LIVE:
 super_admin POSTs the signature      → HTTP 403 "Missing permission: consent.sign"
   (consent.sign held by NO capability — S01 defect-1 fix) + audit row:
   permission.denied | actor 25 | academy_admin
 co-guardian (holds consent.sign by ROLE, not the addressee) → HTTP 404 on
   document/scrolled/sign — RLS + addressee check; existence not even confirmed
 link-level deny override (S02A narrow-only) blocks signing per student → 403 (tested)
 DB backstop: cs_insert WITH CHECK signer_id = actor — a bypassed controller
   still cannot write a signature as anyone else
 consent_signatures immutable: trigger + REVOKE UPDATE/DELETE (grant-app-role.sql
   extended; probe accepts 42501-or-trigger, tested)

LANGUAGE = LANGUAGE RENDERED (OD-20), LIVE: guardian read zh-TC to the end, then
switched to en and read to the end, then signed → recorded language "en", hash
6a06dd37… (= en version), NOT e3301d42… (zh-TC). Sign call carries NO language
parameter; language/version/hashes come from the last SERVER-recorded render.
A language switch INVALIDATES the earlier scroll (tested: TC-scrolled, EN rendered
unscrolled → 422 gate 1).

DUAL-HASH BINDING (Leo, mid-sprint): signature stores template_sha256 AND
rendered_sha256 (merge-resolved document as served; merge_data frozen at issuance
in ops context so rendering is deterministic and reader-scope-independent).
Distinctness proven: same version + different merge data → rendered hash differs,
template hash unchanged (tested). Live signed row: template 6a06dd37… ≠ rendered 7e120cbc….
Event sequence snapshot on the signature: [rendered, scrolled, rendered, scrolled,
rendered, scrolled, signed] with per-event language + hashes + server timestamps.

FIVE-BRANCH, consent_requests (tested): addressed guardian 1 · student 1 (status) ·
school_admin of school 1 · other-school admin 0 · Member 0.
FIVE-BRANCH, consent_signatures — the strictest (tested + co-guardian live):
signer 1 · CO-GUARDIAN OF SAME STUDENT 0 (live: {"data":[]}) · school_admin 0
(requests 1 = status, signatures 0 = no evidence) · ops/audit admin 1 (compliance) ·
Member 0.
OD-10: consentSatisfied() any-one default; requires_all_guardians=true → false
until every active guardian signs (tested; S04A wires it into enrolment).
Re-sign of a signed request → HTTP 409 "Consent request is signed and cannot be acted on".
```
Result: PASS

## 4. Deviations & notes (step 2)
- **Issuance write set widened from "system" to "system OR operations":** the card
  said requests are created by system (S04A) with "fixture/port creation" until
  then. Manual ops issuance is a real admin action, not a fixture, so the INSERT
  policy admits operations/super — narrower than an allowlisted elevation would
  have been. Flagged for review.
- **Issuance validates production semantics:** programme must be PUBLISHED and its
  consent section must select the template — the same condition under which the
  bound-party RLS branch lets the signer read the legal text. (First fixture
  attempt without this failed exactly there — RLS caught it.)
- **Co-guardian "status yes":** as built, a co-guardian sees only requests
  addressed to THEM (card read set: system · ops/audit · addressed signer ·
  student · school_admin). They see the sibling guardian's request not at all —
  status of the family's consent position for co-guardians would need the
  read set widened to "any active guardian of the student". Left NARROW (widening
  later is a migration); Leo to confirm which was intended.
- Local error responses carry stack traces (APP_DEBUG=true, local only) — refusal
  statuses/messages are what production returns.
- Demo fixtures synthetic; demo tokens revoked after the pastes. One earlier token
  batch leaked fragments into shell output (unquoted `|` in an env file) — those
  tokens were deleted and reissued quoted before any use.
- Signing UI (§16 signature pad, trilingual) not in this step's commit: Leo's
  step-2 commission was the server contract ("refusals as API responses, not UI
  states"); the guardian-facing signing screen ships with steps 3–4 before the
  gate's screenshot verifications.

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | **R15 named S10 check:** no live consent template version may still carry placeholder text at go-live (placeholder is EXPECTED during build — deliberately NOT a nightly assertion, per Leo: permanently-red assertions train people to ignore red) | High | **S10 readiness** |
| 2 | **Timestamp trust (Leo, design-fresh note for S10):** signature evidence rests on a server NTP timestamp — contestable if anyone argues the clock was wrong or set retroactively. Options noted now, impossible to retrofit onto collected signatures: (a) RFC-3161 trusted timestamp authority countersigning each evidence bundle hash at signing time; (b) periodic anchoring of the audit_events/signature hash chain to an external immutable reference (e.g. daily digest published outside the platform). Either can be added FORWARD from the moment adopted; S10 should pick one before real signatures exist | **High — S10 design decision** | S10 |
| 3 | Client question (fees/terms per school) still open — gates S04A consumer read clauses | High — client | before S04A |
