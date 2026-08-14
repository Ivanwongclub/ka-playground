# A-series (delegation spine) — deferred follow-ups

Durable register for work deliberately deferred while building the delegation safety spine (A-1…A-4). Each
item was ruled out of its originating card on purpose — recorded here so the terminus isn't lost. Nothing
here is a defect; each is a deliberate next step with its own ruling.

## Status of the spine (built, HELD)

| Card | What shipped |
|------|--------------|
| A-1  | Delegable-capability catalogue (`config/delegable-capabilities.php`) + `authz.delegable_catalogue_integrity` drift assertion — the hard safety spine (never-delegable set). |
| A-2  | `school_authority_grants` + `programme_authority_overrides` tables + RLS (system-write only) + `AuthorityGrantService` (validates ∈ A-1 delegable) + `authz.delegation_grants_valid` assertion. |
| A-3  | `EffectiveCapabilityResolver` (request-wide GUC + per-programme) + ScopeContext delegated-cap derivation for school_admin/teacher. |
| A-4  | **Additive delegated READ arms — COMPLETE at three domains:** `teams_read`, `ps_read` (sessions), `assessments_read` + `assessment_results_read` (embargoed). |

**A-4 additive read-arm phase is complete.** Registration (structural-school, no programme dimension) and
withdrawal (guardianship surface — the withdrawal *reason* is family-private) were **skipped**: not every
domain has a delivery-shaped per-programme delegable read, and forcing one would be a dead arm or a privacy
expansion. Delivery surfaces (schedule/roster/released grades) got arms; structural + guardianship surfaces
did not.

**A-5 (formed-team carve-out) was assessed and DEFERRED to C-1** — the "confirmed-team membership platform-only"
invariant already holds de-facto via service-gating, and making it structural needs a `BYPASSRLS` role in the
highest-stakes deploy file, which belongs with the edge write path (C-1) it constrains. See A-5-deferred below —
it is a **hard precondition on C-1**, not a floating TODO.

## Deferred cards

### A-5-deferred — formed-team structural membership lock (⚠ MUST bundle with C-1)
**A hard precondition on C-1, not a floating TODO.** The immovable "confirmed-team membership is
platform-only" currently holds **DE-FACTO via service-gating** (FormationService forming-only;
Matching/Resolution `asSystem`; `tm_update` system-only; no `tm_delete`) — **NOT structurally at RLS**.
`tm_insert`'s student arm is status-**UNBOUNDED** (a confirmed-team self-add is service-prevented, not
RLS-prevented). Today no reachable path violates the invariant.

C-1 (`team_change_requests` / composition editor) introduces the **first EDGE write path** to team
membership — at that point the structural lock becomes load-bearing and **MUST ship in the same change**:
- **(a) DEPLOY-INFRA:** provision `kap_rls_reader` (`NOLOGIN BYPASSRLS`, `GRANT SELECT ON teams` only) in
  `deploy/gcp/sql/01-roles-and-grants.sql` + local dev/test role creation + the deploy `GRANT EXECUTE`/
  `REVOKE PUBLIC` — a deliberate, reviewed exception to that file's NOBYPASSRLS-everywhere property, with its
  own `rls-proof` review. (Established finding: `teams` is FORCE RLS owned by a NOBYPASSRLS role, and both
  existing roles are NOBYPASSRLS, so nothing can bypass `teams` RLS today; a migration run as `kap_migrate`
  (NOSUPERUSER) cannot mint a BYPASSRLS role — only the deploy superuser in this file can.)
- **(b) APP:** `SECURITY DEFINER team_is_pre_confirmed(uuid)` owned by `kap_rls_reader` (`STABLE`,
  `search_path=''`, schema-qualified `public.teams`, `EXECUTE` granted to `kap_app` only), tighten `tm_insert`
  student arm to require `team_is_pre_confirmed(team_members.team_id)`, + the `authz.formed_team_platform_only`
  assertion (60→61). NB: an inline `EXISTS(teams …)` in `tm_insert` **RECURSES** (`tm_insert` → `teams_read`'s
  `memberOf` arm → `team_members`, a plan-time 42P17) — the SECURITY DEFINER function is the required break, and
  it needs the BYPASSRLS owner from (a) to actually bypass `teams` RLS.

**C-1's edge write path MUST NOT open a team-membership write without (a)+(b) landing in the same commit** —
otherwise C-1 could open a confirmed-team write the RLS does not forbid. Reviewer sees the lock and the thing
it locks in one diff.

### A-3-follow — deny-wins canonical for the multi-school edge
The per-programme resolution of **conflicting same-level (school-specific) overrides**, for an actor active at
**≥2 schools**, currently diverges: `EffectiveCapabilityResolver::capabilitiesForProgramme` (PHP) resolves
**last-wins**; the A-4 RLS `heldFor` SQL resolves **grant-wins-at-specific-level**. They **agree for
single-school actors** (all current tests). Canonical rule is **DENY-WINS**. Adopt it in **both** the PHP
resolver and every A-4 `heldFor`/`heldForResults` SQL block (teams, sessions, assessments) so they can never
drift. Low-stakes (rare edge), but pin it before more actors go multi-school.

### A-8 — re-base structural access onto delegated caps (Reading 1 tightening)
Make the existing **role-based** edge access *require* a delegated cap instead of role alone — the deferred
"Reading 1" tightening. Needs a **baseline-grant seed migration** first (seed every existing school its
baseline grants) or it's a regression. Folds in the cap-gating of:
- `teams_read` `lobbySchoolAdmin` (today: role + lobby, no cap),
- `wr_read` `$schoolOfStudent` (today: school_admin reads its roll's withdrawals, role-based),
- registration `$routedSchool` (today: school_admin reads its routed requests, role-based).
Its own card because the seed's correctness deserves dedicated review, and it changes live access (not additive).

### A-9 — assessment-grading delegation (Reading (i))
A delegated school seeing **unreleased** grades (rejected for A-4 assessments, which is embargoed / Reading (ii)).
Requires: a **new `assessment.grade` permission** (permission-matrix + migration), its **delegability +
embargo-bypass** ruling, and the **grade WRITE arm** (a delegated grader must see unreleased results *to grade* —
read-unreleased + write are the coherent bundle). Deliberately ruled, not an additive read arm. The A-4
assessments read arm (released-only) forecloses nothing here.

### A-10 — co-running-school withdrawal visibility (if ever wanted)
A delegated school reading a **delegated programme's withdrawals** (constructible via `enrolment_id →
enrolments.programme_id`, gated on `enrolment.view`). **Not** an automatic additive arm: a withdrawal is a
**guardianship surface** — the `reason` (why a child is leaving) is family-private, and RLS is row-level (can't
hide the column). A deliberate card weighing reason-privacy (parallel to A-9 for grades), only if co-running
schools genuinely need programme-scoped withdrawal visibility. The school's correct withdrawal scope today is
its own roll (`$schoolOfStudent`), which it already has.
