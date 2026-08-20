# AUDIT-2 — the build against the reference set

**Read-only.** 20 Aug 2026 · against `main` @ `6a2d02c` (working tree clean at audit start).
Nothing was fixed, changed or migrated in the course of this audit; the only artefact is this file.

**Reference set audited** (precedence order on conflict): `docs/reference/KAP-SYSTEM-REFERENCE.md` (Doc 1) ·
`docs/reference/KAP-DATA-ATLAS.md` (Atlas) · `docs/reference/KAP-PROTOTYPE-WORKFLOW.md` (Doc 2) ·
`docs/reference/KAP-ALIGNMENT-PLAN.md` (Doc 3).

**Precedence applied throughout:** the docs are DERIVED. Where a doc disagrees with a migration, a live policy
or a source file, **the source wins** and the doc error is recorded in §B — never silently corrected.

**Prototype freshness assert (passed):**
```
docs/design/KAP-Prototype.html   387,150 bytes (≥380,000 ✓)   2,635 lines   md5 972a2e6736b5047afcce2f2e7f325ee5
```

**Ground-truth counts taken at audit time:**

| Measure | Docs say | Actual | Note |
|---|---|---|---|
| migration files | 62 (Doc 1 header, Atlas subtitle) | **63** | +1 = `2026_08_20_100000_mentor_arm_programme_denorm` (the migration the docs themselves list as in-flight) |
| `Schema::create` tables | 86 (Atlas subtitle) | **86** | ✓ exact |
| live tables in `kap` | — | 87 | 86 domain + `migrations`; Laravel infra included (`cache`, `jobs`, `sessions`, …) |
| reconcile assertions | 60 | **60** classes, 60/60 green | ✓ exact |
| `asSystem` elevation entries | "~60" (Doc 1 §2.5, §3.7) | **74** | see B-9 |
| prototype screens | 57 | **57** (`class="page`) | ✓ exact |
| prototype `data-p` blocks | 42 (Doc 2 header) | **51** | see B-10 |

---

## §A · BUILD-vs-DOCS DIVERGENCES

Class per Doc 3's vocabulary (CL/RW/MG/DU/DR/PW); **DEFECT** where the divergence is a build fault rather
than a planned gap — the vocabulary has no class for "this is wrong", and two findings need one.

### A-1 · The programme run term is NOT a migration — the columns already exist · class **RW + write-path**, was MG · severity MEDIUM

Three documents plan a migration for a column that shipped on 25 July.

- Docs: Atlas §2C `programmes` row — "enrolment_opens/closes_at (**no run term** 🔴 MG)"; Doc 1 §5 same;
  Doc 3 §1 (stu-progs) "term **MG**"; Doc 3 §7 Migrations list — "programme term".
- Source: `api/database/migrations/2026_07_25_120000_create_withdrawal_policy_tables.php:18-21` adds
  `programmes.starts_at` + `programmes.ends_at` ("Basics dates (D2§1) — OD-2 seeds key off the programme start").
  Live schema confirms both columns present.

The gap is not the schema. It is that **nothing writes them**: the wizard stores the start date as JSON in
`wizard_sections.basics.starts_on` (read by `TrackerService.php:44-52` for the FR012 lock), and a repo-wide
grep finds no writer for `programmes.starts_at` outside `events`/`programme_sessions` (unrelated tables).
Live: the one published programme carries `starts_at = NULL, ends_at = NULL`.

**Correct reclass:** a write-path card (wizard `basics` → the columns, or a considered decision to drop them)
plus an RW to serve the term. Not a migration. This also makes the term a **duality of exactly the X-4 kind
the docs already flag for `mentor_team_access`** — the same fact in a column and in wizard JSON, with
different readers. Recommend it be tracked as X-5 alongside X-4.

### A-2 · DEFECT (HIGH, money) — the OD-2 provisional withdrawal policy seeds NULL bounds

Direct consequence of A-1, and it fails in the direction that costs the family.

- `api/app/Services/Programmes/WizardService.php:355` — publish calls `seedProvisional`.
- `api/app/Services/Programmes/WithdrawalPolicyService.php:84` —
  `'full_refund_before' => $programme->starts_at, 'no_refund_after' => $programme->starts_at`.
- `programmes.starts_at` is never written (A-1) ⇒ both bounds are **NULL** for every programme published
  without an explicitly configured policy.
- `WithdrawalPolicyService.php:98-108` (`refundPctAt`): NULL `full_refund_before` skips the 100% branch; with
  no bands the function returns **0**.

So OD-2's stated provisional intent — *"full refund before start · no refund after start · approval required"*
(`docs/OPEN-DECISIONS.md:90`) — silently becomes **"no refund window at all"**. The audit could not observe it
in the dev database because the demo programme was published by a path that skipped `WizardService::publish`
(`programme_versions` is empty), which is itself why this has gone unnoticed.

Mitigating: a programme whose policy is configured through `PUT /admin/programmes/{id}/withdrawal-policy`
(`WithdrawalPolicyService.php:56`) is unaffected, and settlement is full-only regardless (A-3). The defect is
that the *provisional* seed is inert while presenting as configured (`seeded_provisional = true`).

### A-3 · Banded refund percentages have no production consumer · class **DEFECT (dead path)** · severity MEDIUM

- Docs: Atlas §2C `withdrawal_policies / withdrawal_bands` — "banded refund % · DB-validated … band shown at
  decision + in mWd ceremony"; Doc 1 §6 "ops decides ✅ (refund-window band ✅)"; Atlas §4.7 "refund-window band
  as evidence".
- Source: `refundPctAt()` (`WithdrawalPolicyService.php:96`) is referenced **nowhere in `api/app`** outside its
  own definition. Settlement is `WithdrawalSettlementService.php:13-17` — *"FULL-only (OD-48)"* — and never
  consults a band or a percentage.

**Split the claim, because half of it is true:** the refund *window* (`full_refund_before` / `no_refund_after`)
IS served to the decision queue and rendered with full/partial/none tones and a closing-window sort
(`web/src/pages/Withdrawals.tsx:55-67`). It is the *percentages* that are inert: `withdrawal_bands` is
validated at the API and the DB, editable by config, and consumed by nothing. Under OD-48's full-only rule
that may be correct-by-design — but then the config surface offers a control that cannot move money, and the
docs should say so.

### A-4 · `ops-assess` is a zone of Programme 360, not a screen · class **CL (WRONG-SHAPE)** · severity LOW

Doc 3 §5 records "ops-assess ✅ + release ceremony ✅". The assessment machine, grading and the release
ceremony are built — inside `web/src/pages/Programme360.tsx:30-49` (`NEXT` map draft→…→released, `GRADEABLE`)
reached at `/admin/programmes/:id/overview`. There is **no `/admin/assessments` route** in
`web/src/main.tsx:108-168`. Doc 2 §5 specifies `ops-assess L1731` as its own screen carrying the
programme-filter subtab pattern. PRESENT-but-WRONG-SHAPE, not MISSING.

### A-5 · The student "Me" is built, not a placeholder · class **CL (WRONG-SHAPE)** · severity MEDIUM

Doc 3 §1 records "stu-me 🔴 placeholder". Two different Me surfaces exist and the docs conflate them:

| Persona | Nav gate | Route | Reality |
|---|---|---|---|
| student | `enrolment.view && events.rsvp` (`web/src/nav.tsx:104-107`) | `/my/profile` → `MyProfile` → `Profile360` (product density) | **built** — RecordShell, programme record, journey, released results, team/roles glance |
| guardian | `isGuardianActor` (`web/src/nav.tsx:139-142`) | `/me` → `Placeholder` (`web/src/main.tsx:134`) | 🔴 placeholder, as documented |

So the student card is not "build a Me surface" but "reshape the built one to `stu-me` L661" (identity, school,
language, sign-out — and "My guardians", which D-7 already rules PW). Planning it as 🔴 would rebuild a surface
that exists.

### A-6 · The guardian child record exists · class **CL (content gap)** · severity LOW

Doc 3 §2 records "gua-child 🔴 … child record page **CL** (reads exist)". The route and page exist:
`web/src/main.tsx:136` → `ChildProfile` → the same `Profile360` product grammar. What is missing against
`gua-child L750` is composition (identity/school header, per-enrolment cards drilling to the scoped space),
not the surface.

### A-7 · Four `guardian_links` states exist that no document names; two are reachable · class **MG-adjacent / doc** · severity MEDIUM (child-safety relationship)

DB CHECK (live): `requested, pending_confirmation, pending_approval, active, rejected, revoked, expired,
superseded, cancelled` — **nine**. Every document draws five (Atlas §2A, §4.3; Doc 1 §4).

| State | Reachable? | Evidence |
|---|---|---|
| `expired` | **yes** | `api/app/Services/Identity/LinkageService.php:210` |
| `revoked` | **yes** | `api/app/Services/Identity/LinkRevocationService.php:67` |
| `requested` | not written by any service found | — |
| `superseded` | never written to `guardian_links` (0 hits) | — |

Two live states — one of them *revocation of a guardian relationship* — are absent from the state machine of
record, and two enum values are dead. Both halves matter on a minors' platform: the first because the
documented machine is what a reviewer checks against, the second because a permitted-but-unwritten state is an
invitation for a future path to set it with no transition rules.

### A-8 · Doc 3 Phase A is 3-of-4 done since the docs were written · class **plan drift** · severity LOW

| Phase A item | Status now |
|---|---|
| P-HYGIENE-1 (gates mentor) | ✅ `c56e5d4` — denormalised `programme_id`, composite FKs, arms identical, 12-actor RIDER-1 |
| Payments amber | ✅ `6a2d02c` |
| V3 supersession | 🟡 partial — the two §C3-forbidden blocks deleted from the staff surface (`6a2d02c`); `parked/v3-student360-staff` **stays** (ruled: not superseded, nothing rebuilt) |
| owed rig artefacts | 🔴 still owed — the rig needs `FIDELITY_PASSWORD`, absent on this machine |

Consequently **the mentor persona is unblocked**: Doc 3 §3's gate ("Mentor persona is gated: P-HYGIENE-1 →
then CL cards") is satisfied, and `men-comp` (flag-gated composition) no longer has a blocker.

### A-9 · Staleness the docs predict about themselves · class **doc drift** · severity LOW

Doc 1 §2.4 still reads "P-HYGIENE-1 (ruled, build approved 20 Aug)" and Atlas §2D reads "programme_id
**incoming**" for both `team_members` and `stage_gates`. Both landed at `c56e5d4`: the columns are `NOT NULL`,
backfilled from `teams`, and carry `tm_team_programme_fk` / `sg_team_programme_fk`. Live policy shas moved as
predicted — `stage_gates_read` `81e135f3…` → `209c8e30…`, `tm_read` now `c559eba2…`, `teams_read` unmoved at
`f28e2e86…`.

---

## §B · DOC ERRORS — the doc is wrong, the source is right

Each with the exact line to correct and the correction.

| # | Doc · line | Says | Correct |
|---|---|---|---|
| **B-1** | Doc 1 §3.3, §4 (Teacher↔Team row), §6 (Tracker); Atlas §2D `team_teacher_links` | "**OD-55**: teacher links to TEAM, not students" | **OD-61**. `docs/OPEN-DECISIONS.md:78` is the teacher→team link. `docs/OPEN-DECISIONS.md:72` (OD-55) is the *batch failure path* (school-settled invoice aging). Source agrees with OD-61 throughout: `2026_07_28_140000_roles_and_tracker.php:9`, `TrackerService.php:14`. Four citations to fix. |
| **B-2** | Atlas §2E `orders` row | "payer_party (`guardian \| school` — CHECK ◇)" | ◇ **resolved: three values** — `ord_payer_check` = `guardian \| student \| school`, with `ord_school_payer_check` tying `school` to a non-null `payer_school_id`. Matches the E6 mapping the reconcile assertion states (parent→guardian, student→student, school→school). |
| **B-3** | Atlas §2D `assessments`; Doc 1 §5 | "`draft \| published \| open \| closed \| graded \| cancelled` **+ `released` flag path**" | `released` is a **status value in the same CHECK**, not a flag: `draft, published, open, closed, graded, released, cancelled`. Doc 1 §6's arrow chain is right; the Atlas row is not. |
| **B-4** | Atlas §2D `consent_requests`; Doc 1 §5 | "full set: draft·sent·viewed·signed·declined·expired·superseded" | Add **`voided`** — 8 values. Introduced by `2026_07_25_150000_add_voided_status_to_consent_requests.php`, used by `POST /admin/consent-requests/{id}/void` (`routes/api.php:163`), which Doc 1 §3.5 itself lists ("consent void ✅ route"). |
| **B-5** | Atlas §4.3 + §2A; Doc 1 §4 | guardian-link machine of five states | Nine (see A-7). At minimum add `revoked` and `expired`, both reachable, and mark `requested`/`superseded` as permitted-but-unwritten. |
| **B-6** | Atlas §4.1 (enrolment state diagram) | forward edges + withdrawals only | Three legal BACKWARD edges are missing, all in `EnrolmentService.php:22-33`: `in_pool → pending_consent` (re-consent), `teamed → in_pool`, and **`confirmed → in_pool`** (S05-4 dissolution re-pool, keeps the paid order — commented at `EnrolmentService.php:27-28`). The last is materially important: it is the only path where a *confirmed, paid* enrolment moves backwards. |
| **B-7** | Doc 1 §5 "Journey" list | lists **`attendance`** as a table | No `attendance` table exists. Attendance is a `session_bookings.status` value (`attended \| no_show`) and the Learn gate computes from it (`LearnGateService.php:44-52`). The Atlas §2D gets this right; Doc 1 §5 and the Atlas §3 diagram node `ATT[attendance]` do not. |
| **B-8** | Doc 1 §5 "Identity & access" list | lists **`permission_overrides`** as a table | It is a **jsonb column** on the link entities (`2026_07_24_130000_create_invitations_and_link_tables.php:47`) with a narrow-only trigger (`2026_07_24_210000_scope_layer_rls.php:39-41`). Atlas §2A states it correctly; Doc 1's ER list does not. Same line: "`authority_grants` + override tables" — the real names are `school_authority_grants` and `programme_authority_overrides`. |
| **B-9** | Doc 1 §2.5 and §3.7 | "~60 entries" in the elevation register | **74** entries in `api/config/scope-elevations.php`. |
| **B-10** | Doc 2 header | "42 `data-p` blocks" | **51** occurrences. (Screen count 57 ✓ and every spot-checked line cite is exact — see C-1.) |
| **B-11** | Doc 1 header (source ①); Atlas subtitle | "derived from `KAP-ALL-MIGRATIONS.txt`" | **That file does not exist** — not in the working tree, not anywhere in git history. The nearest artefact is `docs/design/KAP-Schema-Raw-Columns.txt` (987 lines, "TABLES/ALTERS FOUND: 109", installed by `c13bf1d`; moved out of `docs/reference/` by CLEANUP-1). A primary source citation that cannot be resolved undermines every "sourced" claim built on it — repoint both citations at the real file, or regenerate and commit the named one. |
| **B-12** | Atlas §2D `sessions / programme_sessions / session_versions` | lists `sessions` as a journey table | `sessions` is **Laravel infrastructure** (created with `users` in `0001_01_01_000000_create_users_table.php`, the session driver). The domain tables are `programme_sessions` + `session_versions` + `session_bookings`. |
| **B-13** | Atlas §2B (Delegation) | lists **`role_library`** under delegation | `role_library` is *programme config* — created in `2026_07_25_100000_create_programme_config_tables.php` beside `certification_rules`/`badge_rules`, consumed by `tenures`. Move it to §2C. (Its ◇ is otherwise resolved — the table exists.) |
| **B-14** | Doc 1 §5, Atlas §2C | "no run term 🔴" | See A-1: `programmes.starts_at` / `ends_at` exist and are unwritten. The correction is "columns exist, no writer, no reader beyond the OD-2 seed", not "absent". |
| **B-15** | Atlas §2A `mentors` ◇ | "mentor registry rows — check shape at audit" | ◇ **resolved**: `mentors(id, user_id, status, created_at, updated_at)` — a thin registry, exactly as guessed. |

Not an error, but worth recording as a naming hazard: this report was carded into **`docs/audits/`** while the
repo already has **`docs/audit/`** (singular, holding `S04C-…`–`S07-TEST-AUDIT-LIVE.md` and
`SYSTEM-AUDIT-S00-S04B.md`). I followed the card exactly. Two directories one letter apart will cost someone an
hour eventually — worth a rename decision.

---

## §C · CONFIRMATIONS WORTH RECORDING

Claims that were previously asserted and are now proven at the source, with the proof.

| # | Claim | Proof |
|---|---|---|
| **C-1** | Doc 2's line citations are trustworthy | Spot-checked five at random: `stu-space L470` ✓, `gua-home L701` ✓, `notifPanel L1950` ✓, `NOTIFS L2493` ✓, `t-track L559` + `GATES L2412` ✓ (verified in S-TRACKER-1). Screen count 57 ✓. Only the `data-p` count is off (B-10). |
| **C-2** | Doc 1 §2.1's matrix is verbatim | Every row checked against `api/config/permission-matrix.php:37-83`: role defaults, the `consent.sign`-guardian-only row, `capability_forbidden`, and all five capability groups match exactly. |
| **C-3** | Doc 1 §2.2's delegable catalogue is exact | `api/config/delegable-capabilities.php:31-53` — 10 delegable / 8 never, key for key, including the `never_reserved` formed-team marker. |
| **C-4** | **S-1 is true as stated** — the delegation map reaches no client | `MeController::show` returns `id/name/role/permissions` from `PermissionResolver::effectivePermissions` only; `EffectiveCapabilityResolver` appears nowhere in it. The A-3 per-programme capabilities are invisible above the policy layer, exactly as Doc 1 §2.4 claims. |
| **C-5** | **P-3/B-18 is true at the policy layer, not merely a UI worry** | `orders_read` (live) admits `student_id::text = current_setting('app.actor_id')` — a student's own order rows, amounts included, are RLS-readable. UI restraint is indeed the only guard. |
| **C-6** | `programmes` has **no RLS** — the fact the P-HYGIENE-1 diagnosis rested on | `pg_class.relrowsecurity = f` for `programmes`, vs `t` for `teams`/`team_members`/`stage_gates`/`team_categories`. The Atlas §2C annotation is exactly right. |
| **C-7** | The enrolment status CHECK matches the docs character for character | Live: `submitted, pending_consent, in_pool, teamed, confirmed, active, completed, withdrawn, released` — including `released` as a real terminal, as Doc 1 §5 insists. |
| **C-8** | The negative space is real | Confirmed absent from the live schema: `incident_notes`, `mentor_checkins`, `session_materials`, `attendance_amendments`, `notifications`, `join_requests`, `team_change_requests`, `merge_requests`, `period_locks`, invite codes, programme-level waitlist, rubric. `stage_requirements` exists and is the documented empty shell (`id, programme_id, stage, requirements, approver_role`). |
| **C-9** | Immutability and gaplessness are enforced where claimed | `audit_events` carries `audit_events_immutable_guard` (a real trigger, BI-1); `receipt_sequences(key, next_number)` is the gapless mechanism (BI-2); `uploads.status` = `pending \| clean \| quarantined` (BI-10). |
| **C-10** | The sider is FLAT, as the prototype requires | `AppShell.tsx:109-110` renders `visibleLeaves` (flat) on desktop; `NavGroup` survives only as the data source and for the mobile drawer. No group headers render. Doc 2 §0's "FLAT item list" is satisfied. |
| **C-11** | Student nav is 4 items, exactly as the prototype specifies | The three Community items all gate on `member_directory.view` (`nav.tsx`, community group), which no student holds — so a student sees Home · My Programmes · Marketplace · Me. The guardian's 5-vs-6 gap is the documented missing **Requests** slot, not an accident. |
| **C-12** | Doc 3's own class discipline holds up | Every gap I could test resolved into the class Doc 3 assigned, with the two reclasses in §A (term MG→RW, ops-assess ✅→WRONG-SHAPE) the only exceptions across ~30 checks. |

Loop check (Atlas §5) — the card asks for seven; **the Atlas states eight**. Each confirmed as written: Money
✅ closed (60/60 assertions, `period_locks` still the open edge) · Consent 🟡 · Formation ✅ closed at the
transaction · Withdrawal 🟡 (and see A-3 for a further open edge the doc does not name) · Audit ✅ closed ·
Delegation 🔴 open (C-4 proves the "never surfaced" edge) · Recognition 🔴 open (no issuance table exists) ·
Notification 🔴 absent (no table, no route, no client).

---

## §D · THE STALE-LITERAL CLASS

The three sites named in the card, plus everything else in the same class found en route: a hardcoded colour
where a v3 token exists.

| # | Site | Literal | Token that should be there | Note |
|---|---|---|---|---|
| **D-1** | `web/src/pages/AdminProgrammes.tsx:399` | `rgba(251,191,36,.12)` | `var(--ka-warning-tint)` | Live. Same marker shape as the one fixed in Payments — a straight mirror of `6a2d02c`. |
| **D-2** | `web/src/pages/Ds2Gallery.tsx:37` (`WARN`) | `rgba(251,191,36,.12)` | `var(--ka-warning-tint)` | Live, and the worst of the three by consequence: it is the **gallery**, so it teaches the retired colour to every future card that copies from it. |
| **D-3** | `web/src/ds2/structure.css:9` `.ds2-subpanel--action` | `rgba(251,191,36,0.07)` + `rgba(251,191,36,0.32)` | `var(--ka-warning-tint)` + `var(--ka-warning-line)` | **The token was created to replace exactly this line** — `tokens.css:78` says so verbatim ("formalises the literal already in `.ds2-subpanel--action`") and the replacement never happened. Its neighbour `.ds2-subpanel--attested` (line 8) already uses `var(--ka-gold-tint)`/`var(--ka-gold-line)`, so this is a one-line outlier in an otherwise tokenised block. Current blast radius is small (`tone="action"` is used only in the gallery, 3×) but it is a DS2 primitive: the next product surface to use the action tone inherits retired amber silently. |
| **D-4** | `web/src/pages/AdminProgrammes.tsx:181` and `:412` | `#7A3B57` ×2 | none exists | Different sub-class: an un-tokened brand plum, not a *retired* value. Either promote it to a token or state it is intentional one-off chrome. |
| **D-5** | `Profile360.tsx:239` · `SelfService.tsx:236` · `Ds2Gallery.tsx:46` | `#2c2338` → `#3a2f4a` avatar gradient | none exists | The same literal pair copied into three files — the shape a token exists to prevent. Worth one `--ka-avatar-*` pair or a shared component. |

Not defects, for the record: `Payments.tsx:214` and `Refunds.tsx:107` still contain the string
`rgba(251,191,36,.12)` — inside the CLEANUP-1 comments that *name* the retired colour. They will appear in
every future grep for it; that is deliberate documentation, not a live style.

---

## Recommended next cards (from this audit only)

1. **A-2 first** — the provisional-policy NULL bounds. Money, and it fails against the family. Small fix,
   but it needs a ruling on which source of truth the term takes (A-1) before the fix can be written.
2. **A-1/X-5** — the programme-term duality: write path + RW, and delete or justify the unwritten columns.
   This removes a planned migration from Doc 3 §7 rather than adding one.
3. **B-1** — the OD-55/OD-61 mis-citation, four places. A wrong OD number in the reference set is how a future
   card ends up implementing the wrong decision.
4. **B-11** — repoint or regenerate `KAP-ALL-MIGRATIONS.txt`; the whole reference set cites it as source ①.
5. **A-7 / B-5** — draw the real guardian-link machine, and rule on the two dead enum values.
6. **§D-3** — the DS2 `--action` tone, before a product surface adopts it.

*Method note: every §A/§B row was verified against the source file or the live database at audit time; where a
claim could not be proven either way it was left out of this report rather than softened into it.*
