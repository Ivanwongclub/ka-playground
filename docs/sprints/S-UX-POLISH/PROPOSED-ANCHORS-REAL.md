# PROPOSED — The Three Anchors For Real (wizard · Payments · My Children)

**Think-first. Plan only — no code, no commit.** First card of the restyle rollout — the **first card that
changes a product surface's appearance.** Two of the three anchors are **gated-review surfaces**: Payments
(money — BI-9, receipts, OD-5) and the wizard (publish / consent-lock / OD-19). So this is *"restyle the
money + publish UI while PROVING the behavior survives"* — reviewed for **behavior-preservation**, not just
appearance. Blessed visual target: the anchor prototypes (`dda3690`), made real over the LIVE data/behavior.

> Grounded in the real source: `pages/Payments.tsx`, `pages/AdminProgrammes.tsx` (wizard),
> `pages/SelfService.tsx` (MyChildren); the DS2 library (`@/ds2`, spec complete at `e74df11`).

---

## 0. Headline — what this card is

**A markup restyle, NOT a logic change.** Each anchor swaps its hand-rolled markup for DS2 components; the
**mutate calls, server endpoints, payload shapes and RLS-scoped reads are byte-identical.** Because behavior
lives server-side (and in unchanged payloads), the existing **backend tests stay green unchanged** — that is
the primary behavior-preservation proof, plus a risk shot per gated surface. Only these three surfaces adopt
DS2; every other product surface stays **byte-identical** (the import-guard proves it).

**One correction the source forces on the prototype:** the prototype mocked a **disabled Confirm** for the
recorder's "You" row. The **real** Payments is **shown-not-hidden** — Confirm/Reject are shown to *every*
`finance.confirm` holder and the server refuses a same-person confirm (403), which the UI surfaces
(`Payments.tsx:2-4`). **The restyle MUST preserve shown-not-hidden — it may add a "You" visual cue but must
NOT disable/hide the control.** (See §2 + §7.)

---

## 1. Per-anchor component mapping (prototype → DS2 → current markup replaced)

### 1a. My Children (`SelfService.tsx` → `MyChildren`) — lowest risk
| Prototype | DS2 component | Replaces (current) |
|---|---|---|
| per-child stat cluster | **StatChip** | inline "2 programmes · age 11" |
| session time / location | **MetaChip** | inline session-timing text |
| consent-complete / signature-needed bar | **Attest** (attested / action) | the `ConsentChip` (derivedStatus) advisory |
| enrolment sub-panels + gaps | **SubPanel + ZoneStack** | the flat enrolment list |
| the signed date | **DatedBadge** | (new — the evidential date, from consent data) |
Reads unchanged: child list from `/api/consent-requests`, per-child `/api/enrolments`, consent via
`/my/students/{id}/consent-status` (derivedStatus), sessions link.

### 1b. Payments (`Payments.tsx`) — GATED (money)
| Prototype | DS2 component | Replaces (current) |
|---|---|---|
| Outstanding / Awaiting / Confirmed strip | **StatChip** cluster (or StatCards) | (new — a summary; derived from the same lists) |
| the queue table | **ZebraTable** (money→right, status/action zones) | the two `Card→Table` blocks |
| status pills | **StatusTag** (reused) | current StatusTag |
| the seal on confirmed + receipt no. | **Seal** + mono | (new — over the existing receipt_number) |
Writes unchanged: `mutate('/api/admin/payments', fd)` (record), `mutate('/api/admin/payments/{id}/confirm')`,
`mutate('/api/admin/payments/{id}/reject', …)` — same endpoints, same FormData/JSON.

### 1c. Wizard (`AdminProgrammes.tsx`) — GATED (publish/consent)
| Prototype | DS2 component | Replaces (current) |
|---|---|---|
| dependency-aware rail | **WizardRail** (state icons + per-phase counts) | the flat `List` of sections |
| readiness ring | **ProgressRing** | the `Progress` bar (`readiness.complete/required`) |
| the marketing trilingual entry | **FormLanguageSwitcher** | the **12 stacked `<Input>`s** (4 fields × en/tc/sc) |
| section-complete / locked signal | **Attest** / lock glyph on the rail | the section-status `Tag`s |
Writes unchanged: `PUT /api/admin/programmes/{id}/wizard/{key}` (saveSection), `POST …/pre-flight`,
`POST …/publish` — same endpoints, same payloads.

---

## 2. BEHAVIOR-PRESERVATION plan (the critical part)

### Payments (line-by-line) — every money behavior, preserved & proven
| Behavior | How preserved | Proven by |
|---|---|---|
| **BI-9 self-confirm block** | **shown-not-hidden kept**: Confirm/Reject stay shown to every `finance.confirm` holder; the UI never hides on "did I record this"; the server's **403 is surfaced** (the existing `surface()` on the mutate result). **NOT disabled.** A "You" cue on the recorded-by cell is optional-visual-only (§7). | the payment SoD tests (server refuses recorder=confirmer) unchanged + green; risk shot |
| record→confirm→receipt flow | same three mutate endpoints, same order; the receipt number renders from the same field | payment tests unchanged |
| **OD-5 full-amount** | the record Modal still posts the full outstanding amount (read-only), same `mutate('/api/admin/payments', fd)` | unchanged endpoint |
| shown-not-hidden refusals | every refusal still comes from the server message via `surface()`/`message.error` | unchanged mutate path |
| **token_hash never shown** | the queue renders only the existing selected fields; ZebraTable columns are an explicit allowlist — no new field is fetched or shown | column set is code-reviewed line-by-line |

### Wizard (line-by-line) — publish/consent/OD-19, preserved & proven
| Behavior | How preserved | Proven by |
|---|---|---|
| **publish gate** | the publish button keeps `disabled={!ready}` (readiness.complete≥required) **and** the server `POST …/publish` stays authoritative (its message surfaced) | wizard/publish tests unchanged; risk shot |
| **consent-lock-on-publish (423)** | `LOCKED_WHEN_PUBLISHED` is server-enforced; the restyle shows a **lock glyph** (WizardRail) but the ACTUAL lock is the server **423 surfaced** on saveSection — unchanged | lock tests unchanged |
| **marketing OD-19 completeness** | **the FormLanguageSwitcher drives the SAME `saveSection` payload** — per field `{en,tc,sc}` in the same `sectionDraft` shape; it changes the VIEW (form-level switch + single input) not the data model, so the server's `marketing.language_incomplete` (422) fires **identically** | MarketplaceCatalogue/marketing tests unchanged; risk shot |
| readiness / pre-flight | ProgressRing reads the same `readiness`; `POST …/pre-flight` unchanged | unchanged endpoints |

**The load-bearing wizard assertion:** FormLanguageSwitcher is a VIEW over the existing `{en,tc,sc}` per-field
model. The `saveSection` payload shape MUST be proven unchanged in the diff — that is what keeps OD-19 + the
423 lock firing identically. (Line-by-line reviews this specifically.)

### My Children (frontend-scan) — reads that already passed
| Behavior | How preserved | Proven by |
|---|---|---|
| own-child-only reads | same endpoints (`/consent-requests`, `/enrolments`, `/my/students/{id}/consent-status`), same RLS — no data-shape change | S-UX3-9 tests unchanged (already green) |
| consent advisory | the Attest renders the same `consent_met` / `your_signature_needed` booleans | unchanged read |
| no cross-child leak | no new fetch; the child list + per-child reads are byte-identical | line-of-sight in the diff |

---

## 3. Import-guard ALLOWED additions (deliberate, this card only)

Add exactly three files to `scripts/ds2-import-guard.mjs` ALLOWED:
`src/pages/Payments.tsx` · `src/pages/AdminProgrammes.tsx` · `src/pages/SelfService.tsx`.
- **No other surface is touched** — every other product page stays byte-identical (the guard fails if any
  non-allowed file imports `@/ds2`).
- **Note on `SelfService.tsx`:** it also contains `MyPayments` and `MyStudents`. This card restyles **only
  `MyChildren`**; the file enters ALLOWED (it now imports `@/ds2`), but MyPayments/MyStudents markup stays
  **unchanged** — confirmed in the diff. (They restyle in their own later slots.)

---

## 4. 成團 → "Team Formation" — NOT on these surfaces (defers)

**From source: 成團 does not appear in any of the three anchors.** It is confined to `Teams.tsx` and
`StudentTeam.tsx` (i18n `teams.*` / `studentTeam.*`), plus the **EN** locale mixing it in ("Awaiting 成團",
"Submit for 成團"). The shared nav is `nav.team = "Team"` (no 成團). So **this card touches no 成團 text** —
the copy-standard **rides the card that restyles the Teams surfaces**, not this one.

Proposed treatment (for that later card, recorded now):
- **EN locale** — replace the mixed "成團" with pure English: "Awaiting 成團" → **"Awaiting formation"**,
  "Submit for 成團" → **"Submit for formation"**, label → **"Team Formation"**. No CJK in EN strings.
- **zh-TC** — keep **成團** (already the proper term). · **zh-SC** — keep **成团**. No "Team 成團" mix.

---

## 5. Split, depth & build order

**One card, three steps** (each its own commit HELD):
- **STEP 1 — My Children (FRONTEND-SCAN).** The low-risk warm-up: validates SubPanel/ZoneStack/Attest/
  StatChip/MetaChip/DatedBadge on a **real** surface (live reads that already passed S-UX3-9) before any
  gated surface depends on them. De-risks the honesty component (Attest) on real consent data first.
- **STEP 2 — Payments (LINE-BY-LINE, gated).** ZebraTable + the summary strip + Seal; BI-9 shown-not-hidden
  preserved; every mutate call diffed byte-identical. Risk shot: the same-person confirm 403 rendered.
- **STEP 3 — Wizard (LINE-BY-LINE, gated; the biggest).** WizardRail + FormLanguageSwitcher + ProgressRing;
  the saveSection payload proven unchanged (OD-19 + 423). Risk shot: the publish-gate / language-incomplete
  refusal rendered.

**Order rationale:** warm-up (My Children) → money (Payments) → publish (wizard). The warm-up proves the
components against real data + real Attest before the gated surfaces; the two gated surfaces then get
line-by-line eyes with the components already validated.

---

## 6. What "behavior-preserved" is PROVEN by

1. **The existing BACKEND tests stay green, UNCHANGED.** The restyle touches React markup only — not the
   endpoints, mutate calls, payloads, or RLS. So the payment-SoD tests, the marketing/OD-19 tests, and
   `SelfServiceUxTest` (My Children) are **not modified** and must stay green. Green-unchanged = the behavior
   the tests pin is untouched. (If a restyle forces a test change, that is a red flag to STOP — it means the
   behavior moved.)
2. **A risk shot per gated surface** (shown-not-hidden proof on a new-look surface):
   - Payments: the BI-9 **same-person confirm 403** rendered (Confirm shown, clicked by the recorder,
     server-refused, message surfaced) — proving shown-not-hidden survived the restyle.
   - Wizard: the **publish-gate refusal** OR the **marketing `language_incomplete` (422)** rendered — proving
     the gate still fires through the new FormLanguageSwitcher/WizardRail.
3. **Line-by-line diff review** (Payments + wizard) that every `mutate(...)` / endpoint / payload is
   byte-identical, and that no new field (esp. `token_hash`) enters a read. tsc / build / i18n-parity green;
   the import-guard confirms only the three files adopted DS2.

---

## 7. Open decisions for Leo
1. **The BI-9 "You" cue** — keep showing the recorder **name** (fully preserves current), or add a **"You"
   indicator** when `recorded_by === identity.id` (a nicer cue)? **Either way the Confirm stays enabled and
   the 403 is surfaced — never disabled** (that would regress shown-not-hidden). *Recommend: name + an
   optional "You" cue, button always enabled.*
2. **Wizard section editor** — adopt the prototype's **inline** rail+editor, or keep the current **Drawer**
   editors and only swap the List→WizardRail navigation? *Recommend: inline (matches the prototype), with the
   saveSection logic untouched — but this is the larger restructure; the Drawer-kept option is lower-churn.*
3. **`SelfService.tsx` scope** — confirm MyPayments/MyStudents stay untouched this card (only MyChildren
   restyles), even though the file enters the import-guard ALLOWED.
4. **The summary strip on Payments** — StatChips vs the fuller Statistic stat-cards from the prototype
   (Outstanding / Awaiting confirmation / Confirmed today)? *Recommend the stat-cards (they carry the
   money-language hierarchy).*

---

### One-line recommendation

Restyle the three anchors in **three steps — My Children (frontend-scan warm-up) → Payments (line-by-line,
gated) → wizard (line-by-line, gated)** — swapping markup for DS2 components while keeping **every mutate
call, endpoint and payload byte-identical**; preserve BI-9 as **shown-not-hidden** (never the prototype's
disabled Confirm) and prove the wizard's FormLanguageSwitcher drives the **same saveSection payload** so
OD-19 + the 423 lock fire identically. Behavior-preserved is proven by the **unchanged backend tests staying
green** + a risk shot per gated surface. 成團 → "Team Formation" is **not** on these surfaces (defers to the
Teams rollout). Add exactly the three files to the import-guard ALLOWED; every other surface stays
byte-identical.
