# PROPOSED — S-UX3-3 (Teams / 成團 UI) · think-first, no code

> Mandatory pre-code pass. UX surface over the built-and-live-audited **S05 teams/成團 engine**. The
> hazard: 成團 confirm is a NEW WRITE surface that must render the **consent gate** — the exact shape
> that produced the S-FIX dead-loop. This plan reasons about consent states **before any code**.
> **Headline finding (§5/§7):** the S05 engine exposes WRITE endpoints + audit reports but **almost no
> ops-facing READ views** — this card needs a **new backend read layer**, and the per-member consent
> status has **no endpoint at all**. This is not "display over proven endpoints."

## 0. What the engine actually is (verified from source)

- 成團 = `POST /teams/{id}/confirm` → `TeamConfirmationService::confirm`. Under one elevation it: locks
  members' `consent_requests` **FOR SHARE** + `guardian_links` FOR SHARE, **re-verifies
  `consentSatisfied` per member under the lock**, locks `programme_capacity` **FOR UPDATE**, checks
  capacity (no partial), transitions each member `teamed→confirmed`, and **mints one
  `payment_obligation` per member** (→ `ConsumePaymentObligations` → orders + PaymentRequested).
- Below-min / deadline = `MatchingController::screen` (read) + `TeamResolutionController` four terminal
  actions (`assign`, `extend-grace`, `waive`, `dissolve`) + `school-leave` (OD-62). Exceptions are
  raised by the **system** deadline sweep (`team_exceptions`, type `parked_rollforward`), not by a user.
- Roles = `RolesTrackerController::assignRole` (`POST /teams/{id}/roles`) — **write only, no GET**.

## 1. Consent-gate states the UI must represent at the 成團 moment

The server's authority is `consentSatisfied(programmeId, studentId)` re-checked **per member under the
FOR SHARE lock**, plus the nightly `teams.consent_complete_at_confirm` (which judges confirm-time facts,
incl. the requires_all "every guardian active-as-of-confirm signed, non-stale" branch). Modes come from
the programme's `consent.requires_all_guardians`. **成團 is offerable only when EVERY member is
satisfied; one unsatisfied member blocks the whole team.**

| Mode | Member consent state | `consentSatisfied` | UI shows (per member) | 成團 (team-level) |
|------|----------------------|--------------------|------------------------|-------------------|
| any-one | ≥1 active guardian **signed** | **true** | ✅ Consent complete | offerable if all members ✅ |
| any-one | request(s) **pending**, none signed | false | ⚠️ Awaiting a guardian signature | **blocked** |
| any-one | signed then **superseded** (material change) | false (stale) | ⚠️ Consent superseded — re-signature needed | **blocked** |
| any-one | **not-yet-requested** (edge / reissue in flight) | false | ⚠️ Consent not issued yet | **blocked** |
| requires_all | **all** active guardians signed, non-stale | **true** | ✅ All N guardians signed | offerable if all members ✅ |
| requires_all | some signed, some **pending** | false | ⚠️ X of N guardians signed | **blocked** |
| requires_all | a signed one **superseded** | false | ⚠️ A guardian's consent is stale | **blocked** |
| requires_all | **late guardian added, unsigned** (S-FIX reopen) | false | ⚠️ New guardian added — awaiting their signature | **blocked** |

Notes that must shape the UI:
- **The UI must not imply success.** A member row shows its consent state from the ops read (§5); the
  team's 成團 affordance shows a truthful summary ("N of M members' consent satisfied"). But the
  **live FOR SHARE re-check can flip between render and click** (a concurrent supersede / late-guardian
  reissue). So the UI is **advisory, the server is authoritative** — see the decision in §2.
- **Privacy invariant (child-safety):** the ops read must expose **booleans/counts only** — satisfied /
  outstanding / "X of N guardians" — **never which guardian signed** (co-guardian rows are RLS-hidden;
  `derivedStatus` already returns booleans only for this reason). The new consent-status endpoint (§5)
  must aggregate, not enumerate signers.
- The "in_pool→pending_consent reopen" from S-FIX applies to `in_pool`; a **teamed** member is not
  state-regressed, so it can still be a team member while its consent is unsatisfied — the UI shows the
  ⚠️ and 成團 is blocked until the guardian signs. This is exactly the dead-loop shape; the UI's job is
  to make the blocking member visible so the ops operator resolves it, not to hide it.

## 2. Server refusals and how the UI surfaces each (control shown, never client-hidden)

Every mutating control follows the S-UX3-1 convention: **shown to the authorised nav; the server's
refusal is rendered; nothing pre-hidden.** 成團 refusals (all confirmed in
`TeamConfirmationService`):

| Refusal | Code | UI surface |
|---------|------|------------|
| Team not in `submitted` | 409 | "This team is no longer awaiting confirmation." + refresh |
| No formation deadline (OD-33) | 422 | server message |
| Approver lacks OD-39 authority (not lobby school-admin / ops / super) | 403 | "You don't have authority to confirm this team." |
| Capacity not configured (OD-31) | 422 | server message |
| No active members | 422 | server message |
| **Member consent unsatisfied / stale (OD-57/58)** | **422** | render the server message; **highlight the blocking member(s)** from the §5 read |
| **Insufficient capacity — no partial claim (OD-32)** | **409** | "Not enough seats: X/Y claimed; this team needs N." |
| **Capacity-lock contention** (two ops confirm at once; FOR UPDATE serialises → loser runs out) | **409** (insufficient capacity) | same as above; the loser sees the 409, list refreshes to show seats claimed |

Resolution / roles endpoints likewise surface their refusals (409 wrong state, 403 authority, 422
below-min config). **Decision to flag for the reviewer:** for 成團, do we (a) keep the button **enabled**
and rely on the server 422/409 (S-UX3-1 purity — no client gate that can diverge from the FOR SHARE
re-check), showing a **truthful advisory** ("2 members' consent not yet satisfied — 成團 will be
refused until resolved") in the confirm modal; or (b) **disable with a visible reason** when the ops
read shows a blocking member. **Recommendation: (a)** — a client-side disable would imply the client
knows the locked outcome (it doesn't), and could hide a control the operator must see; the advisory +
surfaced refusal tells the truth without a divergent gate.

## 3. Roles / tenures display — one active holder invariant

`assignRole` enforces **one active holder per role** (assigning a new holder ends the prior tenure).
The UI must render this so a second active holder is **structurally unrepresentable**:
- **Current holder** per role — one row, prominent ("Captain — Sam Chan (demo)").
- **Tenure history** — a secondary, clearly-labelled "Past holders" list (name + start→end), each an
  **ended** tenure. History is read-only and never shows two open (no `end`) tenures for one role.
- Assigning a new holder (write) states in its confirm that it **ends the current holder's tenure**
  (one active holder — the replacement is a tenure change, not an addition). Badges mint from
  **completed** tenures (S08) — display only here.
- **Backend delta:** there is **no GET for roles/tenures** — a read endpoint is required (§5).

## 4. team_exceptions (FR066) surfacing — display + the resolution actions (ruled)

- **Raise is system-driven** — the deadline/matching sweep opens `parked_rollforward` (and kin)
  exceptions; **not a UI action.** Out of scope to trigger.
- **Display is in scope** — the below-min / matching view renders open exceptions (from
  `MatchingController::screen` / `TeamCapacityReportController`): under-strength teams (member_count vs
  min), unplaced students, parked-rollforward exceptions with their **backstop_at** countdown, and
  waivers.
- **Resolve is in scope** — the four OD-37 terminal actions (`assign`, `extend-grace`, `waive`,
  `dissolve`) + `school-leave`, each via the S-UX3-1 confirm/refuse/refresh convention. **Ruling:
  exceptions are read/display + the resolution actions are write; the RAISE stays system-automatic.**

## 5. Endpoints behind each view — and the backend deltas (call out, do NOT fold silently)

| View | Existing endpoint | Gap |
|------|-------------------|-----|
| Team list / formation | `GET /teams` → `id, programme_id, category_id, name, status, created_by` | **bare FK IDs** → S-UX2b additive names (programme, category/lobby, created_by); **AND no members** |
| Team detail / members | **none** | **NEW read** — a team's members (student names, enrolment status) |
| **Per-member consent status (成團 gate)** | **none** — `consentSatisfied` lives only inside `TeamConfirmationService` | **NEW read, the critical one** — ops-facing, **booleans/counts only** (privacy §1); this is the child-safety-adjacent endpoint |
| Roles / tenures | **none** (`assignRole` is write-only) | **NEW read** — current holder + tenure history per role |
| Below-min / matching | `GET /admin/programmes/{id}/matching` → bare `student_id`, `enrolment_id` | S-UX2b additive names |
| Capacity / exceptions | `GET /admin/programmes/{id}/team-capacity-report` → bare `approver_id`, `enrolment_id`, `waived_by` | S-UX2b additive names |

**These backend deltas each need line-by-line review** — especially the **per-member consent-status
endpoint** (consent semantics + the boolean-only privacy rule) and the **team-members/roles reads**
(RLS: which callers may see a team's roster). This is the core of §7.

## 6. Consequence-stating 成團 confirm copy

成團 allocates seats at the capacity lock and mints a payment obligation per member — the confirm must
say so, not "Are you sure?":

> **Confirm formation of "[team name]"?** This claims **N seats** against the programme's capacity and
> **issues a payment obligation for each of the N members**. It cannot be undone here. *(If any member's
> consent is not yet satisfied, 成團 will be refused until resolved.)*

The parenthetical appears only when the §5 read shows a blocking member (the truthful advisory, §2a).

## 7. Interlocks / scope conflicts found (the point of this pass)

1. **The read-layer interlock (biggest).** S05 shipped the 成團/resolution/roles **writes** + audit
   **reports**, but not the **ops read views** a UI needs: team members, **per-member consent status**,
   roles/tenures. This card is therefore **backend + frontend**, not display-only. The consent-status
   endpoint is **consent/child-safety-adjacent** (the S-FIX shape) and must return **booleans/counts
   only** — it deserves its **own line-by-line review**, and arguably a short think-first of its own if
   the reviewer wants (recommend: fold it in here but review it as carefully as S-FIX).
2. **Scope breadth.** The card mixes a **student-facing formation view** (create/join/submit) with an
   **ops-facing 成團 + resolution view** (confirm/assign/waive/dissolve/roles). These are two audiences
   and two nav treatments. **Flag: consider splitting** — S-UX3-3a (student formation + team/roles
   display, reveals the Team stub) and S-UX3-3b (ops 成團 + below-min resolution). If kept as one, the
   consent-status endpoint + 成團 are the review-critical core; the student formation view is lighter.
3. **Advisory-vs-gate decision (§2).** Whether 成團 is disabled on a client-read blocking member, or
   kept enabled with a truthful advisory + surfaced 422. Recommendation (a): enabled + advisory. Needs
   a ruling because it defines how the consent gate is rendered.
4. **Privacy rule is load-bearing.** The consent-status read must never leak *which* guardian signed
   (co-guardian RLS). A naive "list each guardian's request status" would breach it. The endpoint must
   aggregate to "X of N signed / satisfied" — reviewer must hold this line.

**Recommendation for S-UX3-3:** proceed to a build card, but (a) treat the **per-member consent-status
endpoint** as the review-critical, child-safety-adjacent deliverable (booleans-only, line-by-line); (b)
decide the split (§7.2) and the advisory-vs-gate rule (§7.3) before carding; (c) list every backend
delta (§5) explicitly in the card for line-by-line review — do not fold silently.

---

## Part 2 — Does S-UX3-4 (Sessions / attendance) need its own think-first?

**Verified:** `AttendanceController::mark` validates `{student_id, status: attended|no_show}` and calls
`attendance->mark(...)` — **no consent, no money, no child-safety gate, no state precondition** beyond
the session existing. The attendance **write path is a simple mutation.** It *feeds* the Learn gate
(attendance_threshold_pct → tenure/badges, S08) **downstream**, but does not gate on consent or money.

However, S-UX3-4 also contains a **session lifecycle** surface: `SessionController` (create→`draft`,
`transition {to}`, `reschedule`, `clashPreview`). That is a **state machine + scheduling** sub-surface
(timezone, clash detection, capacity) — heavier than "mark attendance."

**Recommendation:**
- **S-UX3-4 does NOT need a full think-first for the attendance core** — marking attendance is
  additive display (roster + session) + a simple present/absent mutation, no consent/money/child-safety
  interlock. It can proceed **from a card summary**, following the S-UX3-1 write conventions.
- **The session-scheduling sub-surface (create/reschedule/transition/clash-preview) is a state machine**
  — if it's in S-UX3-4's scope, the **card must enumerate the session states + clash/timezone rules**
  (a card-level spec, not a full separate think-first). If the reviewer prefers, scope S-UX3-4 to
  **attendance-marking + read-only session/clash display** and defer scheduling writes to a later card —
  then S-UX3-4 is unambiguously a clean summary card.
- **Do NOT batch S-UX3-3 and S-UX3-4.** S-UX3-3 carries a real consent gate **and** a new backend read
  layer (members/consent/roles), including a child-safety-adjacent endpoint that needs line-by-line
  review; it needs full focus. S-UX3-4 (attendance) is lighter and clean. **Sequence, don't batch:**
  land S-UX3-3 (with the consent-status endpoint carefully reviewed), then take S-UX3-4 as a separate,
  lighter card.

**Bottom line:** S-UX3-3 needs the rulings above before carding (it is not display-only). S-UX3-4's
attendance core is a clean summary card; its scheduling sub-surface, if included, needs a card-level
state/clash spec but not a full think-first. They should be **sequenced, not batched.**
