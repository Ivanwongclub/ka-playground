# SPRINT KAP-S10 — Hardening & UAT

## GOAL
Prove the platform survives restore, rollback, load and hostile inputs — then hand it to the client.

## PRECONDITIONS  S09 gate PASSED · staging environment live (2.14).

## IMPLEMENTS  2.14 (drill) · 2.26 (rehearsal)

## SCOPE
1. **Restore drill (2.14)**: RDS snapshot → fresh instance → full reconcile suite green on restored
   data; RTO/RPO measured and logged.
2. **Rollback rehearsal (2.26)**: deploy tag N to staging, roll back to N-1 with the runbook; note
   any migration interplay.
3. Load pass on dashboards · security pass: throttle verification, upload-scan verification,
   permission-matrix probe per role.
4. UAT seed data (synthetic, realistic depth) · bilingual content pass (EN/繁中).
5. UAT with client · punch list triage (Sev-1 fix now / Sev-2 before go-live / Sev-3 backlog).

## NON-SCOPE
New features. A punch-list item that is a feature request goes to the backlog, not into this sprint.

## AUDIT ELEMENT
**Go-Live Readiness Report** — restore drill result (RTO/RPO) · rollback rehearsal result · security
probe results · **full reconciliation suite green 7 consecutive days** · open-decision register
cleared or explicitly accepted.

## EXIT GATE
The readiness report itself, all rows green, plus client UAT sign-off. AUDIT.md, gate commit.
Leo tags the go-live release.
