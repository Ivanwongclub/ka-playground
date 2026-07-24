# SPRINT KAP-S04A — Enrolment, seats, waitlist, orders & receipts

> S04 is split: S04A = the ordered happy path (enrolment → order → receipt).
> S04B = money's exception reality (payments, refunds, late/wrong/unmatched).
> Adjusted 2026-07-24 from S03 AUDIT §5 and OPEN-DECISIONS follow-ons (Leo):
> carry-forwards are STEPS, not notes — consent-INSERT narrowing (§5 item 4) and
> the client fee-terms scoping resolution both land here or not at all.
> Not committed; not started.

## GOAL
An enrolment can be created exactly once, hold exactly one seat under concurrency, produce an
immutable order with a gapless receipt path, and a freed seat goes to the waitlist — never to luck.
Consent requests issue automatically at enrolment through a path that can never read consent data,
and the temporary manual-issuance widening from S03 is REVERSED.

## PRECONDITIONS
- [x] S03 gate PASSED (`9dc6fbd`) · OD-6 (multi-guardian authority) confirmed 2026-07-23
- [ ] **CLIENT ANSWER: are fees / withdrawal terms ever school-specific?** (S02B carry-forward,
  S03 §5 item 3). Gates STEP 6's consumer fee-read clause — **STOP at STEP 6 if still open**;
  the rest of the sprint does not wait on it.

## IMPLEMENTS  2.7 · 2.8 (enrolment) · 2.18 · 2.22 · BI-2 (sequence) · BI-3 · BI-4 · BI-5 ·
BI-7 (state only; policy math S04B) · OD-6 · OD-10 (issuance set) · OD-11 · OD-18 · E5 · register FRs

## DESIGN ANSWER (stated before build, per Leo): how enrolment issues consent
## requests into a table it must not read

**The path is a SYSTEM-CONTEXT QUEUED JOB, not an elevation.** Enrolment creation runs in the
acting guardian's context inside the 2.7 seat transaction; it writes the enrolment row, and after
commit dispatches `IssueConsentRequests(enrolmentId)`. The job runs under the queue's structural
system context (S02A lifecycle: `beginJob` → system; no allowlist entry, no in-request bypass) and
calls the same `ConsentSigningService::issueRequest` path S03 built, issuing one request to EVERY
active guardian of the student (any-one satisfies unless `consent_requires_all_guardians`, OD-10).

Why a job and not an `asSystem` elevation:
- An elevation would put a system-context window INSIDE a guardian-driven HTTP request, adjacent
  to attacker-influenced input. The job boundary is structural and carries no request state at all.
- Issuance is asynchronous by nature (the hold window is 7 days, OD-11); nothing about the seat
  transaction needs the requests to exist synchronously. Failure isolation: a failed issuance
  retries and dead-letters (P4) without unwinding the seat.

Why this path cannot become a way to READ consent data:
- The job's input is an enrolment id only; its return value goes nowhere (queued). The enrolment
  API response carries NO consent data — the guardian learns of their request by reading
  `/consent-requests`, where RLS shows them exactly their own addressed rows and nothing else.
- The signer set is derived server-side from `guardian_links` inside the job — never from any
  request payload, so the flow cannot be steered to address a request to an arbitrary account.
- The job writes and transitions; it never selects consent_signatures/documents. The S03 read
  sets are untouched: co-guardians still see zero of each other's evidence.
- **The S03 void→re-issue path is re-routed through the same job** once INSERT narrows to
  system-only (ops void stays synchronous and audited; the replacement issuance is queued).
- New assertion (below) closes the lost-job hole the S03 gate exposed: every Awaiting-Consent
  enrolment has a request addressed to every active guardian — a dropped job goes red nightly.

## SCOPE CLASSIFICATION PLAN (declared before work starts; read sets pre-stated)
**Recall: global = readable by EVERY authenticated session. When in doubt, scoped.**

| Table | Classification | Read set / justification |
|---|---|---|
| `enrolments` | **scoped** | A child's participation status is personal data. Read: system · ops/audit · the student · their ACTIVE guardians · school_admin of the student's school (batch/roster duty). Teachers: not in S04A (attendance arrives S06 with its own clause). Write: system state machine only — every transition audited with acting guardian (2.22/BI-8); `Withdrawn` reachable only via the workflow (BI-7, math in S04B) |
| `waitlist_entries` | **scoped** | Queue position reveals enrolment intent. Read: system · ops/audit · the student · their guardians · school_admin of school. Write: system, only inside the 2.7 lock (promotion/expiry) |
| `orders` | **scoped** | Financial + personal. Read: system · finance/audit · the payer guardian · the student (read-only, Spec Q1 6.5). NOT school admins (consolidated billing is an S04B decision). Write: system |
| `order_lines` | **scoped** | Same read set as orders. **INSERT-only at the DB (BI-5): trigger + conditional revoke (kap_test owner caveat per S03)** — corrections are credit notes/refunds (S04B), never edits |
| `receipts` | **scoped** | Read: system · finance/audit · payer guardian (downloads own) · student read-only. Write: system — number assigned INSIDE the issuing transaction from the sequence row (BI-2); never pre-reserved |
| `receipt_sequences` | **scoped (internal)** | The gapless counter row. Read: system · finance/audit. Write: system under `SELECT … FOR UPDATE` (BI-2/BI-3) |
| `consent_requests` (policy change) | already scoped | **INSERT narrows to system-only (STEP 1)** — the S03 ops branch is REMOVED |
| `fee_items` / `withdrawal_policies` / `withdrawal_bands` (policy change) | already scoped | Consumer read clause added at STEP 6 PER THE CLIENT ANSWER: bound parties read the terms of programmes they can be bound by — shaped school-specifically ONLY if the client says terms differ per school |

**OD-18 (every monetary field, non-negotiable, schema-level):** `amount_minor BIGINT` +
`currency CHAR(3) CHECK (currency = 'HKD')` on order_lines, orders (totals), receipts. No float,
no decimal, anywhere in the money path. Order lines SNAPSHOT the fee (name trilingual + amount +
currency) at creation — immutable capture, not a fee_items reference that could drift.

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Narrow consent_requests INSERT to system-only (S03 §5 item 4 — the named reversal).**
   Migration drops the ops branch from `cr_insert`; `POST /admin/consent-requests` is REMOVED
   (manual issuance ends when automatic issuance begins); S03 fixtures/tests migrate to a
   system-context issuance helper; void→re-issue re-routed through the issuance job (stub now,
   wired in step 2). VERIFY: ops-context INSERT refused at the DB (paste); the removed route 404s
   (paste); S03 suite green; `--tag=S03` green.
2. **Enrolment state machine (E5) + automatic consent issuance.** States per spec; guardian +
   consent preconditions (consent gate consumes S03 `consentSatisfied`, OD-10); acting guardian
   recorded on every action (2.22); conflicting guardian actions → Academy Admin exception queue
   entry, never auto-executed (OD-6); `IssueConsentRequests` job per the DESIGN ANSWER.
   VERIFY: enrol → requests appear for EVERY active guardian (paste); enrolment response body
   contains no consent fields (paste); job failure → retry → dead-letter visible (paste).
3. **Seat locking (2.7 / BI-3)**: capacity check + insert in one transaction, `SELECT FOR UPDATE`
   on the programme counter row. VERIFY: two concurrent enrolments on capacity 1 — exactly one
   wins, loser gets waitlist offer or clean "full"; paste both raw responses.
4. **Idempotency (2.8 / BI-4)**: partial unique index — one enrolment per (student, programme)
   outside terminal states; duplicate submit returns the ORIGINAL id (paste).
5. **Waitlist (2.18) + hold window (OD-11)**: Waiting → Offered(48h) → Accepted/Expired/Declined/
   Withdrawn; release promotes head-of-queue INSIDE the 2.7 lock; offer-expiry and hold-expiry
   (7d default, per-programme override) jobs; expiry releases the seat and runs promotion.
   VERIFY: withdraw a seed enrolment → head Offered in the same transaction (paste); 49h fixture
   → Expired, next Offered (paste).
6. **Orders, immutable lines, gapless receipt sequence (BI-2/BI-5, OD-18) + fee-terms scoping.**
   Fee snapshot into lines; sequence mechanism lands here (issuance against payments completes in
   S04B). **`payer_party` per OD-25: enum guardian | student | school — WHO PAYS, never who
   collects; the academy is always the recipient; schools never collect and never refund.**
   Refund destination = the original payer party, paid by the academy (OD-6/2.17), recorded now.
   **Consumer fee-read clause per the CLIENT ANSWER — STOP here if the answer has not arrived.**
   VERIFY: receipt probe gapless under 50 parallel issuances (paste); order_lines UPDATE refused
   at the DB (paste); OD-18 schema paste (`\d order_lines` showing amount_minor + currency CHECK).
7. **Student status timeline UI (E5) + audit element + assertions.** Trilingual, i18n-checked,
   bundle budget green.

## NON-SCOPE
Payment recording, refunds, credit notes, unmatched/late exception queues (S04B) · policy math for
withdrawal (S04B) · teams (S05) · sessions/attendance (S06) · Member anything (OD-22).

## KEY VERIFICATIONS
- **Five-branch live isolation PER SCOPED TABLE** (the S03 pattern; policy exists ≠ policy correct):
  - `enrolments`: guardian sees own students' · co-guardian sees same student's (both active) ·
    OTHER guardian zero · student sees own · school_admin of school sees; other-school zero ·
    Member zero. Paste.
  - `waitlist_entries`: same branches. Paste.
  - `orders` + `order_lines`: payer guardian sees · non-payer co-guardian: per OD-6 any guardian
    may act — state the chosen read outcome BEFORE building · student read-only · school_admin
    ZERO · Member zero. Paste.
  - `receipts`: payer guardian downloads own · student reads own · finance sees all · school_admin
    zero · Member zero. Paste.
- Consent narrowing (STEP 1) refusal pastes at API and DB level.
- Enrolment cannot reach Active without consent satisfied (OD-10 both modes) — paste both.
- Every monetary value in every S04A response body is `{amount_minor, currency}` — no derived
  floats server-side (paste a response).
- `reconcile:run --tag=S03` and `--tag=S02A` green after every step (regression: the narrowing
  touches S03's tables).

## AUDIT ELEMENT (part 1 of the Financial Integrity Report)
Enrolment & Seat Report — state timeline per enrolment with acting guardian; seat ledger per
programme (capacity vs held vs waitlisted); waitlist history with offer outcomes; consent-issuance
health (enrolments awaiting consent vs requests outstanding — surfaces lost jobs to the client).

## ASSERTIONS (--tag=S04A)
- No free seat while a Waiting entry exists and booking is open (2.18).
- No Offered entry past its expiry (2.18).
- No enrolment past its hold deadline in a non-terminal state (OD-11).
- Receipt sequence gapless: `max(number) == count(*)` per sequence AND no duplicates (BI-2).
- `order_lines` rejects UPDATE/DELETE at the database (BI-5 probe, S03 pattern).
- **`consent.issuance_completeness` (new, closes the S03-gate lost-job hole): every enrolment in
  Awaiting Consent has an open-or-signed request addressed to EVERY active guardian of its
  student.** Vacuous-aware until volume.
- S01's guardian-link coverage assertion now fires non-vacuously; S03's three keep running.

## EXIT GATE
Tests + `reconcile:run --tag=S04A` + `--tag=S03` + `--tag=S02A` green + concurrency pastes + the
five-branch pastes for all six scoped tables + OD-18 schema paste + elevation-list review (no new
elevations expected — the design answer uses none) + bundle budget green. AUDIT.md (carry forward:
timestamp-trust/PDF-A/R15 at S10; fee-terms outcome recorded), gate commit.
