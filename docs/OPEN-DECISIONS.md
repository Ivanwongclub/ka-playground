# KAP — Open Decision Register

> A sprint step that depends on an OPEN item is a STOP condition (CLAUDE.md §4).
> Resolve by writing the decision + date here; the register is append-only history.
> Rows OD-10 to OD-16 were folded in from Full Specification v4 Part R on 2026-07-23.

## Resolved

| # | Decision | Gates | Decision / date |
|---|---|---|---|
| OD-1 | Sixth **Member** role (Spec R3) | S01 | **Yes.** Member role in the S01 seed: events, RSVP, directory only. No enrolment, consent, finance or student data. Six roles total; roles remain unstacked per Spec B1. 2026-07-23 |
| OD-4 | Charity `project_type` (Spec R1) | S07 | Charity is a valid `project_type`. Charity funds are **never** distributed to team members. S07 assertion: no distribution transaction against a charity project. 2026-07-23 |
| OD-5 | Partial payments (2.20) | S04B | **No.** Offline payment recorded only when received in full; payment record carries **1..n evidence images**. No payment splitting, no allocation across orders, no running balances. Online payment out of scope (Phase 2 / QFPay). 2026-07-23 |
| OD-5a | Underpayment already received | S04B | **Not recorded.** The platform is not an accounting system. The admin waits for full payment, then records once. An underpaid transfer stays on the bank statement and never enters the system. **Consequence: amendment 2.20's underpayment path is struck** — `unmatched_payments` is populated only by overpayments (2.20) and late payments (2.19). The "no Unmatched >7d without resolver" assertion stands, fired by those paths. 2026-07-23 |
| OD-6 | Multi-guardian authority (2.22) | S04A | Approved as drafted: any active guardian may act; acting guardian recorded on every action; conflicting actions → Academy Admin exception, never auto-executed; refund destination = original payer, not requester. 2026-07-23 |
| OD-8 | Branch model | S00 | All-in-main + annotated tags. Supersedes Build Plan Part 4 item 2. 2026-07-23 |
| OD-9 | Repo location and name | S00 | `ka-playground`, under the org account. 2026-07-23 |
| OD-10 | Consent from all guardians or any one (R8) | S03 | **Any one** by default, with a per-programme `consent_requires_all_guardians` flag. Consistent with OD-6. 2026-07-23 |
| OD-11 | Enrolment hold window (R9) | S04A | **7 days**, configurable per programme. Expiry releases the seat and runs the 2.18 waitlist promotion. 2026-07-23 |
| OD-12 | Learn assessed per student or per team (R6) | S06 | **Both, configurable.** Per student for certification; per team for the stage gate, passing when a configurable percentage of members qualify. Threshold is a programme config field, not a constant. 2026-07-23 |
| OD-13 | Team categories (R7) | **S02 · S05** | **Superseded by `docs/TEAM-CATEGORIES.md`, which is canonical on this topic.** A category is an admin-created **formation lobby**, not a system taxonomy — "School Team" / "Armour Team" were example labels, never built-in types. `team_categories` is an admin-managed table per programme with trilingual names, optional `school_id` binding, and an `assignment_rule` of `auto_by_school` \| `open` \| `admin_assigned`. A team belongs to one lobby for life. The `category` visibility level is defined by this mechanism. Adding, renaming or retiring a lobby is an admin action — no migration. 2026-07-23 |
| OD-13a | `name_sc` vs UI languages | S02 | **Reversed by OD-19.** The platform is trilingual, so `name_sc` is live and required, not dormant. 2026-07-23 |
| OD-19 | **Internationalisation scope** (new) | **S00 · all** | **EN + 繁體中文 (TC) + 简体中文 (SC).** Scaffolded in S00 — locale files, locale switcher, font stack including an SC fallback. **No user-facing string is hardcoded, from the first commit**; retrofitting i18n after ten sprints of inline strings is not viable. Admin-authored short labels use inline trilingual columns (`*_en / *_tc / *_sc`), matching the `team_categories` pattern; long-form content uses per-language records. Code, comments and docs stay English. 2026-07-23 |
| OD-20 | **Consent template language scoping** (new) | **S03** | Consent template **versions are language-scoped**: each language has its own version row and its own SHA-256. BI-6 matches a signature's stored hash to the template version *in the language it was signed in* — never to a translation of another. A programme cannot go live with a language version missing. 2026-07-23 |
| OD-21 | **Co-branding** (new) | **S08** | **None.** Certificates are academy-issued only: no partner logos, no external signatories, no third-party certification terms. Confirms R11 struck. 2026-07-23 |
| OD-13b | "Exactly one default lobby per programme" enforcement | S02 | **Partial unique index on `(programme_id) WHERE is_default`** — database-level, not application validation. Two concurrent admin saves must not be able to produce two defaults, or team formation has no landing zone. 2026-07-23 |
| OD-15 | Role rotation — source and Phase 1 mechanism (R13, superseded) | S05 | **Cadence is not ours** — it originates in an external system, synced via **OIDC (Logto) plus API**. Logto lands in **S11, after UAT**, so no sync can exist in Phase 1. **Phase 1: staff record rotations manually.** The **tenure ledger is ours and is the system of record** — S08 mints badges from completed tenures (`badges == completed tenures`). S05 ships "tenure ledger + rotation recording", not a rotation engine. Sync becomes an additional input at or after S11. 2026-07-23 |
| R14 | Mainland China entity / ICP licence | none | **Not required at this stage.** Closed. Revisit only if the platform serves mainland users directly. The `jurisdiction` field is retained regardless (OD-16). 2026-07-23 |
| R15 | Consent wording | S03 · S10 | **S03 ships placeholder wording**, clearly marked non-legal and non-binding in both the template body and the admin UI. Replaced by the Hong Kong lawyer-approved text before go-live; the S10 Go-Live Readiness Report must show no template version still carrying placeholder text. 2026-07-23 |
| OD-14 | Segregation of duty on offline payments (R10) | S04B | **BI-9 stands — mandatory, server-side, not switchable per programme.** Spec R10 is overridden. Resolved by OD-17: SoD is staffable through the `finance` capability. **Operational requirement: at least two accounts hold `finance`.** 2026-07-23 |
| OD-16 | Multi-jurisdiction scope (R4) | S02 / S04A | **Timezone**: single, `Asia/Hong_Kong` (HK and mainland China share UTC+8). Store timestamps in UTC, render HKT. **Jurisdiction**: field retained — HK and mainland are different jurisdictions for consent law and data residency even at the same offset; R14 still open. **Currency**: Phase 1 is HKD only, but see OD-18 — currency code and minor units are mandatory from day one. Multi-currency UI and admin-entered HKD↔RMB rate are Phase 2. 2026-07-23 |
| OD-17 | **Academy Admin RBAC granularity** (new) | **S01** | Six identity roles unchanged. Academy Administrator gains **capability groups**: `super_admin` (all, plus the right to grant capabilities) · `configuration` · `finance` · `operations` · `audit_read`. Rejected alternative: separate top-level admin roles — breaks Spec B1's unstacked-roles rule and muddies audit attribution. Every capability grant/revoke is itself an audited action. 2026-07-23 |
| OD-22 | **Member surfaces** (raised at S01 card adjustment) | S06 | **Resolved: land in S06** with the session/event machinery (event list, RSVP; directory as read-only addition). **Until then, no Member invitations are issued** — the role exists in the seed and its denials are enforced, but nobody is onboarded into it until there is something to log into. S01 card NON-SCOPE updated; S06 card edit required before S06 starts. 2026-07-24 |
| OD-18 | **Currency representation** (new, derived from OD-16) | **S04A** | All monetary values carry an explicit **ISO currency code** (HKD in Phase 1) and are stored as **integer minor units**. Non-negotiable at Phase 1 because orders, receipts and refunds are immutable (BI-2, BI-5) and cannot be cleanly backfilled. 2026-07-23 |

## Open

| # | Decision | Gates | Status | Note |
|---|---|---|---|---|
| OD-2 | Withdrawal policy values | S02 seeds · **S04B** verifies | **PROVISIONAL — client confirmation required** | Seeded: `full_refund_before_date` = programme start · `pro_rata_bands` = none · `no_refund_after_date` = programme start · `withdrawal_requires_approval` = true. **Band schema and computation build in full regardless**; S04B tests them with synthetic fixtures. Seeds are data, adjustable by config |
| OD-3 | Brand assets (R5) | none — non-blocking | OPEN, ignored by design | Design System v2.1 fixes the palette and mono-logo rule; both typefaces are open-licence. Swap tokens if the client's real palette ever differs |
| OD-7 | Age of majority (2.27) | none | OPEN — safe default in force | Guardian consent signed while the student was a minor remains valid for the enrolment's duration |
| R11 | HKUST co-powered certification | S08 | **DEFAULTED — not real.** Appeared once, at `KA_Playground_Full_Specification_v4.md` line 1842, and nowhere else; client states it was never discussed. **Struck from S08 scope**: certificates are academy-issued, no co-branding, no external signatories, no partner logo rights. Strike line 1842 from the spec. Reversible in one line if wrong. 2026-07-23 |

**Also in Part R, no action needed:** R2 invitation-only (decided — SR001) · R12 avatar library only in Phase 1 (decided).

## Follow-on card edits arising from these decisions

Per the adjustment mechanism (edit a future card before it starts, never mid-flight):

- **S01** — seed six roles including **Member** (events/RSVP/directory only; denied student records, consent, enrolment, finance). Add Academy Admin **capability groups** per OD-17, the grant/revoke flow, and audit events for grants. Permission matrix probe must cover capabilities, not just roles.
- **S00** — i18n scaffold: locale files for EN/TC/SC, locale switcher, SC font fallback in the type stack, lint or review rule against hardcoded user-facing strings (OD-19).
- **S02** — jurisdiction field (OD-16); Learn thresholds as programme config fields, editable after creation (OD-12); **`team_categories` CRUD in the programme wizard's Team Rules section** per `docs/TEAM-CATEGORIES.md` — trilingual names, optional school binding, assignment rule, default flag with the partial unique index (OD-13, OD-13a, OD-13b); withdrawal policy seeds per OD-2 with **band schema built in full**.
- **S03** — per-programme `consent_requires_all_guardians` flag (OD-10).
- **S04A** — hold window default 7 days, configurable (OD-11). **Currency code + integer minor units on every monetary field (OD-18)** — this is schema-level and cannot be deferred.
- **S04B** — evidence upload is **1..n attachments** per payment record. No partial payments; underpayment → `unmatched_payments` with evidence, never applied (OD-5, OD-5a). **BI-9 unweakened (OD-14)**; both recorder and confirmer require the `finance` capability.
- **S05** — **tenure ledger + manual rotation recording** (OD-15); no rotation engine, no cadence logic. Sync hooks out of scope until S11. **Team formation per `docs/TEAM-CATEGORIES.md`**: lobby resolution order (§4), school-link enforcement blocked at formation with an inline reason (§5), `category` visibility level (§6), and the §8 edge cases — retired lobby keeps existing teams, revoked school link flags the teacher's exception queue, teams never change lobby, no cross-lobby joining.
- **S03** — placeholder consent wording in **all three languages**, flagged non-legal in template body and admin UI; **language-scoped template versions, one SHA-256 each** (OD-20); S10 check that no live template version still carries placeholder text and that no language version is missing (R15).
- **S08** — certificates are academy-issued only, **no co-branding of any kind** (R11 struck, OD-21). Certificate templates trilingual.
- **AMENDMENTS.md 2.20** — strike the underpayment path (OD-5a). `unmatched_payments` is fed only by overpayments and late payments (2.19).
- **S06** — Learn threshold: per-student for certification, per-team configurable percentage for the gate (OD-12).
- **S07** — assertion: no distribution transaction against a `charity` project.

## Change log
| # | Date | Change |
|---|------|--------|
| 1 | 2026-07-23 | OD-4, OD-5, OD-6, OD-8, OD-9 resolved; OD-2 provisional; OD-5a raised; Part R folded in as OD-10 to OD-16 |
| 2 | 2026-07-23 | OD-1 resolved: Member role included |
| 3 | 2026-07-23 | OD-5a (initial), OD-10, OD-11, OD-12, OD-13, OD-14, OD-16 resolved. OD-15 reframed — rotation is externally sourced; cadence is not our decision, tenure ledger retained, Phase 1 mechanism still open. **OD-17 (Admin RBAC capability groups)** and **OD-18 (currency representation)** raised and resolved. R11, R14, R15 listed explicitly as external/undecidable in-build |
| 4 | 2026-07-23 | OD-5a reversed — underpayments are not recorded at all; 2.20 underpayment path struck. OD-15 closed: rotation syncs via OIDC/Logto which lands S11, so Phase 1 records manually; tenure ledger retained. OD-13 default set. R14 closed (no ICP required). R15 resolved via placeholder wording. **R11 (HKUST) escalated — appears only at spec line 1842 and is disputed** |
| 5 | 2026-07-23 | OD-10, OD-12 confirmed by client. **OD-13 superseded by `docs/TEAM-CATEGORIES.md`** — categories are admin-defined formation lobbies, not a School/Armour taxonomy; OD-13a and OD-13b raised and resolved. R11 (HKUST) defaulted to struck |
| 6 | 2026-07-23 | **OD-19 (trilingual i18n EN/TC/SC, scaffolded in S00)**, **OD-20 (language-scoped consent template hashing)** and **OD-21 (no co-branding)** raised and resolved. OD-13a reversed — `name_sc` is live |
| 7 | 2026-07-24 | **OD-22 raised (open):** FR058 Member surfaces (event list, RSVP, directory) unassigned to any sprint card — found during the S01 card adjustment. S06 proposed; decision required before S06 starts |
| 8 | 2026-07-24 | **OD-22 resolved:** Member surfaces land in S06; no Member invitations issued until S06 delivers something to log into |
