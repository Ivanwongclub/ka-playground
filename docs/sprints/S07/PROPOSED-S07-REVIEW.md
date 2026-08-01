# PROPOSED S07 REVIEW — think-first (no code)

**Author:** Claude Code · **Date:** 2026-08-01 · **For:** Leo's review BEFORE S07 STEP 1
**Reconciles:** `docs/sprints/S07/SPRINT.md` (a card that predates most of the build) against what
shipped in S04B (money model) and S05 (成團 pipeline), plus the OD-25 hand-off inherited from S04E.
Nothing built.

---

## 0. TL;DR — the card and the hand-off are TWO different domains

The S07 card is **team-PROJECT finance**: team budgets, transactions with evidence, a verification
workflow, charity/sponsorship records, P&L, and an *approval-engine consolidation*. That domain is
**almost entirely greenfield** (no budget/transaction/approval tables exist).

The **OD-25 hand-off** you're inheriting is **school-payer ENROLMENT finance**: aggregating a school's
成團 orders into a `(school, programme)` consolidated invoice. That is the **S04B money model's**
domain (orders/obligations/invoices/receivables), *not* team-project finance. OD-25/OD-54/OD-55 were
originally gated **"S04B · S06-batch (→ S04E)"**, and the S04E hand-off parked it at "S07 (team
finance)" only because S07 was the next finance-labelled card. **These are unrelated money flows** —
schools paying the academy for enrolments vs. teams spending on their own projects.

**Recommendation (D-15): split them.** OD-25 consolidated invoicing is a small, focused
enrolment-finance piece (E6-payer wire + invoice aggregation + one assertion) that should be its own
step/card in the S04B money domain — buildable NOW that 成團 orders exist — and **decoupled** from the
large, greenfield, higher-risk S07 team-project-finance + approval-consolidation card. Bundling them
would tie a quick receivable fix to a multi-week refactor.

**OD-4 (the STOP precondition) is RESOLVED** (2026-07-23 — charity is a valid `project_type`, funds
never distributed to members, with an S07 assertion). The card's "STOP if OD-4 open" is **clear**.

---

## 1. Scope reconciliation — card vs shipped (your Q1)

| Card claims | Shipped? | Reconciliation |
|-------------|----------|----------------|
| Money the platform never touches (record-only) | The **enrolment** money model shipped (S04B): `orders`/`order_lines` (immutable, BI-5), `receipts`/`receipt_sequences` (gapless, BI-2), `OrderService::issueForEnrolment`, `refunds`/`credit_notes` (BI-9 SoD, OD-54), `payment_obligations` + `PaymentObligationConsumer`. 8 finance assertions live. | S07 does **not** rebuild any of this. But note: this is *enrolment* finance, not *team-project* finance — the card conflates the two. |
| **Team budgets + approval · transactions w/ receipt upload · verification workflow** | **Greenfield.** No `team_budgets`, `team_transactions`, verification, or P&L table/service exists. | This is the substantive new build. Reuses the S00 upload service (BI-10) for evidence. |
| Sponsorship/charity records (`project_type`, OD-4) · P&L w/ evidence drill-down | **Unbuilt** — no `project_type` field/table anywhere. OD-4 is a *resolved decision* with no code. | New. The OD-4 assertion ("no distribution against a charity project") is new. |
| **Approval-engine consolidation** — one engine for formation/gates/budgets/transactions/deliverables/refunds; refactor S04B/S05 onto it | Today there are **6 separate, domain-local** approval mechanisms (refund BI-9, manual-payment BI-9, 成團 approval, below-min resolution, stage gates, withdrawal approval), each with its own authority check + status column. **No generic engine.** | **The single riskiest item in the card.** See D-16 — refactoring money-critical SoD (BI-9) onto a new shared engine risks the invariants; recommend descoping or a careful adapter-only approach. |

**Drifts the card assumes differently from what S04B/S05 built:**
- The card treats "team finance" as one thing; the build has **enrolment finance** (S04B, done) and
  **team-project finance** (unbuilt). The OD-25 hand-off belongs to the former, the card body to the latter.
- The card's "refund approval now runs on the consolidated engine" assumes a refactor of a **BI-9 /
  DB-enforced SoD** path (`rf_update` WITH CHECK). That's not a UI rewire — it's touching a money invariant.
- "P&L reports" assume transactions exist; they don't yet — greenfield.

---

## 2. The OD-25 hand-off — consolidated invoicing (your Q2)

`consolidated_invoices` **exists but is dormant**: `(id, school_id, programme_id,
original_amount_minor, balance_minor, currency, status∈{issued,paid})`, no `batch_id`. **Nothing
creates or aggregates one** — the only writer is `WithdrawalSettlementService` updating `balance_minor`;
everything else reads it (FIR, `invoices.balance` assertion). The `orders.covered_by_invoice` status is
**defined but never written**. So issuance is genuinely unbuilt.

### (a) The E6-payer wire — the load-bearing gap
Both obligation-creation sites hardcode `payer_party='guardian'`:
`TeamConfirmationService.php:122` and `TeamResolutionService.php:78`. **Nothing reads the programme's
E6 `payer_party`** (`programmes.payer_party ∈ {parent, student, school}`). Consequence: a
`payer_party='school'` programme still mints a *guardian* obligation → a family-paid order → **the
entire school-settled/invoice branch is unreachable from the live pipeline today.**

The wire: at obligation creation, read `programmes.payer_party` and map it —
- `parent → 'guardian'` (**note the enum mismatch**: programme says `parent`, orders/obligations say
  `guardian`; the map must bridge it, and the mismatch should be commented/asserted so it never drifts),
- `student → 'student'`,
- `school → 'school'` + set `payer_school_id` from the student's active `school_links` roll.
This is small but touches two S05 services — a deliberate, flagged touch (like D-10's STEP-1 touch).

### (b) How the invoice aggregates 成團 orders per (school, programme)
A new issuance service (system-context): for a school-payer order (`payer_party='school'`), **find or
create** the `(school_id, programme_id)` invoice, attach the order (`orders.consolidated_invoice_id =
invoice.id`, `orders.status='covered_by_invoice'`, no `payment_due_at`), and set
`original_amount_minor = Σ (total_amount_minor of the invoice's covered orders)`,
`balance_minor = original − Σ credit_notes`. Because 成團 is async/per-team, orders trickle in — so the
service is an **upsert that recomputes from the covered set**, never a blind increment (idempotent, §4).
Trigger: run it in `PaymentObligationConsumer` right after a school-payer order is issued (the natural
seam — the order already exists there), or a batched sweep. Recommend inline at consume time.

### (c) The `invoices.line_reconciliation` predicate
Distinct from the existing `invoices.balance` (which checks `balance = original − Σ credit_notes`):
> **`invoices.line_reconciliation`** — for every consolidated invoice,
> `original_amount_minor == Σ (orders.total_amount_minor WHERE consolidated_invoice_id = invoice.id
> AND status = 'covered_by_invoice')`, same currency (HKD), integer minor units.
i.e. the invoice's original equals the sum of the orders it covers — no invoiced amount without a
matching covered order, and (with `invoices.balance`) the balance chain stays honest.

---

## 3. The school-settled receivable model (OD-54) — invoicing builds ON it (your Q3)

OD-54 shipped as a *maintenance* model without an *issuance* model: `WithdrawalSettlementService`
already keeps `balance_minor = original − Σ credit_notes` and, for a school payer, **always** writes a
credit note (refund-to-school if the invoice is already `paid`). The `invoices.balance` assertion
guards it. OD-25 issuance must **plug into** this, not contradict it:
- An invoice is a **receivable**: `status='issued'` until the school actually pays (then `paid`); a
  covered order is `covered_by_invoice`, **not** `paid`, and carries **no receipt** until real payment
  (BI-2 receipts only on money received). ✔ consistent with OD-54.
- OD-55 (the *other* inherited school-settled item): a school invoice carries a due date + reminder
  ladder; past due+grace → **one academy exception** (extend terms or withdraw the cohort), students
  participate throughout. This is a second hand-off in the same family — **is this the "second named
  hand-off" you meant?** (see D-17). It's aging/exception, downstream of issuance.

**No contradiction** — OD-25 adds *issuance*; OD-54 already has *maintenance*; OD-55 adds *aging*.

---

## 4. Money-integrity invariants for OD-25 invoicing (your Q4)

| Invariant | How it holds |
|-----------|--------------|
| **Invoice reconciles to its orders** — no invoiced amount without a matching covered order | `invoices.line_reconciliation` (§2c); the invoice original is *computed from* the covered order set, never entered free-hand. |
| **Idempotency** — re-generating doesn't double-count | The issuance service **recomputes** `original_amount_minor` from the covered-order set each run; an order already `covered_by_invoice` with a `consolidated_invoice_id` is not re-attached. One invoice per `(school, programme)` (find-or-create). One live order per enrolment (existing unique index) means an enrolment can't spawn two covered orders. |
| **Immutability** — invoices/lines immutable once issued, like orders/receipts | `order_lines` are already INSERT-only (BI-5, DB trigger) — the invoice's amount traces to immutable lines. `consolidated_invoices.status` is `{issued,paid}` only; `balance_minor` is the *sole* mutable field and moves **only** via credit notes (OD-54, audited). Recommend: the invoice's `original_amount_minor` is **write-once at issuance** (only grows as covered orders attach, never edited down) — consider a guard/assertion that original is monotonic and equals Σ covered orders. Corrections are credit notes, never edits (BI-5 discipline extended to invoices). |
| **BI-2 receipts** | untouched — a covered_by_invoice order gets a receipt only when the *school* pays the invoice (real money), never at coverage. |

---

## 5. Step boundaries + drift register (your Q5)

### Proposed plan — SPLIT the two domains (D-15)

**Track A — OD-25 consolidated invoicing (small, buildable now, S04B money domain).** Recommend its
own card/step, e.g. **S04F** or an S04B addendum — NOT inside the S07 team-project card:
- **A1 — E6-payer wire** (§2a): obligations read programme E6, map parent→guardian, school→school +
  payer_school_id. VERIFY: a `school` programme mints a school obligation → a school-payer order
  (`covered_by_invoice`, no due date); a `parent` programme is unchanged. Two S05 services touched.
- **A2 — invoice issuance/aggregation** (§2b): find-or-create `(school,programme)` invoice, attach
  covered orders, recompute original. `invoices.line_reconciliation` assertion (§2c). VERIFY:
  N school-payer orders → one invoice, original = Σ orders; idempotent re-run; five-branch (another
  school's admin sees only its invoice). Builds on OD-54 (§3).
- **A3 (optional, OD-55)** — invoice aging → single academy exception + reminder ladder. VERIFY: past
  due+grace → one exception, students unaffected.

**Track B — S07 team-project finance (greenfield, the card's actual body).** Its own reconciled card:
- **B1 — team budgets + transactions with evidence** (S00 upload/BI-10) + verification workflow
  (evidence-first: no Verified entry without evidence, server-side).
- **B2 — charity/sponsorship `project_type` (OD-4)** + P&L with evidence drill-down + the OD-4
  assertion (no distribution against a charity project).
- **B3 — approval-engine consolidation** — **the risk item (D-16).** Recommend: do NOT rip-and-replace
  the 6 money-critical mechanisms. Either (i) descope to team-finance approvals only (the new B1/B2
  paths use one engine; leave BI-9 refund/payment SoD and 成團 gates as-is), or (ii) an adapter that
  the existing paths delegate to without changing their DB-enforced SoD. Refactoring `rf_update`'s
  WITH CHECK or `payments.bi9_manual_sod` onto a new engine risks a money invariant for a
  maintainability gain — not worth it pre-UAT.

### Drift register
| # | Drift | Card said | Reality / proposal | Decision |
|---|-------|-----------|--------------------|----------|
| D-15 | Domain conflation | S07 = "team finance" (one thing) | Two unrelated flows: OD-25 school-payer enrolment invoicing (S04B domain, buildable now) vs team-project finance (greenfield). Split. | **Leo** |
| D-16 | Approval-engine consolidation | "one engine … refactor S04B/S05 onto it" | 6 money-critical mechanisms incl DB-enforced BI-9. Rip-and-replace risks invariants. Descope or adapter-only. | **Leo** |
| D-17 | Second hand-off | "two named hand-offs" | I can identify **one** cleanly (OD-25 invoicing). Candidates for the 2nd: OD-55 invoice aging, or the E6-payer wire as a distinct item. Which did you mean? | **Leo** |
| D-18 | E6 enum mismatch | (unnoted) | programme `payer_party='parent'` vs order/obligation `'guardian'`. The wire must map + the mismatch be asserted so it can't drift. | Falls out of A1 |
| D-19 | `covered_by_invoice` unwritten | (assumed working) | Status defined, never written; school branch unreachable until A1. | Falls out of A1 |
| D-20 | OD-4 STOP | "STOP if OD-4 open" | OD-4 is **RESOLVED** — precondition clear; charity is unbuilt code, not an open decision. | Confirmed clear |

---

## 6. Decisions I need before STEP 1
1. **D-15:** split OD-25 invoicing (Track A, its own card/step in the S04B money domain, buildable
   now) from the S07 team-project-finance card (Track B)? Recommended. If yes, which do we do first —
   **A (OD-25, small, closes the S04E hand-off) or B (the big greenfield card)?** I lean **A first**.
2. **D-16:** the approval-engine consolidation — descope to team-finance approvals only / adapter-only
   / or full refactor? I lean **descope** (don't touch BI-9-enforced SoD pre-UAT).
3. **D-17:** what is the **second** named hand-off you're inheriting into S07? (OD-55 aging? the E6
   wire? something else?)
4. Confirm **OD-4 is clear** (it is — RESOLVED) so the STOP precondition is satisfied.

On your rulings I'll reconcile the relevant `SPRINT.md`(s), commit the plan, show you the reconciled
card + STEP 1 plan, then build. Nothing built or committed yet.
