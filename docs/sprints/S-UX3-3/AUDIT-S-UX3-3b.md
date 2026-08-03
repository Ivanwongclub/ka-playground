# AUDIT KAP-S-UX3-3b — Student team-formation (B2 roster + /my/team)

**Result:** PASS · **Date:** 2026-08-03 · **HEAD at gate:** `2868049`

> Written by Claude Code at the card's end. Honesty outranks looking good. This is the BUILD audit; the
> in-product surface is the student `/my/team`. Planning: `PROPOSED-S-UX3-3b.md` + `CARD-S-UX3-3b.md`
> (this dir). Does NOT rewrite the S-UX3-3a AUDIT (`../S-UX3-3a/AUDIT.md`); forward notes only.

## 0. Scope

The **STUDENT** side of 成團 — the funnel step between `in_pool` (consented, unteamed) and the ops
confirm (S-UX3-3a): a student forms or joins a team, sees their team's roster and their own consent
status, and submits it for 成團. Owns the deferred **B2** (member-readable roster). Two steps:
- **STEP 1** — B2 the member roster read + the student self-consent read (`179658e`).
- **STEP 2** — the `/my/team` formation UI (`2868049`).

Together with S-UX3-3a (ops confirm / roles / resolution) this completes the team story: student forms →
submits → ops confirms → seats claimed + obligations minted.

## 1. Files changed

| Path | A/M | Step · Why |
|------|-----|-----|
| `docs/sprints/S-UX3-3/{PROPOSED-S-UX3-3b,CARD-S-UX3-3b}.md` | A | 1 · think-first + card |
| `api/app/Http/Controllers/TeamMembersController.php` | A | 1 · B2 roster (member-gated + allowlisted elevation) |
| `api/app/Http/Controllers/ConsentRequestController.php` | M | 1 · `selfStatus` — the student self-consent read |
| `api/config/scope-elevations.php` | M | 1 · the new elevation entry (verbatim reason) |
| `api/routes/api.php` | M | 1 · `GET /teams/{team}/members`, `GET /my/consent-status` |
| `api/tests/Feature/TeamMembersRosterTest.php` | A | 1 · 4 tests |
| `web/src/pages/StudentTeam.tsx` | A | 2 · the `/my/team` surface |
| `web/src/{main,nav}.tsx` | M | 2 · route + "My Team" nav item |
| `web/src/i18n/locales/{en,zh-TC,zh-SC}.json` | M | 2 · trilingual `studentTeam.*` + `nav.myTeam` (parity 587) |

## 2. Step-by-step verification

### STEP 1 — B2 member roster + self-consent read · `179658e` (LINE-BY-LINE)
**The finding that shaped the design (verified from `create_teams.php`):** `tm_read` restricts a student
to their OWN `team_members` row (`student_id = actor`) — a student **cannot** read teammates under RLS,
and B1's `member_count` (counted under the caller's RLS) **undercounts** for a student (own team reads 1,
joinable teams read 0). So B2 is **NOT a B3-style in-RLS read** (B3's `tenures_read` admits the holder);
it **requires a NEW allowlisted `asSystem`** to cross the co-member wall.

`GET /teams/{team}/members`: **authority established BEFORE the elevation** — the team is fetched under the
caller's RLS (`?? 404`), and `$seesMembers` (does the caller see a member row under their OWN RLS —
true for an active member / guardian-of-member / ops, false for a joinable-lobby non-member) is computed
**outside** `asSystem`. Only the data-fetch runs elevated. **Response allowlist:** entitled →
`{team_id, member_count, members:[{student_id, student_name, role:{…}|null}]}`; joinable non-member →
`{…, members: null}` (COUNT ONLY, never names). **WITHHELD, never present:** consent status (the ops-only
STEP-1 roster, OD-39), enrolment status, guardian identity, money/obligations — the child-safety allowlist.

**Student self-consent read** — `GET /my/consent-status?programme_id=X` (`role:student`): the student's OWN
`satisfied` boolean from `consentSatisfied`, self-scoped (`studentId = the authenticated student`, no param
to name another), **NO elevation** (verified: the student sees their own `guardian_links` +
`consent_requests` under RLS, so the boolean is correct).
```
Team Members Roster ✔ roster carries ONLY names+role+count — no consent/family/money (exact key allowlist)
                    ✔ five-branch: member/guardian/ops → names, joinable non-member → COUNT ONLY, outsider → 404
                    ✔ count-correctness: B2 elevated count = 2 where the student's B1 RLS read undercounts to 1
                    ✔ student self-consent: self-only satisfied boolean
```
Result: **PASS**

### STEP 2 — the `/my/team` formation UI · `2868049` (FRONTEND-SCAN)
`/my/team` + a "My Team" nav item gated **`teams.view && !operations.manage`** (student surface; hidden
from ops who use `/team` — no dual team-nav). "My team" detected by `member_count >= 1` in the RLS
`GET /teams`; joinable by `member_count === 0 && forming`. The my-team card renders the **B2 roster**
(names + role + count), the **UNMISSABLE own-consent advisory** (a full-width red Alert on own
`satisfied === false`: "your team CANNOT be confirmed until your consent is satisfied — ask your guardian"
— the student-side dead-loop prevention, reading ONLY the student's own self-consent, never a teammate's),
and **Submit** (shown to every member; the server enforces submitter-only — `403` rendered) + the v1
no-leave note. Form-or-join: join a forming lobby team (**count only**, never names pre-join) or create one.
```
tsc --noEmit CLEAN · npm run build bundle-budget PASSED · i18n parity 587/587/587
Risk shot: a non-creator member submits → 403 "Only the team submitter may submit it" (shown-not-hidden)
```
Result: **PASS**

## 3. Assertions registered this card

**None (battery stays 58).** Reads only; no new reconcile assertion. The guarantees are proven by the 4
STEP-1 feature tests (and the privacy tooth mirrors STEP 1's dual tooth). `ReconciliationRunnerTest`'s
count of 58 is unchanged.

## 4. STANDING DEPENDENCY — the names-vs-count split RIDES ON `tm_read`

**Not a defect — a recorded coupling.** The whole B2 privacy split is enforced by the `$seesMembers`
check, which is the caller's **own `tm_read` visibility** of a member row. Because `tm_read` walls a
student off from co-members, a student only "sees members" of a team they are actually IN — so a joinable
non-member correctly gets count-only. **If `tm_read` is ever WIDENED** (e.g. to let students see co-members
across a lobby), `$seesMembers` would start returning **names** to newly-visible non-members, silently
breaking this surface's privacy contract. **Any future change to `tm_read` MUST re-check `TeamMembers
Controller` (and the `/my/team` `member_count >= 1` "my team" detection, which rides on the same wall).**

## 5. Honest items / leftovers

| # | Item | Severity | Home |
|---|------|----------|------|
| 1 | **v1 = NO student leave.** A student is committed once joined (until 成團 or ops resolution) — surfaced honestly in the UI. A student-leave endpoint has **re-pooling state-machine implications** (teamed → in_pool, membership removal, the `already-in-a-team` unique index) and is a **separate future backend card**, not built inline. | flagged | future card |
| 2 | **`derivedStatus` is `role:guardian`-only** (a student could not read their own consent summary) — which **drove adding** the tiny self-scoped `selfStatus` student read (§STEP 1). Recorded so the asymmetry is intentional, not an oversight. | resolved | — |
| 3 | `lobbiesFor` returns `name_en` only (no tc/sc) — the create-lobby picker shows the English lobby name. Data field, not a UI string; a trilingual lobby name is a later polish. | cosmetic | S-UX-POLISH / later |

## 6. Exit gate

```
$ php vendor/bin/phpunit tests/Feature/TeamMembersRosterTest.php tests/Feature/ScopeElevationTest.php
OK (8 tests, 43 assertions)

$ php artisan reconcile:run
RECONCILE PASS — 58 assertion(s), 58 passed, 0 failed

$ php artisan test --exclude-group=clamav        # at STEP 1 (STEP 2 is frontend-only)
Tests:    473 passed (5940 assertions)

$ cd web && npx tsc --noEmit && npm run build     # STEP 2
TSC CLEAN · bundle-budget PASSED · i18n parity 587/587/587
```
**Verdict:** **PASS.** Battery 58/58 (reads only, no runner bump); suite 473/5940; the new B2 `asSystem`
site is allowlisted + reason-matched (`ScopeElevationTest` green); STEP 2 frontend-only (0 backend, 0
migrations). Migrations across the card: **0**.

## 7. Invariant check

| BI | Touched? | Evidence |
|----|----------|----------|
| BI-8 (status transitions audited) | reused | submit (forming→submitted) audits via the built TeamConfirmationService |
| Scope elevation discipline | **yes** | ONE new `asSystem` (B2), allowlisted + verbatim-reason-matched; authority (`$seesMembers`) established OUTSIDE the elevation; `ScopeElevationTest` green |
| Child-safety privacy boundary | **yes** | B2 returns names+role+count only — consent/guardian/enrolment/money withheld (privacy tooth); joinable → count-only; §4 dependency recorded |

## 8. Hand-offs forward
- **`tm_read` dependency (§4)** — a standing re-check trigger for any future `tm_read` widening.
- **Student-leave endpoint** — a separate future backend card (re-pooling state machine).
- **Next per the ruled order: Member surfaces.** It needs a **seeded member account** (0 seeded today;
  members are invitation-only via `InvitationService`), so its think-first must resolve the member-account
  seed alongside the directory PII surface.
