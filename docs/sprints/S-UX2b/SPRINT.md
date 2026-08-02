# SPRINT S-UX2b — API display-name additions

> **Phase:** UX (post-S07, pre-S08). **Card 1 of the UX phase.** Ruled sequence:
> **S-UX2b → S-UX1 → S-UX2a → S-UX3 (chunked) → S-UX4 interleaved.** S08 stays parked until after S-UX2.
> Origin: `docs/product/UI-INVENTORY.md` §3a — the list endpoints return bare FK IDs, so the display
> layer cannot be frontend-only. This card supplies the **backend half**: additive display fields.

## 1. Goal

For every list/read endpoint that returns bare foreign-key IDs (§3a), **add embedded display fields**
(names, localized programme names) alongside the existing IDs, mirroring the pattern the report
endpoints already use (`EnrolmentPoolReportController`:
`->join('users as s','s.id','=','e.student_id')->get([…, 's.name as student_name'])`).

**This card is backend-only.** It writes no UI. Each field it adds names its **S-UX2a consumer** —
the frontend that will render it once the shared display kit lands.

## 2. Hard constraints (all three bind every step)

1. **Additive only — no breaking response change.** Every existing key stays, with the same name and
   type. New keys are *added* to each row. No renames, no removals, no shape changes. A current client
   keeps working untouched.
2. **Respect RLS / scope — a name is PII.** A display name is only ever returned for a row the
   caller's scope **already permits reading**. We add names by **joining onto rows RLS already
   returns** — the join exposes no new *row*, only a friendlier column on a row the caller can already
   see. Where an endpoint's scope could be **broader than the name's owning family** (cross-family or
   anonymous surfaces), we **STOP and flag for a ruling** rather than add the name (§4).
3. **Language-scoped programme names.** Programmes carry `name_en` / `name_tc` / `name_sc` (+ `code`).
   Return the **triple** (`programme_name_en/tc/sc`) so the frontend localizes with the existing
   `nameFor()` helper — never a single pre-picked language, never the code alone.

## 3. In scope — endpoints & fields to add

Naming convention: **reuse the report endpoints' exact field names** where one already exists
(`student_name`, `acting_guardian`) so S-UX2a can share render code; use a `_name` suffix for new
ones (`signer_name`, `actor_name`, `recorded_by_name`, `verified_by_name`).

| # | Endpoint (controller) | Current bare IDs | Fields to ADD | Source join | RLS / PII note | S-UX2a consumer |
|---|---|---|---|---|---|---|
| 1 | `GET /enrolments` (`EnrolmentController@index`) | `programme_id`, `student_id`, `acting_guardian_id` | `programme_name_en/tc/sc`, `student_name`, `acting_guardian` | `programmes` + `users`×2 | **VERIFY scope first** (§5 T1): confirm `enrolments` is family/admin-scoped so no cross-family name leaks | `Enrolments.tsx` table (`:40-41`) |
| 2 | `GET /consent-requests` (`ConsentRequestController` list, `:40`) | `programme_id`, `student_id`, `signer_id` | `programme_name_en/tc/sc`, `student_name`, `signer_name` | `programmes` + `users`×2 | **VERIFY scope first** (§5 T1): same family/admin-scope check | `Consents.tsx` List (`:67-68`) |
| 3 | `GET /consent-signatures` (`ConsentRequestController`, `:83`) | `signer_id` | `signer_name` | `users` | Admin/evidence-scoped report; signer name is within scope | `ConsentEvidence.tsx` |
| 4 | `GET /consent-documents` (`ConsentRequestController`, `:100`) | `signer_id` | `signer_name` | `users` | as #3 | `ConsentEvidence.tsx` |
| 5 | `GET /reports/consent-evidence` (`ConsentEvidenceReportController`, `:33`) | `programme_id`, `student_id`, `signer_id` | `programme_name_en/tc/sc`, `student_name`, `signer_name` | `programmes` + `users`×2 | Admin/audit-scoped report — names within scope by construction | `ConsentEvidence.tsx` (`:71-72`) |
| 6 | `GET /audit-events` (`AuditEventController`) | `actor_id` (+ `entity_id` UUID) | `actor_name` (nullable → frontend shows "System") | `users` (left join — actor may be null/system) | Audit-read / audit_read capability scope; actor name within scope. **`entity_id` is polymorphic — DEFERRED (§4)** | `AdminAudit.tsx` (`:145`) |
| 7 | `GET /teams/{team}/finance-report` (`FinanceReportController`) | `recorded_by`, `verified_by` | `recorded_by_name`, `verified_by_name` | `users`×2 | Team-scoped RLS (member-only); names are of the recording/verifying members within the team | **S-UX3** team-finance surface (not S-UX2a — flagged as later consumer) |

## 4. STOP / flag-for-ruling (do NOT add names here without a decision)

- **Payment-link / pay surfaces** — `GET /pay/{token}`, `GET /my/payment-links`,
  `POST /my/orders/{id}/payment-link`. **HARD EXCLUSION, not a ruling:** OD-44 /
  `payment_links.no_pii` structurally forbids any name on the anonymous forwardable payload —
  initials only, enforced by assertion (`PaymentLinkNoPiiAssertion`) and by the table having no
  name/email columns. **This card must not touch these, and must not red that assertion.**
- **`audit_events.entity_id`** (polymorphic UUID across many entity types) — cannot be resolved with
  one uniform join. Needs a per-`entity_type` label resolver. **DEFERRED to a dedicated later card**
  (call it S-UX2b-follow or fold into S-UX3); out of scope here. The card adds `actor_name` only; the
  raw `entity_id` stays as-is for now.
- **Any endpoint where §5 T1 shows the scope is broader than the name's owning family** — if the RLS
  probe reveals a caller can see an enrolment/consent row from *outside* their family (they should not
  be able to), that is a scope defect, not a display gap: **STOP and report to Leo** before adding the
  name. Adding a name on a wrongly-visible row would widen a leak.

## 5. VERIFY plan (run before commit; paste real output — never summarize)

**T1 — RLS scope probe (discharges constraint 2 for #1, #2).** A test that a **cross-family** caller
(guardian A) listing `/enrolments` and `/consent-requests` receives **zero rows belonging to family
B** — proving the name-join only ever rides rows the caller already sees. If any foreign row appears,
STOP (§4). Assert row-count = own-family-only both before and after the name additions.

**T2 — Additive/non-breaking.** For each endpoint #1–#7: assert **every pre-existing key still
present** with unchanged type, AND the new `*_name` / `programme_name_*` keys present and correctly
populated (name matches the joined user/programme). A snapshot-style field-set assertion per endpoint.

**T3 — Null-safety.** `actor_name` is null/"System" when `actor_id` is null (left join, #6);
`acting_guardian`/`signer_name` handle a null FK without dropping the row (LEFT JOIN, not INNER, where
the FK is nullable — do not let a name-join silently filter rows out). Assert row counts are identical
with and without the joins.

**T4 — PII guardrail unbroken.** `php artisan reconcile:run` — full battery still **58/58**, and in
particular `payment_links.no_pii` **green** (proves §4 exclusion held). Paste the runner output.

**T5 — Suite green.** `php -d memory_limit=1G vendor/bin/phpunit --filter '/^(?!.*ClamAv).*/'` — full
suite green ex-clamd (clamd is the S10 env item, [[clamd-oom-foreign-supabase]]). Paste the tail.

**T6 — Live read (optional, if stack up).** `curl` #1 and #6 against the running instance under a
seeded admin token; paste the JSON showing IDs **and** names side by side.

## 6. Out of scope (report, don't build)

- Any frontend change — that is **S-UX2a** (shared `formatMoney`/`formatHkt`/`<StatusTag>`/id→name
  adoption + the §6 fetch-wrapper convention). This card only makes the API *return* the names.
- `GET /teams` (`FormationController@index`) name additions — **deferred**: no S-UX2a/S-UX3 consumer
  built yet. Add `programme_name_*` / `category_name` / `created_by_name` when the teams UI lands.
- The polymorphic `entity_id` resolver (§4).
- Enum→label mapping and money/date formatting — those are display-side, S-UX2a.

## 7. Invariants touched

- **BI-1 / BI-8 (audit):** none written — this card only *reads* `audit_events` and joins a name; no
  write path, no new audit surface.
- **OD-44 / `payment_links.no_pii`:** must remain green (§4, T4). This card's guardrail.
- **No migration.** Pure read-shape additions in controllers — **no schema change, no migration
  file.** (If any endpoint turns out to need an eager-load that a migration would ease, STOP — that is
  a scope change to raise, not to take.)

## 8. Definition of done

All seven endpoints return their display fields additively; T1–T5 green with pasted output (T6 if
stack up); battery 58/58 incl `payment_links.no_pii`; no migration; each field's S-UX2a consumer named
above. Then: plan → build → VERIFY w/ screenshots → review → commit (standing UX-phase rule). Card
ends with `docs/sprints/S-UX2b/AUDIT.md`.
