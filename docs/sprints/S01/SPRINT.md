# SPRINT KAP-S01 — Identity, auth & invitations

## GOAL
Invitation-only identity with a full auth lifecycle, guardian/school/teacher links, and the
continuity rule — for a platform where the account holder is often not the subject.

## PRECONDITIONS
- [ ] S00 gate PASSED · OD-1 (sixth Member role) decided — **STOP if open**

## IMPLEMENTS  2.11 · 2.13 · 2.2 · BI-8 · SR001

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. Users, roles, permissions matrix, per-link overrides; role seed per OD-1.
2. Invitation-only onboarding (2.11): tokenised single-use 14-day link → password → **mandatory email
   verification before first login completes** → create/link student.
3. Password reset (1h single-use) · lockout (5 fails → 15 min, admin-unlockable) · session policy
   (12h idle / 30d remember-me). All auth events → audit_events (BI-8).
4. Throttling (2.13): auth 5/min/IP; pairing codes 5/hour/account + hard invalidation after 10
   global fails; API default 60/min/user.
5. Guardian/school/teacher link entities + pairing codes + **continuity rule (2.2)**: revoking a
   student's last active guardian link with a non-terminal enrolment → Academy Admin action +
   replacement-required exception (14-day) + suspension if unresolved.

## NON-SCOPE
Enrolments (S04A) · programmes (S02) · Logto or any OIDC (S11) · social login · public registration.

## KEY VERIFICATIONS
- Unverified account cannot complete first login (paste the refusal).
- 6th failed login within window → locked; audit event with actor + reason.
- Pairing code: 11th global failed attempt → code hard-invalidated.
- Revoking a sole guardian link (fixture) → exception created, admin notified.

## AUDIT ELEMENT
**Access & Identity Report** — auth event log, invitation funnel (issued→accepted→verified), active
links per student, orphan/sole-guardian exception list (Contact Unreachable rows arrive in S09/2.23).

## ASSERTIONS (register --tag=S01)
Every active student with a non-terminal enrolment has ≥1 active guardian link (vacuous until S04A —
ships now).

## EXIT GATE
`php artisan test` + `php artisan reconcile:run --tag=S01` green + audit element renders the funnel
from real seed events. AUDIT.md, gate commit.
