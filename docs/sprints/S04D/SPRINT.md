# SPRINT KAP-S04D — Linkage approval, S01 retrofits & bulk creation (2.30)

> New card per the approved 2026-07-24 re-plan. Runs AFTER S04C (which shipped the
> pending_approval state and the queue this card feeds).
> **RECONCILED 2026-07-31** against what actually shipped (S04C etc.), per
> `docs/sprints/S04D/PROPOSED-S04D-REVIEW.md` and Leo's three rulings + the gate-authority guardrail:
> **D-i-1** — assertions stay SEPARATE: `links.activation_audited` guardian-only (`--tag=S06`, the
> consent dep); `links.no_active_without_approval` is the all-three S04D backstop. Fix the single
> un-audited activation path (`InvitationService::doAccept` → active teacher_links, no audit) in STEP 1,
> same step as the assertion, so it is green from first run.
> **D-i-2** — extract `AccountMintingService`: the born-unverified + activation-token pattern (inlined
> in `approve` AND `doAccept`) lives in ONE place; approve, invitation-accept and bulk all call it.
> **D-i-3** — the state machine is **guardian-centric**. `school_links`/`teacher_links` are
> admin-authority-activated (D-i / vouch / invitation / bulk); they get the `pending_approval` enum +
> `origin` column for forward-compat/provenance, the mandatory activation audit, and a write-policy
> hardening — but **NO Phase-1 pending ceremony**.

## GOAL
Every relationship reaches `active` only through an admin's audited decision (2.30) — the guardian
ceremonies (pairing, parent-initiated email) now gate through `pending_approval`; `school_links`/
`teacher_links` are activated by the school/academy's authority over its own roll (registration
approval, vouch, invitation, bulk), audited, never by a stray direct-`active` write. The S01
ceremonies survive as mutual-intent evidence but complete nothing; school vouching is the model's one
named single-actor exception (OD-30) and is never silent (OD-24); schools can create their students in
bulk. **The credential-only-at-activation pattern lives in ONE service.**

## PRECONDITIONS
- [ ] S04C gate PASSED · OD-24 rule confirmed in force · OD-30 recorded (done 2026-07-24)

## IMPLEMENTS  2.30 · OD-23 (point 5, 6) · OD-24 · OD-27 (flow transformation) · OD-30 · FR003 · FR005

## SCOPE CLASSIFICATION PLAN
| Table / service | Classification | Read set / justification |
|---|---|---|
| `guardian_links` (state machine — **the real work**) | already scoped | Has `pending_approval` (S04C). S04D routes the S01 ceremonies through it and hardens its write policy so ONLY the approval decision (or system) activates. |
| `school_links` / `teacher_links` (forward-compat + hardening — **NOT a symmetric machine**) | already scoped | **Gain `pending_approval` in the CHECK enum AND a new `origin` column (neither exists today).** Activated by ADMIN AUTHORITY (D-i / vouch / invitation / bulk) → `active`-direct + a mandatory `to_state='active'` audit. Backfill existing `active` rows `legacy-approved` (audited) **only where no approving-decision audit already exists** (S04C's D-i school_links already carry one). Write policy hardened: a non-system/non-admin actor cannot insert/update to `active`. **No Phase-1 pending ceremony** (there is no student-requests-to-join-a-school flow). |
| `AccountMintingService` (**extract, D-i-2**) | n/a (service) | The born-UNVERIFIED account + activation-token pattern, today inlined in `RegistrationApprovalService::approve` and `InvitationService::doAccept`. Extract ONCE; approve, invitation-accept and bulk all call it, so the security-critical credential-only-at-activation logic cannot diverge. |
| `link_visibility_events` (**greenfield — no infra to reuse**) | **scoped** | OD-24: every guardian-addition activation (INCLUDING vouched, OD-30) produces a visibility record addressed to EVERY existing guardian of the student. Read: system · the addressed guardian · ops/audit. Write: system. S09 delivers; the RECORD exists now — "never silent" must be assertable before channels exist. No notification-record table exists today; this is built from scratch. |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **2.30 enum + provenance + the audit gap + the all-three assertion.** (The write-policy hardening
   originally carded here is **DEFERRED to STEP 3** — build finding 2026-07-31, Leo's ruling: it breaks
   two SANCTIONED paths that write `active` in a NON-system context — `PairingService::confirm` (fixed
   by STEP 2's retrofit) and `LinkController::schoolVouch` (fixed by STEP 3's elevation). Harden once,
   after every path is system-context.) Migration: extend `school_links`/`teacher_links` CHECK enums
   with `pending_approval` **and add an `origin` column to both**; backfill existing `active` links
   `legacy-approved` (audited, keyed on the real `created_at`) **only where no approving-decision audit
   exists**. **Fix `InvitationService::doAccept` — it creates active `teacher_links` with NO activation
   audit (the single un-audited path); emit `teacher_link.created` / `to_state='active'` (origin
   `invitation`) AND the backfill covers the existing invitation-created teacher_links, same step.**
   Extract `AccountMintingService` (D-i-2), used by `approve` + `doAccept` (behaviour-preserving).
   Add `links.no_active_without_approval` (all three tables; approving-decision audit OR `legacy-approved`).
   **GATE-AUTHORITY GUARDRAIL:** confirm S04D's `teacher_links` (teacher↔SCHOOL) stays distinct from
   S05's `team_teacher_links` (teacher↔TEAM); the Learn-gate authority check (`TrackerService::
   gateApproverKind`, reads `team_teacher_links` only) is UNCHANGED — a school-linked teacher gains NO
   approve-any-team authority; OD-60's offboarding guard preserved.
   VERIFY: the S04C approval/activation tests stay green after the extraction (regression surface —
   `approve`/`doAccept` behave exactly as before, via the shared service); five-branch re-run on all
   three; backfill audit paste (keyed on real creation); `doAccept` now audits `teacher_link` `active`;
   the gate check reads `team_teacher_links` (paste); `links.no_active_without_approval` red→green.
2. **S01 ceremony retrofit (OD-27) — guardian only.** Pairing codes and parent-initiated email produce
   `pending_approval` AFTER student confirmation (a two-stage pending: ceremony → `pending_confirmation`
   awaiting student → confirm → `pending_approval` awaiting admin) — **never `active`**; retire pairing
   `confirm`'s self-activation; activation happens ONLY via `approveLink` (the single audited gate);
   queue rows appear with their ceremony origin. VERIFY: full pairing flow paste ending in
   `pending_approval`; approval → `active` + audit (now from `approveLink`); rejection → `rejected` +
   audited reason; `links.activation_audited` (guardian) stays green.
3. **School vouch (OD-30) + OD-24 + the deferred write-policy hardening.** Vouch = the vouching school
   admin's single audited act (initiation + approval) for students on their roll ONLY — **move its
   active `guardian_link` write INTO the elevation (today it writes active in the school_admin's
   non-system context)**; `vouch` origin marked forever; build `link_visibility_events` (greenfield);
   **every guardian-addition activation — vouched included — writes visibility records to ALL existing
   guardians (OD-24, never silent)**. **A non-vouch SECOND-guardian SELF-add is REFUSED** (Leo ruling
   2026-07-31: the existing-guardian-initiates-co-guardian ceremony is DEFERRED; VOUCH is the Phase-1
   path to add a further guardian, and it carries the OD-24 visibility). **Now that confirm (STEP 2)
   and vouch both write `active` only in
   system context, apply the WRITE-POLICY HARDENING: a non-system/non-admin actor cannot insert/update
   any of the three link tables to `active` — build check: REFUSE stray direct-`active`, PERMIT the
   sanctioned elevated paths (`approveLink`, D-i `approve`, `schoolVouch`, invitation, bulk).** VERIFY:
   vouch paste (origin + immediate activation + a visibility record per existing guardian); cross-school
   vouch refused; second-guardian addition WITHOUT existing-guardian initiation refused; consent
   evidence isolation intact; **direct-`active` insert refused at the DB in every non-system context on
   all three tables, while every sanctioned elevated path still activates (paste)**.
4. **Bulk student creation by schools (OD-23 point 5).** School admin creates students on their roll via
   **`AccountMintingService`** (rows, not CSV ceremony — batches are S04E); accounts born unverified,
   invitation-verification per OD-29's bulk clause; school-student `school_links` created `active` by
   the creating school admin's act (their roll, their authority — same OD-30 basis, audited
   `to_state='active'`). VERIFY: bulk create paste; created accounts cannot log in before verification;
   per-school report shows creations; `links.no_active_without_approval` green (bulk links audited).

## NON-SCOPE
CSV batch intake, per-row states, batch dashboard, consolidated invoicing (S04E) · registration
forms/queue mechanics (S04C, done) · notification delivery (S09 — the records exist, delivery follows) ·
**a student-requests-to-join-a-school ceremony (school/teacher links stay admin-authority-activated,
D-i-3)** · **the existing-guardian-initiates-co-guardian ceremony (deferred, Leo 2026-07-31 — vouch is
the Phase-1 second-guardian path; STEP 3 only REFUSES a non-vouch second-guardian self-add)** · any
change to `team_teacher_links` or the Learn-gate authority (S05, guardrail above).

## KEY VERIFICATIONS
Five-branch per touched table after every policy amendment · **the write hardening refuses stray
direct-`active` yet permits every sanctioned system-context path (STEP 1)** · **the gate-authority
guardrail: the Learn-gate check still reads `team_teacher_links` only (STEP 1 paste)** · **`doAccept`
teacher_link activation now audited (STEP 1)** · OD-24 visibility paste is the one that matters most: a
vouched second guardian appears to the FIRST guardian's session (their own visibility record) while
consent evidence isolation stays intact · **`scope.public_context_confinement` stays green — S04D adds
NO public policy (all ceremonies authenticated)** · all prior tags green each step.

## AUDIT ELEMENT
Linkage Approval Report — pending by school with age; ceremony-origin breakdown (pairing / email /
vouch / registration form / bulk); **per-school vouch usage (OD-30 exception visible to the academy)**;
OD-24 visibility coverage.

## ASSERTIONS (--tag=S04D)
- `links.no_active_without_approval` (**all three tables** — the S04D provenance backstop) — every
  active link carries an approving decision OR the audited `legacy-approved` backfill marker; no third
  path.
- `links.guardian_addition_visibility` — every 2nd+ guardian activation has visibility records for
  every guardian active at activation time (OD-24; vouched links included).
- `links.vouch_scope` — every vouched link's student was on the voucher's school roll at vouch time.
- **`links.activation_audited` stays guardian-only / `--tag=S06`** (the consent dependency — NOT
  broadened to school/teacher; the tag reflects WHY it exists). S04C's assertions keep running;
  **`scope.public_context_confinement` must stay green** (no new public policy); S01 guardian-coverage
  now exercises the new states.

## EXIT GATE
Tests + `--tag=S04D` + all prior tags green + the vouch/visibility pastes + five-branch pastes + the
`doAccept`-now-audited paste + the gate-authority-guardrail paste (`TrackerService` reads
`team_teacher_links`) + AUDIT.md (record OD-30 usage baseline, the D-i-1/2/3 rulings, and the honest
deviation record), gate commit.
