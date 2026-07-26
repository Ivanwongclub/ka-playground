# Handoff Reconciliation Report — 2026-07-26

> Prepared for Leo's review before S04A STEP 1. Covers: the order of events, what was applied
> from `docs/handoff/`, what was held back and why, the unresolved conflict requiring a ruling,
> sprint-card impact, and the full text of the S04C/S04D/S04E cards as committed (`bf9cad9`).
> All applied changes are UNCOMMITTED pending this review (per the handoff README).

## 1. Order of events — confirmed

- 2026-07-24 — S04A card adjusted; S04C/S04D/S04E cards written and committed (`fb94e37`,
  `bf9cad9`). **None of these consulted the handoff decisions — they did not exist in the repo.**
- 2026-07-25 — handoff files authored in the sandbox (per their own dates).
- 2026-07-26 01:12 — `docs/handoff/` appears in the working tree (untracked).
- 2026-07-26 — handoff applied per its README: collisions renumbered, diffs left uncommitted.

## 2. What was applied

| Handoff file | Action taken |
|---|---|
| OD-APPEND.md | 36 rows appended to OPEN-DECISIONS as **OD-31..OD-66** (renumbered +6 — OD-25..30 were already taken by in-session decisions of 2026-07-24; internal cross-references shifted; original numbers preserved in the handoff file). Change-log row 14 added |
| REGISTER-EDITS.md | **FR201–FR228 appended** with OD citations shifted +6. FR200 HELD BACK (see §3). SR001 edit NOT applied as written (targets a line superseded twice; see §3) |
| CLAUDE-EDITS.md | EDIT 1 applied (payments row → MockProvider behind PaymentProvider; QFPay Phase 2 gated by merchant application). EDIT 2 applied (BI-9 narrowed to manually recorded payments, OD-47 cited, OD-14 fixed-SoD wording preserved). EDIT 4 applied in part (waitlist→pool and QFPay-scaffolding supersession notes added). EDIT 3 NOT applied (see §3) |
| BUILD-PLAN-EDITS.md | Revised sprint sequence appended as Part 5 with live OD numbers and card-name mapping (S06-BATCH ≈ S04E, S-SELFREG ≈ S04C/S04D, S-QFPAY = new card); QFPay merchant application recorded as a first-class launch dependency; four cross-cutting build notes carried |

## 3. Held back — the unresolved conflict (Leo to rule)

**The handoff and the later client model change disagree about onboarding.**

- Handoff (workflow review, 2026-07-25): school-routed self-registration REQUEST that
  **creates no account**; approval issues the standard guardian invitation; bulk import issues
  guardian invitations, never parent accounts; "students never self-create accounts; a guardian
  always anchors them" (handoff OD-44 → live OD-50, CLAUDE EDIT 3, REGISTER EDIT 1/FR200).
- Client model change (relayed by Leo in-session, recorded as the rewritten OD-23 + OD-27/28/29,
  committed `bf9cad9`): students and guardians self-register; **approval CREATES the account**;
  guardian-creates-student is retired; all linkage separately approved.

These cannot both be current. Held back accordingly: FR200, CLAUDE EDIT 3, the REGISTER SR001
edit text. Flagged in OPEN-DECISIONS (change-log row 14) and in BUILD-PLAN Part 5
(S04C/S04D marked BLOCKED). **Ruling needed: which onboarding model is current?**

Secondary notes, flagged not blocking: OD-48 (full fee / full refund only) supersedes OD-2's
provisional pro-rata bands (built in S02B; become unused data). OD-11's individual seat-hold
window loses its object under team-based capacity (OD-31) — its successor is the OD-43 payment
deadline from 成團.

## 4. Sprint-card impact

| Card | Status against OD-31..66 |
|---|---|
| S04A | **REWRITE required** — steps 3–5 assume individual seats (FOR UPDATE on the programme counter at enrolment, individual waitlist 2.18, hold-window seat release). Under OD-31/32/34: seats claim atomically PER TEAM at 成團; the waitlist is the awaiting-a-team pool. STEP 1 (consent-INSERT narrowing) is unaffected but nothing starts pre-review |
| S04B | **REWRITE required** — payment trigger (成團) moves to S05; MockProvider behind PaymentProvider lands here; BI-9 scoped to manual (OD-46/47); school-settled receivable model (OD-53/54) |
| S04C / S04D | **BLOCKED on the §3 onboarding ruling** |
| S04E | **RECONCILE** — CSV intake becomes the OD-51 config-driven, version-stamped Excel template; consolidated invoicing becomes the OD-53 receivable model (invoice at 成團, "covered by invoice" ≠ Paid) |
| S05 | REWRITE per BUILD-PLAN Part 5 (成團 wiring, waivers, teacher-team links) — card not yet adjusted, adjust before its sprint |
| New | S-QFPAY card to be written (Phase 2 pre-production, gated by merchant application) |

## 5. The three cards as committed (full text)

---

### docs/sprints/S04C/SPRINT.md

```markdown
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
```

---

### docs/sprints/S04D/SPRINT.md

```markdown
# SPRINT KAP-S04D — Linkage approval, S01 retrofits & bulk creation (2.30)

> New card per the approved 2026-07-24 re-plan. Runs AFTER S04C (which shipped the
> pending_approval state and the queue this card feeds).

## GOAL
Every relationship on the platform reaches `active` only through an admin's audited decision
(2.30); the S01 ceremonies survive as mutual-intent evidence but complete nothing; school
vouching is the model's one named single-actor exception (OD-30) and is never silent (OD-24);
schools can create their students in bulk.

## PRECONDITIONS
- [ ] S04C gate PASSED · OD-24 rule confirmed in force · OD-30 recorded (done 2026-07-24)

## IMPLEMENTS  2.30 · OD-23 (point 5, 6) · OD-24 · OD-27 (flow transformation) · OD-30 · FR003 · FR005

## SCOPE CLASSIFICATION PLAN
| Table | Classification | Read set / justification |
|---|---|---|
| `guardian_links` / `teacher_links` / `school_links` (state machine) | already scoped | Gain `requested → pending_approval → active \| rejected` (guardian_links partially done in S04C). Existing ACTIVE links are backfilled `legacy-approved` in the migration, audited — the assertion below must not fire on history. Read sets unchanged; write policies amended so ONLY the approval decision (or system) activates |
| `link_visibility_events` (or notification-event reuse) | **scoped** | OD-24: every guardian-addition activation (INCLUDING vouched, OD-30) produces a visibility record addressed to EVERY existing guardian of the student. Read: system · the addressed guardian · ops/audit. Write: system. S09 delivers; the RECORD exists now — "never silent" must be assertable before channels exist |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **2.30 state machine across all three link tables** + backfill migration (`legacy-approved`,
   audited) + policy amendments. VERIFY: direct `active` insert refused at the DB in every
   non-system context; five-branch re-run on all three tables; backfill audit paste.
2. **S01 ceremony retrofit (OD-27):** pairing codes and parent-initiated email flow produce
   `pending_approval` after student confirmation — never `active`; queue rows appear with their
   ceremony origin. VERIFY: full pairing flow paste ending in pending_approval; approval →
   active + audit; rejection → rejected + audited reason.
3. **School vouch (OD-30) + OD-24.** Vouch = the vouching school admin's single audited act
   (initiation + approval) for students on their roll ONLY; vouch origin marked forever;
   **every guardian-addition activation — vouched included — writes visibility records to ALL
   existing guardians (OD-24, never silent)**; additional-guardian ceremonies require the
   existing guardian's initiating action. VERIFY: vouch paste showing origin + immediate
   activation + visibility records to each existing guardian; cross-school vouch refused;
   second-guardian addition WITHOUT existing-guardian initiation refused (paste).
4. **Bulk student creation by schools (OD-23 point 5).** School admin creates students on their
   roll via the retained system primitive (rows, not CSV ceremony — batches are S04E); accounts
   born unverified, invitation-verification per OD-29's bulk clause; school-student links created
   `active` by the creating school admin's act (their roll, their authority — same OD-30 basis,
   same audit). VERIFY: bulk create paste; created accounts cannot log in before verification;
   per-school report shows creations.

## NON-SCOPE
CSV batch intake, per-row states, batch dashboard, consolidated invoicing (S04E) · registration
forms/queue mechanics (S04C, done) · notification delivery (S09 — the records exist, delivery
follows).

## KEY VERIFICATIONS
Five-branch per touched table after every policy amendment · OD-24 visibility paste is the one
that matters most: a vouched second guardian appears to the FIRST guardian's session (their own
visibility record) while consent evidence isolation stays intact · all prior tags green each step.

## AUDIT ELEMENT
Linkage Approval Report — pending by school with age; ceremony-origin breakdown (pairing / email /
vouch / registration form / bulk); **per-school vouch usage (OD-30 exception visible to the
academy)**; OD-24 visibility coverage.

## ASSERTIONS (--tag=S04D)
- `links.no_active_without_approval` — every active link carries an approving decision or the
  audited legacy-approved backfill marker; no third path.
- `links.guardian_addition_visibility` — every 2nd+ guardian activation has visibility records
  for every guardian active at activation time (OD-24; vouched links included).
- `links.vouch_scope` — every vouched link's student was on the voucher's school roll at vouch
  time.
- S04C's assertions keep running; S01 guardian-coverage now exercises the new states.

## EXIT GATE
Tests + `--tag=S04D` + all prior tags green + the vouch/visibility pastes + five-branch pastes +
AUDIT.md (record OD-30 usage baseline), gate commit.
```

---

### docs/sprints/S04E/SPRINT.md

```markdown
# SPRINT KAP-S04E — Bulk enrolment (Spec Part H)

> New card per the approved 2026-07-24 re-plan (Leo change 2: Part H gets its own card, not a
> tail on S04D). Runs AFTER S04D. Position rationale: consolidated invoicing needs S04B's orders
> and receipts; batch rows need S04D's bulk-created students; batch enrolment needs S04A's seat
> and consent machinery. S04E is where all three meet, immediately before S05 teams consume the
> resulting enrolments.

## GOAL
A school administrator enrols a cohort in one auditable batch: CSV in, per-row outcomes out,
seats and consent and orders behaving exactly as they do for a single enrolment — and one
consolidated invoice to the school when the school is the payer (OD-25).

## PRECONDITIONS
- [ ] S04D gate PASSED · OD-25 recorded (school = payer, never collector) · client fee-terms
  answer applied in S04A step 6 (its outcome shapes the consolidated invoice's read set)

## IMPLEMENTS  Spec Part H (H1–H4) · 2.7/2.8/2.18 (per row, via S04A machinery) · OD-25 · OD-18 · FR066 (exceptions reuse)

## SCOPE CLASSIFICATION PLAN
| Table | Classification | Read set / justification |
|---|---|---|
| `enrolment_batches` | **scoped** | A school's cohort operation. Read: system · the owning school's admins · academy ops/finance/audit. Write: system (state machine H2: Draft → Validating → Ready → Committing → Complete \| Failed \| Partially Complete) |
| `enrolment_batch_rows` | **scoped** | Per-row child data (H3: Pending → Validated → Enrolled \| Skipped(reason) \| Failed(reason)). Same read set as the batch. Write: system. Row outcomes NEVER silently dropped — every non-Enrolled row carries its reason (P4) |
| `consolidated_invoices` | **scoped** | Money document addressed to a school (payer_party = school, OD-25 — the school PAYS, never collects). Read: system · finance/audit · the addressed school's admins. Write: system. OD-18 minor units + currency; lines snapshot per enrolment order |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Batch intake + validation (H1).** CSV upload through the S00 upload service (BI-10 — scanned
   before parsing, new `batch-csv` context, hard caps); server-side validation to Validated/
   Skipped per row (existing student matched by school roll, new student → S04D bulk creation
   path); dry-run report before commit. VERIFY: hostile CSV (formula injection, oversize,
   wrong-type) refused pastes; dry-run paste.
2. **Batch commit (H2/H3).** Row-by-row through the REAL S04A machinery — seat lock (2.7),
   idempotency (2.8), waitlist on full (2.18), consent issuance job per enrolment; batch is
   resumable, never half-silent; failures → per-row reasons + FR066 exception on batch failure.
   VERIFY: batch spanning capacity boundary — some Enrolled, overflow Waiting, reasons pasted;
   re-commit idempotent (no duplicate enrolments) paste.
3. **Batch dashboard (H4) + consolidated invoicing (OD-25).** School admin sees Active |
   Complete | Exceptions with per-row drill-down (2.28/Q4 3.4); when payer_party = school, one
   consolidated invoice aggregates the batch's orders (OD-18 fields; academy is the recipient of
   funds, always); guardian-payer rows bill individually as S04A built. VERIFY: invoice totals
   equal the sum of member order lines (paste); OD-18 schema paste; school admin of ANOTHER
   school sees zero (five-branch).

## NON-SCOPE
Payment recording against consolidated invoices (S04B machinery consumes them; if S04B gated
before this card, wire-up only — no new payment paths) · teams (S05) · any linkage flow (S04D).

## KEY VERIFICATIONS
Five-branch per scoped table · batch of N produces exactly N audited outcomes (no silent rows) ·
consent issuance fired per enrolled row (`consent.issuance_completeness` goes non-vacuous at
volume here) · all prior tags green each step.

## AUDIT ELEMENT (Financial Integrity Report, part 1b)
Batch ledger — batches by school/status/age; per-row outcome distribution; consolidated invoice
register with order-line reconciliation.

## ASSERTIONS (--tag=S04E)
- `batches.row_conservation` — every committed batch's rows sum to Enrolled + Skipped + Failed +
  Waiting, each non-Enrolled with a reason.
- `invoices.line_reconciliation` — every consolidated invoice total equals the sum of its member
  order lines (integer minor units, same currency).
- `batches.no_stuck` — no batch in Validating/Committing older than its job-timeout window.

## EXIT GATE
Tests + `--tag=S04E` + all prior tags green + capacity-boundary batch paste + invoice
reconciliation paste + five-branch pastes + AUDIT.md, gate commit.
```

