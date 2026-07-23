# SPRINT KAP-S01 — Identity, auth & invitations

> Adjusted 2026-07-24 per the card-adjustment mechanism: S00 AUDIT §5 items 2, 7, 8, 9, 10
> and the OPEN-DECISIONS follow-ons (OD-1, OD-17) folded in before sprint start; five
> amendments from Leo's review applied (ordering, Member negative test, escalation refusal,
> step 7/8 split, OD-22 raised for Member surfaces).

## GOAL
Invitation-only identity with a full auth lifecycle, guardian/school/teacher links, and the
continuity rule — for a platform where the account holder is often not the subject.

## PRECONDITIONS
- [x] S00 gate PASSED (`70229b3`) · OD-1 (sixth Member role) decided — **Yes, 2026-07-23**

## IMPLEMENTS  2.11 · 2.13 · 2.2 · BI-8 · SR001 · OD-1 · OD-17

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. Users, roles, permissions matrix, per-link overrides. **Six-role seed per OD-1** — Student ·
   Parent/Guardian · Teacher · School Administrator · Academy Administrator · **Member**
   (events/RSVP/directory only; denied student records, consent, enrolment, finance — S01 seeds
   the role only; its surfaces are OD-22, not this sprint). Roles never stacked (Spec B1).
   **Academy Admin capability groups per OD-17**: `super_admin` (all, plus the right to grant) ·
   `configuration` · `finance` · `operations` · `audit_read` — grant/revoke flow included, and
   **every grant or revoke is itself an audited action**. Wire `actor_role` into AuditService now
   that roles exist (closes S00 AUDIT §5 item 7).
2. Invitation-only onboarding (2.11): tokenised single-use 14-day link → password → **mandatory email
   verification before first login completes** → create/link student. **The guardian/school/teacher
   link entities land in this step** — onboarding creates the guardian↔student link through them;
   step 5 builds the additional flows and the continuity rule on top.
3. Password reset (1h single-use) · lockout (5 fails → 15 min, admin-unlockable) · session policy
   (12h idle / 30d remember-me). All auth events → audit_events (BI-8). Login page ships here —
   split-screen with the rescued `auth-assets` background and AA logo per manifest §4
   (closes S00 AUDIT §5 item 10).
4. Throttling (2.13): auth 5/min/IP; pairing codes 5/hour/account + hard invalidation after 10
   global fails; API default 60/min/user.
5. Linking flows on the step-2 entities: pairing codes (student-initiated), parent-initiated email
   flow, school-mediated bulk vouching + **continuity rule (2.2)**: revoking a student's last active
   guardian link with a non-terminal enrolment → Academy Admin action + replacement-required
   exception (14-day) + suspension if unresolved.
6. **Secure the S00 surfaces**: `/api/audit-events` and the Admin › Audit page go behind
   **Sanctum + the `audit_read` capability** (closes S00 AUDIT §5 item 8). No other route ships
   unauthenticated.
7. **Route-level code-splitting (S00 §5 item 2)**: the bundle budget must be green at this sprint's
   gate with S01's pages included.
8. **CI clamav service (S00 §5 item 9)**: cached signature database so the four real-clamd tests
   run in CI instead of skipping.

## NON-SCOPE
Enrolments (S04A) · programmes (S02) · Logto or any OIDC (S11) · social login · public registration ·
**Member surfaces — event list, RSVP, directory (OD-22: unassigned; S01 seeds the role only)**.

## KEY VERIFICATIONS
- Unverified account cannot complete first login (paste the refusal).
- 6th failed login within window → locked; audit event with actor + reason.
- Pairing code: 11th global failed attempt → code hard-invalidated.
- Revoking a sole guardian link (fixture) → exception created, admin notified.
- Capability grant AND revoke each write an audit event naming grantor and grantee (paste rows).
- **Member denial, tested negatively — not via the matrix probe (circular against its own seed):
  a Member session receives 403 on student records, consent, enrolment and finance endpoints.
  Paste all four.** This is the control that made OD-1 safe to answer yes.
- **Privilege escalation refused: an Academy Admin holding `finance` but not `super_admin`
  attempts to grant a capability → 403, and the refusal itself is audited (paste the 403 and the
  audit row).** The success path alone is not sufficient.
- `/api/audit-events` without a session → 401; with a session lacking `audit_read` → 403;
  with `audit_read` → 200 (paste all three).
- `audit_events.actor_role` populated on new events (paste a row).
- `npm run build` → bundle budget PASS with S01 routes split (paste the per-chunk sizes).
- CI run shows the four real-clamd tests executing, not skipping.

## AUDIT ELEMENT
**Access & Identity Report** — auth event log, invitation funnel (issued→accepted→verified), active
links per student, orphan/sole-guardian exception list (Contact Unreachable rows arrive in S09/2.23),
**capability grants/revocations log**.

## ASSERTIONS (register --tag=S01)
- Every active student with a non-terminal enrolment has ≥1 active guardian link (vacuous until
  S04A — ships now).
- **Permission-matrix probe**: effective permissions for each role and capability group match the
  seeded matrix — covers capabilities, not just roles (OPEN-DECISIONS follow-on).

## EXIT GATE
`php artisan test` + `php artisan reconcile:run --tag=S01` green + audit element renders the funnel
from real seed events + bundle budget green + the three-way auth check on `/api/audit-events` pasted.
AUDIT.md, gate commit.
