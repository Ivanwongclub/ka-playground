# SPRINT KAP-S06 — Learning: sessions, bookings, attendance

## GOAL
Sessions with a real lifecycle — including reschedule, the most common real-world change — plus
bookings, attendance, thresholds and assessments.

## PRECONDITIONS  S05 gate PASSED.

## IMPLEMENTS  2.3 · 2.5 · 2.6 · 2.24 · 2.10 (register) · 2.21 extension

## SCOPE
1. **Session state machine (2.3)**: Draft→Published→Full→In Progress→Completed/Cancelled/Rescheduled.
   Reschedule keeps bookings, writes session_version, fires `session.rescheduled`, re-opens booking
   if capacity grew. Attendance only in In Progress/Completed.
2. **Clash check (2.24)** on reschedule: detect clashes across retained bookings; flag + include in
   re-notification; organiser sees clash count pre-confirm.
3. **Mentor lifecycle (2.6)**: Departed blocked while future sessions exist unless each reassigned
   or rescheduled.
4. Booking + session waitlist with auto-promotion · attendance capture · Learn-threshold computation
   (per-student + team roll-up) · **assessment lifecycle (2.5)** · calendar.
5. Register **ladder liveness (2.10)** · extend Withdrawal Cascade: future bookings cancelled,
   waitlist slots released.

## NON-SCOPE
Notification channel delivery (S09 — fire events) · recognition (S08).

## KEY VERIFICATIONS
- Reschedule onto a time clashing with a student's other booking → flagged, count shown, paste.
- Mentor → Departed with a future session → blocked until reassigned (uses 2.3), paste.
- Attendance attempted on a Draft session → rejected.
- Assessment results invisible to student/guardian before Released.

## AUDIT ELEMENT
**Attendance & Session Report** — booked/attended/no-show with recorder identity · reschedule/
cancellation history with notification proof · waitlist promotion log · Learn-threshold status.

## ASSERTIONS (--tag=S06)
Attendance records == attended bookings · no Published session in the past without a terminal state ·
ladder liveness (2.10) · cascade booking leg live.

## EXIT GATE  Tests + tag green + one full reschedule walked in seed with re-notification proof. AUDIT.md, gate commit.
