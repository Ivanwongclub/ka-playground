# SPRINT KAP-S04C — Self-registration & the approval queue (OD-23)

> New card per the approved 2026-07-24 re-plan (OD-23 client model). Runs AFTER S04B.
> Approval latency is now the product's front door — the queue is the product here.

## GOAL
Students and guardians self-register through the platform's only anonymous write; APPROVAL creates
the account; a registration naming a counterpart arrives at the approver as one piece of work
carrying two genuine decisions; and the S01 guardian-creates-student path is retired the moment
self-registration can replace it.

## PRECONDITIONS
- [ ] S04B gate PASSED · OD-23/27/28/29 recorded (done 2026-07-24) · FR066 exceptions queue live (S01)

## IMPLEMENTS  OD-23 · OD-27 (creation retirement) · OD-28 · OD-29 · FR068 · SR001 · 2.28 · FR066 (reuse)

## SCOPE CLASSIFICATION PLAN (read sets pre-stated)
| Table | Classification | Read set / justification |
|---|---|---|
| `registration_requests` (v2 — replaces the S06B design's table, REUSING its anonymous-write RLS) | **scoped** | Pre-account personal data about a child or guardian. INSERT: `public` context — the string appears in EXACTLY ONE policy platform-wide, structural assertion enforced. Read: system · admins of the ROUTED school · academy ops/audit (direct registrations: academy only). UPDATE (decision): the same reviewer set. The requester reads NOTHING — constant-shape 202 + opaque reference, no status endpoint |
| `held_links` | **scoped** | A form-claimed, unconfirmed relationship assertion — the most misleadable row in the system. Read: system · the approver set of the student's routing · ops/audit. Write: system only (created at approval, materialised or expired by jobs). **Materialises into a pending link ONLY when the counterpart address is VERIFIED (Leo 1a); carries origin "form-claimed — not confirmed by either party"; expires (default 90d, configurable) with expiry in queue-age reporting (Leo 1b)** |
| `guardian_links` (state addition) | already scoped | Gains `pending_approval` + the link-approval decision endpoint HERE (minimum needed for orphan pairs); the full 2.30 retrofit of S01 ceremonies is S04D. Policy amendment shipped with the migration |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Public registration forms + anonymous write.** Student and guardian variants, trilingual
   (2.28 Q0); school picker = opt-in listed partners OR "direct to the academy" (first-class, no
   free text); optional counterpart email; `public` scope context + confinement assertion +
   throttle/honeypot/fill-time + text-only caps — the S06B design, reused verbatim. VERIFY:
   anonymous read sweep zero everywhere; INSERT blind (no RETURNING); confinement assertion
   deliberate red then green; constant-shape probes (existing vs non-existing email, duplicate);
   429 paste.
2. **Approval → account creation + OD-29 verification.** Approve (routed reviewer) → account via
   the retained system primitive, born UNVERIFIED; 2.11 single-use verification link; login
   refused before verification; decline requires reason; both decisions audited with actor.
   VERIFY: approve → account + verification mail fixture → pre-verification login refusal paste →
   post-verification login paste; decline refusal without reason.
3. **Orphan pairs + held links (Leo 1a/1b).** Named counterpart with existing VERIFIED account →
   pending link into the queue at approval. Not-yet-registered counterpart → held link that
   materialises ONLY on the counterpart's verified approval; "form-claimed" origin marker; expiry
   job + audit. VERIFY: the TYPO SCENARIO pasted — counterpart address registered by an unrelated
   stranger: no link materialises before verification, and when it does materialise it carries the
   form-claimed marker, never a clean pending link; expiry fixture → expired + reported.
4. **The ONE queue + retirement.** Accounts and links in a single per-approver queue (2.28 Q4/Q5);
   every row shows age; combined item for account+link — TWO endpoints, TWO decisions, TWO audit
   rows (never one decision writing two rows); over-threshold age → FR066 exceptions entry
   (REUSED, not duplicated); Access & Identity report gains queue-age + registration funnel.
   **Retire guardian-creates-student (OD-27): endpoint removed, service entry removed, asSystem
   allowlist entry removed.** VERIFY: combined-item flow pastes both audit rows with their own
   timestamps; escalation fixture lands in FR066; retirement refusal pastes; elevation-list review
   shows the entry GONE; S01 suite migrated and green.

## NON-SCOPE
Pairing/email/vouch retrofit, teacher/school link states, OD-24, OD-30, bulk creation (S04D) ·
batch enrolment (S04E) · notification channels (S09 — fire events).

## KEY VERIFICATIONS
Five-branch per scoped table (registration_requests: routed-school admin sees · other-school admin
zero · academy sees direct · guardian/student/Member zero · anonymous zero; held_links likewise) ·
`--tag=S02A/S03/S04A` green each step · bundle budget + i18n parity green.

## AUDIT ELEMENT
Access & Identity report extensions: queue age by approver (a school not keeping up is visible to
the academy), registration funnel (submitted → approved → verified → linked), held-link ledger
(outstanding / materialised / expired).

## ASSERTIONS (--tag=S04C)
- `scope.public_context_confinement` — `public` in exactly one INSERT policy, nowhere else.
- `account.provenance` — every account traces to an approving decision, an accepted invitation, or
  school bulk creation (audit-backed); no other origin exists.
- `links.no_unverified_materialisation` — no pending link whose origin is a held link against an
  address that was unverified at materialisation time.
- `queue.escalation_liveness` — no request older than the threshold without an FR066 exception.
- `held_links.expiry` — none pending past expiry.

## EXIT GATE
Tests + `--tag=S04C` + all prior tags green + the typo-scenario paste + five-branch pastes +
retirement pastes + elevation review + AUDIT.md, gate commit.
