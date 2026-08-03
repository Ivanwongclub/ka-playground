# AUDIT KAP-S-MARKETPLACE-A — Marketing section + public catalogue read (the storefront funnel-top)

**Result:** PASS · **Date:** 2026-08-03 · **HEAD at gate:** `2dc5b4e`

> Written by Claude Code at the card's end. Honesty outranks looking good. This is the BUILD audit; the
> in-product surfaces are the marketing editor (admin) and the public catalogue endpoints. Planning:
> `PROPOSED-MARKETPLACE.md` (parent) + `PROPOSED-MARKETPLACE-A-marketing-section.md` (sub-pass) +
> `CARD-S-MARKETPLACE-A.md`, all in this dir.

## 0. Scope

The review-critical, anonymous-surface + new-schema half of the marketplace — the front of the enrolment
funnel (browse → the built register→enrol path). Three steps:
- **STEP 1** — the marketing wizard section + storefront-completeness DEFINITION (`847b8a1`).
- **STEP 2** — the anonymous public catalogue read, the SOLE storefront safety gate (`c26755e`).
- **STEP 3** — the marketing section editor, text-first, imagery deferred (`2dc5b4e`).

## 0.1 THE PIVOT — Option B (marketing gates public listing, NOT publish)

The sub-pass ruled marketing "part of the publish-completeness gate, like consent/fees." At build I
verified from source that `WizardService::publish()` throws on any `preFlight` error, so making
`marketing` a **required** section would block `publish()` for every programme without complete marketing
— and **33 test files + the seed publish via the fixed 9-section loop with no marketing** (each with its
own inline `publishedProgramme()` helper). That would break all 33 and balloon STEP 1 to ~38 files. I
STOPPED and surfaced the interlock rather than churn 33 files or silently reinterpret the ruling.

**Leo ruled Option B — decouple**, correcting the prior ruling: consent/fees gate publish because a
programme cannot **operate** without them (operational prerequisites); marketing is **not** an
operational prerequisite — a programme can legitimately enrol / form teams / take payment without being
in the public storefront. So **marketing-completeness gates whether a programme APPEARS in the public
catalogue, not whether it can publish**. `marketing` is an **optional** wizard section; `publish()` /
`preFlight` are untouched (zero test churn — the full suite proves it: all 33 publish paths still green);
**the storefront gate lives SOLELY in the STEP-2 anonymous read.**

## 1. Files changed

| Path | A/M | Step · Why |
|------|-----|-----|
| `docs/sprints/S-MARKETPLACE/{PROPOSED-MARKETPLACE-A-marketing-section,CARD-S-MARKETPLACE-A}.md` | A | 1 · sub-pass + card |
| `api/app/Services/Programmes/WizardService.php` | M | 1 · optional `marketing` SECTIONS entry + `marketingLanguageGaps()` predicate + saveSection guard |
| `api/app/Services/Reconciliation/Assertions/PublishedProgrammeCompletenessAssertion.php` | M | 1 · grandfathered in-pattern marketing extension |
| `api/tests/Feature/WizardMarketingTest.php` | A | 1 · 4 tests |
| `api/app/Http/Controllers/MarketplaceController.php` | A | 2 · the public catalogue + detail read |
| `api/app/Providers/AppServiceProvider.php` | M | 2 · `throttle:catalogue` limiter |
| `api/routes/api.php` | M | 2 · `GET /programmes`, `GET /programmes/{id}` (anonymous) |
| `api/tests/Feature/MarketplaceCatalogueTest.php` | A | 2 · the 8-branch leak test |
| `web/src/pages/AdminProgrammes.tsx` | M | 3 · marketing `SectionFields`, saveSection error-surfacing, optional-display + brand-colour fixes |
| `web/src/i18n/locales/{en,zh-TC,zh-SC}.json` | M | 3 · trilingual marketing labels + `wizard.statusOptional` |

## 2. Step-by-step verification

### STEP 1 — marketing section + storefront-completeness · `847b8a1`
`WizardService::SECTIONS` gains `'marketing' => ['required' => FALSE, 'depends' => ['basics']]` — an
OPTIONAL section (text rides `wizard_sections.data`, **no migration**). `marketingLanguageGaps($data)` is
the **single shared static predicate** (tagline/category/age_range/duration each EN/繁/简 + a hex
`brand_color`), used by saveSection, the reconcile assertion, and (STEP 2) the public read — never
re-implemented, so gate and read agree by construction. `saveSection` rejects a `complete`-status
marketing save with any language gap (`marketing.language_incomplete`); on a **published** programme
marketing is **editable but not degradable** (cannot save into incomplete / break a language), audited.
`preFlight` / `publish()` are **untouched** — the optional section never blocks publish.
`PublishedProgrammeCompletenessAssertion` is extended **in-pattern (key unchanged, no new assertion,
battery stays 58)** with GRANDFATHERING: a published programme with no marketing row passes (legitimately
not in the storefront); a present marketing row must be complete-trilingual.
```
Wizard Marketing ✔ publish is unaffected by the optional marketing section (the decoupling)
                 ✔ complete marketing save missing a language is rejected
                 ✔ published marketing cannot be edited into incomplete
                 ✔ grandfather no marketing passes present incomplete fails
```
Result: **PASS**

### STEP 2 — the anonymous public catalogue read (SOLE storefront gate) · `c26755e`
`GET /programmes` + `GET /programmes/{id}` — UNAUTHENTICATED (outside the `auth:sanctum` group),
`throttle:catalogue` (new per-IP 60/min limiter). Under Option B this read is the ONLY thing keeping an
incomplete / marketing-less programme out of the storefront. **Filters at query time** — a row is
returned iff `status='published' AND is_template=false AND marketingLanguageGaps(data) === []` (the SAME
STEP-1 predicate, not re-implemented; the reconcile assertion is a BACKSTOP, stated in the docblock).
**No elevation, no RLS**: `programmes` + `wizard_sections` carry no row-level security (verified
`relrowsecurity=f`), so the read joins them directly. **No PII** — joins ONLY those two tables, never
users/enrolments/guardians; no enrolled_count, no capacity (omitted v1). Current/past derived from
`basics.starts_on` — no new state. **Constant-shape**: any non-listable / nonexistent / non-numeric id
returns a byte-identical `404 {"message":"No such programme"}`.
```
Marketplace Catalogue (the leak test — all 8 branches)
  ✔ complete appears + detail 200 (+ current/past split)   ✔ present-incomplete absent + constant not-found
  ✔ grandfathered absent   ✔ draft absent   ✔ published template absent
  ✔ not-found byte-identical across incomplete/grandfathered/draft/template/nonexistent/garbage ids
  ✔ no-PII (forbidden fields + exact key-allowlist)   ✔ catalogue routes throttled
```
Live proof: `curl GET /api/programmes` (no auth) → `200 {"data":[]}` — the demo published programme,
having no marketing row, is correctly ABSENT.
Result: **PASS**

### STEP 3 — the marketing editor (admin wizard, text-first) · `2dc5b4e`
FRONTEND-ONLY (STEP 1 built the gate). A `marketing` `SectionFields` case: the four fields each trilingual
(EN/繁/简) + a brand-colour picker; **imagery DEFERRED** (a note says so). `saveSection` now **surfaces
the server's validation message** (a display-only change — see §4) so the STEP-1 `marketing.language_
incomplete` reaches the admin. Two genuine UX bugs fixed while wiring: (a) the optional section rendered
as Phase-2 "Deferred" and its editor was **disabled** → surfaced as "Optional" with the editor open
(Integration stays deferred); (b) the brand-colour picker **displayed** a default it never saved →
seeded into the draft on open.
```
tsc --noEmit CLEAN · npm run build bundle-budget PASSED · i18n parity 561/561/561
Risk shots: partial save → marketing.language_incomplete (lists missing langs); complete save → green Complete
```
Result: **PASS**

## 3. Assertions registered this card

**None (no runner-count change; battery stays 58).** Marketing-completeness is enforced as an **in-pattern
extension** of the existing `programmes.published_completeness` assertion (same key, grandfathered), not a
new assertion — so `ReconciliationRunnerTest`'s hard-coded count of **58** is unchanged. The card's
guarantees are proven by the 12 STEP-1/2 feature tests, not a new nightly assertion.

## 4. Deviations

| Card / sub-pass said | Actually happened | Why |
|---|---|---|
| Marketing is "part of the publish-completeness gate" (like consent/fees) | **Option B — marketing gates PUBLIC LISTING, not publish** | Making it a required section blocks `publish()` → breaks 33 test files + the seed. Surfaced the interlock; Leo corrected the ruling (marketing is not an operational prerequisite). §0.1 |
| No-PII leak test via forbidden-substring list | Demoted the substring check (it false-flagged the non-PII `enrolment_closes_on`), rely on the **exact key-allowlist** | The allowlist is the real guarantee; the substring check was fragile. |
| STEP 3 = "a marketing editor" | Also fixed **two genuine UX bugs** folded in: optional-section disabled-display; brand-colour displayed-but-unsaved | Both block a real admin from completing marketing; the editor is unusable without them. Display-only `saveSection` improvement (§ verified control-flow-identical) shipped so the gate's message surfaces. |
| Imagery in STEP 3 | **Imagery NOT built — deferred** (Leo's ruling: text-first) | Kept STEP 3 tight; imagery is a second anonymous surface warranting its own review. |

## 5. Leftovers & newly discovered risks

| # | Item | Severity | Proposed home |
|---|------|----------|-----------------|
| 1 | **Imagery** — hero/gallery upload via `uploads.contexts.marketing` (BI-10) + a **new anonymous scan-clean serving route** (a second anonymous surface) | planned | deferred sub-step (its OWN line-by-line review) |
| 2 | **The public catalogue is EMPTY until the re-seed** adds marketing-complete programmes — the demo published programme has no marketing row (grandfathered → absent) | expected | fold into the fresh re-seed |
| 3 | Public UI (catalogue grid + detail + enrol CTA into Model B) consuming the STEP-2 endpoints | planned | **S-MARKETPLACE-B** |
| 4 | Optional `programmes.public_listing` opt-out flag (mirrors `schools.public_listing`) — publish without listing | nice-to-have | later, if wanted |

## 6. Exit gate

```
$ php vendor/bin/phpunit tests/Feature/WizardMarketingTest.php tests/Feature/MarketplaceCatalogueTest.php \
    tests/Feature/ReconciliationRunnerTest.php tests/Feature/ScopeElevationTest.php
OK (22 tests, 140 assertions)

$ php artisan reconcile:run
RECONCILE PASS — 58 assertion(s), 58 passed, 0 failed

$ php artisan test --exclude-group=clamav
Tests:    469 passed (5904 assertions)      # +12 across STEPs 1–2; all 33 publish paths still green

$ cd web && npx tsc --noEmit && npm run build
TSC CLEAN · bundle-budget PASSED · i18n parity 561/561/561
```
**Verdict:** **PASS.** Battery 58/58 (in-pattern extension, no runner bump); suite 469/5904;
tsc/build/i18n parity green; `ScopeElevationTest` green (STEP 2 needs no elevation — the public tables
carry no RLS; STEPs 1/3 add none). Migrations: **0**.

## 7. Invariant check

| BI | Touched? | Evidence |
|----|----------|----------|
| BI-1 (audit INSERT-only) | reused | saveSection audits every section save via the existing audit service |
| BI-6 (consent completeness) | untouched | publish gate unchanged; marketing is decoupled from publish |
| BI-10 (uploads scan-clean) | **not reached** | imagery deferred; STEP 3 ships no upload — when imagery lands it MUST ride `UploadService` |
| Scope elevation discipline | none added | STEP 2 reads no-RLS public tables (no `asSystem`); `ScopeElevationTest` green |
| Anonymous-surface safety | **yes** | STEP 2 is the sole storefront gate — the 8-branch leak test (published+non-template+complete only, constant-shape, no-PII, throttled) |

## 8. Hand-offs forward
- **Imagery** — a deferred sub-step: `uploads.contexts.marketing` via `UploadService` (BI-10) + a new
  anonymous scan-clean serving route; **its own line-by-line review** as a second anonymous surface.
- **S-MARKETPLACE-B** — the public catalogue UI (grid + current/past split + programme detail + the enrol
  CTA into Model B `/register`) consuming the STEP-2 endpoints.
- **Re-seed** — the public catalogue is empty until the fresh re-seed adds marketing-complete published
  programmes; fold marketing into that seed.
