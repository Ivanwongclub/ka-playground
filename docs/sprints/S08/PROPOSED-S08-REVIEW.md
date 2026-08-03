# PROPOSED S08 REVIEW — think-first (no code)

**Author:** Claude Code · **Date:** 2026-08-02 · **For:** Leo's review BEFORE S08 STEP 1
**Scope:** recognition — badges auto-minted from tenures, certificates (token-gated verification),
portfolio/Achievements. Reconciles `docs/sprints/S08/SPRINT.md` against what shipped. This sprint
mostly **READS existing ledgers** (S05 tenures, S06 learning/assessments, S07 P&L) and mints
recognition on top. Nothing built.

---

## 0. TL;DR
- **Recognition is DERIVED, read-only over the ledgers.** Badges come from S05 `tenures`
  (`tenure.completed` audit), certificates from S06 `certification_rules` + attendance/assessment,
  portfolio/Achievements from S07 P&L + S06 `assessment_results`. S08 **writes only its own recognition
  tables** — it never mutates a tenure, an assessment, or the P&L.
- **Headline drift — the card bundles TWO domains.** Scope (1) is **avatar library + moderation queue**
  (a UGC/profile-moderation domain); scope (2–4) is **recognition** (badges/certs/portfolio). Different
  concerns — the same bundling the D-15 lesson warns against. **Recommend splitting avatars out** (D-C1).
- **The greenfield is the ISSUED tables** — `badge_awards`, `certificates`, `portfolios`, the
  verification-token surface + access log. Only the RULES config (`badge_rules`, `certification_rules`)
  shipped. The `badges == completed tenures` parity assertion is greenfield too (S05 deferred it here).
- **The four correctness questions have concrete answers** grounded in shipped patterns: idempotency
  (partial-unique index), provenance (the `no_X_without_its_event` pattern), revocation (a policy to
  rule), and child-safety (spec:1263 — **no name without the token**; the redacted public payload is a
  decision S08 must make).

---

## 1. Scope reconciliation (your Q1)

| Card claims | Reads (existing) vs Creates (S08) | Reconciliation |
|-------------|-----------------------------------|----------------|
| Badges auto-minted from tenures/criteria | **Reads** `tenures` (state `completed`, the `tenure.completed` audit) + `badge_rules` (trigger_key/criteria). **Creates** `badge_awards` (issued badges). | Reads the S05 ledger; mints on top. Never writes a tenure. |
| Certificates + token-gated verification | **Reads** `certification_rules` (attendance/assessment thresholds, `certificate_template` — **no co-branding columns, OD-21**) + `LearnGateService` eligibility + `assessment_results`. **Creates** `certificates` + a verification-token surface + access log. | Reads S06; mints certs. |
| Portfolio assembly/export · Achievements | **Reads** `badge_awards`, `certificates`, S07 P&L (portfolio evidence), `assessment_results` (Released only). **Creates** `portfolios`/`portfolio_exports`. | Read-only aggregation over the ledgers. |
| **Avatar library + moderation queue (2.4)** | **Creates** `avatars`/`avatar_uploads` + a moderation state machine (Pending→Approved/Rejected→Appealed→Final). | **DIFFERENT DOMAIN** — UGC moderation, not ledger-derived recognition. **Split? (D-C1)** |

**Confirmed read-only:** recognition is derived, not a source. It reads tenures/finance/learning and
writes only recognition tables (your Q3). No badge/cert/portfolio path mutates a tenure, an assessment,
the P&L, or a stage gate.

---

## 2. Recognition-minting correctness (your Q2 — the substance)

### 2a. IDEMPOTENCY — mint once, DB-enforced
A completed tenure is read repeatedly (the mint sweep re-runs; a tenure is rotated in/out more than
once). The badge must not duplicate. **Reuse the shipped partial-unique pattern** (`enrolments
(student,programme)`, `consolidated_invoices (school,programme)`, `tenures (team,role) WHERE active`):
- **`badge_awards`** carries a **UNIQUE `(student_id, badge_rule_id)`** — a student earns a given badge
  **once**, whichever qualifying completed tenure mints it first; a concurrent or re-run mint hits the
  index and returns the original (never a second). **Decision D-C2:** one badge per `(student,
  badge_rule)` (earned once) — recommended — vs one per completed *tenure* (a student could hold the
  same badge N times). I recommend once-per-earned; it matches "a badge you hold," and the parity
  assertion (§4) then reads "every student with ≥1 qualifying completed tenure holds the badge."
- **`certificates`** UNIQUE `(student_id, programme_id)` — one certificate per programme.

### 2b. PROVENANCE — no recognition without its earning event
**Reuse the `no_X_without_its_event` pattern** (`links.no_active_without_approval`,
`finance.budget_approved_provenance`):
- **`recognition.badge_provenance`** — every `badge_award` references a source `tenure` in state
  `completed` (FK `source_tenure_id`) that carries a `tenure.completed` audit AND meets the rule's
  criteria. A badge with no qualifying completed tenure reds — **path-independent** (scans the badge,
  not the mint path).
- **`recognition.certificate_provenance`** — every `certificate` traces to a student who met the
  programme's `certification_rules` (attendance/assessment) at issuance, with a criteria snapshot.
  "No orphaned issuance" (the card's assertion).

### 2c. REVOCATION — the policy to rule (your Q2c)
The ledger has no "un-complete": a `completed` tenure is a **historical fact** (rotation sets
`completed`; a bad assignment is `terminated`, which never minted). So a badge minted from a completed
tenure is a record of something that genuinely happened. Proposed policy (**D-C3**):
- **Immutable-once-minted as a RECORD, but REVOCABLE by an audited admin action** (the card's
  "revoking → audit with reason"). A revoked badge/certificate is **never deleted** — it stays with
  `status='revoked'` + reason + actor; the public verification page then shows **"revoked."**
- **No automatic revocation from tenure changes** — completed tenures don't reverse, so there's no
  auto-cascade. Revocation is a discretionary academy decision (error, misconduct), always audited.
- Certificates match spec §P1 (`… → Verified → Revoked` is a real terminal state).
This keeps recognition honest (a revoked badge is visibly revoked, not silently erased) and avoids a
fragile auto-cascade over an append-only ledger.

### 2d. CHILD-SAFETY — the shareable document (your Q2d — the sharpest)
A certificate/portfolio names a **minor** and can leave the platform. The spec is explicit
(**spec:1263**): public certificate verification is *"Token-gated URL, **no name disclosed without the
token**"*; **spec:1264** — badges/achievements *"visible to self, guardian and staff only"* by default;
FR056 confirms. Proposed design (**D-C4**):
- The verification **token is the bearer secret** (like `payment_links` — the family shares it with
  whom they choose). **With a valid token:** the page shows the certificate's face — the child's name,
  programme, achievement, issue date, the **academy** as sole issuer (no co-branding, OD-21), and
  valid/revoked. That is the point of a shared certificate.
- **Without a token, or a tampered/invalid token → REFUSED, constant-shape, ZERO PII** — no name, no
  existence disclosure. **Reuse `PaymentLinkNoPiiAssertion` + `PublicContextConfinementAssertion`** —
  a new **`recognition.verification_no_pii`** asserts the public/anonymous verification surface leaks
  no minor's PII without the token.
- **Every verification access is logged** (who/when/which certificate — the Audit Element's
  verification-access log), so a shared certificate's checks are auditable.
- **Generation authority:** a certificate/portfolio is generated only by the **owner (student/guardian)
  or academy staff** — never a peer; Achievements are self/guardian/staff-visible (FR056).
- The exact **redacted public payload** is a decision the spec leaves to S08 — I recommend the
  with-token face above + a bare `{valid|revoked|not_found}`-shape (no PII) without it. Confirm (D-C4).

---

## 3. Interaction with existing surfaces (your Q3)

**Read-only over the ledgers — confirmed.** Recognition:
- **reads** `tenures` / `badge_rules` (badges), `certification_rules` / `LearnGateService` /
  `assessment_results` (certificates), S07 P&L + `assessment_results` (portfolio/Achievements);
- **writes only** its own tables (`badge_awards`, `certificates`, `portfolios`, the verification-access
  log, and — if kept — `avatars`);
- **never** mutates a tenure, assessment, stage gate, or the P&L. Recognition is derived, not a source
  of truth — a corrected tenure/assessment flows *forward* into recognition (or a revocation), never
  the reverse.

---

## 4. Auto-minting + liveness (your Q4)

Badges must appear "without manual action" when a tenure completes (card KEY VERIFICATION). Proposed
(**D-C6**):
- **Event-driven mint:** a listener on the `tenure.completed` transition mints the badge (idempotent,
  §2a) — the badge appears immediately.
- **Nightly backstop sweep** (`recognition:mint-badges`, mirroring the S07 sweeps) re-scans completed
  tenures for any un-minted badge — so a missed event self-heals.
- **The liveness/parity assertion IS the guard:** **`recognition.badges_match_tenures`** — every
  student with a qualifying completed tenure (meeting the rule's criteria) holds the badge; a
  qualifying completed tenure left un-minted reds (the card's `badges == completed tenures`, which S05
  deferred here). **Registered early with the vacuous-table-guard pattern** (`Schema::hasTable` → pass
  until `badge_awards` exists), like `LadderLivenessAssertion`.
Certificates are NOT necessarily auto-issued-for-all — issuance/generation is owner/staff-initiated;
the cert assertion is provenance/eligibility ("no cert without meeting the rules"), not "every eligible
student auto-has a cert." **Confirm (D-C5).**

---

## 5. Step plan + drift register (your Q5)

### Proposed steps (recognition first; avatars split or last)
- **STEP 1 — Badges.** `badge_awards` (UNIQUE `(student,badge_rule)`, `source_tenure_id`, criteria
  snapshot, `status` issued|revoked); event-mint on `tenure.completed` + backstop sweep;
  `recognition.badge_provenance` + `recognition.badges_match_tenures`. VERIFY: complete a tenure →
  badge appears; re-run mint → no duplicate (unique); revoke → audited, verification shows revoked;
  parity/provenance red→green.
- **STEP 2 — Certificates + token-gated verification.** `certificates` (UNIQUE `(student,programme)`,
  criteria snapshot, verification token, `status`); issuance when `certification_rules` met; the
  **token-gated public verification page** (with-token face / no-token refusal, ZERO PII, access
  logged); revocation; `recognition.certificate_provenance` + `recognition.verification_no_pii`
  (child-safety). VERIFY: valid token renders; tampered/absent token refused with no PII (raw);
  access logged; revoked cert shows revoked; academy-only issuer (OD-21).
- **STEP 3 — Portfolio + Achievements.** Portfolio assembly/export (reads badges/certs/P&L/assessments,
  Released only); Achievements aggregation (self/guardian/staff visibility, FR056). VERIFY: export a
  portfolio from seed; five-branch (a peer sees nothing; a non-owner can't generate); embargo respected.
- **STEP 4 (or its own card, D-C1) — Avatars + moderation queue (2.4).** The UGC-moderation domain:
  Pending→Approved/Rejected(reason)→Appealed→Final, one appeal, atomic approved-swap, S00 upload.
  VERIFY: rejected → reason; second appeal blocked; swap atomic.
- **GATE.** Tests + `--tag=S08` + one portfolio exported + AUDIT.md.

### Drift register
| # | Drift | Card said | Reality / proposal | Decision |
|---|-------|-----------|--------------------|----------|
| D-C1 | Domain bundling | S08 = recognition + **avatars** | Avatar moderation is a UGC/profile domain, not ledger-derived recognition — the D-15 bundling smell. Split to its own step (STEP 4) or its own card. | **Leo** |
| D-C2 | Badge idempotency grain | "badges == completed tenures" | One per `(student, badge_rule)` (earned once, recommended) vs one per completed tenure (repeatable). | **Leo** |
| D-C3 | Revocation policy | "revoking → audit with reason" | Immutable RECORD, revocable by audited admin action; no auto-cascade from tenure changes (completed tenures don't reverse). | **Leo** |
| D-C4 | Public verification payload | "token-gated verification" | With token: the cert face (name, programme, achievement, date, academy, valid/revoked). Without/tampered: refused, ZERO PII, logged. spec:1263 = no name without the token. | **Leo (child-safety)** |
| D-C5 | Certificate auto-issue vs on-request | "certificates == students meeting rules" | Provenance/eligibility assertion (no cert without meeting rules), NOT auto-issue-for-all; generation owner/staff-initiated. | **Leo** |
| D-C6 | Mint mechanism | "without manual action" | Event on `tenure.completed` + nightly backstop sweep + the parity assertion as liveness. | Falls out |
| D-C7 | `terminated` vs "revoked" wording | card says "revoking" a tenure | The ledger column is `terminated` (never minted a badge); "revocation" applies to the BADGE/CERT, not the tenure. | Confirmed |
| D-C8 | co-branding | (NON-SCOPE names it Phase 2) | OD-21: academy-issued ONLY, no co-branding columns exist — the verification page names the academy as sole issuer. | Confirmed (OD-21) |

---

## 6. Decisions I need before STEP 1
1. **D-C1** — split avatars out of S08 (recommended), or keep as STEP 4?
2. **D-C2** — badge idempotency: one per `(student, badge_rule)` (recommended) or per completed tenure?
3. **D-C3** — revocation policy: immutable record + revocable-by-audited-admin, no auto-cascade (recommended)?
4. **D-C4** — the public verification payload (child-safety): with-token cert face / no-token ZERO-PII
   refusal + access log — confirm exactly what a shared certificate exposes about a minor.
5. **D-C5** — certificates: provenance/eligibility assertion (not auto-issue-for-all), owner/staff-generated?
6. Confirm the **step boundary** (badges → certs → portfolio/achievements [→ avatars]) and STEP 1 = badges.

On your rulings I'll reconcile `docs/sprints/S08/SPRINT.md`, commit the plan, show you the reconciled
card + STEP 1 plan, then build. Nothing built or committed yet.
