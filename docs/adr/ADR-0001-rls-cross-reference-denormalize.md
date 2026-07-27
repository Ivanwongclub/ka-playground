# ADR-0001 — Cross-referencing scoped tables under RLS: denormalize the join key to a leaf

**Status:** Accepted · **Date:** 2026-07-27 · **Surfaced by:** S05 STEP 1 (teams / team_members)

## Context
Every scoped table on this platform runs Postgres RLS (`ENABLE` + `FORCE`), and a policy's
`USING`/`WITH CHECK` may sub-select other tables. A sub-select against another RLS-forced table
**re-invokes that table's policies** (the runtime role is `NOSUPERUSER NOBYPASSRLS`, so RLS always
applies). When two scoped tables reference each other in their read policies, this produces
`ERROR: infinite recursion detected in policy for relation "…"`.

Concretely, in S05 STEP 1:
- `teams_read` needed "visible to a team's members" → `EXISTS (SELECT 1 FROM team_members …)`.
- `team_members_read` needed "visible to the lobby's school admin" → originally
  `EXISTS (SELECT 1 FROM teams t JOIN team_categories c …)`.
- Reading `teams` → evaluates `team_members` policy → which reads `teams` → **recursion.**

## Decision
When a scoped table B's policy needs an attribute that lives on scoped table A, and A's policy
already references B, **denormalize the needed key from A onto B at write time and have B's policy
reference a LEAF table instead of A.** A leaf is a table whose own policies reference no table that
(transitively) points back into the cycle.

In S05 STEP 1 we copied `category_id` from the team onto `team_members` at insert, and rewrote
`team_members_read` to reference `team_categories` directly (`WHERE c.id = team_members.category_id`).
`team_categories`' policy references only `school_links` — a leaf — so the chain
`teams → team_members → team_categories → school_links` is acyclic.

## Why denormalization is safe here (the guardrails to require)
1. **Derived, never client-supplied:** the copied key is set from the parent row inside the write
   path (`$team->category_id`), never from request input — it cannot be written wrong.
2. **Immutable post-insert:** the child table's `UPDATE` policy is system-only, so the copy cannot
   drift from its parent after creation.
Both conditions must hold for a denormalized RLS key. If either fails, prefer a `SECURITY DEFINER`
helper function over a mutable/forgeable copy.

## Alternatives considered
- **`SECURITY DEFINER` function** to read the inner table bypassing RLS: correct but heavier; more
  surface to review; reserve for cases where denormalization's guardrails don't hold.
- **Drop one side's cross-reference:** loses a legitimate read branch (here, the school admin's
  view of memberships in their lobby).

## Consequences
- Reusable rule for future scoped tables: **before adding a cross-table sub-select to a policy,
  check for a cycle; if one exists, denormalize the join key to a leaf under the two guardrails
  above.**
- `scope.coverage` (the nightly RLS assertion) does not detect policy recursion — it only checks
  RLS is forced with ≥1 policy. Recursion surfaces at query time. Consider a future assertion that
  parses `pg_policies` for cross-references and flags cycles. (Not built; noted.)
