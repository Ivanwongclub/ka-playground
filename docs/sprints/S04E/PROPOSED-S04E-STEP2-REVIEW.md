# PROPOSED S04E STEP 2 REVIEW — think-first (no code)

**Author:** Claude Code · **Date:** 2026-08-01 · **For:** Leo's ruling BEFORE building STEP 2
**Why this exists:** a machinery reconnaissance (before writing a line) found that the card's STEP 2
and the framing "accounts + enrolments + orders from the dry-run dispositions" **conflict with the
SHIPPED enrolment model** (OD-31/OD-34 intent, guardian-consent governance, orders-at-成團). Building
STEP 2 on the card's model would be building on a superseded design. Per CLAUDE.md STOP rule
("the sprint card conflicts with an amendment"), I'm stopping to reconcile. Nothing built.

---

## 0. TL;DR — three drifts, one of them a hard blocker

The card's STEP 2 was written against an older enrolment model (seats, waitlist, orders-at-enrolment).
What actually shipped:

- **Enrolment is pure INTENT (OD-31).** No seat, no `SELECT … FOR UPDATE`, no capacity, no waitlist
  at enrolment. `EnrolmentService::create()` just inserts `status='submitted'`. Capacity/成團 and the
  "awaiting-a-team" pool happen at **S05 team confirmation**, not here. → the card's STEP 2 VERIFY
  ("batch spanning capacity boundary — some Enrolled, overflow Waiting") **cannot be produced** — that
  state does not exist at enrolment time.
- **Orders do not exist at enrolment.** `OrderService::issueForEnrolment` requires status
  `confirmed|active` and is driven by the payment-obligation outbox at **S05 confirmation**. And OD-25
  school-payer is **unwired** — both obligation call sites hardcode `payer_party='guardian'`. → "orders
  from the dry-run dispositions" **cannot be produced in STEP 2**; there is nothing to invoice yet.
- **Enrolment is guardian-consent-gated (the blocker).** `create(programmeId, studentId,
  actingGuardian)` *requires an active `guardian_links` row* for (actingGuardian, student) and
  auto-issues consent to that student's guardians. **A school-roll bulk student has no guardian** —
  every `new`-disposition row (just minted, roll link only) and any guardian-less `existing` row
  **cannot be enrolled** through the real machinery. This is not a wiring gap; it is the platform's
  consent governance (OD-10): a child is enrolled only via a consenting guardian.

Plus two schema gaps STEP 1 left (by design — they belong to the commit): the batch records **no
programme target** and **no payer**.

---

## 1. What STEP 2 *can* honestly be, on the shipped model

The only reusable creation primitive is `EnrolmentService::create()` (it self-dispatches consent; no
orders/capacity run). So a faithful STEP 2 is:

- **Accounts** — `new`-disposition rows → the proven `BulkStudentCreationService::create()` (mint
  born-unverified + roll link, audited). Solid, already built. ✔
- **Enrolment INTENT** — for rows that **have an active guardian**, call `EnrolmentService::create()`
  under a system elevation (school admin is not the guardian; RLS insert is `system OR guardian`).
  Enrolment enters `submitted` → auto-issues consent → `pending_consent`. No seats, no orders.
- **Guardian-less rows** — recorded with a **defined outcome**: created/matched but
  `not_enrolled: awaiting guardian & consent`. Never silently dropped (P4). They become enrollable once
  the S04D guardian path links + a guardian consents — exactly the existing single flow.
- **NO orders, NO capacity, NO waitlist, NO invoice** in STEP 2 — those are downstream (S05), so the
  card's STEP 3 "consolidated invoicing" must also be reconciled (batch-time orders don't exist to
  aggregate; the invoice can only form after the batch's enrolments reach 成團).
- **Idempotent re-commit** — natural via the DB partial-unique `(student_id, programme_id)` index;
  `create()` returns the original. To *report* re-commit cleanly and be resumable, add a per-row
  `enrolment_id` + a `committed` row status (small schema add).

This keeps STEP 2 truthful to OD-31/OD-10 and reuses `create()` + `EnrolmentService::create()` without
re-implementing anything.

---

## 2. Decisions I need from you (STOP — I will not guess)

**D-8 — guardian-less rows (the blocker).** How does STEP 2 treat rows with no active guardian
(all `new`, plus guardian-less `existing`)?
- **(A, recommended)** Enrol only rows that already have an active guardian; guardian-less rows get
  the defined outcome `not_enrolled: awaiting guardian & consent`. Respects OD-10; honest; small.
- **(B)** The CSV also names each child's guardian, and STEP 2 creates the guardian link too — but
  linkage terminates in **pending-approval** (OD-27) and needs an admin decision, so this re-opens
  S04D's linkage governance inside the batch. Much larger; arguably out of Part H.
- **(C)** STEP 2 = **accounts only**; enrolment stays the per-student guardian flow entirely.

**D-9 — orders/capacity/invoice drift.** Confirm STEP 2 creates **enrolment intent only** — no seats,
no waitlist, no orders (they don't exist pre-成團; OD-31) — and that the card's STEP 2 capacity-boundary
VERIFY and STEP 3 consolidated-invoicing are **re-scoped** (invoicing can only aggregate orders that
exist after the batch's enrolments confirm at S05; OD-25 school-payer is a separate unwired item).

**D-10 — programme target + payer capture.** Where do they come from?
- **(recommended)** Add `programme_id` (and, if OD-25 is in scope, `payer_party`/`payer_school_id`) to
  `enrolment_batches`, supplied at the **STEP 1 upload** (amend the upload endpoint) — so the dry-run
  report can already show "enrolling into <programme>". Alternative: a **commit-time** param on a new
  STEP 2 endpoint. Either is small; I need the choice.

---

## 3. Recommendation
Take **D-8 (A)** + **D-9 (intent-only, re-scope invoicing)** + **D-10 (programme on the batch, captured
at upload)**. That yields a STEP 2 that is real, reuses `create()` and `EnrolmentService::create()`,
honours OD-31/OD-10, and produces an honest per-row report (enrolled / not-enrolled-awaiting-guardian /
skipped / failed). It also means the card's Part H "consolidated invoicing" (STEP 3) needs its own
reconciliation, because there are no batch-time orders to invoice — I'll flag that when we get there.

On your rulings I'll update `SPRINT.md` (STEP 2 + the STEP 3 knock-on), commit it as the plan
(`KAP-S04E-plan-2`), then build STEP 2 and hold for review. Nothing is built or committed yet.
