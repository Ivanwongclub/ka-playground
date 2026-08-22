# KAP — REFERENCE REFRESH B
### The Phase-B truth, folded into the four greenfield reference docs
This is the CHANGE SPECIFICATION for docs/reference/* after Phase B. The repo copies are canonical (they carry DOC-FIX-1/2); apply these as edits, never regenerate wholesale. AUDIT-2/3 are dated reports — untouched. Every change cites its source commit.

---

## 1 · KAP-SYSTEM-REFERENCE.md

**§2.4 (delegation map)** — P-HYGIENE-1 line → CLOSED ✅: programme_id denormalised onto team_members + stage_gates, backfilled from teams under system context, composite FKs `(team_id, programme_id) → teams(id, programme_id)` make divergence unrepresentable; all three mentor arms now character-identical (`c56e5d4`). Elevation register count → **79** (S-READ-3 added 2; AUDIT-3 verified).

**§3.1 STUDENT — Reads, add:** published programmes' **list price** (`fee_total_minor`, authed marketplace read — fee ruling `f0004d1`) · **GET /my/guardians** (active links: display name via AD-2-shape elevation; pending: status only, nameless) · consent signature facts return **NULL to a student** (`cs_read` admits the signer only — the B8 join widened the list, not the table).
**§3.1 Cannot** — replace the amounts line with the ruled split: *"Cannot see any ORDER amount — one family's obligation (P-3/B-18). Published LIST PRICES are marketing, identical for every viewer, and family-visible (fee ruling; MarketplaceController:212)."*

**§3.2 GUARDIAN — Reads, add:** **GET /my/children** (own links, names active-only, status for pending — closes the register→link→enrol dead-end) · receipts (`receipt_number`, `issued_at` — inherit order visibility) · signed-consent facts on the list (`signed_at · version · language`, 1:0..1 join, `f7e5d61`).
**Writes, add:** pairing-code **redeem** (guardian redeems; the student generates — the prototype named the wrong actor).
**Surfaces now composed:** action-inbox home · child hub · Fees tab (guardian-only, structurally lazy) · two-register consents ("History") · Me with the redeem ceremony.

**§3.5 configuration** — window fields: `enrolment_opens_at/closes_at` write path on ProgrammeController **retired (422)**; `WizardService::syncBasicsDates` is the SOLE writer, mirroring `basics.starts_on / enrolment_opens_on / enrolment_closes_on` (clear-on-absence) (`e6525d5`, `b8aa74b`). `hold_window_days`: **OD-11 obsolete** — validated, snapshotted, read by nothing.
**§3.5 operations/audit** — consent-evidence report carries a **fifth bucket: expired** (`b50fdc2`).

**§6 Consent workflow** — add the expiry machinery: every issuance writes `expires_at = min(issued_at + 14d, starts_at)` — clamped **only when the start is still future** (a running programme never mints a born-expired consent); nightly `consents:expire` (02:15 HKT, before reconcile) moves `sent|viewed` past expiry → `expired` under CAS (a same-second signature wins); **never** touches the enrolment (BI-7), **never** re-issues; NULL-expiry legacy rows ignored. Expired rows: ops report bucket + family History with error-tone pill.

**§7 Gap register** — move to RULED: fees visible (published, authed family) · OD-11 obsolete · window-writer consolidation · expired = error tone · TTL clamp. **D-7 list corrections:** REMOVE "My guardians on student Me" (RW all along — route was missing, RLS admitted; built at B9) and "· 5 members" *as a member-facing item* (roster serves it; the LIST omission is cost/RW). ADD: "Start a pairing code" on gua-me names the wrong actor. Unruled unchanged: **D-3 · D-4 · D-5 (long pole) · D-6** (+ ops-wd "Endorse" label vs OD-26).

---

## 2 · KAP-DATA-ATLAS.md

**§2C programmes** — row now reads: `starts_at` **written** (syncBasicsDates ← basics.starts_on, HKT→UTC) · `enrolment_opens_at/closes_at` **written by the same sole writer** ← basics keys (`b8aa74b`); ProgrammeController write path retired 422; `ends_at` still writerless (AUDIT-2 A-1) · `hold_window_days` obsolete (OD-11 note).
**§2C fee_items** — add: *"SUM(amount_minor) is served to the authenticated family via the marketplace read under ONE asSystem elevation per request (fee ruling); `fee_items_read` itself unchanged; the anonymous payload carries no money field (`payment_links.single_reader` true)."*

**§2D consent_requests** — `expires_at`: **now written at every issuance** (all four paths re-arm; TTL 14d clamped future-start) · `expired`: **the dead enum value now has its writer** — the nightly sweeper, CAS, status-guarded, decided rows untouched · signature facts (`signed_at·version·language`) ride the family list read via 1:0..1 LEFT join; `cs_read` still admits the signer only.
**§2D team_members / stage_gates** — add `programme_id` (NOT NULL, backfilled from teams, composite FK to `teams(id, programme_id)`); the mentor arms resolve identically on all three reads; stage_gates gains its first referential integrity.

**§4.2 consent diagram** — add the edge: `sent/viewed --> expired: TTL sweeper (nightly, CAS; never touches the enrolment, never re-issues)`.

**§5 LOOPS — Consent** row becomes: 🟡 — expiry edge **real** (writer + sweeper + ops bucket + family register). Open edges: D1 revocation UI (mRevoke) · media `kind` MG · **P-4 student-own-consent read** · re-issue UI.
**§5 Money** — add `period_locks (P-8)` explicitly to the open edges.
**Elevation count wherever stated → 79.**

---

## 3 · KAP-PROTOTYPE-WORKFLOW.md
(§B corrections from AUDIT-3 are DOC-FIX-3 Part 1 — this section is additive.)

**§9 deliberate-divergence list — append, each with its citation:** six guardian tabs (the combined tab is a demo shortcut; we compose what its mirror note promises — B4) · consents "History" heading + two-register headings/count/rail (the flat list over-claimed "Signed and settled" — B9r3; build-added structure recorded at AUDIT-3) · title-as-link drills wherever a card holds an inner action (GUA-FIX/B3 a11y ruling vs the blocks' stopPropagation) · pairing button actor corrected ("Enter a pairing code" — the model's actor, not the block's) · no-placeholder deadlines (absent expiry renders nothing, never '—').

---

## 4 · KAP-ALIGNMENT-PLAN.md

**§1/§2 persona tables** — Student: every row ✅ except stu-progdet (DR: marketing model + fee card content) and the known RW/MG residuals (term display, tracker/results rows). Guardian: every row ✅ except gua-pay (**B10, the closing card**), gua-reqs (DU), and the D-6 leg.
**§7 server backlog** — REMOVE (served in Phase B): /my/children · /my/guardians · marketplace fee · enrolment_opens_at · consent signature facts · consent TTL. ENSURE PRESENT: period_locks (E) · P-4 (E) · re-issue UI (F) · consent_request_id on the enrolment read (C) · order-lines on the orders list (C) · basics.ends_on (E).
**§8 phases** — Phase A + Phase B: **CLOSED**, one-line pointers to docs/reference/KAP-SPRINT-LOG.md. Method section gains the standing line: *"Every phase-end audit re-walks the sprint log's loop-closure table — a phase is not closed while a loop edge it claimed is still open."*

---

## 5 · New file: docs/reference/KAP-SPRINT-LOG.md
The owner-installed running ledger (Phase B card table · decisions · loop-closure table · remaining queue). Committed AS-IS; append-per-phase.
