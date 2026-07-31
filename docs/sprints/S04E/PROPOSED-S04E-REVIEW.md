# PROPOSED S04E REVIEW — think-first pass (no code)

**Author:** Claude Code · **Date:** 2026-07-31 · **For:** Leo's review BEFORE S04E STEP 1
**Reconciles:** `docs/sprints/S04E/SPRINT.md` (card "Bulk enrolment — Spec Part H") against what
actually shipped (S04D `BulkStudentCreationService::create()`, the S00 upload service) and against
the scope you framed for this pass.
**Status:** planning only. Nothing built. One decision (**D-0**) blocks the step plan — it is yours.

---

## 0. TL;DR — the one thing to decide first (D-0)

**Your framing and the committed card describe two different sprints.** They must be reconciled
before STEP 1, because they imply different tables, different assertions, and a different amount of work.

- **Your framing (this pass):** "S04E is the CSV/xlsx bulk **intake** + the ClamAv-scanned upload
  path, feeding parsed rows into the existing `BulkStudentCreationService::create()`." That is
  **bulk student-ACCOUNT creation by file** — accounts + `school_links`, nothing more. `create()`
  already does exactly that (S04D STEP 4).
- **The committed card (`SPRINT.md`):** "**Bulk ENROLMENT** (Spec Part H)". Its goal is to *enrol a
  cohort* — seat locks (2.7), idempotency (2.8), waitlist (2.18), **consent issuance per row**,
  **orders**, and a **consolidated invoice to the school (OD-25)**. `create()` appears only as a
  sub-call for the *new-student rows* inside STEP 1; STEP 2 and STEP 3 are enrolment + invoicing,
  which `create()` does **not** touch at all.

So "feeding parsed rows into `create()`" describes **only the new-student sub-path of the card's
STEP 1**. The card is ~3× that. **This is drift D-1 (the headline) and I cannot resolve it — you must.**

**D-0 — which is S04E?**
- **(A) Card stands — S04E = full Part H.** The file-intake layer you described *is* STEP 1's front
  end; STEP 2/3 add enrolment + invoicing. My step plan below plans STEP 1 in the depth you asked
  for and leaves STEP 2/3 as the card has them (flagging their own reconciliation needs).
- **(B) Re-scope — S04E = intake→`create()` only.** Split Part H enrolment/invoicing into a new
  card (S04F). S04E becomes purely "bulk student accounts by file". Matches your framing literally
  but **drops the card's stated OD-25 consolidated-invoice goal** and Part H — a real deletion of
  scope that only you can authorise.

**My recommendation: (A).** The card's placement rationale (needs S04A seats + S04B orders + S04D
students to *all meet here*) and its three assertions (`row_conservation`, `line_reconciliation`,
`no_stuck`) are enrolment/invoice-shaped — the sprint was designed as bulk *enrolment*, and the
file-intake you want planned is genuinely its STEP 1. But (A) means S04E is a large sprint; if you'd
rather ship the file→`create()` intake alone and defer enrolment, that's (B) and it's legitimate — I
just won't assume it. **Everything below plans the STEP 1 intake layer either reading shares.**

---

## 1. Scope reconciliation — does the card build ON `create()` or re-create it? (your Q1)

**It builds on it — confirmed, with one caveat.**

`BulkStudentCreationService::create(User $admin, array $rows)` (proven live in S04D, `docs/audit/
S04D-TEST-AUDIT-LIVE.md`) already owns the **row-processing engine**:
per-row `DB::transaction`, per-row roll authority (OD-30), idempotency by email, minting via
`AccountMintingService::mintPendingActivation` (born-unverified + token), per-row + batch audit,
and the **defined failure mode** (created / skipped / rejected report, never a silent partial).

**S04E must NOT reimplement any of that.** S04E adds the **file layer on top**:
`upload → virus-scan → parse (CSV/xlsx → rows) → hand rows to create()`.

**Caveat (drift D-2):** `create()` takes rows of `{name, email, school_id}` and produces *accounts +
school_links* — it does **not enrol**. The card's batch also (a) **matches existing students** on the
school roll (no `create()` call — an existing student is not re-minted), and (b) **enrols** every
matched/created row (STEP 2, via S04A machinery). So the intake layer feeds `create()` for the
*new-student* rows only, and feeds the *enrolment* path for all rows. "Feed `create()`" is one of two
downstream sinks, not the whole batch. STEP 1's parser output therefore needs a per-row disposition
(`existing → match` vs `new → create()`), not just a flat "call create()".

**Net:** the engine exists and is reused; S04E defines the intake and the existing-vs-new routing.
No re-creation. ✔ (subject to D-0 confirming how far past `create()` STEP 1's rows travel).

---

## 2. THE CLAMAV DEPENDENCY — now load-bearing (your Q2, the most important question)

**Answer up front: S04E's scan-gates-parse behaviour is FULLY TESTABLE in the current environment.
The S10 clamd fix is NOT a prerequisite for S04E.** Here is why, grounded in what already exists.

### 2a. How the scan gates the parse
The S00 upload service already enforces exactly the gate the card needs (BI-10):
- `UploadService::intake($file, $context, $actor)` — validates per-context MIME + size caps, stores
  the original bytes to a **private pending** area (status `PENDING`, invisible), audits
  `upload.received`, and **dispatches the async `ScanUpload` job**. It returns before the verdict.
- `ScanUpload::handle(VirusScanner ...)` — scans the original bytes. Clean → move to `clean/`, status
  `CLEAN`, audit `upload.scan_passed`. Hit → move to `quarantine/`, status `QUARANTINED`, audit
  `upload.quarantined` + `Log::critical` alert. A scanner *failure* retries (3 tries) and, exhausted,
  **leaves the file PENDING — never visible**.
- `UploadService::contents($upload)` **throws** `"…not visible… BI-10"` unless
  `$upload->isVisible()` (i.e. status `CLEAN`).

**So the gate S04E needs is already the law of this service:** the parser must obtain its bytes via
`UploadService::contents()` (or guard on `isVisible()`), which means **an infected or errored file
physically cannot reach the parser** — `contents()` refuses it. STEP 1 wires the parse to run **only
on the `CLEAN` transition** (job-chained off `upload.scan_passed`, or a batch state
`Validating` that will not start until the upload is `CLEAN`). A `QUARANTINED`/still-`PENDING`
upload fails the batch to `Failed(reason='scan not clean')` — never parsed.

**Design consequence to call out (D-3):** the scan is **asynchronous**. The card's H2 state machine
must therefore treat "scan clean" as a *precondition transition* — `Draft → (upload) → awaiting-scan
→ Validating` — not a synchronous call inside the request. STEP 1 must not `parse()` in the same
request that uploads. This is a real sequencing constraint, not a nicety.

### 2b. Can S04E test its own scanner path in the current env? — YES, without the daemon
The scanner sits behind the **`VirusScanner` interface**. Two implementations exist:
- `App\Services\Uploads\ClamAvScanner` — the real clamd socket client (the one that's infra-flaky).
- `Tests\Support\EicarOnlyScanner` — a **test double** implementing `VirusScanner`, flagging only the
  EICAR string, clean otherwise. Already autoloaded (PSR-4 `Tests\`), already used by
  `UploadServiceTest`, which binds it (`$this->app->bind(VirusScanner::class, EicarOnlyScanner::class)`)
  and drives `ScanUpload::handle` directly to prove **both** verdicts: EICAR → `QUARANTINED` + not
  visible + `contents()` throws BI-10; clean → `CLEAN` + visible + readable.

**Therefore S04E proves its gate the same way, with zero dependence on a running clamd:** feed a
`batch-csv` upload whose bytes embed `Tests\Support\Eicar::STRING` → assert the batch refuses to parse
and lands `Failed(reason=scan)`, no row reaches `create()`; feed a clean CSV → assert it parses and
rows flow. This is a **true test of the gate** (the interface + the async job + the `contents()`
refusal), differing from production only in *which* `VirusScanner` is bound — exactly the seam the
interface was built for.

### 2c. What the real clamd (`ClamAvIntegrationTest`) is, and whether to pull S10 forward
`ClamAvIntegrationTest` exercises the **real daemon** end-to-end (real EICAR → real clamd verdict). It
is the one suite that's infra-blocked (`kap-clamav-1` `Up (unhealthy)`, clamd OOMs loading the 3.2M-sig
DB in the 3.8 GB VM) — AUDIT §5#3, tagged S10.

**Recommendation: do NOT make the S10 clamd fix a prerequisite for S04E.** The gate's *logic* is
covered by the double (2b); what `ClamAvIntegrationTest` adds is confidence that the *real* clamd is
wired and answers — an **environment** guarantee, not a logic one. S04E can ship its gate green on the
double. **BUT** — this is the sprint where the scanner becomes load-bearing on *real user files of
children's names*, so I propose two hedges short of pulling the whole S10 fix forward:
- **(i)** S04E's EXIT GATE explicitly records that the `batch-csv` gate is proven on the double and
  that **real-clamd verification for this context is an S10 acceptance item** — so it's a named,
  scheduled gap, not a silent one (same honesty as the S04D audit's DIV-1).
- **(ii)** Raise a **decision for you (D-4):** should S04E add a lightweight *startup/health probe*
  that refuses to accept `batch-csv` uploads in an environment where `ClamAvScanner` can't reach clamd
  (fail-closed at the edge), so production can't silently fall back to "pending forever"? Cheap, and it
  turns the S10 infra risk into an explicit 503 rather than stuck batches. I lean **yes**, but it's
  scope you'd be adding — hence a flag, not an assumption.

**Bottom line on Q2:** S04E is testable now; clamd fix is **not** a blocker; keep it S10; add a named
S10 acceptance item + (optionally, D-4) a fail-closed health probe.

---

## 3. Parse safety — malformed file fails safe, never partial-parses (your Q3)

The discipline `create()` already has (row-by-row, defined failure mode) must extend **upstream** to
the parse, with a clean split between **structural** failures (whole-file reject) and **row**
failures (per-row skip/fail with reason):

- **Whole-file reject → batch `Failed`, zero rows to `create()`** (a report, never a partial import):
  wrong/missing/extra columns (header schema mismatch), undecodable encoding (not UTF-8 / bad BOM),
  zero data rows, row count over cap, xlsx that won't open as a spreadsheet, MIME/size already
  refused at `intake()`.
- **Per-row skip/fail with reason** (mirrors `create()`'s buckets): bad email, missing name, empty
  `school_id`, duplicate within the file, roll-authority failure (already `create()`'s job), formula
  cell (see §4). Every non-accepted row carries its reason — the card's P4 "row outcomes NEVER
  silently dropped" and H3 `Skipped(reason)/Failed(reason)` states.
- **No streaming into `create()` before the whole file validates structurally** — parse to an
  in-memory/persisted row set first (dry-run report, card STEP 1), *then* commit. `create()` stays
  the single transaction-per-row sink; the parser never opens a transaction of its own.

**Encoding + normalisation:** enforce UTF-8, strip BOM, trim, cap cell length, reject control chars.
CSV via a strict reader (RFC 4180, explicit delimiter — do not sniff); xlsx via a read-only parser
(see §4). **Drift D-5:** the card names **CSV** only ("CSV upload", "CSV context"); your framing and
this pass name **CSV *and* xlsx**. Decide whether xlsx is in S04E Phase-1 at all (see §4 — it carries
most of the attack surface) or whether S04E ships **CSV-only** and xlsx is deferred. I lean
**CSV-only for S04E**, xlsx as a fast-follow, precisely because of §4.

---

## 4. The file as an attack surface — what's confined (your Q4)

**CSV** is comparatively tame (it's text) but not free:
- **Formula/CSV injection** — a cell starting `= + - @`, or tab/CR, executes when the *exported*
  file is opened in Excel. This is primarily an **output** risk (our reports/exports), but we also
  **neutralise on intake**: reject or prefix-escape any cell whose value begins with `= + - @ 0x09
  0x0D` before it's stored or echoed. Applies to CSV *and* xlsx.
- **Oversize / row-bomb** — capped at `intake()` (byte cap) *and* a row-count cap at parse.
- **Bad encoding** — §3.

**xlsx is a ZIP** and carries the real surface — flag each, with the confinement:
- **Zip bomb** (huge decompressed size / high ratio) → a **decompressed-size cap and entry-count
  cap**, refuse before fully inflating; never trust the compressed size alone.
- **XML external entity (XXE) / billion-laughs** in `workbook.xml` / `sharedStrings.xml` → use a
  parser configured **read-only with entity loading disabled** (no DTD resolution); if the chosen
  library can't guarantee that, don't accept xlsx.
- **Formula injection** — as above, on every cell.
- **Cell/sheet count** — cap; ignore all but the first sheet; ignore macros entirely (`.xlsm` never
  accepted — MIME allow-list excludes it).
- **MIME spoofing** — xlsx's finfo type is often `application/zip`; the `batch-csv` context must
  allow-list deliberately and verify the workbook opens, not trust the extension.

**Confinement summary:** intake caps (MIME+size) → async virus scan (BI-10, §2) → strict parse with
decompression/entity/formula/row/cell limits → rows to `create()`/enrolment. **Recommendation:**
given the above, **S04E Phase-1 = CSV-only** (native, safe, covers the school-roll use case); add
xlsx behind the same gate as a deliberate follow-up with the zip/XXE confinement above. This also
keeps STEP 1 shippable without a new parsing dependency (`composer require` = a STOP item; a CSV
reader may be in-stdlib, an xlsx reader is a new dependency you'd approve).

---

## 5. Anonymous surface? (your Q5)

**No anonymous surface — confirmed, and it must stay that way.**
- The S04D bulk endpoint is `POST /api/school/bulk-students` behind `role:school_admin`. S04E's
  upload endpoint (`POST /api/school/enrolment-batches` or similar) sits in the **same authenticated
  `role:school_admin` group** — a school admin uploads for their own roll; roll authority is still
  enforced **per row** by `create()` (OD-30), not by the endpoint alone.
- The new `batch-csv` upload **context** and the `enrolment_batches` / `enrolment_batch_rows` /
  `consolidated_invoices` tables are **scoped** (system · owning-school admins · ops/finance/audit),
  never public. No public RLS policy is added.
- **`scope.public_context_confinement` must stay green** — S04E adds NO public policy. This is a
  named KEY VERIFICATION for every step (as it was in S04D). Assert it, don't assume it.

---

## 6. Proposed step boundaries + drift register (your Q6)

### Proposed step plan for the intake layer (STEP 1 either D-0 reading; STEP 2/3 shown for reading A)
- **STEP 1 — File intake + scan gate + parse + dry-run (the layer you asked me to plan).**
  New `batch-csv` upload context (MIME+size caps); `POST` upload (auth school_admin) → `intake()`
  → async scan; on `CLEAN`, parse (CSV; xlsx per D-5) with the §3/§4 safety; produce a **dry-run
  report** = per-row disposition (match-existing / new→create / skip(reason) / fail(reason)) with
  **nothing committed**. VERIFY: EICAR file → batch `Failed(scan)`, zero rows parsed (double, §2b);
  hostile CSV (formula cell, wrong columns, oversize, bad encoding) → whole-file reject *and*
  per-row reasons pasted; clean file → dry-run report paste; `scope.public_context_confinement`
  green. **This is where your Q1–Q5 land.**
- **STEP 2 — Commit → new-student rows via `create()`, existing rows matched; then enrol via S04A**
  (reading A only): seat lock (2.7), idempotency (2.8), waitlist (2.18), consent issuance per row;
  resumable, per-row reasons; FR066 exception on batch failure. VERIFY: capacity-boundary batch
  (some Enrolled / overflow Waiting) + re-commit idempotent.
- **STEP 3 — Batch dashboard (H4) + consolidated invoicing (OD-25)** (reading A only): five-branch
  isolation; invoice total = Σ member order lines (OD-18 minor units). VERIFY per card.
- **GATE.** Tests + `--tag=S04E` + all prior + pastes + AUDIT.md (record the §2c S10 acceptance item).

Under **reading B**, STEP 1 stands as-is and STEP 2 shrinks to "commit accounts via `create()` +
report" (no enrolment/orders/invoice), and STEP 3 disappears into a new S04F card.

### Drift register — every drift found
| # | Drift | Card / prior says | Reality / proposal | Who decides |
|---|-------|-------------------|--------------------|-------------|
| **D-1** | **Sprint identity** | Card = "Bulk **enrolment**", Part H, seats+consent+orders+invoice | Your framing = "bulk **intake** feeding `create()`" (accounts only). ~3× scope gap. | **Leo (D-0)** |
| D-2 | `create()` is one of two sinks | "feed `create()`" | Batch also *matches existing* (no create) and *enrols* (STEP 2). Parser needs existing-vs-new routing. | Leo (falls out of D-0) |
| D-3 | Scan is async | card implies "scanned before parsing" | Parse must be job-chained off the `CLEAN` transition, not synchronous in the upload request. | Design (STEP 1) |
| D-4 | Fail-closed clamd probe | (not in card) | Optional edge probe to refuse `batch-csv` when clamd unreachable → 503, not stuck batches. | **Leo** |
| D-5 | CSV vs xlsx | card = "CSV" only; your framing = "CSV/xlsx" | Recommend **CSV-only Phase-1**; xlsx (zip/XXE surface, new dependency) as fast-follow. | **Leo** |
| D-6 | New parsing dependency | (not addressed) | An xlsx reader is a `composer require` = a STOP condition needing your approval; CSV needs none. | **Leo** if xlsx |
| D-7 | S10 clamd fix vs S04E | leftover #3 tagged S10 | S04E does **not** need it (double covers the gate); keep S10, add a named S10 acceptance item + record it in the gate. | Confirm |

### STOP conditions already visible for S04E
- **D-0 is a hard STOP** — the step plan can't finalise until you pick A or B.
- **xlsx ⇒ `composer require`** (D-6) — a STOP per CLAUDE.md §4; I will not add it without your word.
- The `batch-csv` context is a new `config/uploads.php` entry (config, not `.env`) — in scope, no STOP.

---

## 7. What I need from you before STEP 1
1. **D-0:** S04E = full Part H (A, recommended) or re-scope to intake→`create()` only (B)?
2. **D-5:** CSV-only Phase-1 (recommended), or CSV+xlsx now (⇒ D-6 dependency approval)?
3. **D-4:** add the fail-closed clamd health probe for `batch-csv` (I lean yes)?
4. Confirm **D-7:** keep the real-clamd fix at S10, ship S04E's gate on the EICAR double, record the
   real-clamd `batch-csv` check as a named S10 acceptance item.

Nothing is built. On your answers I'll produce the reconciled `SPRINT.md` + a STEP 1 plan, hold for
your review, then build STEP 1.
