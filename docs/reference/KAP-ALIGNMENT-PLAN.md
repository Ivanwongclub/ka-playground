# KAP — ALIGNMENT PLAN
### Matching the system (Doc 1) to the prototype's UIUX (Doc 2), prototype as the base
**Document 3 of 3** · 20 Aug 2026

**Method.** The prototype's UIUX is the target grammar; the data model overrules it where they conflict (D-7). Every gap below is classed by its remedy — the class determines the card type and the review tier:

| Class | Meaning | Card type |
|---|---|---|
| **CL** | client-only — reads exist, surface doesn't | UI card, verdict-before-build |
| **RW** | read-widen — model permits, read doesn't carry (NOT-SERVED) | additive server card, RIDER-1 |
| **MG** | migration — the schema lacks the field/table | migration card |
| **DU** | domain-unbuilt — whole capability absent (notifications, requests…) | designed card set |
| **DR** | blocked on an owner decision (D-3…D-7) | ruling first |
| **PW** | prototype-wrong — model forbids; prototype row corrected | D-7 list, build nothing |

**Already aligned (the 18-card rollout):** ground + chrome (glass header/sider, `.nbadge` counts, bell, collapse, footers) · family spine (D-1: `/enrolments/:id`, 5 tabs composed) · both family list surfaces · queue sorts · P0 safety · S-READ-1/2 + S-TRACKER-1 widens. The family personas are ~model-complete for what the reads support.

---

## 1 · STUDENT — closest to done

| Screen | State | Remaining gaps → class |
|---|---|---|
| stu-home | ✅ B1/B1R `128c03b`,`f3af55c` | 4→2 reads; venue **RW** · member-count **RW** (own ruling) |
| stu-progs | ✅ C6 | term **RW** (not MG — `programmes.starts_at` exists and is now written; the read doesn't carry it) · tracker/results rows **RW** (enrolment-list) · forming-count **RW** · Remind **DU**(D6) |
| stu-space | ✅ 5 tabs | stepper dates **MG/RW** (transition-log read, `{state,at}` only — ruled shape) · tile `.ev` sub-lines **RW** · tracker requirements **MG×7** (see §6) · join-request block **DU**(B-4) · join-with-code **DU**(B-2/M-1) · wall names **PW** |
| stu-explore | ✅ B7/B9 `cc3f806`,`4bc12e7` | card grammar · **list price ✅ served** (authed family) · "Coming soon" ✅ (`enrolment_opens_at`). **stu-progdet DEFERRED (DR)** — `show` returns the list payload and About/Schedule/Venue are unmodelled, so a detail page would restate the card just tapped · "Ask my guardian" interest ping **DU** |
| stu-me | ✅ B5/B9 `b0375b6`,`4bc12e7` | identity + language ✅ · **"My guardians" ✅ built** — it was **RW, never PW**: `guardian_links_read` admitted the student all along and only the ROUTE was missing (`GET /my/guardians`, S-READ-3). Names for ACTIVE links only; a pending link renders nameless |

## 2 · GUARDIAN

| Screen | State | Gaps |
|---|---|---|
| gua-home | ✅ B2 `8142231`,`04659da` | the action inbox, deadline-sorted. **Outstanding total removed and NOT relocated** — see gua-pay |
| gua-children | ✅ C6/GUA-FIX/B9 `4bc12e7` | row CTAs (gold Sign → the ceremony `/consents/{id}` · gold Pay) · per-ROW gold budget · whole-card drill **deliberate divergence** (a11y — see Doc 2 §9A) · `consent_request_id` on the enrolment read would drop 3 reads → 2 (**RW**, → C) |
| gua-child | ✅ B3 `3409cca` | the child hub; 7-variable → 4-flat reads; receipts read in |
| gua-space | ✅ B4 `83ff06c` | Fees tab, `isGuardianActor`-gated and structurally lazy (P-3 by construction); order-level rows — `order_lines` on the LIST read is **RW** (→ C). Six tabs = deliberate divergence (Doc 2 §9A) |
| gua-consents / gua-sign | ✅ B8/B9r3 `f7e5d61`,`4cc6a29` | two registers + the signature who-line (`signed_at · v · language`) · `expired` = error tone · media-consent toggle **MG** (P-1 `kind`) · **[Withdraw…] → mRevoke DU** (D1: a guardian-initiated withdrawal is a REQUEST to ops, not a button over `void`) |
| gua-pay | ✅ B10 `92cdab4` | per-order cards + inline line table + Total + gold `Pay online`; settled cards carry their receipt. **Reference OMITTED** — `payment_links.order_reference` is derived AT MINT and served only on the anonymous `/pay/{token}` path, so it does not exist for an unpaid order (**RW+DR** if ever wanted) · `Bank transfer / FPS` → mFps **DR(D-6)** — B-17 says school-side · N+1 on lines → `order_lines` on the orders list (**RW**, → C) |
| gua-reqs | 🔴 | the whole Requests surface **DU** (withdrawal UI both edges + C-2 change-requests) — **the 6th nav slot lands with it** |
| gua-me | ✅ B5/B9 `b0375b6`,`4bc12e7` | identity + language + "Guardian · {n} linked children" (the LINK count) · pairing = **redeem** ("Enter a pairing code") — the block named the wrong actor · notification prefs **DU** (no notifications domain) |

## 3 · MENTOR — thinnest persona

All six screens effectively 🔴 above the API. men-teams/men-team **CL+RW** (roster/gates reads exist; check-ins **MG** B-8/M-4) · men-sess attendance **CL** but marking-as-transition **DR** (design wants request grammar C-8) · men-comp **CL** flag-gated (P-HYGIENE-1 fix first) · men-grade **DR(D-5)** — delegated grading permission doesn't exist. **Mentor persona is gated: P-HYGIENE-1 → then CL cards → grading waits on D-5.**

## 4 · SCHOOL ADMIN — the largest gap (whole shell 🔴)

sch-over/students/student/teams/sess **CL** (reads exist under school RLS) · sch-reqs endorsement UI **CL** (API exists) · sch-comp/sch-board **CL** (lobby reads exist) · sch-chase **CL** for the list, `Send reminder` **DU**(D6) · sch-bill invoices **CL**, `Mark remitted` **DR(D-6)** — the school-side declaration B-17 grants. **Sequencing: the shell + Overview + Students first (pure CL), chase/billing next, anything finer-grained than the 10 coarse permissions waits on D-5.**

## 5 · OPS — backend-complete, UI ~half

Queues (appr/wd/consops) ✅ built, restyle **CL** · **ops-board + ops-comp** — the Formation board with occupancy + drag-compose: **CL** (all reads exist; the confirm ceremony exists) — highest-value staff card · ops-stu Student 360 ✅ (parked V3 supersession pending) · ops-enrs/ops-enr Enrolments-as-record **CL** · ops-team Team 360 = the drawer (ruled) 🟡 promote **CL** · ops-prog Programme 360 🟡 · **ops-sess J-20** + **ops-config wizard J-19** — backend-complete, decision-free, still unstarted **CL** · ops-assess ✅ + release ceremony ✅; release-separated-from-authorship **DR(D-5)**.

## 6 · FINANCE + AUDIT — nearest done

fin-rec/conf/refunds ✅ (BI-9 + P0-SAFE-3; Payments' stale amber literal owed) · fin-orders **CL** · fin-aging **CL** (invoices exist) · fin-recon 🟡 assertion list **CL** · aud-log/aud-consent ✅; export + programme facet **CL** · finance name-blanking **DR(D-3)**.

---

## 7 · THE SERVER BACKLOG, CONSOLIDATED (from every verdict table)

**Served in PHASE B — removed from this backlog:** `/my/children` · `/my/guardians` (S-READ-3 `f0004d1`) · marketplace fee totals · `enrolment_opens_at` · consent signature facts on the family list (B8 `f7e5d61`) · consent TTL + sweeper (S-TTL-1 `e6525d5`).
**Read widens (RW):** `consent_request_id` on the enrolment read (→ C; would take gua-children back to 2 requests) · `order_lines` on the orders LIST read (→ C; removes B10's N+1 on gua-pay) · enrolment-list tracker/results columns · forming-count aggregate · transition-log read (`{state,at}`, no actor/notes — ruled shape) · tile evidence sub-lines · member-count aggregate if ever wanted (own ruling).
**Migrations (MG):** ~~programme term~~ (RECLASSED — the columns shipped 25 Jul; `starts_at` gained its writer in FIX-REFUND-SEED, so the start date is an **RW**, not a migration; only `ends_at` remains open, AUDIT-2 A-1) · `assessments.released_at` + `max_score` · consent `kind` (media, P-1) · **incident_notes ⚠️ child-safety, promote** · mentor_checkins (M-4) · stage requirements model (7 types: attendance-count, upload, mentor-review, check-in, deliverable, session-ref, sequence-lock **if** ruled) · ~~P-HYGIENE-1 denormalise~~ ✅ **CLOSED** (`c56e5d4` — `programme_id` on team_members + stage_gates, composite FKs) · ~~composite-FK hygiene~~ ✅ (same commit) · **period_locks (→ E)** · **P-4 student's own consent read (→ E)** · `basics.ends_on` + programme term (→ E).
**Domains (DU):** notifications D6/B-19 (blocks: Remind, bell drawer, release-notify, chase reminder, "you'll be notified") · join/change/withdrawal request grammars (B-4/C-1/C-2) · invite codes (B-2/J-3/M-1) · team fields (B-1) · interest ping.
**Decisions (DR):** **D-5 vocabulary — the long pole** (school fine-grain, mentor grading, release-separation, delegation-config) · D-6 remittance (gua-pay vs sch-bill) · D-4 DENY-WINS · D-3 name-blanking · X-3 seam before delegation-config.
**Hygiene / integrity (FIX-REFUND-SEED follow-ups, AUDIT-2 §A):** **PRIORITY — the demo seeder must publish through `WizardService::publish`**: `DemoSeeder:287` sets `status = 'published'` directly, skipping pre-flight, version snapshot, capacity seed AND policy seed, so demo data never exercises the publish contract — which is exactly why the NULL-window refund defect stayed invisible · admin `save()` still accepts NULL windows (an explicit staff act, but the invariant now holds only for the provisional seed — wants a validation ruling) · **MG** a DB-level `NOT NULL`/CHECK guard on `withdrawal_policies` window columns (stronger than a service-layer guard; migration + backfill).

---

## 8 · SEQUENCE (dependency-derived)

**Phase A — CLOSED.** **Phase B — CLOSED** (B1–B10 + the read/seed/TTL cards that unblocked them). Card ledger, decisions ledger and the loop-closure table: **`docs/reference/KAP-SPRINT-LOG.md`** — the running build ledger, appended per phase, never rewritten.
**Phase C — decision-free staff (all CL, backend-complete):** ops Formation board + composer · Enrolments-as-record · **J-19 wizard UI · J-20 session admin** · fin-orders/aging/recon list · audit export.
**Phase D — school shell (CL under coarse permissions):** shell + Overview + Students/Teams/Sessions + endorsements + chase-list + billing-view.
**Phase E — server round 3:** the RW batch + small MGs (term, released_at/max_score, consent kind, **incident_notes**) → then their consuming CL cards (dated knots, tracker requirements v1, media toggle).
**Phase F — domains:** notifications v1 (unblocks 6 affordances across 4 personas) → request grammars (guardian Requests slot + school endorse + join-request) → invite codes.
**Phase G — ruled work:** D-5 → mentor grading + school fine-grain + release-separation + delegation-config (with X-3) · D-6 → the remittance leg · D-4 → canonical resolver + remaining A-4 arms.

**Standing method:** every phase-end audit **re-walks the sprint log's loop-closure table** — *a phase is not closed while a loop edge it claimed is still open.*

**Rule of thumb the plan encodes:** every phase ships only verdict-gated CL cards or additive server cards; nothing waits on a decision unless the decision genuinely gates it; and the two documents this plan reconciles are re-derived — never remembered — when a card touches their ground.
