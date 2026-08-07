# DS2 Restyle Rollout — the full ruled sequence (all ~27 remaining surfaces)

> **Planning / documentation only — no code, no restyle building.** The enumerated plan that turns
> **"3 anchors done, 27 unscheduled"** into a complete, ordered rollout. Grounded in the coverage audit
> (the live router `web/src/main.tsx`, `nav.tsx` role gates, the `ds2-import-guard.mjs` ALLOWED ledger).
> Scope: a **FULL revamp** — every live surface adopts DS2, not a subset.

**Status anchor.** Done + proven (the pattern): **My Children** (`SelfService`→MyChildren, anchor 1),
**Wizard** (`AdminProgrammes`, anchor 2), **Payments** (`/admin/payments`, anchor 3) — `AUDIT-ANCHORS-REAL.md`.
The import-guard ALLOWED currently holds exactly those three product files. This plan schedules the rest.

**Sequence (Leo-confirmed): low-risk-first.**
`Tier 1 Display → Tier 2 Child-data → Tier 3 Money → Tier 4 Public/anon.`
Display tiers **batch** (frontend-scan depth); child-data / money / public are **line-by-line**, each with a
**risk shot**.

---

## 1. The discipline (identical to the anchors — non-negotiable)

Every card in this rollout obeys the proven anchor rules:

1. **Additive DS2 only.** No change to Design System v2.1 base (`theme/theme.ts` stays the palette source);
   no change to the `@/ds2` library beyond genuinely-reusable additions surfaced by real adoption
   (a new atom/structure component is its own reviewed DS2-library change, not smuggled into a restyle card).
2. **Markup-only.** Swap hand-rolled markup for DS2 components. **Every mutate call, endpoint, payload shape
   and RLS-scoped read is byte-identical.** Behaviour lives server-side and in unchanged payloads.
3. **Existing tests stay GREEN, UNMODIFIED.** The per-surface tests are not touched; green-unchanged is the
   behaviour-preservation proof. **If a restyle forces a test change, STOP** — the behaviour moved.
4. **Import-guard entry is deliberate, per card.** Each card adds exactly its file(s) to `ALLOWED` in
   `scripts/ds2-import-guard.mjs`. Every non-adopting surface stays **byte-identical** (the guard fails
   otherwise).
5. **Fidelity, not fabrication.** Render only what the real reads provide. **No new field is fetched or
   shown** — especially never `token_hash` on money/public surfaces; never a new PII field on the directory.
   If the prototype implied data the reads don't carry, the card renders the honest subset (as My Children
   did with per-programme consent).
6. **Risk shot** on every sensitive surface (child-data / money / public): the gated behaviour rendered on
   the new look — e.g. a server refusal surfaced shown-not-hidden — proving the gate still fires.
7. **Per-card exit gates:** the surface's tests UNMODIFIED + green · `ds2:tokens` · `ds2:import-guard`
   (ALLOWED += this card's file[s]) · `i18n:check` parity · `tsc` · `vite build` · `bundle-budget` ·
   `reconcile:run 58/58` (frontend-only, no backend touched) · every other surface byte-identical.

**Review depth by tier:** Tier 1 = frontend-scan, batched. Tiers 2–4 = **line-by-line**, one card per surface
(or per shared file), each with a risk shot.

---

## 2. The shared-file constraint (drives batching)

Several routes live in ONE file. A file enters `ALLOWED` as a **unit**, so a card must restyle the whole file
**or** deliberately keep the untouched exports byte-identical (as the anchors did: `SelfService.tsx` entered
ALLOWED for My Children while My Payments / My Students stayed byte-identical). To avoid re-touching a file
across multiple cards, **a multi-surface file is restyled in ONE card, placed in the tier of its
MOST-SENSITIVE surface.**

| File | Surfaces | Restyled at |
|---|---|---|
| `SelfService.tsx` | MyChildren ✅ · **MyPayments · MyStudents** | Tier 2 (MyStudents = child roster) — **must preserve the already-restyled MyChildren byte-identical** |
| `SessionAttendance.tsx` | MySessions · ChildSessions · MentorAttendance · OpsAttendance | Tier 2 (minor attendance) — all four in one card |
| `Consents.tsx` | ConsentList · ConsentSign | Tier 2 (BI-6 consent) |
| `Community.tsx` | MemberEvents · MemberDirectory · MemberProfile | Tier 1 (display; directory PII noted) |
| `Teams.tsx` / `StudentTeam.tsx` | ops 成團 view / student formation | Tiers 3 / 2 — separate files, but **share the 成團 terminology change** (§6) |

---

## 3. DS2 palette to draw from (already built + proven)

Atoms: `StatusAtom · StatChip · MetaChip · StateBadge · ProgressRing · DatedBadge · Seal`.
Structure: `SubPanel · ZoneStack · Attest · ZebraTable · WizardRail · FormLanguageSwitcher`.
Cards pick from these; a genuinely-missing reusable primitive (e.g. a `StatCard`, surfaced by Payments) is a
**separate DS2-library card**, not folded into a restyle. Fidelity rules which components a surface can use
(e.g. `Attest` attested-side REQUIRES a reachable record).

---

## 4. TIER 1 — Display (frontend-scan, batched)

Predominantly read/list/dashboard. Any incidental low-stakes mutation (RSVP, profile edit, enrol-intent)
keeps its mutate call **byte-identical** and does **not** promote the surface to a sensitive tier — **but the
card MUST include a "payload-unchanged proof"**: a diff excerpt showing the mutation handler (onClick/submit)
and its request payload are byte-identical, proving the restyle is markup-only. (This is the anchors'
risk-shot discipline applied to Display-tier mutations — it applies to **D1 Enrolments** and **D3 Member
Events / Member Profile**.)

| Card | Surface (route) | Role | File | Notes |
|---|---|---|---|---|
| **D1** Dashboards & lists | Dashboard `/` | all | `Dashboard.tsx` | KPI read; StatChip/StatCard/SubPanel |
| | Enrolments `/enrolments` | guardian / enrolment.view | `Enrolments.tsx` | list/read; **payload-unchanged proof** for any enrol-intent / withdraw-initiate mutate call (handler + payload byte-identical) |
| **D2** Audit report views | Enrolment Pool `/admin/enrolment-pool` | audit.read | `EnrolmentPool.tsx` | read-only report |
| | Access & Identity `/admin/access-identity` | audit.read | `AccessIdentity.tsx` | read-only report |
| | Audit `/admin/audit` | audit.read | `AdminAudit.tsx` | read-only audit stream; ZebraTable |
| | Consent Evidence `/admin/consent-evidence` | audit.read | `ConsentEvidence.tsx` | read-only; renders consent metadata — **no new field, no signing** |
| **D3** Member surfaces | Member Events `/events` | member | `Community.tsx` | RSVP mutate — **payload-unchanged proof** |
| | Member Directory `/directory` | member | `Community.tsx` | **PII — no new directory field surfaced (fidelity)** |
| | Member Profile `/profile` | member | `Community.tsx` | profile `PUT /my/profile` — **payload-unchanged proof** |

**3 cards.** Batched frontend-scan. D3 is one card (whole `Community.tsx`).

---

## 5. TIER 2 — Child-data (line-by-line, risk shot each)

Student / family data, consent, minor attendance, onboarding-linkage authority.

| Card | Surface (route) | Role | File | Notes / risk shot |
|---|---|---|---|---|
| **C1** Consents | Consent List `/consents` · Consent Sign `/consents/:id` | guardian (+ student view) | `Consents.tsx` | **BI-6** — scroll-to-end / affirmation / drawn-or-typed capture / SHA-256 versioning **untouched**; `Attest` for the signed record. Risk shot: a sign-gate refusal surfaced |
| **C2** Sessions & Attendance | My Sessions `/my/sessions` · Child Sessions `/family/sessions` · Mentor Attendance `/attendance` · Ops Attendance `/admin/attendance` | student · guardian · teacher · config-admin | `SessionAttendance.tsx` | **minor attendance (child-safety)** — mark/book/cancel mutate calls byte-identical; MetaChip for session time/place. Risk shot: an attendance-mark or roster-visibility denial surfaced |
| **C3** Self-service remainder | My Payments `/my/payments` · My Students `/my/students` | guardian · teacher | `SelfService.tsx` | **MUST preserve the already-restyled MyChildren byte-identical** (file already in ALLOWED). MyStudents = school roll (child data); MyPayments = the guardian's OWN payments (read). Diff proves MyChildren unchanged |
| **C4** Student team formation | My Team `/my/team` | student | `StudentTeam.tsx` | own-team RLS; create/join/submit mutate calls byte-identical; **成團 terminology (§6)**. Risk shot: a formation-eligibility refusal surfaced |
| **C5** Approvals | Approvals `/admin/approvals` | ops (operations.manage) | `Approvals.tsx` | onboarding + **linkage** decisions (guardian/school/teacher links for minors) — approve/reject mutate byte-identical; the **two-decision** model (person ≠ relationship) preserved. Risk shot: a decision refusal surfaced |
| **C6** Consent Templates | Consent Templates `/admin/consent-templates` | config-admin | `AdminConsentTemplates.tsx` | **BI-6** template versioning (SHA-256, **language-scoped**, placeholder-text flag) untouched; draft/publish-version mutate byte-identical |

**6 cards.** Line-by-line.

---

## 6. TIER 3 — Money (line-by-line, highest scrutiny, risk shot each)

| Card | Surface (route) | Role | File | Notes / risk shot |
|---|---|---|---|---|
| **M1** Refunds | Refunds `/admin/refunds` | finance | `Refunds.tsx` | **BI-9 SoD on refunds** (recorder ≠ confirmer, both directions) stays server-enforced — mirror Payments' shown-disabled cue; **OD-48 full-only**. Risk shot: same-person refund confirm/reject blocked |
| **M2** Financial Integrity | Financial Integrity `/admin/financial-integrity` | finance / audit | `FinancialIntegrity.tsx` | read-only money report; **no new field (never `token_hash`)**; money right-aligned, tabular-nums, Seal on settled |
| **M3** Withdrawals | Withdrawals `/admin/withdrawals` | ops | `Withdrawals.tsx` | decision can trigger **refund + release** (money + child); BI-7 withdrawal workflow untouched; decide mutate byte-identical. Risk shot: a decision refusal surfaced |
| **M4** Ops Teams / 成團 | Team `/team` | ops (operations.manage) | `Teams.tsx` | **成團 confirm mints payment_obligations + claims seats (BI-3) and re-checks consent** — all server-side, untouched; confirm/assign mutate byte-identical; **成團 terminology (below)**. Risk shot: a capacity/consent refusal at 成團 surfaced |

**成團 → "Team Formation" terminology (rides C4 + M4).** EN locale: replace the mixed CJK — "Awaiting 成團" →
**"Awaiting formation"**, "Submit for 成團" → **"Submit for formation"**, label → **"Team Formation"** (no CJK
in EN). **Keep zh-TC 成團 · zh-SC 成团.** The EN edit (10 occurrences in `en.json`) lands with the **first** of
the two team cards (**C4**, child-data precedes money); **M4 inherits** it. `i18n:check` parity gate applies.

**4 cards.**

---

## 7. TIER 4 — Public / anon (line-by-line, unauthenticated)

| Card | Surface (route) | Role | File | Notes / risk shot |
|---|---|---|---|---|
| **P1** Auth forms | Login `/login` · Register `/register` · Activate `/activate/:token` | anon | `Login.tsx` · `Register.tsx` · `Activate.tsx` | token/session flow (`ka.token`), registration payload, and activate (verify + set-password, OD-29) **untouched**; DS2 form styling only. On-brand with the new DemoGate look |
| **P2** Public Pay | Public Pay `/pay/:token` | anon payer | `PublicPay.tsx` | **the ONLY unauthenticated money surface (OD-44 `single_reader`)** — multi-view resolve + single-act confirm; **`token_hash` NEVER shown**; initials-only page preserved. Risk shot: the resolve/confirm rendered with no PII leak |

**2 cards.** P1 batches the three auth forms (no money); P2 is line-by-line (unauth money).

---

## 8. Need-build-first (NOT restyle-only)

Two routes render `<Placeholder>` — there is **no real UI to restyle** until the functionality is built.

| Surface (route) | State | Path to restyle |
|---|---|---|
| Activity Tracker `/tracker` | **stub** (`Placeholder`) | Build the Tracker UI (Plan·Design·Learn·Pitch·Launch over the built stage-gate hooks — build-plan micro #11), **DS2-native from the start**; no separate restyle card |
| Learn `/learn` | **stub** (`Placeholder`) | Build the Learn-gate view (part of S-UX3-4 scope), **DS2-native**; no separate restyle card |

**Rule:** these are **build-first**, and when built they should be authored in DS2 directly (a functionality
card that lands DS2-native, not a stub restyle). They are **out of this restyle rollout's card count.**

**Owned by exactly one doc — the functionality plan.** Tracker and Learn are scheduled and owned in
`docs/deliverables/KAP-REMAINING-BUILD-PLAN.md` (Tracker = micro item #11; Learn = the Learn-gate remainder of
item #6, since S-UX3-4 shipped sessions/attendance but not the Learn view). This restyle rollout only
cross-references them; it does not schedule them.

---

## 9. The full sequence at a glance

| # | Card | Tier | Depth | Surfaces | Import-guard += |
|---|---|---|---|---|---|
| 1 | **D1** Dashboards & lists | Display | scan (batch) | Dashboard, Enrolments | `Dashboard.tsx`, `Enrolments.tsx` |
| 2 | **D2** Audit report views | Display | scan (batch) | Enrolment Pool, Access & Identity, Audit, Consent Evidence | those 4 files |
| 3 | **D3** Member surfaces | Display | scan (batch) | Events, Directory, Profile | `Community.tsx` |
| 4 | **C1** Consents | Child-data | line-by-line | Consent List, Consent Sign | `Consents.tsx` |
| 5 | **C2** Sessions & Attendance | Child-data | line-by-line | My/Child Sessions, Mentor/Ops Attendance | `SessionAttendance.tsx` |
| 6 | **C3** Self-service remainder | Child-data | line-by-line | My Payments, My Students | `SelfService.tsx` (already ALLOWED) |
| 7 | **C4** Student team formation | Child-data | line-by-line | My Team (+ 成團 EN terminology) | `StudentTeam.tsx` |
| 8 | **C5** Approvals | Child-data | line-by-line | Approvals | `Approvals.tsx` |
| 9 | **C6** Consent Templates | Child-data | line-by-line | Consent Templates | `AdminConsentTemplates.tsx` |
| 10 | **M1** Refunds | Money | line-by-line | Refunds | `Refunds.tsx` |
| 11 | **M2** Financial Integrity | Money | line-by-line | Financial Integrity | `FinancialIntegrity.tsx` |
| 12 | **M3** Withdrawals | Money | line-by-line | Withdrawals | `Withdrawals.tsx` |
| 13 | **M4** Ops Teams / 成團 | Money | line-by-line | Team (成團 inherits C4's EN edit) | `Teams.tsx` |
| 14 | **P1** Auth forms | Public | line-by-line | Login, Register, Activate | those 3 files |
| 15 | **P2** Public Pay | Public | line-by-line | Public Pay | `PublicPay.tsx` |

**15 restyle cards** (3 display batches · 6 child-data · 4 money · 2 public) covering **all 27 live surfaces**;
**+2 build-first stubs** (Tracker, Learn) outside this count. On completion, **every product file** is in
`ALLOWED` and the whole app is DS2 — the import-guard then documents 100% deliberate adoption.

---

## 10. Coverage check (this plan vs the audit)

Audit inventory: 27 un-restyled live surfaces + 2 stubs. This plan schedules **all 27** across 15 cards, and
routes the **2 stubs** to build-first. **No live surface is left unscheduled.** Anchors (My Children, Wizard,
Payments) remain done and are the proven pattern each card follows.

*No code, no cards created, no import-guard change. Plan only — awaiting review before the first restyle card
(D1) starts.*
