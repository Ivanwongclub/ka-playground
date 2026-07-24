# KAP — Spec Amendments (consolidated)

**Status:** These amendments modify Full Specification v4 (→ effective v4.2). On any conflict, the
amendment wins. 2.1–2.16 originate from the Build Plan's four-lens review; 2.17–2.27 from the
second-pass review (findings E1–E11). Each sprint card names the amendments it implements.

| # | Amendment (operative rules) | Lands in |
|---|---|---|
| 2.1 | **Withdrawal policy per programme**: `full_refund_before_date`, `pro_rata_bands[{until_date, refund_pct}]`, `no_refund_after_date`, `withdrawal_requires_approval` (default true). Withdrawn is reachable only via request → approval → policy-computed refund → Refund record (2.17) → credit note. All inputs audited | S02 (fields) / S04B (workflow) |
| 2.2 | **Guardian continuity**: revoking the last active guardian link of a student with a non-terminal enrolment requires Academy Admin action, opens a replacement-required exception (14-day deadline), suspends enrolments if unresolved. Signed consents stay valid against their signed version | S01 |
| 2.3 | **Session state machine**: Draft → Published → Full → In Progress → Completed / Cancelled / Rescheduled. Reschedule keeps bookings, writes a session_version row, fires `session.rescheduled`, re-opens booking if capacity grew. Attendance only in In Progress/Completed | S06 |
| 2.4 | **Avatar moderation**: Pending → Approved / Rejected(reason) → Appealed → Final; approved swap is atomic; one appeal | S08 |
| 2.5 | **Assessment lifecycle**: Draft → Published → Open → Closed → Graded → Released; results visible only at Released | S06 |
| 2.6 | **Mentor lifecycle**: Active → Inactive (no new bookings) → Departed; Departed blocked while future sessions exist unless reassigned/rescheduled | S06 |
| 2.7 | **Enrolment seat locking**: capacity check + insert in one transaction, `SELECT FOR UPDATE` on the programme counter; loser → waitlist offer (2.18) or clear "full" response | S04A |
| 2.8 | **Idempotency**: partial unique index — one enrolment per (student, programme) outside terminal states; payment recording carries a client idempotency key; duplicates return the original | S04A / S04B |
| 2.9 | **Announcements & Messages schema**: `announcements`, `announcement_audiences`, `message_threads`, `messages`; parent may open a thread only to staff of their child's programmes | S09 |
| 2.10 | **Ladder liveness**: nightly — no open reminder ladder with `next_run_at` in the past by >1h; violation alerts | S06 registers / S09 full |
| 2.11 | **Auth lifecycle**: invitation-token onboarding (single-use, 14-day), mandatory email verification before first login, reset link 1h single-use, lockout 5 fails → 15 min (admin-unlockable), 12h idle web session, remember-me 30d. All auth events → `audit_events` | S01 |
| 2.12 | **Shared upload service**: per-context MIME allow-list, size caps (images 5MB, docs 15MB), server-side image re-encode (strips EXIF/payloads), queued ClamAV scan — file invisible until pass; hit → quarantine + alert + audit | S00 |
| 2.13 | **Throttling**: auth 5/min/IP; pairing codes 5/hour/account + hard invalidation after 10 global fails; reminder trigger 1/24h server-side; API default 60/min/user | S01 |
| 2.14 | **Environments & backup**: local → staging → production (staging = same compose, separate RDS+OSS); nightly RDS snapshot 7d + weekly 4w; OSS versioning on receipts/consents; restore drill before go-live then quarterly | S00 (envs) / S10 (drill) |
| 2.15 | **Migration gate**: migrations reversible or flagged destructive with review; CI runs `migrate --pretend` against a staging clone before production deploy | S00 |
| 2.16 | **Retention execution**: scheduled job applies `retention_policies`; writes a Retention Execution Report under Audit & Compliance | S09 |
| 2.17 | **Refund state machine** (E1): Requested → Approved → Paid Out → Confirmed / Rejected. Payout requires evidence; recorder ≠ confirmer (BI-9); destination = original payer party. Assertions: no computed refund without a terminal-or-fresh Refund; every Confirmed refund has evidence | S04B |
| 2.18 | **Enrolment waitlist lifecycle** (E2): Waiting → Offered(48h) → Accepted / Expired / Declined / Withdrawn. Seat release promotes head-of-queue inside the 2.7 lock; offer expiry releases to next. Assertions: no free seat while Waiting exists and booking open; no Offered past expiry | S04A |
| 2.19 | **Late payment exception** (E3): payment against a non-recordable enrolment opens a Late Payment exception — reinstate if seat free, else refund via 2.17 + notify. Never silently rejected or applied | S04B |
| 2.20 | **Wrong-amount payments** (E4): partial-payment policy per OPEN-DECISIONS. Underpayment → Unmatched + shortfall notice; overpayment → receipt at order amount + credit note or refund; `unmatched_payments` queue with aging. Assertion: no Unmatched >7d without resolver | S04B |
| 2.21 | **Withdrawal Cascade assertion** (E5): named, registered in S04B, extended in S05/S06/S09 — no Withdrawn enrolment holds an active team membership, open tenure, future booking, Waiting/Offered entry, or open ladder | S04B + later |
| 2.22 | **Multi-guardian authority** (E6): default any active guardian may act; every action names its acting guardian; conflicting actions route to Academy Admin as an exception. Refund destination follows 2.17 (payer), not the requester. Policy needs client sign-off | S04A/B |
| 2.23 | **Bounce handling** (E7): hard bounce → contact invalid → Contact Unreachable exception surfaced in the S01 exception list; ladders to invalid addresses pause and alert | S09 |
| 2.24 | **Reschedule clash check** (E8): on reschedule, detect clashes across retained bookings; flag, include in re-notification, show organiser a clash count pre-confirm | S06 |
| 2.25 | **Logto cutover tokens** (E9): enumerate outstanding invitation/reset tokens at cutover; honour via compatibility route or void-and-reissue with notification. Assertion: no pre-cutover token unresolved | S11 |
| 2.26 | **Deploy & rollback runbook** (E10): deploy = pull tagged images + compose up; every prod deploy = annotated tag; rollback = previous tag one-command runbook; destructive-migration review decides if DB restore also needed; rollback rehearsed on staging in S10 | S00 (runbook) / S10 (rehearsal) |
| 2.27 | **Age of majority** (E11): open decision; default until decided — guardian consent signed while student was a minor remains valid for the enrolment's duration | register only |
| 2.28 | **Registration-request sitemap deltas (OD-23)**: Part Q gains **Q0. Public** — a single unauthenticated page `Register Interest` (school picker from opt-in listed schools ONLY — no free-text path: an unlisted school is not a public route in, its families are invited directly; submits a REQUEST, shows a static confirmation, no status lookup). **Q4 School Administrator** gains **Registration Requests** tab → `Pending | Approved | Declined | Flagged`, approve issues the standard invitation, decline requires a reason, both audited. Academy ops oversight of the cross-school queue rides audit_read. Overrides Spec L4/B10/R2 where they say no public surface exists | S06B |
