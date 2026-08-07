# KAP — Remaining-Build Plan (authoritative sequence)

> **Planning / documentation only — no code, no sprint work.** The single authoritative view of what is
> left to build and in what order. Folds together the three-band synthesis, `KAP-SCOPE-GAPS.md` (gaps
> A–D), `docs/sprints/S-MARKETPLACE/PROPOSED-MARKETPLACE.md`, and Leo's rulings. The sequence was
> **verified against the real code** and corrected where the ordering missed or invented a dependency
> (§4) — the same discipline that corrected the Marketplace premise.

**Status anchor:** S-UX3-3a STEP 1–3 are built + reviewed (`c57d2e4`, `263b941`, `f293cd1`). STEP 4
(below-min / resolution) is the **next actual build step**, after this plan is reviewed.

> **Built SINCE this plan was written (2026-08-03) — no longer pending:**
> - **#5 Member surfaces → ✅ DONE** (S-UX3-8: events · RSVP · directory · profile).
> - **#6 sessions / attendance → ✅ DONE** (S-UX3-4). *Its **Learn-gate view remains TO BUILD** — it owns the
>   `/learn` stub (see below).*
> - **gap-C guardian/teacher self-service → ✅ DONE** (S-UX3-9: My Children · My Payments · My Students).
>   *(This plan folded gap-C into S-UX4; it shipped separately as S-UX3-9.)*
>
> **Build-first stubs (owned here; cross-referenced from `DS2-RESTYLE-ROLLOUT.md`):** the DS2 restyle rollout
> does NOT restyle placeholder routes — it defers them to this plan, to be built **DS2-native**:
> - **`/tracker`** → **micro item #11** (Activity Tracker UI).
> - **`/learn`** → the **Learn-gate remainder of #6** (sessions/attendance shipped; the Learn view did not).

---

## 1. The sequence at a glance

| Band | Order | Item | One-line scope | Think-first owed? | Risk flag |
|---|---|---|---|---|---|
| **1 — finish in-flight** | 1 | **S-UX3-3a STEP 4** | below-min / resolution UI (assign · extend-grace · waive · dissolve · school-leave); close card + AUDIT | **N** (card approved) | money-adjacent (mints obligations), authority |
| **2 — close the funnel** | 2 | **S-MARKETPLACE-A** | anonymous published-only programmes read **+ new `marketing` wizard section** (part of publish-completeness) | **Y — focused sub-pass** (marketing section) | **ANON READ + child-safety-adjacent** |
| | 3 | **S-MARKETPLACE-B** | public landing (catalogue) + programme-detail UI + enrol CTA into Model B | **N** (covered by PROPOSED-MARKETPLACE) | **ANON surface** |
| | 4 | **S-UX3-3b** | student formation UI (create/join lobby, my-team, submit-for-成團) | **Y** (no PROPOSED yet) | student-facing; own-team RLS |
| **3 — doorless surfaces + admin (by severity)** | 5 | **Member surfaces** (gaps A+D) — ✅ **DONE (S-UX3-8)** | events · RSVP · directory · profile | — | PII (directory); invitation-only |
| | 6 | **S-UX3-4** — ✅ **DONE** (sessions · attendance); **Learn gate TO BUILD** (owns `/learn`) | sessions · attendance · Learn gate | Learn: **Y** | **child-safety** (minor attendance) |
| | 7 | **S-UX3-5** (**HELD**) | team-project finance UI (budgets · transactions · fundraising) | **Y — heavy standalone** | **MONEY + child-safety RLS** (users_read co-member widening) |
| | 8 | **S-UX3-6** | school portal — lobby 成團 (gap B widening) + bulk intake (S04E door) + invoicing (S04F door) | **Y** | **MONEY** (invoicing); authority-widening |
| | 9 | **S-UX3-7** | capabilities admin (grant / revoke) | **Y — light** | **SECURITY/AUTHORITY** (grants power) |
| | 10 | **S-UX4** | teacher + school_admin accounts + **fresh re-seed** (folds gap B teacher-nav, gap C guardian/teacher self-service, provisioning gap) | **Y — light** | provisioning; account creation |
| | 11 | micro | Activity Tracker UI · Profile · audit label-resolver | Tracker **Y**; Profile/label-resolver **N** | low |

---

## 2. Band rationale

- **Band 1** finishes the card already in flight — don't leave S-UX3-3a half-open.
- **Band 2 closes the enrolment funnel end-to-end.** The journey is
  **Marketplace → register → enrol → consent → 成團 → pay**. *Everything downstream of enrol is already
  built*; the missing piece is the **front of the funnel** (a public way to discover programmes and start
  enrolling). Marketplace-A/B build that front; S-UX3-3b completes the "form a team" step. This is the
  coherence-critical, re-ordered part.
- **Band 3** clears the doorless surfaces (built engines with no UI) and the remaining admin, **by
  severity**: the Member role being entirely dark is the biggest doorless gap, then learning, then the
  held team-finance, the school portal, capabilities, accounts, and micro items.

---

## 3. Per-item detail (scope · dependencies · think-first · risk)

**1 · S-UX3-3a STEP 4 — below-min / resolution.**
Scope: the four OD-37 terminal actions + school-leave (OD-62) over the built matching/capacity reads, each
refusal enumerated. Deps: the built `TeamResolutionService` + matching + capacity-report reads (all
present). Think-first: **N** — card is approved (CARD-S-UX3-3a STEP 4). Risk: money-adjacent (`assign`
mints an obligation), academy-operations authority.

**2 · S-MARKETPLACE-A — anonymous read + marketing section.**
Scope: a dedicated **published-only, constant-shape, throttled** public programmes read (`GET /programmes`
+ `/programmes/{id}`), modelled on the existing anonymous read `GET /register/schools`; **plus a new
`marketing` wizard section** (trilingual tagline/category/age-range/duration/imagery/brand) that is **part
of the publish-completeness gate** (Leo ruling 1). Deps: built publish gating + `WizardService::SECTIONS` +
`PublishedProgrammeCompletenessAssertion` (extend, don't fight — §4-C). Think-first: **Y, focused** — the
existing PROPOSED-MARKETPLACE covers the read + A/B split, but the **marketing-section detail** (data
shape, trilingual completeness validation, completeness-gate wiring, post-publish lock, backfill of
existing published programmes) needs a short sub-pass before carding, at S04C review level. Risk:
**anonymous READ + child-safety-adjacent** (published-only, no PII, no enumeration).

**3 · S-MARKETPLACE-B — public UI.**
Scope: the public catalogue landing (grid + current/past split + category filter) + programme-detail page
(hero/stats/CTA/share) + the enrol CTA wiring (anonymous → `/register`; signed-in guardian → built enrol
flow). Deps: **A** (the read + marketing data); built onboarding (Model B) + enrolment engine. Think-first:
**N** — covered by PROPOSED-MARKETPLACE; card directly after A. Risk: **anonymous surface** (public,
unauthenticated pages).

**4 · S-UX3-3b — student formation.**
Scope: student create/join a lobby, name + submit a team for 成團, my-team view. Deps: the built formation
engine (`/my/teams`, `/teams/{id}/join|submit`, `role:student`), which needs an **`in_pool` enrolment +
eligible lobby** — both built. **NOT dependent on Marketplace** (see §4-A). Think-first: **Y** — no PROPOSED
yet (the S-UX3-3 pass deferred it). Risk: student-facing, own-team RLS; 成團 downstream re-checks consent
(built).

**5 · Member surfaces (gaps A + D).**
Scope: events list + RSVP (`/events`, `/events/{id}/rsvp`, `/my/rsvps`), directory (`/directory`), profile
(`PUT /my/profile`) — all built (S06), all doorless. Deps: needs a **member account fixture to build/demo/
test** — none is seeded (§4-B). Think-first: **Y** — no card/PROPOSED. Risk: PII (a member directory);
invitation-only (OD-1/22).

**6 · S-UX3-4 — sessions / attendance / Learn gate.**
Scope: session schedule, attendance marking (mentor/ops), student book/cancel, Learn-threshold view. Deps:
built S06 engine (sessions, bookings, attendance, learn gate). Think-first: **Y**. Risk: **child-safety**
(attendance records of minors).

**7 · S-UX3-5 — team-project finance (HELD).**
Scope: budgets · transactions + evidence · fundraising · finance-report UI over the built S07 engine.
Deps: S07 (built). **HELD** — a heavy standalone think-first: exposing team-project finance to co-members
implies a **`users_read` co-member widening** (child-safety RLS change), which does **not** move forward
casually. Think-first: **Y — heavy, RLS-critical**. Risk: **MONEY + child-safety RLS**.

**8 · S-UX3-6 — school portal.**
Scope: lobby-team 成團 for school_admin (**folds gap B gate-widening**), bulk CSV intake (S04E door),
consolidated invoicing (S04F door). Deps: S04E/S04F engines (built); the OD-39 authority already admits
lobby school_admin server-side. Think-first: **Y**. Risk: **MONEY** (invoicing); a deliberate authority-
widening (gate B).

**9 · S-UX3-7 — capabilities admin.**
Scope: grant/revoke academy capabilities (`/admin/capabilities/grant|revoke`, `capabilities.grant`,
super-only). Deps: built endpoints. Think-first: **Y — light**. Risk: **SECURITY/AUTHORITY** (grants power;
every grant is audited).

**10 · S-UX4 — accounts + re-seed.**
Scope: teacher + school_admin accounts and their nav (**folds gap B teacher-nav + gap C guardian/teacher
self-service**), plus a fresh re-seed. Deps: none blocking. **Note the pull-earlier tension:** its re-seed
is *also the demo-data fix* (S-UX3-2/3-3a await a fresh seed; teacher/school_admin are unseeded), so if a
client demo needs teacher/school_admin populated it has a pull earlier than its severity slot. Think-first:
**Y — light**. Risk: provisioning; account creation.

**11 · micro.** Activity Tracker UI (think-first **Y** — Plan/Design/Learn/Pitch/Launch over the built
stage-gate hooks) · Profile (**N**, small cross-role) · audit label-resolver (**N**, micro: friendly labels
for raw audit codes). Risk: low.

---

## 4. Verification corrections (beyond transcription — the real-code check)

**A. S-UX3-3b is technically INDEPENDENT of Marketplace — its Band-2 placement is narrative, not a
dependency.** Verified `FormationService::create`: it needs only a **pooled (`in_pool`) enrolment** +
**eligible lobby** — both reached through the *already-built* enrol + consent + lobby engines, none of
which is Marketplace. Marketplace is the *pre-enrolment* funnel (browse → register); formation operates on
students who are *already enrolled and consented*. So 3b has **zero code dependency on Marketplace** and
could ship any time after STEP 4 (e.g. right after roles STEP 3, completing the team-formation story).
**Recommendation:** keep 3b in Band 2 for journey coherence, but record it as **unblocked** — it may be
pulled forward if the team-formation story is prioritised over the public front door.

**B. Member surfaces (Band 3, first) has a fixture dependency the severity-ordering missed.** The `member`
role is invitation-only and **no member account is seeded** (DB confirms 0 members; `PreviewSeeder` creates
none). Building — and especially demoing/screenshotting — the member UI needs a member account, and the
re-seed lives later in Band 3 (S-UX4). **Recommendation:** the Member-surfaces card **seeds its own member
account** via the built `InvitationService` member path (cheap), rather than soft-blocking on S-UX4. Flag
for Leo: without that, Member-surfaces is coupled to S-UX4's re-seed despite ranking first by severity.

**C. Marketplace-A's marketing section EXTENDS the built wizard — no destructive collision, but confirms A
is heavier, and adds one interlock.** Verified: `WizardService::SECTIONS` is a **fixed const registry**;
adding `'marketing' => ['required' => true, 'depends' => ['basics']]` plugs into readiness/preflight
automatically, and `PublishedProgrammeCompletenessAssertion` extends in its established shape (consent +
fee-item → + marketing-complete). So it's the **established extension pattern, not a rewrite** — but it is
a real backend + wizard-UI change, which **confirms Leo ruling 2** (A is heavier; run it *after* STEP 4,
not in parallel). **New interlock to flag:** making marketing part of publish-completeness means **existing
published programmes (demo/test) would retroactively fail** the gate for lacking marketing data — so the
card must either **scope the new gate to publishes after the section ships** or **backfill marketing data**
(and decide whether `marketing` joins `LOCKED_WHEN_PUBLISHED` or stays editable post-publish for copy
tweaks). Whether the new completeness check is a **new reconcile assertion** (bumps the battery 58→59,
requires the `ReconciliationRunnerTest` count update) or an **extension of the existing** one is a card
decision.

---

## 5. Think-first ledger (what PROPOSED is owed before carding)

| Item | PROPOSED status |
|---|---|
| S-UX3-3a STEP 4 | ✅ done (CARD approved) — build directly |
| S-MARKETPLACE-A | ⚠️ **partial** — PROPOSED-MARKETPLACE covers the read + A/B; **owes a focused marketing-section sub-pass** (S04C-level review) |
| S-MARKETPLACE-B | ✅ covered by PROPOSED-MARKETPLACE — card after A |
| S-UX3-3b | ❌ **owed** — student-formation think-first |
| Member surfaces | ❌ **owed** — think-first (incl. the member-account seed, §4-B) |
| S-UX3-4 | ❌ **owed** — sessions/attendance think-first |
| S-UX3-5 | ❌ **owed — heavy** — the users_read co-member RLS widening (HELD until this is done) |
| S-UX3-6 | ❌ **owed** — school portal (gate-widening + S04E/S04F doors) |
| S-UX3-7 | ❌ owed — light |
| S-UX4 | ❌ owed — light |
| micro (Tracker) | ❌ owed — light |

---

## 6. Marketplace open decisions (Leo's rulings applied)

| # | Decision | Ruling | Status |
|---|---|---|---|
| 1 | **Presentation data** | **FULL marketing section** — a new trilingual `marketing` wizard section (tagline/category/age-range/duration/imagery/brand), **part of the publish-completeness gate** (a published programme must have complete marketing in all 3 languages, like consent-template + fee-item). | **DECIDED** |
| 2 | **A vs STEP 4 timing** | Marketplace-A starts **after** S-UX3-3a STEP 4 closes — **not in parallel** (the full marketing section made A heavier). | **DECIDED** |
| 3 | **Public capacity display** | **Omit for v1** — the team-based capacity model (seats claimed at 成團, not enrolment, OD-31) makes "spots left" misleading and PII-adjacent. | **Working assumption — reviewer lean, STILL OPEN** (revisit if the client wants a progress signal; if kept, derive from `programme_capacity.claimed`, never per-enrolment). |
| 4 | **Past programmes** | **Derive from the timeline** (`basics.enrolment_closes_on`/`starts_on` past) — **no new lifecycle state**. | **DECIDED** |
| 5 | **Catalogue vs `/p/:id`-only** (delegated to this doc) | **Recommendation: build BOTH — a public catalogue landing (list) AND `/p/:id` detail pages — for v1.** Reasoning: the client framing is a "main/landing page listing programmes" (a catalogue), and the **full marketing-section investment (ruling 1) only pays off with a browsable catalogue** — `/p/:id`-only would strand that data behind shared links. Keep the v1 catalogue **minimal** (published grid + current/past split + category filter); `/p/:id` is the deep-link/share target. | **STILL OPEN — needs Leo's sign-off.** |

---

## 7. Provisioning / re-seed note

S-UX4's re-seed is **also the demo-data fix**: S-UX3-2 and S-UX3-3a await a fresh by-hand re-seed, and
`teacher`/`school_admin` have no seeded accounts. Two consequences already surfaced: **(a)** Member
surfaces needs a member account (§4-B) — seed it in that card, don't wait; **(b)** if a client demo needs
teacher/school_admin populated, S-UX4's seed portion has a **pull earlier** than its Band-3 severity slot.
Recommend Leo fold the outstanding re-seed (S-UX3-2/3-3a) into whichever of these lands first.

*No code, no cards created. Plan only — awaiting review. STEP 4 is the next build step.*
