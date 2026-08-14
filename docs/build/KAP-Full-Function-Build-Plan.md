# KA Playground — Full-Function Build Plan

> **Framing: this is the full product, not a demo.** The new design kit (DS2 v3) and the prototype are the visual contract — every surface is built to them exactly (violet-black canvas, ambient-glow glass chrome, gold accent, glance cards / journey steppers / consequence sheets, the enrolment-centric record pages). **Ruling applied: keep the proven backend/RLS, rebuild only the UI to the kit.** The expensive safety-tier work (RLS, four-eyes, elevations, the enrolment pipeline, the embargo) is *kept and reused*; the design kit governs the presentation layer only.
>
> **Verified built-state (from `git log origin/main..HEAD` + source):**
> - **Pushed & live (7 sprints):** the RLS spine, consent, teams, sessions, assessments, finance/four-eyes, entity pages, mentor access, the **DS2 v2** restyle. 493 tests green, reconcile 58/58. **27 page surfaces exist** (listed in §3).
> - **Held, not pushed (1 commit, 47a7360):** KAP-MKT-1 marketplace (catalogue + guardian-led enrolment). Proven, green.
> - **Not built:** everything else in the backlog (authority/delegation layer, missing-halves, new record-page compositions, the v3 surfaces).
>
> **Standing review gate on every card** (unchanged): map → ruling → HELD build → line-by-line diff → registered elevations char-matched to `scope-elevations.php` → RLS proof → behaviour-sha → reconcile 58/58 → push. Tiers: **[CS]** child-safety · **[FIN]** finance · **[RLS]** RLS-touching · **[UI]** presentation-only · **[CFG]** config.

---

## The shape of the build

Four phases. The ordering principle: **establish the target style once, then never build off-style again.**

```
PHASE 0  Foundation — DS2 v3 tokens + primitives + app chrome        (build the style ONCE)
PHASE 1  Authority & delegation spine + enrolment-centric record model (the structural core)
PHASE 2  UI-rebuild pass over the 27 proven surfaces → v3 grammar     (keep backend, re-skin)
PHASE 3  New-capability cards (missing-halves, consent/money, completeness) — backend + UI, in v3
```

Phases 1–3 interleave in practice (a new record page in Phase 1 may pull a rebuild from Phase 2), but the dependency is strict: **Phase 0 lands before any React is written to the new kit**, because it defines the tokens and primitives every later card consumes.

---

## PHASE 0 — Foundation: build the style once  [UI, no RLS]

Nothing here touches the backend. It replaces the DS2 v2 presentation layer with v3 and builds the shared primitives so every subsequent surface is composed, not hand-styled.

| # | Card | What it delivers |
|---|---|---|
| P0-1 | **DS2 v3 token swap** | `theme.ts` → the v3 `kaColors` (violet-black `#0F0D14`, gold `#E0A83B`, `foregroundSoft`, new `pending`, dividers-only `border`), `kaCategoryAccents`, the AntD `token`/`components` config (outline-ban, radii 10/6, type 32/24/18/14). `tokens.css` mirror. The drift-gate (`ds2-tokens-check`) enforces parity. One commit, done once — supersedes the live palette. |
| P0-2 | **App chrome** | The ambient-glow background (4 radial layers), the **glass sidebar + header** (zero-fill, backdrop-blur, hairline dividers), the collapse handle, per-role footer (staff: env + version + session timer + logout; family: last-sign-in + timer + logout). The `position:fixed`/backdrop-filter containing-block fix noted in the kit. |
| P0-3 | **Shared primitives** (the component specs) | `Ds2SegBar` (labeled 5-segment), **Glance card**, **Journey stepper** (gold ink, dated knots, stat tiles), **Status pill** (filled tint, dot+word, the status vocabulary), **Consequence sheet / irreversible modal** (reason capture, swipe-disabled), **Board** (read-only occupancy, no drag), **Composition editor** (drag-to-stage + button-equivalent → request), **Action-required list**, **mobile bottom-sheet** wrapper, **elastic search** (entitlement-scoped), **notification drawer/sheet**. Each built to the kit's §3 spec. |
| P0-4 | **Record-page anatomy shell** | The Lightning-style shell restated under the iff-rule: header band + highlights strip + main(spine)/rail(associations) + history-card-iff-audit_read. The reusable frame every Phase-1/2 record page fills. |

**Why first:** after Phase 0, every card is *composition*, not styling — a screen is "glance cards + a journey stepper + a consequence sheet," not bespoke CSS. This is what makes the UI-rebuild pass (Phase 2) fast and consistent.

---

## PHASE 1 — Authority spine + enrolment-centric record model  [CS, RLS]

The structural core from the re-think. Backend + UI together, in v3. Safety-first order.

| # | Card | Tier | Notes |
|---|---|---|---|
| A-1 | Delegable-capability catalogue (code + drift probe) | [CS] | The safety spine; nothing delegable-that-shouldn't-be can slip after this. |
| A-2 | `school_authority_grants` + `programme_authority_overrides` tables | [RLS] | Per-school baseline + per-programme override. |
| A-3 | Session-context resolver → `app.capabilities` | [RLS] | Edge grants into the existing GUC the policies already read. |
| A-4 | Delegated RLS arms (one domain/card: teams → team_members → stage_gates → sessions → assessments → registration → withdrawal) | [CS][RLS] | Additive OR-clauses; behaviour-sha untouched arms; RIDER-1 per role. |
| A-5 | Formed-team write carve-out (edge writes only forming/submitted; platform-only once confirmed) | [CS][RLS] | The one non-money exclusive. |
| A-6 | Migrate `mentor_team_access` → an override row; deprecate column | [RLS] | |
| A-7 | Delegation-config screen (platform-exclusive, on School record; renders only delegable caps) | [CFG][UI] | The human face of the authority model. |
| R-1 | **Enrolment-centric record pages** — Student 360 → enrolment cards → Enrolment record (the pivot); Programme record; School record; Guardian record; Teacher record | [UI] | Built on P0-4 shell; the compositions from the proposal (Part C). This is the redesign's heart — the CRM record pages, iff-shaped. |
| R-2 | **Family scoped-programme space** — person → enrolment cards → one scoped programme (Journey · Team · Sessions · Tracker · Results) + programme switcher | [UI] | The student/guardian grammar; mirrors R-1's structure at family density. |

---

## PHASE 2 — UI-rebuild of the 27 proven surfaces → v3  [UI, backend kept]

Each existing page is **re-skinned to DS2 v3 and re-composed to the prototype**, keeping its controllers/policies/services. Behaviour-sha proves the backend is untouched; the diff is UI-only. Grouped by persona for batching.

**Existing surfaces (verified in source):** AccessIdentity · Activate · AdminAudit · AdminConsentTemplates · AdminProgrammes · Approvals · Community · ConsentEvidence · Consents · Dashboard · EnrolmentPool · Enrolments · FinancialIntegrity · Login · Payments · PublicPay · Refunds · Register · SelfService · SessionAttendance · StudentTeam · Teams · Withdrawals · Marketplace(held) · (NotFound/Placeholder/StyleGuide/Ds2Gallery = utility, restyle or drop).

| # | Card | Surfaces rebuilt |
|---|---|---|
| U-1 | **Auth & entry** | Login, Activate, Register, PublicPay, NotFound → v3 (AuthCard, the glass chrome). |
| U-2 | **Family — student** | Dashboard(student home = digest), StudentTeam → the scoped-programme space (folds into R-2), Community. |
| U-3 | **Family — guardian** | Dashboard(guardian = action-inbox), Consents (signing ceremony full-screen), Payments (both rails), SelfService. |
| U-4 | **Marketplace (held commit)** | Rebuild Marketplace.tsx UI to v3 (glance cards, category-accent chips, guardian child-picker). **Backend/RLS from 47a7360 kept** — this is the model case for keep-backend/rebuild-UI. |
| U-5 | **Ops — records & queues** | Approvals, EnrolmentPool, Enrolments, Teams (board = read-only occupancy), Withdrawals, AccessIdentity. |
| U-6 | **Ops — delivery & config** | SessionAttendance, AdminProgrammes, AdminConsentTemplates. |
| U-7 | **Finance** | Payments(finance), FinancialIntegrity, Refunds → v3 (To-Record/To-Confirm split, four-eyes disablement, name-blanking). |
| U-8 | **Audit** | AdminAudit, ConsentEvidence → v3 (immutable-ledger styling, facets). |

*Utility pages:* StyleGuide/Ds2Gallery become the **v3 living style reference** (rebuild) or are dropped; Placeholder retired as real surfaces land.

---

## PHASE 3 — New-capability cards (missing-halves + completeness), in v3

Backend + UI together, built to the kit. This is the full-function scope from the consolidated backlog, now un-gated from "demo v1" — it's the whole product. Ordered by dependency.

**3a · Core halves (the request grammars + their queues)** [CS/RLS]
- `team_change_requests` (linchpin) → the three ops queues (join-review, team-change, batch-processing) → batch detail with per-row enum outcomes → submit-team ceremony → `session_materials` (student+guardian read) → `mentor_checkins` (flag-gated) → `attendance_amendments` → first-run/activation + empty-state catalogue → audit-coverage rule.

**3b · Consent, money & safeguarding** [CS/FIN]
- `consents.kind` + consent-withdrawal supersede · `incident_notes` (ops-only, RLS-reviewed) · `orders_read` student narrowing · student-own-consent-read · both payment rails + remittance loop + suspense · fee waivers via immutable `credit_notes` + reason enum · period soft-lock · surface refund four-eyes (already exists).

**3c · Journey completeness** [mixed]
- Booking cancel (student + guardian-on-behalf) · capacity + waitlist (own-row read) · team invite codes (hardened) · `teams.intro`/`visibility` + lobby-wall predicate · leave-team · sent-request traces · terminal-state rendering · guardian marketplace (extends U-4) · contact self-update · quarantine feedback + submission history · notifications domain (in-app; email next) · DAR log · school CSV report (released data) · grading rubric display · cancel-pending-withdrawal · mentor unavailability/reschedule requests · programme wizard/editor (large — sequence mid-phase) · session transition actions + lobby/template admin · co-guardian invite/remove.

**3d · Deferred to a later cycle** (schema-seam now where noted)
- Merge/de-dupe (ops request-then-apply, blocks on guardian-link conflict) · moderation/second-marker (seam now, UI later) · month-end reporting tooling · audit facets/exports · account security (all roles — **promote earlier given minors' accounts**) · step-up re-auth · full i18n TC/SC catalogue · email/WhatsApp channels · a11y polish · Settings module.

---

## The four mandatory conditions (gate their related 3a–3c cards)

M-1 invite-code hardening · M-2 batch-reason enum · M-3 waitlist own-row scoping · M-4 RLS line-review for `incident_notes` + `mentor_checkins`. A related card can't pass review without its condition.

---

## Recommended sequence (full build)

1. **PHASE 0** entire (P0-1 → P0-4). *The style exists before any surface is built to it.*
2. **PHASE 1** A-1→A-7 (authority spine, safety-first), then R-1/R-2 (record model). *The structural core.*
3. **PHASE 2** U-1→U-8, batched by persona. *Fast because everything is P0 primitives; behaviour-sha keeps backends provably untouched.* U-4 (marketplace) proves the keep-backend/rebuild-UI pattern early.
4. **PHASE 3** 3a → 3b → 3c, with the programme wizard (3c) sequenced mid-phase not last; 3d in a later cycle.

**Two sequencing notes:**
- **Phase 1 and Phase 2 can run partly in parallel** once Phase 0 lands — the record pages (R-1) and the surface rebuilds (U-*) both consume the same primitives. If you want throughput, R-1 + U-2/U-3 (family) can go together.
- **The held marketplace commit (47a7360)**: decide whether to **push it as-is first** (it's proven green in v2 styling) or **hold it and rebuild to v3 in U-4**. My recommendation: **push it now** (bank the proven backend on `origin/main`), then U-4 rebuilds its UI to v3 as a clean UI-only diff — rather than sitting on a held commit through the whole Phase 0/1.

---

## What this plan is *not*

- **Not a re-prove of the backend.** RLS, four-eyes, elevations, the enrolment pipeline, the embargo — all kept. Phase 2 is behaviour-sha'd UI-only.
- **Not a demo.** No frozen-demo constraint; this is the full product. (If a client demo is still wanted, it's a checkpoint *within* this build, not a separate track.)
- **Not off-kit anywhere.** After Phase 0, every surface is DS2 v3 by construction.

---

*This plan builds the full product to the new design kit and prototype exactly, keeping every proven backend/RLS boundary and rebuilding only the presentation to v3. Phase 0 establishes the style once; Phases 1–3 build the structural core, re-skin the 27 proven surfaces, and add the full-function capability set — each card through the standing review gate.*
