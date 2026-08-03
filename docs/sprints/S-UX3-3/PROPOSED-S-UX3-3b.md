# PROPOSED — S-UX3-3b · Student team-formation UI (think-first)

> **Plan only — no code, no commit.** The STUDENT side of 成團: a student forms or joins a team, sees
> their team, and submits it for the ops 成團 (S-UX3-3a). It also OWNS the deferred **B2** (the
> member-readable team roster the ops consent-read didn't need). Sourced from the built S05 formation
> engine + the teams/team_members RLS. Process rules in effect: at build, diff + VERIFY-output written to
> `~/Downloads` files; depth stated per step.

---

## 1. The formation engine as built (S05) — what exists, what a UI needs

All `role:student`-gated, all present:
| Action | Endpoint | Built behaviour |
|---|---|---|
| List lobbies | `GET /programmes/{programmeId}/lobbies` | `FormationService::lobbiesFor` — the lobbies this student may form/join in (open + their-school-bound). |
| Create a team | `POST /my/teams` `{programme_id, category_id, name}` | needs a **pooled (`in_pool`) enrolment** + an **eligible lobby**; inserts `status='forming'`, adds the creator as member, moves their enrolment `in_pool → teamed`. Returns `{id, status}`. |
| Join a team | `POST /teams/{id}/join` | team must be `forming` (else 409); same pooled-enrolment + lobby-eligibility checks; adds membership, `in_pool → teamed`. |
| Submit for 成團 | `POST /teams/{id}/submit` | **only `created_by` may submit** (403 else); must be `forming` (409 else); `forming → submitted`. |
| Read teams | `GET /teams` | RLS-shaped (FormationController::index, B1 names + member_count). |

**Not built (gaps — §5):** there is **no student "leave / cancel membership" endpoint** (only the ops
`/admin/team-members/{id}/school-leave`, OD-62). A student who joins a forming team **cannot leave it** —
the `addMember` unique index (`already in a team for this programme`) also blocks re-teaming. And there is
**no student-facing `derivedStatus`** (the self-consent summary is `role:guardian`, §4).

**What a UI needs beyond the above:** a **roster read** — "who is on my team." `GET /teams` gives the
student their team ROW, but not a usable roster (see §2 — RLS hides teammates). That is B2.

---

## 2. B2 — the member-readable roster — AUTHORITY + ELEVATION (the review-critical fork)

**The finding that drives everything (verified from `create_teams.php`):** the `team_members` read policy
`tm_read` is:
```
system OR opsAudit OR student_id = actor OR student_id = ANY(student_ids /*guardian's children*/)
  OR (school_admin AND the row's lobby is in my schools)
```
**A student can read ONLY their OWN `team_members` row** (`student_id = actor`) — **not their teammates'**.
Consequences:
- A student querying their team's roster under RLS gets back **only themselves**.
- **B1's `member_count` is WRONG for a student:** `FormationController::index` counts `team_members` under
  the caller's RLS, so for a student it counts only their own visible rows — their own team reads
  `member_count = 1`, and joinable lobby teams read `0`. The ops STEP-2 count is correct (ops RLS sees
  all); the **student count is not**.

So the roster a student needs is **not satisfiable by a plain RLS read** — `tm_read` deliberately walls a
student off from co-members. B2 therefore requires an **explicit decision**, stated (the fork we resolve
every time):

**(a) Authority.** The caller must be an **active member of the team** (a `team_members` row with
`student_id = actor`, status ≠ removed) — verified in-service against their own (RLS-visible) membership.
Five-branch:
- a **member** of the team → 200, the roster (elevated, see below);
- a **non-member student** → 404 (RLS-shaped absence — they can't see the team either, unless it's a
  joinable `lobbyWall` forming team, for which they get **counts only**, never names — §2c);
- the member's **guardian** → 200 (guardian reads their child's team; `student_id = ANY(student_ids)`);
- **ops/audit/super** → 200 (already have the ops path, but B2 admits them too);
- **unauthenticated / unrelated** → 404.

**(b) Elevation — YES, a NEW allowlisted `asSystem`, and this is child-safety-adjacent.** Because
`tm_read` hides teammates, returning any teammate data requires elevation past the caller's RLS. The
**privacy scope is the whole point** — a minor seeing other minors' data:
- **Returned:** teammate **display name only** (+ their team **role/captain** if held, from the tenure
  ledger) and the **accurate member count**. The member's own name rides `users_read`; teammate names ride
  the elevation.
- **WITHHELD (never returned to a member):** **consent status** (that is the ops roster, STEP 1
  `TeamConsentStatusController`, OD-39 ops/lobby-admin only — it 403s guardians and members by design;
  a teammate must NEVER see a co-member's consent/guardian situation), **enrolment status**, **guardian
  identities**, **payment/obligation state**, **contact info**. Names + roles, nothing family/consent/money.
- The elevation reason is verbatim-allowlisted (`ScopeElevationTest`), stating "team roster names only, no
  consent/guardian/enrolment/money — a member-scoped read past tm_read's own-row wall (child-safety
  allowlist)." A **privacy tooth test** asserts the serialized response carries no consent/guardian/
  enrolment/obligation field — mirroring STEP 1's dual tooth.

**(c) Joinable-team counts vs own-team roster.** For a team the student is **not** in but **may join**
(`lobbyWall` forming teams in their lobby), showing full **names** is a pre-join privacy question — a
student shouldn't see who is in a team before joining. **Recommend: joinable lobby teams expose a COUNT
ONLY (n / min), never names; the full name-roster is only for a team the caller is an active member of.**
So B2 serves two shapes: `own/member team → {members:[{name, role?}], count}`; `joinable team →
{count}` only.

**This is a genuinely DIFFERENT read from STEP-1 (B0) and STEP-3 (B3):** B0 was ops-only + elevated +
consent booleans; B3 was member-readable-within-RLS + no elevation (tenures_read admits the holder). B2
is **member-gated + REQUIRES elevation** (tm_read does *not* admit co-members) and returns **names only,
no consent**. State it explicitly; it gets its own authority + privacy tests.

---

## 3. The formation state machine, as the student experiences it

| State | What the student can DO | Read-only |
|---|---|---|
| **forming** (assembling) | others JOIN; the **creator/submitter** may SUBMIT (forming→submitted). A member **cannot leave** (no endpoint — §5). | roster (B2), own consent status (§4) |
| **submitted** (awaiting 成團) | **nothing** — waiting on ops. JOIN → 409 ("no longer accepting members"); SUBMIT → 409 ("Team is submitted"); no un-submit. | roster, status "awaiting 成團" |
| **confirmed** | proceed to **pay** their own obligation (existing money surface). | roster, "confirmed", their obligation |
| **disbanded** | (ops dissolved it) — re-pool per OD-38; the student returns to the pool. | status |

**Submit refusals (from `TeamConfirmationService::submit`), each rendered (shown-not-hidden):**
`403 "Only the team submitter may submit it"` (a non-creator member) · `409 "Team is {status}"` (already
submitted/confirmed) · `404` (gone). The submit button is **shown to all members but the server enforces
submitter-only** — consistent with the S-UX3-1 convention.

---

## 4. Consent visibility to the student — self-scoped ONLY, never the team roster

- **Self-scoped read exists:** `GET /my/students/{studentId}/consent-status` →
  `ConsentRequestController::derivedStatus` (`role:guardian`) → `ConsentSigningService::derivedStatus` —
  the **guardian's own child** booleans. This is **DIFFERENT** from the ops team roster
  (`GET /teams/{team}/consent-status`, STEP 1, OD-39, **403s guardians**). Confirmed: **the student/guardian
  side reads ONLY their own consent, never the team-wide ops roster.**
- **The formation UI surfaces the student's OWN consent status** (an advisory: "your consent is/ is not
  satisfied — 成團 needs it") so the student isn't blindsided when ops 成團 refuses on unsatisfied/stale
  consent. It uses the self-scoped read; it **never** shows a teammate's consent.
- **Decision (a real gap):** `derivedStatus` is **`role:guardian`-only** — a **student** cannot call it.
  The student needs *some* "is my consent satisfied" signal in the formation UI. Options: (i) add a
  student-accessible self-consent-satisfied read (self-scoped, own enrolment only); (ii) surface it only
  to the guardian and show the student a neutral "your guardian manages consent"; (iii) the student reads
  their own `consent_requests` (cr_read admits them) and the UI derives satisfied. **Recommend (i)** — a
  tiny self-scoped student read — decided at build; flag it.

---

## 5. Interlocks

- **Pool:** create/join **requires an `in_pool` enrolment** and moves it `in_pool → teamed`. `in_pool` is
  reached only *after* the consent gate (S04A), so a forming member **was** consented at pool-entry; the
  advisory (§4) covers the **superseded-since** case (a new template version lapsed their consent — the
  成團 re-check would then refuse). A student with no `in_pool` enrolment gets `422 "You must have a
  consented, unteamed enrolment…"` — the UI must route them to enrol/consent first.
- **Capacity:** seats are claimed at **成團, not formation** (OD-31). Forming/joining claims **no seat**;
  the forming team consumes no capacity until ops confirms. So the formation UI shows **no "seats/spots"**
  — capacity is a 成團 concern (consistent with omitting it on the ops advisory).
- **Consent gate:** a student **can** sit in a forming team with (later-)unsatisfied consent — formation
  does **not** gate on consent; only 成團 does (the FOR SHARE re-check). Surfaced via the self-scoped
  advisory (§4), never blocked at formation.
- **member_count correctness (§2):** the student UI must take roster + count from **B2 (elevated)**, not
  B1's RLS-undercounted `member_count`.

---

## 6. Nav — a student-facing surface, distinct from the ops Team nav

- The ops `/team` nav is `operations.manage`-gated (S-UX3-3a) and renders the ops 成團 queue — **not** for
  students.
- **S-UX3-3b reveals a distinct student surface** — recommend a **new route `/my/team`** + a **"My Team"**
  nav item, gated on **`teams.view`** (the student role default; also held by teacher/school_admin/ops, but
  the surface is the student-formation flow — teachers/school-admins have their own team surfaces later).
  Since roles are never stacked, a `student` account gets **"My Team"**, an `operations` admin gets
  **"Team"** — two items, two surfaces, no overlap on one account.
- Reveal it in `nav.tsx` under the programme group, `visible: (h) => h('teams.view')`.

---

## 7. Recommended step split + review depth

| Step | Scope | Depth |
|---|---|---|
| **STEP 1 — B2 the member roster read** | `GET /teams/{team}/members` (or `/roster`): member-gated authority + the **new allowlisted `asSystem`**; **names + roles + count only**, consent/guardian/enrolment/money **withheld**; own-team → names, joinable lobby team → count only. Tests: five-branch authority, the **privacy tooth** (no consent/guardian/enrolment field), count-correctness (the elevated count ≠ B1's RLS undercount for a student). | **LINE-BY-LINE** (new elevation + child-safety privacy boundary) |
| **STEP 2 — student formation UI** | `/my/team` + "My Team" nav (`teams.view`): pick a lobby (lobbiesFor) → **create** (name) or **join** a forming lobby team (counts only) → **my-team** view (roster from B2, status) → **self-consent advisory** (§4) → **submit** (submitter-only, refusals rendered). S-UX3-1 mutate/confirm/refuse/refresh; trilingual; darkAlgorithm. | **FRONTEND-SCAN** |
| *(decision, in STEP 1 or 2)* | the **student self-consent read** (§4 recommend (i)) — tiny self-scoped backend if chosen → **line-by-line**. | — |

**Sequencing:** STEP 1 before STEP 2 (the roster is the my-team view's data). One risk screenshot in
STEP 2 (per the standing rule): the **submit refusal** — a non-submitter member's `403 "Only the team
submitter may submit it"` rendered (shown-not-hidden on a new write surface). No other screenshots.

---

## Open decisions for the reviewer
1. **B2 privacy scope (§2b):** confirm **names + role/captain + count only**, consent/guardian/enrolment/
   money withheld — the child-safety boundary. (Recommend as stated.)
2. **Joinable-team visibility (§2c):** count-only for teams you may join, names only for your own team.
   (Recommend as stated.)
3. **Student self-consent read (§4):** add the tiny self-scoped student read (recommend), vs guardian-only.
4. **Leave/cancel membership (§1/§5):** NOT built. v1 = no leave (a student is committed once joined until
   成團 or ops resolution), or add a student "leave a *forming* team" endpoint (new backend — its own
   small line-by-line)? Recommend **v1 = no leave**, flagged, revisit if the client wants it.

*No code, no schema, no endpoint in this pass — plan only. Awaiting review; then STEP 1 builds.*
