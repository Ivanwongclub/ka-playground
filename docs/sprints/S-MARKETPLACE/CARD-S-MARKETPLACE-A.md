# CARD — S-MARKETPLACE-A · marketing section + anonymous published-only read

> The review-critical, anonymous-surface + new-schema half of the marketplace. Planning:
> `PROPOSED-MARKETPLACE.md` (parent, approved) + `PROPOSED-MARKETPLACE-A-marketing-section.md` (sub-pass,
> approved). Rulings folded: marketing = full trilingual `wizard_sections` section (no migration, text
> rides `data`); grandfather existing + in-pattern extension of `programmes.published_completeness`
> (battery stays 58/58); marketing editable-but-re-validated post-publish; imagery optional/text-first;
> the public read is the S04C-analogue anonymous read (published+complete+non-template, constant-shape,
> no-PII, throttled).

**Build order = review order:** STEP 1 (section + publish-gate interlock) → STEP 2 (anonymous read) →
STEP 3 (marketing editor + [flagged] imagery). STEP 1 must land before STEP 2 (the read's completeness
predicate is defined in STEP 1). STEP 2 needs Leo's catalogue-vs-`/p/:id` sign-off (can arrive while
STEP 1 builds).

---

## STEP 1 — marketing wizard section + storefront-completeness definition (LINE-BY-LINE) — DECOUPLED (Option B)

Marketing gates **public listing, not publish** (Leo's ruling — see the REVIEW NOTE). `publish()` /
`preFlight` are **UNCHANGED**; zero churn on the 33 publish paths and the seed.

- **`WizardService::SECTIONS`** — add `'marketing' => ['required' => FALSE, 'depends' => ['basics']]`
  (an **OPTIONAL** section — NOT a publish prerequisite; `preFlight` never emits a marketing error).
- **Data shape** (`wizard_sections.data` JSON, no migration): `{tagline,category,age_range,duration}` each
  `{en,tc,sc}` + `brand_color` (hex) + `images:{hero:<upload_ref|null>, gallery:[<upload_ref>…]}` (imagery
  **optional, NOT part of completeness**).
- **Trilingual completeness DEFINITION** (not a publish gate) — `WizardService::marketingLanguageGaps(data):
  string[]`, shared by saveSection + the assertion: the four text fields each need non-empty EN + 繁 + 简;
  `brand_color` a valid hex. This DEFINES "marketing-complete" for the STEP-2 public read to filter on.
- **`saveSection`** — a `status='complete'` marketing save with any language gap → `ValidationException`
  (`marketing.language_incomplete`). **Editable-but-re-validated post-publish:** on a non-draft programme,
  a marketing save must stay complete-trilingual (cannot downgrade to incomplete / break a language);
  audited like every section save. **NOT** added to `LOCKED_WHEN_PUBLISHED`. This validation does **not**
  block publish — it only governs the marketing section's own save.
- **`PublishedProgrammeCompletenessAssertion`** — extend the existing loop **in-pattern (no new assertion,
  key stays `programmes.published_completeness`, 58/58)**, GRANDFATHERING: a published programme with **no**
  marketing row passes (not in the storefront — legitimate); a programme **with** a marketing row must be
  complete-trilingual, else fail (catches a half-filled/tampered row). `proves()` updated to name marketing.

**Mandatory STEP-1 tests:**
1. **Grandfather red-green:** published + NO marketing row → PASS (grandfathered, not in storefront);
   published + present-but-incomplete marketing row → FAIL. (Distinguishes "opts out of storefront" from
   "has it but broke it.")
2. **saveSection trilingual rejection:** complete-status save missing any language → `marketing.language_incomplete`.
3. **Post-publish re-validation:** editing a published programme's marketing into incomplete → rejected.
4. **publish() UNAFFECTED (proves the decoupling):** a programme with NO marketing still publishes cleanly —
   the 9-section publish loop works untouched, no new preFlight error.
5. **Battery 58/58** (in-pattern extension, no runner bump); `ReconciliationRunnerTest` count guard
   unchanged (58); `ScopeElevationTest` green (no new elevation).

> Condition-2-from-the-original-card (publish blocked without marketing) is **DELIBERATELY DROPPED** under
> B — replaced by test 4. **The storefront gate now lives SOLELY in the STEP-2 public read**, so STEP 2's
> leak test is the single safety gate and gets **extra scrutiny**: a marketing-incomplete / marketing-less
> programme must be **provably absent** from the public read.

**VERIFY:** the five tests green; battery 58/58; suite green; migrations = 0 (text rides `wizard_sections.data`);
no screenshots (backend gate, editor is STEP 3). Diff to `~/Downloads`, held for review.

## STEP 2 — the anonymous published-only read (LINE-BY-LINE, **EXTRA SCRUTINY**) — *needs Leo's catalogue-vs-`/p/:id` sign-off*

> **Under Option B the storefront gate lives SOLELY here** — publish no longer gates on marketing, so this
> read is the single safety boundary. Its leak test must prove a marketing-incomplete AND a marketing-less
> published programme are both **absent** from the public read, plus draft/template absence, constant-shape,
> and no-PII. This step is the decoupling's safety net; review accordingly.

- `GET /programmes` (catalogue) + `GET /programmes/{id}` (detail), **unauthenticated + throttled**
  (`throttle:registration` or a dedicated limiter), modelled on `GET /register/schools`.
- Returns marketing fields **only** for `status='published' AND is_template=false AND marketing-complete`
  (same predicate as STEP 1); a grandfathered (marketing-less) programme **does not appear**.
- **Constant-shape** not-found for draft / incomplete / nonexistent (no enumeration). **No PII** — joins
  only `programmes` + `wizard_sections`, never users/enrolments/guardians; capacity/"spots left" omitted v1.
- Its own **child-safety leak test** (published-only, no draft/stale, constant-shape, no-PII, throttled) —
  the S04C-analogue anonymous read.

## STEP 3 — marketing section editor (FRONTEND-SCAN) + [flagged] imagery (LINE-BY-LINE if pulled in)

- The trilingual marketing fields + brand-color picker in the programme wizard (so a programme can be made
  marketing-complete). S-UX2a display kit; trilingual; darkAlgorithm.
- **Imagery — DEFERRED (Leo's ruling, this build):** STEP 3 ships the editor **text-first, WITHOUT
  imagery** (brand-colour card only) — the public catalogue works on trilingual text + brand_color. The
  upload + anonymous image-serving route is a **separate sub-step, NOT built here**: `uploads.contexts.
  marketing` via the built `UploadService` (BI-10) + a **new anonymous scan-clean serving route** (a
  second anonymous surface) — never external URLs, never bypassing the scan. It gets its **own
  line-by-line review** if/when wanted; not in S-MARKETPLACE-A.

## Constraints / invariants
- **No migration** (text rides `wizard_sections.data`). **Battery 58/58** (in-pattern extension).
- Marketing NOT in `LOCKED_WHEN_PUBLISHED`; re-validated on post-publish edit (never degradable).
- STEP 2 read is a NEW anonymous surface — published+complete only, constant-shape, no-PII, throttled.
- Imagery, if present, rides BI-10; no anonymous surface serves an unscanned file.

## Definition of done
STEP 1 gate + 5 tests green (grandfather red-green, forward gate, trilingual rejection, post-publish
re-validation, battery 58/58); STEP 2 anonymous read + leak test; STEP 3 editor (+ flagged imagery);
each backend step reviewed line-by-line; suite + build green. AUDIT.md at the end.

---

## REVIEW NOTE (STEP-1 interlock — needs Leo's ruling before STEP-1 code)

Verified from source: `WizardService::publish()` throws on any `preFlight` error. Making `marketing` a
**required** section therefore blocks `publish()` for any programme without complete marketing. **33 test
files** each publish via the inline 9-section loop (`basics…certification`, no marketing) + their own
`publishedProgramme()` helper, and `database/seeders/PreviewSeeder` publishes the same way. So the forward
gate (test condition 2) breaks all 33 on publish. The sub-pass's "one const line, no rewrite" is true for
the mechanism but under-weighted this consequence. Options (Leo to rule):
- **(A) Pay the churn — marketing gates PUBLISH (ruling as written):** add a complete marketing section to
  all 33 helpers (via a shared `completeMarketing()` test helper) + the seed. STEP-1 diff ≈ 38 files; the
  4-file gate logic is line-by-line, the 33 test edits are mechanical.
- **(B) Decouple — marketing gates PUBLIC LISTING, not publish:** marketing NOT required for `publish()`
  (programmes operate without it); the STEP-2 public read filters `marketing-complete`, so marketing gates
  the storefront, not operation. Zero test churn; arguably cleaner separation — **but relaxes condition 2**
  (publish stays open). The seed + 33 tests are untouched; the reconcile check becomes "published AND has a
  marketing row → complete" (grandfather still applies).

**Recommendation:** (B) is cleaner and churn-free, but reinterprets the approved ruling; (A) honors the
ruling at a 33-file cost. Held for Leo.

**RULING (Leo): OPTION B — decouple.** Marketing gates PUBLIC LISTING, not publish. This **corrects the
prior "part of the publish-completeness gate" ruling**: consent/fees gate publish because a programme
cannot OPERATE without them (operational prerequisites); marketing is NOT an operational prerequisite — a
programme can legitimately enrol / form teams / take payment without being in the public storefront. So
marketing-completeness gates whether a programme APPEARS in the public catalogue, not whether it can be
published. `marketing` is therefore an OPTIONAL wizard section; `publish()`/`preFlight` are untouched
(zero test churn); the storefront gate lives solely in the STEP-2 public read (which gains extra scrutiny);
the reconcile assertion grandfathers marketing-less publishes and enforces "present ⇒ complete-trilingual".
