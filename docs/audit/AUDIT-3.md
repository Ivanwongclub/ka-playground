# AUDIT-3 — Phase B closeout

**Scope:** the FAMILY build (B1–B9 + riders + S-READ-3 + S-TTL-1 + SEED-CONTRACT-1) against the
reference set, before Phase C opens. **READ-ONLY** — nothing was built or changed by this audit.

**Reference precedence on conflict:** `docs/reference/*` (4 files) · `docs/design/KAP-Prototype.html`
· **the migrations + policies as source-of-truth**. Where a doc disagrees with source, source wins
and the doc error is a §B finding.

**Freshness assert:** `KAP-Prototype.html` = **387,150 bytes** (≥ 380,000 ✅).
`docs/reference/` holds **exactly four** files ✅.
**Audited at** `4cc6a29` (origin/main, Phase B pushed). Working tree clean.

**Class codes:** CL cleared/deliberate-with-citation · RW read-widen owed · MG missing (buildable,
no blocker) · DU domain-unbuilt · DR decision required · PW prototype-wrong.

---

## §A — DIVERGENCES

### A.1 Surface verdict table

Per family surface: the block row (Doc 2), the built surface, the verdict, and the data-model
column that decides whether a gap is buildable.

| # | Surface | Doc 2 row | Built at | Verdict | Data model |
|---|---|---|---|---|---|
| 1 | **stu-home** | L31 | `StudentHome.tsx` | **PRESENT** — photo hero, HKT greeting, NEXT UP, My programmes; 4 reads → 2 | enrolment list read carries banner/team/consent/next-session (S-READ-2) |
| 2 | **stu-progs** | L32 | `StudentProgrammes.tsx` | **PRESENT** w/ 1 MG (see A.3) | term line needs `ends_at` — writerless |
| 3 | **stu-space** | L33 | `EnrolmentSpace.tsx` | **PRESENT**, 1 CL (band height), 1 CL (six tabs) | tracker read widened S-TRACKER-1 |
| 4 | **stu-explore** | L39 | `Marketplace.tsx` | **PRESENT** (B7 + B9) — fee and 4th filter now real | `fee_total_minor`, `enrolment_opens_at` (S-READ-3) |
| 5 | **stu-progdet** | L39 | — | **MISSING (DR)** — deferred by the B7 ruling | blocked on the marketing content model + fee ruling |
| 6 | **stu-me** | L40 | `Me.tsx::StudentMe` | **PRESENT** (B5 + B9) — "My guardians" now built | `GET /my/guardians` (S-READ-3) |
| 7 | **gua-home** | L48 | `GuardianHome.tsx` | **PRESENT** — action-required list, no hero/trio/golds | 3 reads, all load-bearing |
| 8 | **gua-children** | L64 | `SelfService.tsx::MyChildren` | **PRESENT** (B9) — row CTAs + zero-enrolment children visible | `GET /my/children` |
| 9 | **gua-child** | L66 | `ChildHub.tsx` | **PRESENT**, 1 CL (title-as-link), 2 MG/DU (A.3) | 4 flat reads |
| 10 | **gua-space** | L67 | `EnrolmentSpace.tsx` (guardian arm) | **PRESENT** — 6 tabs incl. Fees (B4) | orders/receipts guardian-gated |
| 11 | **gua-consents** | L68 | `Consents.tsx` | **PRESENT**, 1 CL (two registers + History heading), 1 DU (`Withdraw…`) | signature join (B8), TTL (S-TTL-1) |
| 12 | **gua-sign** | L68 | `Consents.tsx::ConsentSign` | **PRESENT** — three gates server-enforced | BI-6 |
| 13 | **gua-pay** | L69 | `SelfService.tsx::MyPayments` | **WRONG-SHAPE (MG)** — see A.2 | order lines read exists (R1-G) |
| 14 | **gua-me** | L73 | `Me.tsx::GuardianMe` | **PRESENT** (B5 + B9) — linked-children count now real | `GET /my/children` |
| 15 | **gua-reqs** | L72 | — | **MISSING (DU)** — Doc 2 itself says "As-built: API only" | withdrawal chain exists, no UI either edge |
| — | **chrome** | §8 | `AppShell.tsx` | **PRESENT** — nav badges, locale switcher, sign-out ×3 | — |

### A.2 gua-pay — the one family surface with no Phase-B card · **MG · severity: medium**

`SelfService.tsx:321` (`MyPayments`) is an **S-UX3/R1-era composition**, not composed to the
`gua-pay` block (`docs/reference/KAP-PROTOTYPE-WORKFLOW.md:69`). It has the parts — orders,
receipts, the `/api/orders/{id}/lines` read (`SelfService.tsx:46`) — but not the block's grammar:
the PAYABLE card's line table with a **total row beside the reference as a gold VALUE**, and the
two buttons (`Pay online` / `Bank transfer / FPS` → mFps).

**Root cause is scope, not quality: there is no B6 commit.** The Phase-B card sequence runs
B1 · B1R · B2 · B3 · B4 · B5 · B7 · B8 · B9 — **B6 is absent**, and gua-pay is the surface it would
have covered. Nothing is broken; the surface simply never got its composition card.

**Attached, unchanged:** D-6 (`KAP-PROTOTYPE-WORKFLOW.md:70`) — the guardian-side remittance leg is
`Bank transfer / FPS` while B-17 grants remittance to the SCHOOL. Still **unruled**.

### A.3 Remaining element-level gaps

| Element | Where | Class | Severity | Note |
|---|---|---|---|---|
| Programme TERM ("Feb – Jun 2027") | Doc 2 L32/L39 · `Marketplace.tsx` | **MG** | low | `programmes.ends_at` exists and is **writerless** — no `basics.ends_on` anywhere (AUDIT-2 A-1, still open) |
| Consent SIGNED DATE on gua-child | Doc 2 L66 · `ChildHub.tsx:16-18` | **RW** | low | `/api/consent-requests` carries status + expires_at, not `signed_at` (that is on `/api/consent-signatures`). Deliberately not worth a 5th read |
| `[Materials · n]` | Doc 2 L66 · `ChildHub.tsx:19` | **DU** | low | no `session_materials` table, route or read |
| `Request withdrawal…` (family) | Doc 2 L66 · `ChildHub.tsx:20-22` | **DU** | **medium** | API chain exists guardian→school-endorse→ops; **no UI at either edge**. Doc 1A `KAP-SYSTEM-REFERENCE.md:105` records it |
| `Withdraw…` on a signed consent (D1 revocation) | Doc 2 L68 · `Consents.tsx` (0 hits) | **DU** | medium | mRevoke drawn, unbuilt. Atlas L246 lists it as the consent loop's open edge |
| `stu-progdet` | Doc 2 L39 | **DR** | medium | B7 ruling deferred it; blocked on the marketing content model + the fee ruling (now partly unblocked — see §C.4) |
| `gua-reqs` | Doc 2 L72 | **DU** | medium | Doc 2 already marks it API-only |

### A.4 Deliberate divergences — recorded AS deliberate, with citation

These are **not misses**. Each is a ruled departure carrying its reasoning at the site.

| Divergence | Block says | Built | Citation |
|---|---|---|---|
| **Title-as-link, no whole-card click** | whole card clickable (L768) | title is the link; card is not | `ChildHub.tsx:150-156` — a gold `[Sign]` inside a clickable card nests interactives (invalid HTML, ambiguous a11y tree, `stopPropagation` is mouse-only). Same ruling as GUA-FIX and C6 |
| **Six guardian tabs** | block shows fewer | `GUARDIAN_TABS` = journey·fees·team·sessions·tracker·results | `EnrolmentSpace.tsx:55`, `tabBarGutter={12}` at `:515` — B4 ruling; the gutter fix stops the 6th tab collapsing Tracker/Results into an overflow menu |
| **Two registers + "History" heading** | ONE flat `.inbox-item` list — Doc 2 L68 states it outright: *"The list is not a pending-queue: it shows SIGNED history too"* | `Awaiting your signature · n` + `History` | `Consents.tsx:77-84` cites "the block's second visual register (L825-834)". **Accurate at ROW level** (pencil+warn+clickable vs ✓+ok+inert) but the **headings, the count badge and the amber rail card are build-added structure the block does not have.** Ruled deliberate; recorded here so it is never re-found as a miss |
| **`expired` is not "settled"** | — | own pill + honest heading | `Consents.tsx:70-74` (B9-RIDER-3) — closes the exact flag AUDIT/S-TTL-1 raised |
| **No price on un-enrolled drill** | "View ›" drills to progdet | omitted | B7 ruling: no family-facing detail route exists, so the card is not a drill |

---

## §B — DOC ERRORS (source wins; exact line + correction)

### B-1 · `KAP-PROTOTYPE-WORKFLOW.md:40` — **HIGH**
> *"(D-7: guardians list is an ungranted read as-built.)"*

**False since `f0004d1`.** `GET /my/guardians` is served — `api/routes/api.php` (role:student) →
`api/app/Http/Controllers/MyLinksController.php::guardians`, with the AD-2 display-name elevation
registered at `api/config/scope-elevations.php` (verified present, 79 entries).
**Correction:** *"'My guardians' is SERVED — `GET /my/guardians` (S-READ-3 item 1), names for ACTIVE
links only (ruling F-1/F-2). Built on stu-me by B9. The D-7 classification was wrong and is struck."*

### B-2 · `KAP-PROTOTYPE-WORKFLOW.md:204` (§9) — **HIGH**
§9 is titled *"PROTOTYPE ELEMENTS ALREADY OVERRULED BY THE MODEL"* and still lists
`"My guardians" on stu-me`.
**This is the most harmful doc error in the set**: a future card reading §9 would refuse to build a
surface that now exists and is consumed. **Correction:** strike the entry, with a pointer to B-1.

### B-3 · `KAP-SYSTEM-REFERENCE.md:96` (§3.1 STUDENT) — **HIGH**
> *"**Cannot:** sign consent · **see any amount** · see another family/team …"*

**False since `f0004d1`.** `MarketplaceController.php:212` serves `fee_total_minor` to
`['student', 'guardian']`. The S-READ-3 F-3 ruling made the published catalogue price
**family-visible**, students included.
**Correction:** *"Cannot: … see an ORDER amount (P-3/B-18 — a specific family's obligation). A
PUBLISHED CATALOGUE LIST PRICE is marketing and IS visible to students (S-READ-3 F-3)."*
⚠️ This is the P-3 tension flagged in the S-TTL-1/S-READ-3 reports and **still awaiting explicit
confirmation** — the doc line is the place it will be read from, so it should not be corrected
until that confirmation lands. Recorded as **DR**.

### B-4 · `KAP-SYSTEM-REFERENCE.md:105` (§3.2 GUARDIAN Gaps) — medium
> *"… **Me is a placeholder** (real surface = identity + language + notification prefs + pairing ceremony)"*

**False since `b0375b6`.** `Me.tsx::GuardianMe` is built: identity card, language row
(`LocaleSwitcher`), pairing-code redeem modal, and (B9) the linked-children count.
**Correction:** strike "Me is a placeholder"; keep **notification prefs** as the one remaining gap
(DU — no notifications table/route anywhere).

### B-5 · `KAP-DATA-ATLAS.md:73` (programmes row) — medium
Names `syncBasicsDates` as the writer of **`starts_at` only**; `enrolment_opens/closes_at` are
listed with no writer.
**Correction:** *"`enrolment_opens_at` / `enrolment_closes_at` — also written by
`WizardService::syncBasicsDates`, mirrored from `basics.enrolment_opens_on` / `.enrolment_closes_on`
(closes at END of day), SEED-CONTRACT-1. `ProgrammeController` REJECTS both with 422 (`prohibited`,
S-TTL-1 Part B) — the wizard is the sole writer of the whole timeline."*

### B-6 · `KAP-DATA-ATLAS.md:188` (§4.2 Consent state machine) — medium
> `sent --> expired: deadline (school chase escalation OD-50)`

The edge is now **real** (it was aspirational — nothing wrote `expired`), but the annotation is
wrong: there is no school chase. **Correction:** *"`sent|viewed --> expired`: TTL reached —
`ConsentSigningService::expireOverdue`, swept nightly by `consents:expire`
(`api/routes/console.php`, 02:15 HKT). Surfaces in the ops evidence report's `expired` bucket
(`ConsentEvidenceReportController`). Never auto-withdraws (BI-7), never auto-re-issues."*

### B-7 · `KAP-DATA-ATLAS.md:246` (§5 Consent loop) — medium
Open edges listed as *"D1 revocation · media-consent `kind` · student's own read P-4"*. The
**expiry edge is missing from the list** — it was open (writerless column + dead enum value) and is
now closed. **Correction:** add *"expiry edge CLOSED by S-TTL-1 (writer + sweeper + ops bucket)"*
and keep the other three.

### B-8 · `docs/OPEN-DECISIONS.md:19` (OD-11) — medium
> *"7 days, configurable per programme. Expiry releases the seat and runs the 2.18 waitlist promotion."*

The mechanism was superseded: CLAUDE.md records the individual waitlist (Spec E / 2.18) as
superseded by **OD-34** under team-based capacity (**OD-31**) — seats allocate to teams at Team
Formation. Source agrees: `programmes.hold_window_days` is validated
(`ProgrammeController::rules()`) and snapshotted (`:130`) and **read by nothing in `app/`**.
**Correction:** mark OD-11 **OBSOLETE**, cite OD-34/OD-31, and record that `hold_window_days` is a
consumer-less column pending its own retire/keep decision.

### B-9 · no doc records the fee ruling — medium
`fee_total_minor` and the "family-visible" grant appear in **no** reference document
(grep: 0 hits across all four + OPEN-DECISIONS). **Correction:** add to Doc 1A §3.1/§3.2 (subject to
B-3) and to the Atlas's programme/money section, naming the once-per-request elevation
(`MarketplaceController::withFeeTotals`) and that the ANONYMOUS payload carries no money field.

### B-10 · no doc records the S-TTL-1 clamp — low
The TTL and its clamp (`min(issued_at + 14d, starts_at)`, applied only to a FUTURE start) exist
only in code + commit body. Should join the Atlas consent section alongside B-6.

---

## §C — CONFIRMATIONS

1. **Gates green at `4cc6a29`.** `php artisan test` **597 passed, 0 failed, 7266 assertions** ·
   `reconcile:run` **60/60** · client `npm run build` **6/6 PASSED** (i18n, ds2:tokens,
   ds2:import-guard, emoji, marketing-payload, bundle-budget).
2. **The elevation register is accurate.** 79 entries; both Phase-B additions present and
   reason-byte-matched — `MyLinksController::guardians`, `MarketplaceController::withFeeTotals`.
   `ScopeElevationTest` asserts every `asSystem` call site is allowlisted.
3. **Atlas counts verified against source.** "63 migrations" = 63 `.php` files in
   `api/database/migrations/` ✅. "86 `Schema::create` tables" = 86 calls ✅.
4. **The S-READ widens are real and consumed, not shelf-ware.**
   `GET /my/children` → the Marketplace child picker (`Marketplace.tsx:89,109,139`), MyChildren
   rows, gua-me count. `GET /my/guardians` → stu-me. `enrolment_opens_at` → the "Coming soon"
   filter (`Marketplace.tsx:65,214`). `fee_total_minor` → the card price.
   **The register→link→enrol dead-end is closed**: the picker no longer derives children from
   enrolment rows, so a newly-linked child with no enrolment can be enrolled.
5. **RLS descriptions are accurate.** `guardian_links_read`'s two family arms
   (`guardian_id = actor` / `student_id = actor`) are status-blind, as the S-READ-3 map stated;
   names are gated to ACTIVE links on both sides via `app.student_ids` (guardian, unelevated) and
   the AD-2 elevation (student).
6. **`payment_links.single_reader` is true in both senses** — green, AND the anonymous
   `/api/programmes` payload carries no money field (asserted directly, `MarketplaceFamilyFeeTest`).
7. **The demo seeder now walks the real contract.** 3 programme_versions · 3 programme_capacity ·
   3 withdrawal_policies with real HKT-correct windows (all were **0** before SEED-CONTRACT-1).
8. **The i18n gate checks coverage, not just parity** (T-I18N-COMPLETE) — the gap that let B1R
   delete two live keys is closed.

---

## §D — HYGIENE

| # | Item | Evidence | Severity |
|---|---|---|---|
| D-1 | **成團 in EN code/comments — 19 occurrences across 8 PHP files.** Standing rule: EN code/docs never carry 成團 | `TeamConfirmationService.php` ×7 · `ConsentCompleteAtConfirmAssertion.php` ×3 · `CapacityClaimsWholeAssertion.php` ×3 · `WizardService.php` ×2 · `TeamConsentStatusController.php` ×1 · `PaymentObligationCompletenessAssertion.php` ×1 · `DemoSeeder.php` ×1 · `PreviewSeeder.php` ×1 | **medium** |
| | ⚠️ Two sub-classes need care, not a blind sed: **`abort()` strings** (`TeamConfirmationService.php:70,74`) are user-facing API errors, and the **elevation reason** at `TeamConsentStatusController.php:43` is byte-matched against `scope-elevations.php` — changing one without the other throws at runtime. Assertion `proves()` strings render in reconcile output | | |
| D-2 | **Three stale v2-amber literals** — `rgba(251,191,36,…)` hardcoded instead of the `--ka-warning` token | `AdminProgrammes.tsx:399` · `Ds2Gallery.tsx:37` (`WARN`) · `ds2/structure.css:9` (`.ds2-subpanel--action`) | low |
| D-3 | **`#241a12` on-gold literal has no token** — used raw at `ds2/atoms.css:7,12,15` and in `Marketplace.tsx`'s filter chip. Same class as D-2 | | low |
| D-4 | **51 unreferenced i18n keys** — present in all three locales, not statically referenced | `npm run i18n:check` note line | low |
| | ✅ Deliberately **report-only**: dynamic prefixes make deletion unsafe. This is the correct posture after the B1R incident (a namespace-blind regex deleted two LIVE keys and parity did not catch it) | | |
| D-5 | **`useResource` has no request dedupe** — no in-flight map, no cache (`web/src/api/useResource.tsx`). `/consents` fires `/api/consent-requests` **twice** in one load | measured in the B8 rig trace | low |
| D-6 | **`Consents.tsx:203`** renders `'—'` as `deadlineLabel` when `expires_at` is null — a placeholder where the standing rule says omit (the B2 "Due today · EXPIRES —" lesson). Reachable only for pre-S-TTL-1 rows now | | low |
| D-7 | **`ProgrammeBandHeader` uses `--ka-hero-band` (180px)** while Doc 2 specifies **122px** for stu-space (L33) and **112px** for gua-space (L67) | `ds2/tokens.css:92` vs Doc 2 L33/L67 | low |
| D-8 | **`hold_window_days`** — validated, snapshotted, read by nothing. Retire-or-keep decision owed (see B-8) | | low |
| D-9 | **`programmes.ends_at`** — still writerless; blocks the programme TERM line on two surfaces (A.3) | AUDIT-2 A-1, still open | low |
| D-10 | **No enrolment-window enforcement at enrolment time** — the storefront can read `closed` while `POST /my/enrolments` still accepts. Found while mapping SEED-CONTRACT-1; it is also what forced the S-TTL-1 clamp guard | | **medium** |

---

## Headline

Phase B closes with **every family surface composed except two** — `gua-pay` (no B6 card was ever
written) and `stu-progdet` (deferred by ruling) — **all gates green**, and the three functional
dead-ends that opened the phase now closed (enrol-a-newly-linked-child, the consent expiry edge, the
storefront window falsehood).

The work owed is **documentation, not code**: ten §B corrections, of which **B-1/B-2 are urgent**
because they tell a future card that a built, consumed surface is forbidden — and **B-3 must wait**
on the P-3 confirmation rather than be corrected on my own reading.
