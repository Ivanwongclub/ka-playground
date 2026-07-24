# SPRINT KAP-S02A — Access foundation

> Created 2026-07-24 by splitting the adjusted S02 card (Leo); approved with two pre-start
> amendments (global-entry justifications; RLS as the enforcement mechanism). S02B has this
> sprint's gate as its precondition.

## GOAL
The platform's first real data surfaces — schools and the programme entity — born WITH the FR006
scope layer around them, plus the production bootstrap path. After this gate, "who can reach what"
is enforced at the query layer, proven by isolation tests, and guarded by a structural nightly
assertion that automatically covers every future table.

## PRECONDITIONS
- [x] S01 gate PASSED (`9ed17c7`, + post-gate fixes `d9f658e`/`5c31dd3`)

## IMPLEMENTS  FR006 (scope layer) · OD-16 (jurisdiction) · S01 AUDIT §5 items 3, 4, 6, 7, 8

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Super-admin bootstrap (S01 §5 item 7)**: `artisan bootstrap:super-admin` — creates the first
   academy_admin with `super_admin`; **refuses if any super_admin already exists**; the run itself
   is audited. The resulting credential is a standing S10 go-live item: rotate or remove before
   production (recorded in this sprint's AUDIT §5 for carry-forward).
2. **Schools + programme entity + versioning** — the first real data surfaces. Schools CRUD
   (trilingual names per OD-19; closes S01 §5 item 6). Programme entity with **`jurisdiction`
   field (OD-16** — HK and mainland share an offset, not a legal regime**)**, enrolment window,
   `hold_window_days`, `payer_party`. Version snapshots immutable (same enforcement pattern as
   BI-1). Wizard, sections and publish flow are S02B — this step is the entity and its versioning
   only.
3. **FR006 scope layer — lands WITH step 2's surfaces, never after (S01 §5 item 8, Leo: a STEP,
   not a note)**. **Enforcement mechanism: Postgres Row-Level Security** — policies on every
   `scoped` table keyed on per-request session context (`app.actor_id`/`app.actor_role`/
   `app.capabilities`/`app.school_ids`; middleware for HTTP, job bootstrap for queues, explicit
   for console). Below every access path (Eloquent, DB::table, raw SQL, reports, exports),
   **fail-closed** (no context ⇒ zero rows, never everything), `FORCE ROW LEVEL SECURITY` so even
   the owner obeys; the runbook owner-guard gains a `rolbypassrls` check. App-layer scoping is UX
   and defence-in-depth, never the line. **Context lifecycle is STRUCTURAL (Leo, pre-STEP-3):
   framework events registered once in a provider, never per-callsite — HTTP middleware sets on
   entry and RESETS in terminate; `Queue::before` resets-then-sets from the job's own serialized
   context and `Queue::after`/`failing` reset again (long-lived Horizon worker connections are
   scrubbed around EVERY job — stale context from a previous user is impossible, and a job
   carrying no context runs with none, fail-closed); `CommandStarting` resets then sets the
   `system` context that only console/queue bootstrap can set (reconcile:run's cross-scope reads
   run as `system`; the HTTP path cannot claim it).** Scoping bounds every read/write by the actor's links —
   school_admin sees and manages ONLY their own school's students, teachers and programmes;
   teacher scoped to their school; guardian/student scoped to their links. **Per-link
   `permission_overrides` (B7 layer 2) NARROW ONLY, never widen — enforced server-side: the
   effective permission set is role ∩ overrides, and an override attempting to ADD a permission
   the role default does not carry is rejected at write time and ignored at resolve time**
   (Leo amendment 1 — a widening override would grant cross-school reach and defeat this step).
   School Admin gains their B1 invitation right (invite teachers) **scoped to their own school**
   (closes S01 §5 item 4). Frontend route guard: unauthenticated users land on /login, not empty
   shells (closes S01 §5 item 3). Every cross-scope attempt → 403/404 + `permission.denied`-class
   audit event. **The isolation test is the point: school A's admin must never reach school B's
   rows — proven by test AND live paste.**

## NON-SCOPE
The wizard, pre-flight, publish lock, templates, role library, team rules, team_categories,
thresholds, withdrawal policy — ALL S02B. Enrolment (S04A) · consent (S03) · team formation (S05) ·
Member surfaces (OD-22: S06).

## KEY VERIFICATIONS
- `bootstrap:super-admin` creates once with audit row; second run refuses (paste both).
- **Scope isolation (the control this sprint exists for): school A's admin listing/fetching school
  B's students, teachers or programmes → 403/404 + audited denial. Paste the attempts.** A
  super_admin-capability admin CAN cross schools (platform owner) — paste that contrast.
- Per-link override narrows: a guardian link with a restrictive `permission_overrides` JSONB loses
  the overridden permission on that pairing only (paste effective-permission diff).
- **An override attempting to WIDEN (adding a permission the role default lacks, or any
  cross-school grant) is rejected server-side at write time; a hand-inserted widening row is
  ignored at resolve time. Paste both refusals (Leo amendment 1).**
- Version snapshot rows immutable (BI-1 probe pattern; paste the DB rejection).
- **Context isolation across the worker boundary (Leo): request as user A (school A), then a
  queued job processed on the same worker — the job does NOT inherit A's context; then a request
  as user B — B sees B's scope, never A's. Two users in sequence, pasted.**
- `jurisdiction` present on the programme entity (OD-16).
- Trilingual: school and programme entity surfaces render EN/TC/SC, i18n:check green.

## AUDIT ELEMENT
**Scope & Access Report** — per school: its admins and teachers; cross-scope attempt log (who,
what, when, from which scope); per-link override list (every row provably narrow-only); bootstrap
credential status (exists / last rotated — feeds the S10 readiness check).

## ASSERTIONS (--tag=S02A)
- **Scope coverage — structural, not fixture-based (Leo amendment 2):** the assertion enumerates
  EVERY table in the schema and checks it against a declared classification map
  (`scoped` — carries its scope constraint · `global` — deliberately unscoped · `infrastructure`).
  **Any table missing from the map FAILS; any `scoped` table lacking RLS
  (`relrowsecurity AND relforcerowsecurity AND ≥1 policy`) FAILS; and every `global` entry MUST
  carry a written justification string — an empty, short or placeholder reason FAILS** (Leo:
  `global` is the escape hatch; the reasoning goes on the record at the moment of the decision,
  not reconstructed later). Plus a fail-closed probe: a context-free SELECT on a seeded `scoped`
  table must return zero rows. Tables added in S03–S09 are caught the night they appear.
- Version snapshots immutable.

## EXIT GATE
Tests + `reconcile:run --tag=S02A` green + **the scope-isolation paste** + the widening-override
refusal paste + the fail-closed zero-rows paste + bundle budget green. AUDIT.md (carry the super_admin credential rotation item
forward to S10), gate commit.
