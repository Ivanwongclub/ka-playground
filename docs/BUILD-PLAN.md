# KA Playground — Completeness Review & Build Plan (v1)

**Date:** 23 July 2026 · **Prepared by:** Tune Bright Limited
**Input:** Full Specification v4 · **Method:** four independent review passes over the spec, each with a distinct lens, findings then reconciled

---

# PART 1 — MULTI-PERSPECTIVE REVIEW FINDINGS

Four review lenses were run against v4 independently. Each traced the document itself — every finding below cites what is missing or broken, not generic advice. **Verdict: the spec is buildable, with 15 gaps to close before or during build. None invalidates the architecture; 6 are blocking (must be specified before their sprint), 9 are non-blocking (resolved by the amendments in Part 2).**

## Lens A — Close-Loop Auditor
*Traced every entity's lifecycle for dead ends and undefined exits.*

| # | Finding | Severity | Resolution |
|---|---|---|---|
| A1 | **Withdrawal → money is an open loop.** Enrolment has `Withdrawn`; Order has `Refunded`. Nothing connects them. A student withdrawing mid-programme has no defined financial outcome — full refund, pro-rata, or forfeit? | **Blocking** (Sprint 4) | Add a per-programme **withdrawal policy**: `full_refund_before` date, `pro_rata` bands, `no_refund_after` date. Withdrawal approval computes the refund per policy and opens a Refund record automatically. Amendment 2.1. |
| A2 | **Sole-guardian revocation strands the student.** Guardian links can be Revoked, but if the revoked link was the student's only one, an *active* enrolment now has no consent-capable guardian, and a signed consent whose signer is gone. | **Blocking** (Sprint 2) | Rule: revoking the last active guardian link of a student with non-terminal enrolments requires Academy Admin approval and immediately opens a "guardian replacement required" exception with a deadline; enrolments suspend if unresolved. Signed consents remain valid (signed against their version). Amendment 2.2. |
| A3 | **Sessions have no state machine of their own.** Bookings do; the session (lesson/event) itself can only be cancelled via a notification event. No Draft → Published lifecycle, and **no reschedule** — only cancel. Rescheduling a lesson is the most common real-world change. | **Blocking** (Sprint 6) | Session state machine: `Draft → Published → Full → In Progress → Completed / Cancelled / Rescheduled`. Reschedule keeps bookings, re-notifies, and re-opens booking if capacity changes. Amendment 2.3. |
| A4 | **Avatar moderation queue exists in the sitemap but has no state machine** and no schema states. | Non-blocking | `avatar_uploads`: `Pending → Approved / Rejected → Appealed → Final`. Amendment 2.4. |
| A5 | **Assessments appear in schema (N6) with no lifecycle.** | Non-blocking | `Assessment: Draft → Published → Open → Closed → Graded → Released`. Amendment 2.5. |
| A6 | **Mentors have no lifecycle.** A departing mentor with future booked sessions is undefined. | Non-blocking | `Mentor: Active → Inactive → Departed`; departure forces reassignment or reschedule of future sessions (uses A3's machine). Amendment 2.6. |

## Lens B — Data Integrity
*Looked for places where the 100%-accuracy guarantee can silently break.*

| # | Finding | Severity | Resolution |
|---|---|---|---|
| B1 | **Enrolment capacity race.** Optimistic locking is specified for the last team seat, but not for the last *enrolment* seat. Two simultaneous enrolments on a capacity of one both pass the read check. | **Blocking** (Sprint 4) | Same mechanism: capacity check and enrolment insert in one transaction with a `SELECT … FOR UPDATE` on the programme counter row; loser gets a clear "programme is now full / waitlist" response. Amendment 2.7. |
| B2 | **No idempotency on enrolment creation.** A double-submitted Enrol click creates two Intents, two orders, two consent requests. | Non-blocking | Idempotency key = (student, programme, active-or-pending); DB partial unique index. Same pattern on payment recording. Amendment 2.8. |
| B3 | **Announcements and Messages are in the sitemap (Parent › Messages, Content › Announcements) but absent from the Part N schema.** They'd be discovered mid-sprint. | Non-blocking | Add N-section: `announcements`, `announcement_audiences`, `message_threads`, `messages` (thread-scoped, role-checked). Amendment 2.9. |
| B4 | Reconciliation assertions cover money and counts but **not notification ladders** — a ladder that silently stopped (job crash) never fires an alert. | Non-blocking | Add nightly assertion: every active ladder's `next_run_at` is in the future or the ladder is closed. Amendment 2.10. |

## Lens C — Production Readiness
*Asked: can this actually run as production on day one?*

| # | Finding | Severity | Resolution |
|---|---|---|---|
| C1 | **Auth lifecycle is unspecified.** Sanctum is named, but password reset, email verification at invitation acceptance, session expiry, and account lockout after failed attempts are nowhere. For a minors-plus-UHNW platform this is not boilerplate to improvise. | **Blocking** (Sprint 1) | Full auth spec in Amendment 2.11: invitation-token onboarding, mandatory email verification, reset flow, lockout (5 fails → 15 min), session policy, all audit-logged. |
| C2 | **File upload hardening absent.** Receipts, consent evidence, deliverables, logos — no virus scanning, no MIME allow-list, no size caps in spec. | **Blocking** (Sprint 1, shared service) | One upload service: MIME allow-list per context, size caps, image re-encode, ClamAV scan job before the file becomes visible, quarantine on hit. Amendment 2.12. |
| C3 | **No rate limiting / abuse controls** on auth, pairing codes, reminders. Pairing codes are 6 chars — brute-forceable without throttling. | Non-blocking | Laravel throttle: auth 5/min, pairing-code attempts 5/hour/account + code invalidation after 10 global failures, reminder trigger 1/24h (already specified) enforced server-side. Amendment 2.13. |
| C4 | **Environments and backup/restore undefined.** ApsaraDB backups exist, but no staging environment, no restore drill, no OSS versioning statement. | Non-blocking | Envs: `local → staging → production` (staging = same Docker Compose, separate RDS + OSS bucket). Nightly RDS snapshot retained 7 days + weekly retained 4; OSS versioning on; one restore drill before go-live and quarterly after. Amendment 2.14. |
| C5 | **Deployment gate:** migrations are named but there's no zero-data-loss rule. | Non-blocking | Rule: migrations must be reversible or explicitly marked destructive and reviewed; production deploys run `migrate --pretend` gate in CI first. Amendment 2.15. |

## Lens D — Compliance & Audit
*Checked the audit trail against the modules, and the user's requirement that every module/sprint ships an audit report element.*

| # | Finding | Severity | Resolution |
|---|---|---|---|
| D1 | **The spec has one global audit architecture (Part P) but no per-module audit deliverable.** Nothing forces each sprint to prove its own close-loop before moving on. | **Blocking** (process) | Every sprint below ships a **Module Audit Report** — a queryable admin screen + exportable report proving that module's loop closes. Defined per sprint in Part 3. |
| D2 | Auth events (C1) must write to `audit_events` — currently only entity transitions do. | Non-blocking | Add auth event types: login, logout, failed login, lockout, reset requested/completed, invitation accepted. Amendment 2.11 includes this. |
| D3 | Data-retention execution is referenced (`retention_policies`) but has no job or report. | Non-blocking | Scheduled retention job + a Retention Execution Report under Audit & Compliance. Amendment 2.16. |

---

# PART 2 — SPEC AMENDMENTS (fold into v4 as v4.2)

**2.1 Withdrawal policy (per programme).** Fields: `full_refund_before_date`, `pro_rata_bands[{until_date, refund_pct}]`, `no_refund_after_date`, `withdrawal_requires_approval` (default true). Workflow: student/guardian requests → approver decides → on approval, system computes refund per policy → Refund record opens pre-filled → credit note on completion. Enrolment `Withdrawn` is reachable **only** through this workflow. Audit: request, computation inputs, approval, refund linkage.

**2.2 Guardian continuity rule.** Deleting/revoking the last active guardian link of a student with any enrolment in {Consent Pending, Payment Pending, Active} requires Academy Admin action, creates an exception with a 14-day deadline, suspends affected enrolments if unresolved, and notifies School Admin. Consents already signed remain valid against their signed version and signer identity.

**2.3 Session state machine.** `Draft → Published → Full → In Progress → Completed | Cancelled | Rescheduled`. Reschedule: retains all bookings, writes a session_version row (old/new datetime, reason), fires `session.rescheduled` (new event, channels as `session.cancelled`), re-opens booking if capacity increased. Cancel: all bookings → Cancelled, waitlist cleared, `session.cancelled` fires. Attendance can only be taken in `In Progress`/`Completed`.

**2.4 Avatar moderation.** `avatar_uploads.status: Pending → Approved | Rejected(reason) → Appealed → Final`. Approved swaps the active avatar atomically; Rejected notifies with reason; one appeal allowed.

**2.5 Assessment lifecycle.** `Draft → Published → Open → Closed → Graded → Released`. Results visible to student/guardian only at Released. Results feed Profile › Achievements (owner unchanged).

**2.6 Mentor lifecycle.** `Active → Inactive (no new bookings) → Departed`. Transition to Departed blocked while future sessions exist unless each is reassigned or rescheduled (2.3).

**2.7 Enrolment seat locking.** Capacity check + insert inside one transaction, `SELECT FOR UPDATE` on the programme's counter row. On conflict: if waitlist enabled → offer waitlist; else clear "programme full" response. Same pattern already specified for team seats — now symmetric.

**2.8 Idempotency.** Partial unique index: one enrolment per (student, programme) where status not in terminal set. Payment recording carries a client-generated idempotency key; duplicate submits return the original record.

**2.9 Schema additions (Part N).** `announcements` (title, body, audience_type, publish_at, status) · `announcement_audiences` (role/programme/school scoping) · `message_threads` (context_type, context_id, participant set) · `messages` (thread_id, sender_id, body, read_at). Messages are role-checked: a parent can open a thread only to staff of their child's programmes.

**2.10 Ladder liveness assertion.** Nightly: no reminder ladder in an open state has `next_run_at` in the past by >1h. Violation → reconciliation.mismatch alert.

**2.11 Auth lifecycle.** Invitation-only onboarding (per L4): academy issues invitation → guardian opens tokenised link (single-use, 14-day expiry) → sets password → **email verification mandatory before first login completes** → creates/links student. Password reset: tokenised email link, 1-hour expiry, single-use. Lockout: 5 failed attempts → 15-minute lock, audit-logged, admin-unlockable. Session: 12h idle expiry web, remember-me 30 days. All auth events write to `audit_events` (login, logout, failed_login, lockout, reset_requested, reset_completed, invitation_accepted, email_verified).

**2.12 Upload service.** Single shared service for all file intake. Per-context MIME allow-list (images: jpg/png/webp; documents: pdf; evidence: pdf/jpg/png), size caps (images 5MB, documents 15MB), images re-encoded server-side (strips payloads + EXIF), ClamAV scan as a queued job — file is quarantined and invisible until scan passes; hit → quarantine + admin alert + audit event.

**2.13 Throttling.** Auth endpoints 5/min/IP; pairing-code attempts 5/hour/account, code hard-invalidated after 10 global failed attempts; consent-reminder trigger enforced server-side at 1/24h; API default 60/min/user.

**2.14 Environments & backup.** `local → staging → production`; staging mirrors production compose with separate RDS/OSS. RDS: automated nightly snapshot (7-day retention) + weekly (4 weeks). OSS versioning enabled on receipts/consents buckets. Restore drill before go-live, then quarterly, results logged.

**2.15 Migration gate.** All migrations reversible or flagged destructive with review; CI runs `migrate --pretend` against a staging clone before production deploy.

**2.16 Retention execution.** Scheduled job applies `retention_policies` (anonymise/delete per policy), writes a Retention Execution Report (what, why, when, count) under Audit & Compliance.

---

# PART 3 — SPRINT BUILD PLAN

Ten sprints. Order is dependency-driven. **Every sprint ships three things: the module, its audit report element, and its reconciliation assertions.** A sprint is not done until its audit element proves the loop closes — this is the per-module audit requirement made concrete.

> **Sprint 0 — Foundation & kickoff**
> Move the MVP codebase into the build folder; extract reusable assets (logos, scheme images, hero/gallery imagery, icons) into the new repo's asset structure. Scaffold: Laravel 12 API + React/Vite/AntD Pro + Docker Compose (app, Postgres, Redis, Horizon, Nginx). Theme tokens per Part L (aubergine/gold, both algorithms, `App` wrapper for static methods, shared chart theme object). `audit_events` table with DB-level INSERT-only enforcement. Shared upload service (2.12). Environments (2.14). CI with migration gate (2.15).
> Mobile app-shell primitives land here too: bottom tab bar, navigation drawer (edge-swipe, no hamburger), bottom-sheet component with snap points and guarded swipe-to-close, PWA manifest — per Design System §17.
> **Audit element:** Audit Log viewer (Admin › Audit) — filterable by actor/entity/action/date; proves every write from Sprint 1 onward is captured from day one.
> **Assertions live:** audit table immutability probe (attempted UPDATE fails and alerts).

> **Sprint 1 — Identity, auth & invitations**
> Users, roles, permissions matrix, per-link overrides. Invitation-only onboarding, email verification, password reset, lockout, session policy (2.11). Throttling (2.13). Guardian/school/teacher link entities + pairing codes with continuity rule (2.2).
> **Audit element:** **Access & Identity Report** — auth event log, invitation funnel (issued→accepted→verified), active links per student, orphan/sole-guardian exception list.
> **Assertions:** every active student with a non-terminal enrolment has ≥1 active guardian link (will hold vacuously until Sprint 4; assertion ships now).

> **Sprint 2 — Programme configuration surface**
> Programme entity + versioning, hub-and-spoke wizard (Part D) with all 10 sections, readiness computation, pre-flight check, publish lock, templates. Role library, team rules, stage requirements, fee items, certification/badge rules, withdrawal policy (2.1).
> **Audit element:** **Configuration Audit Report** — per programme: config version history, who changed what and when, pre-flight results archive, locked-field change attempts.
> **Assertions:** no Published programme missing a consent template or fee items; version snapshots immutable.

> **Sprint 3 — Consent engine (in-house e-sign)**
> Templates (rich text + HTML source), versions with SHA-256, merge fields, signature anchors, signing flow (scroll-to-end, affirmation, drawn/typed capture), signed-PDF generation with audit certificate page, versioning/re-consent, decline path.
> **Audit element:** **Consent Evidence Report** — per programme: signature coverage by template version, outstanding/declined/expired lists, full evidence bundle export (PDF + hash + audit trail) per signature — the exact bundle a legal challenge would demand.
> **Assertions:** every Signed request has a document with matching hash; no active enrolment on a superseded version without an open re-consent request.

> **Sprint 4 — Enrolment, orders & receipts**
> Enrolment state machine with guardian precondition, seat locking (2.7), idempotency (2.8), hold-window expiry job. Orders, immutable lines, offline payment recording with evidence + segregation of duty, gapless receipt numbering in-transaction, refunds via withdrawal policy (2.1), credit notes. Student status timeline UI. Payer-party routing.
> **Audit element:** **Financial Integrity Report** — receipt sequence audit (gap probe), payments-vs-receipts reconciliation, refund→credit-note linkage, offline-payment evidence completeness, who-recorded/who-confirmed listing.
> **Assertions:** receipts total == confirmed payments; sequence gapless; every Active enrolment has signed consent + paid/waived order; no enrolment past hold deadline in a non-terminal state.

> **Sprint 5 — Teams, roles & Activity Tracker**
> Team formation full close-loop (all 12 gap mechanisms), role assignment with cardinality, rotation engine + tenures, stage gates with live condition evaluation, Tracker UI as view-over-modules, activation sequencing.
> **Audit element:** **Team Governance Report** — formation approvals with reasons, exception queue history (orphans/sub-minimum/leaderless), role tenure ledger, gate decisions with condition snapshots at decision time.
> **Assertions:** one active team per student per programme (DB-enforced, probed); every Passed gate's conditions still hold; badge count == completed tenures (fires fully in Sprint 8).

> **Sprint 6 — Learning: lessons, events, attendance**
> Session state machine incl. reschedule (2.3), mentor lifecycle (2.6), booking + waitlist with auto-promotion, attendance capture, Learn-threshold computation (per-student, team roll-up), assessments lifecycle (2.5), calendar.
> **Audit element:** **Attendance & Session Report** — per session: booked/attended/no-show with recorder identity; reschedule/cancellation history with notification proof; waitlist promotion log; Learn-threshold status per student.
> **Assertions:** attendance records == attended bookings; no Published session in the past without a terminal state; ladder liveness (2.10).

> **Sprint 7 — Team finance (record-only)**
> Budgets + approval, transactions with receipt upload, verification workflow, sponsorship/charity records (`project_type` per open decision 1), P&L reports, shared approval engine consolidation (formation/gates/budgets/transactions/deliverables/refunds all on one engine by end of sprint).
> **Audit element:** **Team Finance Verification Report** — budget vs actual vs verified per team, unverified-entry aging, approval chain per transaction, P&L export with full drill-down to evidence.
> **Assertions:** budget actuals == sum of approved transactions; every Verified entry has evidence attached.

> **Sprint 8 — Recognition & profile**
> Avatar library + moderation (2.4), badges auto-minted from tenures/criteria, certificates with issuance rules + token-gated verification, portfolio assembly/export, Achievements aggregation.
> **Audit element:** **Recognition Issuance Report** — badge/certificate issuance log with triggering criteria snapshot, revocations with reasons, verification-access log (who checked which certificate when).
> **Assertions:** badges == completed tenures + met criteria; certificates == students meeting issuance rules; no orphaned issuance.

> **Sprint 9 — Notifications, dashboards & reporting**
> Notification engine: rules, Handlebars templates per channel/language, preference matrix with transactional locks, ladders, quiet hours, delivery log, dead-letter surfacing. Dashboards: aggregates + triggers, role presets, widgets per Part I. Reports: standard catalogue, AR suite, exports, scheduling. Announcements + Messages (2.9). Retention job (2.16). Full nightly reconciliation suite assembled.
> **Audit element:** **Platform Assurance Report** — the capstone: nightly reconciliation dashboard (all assertions, pass/fail history), notification delivery proof, scheduled-report run log, aggregate-vs-source drift history. This is the screen that demonstrates the whole platform's close-loop to the client.
> **Assertions:** all previous sprints' assertions running nightly as one suite + notification/report liveness.

> **Sprint 10 — Hardening & UAT**
> Mobile gesture QA on real devices (sheet snap/dismiss, edge-swipe vs browser back, keyboard-aware sheets, safe areas, row-swipe actions). Restore drill (2.14), load pass on dashboards, security pass (throttle verification, upload-scan verification, permission matrix probe per role), seed data for UAT, bilingual content pass (EN/繁中), UAT with client, punch list.
> **Audit element:** **Go-Live Readiness Report** — restore drill result, security probe results, full reconciliation suite green for 7 consecutive days, open-decision register cleared or accepted.

> **Sprint 11 — Logto identity migration (final sprint, after UAT)**
> Deploy Logto (Docker, own Postgres DB) on `auth.` subdomain. Swap the Sanctum implementation behind the auth interface for the Logto SDK — application code untouched by design (Part O). Migrate accounts (email/verified-status carry over; passwords re-set via one-time migration links — never exported). Enable Google connector incl. One Tap, sync-at-sign-up only. Register external organiser OIDC clients when their details arrive. Invitation-only rule preserved: social sign-in binds to invitation tokens or verified-email matches only.
> **Audit element:** **Identity Migration Report** — per-account migration status, auth-method changes, failed migrations, OIDC client register with claims issued.
> **Assertions:** every pre-migration account resolves to exactly one Logto identity; no orphaned sessions; auth event logging continuity across the cutover.
> **Nothing in Sprints 0–10 depends on this sprint** — it can run post-go-live without blocking launch.

**Deferred (Phase 2, unchanged):** QFPay provider · organiser sync + live Design stage · certificate co-branding · verifiable credentials · custom avatar upload · WhatsApp/WeChat channels · custom report builder · AP module · drag-and-drop dashboard personalisation · mobile app.

---

# PART 4 — CLAUDE CODE KICKOFF NOTES

1. **First instruction of Sprint 0:** copy the MVP codebase into `./build-reference/` inside the repo, then extract into the new asset tree: logos, scheme/card images, hero and gallery imagery, favicons, any icon sets. Reference-only — no MVP code is imported into the new codebase; it is a visual/asset source and a behavioural reference.
2. Each sprint = one working branch, one migration set, and its audit element merged together — the audit report is part of the sprint's definition of done, not a follow-up.
3. The reconciliation suite grows additively: each sprint registers its assertions into the same nightly job introduced in Sprint 0.
4. Open decisions from v4 Part R that gate specific sprints: withdrawal policy values (Sprint 2 config), charity `project_type` confirmation (Sprint 7), sixth Member role (affects Sprint 1 role seed — decide before Sprint 1), brand assets (Sprint 0 theming can proceed on the L9 palette; swap tokens when assets arrive).

---
*End of document.*

---

# PART 5 — Revised sprint sequence (workflow-review handoff, applied 2026-07-26)

> From docs/handoff/BUILD-PLAN-EDITS.md. OD numbers below use the LIVE register numbering
> (handoff numbers +6). Card-name mapping: the handoff's S06-BATCH ≈ the S04E card,
> S-SELFREG ≈ the S04C/S04D pair, S-QFPAY = a new card to be written. The committed
> S04A–S04E cards were written BEFORE this handoff and are NOT yet reconciled to it.

| Sprint | Scope | Change |
|---|---|---|
| S00–S03 | Foundation · Identity · Access/RLS · Programme config · Consent | unchanged (built) |
| S04A | Enrolment states, awaiting-a-team pool, formation deadline, withdrawal workflow, per-programme independence, scheduled-job auditing | **REWRITE REQUIRED** — team-based capacity, not individual seats (OD-31/33/34/43/63/64/65) |
| S04B | Orders, receipts, credit notes, refunds, offline recording, payment link, MockProvider behind PaymentProvider | **REWRITE REQUIRED** — trigger (成團) lives in S05; BI-9 scoped to manual (OD-43/44/46/47/48/53/54) |
| S05 | Teams, lobbies, formation, approval routing, 成團 → seat claim → payment trigger, waivers, post-成團 control, teacher-team links | **REWRITE REQUIRED** — capacity claimed here (OD-32/35/37–42/57/58/61/62) |
| S06 | Sessions, attendance, assessment, member events | unchanged |
| S04E (≈S06-BATCH) | School bulk import, versioned Excel template, two payment modes, school-settled receivable, consolidated invoicing, batch failure path | **RECONCILE at card review (REPO-RECONCILE 2026-07-26):** bulk creates STUDENT accounts via the OD-27 primitive (OD-50); guardian invitations are register-only — consent then payment surface as portal tasks (OD-50a/50b; family-paid pays at 成團 through S04B machinery, school-settled rows have no family payment step); Excel-template model (OD-51); receivable model (OD-53/54); boundary check — a consent request addressed to a not-yet-registered guardian (bound to the invitation) is additive to S03, flag at review |
| S04C/S04D (≈S-SELFREG) | Registration + approval surfaces | **UNBLOCKED (REPO-RECONCILE 2026-07-26): Model B confirmed; S04C's account.provenance assertion correct as written. At S04C review: make the school-verification-gates-programmes holding-state consequence explicit (OD-28)** |
| S07–S09 | Team finance · Recognition · Notifications (delivers the OD-66 catalogue) | unchanged |
| S10 | Go-live readiness | **add:** QFPay merchant-application status is a launch gate; credential rotation; PDF/A decision |
| S-QFPAY | QFPay adapter for PaymentProvider: hosted session, webhook signature verification, idempotency, settlement reconciliation, async refunds | NEW CARD — Phase 2 pre-production, gated by merchant application; slots before S10 |

**Parallel client workstream:** QFPay merchant application (IN PROGRESS) is a first-class launch
dependency with its own status — not an S10 discovery. On approval, confirm Alipay CN enabled and
HKD settlement.

**Cross-cutting notes for every affected card:** scheduled jobs audit with a SYSTEM actor, never
null (OD-64) · every new table classified in its creating migration (S02A discipline) · every
module raises its notification events even though S09 delivers them (OD-66) · the awaiting-a-team
pool is ONE concept — never a separate waitlist (OD-34).
