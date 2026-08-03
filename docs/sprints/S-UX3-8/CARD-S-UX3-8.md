# CARD — S-UX3-8 · Member surfaces (events · RSVP · directory · profile)

> The biggest doorless gap: the `member` role is dark over a fully-built S06 surface. Planning:
> `PROPOSED-MEMBER-SURFACES.md` (approved). Rulings folded: **enable member invitation now** (the OD-22
> trigger — surgical); directory PII allowlist `{user_id, display_name, headline}` only (members-only,
> visible opt-out, joins only `member_profiles`, no elevation); add `GET /my/profile` (self-read); nav on
> `member_directory.view`; omit event/directory detail v1.

**Build order = review order:** STEP 1 (invitation enablement + directory read authority + self-profile
read) → STEP 2 (the member UI + nav).

---

## STEP 1 — member-invitation enablement + directory read authority + self-profile read (LINE-BY-LINE)

- **`InvitationService::INVITABLE_ROLES`** — add `'member'` (SURGICAL: exactly the array entry; nothing
  else in InvitationService changes). Verified: `doAccept` mints with `$invitation->role` and the
  school-vouch branch is `school_id !== null && role === 'teacher'` — a school-less member accept
  (`school_id === null`) skips it cleanly, minting a member account with EXACTLY the member role's caps.
  The now-unreachable `$role === 'member'` branch in `issue()`'s throw is left untouched (no "while I'm
  here" cleanup).
- **`GET /directory`** — no change; VERIFY the authority from `member_profiles_read`
  (`system OR ops/audit OR (member AND (visible OR user_id=actor))`) and that it joins **only
  `member_profiles`** (never `users`) → structural no-PII. Allowlist `{user_id, display_name, headline}`.
- **`GET /my/profile`** (NEW, `role:member`) — the member's OWN profile row (`user_id = actor`),
  self-scoped, own-row, **no elevation** (member_profiles RLS admits the member for their own via
  `user_id=actor`). Returns `{display_name, headline, visible}` or a null/creatable shape when absent
  (pre-fills the editor; the empty state is not an error).

**Mandatory STEP-1 tests:**
1. **Member invitable + accept:** `POST /admin/invitations {role:'member'}` (ops) → 201; `POST
   /onboarding/accept` → creates a user with EXACTLY `role='member'` + the member permissions
   (events.view, events.rsvp, member_directory.view) and **NO** teacher/school_admin caps and **NO**
   teacher_link (school-less path clean, no leakage from the teacher/school-admin accept branches).
2. **Directory privacy tooth (mirror B2):** exact key-allowlist `{user_id, display_name, headline}`
   (primary) + no `email` / `@` / `users.name` in the body (secondary). Red-green: adding a users-join reds it.
3. **Directory five-branch:** member → visible profiles + own; a NON-member (student / guardian / teacher /
   school_admin) → empty; ops → all; a `visible=false` member → **absent from others' directory, present in
   their own** read; unauthenticated → 401.
4. **RSVP clean write:** `409` only-published; upsert self; `/my/rsvps` returns the caller's own only.
5. **Profile self-scoped:** a member reads (`GET /my/profile`) and writes (`PUT /my/profile`) only their
   own — no param to name another member.
6. **Battery unchanged** (reads + a role-auth change — no assertion added; state if it changes: it does
   not); **`ScopeElevationTest` green** (no new elevation — the directory needs none, confirm).

**VERIFY (process rule — files, not on request):** the diff →
`~/Downloads/KAP-S-UX3-8-1-REVIEW-<ts>.diff` and the VERIFY output →
`~/Downloads/KAP-S-UX3-8-1-VERIFY-<ts>.txt`; paste the gate summary. Battery 58/58; suite green;
migrations 0. **No screenshots** (reads + clean self-writes — no shown-not-hidden refusal). Commit HELD.

## STEP 2 — the member UI + nav (FRONTEND-SCAN)

- **Events** (list + RSVP going/not_going/maybe), **Directory** (display_name + headline), **Profile
  editor** (display_name / headline / **visible** opt-out, pre-filled from `GET /my/profile`). S-UX2a kit;
  trilingual; darkAlgorithm.
- **Nav:** reveal Events / Directory / Profile on **`member_directory.view`** (member + academy_admin).
  The Profile surface must handle **"no member profile" gracefully** (empty/creatable, not an error) for
  an academy admin who has the permission but no profile — a one-line FE consideration, not a gate change.
- Demo member: seed one via invite→accept (exercising the STEP-1 capability) so the surface is walkable.

## Constraints / invariants
- **No migration** (reads + a role-auth array entry). Battery 58/58. Directory joins only `member_profiles`
  — structural no-PII. RSVP/profile are clean self-writes; no elevation anywhere.
- Members are **adults** (invited, no guardian/consent) — the directory is a community directory, minimal
  by design.

## Definition of done
STEP 1: member invitable + accept (exactly member caps), directory members-only/visible-only/opt-out +
privacy tooth, self-profile read, RSVP/profile self-scoped — all six tests green; battery 58/58, elevation
unchanged. STEP 2: the member UI + nav, events/RSVP/directory/profile working; tsc/build/i18n green. AUDIT
at the end.
