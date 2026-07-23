# SPRINT KAP-S09 — Notifications, dashboards & reporting

## GOAL
Every event fired since S01 finally reaches humans — provably — and the platform can watch itself.

## PRECONDITIONS  S08 gate PASSED.

## IMPLEMENTS  2.9 · 2.10 (full) · 2.16 · 2.23 · 2.21 final leg

## SCOPE
1. Notification engine: rules · Handlebars templates per channel/language (EN/繁中) · preference
   matrix with transactional locks · ladders · quiet hours · delivery log · dead-letter surfacing.
2. **Bounce handling (2.23)**: hard bounce → contact invalid → Contact Unreachable exception in the
   S01 list; ladders to invalid addresses pause + alert.
3. Dashboards: aggregates + triggers · role presets · widgets per Spec Part I.
4. Reports: standard catalogue · AR suite · exports · scheduling.
5. **Announcements + Messages (2.9)** — thread role-checks as specified.
6. **Retention job (2.16)** + Retention Execution Report.
7. Assemble the **full nightly reconciliation suite** (every sprint's assertions in one run) ·
   cascade final leg: withdrawal closes open ladders.

## NON-SCOPE
WhatsApp/WeChat channels (Phase 2) · custom report builder (Phase 2) · dashboard drag-and-drop (Phase 2).

## KEY VERIFICATIONS
- Bounce webhook fixture → contact flagged, ladder paused, exception visible in S01 report.
- Quiet hours: event at 02:00 → delivered at window open, delivery log proves both timestamps.
- Parent opens a thread to staff of another child's programme → refused.
- Retention job on fixture data → anonymised per policy, report row written.

## AUDIT ELEMENT
**Platform Assurance Report** (capstone) — nightly reconciliation dashboard (all assertions,
pass/fail history) · notification delivery proof · scheduled-report run log · aggregate-vs-source
drift history. This is the screen that demonstrates the whole platform's close-loop to the client.

## ASSERTIONS
All previous tags nightly as one suite + notification/report liveness + ladder-pause-on-invalid.

## EXIT GATE  Full suite green two consecutive nightly runs (real scheduler, not manual). AUDIT.md, gate commit.
