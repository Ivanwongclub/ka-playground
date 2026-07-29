# SPRINT KAP-S06 — Learning: activation, sessions, attendance, Learn gate, Member surfaces

## GOAL
Turn confirmed teams into a *running* programme: activate enrolments when the programme begins, give
sessions a real lifecycle (incl. reschedule), capture attendance, compute the Learn gate from it, and
— **new to this card** — stand up the Member surfaces (OD-22) the seed has been waiting on.

## PRECONDITIONS  S05 gate PASSED (`796e6df`).

## IMPLEMENTS
FR012 (tracker activation) · 2.3 · 2.24 · 2.6 · 2.5 · OD-12 (Learn gate) · **OD-22 / FR058 (Member
surfaces)** · 2.10 (register) · 2.21 extension · consent at-rest hardening (AUDIT.md S05 §8).

## RULINGS carried into this card (2026-07-28, Leo)
- **R3 — Activation = programme START DATE**, via a SYSTEM-actor scheduled job. **NOT 成團, NOT payment**
  (school-settled go Active on invoice, so activation must be payment-decoupled). "Active" = the
  programme is running. Fires at **max(programme_start, confirmed_at)** — a post-start late-joiner
  activates on confirm, never stuck in limbo. **Tracker locks until Active** = until the programme begins.
- **R2 — Learn gate = Option B (computed-eligible, teacher-confirmed).** The threshold (per-member %)
  is a HARD PRECONDITION to `approveGate('Learn')` — all five gates keep uniform OD-61 authority; the
  threshold gates the approval, it does not replace it. **0/0 (no sessions) → not-yet-assessable →
  approval refused;** publish pre-flight WARNS "Learn threshold set, no sessions" (mirrors capacity).
- **R1 — Learn threshold lives in `certification_rules`** (`attendance_threshold_pct` per-student,
  `team_gate_pass_pct` per-team). `stage_requirements` shell stays unused.
- **R-Member — the `member` ScopeContext branch is an `app.member` marker** (NOT link-scoped). Events
  are **network-wide reads**; `event_rsvps` are **per-member** — the RSVP policy must NOT inherit the
  events breadth. New tables (`events`, `event_rsvps`, member directory) MUST get `scope-map` entries.
- **D5 canonical casing:** anything S06 writes uses `'Learn'` (TitleCase, matching `TrackerService::STAGES`
  and `stage_gates.stage`); the lowercase `stage_requirements` CHECK is left untouched (shell unused).

## SCOPE (steps in this order; each = build + VERIFY + commit + stop; per-step eyes-on)
1. **Enrolment activation seam (R3) + tracker lock (FR012).** SYSTEM-actor scheduled job:
   `confirmed → active` for confirmed enrolments whose programme has started (basics `starts_on ≤ now`);
   late-joiners (confirmed after start) activate on the next run — no limbo. Tracker gate operations
   (`approveGate`) refuse until the programme is Active. Assertion `enrolments.activation_liveness`
   (no confirmed enrolment in a started programme left un-activated past the window).
2. **Session state machine (2.3)** Draft→Published→Full→In Progress→Completed/Cancelled/Rescheduled +
   **reschedule** (keeps bookings, writes `session_versions`, fires `session.rescheduled`, re-opens
   booking if capacity grew) + **clash check (2.24)** (clashes across retained bookings, count shown
   pre-confirm) + **mentor lifecycle (2.6)** (Departed blocked while future sessions exist). Attendance
   only in In Progress/Completed. Sessions bind to the **shipped** `enrolments`/`team_members` roster (D4).
3. **Bookings + session waitlist** (auto-promotion) + **attendance capture** (recorder identity;
   attendance ties booking → enrolment → `team_members`). **Deliberate state split (S06-3):**
   *booking* requires only a LIVE enrolment (`confirmed`/`active`) — a student may reserve a seat on a
   session published before the programme starts; *attendance* requires the session to be In Progress
   or Completed (never Draft/Published). Attendance is recorded ON the booking (`attended`/`no_show` +
   recorder), so `attendance == attended bookings` by construction.
4. **Learn-threshold computation (OD-12, R2) + the Learn gate + assessment lifecycle (2.5).**
   Per-student attendance ratio ≥ `attendance_threshold_pct` → qualifies; team roll-up ≥
   `team_gate_pass_pct` → **Learn-eligible**. Eligibility is a hard precondition to `approveGate('Learn')`;
   ineligible/0-of-0 → refused. Assessment results hidden until Released.
5. **Member surfaces (OD-22 / FR058).** `events` (network-wide, academy-published), `event_rsvps`
   (per-member), read-only members **directory** (`member_directory.view` = Member + academy only). The
   `member` ScopeContext branch (`app.member` marker). Five-branch scoping (§KEY VERIFICATIONS). New
   tables → `scope-map`. **No Member invitations until this ships** (OD-1/OD-22).
6. **`requires_all_guardians` at-rest hardening** (its own step, with teeth) — **STEP ONE of it:
   verify `guardian_link.created` / `guardian_link.revoked` audit events EXIST; if not, build them
   first** (scope note, not assumption). Then extend `teams.consent_complete_at_confirm` so a
   `requires_all` programme's every guardian active-as-of-confirm had a non-stale signature by confirm,
   judged by immutable link-lifecycle audit events. Plus **ladder liveness (2.10)** register and the
   **Withdrawal Cascade extension (2.21)** (future bookings cancelled, waitlist slots released).
7. **Gate.** Attendance & Session Report audit element · `--tag=S06` assertions · elevation review · AUDIT.md.

## NON-SCOPE
Notification channel *delivery* (S09 — S06 fires events) · recognition/badges (S08) · the
`stage_requirements` shell (R1, unused) · Logto role-rotation sync (S11).

## KEY VERIFICATIONS
- **Activation:** a confirmed enrolment in a not-yet-started programme is NOT active; after start (job
  run) it IS; a late-joiner (confirmed after start) activates on the next run. Tracker gate refused
  before Active, allowed after.
- **Learn gate (R2):** a team below `team_gate_pass_pct` (or 0-of-0) → `approveGate('Learn')` refused;
  once eligible, the teacher's approval succeeds (OD-61 authority unchanged).
- Reschedule onto a clashing time → flagged, count shown. · Mentor → Departed with a future session →
  blocked. · Attendance on a Draft session → rejected. · Assessment results invisible before Released.
- **Member five-branch:** Member sees directory + published events + own RSVP; Member denied
  enrolment/consent/money/student-data; a student/guardian/teacher denied the members directory; a
  Member never appears in a team/tracker/tenure. · Events network-wide, `event_rsvps` per-member.

## AUDIT ELEMENT
**Attendance & Session Report** — booked/attended/no-show with recorder identity · reschedule/
cancellation history with notification proof · waitlist promotion log · **Learn-eligibility status**
(per-member qualification + team roll-up vs thresholds).

## ASSERTIONS (--tag=S06)
`enrolments.activation_liveness` · attendance == attended bookings · no Published session in the past
without a terminal state · Learn-gate integrity (no `stage_gates` `'Learn'` pass for an ineligible
team) · ladder liveness (2.10) · cascade booking leg live · **consent_complete_at_confirm hardened**
(requires_all, at-rest). Each with red-then-green teeth.

## EXIT GATE  Tests + `--tag=S06` green + all prior tags green + one full reschedule walked in seed with
re-notification proof + Member five-branch paste. AUDIT.md, gate commit.
