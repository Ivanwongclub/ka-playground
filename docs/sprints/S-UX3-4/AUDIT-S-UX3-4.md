# AUDIT KAP-S-UX3-4 — Sessions / attendance UI (the learning-delivery surface over the S06 engine)

**Result:** PASS · **Date:** 2026-08-04 · **HEAD at gate:** `f4b4293`

> Written by Claude Code at the card's end. Honesty outranks looking good. This is the BUILD audit; the
> in-product surfaces are My Sessions / My Child's Sessions / Attendance / Attendance Oversight. Planning:
> `PROPOSED-S-UX3-4.md` + `CARD-S-UX3-4.md` (this dir). Does NOT rewrite any prior AUDIT.

## 0. Scope

The learning-delivery surface — **sessions and attendance** — over the already-built S06 engine (which had
shipped **write + report only**: no session/attendance READ for a student or a mentor). Because attendance
is **minor presence data**, the card split by risk, not by convenience:
- **STEP 1** (`a6de9fb`) — the attendance READS + the mark, **child-safety-grade, LINE-BY-LINE, gated FIRST**.
- **STEP 2** (`f4b4293`) — the UI + a thin mentor session-list, **MIXED DEPTH** (the one new read reviewed
  line-by-line; the UI frontend-scan, batched).

## 1. The child-data finding (STEP 1)

**Attendance is a MINOR's presence record → a child-safety-grade read boundary**, sharper than the adult
member directory. The S06 `session_bookings` / `programme_sessions` RLS was already built roster- and
mentor-aware, so — unlike B2's `tm_read` — the reads **resolve in existing RLS**; the line-by-line depth is
earned by the child data, not by an elevation. STEP 1's three reads:

| Read | Elevation | Boundary |
|---|---|---|
| **student self** `GET /my/sessions` | **NONE** | pure RLS self-read (ps_read roster + session_bookings self clause) + a belt-and-suspenders `student_id` filter; a classmate's attendance is invisible (no co-student clause) |
| **guardian → own child** `GET /my/students/{id}/sessions` | **NONE** | **child-guard FIRST** — a non-linked child is refused **403 before any attendance is read**; guardian `users_read` + the `guardian_link` clause admit only the linked child |
| **mentor / ops roster** `GET /admin/sessions/{id}/roster` | **NEW, tight (B2-shaped)** | fetch the session **under the caller's RLS** (an unrelated teacher → **404, no existence leak**), then an **explicit `isMentor \|\| isOps`** (an enrolled student who *can* see the session row → **403**, never the roster of minors), then a TIGHT elevation returning only `{student_id, student_name, status}` — **recorder identity/time, consent, guardian identity, enrolment, other teams, cross-session linking all WITHHELD** |

**Why the roster needs the elevation and the others don't:** a mentor's `users_read` admits only
*school-linked* students, but a session's attendees are the D4 programme/team roster (spans schools), so
resolving roster **names** crosses the users wall. The guardian reads their *own* child (admitted), the
student reads only *themselves* — neither crosses a wall.

**The two critical child-privacy branches, PROVEN (tests):**
- **guardian denied another child** — `/my/students/{notMyChild}/sessions` → **403** (no attendance leaked).
- **student denied another student's roster** — an enrolled student → **403** on the roster; and in
  `/my/sessions` a classmate's name + mark never appear.

## 2. STEP 2 — the UI + the mentor session-discovery interlock

**The interlock (caught mid-STEP-2, ruled before building):** STEP 1 built the roster **by-id** but no
**mentor session-list** — and a mentor (a teacher) can reach neither the `role:student` `/my/sessions` nor
the ops report, so they had **no way to discover a session to open**. Surfaced to Leo before churning;
ruled **OPTION 1**.

**OPTION 1 as built** — a thin `GET /my/mentor/sessions` (`role:teacher`), reviewed **line-by-line within
STEP 2** while the UI batched frontend-scan:
- **METADATA + COUNT aggregates ONLY**: `title/time/status/capacity` + `booked/attended/no_show` as
  `COUNT(*) FILTER (…) GROUP BY session_id`. **NO student id / name / row leaves the list** — discovery is
  *how-many*, the roster (STEP 1's gated read) is *who*.
- **Elevation-free** (`asSystem` = 0 — resolves in the ps_read mentor clause + a belt-and-suspenders
  `mentor_id` filter), **mentor-scoped** (own sessions only; another mentor's excluded).

**The surfaces** (`web/src/pages/SessionAttendance.tsx`, one shared `RosterMark`):
- **Student** *My Sessions* — book/cancel (existing writes) + own attendance chip.
- **Guardian** *My Child's Sessions* — read-only; **child picker reused from `/api/consent-requests`** (no
  new backend).
- **Mentor** *Attendance* — `/my/mentor/sessions` → open → roster → mark.
- **Ops** *Attendance Oversight* — programme → attendance-report → session → the same `RosterMark`.

**The mark write's session-state refusal, rendered SHOWN-NOT-HIDDEN:** the Present/Absent controls stay
visible; marking a **published** (not in_progress) session returns **409 "Attendance can only be taken on
an In Progress or Completed session (is published)"**, which the UI renders via `message.error` — proven by
the risk shot (`S-UX3-4-STEP2-mark-refusal.png`). The mark write itself is S06-built and clean
(403 not-recorder / 409 session-state / 404 no-seat / 422) — **no Learn-gate or enrolment precondition**.

## 3. Honest items / deviations

| Item | Note |
|---|---|
| **Mentor-list interlock** | Not a plan item — STEP 1's reads didn't include a mentor discovery list. Caught mid-STEP-2, surfaced, ruled OPTION 1 before building. Recorded as the interlock it was. |
| **Guardian children reused** | The guardian child picker reuses `/api/consent-requests` (each row carries student_id + name) — **no new backend**. The PROPOSED had recommended deferring a guardian read; STEP 1 built the by-id child read (mandatory five-branch test), and STEP 2 sourced the picker from consent-requests. |
| **Ops oversight gate** | Gated `configuration.manage` (the programme list's gate), not `operations.manage` — an operations-only admin has no programme-list source. Documented follow-on. |
| **Mark is binary** | attended/no_show only — no "late" in the S06 engine, so the control offers Present/Absent, not invented. |
| **`useResource` signature** | widened to `(string \| null \| undefined)` to match its existing idle-url branch (dependent fetch). Backward-compatible. |
| **Roster non-authorized branch** | denies by VISIBILITY-first: unrelated caller → 404 (no existence leak), enrolled student → 403. Stronger than a flat 403; pinned by two tests. |

## 4. Exit gate

```
# STEP 1 (a6de9fb) — child-data reads, line-by-line
$ phpunit SessionAttendanceUxTest ScopeElevationTest      → OK (12 tests, 67 assertions)
    roster exact key-allowlist {student_id,student_name,status} + forbidden-field red-green sweep
    five-branch: student→own, guardian→child + DENIED another child (403), mentor→their roster,
    student→DENIED roster (403), ops→roster, unauth→401
    only the roster added an elevation (self/child reads: none)
    mark authority+state (published→409, non-mentor→403, mentor+in_progress→200)

# STEP 2 (f4b4293) — the mentor list (line-by-line) + the UI (frontend-scan)
$ phpunit SessionAttendanceUxTest ScopeElevationTest      → OK (13 tests, 82 assertions)
    + mentor list is OWN metadata + counts only (no student identity), non-teacher → 403, adds no elevation
$ npx tsc --noEmit                                         → clean
$ npm run i18n:check                                       → 647 / 647 / 647, parity complete
$ npm run build                                            → bundle-budget PASSED
$ php artisan test --exclude-group=clamav                  → 487 passed (6059 assertions)
$ php artisan reconcile:run                                → 58 / 58 (before AND after the risk-shot teardown)
$ php artisan migrate --pretend                            → Nothing to migrate (0 new)
```
**Verdict:** **PASS.** Battery **58/58**; **one new elevation** across the card (STEP-1
`SessionReadController::roster`, allowlisted + reason-matched, `ScopeElevationTest` green) — the STEP-2
mentor list added **none**; **migrations 0**.

## 5. Invariant check

| BI / discipline | Touched? | Evidence |
|---|---|---|
| Child-data read boundary | **yes** | tight roster allowlist + the two cross-child denials proven; the mentor list is metadata-only (no minor identity) |
| Scope-elevation discipline | **+1 (roster only)** | `ScopeElevationTest` enumerates every `asSystem` site; the roster's verbatim reason is in `config/scope-elevations.php`; self/child/mentor-list reads add none |
| BI-8 (audit) / mark write | reused | the S06 mark write (audited, clean) is surfaced, not modified |
| Provenance battery | protected | risk-shot demo data torn down via psql (system context) + reconcile re-confirmed 58/58 |

## 6. Hand-offs forward
- **Re-seed for a walkable surface:** a teacher assigned as a session's `mentor_id`, a published→in_progress
  session, 2–3 bookings, and some marked attendance (the risk-shot demo data was intentionally torn down).
- **Ops oversight for operations-only admins:** needs an ops-readable programme list — a follow-on.
- **Next per the ruled order: guardian/teacher self-service** (My Children / My Payments / My Students) —
  display over built endpoints; likely batches.
