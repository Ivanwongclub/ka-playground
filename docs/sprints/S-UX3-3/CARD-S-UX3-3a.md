# CARD — S-UX3-3a · Ops-facing 成團 core

> UX + read-layer over the built-and-live-audited **S05 teams engine**. Review-critical,
> **child-safety-adjacent** (the per-member consent-status read). Planning: `PROPOSED-S-UX3-3.md` +
> `PROPOSED-S-UX3-3a-consent-endpoint.md` (both approved). Rulings folded: ops-first split; 成團 button
> **enabled + advisory** (server FOR SHARE re-check authoritative, 422 rendered); consent endpoint gets
> S-FIX-level review. S-UX3-3b (student formation view) and S-UX3-4 (attendance) follow separately —
> **not batched**. Reveals the **Team** nav stub.

**Build order = review order:** the backend consent endpoint + its four tests FIRST (the review-critical
core), then the ops 成團 views, then roles/tenures, then resolution. Each step: build → VERIFY → paste
real output → line-by-line review → then the next.

---

## STEP 1 — the consent-status read endpoint (review-critical, child-safety-adjacent)

**1a. `ConsentSigningService::consentSummary(int $programmeId, int $studentId): array`** — the single
source. Returns `{satisfied, requires_all, signed_count, guardian_count, blocker}` where **`satisfied`
DELEGATES to `consentSatisfied(...)` — never re-derived** (read and confirm-time gate agree by
construction). `signed_count`/`guardian_count` are `->count()` on the **same queries** `consentSatisfied`
uses (signed `consent_requests`; active `guardian_links`) — **the ids never leave the method**.
`blocker` ∈ `null | awaiting_signature | stale | not_requested` (a **coded state, not an identity**).

**1b. `GET /teams/{team}/consent-status`** (new controller) — OD-39 authority **in-service, before the
elevation** (lobby school-admin of the team's category's school, academy `operations`/`super_admin`);
then, under a **new allowlisted `asSystem`** (reason: "team consent roster (S-UX3-3a): aggregate
booleans/counts only, no guardian identity leaves the elevation"), maps `consentSummary` per **active**
team member and adds `student_name`. Advisory, read-only; the confirm-time FOR SHARE re-check remains
the authority (docblock states this).

**Response shape — EXACTLY this key allowlist, nothing else** (condition 2):
```
{ team_id, mode ("any-one"|"requires_all"), all_satisfied, blocking_count,
  members: [ { student_id, student_name, satisfied, signed_count, guardian_count, blocker } ] }
```
**FORBIDDEN, must never appear anywhere in the payload:** `guardian_id`, `signer_id`, any guardian name,
per-guardian request rows, request ids, `signed_at`/timestamps, signing order — anything from which a
co-guardian identity or who-signed-when can be inferred. (`student_id`/`student_name` are the MEMBER, the
roster identity — allowed; the child-safety line is guardian identity.)

**1c. Mandatory tests — all four, none optional (condition 3):**
- **(a) Privacy tooth (red-green):** a submitted team; a member with **two active guardians** named
  distinctively (e.g. `"Zeta Guardian"`, `"Omega Guardian"`), one signed / one pending (requires_all
  unsatisfied — the leak-prone path). As academy ops, assert the **serialized JSON** contains **neither
  guardian name nor either guardian id** and **no key matches `guardian`/`signer`**; AND the
  **key-allowlist assertion** — the response's key set is **exactly** the §1 allowlist (any extra key
  fails). The tooth: adding a `signer_id`/guardian name reds it; the shipped endpoint stays green.
- **(b) Five-branch authority:** academy ops/super → 200 full roster; lobby school-admin → 200 that team;
  **unaffiliated school-admin → 404** (RLS-shaped absence, no existence leak); **guardian → 403**;
  member/unauth → 403/401.
- **(c) Teamed-member-unsatisfied blocker:** add a 2nd requires_all guardian (unsigned) to a **teamed**
  member → that member reports `satisfied:false`, `blocker:"awaiting_signature"`, and the team
  `all_satisfied:false`, `blocking_count≥1`.
- **(d) Single-source agreement:** for a spread of members, `member.satisfied == consentSatisfied(programme, student)` exactly.

**Condition 1 (allowlist):** the new `asSystem` site gets its own `config/scope-elevations.php` entry;
`ScopeElevationTest` stays green; the reconciliation **runner-count guard is bumped only if a new
assertion is registered — none is here**, so the runner count is unchanged (58). *(No assertion added:
the endpoint is a read; its guarantees are proven by the STEP-1c tests, not a new reconcile assertion.)*
**Condition 4 (name provenance):** `student_name` is read **under the endpoint's own `asSystem`** (needed
to reach members outside the caller's derived scope) and the response carries **nothing but the
student's own name** — never a guardian's. One provenance, one identity kind (the member).
**Condition 5 (observe, don't fix):** the endpoint **observes and surfaces** the `not_requested` blocker
but **never issues** the missing request — resolving it is the **separate audited path** (the S-FIX
`ReissueConsentOnGuardianActivation` listener / enrolment-creation issuance); this read is not an
issuance trigger and the ops UI offers **no "fix from here" control** that bypasses the audited path.

## STEP 2 — the ops 成團 view (over STEP 1 + team reads)

- **成團 work queue:** list **`submitted`** teams (verified: a team awaiting 成團 sits in `submitted`;
  `confirm` requires `submitted`, else 409). Columns via the S-UX2b names on `GET /teams` (Backend delta
  B1) + member count.
- **Team detail:** members roster (Backend delta B2) with **per-member consent status** from STEP 1 —
  ✅ satisfied / ⚠️ with the coarse reason, and "X of N guardians signed" for requires_all.
  - **Count is the primary signal, blocker is secondary (STEP-1 review note 1).** `signed_count` /
    `guardian_count` render **prominently** as the actionable "X of N signed" gap — the coarse `blocker`
    word is a **subordinate** hint, never the sole signal. Rationale: `consentSummary` collapses a mixed
    team (a stale-only guardian AND a separately-awaiting guardian) to **one** `blocker` by a fixed
    precedence — it picks **`stale` over `awaiting_signature`** — so the single word can under-describe a
    multi-guardian gap; the count cannot.
  - **DECISION (state in build, one line):** the `stale > awaiting_signature` precedence in
    `consentSummary` is **intentional and retained** — `stale` names a supersession the operator must act
    on (a re-issue), which strictly out-ranks a not-yet-signed live request; STEP 2 surfaces the count as
    the truth and does **not** flip it. *(If review disagrees, the flip is a STEP-1 change, not a STEP-2
    one — noted, not silently done.)*
  - **STANDING CONSTRAINT (STEP-1 review note 2) — do NOT enrich the blocker.** The `blocker` loop reads
    `signer_id` **internally only** (never returned); STEP 2 (and every later step) renders **only** the
    STEP-1 allowlist keys — it must **never** expand `blocker` into a per-guardian / who-is-stale
    breakdown, which would reintroduce the guardian-identity leak surface. The STEP-1c key-allowlist +
    string-search privacy tooth stays **green and unchanged**; no STEP-2 code adds a guardian field.
- **成團 confirm (ruling 2a — enabled + advisory):** the button is **shown and ENABLED** (never
  client-disabled). The **consequence-stating confirm modal** (from PROPOSED §6):
  > *Confirm formation of "[team]"? This claims **N seats** against the programme's capacity and issues
  > a **payment obligation for each of the N members**. It cannot be undone here.*
  Plus a **truthful advisory** appended only when STEP 1 shows a blocker: *"⚠️ M members' consent is not
  yet satisfied — 成團 will be refused until resolved."* On click, `POST /teams/{id}/confirm`; the
  server FOR SHARE re-check is authoritative and every refusal is **rendered** (S-UX3-1 error surface),
  refresh after: 409 not-submitted, 422 no-deadline/no-capacity/no-members, **422 consent
  unsatisfied/stale (highlight the blocking member from STEP 1)**, **409 insufficient-capacity /
  lock-contention (no partial)**, 403 OD-39 authority. Success → team confirmed, obligations minted,
  list refreshes.

## STEP 3 — roles / tenures (over Backend delta B3)

- **Current holder** per role — one prominent row ("Captain — Sam Chan (demo)"). **Tenure history** — a
  secondary "Past holders" list (name + start→end), each an **ended** tenure. The display makes a second
  active holder **structurally unrepresentable**: at most one open (no-`end`) tenure per role is ever
  rendered.
- **`assignRole` write** (`POST /teams/{id}/roles`) via S-UX3-1 confirm/refuse/refresh; the confirm copy
  states it is a **tenure change**: *"Assign [role] to [name]? This ends [current holder]'s tenure and
  starts a new one — a role has one active holder."* Refusals rendered (403 authority, 409 wrong state).

## STEP 4 — below-min / matching + resolution (over the matching + capacity reads)

- **Display (read):** open exceptions from `GET /admin/programmes/{id}/matching` +
  `/team-capacity-report` (Backend delta B4/B5 for names): under-strength teams (count vs min), unplaced
  students, `parked_rollforward` exceptions with a **backstop_at countdown**, waivers.
- **Resolve (write):** the four OD-37 terminal actions — `assign`, `extend-grace`, `waive`, `dissolve` —
  + `school-leave` (OD-62), each via S-UX3-1 confirm/refuse/refresh with consequence-stating copy. These
  are **terminal money/enrolment-affecting** actions (`assign` and 成團-adjacent flows **mint a payment
  obligation** → order), so each **ENUMERATES its refusal set + UI surface in VERIFY** — not "per
  convention." **The RAISE stays system-automatic** (the deadline sweep opens exceptions) — **no UI
  trigger.** Refusals (verified from `TeamResolutionService`), all rendered + list-refresh:

  | Action | Refusals → codes | UI surface |
  |--------|------------------|------------|
  | **assign** (place an unplaced student into a below-min confirmed team; **mints an obligation**) | 404 team/enrolment; 409 team not below-min-confirmed; 422 enrolment not `in_pool` (not unplaced); 422 student/team different programme; 422 capacity not configured; 409 **no free seat** ("suspended member still holds theirs — use waiver or dissolve"); 403 OD-37 authority | consequence modal states it **claims a seat + issues that member's obligation**; each refusal rendered with its message; the 409 no-free-seat points to waive/dissolve |
  | **extend-grace** (the grace-once, OD-37) | 404 member; 409 member not `suspended` ("nothing to extend"); **409 grace already extended once** (not repeatable); 403 authority | confirm states grace is **once, not repeatable**; the "already extended" 409 rendered clearly |
  | **waive** | 422 **reason required** (OD-40); 404 team; 409 team not `confirmed`; 403 authority | reason modal (written reason mandatory); 409/403 rendered |
  | **dissolve** | 404 team; 409 team not `confirmed` ("only a confirmed team is dissolved"); 403 authority | consequence modal states it **dissolves the confirmed team**; refusals rendered |
  | **school-leave** (OD-62) | 404 member; **409 exception already open**; 403 authority | confirm states it records the leave + opens the exception; the duplicate-open 409 rendered |

  Common authority refusal: **403 "Below-min resolution is an academy action (OD-37)"** / **403 "requires
  the operations capability"** — surfaced as an authority message, control shown-not-hidden.

## Backend read deltas — EXPLICIT line-items (line-by-line review; not folded silently)

| # | Delta | Endpoint | Change |
|---|-------|----------|--------|
| **B0** | **consent-status endpoint** (STEP 1) | `GET /teams/{team}/consent-status` (NEW) | the review-critical child-safety-adjacent read; `consentSummary` + booleans-only shape |
| **B1** | team-list names | `GET /teams` | additive LEFT-join names: programme, category/lobby, `created_by` (S-UX2b) |
| **B2** | team members read | **NEW** — `GET /teams/{team}/members` (or a team-detail read) | active members: `student_id` + `student_name` + enrolment status (LEFT-join names) |
| **B3** | roles/tenures read | **NEW** — `GET /teams/{team}/roles` | current holder + tenure history per role, names LEFT-joined; one-open-tenure invariant reflected |
| **B4** | matching-screen names | `GET /admin/programmes/{id}/matching` | additive names on bare `student_id`, `enrolment_id` (S-UX2b) |
| **B5** | capacity-report names | `GET /admin/programmes/{id}/team-capacity-report` | additive names on bare `approver_id`, `enrolment_id`, `waived_by` (S-UX2b) |

All additive-name deltas (B1/B4/B5) are LEFT joins, additive keys, count-preserving (S-UX2b pattern),
proven by a names test.

**B2 and B3 are NOT B0's shape — each stated explicitly (verified from the RLS policies in source):**

- **B0 (consent-status)** is **ops-only + elevated**: it exposes consent *across* members who may be
  outside the caller's scope and must return booleans-only — so it gates on OD-39 in-service and reads
  under its own `asSystem`. This is unique to the consent read.
- **B2 (team members / roster).** **(a) Authority gate:** the existing `teams`/`team_members` RLS —
  `teams_read` = `system OR ops/audit OR memberOf (a student ON the team) OR lobby-school-admin OR the
  forming-lobby wall`. The roster is therefore **member-readable** (a student sees their own team), not
  ops-only. **(b) Elevation: NONE** — it resolves **within the caller's RLS**; **no `asSystem`.** The
  member's `student_name` rides **`users_read`'s own gate** (double-gated, S-UX2b #15): it resolves for
  ops/super, and is **NULL for a lobby school-admin viewing a cross-school member** (users_read admits
  only their own school's students) — expected, not a bug. **Own authority test:** a team MEMBER reads
  their own team's roster (200); a non-member student → RLS-shaped absence; ops → any team; lobby-admin →
  their lobby's team.
- **B3 (roles / tenures).** **(a) Authority gate:** the existing `tenures_read` RLS — `system OR
  ops/audit OR student_id = actor (the HOLDER) OR the holder's active guardian OR lobby-school-admin`.
  So a **holder sees their own tenure and a guardian their child's**, while **ops / lobby-school-admin
  see the full team's tenures**. **(b) Elevation: NONE** — resolves **within the caller's RLS**; **no
  `asSystem`**; holder `student_name` rides `users_read` (double-gated). **Note:** because a student sees
  only their *own* tenure under RLS, the **full-team roles roster in S-UX3-3a is an ops / lobby-admin
  view**; the student's own-role view belongs to S-UX3-3b. **Own authority test:** ops → full team roles;
  a holder → their own tenure; a guardian → their child's; an unaffiliated caller → RLS-shaped absence.

Each of B0/B2/B3 therefore gets **its own authority test** — B0's five-branch (ops-only, guardian→403,
unaffiliated-school-admin→404), B2's member-readable branch set, B3's holder/guardian/ops branch set —
**not one inherited from B0.** Each delta is its own review line-item.

## VERIFY expectations (condition 6)

- **Battery 58/58, UNCHANGED** — no assertion touched (the endpoint's guarantees are the STEP-1c tests).
- **Suite green** (ex-clamd); the four STEP-1c tests + a names test for B1/B4/B5 green with pasted output.
- `cd web && npx tsc --noEmit && npm run build` green — i18n parity (all new labels trilingual), no
  hardcoded strings.
- `ScopeElevationTest` green (the new `consentSummary` `asSystem` allowlisted, reason-matched).
- **Tiered screenshots — RISK SHOTS only, not the full gallery:**
  1. **成團 confirm with a blocking member** — the modal open showing the truthful advisory ("M members'
     consent not yet satisfied") over a member marked ⚠️ by name.
  2. **The 422 refusal rendered** — attempting 成團 on that team → the server's "consent not satisfied"
     surfaced (control was shown-not-hidden).
  3. **A clean 成團 success** — a team with all members satisfied → confirmed, obligations minted, queue refreshes.
  4. **Guardian-403** — a guardian hitting `/teams/{team}/consent-status` (or the 成團 control's surface)
     → authority refused (the roster is not their surface).
  5. **One roles tenure-change confirm** — the assignRole modal stating it ends the current holder's tenure.

## Constraints / invariants

- **Server remains the authority.** 成團 button enabled + advisory; the FOR SHARE re-check + 422 is the
  gate. Nav-hiding/enablement is never the control.
- **Child-safety privacy holds by the test** (STEP 1c-a) — no guardian identity leaves the endpoint.
- **No new write on the consent path** — the read observes `not_requested`; issuance stays the audited
  S-FIX path (condition 5). No migration, no schema change; the only backend logic is `consentSummary`
  (single source) + the additive-name joins + the two new roster reads.
- darkAlgorithm; Design System v2.1; S-UX2a display kit (StatusTag/formatHkt/DataBoundary) + S-UX3-1
  write conventions (mutate/ReasonModal/confirm-error-refresh).

## Definition of done

STEP 1 endpoint + all four mandatory tests green (privacy tooth red-green, five-branch authority,
teamed-unsatisfied blocker, single-source agreement); STEPS 2–4 drive 成團 / roles / resolution through
the enabled-advisory + shown-not-hidden conventions with every refusal rendered; backend deltas B0–B5
each reviewed as a line-item; battery 58/58, suite + build green; the five risk screenshots captured.
Then plan → build (review order) → VERIFY → **line-by-line review** → commit. `AUDIT.md` at the end.
