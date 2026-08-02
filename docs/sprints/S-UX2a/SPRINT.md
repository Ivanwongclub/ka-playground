# SPRINT S-UX2a — Shared display kit + fetch convention

> **UX phase, card 3** (S-UX2b ✓ → S-UX1 ✓ → **S-UX2a** → S-UX3 chunked → S-UX4). Origin:
> `docs/product/UI-INVENTORY.md` §3 + §6. Consumes the S-UX2b API names. Frontend-only.
> Screenshot-verified: **before/after per page** for every raw-value kill.

## 1. Goal

Build the display layer the app has never had, and adopt it everywhere:
1. **`formatMoney`** — one money formatter (minor units + currency + locale). Kills the two conflicting
   inline copies.
2. **`formatHkt`** — one date/time formatter (ISO → HKT, locale-aware). Kills the inconsistent copies
   and every raw-ISO leak.
3. **`<StatusTag>` + an enum→label registry** — one place that maps every closed-set status/enum code
   to an i18n label + colour. Kills raw `<Tag>{code}</Tag>`.
4. **id→name adoption** — consume the S-UX2b `*_name` / `programme_name_*` fields; render names, never
   raw FK integers.
5. **Shared fetch convention** — one `useResource` hook (`res.ok` guard + loading/empty/error) and a
   `<DataBoundary>`. Fixes the `Consents` list crash and the 4 silently-blank pages.
6. **Catch-all 404 route** (carried from S-UX1 / S13's blank-page finding).
7. **`Promise.all` the dashboard reads** (carried from S-UX1).

Server behaviour is unchanged — this is presentation. **Money display gets line-by-line review** even
though no money *mechanism* changes (client standing rule).

## 2. The kit (new modules under `web/src/display/` and `web/src/api/`)

- `display/money.ts` — `formatMoney(minor: number, currency: string, locale: string): string`
  using `Intl.NumberFormat(locale, { style: 'currency', currency })` on `minor/100` (the correct
  `PublicPay.tsx` pattern, generalised). Never a hardcoded `$` or `en-HK`.
- `display/date.ts` — `formatHkt(iso, locale)` and `formatHktDate(iso, locale)` via
  `Intl.DateTimeFormat(locale, { timeZone: 'Asia/Hong_Kong', … })`. Null-safe (→ `—`).
- `display/status.tsx` — `<StatusTag domain="orderStatus" value={v} />` backed by a **registry**:
  `{ domain → { code → { labelKey, color } } }`. Unknown code → humanised fallback + neutral colour
  (never crashes, never blanks).
- `display/names.ts` — `programmeName(row, locale)` (picks `programme_name_en/tc/sc`) and
  `personName(name)` (name or a neutral `—`; never the raw id). Actor-null → `t('audit.system')`.
- `api/useResource.ts` — `useResource<T>(url)` → `{ data, loading, error, reload }`; `res.ok` guarded,
  `.catch` wired, 401 already handled by `authFetch`. `<DataBoundary loading error empty>` renders the
  Spin / error `Alert` / empty state consistently (best-in-class `PublicPay` / `ConsentSign` pattern,
  generalised).

## 3. Closed-set enum registry (from source) vs open-set humaniser

**Closed sets → registry (i18n label + colour):** order status, payment origin, refund status,
refund destination party, withdrawal status, consent-request status *(already keyed — fold into the
registry)*, consent-template version status, consent language. Each code enumerated from its
migration/model so the registry is exhaustive; an unknown code degrades to the humaniser, never a raw
tag.

**Open sets → humaniser, NOT i18n (RULED D-UX2a.1):** `audit_events.action` / auth action codes and
`entity_type` are open-ended and audit-precise (`enrolment.submitted`, `permission.denied`,
`payment_link.minted`, …). **Humanise** them (`enrolment.submitted` → "Enrolment submitted") rather
than i18n each — keeps audit precision, avoids hundreds of keys.
**Audit-surface refinement (ruled):** on **AdminAudit** and **AccessIdentity**, render the humanised
label **and preserve the RAW code** alongside it (a `title` tooltip + muted secondary text) — an
auditor cross-referencing exports needs the exact code; the audit view stays honest to the byte.
Elsewhere (non-audit surfaces) the humanised label alone is fine.

## 4. Per-page adoption — the raw-value kills (before/after targets)

| Page | Kills |
|------|-------|
| `Enrolments.tsx` | `programme_id`/`student_id` ints → `programmeName` / `student_name` (S-UX2b); status already labelled |
| `Consents.tsx` (List) | `programme_id`/`student_id` → names; **+ fetch convention (this is the crash: no `res.ok`/`.catch`)** |
| `Consents.tsx` (Sign) | `signed_at` raw ISO → `formatHkt`; `language` code → StatusTag |
| `AdminAudit.tsx` | `actor_id` → `actor_name` (S-UX2b, null→System); `action`/`from_state`/`to_state` → humaniser; `entity_id` UUID **stays raw** (polymorphic — S-UX2b deferral) |
| `AccessIdentity.tsx` | action codes → humaniser; `actor_id` → name; `student_id` → name; unify `hkt` (`en-GB`) onto `formatHkt` |
| `EnrolmentPool.tsx` | withdrawal `status` raw → StatusTag; `programme_id` → name; `formation_deadline_on` raw → `formatHktDate` |
| `FinancialIntegrity.tsx` | order status / payment origin / refund status / destination → StatusTag; **money → `formatMoney`** (drop hardcoded `$`/`en-HK`); `generated_at` raw ISO → `formatHkt`; **`'OD-54 ✓/✗'` hardcoded → i18n** |
| `ConsentEvidence.tsx` | `signed_at` raw ISO → `formatHkt`; `language` code → StatusTag; `programme_id`/`student_id` → names; **+ fetch convention (silent-blank)** |
| `AdminConsentTemplates.tsx` | version `status` raw → StatusTag; **+ fetch convention (silent-blank)** |
| `AdminProgrammes.tsx` | **+ fetch convention (silent-blank on load failure)**; hardcoded `"min"/"max"/"%"/'placeholder-s03'` → i18n |

Also: `Dashboard.tsx` — `formatMoney`/`formatHkt` where relevant + **`Promise.all`** the reads; add the
**catch-all 404** route in `main.tsx` (a real NotFound page, i18n, inside the shell).

## 5. VERIFY plan — before/after screenshots + gates

**Screenshots** (running instance, seeded): for **each page in §4**, a *before* (current raw state) and
*after* (kit-adopted) capture, side by side in the bundle. Acceptance per page: **no raw FK integer,
no raw enum code, no raw ISO timestamp, no minor-unit integer, no hardcoded user-facing string**
remains in the captured view (except `entity_id` UUID, deferred). Named set:
- `Enrolments`, `Consents-list`, `AdminAudit`, `AccessIdentity`, `EnrolmentPool`,
  `FinancialIntegrity`, `ConsentEvidence`, `AdminConsentTemplates` — before + after each (~16 shots).
- **404**: visit an unknown path → the NotFound page (not blank). Before (blank, S-UX1 S13) / after.
- **Fetch convention**: force `Consents` list into an error (e.g. offline/500) → shows the error
  `Alert`, **does not crash** (before: unhandled promise). Capture the after error-state.

**Gates:**
- `cd web && npx tsc --noEmit && npm run build` — tsc + i18n-check + prod build green (paste tail).
  Every new label trilingual; i18n parity holds; no new hardcoded JSX literal.
- **Money line-by-line:** `formatMoney` and each of its adoption sites reviewed against the currency +
  minor-unit contract (HKD, integer minor units, ISO code) — pasted diff for the money sites.

## 6. Out of scope (report, don't build)

- Building Tracker/Team/Learn/Team-finance screens — S-UX3.
- Any API change. S-UX2b already added the names; if a page needs a display field **no endpoint
  returns**, STOP and raise it (don't add a backend field under a frontend card).
- The `users_read` co-member branch for team-finance names (S-UX2b Finding 2) — ruled at S-UX3
  team-finance.
- `entity_id` polymorphic resolver — its own later card.

## 7. Open decision

- **D-UX2a.1 — audit/auth action & entity_type codes:** humanise (recommended) vs full i18n registry.
  Needs a ruling before the AdminAudit/AccessIdentity adoption.

## 8. Constraints / invariants

- **No hardcoded user-facing strings** — every new label trilingual via `t()`; i18n-check stays green.
  This card also *removes* the existing §3f hardcoded leaks.
- **darkAlgorithm only**; Design System v2.1 binding (StatusTag colours from the theme tokens, not ad hoc).
- **Money contract:** every amount carries a currency; formatting divides integer minor units by 100
  through `Intl` currency style — never a float, never a hardcoded symbol/locale.
- Frontend-only — no migration, no schema, no server behaviour change.

## 9. Definition of done

Kit built and adopted across every §4 page; every raw-value kill verified by a before/after screenshot
meeting §5's criterion; the `Consents` crash and 4 blank pages fixed by the fetch convention (error
state captured); catch-all 404 live; dashboard reads parallelised; `tsc`+`build`+i18n green; money
sites reviewed line-by-line. Then plan → build → VERIFY w/ screenshots → review → commit. Card ends
with `docs/sprints/S-UX2a/AUDIT.md`.
