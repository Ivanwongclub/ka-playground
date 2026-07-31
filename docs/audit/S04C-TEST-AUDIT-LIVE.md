# S04C TEST AUDIT — LIVE (observed against the running instance)

**Executed:** 2026-07-31 against the running local instance (http://localhost:8080, DB `kap`; test DB
`kap_test`). **Method:** every S04C case from the committed test suite enumerated and run **live** —
"PASS-OBSERVED" = the committed case's scenario executed now with an observed result. The
refusal/confinement cases (the point of this audit) were driven directly against the running instance
(live HTTP + `public`-context DB writes + raw cross-connection PDO) and their outcomes pasted.
Nothing inferred. Two divergences are flagged in §6 — not smoothed.

## 0. How the cases were run
- **Behavioural (§1):** the 6 committed S04C test files executed now (they exercise the scenarios end to end).
- **Assertions (§2):** `reconcile:run --tag=S04C` against the live `kap` DB.
- **Refusals/confinement (§3):** live HTTP (`curl` to :8080) + `public`-context DB writes + a raw
  cross-connection PDO race against `kap`.
- **clamd (§5):** the ClamAv integration suite with the daemon healthy (`PING → PONG`).

## 1. Behavioural cases — PASS-OBSERVED (executed now)
| Sub-step · file | Cases | Result |
|---|---|---|
| **S04C-1** · `PublicRegistrationSecurityTest` | confinement green · confinement-reds-on-2nd-table · public-can-insert-submitted · public-cannot-insert-privileged · public-reads-nothing · constant-shape-4-case · honeypot-drops · too-fast-drops · genuine-recorded-as-public · throttle-429 | **10/10 PASS-OBSERVED** |
| **S04C-2** · `RegistrationApprovalTest` | ops-approves-direct-unverified · activation-sets-pw+verifies+login · activation-refused-invalid/burned · decline-requires-reason · school-admin-own-not-another · family-role-refused · school-routed-student→affiliation · direct-student→registered · two-activations-one-wins · **CAS-across-connections** · second-approve-no-2nd-account | **11/11 PASS-OBSERVED** |
| **S04C-3** · `LinkageTest` | road-A-pending-link · road-B-held-link · **typo-scenario** (no materialisation pre-verify, form_claimed after) · FLAG#2-audit-road-A · FLAG#2-audit-road-B · **guardian-cannot-self-activate** · reject-terminal · held-expiry-terminal · activation_audited-teeth · no_unverified_materialisation-teeth | **10/10 PASS-OBSERVED** |
| **S04C-4** · `OnboardingQueueTest` | queue-lists-aged · family-role-refused · escalation-idempotent · escalation_liveness-teeth · provenance-teeth · **two-decisions-two-audit-rows** | **6/6 PASS-OBSERVED** |
| **Gate** · `RegistrationRlsTest` | five-branch: registration_requests · held_links · school_links-affiliation | **3/3 PASS-OBSERVED** |
| **S04C-4 retirement** · `OnboardingTest` | guardian-creates-student-path-is-retired (404) | **1/1 PASS-OBSERVED** |

**41 behavioural cases PASS-OBSERVED** (156 assertions), 0 FAIL, 0 BLOCKED.

## 2. Assertion-guarded cases — live `reconcile:run --tag=S04C`
All five **PASS-OBSERVED** live (after removing the audit's own throwaway fixtures — see §6 divergence 1):
```
PASS  scope.public_context_confinement
PASS  links.activation_audited
PASS  links.no_unverified_materialisation
PASS  queue.escalation_liveness
PASS  account.provenance
RECONCILE PASS — 5 assertion(s), 5 passed, 0 failed
```
(Full battery `reconcile:run` = 44/44.)

## 3. First-class REFUSAL / CONFINEMENT cases — PASS-OBSERVED (live)

**A · Anonymous-write confinement** (the `public` context, live on `kap`):
```
A1 public reads guardian_links: 0 rows   (no enumeration oracle)
A2 public reads users:          0 rows
A3 public INSERT submitted request: INSERTED (allowed — the one thing it may do)
A4 public reads back what it wrote: 0 rows   (write-only, cannot read its own row)
A5 public INSERT status='approved': REFUSED AT DB —
   SQLSTATE[42501]: new row violates row-level security policy for table "registration_requests"
```
Public writes exactly one thing (a pending registration_request), reads nothing, and cannot craft a
privileged row — the escalation is refused **at the database**, not by the app.

**B · FLAG #2 path-independence** (transaction-wrapped on `kap`, rolled back — no pollution):
```
B1 planted an active guardian_link with NO to_state='active' audit → links.activation_audited: RED
   (1 active guardian_link(s) have NO to_state='active' audit event …)
B2 added the to_state='active' audit                                → links.activation_audited: GREEN
B3 rolled back (planted rows discarded; live DB untouched)
```
The assertion catches an active link with no activation audit **regardless of how it activated** —
`WHERE status='active' AND NOT EXISTS(the audit)`. This is the property that caught the seed links.

**C · Activation CAS burn — cross-connection race** (raw PDO on `kap`):
```
C1 activation A claims the token: rows affected = 1
C2 activation B blocks on the row lock while A holds it: BLOCKED (lock timeout)
C3 after A commits, B's identical burn: rows affected = 0   → exactly one activation wins
```

**D · Retirement** — `POST /api/my/students` (unauthenticated): **404** (route removed, gone — not 403).

**E · Pre-activation login refused** — login on an approved-but-unverified account (with the JSON
`Accept` header the SPA sends): **422** (refused; no usable credential until activation).

**F · Guardian cannot self-activate** — a guardian logs in and POSTs to approve **their own** pending
link: **403**; the link stays `pending_approval`. (Reviewer roles only; enforced server-side.)

**G · Counterpart-email four-case oracle** — one byte-identical `202`:
```
registrant-new:     202 | keys=['reference','status'] status=received
registrant-exists:  202 | keys=['reference','status'] status=received
counterpart-new:    202 | keys=['reference','status'] status=received
counterpart-exists: 202 | keys=['reference','status'] status=received
```
Naming an existing account (registrant OR counterpart) is indistinguishable from naming a new one.

**11 refusal/confinement observations PASS-OBSERVED** (A1–A5, B, C, D, E, F, G).

## 4. Concurrency — actual cross-connection test
§3-C above is the real cross-connection race (two raw PDO connections on `kap`): A claims, B **blocks
on the row lock** (observed lock timeout), and after A commits B's identical burn matches **zero rows**.
Exactly one activation wins — structural, matching the paid-link/seat/receipt CAS. **PASS-OBSERVED.**

## 5. clamd suite — green with the daemon healthy
With clamd healthy (`PING → PONG`) and `UploadServiceTest` loaded so the `EICAR` constant is defined,
the ClamAv integration suite is **green** (`real clamd flags eicar` ✓, `real clamd passes clean content`
✓, PDF/JPEG intake+quarantine ✓). The gate's full-suite run (362/362, 0 errored) confirmed the same.
See divergence 2 for the isolation caveat.

## 6. Divergences (flagged explicitly — not smoothed)
| # | Divergence | Class | Detail |
|---|---|---|---|
| **1** | **`account.provenance` RED on first live run** (`--tag=S04C` → 4/5) | **audit self-inflicted, not a product defect** | The 3 flagged accounts (`gc-…`, `selfg-…`, `selfs-…`) were **this audit's own throwaway fixtures**, created via direct `User::create` in the tinker drives — bypassing the approval path that writes the provenance audit. The assertion **correctly** flagged them (it catches ANY origin-less account — a live demonstration that it works, on real pollution). After removing the 3 fixtures: **0 provenance-less accounts, 5/5 GREEN.** The committed behaviour (every account traces to an origin) is upheld; the assertion is not too weak. |
| **2** | **`ClamAvIntegrationTest` fails in ISOLATION** with `Undefined constant "Tests\Feature\EICAR"` | **test-infra, false-red-only (F-1 residual)** | The `EICAR` constant is defined at `UploadServiceTest.php:19` and used by `ClamAvIntegrationTest.php`. In the full suite (or with `UploadServiceTest` loaded) it is defined → green; in isolation it is undefined → error, **before** clamd is even contacted. This is the **same F-1 file-order class** as the `EicarOnlyScanner` double: the S05 chore extracted the *scanner double* to `tests/Support/` but left the `EICAR` *constant* file-scoped in `UploadServiceTest`. **Pre-existing (not S04C); false reds, never false greens.** Recommend extracting `EICAR` to an autoloaded `tests/Support/` const (finishing the F-1 fix). |

**Non-divergences noted for transparency:**
- **Pre-activation login returned 302 without the `Accept: application/json` header** — this is standard
  Laravel web-redirect on validation failure; **with** the JSON header (which the SPA always sends) it is
  **422**. Confirmed both ways. Not a product divergence.
- **clamd is memory-sensitive** in this 3.8 GB VM (it OOMs reloading its 3.2M-sig DB on a cold recreate).
  For this audit it was healthy (`PONG`); the clamd suite is green when the daemon is up.

## 7. Verdict
**S04C test audit: PASS-OBSERVED, live.** 41 behavioural cases (156 assertions) + 5 `--tag=S04C`
assertions + 11 refusal/confinement observations + 1 cross-connection race are all PASS-OBSERVED. The
confinement holds (public writes one table, reads nothing, cannot escalate at the DB), FLAG #2 is a
path-independent proof, the CAS activation admits exactly one winner, the retirement is gone (404), and
a guardian cannot self-activate (403). Two divergences, both **non-product**: the audit's own fixtures
tripping `account.provenance` (which is the assertion working), and the pre-existing F-1-residual `EICAR`
constant isolation issue (false-red-only). Recommend finishing the F-1 fix by extracting `EICAR`.

### Counts
- Behavioural: **41 PASS-OBSERVED**, 0 FAIL, 0 BLOCKED.
- `--tag=S04C` assertions: **5 PASS-OBSERVED**, 0 FAIL (after removing the audit's own fixtures).
- Refusal/confinement: **11 PASS-OBSERVED**.
- Concurrency: **1 PASS-OBSERVED** (cross-connection block-then-zero observed).
- clamd suite: **green** (daemon healthy + `EICAR` defined).
- Divergences: **2**, both non-product (audit-self-inflicted provenance fixtures; F-1-residual `EICAR` const).
