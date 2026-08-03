# KAP — Tracked Scope Gaps (from the navigation map)

> Gaps the target-state navigation map (`KAP-NAVIGATION-MAP.md`) surfaced, recorded so they are not
> lost. Each is **built-vs-missing** in one line plus a proposed sprint home. These are **not defects** —
> they are engine-ahead-of-UI or conscious-decision items. Sourced from `web/src/nav.tsx`,
> `api/routes/api.php`, `config/permission-matrix.php`.

| # | Gap | Built | Missing | Proposed home |
|---|-----|-------|---------|---------------|
| **A** | **Member role has zero nav** | The entire S06 member surface — `/events`, `/events/{id}/rsvp`, `/my/rsvps`, `/directory`, `PUT /my/profile` — is built and tested (34 automated tests in the "Learning Delivery" pillar). | No nav item, no screen, and **no S-UX card** for any of it. A `member` who logs in sees only Dashboard. | **Needs a new card** (e.g. `S-UX3-8 Member surfaces`): events list + RSVP + directory + profile, gated `events.view` / `member_directory.view`. Highest-severity gap — a whole role is dark. |
| **B** | **`/team` gate excludes real authorities** | The 成團 view + roles/tenure read are built; server authority (OD-39) admits **lobby `school_admin`** and gate-approval admits **`teacher` (`teams.approve`)**. | The nav item is gated `operations.manage` only, so school_admin and teacher — who can act server-side — see **no team nav**. | **Conscious gate-widening card**, not an accident: fold into **S-UX3-6 (School Portal, lobby 成團 for school_admin)** and **S-UX4 (teacher account + team/gate view)**. Decide the widened predicate deliberately. |
| **C** | **Guardian/teacher self-service reads are API-only** | `GET /my/payment-links` + `POST /my/orders/{id}/payment-link` (guardian pay), `GET /my/students/{id}/consent-status` (guardian children), `student_records.view` (teacher's students) — all built endpoints. | No authenticated nav: guardians pay only via the anonymous `/pay/{token}` page; no "My Payments", no consolidated "My Children"; teachers have no "My Students" screen. | **Needs cards** — a guardian **"My Children / My Payments"** card, and a teacher **"My Students"** card (likely under **S-UX4** with the teacher account). |
| **D** | **Uncarded route stubs** | Route placeholders exist (`/tracker`, `/profile`) per D-UX1.1; member Events/Directory built (S06). | No sprint number owns: **Activity Tracker** (Plan/Design/Learn/Pitch/Launch UI — engine hooks exist via stage-gate approval), **Profile** (all roles), **Member Events/Directory** (see A). | **Needs card numbers**: Activity Tracker → a new **S-UX3-x** (Tracker); Profile → a small cross-role card; Member Events/Directory → the same card as (A). |

## Notes
- (A) and (D)'s member items are the same underlying gap — one **Member surfaces** card resolves both.
- (B) is the only *policy* item (a gate decision), not missing UI per se — the screen exists, the gate
  is deliberately narrow for the ops-first STEP 1–2 and should be widened by a card, not silently.
- (C) is pure engine-ahead-of-UI: no new backend, just nav + screens over built endpoints.
- Cross-cutting provisioning gap (from the map §6-A): **`teacher` and `school_admin` have no seeded demo
  account** — fold into Leo's fresh re-seed under **S-UX4**, alongside the cards above.
- **Deferred design/visual polish (DELIBERATE, not a miss):** the UX phase is functional-first; the admin
  surfaces are below visual standard by design (dense trilingual forms, flat 11-section wizard, no
  guided flow). A coherent design pass is deferred to after the functional surfaces substantially land
  (post-Marketplace-B + Member surfaces) and gets its own think-first — the observations + design
  questions are captured in **[S-UX-POLISH](../sprints/S-UX-POLISH/PROPOSED-UX-POLISH.md)** (living record).
