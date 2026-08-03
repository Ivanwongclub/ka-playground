# KAP — Target-State Navigation Map (role by role)

> **Planning / documentation only. No app code, no commit.** This maps the FULL planned navigation each
> real role will have when all sprints ship — built *and* not-yet-built — sourced from the real repo:
> `web/src/nav.tsx`, `web/src/main.tsx` (routes), `api/routes/api.php` (middleware gates),
> `api/config/permission-matrix.php` (roles/capabilities/permissions), and `MeController`
> (`effectivePermissions`). Honesty discipline: nothing that will not exist is invented; every
> not-yet-built item is tagged and mapped to the sprint that builds it.

**Maturity legend (per item AND sub-item)**
- **VISIBLE NOW** — built and revealed in `nav.tsx` today.
- **HIDDEN STUB** — route exists as a `Placeholder` (D-UX1.1: nav item hidden until its card ships), or a built engine with a nav item deliberately withheld.
- **PENDING** — not built; the sprint that builds it is named.

**Scope:** real **provisionable roles** the auth model defines — not demo-account fixtures. A role with no
seeded demo account today (`school_admin`, `teacher`) is still a real role and appears at full
target-state; its missing account is annotated as a provisioning gap, never a reason to omit it.

---

## 1. Real roles & academy capabilities (from `config/permission-matrix.php`)

Six roles; `academy_admin` carries capability groups (OD-17), never sub-roles. "Seeded account" is a
**separate provisioning annotation** — every role is mapped regardless.

| Role | Default permissions (role) | Seeded demo account? |
|---|---|---|
| **student** | student_records.view, consent.view, enrolment.view, teams.view, events.view, events.rsvp | ✅ yes (sam/mia/kai/… @demo.ka) |
| **guardian** | student_records.view, consent.view, **consent.sign**, enrolment.view, **enrolment.create**, finance.view, events.view | ✅ yes (gordon/wendy @demo.ka) |
| **teacher** | student_records.view, teams.view, **teams.approve**, enrolment.view, events.view | ❌ **NO seeded account** (provisioning gap → S-UX4) |
| **school_admin** | student_records.view, **student_records.manage**, consent.view, enrolment.view, enrolment.create, finance.view, teams.view, events.view | ❌ **NO seeded account** (provisioning gap → S-UX4/S-UX3-6) |
| **member** | events.view, events.rsvp, member_directory.view | ❌ **NO seeded account** (invitation-only, OD-1/22) |
| **academy_admin** | events.view, member_directory.view **(base role only — the admin power comes from capabilities)** | ✅ yes (5 accounts) |

**Academy capabilities** (add to an `academy_admin`; `MeController` returns role ∪ capability permissions):

| Capability | Grants | Seeded? |
|---|---|---|
| **super_admin** | ALL permissions + capabilities.grant | ✅ super@demo.ka |
| **operations** | operations.manage, student_records.view/manage, consent.view, enrolment.view/create, teams.view, **teams.approve** | ✅ ops@demo.ka |
| **finance** | finance.view, **finance.record**, **finance.confirm** | ✅ finance1@ + finance2@ (two, for BI-9) |
| **configuration** | configuration.manage | ✅ (ops@ also holds configuration) |
| **audit_read** | audit.read | ✅ audit@demo.ka |

> **Nuance to surface:** a bare `academy_admin` with **no capability** sees only `events.view` +
> `member_directory.view` — essentially nothing in the admin cockpit. Admin nav is capability-driven,
> not role-driven.

---

## 2. Nav-item gate reference (the 15 built items + the stubs)

From `nav.tsx` (each `visible:(h)=>h('…')`) and `main.tsx` routes.

| Nav item | Route | Gate (`nav.tsx`) | Maturity |
|---|---|---|---|
| Dashboard | `/` | *(none — any authenticated user)* | VISIBLE NOW |
| Enrolments | `/enrolments` | `enrolment.view` | VISIBLE NOW |
| Consents | `/consents` (+ `/consents/:id` sign) | `consent.view` | VISIBLE NOW |
| Approvals | `/admin/approvals` | `operations.manage` | VISIBLE NOW |
| Withdrawals | `/admin/withdrawals` | `operations.manage` | VISIBLE NOW |
| **Team (成團)** | `/team` | `operations.manage` | VISIBLE NOW (STEP 1+2) |
| Programmes | `/admin/programmes` | `configuration.manage` | VISIBLE NOW |
| Consent Templates | `/admin/consent-templates` | `configuration.manage` | VISIBLE NOW |
| Enrolment Pool | `/admin/enrolment-pool` | `audit.read` | VISIBLE NOW |
| Payments | `/admin/payments` | `finance.record \|\| finance.confirm` | VISIBLE NOW |
| Refunds | `/admin/refunds` | `finance.record \|\| finance.confirm` | VISIBLE NOW |
| Financial Integrity | `/admin/financial-integrity` | `finance.record \|\| audit.read` | VISIBLE NOW |
| Access & Identity | `/admin/access-identity` | `audit.read` | VISIBLE NOW |
| Audit | `/admin/audit` | `audit.read` | VISIBLE NOW |
| Consent Evidence | `/admin/consent-evidence` | `audit.read` | VISIBLE NOW |
| *(Tracker)* | `/tracker` | *route stub, no nav item* | HIDDEN STUB (uncarded) |
| *(Learn)* | `/learn` | *route stub, no nav item* | HIDDEN STUB → S-UX3-4 |
| *(Profile)* | `/profile` | *route stub, no nav item* | HIDDEN STUB (uncarded) |

---

## 3. Per-role target-state navigation trees

Each item shows its maturity; sub-navigation (tabs / drawers / sub-routes / in-page sections) is listed
where it exists or is planned.

### 3.1 STUDENT  *(seeded ✅)*
- **Dashboard** `/` — VISIBLE NOW.
- **Enrolments** `/enrolments` (`enrolment.view`) — VISIBLE NOW. *View own enrolment status + consent gate. Students cannot create (guardian does).*
- **Consents** `/consents` (`consent.view`) — VISIBLE NOW. *View own consent status; **cannot sign** (no `consent.sign`).*
- **My Team (formation)** — **PENDING → S-UX3-3b.** Create/join a lobby, name a team, submit for 成團. *(APIs built: `POST /my/teams`, `/teams/{id}/join`, `/teams/{id}/submit` — all `role:student`.)* Today `/team` is `operations.manage`-gated, so **the student sees no team nav yet**.
  - Sub: lobby list · my team detail · member roster · submit-for-成團 action — all PENDING (S-UX3-3b).
- **Sessions / Learn** `/learn` — **PENDING → S-UX3-4.** Book/cancel sessions (`/my/sessions/{id}/book|cancel`, `role:student`), Learn-stage progress. *(HIDDEN STUB route today.)*
- **Activity Tracker** `/tracker` — **HIDDEN STUB, uncarded.** Plan/Design/Learn/Pitch/Launch stages.
- **Profile** `/profile` — HIDDEN STUB (uncarded).
- Team-project finance (as a team member): **PENDING → S-UX3-5** (record team transactions/evidence). *API-only today.*

### 3.2 GUARDIAN  *(seeded ✅)*
- **Dashboard** `/` — VISIBLE NOW.
- **Enrolments** `/enrolments` (`enrolment.view`) — VISIBLE NOW. *Their children's enrolments; **enrol action** via `POST /my/enrolments` (`role:guardian`).*
- **Consents** `/consents` + `/consents/:id` (`consent.view` + `consent.sign`) — VISIBLE NOW. *Sub-route = the sign flow: scroll-to-end gate → affirmation → drawn/typed signature → signed PDF. Guardian is the signer.*
- **My Children / consent status** — **partially API-only.** `GET /my/students/{studentId}/consent-status` (`role:guardian`) is built; there is **no dedicated "My Children" nav card** — it is folded into Enrolments/Consents. *Planned consolidation: uncarded (gap).*
- **Pay surfaces** — **API-only / anonymous, no guardian nav card.** Guardian mints a forwardable link (`POST /my/orders/{id}/payment-link`, `role:guardian`) and lists `/my/payment-links`; payment itself happens on the anonymous **`/pay/{token}`** page (§4). There is **no authenticated "My Payments" nav item** — a gap (see §6).
- **Profile** `/profile` — HIDDEN STUB.
- *Note:* guardian holds `finance.view` (their own money) but **not** `finance.record/confirm`, so the admin Payments/Refunds nav never shows for them (correct).

### 3.3 TEACHER  *(NO seeded account — provisioning gap → S-UX4)*
Real role; full planned nav below even though no account exists today.
- **Dashboard** `/` — VISIBLE NOW (once an account exists).
- **Enrolments** `/enrolments` (`enrolment.view`) — VISIBLE NOW.
- **My Students** (`student_records.view`) — **no nav card planned yet** (API-only; gap).
- **Team / gates** (`teams.view` + **`teams.approve`**) — **PENDING.** Teacher can approve activity-stage gates (`POST /teams/{id}/gates/{stage}/approve`) and is a team-linked mentor. Today `/team` is `operations.manage`-gated → **teacher sees no team nav**; a teacher-facing team/gate view is **PENDING → S-UX3-3a STEP 3 (roles/gates) + S-UX4 (account)**.
- **Attendance** — **PENDING → S-UX3-4.** Mark attendance (`POST /admin/sessions/{id}/attendance`, authority in-service = mentor/ops). No nav today.
- **Profile** `/profile` — HIDDEN STUB.

### 3.4 SCHOOL_ADMIN  *(NO seeded account — provisioning gap → S-UX4/S-UX3-6)*
- **Dashboard** `/` — VISIBLE NOW (once provisioned).
- **Enrolments** `/enrolments` (`enrolment.view` + `enrolment.create`) — VISIBLE NOW. *School can enrol its students.*
- **Consents** `/consents` (`consent.view`) — VISIBLE NOW (view).
- **Students** (`student_records.manage`) — **PENDING → S-UX3-6.** Manage/bulk-create the school's students. *(Bulk creation engine = S04D; CSV bulk enrolment engine = S04E.)*
- **School Portal** — **PENDING → S-UX3-6.** The school_admin home:
  - **Lobby teams** — teams in the school's lobby/category (`teams.view`; 成團 authority via OD-39 lobby-admin). PENDING.
  - **Bulk intake** — CSV enrolment intake + batch dashboard (S04E engine). PENDING nav; **API/engine built**.
  - **Invoicing** — consolidated school-settled invoices, aging (S04F engine; school is payer not collector). PENDING nav; **API/engine built** but currently **API-only** (see §6).
- **Team (成團)** — school_admin is a legitimate OD-39 approver **server-side**, but `/team` nav is `operations.manage`-gated → **not shown**. Lobby-admin 成團 access is **PENDING → S-UX3-6** (or a widened gate).
- **Finance (read)** — `finance.view` (their own money) — no dedicated card; part of School Portal invoicing (PENDING).
- **Profile** `/profile` — HIDDEN STUB.

### 3.5 MEMBER  *(NO seeded account — invitation-only, OD-1/22)*
First-generation Kings Network members — events, RSVP, directory only.
- **Dashboard** `/` — VISIBLE NOW (once provisioned).
- **Events** (`events.view`, `events.rsvp`) — **PENDING, UNCARDED.** `GET /events` (RLS-shaped), `POST /events/{id}/rsvp`, `GET /my/rsvps` are **built (S06)** but **API-only, no nav card and no S-UX card** (gap — see §6).
- **Directory** (`member_directory.view`) — **PENDING, UNCARDED.** `GET /directory` built (S06), **API-only, no nav**.
- **Profile** `/profile` (`PUT /my/profile`, `role:member`) — HIDDEN STUB route exists; member profile edit built (S06), **no nav card**.
- *Members hold none of the enrolment/consent/finance/team permissions — their entire surface is the three above, and none has a nav card yet.*

### 3.6 ACADEMY_ADMIN — by capability  *(seeded ✅)*
Admin nav is capability-driven. A bare academy_admin sees almost nothing; each capability reveals its slice.

- **operations** → Dashboard · **Approvals** · **Withdrawals** · **Team (成團)** (all VISIBLE NOW) · plus PENDING: Team roles/tenure (**S-UX3-3a STEP 3**), below-min resolution + matching + capacity (**S-UX3-3a STEP 4**), attendance/sessions authority (**S-UX3-4**). Enrolments/Consents also visible (operations grants those views).
- **configuration** → **Programmes** (+ wizard sub-sections, team-categories, fee-items, versions, publish) · **Consent Templates** (+ versions, publish) — VISIBLE NOW.
- **finance** → **Payments** · **Refunds** · **Financial Integrity** — VISIBLE NOW.
  - Payments sub: record form + evidence upload · pending-confirmation table · confirm/reject (BI-9: recorder ≠ confirmer).
  - Refunds sub: approve table · confirm table (approve ≠ confirm).
- **audit_read** → **Enrolment Pool** · **Access & Identity** · **Audit log** · **Consent Evidence** · Financial Integrity (read) — VISIBLE NOW.
- **super_admin** → everything above · plus **Capabilities admin** (grant/revoke, `capabilities.grant`) — **PENDING → S-UX3-7** (`POST /admin/capabilities/grant|revoke` built, no nav).
- Cross-capability PENDING: **Team-Project Finance** oversight (budgets/transactions/fundraising/finance-report, S07 engine) — **PENDING → S-UX3-5**, API-only today.

---

## 4. Anonymous / role-less surfaces (no nav by design)

Built (S04C / OD-44); unauthenticated; deliberately outside the nav.

| Surface | Route | Sprint | Child-safety constraint |
|---|---|---|---|
| Self-registration | `/register` | S04C / OD-23 | Anonymous INSERT under RLS; constant-shape responses (no account enumeration). |
| Email activation | `/activate/{token}` | S04C / OD-29 | Single verify-and-activate; token-scoped. |
| Forwardable payment | `/pay/{token}` | S04B / OD-44 | **Initials only, no PII**; expiring token; shows programme + amount, never the child's full identity. |

These are **VISIBLE NOW** as pages, correctly with **no nav** (there is no authenticated user to show a menu to).

---

## 5. Role × Nav matrix (full planned scope)

Columns are real roles; academy_admin split by capability. Cell: **✅** visible now · **○** hidden-stub ·
**◻** pending · **—** never (no gate). Academy caps stack on one account (e.g. ops@ holds ops+config).

| Nav item (→ sprint if not built) | Student | Guardian | Teacher | School-Admin | Member | AA·ops | AA·finance | AA·config | AA·audit | AA·super |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Dashboard | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Enrolments | ✅ | ✅ | ✅ | ✅ | — | ✅ | — | — | — | ✅ |
| Consents (view) | ✅ | ✅ | — | ✅ | — | ✅ | — | — | — | ✅ |
| — Consent sign flow `/consents/:id` | — | ✅ | — | — | — | — | — | — | — | ✅ |
| Approvals | — | — | — | — | — | ✅ | — | — | — | ✅ |
| Withdrawals | — | — | — | — | — | ✅ | — | — | — | ✅ |
| Team (成團) queue + detail drawer | — | — | — | — | — | ✅ | — | — | — | ✅ |
| — Team roles/tenure → S-UX3-3a STEP 3 | — | — | ◻ | ◻ | — | ◻ | — | — | — | ◻ |
| — Below-min resolution/matching → STEP 4 | — | — | — | ◻ | — | ◻ | — | — | — | ◻ |
| Student formation (my team) → S-UX3-3b | ◻ | — | — | — | — | — | — | — | — | ◻ |
| Programmes (+wizard) | — | — | — | — | — | — | — | ✅ | — | ✅ |
| Consent Templates | — | — | — | — | — | — | — | ✅ | — | ✅ |
| Enrolment Pool | — | — | — | — | — | — | — | — | ✅ | ✅ |
| Payments (record/confirm) | — | — | — | — | — | — | ✅ | — | — | ✅ |
| Refunds (approve/confirm) | — | — | — | — | — | — | ✅ | — | — | ✅ |
| Financial Integrity | — | — | — | — | — | — | ✅ | — | ✅ | ✅ |
| Access & Identity | — | — | — | — | — | — | — | — | ✅ | ✅ |
| Audit log | — | — | — | — | — | — | — | — | ✅ | ✅ |
| Consent Evidence | — | — | — | — | — | — | — | — | ✅ | ✅ |
| Sessions / Learn → S-UX3-4 | ◻ | — | ◻ | — | — | ◻ | — | — | — | ◻ |
| Activity Tracker `/tracker` (uncarded) | ○ | — | ○ | — | — | ○ | — | — | — | ○ |
| Team-Project Finance → S-UX3-5 | ◻ | — | — | — | — | ◻ | — | — | — | ◻ |
| School Portal (lobby/bulk/invoice) → S-UX3-6 | — | — | — | ◻ | — | — | — | — | — | ◻ |
| Capabilities admin → S-UX3-7 | — | — | — | — | — | — | — | — | — | ◻ |
| Member: Events (uncarded) | ◻¹ | — | — | — | ◻ | — | — | — | — | ◻ |
| Member: Directory (uncarded) | — | — | — | — | ◻ | — | — | — | — | ◻ |
| Profile `/profile` (uncarded) | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |

¹ students hold `events.rsvp` but have no built/planned events nav; shown ◻ only if an events surface is later carded for them.

---

## 6. Scope-gap callouts (the point of this map)

**A. Provisioning gaps — real roles with no seeded account**
- **teacher** — real role (teams.approve, attendance) but **no demo account**; blocks demoing gate-approval/attendance. → **S-UX4** (account + seed).
- **school_admin** — real role (lobby 成團 authority, bulk intake, invoicing) but **no demo account**. → **S-UX4 / S-UX3-6**.
- **member** — invitation-only by design (OD-1/22); no account is expected, but the *surfaces* have no UI (below).
- Fold any new teacher/school_admin demo accounts into Leo's **fresh re-seed**, not a hand-insert (consistent with the pending S-UX3-2/3-3a re-seed).

**B. Built engine, API-only, NO planned nav card (surface-without-a-door)**
- **Member surfaces (S06):** `/events`, `/events/{id}/rsvp`, `/my/rsvps`, `/directory`, `/my/profile` — all **built, none carded to an S-UX number**. The member role's *entire* product surface currently has no nav. **Biggest gap.**
- **Guardian payments:** `/my/payment-links` exists but there is **no authenticated "My Payments" nav**; guardians rely on the anonymous `/pay/{token}` page. Consider a carded guardian payments view.
- **Guardian "My Children":** `/my/students/{id}/consent-status` is API-only; no consolidated children card.
- **Teacher "My Students":** `student_records.view` API-only; no nav.
- **School invoicing (S04F) & bulk intake (S04E):** engines built, **API-only**, folded into the PENDING School Portal (S-UX3-6) but not yet carded to screens.

**C. Nav items whose target screen isn't built (with the sprint)**
- Team **roles/tenure** → S-UX3-3a **STEP 3**; **below-min resolution + matching + capacity** → S-UX3-3a **STEP 4**.
- **Student team formation** (create/join/submit) → **S-UX3-3b**.
- **Sessions / attendance / Learn** → **S-UX3-4** (`/learn` stub today).
- **Team-Project Finance** (budgets/transactions/fundraising/finance-report, S07) → **S-UX3-5**.
- **School Portal** (lobby teams, bulk intake, invoicing) → **S-UX3-6**.
- **Capabilities admin** (grant/revoke) → **S-UX3-7**.
- **teacher/school_admin accounts + seed** → **S-UX4**.

**D. Gate mismatch worth a decision (not a bug, a nav-policy gap)**
- `/team` nav is gated **`operations.manage`** (academy ops/super only), but the server 成團 authority (OD-39) also admits **lobby school_admin** and gate-approval admits **teacher (teams.approve)**. So two roles that *can* act on teams server-side **see no team nav**. Intentional for the ops-first STEP 1–2; the school_admin/teacher team surfaces are the PENDING S-UX3-6 / S-UX4 work. Flagging so the widened gate is a conscious card, not an accident.

**E. Uncarded pending chunks (no S-UX number yet)**
- **Activity Tracker** (`/tracker` stub; Plan/Design/Learn/Pitch/Launch) — engine hooks exist (stage-gate approval), **UI uncarded**.
- **Profile** (`/profile` stub) — all roles; **uncarded**.
- **Member Events/Directory** — **uncarded** (see B).

---

*Sources: `web/src/nav.tsx`, `web/src/main.tsx`, `api/routes/api.php`, `api/config/permission-matrix.php`,
`api/app/Http/Controllers/MeController.php` + `PermissionResolver`. Read-only; no code changed.*
