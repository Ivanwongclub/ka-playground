# SPRINT KAP-S02B — Programme configuration

> Created 2026-07-24 by splitting the adjusted S02 card (Leo). S02A (access foundation)
> gates this sprint. Not yet reviewed by Leo.

## GOAL
Programmes fully configurable through the hub-and-spoke wizard, versioned, pre-flight-checked, and
publish-locked — including the withdrawal policy fields the money path will depend on. Everything
here builds ON the S02A entity and INSIDE the S02A scope layer.

## PRECONDITIONS
- [ ] **S02A gate PASSED** — the scope layer exists; every surface this sprint adds is born scoped
- [x] OD-2 default values available — **PROVISIONAL, client confirmation pending**: seeds are data
  and adjustable by config; the band schema builds in full regardless (OPEN-DECISIONS OD-2)

## IMPLEMENTS  2.1 (fields) · Spec Part D · OD-2 · OD-12 · OD-13/13a/13b · OD-21

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Hub-and-spoke wizard**, all 10 sections (Spec Part D); readiness computation; pre-flight check
   (Error blocks publish / Warning / Info); publish lock (one-way door: first enrolment locks fees
   + consent template; changes create versions); programme templates (clone → Draft).
2. **Role library · team rules · stage requirements · fee items · certification/badge rules.**
   Fixed stages Plan · Design · Learn · Pitch · Launch (A2). **Learn thresholds are programme
   config fields, editable after creation (OD-12)** — per-student for certification, per-team
   percentage for the gate. **`team_categories` CRUD in the wizard's Team Rules section per
   `docs/TEAM-CATEGORIES.md` (canonical)**: admin-created formation lobbies, trilingual names
   (`*_en/_tc/_sc`, OD-13a), optional `school_id` binding, `assignment_rule`
   (`auto_by_school | open | admin_assigned`), default flag enforced by a **partial unique index
   on `(programme_id) WHERE is_default` (OD-13b — database-level, two concurrent saves cannot
   produce two defaults)**. Never an enum, never seeded with fixed types. Certification rules:
   academy-issued only, no co-branding fields of any kind (OD-21). Every new table classified in
   the S02A scope map — the structural assertion fails otherwise.
3. **Withdrawal policy fields (2.1)**: `full_refund_before_date`, `pro_rata_bands`,
   `no_refund_after_date`, `withdrawal_requires_approval` — configuration only; workflow is S04B.
   **Band schema built in full and validated with synthetic fixtures (OD-2)**; seeds per OD-2's
   provisional defaults (full refund before programme start · no bands · no refund after start ·
   approval required), marked provisional pending client confirmation.

## NON-SCOPE
Enrolment (S04A) · consent signing (S03; template *selection* config is in scope) · any refund
logic · team formation itself (S05 — lobbies are config here, formation is not) · Member surfaces
(OD-22: S06).

## KEY VERIFICATIONS
- Publishing without a consent template or fee items → pre-flight blocks (paste).
- Editing a locked field on a Published programme → rejected + audit event of the attempt.
- **Two concurrent default-lobby saves → exactly one default survives; the loser gets the
  database error, not a second default (OD-13b — paste the constraint violation).**
- Learn threshold edited AFTER programme creation → accepted, versioned, audited (OD-12).
- Withdrawal band schema: overlapping/unordered bands rejected; valid bands compute against
  synthetic fixtures (paste both).
- A school_admin walking the wizard sees only their school's data in every section (S02A scope
  layer holding under the new surfaces — spot-paste one cross-scope refusal from a wizard section).
- Trilingual: wizard section names + team_categories names render in EN/TC/SC, i18n:check green.

## AUDIT ELEMENT
**Configuration Audit Report** — per programme: config version history (who/what/when), pre-flight
results archive, locked-field change attempts, team_categories change log (add/rename/retire —
admin actions, no migrations).

## ASSERTIONS (--tag=S02B)
- No Published programme missing consent template or fee items.
- **Every programme with team_categories has exactly one default lobby (OD-13b).**
- (Version-snapshot immutability and structural scope coverage are S02A assertions and keep
  running nightly; this sprint's new tables must satisfy the scope map or S02A's assertion fails.)

## EXIT GATE
Tests + `reconcile:run --tag=S02B` green + a programme walked Draft→Published through the wizard in
seed + bundle budget green. AUDIT.md, gate commit.
