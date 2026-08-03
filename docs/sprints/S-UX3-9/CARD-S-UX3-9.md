# CARD — S-UX3-9 · Guardian / teacher self-service (My Children · My Payments · My Students)

Approved from `PROPOSED-SELF-SERVICE.md` with Leo's rulings (2026-08-04). **ONE batched card, MIXED DEPTH.**
Last card of the existing-user run. Commit HELD.

## Depth
- **LINE-BY-LINE:** the one new read `GET /my/students` (teacher — minor identities, elevation-free) **and**
  the money-read allowlist / `token_hash` withholding (the child/money privacy teeth).
- **FRONTEND-SCAN (batched):** the three UIs over built/existing-RLS reads.

## Backend (LINE-BY-LINE) — the one new read
`GET /teacher/students` (`role:teacher`): the teacher's school roll. **NOT `/my/students`** — that path is
the RETIRED guardian-create endpoint (OD-27) which must keep 404ing (`OnboardingTest`); a route there would
turn its `POST` from 404 into 405. Caught by the full suite; renamed. (Browser route stays `/my/students`.) **ELEVATION-FREE** — `school_links_read`
admits `role IN ('school_admin','teacher') AND school_id ∈ app.school_ids`, and `users_read`'s teacher
clause admits those students' names. Same shape as `SchoolAdminController::students`, under the teacher's RLS.
- **Exact allowlist `{student_id, student_name}` ONLY.** Withheld: guardian identity, consent, enrolment
  detail, money, other schools.
- **Boundary:** own school only; another school's students **absent** (RLS); a teacher with no active
  `teacher_link` → empty roster.
- **Assert NO `asSystem` added** — if it needs one, STOP and flag (the no-wall-cross premise would be wrong).

## Frontend (FRONTEND-SCAN, batched) — `web/src/pages/SelfService.tsx`
- **Guardian My Children** (`/my/children`): child list (reuse `/api/consent-requests`, dedup), per child
  their enrolments (`/api/enrolments`, `enr_read` own-child), a **consent chip reusing the existing
  `derivedStatus`** (`/my/students/{id}/consent-status?programme_id=`, S-UX3-3b — NOT a new elevation), and a
  link to sessions (`/family/sessions`, S-UX3-4).
- **Guardian My Payments** (`/my/payments`): OD-67 family-money reads (`/orders` + `/orders/{id}/lines` +
  `/receipts` + `/my/payment-links`), **READ-ONLY**. Shown: programme / amount / status / due / receipt /
  link-status. **WITHHELD:** `token_hash` (never to client), other families' money, school-payer orders.
  Plus the **mint-payment-link button** (existing `POST /my/orders/{id}/payment-link`) — **"get the payment
  link"** framing, NEVER "pay"; returns `{url}` (the forwardable `/pay` page). Refusal rendered
  shown-not-hidden (422 "Order is {status} — nothing to pay" on a non-issued order).
- **Teacher My Students** (`/my/students`): the new roster.
- **Nav:** My Children / My Payments → `consent.sign` (guardian-unique); My Students →
  `teams.approve && !operations.manage` (teacher, matches the S-UX3-4 mentor gate).
- Trilingual i18n parity. S-UX2a kit (StatusTag reuses `orderStatus`; `bookingStatus`/`sessionStatus`).

## Mandatory tests
1. **Teacher /my/students:** allowlist `{student_id, student_name}`; own-school roll returned; **another
   school's students DENIED/absent** (cross-school child-privacy); no guardian/consent/enrolment/money leak;
   **NO new elevation** (asSystem count unchanged; controller not in scope-elevations).
2. **Guardian money reads:** own-children's orders only; another family's orders absent; **`token_hash` NEVER
   in any response**; school-payer orders excluded.
3. **Mint:** refusal on a non-issued order (422); a mint on an issued order returns a link and **does not move
   money** (order stays `issued`, no receipt).
4. **Battery unchanged (58); migrations 0** (all reads ride existing tables).

## Exit gate
New battery + `ScopeElevationTest` green · `reconcile:run` 58/58 · full suite green · `migrate --pretend`
0 new · `tsc`/`build`/i18n parity. VERIFY output + diff → `~/Downloads`. **ONE risk shot** (mint refusal);
the three reads screenshot-free. **Commit HELD.** Closes S-UX3-9 + the existing-user run; AUDIT follows.
