# KA Playground — Data Model & Schema Reference

> *Schema current to `main` as of 2026-08-12 (58 migrations). Extracted directly from the migration source, including all row-level-security policies.*


> **Purpose of this document.** This is a complete, self-contained description of the data model, entity relationships, lifecycles, and access-control (row-level security) boundaries of a real programme-management platform. It exists so a UI/UX designer or researcher can propose an interface **grounded in the actual data structure and its hard constraints** — not a generic CRM mock-up. Read the whole thing before designing. Nothing here depends on any other conversation, file, or prior context.
>
> **The two rules that govern every screen** (they recur throughout — internalize them):
> 1. **A person only ever sees what their own access returns.** Every table below is protected by row-level security (RLS): the database itself filters rows by *who is asking*. A screen cannot show a number, a name, a card, or a count that the viewer's own reads don't return. "Show the student's guardian" is only valid *if that viewer is entitled to that guardian* — otherwise the element must not exist. Design "a card exists **if and only if** the viewer's reads return it," never "a card that's hidden by permission."
> 2. **Users never move records; the server does.** Every state transition (enrol → in pool → teamed → confirmed → active; consent sent → signed; order issued → paid → confirmed; assessment graded → released) is written by the system under its own context after checking invariants. The UI *requests* a transition (and shows its consequence first); it never drags a card between columns or flips a status directly. Any "kanban" is **read-only occupancy**, not a manipulable board.

---

## 1. What the platform is

A Hong-Kong-based programme-management platform for youth academies. It manages the full lifecycle of a young person's participation in learning programmes: family enrolment, guardian/student linkage, consent ceremonies, team formation, session attendance, learning delivery, family-paid and school-settled payments, team-project finance, and recognition/assessment.

**Two non-negotiable governing concerns run through everything: child safety and financial integrity.** These are why the access model is strict and why transitions are server-owned.

### The personas (who uses it)

| Persona | Who they are | Grammar |
|---|---|---|
| **Student** | The young participant. Enrolment is done *for* them by a guardian; they observe and participate. | Product-simple, phone-first |
| **Guardian** | Parent/guardian. Signs consent, pays fees, acts on behalf of their linked children. | Product-simple, phone-first |
| **Member** | A lightweight community/recognition persona (limited surfaces). | Product-simple |
| **Teacher / Mentor** | Delivers/mentors specific teams; sees only their assigned teams (names-only, per-programme, where enabled). | Enterprise-dense |
| **School admin** | Administers a school; sees students/teams affiliated to *their* school. | Enterprise-dense |
| **Academy admin (Operations / Finance / Audit / Super)** | Staff who run admissions, delivery, finance, and audit. Capabilities gate what they can do. | Enterprise-dense, desktop-first CRM/ERP |

The staff side is a genuine multi-capability back office (operations, finance with four-eyes controls, append-only audit). The family side is deliberately simple.

---

## 2. The spine of the model: **student → many enrolments → per-programme children**

This is the single most important structural fact. **A student is not "in a programme." A student has many *enrolments*, one per programme, and every downstream thing (team, sessions, tracker, results, fees) hangs off a specific enrolment / programme — never off the student globally.**

```
                         ┌─────────────────────────────────────────────┐
                         │                  STUDENT                     │
                         │              (a user record)                 │
                         └───────────────────┬─────────────────────────┘
                                             │ 1-to-many
              ┌──────────────────────────────┼──────────────────────────────┐
              ▼                               ▼                              ▼
       ┌────────────┐                  ┌────────────┐                 ┌────────────┐
       │ ENROLMENT  │                  │ ENROLMENT  │                 │ ENROLMENT  │
       │ Programme A│                  │ Programme B│                 │ Programme C│
       │ status: …  │                  │ status: …  │                 │ status: …  │
       └──────┬─────┘                  └──────┬─────┘                 └──────┬─────┘
              │ each enrolment/programme has ITS OWN:                        │
              ▼                                                              ▼
   ┌───────────────────────────────────────────────────────────┐
   │  • a TEAM (via team_members → teams.programme_id)          │
   │  • SESSIONS (sessions.programme_id) + the student's bookings│
   │  • a TRACKER / stage-gates (per team)                     │
   │  • ASSESSMENT RESULTS (per assessment, per enrolment)     │
   │  • an ORDER + PAYMENT OBLIGATION (the fee for THIS enrol) │
   │  • a CONSENT REQUEST (for THIS enrolment)                 │
   └───────────────────────────────────────────────────────────┘
```

**Design consequence:** a student's "record" is a parent with a *collection of enrolments*. Team / sessions / tracker / results are meaningless without first choosing *which enrolment*. Any nav that puts "My Team" or "My Sessions" as a single flat destination is wrong for a multi-programme student — those are properties *of one enrolment*.

This matches, almost exactly, the education CRM data model used by Salesforce Education Cloud (a **Contact** has many **Program Enrollments**; each Program Enrollment has its own **Course Connections** / child records). If you research one external reference model, that's the closest.

---

## 3. Entities & relationships (the real schema)

Stack: Laravel + PostgreSQL (v17) with row-level security, React front end. IDs are a mix of `bigint` (users, programmes, schools) and `uuid` (most domain rows). All timestamps are `timestamptz`.

### 3.1 Identity & relationships

**`users`** — every person (student, guardian, teacher, school_admin, academy_admin, member) is a row here.
`id`, `name`, `email` (unique), `password`, activation/lockout columns, timestamps.
→ Role and capabilities are held in a separate authz layer (a user has one role key + a set of capability strings).

**`guardian_links`** — the student↔guardian relationship. **This is the backbone of child safety.**
`id` (uuid), `student_id` → users, `guardian_id` → users, `status` (a state machine; every transition audited), `verified_at`, `permission_overrides` (jsonb), `origin` (`onboarding | pairing_code | parent_initiated | school_mediated`), timestamps.
Constraint: **one active link per (student, guardian) pair.** A student may have more than one guardian; a guardian may have many students.

**`school_links`** — student↔school affiliation. `student_id`, `school_id` → schools, `status`. Constraint: **a student has at most one *active* school link.**

**`teacher_links`** — teacher↔school affiliation. `teacher_id`, `school_id`, `status`.

**`team_teacher_links`** — teacher/mentor↔team assignment (which mentor is on which team). `teacher_id`, `team_id`, `status`.

**`schools`** — `id`, trilingual `name_en / name_tc / name_sc`, timestamps.

**`invitations`** — invitation-only onboarding. `email`, `role` (`guardian | teacher | school_admin | academy_admin` — **never student**: students are guardian-led, never self-invited), single-use `token_hash`, `issued_by`, `expires_at` (issued + 14 days), `accepted_at`, `user_id`.
**`pairing_codes`** — short codes used to establish a guardian link in person.

> **Child-safety design facts you must honor:**
> - A student is **never** self-created or self-invited. Enrolment and consent are **guardian-actored**.
> - The story the platform tells: *"a student can ask; only a guardian can consent / enrol / pay."*
> - Cross-family visibility is forbidden by RLS: Guardian A can never see Guardian B's children, and vice-versa.

### 3.2 Programmes & configuration

**`programmes`** — a learning programme. Has a code, publication status, an enrolment window, brand attributes (including a `brand_color`), a `mentor_team_access` boolean (per-programme toggle: may linked mentors see their team's roster/tracker — **names-only, read-only, and only where this flag is on**), a nullable **`banner_upload_id`** (a reference to a virus-scanned `uploads` row — the programme's storefront/marketing banner image; **optional** — absent or not-yet-clean → the UI falls back to `brand_color`), and versions (`programme_versions`). Trilingual labels. *(Note: `programmes` has no RLS — it's the public catalogue; the mentor flag and banner are readable in every context.)*
**`team_categories`** — the "lobby" a team belongs to (scopes to a school or is open). Referenced by teams; **never references teams back** (avoids a policy cycle).
**`fee_items`** — priced items configurable per programme.
**`programme_capacity`** — capacity limits.
**`wizard_sections`** — the programme-setup wizard's stored sections.

### 3.3 Enrolment (the join, and its lifecycle)

**`enrolments`** — **the student↔programme join. The spine.**
`id` (uuid), `programme_id` → programmes, `student_id` → users, `acting_guardian_id` → users (the guardian who performed the enrolment), `status`, timestamps.

**Enrolment status lifecycle** (server-owned transitions):
```
submitted → (consent) → in_pool → teamed → confirmed → active
                              ↘ (withdrawal) → withdrawn
```
- `submitted` — guardian has enrolled the student.
- `in_pool` — consent satisfied; student is in the matching pool for that programme.
- `teamed` — placed in a team.
- `confirmed` — the academy confirmed the team (checks consent + minimums).
- `active` — running.

**`enrolment_batches` / `enrolment_batch_rows`** — bulk enrolment operations (batch commit).

### 3.4 Consent (the ceremony)

**`consent_template_versions`** — versioned, trilingual consent copy; each version has a hash. `is_material` flag.
**`consent_requests`** — a consent ask for a specific enrolment/student. `student_id`, `status` (`draft | sent | viewed | signed | declined | expired | superseded`), `expires_at`. (Note the **`expires_at`** — urgency is real data, not decoration.)
**`consent_signatures`** — **immutable evidence.** `request_id` (unique), `language` (the language actually rendered to the signer, server-recorded), `template_version_id`, `template_sha256` (hash of the exact version signed), `signature_payload` (jsonb: stroke vectors / typed name), `created_at`. Append-only; enforced by triggers. *Even a bypassed controller cannot write a signature as someone else.*
**`consent_documents`** — generated consent PDFs/records.

> **Consent is the strictest write in the system.** Only the signer, among portal roles, can create their own signature. A guardian signs for their linked child; the student observes status only.

### 3.5 Teams, membership, roles, tracker

**`teams`** — `id` (uuid), `programme_id` → programmes, `category_id` → team_categories ("the lobby, for life"), `name`, `status` (`forming | submitted | confirmed | disbanded`), `created_by`.
**`team_members`** — `team_id`, `category_id` (denormalized from the team — deliberately, to break an RLS recursion between teams and team_members), `student_id`, `status` (`active | suspended | removed`).
**`team_teacher_links`** — mentor assignment (above).
**`roles` / tracker tables** — a member's role within a team, and the team's progress.
**`stage_gates`** — the team's tracker stages (e.g. Plan · Design · Learn · Pitch · Launch); each gate has a pass/label state. Stored per team.
**`tenures`** — a student's participation record over time.
**`team_exceptions`, `team_resilience`** — edge-case handling (a member leaving, etc.).

> **Team is reached *through* an enrolment/programme.** A student's team for Programme A is unrelated to their team for Programme B. Mentors see a team's roster/tracker only where the programme's `mentor_team_access` is enabled, and only **names-only**.

### 3.6 Sessions & attendance

**`sessions`** (a.k.a. programme sessions) — `programme_id` → programmes, `team_id` (nullable: null = programme-wide session, set = team-specific), `starts_at`, `status` (`draft | published | full | in_progress | completed | cancelled | rescheduled`), `mentor_id`.
**Session bookings** — `session_id`, per-student booking `status` (`booked | waitlisted | cancelled | attended | no_show`).
**Session versions** — snapshots of a session before each change (audit of schedule changes).

### 3.7 Money (family-paid & school-settled) — *financial-integrity tier*

**`orders`** + **`receipts`** — the order for an enrolment's fee; receipts have a monotonic sequence (`receipt_sequences`).
**`payment_obligations`** — who owes what for an enrolment (family-paid vs school-settled).
**`payment_links`** + **`payments`** — payment links issued and payments recorded against them.
Manual payments, **refunds**, **credit_notes** — the reversal side.
**`invoice_aging`** — aging of school-settled invoices.
**Team-project finance:** `team_budgets`, `budget_lines`, `team_transactions`, `team_fundraising` — a team can hold a budget, record transactions, and run fundraising.

> **Financial-integrity design facts:**
> - **Four-eyes control:** the officer who *records* a payment cannot be the one who *confirms* it. A confirm button must be disabled for the recording officer.
> - A **finance-only** officer is **not entitled to a child's name** — finance surfaces show the money, with the person often reduced to "—". Do not assume a finance screen may show student names.
> - Amounts, receipts, and payment methods live on the **guardian/finance** side. Whether a *student* may see even fee *status* on their own enrolment is an open question — do not assume it.
> - Orders carry `payment_due_at` and line items — again, urgency/detail is real data.

### 3.8 Withdrawals & recognition

**`withdrawal_requests`** — `student_id`, reason, refund-window state. **`withdrawal_endorsements`** — endorsements on a withdrawal.
**`withdrawal_policy` tables** — the refund-window rules.

**`assessments`** — `programme_id`, `status` (`draft | published | open | closed | graded | released | cancelled`), `created_by`.
**`assessment_results`** — `assessment_id`, `student_id`, `enrolment_id` (unique per assessment+enrolment), `score` (nullable).

> **THE EMBARGO (a read-time visibility gate, not just a workflow state):**
> A family sees a student's **result** ONLY once the parent assessment reaches **`released`**. Grader/academy see all states. A graded-but-not-released score is invisible to the family *at the database read level* — it is not merely hidden in the UI. So: a results surface shows **nothing** before release, and only released scores after. Never design a "pending score" that a family could glimpse.

### 3.9 Infrastructure / cross-cutting

**`audit_events`** — append-only audit log. `actor_id` (unconstrained: an audit row must outlive the actor), `actor_role`, `on_behalf_of`, `entity_type`, `entity_id`, `from_state`, `to_state`, `action`, `reason`, `payload_before/after`, `ip`, `request_id`, `programme_id`. Read by auditors; never updated or deleted.
**`uploads`** — every uploaded file. `context`, `disk`, `path`, `sha256`, `status` (`pending | clean | quarantined`), `scan_signature` (virus name on a hit). **Files are virus-scanned (ClamAV); only `clean` files are served.** Consent documents, and **programme storefront banner images** (referenced by `programmes.banner_upload_id`), flow through here — so a programme card's image is *a scan-clean uploads row*, with the programme's `brand_color` as the fallback when there's no clean banner. Design programme-card imagery against this real field, not an invented image URL.
**`reconciliation_log`** — financial reconciliation runs (books-balance checks).
**`registration_requests`, `held_links`, `onboarding_exceptions`** — the onboarding/approval queue and its edge cases.

---

## 4. Access-control boundaries (RLS) — *who can see each thing*

Every domain table has `ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY`. The database evaluates a policy per row using **session settings** the app sets per request: `app.actor_id`, `app.actor_role`, `app.capabilities`, `app.student_ids` (the students this actor is entitled to — their own or their linked children), `app.school_ids`, `app.context` (`system` for server-owned writes).

Below are the **actual read predicates** for the core entities (simplified to plain English from the live policies). Design so that **every element on a screen corresponds to a row this viewer's predicate returns.**

### `enrolments` — read
A row is visible if **any** of:
- context = `system`; **or**
- actor is an academy admin with `operations`, `audit_read`, or `super_admin` capability (ops/audit see all); **or**
- `student_id` = the actor (the student themselves); **or**
- `student_id` ∈ the actor's entitled students (a guardian, for their linked children); **or**
- actor is a `school_admin` and the student has an active `school_link` to one of the actor's schools.

**enrolment insert:** only `system`, **or** a `guardian`, for their own linked student, acting as themselves. (Enrolment is guardian-actored — a student cannot create their own enrolment.)
**enrolment update/delete:** `system` only (the state machine owns transitions).

### `users` — read/insert/update
Scoped expressions (a person sees themselves and the users they're entitled to — their linked children/guardians, their school's students for a school_admin, all for ops/audit). Deletes are system-only.

### `teams` — read
Visible if: `system`; **or** ops/audit; **or** the actor is a **member of the team** (a student in it, or that student's guardian); **or** the actor is the **school admin of the team's lobby**; **or** the "lobby wall": a `forming` team is visible to a `student` who is `in_pool`/`teamed` in that programme and whose school matches the lobby (so students can find a forming team to join). Insert: `system`, or a student creating a team as themselves. Update/delete: `system` only.

### `team_members` — read
Visible if: `system`; **or** ops/audit; **or** `student_id` = actor; **or** `student_id` ∈ entitled students (guardian); **or** a school_admin of the member's category/lobby.
(Note the deliberate denormalization of `category_id` onto `team_members` to avoid a policy recursion with `teams`.)

### `team_teacher_links` — read
Visible if: `system`; ops/audit; the **teacher themselves** (`teacher_id` = actor); or the school admin of the team.

### `stage_gates` (tracker) — read
Visible if: `system`; ops/audit; the school admin; **or** a member of the team (the student or their guardian); **or** the **active mentor** of that team.

### `tenures` — read
Visible if: `system`; ops/audit; the student; their guardian (active link); or the school admin.

### `consent_requests` — read
The **signer** (the guardian/student on the request), the **school admin** of the student's school (for chasing), ops/audit, system. **Writes:** system (per-enrolment issuance) or academy operations (manual). The signer updates their own request's status.
### `consent_signatures` — the strictest
Read by the signer + ops/audit/system; **the signer alone among portal roles can insert**, and only their own. Immutable.

### `assessment_results` — read *(carries the embargo)*
A **roster** rule lets a programme's student (and their guardian) see that an assessment *exists* (title/status). But a **result row** is visible to the family **only when the parent assessment's status is `released`**. Academy/grader see all states. This gate is in the read policy itself.

### Money tables (`orders`, `payment_obligations`, `payments`, `refunds`, …)
Guardian sees their own family's orders/obligations/payments (via entitled students). Finance officers see the money but **not necessarily the child's name** (finance-only capability does not grant identity). Four-eyes: recording vs confirming a payment are different actors. System owns state transitions.

### `audit_events` — read
Auditors (audit_read capability) and system. Append-only; no update/delete policy exists.

---

## 5. Cross-persona summary — *what each persona's record surfaces look like*

Use this as the "who sees what" cheat-sheet. **Every cell is "the viewer's own entitled reads," never a widened view.**

| Surface | Student (self) | Guardian (their child) | Mentor (their team) | School admin (their school) | Ops / Audit |
|---|---|---|---|---|---|
| Enrolments | own | their child's | — | school's students | all |
| Team / members | own team, teammates **names-only** | their child's team | their team, **names-only**, if programme `mentor_team_access` on | school's teams | all |
| Tracker / stage-gates | own team's | their child's team's | their team's (if enabled) | school's | all |
| Sessions / bookings | own | their child's | their team's | school's | all |
| Consent | **observes** status | **signs**, sees status | — | chases (school's) | all |
| Assessment results | own, **released only** | child's, **released only** | — (grades as grader if assigned) | school's, released | all states |
| Fees / orders / payments | **uncertain — confirm entitlement** (at most status, no amounts) | own family's amounts + receipts | — | school-settled invoices for school | finance sees money, **not names** |
| Guardian / school / mentor links | can see *if linked* (display-only, no drill target) | is the guardian | sees own team links | school's | all |
| People search / directory | none (nav only) | none (only own children) | own team | own school | full search |

**Linkified names:** whether a name is a clickable link or plain text depends on whether *that viewer* has a record to open behind it. A student seeing their mentor's name has **no mentor record to open** → plain text. Ops seeing a student's name → a real Student record → link. Design names as "link if the viewer has an entitled destination, else text."

---

## 6. Fields that carry urgency/detail (often under-surfaced)

The platform stores decision-driving data that a good UI must surface at the point of decision, rather than burying:
- `consent_requests.expires_at` — consent deadlines (a guardian needs to see "expires in 6 days").
- `orders.payment_due_at` + order line items — what's owed, for what, by when.
- enrolment `status` + the pool/team/confirm stage — "where is my child in the journey."
- assessment `released` transition — the moment a result becomes visible.
- session `starts_at` / booking `status` — next session, booked or not.
- withdrawal refund-window state — is a full refund still available.

A recurring finding on the existing product: the *mechanics* are correct but the *decision evidence* is often off-screen (stored in the DB, never surfaced). Surfacing these well is a primary UX opportunity.

---

## 7. Hard constraints for any design (the "do not violate" list)

1. **No element without an entitled read.** Every card, count, name, chart, or related record must correspond to rows the viewer's RLS returns. "A card that exists only if linked" — not "a card hidden by permission."
2. **The server owns transitions.** No drag-to-change status. Actions request a server transition and must show the consequence first (especially irreversible ones like assessment *release*).
3. **Enrolment-centric.** Team / sessions / tracker / results / fees are properties of *one enrolment/programme*. A multi-programme student picks a programme first; those surfaces are scoped to it. Don't flatten them into single global destinations.
4. **Consent & enrolment are guardian-actored.** Students observe and request; guardians consent, enrol, pay. Never design a student-initiated consent/enrolment/payment.
5. **The embargo is real.** Results show nothing before `released`. No "pending grade" a family could see.
6. **Four-eyes on money.** Recording ≠ confirming a payment. Finance may not see child names.
7. **Cross-family isolation.** Never a path where one family sees another's children.
8. **Names-only for mentors**, and only where the programme enables mentor team access.
9. **Files are scanned.** Only `clean` uploads are served; banners/documents go through the upload+scan pipeline.
10. **Trilingual.** English, Traditional Chinese, Simplified Chinese are first-class. (One fixed terminology rule in English UI: the team-formation concept is written **"Team Formation"** in all English text.)

---

## 8. Suggested research framing (optional)

If researching external reference patterns, the most relevant are:
- **Salesforce Education Cloud (EDA)** — Contact → Program Enrollments → Course Connections; the "related records as cards / related lists" pattern; the record-with-children navigation. This maps to student → enrolments → per-programme children almost 1:1.
- **CRM record pages generally** (Salesforce Lightning, HubSpot associations, Microsoft Dynamics model-driven "Related" tabs) — for the parent-record + related-records-as-cards + context-switching pattern, *but* remember every "the operator sees/moves the record" assumption must be re-read as "the viewer sees only their entitled reads; the server moves the record."
- **Student information systems / LMS** (Ellucian, Anthology, PowerSchool, Canvas) — for enrolment-centric IA where one person has many enrolments each with sub-records.

The goal is an interface that expresses **person → many enrolments (as navigable cards) → one enrolment's scoped detail (team · sessions · tracker · results · fees) with a context switcher**, where every surface is bounded by the RLS table above.

---

*End of reference. This document is self-contained; design proposals should cite the section numbers above when they touch a constraint.*
