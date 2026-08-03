# CARD — S-UX3-3b · Student team-formation UI

> The STUDENT side of 成團 over the built S05 formation engine. Planning: `PROPOSED-S-UX3-3b.md`
> (approved). Owns the deferred **B2** (member-readable roster). Rulings folded: B2 = names + role +
> count ONLY (consent/enrolment/guardian/money withheld); joinable teams = count only; a tiny student
> self-consent read (self-scoped, no elevation); v1 = NO leave (flagged as a known limitation).

**Build order = review order:** STEP 1 (B2 roster read + self-consent read — child-safety-adjacent
elevation) → STEP 2 (the formation UI). STEP 1 before STEP 2 (the roster is the my-team view's data).

---

## STEP 1 — B2 roster read + student self-consent read (LINE-BY-LINE)

**B2 — `GET /teams/{team}/members`** (`TeamMembersController::index`). Authenticated; authority in-service.
- **Authority (five-branch):** the team is fetched under the caller's RLS (`?? 404`). A caller who can see
  a member row of that team (an **active member**, a **guardian of a member**, or **ops/audit/super**) is
  entitled to **names**; a caller who sees the team but no member row (a **joinable `lobbyWall` forming
  team**) gets a **count only**; a caller who can't see the team → **404**.
- **Elevation — NEW allowlisted `asSystem` (child-safety-adjacent).** `tm_read` walls a student off from
  co-members (a student reads only their own `team_members` row), so returning any teammate data — or an
  accurate count — needs elevation to cross that wall. **Authority is established BEFORE the elevation.**
  One call site, one verbatim-matched reason (`config/scope-elevations.php`).
- **Response — exact allowlist:** entitled → `{team_id, member_count, members:[{student_id, student_name,
  role:{name_en,tc,sc}|null}]}`; joinable non-member → `{team_id, member_count, members:null}` (count
  only, never names). **WITHHELD, never present:** consent status (the ops-only STEP-1 roster), enrolment
  status, guardian identity, payment/obligation — anything family/consent/money.

**Student self-consent read — `GET /my/consent-status?programme_id=X`** (`ConsentRequestController::
selfStatus`, `role:student`). Self-scoped: `studentId = the authenticated student` (no param to name
another). Returns `{satisfied: bool}` from `consentSatisfied` under the **student's own RLS — NO
elevation** (verified: the student sees their own `guardian_links` + `consent_requests`, so the count is
correct). This backs the formation UI's own-consent advisory; it is DIFFERENT from the ops team roster
(STEP 1, OD-39, which 403s guardians/members).

**Mandatory STEP-1 tests:**
1. **Privacy tooth (mirror STEP 1):** the entitled B2 response's key set is EXACTLY `{team_id,
   member_count, members[{student_id, student_name, role}]}` — no consent / guardian / enrolment /
   obligation field anywhere; string-search asserts none present. Red-green: adding a consent/guardian
   field reds it.
2. **Five-branch authority:** an active member → 200 with names; a **non-member** student of a **joinable**
   lobby team → 200 **count-only, no names**; a non-member of a **non-joinable** team → 404; a **guardian**
   of a member → 200 names; **ops** → 200 names; unrelated → 404.
3. **Count correctness:** B2's elevated `member_count` is the TRUE count, where a student's own B1
   (`GET /teams`) read undercounts (tm_read hides co-members) — assert B2 gives N while the student's
   RLS-scoped count would not.
4. **Elevation allowlisted:** `ScopeElevationTest` green, reason-matched. **No new reconcile assertion**
   (reads only) — battery stays 58; state if that changes (it does not).
5. **Self-consent read:** a student reads their own `satisfied`; the endpoint is self-only (no way to name
   another student).

**VERIFY (process rule — written to files, not on request):** the diff to
`~/Downloads/KAP-S-UX3-3b-1-REVIEW-<ts>.diff` and the VERIFY output to
`~/Downloads/KAP-S-UX3-3b-1-VERIFY-<ts>.txt`; paste the gate summary. Battery 58/58; suite green;
migrations 0 (reads only); ScopeElevationTest green. No screenshots (backend reads). Commit HELD.

## STEP 2 — the student formation UI (FRONTEND-SCAN)

- **`/my/team` + a "My Team" nav item** gated `teams.view` (student role default) — distinct from the
  ops `/team` (operations.manage). Roles never stack → a student gets "My Team", an ops admin gets "Team".
- Flow: pick a **lobby** (`GET /programmes/{id}/lobbies`) → **create** (name a team) or **join** a forming
  lobby team (**count only** shown, never names pre-join) → **my-team** view (roster from B2, status) →
  the **self-consent advisory** (own `satisfied` from the self-read; never a teammate's) → **submit**
  (submitter-only). S-UX3-1 mutate/confirm/refuse/refresh; trilingual; darkAlgorithm.
- Submit refusals rendered (shown-not-hidden): `403 "Only the team submitter may submit it"` ·
  `409 "Team is {status}"` · `404`. **One risk screenshot:** the submit-refusal 403 (a non-submitter
  member) — the only shown-not-hidden write refusal on this new surface.
- **v1 = NO leave** (a student is committed once joined until 成團 / ops resolution) — surfaced as a known
  limitation; a student-leave endpoint (with re-pooling state-machine implications) is a **separate future
  backend card**, not built inline.

## Constraints / invariants
- **No migration** (reads only). Battery 58/58. Capacity is a 成團 concern — the formation UI shows **no
  seats/spots** (OD-31). Consent is not gated at formation, only surfaced (own status).
- B2's elevation is the child-safety boundary: names + role + count, nothing consent/guardian/money.
- Roster + count come from B2 (elevated), never B1's student-undercounted `member_count`.

## Definition of done
STEP 1 B2 roster (member-gated + elevated, names/role/count only) + self-consent read, all five tests
green (privacy tooth, five-branch, count-correctness, allowlisted elevation, self-only consent); STEP 2
the formation flow with submit refusals rendered; battery 58/58, suite + build green. AUDIT at the end.
