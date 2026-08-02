# AUDIT KAP-S-UX2a — Shared display kit + fetch convention

**Result:** PASS · **Date:** 2026-08-02 · **HEAD at commit:** `7a43391` · **Card:** `SPRINT.md`

> Written at the card's end. Honesty outranks looking good. Third card of the UX phase (S-UX2b ✓ →
> S-UX1 ✓ → **S-UX2a** → S-UX2b-f → S-UX3 → S-UX4). Frontend-only. Origin: `docs/product/UI-INVENTORY.md` §3 + §6.

## 1. What S-UX2a is

The display layer the app never had, built once and adopted everywhere: money/date formatters, a
StatusTag enum→label registry, id→name helpers (consuming the S-UX2b fields), a shared fetch
convention, a catch-all 404, and parallelised dashboard reads.

## 2. Files changed (20; +729 / −304)

**Kit (new, 6):** `display/money.ts`, `display/date.ts`, `display/names.ts`, `display/status.tsx`,
`api/useResource.tsx`, `pages/NotFound.tsx`.
**Adopted (12):** `pages/{Enrolments, Consents, EnrolmentPool, AdminAudit, AccessIdentity,
FinancialIntegrity, ConsentEvidence, AdminConsentTemplates, AdminProgrammes, Dashboard}.tsx`,
`main.tsx` (404 route), `i18n/locales/{en,zh-TC,zh-SC}.json` (394-key parity).

## 3. The kit

- **`formatMoney(minor, currency, locale)`** — `Intl.NumberFormat(locale, {style:'currency',currency})`
  on `minor/100`. Null→`—`. No float, no hardcoded `$`/`en-HK`. The generalised (correct) PublicPay pattern.
- **`formatHkt` / `formatHktDate`** — `Asia/Hong_Kong`, locale-aware, null-safe; normalises the API's
  `"…+00"` offset; a bare `YYYY-MM-DD` renders as a calendar date with no TZ shift.
- **`StatusTag`** — closed-set registry (codes taken from the migration CHECK constraints) → i18n label
  + colour; **unknown code → `humanise` fallback, never raw**. **`AuditCode`** — humanised label **and**
  the exact raw code preserved (D-UX2a.1).
- **`programmeName` / `personName`** — the S-UX2b `programme_name_*` triple + `*_name`; a raw FK integer
  never reaches the screen.
- **`useResource` + `<DataBoundary>`** — `res.ok` guarded, `.catch` wired, loading/error/empty rendered
  consistently.

## 4. Step verification — before/after screenshots (all met §5 except the flagged gaps)

Full before/after set in the review bundle. Decisive afters:
- **FinancialIntegrity** — `HK$2,500.00` (Intl currency; was hardcoded `$`/`en-HK`); `Aug 2, 2026,
  12:30 PM` (was raw ISO); `Issued`/`Paid`/`Gateway`/`Confirmed` StatusTags; **`Reconciled`** (was
  `OD-54 ✓`).
- **Enrolments** — `Summer STEM 2026` + `Sam Chan (demo)` (were raw FK ints).
- **AdminAudit** — HKT dates; actor **names** (`System` for null/dangling); action/entity **humanised +
  raw preserved** (`Login login`, `Scope elevation scope_elevation`); `entity_id` stays raw (deferred).
- **Consents (list)** — **crash fixed**: forced 500 → "Couldn't load this / HTTP 500" Alert, not a
  blank (verified `blank=false`).
- **404** — real Result page in-shell (replaces S-UX1 S13's blank).

**Money — line-by-line:** `formatMoney(minor, 'HKD', locale)` = integer minor ÷ 100 via Intl currency
style; sites = FinancialIntegrity order/refund/payment amounts, credit-notes, invoice
original/balance, reconciliation figures. HKD-only (Phase 1; multi-currency is OD-18). No float, no
hardcoded symbol/locale.

**Build gate:**
```
$ npm run build
OK  en/zh-TC/zh-SC — 394 keys, parity complete
i18n:check PASSED — parity complete, no hardcoded user-facing strings
tsc -b · vite build · bundle-budget PASSED
```
The §3f hardcoded leaks (min/max/%/placeholder-s03/OD-54) are removed; i18n parity holds.

## 5. Deviations / flags

| Item | Outcome | Why |
|------|---------|-----|
| **AccessIdentity `actor_id`/`student_id`** | **Left raw + FLAGGED** | `/reports/access-identity` returns no name field (not in S-UX2b's scope). Not fixed under a frontend card — **scheduled as S-UX2b-f** (Leo ruling a): additive `actor_name`/`student_name` LEFT-joins, S-UX2b pattern + proofs, built before S-UX3. |
| **EnrolmentPool timelines `programme_id`** | **Column dropped** | Endpoint returns names but only a raw `programme_id`. Ruling (b): stays dropped — the pool-by-programme table already answers it; no join for redundancy. |
| VERIFY "screenshots" | before/after per page | The discipline for a display card. |
| Open-set codes | humanise (+ raw on audit surfaces) | D-UX2a.1; closed enums use the registry. |

## 6. Invariant check

| Control | Held? | Evidence |
|---------|-------|----------|
| No hardcoded user-facing strings (OD-19) | Yes — and improved | i18n:check green, 394-key parity; the §3f leaks removed. |
| Money contract (integer minor units, currency, no float) | Yes | `formatMoney` divides minor by 100 through Intl currency style; HKD (Phase 1). |
| Audit honesty (BI-1 surface) | Yes — strengthened | Audit surfaces show humanised label AND the exact raw code (auditors keep the byte). |
| Server remains the authority | Yes | Display-only; no API/gate/schema change. AccessIdentity's real names await the S-UX2b-f backend follow. |
| No migration / schema change | Yes | Frontend-only card. |
