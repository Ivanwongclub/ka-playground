# KA Playground — Consolidated Build Backlog

> **The single ranked source for the build chat.** This merges five inputs into one list so the build works from one document, not five: (1) the data-model re-think Step-6 amendment list (authority/delegation), (2) the reconciliation of the design changes (the 22 B-items + flags), (3) the journey audit's gap register (Tiers 0–3 across 7 roles), (4) the four mandatory build conditions, and (5) the D-4 decision-sheet rulings. Where inputs overlapped, they're merged into one line with all sources noted.
>
> **The v1 line is drawn explicitly.** Everything is tagged **[v1]**, **[v1-seam]** (schema/RLS seam in v1, UI later), or **[v2]**. v1 = the launch product: the enrolment-centric core made whole + the authority model + the "missing halves of built things." v2 = fast-follow.
>
> **Review tiers** (each card runs the standing gate — map → ruling → HELD build → line-by-line diff → registered elevations char-matched to `scope-elevations.php` → RLS proof → behaviour-sha → reconcile → push, against the frozen demo): **[CS]** child-safety · **[FIN]** financial-integrity · **[RLS]** RLS-touching · **[UI]** UI-only, no schema · **[CFG]** config/ops.
>
> **Precedence note carried from review:** where any design-doc body disagrees with its 14-Aug addendum or the reconciliation, the addendum/reconciliation win.

---

## 0 · The four MANDATORY build conditions (gate every related card — non-negotiable)

These are conditions on other cards, not standalone work. A related card cannot pass review without them.

| # | Condition | Applies to |
|---|---|---|
| M-1 | **Invite codes** grant *request-rights only* — single-use/rotating, `expires_at`, rate-limited; never auto-membership, never any team read beyond the public recruiting card | team invite cards [CS][RLS] |
| M-2 | **Batch failure reasons** are a fixed **enum of reason codes**, never free text; never name a guardian or another family | batch enrolment cards [CS] |
| M-3 | **Waitlist / capacity family read** scoped to the family's **own row only** — never the list, never counts of other children | capacity/waitlist cards [RLS] |
| M-4 | **RLS line-by-line review before ship** for `incident_notes` and `mentor_checkins` (child-safety writes) | those two cards [CS][RLS] |

---

## 1 · FOUNDATION — the authority & delegation layer (build FIRST)

The Step-6 spine. Everything else composes on it. Safety-first order within the group.

| # | Card | Tier | v1? | Sources |
|---|---|---|---|---|
| F-1 | **Delegable-capability catalogue** (code + drift probe) — marks delegable vs. never-delegable (`consent.sign`, formed-team membership, money gates, `delegation.grant`). Build first so nothing dangerous can be delegated by later config. | [CS] | **[v1]** | Step-6 A1 |
| F-2 | **`school_authority_grants`** (per-school baseline) + **`programme_authority_overrides`** (per-programme grant/withhold) tables | [RLS] | **[v1]** | Step-6 A2/A3 |
| F-3 | **Session-context resolver** — computes each edge actor's effective delegated capabilities per request into `app.capabilities` | [RLS] | **[v1]** | Step-6 A6 |
| F-4 | **Delegated RLS arms** — additive OR-clauses on teams / team_members / stage_gates / sessions / assessments / registration / withdrawal reads+writes, each gated by a delegated capability + scope join. behaviour-sha untouched arms; RIDER-1 per seeded role. One domain per card. | [CS][RLS] | **[v1]** | Step-6 A5 |
| F-5 | **Formed-team write carve-out** — `team_members` write admits edge only while team ∈ {forming, submitted}; platform-only once `confirmed`. Registered elevation for platform override. | [CS][RLS] | **[v1]** | Step-6 B1 · recon B-21 |
| F-6 | **Migrate `mentor_team_access`** into a `programme_authority_overrides` row; deprecate the column after backfill | [RLS] | **[v1-seam]** | Step-6 A4 |
| F-7 | **Delegation-config screen** — platform-exclusive, on the School record; edits F-2 tables; renders **only delegable** capabilities; audited | [CFG][UI] | **[v1]** | Step-5 · recon C3 |

---

## 2 · ENROLMENT-CENTRIC CORE — the "missing halves of built things" (Tier-1)

The audit's sharpest finding: built surfaces with unbuilt other halves. These make the core whole.

| # | Card | Tier | v1? | Sources |
|---|---|---|---|---|
| C-1 | **`team_change_requests`** — the linchpin; all roster composition (ops/school/mentor) emits a request, platform applies; `teams`/`team_members` stay system-write | [CS][RLS] | **[v1]** | recon B-3 · audit O1 |
| C-2 | **Join-request table + academy-review half** (`lead-accepted → academy review → applied`); net-new (no such table today). Unblocks lead-reads-requester-names (scoped to own team's pending requests, **not** a general user read) | [CS][RLS] | **[v1]** | recon B-4, FLAG-A3 · audit O1 |
| C-3 | **Three missing ops queues wired to the above** — join-request review, team-change approvals, batch-processing with per-row outcomes. "Every request grammar must terminate in a staff queue." | [UI] | **[v1]** | audit O1 (3B.1) |
| C-4 | **Batch detail + per-row validation outcomes** — the school's batch is opaque today; needs per-row disposition (new/match/skip/fail) with **enum reasons (M-2)**; twin of the ops processing queue | [CS][RLS] | **[v1]** | audit SC1↔O1 · recon B-5 |
| C-5 | **Submit-team ceremony** (forming → submitted; membership locks; consent-blocker checklist) — the student-side twin of the ops confirm ceremony; spec'd, not built | [UI] | **[v1]** | audit S2 |
| C-6 | **`session_materials`** (file ref, scan state, published) — mentor upload built, **student + guardian read side missing**; guardian mirrors the student read (booked ∧ published ∧ scan-clean) | [RLS] | **[v1]** | recon B-7, FLAG-B7 · audit S3 |
| C-7 | **`mentor_checkins`** — satisfies the Learn-gate "mentor check-in" requirement that currently has **no actor**; flag-gated direct write, linked-team scoped, audited **(M-4)** | [CS][RLS] | **[v1]** | recon B-8 · audit M1 |
| C-8 | **`attendance_amendments`** — reasoned request, original row immutable, ops applies | [RLS] | **[v1]** | recon B-9 · audit M3 |
| C-9 | **First-run / activation + empty-state catalogue** — no role has an activation/first-login flow; every list surface needs one empty state (register in handoff) | [UI] | **[v1]** | audit S1, 4C |
| C-10 | **Audit coverage rule** — every queue decision writes an `audit_events` row (actor, reason, before/after); binds C-1/2/4/8 and finance decisions | [RLS] | **[v1]** | recon B-22 · audit A4 |

---

## 3 · CONSENT, MONEY & SAFEGUARDING (Tier-0/1 — the ruled product decisions)

| # | Card | Tier | v1? | Sources |
|---|---|---|---|---|
| P-1 | **`consents.kind`** (enrolment/media/…) + **consent-withdrawal superseding action** (new record referencing prior; signed records immutable) — enables separate media consent | [CS][RLS] | **[v1]** | recon B-6 · audit G2/G7 · answers 2,3 |
| P-2 | **`incident_notes`** — ops-only safeguarding notes on Student 360; **no family/school/mentor read, ever**; RLS reviewed line-by-line **(M-4)** | [CS][RLS] | **[v1]** | recon B-13 · audit O2 · answer 7 |
| P-3 | **`orders_read` narrowing** — student gets status-only (paid/due), no amounts; deliberate RLS narrowing (the earlier finding's fix) | [RLS][FIN] | **[v1]** | recon B-18 · answer 11 |
| P-4 | **Student reads own consent doc** (version, language, signed-date; read-only; IMM:1 unaffected) | [RLS] | **[v1]** | recon B-20, FLAG-B20 · audit S7 |
| P-5 | **Payment rails: both** — provider (link) + manual (FPS/bank, `order_reference`); guardian Pay surface shows instructions + "I've paid, ref #" declaration feeding finance To-Record | [FIN] | **[v1]** | audit G4/F8 · answer 4 |
| P-6 | **Remittance loop** — school declares "paid, ref #" → lands in finance To-Record pre-matched; suspense workflow for unmatched | [FIN] | **[v1]** | recon B-17 · audit SC2↔F1/F6 |
| P-7 | **Fee waivers / scholarships** — reuse immutable `credit_notes` + reason enum + four-eyes; family sees net owed (D-4 #2) | [FIN] | **[v1]** | D-4 #2 · audit F3/F4 |
| P-8 | **Period lock** — soft close + reason-gated reopen + refuse-close-on-red-reconciliation (D-4 #3) | [FIN] | **[v1-seam]** | D-4 #3 · audit F5 |
| P-9 | **Refund four-eyes** — **already exists** (`approved_by` ≠ `confirmed_by`, app+DB); no build, just surface it correctly in the UI | [FIN] | **[v1 UI]** | recon FLAG-A4 · audit F2 |

---

## 4 · JOURNEY COMPLETENESS (Tier-2 — mostly v1, some v2)

| # | Card | Tier | v1? | Sources |
|---|---|---|---|---|
| J-1 | **Booking cancel + capacity release** — actor = student **and** guardian-on-behalf (own child scope) | [RLS] | **[v1]** | recon B-11, FLAG-B11 · audit S6/G9 |
| J-2 | **Capacity + waitlist** — `programme_capacity` exists (no waitlist, not family-readable); add waitlist + **family reads own position only (M-3)** | [RLS] | **[v1]** | recon B-16, FLAG-B16 · audit S10 |
| J-3 | **Team invite codes** — with full hardening **(M-1)** | [CS][RLS] | **[v1]** | recon B-2, FLAG-B2 · audit 1.5 |
| J-4 | **`teams.intro` / `teams.visibility`** + lobby-wall predicate adds the visibility test (a narrowing) | [RLS] | **[v1]** | recon B-1, FLAG-B1 |
| J-5 | **Leave forming team** (member; consequence = return to pool) | [UI] | **[v1]** | audit S4 |
| J-6 | **Sent-request traces** (student's "my requests" echo — join, interest, invite) | [UI] | **[v1]** | recon B-12 · audit S5 |
| J-7 | **Terminal-state rendering** — withdrawn/completed enrolment cards + scoped space (family) | [UI] | **[v1]** | audit S8/G10 |
| J-8 | **Guardian Market Place** — the paying persona can enrol but can't browse; guardian-skinned catalogue, CTA "Enrol [child]…" | [UI] | **[v1]** | audit G1 |
| J-9 | **Contact self-update** (guardian phone/email — the whole chase machinery depends on it being current) | [UI] | **[v1]** | audit G5 |
| J-10 | **Quarantine/resubmit feedback + submission history** (upload scan-fail path, family-safe wording) | [UI] | **[v1]** | audit S9 |
| J-11 | **Notifications domain** — in-app v1 (triggers: consent `expires_at`, `payment_due_at`); deadline classes snooze-limited + non-mutable; email fast-follow | [RLS] | **[v1]** | recon B-19 · audit 1.11 · answer 12 |
| J-12 | **DAR log** (PDPO) — thin table or `audit_events` facet, ops-internal | [CFG] | **[v1]** | recon B-14 · audit A5 · answer 8 |
| J-13 | **School term report** — CSV export of own **released** data, RLS-scoped, no new reads (D-4 #5) | [RLS] | **[v1]** | D-4 #5 · audit SC5 |
| J-14 | **Grading rubric display** (criteria beside score, read-only) | [UI] | **[v1]** | D-4 #4a · audit M5 |
| J-15 | **Recognition / certificates** — own-family read; net-new (no table today) | [RLS] | **[v2]** | recon B-15, FLAG-B15 · audit S11 |
| J-16 | **Receipts / invoice PDFs + line dispute** (bursars need them) | [UI] | **[v2]** | audit G4/SC3 |
| J-17 | **Cancel pending withdrawal** (cooling-off) | [UI] | **[v1]** | audit G8 |
| J-18 | **Mentor unavailability / substitute + session-reschedule requests** | [UI] | **[v1]** | audit M2 |
| J-19 | **Programme wizard/editor** — the entire §3 config surface (basics → stages → sessions → fees → consent template → banner/visibility → publish); currently read-only | [CFG] | **[v1]** | audit O3 |
| J-20 | **Session publish/cancel/reschedule actions** + lobby (team_categories) admin + consent-template versioning admin | [CFG] | **[v1]** | audit O6, 3B.3 |
| J-21 | **Co-guardian: invite initiation + remove/deactivate link** (request-to-academy grammar; peers, notify-all, disputes→academy — already the model) | [CS][UI] | **[v1]** | audit G3 (data model already supports) |

---

## 5 · v2 / FAST-FOLLOW (Tier-2/3 not in v1)

| # | Card | Tier | Sources |
|---|---|---|---|
| V-1 | **Merge / de-dupe** — ops-only, request-then-apply, block on guardian-link conflict, audited (D-4 #1). v1 = manual runbook. | [CS] | D-4 #1 · audit O5 |
| V-2 | **Moderation / second-marker** — schema seam in v1 (nullable moderator + state), UI in v2 (D-4 #4b) | [—] | D-4 #4b |
| V-3 | Month-end reporting tooling (beyond the v1 lock primitive) | [FIN] | audit F5 |
| V-4 | Audit facets/exports (actor/action/date filters; CSV/PDF trail) | [UI] | audit A2/A3 |
| V-5 | Account security (password change, sign-out-all) — all roles; **note: minors' accounts, so promote to v1 if the demo audience expects it** | [—] | audit S12/G11 |
| V-6 | Step-up re-auth for consent signing + four-eyes confirm | [CS] | audit 4C |
| V-7 | i18n full parallel TC/SC copy catalogue (mechanical, sizeable) | [UI] | audit 4C |
| V-8 | Email / WhatsApp notification channels (PDPO review) | [—] | audit 4C · answer 12 |
| V-9 | a11y: drag button-equivalent, aria on collapsed rail, focus states, reduced-motion | [UI] | audit 4C |
| V-10 | Settings module contents (template admin, fee catalogue, officer pairs, channel policy) | [CFG] | audit O7 |

---

## 6 · Recommended build order (v1)

1. **Foundation (§1)** — F-1 catalogue → F-2/F-3 tables+resolver → F-4 RLS arms (one domain per card) → F-5 formed-team carve-out → F-6 mentor-flag migrate → F-7 config screen. *Nothing delegable-that-shouldn't-be can slip once F-1 lands.*
2. **Core halves (§2)** — C-1 `team_change_requests` first (the linchpin), then C-2/C-3 (join-request + queues), C-4 (batch), C-5–C-8, C-9 first-run, C-10 audit rule.
3. **Consent/money/safeguarding (§3)** — P-1 consent kinds, P-2 incident notes, P-3 orders narrowing, P-4 consent read, then P-5–P-9 finance.
4. **Completeness (§4)** — J-series; the config-heavy J-19/J-20 (wizard) are large — sequence them mid-run, not last.
5. **v2 (§5)** — after the client demo and v1 stabilization.

Each card: the standing review gate, against the **frozen** demo. Unfreeze only past the client demo.

---

## 7 · Scope reality check

**v1 is large but coherent:** the Foundation (7) + Core halves (10) + Consent/money/safeguarding (9) + the v1 slice of Completeness (~18) ≈ **44 cards**. That's a real release, not a sprint. Two levers if it needs trimming:
- **J-19 programme wizard** is the single biggest card — if the demo can run on seeded config, it can slip toward the end of v1 without blocking anything upstream.
- **V-5 account security** is the one v2 item I'd flag for possible promotion — minors' accounts make it safeguarding-relevant; if the client demo audience will ask, pull it into v1.

Everything preserves the immovables: no card adds a visibility path, money keeps four-eyes, consent stays guardian-only, the embargo and cross-family/school isolation hold, and every delegated capability is additive-only on the RLS.

---

*This backlog + the D-4 decision sheet are the build hand-off. One ranked source, v1 line drawn, every card tagged with its review tier and traced to its origin. Override any ruling and I'll re-fold it.*
