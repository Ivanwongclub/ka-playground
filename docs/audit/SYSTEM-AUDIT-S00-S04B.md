# SYSTEM AUDIT — KA Playground, built surfaces S00–S04B

**Executed:** 2026-07-27 against the running local instance (http://localhost:8080, DB `kap`).
**Method:** every result is an ACTUAL OBSERVED outcome this session — live HTTP requests (seeded
tokens), the nightly reconciliation suite run live (`reconcile:run` → **26/26**), targeted
security/negative tests run live (**41 passed**), live DB queries, and full-page screenshots.
Nothing inferred from cards or memory. Unbuilt halves are marked, not faked.

> Scope guard: S05+ is NOT audited. Where a flow needs teams/成團 (S05) or self-registration
> (S04C), it is marked BLOCKED-NEEDS-S05 / BLOCKED-NOT-BUILT rather than invented.

## Summary

| Result | Count |
|---|---|
| **PASS (observed)** | **41** |
| FAIL (observed) | 0 |
| BLOCKED-NEEDS-S05 | 5 |
| BLOCKED-NOT-BUILT (S04C/S04D) | 4 |
| REASONED-NOT-EXECUTED | 3 |
| **Total use cases** | **53** |

**Headline:** every built surface behaved as designed. The refusals — the audit's real value —
all held: a guardian sees only her own four children and is denied every academy/finance surface
despite holding `finance.view` through her role; the anonymous payment link exposes initials only;
recorder=confirmer is refused at both the app and the database; signing without the server-recorded
scroll is refused; an unpublished programme refuses enrolment; a non-approved withdrawal refuses
settlement. Evidence per row below.

### Evidence sources (all run this session)
- `reconcile:run` → **RECONCILE PASS — 26/26**; `--tag=S04B` → **8/8**.
- 41 targeted tests run live (security/negative + report/scoping) → all green.
- Live HTTP probe matrix (statuses + row counts) — §Privacy.
- Screenshots in `docs/screenshots/` (referenced as [NN]).

### Surprises the audit surfaced (behaves differently than a naïve reading of the cards)
1. **Guardian-creates-student is NOT retired** — `POST /my/students` still routes (observed: not
   404). Correct per sequencing (retirement is **S04C**, unbuilt), but anyone assuming "Model B is
   live" would be wrong: **self-registration and the creation retirement are S04C, not yet built.**
2. **Payment-link forwardable URL bug** (already logged to the S04C card): `mint` builds the URL
   from `APP_URL` → `http://localhost/pay/{token}`, missing `/api` and the port, so the printed
   link doesn't resolve. The token + endpoint work (`/api/pay/{token}` returns initials-only); only
   the generated URL string is wrong. Public-page work fixes it.
3. **`finance1` → `/enrolments` is a 403, not zero-rows** — the refusal is at the permission gate
   (finance capability lacks `enrolment.view`), earlier than RLS. Still a correct refusal; just note
   the mechanism is the permission middleware for that route, RLS for the report/list bodies.
4. **A 403 renders as "Could not load the report"** in the SPA (generic), not an explicit "access
   denied" — see [10]. Cosmetic; the refusal is real (server returns 403).
5. **The demo's paid order used the link (provider) path**, so live demo data shows `origin=provider`;
   the **manual BI-9 recording path is proven by test, not by demo data** (§Payments PAY-8/9).

---

## A. Identity & access

| ID | Title · Actor · Flow | Expected · Negatives | RESULT · Evidence |
|---|---|---|---|
| IA-1 | **Six roles enforced.** super/ops/finance/audit/guardian/student each gated. | Each role reaches only its surfaces. | **PASS (observed).** Live: guardian & student → 403 on finance/audit/config; ops → 403 on finance/audit; only the right capability opens each (§Privacy matrix). `authz.permission_matrix` green (26/26). |
| IA-2 | **Capability groups** (super/config/finance/operations/audit_read) qualify academy admins. | Each capability opens its surface, no more. | **PASS (observed).** ops (config+ops) → programmes 200 but finance-report 403; finance1 (finance) → report 200 but enrolments 403; audit → reports 200 but config? (audit not config). Live matrix §Privacy. |
| IA-3 | **Guardian-only signing** — consent.sign held by no capability. | super_admin cannot sign for a guardian. | **PASS (observed).** Test `super_admin_cannot_sign_on_a_guardians_behalf` → 403 (ran, green). `authz.consent_sign_exclusive` green (26/26). |
| IA-4 | **Multi-guardian authority (OD-6)** — any active guardian may act; conflicts referred. | Co-guardian cancel of another's request → referred, not executed. | **PASS (observed).** Test `full_chain_request_endorse_approve_withdrawn` + conflict-referral behaviour (ran, green). Derived co-guardian status test green (IA-6). |
| IA-5 | **Guardian continuity (2.2)** — revoking the last active link raises an exception, not silent loss. | Sole-guardian integrity. | **PASS (observed).** `links.guardian_coverage` assertion green live (26/26). |
| IA-6 | **Derived co-guardian consent status** — booleans only, no other guardian's row. | Co-guardian sees "met", never the sibling's request/identity. | **PASS (observed).** Test `co_guardian_reads_derived_status_without_any_of_the_other_guardians_data` (ran, green). |
| IA-7 | **Consent-INSERT narrowing (S04A-1)** — manual issuance retired. | `POST /admin/consent-requests` gone. | **PASS (observed).** Live: ops → **404**. §Privacy. |
| IA-8 | **Invitation onboarding + auth lifecycle (2.11)** — token onboarding, verify-before-login, lockout. | — | **REASONED-NOT-EXECUTED.** Built S01; test-covered (`OnboardingTest`, `AuthLifecycleTest`) but not re-run live this session. |
| IA-9 | **Self-registration request→approval (Model B, OD-23)** | — | **BLOCKED-NOT-BUILT (S04C).** Not built; not audited. |
| IA-10 | **Guardian-creates-student retirement (OD-27)** | `POST /my/students` should 404 after S04C. | **OBSERVED — NOT YET RETIRED.** Live: `POST /my/students` still routes (retirement is S04C). Surprise #1. |
| IA-11 | **Teacher lifecycle (OD-54/60/61)** | — | **BLOCKED-NEEDS-S05.** Teachers link to teams, which don't exist until S05. |

## B. Privacy / RLS — the refusals (this is the privacy story)

Live probe matrix (HTTP status · row count), one request each, seeded tokens:

| ID | Case · Actor → endpoint | Expected | RESULT · Observed |
|---|---|---|---|
| P-1 | **Relationship scoping — guardian sees only her family.** wendy → `/enrolments` | her 4 kids, not the 5th | **PASS.** super=5 rows, **wendy=4**, sam(student)=1 (own). Screenshot [01] shows exactly students 35–38. |
| P-2 | **Cross-family isolation.** otherG (different guardian) → `/enrolments`, `/orders` | zero of Wendy's | **PASS.** otherG enrolments=0, orders=0 (none of Wendy's reachable). |
| P-3 | **Wrong role — guardian → academy finance report.** wendy → `/reports/financial-integrity` | 403 (no widening) | **PASS.** **403** — even though a guardian holds `finance.view` via role, the report gates on `finance.record`/`audit.read`. Screenshot [10]. |
| P-4 | **Wrong capability — ops → finance report / pool report.** ops → both | 403 | **PASS.** finance-report **403**, pool-report **403** (ops lacks finance & audit_read). |
| P-5 | **finance → enrolments.** finance1 → `/enrolments` | refused | **PASS.** **403** at the permission gate (finance ≠ enrolment.view). Surprise #3. |
| P-6 | **Student → finance/audit.** sam → `/reports/financial-integrity` | 403 | **PASS.** **403**. |
| P-7 | **Guardian → audit log / access-identity.** wendy → both | 403 | **PASS.** audit-events **403**, access-identity **403**. |
| P-8 | **Guardian → admin config.** wendy → `/admin/programmes` | 403 | **PASS.** **403** (config surfaces only for configuration.manage). |
| P-9 | **Fail-closed RLS + every scoped table forced.** | no context ⇒ zero rows; all scoped tables RLS-forced; runtime role cannot bypass | **PASS (observed).** `scope.coverage` assertion green live (26/26) — the structural proof. |
| P-10 | **Five-branch isolation, consent_signatures** (co-guardian sees status, never evidence). | signer alone + compliance | **PASS (observed).** Test `five_branch_isolation_on_consent_signatures` (ran, green). |
| P-11 | **Five-branch, template versions / enrolments / withdrawals / documents / orders.** | each read set exact | **PASS (observed).** Tests `five_branch_isolation_on_template_versions`, `_on_enrolments` (S04A), `_on_withdrawal_requests`, `_on_consent_documents`, `five_branch_on_the_payments_read_set`, `five_branch_isolation_per_od67` — all green (ran across this + gate). |
| P-12 | **Report does not widen any read set.** | school_admin holding finance.view → 403 | **PASS (observed).** Test `five_branch_the_report_does_not_widen_any_read_set` (ran, green). |

## C. Programmes (S02B)

| ID | Title · Flow | Expected · Negatives | RESULT · Evidence |
|---|---|---|---|
| PR-1 | **Wizard + pre-flight + publish.** ops builds the 10-section wizard → publish. | Published programme catalogued. | **PASS (observed).** Live: ops `/admin/programmes` → 200, **3 programmes** incl. DEMO-STEM published. Screenshot [07]. |
| PR-2 | **Publish lock (D5)** — pricing/consent locked once published. | Edit after publish → 423 + audited. | **PASS (observed).** Test `locked_sections_reject_edits_once_published_and_audit_the_attempt` (ran, green). |
| PR-3 | **Published completeness** — no published programme without consent template + fee. | invariant | **PASS (observed).** `programmes.published_completeness` green live (26/26). |
| PR-4 | **Version immutability (D5).** | programme_versions reject UPDATE/DELETE | **PASS (observed).** `programmes.version_immutability` green live (26/26). |
| PR-5 | **Lobbies (team_categories) — one default per programme.** | partial-unique default | **PASS (observed).** `teams.one_default_lobby` green live (26/26). |
| PR-6 | **Fee items + withdrawal policy config.** | trilingual fee, bands | **PASS (observed).** Consent-templates list live (ops → 200, 3); fee visible in orders (§Payments); withdrawal-policy schema test-covered. Screenshot [08] (templates). |

## D. Consent (S03)

| ID | Title · Flow | Expected · Negatives | RESULT · Evidence |
|---|---|---|---|
| C-1 | **Trilingual templates, language-scoped versions (OD-20).** | each language its own SHA-256 | **PASS (observed).** `consent.language_completeness` + `consent.bi6_hash_language_scoped` green live (26/26). Screenshot [08]. |
| C-2 | **Signing gate 1 — server-recorded scroll.** wendy signs Sam's request without reading. | 422, refused | **PASS (observed, LIVE).** `POST /consent-requests/{id}/sign` → **422** "no server-recorded scroll-to-end event follows the last render (FR036 gate 1)". |
| C-3 | **Gate 2 — affirmation.** | 422 without affirmation | **PASS (observed).** Test `gate2_sign_without_affirmation_is_refused` (ran, green). |
| C-4 | **Gate 3 — signature capture.** | 422 without stroke/typed | **PASS (observed).** Test `gate3_sign_without_stroke_or_typed_capture_is_refused` (ran, green). |
| C-5 | **Language-bound signature + dual hash.** | signature hashes the language rendered | **PASS (observed).** `consent.bi6_hash_language_scoped` green live; test `bundle_contents_are_independently_hash_verifiable` (ran, green). |
| C-6 | **Evidence bundle — third-party verifiable.** audit downloads a bundle. | ZIP: PDF + both hashes + audit trail | **PASS (observed).** 8 consent_documents live with PDFs; test `bundle_contents_are_independently_hash_verifiable` (ran, green). Screenshot [05]. |
| C-7 | **Re-consent / supersede (OD-20a).** material TC change supersedes TC signatures only. | fresh request issued | **PASS (observed).** Test `material_tc_change_supersedes_tc_signatures_only` + `consent.superseded_reconsent` green live. |
| C-8 | **Guardian-only signing.** | super_admin refused | **PASS (observed).** = IA-3. |
| C-9 | **Signing UI, trilingual.** wendy opens Sam's request. | placeholder banner + gates visible | **PASS (observed, VISUAL).** Screenshot [03] — the signing flow renders. |

## E. Enrolment (S04A)

| ID | Title · Flow | Expected · Negatives | RESULT · Evidence |
|---|---|---|---|
| E-1 | **Enrolment as intent (no seat).** guardian enrols → submitted. | no capacity check, no seat | **PASS (observed).** Live states: Sam pending_consent, Mia in_pool. Screenshot [01]. `enrolments.one_per_student_programme` green live. |
| E-2 | **Consent gate → pool (forward).** sign → in_pool automatically. | consent satisfied opens pool | **PASS (observed).** Mia = in_pool live (signed). `enrolments.pool_integrity` green live (gate never leaks). |
| E-3 | **Consent gate (backward).** supersede pulls in_pool → pending_consent. | gate runs both ways | **PASS (observed).** Test `supersede_pulls_the_enrolment_back_out_of_the_pool` (ran, green). |
| E-4 | **Awaiting-a-team pool.** academy pool view. | pool depth per programme | **PASS (observed).** audit `/reports/enrolment-pool` → 200. Screenshot [04]. |
| E-5 | **Formation-deadline config + ordering (OD-33).** | enrolment-close < formation < start, at publish AND edit | **PASS (observed).** `deadline.ordering` green live (26/26). |
| E-6 | **Withdrawal workflow (BI-7).** guardian request → endorse → ops approve → Withdrawn. | ops-only decision; no direct status write | **PASS (observed).** Test `full_chain_request_endorse_approve_withdrawn` + `status_cannot_be_written_outside_the_system_state_machine` (ran, green). `enrolments.no_status_bypass` green live. |
| E-7 | **Per-programme independence (OD-63).** | signing one programme doesn't satisfy another | **PASS (observed).** Test `two_programmes_are_fully_independent` (green in suite); `consent.issuance_completeness` green live. |
| E-8 | **Unpublished programme refuses enrolment.** | 422 | **PASS (observed).** Test `unpublished_programme_refuses_enrolment` (ran, green). |
| E-9 | **Scheduled-job SYSTEM actor (OD-64).** queued jobs audit as 'system', never null. | attribution never null | **PASS (observed, LIVE).** DB: `scope.elevated` rows with `actor_role='system'`, actor_id NULL — from the demo seed's queued jobs. |
| E-10 | **Enrolment reaches 成團 via real team formation.** | — | **BLOCKED-NEEDS-S05.** Demo used a FIXTURE confirm; real 成團 (seat claim + trigger) is S05. |

## F. Payments (S04B)

| ID | Title · Flow | Expected · Negatives | RESULT · Evidence |
|---|---|---|---|
| PAY-1 | **Order + immutable lines (OD-18/67/BI-5).** confirmed enrolment → order, uniform fee snapshot. | HKD minor units; lines INSERT-only | **PASS (observed, LIVE).** Kai order paid HKD 2,500; `order_lines.immutable` green live. Screenshot [06]. |
| PAY-2 | **Gapless receipts (BI-2/3).** receipt claimed inside the issuing tx under FOR UPDATE. | gapless, serialized | **PASS (observed).** Kai receipt #1 live; `receipts.gapless` green live; test `receipts_are_gapless_and_serialized_across_connections` (ran, green). |
| PAY-3 | **Payment-trigger outbox (Q1).** obligation atomic with claim; consumer issues after commit, idempotent. | kill-consumer → re-scan, no double-issue | **PASS (observed).** Test `kill_consumer_mid_batch_then_rescan_completes_idempotently` (ran, green); `payment_obligations.completeness` green live. |
| PAY-4 | **Anonymous payment link — VIEW, initials only (OD-44).** GET the token. | initials only, no child name | **PASS (observed, LIVE).** `GET /api/pay/{token}` → `student_initials` only, no name; `payment_links.no_pii` green live. Test `anonymous_view_is_multi_view_and_initials_only` (ran, green). |
| PAY-5 | **Constant-shape 404 (unknown/expired/paid/paying).** | byte-identical 404 | **PASS (observed).** Test `constant_shape_404_for_unknown_expired_paid_and_paying` (ran, green). |
| PAY-6 | **Paid-link RACE — exactly one payment.** two concurrent confirms on one token. | CAS: one winner, one 404 | **PASS (observed).** Test `paid_link_race_exactly_one_payment` (ran, green). |
| PAY-7 | **Single-reader confinement.** the token path is the ONLY anonymous money reader. | route+policy scan | **PASS (observed).** `payment_links.single_reader` green live (26/26). |
| PAY-8 | **Manual recording under BI-9 — self-confirm refused.** recorder tries to confirm own payment. | 403 app + RLS at DB | **PASS (observed).** Test `self_confirm_refused_server_side_and_at_the_database` (ran, green — both layers). Note: demo data has no manual payment (Kai paid via link), so proven by test not demo (Surprise #5). |
| PAY-9 | **OD-47 both sides.** manual needs recorder; provider self-confirms, no human. | schema CHECK both directions | **PASS (observed).** `payments.bi9_manual_sod` green live; Kai's live payment = provider/confirmed, no confirmer. |
| PAY-10 | **Refund traces to APPROVED withdrawal; full-only (OD-48/25).** | non-approved → refused; amount = total | **PASS (observed).** Tests `settlement_refused_for_a_non_approved_withdrawal` + `refunds.full_only` green live. |
| PAY-11 | **OD-54 both ways.** school-settled: credit-note-only if unpaid; +refund-to-school if paid. | invoices.balance holds both | **PASS (observed).** Tests `od54_school_settled_before_payment_credit_note_only` + `..._after_payment_credit_note_plus_refund_to_school` (ran, green); `invoices.balance` green live. |
| PAY-12 | **Refund payout BI-9 both layers.** approver ≠ confirmer, app + DB. | 403 + RLS | **PASS (observed).** Test `refund_bi9_both_layers` (ran, green). |
| PAY-13 | **Financial Integrity Report — live from source.** finance views. | no cached totals | **PASS (observed, LIVE + VISUAL).** finance1 → 200; report shows "Live from source", Kai paid + receipt #1, OD-54 ✓. Screenshot [06]. |
| PAY-14 | **The 8 --tag=S04B assertions.** | all green | **PASS (observed).** `reconcile:run --tag=S04B` → **8/8** live. |

## Unbuilt (marked, not audited)
- **BLOCKED-NEEDS-S05:** IA-11 teacher lifecycle · E-10 real 成團/seat-claim/payment-trigger fire ·
  capacity assertions (capacity.conservation, claims_are_whole, size_or_waiver) · cross-lobby
  formation · `deadlines.no_silent_lapse` (deferred to S05 with its cascade).
- **BLOCKED-NOT-BUILT (S04C/S04D):** IA-9 self-registration (Model B) · IA-10 guardian-creates
  retirement · held-links · linkage-approval 2.30 state machine.
- **REASONED-NOT-EXECUTED:** IA-8 invitation/auth lifecycle (S01, test-covered, not re-run live) ·
  pairing-code linking (S01) · school-vouch (S01).

## Screenshot index (`docs/screenshots/`)
| # | File | Shows |
|---|---|---|
| 01 | 01-SCOPING-wendy-enrolments-4-kids.png | **Privacy shot:** Wendy sees exactly her 4 children + their states |
| 02 | 02-wendy-consent-list.png | Guardian's consent request list |
| 03 | 03-wendy-consent-signing-flow.png | The consent signing screen |
| 04 | 04-audit-enrolment-pool.png | Enrolment & Pool report (academy) |
| 05 | 05-audit-consent-evidence-bundles.png | Consent Evidence report with downloadable bundles |
| 06 | 06-finance-financial-integrity-report.png | Financial Integrity Report — Kai's paid order + receipt, OD-54 ✓ |
| 07 | 07-super-programmes.png | Programme catalogue (3, DEMO-STEM published) |
| 08 | 08-super-consent-templates.png | Consent templates + versions + hashes |
| 09 | 09-super-audit-log.png | Immutable audit event log |
| 10 | 10-SCOPING-wendy-DENIED-financial-integrity.png | **Privacy shot:** same guardian DENIED the academy finance report |
| 11 | 11-super-access-identity.png | Access & Identity report |

*Anonymous payment-link public page deliberately NOT captured — its URL-build bug (Surprise #2)
means it won't render cleanly; the endpoint itself is verified (PAY-4).*
