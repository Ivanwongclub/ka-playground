# SPRINT KAP-S02 — Programme configuration surface

## GOAL
Programmes fully configurable through the hub-and-spoke wizard, versioned, pre-flight-checked, and
publish-locked — including the withdrawal policy fields the money path will depend on.

## PRECONDITIONS
- [ ] S01 gate PASSED · OD-2 default values available (fields build regardless; seed defaults need it)

## IMPLEMENTS  2.1 (fields) · Spec Part D

## SCOPE
1. Programme entity + versioning; version snapshots immutable.
2. Hub-and-spoke wizard, all 10 sections (Spec Part D); readiness computation; pre-flight check;
   publish lock; templates.
3. Role library · team rules · stage requirements (fixed stages: Plan, Design, Learn, Pitch, Launch)
   · fee items · certification/badge rules.
4. **Withdrawal policy fields (2.1)**: `full_refund_before_date`, `pro_rata_bands`,
   `no_refund_after_date`, `withdrawal_requires_approval` — configuration only; workflow is S04B.

## NON-SCOPE
Enrolment (S04A) · consent signing (S03; template *selection* config is in scope) · any refund logic.

## KEY VERIFICATIONS
- Publishing without a consent template or fee items → pre-flight blocks (paste).
- Editing a locked field on a Published programme → rejected + audit event of the attempt.
- Version snapshot rows immutable (same probe pattern as BI-1).

## AUDIT ELEMENT
**Configuration Audit Report** — per programme: config version history (who/what/when), pre-flight
results archive, locked-field change attempts.

## ASSERTIONS (--tag=S02)
No Published programme missing consent template or fee items · version snapshots immutable.

## EXIT GATE
Tests + `reconcile:run --tag=S02` green + a programme walked Draft→Published through the wizard in
seed. AUDIT.md, gate commit.
