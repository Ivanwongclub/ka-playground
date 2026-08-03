# CARD — S-UX3-4 · Sessions / attendance UI (over the built S06 engine)

Approved from `PROPOSED-S-UX3-4.md` with Leo's rulings (2026-08-03). Attendance is **minor presence data →
child-safety-grade** read boundary. **Two steps; STEP 1 is line-by-line, gated FIRST. STEP 2 (UI) batches
only AFTER STEP 1 clears — not built in this pass.**

---

## STEP 1 — attendance reads + mark (BACKEND, LINE-BY-LINE, child-data). Commit HELD.

### What ships
Three **read** endpoints over the S06 engine, plus tests exercising the **existing** mark write (no write is
rebuilt). Per the five-branch, resolved from source:

| Endpoint | Gate | Elevation? | Returns |
|---|---|---|---|
| `GET /my/sessions` | `role:student` | **NONE** — pure RLS self-read | the student's sessions across programmes they hold a live enrolment in + **their own** booking/attendance status |
| `GET /my/students/{studentId}/sessions` | `role:guardian` | **NONE** — guardian `users_read` + `session_bookings` guardian clause admit their **own** child; child-guard 403s any non-linked child | the linked child's sessions + that child's booking/attendance |
| `GET /admin/sessions/{id}/roster` | authority in-controller (session **mentor** OR academy **operations/super/audit_read**) | **NEW narrow elevation** (B2-shaped) | the session's roster: per booked student `{student_id, student_name, status}` — nothing else |

### Why the roster needs a NEW elevation (and the self/child reads do not)
`users_read` admits a **teacher** to a student's name **only** when the student is on the teacher's school
via `school_links`. A session's attendees are the **programme/team roster** (D4) — which spans schools and
includes direct-registered students. So a mentor resolving roster **names** *crosses the users RLS wall* —
exactly as B2's roster crossed `tm_read`. → a new allowlisted `asSystem` on
`SessionReadController::roster`, verbatim reason in `config/scope-elevations.php`, `ScopeElevationTest`
green. The **guardian** reading their *own* child's name is admitted by `users_read`
(`role='guardian' AND id ∈ student_ids`) → **no elevation**. The **student** reads only their own data → no
elevation (the mentor's name is **omitted** in v1 to keep it elevation-free).

### The elevation allowlist — TIGHT (child data)
Only `{student_id, student_name, per-student attendance status for THIS session}` leave the elevation.
**WITHHELD, never returned:** consent status, guardian identity, enrolment detail, other teams,
cross-session linking, money/obligations, recorder identity/time (those live in the ops audit report).

### The mark write — confirmed clean (source)
`AttendanceService::mark` refusals: **403** not-the-recorder · **409** session not in_progress/completed ·
**404** no booked seat · **422** bad status. **No Learn-gate precondition, no enrolment precondition** — the
enrolment check is at BOOK time, not MARK time. STEP 1 does **not** modify the write; tests exercise it.

### Files
- **new** `app/Http/Controllers/SessionReadController.php` — the three reads.
- `routes/api.php` — three GET routes in the S06-3 block.
- `config/scope-elevations.php` — **one** new entry (`SessionReadController::roster`).
- **new** `tests/Feature/SessionAttendanceUxTest.php` — the mandatory battery.
- **0 migrations.** No schema need — the reads ride existing tables/policies.

### Mandatory tests (Leo)
1. **Privacy tooth (child-data):** roster returns EXACTLY `{student_id, student_name, status}` — assert keys;
   a known student name present; **NO** email / guardian name / consent field / enrolment field /
   other-team / other-session key. Red-green (a users-email or guardian join would leak).
2. **Five-branch:** student→own via `/my/sessions`; guardian→their child via `/my/students/{id}/sessions`
   **and DENIED another child (403)** — the critical child-privacy assertion; teacher(mentor)→their assigned
   session's roster; a student→another student's attendance = **NEVER** (roster 403; `/my/sessions` shows
   only own); ops→roster per authority; unauth→401.
3. **Elevation:** the roster site is allowlisted + reason-matched (`ScopeElevationTest` green); the self/child
   reads add **no** elevation (assert the allowlist gained exactly one key).
4. **Mark write:** mentor of the session → 200; a teacher who is not the mentor → 403; a published
   (not in_progress) session → 409.
5. **Battery unchanged (58)** unless an assertion is added (state it); **migrations 0**.

### Exit gate
`SessionAttendanceUxTest` + `ScopeElevationTest` green · `reconcile:run` 58/58 · full suite green ·
`migrate --pretend` clean (0 new). VERIFY output + diff → `~/Downloads`. **No screenshots** (backend).
**Commit HELD.**

---

## STEP 2 — the UI + nav (FRONTEND-SCAN, batched, ZERO screenshots). **NOT in this pass.**
Built only after STEP 1 is reviewed and cleared. Student *My Sessions* (book/cancel + own attendance),
mentor *Attendance* (roster → mark, refusals shown-not-hidden), ops report view (reuse the built
attendance-report), Learn threshold display; nav per §6 of the PROPOSED; trilingual i18n parity.
