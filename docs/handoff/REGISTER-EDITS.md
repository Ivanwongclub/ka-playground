# REGISTER.md — edits

## EDIT 1 — amend SR001 (invitation-only is no longer absolute)

**FIND:**
```
| SR001 | A | Invitation-only onboarding; no public sign-up | Spec Part L4 |
```
**REPLACE WITH:**
```
| SR001 | A | Invitation-led onboarding. No self-service account creation. Public surface limited to a school-routed self-registration REQUEST (creates no account; approval issues the standard invitation). Superseded from "no public sign-up" by OD-23 / OD-44 | Spec Part L4 + OD-23 |
```

## EDIT 2 — add new functional requirements (append to the seeded-entries table)

```
| FR200 | OD-23 | School-routed self-registration: a request naming a partner school + guardian; school approves against roll; approval issues the standard guardian invitation. No account created by the request | OD-23 |
| FR201 | OD-25 | Team-based capacity: seats allocate to the team at 成團, claimed atomically at approval | OD-25/26 |
| FR202 | OD-27 | Per-programme formation deadline; ordering (enrolment close → formation → payment) validated at publish and on edit | OD-27 |
| FR203 | OD-28 | Awaiting-a-team pool replaces the individual waitlist | OD-28 |
| FR204 | OD-29 | Unteamed-at-deadline resolution: match / roll (parked, 90-day auto-refund backstop) / release | OD-29 |
| FR205 | OD-31 | Team-below-minimum exception with four terminal actions (assign / grace-once / waiver / dissolve) | OD-31 |
| FR206 | OD-32 | Team dissolution re-pools paid members in-lobby, paid status retained, no re-charge | OD-32 |
| FR207 | OD-33 | Team approval: school approves normal teams, academy handles exceptions; team-linked teacher may approve | OD-33 |
| FR208 | OD-34 | Size waiver stored as a team field with reason; nightly check reads "meets rules OR waiver" | OD-34 |
| FR209 | OD-35 | Post-成團 changes academy-only, reasoned, audited, notified; paid removal via withdrawal workflow | OD-35 |
| FR210 | OD-37 | Payment triggered on entering a confirmed team; deadline default 7 days | OD-37 |
| FR211 | OD-38 | Forwardable payment link, initials-only, expiring, dead once paid | OD-38 |
| FR212 | OD-40 | PaymentProvider interface; MockProvider Phase 1; QFPay Phase 2 gated by merchant application; HKD only | OD-40 |
| FR213 | OD-43 | Manual "reconcile payment" action alongside nightly gateway reconciliation | OD-43 |
| FR214 | OD-44 | Bulk import creates student records + guardian invitations; existing people matched, not duplicated | OD-44 |
| FR215 | OD-45 | Config-driven, version-stamped, pre-filled Excel template | OD-45 |
| FR216 | OD-47 | School-settled receivable: invoice at 成團, "covered by invoice" status, receipt on real payment | OD-47 |
| FR217 | OD-48 | School-settled withdrawal = credit note always; refund-to-school if already paid; balance assertion | OD-48 |
| FR218 | OD-49 | Batch failure = single academy exception on invoice aging | OD-49 |
| FR219 | OD-50 | Consent never batched; consent deadline + school-admin escalation for non-responders | OD-50 |
| FR220 | OD-51 | Consent completeness gates team submission (成團) | OD-51 |
| FR221 | OD-52 | Stale consent re-consent blocks 成團; material change updates all three languages | OD-52 |
| FR222 | OD-53 | Fresh consent per cohort (per-enrolment, not per-child) | OD-53 |
| FR223 | OD-54 | Teacher lifecycle: invited, school-stamped, single-school, offboarding-guarded | OD-54 |
| FR224 | OD-55 | Teacher links to team (not students); may approve that team's gates; required before first gate | OD-55 |
| FR225 | OD-56 | Student leaving school mid-programme: team stands, academy exception | OD-56 |
| FR226 | OD-57 | Enrolment independence per programme (student × programme) | OD-57 |
| FR227 | OD-58 | Scheduled-job state changes audit with a system actor | OD-58 |
| FR228 | OD-60 | Notification catalogue: 21 events, transactional/informational, per channel × language | OD-60 |
```

## EDIT 3 — add a change-log row
```
| 2 | 2026-07-25 | Workflow review: self-registration, team-based capacity, bulk import, payment model, teacher lifecycle | SR001 amended; FR200–FR228 added |
```
