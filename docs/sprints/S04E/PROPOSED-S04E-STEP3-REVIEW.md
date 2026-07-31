# PROPOSED S04E STEP 3 REVIEW — think-first (no code)

**Author:** Claude Code · **Date:** 2026-08-01 · **For:** Leo's ruling BEFORE building STEP 3
**Reconciles:** the card's STEP 3 ("dashboard H4 + consolidated invoicing OD-25") against the D-9
ruling (no batch-time orders) and the SHIPPED finance schema. Nothing built.

---

## 0. TL;DR
- **The dashboard (H4) is fully buildable now** from the shipped batch/row/enrolment tables. That is
  the substance of STEP 3.
- **The consolidated invoice (OD-25) cannot live in S04E** — and, on inspection, **needs almost no
  forward-compat from S04E to be buildable later without retrofit.** The invoice is keyed on
  **(school, programme)**, not on a batch; the one missing wire is *payer determination at 成團*,
  which lives in the finance/team code, not here. **Recommendation: OD-25 consolidated invoicing lands
  in the team-finance sprint (S07), not S04E.**
- **FR066: wire it** (your lean is right) — an `onboarding_exceptions` row for a failed batch, same
  ledger as every other tracked exception. Small.
- **STEP 3 is the LAST S04E step:** dashboard (H4) + FR066 + `batches.no_stuck` + the AUDIT batch
  ledger. The invoice is a named S07 hand-off, not a loose end.

---

## 1. The dashboard (H4) — buildable now (your Q1)

Everything it needs already exists: `enrolment_batches` (status, counts, `programme_id`, `school_id`,
`committed_at`), `enrolment_batch_rows` (per-row `status`/`disposition`/`reason`/`enrolment_id`/
`committed`), and `enrolments` (the downstream status of each enrolled row).

**"Where is my batch" — the school admin's view:**
- **List** — the school's batches with status (`ready | committing | complete | partially_complete |
  failed`), programme, counts, age. (New list endpoint; `show` already returns one batch's detail.)
- **Drill-down** — per-batch counts (enrolled / not_enrolled / skipped / failed) + per-row outcome &
  reason. Enrich each **enrolled** row with its live enrolment status (`submitted → pending_consent →
  in_pool …`) so the admin sees how far each child has progressed.
- **The `not_enrolled` list is the actionable core** — every guardian-less row
  (`not_enrolled: awaiting guardian & consent`, `committed=false`) is exactly the **join-back to
  S04D**: it names the children who need a guardian linked + consenting. Once S04D links a guardian,
  a **re-commit enrolls them** (STEP 2 already does this — `committed=false` rows are re-evaluated).
  So the dashboard's headline is "N enrolled, M awaiting a guardian — here they are."
- **Five-branch** — RLS already scopes both tables to the owning school's admins; another school's
  admin sees zero (proven for `show` in STEP 1). The list endpoint inherits it.

**AUDIT element (Financial Integrity Report, part 1b) — the batch-ledger half builds now:** batches by
school/status/age; per-row outcome distribution; upload/scan disposition (already in the STEP 1
report block). The **consolidated-invoice register** half of that element defers with the invoice (§2).

**No drift in the dashboard itself** — it reads only shipped columns. ✔

---

## 2. The consolidated invoice (OD-25) — the deferral, analysed (your Q2)

**Key schema fact that resolves the whole question:** `consolidated_invoices` is keyed on
**`(school_id, programme_id)`** — `id, school_id, programme_id, original_amount_minor, balance_minor,
currency, status`. **There is no `batch_id`.** An invoice is "what this school owes for this
programme," aggregating **every school-payer order for that (school, programme)** — whether the
enrolments came from a bulk batch or one at a time. So the mental model "a *batch's* invoice" is not
how the money model works; it is a *school+programme* invoice.

### (a) How is the batch→orders link preserved? — it isn't a batch link, and doesn't need to be
Orders are born at 成團 (S05): `TeamConfirmationService` / `TeamResolutionService` create a
`payment_obligation` (`enrolment_id, programme_id, student_id, payer_party, payer_school_id,
order_id`), the outbox consumer turns it into an `order`. **Both obligation call sites currently
hardcode `payer_party='guardian'`** — the OD-25 school-payer path is unwired.

The thread that makes an order belong on a school's consolidated invoice is therefore **the payer
designation on the obligation**, not a FK back to a batch. The authoritative source for that is the
**programme's E6 `payer_party`** (`parent|student|school`, already stored on `programmes`, just not
consulted). A "school-paid programme" → every obligation for it carries `payer=school` + the
`school_id` → its orders are school-payer → the `(school, programme)` invoice aggregates them.
Bulk-origin is irrelevant to the money; the `(school, programme)` key already captures it.

*(If a per-cohort payer override were ever needed, the trace exists —
`enrolment_batch_rows.enrolment_id → enrolment → obligation` — but it is not needed for
`(school, programme)` invoicing, and I do not recommend building it.)*

### (b) Where does the invoice actually get built? → the team-finance sprint (S07)
It belongs where its inputs are born: **成團 orders (S05) + a finance sprint that (i) wires obligation
payer determination from programme E6, and (ii) builds the `(school, programme)` consolidated-invoice
aggregation** over school-payer orders. That is **S07 (team finance)**, not S04E. Building it in S04E
would mean inventing orders that don't exist yet.

### (c) What must STEP 3 do NOW to avoid a later retrofit? → essentially nothing structural
The forward-compat is **already present**:
- `consolidated_invoices` exists, `(school, programme)`-keyed, RLS-scoped (system/finance/audit/owning
  school admin). ✔
- `payment_obligations` already carries `payer_party` + `payer_school_id`. ✔
- The batch already records `payer_party`/`payer_school_id` (STEP 2 forward-compat columns) — a
  *recorded intent* for the cohort, though **not** the authoritative source (programme E6 is).

So the deferred invoice is buildable later **without retrofitting S04E**. The single missing wire —
consult the payer designation instead of hardcoding `'guardian'` at obligation creation — is an
**S07 finance change**, not an S04E one. The minimal thing STEP 3 *may* optionally do: populate the
batch's `payer_party` from the programme's E6 value at upload, so the dashboard can show "school-paid
cohort." Cheap, display-only, not load-bearing — flagged as an option, not a requirement.

**Recommendation:** OD-25 consolidated invoicing → **S07**. S04E STEP 3 builds none of it; it is a
named hand-off with the seam already in place.

---

## 3. FR066 — wire the exception row (your Q3)

`onboarding_exceptions` fits a batch failure directly: `subject_type='enrolment_batch'`,
`subject_id=<batch.id>`, `age_days`, `reason=<failure_reason>`, `status='open'`, RLS system-write /
ops+audit read. **Recommendation (matches your lean): wire it.** STEP 2's `failBatch()` and STEP 1's
scan/structural failure paths additionally insert an `onboarding_exceptions` row, so a dead batch sits
in the same trackable ledger as every other exception (and the existing `queue.escalation_liveness`
sweep already watches that table). The `batch.failed` audit event stays too — the audit is the event,
the exception is the actionable open item. Small, consistent, in-scope for STEP 3.

**Drift note:** this touches STEP 1's and STEP 2's failure paths (additively) — a deliberate
consequence of the ruling, like the D-10 STEP-1 touch. No behaviour changes; a row is added on failure.

---

## 4. Step boundary — STEP 3 is the last S04E step (your Q4)

**STEP 3 scope (honest):**
- **Dashboard (H4)** — batch list + enriched drill-down + the `not_enrolled` actionable join-back.
- **FR066** — `onboarding_exceptions` row on batch failure (§3).
- **`batches.no_stuck` assertion** — no batch stuck in `scanning|validating|committing` past its
  job-timeout window (liveness; the card's third assertion).
- **AUDIT element** — the batch-ledger half of Financial Integrity Report 1b (invoice register defers).
- **AUDIT.md + gate** — record the S07 hand-off for OD-25 invoicing and the S10 real-clamd item.

**Out of STEP 3 / S04E (named hand-offs, not loose ends):**
- **OD-25 consolidated invoicing → S07** (team finance), gated on 成團 orders + payer-determination
  wiring. `invoices.line_reconciliation` assertion moves there.
- **xlsx intake → fast-follow** (D-5/D-6, unchanged).
- **real-clamd `batch-csv` end-to-end → S10** (D-7, unchanged).

So **S04E = STEP 1 (intake) + STEP 2 (commit) + STEP 3 (dashboard + FR066)**, then the gate. The
"bulk enrolment" cohort deliverable is complete at STEP 3; the *money* for school-paid cohorts is an
honest S07 continuation, because the money doesn't exist until 成團.

---

## 5. Assertions
- **`batches.no_stuck`** — **[STEP 3]** liveness on the transient states.
- `batches.scan_gated` (STEP 1) + `batches.row_conservation` (STEP 2) — already shipped.
- ~~`invoices.line_reconciliation`~~ — **→ S07** with the invoice it guards.

---

## 6. Drift register
| # | Drift | Card said | Reality / proposal | Decision |
|---|-------|-----------|--------------------|----------|
| D-11 | Consolidated invoicing in S04E | STEP 3 builds it | No batch-time orders (D-9); invoice is (school,programme)-keyed, built at S07 where 成團 orders exist. Seam already present — no S04E retrofit. | **Leo** |
| D-12 | AUDIT element invoice register | "…+ consolidated invoice register with order-line reconciliation" | Batch-ledger half builds now; invoice-register half defers to S07. | Falls out of D-11 |
| D-13 | FR066 exception | (open item) | Wire an `onboarding_exceptions` row on batch failure (§3). Touches STEP 1/2 failure paths additively. | **Leo (lean: wire)** |
| D-14 | Batch payer columns vs programme E6 | (STEP 2 added batch payer cols) | Programme E6 `payer_party` is the authoritative payer source for OD-25; batch columns are recorded intent (display-only). Optionally populate from E6 at upload. | **Leo (optional)** |

---

## 7. Decisions I need before STEP 3
1. **D-11:** confirm OD-25 consolidated invoicing lands in **S07**, not S04E (STEP 3 builds the seam
   is already in place — nothing to add). Recommended.
2. **D-13:** confirm **wire the FR066 `onboarding_exceptions` row** on batch failure (your lean).
3. **D-14:** optionally populate `enrolment_batches.payer_party` from the programme's E6 at upload
   (display-only), or leave the batch payer columns dormant until S07. Recommend: leave dormant
   (the programme is the source of truth) unless you want the dashboard to label school-paid cohorts.
4. Confirm **STEP 3 is the last S04E step**, then the S04E gate.

On your rulings I'll update `SPRINT.md` (STEP 3 + the S07 hand-off), commit it as the plan
(`KAP-S04E-plan-3`), then build STEP 3 and hold for review. Nothing built or committed yet.
