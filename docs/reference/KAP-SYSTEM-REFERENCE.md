# KAP — SYSTEM REFERENCE
### Roles · Capabilities · Access Rights · Relationships · ER Shape · Workflows
**As-built AND as-designed, gaps marked. Every claim sourced.**

20 Aug 2026 · Document 1 of 3 (→ KAP-PROTOTYPE-WORKFLOW.md → KAP-ALIGNMENT-PLAN.md)

**Sources of truth, in precedence order on conflict:**
① the 63 migrations in `api/database/migrations/` + live policies — what EXISTS
② `permission-matrix.php` · `delegable-capabilities.php` · `scope-elevations.php` — what is GRANTED
③ Rethink Steps 1–6 — what is DESIGNED (wins over prototype on conflict)
④ the FR/OD register (FR001–FR228 / OD-1–OD-65) — the decisions
⑤ `KAP-GAP-AUDIT-FINAL.md` — the measured gap between ① and ③

Legend: ✅ built & proven · 🟡 built-partial / interim · 🔴 designed, not built · ⚠️ built divergence needing a ruling

---

## 1 · THE IDENTITY MODEL

**Six roles, never stacked** (OD-1, Spec B1): `student` · `guardian` · `teacher` · `school_admin` · `academy_admin` · `member`. One account = one role. ✅ enforced: `users.role` single-valued; `activeSchoolIds()` returns `[]` for every non-edge role, so delegation can never attach accidentally.

**Academy staff are ONE role qualified by capability groups** (OD-17): an `academy_admin` has a deliberately thin base (`events.view`, `member_directory.view`) and gains power only through granted capabilities: `super_admin` (\*) · `operations` · `finance` · `configuration` · `audit_read`. This produces the five staff *personas* the UI serves — ops, finance officer, config admin, auditor, super — without ever blurring identity.

**Edge operators** = `teacher` + `school_admin`: school-anchored actors whose reach is their own school's roll, never the platform. **Family** = `student` + `guardian`, bound by `guardian_links`. **Member** = first-generation adult community (OD-1): events, RSVP, member directory — *"the absence of every student/consent/enrolment/finance permission IS the control"* (matrix comment). Member invitations were deliberately not issued until member surfaces existed (OD-22).

---

## 2 · THE PERMISSION LAYER

### 2.1 The matrix (verbatim from `permission-matrix.php` — seeded to DB, drift-probed nightly)

18 permissions × 6 roles. Y = role default (B7 layer 1).

| Permission | student | guardian | teacher | school_admin | academy_admin | member |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| student_records.view | Y | Y | Y | Y | — | — |
| student_records.manage | — | — | — | Y | — | — |
| consent.view | Y | Y | — | Y | — | — |
| **consent.sign** | — | **Y** | — | — | — | — |
| enrolment.view | Y | Y | Y | Y | — | — |
| enrolment.create | — | Y | — | Y | — | — |
| finance.view | — | Y | — | Y | — | — |
| finance.record / confirm | — | — | — | — | — | — |
| teams.view | Y | — | Y | Y | — | — |
| teams.approve | — | — | Y | — | — | — |
| events.view | Y | Y | Y | Y | Y | Y |
| events.rsvp | Y | — | — | — | — | Y |
| member_directory.view | — | — | — | — | Y | Y |
| audit.read · configuration.manage · operations.manage · capabilities.grant | — | — | — | — | — (via caps) | — |

**Capability groups** (academy_admin only): `super_admin` = \* except forbidden · `finance` = finance.view/record/confirm · `operations` = operations.manage + student_records.\* + consent.view + enrolment.view/create + teams.view/approve + events.view · `configuration` = configuration.manage · `audit_read` = audit.read.

**capability_forbidden = [`consent.sign`]** — no staff capability may ever sign a consent (FR036, ETO Cap. 553, BI-6). Guarded by the `authz.consent_sign_exclusive` nightly assertion. ✅

### 2.2 The delegable catalogue (`delegable-capabilities.php`, A-1) ✅

Gates what may EVER be granted to an edge operator. **Delegable:** student_records.view/manage · consent.view · enrolment.view/create · finance.view · teams.view/approve · events.view/rsvp. **NEVER (the hard safety spine):** consent.sign · finance.record · finance.confirm · capabilities.grant · configuration.manage · operations.manage · audit.read · member_directory.view. Enforced twice: A-2 grant validation rejects non-delegable keys, and `authz.delegable_catalogue_integrity` fails loud nightly on drift.

⚠️ **D-5 (unruled, the long pole):** the delegable set is these 10 *coarse* permissions. The design (Rethink Step 2) wants finer verbs — `consent.chase`, `attendance.mark`, `withdrawal.initiate`, `assessment.grade|release` — which **do not exist**. A school cannot hold *chase* without holding `consent.view` wholesale. Everything delegation-driven (school shell, mentor queues, delegation-config screen) waits on this vocabulary refinement — a migration + matrix edit, never runtime.

### 2.3 Per-link overrides (B7 layer 2) ✅ narrow-only

`permission_overrides` ride the link entities. **A DB trigger rejects any key other than `"deny"`** and `PermissionResolver::allowsFor` refuses to widen — an override restricts, never invents (Step 4 §4.2.2). Hand-planting a widening row grants nothing.

### 2.4 The delegation map (A-1…A-4) — built at the policy layer, invisible above it

- **A-2** ✅ grant tables + `AuthorityGrantService` (grant/revoke/override audited with before/after; refusals audited too).
- **A-3** ✅ `EffectiveCapabilityResolver` — per-programme effective capabilities in PHP.
- **A-4** ✅ delegated RLS read arms on **teams · sessions · assessments** (GUC prefilter + per-programme scope-join honouring withholds — *"a withhold on programme P denies P's rows even while the capability sits in the request-wide GUC"*). 🔴 **four domains remain**: team_members, stage_gates, registration, withdrawal — gated on D-4.
- ⚠️ **X-1 (needs ruling D-4):** the precedence logic now exists **four times** — PHP resolves LAST-WINS, three SQL arms resolve GRANT-WINS-at-specific-level, and the *canonical* rule (DENY-WINS) is implemented by **none**. Benign single-school; a real fork.
- ⚠️ **X-3 (seam):** a school cannot read the all-schools (`school_id IS NULL`) override rows that affect it — its view of its own effective authority is incomplete by construction. Must close before the delegation-config screen ships.
- 🔴 **S-1/M-2:** `/me` returns `PermissionResolver` output only; **`EffectiveCapabilityResolver` never reaches any client** — the map reshapes RLS correctly and reshapes no UI affordance at all.
- ⚠️ **P-HYGIENE-1 (ruled, build approved 20 Aug):** the mentor arm reaches the programme by two paths (`teams_read` direct; `tm_read`/`stage_gates_read` via `team_categories`, which is RLS-scoped) — the category route silently acquired a school-scope condition never authored. Ruled Option A: denormalise `programme_id` onto team_members + stage_gates (backfill from `teams` with mismatch-abort guard; `set_config('app.context','system')` for the RLS-protected backfill; composite FKs `(team_id, programme_id) → teams(id, programme_id)` making divergence unrepresentable; arms become character-identical to teams_read's; lands before F-4). Item 2 closed at `255ca2e`: the enr_read audit_read arm is load-bearing via `/reports/enrolment-pool` — comment only.

### 2.5 Enforcement layers (where a right actually bites)

```
route middleware (permission:X)          — coarse gate; NARROWER than policy arms BY DESIGN
  → RLS policy (FORCE, fail-closed)      — row scope; the authority of record for reads
    → service-layer authority (OD-39)    — ~30 staff mutations resolve authority IN-SERVICE
      → registered elevations (asSystem) — 74 entries, each with reason + WITHHELD allowlist
        → DB constraints                 — BI-9 WITH CHECK, narrow-only trigger, status CHECKs
```
🔴 **S-2 (root cause of "shown-not-hidden"):** because ~30 staff writes carry authority in-service with no middleware and no affordance field on reads, the client cannot know who may act — it shows the button and lets the server 403. Entitlement-iff needs reads to return the affordance.
📌 **Lesson (S-READ-1, P-HYGIENE-1):** an arm being unreachable through one route is NOT evidence it is dead — e.g. `enr_read`'s `audit_read` arm is load-bearing via `/reports/enrolment-pool`.

---

## 3 · PER-ROLE REFERENCE

Format per role: **Does** (functions/features) · **Reads** · **Writes** · **Cannot** (by design) · **Gaps**.

### 3.1 STUDENT
**Does (as-designed, B1):** 4-slot world — Home · Programmes (enrolment cards → the scoped programme space with Journey/Team/Sessions/Tracker/Results tabs) · Market Place (browse, express interest) · Me. Books/cancels sessions, forms/joins/submits teams, sees released results only.
**Reads ✅:** own user row · own enrolments (list+detail, widened S-READ-1/2: banner, team, consent state, next session) · own team + roster with names/roles/count (allowlisted elevation, WITHHELD: contacts) · lobby wall = **forming teams in own programme+lobby, count-only, no organiser name** (`lobbyWall` arm; users_read is own-row for students) · own sessions + bookings (`programme_id` served) · stage gates for own team incl. passed_at/approver_kind (S-TRACKER-1; approver identity + notes withheld) · **released** assessment results only (embargo: `graded` renders identically to `published`; unreleased never probed) · own consent state (not the document — P-4 🔴) · own orders **status-only, never amounts** (P-3/B-18: `orders_read` still exposes amounts server-side; UI restraint is the only guard — RLS narrowing pending) · **own guardian links** — `GET /my/guardians` (S-READ-3 item 1): guardian name + link status, names for ACTIVE links only, no contact fields · a published programme's **list price** on the catalogue read (see Cannot/Can see below).
**Writes ✅:** create team (name only — intro/visibility 🔴 B-1) · **join team = DIRECT INSERT** ⚠️ (model requires request → lead accept → academy review, B-4/C-2 unbuilt — the student's most consequential write is one step where the design specifies three) · submit team (creator-only, client-gated + server-enforced) · book/cancel session · RSVP events.
**Cannot:** sign consent · **see any ORDER amount** — one family's obligation (P-3/B-18: one shared column, two viewers) · see another family/team · see unreleased results · read own consent PDF (designed ✅, built 🔴 P-4).
**Can see (fee ruling, S-READ-3 F-3):** a PUBLISHED programme's **list price** — `fee_total_minor` + currency on the catalogue read (`MarketplaceController:212`, family callers only; the ANONYMOUS payload carries no money field at all, which is what keeps `payment_links.single_reader` true). A list price is marketing, identical for every viewer, and is not an obligation; an order amount is. Both halves are named here on purpose — the distinction is the doctrine, and stating only one half is what made the previous line ("see any amount") read as a blanket prohibition it never was. Value = `SUM(fee_items.amount_minor)`, exactly what `OrderService` charges; zero fee items ⇒ **no field**, never HK$0.00.
**Gaps:** Tracker requirements (0 of 7 requirement types modelled) · stepper dates (transition-log read 🔴) · "Remind" (notifications D6 🔴) · programme term (schema 🔴) · invite codes (J-3/M-1 🔴).

### 3.2 GUARDIAN
**Does (as-designed, B2):** 5-slot world — Home (action inbox: consents to sign + orders to pay, deadline-sorted) · Children (child cards → per-enrolment rows → the same scoped space) · Consents (the signing ceremony) · Payments (itemised orders, receipts) · Me. Enrols children (Market Place via "Enrol a child"), signs consents, pays, requests withdrawal 🔴, links additional children.
**Reads ✅:** own children (via active `guardian_links`) mirrored across every family read — enrolments, team+roster, sessions, tracker, released results (**the guardian's read entitlement mirrors the child's** — proven byte-identical on tracker) · own orders WITH amounts + `order_lines` itemisation + gapless receipts · consent requests + full ceremony docs · child's school name (S-READ-2) · **own child links** — `GET /my/children` (S-READ-3 item 1): child name + link status, names for ACTIVE links only, no contact fields. This is the LINK read, not the enrolment read, so a newly-linked child with NO enrolment is visible — which is what closed the register→link→enrol dead-end (the Marketplace child picker used to derive children from enrolment rows) · a published programme's **list price** — `fee_total_minor` + currency, family callers only (`MarketplaceController::withFeeTotals`, ONE registered elevation per request; the anonymous payload carries no money field).
**Writes ✅:** **consent.sign — the only signer in the system** (scroll-gate server-recorded, language switch invalidates scroll+affirmation, three gates, sha-256 dual-hash evidence, signed PDF; exceeds spec) · enrol child (creates enrolment intent, no money at enrol — OD-31) · pay (provider link; FPS/manual 🔴 D-6 unruled) · initiate guardian-link ceremony (two-stage: ceremony → `pending_confirmation` → student confirms → `pending_approval` → ops `approveLink` → `active`; self-activation retired S04D).
**Cannot:** act for another family (RLS, proven per-card) · declare remittance (B-17 grants the school, D-6 unruled) · see staff notes/approver identities.
**Gaps 🔴:** withdrawal request path (API chain exists guardian→school-endorse→ops; **no UI for either edge step**) · cancel pending withdrawal (J-17) · co-guardian invite (J-21) · **notification prefs** (DU — no notifications table, route or client anywhere) · enrol-cascade consequence sheet · media-consent toggle + D1 revocation (P-1; `superseded` status may be reusable).

### 3.3 TEACHER (mentor)
**Does (as-designed, B3):** My Teams (roster, stage tracker, check-ins 🔴) · My Sessions (today view, attendance marking) · gate approval for linked teams (`teams.approve`, OD-61: teacher links to TEAM, not students; required before first gate) · grading where delegated 🔴 (permission doesn't exist — D-5).
**Reads ✅ (config-gated):** `programmes.mentor_team_access` (per-programme toggle, S-MENTOR-1) opens team/roster/gates reads via the mentor arms ⚠️ (P-HYGIENE-1 divergence: category-routed arms silently school-scoped — fix in flight) · linked teams' consent **status** (never documents) · own school's roll (`users_read`: students, teachers, admins of own school — **never guardians**).
**Writes ✅:** approve stage gates for linked teams (OD-39 in-service authority) · mark attendance ⚠️ (built as a Segmented toggle — design wants a transition request).
**Cannot:** reach another school · sign consent · see finance · see unreleased results beyond own grading scope · read tenures directly (no mentor arm — secondary finding, own card).
**Gaps 🔴:** whole mentor persona thin — no My-Teams surface proper, no today-view, no unavailability/substitute (J-18), no check-ins (C-7/M-4), delegated grading inexpressible (D-5).

### 3.4 SCHOOL_ADMIN
**Does (as-designed, B4 + Step 4):** the school shell 🔴 (**entirely unbuilt** — its 4 reachable surfaces are other personas' pages): roll management, bulk student creation ✅ (backend), enrolment batches ✅ (backend: CSV intake → per-row dispositions → commit), consent CHASE (never sign) 🔴, withdrawal endorsement (pastoral, non-authoritative — OD-26) 🔴 UI, school-settled billing view (consolidated invoices, OD-25/47) 🔴 UI, term report CSV (D-4 #5) 🔴, vouch (OD-30) ✅ backend.
**Reads ✅:** own school's roll · own school's teams (via lobby school) · consent status for roll · own consolidated invoices · batch dashboards.
**Writes ✅ (backend):** bulk create students (`AccountMintingService`, school-scoped elevation) · batch enrolment intake/commit · vouch · endorse withdrawal (API).
**Cannot:** sign consent (mis-offered link **closed** P0-SAFE-1 ✅) · reach another school (proven cross-school per-card) · any money write (school-settled is academy-recorded) · see all-schools override rows affecting it ⚠️ X-3.
**Gaps:** essentially the whole persona above the API 🔴, gated on D-5 + the School record page (C-series 🔴).

### 3.5 ACADEMY_ADMIN — by capability
- **operations** ✅ backend / 🟡 UI: registration approval (the ONE queue, S04C) · guardian-link approve/reject · Team Formation confirm (consent-complete gate, capacity claim, consequence ceremony ✅) · matching/assign/waive/dissolve ✅ · withdrawal decide ✅ · attendance oversight ✅ · assessment grade+release ⚠️ (E1 wants release separated from authorship; one actor today — blocked on vocabulary) · session lifecycle 🔴 UI (J-20) · programme wizard 🔴 UI (J-19, backend complete) · consent void ✅ route/🔴 surface.
- **finance** ✅: BI-9 four-eyes on manual payments (recorder ≠ confirmer, app + DB `WITH CHECK`; **Payments UI exceeds spec; Refunds cue added P0-SAFE-3** — reject leg intentionally un-guarded, verified) · refunds full-only + credit notes (immutable, system-insert-only) · consolidated invoicing + aging 🔴 UI · Financial Integrity assertions ✅ · reconciliation ✅/🟡 (verdict tag, not assertion list). ⚠️ **D-3 unruled:** AD-2 elevation deliberately shows finance the child's display name; Proposal E2 says blank it. Ratify one.
- **configuration** ✅ backend / 🔴 UI: wizard (hub-and-spoke, pre-flight, publish with version snapshot, locked sections audited) · fee items · withdrawal policy bands · certification/badge rules (exist — J-15 smaller than ranked) · team categories (lobbies) · consent template versioning.
- **audit_read** ✅: the immutable trail (read-only, zero write affordance) · consent evidence · access identity · enrolment-pool report (the arm that proved load-bearing) · 🟡 no export, no programme facet.
- **super_admin** = \* except `consent.sign`.

### 3.6 MEMBER
**Does ✅:** events, RSVP, member directory (adults only, `authz.member_directory_exclusive`). **Cannot:** everything else — the absence IS the control. **Gaps:** recognition surfaces (J-15, v2).

### 3.7 SYSTEM (non-human)
`asSystem` elevation register (74 entries, each with justification + WITHHELD allowlist, `ScopeElevationTest`-verified) · scheduled jobs audit with a system actor (OD-58) · transactional payment outbox at Team Formation · re-consent fan-out (OD-20a) · booking cascade + waitlist promotion on withdrawal.

---

## 4 · ROLE ↔ ROLE RELATIONSHIPS

| Relationship | Mechanism | State machine | Status |
|---|---|---|---|
| Guardian ↔ Student | `guardian_links` (+ origin) | the CHECK permits NINE states. Written: ceremony → `pending_confirmation` → confirm → `pending_approval` → **approveLink** → `active`; admin refusal = `rejected`, student decline = `cancelled` (distinct); `expired` (`LinkageService:210`) and **`revoked`** (`LinkRevocationService:67`) are reachable and belong in the machine. Permitted but NEVER written: `requested`, `superseded` | ✅ two-stage, audited; co-guardian invite 🔴 J-21 |
| Teacher ↔ Team | `team_teacher_links` | linked by ops; gate authority requires link (OD-61) | ✅; teacher↔students NEVER direct |
| Teacher ↔ School | school-stamped, single-school, offboarding-guarded (OD-54) | | ✅ |
| Student ↔ School | `school_links` (roll) | vouch (OD-30) / bulk mint; leaving mid-programme: team stands, academy exception (OD-56) | ✅ backend |
| Student ↔ Team | `team_members` | join (⚠️ direct insert; design = request→review) · formed-team writes platform-only (F-5 carve-out 🔴 — service-layer only today) | 🟡 |
| School ↔ Academy | delegation map (A-2) + school-settled billing (OD-25/47/48/49) | grants per school×programme; withhold wins per-programme | ✅ policy / 🔴 UI |
| Guardian ↔ Academy(finance) | orders/receipts; AD-2 name elevation ⚠️ D-3 | | ✅ |
| Member ↔ Academy | events only | | ✅ |

---

## 5 · ER SHAPE (from the 63 migrations — ground truth)

**Identity & access:** `users` (role, single) → `roles` / `permissions` / `role_permissions` / `capability_permissions` / `admin_capabilities` · `guardian_links` · `school_links` · `teacher_links` (school-stamped) · `team_teacher_links` · `permission_overrides` (**a jsonb COLUMN on the link entities, not a table** — deny-only trigger) · `school_authority_grants` + `programme_authority_overrides` (A-2) · `invitations` (single-use sha256, 14d) · `pairing_codes`.

**Programme & config:** `programmes` (status, version snapshot, `enrolment_opens/closes_at`, `starts_at` (writer landed FIX-REFUND-SEED — mirrored from `basics.starts_on`; the OD-2 refund window seeds from it), `ends_at` (writerless, AUDIT-2 A-1), `banner_upload_id`, `mentor_team_access` ⚠️ column-vs-override duality X-4) · `programme_versions` · `fee_items` · `withdrawal_policies` (banded, DB-validated) · `certification_rules` + `badge_rules` (✅ exist; issuance ledger 🔴) · `team_categories` (lobbies; school-bound or open) · `consent_templates` (versioned, trilingual) · `programme_capacity` (**not family-readable**; team-based, OD-31).

**Journey:** `enrolments` — status CHECK: `submitted | pending_consent | in_pool | teamed | confirmed | active | completed | withdrawn | released` (pool is a STATE, no waitlist table — OD-34; `released` is a real terminal status, `EnrolmentService:25`) · `consent_requests` (status: draft|sent|viewed|signed|declined|expired|**superseded**|**voided**; event_sequence; dual-hash evidence; **no `kind`** 🔴 P-1) · `teams` (forming|submitted|confirmed…; **no intro/visibility** 🔴 B-1) · `team_members` (denormalised category_id; programme_id incoming per P-HYGIENE-1) · `stage_gates` (5 fixed stages; passes only; approver_kind; **no requirement rows** — stage_requirements an empty shell; **no sequence lock** — "locked" is prototype invention D-7) · `tenures` (role ledger; **no mentor arm**) · `programme_sessions` + `session_bookings` (booked|waitlisted|cancelled|attended|no_show — session-level waitlist ✅ auto-promote) · attendance is **NOT a table** — it is a `session_bookings.status` value (`attended`/`no_show`), which the Learn gate computes from (S06-4, `LearnGateService:44-52`); amendments 🔴 C-8 · `assessments` (status CHECK, one enum: draft|published|open|closed|graded|**released**|cancelled — `released` is a STATUS VALUE, not a flag; **no rubric, no moderation seam, no max_score, no released_at** 🔴) · `assessment_results` (score, graded_by/at).

**Money:** `orders` (+ INSERT-only `order_lines`, trilingual snapshots) · `receipts` (gapless sequence) · `payments` (manual under BI-9: `rf`-style WITH CHECK) · `payment_links` (token-resolved, initials-only, `no_pii` assertion) · `refunds` (approved_by ≠ confirmed_by at DB) · `credit_notes` (immutable, system-only insert) · `consolidated_invoices` (school-settled, OD-25) · payment outbox · **absent 🔴:** `period_locks` (P-8), rubric, waiver reason enum (D-4 #2 rides credit_notes).

**Team-project finance (S07):** budgets · transactions (SoD CHECK) · fundraising · P&L; charity no-distribution enforced. ✅ backend, no UI (out of family scope v1).

**Trail:** `audit_events` (immutable, insert-from-any-context BI-8; before/after images) · `uploads` (scan pipeline: pending→clean/quarantined, BI-1/BI-10; consent PDFs classified).

**Absent entirely (confirmed by sweep §4A):** incident_notes ⚠️ **(no safeguarding-notes surface on a minors' platform — promote)** · mentor_checkins · session_materials · attendance_amendments · notifications · invite codes · join_requests · team_change_requests · merge_requests · period_locks · programme-level waitlist.

---

## 6 · WORKFLOWS (state machines as built; design deltas marked)

**Enrolment (OD-31/34 — intent, not allocation):**
`Submitted → Pending Consent → In Pool → Teamed → Confirmed → Active → Completed` (+ `withdrawn`, `released` terminals). No money, no seats at enrolment; **everything fires at Team Formation**: consent-complete gate → atomic N-seat claim under one FOR UPDATE (all-or-refuse, OD-32) → status flips → order issued via transactional outbox (family-paid) or invoice line (school-settled, OD-47). Formation deadline machinery + resilience ✅ (S05-3/4).

**Consent (S03, BI-6):** template (versioned, trilingual) → issue per enrolment (fresh per cohort OD-53; never batched OD-50) → sent/viewed → guardian ceremony (scroll-gate + language-switch invalidation + affirmation) → signed (dual-hash + PDF + audit certificate) — or declined/expired. Material change → re-consent fan-out marks `superseded`, blocks Formation (OD-52). Consent completeness gates team submission (OD-51). 🔴 kind/media-consent, D1 withdrawal, student read (P-1/P-4/B-20).

**Team Formation:** create (student) → forming (lobby wall shows count-only) → join ⚠️ direct-insert (design: request→lead→academy, B-4) → submit (creator-only) → ops review (consent gate + blocking-count advisory) → **confirm = the allocation moment** (seats+money+status, above) · matching/assign/waive/dissolve for the pool ✅ · formed-team membership exclusivity: service-layer only (F-5 carve-out 🔴).

**Delivery:** sessions published (lifecycle API ✅ / UI 🔴 J-20) → booking (capacity + session waitlist ✅) → attendance (mentor marks ⚠️ toggle-not-request; ops oversees) → Learn gate consumes attendance (S06-4) → withdrawal cascade cancels future bookings + promotes waitlist ✅.

**Tracker:** 5 fixed stages, pass-recorded by authorised approver (OD-39/OD-61); `passed_at` + `approver_kind` family-readable (identity + notes withheld); **no requirements, no sequence lock — done/todo only** (D-7 ×3).

**Assessment (E1):** draft→published→open→closed→graded→**released** (irreversible, danger-confirmed) · embargo at the read: family sees released only; `graded` byte-identical to `published`; unreleased never probed · 🔴 rubric display, moderation seam, release-separated-from-authorship, released_at/max_score.

**Family money:** order at Formation → payment link (OD-38: forwardable, initials-only, expiring, dead-once-paid) → provider (MockProvider; QFPay phase 2, HKD only, OD-40) → receipt (gapless) · manual payment: record (finance.record) → confirm (finance.confirm, ≠ recorder, app+DB) · refund: approve → confirm (four-eyes; reject unguarded **by design**) · reconcile nightly + manual (OD-43) · ⚠️ D-6: FPS/self-declaration unruled.

**School-settled money (OD-25/47/48/49):** batch enrol → invoice at Formation → "covered_by_invoice" order status → school pays → receipt · withdrawal ⇒ credit note always, refund-to-school if paid, balance assertion · batch failure = one academy exception on aging.

**Withdrawal (Step 5 split):** guardian requests 🔴 UI → school endorses (pastoral, non-authoritative, OD-26) 🔴 UI → ops decides ✅ (refund-window band ✅) → cascade ✅. Duplicate idempotent; decided final; conflicting guardian cancel is referred, never executed ✅.

**Registration/onboarding (S04C):** self-register → the ONE approval queue → activation; school verification gates programme access (OD-28 holding state); guardian-led student creation RETIRED (OD-27) ⚠️ stale `users_insert` guardian arm remains (X-2, hygiene card).

**Recognition (S08, v2):** certification_rules/badge_rules mint from tenures — config ✅, issuance 🔴.

---

## 7 · THE GAP REGISTER (design ↔ build), one line each

**Ruled & closed this rollout:** D-1 space key=enrolment_id ✅ · D-2 outline ban ✅ · mis-gated surfaces ✅ · queue sorts ✅ · Refunds cue ✅ · spine/chrome/tabs ✅ (18 cards, see BUILD-STATUS-R2).

**Unruled decisions:** D-3 (AD-2 vs E2 name) · D-4 (DENY-WINS canonicalisation) · **D-5 (vocabulary — gates the most)** · D-6 (guardian remittance) · D-7 running prototype-corrections list: 5-seg segbar vs 7 states · mentor flag-off card · "My guardians" on student Me · "Edit roster" on confirmed team · "· 5 members" on lobby wall **for non-members** (roster IS served to members) · "Led by {name}" on the wall · tracker now/locked tri-state · "Unlocks when…" copy · locked-gate requirement projections.

**Biggest designed-not-built blocks:** the school-admin shell (whole persona) · mentor persona surfaces · staff six regions / Enrolments-as-record / Formation board / Delivery / Config UIs (J-19/J-20 backend-complete) · notifications domain (D6/B-19 — blocks Remind, bell drawer, release notify) · incident_notes ⚠️ child-safety · withdrawal family/school UI · C-1/C-2 request grammars · B-1/B-2 team fields+codes · P-1 consent kind · P-3 orders narrowing · F-5 formed-team carve-out · Guardian/School record pages + delegation-config screen (needs X-3 + D-5).

---

*Next: Document 2 — `KAP-PROTOTYPE-WORKFLOW.md`, the prototype's workflows and UIUX screen by screen, derived from `docs/design/KAP-Prototype.html`. Then Document 3 aligns this reference against it with the prototype UIUX as the base.*
