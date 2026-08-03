# PROPOSED (sub-pass) — S-UX3-3a per-member consent-status read endpoint

> Child-safety-adjacent. Reviewed at S-FIX level BEFORE any code. Everything below is verified from
> source. This endpoint is **advisory** — it renders the 成團 consent gate for the ops operator; the
> authority is the confirm-time FOR SHARE re-check in `TeamConfirmationService`, never this read.

## 0. What it is / where it sits

A new **read** endpoint (proposed `GET /teams/{team}/consent-status`) backing the S-UX3-3a ops 成團
view: for each active member of a team, is that member's consent satisfied for 成團, and an aggregate
progress — **booleans and counts only**. It exists because `consentSatisfied` lives **only inside**
`TeamConfirmationService` today (confirmed: no controller exposes it), so the UI cannot render the gate
without it.

## 1. Exact response shape — booleans/counts ONLY (exact JSON keys)

```json
{
  "team_id": "019f…-uuid",
  "mode": "any-one" | "requires_all",
  "all_satisfied": false,
  "blocking_count": 1,
  "members": [
    {
      "student_id": 8,
      "student_name": "Sam Chan (demo)",
      "satisfied": true,
      "signed_count": 2,
      "guardian_count": 2,
      "blocker": null
    },
    {
      "student_id": 9,
      "student_name": "Mia Chan (demo)",
      "satisfied": false,
      "signed_count": 1,
      "guardian_count": 2,
      "blocker": "awaiting_signature"
    }
  ]
}
```

- **Allowed keys, and nothing else.** Top: `team_id`, `mode`, `all_satisfied`, `blocking_count`,
  `members[]`. Per member: `student_id`, `student_name`, `satisfied`, `signed_count`, `guardian_count`,
  `blocker`.
- `mode` is programme-level (`consent.requires_all_guardians` → `requires_all`, else `any-one`).
- `signed_count` / `guardian_count` are the **counts** for the "X of N guardians signed" progress
  (requires_all); for any-one the UI renders "complete" / "awaiting a signature" from `satisfied`.
- `blocker` is a **coded state enum, not an identity**: `null` | `"awaiting_signature"` (no signature
  yet) | `"stale"` (a signature superseded before it counted) | `"not_requested"` (a guardian with no
  open/signed request — the S-FIX edge). Purely a state; carries no guardian reference.
- **`student_id`/`student_name` are the MEMBER (the student), not a guardian** — the team roster is the
  ops operator's legitimate view; the child-safety line is about **guardian** identity, not the student.
- **FORBIDDEN — must never appear:** `guardian_id`, `signer_id`, any guardian name, per-guardian request
  rows, request ids, `signed_at`/timestamps, signing order, or anything from which co-guardian identity
  or who-signed-when can be inferred. Only aggregate counts + the coarse `blocker` state leave the
  endpoint (mirrors `derivedStatus`: "no row, timestamp or identity leaves the elevation").

## 2. Computation — single source of truth, no divergent re-implementation

- **`satisfied` is `ConsentSigningService::consentSatisfied(programmeId, studentId)`** — the **identical
  method** the confirm-time FOR SHARE loop calls (`TeamConfirmationService` line ~46). The boolean is
  **never re-derived**; the read and the write agree by construction.
- The counts (`signed_count`, `guardian_count`) and `blocker` are computed on the **same queries**
  `consentSatisfied` already uses (`consent_requests … status='signed'`, `guardian_links … status=
  'active'`) — `->count()`, never the ids. To keep ALL of it in one place, **extend
  `ConsentSigningService` with one new read method** (e.g. `consentSummary(programmeId, studentId):
  array` returning `{satisfied, requires_all, signed_count, guardian_count, blocker}`), where
  `satisfied` delegates to `consentSatisfied`. The controller maps that array to JSON and adds
  `student_name`. **No consent logic lives in the controller.**
- **Advisory, not authoritative — stated in the docblock.** This read runs **without** the FOR SHARE
  lock, so a member shown `satisfied: true` can still be refused at 成團 if a concurrent supersede /
  late-guardian reissue flips it between read and confirm. That is by design (S-UX3-1 shown-not-hidden,
  ruling 2a): the UI shows the advisory; the server's 422 is the authority. The endpoint must not
  suggest otherwise.
- **Elevation:** the endpoint reads consent/guardian rows for students who may be **outside the ops
  caller's RLS scope** (the confirm reads members under `asSystem` for exactly this reason). So the
  method runs under a new allowlisted `asSystem` (its own `scope-elevations` entry, reason: "team
  consent roster (S-UX3-3a): aggregate booleans/counts only, no guardian identity leaves the
  elevation") — authority established **before** the elevation (§3).

## 3. RLS / authority — who may read a team's consent roster

**Gate = the 成團 confirm authority (OD-39), matched exactly** so only those who can *act* can *see*:
**the lobby school-admin of the team's category's school, academy `operations`/`super_admin`.** Resolved
**in-service before the elevation** (as `TeamConfirmationService` does), because the team/members are
outside a school-admin's derived RLS scope until resolved.

Five-branch read discipline for this endpoint:
1. **Academy ops / super_admin** → 200, full roster for any team.
2. **Lobby school-admin** (of the team's category's school) → 200, that team only.
3. **Unaffiliated school-admin** (different school) → **404** (RLS-shaped absence, no existence leak).
4. **Guardian / student** → **403** — a guardian NEVER reads the team-wide roster; they see only their
   own child's consent via the guardian-facing `derivedStatus` (`/my/students/{id}/consent-status`),
   which is booleans-only and self-scoped. This endpoint is not their surface.
5. **Member role / unauthenticated** → **403 / 401**.

The route carries no blanket `permission:` that a guardian holds; authority is the in-service OD-39
check (like `/teams/{id}/confirm`), so it cannot be reached by a guardian even by direct URL.

## 4. The privacy proof — a path-independent, red-green causality tooth

A test that **reds** the moment any guardian identity leaks:

- **Fixture:** a submitted team; a member student with **two active guardians** given
  **distinctive, searchable names** (e.g. `"Zeta Guardian"`, `"Omega Guardian"`) and distinctive ids;
  one guardian signed, one pending (so requires_all is unsatisfied → the leak-prone path is exercised).
- **Assert (as academy ops):** the **serialized JSON response**, searched as a whole string,
  **contains neither guardian name** (`"Zeta Guardian"`, `"Omega Guardian"`) **nor either guardian's
  id**, and **no key matches `guardian`/`signer`** anywhere in the payload; AND the member's
  `signed_count`=1, `guardian_count`=2, `satisfied`=false, `blocker`="awaiting_signature".
- **Red-green tooth:** flip one thing — e.g. temporarily have the endpoint include a `signer_id` or a
  guardian name — and the assertion **reds**; the shipped endpoint keeps it **green**. This proves the
  privacy holds *by the test*, not by promise (the S-FIX causality discipline). Pair it with a
  **key-allowlist assertion**: the response's key set is exactly the §1 allowlist — any extra key fails.
- Optionally register a reconciliation-style invariant later, but the feature test above is the
  load-bearing proof for this card.

## 5. The teamed-member-unsatisfied case — the dead-loop, made visible

**Confirmed from `consentSatisfied`:** for `requires_all`, it returns **false** whenever
`activeGuardians.diff(signedSigners)` is non-empty — i.e. any active guardian has not signed. So a
**teamed** member with a **late-added, unsigned** guardian (S-FIX reopen: the request was reissued, the
guardian hasn't signed) or a **superseded** prior signature → `satisfied: false`, `blocker`
∈ {`awaiting_signature`, `stale`}. The endpoint therefore reports that member as a **blocker**
(`all_satisfied: false`, `blocking_count ≥ 1`), and the UI surfaces it **by `student_name` + the coarse
reason** ("Mia Chan — awaiting a guardian signature"). This is precisely the dead-loop shape S-FIX
addressed; the endpoint's job is to make the blocking member **visible so ops can chase the signature**,
never to hide it. A test asserts: add a second requires_all guardian to a teamed member without a
signature → that member reports `satisfied:false`, and `all_satisfied:false` for the team.

## 6. Pre-成團 team state — pinned from source (PROPOSED §2 is CORRECT)

The teams status enum is `('forming','submitted','confirmed','disbanded')`. The lifecycle:
`forming` → `submitted` → `confirmed`. Verified:
- **`forming`** — team being assembled (students create/join).
- **`submitted`** — awaiting 成團 approval. Reached by the submitter (`TeamConfirmationService::submit`,
  `forming`→`submitted`, "only the team submitter may submit … Team is {status}" 409 if not forming) OR
  the deadline sweep (`FormationDeadlineService`, size-compliant `forming`→`submitted` at OD-33).
- **`confirmed`** — 成團 done (`confirm` requires `team.status === 'submitted'`, else **409**).

So **a team awaiting 成團 sits in `submitted`.** The PROPOSED §2 refusal ("Team not in `submitted` →
409") is **correct — no correction needed.** For the ops view: list `submitted` teams as the 成團 work
queue; the consent-status endpoint is called per team the operator is about to confirm. (The endpoint
itself may be called for a team in any state — it's a read — but the 成團 *action* applies only to
`submitted`.)

## Summary of what S-UX3-3a's card must build for this endpoint (for line-by-line review)

1. `ConsentSigningService::consentSummary(programmeId, studentId)` — extends the single source; returns
   `{satisfied (=consentSatisfied), requires_all, signed_count, guardian_count, blocker}`; **no id
   leaves it**. Its `asSystem` gets its own allowlist entry (aggregate-booleans reason).
2. `GET /teams/{team}/consent-status` — OD-39 authority in-service (before the elevation); maps
   `consentSummary` per active member + adds `student_name` (member/student — a LEFT join, S-UX2b);
   returns exactly the §1 shape.
3. Tests: the **privacy tooth** (§4, red-green + key-allowlist), the **five-branch authority** (§3, incl.
   guardian → 403), the **teamed-unsatisfied blocker** (§5), and the **single-source agreement**
   (`satisfied` == `consentSatisfied`). Battery stays green; no assertion touched.

**Nothing else changes.** The endpoint is advisory and read-only; the 成團 authority remains the
FOR SHARE re-check. No new write, no migration, no schema change, no consent logic outside the one
service method.
