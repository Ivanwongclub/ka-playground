# PROPOSED S04D REVIEW — think-first reconciliation (no code)

> Planning artefact for Leo's eyes-on review **before STEP 1**. Nothing built; nothing committed
> beyond this document. Every claim checked against the shipped repo (file:line cited).
> Card under review: `docs/sprints/S04D/SPRINT.md` (Linkage approval, S01 retrofits & bulk creation, 2.30).

## Verdict in one paragraph

The card's **intent** is correctly 2.30 / OD-24 / OD-30 / OD-27, and it builds on S04C rather than
re-creating it. But it treats the "state machine across all three link tables" as **uniform**, and
that's the headline drift: only `guardian_links` has a real ceremony→`pending_approval` flow;
`school_links` and `teacher_links` are **admin-authority-activated** (registration approval, vouch,
invitation, bulk) — they reach `active` directly, by the school/academy's authority over its own
roll, and never got `pending_approval` (S04C added it to `guardian_links` only). There is also **one
concrete correctness gap** the plan must fix or its own assertion reds: `InvitationService::doAccept`
creates **active `teacher_links` with no `to_state='active'` audit** — the single un-audited activation
path in the system. Five drifts and three decisions are below. The four-step shape is sound.

## 1. Scope reconciliation — does the card build on what S04C shipped?

**Yes — it extends, it doesn't re-create.** S04C shipped the guardian-link state machine
(`pending_approval` + `LinkageService::approveLink`/`rejectLink`, `LinkageService.php:127`), the held-
link machinery, and the **minimal `school_links` affiliation** (D-i: an active row minted at
registration approval, `RegistrationApprovalService.php:102`). S04D layers the rich states on top.

**But the "state machine across all three tables" is asymmetric — the card over-states it:**

| Table | Shipped state | What activates it | S04D reality |
|---|---|---|---|
| `guardian_links` | has `pending_approval` (S04C) | ceremonies → `pending_approval` → `approveLink` | **full ceremony state machine** — the substantive S04D work |
| `school_links` | **8-value enum, NO `pending_approval`, NO `origin` col** (`create_invitations_and_link_tables.php:58-68`) | school AUTHORITY (D-i registration approval, vouch, bulk) → **active-direct + audit** | admin-authority-activated; **no Phase-1 ceremony produces a pending school link** (there is no student-requests-to-join-a-school flow) |
| `teacher_links` | **same — no `pending_approval`, no `origin`** (`:70-79`) | admin AUTHORITY (invitation, OD-60) → active-direct | admin-authority-activated; no Phase-1 teacher pending ceremony (invitation-only) |

So the honest scope is: **guardian ceremonies gate through `pending_approval` (the real work);
`school_links`/`teacher_links` get (a) the `pending_approval` enum value for forward-compat, (b) an
`origin` column they currently lack, (c) a mandatory activation audit, and (d) a hardened write policy
that refuses a non-system/non-admin direct-`active` insert** — but no new pending ceremony. The card's
uniform framing implies a three-way symmetric state machine; it's really guardian-centric plus a
policy/audit hardening on the other two.

**The genuine hardening (flag as a positive):** S04D STEP 1's "direct `active` insert refused at the
DB in every non-system context" closes the RLS-layer gap I flagged in S04C — the `guardian_links`
write policy still admits `guardian_id = actor`, so a guardian could in principle insert/update their
own `active` link at the DB layer (today only the controller `guardReviewer` blocks it; there's no
endpoint, but the policy allows it). S04D moves that guarantee into the database. Good.

## 2. FLAG #2 carries forward — but `links.activation_audited` is guardian-ONLY, and there's an un-audited path

**The assertion is NOT the universal backstop the card implies.**
`LinkActivationAuditedAssertion` checks **only `guardian_links`** (`entity_type='guardian_link'`,
`LinkActivationAuditedAssertion.php:42-51`, tagged `['S04C','S06']`). That is *correct* — it's the S06
`requires_all` consent dependency, which reads guardian links. It does **not** cover `school_links`
or `teacher_links`.

**The concrete gap (→ the #1 thing to fix): `InvitationService::doAccept` creates an active
`teacher_link` with NO `to_state='active'` audit** (`InvitationService.php:101-108`; the only audit is
`invitation.accepted`/`toState:'accepted'` at `:111-119`). It is the **single active-link path in the
system with no activation audit**. Every other path audits: `approveLink` (guardian, `:157`),
`PairingService::confirm` (guardian, `:133`), `schoolVouch` (guardian, `:134`), D-i `school_link`
(`RegistrationApprovalService.php:104`). So:

- The card's own **`links.no_active_without_approval`** (all three tables) is the right backstop for
  school/teacher — but the moment it ships, it **reds on every invitation-created `teacher_link`**
  unless `doAccept` is retrofitted to emit `teacher_link.created`/`to_state='active'` first (or in the
  same step). **This must be fixed in STEP 1, not discovered at the gate.**
- **Decision D-i-1:** keep `links.activation_audited` guardian-scoped (the S06 dep) and let
  `links.no_active_without_approval` be the all-three activation-audit backstop, **or** broaden
  `activation_audited` to all three tables? Broadening pulls school/teacher links into `--tag=S06`,
  which aren't consent deps — messier. **Recommend: keep them separate** (guardian = `activation_audited`
  = S06; all-three = `no_active_without_approval` = S04D), and fix `doAccept`'s missing audit regardless.

## 3. Teacher↔team vs teacher↔school — do NOT broaden the gate authority

Two deliberately-separate tables:
- **`teacher_links`** (teacher↔**school**, OD-60) — the affiliation S04D touches. Invited by a school/
  academy admin, single-school, offboarding-guarded while mentoring active teams.
- **`team_teacher_links`** (teacher↔**team**, OD-61, S05, `roles_and_tracker.php:26-36`) — the **Learn-
  gate approval authority**.

**The gate check is team-scoped and must stay that way.** `TrackerService::gateApproverKind`
(`TrackerService.php:100-105`) resolves a teacher's authority via
`team_teacher_links WHERE team_id=? AND teacher_id=? AND status='active'` — and the `stage_gates` RLS
policy gates teacher writes the same way (`roles_and_tracker.php:106`). **Guardrail for S04D:** the
teacher↔school `teacher_links` state machine must **not** confer gate-approval authority; a teacher
merely affiliated with a school cannot approve a team's gate — only a `team_teacher_links` row does.
The card doesn't mention `team_teacher_links`, so the risk is low, but STEP 1's policy amendments to
`teacher_links` must leave `team_teacher_links` and the gate check untouched. Also preserve OD-60's
offboarding guard (removing a `teacher_link` is blocked while the teacher mentors active teams — that
guard reads `team_teacher_links`).

## 4. Reconcile against S04C retirement, the holding state, and the S06 hardening

- **Retirement (S04C, OD-27):** guardian-creates-student is gone (`POST /my/students` 404,
  `GuardianStudentService` deleted). S04D does **not** resurrect it — it **transforms** the S01
  ceremonies (pairing, parent-initiated email) so they terminate in `pending_approval` instead of
  completing a link (OD-27 "Transformed"). Consistent.
- **Holding state (OD-28):** account state is derived from **active** links. S04D's `pending_approval`
  links do **not** flip a student to Active — only an approved (`active`) link does. So the ceremonies
  correctly keep a person Registered until the admin decision. Consistent.
- **S06 `requires_all` hardening:** reads "active-as-of-confirm" guardian links via `to_state='active'`
  audits. Every S04D guardian activation still audits `active`: the vouch (`schoolVouch`) does today;
  the retrofitted pairing/email flow moves the `active` audit from `PairingService::confirm`
  (which self-activates today, `:131`) to `approveLink` (which audits, `:157`). So the hardening stays
  fed — provided the retrofit routes activation through `approveLink` (the single audited gate), which
  is exactly what STEP 2 should do. `links.activation_audited` remains the loud backstop.

## 5. Anonymous surface? — none; the public context stays confined

S04D's ceremonies are **all authenticated**: pairing (student generates / guardian redeems, both
logged in), parent-initiated email (guardian), vouch (school admin), bulk (school admin). **There is
no new anonymous/public write.** The S04C `public` context must stay confined to the single
`registration_requests` INSERT policy — **`scope.public_context_confinement` will red if S04D adds any
policy referencing the public context.** Guardrail: S04D must not touch the public context; that
assertion is the structural tripwire and should stay green throughout.

## 6. Drift register

| # | Drift / gap | Severity | Disposition |
|---|---|---|---|
| **D1** | `school_links` + `teacher_links` have **no `pending_approval` state and no `origin` column** (`create_invitations_and_link_tables.php:58-79`). The card says they "gain `requested→pending_approval→active`" but omits the missing `origin` column (needed for vouch-origin-forever, ceremony provenance). | **high** | STEP 1 migration: extend both CHECK enums + **add `origin` to both tables**. |
| **D2** | `InvitationService::doAccept` creates **active `teacher_links` with no `to_state='active'` audit** — the one un-audited activation path. `links.no_active_without_approval` will red on it. | **high** | Fix in STEP 1 (emit `teacher_link.created`/`active`) *before/with* the assertion, + backfill existing. |
| **D3** | The three-table state machine is **asymmetric** — school/teacher are admin-authority-activated (no Phase-1 pending ceremony); the card's uniform framing over-states their work (§1). | med | Reword: guardian = ceremony state machine; school/teacher = enum + `origin` + audit + non-system-direct-active guard. |
| **D4** | Pairing/parent-initiated still produce **`pending_confirmation`** and pairing `confirm` **self-activates to `active`** (`PairingService.php:80,131`; `LinkController.php:67`). 2.30 wants a **two-stage** pending: ceremony → `pending_confirmation` (awaiting student) → student confirms → `pending_approval` (awaiting admin) → `approveLink` → `active`. | med | STEP 2 must make `confirm` land in `pending_approval` (not `active`) and route activation only through `approveLink`. |
| **D5** | **OD-24 visibility infra is NOT BUILT** — no `link_visibility_events`, no notification-record table, no reuse candidate (only `audit_events`, which is the audit trail, not a guardian-facing feed). | med | STEP 3 builds `link_visibility_events` greenfield (scoped; write=system; read=addressed guardian + ops/audit). |
| **D6** | **No shared account-creation primitive** — `User::create` is inlined in `RegistrationApprovalService::approve` (`:64`) and `InvitationService::doAccept` (`:90`). The card's "retained system primitive" for bulk is really the inline approve pattern. | med | STEP 4: extract a small elevated account-minting service (UNVERIFIED + activation token) that approve and bulk both call — or loop approve. Decision D-i-2. |
| **D7** | The **backfill** (`legacy-approved`) must not double-mark S04C links that already carry a real activation audit (guardian `approveLink`, D-i `school_link`). | low | `links.no_active_without_approval` accepts **either** an approving-decision audit **or** the `legacy-approved` marker; backfill only the audit-less (invitation `teacher_links`, any pre-existing). |

**No STOP-condition blocker.** No open OD gates a step (2.30/OD-24/OD-30/OD-27 all resolved); no
already-run migration is modified (S04D adds new migrations); the backfill writes new audit rows and
updates link rows (not immutable tables). The three decisions below are design rulings, not blockers.

## Decisions for Leo (before STEP 1)

**D-i-1 — assertion scope (the §2 choice). Recommended: keep separate + fix `doAccept`.**
Keep `links.activation_audited` guardian-scoped (S06 consent dep) and make
`links.no_active_without_approval` the all-three activation-audit backstop; retrofit
`InvitationService::doAccept` to emit the `teacher_link` `to_state='active'` audit so the new assertion
is green from the first run. (Alternative: broaden `activation_audited` to all three — but that pulls
school/teacher into `--tag=S06`, which aren't consent deps.)

**D-i-2 — the bulk creation primitive (§D6). Recommended: extract a shared service.**
Extract the UNVERIFIED-account + activation-token minting (today inline in `approve`) into one elevated
`AccountMintingService`, called by registration approval AND bulk. One place for the born-unverified
pattern; avoids duplicating the OD-29 mechanics. (Alternative: STEP 4 loops `approve`'s primitive.)

**D-i-3 — school/teacher state shape (§1/§D3). Recommended: forward-compat enum, no Phase-1 ceremony.**
Add `pending_approval` + `origin` to `school_links`/`teacher_links` for forward-compat and provenance,
add the mandatory activation audit, and harden the write policy to refuse non-system direct-`active`
— but ship **no** Phase-1 pending ceremony for them (they're admin-authority-activated: D-i, vouch,
invitation, bulk). Confirm this is the intended shape rather than inventing a student-joins-school flow.

## 7. Proposed step plan (keep the card's four; sharpen as marked)

**STEP 1 — The 2.30 state machine + the audit gap + policy hardening.**
Migration: extend `school_links`/`teacher_links` CHECK enums with `pending_approval` + **add `origin`
to both** (D1); backfill existing `active` links as `legacy-approved` with an audit **only where no
approving-decision audit exists** (D7). **Fix `InvitationService::doAccept` to emit
`teacher_link.created`/`to_state='active'` (D2).** Amend write policies so a non-system/non-admin actor
cannot insert/update a link to `active` (close the S04C RLS gap). Add `links.no_active_without_approval`
(all three tables; accepts approving-decision audit OR `legacy-approved`). *VERIFY:* direct-`active`
insert refused at the DB in every non-system context (all three tables); five-branch re-run on all
three; backfill audit paste; `doAccept` teacher_link now audits `active`; the new assertion red→green.

**STEP 2 — S01 ceremony retrofit (OD-27) — guardian only.**
Pairing and parent-initiated email: after student confirmation the link lands in **`pending_approval`**
(not `active`); queue rows carry their ceremony origin; activation happens **only** via `approveLink`
(the single audited gate). Retire pairing `confirm`'s self-activation (D4). *VERIFY:* full pairing flow
paste ending in `pending_approval`; approval → `active` + audit; rejection → `rejected` + reason;
`links.activation_audited` still green (the `active` audit now comes from `approveLink`).

**STEP 3 — School vouch (OD-30) + OD-24 visibility.**
Vouch = the vouching school admin's single audited act for students **on their roll only**; `vouch`
origin marked forever. Build `link_visibility_events` (greenfield, D5). **Every guardian-addition
activation — vouched included — writes a visibility record to ALL existing guardians of the student**;
non-vouch additional-guardian links additionally require the existing guardian's initiating action.
Assertions `links.guardian_addition_visibility` + `links.vouch_scope`. *VERIFY:* vouch paste (origin +
immediate activation + a visibility record per existing guardian); cross-school vouch refused;
second-guardian addition WITHOUT existing-guardian initiation refused; consent-evidence isolation intact.

**STEP 4 — Bulk student creation by schools (OD-23 pt 5).**
School admin creates students on their roll via the shared minting primitive (D-i-2; rows, not CSV —
batches are S04E); accounts born UNVERIFIED (OD-29 bulk clause); the school-student `school_links` are
created `active` by the creating admin's act (same OD-30 roll authority, audited `to_state='active'`).
*VERIFY:* bulk create paste; created accounts cannot log in before verification; per-school report shows
creations; `links.no_active_without_approval` green (bulk links carry the activation audit).

## Assertions (`--tag=S04D`) — the card's three, sharpened
`links.no_active_without_approval` (all three; approving-decision audit OR `legacy-approved`) ·
`links.guardian_addition_visibility` (OD-24; vouched included) · `links.vouch_scope` (student on the
voucher's roll at vouch time). S04C's assertions keep running; **`scope.public_context_confinement`
must stay green (no new public policy, §5)** and **`links.activation_audited` stays guardian-scoped**.

## What I did NOT do
No code, no migration, no schema, no commits beyond this file. No card edit — the drifts are *proposed*
corrections for your ruling. The three decisions (D-i-1…3) are yours before STEP 1.
