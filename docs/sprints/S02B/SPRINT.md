# SPRINT KAP-S02B — Programme configuration

> Created 2026-07-24 by splitting the adjusted S02 card (Leo). Re-reviewed against what S02A
> actually shipped (Leo, pre-start): scoping is enforced by RLS with a mandatory classification
> map — the classification of every new table is IN THIS CARD (the coverage assertion is the
> backstop, not the plan), and the programme-scope doctrine is stated below. Not yet committed.

## GOAL
Programmes fully configurable through the hub-and-spoke wizard, versioned, pre-flight-checked, and
publish-locked — including the withdrawal policy fields the money path will depend on. Everything
here builds ON the S02A entity and INSIDE the S02A scope layer; every table this sprint creates is
classified in this card and ships its classification in the same migration that creates it.

## PRECONDITIONS
- [x] **S02A gate PASSED** (`1acaea2`) — RLS scope layer live; scope map + coverage assertion active
- [x] OD-2 default values available — **PROVISIONAL, client confirmation pending**: seeds are data
  and adjustable by config; the band schema builds in full regardless (OPEN-DECISIONS OD-2)

## IMPLEMENTS  2.1 (fields) · Spec Part D · OD-2 · OD-12 · OD-13/13a/13b · OD-21

## SCOPE CLASSIFICATION PLAN (declared before work starts; Leo pre-start review ×2)
**What `global` MEANS: readable by EVERY authenticated session — students, guardians, Members,
teachers, all of them. It does not mean "internal". Every justification below is written against
that definition. Tie-breaker (Leo): when in doubt, scoped — widening later is a migration,
narrowing after exposure is a disclosure.**

**Doctrine — programme is an ATTRIBUTE, not a scope key (Phase 1).** S02B's tables are programme
CONFIGURATION: no personal data, attached to a catalogue whose visibility rule is authentication
(L4). They classify `global` with recorded justifications mirroring `programmes`/
`programme_versions`; ALL writes are permission-gated (`configuration.manage`). Scope for
programme-linked PERSONAL data (first arriving with S04A enrolments) resolves through the PERSON:
such rows always carry `student_id`, and school_admin/teacher/guardian visibility derives from the
existing link subqueries — never from programme membership. A programme spanning three schools or
belonging to none grants and denies nothing by itself; the context carries no `programme_ids`.
Any future table needing true programme-membership scoping without a student on the row must first
create explicit assignment links and derive scope from them — decided at that sprint's card.

| Table | Classification | Justification core |
|---|---|---|
| `wizard_sections` (readiness state) | global | Readable by every authenticated session per the definition above; contains section status + config payloads of the catalogue (no personal, no commercial data — fees/terms live in their scoped tables); writes configuration.manage |
| `fee_items` | **scoped** (Leo challenge 2) | Commercial terms. ASSUMPTION: Phase 1 pricing is uniform per programme (A2/D2§3 define fees on the programme; H5 consolidated invoicing bills schools at programme rates — nothing in the spec prices per school). But the assumption is commercial and unconfirmed → scoped by the tie-breaker. Read: academy staff (configuration/finance/operations/audit_read/super) + system. The consumer-facing read clause (guardians seeing a published programme's fees to enrol) is decided at the S04A card, by when the client question (AUDIT §5) should be answered |
| `programme_stages` / `stage_requirements` | global | Fixed-stage config (A2), readable by every authenticated session; no personal or commercial data |
| `role_library` | global | Team-role definitions (D2§7), readable by every authenticated session; tenures/assignments (S05) will be scoped via their student rows |
| `team_categories` | **scoped** (Leo challenge 1) | Lobby names are partner-school names with school bindings — global would hand every student, guardian and Member the partner-school roster and each school's programmes: a client list on a platform selling privacy (FR056 instinct). Read: system · academy staff · school-linked roles where `school_id` matches their schools · guardians via their students' schools (subquery) · school-UNBOUND lobbies (`school_id IS NULL`) to authenticated non-Member request contexts (open lobbies carry no roster data; S05 formation needs them visible). Members see nothing. Write: configuration.manage only |
| `certification_rules` / `badge_rules` | global | Completion criteria config (D2§9, OD-21 — no co-branding fields exist), readable by every authenticated session; no personal or commercial data |
| `pre_flight_results` | global | Validation-run archive, readable by every authenticated session; messages are config-completeness findings, no personal or commercial data |
| `withdrawal_policies` + `withdrawal_bands` | **scoped** (Leo challenge 2) | Refund terms are commercial terms — same assumption, same tie-breaker, same read set as `fee_items`. Policy fields move OFF the global `programmes` row into `withdrawal_policies` (one row per programme) so the terms sit behind RLS; S04B's workflow reads them as system/finance |

Each migration creating one of these tables updates `config/scope-map.php` IN THE SAME COMMIT with
the full justification; each step's VERIFY runs `reconcile:run --tag=S02A` and pastes it green.
If implementation reveals personal data landing in any of these tables, that is a STOP — the
classification changes to `scoped` with policies in the same migration, and this plan is amended.

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Hub-and-spoke wizard**, all 10 sections (Spec Part D); readiness computation; pre-flight check
   (Error blocks publish / Warning / Info); publish lock (one-way door: first enrolment locks fees
   + consent template; changes create versions); programme templates (clone → Draft). Tables per
   the classification plan, classified in-migration.
2. **Role library · team rules · stage requirements · fee items · certification/badge rules.**
   Fixed stages Plan · Design · Learn · Pitch · Launch (A2). **Learn thresholds are programme
   config fields, editable after creation (OD-12)** — per-student for certification, per-team
   percentage for the gate. **`team_categories` CRUD in the wizard's Team Rules section per
   `docs/TEAM-CATEGORIES.md` (canonical)**: admin-created formation lobbies, trilingual names
   (`*_en/_tc/_sc`, OD-13a), optional `school_id` binding, `assignment_rule`
   (`auto_by_school | open | admin_assigned`), default flag enforced by a **partial unique index
   on `(programme_id) WHERE is_default` (OD-13b)**. Never an enum, never seeded with fixed types.
   Certification rules: academy-issued only, no co-branding fields of any kind (OD-21). Tables
   classified in-migration per the plan.
3. **Withdrawal policy (2.1)**: `withdrawal_policies` (`full_refund_before_date`,
   `no_refund_after_date`, `withdrawal_requires_approval`) + `withdrawal_bands` — SCOPED tables
   per the plan, policies in the same migration; configuration only; workflow is S04B.
   **Band schema built in full and validated with synthetic fixtures (OD-2)**; seeds per OD-2's
   provisional defaults, marked provisional pending client confirmation. Classified in-migration.

## CLIENT QUESTION (raised to AUDIT §5)
Are programme fees or refund terms ever negotiated per school? Phase 1 assumes NO (uniform per
programme). The answer gates the S04A consumer-facing fee-read clause and whether school-facing
billing surfaces may show cross-programme terms.

## NON-SCOPE
Enrolment (S04A) · consent signing (S03; template *selection* config is in scope) · any refund
logic · team formation itself (S05 — lobbies are config here, formation is not) · Member surfaces
(OD-22: S06) · any personal data in configuration tables (STOP condition per the plan above).

## KEY VERIFICATIONS
- **Per step: `reconcile:run --tag=S02A` green AFTER the step's migrations — pasted, not assumed.
  The coverage assertion is the backstop; the classification shipped with the table is the plan.**
- Publishing without a consent template or fee items → pre-flight blocks (paste).
- Editing a locked field on a Published programme → rejected + audit event of the attempt.
- **Two concurrent default-lobby saves → exactly one default survives; the loser gets the
  database error, not a second default (OD-13b — paste the constraint violation).**
- Learn threshold edited AFTER programme creation → accepted, versioned, audited (OD-12).
- Withdrawal band schema: overlapping/unordered bands rejected; valid bands compute against
  synthetic fixtures (paste both).
- Config writes without `configuration.manage` → 403 + audited denial (paste one per table group).
- A school_admin walking the wizard reads the catalogue config (global by doctrine) but every
  person-derived surface stays bounded — spot-paste one cross-scope refusal alongside a config
  read that succeeds, to demonstrate the attribute-vs-scope-key distinction.
- Trilingual: wizard section names + team_categories names render in EN/TC/SC, i18n:check green.

## AUDIT ELEMENT
**Configuration Audit Report** — per programme: config version history (who/what/when), pre-flight
results archive, locked-field change attempts, team_categories change log (add/rename/retire —
admin actions, no migrations).

## ASSERTIONS (--tag=S02B)
- No Published programme missing consent template or fee items.
- **Every programme with team_categories has exactly one default lobby (OD-13b).**
- (Version-snapshot immutability and structural scope coverage are S02A assertions and keep
  running nightly as the backstop for this sprint's classifications.)

## EXIT GATE
Tests + `reconcile:run --tag=S02B` green + `reconcile:run --tag=S02A` green (all new tables
classified and justified) + a programme walked Draft→Published through the wizard in seed +
bundle budget green. AUDIT.md, gate commit.
