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
| stu-home | 🟡 dashboard exists, old composition | NEXT-UP card + greeting hero + programme cards **CL** (reads exist post S-READ-2) |
| stu-progs | ✅ C6 | term **RW** (not MG — `programmes.starts_at` exists and is now written; the read doesn't carry it) · tracker/results rows **RW** (enrolment-list) · forming-count **RW** · Remind **DU**(D6) |
| stu-space | ✅ 5 tabs | stepper dates **MG/RW** (transition-log read, `{state,at}` only — ruled shape) · tile `.ev` sub-lines **RW** · tracker requirements **MG×7** (see §6) · join-request block **DU**(B-4) · join-with-code **DU**(B-2/M-1) · wall names **PW** |
| stu-explore / stu-progdet | 🟡 marketplace exists, needs restyle to card grammar | **CL**; "Ask my guardian" interest ping **DU** (small — an interest table or notification) |
| stu-me | 🔴 placeholder | identity+language **CL** · "My guardians" **PW** (ungranted read — or rule a grant) |

## 2 · GUARDIAN

| Screen | State | Gaps |
|---|---|---|
| gua-home | 🟡 | action-card composition to prototype **CL** |
| gua-children | ✅ C6/GUA-FIX | team-on-row/next-session ✅ served · whole-card drill **PW** (a11y divergence, documented) |
| gua-child | 🔴 | child record page **CL** (reads exist: links, enrolments, consents) |
| gua-space | 🟡 mirror works | **Fees tab** (guardian-only addition) **CL** — orders read already guardian-scoped |
| gua-consents / gua-sign | ✅ ceremony exceeds spec | media-consent optional toggle **MG** (P-1 `kind`) · countdown chips ✅ |
| gua-pay | 🟡 | itemised lines ✅ exist; restyle **CL** · "I've paid — submit reference" **DR(D-6)** — B-17 says school-side |
| gua-reqs | 🔴 | the whole Requests surface **DU** (withdrawal UI both edges + C-2 change-requests) — **the 6th nav slot lands with it** |
| gua-me | 🔴 placeholder | identity/language/notification-prefs **CL/DU** · "Link another child" pairing entry **CL** (ceremony exists) |

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

**Read widens (RW):** enrolment-list tracker/results columns · forming-count aggregate · transition-log read (`{state,at}`, no actor/notes — ruled shape) · tile evidence sub-lines · member-count aggregate if ever wanted (own ruling).
**Migrations (MG):** ~~programme term~~ (RECLASSED — the columns shipped 25 Jul; `starts_at` gained its writer in FIX-REFUND-SEED, so the start date is an **RW**, not a migration; only `ends_at` remains open, AUDIT-2 A-1) · `assessments.released_at` + `max_score` · consent `kind` (media, P-1) · **incident_notes ⚠️ child-safety, promote** · mentor_checkins (M-4) · stage requirements model (7 types: attendance-count, upload, mentor-review, check-in, deliverable, session-ref, sequence-lock **if** ruled) · P-HYGIENE-1 denormalise (in flight) · composite-FK hygiene · period_locks.
**Domains (DU):** notifications D6/B-19 (blocks: Remind, bell drawer, release-notify, chase reminder, "you'll be notified") · join/change/withdrawal request grammars (B-4/C-1/C-2) · invite codes (B-2/J-3/M-1) · team fields (B-1) · interest ping.
**Decisions (DR):** **D-5 vocabulary — the long pole** (school fine-grain, mentor grading, release-separation, delegation-config) · D-6 remittance (gua-pay vs sch-bill) · D-4 DENY-WINS · D-3 name-blanking · X-3 seam before delegation-config.
**Hygiene / integrity (FIX-REFUND-SEED follow-ups, AUDIT-2 §A):** **PRIORITY — the demo seeder must publish through `WizardService::publish`**: `DemoSeeder:287` sets `status = 'published'` directly, skipping pre-flight, version snapshot, capacity seed AND policy seed, so demo data never exercises the publish contract — which is exactly why the NULL-window refund defect stayed invisible · admin `save()` still accepts NULL windows (an explicit staff act, but the invariant now holds only for the provisional seed — wants a validation ruling) · **MG** a DB-level `NOT NULL`/CHECK guard on `withdrawal_policies` window columns (stronger than a service-layer guard; migration + backfill).

---

## 8 · SEQUENCE (dependency-derived)

**Phase A — finish in flight:** P-HYGIENE-1 (gates mentor) · Payments amber · V3 supersession · owed rig artefacts.
**Phase B — family polish (all CL):** stu-home + gua-home compositions · gua-space Fees tab · gua-child · both Me surfaces · marketplace restyle + detail.
**Phase C — decision-free staff (all CL, backend-complete):** ops Formation board + composer · Enrolments-as-record · **J-19 wizard UI · J-20 session admin** · fin-orders/aging/recon list · audit export.
**Phase D — school shell (CL under coarse permissions):** shell + Overview + Students/Teams/Sessions + endorsements + chase-list + billing-view.
**Phase E — server round 3:** the RW batch + small MGs (term, released_at/max_score, consent kind, **incident_notes**) → then their consuming CL cards (dated knots, tracker requirements v1, media toggle).
**Phase F — domains:** notifications v1 (unblocks 6 affordances across 4 personas) → request grammars (guardian Requests slot + school endorse + join-request) → invite codes.
**Phase G — ruled work:** D-5 → mentor grading + school fine-grain + release-separation + delegation-config (with X-3) · D-6 → the remittance leg · D-4 → canonical resolver + remaining A-4 arms.

**Rule of thumb the plan encodes:** every phase ships only verdict-gated CL cards or additive server cards; nothing waits on a decision unless the decision genuinely gates it; and the two documents this plan reconciles are re-derived — never remembered — when a card touches their ground.
