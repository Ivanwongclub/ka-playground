# PROPOSED SPRINT KAP-S04B — Money machinery: orders, receipts, providers, refunds (no trigger)

> DRAFT (2026-07-27) — rewrite to the boundary table: S04B builds every piece of payment
> machinery and exposes the trigger as a PORT; the trigger itself fires at 成團 in S05 (OD-43).
> Not live until Leo approves.

## GOAL
Everything money needs, none of it self-starting: immutable full-fee orders with gapless
receipts, a PaymentProvider interface with a MockProvider driving the full flow, manual
recording under BI-9, the forwardable payment link, full-only refunds and the school-settled
receivable — all proven against a fixture trigger, waiting for S05 to pull it.

## PRECONDITIONS
- [ ] S04A gate PASSED
- [ ] **CLIENT ANSWER: are fees/terms ever school-specific?** (moved here with the orders) —
  gates the consumer fee-read clause in STEP 1; **STOP there if still open**.

## IMPLEMENTS  BI-2 · BI-5 · BI-9 (as narrowed by OD-47) · OD-18 · OD-25 · OD-26 (money side) ·
OD-42* (submitter n/a — see S05) · OD-43 (port only) · OD-44 · OD-45 (machinery) · OD-46 ·
OD-48 · OD-50b · OD-53 · OD-54 · 2.17 · 2.19/2.20 (reshaped by full-fee) · 2.29

## SCOPE CLASSIFICATION PLAN (read sets pre-stated)
| Table | Classification | Read set / justification |
|---|---|---|
| `orders` / `order_lines` | **scoped** | Read: system · finance/audit · the payer guardian · the student (read-only, Q1). Lines INSERT-only at the DB (BI-5, conditional revoke per S03 pattern); full fee snapshot, OD-18 minor units + `currency CHAR(3)` |
| `receipts` / `receipt_sequences` | **scoped** | Read: payer guardian (own) · student read-only · finance/audit · system. Number assigned INSIDE the issuing transaction from the counter row under `FOR UPDATE` (BI-2/BI-3); never pre-reserved |
| `payments` | **scoped** | One table, two origins: `manual` (school-settled/offline — BI-9 recorder ≠ confirmer, both `finance`, OD-47) and `provider` (mock/gateway — confirms itself, out of BI-9 scope). Read: finance/audit · payer guardian (own) · system. 1..n evidence images on manual (OD-5) |
| `refunds` / `credit_notes` | **scoped** | 2.17 machine, FULL amounts only (OD-48); BI-9 on the manual side; destination = original payer party (OD-25: paid BY the academy). School-settled precedence: credit note always; refund-to-school if invoice already paid (OD-54, nightly balance assertion) |
| `consolidated_invoices` | **scoped** | OD-53 receivable: issued at 成團 (S05/S04E wire it; machinery + "covered by invoice" status ≠ Paid here), net-30 default, aging feeds OD-55 batch exception later. Read: finance/audit · the addressed school's admins · system |
| `payment_links` (OD-44) | **scoped — WITH AN ANONYMOUS READ SURFACE** | The platform's second anonymous surface (after S04C's write): a forwardable tokenized page showing order reference, amount, programme name, student as INITIALS ONLY. No RLS read policy for anonymous — the token endpoint resolves server-side (system context) by single-use token hash; constant-shape 404 for unknown/expired/paid tokens; expires at the payment deadline, dead once paid. Design reviewed like the S04C anonymous write (confinement-style assertion: no child-identifying field can appear in the link payload) |

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Orders + lines + receipts (BI-2/BI-5, OD-18/48) + fee-read clause.** Full-fee snapshot,
   payer_party (OD-25), gapless in-transaction numbering; consumer fee-read clause per the
   CLIENT ANSWER — **STOP if unanswered**. VERIFY: 50-parallel receipt probe gapless (paste);
   line UPDATE refused at DB (paste); OD-18 schema paste.
2. **`PaymentTriggerPort` + deadline machinery (OD-43 port, OD-45).** Port: given a Confirmed
   enrolment → issue order, fire `payment.requested`, start the deadline clock (default 7d);
   grace → suspend → exception jobs (SYSTEM actor, OD-64). Proven with a FIXTURE trigger — 成團
   arrives in S05. VERIFY: fixture-triggered full flow paste; deadline-lapse job paste with
   SYSTEM actor; nothing in S04B can fire the port from a user request (paste the absence).
3. **PaymentProvider + MockProvider (OD-46) + payment link (OD-44).** Interface (create session,
   confirm, refund, reconcile); MockProvider drives success/fail/timeout; provider payments
   self-confirm (OD-47 — out of BI-9); link page: initials-only, expiring, single-use, dead
   once paid. VERIFY: mock end-to-end paste; link shows NO child-identifying data (paste of
   payload + the assertion); expired/paid link → constant-shape 404 (paste).
4. **Manual recording under BI-9 (OD-47 scope).** Recorder ≠ confirmer, both `finance`,
   server-side; evidence images 1..n via the upload pipeline (BI-10); late/wrong-amount
   exceptions reshaped by full-fee-only (2.19; 2.20's partial paths stay dead per OD-5/48).
   VERIFY: self-confirm refusal paste (BI-9); provider payment needing no confirmer (paste —
   the OD-47 boundary shown from both sides).
5. **Refunds + credit notes + receivable (2.17, OD-48/53/54).** Full-only; school-settled credit
   note precedence with the balance assertion; withdrawal-money wiring to S04A's approved
   requests. VERIFY: partial refund refused (paste); credit-note-then-refund-to-school flow
   paste; invoice balance assertion green + deliberate red.
6. **Financial Integrity Report (audit element) + assertions.**

## NON-SCOPE
成團 and the real trigger (S05) · batch/Excel (S04E) · QFPay adapter (S-QFPAY; interface only
here) · teams · enrolment states (S04A's, read-only here).

## KEY VERIFICATIONS
Five-branch per scoped table (payer guardian · non-payer co-guardian — read outcome stated at
card review per OD-6 · student read-only · school_admin zero except own consolidated invoices ·
Member zero · anonymous: link-token page only, nothing else) · `--tag` regression green each step.

## ASSERTIONS (--tag=S04B)
- `receipts.gapless` (BI-2 probe) · `order_lines.immutable` (BI-5 probe)
- `payments.bi9_manual_sod` — no manual payment where recorder = confirmer; every provider
  payment confirmer-free (OD-47, both directions)
- `refunds.full_only` — no refund or credit note ≠ its order total (OD-48)
- `invoices.balance` — consolidated invoice balance = original − credits (OD-54)
- `payment_links.no_pii` — no link payload row contains more than initials (OD-44)
- `deadlines.no_silent_lapse` — every lapsed payment deadline has its SYSTEM-actor audit event
  and a suspension or exception (OD-45/64)

## EXIT GATE
Tests + `--tag=S04B` + prior tags green + fixture-trigger flow + BI-9 both-sides pastes +
five-branch pastes + AUDIT.md (fee-terms outcome recorded), gate commit.
