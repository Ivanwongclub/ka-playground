# PROPOSED S07 (Track B) REVIEW — think-first (no code)

**Author:** Claude Code · **Date:** 2026-08-01 · **For:** Leo's review BEFORE S07 STEP 1
**Scope:** team-PROJECT finance (record-only) — the S07 card minus the OD-25 invoicing already split
to S04F (D-15) and minus the approval-engine consolidation (descoped, D-16). This is **greenfield** —
a new domain, not a reconciliation of existing machinery — so the substance here is the DOMAIN DESIGN.
Nothing built. **OD-4 (the STOP precondition) is RESOLVED** — the precondition is clear.

---

## 0. TL;DR
- **Greenfield, but fully spec-defined:** FR061 + Spec §A3/§M3/§N5/§P1 give the tables, the two state
  machines, the approval chain, and the assertions. I'm not inventing the domain — I'm building what
  the spec already specifies.
- **Ledger separation is a SPEC MANDATE, not a choice:** §A3 — *"programme fees … the Order module …
  The two never mix"*; GR006 — *"Team funds never enter the academy's receivables; programme fees
  never appear in a team's P&L."* Team-project money is a **wholly separate record-only ledger** from
  S04B/S04F enrolment money. (The D-15 lesson, now spec-backed.)
- **Record-only:** the platform never holds or moves team funds (§A3). It records offline reality,
  routes for approval, verifies against evidence, and reports. No PaymentProvider, no receipts, no
  academy money.
- **The new SoD replicates the BI-9 pattern, re-homes nothing** (D-16): a team transaction's verifier
  ≠ its submitter, DB-enforced — on NEW tables/policies, leaving the payments/refunds controls alone.
- **4 steps:** budgets → transactions+verification → sponsorship/charity → P&L + report + assertions.

---

## 1. Scope reconciliation (your Q1)

| Card claims | Reality | Reconciliation |
|-------------|---------|----------------|
| Team budgets + approval · transactions w/ receipt upload · verification workflow | **Greenfield** — no budget/transaction/verification/P&L table or code exists (grep-confirmed). | This is the build. Spec §N5 names the tables; §P1 the state machines. |
| Sponsorship/charity records (`project_type`) | **Greenfield** — `project_type` has **zero occurrences** in `api/`. | New. Reframes the **Pitch** stage (spec:1296) to cover commercial sponsorship + philanthropic charity. |
| P&L reports w/ evidence drill-down | Greenfield. | New — the "Team Finance Verification Report" (§N-report). |
| **Approval-engine consolidation** (formation/gates/budgets/transactions/deliverables/refunds on one engine) | **DESCOPED (D-16).** | S07's team-finance approval is its OWN new mechanism; the 6 existing DB-enforced money controls are **left alone**. |

**The boundary — team-project money is NOT the enrolment receivable model.** §A3 is explicit and §M3
draws the wall: team funds and programme fees *never mix*. So S07 does **not** touch
`orders`/`receipts`/`refunds`/`consolidated_invoices` (S04B/S04F) — a team recording it spent HK$300
on poster printing is unrelated to a family/school paying an enrolment fee. Conflating them would
repeat the exact D-15 error; the spec forbids it (GR006).

---

## 2. The domain design (greenfield — the substance) (your Q2)

**The TEAM is the project** — there is no separate "project" entity. Money attaches to the `teams` row
(spec §N4); two Tracker stages have financial gate conditions: **Plan** (budget approved) and **Pitch**
(fundraising target). Amounts are **integer minor units + currency (HKD, OD-18)** like all money here.

### 2a. Tables (spec §N5 — greenfield)
- **`team_budgets`** — one budget per team (per cycle). `team_id`, `status`, `currency`,
  `total_planned_minor` (derived), `submitted_by`, `approved_by`, timestamps.
  Optional `project_type` lives on the team's fundraising records (§2c), not the budget.
- **`budget_categories`** — the category taxonomy for a budget's lines (e.g. Materials, Marketing,
  Travel). Per §N5; likely per-programme config or a small fixed set — **decision D-B1**.
- **`budget_lines`** — `budget_id`, `category`, `name`, `planned_amount_minor`. Immutable once the
  budget is Active (corrections = a new revision, BI-5 pattern).
- **`team_transactions`** — `team_id`, `budget_line_id` (nullable for income), `type`
  (`income | expense`), `amount_minor`, `currency`, `description`, `occurred_on` (the offline date),
  `status`, `recorded_by`, `verified_by`, `evidence_upload_id`. Immutable once Recorded (BI-5).
- **`transaction_receipts`** — the evidence link (or reuse `team_transactions.evidence_upload_id`
  directly). Every receipt rides `UploadService::intake` (BI-10, scanned) — see §2e.
- **`sponsorship_records` / `sponsorship_agreements`** — §2c.

All **scoped** (RLS): a team's finance is readable by its members + linked teacher + lobby school admin
+ academy ops/audit; writable by system (the services) under the actor's authority. New tables →
classified in `scope-map.php`; `scope.public_context_confinement` stays green.

### 2b. State machines (spec §P1 — canonical, must be implemented as-is)
- **Budget:** `Draft → Submitted → Under Review → Approved | Changes Requested → Active → Closed`.
  (Changes Requested → back to Draft.) The **Plan-stage gate reads "budget Active/Approved" LIVE**
  (spec:241 — never a cached flag).
- **Transaction:** `Draft → Receipt Attached → Submitted → Under Review → Approved | Rejected →
  Recorded → Verified`. **Evidence is attached BEFORE Submitted** (Receipt Attached is an early
  state), so a transaction cannot reach Verified without evidence — the KEY VERIFICATION
  *"Verified without evidence → impossible (server-side)"*. **Verified is a distinct terminal step
  past Approved** — "verified against offline reality."

Every transition writes an audit event (§A4) — no undefined state.

### 2c. The approval / verification chain (spec:1491) — the NEW SoD
`student (submitter) → Finance Manager (team role) → teacher (team_teacher_links; else the lobby's
school admin)`.
- **Finance Manager** is a **team role** held via `tenures` → `role_library` (the approver-resolution
  substrate S05 already built). **Dependency D-B2:** confirm a "Finance Manager" role exists in
  `role_library` (or seed it).
- **The new segregation-of-duty (D-16 — replicate BI-9's pattern, re-home nothing):** a transaction's
  **`verified_by` ≠ its `recorded_by`/`submitted_by`**, enforced **two-layer** exactly like manual
  payments — a DB RLS `WITH CHECK (verified_by <> recorded_by)` on `team_transactions` UPDATE + an
  app-service 403 + a nightly assertion. **Distinct from BI-9:** BI-9's SoD is the *academy `finance`
  capability* (two academy finance accounts); this is *team-scoped* (submitter ≠ verifier within the
  team's own approval chain). New tables, new policies, nothing re-homed.

### 2d. project_type (Pitch reframe, spec:1296)
`project_type ∈ {sponsorship | charity}` on the team's fundraising/finance records. Reframes Pitch to
cover commercial sponsorship AND philanthropic charity, rather than a separate Charity module.

### 2e. Evidence (BI-10)
Receipts route through `UploadService::intake` — the **`evidence` context already exists**
(`pdf/jpg/png`, 15 MB; the same context manual-payment evidence uses) — reuse it, or add
`team-transaction-evidence` following the same shape. **Decision D-B3.** Either way it's scanned;
a transaction's evidence is invisible until clean (BI-10).

---

## 3. Money-integrity for the new domain (your Q3)

| Invariant | Design | Assertion |
|-----------|--------|-----------|
| **Budget actual == Σ approved transactions** (spec:1776) | P&L computes actual from approved/verified transactions; never a cached counter (§A4). | `finance.budget_actuals_match` |
| **Every Verified entry has evidence** (spec:43) | State machine: Receipt Attached precedes Submitted; Verified unreachable without a clean evidence upload. | `finance.verified_has_evidence` |
| **Verification ≠ recording (new SoD)** | `verified_by <> recorded_by`, DB `WITH CHECK` + app 403 (§2c). | `finance.verification_sod` |
| **Transactions immutable once Recorded** (BI-5 pattern) | INSERT-only past Recorded; corrections are new transactions (a reversal/adjustment), never edits — like order_lines. | (probe assertion, BI-5 style) |
| **Charity: no distribution to members** (OD-4) | a `charity` project may not record a distribution (an expense whose beneficiary is a team member). Needs a beneficiary/payee concept to detect — **decision D-B4**. | `finance.charity_no_distribution` |
| **Two ledgers never mix** (GR006) | team-finance tables carry NO order/receipt/invoice reference; the Order module carries no team-transaction reference — structural. | `finance.ledger_separation` (optional structural check) |
| **Can't overspend the budget?** | **Decision D-B5:** record-only means the platform records offline reality — a real overspend HAPPENED. Propose: approval of an over-category transaction is **flagged** (an over-budget exception + report row), NOT hard-blocked (you can't refuse to record what already happened). A hard block would force under-recording — worse. | over-budget flag in the report |

---

## 4. Interaction with the existing model (your Q4)

**Wholly separate ledger — spec-mandated (§A3 / GR006 / §M3), confirmed.** Team-project finance:
- does **NOT** touch `orders` / `order_lines` / `receipts` / `refunds` / `consolidated_invoices` /
  `payment_obligations` (the S04B/S04F Order module);
- creates no receipt (no real money is received — the academy never holds team funds);
- carries no PaymentProvider / QFPay path (that's the family-paid gateway, Phase 2).

The **only** touchpoints are: (a) the `teams` row it hangs off, (b) the `tracker` **gates** that read
budget/fundraising status **live** (Plan needs budget approved; Pitch needs the funding target) — the
gate reads the finance module, never a cached flag (spec:241). No money crosses the wall.

---

## 5. Charity / sponsorship — the shape (your Q5)

**BOTH a classification AND external-money-in:**
- **Money-in:** sponsorship/charity funds are recorded as an **income** `team_transaction`
  (`type=income`) plus a **`sponsorship_records`** row and an uploaded **`sponsorship_agreement`**
  (evidence, BI-10). The platform records that money came in from an external sponsor/donor — it never
  holds it (§A3).
- **Classification:** `project_type ∈ {sponsorship | charity}` constrains what transactions are legal.
- **The OD-4 control:** a `charity` project may record income and legitimate project expenses, but
  **never a distribution to a team member** (charity funds are not the members' to take). This is the
  new `finance.charity_no_distribution` assertion — its teeth depend on D-B4 (how a "distribution" is
  identified: an expense flagged/typed as a member payout, needing a beneficiary field).

---

## 6. Step plan + drift register (your Q6)

### Proposed 4-step plan
- **STEP 1 — Budgets.** `team_budgets` + `budget_lines` (+ categories, D-B1); the budget state machine
  (Draft→…→Active→Closed); submit + teacher-approval; the **Plan-stage gate reads budget Active live**.
  VERIFY: state machine transitions; changes-requested loop; Plan gate green only when budget Active;
  five-branch; immutability of lines once Active.
- **STEP 2 — Transactions + verification.** `team_transactions` (+ receipts/evidence via BI-10); the
  transaction state machine; **evidence-before-Submitted** (Verified impossible without evidence); the
  **new SoD** (`verified_by <> recorded_by`, DB + app + assertion); immutability once Recorded.
  VERIFY: verified-without-evidence refused (server-side, raw); recorder≠verifier refused (raw 403 +
  DB); immutability; `finance.verified_has_evidence` + `finance.verification_sod` teeth.
- **STEP 3 — Sponsorship / charity (Pitch).** `sponsorship_records` + `sponsorship_agreements`;
  `project_type`; income transactions; the **Pitch-stage gate** (funding target/sponsor count);
  **OD-4** `finance.charity_no_distribution`. VERIFY: charity distribution refused (raw); Pitch gate
  live; project_type classification.
- **STEP 4 — P&L + Team Finance Verification Report + assertions.** budget vs actual vs verified per
  team; unverified-entry aging; approval chain per transaction; P&L export with evidence drill-down;
  **`finance.budget_actuals_match`** + the ledger-separation check. VERIFY: actuals == Σ approved;
  drill-down reaches the scanned evidence; five-branch.

### Drift register
| # | Drift | Card said | Reality / proposal | Decision |
|---|-------|-----------|--------------------|----------|
| D-B0 | Domain boundary | S07 = "team finance" (one thing) | Team-PROJECT finance is a separate record-only ledger from enrolment money (§A3/GR006). Never touches the Order module. | Confirmed (spec-mandated) |
| D-B1 | Budget categories | (unspecified) | Fixed small set, per-programme config, or free-text? Recommend a **fixed seeded set** (Materials/Marketing/Travel/Other) for Phase 1. | **Leo** |
| D-B2 | Finance Manager role | approval chain names it | Confirm/seed a "Finance Manager" role in `role_library` (the tenure the chain resolves). | **Leo / dependency** |
| D-B3 | Evidence context | (unspecified) | Reuse the existing `evidence` context, or add `team-transaction-evidence`. Recommend **reuse `evidence`** (already pdf/jpg/png 15MB, scanned). | **Leo** |
| D-B4 | "Distribution" detection (OD-4) | "no distribution against charity" | Needs a way to identify a member payout — an expense `beneficiary` field or a `distribution` transaction sub-type. Recommend a `beneficiary_member_id` nullable on expense transactions; charity + non-null → refused. | **Leo** |
| D-B5 | Overspend | (unspecified) | Record-only → **flag, don't hard-block** (record reality, raise an over-budget exception). A hard block forces under-recording. | **Leo** |
| D-B6 | Approval-engine consolidation | "one engine, refactor S04B/S05" | **DESCOPED (D-16).** S07 builds its own team-finance approval; existing money controls untouched. | Confirmed |
| D-B7 | P&L semantics | "P&L reports" | For a record-only team: P&L = Σ verified income − Σ verified expense, vs budget planned (budget/actual/verified three-way). No cash position (platform holds nothing). | Confirmed (spec §N-report) |

---

## 7. Decisions I need before STEP 1
1. **D-B1** budget categories: fixed seeded set (recommended) / per-programme config / free-text?
2. **D-B2** confirm the "Finance Manager" `role_library` role exists or should be seeded.
3. **D-B3** evidence context: reuse `evidence` (recommended) or a new `team-transaction-evidence`?
4. **D-B4** how a charity "distribution" is identified (recommend a `beneficiary_member_id` on expense
   transactions; charity + beneficiary set → refused) — the shape of the OD-4 control.
5. **D-B5** overspend = flag-and-report (recommended, record-only) or hard-block at approval?
6. Confirm the **4-step boundary** (budgets → transactions+verification → sponsorship/charity → P&L),
   and that STEP 1 is **budgets**.

On your rulings I'll reconcile `docs/sprints/S07/SPRINT.md` (Track B), commit the plan, show you the
reconciled card + STEP 1 plan, then build. Nothing built or committed yet.
