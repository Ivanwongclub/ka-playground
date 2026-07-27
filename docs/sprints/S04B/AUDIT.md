# AUDIT KAP-S04B — Money machinery: orders, receipts, providers, refunds

**Result:** PASS (gate reviewed and approved by Leo 2026-07-27) ·
**Date:** 2026-07-27 · **HEAD at gate:** last content commit `46e33db`

> The trigger (成團) lives in S05; S04B built the machinery with the trigger out of scope, proven
> against fixture seams (payment_obligations outbox, fixture consolidated invoices) — the same
> discipline as S04A's EnrolmentStatusPort. Eyes-on review per step; each step's VERIFY was
> pasted to Leo before its commit.

## 1. What shipped
Orders + immutable lines + gapless receipts (BI-2/5, OD-18/67) · the PaymentTriggerPort as a
transactional OUTBOX (Q1) · the forwardable payment link (OD-44) with the anonymous read/pay
surface · manual recording under BI-9 (OD-47) with evidence via the existing BI-10 pipeline ·
refunds + credit notes + OD-54 school-settled precedence (2.17, OD-25/48/54) · the Financial
Integrity Report + the 8-assertion battery · grant-role hardening.

## 2. Step-by-step (commit · headline · VERIFY pasted to Leo at each)
```
S04B-1  887a71a  orders/lines/receipts — uniform fee snapshot (OD-67), gapless receipts under
                 FOR UPDATE (BI-2/3), order_lines INSERT-only (BI-5), OD-18 minor units + HKD CHECK
S04B-2  10f9485  PaymentTriggerPort as transactional outbox (Q1) — obligation atomic with the
                 claim, system consumer issues after commit idempotently, kill-consumer test
S04B-3  b14b496  payment link (OD-44) — hash-only token, frozen initials, multi-view/single-act,
                 CAS paid-link race guard, anonymous paths as audited elevations
S04B-3b d048009  CJK initials show the GIVEN name, family name hidden (陳詠恩 → 詠恩) — Leo fix
S04B-4  8c577ca  manual recording under BI-9 — app 403+audit AND DB WITH CHECK (recorder≠confirmer),
                 OD-47 both-sides as a schema CHECK, evidence via existing BI-10 pipeline
S04B-5  9b348a0  refunds + credit notes + OD-54 — settle only on approved withdrawal (idempotent),
                 full-only (OD-48), destination=original payer (OD-25), BI-9 payout both layers (2.17)
S04B-h  58d6e25  grant-role hardening — reconciliation_log revoke + append-only triggers on
                 payment_evidence & withdrawal_endorsements
S04B-6  46e33db  Financial Integrity Report (live-from-source, academy-gated) + 4 assertions;
                 deadlines.no_silent_lapse deferred to S05 (ruling b)
```

## 3. The `--tag=S04B` battery — 8, re-run fresh at the gate
```
$ php artisan reconcile:run --tag=S04B
  PASS  payment_obligations.completeness [OD-43 · Q1] every settled Confirmed enrolment has an
        obligation and its order; no obligation sits unconsumed past the issuance window
  PASS  payment_links.no_pii         [OD-44] no link row carries more than initials; no name/
        email/plaintext-token columns exist
  PASS  payment_links.single_reader  [OD-44] the token path is the ONLY unauthenticated reader —
        by route table, by pg_policies, by hash-only storage
  PASS  invoices.balance             [OD-54] every invoice balance = original − Σ credit notes
  PASS  receipts.gapless             [BI-2] gapless per sequence: max = count, no dupes, counter = max+1
  PASS  order_lines.immutable        [BI-5] order_lines rejects UPDATE and DELETE at the DB
  PASS  payments.bi9_manual_sod      [BI-9 · OD-47] no manual payment with recorder = confirmer;
        no provider payment with a human recorder/confirmer (both directions)
  PASS  refunds.full_only            [OD-48] every refund and credit note = its order total exactly
RECONCILE PASS — 8 assertion(s), 8 passed, 0 failed
```
**8 by design.** The card lists a 9th — `deadlines.no_silent_lapse` (OD-45/64) — DEFERRED TO S05
(Leo ruling, 2026-07-27). Its resolution machinery is the non-payment cascade (OD-39/45: grace →
member suspended → academy exception), which is team-membership work that does not exist until S05.
STEP 2 shipped the deadline CLOCK only (`orders.payment_due_at`); a partial suspend-detector in
S04B would have been a permanently-red assertion with no resolution path — the R15 anti-pattern.
Added to the S05 card's battery, tied to the machinery that resolves it. **The 9th lives where its
resolution machinery is.**

## 4. Elevation-list review — 12 sanctioned asSystem sites (each audits scope.elevated)
| # | Call site | Sprint | One-line justification |
|---|---|---|---|
| 1 | LinkRevocationService::revoke | S01 | Sole-guardian count must see ALL active links while RLS hides co-guardians; read-only count, hidden rows never exposed |
| 2 | LinkController::requestByEmail | S01 | B4 pre-link lookup by exact email — target is outside scope until the link exists; response identical whether or not the account exists |
| 3 | LinkController::schoolVouch | S01 | B4 guardian lookup for a student already verified in the acting school — guardian outside school scope until the link is created |
| 4 | GuardianStudentService::createStudent | S01 | Child account is outside the guardian's scope until the link this op creates exists (INSERT..RETURNING checks SELECT); retired at S04C (OD-27) |
| 5 | AuthService::login | S01 | Credential-verified token issuance is an auth-bootstrap act regardless of ambient session |
| 6 | InvitationService::accept | S01 | Invitation acceptance is a pre-auth bootstrap by design (2.11); single-use token-gated writes no scoped context could perform |
| 7 | ConsentSigningService::derivedStatus | S03 | met/outstanding aggregates over ALL guardians' requests while RLS hides co-guardians; returns booleans only, no row/identity leaves |
| 8 | ConsentDocumentService::download | S03 | Signed-PDF upload row is system-owned storage; read authorisation already decided by consent_documents RLS for the session |
| 9 | ConsentTemplateService::supersedeForLanguage | S03 | OD-20a fan-out supersedes signed requests across ALL guardians — rows the publishing admin can't read; status writes + fresh issuance only, each audited |
| **10** | **PaymentLinkService::resolve** | **S04B** | **Anonymous link VIEW: the viewer holds only the bearer token — no session, no context to scope by. The token IS the authority. Reads exactly one frozen-payload row by sha256 hash; initials-only, no other order data reachable** |
| **11** | **PaymentLinkService::confirmPayment** | **S04B** | **Anonymous link PAY: same — the token is the authority, no user session exists. Atomic active→paying CAS serialises concurrent confirmers; provider self-confirms (OD-47); writes payment + order transition + link death, all audited** |
| **12** | **ManualPaymentService::confirm** | **S04B** | **BI-10 clean-gate, placed AFTER the BI-9 authority check: scan status is a system-integrity fact; the confirmer's authority is already set by finance.confirm + BI-9, but uploads RLS does not admit them, so a scoped count would read 0 and silently defeat the gate. Reads only uploads.status for this payment's evidence** |

Three new this sprint (10–12). The two anonymous-link elevations exist because **the token is the
authority — there is no authenticated session to scope by** (the whole point of a forwardable link).
The BI-10 clean-gate is narrow (reads only `uploads.status`), audited, and sits after the authority
check, so it grants no authority — it only reads a scan-status fact the confirmer's own scope can't see.

## 5. PROCESS note — the grant-role lesson (record for every future immutability review)
`kap_test` runs the app role **as the table owner** (single-role test DB), so a REVOKE on the app
role is a no-op there and the **privilege path is masked in tests** — an immutability test passes on
the trigger alone even when the belt-and-braces GRANT is wrong. **Immutability reviews must check
GRANTs explicitly (\dp / information_schema.role_table_grants on a two-role DB), not just triggers.**
The dev-DB audit at STEP 5 surfaced three real items the test DB masked, now closed (`58d6e25`):
- `order_lines`, `receipts`, `credit_notes` — scoped + trigger-protected (never breachable by
  kap_app: RLS default-deny + trigger), but missing the third-layer REVOKE; added.
- `reconciliation_log` — GLOBAL (non-RLS) with a LIVE grant: kap_app could rewrite a run record.
  The one genuine soft spot (audit spine, SR010). REVOKE added.
- `payment_evidence`, `withdrawal_endorsements` — RLS-protected but single-layer; brought to the
  evidence-table pattern (RLS-deny + trigger + revoke).
Full re-audit: no immutable/append-only table retains UPDATE/DELETE for kap_app.
(Honest correction on the record: I first framed the order_lines/receipts/credit_notes gap as
"defeated on real environments" — overstated. RLS default-deny + trigger already held; the missing
REVOKE was a missing THIRD layer, not an open door.)

## 6. OD / BI trace
| Decision | Where honoured in S04B |
|---|---|
| OD-18 | `amount_minor BIGINT` + `currency CHAR(3) CHECK 'HKD'` on orders/lines/receipts/refunds/credit_notes/invoices — no float anywhere |
| OD-25 | `payer_party` (who pays); refund `destination_party` = original payer, paid BY the academy; schools never collect |
| OD-43 | payment deadline clock set at issuance (family-paid); the outbox fires the trigger; lapse handling → S05 |
| OD-44 | forwardable link: hash-only token, frozen initials (given name, family hidden), multi-view/single-act, no_pii + single_reader assertions |
| OD-46 | PaymentProvider interface + MockProvider; QFPay adapter is S-QFPAY |
| OD-47 | BI-9 scoped to MANUAL payments (schema CHECK both sides); provider payments self-confirm |
| OD-48 | refunds & credit notes full-only (assertion + service construction); no pro-rata |
| OD-54 | school-settled: credit note always; balance drops if unpaid, +refund-to-school if paid; invoices.balance |
| OD-67 | uniform programme fee snapshot; any active guardian reads+pays; school admin sees own invoices only |
| BI-2 | gapless in-transaction receipt numbering (FOR UPDATE); receipts immutable |
| BI-3 | FOR UPDATE on the sequence row; cross-connection serialization proven |
| BI-5 | order_lines & credit_notes INSERT-only (trigger + revoke) |
| BI-9 | recorder ≠ confirmer on manual payments AND refund payouts, app + DB (WITH CHECK) |
| BI-10 | payment evidence rides the existing scan pipeline; confirmation waits for clean |

## 7. Full gate battery (re-run fresh)
```
$ php artisan test               → Tests: 237 passed (2524 assertions)
$ php artisan reconcile:run      → RECONCILE PASS — 26 assertion(s), 26 passed, 0 failed
$ php artisan reconcile:run --tag=S04B → 8/8 (§3)
$ php artisan migrate --pretend  → INFO  Nothing to migrate.
$ npx tsc --noEmit               → CLEAN
$ npm run build                  → i18n 281 keys ×3 parity, no hardcoded strings · bundle-budget PASSED
```

## 8. Previously-green tests touched (all mechanical, flagged when touched)
- `AuthzTest` /api/payments 501→200 (STEP 4: the stub became the real finance-gated surface).
- `ReconciliationRunnerTest` totals 18→22 (STEP 5 + hardening) → 26 (STEP 6) — assertion
  registrations, same class as every prior bump.

## 9. Leftovers & register-level OPEN items (Leo's OD-sweep — NOT S04B blockers)
| # | Item | Status |
|---|------|--------|
| 1 | `deadlines.no_silent_lapse` | Deferred to S05 with its cascade machinery (§3) |
| 2 | Consolidated-invoice LIVE issuance | Fixture here; lands S05/S04E at 成團 volume |
| 3 | Carried from S03 | timestamp trust · PDF/A strictness · R15 placeholder-gone · bootstrap credential rotation — all S10 |
| **OD-3** | Brand assets | **OPEN by design** — non-blocking; swap tokens if the client's real palette differs |
| **OD-7** | Age of majority | **OPEN by design** — safe default in force (guardian consent stays valid for the enrolment's duration) |
| **R14** | Mainland data residency / ICP | **Deferred to QFPay Phase 2** — closed for Phase 1; revisit only if serving mainland users directly |
| **OD-22** | **Member surfaces (FR058)** | **GENUINELY UNASSIGNED — must be assigned to a sprint BEFORE S06 starts.** Register-level action, flagged at this gate so it is not lost |

These four are register-level, not S04B defects — recorded here per the OD-sweep so the gate leaves
the register's open state visible.
