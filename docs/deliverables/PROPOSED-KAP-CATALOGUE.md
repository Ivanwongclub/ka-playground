# PROPOSED — KAP Features & Test Catalogue (Excel) + Presentation (PPT)

> **THINK-FIRST plan. No Excel, no PPT, no code generated yet.** This document is reviewed before
> generation because a client-facing "built & tested" artifact must not overclaim. Once approved, the
> deliverable is two files (A: Excel catalogue, B: PPT deck) built by reading the **real committed repo**
> — the test suite, the sprint AUDITs, and the screenshots that already exist on disk. Nothing is written
> from memory; nothing is fabricated; every claim carries an evidence type.

Scope note: this is a **documentation build over the real repo**. It does not touch `api/` or `web/`
application code, adds no migration, and needs no commit to `main` beyond this plan and (once approved)
the two generated files under `docs/deliverables/`.

---

## 0. Honesty posture (the spine of this deliverable)

The single biggest risk in a "features & tests" catalogue for a platform like KAP is **conflating three
very different maturity states** and letting a deck imply the whole platform is live and screen-complete.
The real state, from the inventory pass, is:

- A **large, tested backend engine** (S00–S07): enrolment, consent, money, teams, invoicing, team
  finance, learning — all with automated tests and reconciliation assertions.
- A **growing but partial UI** (the S-UX phase): six of the eight client pillars now have real screens;
  **two pillars (School-Settled Invoicing, Team-Project Finance) have no UI at all yet**, plus bulk
  enrolment (S04E) and the entire S06 learning layer are backend-only.

The catalogue's job is to present the genuine strength (a rigorously tested engine + a real, safe UI for
the money/consent/team-formation core) **without blurring** the backend-only reality. Every mechanism
below exists to keep that line visible. See §7 (honesty rules) and §8 (where honesty makes the deck look
weaker, and how we hold the line anyway).

---

## 1. Environment & tooling constraint — READ FIRST (a real gating flag)

The task asked me to read `/mnt/skills/public/xlsx/SKILL.md` and `/mnt/skills/public/pptx/SKILL.md` and
note what they constrain. **Those files do not exist in this environment** — there is no `/mnt/skills`
tree, and no bundled `xlsx`/`pptx` skill under the session's `bundled-skills` directory. In addition, the
generation libraries are **not currently installed**: `python3` is present but `openpyxl`, `python-pptx`,
and `libreoffice`/`soffice` are all absent.

**Implication for the reviewer:** the plan below specifies *what* the two files will contain and *how*
they stay honest, but **the generation step is blocked on tooling** that must be provided first. Before
generation I will need one of:
- the actual `xlsx` and `pptx` skills mounted (preferred — so I follow their exact constraints), **or**
- `pip install openpyxl python-pptx` (and optionally LibreOffice for formula recalculation / PPT→PDF
  preview) so I can build the files directly.

Because I could not read the real SKILL files, I will **not** invent their specific rules. The
constraints I list in §5.3 are the *conservative, generally-true* ones for these formats; every one is
marked **"re-verify against the actual SKILL at generation."** If the skills are mounted at generation
time and they contradict anything here, the SKILL wins and I will flag the delta before building.

This is itself a STOP-style dependency: **do not expect the two files from this pass** — this pass yields
the plan only, and the generation is a separate, tooling-gated step.

---

## 2. The eight category pillars & the real feature inventory

Categories are **domain pillars, not sprint numbers** (as instructed). Source: `docs/sprints/*/AUDIT.md`
(all marked Result: PASS), `CLAUDE.md`, `docs/OPEN-DECISIONS.md`, and the real React tree
(`web/src/pages`, `nav.tsx`, `main.tsx`). Each feature carries its **sprint provenance** and an
**evidence type**: `UI` = has a real React screen; `Backend` = tested engine, **no screen yet**.

### 2.1 Enrolment & Consent — **HAS UI**
| Feature | What it does (plain) | Provenance | Evidence | Ref |
|---|---|---|---|---|
| Consent e-sign engine | Scroll-to-end, affirmation, drawn/typed capture, signed PDF + certificate page | S03 | UI (`Consents.tsx`, `/consents/:id`) | BI-6, OD-20 |
| Language-scoped consent versioning | Each language's consent hashes against its **own** SHA-256 template version | S03 | Backend (proven in tests) | BI-6, OD-20 |
| Consent evidence bundles | Auditor view of signed consents + certificate/evidence | S03 | UI (`ConsentEvidence.tsx`) | BI-10 |
| Consent template admin | Create/version/publish trilingual templates; placeholder-text flag | S02B/S03 | UI (`AdminConsentTemplates.tsx`) | OD-20 |
| Enrolment as intent | Enrolment is intent, not a seat; consent gate blocks it | S04A | UI (`Enrolments.tsx`) | OD-31, OD-34 |
| Awaiting-a-team pool | Consented, unteamed students wait in a pool (no individual waitlist) | S04A | UI (`EnrolmentPool.tsx`) | OD-34 |
| Consent re-issue on guardian activation | A newly-activated guardian link re-issues the needed consent request | S-FIX-consent-reissue | Backend | 2.30 |
| Programme configuration | Capacity, consent flags, hold window, fees, deadlines | S02B | UI (`AdminProgrammes.tsx`) | OD-10, OD-11 |
| Withdrawal workflow | `Withdrawn` reached only through the audited withdrawal path | S04A | UI (`Withdrawals.tsx`) | BI-7 |
| **Bulk enrolment (Part H)** | Batch-enrol many students | S04E | **Backend — no UI yet** | OD-31 |

### 2.2 Team Formation (成團) — **HAS UI**
| Feature | Plain | Provenance | Evidence | Ref |
|---|---|---|---|---|
| 成團 confirmation + seat claim | Confirm a team, claim seats under a row lock, no partial claim | S05 | Backend engine (driven by UI) | BI-3, OD-31, OD-32 |
| Deadline & matching machinery | in_pool→teamed / park / release at the formation deadline | S05 | Backend | OD-33, OD-35 |
| Approval routing | Who may confirm a team (lobby school-admin / academy ops·super) | S05 | Backend | OD-39 |
| Ops 成團 view | Submitted-teams queue + team detail + enabled/advisory confirm, refusals rendered | S-UX3-3a | UI (`Teams.tsx`, `/team`) | OD-39 |
| Per-member consent-status read | Booleans/counts only, **no guardian identity** leaves the endpoint | S-UX3-3a | UI + Backend | OD-57/58, BI-6 |
| Roles / tenure ledger | One active holder per role; tenure history | S05 | Backend (UI is S-UX3-3a STEP 3, pending) | OD-15 |

### 2.3 Financial Integrity (family payments) — **HAS UI**
| Feature | Plain | Provenance | Evidence | Ref |
|---|---|---|---|---|
| Orders, gapless receipts | Receipt numbers gapless, assigned inside the issuing transaction | S04B | Backend | BI-2 |
| Immutable order lines | Corrections are new records (credit notes/refunds), never edits | S04B | Backend | BI-5 |
| Manual payment record→confirm | Record a school-settled/offline payment with evidence, then confirm | S04B | UI (`Payments.tsx`) | BI-9, BI-10 |
| Segregation of duty (SoD) | Recorder ≠ confirmer on manual payments/refunds — server + DB enforced | S04B | UI + Backend | BI-9, OD-14/47 |
| Refund settlement | Approve ≠ confirm refund payout | S04B | UI (`Refunds.tsx`) | BI-9, 2.17 |
| No partial payments | Underpayment is not recorded at all; no splitting/allocation | S04B | Backend | OD-5/5a |
| Payment provider interface | MockProvider now; QFPay Phase 2; HKD, integer minor units | S04B | Backend | OD-46, OD-18 |
| Financial-integrity report | The client-facing money-integrity audit screen | S04B/UX | UI (`FinancialIntegrity.tsx`) | BI-2/5/9 |

### 2.4 Onboarding & Linkage — **HAS UI**
| Feature | Plain | Provenance | Evidence | Ref |
|---|---|---|---|---|
| Auth (Sanctum behind interface) | Login/session; Logto arrives S11 | S01 | UI (`Login.tsx`) | — |
| RBAC capability groups | Academy-admin capabilities (super/config/finance/operations/audit_read) | S02A | Backend | OD-17 |
| Self-registration + approval | Students/guardians self-register; approval creates the account | S04C | UI (`Register.tsx`) | OD-23 |
| Email verification per account | Every self-registered account verifies its own email | S04C | UI (`Activate.tsx`) | OD-29 |
| Derived account states | Registered→Active derived from approved links | S04C | Backend | OD-28 |
| Two-decision linkage | Approving a person ≠ approving a relationship (separately audited) | S04D | UI (`Approvals.tsx`) | 2.30, OD-23/27 |
| School bulk student creation | Schools create students directly in bulk | S04D | Backend | 2.30 |
| Access & identity report | Who-can-see-what, provenance of accounts/links | S04D | UI (`AccessIdentity.tsx`) | OD-28 |

### 2.5 School-Settled Invoicing — **NO UI (backend-only)**
| Feature | Plain | Provenance | Evidence | Ref |
|---|---|---|---|---|
| Consolidated invoicing | School is **payer, never collector**; all money received by the academy | S04F | **Backend — no UI yet** | OD-25 |
| Invoice aging / no silent overdue | Every overdue receivable is flagged with an audit | S04F | **Backend — no UI yet** | OD-55 |

### 2.6 Team-Project Finance — **NO UI (backend-only)**
| Feature | Plain | Provenance | Evidence | Ref |
|---|---|---|---|---|
| Record-only team budgets | Budget approval; money moves offline, platform records | S07 | **Backend — no UI yet** | FR061 |
| Verified transactions + evidence | Every verified transaction has a scan-clean evidence upload; verifier ≠ recorder | S07 | **Backend — no UI yet** | FR061, BI-10 |
| Charity fundraising guardrails | Charity money never distributed to a team member | S07 | **Backend — no UI yet** | OD-4 |

### 2.7 Anonymous & Child-Safety Surfaces — **HAS UI**
| Feature | Plain | Provenance | Evidence | Ref |
|---|---|---|---|---|
| Forwardable initials-only payment link | A guardian-forwardable `/pay/:token` page showing initials only, no PII | S04B | UI (`PublicPay.tsx`) | OD-44 |
| Anonymous-INSERT registration | Public forms insert under RLS with constant-shape responses (no enumeration) | S04C | UI (`Register.tsx`) | OD-23 |
| Coded consent-status (no identity) | Ops see consent booleans/counts; guardian ids never leave | S-UX3-3a | UI (`Teams.tsx`) | OD-57/58 |
| Scope isolation (deny-by-default) | A user is refused another family's/school's data (RLS FORCE) | S02A/cross | UI (visible as 403/absence) | BI-8 |

### 2.8 Admin Cockpit (UX) — **HAS UI**
| Feature | Plain | Provenance | Evidence | Ref |
|---|---|---|---|---|
| App shell + role-aware nav | Single source-of-truth nav, server-mirrored permission gates, dashboard | S-UX1 | UI (`Dashboard.tsx`, `nav.tsx`) | OD-17 |
| Shared display kit + fetch convention | Consistent money/date/status/name rendering; no silent blank pages | S-UX2a/2b | UI infra | OD-18/19 |
| Audit viewer | Append-only audit log surface for the client | S00 | UI (`AdminAudit.tsx`) | BI-1/8 |
| Trilingual i18n | EN + 繁中 + 简中, no hardcoded strings | S00 | UI (cross-cutting) | OD-19 |

**Out of the eight pillars but built:** the entire **S06 Learning layer** (activation, sessions,
attendance, Learn gate, assessment, Member event/RSVP/directory surfaces) is backend-built with tests but
maps to **none** of the eight given pillars and has **no screen**. The plan will add a short honest
footnote row rather than silently drop it (see §8).

---

## 3. Deliverable A — the Excel catalogue (3 sheets)

### 3.1 Sheet "Features"
One row per built feature, grouped by the eight categories above (a Category column + visual grouping).
**Columns:** `Category` · `Feature` · `What it does (plain language)` · `Evidence type` · `Sprint
provenance` · `Key invariant / OD ref`.

`Evidence type` is a **controlled vocabulary of exactly three values** (the honesty lever):
- `Automated test` — a real screen/flow backed by ≥1 automated test in the suite.
- `Manual UAT` — a UI path that exists but whose verification is a manual click-through (no automated
  coverage). Marked as such; never shown as automated.
- `Backend-only-no-UI-yet` — engine is built and tested but there is **no React screen**.

(Content for the Features sheet = §2 tables, with the Evidence-type column resolved per row against the
test suite in §4. A feature can be both automated *and* backend-only — e.g. 成團 seat-claim is
`Automated test` at the engine and its UI is separate; the row states the engine's evidence and a UI
note.)

### 3.2 Sheet "Test Cases" — pulled FROM THE REAL SUITE
**Columns:** `Category` · `Test ID / name (actual method)` · `Steps (Given/When/Then or numbered)` ·
`Expected result` · `Source`.

- `Source` is either `automated: <RelativePath>::<method>` (e.g.
  `tests/Feature/ManualPaymentTest.php::test_self_confirm_refused_server_side_and_at_the_database`) **or**
  `Manual UAT`.
- Automated rows are generated by the extraction method in §4 — the Steps/Expected describe **what the
  code actually does**, not an idealized case.
- Manual-UAT rows are added **only** for UI paths the automated suite does not cover (§4.3), each clearly
  tagged `Manual UAT`.

### 3.3 Sheet "Coverage summary"
Per category, honest counts: `# features` · `# automated tests` · `# manual UAT` · **`# backend-only-no-
UI-yet`**. Plus a totals row. The backend-only column is a first-class number, not a footnote — it is the
truth the deck must carry.

Indicative totals from the inventory (exact per-category numbers finalized at generation by reading each
file's docblock):
- **70** Feature test files, **449** automated Feature test methods (+1 Unit example = the 450 the suite
  reports green). **58** reconciliation assertions in the nightly battery (a separate, second proof layer).
- **6 of 8** pillars have a real UI screen; **2 of 8** (School-Settled Invoicing, Team-Project Finance)
  are backend-only; plus bulk enrolment (S04E) and all of S06 are backend-only.

---

## 4. Test-suite extraction method (no invented assertions)

The Test Cases sheet is **derived from the committed files**, not written from memory. Method:

**4.1 Enumerate.** The real inventory is already taken: 70 files under `api/tests/Feature/*.php` +
`api/tests/Unit/*.php`, 449 `public function test_*` methods. The generation reads each file and lists its
methods. (The proposed **file → pillar** assignment is in §4.4; final assignment is confirmed by reading
each file's class docblock, which states its domain.)

**4.2 Turn each method into a row WITHOUT inventing.** For each `test_*` method I extract, in order:
1. the **method name** (already a behavioral sentence, e.g.
   `test_teamed_member_with_unsigned_requires_all_guardian_is_a_blocker`);
2. the **leading comment / class docblock** (the tests are heavily commented with intent + the OD/BI ref);
3. the **actual arrange→act→assert calls** — the `postJson/getJson`, the seeded fixtures, and every
   `assert*` (`assertOk`, `assertForbidden`, `assertStatus(422)`, `assertSame`, `assertDatabaseHas`,
   `assertStringContainsString('row-level security')`, …).

The row's **Steps** = the arrange+act rendered as Given/When/Then; the **Expected result** = *literally
what the assertions assert*. I quote status codes and the asserted facts. Where a method makes many
assertions, the Expected cell summarizes the asserted outcomes and I keep the `Source` pointer so a
reviewer can open the exact method. **No expected result is stated that the method does not actually
assert.** If a method is too intricate to fairly compress, the row says "see method for full assertion
set" plus the headline asserted outcome — it never paraphrases into a stronger claim.

**4.3 Manual-UAT rows (added sparingly, tagged).** Some UI paths are only partially covered by automated
tests (the automated suite proves the *server* behavior; the *rendered* UI is proven by our Playwright
risk-shots and manual click-through, not by the PHPUnit suite). For those I add a small number of
explicit `Manual UAT` rows — e.g. "成團 confirm modal shows the truthful advisory over a blocking member"
— sourced to the **screenshot** that evidences them (§6), never presented as an automated pass. Candidate
Manual-UAT areas: the money mutation screens' rendered error surfaces, the 成團 drawer, the public-pay
initials-only render, the trilingual locale switch. Each is a *render* claim the PHPUnit suite doesn't
make.

**4.4 Proposed file → pillar mapping (extraction scaffold; confirmed by docblock at generation).**
- **Enrolment & Consent:** ConsentSigning, ConsentDocument, ConsentTemplate, ConsentReconsent,
  ConsentReissueOnGuardianActivation, ConsentHardening, ConsentEvidenceReport, Enrolment,
  EnrolmentActivation, Wizard, ProgrammeConfig, ProgrammeEntity, Withdrawal, WithdrawalPolicy,
  WithdrawalBookingCascade.
- **Team Formation (成團):** Formation, TeamConfirmation, FormationDeadline, DeadlineMatching,
  TeamResilience, TeamConsentStatus, TeamListNames, S05GateAssertions, RolesTracker, LearnGate,
  BookingAttendance.
- **Financial Integrity:** ManualPayment, PaymentObligation, PaymentLink, OrderReceipt, RefundSettlement,
  PayerWire, FinancialIntegrityReport.
- **Onboarding & Linkage:** Onboarding, OnboardingQueue, RegistrationApproval, LinkingFlows, Linkage,
  LinkStateMachine, VouchVisibility, BulkStudentCreation, BootstrapSuperAdmin, Authz, AuthLifecycle,
  SessionLifecycle, Throttling.
- **School-Settled Invoicing:** InvoiceIssuance, InvoiceAging, EnrolmentBatchIntake, EnrolmentBatchCommit,
  EnrolmentBatchDashboard.
- **Team-Project Finance:** Budget, Transaction, CharityFundraising, S06GateAssertions, FinanceReport.
- **Anonymous & Child-Safety Surfaces:** PublicRegistrationSecurity, RegistrationRls, ScopeIsolation,
  ScopeElevation, SystemActor, AuditEndpointSecurity, MemberSurfaces.
- **Admin Cockpit (UX):** DisplayNames, AuditSpine, ReconciliationRunner, UploadService, ClamAvIntegration.

(A handful of files legitimately touch two pillars — e.g. `TeamConsentStatus` is both Team Formation and
Child-Safety. Each test is listed **once**, under its primary pillar, with a cross-ref note; it is never
double-counted in the coverage totals.)

---

## 5. Deliverable B — the PPT deck (exec summary + technical appendix)

### 5.1 Exec section (screenshots-forward)
- **Title + one-line what-it-is** (KAP: enrolment, consent, money, team & finance for Armour Academy).
- **The three pillars of the story:** child safety · financial integrity · multi-party workflow.
- **What's live vs building** — one honest slide: a 2-column "Live UI / Tested engine (UI next)" map of
  the eight pillars (green tick where a screen exists, "engine ready" where backend-only). This slide is
  the deck's honesty anchor.
- **The demo story** — a short narrative walked through **real screenshots**: register → consent sign →
  enrol → 成團 (with the advisory + refusal) → family payment (BI-9 pair) → refund → public initials-only
  pay. Each step is a shot that exists on disk (§6).

### 5.2 Technical appendix (the rigor story)
- **Coverage summary** (the Excel sheet 3 numbers as a chart/table).
- **Invariant register** BI-1..BI-10 (from §2 refs) — the guarantees, plain-language.
- **The reconciliation battery** — 58/58 assertions green; what a nightly reconciliation proof means.
- **Elevation discipline** — RLS FORCE + the allowlisted `asSystem` + `ScopeElevationTest` (every
  elevation is enumerated and reason-matched). The child-safety privacy tooth on the consent read.

### 5.3 What the two SKILLs constrain — **conservative; re-verify against the real SKILL at generation**
(The actual SKILL.md files were not readable here — §1. These are the standard, format-level constraints
I will hold to, each to be reconciled with the mounted skill before building.)
- **Excel:** build with a real spreadsheet library (openpyxl per the usual `xlsx` skill), not hand-rolled
  XML. Freeze the header row; set column widths; use a data-validation dropdown for the `Evidence type`
  controlled vocabulary; wrap long text. Keep one deliverable per workbook, ≤ a few thousand rows (we are
  ~449 + features + summary ≈ well within limits). No live formulas needed beyond the Coverage counts;
  if formulas are used, recalculation may require LibreOffice (flagged).
- **PowerPoint:** build with the usual `pptx` skill / python-pptx; **embed each screenshot from its real
  file path** (no external URLs, no fabricated mockups); down-scale to slide width preserving aspect;
  keep the deck to a **sensible exec length (~12–18 slides) + appendix**, not one-slide-per-screenshot
  sprawl. Alt-text each image. Any category with no screenshot gets a text "No UI yet — tested engine"
  block, never a placeholder image that implies a screen.

---

## 6. Screenshot inventory — what REALLY exists on disk (paths), and pillar coverage

Real captured screenshots found (no fabrication; these are the only visuals the deck may use):

**A. Committed, in-repo — `docs/screenshots/`** (14):
`01-SCOPING-wendy-enrolments-4-kids.png`, `02-wendy-consent-list.png`,
`03-wendy-consent-signing-flow.png`, `04-audit-enrolment-pool.png`,
`05-audit-consent-evidence-bundles.png`, `06-finance-financial-integrity-report.png`,
`07-super-programmes.png`, `08-super-consent-templates.png`, `09-super-audit-log.png`,
`10-SCOPING-wendy-DENIED-financial-integrity.png`, `11-super-access-identity.png`,
`S04C-01-register.png`, `S04C-02-public-pay.png`, `S04C-03-activate.png`.

**B. Committed, in-repo — `docs/sprints/S03/screenshots/`** (8): trilingual consent-signing gate states
(`sign-en-gate1..3-unmet`, `sign-en-signed`, `sign-zh-TC-…`, `sign-zh-SC-…`, `admin-templates-en/zh-TC`).

**C. S-UX3-2 money shots** (5) — in `~/Downloads/KAP-S-UX3-2-REVIEW-20260802-235356/screenshots/`
(mirror in this session's scratchpad): `BI9a-recorder-confirm-403.png`,
`BI9b-second-person-confirm-success.png`, `P1-payments-list.png`, `R1-refunds-list.png`,
`R3-refund-confirmed.png`.

**D. S-UX3-3a STEP 2 shots** (4) — `~/Downloads/step2-shots/` (mirror in scratchpad):
`step2-1-blocking-advisory.png`, `step2-2-422-rendered.png`, `step2-3-clean-success.png`,
`step2-4-guardian-403.png`.

**Pillar → visual coverage:**
| Pillar | Has a real screenshot? | Which |
|---|---|---|
| Enrolment & Consent | ✅ | A (01–05, 08), B (all S03) |
| Team Formation (成團) | ✅ | D (all four STEP-2 shots) |
| Financial Integrity | ✅ | C (all five), A (06) |
| Onboarding & Linkage | ✅ | A (register, activate, access-identity) |
| Anonymous & Child-Safety | ✅ | A (public-pay, scoping-denied), D (guardian-403) |
| Admin Cockpit (UX) | ✅ | A (audit log, programmes, templates, access-identity) |
| **School-Settled Invoicing** | ❌ | **none — "No UI yet"** |
| **Team-Project Finance** | ❌ | **none — "No UI yet"** |

The two ❌ pillars will be rendered with an explicit **"No UI yet — engine built & tested"** slide/cell,
never an implied screen. (Note C/D live under `~/Downloads` and scratchpad, not committed to the repo; at
generation I will copy the exact files the deck embeds into `docs/deliverables/assets/` so the deck is
self-contained and reproducible — no live external path dependency.)

---

## 7. Honesty rules (stated, binding on generation)

1. **Every Features row carries an evidence type** from the three-value vocabulary — no blanks.
2. **Backend-only-no-UI-yet is labelled, everywhere** — in the Features sheet, the Coverage summary (its
   own column), and the deck (the "live vs engine" slide + the two ❌ pillars). The S00–S07 engine is real
   and tested, and that is exactly how it is shown — **tested engine, UI pending** — never "done" with an
   implied screen.
3. **Manual UAT is never shown as automated.** Manual rows are tagged `Manual UAT` and sourced to a
   screenshot or a stated click-through, not to a PHPUnit method.
4. **No screenshot is fabricated or mocked.** The deck uses only the files in §6. A pillar with no shot
   says "No UI yet." No placeholder image ever stands in for a screen that doesn't exist.
5. **Test rows assert only what the code asserts** (§4.2). Method names and real `assert*` calls are the
   source; nothing is upgraded into a stronger claim.
6. **Counts are honest**, including the uncomfortable one (backend-only). The 449 automated methods and
   58 reconcile assertions are stated as what they are (two distinct proof layers), not merged into a
   bigger vanity number.

---

## 8. Where full honesty makes the deck look weaker — and how the plan holds the line

- **"Most of the engine has no UI yet."** Six of eight pillars *do* have screens now, but two client
  pillars (invoicing, team finance) and the whole S06 learning layer are backend-only. **Handling:** we
  don't hide it — we **reframe it truthfully as sequencing, not absence.** The "live vs engine" slide
  shows the engine as *built and tested*, with UI as the current S-UX phase's job. The strength (a
  rigorously tested core you can trust before the screen exists) is the honest, and genuinely
  impressive, story. We do **not** pad the deck with backend-only pillars dressed as finished features.
- **S-UX3-3a has no `AUDIT.md`.** The 成團 UI shipped (screen + tests + our risk-shots) but its sprint
  card has no AUDIT yet. **Handling:** the catalogue sources it from the CARD + the committed code + the
  real screenshots, and a footnote states "AUDIT pending" rather than implying a closed sprint.
- **S08–S11 are unbuilt (SPRINT.md only).** Recognition/badges, notifications/reporting, hardening/UAT,
  Logto migration. **Handling:** they appear **only** if a "Roadmap / not yet built" section is wanted;
  by default they are **excluded** from the "built & tested" catalogue entirely, so nothing unbuilt can
  read as built.
- **Screenshots C/D are not committed to the repo** (they live in Downloads/scratchpad). **Handling:**
  copy them into `docs/deliverables/assets/` at generation so the deck is self-contained and the evidence
  is reproducible.

---

## 9. Proposed output layout (once approved)

```
docs/deliverables/
  PROPOSED-KAP-CATALOGUE.md         ← this plan (committed now)
  KAP-Features-Test-Catalogue.xlsx  ← Deliverable A (3 sheets)   [generation, tooling-gated]
  KAP-Presentation.pptx             ← Deliverable B (exec+appendix) [generation, tooling-gated]
  assets/                           ← the exact screenshots the deck embeds (copied from §6)
```

---

## 10. Open questions for the reviewer (blocking generation)

1. **Tooling (the §1 gate):** mount the real `xlsx`/`pptx` skills, or approve
   `pip install openpyxl python-pptx` (+ optional LibreOffice)? Generation cannot start until one is in
   place.
2. **S06 learning layer** (built, tested, but outside the eight pillars, no UI): include as a ninth
   "Learning (engine)" honesty row, or omit? (Default: a single honest footnote, not a pillar.)
3. **S08–S11 roadmap:** exclude entirely (default), or include a clearly-labelled "Not yet built —
   roadmap" appendix slide?
4. **Manual-UAT depth:** keep manual rows to the few render-claims our screenshots evidence (default), or
   enumerate a fuller manual UAT script per UI screen (larger, still tagged manual)?
5. **Deck length:** target ~12–18 exec slides + appendix (default), or a tighter ~10?
6. **Audience:** is this deck for the client (Armour Academy / Kings Network) as-is, or does it also serve
   as Leo's internal build-status review? (Affects how much of the rigor appendix leads vs trails.)

**No Excel, no PPT, no code generated in this pass. Awaiting review of this plan.**
