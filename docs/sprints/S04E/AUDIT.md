# AUDIT KAP-S04E — Bulk enrolment (Spec Part H)

**Result:** PASS · **Date:** 2026-08-01 · **HEAD at gate:** `e5311ac`

> Written by Claude Code at the sprint's end. Honesty outranks looking good. This is the BUILD audit;
> the in-product audit element (batch ledger in the Financial Integrity Report) is separate.

## 1. What S04E is (reconciled from the card)
A school administrator enrols a cohort from a **CSV**: virus-scanned before a row is parsed (BI-10),
dry-run-previewed, then committed row-by-row through the **existing** enrolment machinery — intent
only (OD-31), guardian-consent-gated (OD-10). A dashboard shows where each batch is. The card was
reconciled three times against what actually shipped; the rulings are recorded in `SPRINT.md` and the
three `PROPOSED-S04E-*-REVIEW.md` think-first artefacts.

**Rulings that shaped the build:** D-0 (full Part H) · D-5/D-6 (CSV-only, no new dependency) · D-4
(fail-closed clamd probe) · D-7 (real-clamd → S10 item) · D-3 (async scan → parse off CLEAN) ·
**D-8** (enrol only rows with an active guardian; guardian-less → `not_enrolled`) · **D-9** (intent
only — no seats/waitlist/orders; invoicing re-scoped) · **D-10** (programme on the batch, at upload) ·
**D-11** (OD-25 consolidated invoicing → S07) · **D-13** (batch status IS the FR066 ledger — no
exception row) · **D-14** (payer read live from programme E6; batch column dormant).

## 2. Files changed (29)
| Path | A/M/D | Why |
|------|-------|-----|
| `Services/Enrolment/EnrolmentBatchCsvParser.php` | A | STEP 1 — native CSV parse, structural-vs-row failure, formula neutralisation, existing/new disposition (dry run). |
| `Jobs/ValidateEnrolmentBatch.php` | A | STEP 1 — the ONE parse entry point, chained off the CLEAN transition; `contents()` BI-10 refusal is the gate (D-3). |
| `Services/Enrolment/EnrolmentBatchCommitService.php` | A | STEP 2 — reuse `create()` + `EnrolmentService::create()` row-by-row; intent only; DB-enforced idempotency; live guardian re-check. |
| `Services/Enrolment/StructuralParseException.php` | A | STEP 1 — whole-file reject signal. |
| `Http/Controllers/EnrolmentBatchController.php` | A→M | STEP 1 upload (fail-closed probe D-4, programme required D-10) + STEP 2 commit + STEP 3 index/show (live enrolment-status enrichment). |
| `Models/EnrolmentBatch.php` / `EnrolmentBatchRow.php` | A | State/disposition constants. |
| `Services/Uploads/VirusScanner.php` + `ClamAvScanner.php` + `tests/Support/EicarOnlyScanner.php` | M | `isAvailable()` fail-closed probe (D-4) on the interface + both impls. |
| `tests/Support/UnreachableScanner.php` | A | Down-scanner double for the 503 path. |
| `Services/Reconciliation/Assertions/BatchScanGatedAssertion.php` | A | STEP 1 — BI-10 gate, path-independent. |
| `…/BatchRowConservationAssertion.php` | A | STEP 2 — rows conserved + reasoned, no "waiting". |
| `…/BatchNoStuckAssertion.php` | A | STEP 3 — transient-state liveness. |
| `Http/Controllers/FinancialIntegrityReportController.php` | M | STEP 3 — FIR 1b batch ledger (invoice register half → S07). |
| `database/migrations/2026_08_01_100000_create_enrolment_batches.php` | A | STEP 1 — `enrolment_batches` + `enrolment_batch_rows` (scoped RLS). |
| `database/migrations/2026_08_01_110000_enrolment_batch_commit_columns.php` | A | STEP 2 — additive `programme_id`/payer/counts/`enrolment_id`/`committed` (new migration, not an edit). |
| `config/uploads.php` | M | `batch-csv` context + row cap + `batch_stuck_minutes` window. |
| `config/scope-map.php` | M | Classify the two new scoped tables. |
| `config/scope-elevations.php` | M | Allowlist parse / commit / upload / dashboard-enrichment elevations. |
| `Providers/ReconciliationServiceProvider.php` | M | Register the 3 S04E assertions. |
| `routes/api.php` | M | upload / index / show / commit routes (role:school_admin). |
| `tests/Feature/EnrolmentBatch{Intake,Commit,Dashboard}Test.php` | A | 18 feature cases. |
| `tests/Feature/ReconciliationRunnerTest.php` | M | Count guard 47→50. |
| `docs/sprints/S04E/{SPRINT,PROPOSED-*}.md` | A/M | Reconciled card + three think-first artefacts. |

## 3. Step-by-step verification (real output, pasted)

### STEP 1 — intake + scan gate + CSV parse + dry-run · commit `23a488c`
```
EICAR CSV      => status=failed reason='scan not clean (quarantined)'  rows parsed => 0
clamd down     => REAL isAvailable()=false → 503, no batch, no upload
CLEAN CSV      => status=ready counts[total=5 new=1 existing=1 skipped=1 failed=2] usersΔ=0
```
Scan gates parse (BI-10, EICAR double); fail-closed probe before intake; structural→whole-file reject,
row→per-row reason; formula neutralisation. `scope.public_context_confinement` green. Result: **PASS**.

### STEP 2 — batch commit (intent only) · commit `5ba8659`
```
COMMIT 1 => {"enrolled":1,"not_enrolled":1,"skipped":0,"failed":0,"total":2}
COMMIT 2 => same  |  enrolments 1→1 (idempotent)  |  users 18→18 (no dup accounts)
HAS-guardian → enrolled (pending_consent + consent issued, per test) ; NO-guardian → not_enrolled 'awaiting guardian & consent'
```
Reuses `EnrolmentService::create()`; DB `(student_id,programme_id)` unique is the idempotency guarantee
(a dedicated test proves the constraint itself throws); guardian re-checked LIVE at commit. No orders,
no seats, no waitlist (OD-31). Result: **PASS**.

### STEP 3 — dashboard (H4) + FR066-via-status + liveness · commit `e5311ac`
```
index  => lists the school's batches; exceptions = failed batches (D-13 ledger)
show   => enrolled row enriched with LIVE enrolment_status (pending_consent → in_pool as the enrolment advances)
no_stuck => fresh committing = green ; aged >30min = red ; complete = green
five-branch => another school's admin: empty list / 404
```
Read-separation (stored disposition vs live status); FR066 = batch status + audit + dashboard listing
(no dangling row); generous 30-min liveness window. Result: **PASS**.

## 4. Assertions registered this sprint
| Assertion | Tag | First green run pasted? |
|-----------|-----|-------------------------|
| `batches.scan_gated` | S04E | Yes (STEP 1; §6) |
| `batches.row_conservation` | S04E | Yes (STEP 2; §6) |
| `batches.no_stuck` | S04E | Yes (STEP 3; §6) |
| ~~`invoices.line_reconciliation`~~ | → S07 | Deferred (D-11) with the consolidated invoice it guards. |

## 5. Deviations from SPRINT.md (all ruled, all recorded)
| Card said | Actually happened | Why |
|-----------|-------------------|-----|
| Bulk **enrolment** with seat lock (2.7) / waitlist (2.18) / orders | Intent only — no seats/waitlist/orders | D-9: enrolment is intent (OD-31); allocation is S05 成團. |
| STEP 2 enrols all rows | Enrols only rows with an active guardian; guardian-less → `not_enrolled` | D-8: enrolment is guardian-consent-gated (OD-10). |
| STEP 3 builds consolidated invoicing (OD-25) | **Deferred to S07** | D-11: invoice is (school,programme)-keyed over 成團 orders that don't exist yet; the missing wire (obligation payer from E6) is a finance change. |
| FR066 = `onboarding_exceptions` row | Batch **status** is the ledger | D-13 (refined): that table CHECK-blocks `enrolment_batch` and has no resolve path; a row would dangle. Status enum + audit + dashboard listing is the trackable terminal fate. |
| CSV **and** xlsx | CSV only | D-5/D-6: xlsx carries the zip/XXE surface + a new dependency (a STOP); deferred fast-follow. |

## 6. Exit gate
```
$ php artisan reconcile:run --tag=S04E
  PASS  batches.scan_gated        [2.12 · BI-10 · S04E STEP 1]
  PASS  batches.row_conservation  [Spec Part H P4 · OD-31 · S04E STEP 2]
  PASS  batches.no_stuck          [Spec Part H H2 · S04E STEP 3]
RECONCILE PASS — 3 assertion(s), 3 passed, 0 failed

$ php artisan reconcile:run          # all prior tags + S04E
RECONCILE PASS — 50 assertion(s), 50 passed, 0 failed

$ php -d memory_limit=1G vendor/bin/phpunit --filter '/^(?!.*ClamAv).*/'   # full suite, ex-clamd
OK (392 tests, 4709 assertions)

$ php -d memory_limit=1G vendor/bin/phpunit --filter 'EnrolmentBatch(Intake|Commit|Dashboard)Test'
OK (18 tests, 152 assertions)

# ClamAv INTEGRATION suite — pre-existing infra flake, NOT S04E (S04E ships its gate on the double):
kap-clamav-1  Up (unhealthy)   # clamd OOM in the 3.8GB VM — the S10 acceptance item below
```
**Verdict:** PASS. Three S04E assertions green, full 50-assertion battery green, full suite (392) green
ex-clamd, 18 S04E feature cases green. The only red remains the ClamAv **integration** suite (unhealthy
local clamd) — infra, not S04E code.

## 7. Named hand-offs (not loose ends)
| Item | Lands in | Gate condition |
|------|----------|----------------|
| **OD-25 consolidated invoicing** + `invoices.line_reconciliation` (D-11) | **S07 (team finance)** | 成團 orders exist + obligation payer wired from programme E6 (not hardcoded `'guardian'`). Schema seam already present (`consolidated_invoices` (school,programme)-keyed; `payment_obligations` has payer cols). |
| **xlsx bulk intake** (D-5/D-6) | fast-follow | new xlsx-reader dependency (a STOP) + zip/XXE confinement. |
| **real-clamd `batch-csv` end-to-end** (D-7) | **S10 (go-live)** | verify the live daemon flags/passes a `batch-csv` upload before go-live; S04E proved the gate on the EICAR double. |

## 8. Invariant check
| BI | Touched? | Evidence |
|----|----------|----------|
| BI-10 (uploads invisible until scan passes) | Yes — the whole STEP 1 gate | `contents()` refuses non-CLEAN; `batches.scan_gated` (path-independent); EICAR → 0 rows parsed. |
| BI-4 (enrolment idempotent, duplicate returns original) | Yes — reused | `EnrolmentService::create()` + DB `(student_id,programme_id)` unique; re-commit 1→1; the constraint-itself test. |
| BI-1 (audit INSERT-only) | Yes | every batch transition audits; drive cleanups retained audit rows (superuser DELETE refused on `audit_events`). |
| Scope-elevation discipline | Yes | `ScopeElevationTest` green — parse/commit/upload/dashboard-enrichment sites allowlisted with exact reasons. |
| Scope coverage | Yes | `scope.coverage` green — both new tables classified + RLS-forced; `scope.public_context_confinement` green (no public policy). |
