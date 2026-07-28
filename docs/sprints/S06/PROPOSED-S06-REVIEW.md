# PROPOSED S06 REVIEW — think-first reconciliation (no code)

**Status:** planning artefact for Leo's review before S06 STEP 1. Build nothing until this is ratified.
**Date:** 2026-07-28 · **HEAD:** S05 gate `796e6df` · **Method:** reconcile the S06 card against what S04A/S05 actually shipped, flag every drift, propose the step plan.

---

## 0. TL;DR — the card is materially out of date

The S06 card (`SPRINT.md`, "Learning: sessions, bookings, attendance") was written before S05 and **before the OD-22 card edit that was mandated but never applied**. It is missing two whole workstreams you've asked S06 to carry, and it assumes a tracker-activation seam that has no trigger. Five drifts (D1–D5) and three ruling-needed items (R1–R3) below. Everything the card DOES list (sessions/bookings/attendance/assessment/mentor) is net-new and sound — no table for any of it exists yet.

---

## 1. Scope reconciliation — card vs what S04A/S05 built

### 1a. What exists to build on (from the surface map)
- `stage_gates` + `TrackerService::STAGES = ['Plan','Design','Learn','Pitch','Launch']` (S05) — the Learn gate is stage index 2, currently **manually** approved (`TrackerService::approveGate`, OD-61 authority).
- `certification_rules` (S02B) — **the live OD-12 threshold config already exists**: `attendance_threshold_pct` (default 70, per-student) and `team_gate_pass_pct` (default 60, per-team), written via `PUT /admin/programmes/{id}/certification-rules`.
- `team_members` / `teams` / `tenures` (S05) — attendance roll-up and Learn qualification are **per team member**; the roster is here.
- `enrolments` state machine — `confirmed → active → completed`; but **`active` and `completed` are declared-but-unused edges: nothing in the codebase transitions into them** (`EnrolmentService.php:27-30`, zero callers).
- `member` permission set (`config/permission-matrix.php:61-63`: `events.view`, `events.rsvp`, `member_directory.view`) — denials enforced; **no endpoints, no scope branch, no data**.

### 1b. Net-new (nothing exists — S06 is first to build)
`sessions`, `attendance`, `assessment(s)`, `events`, `event_rsvps`, member directory, mentor lifecycle, and all Member-role routes/controllers/scope handling. Also: `stage_requirements` exists but is an **empty shell** (defined S02B, zero writers/readers) — S06 should NOT build the Learn threshold on it; the live config is `certification_rules` (see R1).

### 1c. DRIFT

| # | Drift | Card says | Reality after S05 | Fix |
|---|-------|-----------|-------------------|-----|
| **D1** | **Member surfaces (OD-22) missing from the card** | `SPRINT.md` IMPLEMENTS = 2.3·2.5·2.6·2.24·2.10·2.21; **no OD-22, no FR058, no events/RSVP/directory** | OD-22 (change-log #8, re-confirmed #17) resolved that Member surfaces land in S06 and that **"S06 card edit required before S06 starts"** — never done | **Add Member surfaces to the card** (event list, RSVP, read-only directory) as a first-class workstream. No Member invitations until it ships (OD-1/OD-22). |
| **D2** | **Learn gate: manual vs computed** | card item 4: "Learn-threshold computation (per-student + team roll-up)" | S05 built Learn as one of five **manually-approved** stage gates (`approveGate`); OD-12 says the Learn team gate "passes when a **configurable %** of members qualify" — a **computed** gate | Reconcile (R2): the Learn gate is special. Decide whether computation AUTO-passes it or gates a teacher's approval. |
| **D3** | **Tracker activation has no seam** | FR012: "tracker locked until enrolment **Active**" (card implies attendance/tracker keyed on Active) | `confirmed → active` transition has **zero callers**; nothing activates an enrolment | Ruling R3: define the activation trigger (payment paid? programme start? admin?), build it, then gate the tracker on it. |
| **D4** | **Card written pre-S05 — ties not referenced as shipped** | "attendance capture", "assessment lifecycle" as abstract | Attendance/assessment/Learn roll-up must reference the **shipped** `team_members` roster + `enrolments.active` + `certification_rules`, not an imagined model | Card language update: bind these to S05 artefacts. |
| **D5** | **Stage-name case mismatch** | — | `TrackerService::STAGES` uses `'Learn'` (TitleCase); `stage_requirements` CHECK enforces lowercase `'learn'`; `stage_gates.stage` stores `'Learn'` | Pick one canonical form for anything S06 writes; the Learn computation must write the same casing `stage_gates` uses (`'Learn'`). |

---

## 2. The Learn threshold (OD-12) — where the %, how completion, what gate

**Where the % comes from — RESOLVED, it exists:** `certification_rules` (per programme, editable post-creation):
- `attendance_threshold_pct` (default 70) — **per-student**: a student "qualifies" for Learn when their attended-session ratio ≥ this.
- `team_gate_pass_pct` (default 60) — **per-team**: the Learn team gate passes when ≥ this % of the team's active members qualify.

This is **not** the capacity situation — the config field is live and wired to an endpoint. What's **missing** is the **computation and the gate wiring**: nothing reads these to measure completion or drive a gate.

**How completion is measured (proposed):** per student, `attended / eligible_sessions ≥ attendance_threshold_pct`. "Eligible sessions" = the sessions counted toward Learn (per R2 — all programme sessions? a Learn-tagged subset?). Per team, `qualifying_members / active_members ≥ team_gate_pass_pct`.

**The gate it drives:** the **Learn stage gate** (`stage_gates`, stage=`'Learn'`). Here is the D2 tension made concrete — **R2 ruling needed**:
- **Option A (computed auto-pass):** when the team roll-up crosses `team_gate_pass_pct`, the SYSTEM records the Learn gate pass (system-actor). Learn becomes the ONE computed gate; the other four stay manual (OD-61). Clean OD-12 reading, but Learn no longer flows through the teacher's `approveGate`.
- **Option B (computed-eligible, teacher-confirmed):** computation marks the team "Learn-eligible"; the teacher/school-admin still approves the gate (OD-61 authority preserved for all five). Eligibility is a precondition to `approveGate('Learn', …)` — approving an ineligible team is refused.
- **Option C (both surfaced):** record the computed status; allow either auto-pass or manual, per a programme flag.
**Recommendation: Option B** — it keeps OD-61's uniform gate-approval authority (which the S05 gate just verified five-branch), makes the threshold a hard precondition rather than a silent auto-action, and still honours OD-12 ("passes when % qualify" = eligible-when-%-qualify). Flag for your call.

**Unconfigured-like-capacity check:** the threshold itself is configured (good), BUT `attendance_threshold_pct` has a **default of 70 that is never validated at publish** (unlike capacity's `>0 / ≥ min team size` pre-flight). If a programme has no Learn-relevant sessions, the ratio is 0/0 — **undefined**. R2 must define the 0/0 case (vacuously qualified? or not-yet-assessable?), and pre-flight should surface "Learn threshold set but no sessions" as a warning, mirroring the capacity pre-flight.

---

## 3. Member surfaces (OD-22 / FR058) — reads and five-branch scoping

**Today:** the `member` role has permissions (`events.view`, `events.rsvp`, `member_directory.view`) but **no scope branch in `ScopeContext::set()`** — a Member resolves to empty `school_ids`/`student_ids`/`capabilities`, so under RLS a Member currently matches **nothing** (fail-closed). There are no events/rsvp/directory tables, routes, or controllers.

**What each surface reads (proposed, net-new):**
- **Event list** — a new `events` table (academy-published events: title trilingual, starts_at, location, capacity?). Members read **published** events (all members see all published events — events are network-wide, not link-scoped).
- **RSVP** — a new `event_rsvps` table (event_id, member_id, status). A Member reads/writes **only their own** RSVP; the organiser (academy) reads all.
- **Directory** — the **first-generation Kings Network members** directory. Reads from `users WHERE role='member'` (+ a directory profile?). Scoping: Members see other Members (network-wide), **never** students/guardians/teachers. `member_directory.view` is Member + academy_admin only (already guarded by the `authz.member_directory_exclusive` nightly assertion).

**The five-branch scoping (the S06 verification):** a Member must see the directory + events, and be **denied** everything else:
1. Member sees the members directory (other members) → allowed.
2. Member sees published events + manages their own RSVP → allowed.
3. Member reading enrolments / consent / orders / student data → **denied** (empty scope + no permission; the absence IS the control, `permission-matrix.php:59-60`).
4. A student/guardian/teacher reading the members directory → **denied** (`member_directory.view` is Member+academy only).
5. Member appearing in / reading a team, tracker, or tenure → **denied** (Members never enrol; no team membership).

**Scope decision needed (folds into R-Member):** define the `member` branch in `ScopeContext` — likely `app.member = true` (or a member-directory membership marker) so the directory/events RLS can admit "any authenticated member" without leaking link-scoped tables. New tables (`events`, `event_rsvps`, directory) **must be added to `config/scope-map.php`** or the nightly scope-coverage assertion fails.

---

## 4. `requires_all_guardians` at-rest hardening (AUDIT.md §8 leftover — fold in here)

**Today:** `consent_complete_at_confirm` (S05 gate) checks that each confirmed enrolment had **≥1** non-stale signature by confirm time. The **all-guardians** nuance is enforced only LIVE at 成團 (`consentSatisfied`: for `requires_all_guardians`, every active `guardian_link` must be among the signed signers). The at-rest backstop does not reproduce it — a programme with `requires_all_guardians` could, in principle, have a confirmed enrolment where only one of two guardians signed, and the assertion would stay green.

**Proposed S06 hardening (mirrors the supersede fix):** extend `consent_complete_at_confirm` so that for `requires_all_guardians` programmes, EVERY guardian **active as of the confirm event** had a non-stale signature by then. "Active as of confirm" is judged by **immutable facts**, not live `guardian_links`:
- a guardian counts if their `guardian_link` was created (audit `guardian_link.created` / link `created_at`) `≤ confirm` AND not revoked before confirm (no `guardian_link.revoked` audit `≤ confirm`).
This is the same past-facts discipline as the supersede timestamp. It needs the guardian-link lifecycle audit events to exist and be reliable (**verify in STEP: do `guardian_link.created/revoked` audit events exist?** — if not, this hardening needs them first, which is a scope note). Recommend landing this as a dedicated step with red-then-green teeth (a `requires_all` programme, two guardians, only one signed before confirm → red).

---

## 5. Proposed step plan (each = build + VERIFY + commit + stop; per-step eyes-on)

Ordered so prerequisites (activation, sessions) precede consumers (attendance, Learn):

| Step | Scope | Notes / dependencies |
|------|-------|----------------------|
| **S06-1** | **Enrolment activation seam (R3)** + tracker activation gate (FR012) | Resolves D3. Build the `confirmed → active` trigger (per R3 ruling); gate tracker/attendance on `active`. Small but unblocks everything. |
| **S06-2** | **Session state machine (2.3)** + reschedule + `session_version` + **clash check (2.24)** + mentor lifecycle (2.6) | The card's core. New `sessions`, `session_versions`; `session.rescheduled` event; mentor Departed-blocked-while-future-sessions. |
| **S06-3** | **Bookings + session waitlist** (auto-promotion) + **attendance capture** | Attendance only in In Progress/Completed; attendance ties to booking→enrolment→`team_members`. |
| **S06-4** | **Learn-threshold computation (OD-12) + the Learn gate (R2)** + **assessment lifecycle (2.5)** | Per-student attendance ratio → team roll-up → Learn gate (Option B recommended). Assessment results hidden until Released. Reconciles D2/D5. |
| **S06-5** | **Member surfaces (OD-22)** — events + RSVP + read-only directory + `member` scope branch | Resolves D1. Five-branch scoping (§3). New tables → scope-map. |
| **S06-6** | **`requires_all_guardians` at-rest hardening** (§4) + **ladder liveness (2.10)** register + **Withdrawal Cascade extension (2.21)** (future bookings cancelled, waitlist released) | The consent backstop + the two register/cascade items. |
| **S06-7** | **Gate** — Attendance & Session Report audit element + `--tag=S06` assertions (attendance == attended bookings; no past Published session without a terminal state; ladder liveness; cascade booking leg) + elevation review + AUDIT.md | Run together, like S05. |

**Step-boundary notes:**
- S06-1 first: without an activation trigger, attendance/tracker have no gate to check (D3). If R3 says "activation is out of S06 scope," then S06 must instead pick a different seam for tracker-availability (e.g., `confirmed` is enough) — a ruling either way.
- Member surfaces (S06-5) are independent of the session machinery — could move earlier, but they're lower-risk, so parked after the core.
- The `requires_all` hardening (S06-6) depends on guardian-link lifecycle audit events existing; if they don't, it grows a sub-task.

---

## 6. Rulings needed before STEP 1 (your call)

- **R1 — Learn threshold home:** confirm the Learn threshold lives in **`certification_rules`** (`attendance_threshold_pct` / `team_gate_pass_pct`), and `stage_requirements` (the empty shell) is **left unused** (or repurposed for per-stage deliverable definitions later). Recommend: use `certification_rules`; don't build on the empty shell.
- **R2 — Learn gate mechanism:** Option A (computed auto-pass) / **B (computed-eligible, teacher-confirmed — recommended)** / C (flagged both). Plus the 0/0 (no-sessions) rule and a publish pre-flight warning.
- **R3 — Enrolment activation trigger:** what moves `confirmed → active`? Candidates: (a) payment recorded/paid, (b) programme start date reached (a job), (c) admin action, (d) 成團 itself (active immediately). This also decides what the tracker is "locked until."
- **R-Member — Member scope model:** confirm the `member` `ScopeContext` branch (network-wide directory + published events, own RSVP) and that new S06 tables get scope-map entries.
- **Card edits:** ratify adding **OD-22 Member surfaces** and the **enrolment-activation** workstreams to the S06 card (they're not there today).

---

## 7. Drift summary (one line each)
- **D1** Member surfaces (OD-22) absent from the card — the mandated card edit was never applied. **[blocker: card edit]**
- **D2** Learn gate is manual (S05) but OD-12 wants it computed — reconcile (R2).
- **D3** `confirmed → active` has no trigger; tracker "locked until active" has no seam (R3).
- **D4** Card predates S05 — attendance/assessment/Learn must bind to shipped `team_members` / `enrolments.active` / `certification_rules`.
- **D5** Stage-name case mismatch (`'Learn'` vs `'learn'`).
- **Corrected non-drift:** the Learn threshold is NOT unconfigured (it's in `certification_rules`) — unlike the capacity counter. The gap is computation + gate wiring, not config.
