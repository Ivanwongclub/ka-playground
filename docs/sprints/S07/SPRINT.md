# SPRINT KAP-S07 — Team-project finance (record-only)

> **RECONCILED 2026-08-01** (twice): first the D-15/D-16 split, then the Track-B domain design per
> `docs/sprints/S07/PROPOSED-S07-TRACKB-REVIEW.md` and Leo's rulings.
> **D-15** — the OD-25 school-payer *enrolment* invoicing that the S04E hand-off parked here was a
> DIFFERENT domain (the S04B money model); it was **split to its own card `S04F`** (built + gated,
> `a457776`). This card is **team-PROJECT finance only** — a team spending its project budget with
> evidence, verified against offline reality.
> **D-16** — the "one approval engine" consolidation is **DESCOPED**: re-homing the 6 DB-enforced money
> approvals (incl BI-9 SoD, a fraud control) onto a new engine risks the invariants. S07's team-finance
> verification is its OWN new mechanism (the BI-9 *pattern* on NEW tables); the existing S04B/S05 money
> controls are **left alone**.
> **OD-4** RESOLVED — the STOP precondition is clear (charity is a valid `project_type`; charity funds
> never distributed to members; the no-distribution assertion is S07's).
> **Track-B rulings:** **D-B1** budget categories = a FIXED SEEDED SET · **D-B2** "Finance Manager" is a
> team role via the S05 tenure mechanism (one active holder, audited) · **D-B3** REUSE the existing
> `evidence` upload context (BI-10) · **D-B4** charity "distribution" = an expense with a
> `beneficiary_member_id`; charity + beneficiary → REFUSED (path-independent assertion) · **D-B5**
> overspend is FLAGGED-and-reported, never hard-blocked (record-only records reality; over-budget
> approval requires the verifier to ACKNOWLEDGE) · **D-B7** P&L = Σ verified income − Σ verified expense
> vs budget planned (no cash position — the platform holds nothing).

## GOAL
A team records its project money — budgets, income and spending — that **the platform never touches**
(§A3: record-only; money moves offline). Every spend carries scanned evidence; every entry is
verified against offline reality by someone other than who recorded it; the team's P&L is portfolio
evidence. **This ledger is WHOLLY SEPARATE from enrolment money** (§A3/GR006 — programme fees and team
funds never mix; S07 never touches the Order module).

## PRECONDITIONS
- [ ] S06 gate PASSED (done) · **OD-4 RESOLVED** (done — STOP clear) · S05 tenure/role mechanism live
  (the approval-chain substrate) · the `evidence` upload context live (S00/BI-10)

## IMPLEMENTS  FR061 (team finance record-only) · FR057 (project_type sponsorship/charity) · Spec §A3 · §M3 · §N5 · §P1 · OD-4 · OD-18 (minor units/HKD) · GR006 (two-ledgers-never-mix)

## BUILDS ON / DOES NOT TOUCH
- **Attaches to:** the `teams` row (the team IS the project — no separate project entity); the S05
  `tenures`/`role_library` approval substrate; the Tracker **Plan** (budget) and **Pitch**
  (fundraising) gates, which read the finance module LIVE (spec:241); `UploadService::intake` +
  the `evidence` context (BI-10, D-B3).
- **NEVER touches (GR006 / §A3):** `orders` · `order_lines` · `receipts` · `refunds` ·
  `consolidated_invoices` · `payment_obligations` · any PaymentProvider/QFPay. No academy money, no
  receipts (the platform holds nothing). The 6 existing DB-enforced money SoD controls are LEFT ALONE (D-16).

## SCOPE CLASSIFICATION PLAN
New **scoped** (RLS) tables — read: the team's members + its linked teacher + lobby school admin +
academy ops/audit; write: system (the services, under the actor's authority):
`team_budgets` · `budget_lines` · `team_transactions` · `sponsorship_records` · `sponsorship_agreements`.
One **seeded reference** table readable by every authenticated session (like `role_library`):
`budget_categories` (fixed set — D-B1; no personal/commercial data). New tables classified in
`scope-map.php`; `scope.public_context_confinement` stays green (no public policy).

## SCOPE (steps in this order; each = VERIFY + commit + stop)

1. **Budgets (Plan stage).** `team_budgets` + `budget_lines` + the seeded `budget_categories`
   (Materials / Marketing / Travel / Other — D-B1, trilingual, aggregatable). Budget state machine
   (spec §P1): `Draft → Submitted → Under Review → Approved | Changes Requested → Active → Closed`
   (Changes Requested → Draft). Draft edits by the team (Finance Manager/leader); **teacher approves**
   (spec:54, via the team's teacher link, lobby-school-admin fallback — the S05 gateApproverKind
   resolution, read-only). **Budget lines immutable once Active** (BI-5 — corrections are a new
   revision, never edits). The **Plan-stage gate reads budget status = Active LIVE** (spec:241 — never
   a cached flag). Seed **"Finance Manager"** in `role_library` (D-B2). Assertion
   `finance.budget_approved_provenance` — every Active budget carries an approving audit (no active
   budget without an approval). VERIFY: full state machine incl the changes-requested loop; teacher-only
   approval (five-branch on the approver); lines immutable once Active; Plan gate green ONLY when budget
   Active; assertion red→green.

2. **Transactions + verification (the SoD core).** `team_transactions` (`type` income|expense,
   `amount_minor`/`currency` HKD, `budget_line_id`, `beneficiary_member_id` nullable, `occurred_on`,
   `recorded_by`, `verified_by`, `evidence_upload_id`). Transaction state machine (spec §P1):
   `Draft → Receipt Attached → Submitted → Under Review → Approved | Rejected → Recorded → Verified`.
   **Evidence-before-Submitted enforced by state ORDERING** — a transaction cannot pass Submitted
   without a clean `evidence` upload (BI-10), so **Verified-without-evidence is structurally
   impossible**. **NEW SoD (D-16 — BI-9 pattern, re-home nothing):** `verified_by <> recorded_by`,
   enforced TWO-layer — a DB RLS `WITH CHECK` on the verify UPDATE + an app-service 403 + a
   reconciliation assertion — on NEW tables/policies (distinct from BI-9's academy-finance SoD; this is
   team-scoped). **Immutable once Recorded** (BI-5). **Over-budget = FLAG, not block (D-B5):** approval
   of a transaction that pushes a line over its planned amount is allowed but requires the verifier to
   ACKNOWLEDGE (an `over_budget_acknowledged` flag) and raises an over-budget report row — never
   under-recorded. Assertions `finance.verified_has_evidence` + `finance.verification_sod`. VERIFY:
   verified-without-evidence refused server-side (raw); recorder=verifier refused (raw 403 + DB check);
   immutability once Recorded; over-budget flagged not blocked (paste); both assertions red→green.

3. **Sponsorship / charity (Pitch stage) — OD-4.** `sponsorship_records` + `sponsorship_agreements`
   (uploaded evidence, BI-10); `project_type ∈ {sponsorship | charity}` on the team's fundraising
   records (Pitch reframe, spec:1296). Sponsorship/charity funds recorded as **income** transactions
   (§2c). **OD-4 control:** a `charity` project may record income + legitimate expenses but **NEVER a
   distribution to a member** — an expense with a `beneficiary_member_id` against a charity project is
   REFUSED (server-side) and the assertion `finance.charity_no_distribution` scans for any such row
   path-independently (D-B4). The **Pitch-stage gate** reads the funding target / sponsor count LIVE.
   VERIFY: charity distribution refused (raw); a sponsorship expense to a member on a NON-charity
   project is allowed; income recording + agreement evidence; Pitch gate live; assertion red→green.

4. **P&L + Team Finance Verification Report + assertions.** P&L per team = Σ verified income − Σ
   verified expense, three-way vs budget planned (budget / actual / verified — D-B7); no cash position.
   The audit element **Team Finance Verification Report**: budget vs actual vs verified per team ·
   unverified-entry aging · approval chain per transaction · P&L export with **drill-down to the
   scanned evidence file**. Assertion `finance.budget_actuals_match` — every budget's actual = Σ its
   approved transactions (spec:1776); the optional structural `finance.ledger_separation` (team-finance
   rows carry no order/receipt reference and vice-versa, GR006). VERIFY: actuals == Σ approved (paste);
   P&L drill-down reaches the evidence file; five-branch (another team/school sees zero); assertions green.

## NON-SCOPE
Approval-engine consolidation (DESCOPED, D-16 — existing money controls untouched) · any real money
movement / AP module (Phase 2) · QFPay (the family-paid gateway, Phase 2) · the Order module
(orders/invoices/receipts — GR006, never touched) · deliverables/portfolio assembly (S08).

## KEY VERIFICATIONS
Verified-without-evidence **structurally impossible** (state ordering, server-side) · **new SoD**:
recorder ≠ verifier, DB-enforced (BI-9 pattern, NEW tables) · **ledger separation**: no team-finance
row references the Order module and vice-versa (§A3/GR006) · **charity no-distribution** path-independent
(OD-4) · overspend FLAGGED not blocked (record-only) · budget actuals == Σ approved · P&L drill-down
reaches the scanned evidence · `scope.public_context_confinement` stays green · all prior tags green each step.

## AUDIT ELEMENT (Team Finance Verification Report)
Budget vs actual vs verified per team · unverified-entry aging · approval chain per transaction · P&L
export with full drill-down to the uploaded (scanned) evidence · over-budget flags · charity
project_type + no-distribution posture.

## ASSERTIONS (--tag=S07)
- `finance.budget_approved_provenance` — **[STEP 1]** every Active budget carries an approving audit.
- `finance.verified_has_evidence` — **[STEP 2]** every Verified transaction has a clean evidence upload.
- `finance.verification_sod` — **[STEP 2]** no transaction has `verified_by = recorded_by` (new SoD).
- `finance.charity_no_distribution` — **[STEP 3]** no charity project has an expense with a member
  beneficiary (OD-4), path-independent.
- `finance.budget_actuals_match` — **[STEP 4]** every budget actual = Σ its approved transactions.
- `finance.ledger_separation` (optional) — **[STEP 4]** team-finance and the Order module never cross-reference (GR006).

## EXIT GATE
Tests + `--tag=S07` + all prior tags green + STEP pastes (verified-without-evidence refused · recorder=verifier
refused · charity distribution refused · actuals==Σ approved · P&L evidence drill-down) + five-branch +
AUDIT.md, gate commit.
