# SPRINT KAP-S04C — Self-registration & the approval queue (OD-23)

> New card per the approved 2026-07-24 re-plan (OD-23 client model). Runs AFTER S04B.
> Approval latency is now the product's front door — the queue is the product here.
> **RECONCILED 2026-07-29** against what actually shipped (S04A/B/S05/S06), per
> `docs/sprints/S04C/PROPOSED-S04C-REVIEW.md` and Leo's four rulings:
> **D-iii** — the anonymous write is a REAL `public` scope context + a `WITH CHECK` INSERT policy
> confined at the DB (NOT laundered through `asSystem`); the "reuse S06B verbatim" premise was hollow
> (S06B never built) — STEP 1 is a **greenfield build** of the platform's first anonymous write.
> **D-i** — a minimal `school_links` affiliation lands here so an approved student reaches Active.
> **D-ii** — build the public payment PAGE + mint an absolute URL to it (folded into STEP 1).
> **D-iv** — `links.activation_audited` ships as a structural assertion with teeth (FLAG #2).

## GOAL
Students and guardians self-register through the platform's **first anonymous write**, structurally
confined at the database (a `public` context that can INSERT `registration_requests` and nothing
else); APPROVAL creates the account (born UNVERIFIED, OD-29); a registration naming a counterpart
arrives at the approver as one piece of work carrying two genuine, separately-audited decisions;
an approved student reaches **Active** via a minimal school affiliation (D-i); the public-facing
surfaces this sprint owns finally render (registration forms **and** the never-built public payment
page, D-ii); and the S01 guardian-creates-student path is retired the moment self-registration can
replace it.

## PRECONDITIONS
- [ ] S04B gate PASSED · OD-23/27/28/29 recorded (done 2026-07-24) · FR066 exceptions queue live (S01)

## IMPLEMENTS  OD-23 · OD-27 (creation retirement) · OD-28 · OD-29 · FR068 · SR001 · 2.28 · FR066 (reuse)

## CARRY-IN — public-surface fix from S04B (build WITH the public-page work, D-ii)
- **Payment-link forwardable URL (OD-44):** `PaymentLinkService::mint`
  (`api/app/Services/Money/PaymentLinkService.php:65`) builds `url("/pay/{token}")` =
  `http://localhost/pay/{token}` — wrong host/port AND pointing at a page that does not exist. The
  card's earlier "missing `/api`" framing was itself wrong: `/api/pay/{token}` returns JSON, and a
  forwardable link is for a **human** (a grandparent). **The real fix is the page that was never
  built:** the anonymous, initials-only public payment PAGE that renders and calls `/api/pay/{token}`,
  plus minting an **absolute URL to that page** (config-driven public base, not `APP_URL`+path). This
  lands in STEP 1 as part of this sprint's anonymous surfaces and finally lets us capture the
  screenshot the system audit had to skip (SYSTEM-AUDIT Surprise 2). Found at the S04B preview,
  2026-07-27.

## SCOPE CLASSIFICATION PLAN (read sets pre-stated)
| Table | Classification | Read set / justification |
|---|---|---|
| `registration_requests` (**greenfield** — no prior table exists; the deleted S06B card never shipped one) | **scoped** | Pre-account personal data about a child or guardian. INSERT: a **new `public` scope context** (`app.context='public'`) whose ONLY grant is a single `WITH CHECK` INSERT policy on THIS table — confined at the DB, not run as system. The `public` string appears in EXACTLY ONE policy platform-wide; `scope.public_context_confinement` proves it structurally (the `single_reader` discipline of the payment link, applied to a writer). Read: system · admins of the ROUTED school · academy ops/audit (direct registrations: academy only). UPDATE (approve/decline): the same reviewer set. The requester reads NOTHING — constant-shape 202 + opaque reference, no status endpoint |
| `held_links` | **scoped** | A form-claimed, unconfirmed relationship assertion — the most misleadable row in the system. Read: system · the approver set of the student's routing · ops/audit. Write: system only (created at approval, materialised or expired by jobs). **Materialises into a pending link ONLY when the counterpart address is VERIFIED (Leo 1a); carries origin "form-claimed — not confirmed by either party"; expires (default 90d, configurable) with expiry in queue-age reporting (Leo 1b)** |
| `guardian_links` (state addition) | already scoped | Gains a pending state + the link-approval decision endpoint HERE (minimum needed for orphan pairs); the full 2.30 retrofit of S01 ceremonies is S04D. **The activation decision MUST audit `entity_type='guardian_link'`, `to_state='active'`, actor = approver (FLAG #2 — S06 consent integrity depends on it; `ConsentCompleteAtConfirmAssertion.php:69` reads exactly this).** Naming: the shipped CHECK enum uses `pending_confirmation`, not the 2.30 word `pending_approval` (`create_invitations_and_link_tables.php:39`) — extend the constraint additively and reconcile the name in the migration note. Policy amendment shipped with the migration |
| `school_links` (**affiliation at approval, D-i**) | already scoped | The existing student↔school table (`create_invitations_and_link_tables.php:58`; one `active` per student; read policy already gates programme visibility + school_admin scope). S04C mints an **active** `school_links` row when a school-routed student's registration is approved — the OD-28 "first approved link" that flips the student to Active. Audited `school_link.created`, `to_state='active'`, actor = approver (BI-8). The rich school-link **state machine** + teacher links remain S04D — only the minimal affiliation lands here |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **The `public` context + anonymous write + public payment page (greenfield).** Build the new
   `public` scope context (`app.context='public'`) and its ONE `WITH CHECK` INSERT policy on
   `registration_requests` — confined at the DB, the platform's first structurally-bounded anonymous
   writer (NOT `asSystem`). Student and guardian registration forms, trilingual (2.28 Q0); school
   picker = opt-in listed partners OR "direct to the academy" (first-class, no free text); optional
   counterpart email; `throttle:registration` (mirror `throttle:payment-link`) + honeypot + min
   fill-time + text-only caps. Requester gets a **constant-shape 202 + opaque reference; no status
   endpoint**. **Also build the public payment PAGE + fix the forwardable URL to an absolute link to
   it (CARRY-IN / D-ii).** VERIFY: anonymous read sweep zero everywhere; INSERT blind (no RETURNING);
   `scope.public_context_confinement` deliberate red then green; constant-shape probes — existing vs
   non-existing vs duplicate **registrant** email AND the **counterpart-email oracle** (naming an
   existing/verified counterpart vs an unknown one) all byte-identical; 429 paste; **minted payment
   URL resolves + public payment page renders (screenshot).**
2. **Approval → account creation (UNVERIFIED) + OD-29 verification.** Approve (routed reviewer) →
   account via the retained system creation primitive **in its unverified variant** (the shipped
   `GuardianStudentService::createStudent` hard-codes `email_verified_at=now()`
   (`GuardianStudentService.php:42`); approval must create born-UNVERIFIED, OD-29); 2.11 single-use
   signed verification link; login refused before verification; decline requires reason; both
   decisions audited with actor. VERIFY: approve → account + verification-mail fixture → pre-verification
   login refusal paste → post-verification login paste; decline refusal without reason.
3. **Orphan pairs + held links + the activation audit (Leo 1a/1b · FLAG #2).** Named counterpart with
   an existing VERIFIED account → pending link into the queue at approval. Not-yet-registered
   counterpart → held link that materialises ONLY on the counterpart's verified approval;
   "form-claimed" origin marker; expiry job + audit. Extend the `guardian_links` status CHECK
   additively for the pending state (D9). **The link-approval decision writes the FLAG #2 audit:
   `guardian_link.created`, `to_state='active'`, actor = approver — on EVERY activation path.** VERIFY:
   the TYPO SCENARIO pasted — counterpart address registered by an unrelated stranger: no link
   materialises before verification, and when it does it carries the form-claimed marker, never a
   clean pending link; expiry fixture → expired + reported; **`to_state='active'` audit row pasted for
   every activation path**; `links.activation_audited` deliberate red then green;
   `links.no_unverified_materialisation` red then green.
4. **The ONE queue + affiliation + retirement.** Accounts and links in a single per-approver queue
   (2.28 Q4/Q5); every row shows age; combined item for account+link — TWO endpoints, TWO decisions,
   TWO audit rows (never one decision writing two rows); over-threshold age → FR066 exceptions entry
   (REUSED `guardian_replacement_exceptions`-style ledger — there is no generic queue service;
   greenfield row-ledger); Access & Identity report gains queue-age + registration funnel + held-link
   ledger. **On a school-routed student's approval, mint the active `school_links` affiliation so the
   student reaches Active (D-i), audited `school_link.created` / `to_state='active'`.**
   **Retire guardian-creates-student (OD-27): endpoint removed (`routes/api.php:76`), service entry
   removed (`GuardianStudentService::createStudent`), asSystem allowlist entry removed
   (`config/scope-elevations.php:15`; 49 → 48).** VERIFY: combined-item flow pastes both audit rows
   with their own timestamps; student-approval pastes the `school_link` activation audit → student
   reads Active; escalation fixture lands in FR066; retirement refusal (404) pastes; elevation-list
   review shows the entry GONE (48); S01 suite migrated and green.

> Sequencing note (D7): self-registration goes live in STEP 1–2 and the old creation path is retired
> in STEP 4. All work lands on `main` and the sprint tags at the end (OD-8), so there is never a
> *released* moment without a creation path — "never gapless" (OD-27) is satisfied by the sprint
> boundary, not by same-step removal. Recorded so the AUDIT explains it.

## NON-SCOPE
The school-link **state machine** and teacher/school link *states* (S04D) — S04C ships only the
minimal `school_links` affiliation-at-approval (D-i), not the ceremonies · pairing/email/vouch
retrofit · OD-24 · OD-30 · bulk creation (S04D) · batch enrolment (S04E) · notification channels
(S09 — fire events).

## KEY VERIFICATIONS
Five-branch per scoped table (registration_requests: routed-school admin sees · other-school admin
zero · academy sees direct · guardian/student/Member zero · anonymous zero; held_links likewise;
school_links affiliation: routed-school admin sees post-approval · cross-school zero · the student
reads Active) · the counterpart-email oracle byte-identical · `--tag=S02A/S03/S04A` green each step ·
bundle budget + i18n parity green.

## AUDIT ELEMENT
Access & Identity report extensions: queue age by approver (a school not keeping up is visible to
the academy), registration funnel (submitted → approved → verified → linked → **Active**), held-link
ledger (outstanding / materialised / expired).

## ASSERTIONS (--tag=S04C)
- `scope.public_context_confinement` — the `public` context appears in exactly one INSERT policy,
  nowhere else (structural, binds to the real DB context built in STEP 1).
- `account.provenance` — every account traces to an approving decision, an accepted invitation, or
  school bulk creation (audit-backed); no other origin exists.
- `links.no_unverified_materialisation` — no pending link whose origin is a held link against an
  address that was unverified at materialisation time.
- **`links.activation_audited` (D-iv / FLAG #2)** — every `active` `guardian_link` carries a
  `to_state='active'` audit event; a missing one fails SILENTLY (S06's requires_all hardening reads
  the guardian as not-active) — this assertion makes it loud. Red-then-green teeth.
- `queue.escalation_liveness` — no request older than the threshold without an FR066 exception.
- `held_links.expiry` — none pending past expiry.

## EXIT GATE
Tests + `--tag=S04C` + all prior tags green + the typo-scenario paste + five-branch pastes + the
counterpart-email oracle paste + the public payment page render + the `to_state='active'` activation
audit pastes (guardian_link and school_link) + retirement pastes + elevation review (48) + AUDIT.md
(including the D7 sequencing rationale and the D8/D9 reconciliations), gate commit.
