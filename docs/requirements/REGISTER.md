# KAP — Requirement Register

> Completed in S00 STEP 1 from Full Specification v4.2 (`docs/spec/`), AMENDMENTS.md 2.1–2.27 and
> the resolved rows of OPEN-DECISIONS.md.
> IDs are permanent — add or mark withdrawn, never renumber. Commits cite sprint steps; sprint
> cards cite these IDs; that is the traceability chain.

## Numbering
`GR` general · `FR` functional · `SR` system · `OR` optional · `CF` critical/deal-breaker · `SLA` service level
Amendments and findings keep their existing numbers (A1–D3, E1–E11, 2.1–2.27) — they ARE register entries.

**CF note:** the critical/deal-breaker category is served by the ten Build Invariants in CLAUDE.md §3.
They are cited as `BI-1`–`BI-10` in commits and assertions; no duplicate CF rows are minted here.

**Precedence reminder:** where a row notes a supersession, the register row reflects the *effective*
requirement after CLAUDE.md → OPEN-DECISIONS → TEAM-CATEGORIES → AMENDMENTS ordering. The spec text
cited in Source is the origin, not necessarily the current rule.

## General requirements

| ID | Origin | Requirement | Source |
|----|--------|-------------|--------|
| GR001 | A | Trilingual EN / 繁體中文 / 简体中文 across all user-facing surfaces — widened from bilingual by OD-19; no hardcoded user-facing string from the first commit | Spec v4 · OD-19 |
| GR002 | A | Every workflow close-loop with complete audit trail; 100% report accuracy | Client requirement |
| GR003 | A | Per-sprint in-product audit report element | Client requirement |
| GR004 | A1 | Configuration, not code: nothing programme-specific hard-coded; the Admin portal is the product (~40% of Phase 1 effort) | Spec A1 |
| GR005 | A2 | Fixed platform-wide vs configurable per programme per the A2 matrix (five stages, state machines, audit model, roles, record-only finance, consent gate, sequential receipts are fixed; everything programme-shaped is config) | Spec A2 |
| GR006 | A3 | Two money flows never mix: programme fees (real money, Order module) vs team funds (record-only). Team funds never enter receivables; programme fees never appear in a team P&L | Spec A3 · M3 |
| GR007 | A4 | Every state change is an event in an append-only audit log; reports read from source of truth, never cached counters | Spec A4 (BI-1, BI-8) |

## System requirements

| ID | Origin | Requirement | Source |
|----|--------|-------------|--------|
| SR001 | L4 | **Self-registration with approval (OD-23 as re-decided by the client 2026-07-24; both the original "no public sign-up, ever" AND the interim request-not-account design superseded).** Students and guardians self-register (FR068); APPROVAL creates the account — routed to the named partner school's admins, or to the academy for direct registrations. Guardians never create students (OD-27). School accounts remain academy-invited; school bulk creation makes students directly. All linkage requires admin approval (2.30); person-approval ≠ relationship-approval, two audited decisions. Account state derives from links (OD-28); every self-registered account verifies its own email before first login (OD-29) | Spec L4 · R2 · **OD-23 · OD-27 · OD-28 · OD-29** |
| SR002 | O | Logto migration only after UAT (S11); Sanctum behind an auth interface until then | Spec Part O |
| SR003 | O | Alibaba Cloud HK hosting; ApsaraDB RDS (Postgres) + OSS; ECS + Docker Compose | Spec Part O |
| SR004 | N | Schema per Part N including the N12 database-level constraints: immutability by revoked UPDATE/DELETE, gapless receipt sequence in-transaction, consent/guardian checks, partial unique indexes, FOR UPDATE seat locks | Spec N1–N12 |
| SR005 | O | Stack: Laravel 12 JSON API · React 18 + TS + Vite + AntD 5/Pro · PostgreSQL · Redis + Horizon (visual queue, failed-job DLQ) · server-side PDF · react-signature-canvas · react-grid-layout | Spec Part O |
| SR006 | O2 | Shared upload service for all file intake: per-context MIME allow-list, size caps (images 5MB, docs 15MB), server-side re-encode stripping EXIF/payloads, queued ClamAV scan — file invisible until pass; hit → quarantine + alert + audit (BI-10) | Spec O2 · 2.12 |
| SR007 | O2 | Environments local → staging → production (staging = same compose, own RDS+OSS); nightly RDS snapshots 7d + weekly 4w; OSS versioning on receipts/consent buckets; restore drill before go-live then quarterly | Spec O2 · 2.14 |
| SR008 | O2 | Migration gate: every migration reversible or flagged destructive with review; CI runs `migrate --pretend` against a staging clone before production deploy | Spec O2 · 2.15 |
| SR009 | P2 | One immutable `audit_events` table (INSERT-only at the DB, UPDATE/DELETE revoked from the app role) with the P2 column set; actor always one identity (BI-1, BI-8, OD-17) | Spec P2 |
| SR010 | P3 | Nightly reconciliation suite running every P3 assertion (incl. the 2.21 Withdrawal Cascade), results to `reconciliation_log`, mismatch → Academy Admin alert + `reconciliation.mismatch` | Spec P3 · 2.21 |
| SR011 | P4 | No silent failure: every external call a queued job with status, bounded retries, dead-letter surfaced in admin UI; every scheduled job writes a run record; a job that fails to run is itself an alert | Spec P4 |
| SR012 | B9 | Throttling: auth 5/min/IP · pairing codes 5/hour/account, hard-invalidated after 10 global fails · reminder trigger 1/24h server-side · API default 60/min/user | Spec B9 · 2.13 |
| SR013 | N11 | Compliance registers: `data_access_log`, `consent_register`, `cross_border_transfers` (destination jurisdiction per transfer), `data_subject_requests`, `retention_policies` + scheduled retention execution with report | Spec N11 · L5 · 2.16 |
| SR014 | L5 | Time & money representation: single timezone `Asia/Hong_Kong`, timestamps stored UTC rendered HKT, `jurisdiction` field retained (spec L5 per-user timezone rendering **superseded by OD-16**); every monetary value carries an ISO currency code and integer minor units, HKD Phase 1, multi-currency Phase 2 (OD-18). **Clarified (Leo, 2026-07-23): OD-16's single timezone governs DISPLAY only — it does not remove per-entity timezone fields. `sessions.timezone` (Spec N6 line 1633, rationale L5 line 1280) is required and stays, because an event may be held outside Hong Kong. No contradiction with FR062** | Spec L5 · OD-16 · OD-18 |
| SR015 | L9–L11 | Theming: aubergine/gold token system per Design System v2.1 (binding); **`darkAlgorithm` only — no light mode, no toggle** (spec L10 "user-toggleable" **superseded** by client decision 23 Jul 2026); `cssVar: true`, `hashed: false`; `App` wrapper for static methods; shared chart theme object; ProLayout shell with §17 mobile app-shell below 768px | Spec L9–L11 · DESIGN-SYSTEM.md |
| SR016 | 2.25 | Logto cutover: enumerate outstanding invitation/reset tokens; honour via compatibility route or void-and-reissue with notification; assertion — no pre-cutover token unresolved | 2.25 (S11) |
| SR017 | 2.26 | Deploy & rollback runbook: deploy = pull tagged images + compose up; every prod deploy an annotated tag; one-command rollback to previous tag; destructive-migration review decides DB restore; rehearsed on staging (S10) | 2.26 |
| SR019 | S01 review | **Student delivery gating (D2, Leo 2026-07-24):** no notification, reminder or link is ever delivered to a student email address that has not been **independently verified**. Guardian-created student accounts are born login-verified (the guardian's authenticated act vouches sign-in, B10), but delivery-verification is a SEPARATE, unmet-by-default state — a mistyped address must never receive a child's attendance or assessment data. S09's notification pipeline enforces this at the send layer; nothing sends before S09 | S01 AUDIT §5 · gates S09 |
| SR018 | O | Mobile shell: app-like responsive (bottom tab bar, edge-swipe drawer — no hamburger, bottom sheets, gestures) + PWA manifest per Design System §17 | Spec O · §17 |

## Functional requirements

| ID | Origin | Requirement | Amendments / decisions |
|----|--------|-------------|------------------------|
| FR001 | B1 | Six identity roles: Student · Parent/Guardian · Teacher · School Administrator · Academy Administrator · Member. Roles never stacked on one account. Academy Administrator carries capability groups (`super_admin`, `configuration`, `finance`, `operations`, `audit_read`); grants/revokes audited | OD-1 · OD-17 |
| FR002 | B2–B3 | Relationship model: student↔guardian many-to-many (mandatory for enrolment), student↔school many-to-one active, student↔teacher many-to-many, teacher↔school, one active team per student per programme, team↔programme | — |
| FR003 | B4 | **Guardian linking (amended by OD-23/OD-27, 2026-07-24 — B4's auto-completion superseded by 2.30):** pairing codes (6-char, 7-day/first-use expiry, max 5 active), parent-initiated email flow and student confirmation establish MUTUAL INTENT; the link then enters `pending_approval` — only a school admin (of the student's school) or platform admin activates it. School vouching collapses initiation+approval into the vouching admin's single audited act. Additional-guardian links further require OD-24 (existing-guardian initiation, all-guardian visibility) | L4 · R2 · **2.30 · OD-27** |
| FR004 | B5 | Relationship state machine Requested → Pending Confirmation → Active → Revoked / Expired / Superseded / Cancelled; every transition audited with actor + reason; revocation never deletes history | — |
| FR005 | B6 | Multiple and changing guardians: several active links, independent portal access; one signer suffices unless `consent_requires_all_guardians`; separated guardians never see each other's contacts; guardian change supersedes the link, signed consents stay valid. **Adding a further guardian requires the existing guardian's initiating action + student confirmation + admin approval, visible to ALL existing guardians — never silent (OD-24; approval alone does not satisfy the visibility requirement)** | OD-10 · **OD-24** |
| FR006 | B7 | Two-layer permissions: role defaults + per-link JSONB overrides + programme scope, with Effective Permissions Preview in admin | — |
| FR007 | B8 | Guardian continuity: revoking a student's last active link with a non-terminal enrolment requires Academy Admin action + reason, opens a 14-day replacement exception, unresolved → enrolments Suspended (never auto-Withdrawn); signed consents remain valid | 2.2 |
| FR008 | B9 | Auth lifecycle: single-use invitation tokens (14d), mandatory email verification before first login, reset link 1h single-use invalidating sessions, lockout 5 fails → 15 min (admin-unlockable), 12h idle web session, remember-me 30d; all auth events → `audit_events` | 2.11 · 2.13 |
| FR009 | B10 | Google sign-in is a sign-in method, never a registration channel: accepted only via valid invitation token or verified existing email; students authenticate with guardian-created credentials; never bypasses profile completion or consent | OR001 |
| FR010 | C | Activity Tracker: five fixed stages Plan · Design · Learn · Pitch · Launch; a view over owning modules, holding only stage definitions and gate records; Learn individually scoped with team roll-up, gate passes at configurable member percentage | OD-12 |
| FR011 | C1 | Stage-gate workflow Not Started → In Progress → Submitted → Under Review → Passed / Returned (reason mandatory); condition regression demotes; gates evaluated live, never cached — asserted nightly | — |
| FR012 | C2 | Tracker activation sequencing: locked until enrolment Active; Learn available before team; full tracker on team lock | — |
| FR013 | C4 | Session lifecycle Draft → Published → Full → In Progress → Completed / Cancelled / Rescheduled; reschedule first-class (bookings retained, `session_versions` row, re-notification with clash count, booking reopens if capacity grew); attendance only In Progress/Completed; no dangling Published session | 2.3 · 2.24 |
| FR014 | C4 | Mentor lifecycle Active → Inactive (no new bookings) → Departed; Departed blocked while future sessions exist unless reassigned/rescheduled | 2.6 |
| FR015 | C4 | Assessment lifecycle Draft → Published → Open → Closed → Graded → Released; results visible to student/guardian only at Released, aggregating in Profile › Achievements | 2.5 |
| FR016 | D1–D3 | Programme setup wizard: hub-and-spoke checklist (10 sections), independent section save, readiness recalculation, dependency-disabled spokes with inline explanation, conditional fields | — |
| FR017 | D4 | Pre-flight check with Error (blocks publish) / Warning / Info severities | — |
| FR018 | D5 | Programme lifecycle Draft → Ready → Published → Enrolment Closed → Running → Completed → Archived; Published is a one-way door — first enrolment locks fees and consent template; changes create versions, existing enrolments keep their terms | — |
| FR019 | D6 | Programme templates: any configured programme saves as a template; creation from template clones all sections back to Draft | — |
| FR020 | E1–E2 | Consent before payment in one guardian-facing flow; enrolment preconditions (≥1 active guardian link, Published + window, eligibility, capacity or waitlist); blocked attempts held as intent with reason; hold window default 7 days, configurable | OD-11 |
| FR021 | E3–E4 | Enrolment state machine Intent → Consent Pending → Payment Pending → Active → Completed / Withdrawn / Suspended / Declined / Expired / Abandoned; expiry via scheduled job, never user action; every transition audited (BI-7, BI-8) | — |
| FR022 | E5 | Student status timeline per enrolment showing exactly what blocks and who holds it; guardian reminder limited to 1/24h server-side | — |
| FR023 | E6 | `payer_party` = parent (default) / student / school; payment record visible in both portals, only the pay action moves | — |
| FR024 | E7 | Withdrawal close-loop — the only route to Withdrawn: request (reason) → approval → policy-computed refund with inputs snapshotted into the audit event → pre-filled Refund record → credit note; seat release, waitlist promotion and team consequences in the same transaction | 2.1 · 2.21 · OD-2 (seeds provisional) |
| FR025 | E8 | Enrolment waitlist lifecycle Waiting → Offered (48h) → Accepted / Expired / Declined / Withdrawn; seat release promotes head-of-queue inside the 2.7 lock; no free seat while Waiting exists and booking open | 2.18 |
| FR026 | E8 | Concurrency & idempotency: capacity check + insert in one transaction with `SELECT … FOR UPDATE` on the counter row (BI-3); partial unique index one non-terminal enrolment per (student, programme); client idempotency keys on payment recording; duplicates return the original (BI-4) | 2.7 · 2.8 |
| FR027 | E8 | Wrong-amount and late payments: late payment → Late Payment exception (reinstate if seat free, else 2.17 refund + notify — never silent); overpayment → receipt at order amount + credit note or refund; **underpayment never recorded** (OD-5a — 2.20's underpayment path struck); `unmatched_payments` fed only by overpayments and late payments, aged, no Unmatched >7d without resolver | 2.19 · 2.20 · OD-5 · OD-5a |
| FR028 | 2.22 | Multi-guardian authority: any active guardian may act; acting guardian recorded on every action; conflicting actions → Academy Admin exception, never auto-executed; refund destination = original payer, not requester | 2.22 · OD-6 |
| FR029 | F1 | Order module entities with mutability rules: orders (status transitions only), order lines immutable after issue and copied — never referenced — from fee items (BI-5), payment attempts append-only, receipts and credit notes immutable, refunds append-only | — |
| FR030 | F2 | Order state machine Draft → Issued → Awaiting Payment → Partially Paid → Paid → Refunded / Partially Refunded, with Overdue (reminder ladder, no auto-cancel), Cancelled (reason + credit note if paid), Voided | — |
| FR031 | F3–F4 | Payment attempts: offline enters at Pending, admin-confirmed; mandatory evidence (1..n images per OD-5); **recorder ≠ confirmer mandatory, server-side** — spec F4's "programme may require" is **superseded by OD-14** (BI-9); both identities stored; both require `finance` capability | OD-5 · OD-14 |
| FR032 | F5 | Receipts: `KA-{YYYY}-{NNNNNN}` gapless, allocated by DB sequence inside the confirming transaction (BI-2); immutable; unlimited DUPLICATE-watermarked reprints, each audited; PDF + SHA-256 to OSS; 7-year retention (IRO s.51C) | — |
| FR033 | F6 | Refund machine Requested → Approved → Paid Out → Confirmed / Rejected; payout requires evidence; recorder ≠ confirmer (BI-9); destination = original payer party; withdrawal refunds arrive pre-filled from E7 computation; net position == receipts − credit notes asserted nightly | 2.17 |
| FR034 | F7 | Gateway abstraction: `PaymentProvider` interface with ManualProvider (Phase 1); QFPay is Phase 2 — **no Phase 1 scaffolding** | — |
| FR035 | G1–G3 | In-house consent templates: rich-text + HTML source modes, `{{merge}}` fields, signature anchor tokens; publish blocked without a `{{signature}}` anchor; ETO Cap. 553 reliability basis | R15 (placeholder wording until lawyer text) |
| FR036 | G4–G6 | Signing: authenticated guardian session, scroll-to-end gate, affirmation checkbox always required, drawn (PNG + stroke vectors) / typed capture, full evidence capture (server NTP timestamp, IP, UA, version hash, event sequence), signed PDF generated and hashed to OSS | — |
| FR037 | G5 · G7 | Consent request machine Draft → Sent → Viewed → Signed / Declined (reason, releases seat immediately) / Expired → Superseded; versions immutable, language-scoped with one SHA-256 each (BI-6, OD-20); material changes supersede and re-request, non-material apply forward; consent signed while student was a minor stays valid for the enrolment (OD-7 default) | OD-20 · OD-7 · 2.27 |
| FR038 | G8 | Audit certificate page on every signed PDF: title, version, version SHA-256, signer identity + relationship, method, HKT timestamp with UTC offset, IP, UA, event sequence, PDF's own hash | — |
| FR039 | H1–H4 | Bulk enrolment: CSV upload, per-row validation with error report, batch machine Draft → … → Complete / Closed with exceptions; per-row states; live batch dashboard with chase actions; individual guardian consent never batched away | — |
| FR040 | H5 | Batch payment arrangements: school (one order per student, one consolidated invoice, one itemised receipt), parent (individual), mixed (per-row split, both receipts referencing the same order) | — |
| FR041 | I1 | Widget dashboards on a 12-column grid; Academy Admin defines role presets; per-user layout JSON with reset-to-default; global filter bar; **drag-and-drop personalisation is Phase 2** | — |
| FR042 | I2–I4 | Charting: AntD Charts default, ECharts for dense views (OR003); chart-type-to-metric mapping; meaning never encoded by colour alone; chart palettes and theme per DESIGN-SYSTEM.md (binding) with brand colours never stretched into categorical palettes | — |
| FR043 | I5 | Role dashboard content per I5 tables (Student, Guardian, Teacher, School Admin, Academy Admin — action-required and compliance widgets first) | — |
| FR044 | I6 | Dashboard data flow: trigger-maintained aggregate tables, scheduled refresh, role scoping at the query layer never in UI, nightly aggregate == source assertion | — |
| FR045 | J1 | Standard report catalogue across enrolment, consent, progress, attendance, teams, finance, AR/AP, recognition, audit | — |
| FR046 | J2 | Custom report builder (no SQL) with server-side role scoping — **Phase 2** | — |
| FR047 | J3 | AR: aging summary/detail, outstanding by customer, DSO, collection status, customer statements, revenue recognition — computed live from the ledger | — |
| FR048 | J4 | AP: aging, vendor balances, commitments (vendors = mentors, organisers, venues) — **Phase 2** | — |
| FR049 | J5 | Export PDF/XLSX/CSV; scheduled reports as queued jobs with delivery logging; failed scheduled report raises an alert | — |
| FR050 | K1 | Notification event catalogue per K1 (≈40 events with recipients, channels, timing) | — |
| FR051 | K2 | Handlebars templates per event × channel × language (EN/TC/SC), variable groups, defaults/conditionals/iteration, live preview; publish blocked on undefined variable | OD-19 |
| FR052 | K3 | Preference centre: category × channel matrix, three layers (system → role → user); transactional categories locked on | — |
| FR053 | K4 | Delivery pipeline: rule match → recipient resolution → preference filter → quiet hours → 5-min dedupe → one job per recipient per channel → adapters; bounded retries; hard fail → dead letter + admin alert; hard bounce → Contact Unreachable exception, ladders to invalid addresses pause | 2.23 |
| FR054 | K5 | Reminder ladders configured per event with escalation; a ladder stops when its condition clears; every send logged as notification proof; liveness asserted nightly (no `next_run_at` in the past >1h) | 2.10 |
| FR055 | K6 | Quiet hours 21:00–08:00 HKT with urgent override; per-user daily cap folding overflow into a digest | — |
| FR056 | L4 | Privacy defaults (family-office posture): authenticated-only catalogue, invitation-only registration, team visibility `private` by default, peer directory off unless enabled, configurable student name display (first name + initial default), **avatar library only** (R12), token-gated certificate verification, achievements visible to self/guardian/staff only | R2 · R12 |
| FR057 | L6 | Charity as `project_type` (`sponsorship \| charity`) on Pitch-stage deliverables and finance records; charity funds never distributed to team members — asserted | OD-4 |
| FR058 | L7 | Member role surfaces: event listings, RSVP, members directory only — no team, tracker, enrolment, consent, finance or student-data access | OD-1 |
| FR059 | P1 · N4 | Team formation per `docs/TEAM-CATEGORIES.md` (canonical): admin-created formation lobbies per programme (trilingual names, optional school binding, `assignment_rule`, default lobby via partial unique index), formation state machine Draft → … → Locked → Active; one active team per student per programme; a team belongs to one lobby for life — spec "School/Armour Team" fixed types **superseded** | OD-13 · OD-13a · OD-13b |
| FR060 | P1 · N4 | Team roles from the programme role library; **manual rotation recording in Phase 1** (cadence originates externally, syncs via Logto at/after S11); tenure ledger is the system of record: Scheduled → Active → Completed → Badge Issued / Terminated Early | OD-15 |
| FR061 | A3 · N5 | Team finance record-only: budgets with lines and categories, transactions with uploaded evidence, approval chain (student → Finance Manager → teacher), verification against offline reality, team P&L as portfolio evidence | — |
| FR062 | N6 · C | Sessions (lesson/event) with quota, waitlist policy, `counts_toward_learn`, location + timezone; bookings with waitlist position and promotion; attendance recorded against bookings, only In Progress/Completed | — |
| FR063 | N7 · D2§9 | Recognition: badges == completed role tenures; certificates **academy-issued only — no co-branding, no external signatories** (spec line 1842 struck; OD-21), trilingual templates, token-gated verification, revocable; portfolios with export | OD-21 · OD-15 |
| FR064 | N7 | Avatars: library only in Phase 1 (R12); upload moderation machine Pending → Approved / Rejected (reason) → Appealed → Final, atomic swap, one appeal | 2.4 |
| FR065 | N7a | Announcements with audience scoping (role/programme/school); message threads role-checked — a guardian may open a thread only to staff of their child's programmes | 2.9 |
| FR066 | B8 · E8 | Unified Admin › Exceptions queue: guardian replacement (14d deadline), late payment, guardian conflict, contact unreachable — each with owner, deadline and resolution state, visible to the roles the spec names | 2.19 · 2.22 · 2.23 |
| FR067 | Q | Sitemap: v3 sitemap plus Q1–Q5 deltas (student enrolment tabs + payments, parent consent centre + pay/receipts, teacher gate/refund approvals, school batch + billing tabs, admin wizard hub, orders/receipts/AR/AP, consent management, notification rules, dashboard presets) — plus 2.28 deltas (public registration page; School Administrator Registration Requests tab) | 2.28 |
| FR068 | OD-23 | **Self-registration flow (REWRITTEN 2026-07-24 for the client model; proposed S04C):** public student AND guardian registration forms — anonymous INSERT-only under the `public` scope context confined to exactly one policy (structural assertion), constant-shape responses, no status endpoint, no reads, no uploads, throttled + honeypot (the S06B anonymous-write design, reused). Routing: named partner school → that school's approval queue; direct → academy queue (`schools.public_listing` opt-in governs the picker; "direct" is a first-class choice — no free text). **APPROVAL CREATES THE ACCOUNT** (unverified; OD-29 verification before first login). A registration naming a guardian/student counterpart yields a PENDING LINK at approval — auto-matched by exact email, or HELD until the counterpart is approved (no manual matching, no existence oracle). **Held links materialise only against a VERIFIED address (OD-29 makes this free) — a typo'd email must never surface a stranger as a routine pending link; the queue marks held-link origin "claimed by a registration form, not confirmed by either party" so the approver checks rather than clears; held links EXPIRE (default 90d, configurable) and expiry is surfaced in queue-age reporting.** One queue per approver for accounts AND links; age visible; over-threshold requests escalate into the FR066 exceptions queue; combined review presents account+link as one work item carrying TWO recorded decisions | **OD-23 · OD-27 · OD-28 · OD-29** · 2.28 · 2.30 · SR001 |
| FR201 | OD-31 | Team-based capacity: seats allocate to the team at 成團, claimed atomically at approval | OD-31/26 |
| FR202 | OD-33 | Per-programme formation deadline; ordering (enrolment close → formation → payment) validated at publish and on edit | OD-33 |
| FR203 | OD-34 | Awaiting-a-team pool replaces the individual waitlist | OD-34 |
| FR204 | OD-35 | Unteamed-at-deadline resolution: match / roll (parked, 90-day auto-refund backstop) / release | OD-35 |
| FR205 | OD-37 | Team-below-minimum exception with four terminal actions (assign / grace-once / waiver / dissolve) | OD-37 |
| FR206 | OD-38 | Team dissolution re-pools paid members in-lobby, paid status retained, no re-charge | OD-38 |
| FR207 | OD-39 | Team approval: school approves normal teams, academy handles exceptions; team-linked teacher may approve | OD-39 |
| FR208 | OD-40 | Size waiver stored as a team field with reason; nightly check reads "meets rules OR waiver" | OD-40 |
| FR209 | OD-41 | Post-成團 changes academy-only, reasoned, audited, notified; paid removal via withdrawal workflow | OD-41 |
| FR210 | OD-43 | Payment triggered on entering a confirmed team; deadline default 7 days | OD-43 |
| FR211 | OD-44 | Forwardable payment link, initials-only, expiring, dead once paid | OD-44 |
| FR212 | OD-46 | PaymentProvider interface; MockProvider Phase 1; QFPay Phase 2 gated by merchant application; HKD only | OD-46 |
| FR213 | OD-49 | Manual "reconcile payment" action alongside nightly gateway reconciliation | OD-49 |
| FR214 | OD-50 | Bulk import creates student records + guardian invitations; existing people matched, not duplicated | OD-50 |
| FR215 | OD-51 | Config-driven, version-stamped, pre-filled Excel template | OD-51 |
| FR216 | OD-53 | School-settled receivable: invoice at 成團, "covered by invoice" status, receipt on real payment | OD-53 |
| FR217 | OD-54 | School-settled withdrawal = credit note always; refund-to-school if already paid; balance assertion | OD-54 |
| FR218 | OD-55 | Batch failure = single academy exception on invoice aging | OD-55 |
| FR219 | OD-56 | Consent never batched; consent deadline + school-admin escalation for non-responders | OD-56 |
| FR220 | OD-57 | Consent completeness gates team submission (成團) | OD-57 |
| FR221 | OD-58 | Stale consent re-consent blocks 成團; material change updates all three languages | OD-58 |
| FR222 | OD-59 | Fresh consent per cohort (per-enrolment, not per-child) | OD-59 |
| FR223 | OD-60 | Teacher lifecycle: invited, school-stamped, single-school, offboarding-guarded | OD-60 |
| FR224 | OD-61 | Teacher links to team (not students); may approve that team's gates; required before first gate | OD-61 |
| FR225 | OD-62 | Student leaving school mid-programme: team stands, academy exception | OD-62 |
| FR226 | OD-63 | Enrolment independence per programme (student × programme) | OD-63 |
| FR227 | OD-64 | Scheduled-job state changes audit with a system actor | OD-64 |
| FR228 | OD-66 | Notification catalogue: 21 events, transactional/informational, per channel × language | OD-66 |
| — | — | *(handoff FR200 — self-registration request-not-account — NOT applied: contradicts FR068 under the later client model change; awaiting Leo ruling)* | — |

## Optional requirements

| ID | Origin | Requirement | Source |
|----|--------|-------------|--------|
| OR001 | B10 | "Continue with Google" via Laravel Socialite in Phase 1 (optional; One Tap deferred to Logto) — bound to FR009's invitation rules | Spec B10 |
| OR002 | L5 | Vietnamese localisation — flagged worth scoping, not committed | Spec L5 |
| OR003 | I2 | Apache ECharts for dense visualisations (heatmaps, scatter >10k points) | Spec I2 |

## Amendment map — each amendment to the requirement(s) it modifies

| Amendment | Modifies | Lands in |
|-----------|----------|----------|
| 2.1 | FR024 (withdrawal policy fields + workflow) | S02 / S04B |
| 2.2 | FR007 (guardian continuity) | S01 |
| 2.3 | FR013 (session machine) | S06 |
| 2.4 | FR064 (avatar moderation) | S08 |
| 2.5 | FR015 (assessment lifecycle) | S06 |
| 2.6 | FR014 (mentor lifecycle) | S06 |
| 2.7 | FR026 (seat locking, BI-3) | S04A |
| 2.8 | FR026 (idempotency, BI-4) | S04A / S04B |
| 2.9 | FR065 (announcements & messages) | S09 |
| 2.10 | FR054 (ladder liveness) | S06 registers / S09 full |
| 2.11 | FR008 (auth lifecycle, BI-8) | S01 |
| 2.12 | SR006 (shared upload, BI-10) | S00 |
| 2.13 | FR008 · SR012 (throttling) | S01 |
| 2.14 | SR007 (environments & backup) | S00 (envs) / S10 (drill) |
| 2.15 | SR008 (migration gate) | S00 |
| 2.16 | SR013 (retention execution) | S09 |
| 2.17 | FR033 (refund machine, BI-9) | S04B |
| 2.18 | FR025 (waitlist lifecycle) | S04A |
| 2.19 | FR027 · FR066 (late payment exception) | S04B |
| 2.20 | FR027 (wrong-amount; underpayment path **struck by OD-5a**) | S04B |
| 2.21 | FR024 · SR010 (Withdrawal Cascade assertion) | S04B + later |
| 2.22 | FR028 · FR066 (multi-guardian authority) | S04A/B |
| 2.23 | FR053 · FR066 (bounce handling) | S09 |
| 2.24 | FR013 (reschedule clash check) | S06 |
| 2.25 | SR016 (Logto cutover tokens) | S11 |
| 2.26 | SR017 (deploy & rollback runbook) | S00 (runbook) / S10 (rehearsal) |
| 2.27 | FR037 (age-of-majority default; OD-7 still open) | register only |

## Change log
| # | Date | Change | IDs added/withdrawn |
|---|------|--------|---------------------|
| 1 | 2026-07-23 | S00 STEP 1: register completed from Spec v4.2 + AMENDMENTS 2.1–2.27 + resolved OD rows. GR001 widened to trilingual per OD-19. Amendment map added. Known supersessions annotated in-row (spec line 1842 struck; team categories per TEAM-CATEGORIES.md; OD-14 SoD mandatory; OD-16 single timezone; dark-only theme) | GR004–GR007, SR004–SR018, FR001–FR067, OR001–OR003 added; none withdrawn |
| 2 | 2026-07-23 | SR014 clarified per Leo: OD-16's single timezone governs display only; per-entity timezone fields remain (`sessions.timezone`, N6/L5) | none |
| 3 | 2026-07-24 | SR019 added (student delivery gating, D2 narrowing from Leo's S01 review) — gates S09 | SR019 added |
