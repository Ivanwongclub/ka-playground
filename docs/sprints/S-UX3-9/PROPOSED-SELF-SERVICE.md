# PROPOSED — S-UX3-9 · Guardian / teacher self-service (My Children · My Payments · My Students)

**Think-first. Plan only — no code, no commit.** Resolved from source (routes/api.php + the S04B/S06/
S-UX3-4 RLS). The nav map's **gap C**: engines exist, these are the doors. Early read: *"display over built
endpoints, likely batches."*

---

## 0. Headline (confirm / correct)

**Mostly CONFIRMED — it batches, over built RLS reads.** Two corrections:

- ⚠️ **Teacher "My Students" has NO built endpoint.** `/students` (line 41) is a `notImplemented` stub. This
  card needs a **new read** — a teacher's school roster. Good news: it **resolves in existing RLS with no
  elevation** (a teacher links to a SCHOOL, not to individual students — `teacher_links = (teacher_id,
  school_id)` — and `users_read`'s teacher clause admits *their school's* students). So it does **NOT cross
  a wall** like the attendance roster did. But it returns **minor identities to a teacher**, so per the
  S-UX3-4 discipline that one read is reviewed **LINE-BY-LINE** (child data), even though it needs no
  elevation.
- ℹ️ **One guardian read reuses an EXISTING elevation, not a new one.** The per-child consent status
  (`/my/students/{id}/consent-status`, `ConsentSigningService::derivedStatus`) already elevates (S-UX3-3b,
  allowlisted). Reusing it adds **no new elevation**.

Everything else is **pure frontend over built, RLS-shaped, no-elevation reads**. No new money endpoint, no
money mutate. **Net new backend: one teacher read.**

---

## 1. GUARDIAN "My Children" — reads (all resolve in the guardian's own RLS)

The child-centric aggregation. The **child list** is reused from `/api/consent-requests` (each row carries
`student_id` + `student_name` — the S-UX3-4 pattern, no new endpoint). Per child, all built:

| Read | Endpoint | Elevation | Own-child boundary (from source) |
|---|---|---|---|
| Enrolments | `GET /enrolments` (permission:enrolment.view) | **NONE** | `enr_read` = … OR `student_id ∈ app.student_ids` (the guardian's children) — a guardian sees only their children's enrolments |
| Consent status | `GET /my/students/{id}/consent-status` | **existing** (derivedStatus, S-UX3-3b — already allowlisted) | the derived read is guardian→own-child by construction |
| Sessions / attendance | `GET /my/students/{id}/sessions` (S-UX3-4) | **NONE** | child-guard FIRST — a non-linked child → 403 before any read |
| Money summary | `GET /orders` + `/receipts` (see §2) | **NONE** | `familyRead` — children only |

**Boundary confirmed:** every read is scoped to `app.student_ids` (the guardian's active children) or
child-guarded — **a guardian never sees another guardian's child**. No new endpoint; the UI composes these.

---

## 2. GUARDIAN "My Payments" (authenticated) — money reads, RLS family, READ-ONLY

The nav map's note: a guardian pays via the anonymous `/pay/{token}` page but has **no authenticated
payments view**. The built family-money reads (S04B step 1, **OD-67**) supply it — all RLS-shaped, all
read-only:

| Read | Endpoint | RLS (source) |
|---|---|---|
| Orders (obligations) | `GET /orders` | `familyRead = system OR finance/audit OR student_id = actor OR student_id ∈ app.student_ids` → **guardian sees their children's orders** |
| Order lines | `GET /orders/{id}/lines` | `viaOrder = system OR EXISTS(order visible)` → readable when the parent order is |
| Receipts (paid) | `GET /receipts` | `viaOrder` → the child's receipts, when the order is visible |
| Payment-link status | `GET /my/payment-links` | the guardian's OWN minted links (never `token_hash`) |

**Money surface — read-only display, confirmed:**
- **No mutate in the view.** Paying stays the `/pay/{token}` flow. **NO elevation** (all `familyRead`/
  `viaOrder`).
- **Shown:** programme name, amount + currency, order status (issued / paid / covered / refunded / cancelled),
  due date, receipt number + issued-at, payment-link status.
- **Withheld (by the reads' own shape):** `token_hash` (never listed, ruling 6); another family's money
  (RLS); `receipt_sequences` internals (finance/audit only). School-payer orders: a guardian sees ZERO
  school orders (OD-67 — `familyRead` keys on `student_id`, not the school).
- **Optional (existing action, Leo to rule):** surfacing the existing **mint payment-link** button
  (`POST /my/orders/{id}/payment-link`, an already-audited guardian act) so a guardian can generate a
  forwardable link from the view. Not a new money mutate; actual payment still leaves via `/pay`. Recommend
  **read-only v1**, mint-link as a thin optional add.

---

## 3. TEACHER "My Students" — the one gap, and the one new read

**The gap:** `/students` is a `notImplemented` stub — no built teacher roster. **The relationship:**
`teacher_links = (teacher_id, school_id)` (a teacher links to a SCHOOL, unique-active per teacher), so **a
teacher's students = the students on their school's roll** (via `school_links`).

**The read (new):** `GET /my/students` (or `/teacher/students`), `role:teacher` — the students of the
teacher's school(s). **Resolves in existing RLS, NO elevation:** `users_read`'s teacher clause admits
`school_links.student_id WHERE school_id ∈ app.school_ids` (the teacher's school context, set from
`teacher_links`). Unlike the attendance roster, **it does not cross the users wall** — school membership is
exactly what `users_read` grants a teacher.

**Why line-by-line anyway:** it returns a **roster of minors' identities to a teacher**. Child data →
line-by-line, with a **privacy tooth**:
- **Allowlist:** `{student_id, student_name}` (+ optionally `school_id`). That is all `users_read` +
  `school_links` grant a teacher.
- **WITHHELD:** guardian identity, consent detail, payment/obligation, contact/email, another school's
  students. (Note: `enr_read` does **not** admit a teacher, so enrolment detail is out of reach anyway —
  the boundary is enforced by RLS, not just the query.)
- **Boundary:** own school only (`app.school_ids`); **another school's students → absent** (RLS). A teacher
  with no active `teacher_link` → empty roster.

**Scope note:** v1 = the school roster (name + id). A finer "students I personally teach" (via programme/
team/session assignment) is a possible refinement, but the built RLS boundary is school-scope — that is what
ships. Attendance-per-student is already the S-UX3-4 surface.

---

## 4. The child-data / money forks (the review-critical part)

- **Does any read cross an RLS wall?** **No.** Guardian reads are `app.student_ids`-scoped (own children);
  the teacher read is `app.school_ids`-scoped (own school) and `users_read` admits it. Neither crosses a
  wall the way the attendance roster (mentor → cross-school attendees) did. **So no read needs a NEW
  elevation.** (The consent-status read reuses an existing, already-allowlisted elevation.)
- **Money reads:** **read-only display**, no mutate, no elevation (`familyRead`/`viaOrder`). Fields shown/
  withheld per §2; `token_hash` never leaves; school orders invisible to families.
- **Therefore, by the batchability rule** ("every read resolves in existing RLS, no new elevation, no
  money-mutate → the card BATCHES"): **this card BATCHES.** The one caveat is the teacher read returns
  minor identities, so that single read is reviewed **line-by-line within the batched card** (mixed depth,
  the S-UX3-4 STEP-2 shape) rather than forcing a separate gated step.

---

## 5. NAV — reveals + gates

| Item | Path | Visible when | Note |
|---|---|---|---|
| **My Children** (guardian) | `/my/children` | `has('consent.sign')` | consent.sign is guardian-unique (capability_forbidden bars ops; students hold consent.view, not .sign) |
| **My Payments** (guardian) | `/my/payments` | `has('consent.sign')` | guardian-scoped; a guardian's `finance.view` is "their own money", but consent.sign keeps it guardian-only (not school_admin/ops) |
| **My Students** (teacher) | `/my/students` | `has('teams.approve') && !has('operations.manage')` | teacher-unique (school_admin lacks teams.approve; ops excluded). Overlaps the S-UX3-4 mentor "Attendance" gate — both are teacher surfaces |

All shown-not-hidden: server RLS is the real gate; nav visibility only avoids offering a dead door.

---

## 6. Recommended split + BATCHABILITY

**ONE batched card, MIXED DEPTH** (matches S-UX3-4 STEP 2):
- **Line-by-line (child data):** the one new read — teacher `GET /my/students` — with its privacy tooth
  (allowlist `{student_id, student_name}`; own-school-only; another school denied; no guardian/consent/
  money leak). Elevation-free (assert no `asSystem` added; `ScopeElevationTest` unchanged). 0 migrations.
- **Frontend-scan (batched):** the three UIs — guardian **My Children** (compose enrolments + consent
  status + sessions + money summary per child, from the child list), guardian **My Payments** (orders +
  receipts + payment-link status, read-only), teacher **My Students** (the new roster). Nav per §5.
  Trilingual i18n parity. S-UX2a kit (StatusTag reuses `orderStatus`; `bookingStatus`/`sessionStatus` from
  S-UX3-4).

**Alternative (if Leo prefers the conservative shape):** split the teacher read into a gated STEP 1 (like
the attendance roster), then the UIs as STEP 2. There is **no elevation** to gate here, so the mixed-depth
single card is the lighter, rule-consistent choice — but the minor-identity roster is the kind of read Leo
gated first for attendance, so the call is his.

**Screenshots:** ZERO by default — every surface is a read (no new write surface to prove shown-not-hidden;
the money view is read-only, paying stays `/pay`). If Leo surfaces the optional mint-link button, that is an
existing write, still no refusal-on-new-surface to shoot.

---

## 7. Explicitly OUT of scope
- **Any money mutate** — paying stays `/pay/{token}`; recording/confirming stays the finance team.
- **Enrolment/consent detail in the teacher roster** — `enr_read` doesn't admit a teacher; v1 is the school
  roster (name + id).
- **"Students I personally teach" (programme/team-scoped)** — v1 is school-scope (what RLS grants);
  a finer scope is a later refinement.
- **Student "My Payments"** — students can hold own-payer orders (RLS admits self), but this card is
  guardian/teacher self-service; a student money view is a later add.

---

### One-line recommendation

Build S-UX3-9 as **one batched, mixed-depth card**: the single new read (teacher `GET /my/students`, school-
RLS, no elevation) reviewed **line-by-line** with a child-data privacy tooth; the three self-service UIs
(guardian My Children, guardian My Payments read-only, teacher My Students) **frontend-scan, batched, zero
screenshots**. Everything else rides built RLS reads — no new money endpoint, no money mutate, no new
elevation.
