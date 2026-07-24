# AUDIT KAP-S03 — Consent engine

**Result:** PASS — all four steps + gate verified; one documented deviation (no
PDF/A claim, Leo option b) and the timestamp-trust gap carried to S10 ·
**Date:** started 2026-07-25, gated 2026-07-24 · **HEAD at gate:** see gate commit

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
  have been. **ACCEPTED by Leo conditionally (step 2a):** every manual issuance
  now REQUIRES a reason, audited with the operator (tested + refusal tested);
  the S04A narrowing is a named §5 item.
- **Issuance validates production semantics:** programme must be PUBLISHED and its
  consent section must select the template — the same condition under which the
  bound-party RLS branch lets the signer read the legal text. (First fixture
  attempt without this failed exactly there — RLS caught it.)
- **Co-guardian "status yes" — RULED (Leo, step 2a): DERIVED status, not row
  access.** consent_requests read set stays narrow (in a separated family the
  other guardian's row/timestamp/identity is exactly the leak the set prevents).
  New: GET /my/students/{id}/consent-status?programme_id= — booleans only
  (consent_met, requires_all_guardians, your_signature_needed), computed under a
  new allowlisted+audited asSystem elevation (ConsentSigningService::derivedStatus).
  Live paste: guardian B → {"consent_met":true,…} while B's raw request AND
  signature views return {"data":[]}; scope.elevated audit row carries B as actor.
- **Merge-data drift — RULED (Leo item 4): void + re-issue, never re-render.**
  Before: no path — frozen merge data never rechecked, an issued (even signed)
  document naming the wrong child was immutable. Now: POST /admin/consent-requests/
  {id}/void {reason, reissue} (ops) → status 'voided' (new CHECK state, migration
  2026_07_25_150000), audit consent_request.voided with operator + reason +
  replacement id, ConsentRequestVoided event fired for the S09 notification
  ladder (no channel built — card non-scope). Re-issue snapshots FRESH merge
  data: tested — corrected student name appears, rendered hash changes, template
  hash unchanged; voided request 409s on render/sign; a voided SIGNED request's
  signature row is untouched (immutable evidence of what WAS signed).
- Local error responses carry stack traces (APP_DEBUG=true, local only) — refusal
  statuses/messages are what production returns.
- Demo fixtures synthetic; demo tokens revoked after the pastes. One earlier token
  batch leaked fragments into shell output (unquoted `|` in an env file) — those
  tokens were deleted and reissued quoted before any use.
- Signing UI (§16 signature pad, trilingual) not in this step's commit: Leo's
  step-2 commission was the server contract ("refusals as API responses, not UI
  states"); the guardian-facing signing screen ships with steps 3–4 before the
  gate's screenshot verifications.

### STEP 3 — Signed PDF + audit certificate (FR038) · commit (this step)
```
$ php artisan test → 167 passed (1213 assertions)   (9 new in ConsentDocumentTest,
  1 new in ConsentSigningTest: signed-then-voided consent_met exclusion)
$ reconcile:run --tag=S02A → RECONCILE PASS — 2 assertion(s), 2 passed, 0 failed

mPDF 8.3.1, PINNED EXACT in composer.json ("mpdf/mpdf": "8.3.1") — Leo condition 4;
generator recorded on the consent_documents row AND the certificate page (tested).

CONDITION 1 (hardening), tested:
 - merge values HTML-escaped before templating (renderBody) — student name set to
   "<img src='file://CANARY'>@import url(file://CANARY)" renders as LITERAL text
   (&lt;img …), generation succeeds, canary bytes ABSENT from the PDF.
 - defense in depth: sanitizeForPdf strips img/iframe/object/embed/link/style/svg/
   script + url() style attrs + @import (tested).
 - mPDF built with whitelistStreamWrappers = [] — file://, http(s)://, phar:// all
   refused even for RAW hostile markup fed straight to renderPdf (tested, canary
   absent). data: URIs (our own signature PNG) carry no wrapper — unaffected.

CONDITION 2 (embedded CJK), live zh-TC document:
 /BaseFont /MPDFAA+Sun-ExtA   ← MPDFAA+ prefix = SUBSET-EMBEDDED, FontFile2 present
 no UniCNS/Adobe-CJK CMaps (the non-embedded mode is never engaged; tested)

CONDITION 3 (PDF/A-1b): output carries <pdfaid:part>1</pdfaid:part>,
 <pdfaid:conformance>B</pdfaid:conformance>, OutputIntent + ICC. veraPDF (docker,
 ghcr.io/verapdf/cli) verdict: FAIL on EXACTLY ONE clause — 6.3.5 test 3: subset
 CIDFonts lack a CIDSet stream. Characterised: universal to mPDF unicode embedding
 (Latin-only fails identically; percentSubset=0 changes nothing). See §4 note —
 recommendation for Leo.

Live zh-TC evidence chain (dev stack, REAL ClamAV scan):
 doc=019f9260-761e… lang=zh-TC generator=mpdf/mpdf 8.3.1
 upload status=clean · file sha256 == recorded pdf_sha256 (a4cc10e2…) — verified
BI-10: download 409s until the scan verdict is clean (tested — our own generated
 files get no exemption). BI-6: generation REFUSES if the re-render hash no longer
 equals the signed rendered_sha256 (tested: merge drift → RuntimeException).
Five-branch on consent_documents (tested): signer 1 + downloads %PDF · co-guardian 0,
 download 404 · school_admin 0 · ops 1 · Member 0. Rows immutable at the DB (tested).

LEO EDGE CASE: a signed-then-voided request does NOT count toward consent_met —
 current behaviour was ALREADY CORRECT (consentSatisfied counts status='signed'
 only; void rewrites status). Regression-tested: met → void → not met, evidence
 row untouched, co-guardian derived status flips back to outstanding.
```
Result: PASS (one documented PDF/A deviation, §4)

## 4a. Deviations & notes (step 3)
- **PDF/A-1b deviation (condition 3 "say so and recommend"):** mPDF 8.3.1 emits
  PDF/A-1b structure (XMP claim, OutputIntent, full font embedding) but omits the
  CIDSet stream for subset CID fonts — veraPDF fails clause 6.3.5-3, and ONLY that,
  on every document (CJK and Latin alike). The file is self-contained and renders
  identically without installed fonts; the gap is subset bookkeeping, not content.
  **Recommendation:** accept the documented deviation for Phase 1 evidence, and at
  S10 hardening decide between (a) ghostscript post-processing to strict PDF/A
  (new dependency — needs approval) or (b) recording the deviation in the evidence
  bundle's provenance page. Leo to rule.
- **Queue-boundary repair (structural, scope layer):** Queue::after blind-reset the
  context; under the SYNC driver (tests, inline jobs) that wiped the requester's
  context mid-request. Job boundaries now snapshot/restore (beginJob/endJob) —
  workers still start scrubbed→system (their snapshot is empty). Additionally
  ScopeContext::apply() tolerates SQLSTATE 25P02 ONLY (aborted surrounding tx:
  GUC writes are impossible there and rollback reverts them anyway); any other
  QueryException still throws.
- **Conditional immutability REVOKE:** FK RI checks (SELECT..FOR KEY SHARE) need
  the referenced table owner's UPDATE privilege. In kap_test the app role IS the
  owner, so the migrations' belt-and-braces REVOKE broke inserts into
  consent_documents. The migration REVOKE now applies only when the migrating role
  is not kap_app; real environments (owner kap ≠ runtime kap_app) revoke as before
  (grant-app-role.sql unchanged); the DB trigger remains the guarantee everywhere.
- **Step-2 latent gap closed:** the 'consent-signature' upload context was
  referenced but never registered in config/uploads.php (the optional PNG path was
  untested). Both 'consent-signature' and 'consent-document' contexts now exist.
- {{today}} now resolves from the request's ISSUANCE date (was wall-clock) — the
  PDF re-render must reproduce rendered_sha256 byte-exactly on any later day.

### STEP 3a — PDF/A ruling (Leo): option (b) — no conformance claim · commit caec110
Chose (b): the pdfaid XMP block is gone; the document claims nothing a free
validator can disprove. Still self-contained (fonts subset-embedded, empty
stream-wrapper whitelist, no external references) — tested: bytes contain
FontFile2, contain NO 'pdfaid'. Certificate page wording updated. Real PDF/A
remains the S10 decision (§5 item 5, ghostscript evaluation).

### STEP 4 — Versioning · language-aware re-consent (OD-20a) · decline · commit (this step)
```
$ php artisan test → 173 passed (1361 assertions)   (6 new in ConsentReconsentTest;
  ReconciliationRunnerTest totals 10→13 — the only change to a previously-green test,
  caused by registering the three S03 assertions)
$ reconcile:run --tag=S03  → RECONCILE PASS — 3 assertion(s), 3 passed, 0 failed
$ reconcile:run --tag=S02A → RECONCILE PASS — 2 assertion(s), 2 passed, 0 failed

MATERIALITY: declared at publish (is_material, migration 2026_07_25_170000);
non-material publish supersedes nothing (tested).

LANGUAGE-AWARE RE-CONSENT (OD-20a), LIVE on the demo template (3 signed: 2×en, 1×zh-TC):
 material zh-TC v2 published →
   zh-TC-signed request  → superseded  + fresh request issued (sent)
   BOTH en-signed requests → signed, untouched
 audit: consent_request.superseded actor=24 reason="material change to zh-TC v2 (OD-20a)"
        consent_request.issued     actor=24 reason="re-consent: material change to zh-TC v2"
 Fan-out runs under a NEW allowlisted elevation (ConsentTemplateService::
 supersedeForLanguage) — the publishing admin's context cannot read guardians'
 requests; only status writes + fresh issuance leave it, each audited.
 Mid-update DRIFT is expected: fan-out issuance bypasses the parity check
 (duringMaterialUpdate) — manual issuance still refuses drift; parity restored
 live: {"en":2,"zh-TC":2,"zh-SC":2} (en material v2 superseded the 2 en requests).

STALENESS GUARD (new, closes a hole the immutable-version design left open):
 published version rows never change, so an OLD row still hash-matches after a
 new version publishes. sign() now refuses (409) unless the rendered version is
 the CURRENT one for its language; re-read → sign records the new hash (tested).

DECLINE (FR037), LIVE: fresh request declined by its signer →
 status declined · audit: actor=26 (guardian) sent→declined
 reason="不同意攝影條款 (photography clause declined)"
 Terminal (render/sign/re-decline all 409, tested) · reason REQUIRED (422, tested)
 · non-addressee 404 (tested) · does not satisfy consent (tested).

ASSERTIONS (--tag=S03) registered: consent.bi6_hash_language_scoped ·
 consent.language_completeness · consent.superseded_reconsent (vacuous-aware).
 DELIBERATE FAILURE, LIVE: one signature's stored hash tampered (synthetic dev row,
 trigger disabled as owner, restored byte-identically after) →
   FAIL consent.bi6_hash_language_scoped — "1 signature(s) with hash or language
   mismatch: 019f9232-90b7…" · RECONCILE FAIL — 2 passed, 1 failed
 restore → RECONCILE PASS 3/3. Assertion-teeth also unit-tested (missed fan-out
 simulation → consent.superseded_reconsent fails naming the request).
 Runner exit codes re-proven: --tag=NOPE (empty match) → exit 1; --tag=S03 → exit 0.
 (Note: the exit shown inline during the tamper demo was the grep pipeline's, not
 the runner's — the runner's fail-exit is covered by ReconciliationRunnerTest.)
```
Result: PASS

### GATE — Signing UI · Consent Evidence Report · evidence bundle · full battery
```
$ php artisan test → 175 passed (1412 assertions)   (2 new in ConsentEvidenceReportTest)
$ php artisan reconcile:run → RECONCILE PASS — 13 assertion(s), 13 passed, 0 failed
  (one red on the way: programmes.published_completeness caught the STEP-2 demo
   fixture SGNLIVE1 declaring has_fee_items with zero fee_items rows — the
   invariant was right, the synthetic fixture was sloppy; fee item added, green)
$ php artisan migrate --pretend → Nothing to migrate
$ npx tsc --noEmit → clean · npm run build → i18n:check 226 keys ×3 parity,
  no hardcoded strings · bundle-budget PASSED (largest chunk well under 1MB gz)

GUARDIAN SIGNING UI (Leo gate item 1) — screenshots in screenshots/:
 sign-en-gate1-unmet.png · sign-zh-TC-gate1-unmet.png · sign-zh-SC-gate1-unmet.png
   → placeholder non-binding banner visible in ALL THREE languages (UI banner
     keyed off is_placeholder + the in-body R15 text), document in the chosen
     language, and GATE 1 in its unmet state: red "Signing is locked: the
     document has not been read to the end in the language displayed."
 sign-en-gate2-unmet.png → scroll recorded (green server confirmation),
   affirmation unchecked, red gate-2 lock, Sign now disabled.
 sign-en-gate3-unmet.png → affirmed, empty signature pad, red gate-3 lock,
   Sign now disabled.
 sign-en-signed.png → full UI flow completed end-to-end by Playwright (scroll →
   affirm → draw → sign): success screen shows language recorded + BOTH hashes.
 admin-templates-en.png · admin-templates-zh-TC.png → R15 admin banner + per-
   version R15 tags with per-language hashes (step-1 carried verification).
 The screen refuses at every unmet gate; the API refusals behind it were pasted
 at STEP 2 — the button was never the gate.

FULL PRODUCTION PIPELINE, LIVE: HTTP sign (zh-SC, drawn incl. PNG through the
 consent-signature upload context) → redis queue → Horizon → mPDF → ClamAV clean
 → consent_documents row (generator mpdf/mpdf 8.3.1).

EVIDENCE BUNDLE (Leo gate item 2) — exported live via
 GET /reports/consent-evidence/{sig}/bundle (audit_read), signature
 019f928b-45bd… (zh-SC v3). CONTENTS: manifest.json · template.html ·
 rendered.html · consent.pdf · audit-events.json · README.txt.
 THIRD-PARTY VERIFICATION, from bundle bytes alone (shasum + jq, no platform):
   VERIFIED template.html  2434073552ad6548…  == manifest template_sha256
   VERIFIED rendered.html  cff4f365acfb35f3…  == manifest rendered_sha256
   VERIFIED consent.pdf    c919b84505d09392…  == manifest pdf_sha256
 A third party can verify standalone: (1) the exact legal text and its hash in
 the language signed; (2) the exact rendered document the guardian saw and its
 hash; (3) the PDF (certificate page carries the same hashes + generator + the
 event sequence); (4) event-sequence internal consistency; (5) the audited
 issue→view→sign trail with actor identities.
 THE GAP, STATED (in README.txt inside every bundle AND here): (A) TIME — all
 timestamps are our server clock; nothing anchors any hash to a moment in time
 or proves non-fabrication by the platform operator (S10 decision: RFC-3161 TSA
 or external hash-chain anchoring; protects only signatures made after
 adoption — §5 item 2). (B) SIGNER IDENTITY — bound to an authenticated account,
 not a qualified certificate; account-to-person corroboration needs platform
 records. (C) DATABASE STATE — INSERT-only protections and bundle-to-live-row
 correspondence are attestable only by inspecting the platform. The service also
 REFUSES to export a bundle whose re-render no longer reproduces the signed
 hash (tested).

AUDIT ELEMENT shipped: Consent Evidence Report (/reports/consent-evidence +
 /admin/consent-evidence page) — coverage by version AND language, R15 exposure
 count, outstanding/declined/superseded/voided lists, per-signature bundle
 download. audit_read-gated (guardian 403 tested).

FIVE-BRANCH COLLECTION (all four scoped tables): consent_template_versions
 (STEP 1, live) · consent_requests (STEP 2, tested + live co-guardian) ·
 consent_signatures (STEP 2, co-guardian-zero live: {"data":[]}) ·
 consent_documents (STEP 3, tested incl. signer download %PDF + co-guardian 404).

ELEVATION REVIEW (gate ritual): nine allowlisted asSystem sites — six from
 S01/S02A unchanged, three new this sprint, each reviewed: derivedStatus
 (booleans only leave it) · ConsentDocumentService::download (authz decided by
 cd_read before elevation; storage-row read only) · supersedeForLanguage
 (status writes + fresh issuance only, each audited with the publishing admin
 as actor). Every elevation writes scope.elevated.
```

## 4b. Deviations & notes (gate)
- **api Dockerfile: ext-zip added** (evidence bundle ZIPs; host PHP had it, the
  container did not — ZipArchive failed live despite green host tests). Image
  rebuilt. Environment-parity lesson noted; staging/production image build (S00
  runbook) must include the same extension list.
- **Horizon stale autoloader:** the long-running worker predated `composer
  require mpdf` and failed 3× with "Class Mpdf not found" until restarted —
  operational note for the deploy runbook: RESTART WORKERS AFTER DEPENDENCY
  CHANGES. One signature's document job was then lost between queue connections
  (host retry pushed where no worker listens); backfilled idempotently via the
  service. All 5 signatures now have documents.
- Playwright drove the real UI at localhost:8080 for all screenshots (no mocked
  states); demo tokens revoked after each live block.

## 5. Leftovers & newly discovered risks
| # | Item | Severity | Proposed sprint |
|---|------|----------|-----------------|
| 1 | **R15 named S10 check:** no live consent template version may still carry placeholder text at go-live (placeholder is EXPECTED during build — deliberately NOT a nightly assertion, per Leo: permanently-red assertions train people to ignore red) | High | **S10 readiness** |
| 2 | **Timestamp trust (Leo, design-fresh note for S10):** signature evidence rests on a server NTP timestamp — contestable if anyone argues the clock was wrong or set retroactively. Options noted now, impossible to retrofit onto collected signatures: (a) RFC-3161 trusted timestamp authority countersigning each evidence bundle hash at signing time; (b) periodic anchoring of the audit_events/signature hash chain to an external immutable reference (e.g. daily digest published outside the platform). Either can be added FORWARD from the moment adopted; S10 should pick one before real signatures exist | **High — S10 design decision** | S10 |
| 3 | Client question (fees/terms per school) still open — gates S04A consumer read clauses | High — client | before S04A |
| 4 | **S04A MUST narrow consent_requests INSERT back to system-only** once enrolment issues requests automatically (Leo ruling 1: "a temporary widening on a personal-data table becomes permanent unless a sprint is named for reversing it"). Until then every manual issuance is reason-audited | High | **S04A** |
| 5 | **PDF/A strictness decision** (§4a): mPDF omits CIDSet for subset fonts — veraPDF clause 6.3.5-3, sole failure. Choose at S10: ghostscript post-process (new dependency) vs documented deviation in evidence provenance | High — S10 decision | S10 |
