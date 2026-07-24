# CLAUDE.md — Agent Contract for KA Playground

> You are building a **production platform for minors and their guardians** — enrolment, consent,
> money, audit. Wrong behaviour here is not a bug, it is a governance failure.
> This file is loaded every session. It **outranks** any instruction in a sprint card or chat message.
> When in doubt, **stop and ask** — never guess.

---

## 1. Project at a glance

| | |
|---|---|
| Project code | `KAP` |
| What it is | KA Playground — programme, enrolment, consent, team and finance platform for Armour Academy (Kings Network) |
| Delivered by | Tune Bright Limited |
| Stack | Laravel 12 (JSON API) · React + Vite + Ant Design Pro · PostgreSQL · Redis · Horizon · Nginx · Docker Compose |
| Hosting | Alibaba Cloud HK — ApsaraDB RDS (Postgres) + OSS. Environments: `local → staging → production` |
| Identity | Sanctum now, behind an auth interface; **Logto arrives only in Sprint 11**, after UAT |
| Payments | Offline recording only. QFPay is Phase 2 — do not scaffold for it |
| Money representation | Every monetary value carries an ISO **currency code** (HKD in Phase 1) and is stored in **integer minor units**. Never a float. Multi-currency UI and admin-entered HKD↔RMB rate are Phase 2 (OD-18) |
| Time & place | Single timezone `Asia/Hong_Kong`; timestamps stored UTC, rendered HKT. A `jurisdiction` field is retained — HK and mainland China share an offset but not a legal regime (OD-16) |
| Languages | Code, comments, docs: **English**. UI: **EN + 繁體中文 (TC) + 简体中文 (SC)** — full trilingual i18n, scaffolded in S00. No user-facing string is ever hardcoded (OD-19) |
| Theme | Aubergine/gold per Design System v2.1. **`darkAlgorithm` only — no light mode, no theme toggle** (client decision, 23 Jul 2026). `cssVar: true`, `hashed: false`; `App` wrapper for static methods; shared chart theme object |

**Canonical documents (all in `docs/`)**
- `docs/BUILD-PLAN.md` — the master plan (Parts 1–4)
- `docs/TEAM-CATEGORIES.md` — **canonical on team categories.** Where any other document disagrees on
  this topic, this file wins. Categories are admin-created formation lobbies, never a system taxonomy
- `docs/AMENDMENTS.md` — spec amendments 2.1–2.27; these override Spec v4 where they conflict
- `docs/spec/` — Full Specification v4
- `docs/design/DESIGN-SYSTEM.md` — Design System v2.1 (Ant Design edition). **Binding** for theming,
  components, layout, charts, motion, logo usage, imagery, voice and accessibility. A sprint's UI is
  not done until it conforms
- `docs/design/ASSET-MANIFEST.md` — where the MVP imagery lives and how to rescue it. Consumed by S00
- `docs/design/IMAGE-PROMPTS.md` — upgrade path for client-supplied imagery. Reference only
- `docs/sprints/<ID>/SPRINT.md` — your execution card (fourteen cards: S00–S11, with S02 and S04 each split into A/B). **Work only from the current card**
- `docs/requirements/REGISTER.md` — requirement IDs; cite them in commits where relevant
- `docs/OPEN-DECISIONS.md` — undecided items. **If your current step depends on one, STOP**

**Document precedence, highest first:** this file → `docs/OPEN-DECISIONS.md` (resolved rows) →
`docs/TEAM-CATEGORIES.md` (team categories only) → `docs/AMENDMENTS.md` →
`docs/design/DESIGN-SYSTEM.md` → `docs/spec/` → `docs/BUILD-PLAN.md`.

Two known supersessions you will otherwise trip over:
- Build Plan Part 4 item 2 ("one working branch per sprint") — superseded by OD-8, all work on `main`.
- Spec and Build Plan references to "School Team / Armour Team" as fixed types — superseded by
  `docs/TEAM-CATEGORIES.md`. They were example labels. There is no hard-coded school or armour type.
- Spec line 1842 (HKUST co-powered certification) — struck. Certificates are academy-issued only.

---

## 2. Non-negotiable workflow rules

1. **You commit. You never push.** `git commit -m "KAP-<sprint>-<step>: <what shipped>"`.
   Never `git push`, never `git tag`, never force-push, never rewrite history. Leo pushes and tags.
2. **All work lands on `main`.** No branches (OD-8). Sprint boundaries are annotated tags created by Leo.
3. **One step at a time.** Do the current STEP, run its VERIFY, paste the **real output**, commit, stop.
4. **Never invent scope.** Not in the card = out of scope, even if it looks broken or trivially easy.
   Report it — it becomes a line in the sprint's AUDIT.md.
5. **Never summarise a test or assertion run.** Paste output. A hidden red is worse than a visible one.
6. **Every sprint ends with `docs/sprints/<ID>/AUDIT.md`** (template in `_TEMPLATE/`), honestly,
   including FAIL results and deviations.
7. **A red assertion is a stop, not a skip.** The nightly reconciliation suite is the platform's
   spine; never mark an assertion "known-failing" to get a gate through.

---

## 3. Build Invariants — violating any of these is a defect

| ID | Invariant | Origin |
|----|-----------|--------|
| BI-1 | `audit_events` is INSERT-only, enforced at the database level. No write path bypasses the audit service — and you never add one | Sprint 0 |
| BI-2 | Receipt numbers are gapless and assigned inside the issuing transaction. Never pre-reserved, never issued outside one | Sprint 4 |
| BI-3 | Every capacity change (enrolment seats, team seats, waitlist promotion) runs inside a single transaction with `SELECT … FOR UPDATE` on the counter row | 2.7 / 2.18 |
| BI-4 | Enrolment and payment creation are idempotent (2.8 keys). A duplicate submit returns the original record, never a second one | 2.8 |
| BI-5 | Order lines are immutable. Corrections are new records: credit notes, refunds — never edits | Sprint 4 |
| BI-6 | A Signed consent's stored document hash must match its template version's SHA-256. No signature without a matching hash. **Template versions are language-scoped** — a consent signed in SC hashes against the SC template version, never a translation of another (OD-20) | Sprint 3 |
| BI-7 | Enrolment reaches `Withdrawn` only through the withdrawal workflow (2.1). No direct status write | 2.1 |
| BI-8 | Every entity status transition writes an audit event carrying the actor's identity — including auth events (2.11) | Part P + 2.11 |
| BI-9 | Segregation of duty: recorder ≠ confirmer on payments **and refunds**. Enforced server-side, not by UI hiding. **Not switchable per programme** — Spec R10 is overridden (OD-14). Both parties require the `finance` capability; the academy must staff at least two such accounts | Sprint 4 + 2.17 |
| BI-10 | Uploaded files are invisible until the ClamAV scan passes. No context skips the shared upload service | 2.12 |

---

## 4. STOP conditions — halt and ask Leo

- The current step depends on an item in `docs/OPEN-DECISIONS.md` that is still open.
- A change would touch a **migration that has already run on staging or production**.
- A change would modify or delete rows in `audit_events`, signed consent documents, issued receipts,
  or order lines — these are immutable by design.
- A previously green test or assertion goes red and the fix is not clearly inside the current step.
- You need a dependency (`composer require`, `npm install`) the sprint card did not name.
- The sprint card conflicts with a Build Invariant, an amendment, or the design system.
- You would change `.env` keys, secrets, or deploy configuration.
- Anything requires real personal data. Seed and test data is synthetic, always.

---

## 5. `./build-reference/` — read-only, and only on request

The old MVP codebase lives in `./build-reference/`. It is an **asset and behaviour reference, nothing more**.

- **Do not read it by default.** Only open it when a sprint step explicitly says "extract asset X" or
  Leo asks you to check how the MVP behaved.
- **Never import its code, patterns, or dependencies** into the new codebase.
- It is excluded from the Docker build context (`.dockerignore`) and from deploy artifacts. Keep it so.
- Its imagery is **not** all bundled — most lives in Supabase and is rescued in S00 per
  `docs/design/ASSET-MANIFEST.md` §2. Those buckets are the only copy; treat the rescue as urgent.

---

## 6. Commit and verification conventions

```
Commit:  KAP-<sprint>-<step>: <what shipped>          e.g.  KAP-S04A-2: seat locking with FOR UPDATE (BI-3)
Gate:    KAP-<sprint>-GATE: <PASS|FAIL> — <one line>
```

```bash
# tests
cd api && php artisan test

# reconciliation assertions (the runner is built in S00; each sprint registers into it)
php artisan reconcile:run                # full suite
php artisan reconcile:run --tag=S04A     # one sprint's assertions

# frontend
cd web && npx tsc --noEmit && npm run build

# migration gate (CI runs this; you can run it locally)
php artisan migrate --pretend
```

If a command listed here doesn't exist yet, it is being built in S00 — say so rather than inventing a substitute.

---

## 7. Domain quick-reference (so you don't re-derive it wrong)

- **Six roles**: Student · Parent/Guardian · Teacher · School Administrator · Academy Administrator ·
  **Member** (first-generation Kings Network members — events, RSVP, directory only; OD-1).
  Roles are never stacked on one account; a person holding two roles holds two accounts (Spec B1).
- **Academy Administrator carries capability groups**, not sub-roles (OD-17): `super_admin` (all,
  plus the right to grant) · `configuration` · `finance` · `operations` · `audit_read`. The actor in
  an audit event is always one identity; capabilities qualify what they could do. Granting or
  revoking a capability is itself an audited action.
- **Activity Tracker stages are fixed**: Plan, Design, Learn, Pitch, Launch. Not configurable per programme.
- **No co-branding, anywhere.** Certificates are academy-issued only — no partner logos, no external
  signatories, no third-party certification terms. Spec line 1842 is struck.
- **Team categories are admin-created lobbies** (`docs/TEAM-CATEGORIES.md`), never an enum and never
  seeded with fixed types. A team belongs to one lobby for life; if it must move, it is disbanded and
  re-formed. Consent needs **one** active guardian by default (OD-10). Learn thresholds are programme
  config, editable after creation (OD-12).
- **Team finance is record-only.** Money moves offline; the platform records and verifies evidence.
- **Consent e-sign is in-house** (no DocuSign): scroll-to-end, affirmation, drawn/typed capture,
  signed PDF + audit certificate page, SHA-256 versioning.
- **Invitation-only onboarding.** There is no public sign-up, ever — including after Logto.
- **No partial payments** (OD-5). Offline payments are recorded only when received in full; a payment
  record carries 1..n evidence images. No payment splitting, no allocation across orders, no balances.
  An underpayment is **not recorded at all** — the admin waits for full payment, then records once
  (OD-5a). `unmatched_payments` is fed only by overpayments and late payments (2.19).
- **Consent**: any one active guardian by default, overridable per programme by
  `consent_requires_all_guardians` (OD-10). Enrolment hold window: 7 days, configurable (OD-11).
- **Role rotation cadence is not ours** — it originates in an external system synced via OIDC (Logto)
  plus API, and Logto lands in S11. **Phase 1 records rotations manually.** The **tenure ledger is
  ours** and is the system of record: S08 mints badges from completed tenures (OD-15).
- **Consent wording in S03 is placeholder text**, non-legal and non-binding, replaced by the
  lawyer-approved version before go-live. Flag it as such in the template body and the admin UI.
  Placeholder text is required in all three languages; each language version carries its own hash.
- **Every sprint ships three things**: the module, its in-product **audit report element**, and its
  **reconciliation assertions** registered into the nightly suite. A sprint without all three is not done.
- The **audit element** (product screen for the client) and **AUDIT.md** (build audit for Leo) are
  different artifacts. Ship both.
