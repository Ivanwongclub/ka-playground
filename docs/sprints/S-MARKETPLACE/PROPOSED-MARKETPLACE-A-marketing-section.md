# PROPOSED — S-MARKETPLACE-A · marketing-section sub-pass (think-first)

> **Plan only — no code, no commit.** The review-critical, anonymous-surface + new-schema half of
> S-MARKETPLACE-A. The parent scoping pass (`PROPOSED-MARKETPLACE.md`, approved) covers the anonymous-read
> shape and the A/B split; this sub-pass resolves the **marketing-data section** and the **publish-gate
> interlock** — the genuinely-new work — **from source**. Rulings already made are folded, not
> re-litigated: marketing = full trilingual section (DECIDED); capacity display omitted v1 (working
> assumption); past = derive from timeline (DECIDED); catalogue+`/p/:id` both, minimal v1 (reviewer rec,
> **still open — Leo's sign-off**, does not block this section's design).

---

## 1. WHERE marketing data lives (the wizard section)

**A new `wizard_sections` row with `section_key='marketing'`** — the same shape as the built `basics` /
`consent` / `team_rules` sections. Verified against `WizardService`:

- **`WizardService::SECTIONS`** is a fixed const registry `key => ['required'=>bool, 'depends'=>[...]]`.
  Adding one line —
  `'marketing' => ['required' => true, 'depends' => ['basics']]` — plugs in **without a rewrite**:
  - `state()` counts it toward `readiness{complete, required}` automatically (it iterates `SECTIONS`).
  - `saveSection()` already validates `array_key_exists($key, SECTIONS)`, so the section becomes saveable;
    it rejects an unknown key today.
  - `preFlight()` iterates `SECTIONS` and emits `section.marketing.incomplete` if a required section is
    not `status='complete'` — so publish is blocked until marketing is complete, for free.
- **Trilingual completeness rule (OD-19).** Every marketing *text* field must carry non-empty **EN + 繁 +
  简**. This is enforced two places, mirroring the existing `consent.language_versions_incomplete` check:
  1. **`saveSection`** — a new per-section validation (like the `basics`/`team_rules` date re-validation
     already there): a `marketing` save with `status='complete'` but any field missing a language →
     `ValidationException` (`marketing.language_incomplete`). A programme can save `status='incomplete'`
     freely while drafting.
  2. **`preFlight`** — a defensive re-check at publish (error finding), so a hand-planted incomplete row
     can never publish.
- **Data shape (the section's `data` JSON):**
  `{ tagline:{en,tc,sc}, category:{en,tc,sc} (or a coded enum + labels), age_range:{en,tc,sc},
     duration:{en,tc,sc}, brand_color:"#RRGGBB", images:{ hero:<upload_ref|null>, gallery:[<upload_ref>…] } }`.
  "marketing-complete" = **every text field present in all three languages + a valid brand_color**.
  Imagery is **optional** for completeness (see §4).

*(No `programmes` table column changes — marketing rides `wizard_sections.data` like every other section.
Zero migration for the text fields.)*

---

## 2. THE BACKFILL INTERLOCK (the critical decision)

**Two enforcement layers, ruled separately — this is the crux.**

**(a) Publish-time gate (preFlight) — HARD, forward-only.** `marketing` is a required section; **every NEW
publish must have complete trilingual marketing**. This is the "part of the publish-completeness gate"
the ruling calls for, applied to all publishes from here on. No existing data is touched by a forward
gate.

**(b) Nightly reconcile — EXTEND the existing assertion, GRANDFATHER existing publishes.** Ruling:
**scope the marketing-completeness requirement so existing published programmes are grandfathered** — do
NOT retroactively fail them. Mechanism, verified against `PublishedProgrammeCompletenessAssertion` (it
loops published programmes collecting failures for consent-template + fee-items):
- Extend that same loop with: *"if a programme HAS a `marketing` section row, it must be complete-
  trilingual; a programme with NO marketing row is grandfathered (predates the section)."*
- **New publishes always pass** (preFlight forced them complete). **Existing publishes (incl. the demo
  seed) have no marketing row → grandfathered → pass.** A published programme with a *present-but-
  incomplete* marketing row fails (catches tampering / half-filled rows).
- **The demo seed stays battery-green with NO backfill and NO migration.** (There are no production
  publishes; the only "existing published" data is the demo seed, which has no marketing row → passes.)
  When the pending fresh re-seed lands, its published programmes will carry marketing anyway (built via
  the new section), so they satisfy the forward path.

**New reconcile assertion vs in-pattern extension — DECIDED: in-pattern extension, NO runner bump (58
stays 58).** Justification: it is the *same* "published programme is complete" concept (consent + fees +
now marketing); `PublishedProgrammeCompletenessAssertion` already iterates published programmes and
appends failures — adding a marketing-completeness failure to that list is a natural extension, not a new
orthogonal invariant. Keeping it as one assertion avoids churn on the `ReconciliationRunnerTest` count
guard (which hard-asserts **58**; a new assertion would force 58→59 + a test edit). The assertion's
`proves()` string is updated to name marketing; the key stays `programmes.published_completeness`.

> **Net for the battery:** 58/58 throughout — the extension grandfathers existing data, so no red at
> ship time, no backfill, no runner-count change.

---

## 3. LOCKED_WHEN_PUBLISHED — does marketing lock?

Today `LOCKED_WHEN_PUBLISHED = ['fees','consent']` (binding governance/money). **Ruling: marketing is NOT
locked — it stays editable post-publish — but its completeness is RE-VALIDATED on every post-publish
edit**, exactly like the built `basics`/`team_rules` date re-validation (`saveSection` re-checks those
even when published). Rationale:
- Marketing is **presentation, not governance** — fixing a typo or refreshing a tagline must not require
  a re-publish (a real product need); consent/fees lock because they are legally/financially binding.
- But the public surface reads marketing, so it must never degrade: an admin **cannot edit a published
  programme's marketing into an incomplete or non-trilingual state** — `saveSection` rejects it (same
  mechanism as the post-publish date guard). Every edit is audited (`saveSection` records an audit event),
  so changes are traceable.
- Result: copy is maintainable **and** the public read never sees stale-incomplete marketing.

*(If review prefers stability-over-agility, the fallback is to add `marketing` to `LOCKED_WHEN_PUBLISHED`
— a one-line change — forcing a re-publish for copy edits. Recommended: editable + re-validated.)*

---

## 4. IMAGERY — the real sub-delta (flagged)

Imagery is **materially bigger than the text fields** and must respect **BI-10**. Verified: `UploadService`
is the single intake path (per-context MIME/size, server-side re-encode dropping EXIF, private pending
store, queued ClamAV, visible only after a clean verdict); `contents()` refuses anything not scanned
clean. **Serving is only via RLS-shaped download routes** (e.g. `/consent-documents/{id}/download`) —
**there is NO anonymous file-serving route today.** So public marketing images need a **new anonymous
serving path for scan-clean images**, which is a genuine interlock (public serving + CSP for a public page).

**Ruling for v1:** **imagery is OPTIONAL and is NOT part of the completeness rule** (marketing-complete =
trilingual TEXT + brand_color). When imagery IS provided:
- it **MUST** ride the built `UploadService` (BI-10) via a new `uploads.contexts.marketing` config
  (image MIME allowlist, size cap) — **never an external URL, never bypassing the scan** (external URLs
  on a public page also break the self-contained/CSP posture);
- it is served to anonymous visitors only through a **new public route that serves ONLY scan-clean images
  of published programmes** — flagged for line-by-line review as a second anonymous surface.

**Recommendation:** ship v1 **text-first** — marketing-complete needs only trilingual text + brand_color,
so the catalogue works with a **brand-color card (no image)** on day one. Treat **hero/gallery upload +
the anonymous clean-image serving route as a flagged sub-step** (S-MARKETPLACE-A STEP 3 or a fast-follow),
so imagery does not balloon the review-critical section+gate work. If the client requires mandatory hero
imagery in v1, that pulls the upload UI + the anonymous serving route into v1 — a bigger card.

---

## 5. THE ANONYMOUS-READ CONTRACT (this section's data)

Restated for the marketing data — the **S04C-analogue anonymous READ** (S04C was the first anonymous
WRITE; this is the read analogue), modelled on the existing anonymous read `GET /register/schools`
(`schools WHERE public_listing=true`, trilingual names only, throttled):

- **Published-only + complete-only.** Returns marketing fields **only** for `status='published' AND
  is_template=false AND marketing-complete` (same completeness predicate as §1/§2). **No draft, no stale,
  no incomplete** — a grandfathered (marketing-less) published programme simply **does not appear**. The
  read filters at query time — it never relies on the battery for safety.
- **Constant-shape / no enumeration.** A programme id that is not published-and-complete returns the
  **same** "not found" as a nonexistent id (like `/pay`, `/register/schools`) — no way to probe draft ids
  or infer state.
- **No PII.** The read joins **only** `programmes` + `wizard_sections` (marketing) — **never** `users`,
  `enrolments`, `guardians`, or `enrolled_count`. Marketing fields carry no personal data by construction.
  (Capacity/"spots left" is omitted v1 per the ruling — team-based model + PII-adjacency.)
- **Throttled** like `/register/schools` (`throttle:registration` or a dedicated `throttle:catalogue`).
- **Read-only**; the only action is the CTA hand-off (anonymous → `/register` Model B; signed-in guardian
  → the built enrol flow). **Flag for line-by-line review at build** — it is a new public surface.

*(Optional, not required v1: a `programmes.public_listing` opt-out flag mirroring `schools.public_listing`,
so an admin can publish a programme without listing it publicly. Note only — status+complete is the v1
gate.)*

---

## 6. Recommended STEP split for S-MARKETPLACE-A

| Step | Scope | Review depth |
|------|-------|--------------|
| **STEP 1 — marketing wizard section + publish-gate interlock** | `SECTIONS` entry (`marketing`, required, depends basics); trilingual completeness validation in `saveSection` + `preFlight`; the **editable-but-re-validated** post-publish rule (§3); the **in-pattern extension** of `programmes.published_completeness` with **grandfathering** (§2), battery stays 58/58. **No migration** (rides `wizard_sections.data`). | **LINE-BY-LINE** (schema-adjacent gate + battery interlock) |
| **STEP 2 — the anonymous published-only read** | `GET /programmes` (catalogue) + `GET /programmes/{id}` (detail), unauthenticated + throttled; returns marketing for `published AND marketing-complete` only; constant-shape not-found; joins nothing personal; the **S04C-analogue** child-safety leak test (published-only, no draft/stale, no-enumeration, no-PII, throttled). | **LINE-BY-LINE** (new anonymous surface) |
| **STEP 3 — marketing section editor (admin wizard) + [flagged] imagery** | The trilingual marketing fields in the programme wizard (so a programme can BE made marketing-complete); brand-color picker. **Imagery is a flagged sub-step**: `uploads.contexts.marketing` via BI-10 + a new anonymous scan-clean serving route — line-by-line if included, else deferred to fast-follow. | **FRONTEND-SCAN** (the editor) · **LINE-BY-LINE** (only if imagery/serving is pulled in) |

**Sequencing note:** STEP 1 must land before STEP 2 (the read's completeness predicate is defined in
STEP 1). STEP 3's editor is needed for anyone to publish a marketing-complete programme, so it precedes a
usable public catalogue — but it is admin-facing (frontend-scan) unless imagery is included. Imagery
(upload + anonymous serving) is the one piece that can, at Leo's call, defer to a fast-follow to keep
S-MARKETPLACE-A tight around the review-critical section + anonymous read.

---

## Decisions this sub-pass fixes (for the card) + still-open

**Fixed here:**
1. Marketing lives in a new `wizard_sections` `marketing` section — one `SECTIONS` const line, no migration
   (text). §1
2. Backfill: **grandfather** existing publishes; **in-pattern extension** of the existing completeness
   assertion (**no runner bump, 58/58**); preFlight is the hard forward gate; demo seed green, no backfill. §2
3. `marketing` is **NOT** locked post-publish, but **re-validated** on edit (editable copy, never
   degradable). §3
4. Imagery **optional v1**, text-first; when present it **rides BI-10** + a **new anonymous clean-image
   serving route** (flagged, deferrable). §4
5. The public read is the **S04C-analogue anonymous read**: published+complete only, constant-shape,
   no-PII, throttled — line-by-line at build. §5

**Still open (Leo's sign-off — does NOT block this section's design):**
- Catalogue **and** `/p/:id` both, minimal v1 (reviewer recommendation) vs `/p/:id`-only.
- Whether **hero imagery is mandatory in v1** (pulls the upload UI + anonymous serving route into v1) or
  optional/deferred (recommended).
- Optional `programmes.public_listing` opt-out flag (nice-to-have, not v1-required).

*No code, no schema, no endpoint in this pass — plan only. Awaiting review; then STEP 1 builds.*
