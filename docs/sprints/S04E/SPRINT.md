# SPRINT KAP-S04E — Bulk enrolment (Spec Part H)

> New card per the approved 2026-07-24 re-plan (Leo change 2: Part H gets its own card, not a
> tail on S04D). Runs AFTER S04D. Position rationale: consolidated invoicing needs S04B's orders
> and receipts; batch rows need S04D's bulk-created students; batch enrolment needs S04A's seat
> and consent machinery. S04E is where all three meet, immediately before S05 teams consume the
> resulting enrolments.
>
> **RECONCILED 2026-07-31** against what shipped (S04D `BulkStudentCreationService::create()`, the
> S00 upload service) per `docs/sprints/S04E/PROPOSED-S04E-REVIEW.md` and Leo's six rulings:
> **D-0** — S04E is the **full Part H bulk ENROLMENT** (the card stands); the file-intake layer is
> its STEP 1, which **feeds the proven `create()` for new-student rows and matches existing students
> on the roll** — it does NOT re-implement the row engine (per-row txn, roll authority, idempotency,
> minting, audit, defined failure mode all already live in `create()`).
> **D-5/D-6** — **CSV-only in Phase 1** (native reader, NO new dependency); **xlsx is deferred** as a
> fast-follow — its xlsx-reader `composer require` is a STOP for a later card, never added here.
> **D-4** — a **fail-closed clamd probe**: when the scanner is unreachable, `batch-csv` upload is
> refused with **503** (never accepted-then-stuck-pending).
> **D-3** — the ClamAV scan is **async**; the parse is **job-chained off the `CLEAN` transition**,
> never run synchronously in the upload request.
> **D-7** — the real-clamd end-to-end check for `batch-csv` stays an **S10 acceptance item** (verify
> against the live daemon before go-live); S04E proves its gate on the `EicarOnlyScanner` double.

## GOAL
A school administrator enrols a cohort in one auditable batch: **CSV** in, per-row outcomes out,
seats and consent and orders behaving exactly as they do for a single enrolment — and one
consolidated invoice to the school when the school is the payer (OD-25). The file is **virus-scanned
before a single row is parsed** (BI-10); the row engine is the **existing, proven `create()`** for
new students plus roll-match for existing ones — S04E adds only the **file intake layer and the
enrolment/invoicing** on top.

## PRECONDITIONS
- [ ] S04D gate PASSED · OD-25 recorded (school = payer, never collector) · client fee-terms
  answer applied in S04A step 6 (its outcome shapes the consolidated invoice's read set)

## IMPLEMENTS  Spec Part H (H1–H4) · 2.7/2.8/2.18 (per row, via S04A machinery) · 2.12/BI-10 (batch-csv context) · OD-25 · OD-18 · FR066 (exceptions reuse)

## BUILDS ON (reuse — do NOT re-implement)
- `App\Services\Identity\BulkStudentCreationService::create(User $admin, array $rows)` — the row
  engine (per-row transaction, per-row roll authority OD-30, idempotency by email, minting via
  `AccountMintingService::mintPendingActivation`, per-row + batch audit, **defined failure mode**:
  created/skipped/rejected report, never a silent partial). Proven live: `docs/audit/S04D-TEST-AUDIT-LIVE.md`.
  S04E feeds it the **new-student** rows; it is one of two sinks (the other is roll-match + enrolment).
- `App\Services\Uploads\UploadService::intake()/contents()` + `App\Jobs\ScanUpload` + the
  `VirusScanner` interface — the BI-10 scan gate. `contents()` throws unless the upload is `CLEAN`;
  the parser reads ONLY through it, so an infected/errored file physically cannot reach the parse.
- `Tests\Support\EicarOnlyScanner` / `Tests\Support\Eicar` — the daemon-free double that proves both
  verdicts (S04E binds it exactly as `UploadServiceTest` does).

## SCOPE CLASSIFICATION PLAN
| Table | Classification | Read set / justification |
|---|---|---|
| `enrolment_batches` | **scoped** | A school's cohort operation. Read: system · the owning school's admins · academy ops/finance/audit. Write: system (state machine H2: Draft → Uploaded → Scanning → Validating → Ready → Committing → Complete \| Failed \| Partially Complete). **No public policy** — `scope.public_context_confinement` stays green |
| `enrolment_batch_rows` | **scoped** | Per-row child data (H3: Pending → Validated → Enrolled \| Skipped(reason) \| Failed(reason)). Same read set as the batch. Write: system. Row outcomes NEVER silently dropped — every non-Enrolled row carries its reason (P4). Carries a per-row disposition (`match-existing` vs `new→create()`) |
| `consolidated_invoices` | **scoped** | Money document addressed to a school (payer_party = school, OD-25 — the school PAYS, never collects). Read: system · finance/audit · the addressed school's admins. Write: system. OD-18 minor units + currency; lines snapshot per enrolment order |

New upload **context** (`config/uploads.php`, not `.env`): `batch-csv` — MIME allow-list `text/csv`
(+ the finfo reality that plain CSV often resolves `text/plain`; allow-list deliberately, verify
structurally at parse), hard byte cap + a **row-count cap** at parse. **xlsx MIME is NOT allow-listed
in Phase 1** (D-5).

## SCOPE (steps in this order; each = VERIFY + commit + stop)
1. **Batch intake + scan gate + CSV parse + dry-run (H1).** See the STEP 1 PLAN below. A school admin
   uploads a **CSV** through the S00 upload service under the new `batch-csv` context (BI-10 — the
   file is **scanned before parsing**, hard caps enforced at `intake()`). The scan is **async**; the
   parse is **chained off the `CLEAN` transition** (D-3) — a `QUARANTINED`/still-`PENDING` upload
   fails the batch to `Failed(scan)`, never parsed. When clamd is **unreachable**, the upload is
   refused **503** (fail-closed probe, D-4). On `CLEAN`, the CSV is parsed with structural-vs-row
   safety (§Parse safety) and each row is routed **existing-student (roll match) vs new-student
   (→ `create()`)**; a **dry-run report** (per-row disposition, nothing committed) precedes commit.
   VERIFY: EICAR-embedded CSV → batch `Failed(scan)`, **zero rows parsed** (double); clamd-unreachable
   → **503**, no batch row; hostile CSV (formula-cell `=+-@`, wrong/missing columns, oversize,
   bad encoding) → whole-file reject **and** per-row reasons pasted; clean CSV → dry-run report paste;
   `scope.public_context_confinement` green.
2. **Batch commit (H2/H3).** Row-by-row: **new-student rows through `create()`** (reused, not
   re-built), **existing rows matched** on the school roll, then **all rows enrolled through the REAL
   S04A machinery** — seat lock (2.7), idempotency (2.8), waitlist on full (2.18), consent issuance
   job per enrolment; batch is resumable, never half-silent; failures → per-row reasons + FR066
   exception on batch failure. VERIFY: batch spanning capacity boundary — some Enrolled, overflow
   Waiting, reasons pasted; re-commit idempotent (no duplicate accounts *or* enrolments) paste.
3. **Batch dashboard (H4) + consolidated invoicing (OD-25).** School admin sees Active | Complete |
   Exceptions with per-row drill-down (2.28/Q4 3.4); when payer_party = school, one consolidated
   invoice aggregates the batch's orders (OD-18 fields; academy is the recipient of funds, always);
   guardian-payer rows bill individually as S04A built. VERIFY: invoice totals equal the sum of
   member order lines (paste); OD-18 schema paste; school admin of ANOTHER school sees zero (five-branch).

## STEP 1 PLAN (intake layer — the reviewed detail)
The front end of Part H, and where the PROPOSED-review's Q1–Q5 land. Sequenced by the async gate:

1. **Upload (auth, fail-closed).** `POST /api/school/enrolment-batches` in the existing
   `role:school_admin` group (roll authority still enforced per-row downstream by `create()`, OD-30 —
   not by the endpoint alone). Before accepting bytes: the **fail-closed clamd probe** (D-4) — if
   `ClamAvScanner` cannot reach clamd, respond **503** and create no batch (never accept-then-stuck).
   On success: `UploadService::intake($file, 'batch-csv', $admin)` (MIME + byte cap enforced there),
   create `enrolment_batches` row `Draft → Uploaded`, audit; `intake()` dispatches `ScanUpload`.
2. **Scan (async, BI-10).** `ScanUpload` runs on Horizon exactly as today: `CLEAN` →
   `upload.scan_passed`; `QUARANTINED` → `upload.quarantined` + critical alert; scanner failure
   retries and, exhausted, leaves it `PENDING` (invisible). **No S04E change to the scan job** — it
   already does the right thing.
3. **Parse — chained off CLEAN (D-3).** The parse is triggered by the `CLEAN` transition (a job
   dispatched on `upload.scan_passed` for a `batch-csv` upload, or the batch `Scanning → Validating`
   guard that will not fire until `Upload::isVisible()`), **never** synchronously in the upload
   request. The parser obtains bytes via `UploadService::contents()` — which **throws BI-10 unless
   CLEAN**, so an unscanned/infected file cannot reach it. A non-CLEAN terminal verdict fails the
   batch to `Failed(reason=scan)`.
4. **CSV read + safety (no new dependency, D-6).** Native strict CSV read (RFC 4180, explicit
   delimiter — no sniffing), UTF-8 enforced + BOM stripped, control chars rejected, cell length +
   **row-count cap**. **Structural failure → whole-file reject → batch `Failed`, zero rows to
   `create()`** (wrong/missing/extra header columns, undecodable encoding, zero data rows, over cap).
   **Row failure → per-row `Skipped/Failed(reason)`** (bad email, missing name, empty `school_id`,
   intra-file duplicate, formula cell). **Formula/CSV injection**: any cell beginning `= + - @` / tab
   / CR is neutralised (reject or prefix-escape) on intake, and escaped again on any export.
5. **Existing-vs-new routing + dry-run.** Each valid row is dispositioned: **existing** (matched on
   the school roll — NOT re-minted) vs **new** (queued for `create()`). Produce a **dry-run report**
   (counts + per-row disposition/reason) with **nothing committed** — the parse writes
   `enrolment_batch_rows` in `Validated/Skipped/Failed`, the batch reaches `Ready`. Commit is STEP 2.
   `create()` is called only at commit, only for new rows, and remains the single transaction-per-row
   sink — the parser opens no transaction of its own.

**STEP 1 VERIFY (pastes required):** EICAR-embedded CSV → `Failed(scan)`, zero rows (double);
clamd-unreachable → 503, no batch; formula-cell/ wrong-columns / oversize / bad-encoding CSV →
whole-file reject + per-row reasons; clean CSV → dry-run report (existing vs new disposition);
`scope.public_context_confinement` green; `batch-csv` context added to `config/uploads.php`.

## Parse safety (structural vs row — the defined failure mode, extended upstream)
Mirrors `create()`'s discipline one layer up: **structural failures reject the whole file** (report,
never a partial import); **row failures skip/fail that row with a reason**; **nothing streams into
`create()` before the whole file validates structurally**. Full list in STEP 1 PLAN §4.

## File attack surface (CSV Phase 1; xlsx deferred)
CSV: formula/CSV injection (neutralise `=+-@`/tab/CR on intake AND escape on export), oversize/row-bomb
(byte cap at `intake()` + row-count cap at parse), bad encoding (UTF-8 enforced). **xlsx is NOT
accepted in Phase 1** — when it lands (fast-follow), it carries the zip surface (zip-bomb →
decompressed-size/entry caps; XXE/billion-laughs → entity loading disabled; macro files excluded) and
a new `composer require` that is a STOP. Not this card.

## NON-SCOPE
xlsx intake (deferred fast-follow, D-5; its reader dependency is a STOP) · the S10 clamd env fix
itself (S04E ships its gate on the double; real-clamd `batch-csv` end-to-end is the **S10 acceptance
item**, D-7) · payment recording against consolidated invoices (S04B machinery consumes them; if S04B
gated before this card, wire-up only — no new payment paths) · teams (S05) · any linkage flow (S04D).

## KEY VERIFICATIONS
Five-branch per scoped table · **scan gates parse: EICAR CSV never reaches a parsed row (double)** ·
**fail-closed: clamd-unreachable → 503, no stuck batch** · batch of N produces exactly N audited
outcomes (no silent rows) · **new rows via `create()` reused, existing rows roll-matched (no
re-mint)** · consent issuance fired per enrolled row (`consent.issuance_completeness` goes non-vacuous
at volume here) · **`scope.public_context_confinement` stays green — S04E adds NO public policy** ·
all prior tags green each step.

## AUDIT ELEMENT (Financial Integrity Report, part 1b)
Batch ledger — batches by school/status/age; per-row outcome distribution; **upload/scan disposition
per batch (received / clean / quarantined / scan-unreachable-refused)**; consolidated invoice register
with order-line reconciliation.

## ASSERTIONS (--tag=S04E)
- `batches.row_conservation` — every committed batch's rows sum to Enrolled + Skipped + Failed +
  Waiting, each non-Enrolled with a reason.
- `batches.scan_gated` — no `enrolment_batch_rows` exist for a batch whose upload is not `CLEAN`
  (a parsed row implies a passed scan — the BI-10 gate, assertable).
- `invoices.line_reconciliation` — every consolidated invoice total equals the sum of its member
  order lines (integer minor units, same currency).
- `batches.no_stuck` — no batch in Scanning/Validating/Committing older than its job-timeout window.

## EXIT GATE
Tests + `--tag=S04E` + all prior tags green + STEP 1 scan-gate/fail-closed/hostile-CSV pastes +
capacity-boundary batch paste + invoice reconciliation paste + five-branch pastes + AUDIT.md
(**record the D-7 S10 acceptance item: verify real-clamd `batch-csv` end-to-end with the live daemon
before go-live**), gate commit.
