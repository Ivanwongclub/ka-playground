# PROPOSED — S-UX3-8 · Member surfaces (think-first)

> **Plan only — no code, no commit.** The biggest doorless gap: the `member` role is dark over a
> fully-built S06 surface (events, RSVP, directory, profile). Sourced from the built controllers +
> the member-surfaces RLS + the role/invitation model. The S-UX3-3b AUDIT flagged two blockers this
> pass MUST resolve: the **member-account seed** (0 members exist) and the **directory PII boundary**.

---

## 1. The S06 member surface as BUILT (endpoints, gates, RLS)

| Endpoint | Gate | Returns / does | RLS |
|---|---|---|---|
| `GET /events` | authed (RLS-shaped) | published events network-wide: `{id, title_en/tc/sc, starts_at, location, status}` | `events_read` = system OR ops/audit OR (`status='published'` AND **member**) — a **member sees every published event; non-members (student/guardian/teacher/school_admin) see NOTHING** |
| `POST /events/{id}/rsvp` | `role:member` | RSVP `going\|not_going\|maybe`; 422 bad status · 404 no event · 409 not-published; upserts `event_rsvps(member_id=self)` | write self-only |
| `GET /my/rsvps` | `role:member` | the member's OWN rsvps | `event_rsvps_read` = system OR ops/audit OR `member_id=actor` (**own only**) |
| `GET /directory` | authed (RLS-shaped) | visible profiles: `{user_id, display_name, headline}` | `member_profiles_read` = system OR ops/audit OR (**member** AND (`visible` OR `user_id=actor`)) |
| `PUT /my/profile` | `role:member` | upsert `{display_name, headline, visible}` for `user_id=self` | write self-only, no elevation |
| `POST /admin/events`, `/admin/events/{id}/transition` | (ops) | create/publish events — the academy authoring side | — |

**Built vs missing:** the *reads* and *writes* above are built and RLS-correct. **Gaps for the UI:**
- **`GET /my/profile` (read own profile) is NOT built** — only `PUT`. The edit form needs to pre-fill;
  add a tiny self-scoped read.
- **Event detail (`GET /events/{id}`) and directory detail** are not built — the LIST payloads are
  sufficient for v1 (title/time/location; display_name/headline). Note only; not required.

---

## 2. WHO IS A MEMBER — resolved from source: ADULTS (community/network, not minors)

From the role/invitation model, **members are adults**, not minors:
- Members are **invited by email → accept with a password** (a self-managing account). There is **NO
  guardian, NO consent flow, NO school affiliation** — the `member` marker is `role = 'member'`,
  "**network-scoped, not link-derived**" (the RLS comment). Contrast students (guardian-led creation,
  consent gate, minors) and the whole absence of any enrolment/consent machinery for members.
- CLAUDE.md: "**first-generation Kings Network members** — events, RSVP, directory only (OD-1)."

**Decision this fixes:** the directory is a **community (adult) directory** — PII (names), but **not
child-safety-grade**. That said, the built fields are already minimal and conservative (a CHOSEN
`display_name` + `headline`, plus a `visible` opt-out) — no real-name/email/contact — so the privacy tier
is handled by design regardless.

---

## 3. THE DIRECTORY PII BOUNDARY (the review-critical fork — explicit, like B0/B2/B3)

**Authority + RLS (from `member_profiles_read`):**
`system OR ops/audit OR (member AND (visible OR user_id = actor))`. Five-branch:
- a **member** → all **`visible=true`** profiles **+ their own** (even if their own is invisible);
- a **non-member** (student / guardian / teacher / school_admin) → **NOTHING** (the `member` marker gates
  the whole non-system/non-ops branch) — the directory is members-only;
- **ops/audit/super** → all profiles (network oversight);
- **a member who set `visible=false`** → **absent from everyone else's directory, present in their own**
  read (the opt-out);
- **unauthenticated** → nothing (authed surface).

**PII allowlist (what leaves `GET /directory`):** `{user_id, display_name, headline}` — **ONLY** the
member's CHOSEN display name + a one-line headline. **WITHHELD / must NEVER leak:** the user's real
account name (`users.name`), **email**, any contact field, role, links, RSVPs, or any other user row.
The read joins **only `member_profiles`** (never `users`) — so no account PII can leak. **Privacy tooth
(mirror B2):** the directory response's key set is EXACTLY `{user_id, display_name, headline}` per row;
a string-search asserts no `email`/`@`/`users.name`/contact field; red-green if a users-join is added.

**Opt-out — already modelled** (like `schools.public_listing`): `member_profiles.visible`. A member sets
`visible=false` via `PUT /my/profile` to unlist themselves; they still see their own profile. No elevation
— it all resolves in the member's own RLS (**different from B2**, which needed elevation to cross the
tm_read wall; the member RLS is member-friendly by design).

---

## 4. EVENTS + RSVP — a clean write, no interlock

- **Events visibility:** members see **all published** events (network-wide, `events_read`); the academy
  authors/publishes via `/admin/events`. Not scoped per member — a flat network feed.
- **RSVP** (`POST /events/{id}/rsvp`) is a **clean self-write**: status enum, `409` only-published,
  upsert keyed on `(event_id, member_id=self)`. **NO consent, NO capacity, NO money** — events are
  **uncapped** (no seat claim; RSVP is intent, not a ticket). Unlike 成團/attendance, there is no gate to
  surface. A member sees their own RSVP via `GET /my/rsvps` (never another member's).

---

## 5. PROFILE — self-scoped, own record only

`PUT /my/profile` (`role:member`) upserts `member_profiles` for `user_id = the authenticated member`
(`upsertProfile`) — **self-scoped, no elevation, own record only** (no id param to touch another).
Fields: `display_name`, `headline`, `visible`. **The `visible` field FEEDS the directory** — a profile
edit toggles the member's own directory listing (the opt-out of §3). **Add `GET /my/profile`** (self-read)
so the edit form pre-fills — self-scoped, own-row, no elevation.

---

## 6. THE MEMBER-ACCOUNT SEED — members are NOT invitable yet (this card is the trigger)

**Finding (from source):** `InvitationService::INVITABLE_ROLES = ['guardian','teacher','school_admin',
'academy_admin']` — **`member` is EXCLUDED**, and issuing a member invitation throws with the reason
**"Member invitations are not issued until S06 delivers the Member surfaces (OD-22)."** So there is **no
built path to create a member account** — the re-seed confirms **0 members exist**.

**This card IS the OD-22 trigger.** Delivering the member surfaces is exactly the condition the code waits
for. **Recommend:** STEP 1 **enables member invitation** — add `'member'` to `INVITABLE_ROLES`; the
`accept`/`doAccept` path already handles non-teacher roles (the school-vouch branch is teacher-only, so a
member accept just creates the self-managing account). This gives a **real create path** (invite → accept)
AND lets the card seed a demo member by exercising it (or a direct insert for tests). Do **not** soft-block
on S-UX4 — the trigger is here. *(Alternative if the reviewer prefers to keep invitation deferred: seed a
member directly and leave the invitation flag off — but that leaves members uncreatable in production,
which is the actual gap; not recommended.)*

---

## 7. NAV — reveal the member surface (currently a member sees only Dashboard)

- **Events + Directory + Profile** for the member. **Gate: `member_directory.view`** — held by **member**
  (and `academy_admin` base). **NOT `events.view`** (which is held by EVERY role — a student/guardian with
  events.view would see the nav but `events_read` returns nothing for a non-member, a misleading empty
  screen). `member_directory.view` correctly scopes to member + academy oversight.
- **Overlap to flag (decision):** `academy_admin` base also holds `member_directory.view`, so an academy
  admin would see Events/Directory/Profile. Directory/Events as network oversight is arguably correct;
  **Profile** is a member self-surface (an academy admin has no meaningful member profile). Options: (a)
  accept the overlap (academy sees the network directory; their Profile is empty/creatable — minor), or
  (b) gate **Profile** on the member role specifically. **Recommend (a)** for v1 (simplest, minor), flag
  for the reviewer.

---

## 8. Recommended step split + depth

| Step | Scope | Depth |
|---|---|---|
| **STEP 1 — member invitation enablement + directory read authority + self-profile read** | add `'member'` to `INVITABLE_ROLES` (+ confirm the accept path for a school-less member); the **directory PII allowlist** (`{user_id, display_name, headline}`, members-only, visible-only, opt-out) — verified from `member_profiles_read`, no elevation; **`GET /my/profile`** self-read. Tests: member-invitable + accept creates a member; directory members-only (non-member → empty) + visible-only + own-invisible-visible-to-self + **privacy tooth** (no email/users PII); RSVP clean write (409 only-published); profile self-scoped. | **LINE-BY-LINE** (the directory PII boundary + a role-authorization change) |
| **STEP 2 — the member UI + nav** | Events list + RSVP (going/not_going/maybe), the Directory (display_name + headline), the Profile editor (display_name/headline/**visible** opt-out, pre-filled from `GET /my/profile`); reveal Events/Directory/Profile nav on `member_directory.view`. S-UX2a kit; trilingual; darkAlgorithm. | **FRONTEND-SCAN** |

**Screenshots: ZERO** (per the standing rule). The member surface is **reads + clean self-writes** — RSVP
(409 only reachable on a non-published event, which a member never sees) and profile (self-scoped) carry
**no shown-not-hidden refusal on a new write surface** to prove. Tests + diff are the proof.

**Demo member for the re-seed:** once invitation is enabled (STEP 1), seed a demo member via invite→accept
(exercising the new capability) so the surface is walkable; fold into Leo's seed.

---

## Open decisions for the reviewer
1. **Enable member invitation now** (recommend — the code-flagged OD-22 trigger) vs seed-direct-and-defer.
2. **Nav gate** `member_directory.view` (member + academy_admin) — accept the academy overlap on Profile
   (recommend) or gate Profile member-only.
3. **Directory PII allowlist** = `{user_id, display_name, headline}` only, members-only, visible-opt-out
   (recommend as stated — it is the built shape).
4. Event detail / directory detail endpoints — omit for v1 (lists suffice)? (Recommend omit.)

*No code, no schema, no endpoint in this pass — plan only. Awaiting review; then STEP 1 builds.*
