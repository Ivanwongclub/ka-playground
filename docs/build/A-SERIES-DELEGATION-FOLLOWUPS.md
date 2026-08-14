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

## Deferred cards

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
