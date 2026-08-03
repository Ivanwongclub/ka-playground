# PROPOSED — S-UX3-4 · Sessions / attendance UI (the learning-delivery surface over the S06 engine)

**Think-first. Plan only — no code, no commit.** Resolved from source (routes/api.php, the S06 migrations
+ services + controllers). Risk tag (remaining-build-plan): **child-safety — minor attendance records.**

---

## 0. Headline (what the source changed vs the early assessment)

The early read was *"clean summary card, attendance a clean write, no consent/money interlock — may batch
fully."* Source **splits that verdict**:

- ✅ **CONFIRMED — the WRITE is clean, no consent/money interlock.** The attendance mark
  (`POST /admin/sessions/{id}/attendance`) and the student book/cancel already exist, are audited, and
  carry only **session-state** + **recorder-authority** refusals — never a consent or money gate.
- ⚠️ **CORRECTED — the READS do not exist, and one of them is a child-data fan-out.** The S06 engine is
  **write + report only**: there is **no session list, no session detail, no attendance read** endpoint
  for a student or a mentor. This card must **build new read endpoints**. One of them — the **mentor's
  session roster** (a list of *minors' presence* shown to a teacher) — is exactly the child-data privacy
  surface the risk tag warns about. So the card is **NOT a single frontend-scan batch**: the new reads get
  a **line-by-line backend step, gated FIRST**; the UI batches after.
- ✅ **GOOD NEWS on elevation — none needed.** Unlike B2 (member roster, which had to *cross* `tm_read`
  with a new elevation), S06 built `programme_sessions` and `session_bookings` **roster-aware and
  mentor-aware in the RLS itself**. Every read this card needs — student-self, guardian-child,
  mentor-roster, ops-all — resolves in the **existing** policies with **no `asSystem`**. `ScopeElevationTest`
  is untouched. The line-by-line step is warranted by *child data*, not by an elevation.

---

## 1. The S06 engine as BUILT — endpoint census (built vs needs-a-read)

| Endpoint | Verb | What | Gate | RLS / authority | Status |
|---|---|---|---|---|---|
| `/admin/programmes/{id}/sessions` | POST | create session | in-service (ops) | system write | built (ops) |
| `/admin/sessions/{id}/transition` | POST | lifecycle draft→published→…→completed | in-service | system write | built (ops) |
| `/admin/sessions/{id}/reschedule` | POST | reschedule (immutable `session_versions`) | in-service | system write | built (ops) |
| `/admin/sessions/{id}/clash-preview` | POST | clash check | in-service | read helper | built (ops) |
| `/my/sessions/{id}/book` | POST | student books (FOR UPDATE, waitlist) | `role:student` | elevated write, own enrolment | built |
| `/my/sessions/{id}/cancel` | POST | student cancels (auto-promote) | `role:student` | elevated write, own booking | built |
| `/admin/sessions/{id}/attendance` | POST | **mark attended/no_show** | in-service | recorder = mentor **or** ops | built |
| `/admin/programmes/{id}/attendance-report` | GET | **audit element** (per-programme roster, reschedule log, waitlist promotions, Learn threshold) | in-service (ops/audit RLS) | ops/audit | built |
| `/admin/programmes/{id}/assessments` + transition/grade/results | — | assessment lifecycle (2.5) | ops | embargoed results | built (OUT of scope — §8) |
| `/assessments/{id}/results/{studentId}` | GET | RLS-embargoed single result | authed | family sees only once *Released* | built (OUT of scope — §8) |
| **`GET /my/sessions`** | — | student's sessions + own booking/attendance | — | ps_read roster + bookings self | **NEEDS BUILD** |
| **`GET /admin/sessions/{id}/roster`** | — | a session's bookings + attendance (mentor/ops) | — | bookings mentor/ops clause | **NEEDS BUILD (child-data fan-out)** |
| *(optional)* guardian child-attendance read | — | `/my/students/{id}/sessions` | — | bookings guardian clause | **decision — §7** |

**RLS as built (the reason no elevation is needed):**

`programme_sessions.ps_read` = `system OR ops/audit OR mentor_id = actor OR roster`, where
`roster = EXISTS(enrolment in this programme where student_id = actor OR ∈ app.student_ids)`.
→ a student sees the sessions of programmes they're enrolled in; a guardian sees their children's; a
mentor sees the sessions they're assigned to; ops sees all.

`session_bookings.session_bookings_read` = `system OR ops/audit OR student_id = actor OR
student_id ∈ app.student_ids OR active guardian_link OR (session's mentor = actor)`.
→ a student sees **only their own** booking/attendance; a guardian their child's; **the mentor of a session
sees every booking on that session** (the roster); ops sees all. **There is no co-student clause** — a
student gets *zero* rows for a classmate. The co-minor privacy wall is enforced **by the policy's absence
of that clause**, exactly like B2 withholds a co-member's consent.

---

## 2. WHO MARKS ATTENDANCE — the write, confirmed clean

`AttendanceService::mark` — recorder = **the session's mentor** (`session.mentor_id === recorder.id`)
**or academy operations** (`academy_admin` with `operations`/`super_admin`), enforced **server-side inside
the elevation** by `assertRecorder` (not by UI hiding). Refusals to surface (shown-not-hidden):

| Refusal | Trigger | Code |
|---|---|---|
| Not the recorder | caller is neither the session's mentor nor ops | **403** "Only the session mentor or academy operations may record attendance" |
| Wrong session state | session is not `in_progress`/`completed` (e.g. still `published`) | **409** "Attendance can only be taken on an In Progress or Completed session (is …)" |
| No booked seat | student has no booked/attended/no_show booking on the session | **404** "No booked seat for this student to mark" |
| Bad status | status ∉ {attended, no_show} | **422** |

**Interlock verdict: session-STATE + recorder-authority only. NO consent gate, NO money gate.** It is a
clean write in the RSVP sense (server-authoritative, per-student, audited `session_booking.{attended|no_show}`)
— but with a real **state precondition** (session must be running/finished) and an **authority
precondition** (mentor-or-ops). Those are refusals the UI surfaces, not blockers that change the card's
shape. **This card adds NO new write** — it surfaces the existing mark + book/cancel.

The student booking write (also existing) carries its own refusals to surface: **409** "only a
published/full session accepts bookings", **403** "not a live participant in this programme", **403**
"session is for a team you are not an active member of", idempotent re-book returns the original.

---

## 3. THE ATTENDANCE-READ PRIVACY BOUNDARY — the review-critical fork (minor data)

Attendance is a **minor's presence record** — more sensitive than the adult member directory. The reads
split into a **self/child-scoped read** (low blast radius) and an **authority fan-out read** (a roster of
minors shown to a teacher). Both resolve in existing RLS; the fan-out is the one that gets line-by-line.

**Five-branch — who sees WHOSE attendance:**

| Reader | Via | Sees | Withheld |
|---|---|---|---|
| **system** | elevated write paths only | — | (no read endpoint elevates) |
| **ops / audit academy_admin** | roster read / attendance-report | **all** sessions & attendance (authority) | nothing |
| **mentor (the session's)** | `GET /admin/sessions/{id}/roster` | **that session's whole roster** — every booked student's attended/no_show | other mentors' sessions; sessions they're not assigned to |
| **student (self)** | `GET /my/sessions` | **only their own** booking + attendance across their programmes | **every other student's attendance** (no co-student RLS clause) |
| **guardian** | child-scoped read *(if built, §7)* | **only their linked child's** attendance (active `guardian_link`) | any non-linked child; the roster |
| **unrelated student / other role** | — | nothing (RLS returns zero rows; route not theirs) | everything |
| **unauthenticated** | — | **401** | everything |

**The withheld line, stated plainly:** *a student can never see another student's attendance.* A mentor
sees a roster **only for sessions they are the assigned mentor of**. A guardian sees **only their linked
child**. This is the same co-minor wall as B2 (withholding a co-member's consent), and here it is enforced
by the **absence of a co-student clause** in `session_bookings_read`.

**Does any read need a NEW elevation?** **No.** The mentor-roster read resolves via the policy's
`(session's mentor = actor)` clause; the ops read via `ops/audit`; the student read via `student_id = actor`;
the guardian read via the `guardian_link` clause. **Zero `asSystem`** — the new endpoints are thin
RLS-scoped selects. **This is the fact that decides the split:** the reads do NOT cross an RLS wall, so no
gated-elevation step is needed — **but** the mentor-roster read is a **fan-out of minors' data to a teacher**,
so per the child-safety tag it is **built and reviewed line-by-line FIRST**, before the UI. The reviewer's
job on that step: confirm the roster select runs **under the mentor's own RLS context (no elevation)**, is
**reachable only by mentor/ops** (route gate + RLS backstop), and **leaks no PII beyond the roster
allowlist** (student display name + attendance status — never guardian/consent/contact data).

---

## 4. The Learn gate — display-only, attendance-derived, no consent/enrolment interlock

`LearnGateService::eligibility(team)` is **computed, not stored**: per-member `attended / marked` across
the programme's sessions vs `certification_rules.attendance_threshold_pct` (default 70), then the team
passes when the qualifying share ≥ `team_gate_pass_pct` (default 60). Suspended members are excluded from
the denominator (a payment lapse must not penalise learning). A team with **no marked attendance is 0/0 —
`assessable:false`, never silently "eligible."**

**Interaction check:** the Learn gate is **purely attendance-derived** — it reads `team_members` +
`session_bookings`, and the threshold from `certification_rules`. It touches **no consent state and no
enrolment/money state**. It is a **HARD PRECONDITION to the *teacher's* Tracker gate approval**
(`TrackerService::approveGate`, S07) — it does **not** auto-pass and it is **not operated from this card**.
**This card DISPLAYS the gate (threshold + attended ratios + eligible/assessable), read-only.** Operating
the gate belongs to the Tracker surface. Recommend surfacing the threshold + computed ratios inside the
mentor/ops attendance view (the `attendance-report` already returns `learn_team_gate_pass_pct`); a full
per-team eligibility widget is a thin additive RLS read folded into STEP 1 **or** deferred — §7 decision.

---

## 5. Sessions visibility — the read scope per role

- **Student** → the sessions of **programmes they hold a live enrolment in** (`ps_read` roster), with their
  **own** booking/attendance stitched on. No other student's booking, and **no live seat count** (see §7 —
  computing "12/15 booked" needs cross-student reads a student's RLS forbids; the book *result* reports
  booked|waitlisted instead).
- **Mentor (teacher)** → **only the sessions they are the assigned `mentor_id` of** — and, for each, its
  full roster. Not other mentors' sessions.
- **Ops (academy_admin operations/audit)** → **all** sessions and the per-programme attendance report
  (already built).
- **Guardian** → their child's sessions/attendance **iff** a child-scoped read is built (§7).

---

## 6. NAV — reveals for which roles, with the gates

| Item | Path | Visible when | Serves |
|---|---|---|---|
| **My Sessions** | `/my/sessions` | `has('enrolment.view')` **and not** `has('operations.manage')` | student self-service (list · book/cancel · own attendance) — mirrors the `/my/team` student-vs-ops pattern |
| **Attendance** | `/mentor/attendance` (or `/sessions`) | `has('sessions.mark')` / the mentor marker **and not** ops | mentor: their sessions → roster → mark |
| **Sessions & Attendance (report)** | reuse `/admin/…` under Administration | `has('operations.manage')` **or** `has('audit.read')` | ops oversight — the built attendance-report |

Exact gate strings resolve against the built permission set during the card (the mentor marker — whether
`sessions.mark` exists as a permission or the surface keys on the teacher role + mentor assignment — is a
STEP-1 source check, stated in the card, no guessed gate). Shown-not-hidden throughout: server authority
(`assertRecorder`, RLS) is the real gate; nav visibility only avoids offering a dead door.

---

## 7. Open decisions for the card (call before building)

1. **Guardian child-attendance view — v1 or defer?** The RLS already admits it (`guardian_link` clause).
   A `GET /my/students/{id}/sessions` mirrors the existing `/my/students/{id}/consent-status` derived read.
   **Recommend: DEFER to a thin follow-on** — keep v1 to student-self + mentor + ops, matching how consent
   shipped student-self first. Cheap to add later; keeps the child-data review surface minimal.
2. **Live seat count on the student list — show or omit?** Computing remaining capacity needs reading other
   students' bookings (RLS-forbidden for a student). **Recommend: OMIT in v1** — the book write already
   returns `booked|waitlisted`, so the student learns availability at the moment of action. No elevation, no
   aggregate-count endpoint.
3. **Team Learn-eligibility widget — fold in or defer?** **Recommend: display the threshold + attended
   ratios** (from the roster read / report) in v1; **defer** a full per-team eligible/assessable widget
   unless wanted — if wanted, it's a thin RLS read folded into STEP 1.
4. **Assessment results are OUT of scope** (§8).

---

## 8. Explicitly OUT of scope

- **Assessment results / grading / the embargo surface** (2.5, `assessment_results` RLS embargo). Built,
  but a **distinct** surface with its own "hidden until Released" family-visibility rule — its own card, not
  this one. The remaining-build-plan line for S-UX3-4 lists *"session schedule, attendance marking, student
  book/cancel, Learn-threshold view"* — assessments are not in it.
- **Session create / transition / reschedule / clash** — ops authoring, already built; this card **surfaces
  reads and the existing mark/book writes**, it does not add authoring UI (unless a later card asks).
- **Operating the Learn/Tracker gate** — S07/Tracker.
- **No new write** in this card.

---

## 9. Recommended step split + BATCHABILITY

**NOT a single batch.** The mentor-roster read is a child-data fan-out → it earns a gated line-by-line
backend step first; the UI batches after.

- **STEP 1 — new RLS-scoped read endpoints (LINE-BY-LINE, gated FIRST).**
  `GET /my/sessions` (student-self) + `GET /admin/sessions/{id}/roster` (mentor/ops). Both thin selects
  under the caller's **own RLS context — NO `asSystem`**. Deliver with a **privacy-tooth test battery**:
  the roster's exact key-allowlist (student display name + attendance status only — **no guardian/consent/
  contact PII**, red-green on a users/guardian join); the **five-branch** of §3 (mentor→their-session-only,
  student→own-only via `/my/sessions`, **co-student→zero**, guardian→withheld in v1, ops→all, unauth→401);
  the roster reachable **only** by mentor/ops (route gate + RLS backstop). **0 migrations, 0 new elevation
  (`ScopeElevationTest` unchanged), battery stays 58** (reads + an RLS authority entry — no new assertion
  unless we choose to register a co-student-withholding assertion, which I'd recommend as one line).
  → **Review depth: line-by-line** (child data). Proof = tests + diff.

- **STEP 2 — the UI + nav (FRONTEND-SCAN, batched, ZERO screenshots).**
  Student *My Sessions* (list · book/cancel via the existing writes, surfacing their refusals · own
  attendance chip); mentor *Attendance* (their sessions → roster → mark attended/no_show, surfacing the
  409/403/404 refusals shown-not-hidden); ops report view (reuse the built attendance-report); the Learn
  threshold + ratios display (read-only). Nav per §6, trilingual i18n parity. Pure reads + existing clean
  writes → frontend-scan, one prompt. → Proof = `tsc`/`build`/i18n parity + tests; no screenshot (no new
  write surface — the writes already exist and were gated at S06).

**Why STEP 1 is line-by-line despite no elevation:** the elevation fork says *"new elevation → gated."*
There is none. But the child-safety fork also says *"crosses a child-data privacy wall → gated."* The
mentor-roster read **exposes a roster of minors' attendance to a teacher** — that IS the child-data wall,
even though existing RLS enforces it. So the read authority is reviewed line-by-line; the elevation
allowlist simply doesn't change.

---

## 10. Hand-offs / pre-reqs for a walkable STEP 2

Needs seeded, to walk the surface end-to-end: a **teacher assigned as a session's `mentor_id`**, a
**published→in_progress session**, **2–3 student bookings** on it, and **some marked attendance** (so the
Learn ratio is non-trivial and the mentor roster is non-empty). Flag to Leo for the re-seed — like the
member account in S-UX3-8, this surface is only walkable with a mentor + a running session + bookings.

---

### One-line recommendation

Build S-UX3-4 in **two steps**: **STEP 1** the two new RLS-scoped reads (`/my/sessions` self + `/admin/
sessions/{id}/roster` mentor/ops) **line-by-line, gated first** with a child-data privacy-tooth battery and
**no new elevation**; **STEP 2** the student/mentor/ops UI + nav **frontend-scan, batched, zero
screenshots**. Confirm the write is clean (it is); the reads are the review surface. Decide §7 (guardian
view + seat count + Learn widget) before STEP 1.
