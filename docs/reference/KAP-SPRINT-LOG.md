# KAP — SPRINT LOG
### The running build ledger: what happened, what was decided, what remains
Append-per-phase: future phases ADD sections, never rewrite history. · Companion to the four reference docs; the loop table is sourced from KAP-DATA-ATLAS §5, not re-derived.
22 Aug 2026 · Phase B CLOSED (pending B10 + doc/sweep cards in the closing push)

---

## PHASE B — family alignment (B1–B10)

### B.1 · Card ledger

| Card | Commit | What it did · flags left |
|---|---|---|
| B1-STU-HOME | `128c03b` | Student home: hero + NEXT UP + programme cards; 4→2 reads. Flags: venue RW · member-count RW |
| B1R | `f3af55c` | Band split `--ka-hero-card` 96px · stale member_count comment · 8 dead keys. Found: i18n parity≠completeness gap · space-band 122px gap |
| B2-GUA-HOME | `8142231`+`04659da` | Doc 2 gua-home row rewritten from source (contamination) + the action-inbox build; NULL-expiry fabrication fixed. Flag: Outstanding homeless → gua-pay |
| B3-GUA-CHILD | `3409cca` | Child hub: 7-variable→4-flat reads; receipt read in; title-as-link a11y shape; `initials()` 4→1. Rider: fidelity 127.0.0.1 (the ::1 trap) |
| B4-GUA-SPACE-FEES | `83ff06c` | Guardian Fees tab, `isGuardianActor` gated, structurally lazy (P-3 by construction); order-level rows (lines RW). Six tabs ruled deliberate |
| T-I18N-COMPLETE | `3862c5d` | The coverage gate (796 keys + 19 dynamic prefixes, value-set heuristic); found 3 live raw keys on Refunds |
| B5-ME | `b0375b6` | Both Me surfaces; redeem ceremony (server's words; honest pending copy); stu-me 6→1 reads. Reclass: "My guardians" PW→RW |
| B7-MARKETPLACE | `cc3f806` | stu-explore card grammar; progdet DEFERRED (marketing model + fee were unserved). Fee ruling spawned S-READ-3 |
| S-READ-1/2 | `8ff780b`,`07294e1` | (Pre-B) enrolment detail + list widens — the family reads' foundation |
| S-READ-3 | `f0004d1` | /my/children + /my/guardians (AD-2 shape, active-only names) · marketplace fee (authed family, one asSystem/request) · enrolment_opens_at |
| B8-GUA-CONSENTS | `f7e5d61` | Two-register consents + the signature join (1:0..1, three facts); sha-from-failure proof method |
| SEED-CONTRACT-1 | `b8aa74b` | Seeder publishes through WizardService::publish (0→3 versions/capacity/policies); richer branches; storefront falsehood killed |
| S-TTL-1 (+rider) | `e6525d5`+`b50fdc2` | Consent TTL writer (clamped, future-start-only) + sweeper (CAS) + window-field retirement (422) + the 5th ops bucket |
| B9 (+riders 2,3) | `4bc12e7`+`4cc6a29` | S-READ-3 consumed everywhere (picker fix — the dead-end closed at DEMO-AI proof) · gua-children row CTAs · enrollableIn() · expired=error, "History" |
| AUDIT-3 | `5cfc281` | Phase B closeout: 15 surfaces tabled, 10 doc errors, 8 confirmations, 10 hygiene. Headline: B6/gua-pay scope gap |
| CD-FIX-2 | `760832c` | kap-seed job APP_KEY=kap-app-key:latest (registration chain touches Crypt; CI's throwaway key masked it) |
| DOC-FIX-3 · SWEEP-ZH-1 · B10-GUA-PAY | *(closing push)* | The ten §B corrections + P-3 split line + this file · the 19× 成團 careful sweep (2 unsafe subclasses atomic) · the last family surface |

### B.2 · Decisions ledger (owner + delegated rulings made mid-phase)

| Decision | Ruling |
|---|---|
| Programme fees | **Family-visible pre-enrolment**, published only, authed only; served via marketplace read; fee_items_read untouched; single_reader stays true |
| P-3 split | Order amounts = one family's obligation, never the student. **List prices = marketing, every viewer** — both halves now doctrine (Doc 1A §3.1) |
| "My guardians" | PW→RW — RLS admitted the student all along; the route was missing (S-ROSTER discipline: walk all paths) |
| expired tone | **error**, per the shared domain; local colour maps deleted (drift is what greys made) |
| TTL clamp | min(issue+14d, starts_at) — **future starts only**; a running programme never mints a born-expired consent |
| OD-11 | **Obsolete** (seat timer for retired machinery); hold_window_days read by nothing — doc-pass row |
| Window fields | Wizard owns the timeline; ProgrammeController write path **retired with 422**, never silent-drop |
| Six guardian tabs | Deliberate divergence — the prototype's combined tab is a demo shortcut; we compose what its mirror note promises |
| Pairing actor | The prototype named the wrong actor: student STARTS a code, guardian REDEEMS ("Enter a pairing code") |
| History heading | "Signed and settled" over-claimed a register holding declined/voided/expired → "History" |

### B.3 · Standing disciplines proven this phase
Verdict-before-build (caught my gua-home/gua-pay doc contamination, the photo-hero misread, the fees invention) · shots-as-ground-truth (caught ka-linkname, the ::1 wrong-product trap, tab overflow, fabricated countdowns, antd blue ×2) · walk-all-paths before "not served" (roster, audit arm, My-guardians, signed_at) · server's words in child-safety ceremonies · behaviour-sha-from-failure · CAS sweepers · teardown keeps audit rows (BI-1 STOP).

---

## LOOP-CLOSURE TABLE (Atlas §5 · re-walked at every phase-end audit)
**A phase is not closed while a loop edge it claimed is still open.**

| Loop | State | Open edges → closing card · phase |
|---|---|---|
| Money | ✅ core | aging UI → C · **period_locks P-8 → E** |
| Consent | 🟡 | D1 revocation UI (mRevoke) → F · media `kind` MG → E · **P-4 student-own-consent read → E** · re-issue UI → F · family 3rd-state polish → done (B9r3) |
| Formation | ✅ core | join-request grammar B-4 → F · change requests C-1/C-2 → F |
| Withdrawal | 🟡 | family UI (3 entry points, one card) → F · school endorse UI → D · cancel J-17 → F |
| Audit | ✅ | export → C |
| Delegation | 🔴 | /me effective caps (S-1) · config screen · X-3 seam · canonical rule → **G (D-4/D-5)** |
| Recognition | 🔴 | issuance ledger → J-15, v2 (deliberate) |
| Notification | 🔴 | the domain → F (unblocks Remind · bell drawer · chase reminders · release-notify · "email coming") |

---

## REMAINING QUEUE (post-B10)

**Phase C — decision-free staff (~10):** Formation board + composer · Enrolments-as-record · J-19 wizard UI · J-20 session admin · queue restyles (appr/consops/wd — note ops-wd's "Endorse" label vs OD-26 needs a ruling first) · fin-orders/aging/recon · audit export + Detail/Reason · small servers (consent_request_id on enrolment read · order-lines on orders list · useResource dedupe · rig token-auth · compose queue worker).
**Phase D — school shell (~8):** Overview · Students/Student · Teams · Sessions · endorsements · chase (+ Request re-issue) · billing view · board.
**Phase E — server round (~8):** stage-requirements model (7 types) · transition-log read `{state,at}` · basics.ends_on + term · released_at/max_score · consent `kind` · **incident_notes ⚠ child-safety** · period_locks · P-4 · (member-count aggregate iff ruled).
**Phase F — domains (~6):** notifications v1 · request grammars + family withdrawal (3 entries) + school endorse + J-17 · invite codes (M-1) · re-issue UI · mentor check-ins (M-4).
**Phase G — gated on owner rulings:** **D-5 vocabulary (gates the most — nothing cardable until ruled)** · D-6 remittance (gua-pay FPS vs sch-bill) · D-4 DENY-WINS canonicalisation + remaining A-4 arms · D-3 finance name-blanking (the prototype sides with E2 at fin-conf L1813).

**Ride-along debts (no own cards):** seeder cosmetics (session times, zh-TC signature) · 3 sibling amber sites · 51 unreferenced keys (report-only posture, deliberate).
