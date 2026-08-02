# KA Playground — UI/UX Inventory & Gap Report

**Date:** 2026-08-02 · **Author:** Claude Code (build agent) · **Status:** advisory — NO code
changed to produce this. S08 paused. This is a survey of the *product surface* against what the
backend can already do.

> **Method.** Frontend routes read from `web/src/main.tsx`; every page inspected in `web/src/pages/`;
> shell/nav/i18n/theme from `web/src/AppShell.tsx`, `web/src/i18n/`, `web/src/theme/`; the backend
> action surface enumerated from `api/routes/api.php` (~120 routes) and spot-checked against
> controllers for **what they actually return** (names vs raw IDs). Where a screen is a stub, it is
> called a stub.

**One-line finding.** The platform's *engine* (17 sprints of workflows, invariants, money controls,
audit) is far ahead of its *cockpit*. The **theme and i18n infrastructure are mature and
build-enforced**; the **display/formatting layer barely exists**; and **most backend workflows have
no UI to drive them** — they are reachable only by API call. Five of the primary nav destinations are
empty placeholders.

---

## 1. Route Inventory

**Legend:** ✅ real screen · 🟡 real but thin/report-only · ⛔ **stub** (renders
`<Placeholder titleKey="empty.title"/>` — icon + heading + caption, no data, no actions) · ✱ public
(outside the authenticated shell).

### Public routes (outside `AppShell`, `main.tsx:73-77`)
| Route | Component | State | Displays | Actions exposed |
|---|---|---|---|---|
| `/login` ✱ | `Login.tsx` | ✅ | Split-screen: logo, email/password/remember, hero photo | `POST /auth/login` → store token → `/` |
| `/register` ✱ | `Register.tsx` | ✅ | Guardian/student self-registration card, school picker, counterpart, honeypot | `GET /register/schools`; `POST /register` (202 → opaque reference) |
| `/activate/:token` ✱ | `Activate.tsx` | ✅ | Password + confirm form | `POST /register/activate` |
| `/pay/:token` ✱ | `PublicPay.tsx` | ✅ | Anonymous forwardable pay page: programme, student initials, order ref, formatted amount | `GET /pay/:token`; `POST /pay/:token/confirm` |

### Authenticated routes (inside `AppShell`, primary nav)
| Route | Component | State | Displays | Actions exposed |
|---|---|---|---|---|
| `/` (index) | `Placeholder` | ⛔ **stub** | Empty state only — **no dashboard exists** | none |
| `/tracker` | `Placeholder` | ⛔ **stub** | Empty state | none |
| `/team` | `Placeholder` | ⛔ **stub** | Empty state | none |
| `/learn` | `Placeholder` | ⛔ **stub** | Empty state | none |
| `/profile` | `Placeholder` | ⛔ **stub** | Empty state | none |
| `/style-guide` | `StyleGuide.tsx` | ✅ (demo) | Design-system showcase (palette, components, charts, demo table with **hardcoded** rows) | none — `message`/`modal` demos. **Ships in prod, in nav — see §4** |

### Authenticated routes (admin / domain, reachable by URL + nav)
| Route | Component | State | Displays | Actions exposed |
|---|---|---|---|---|
| `/consents` | `Consents.tsx` (List) | ✅ | Consent-request table (programme, student, status, open link) | `GET /consent-requests`; row → `/consents/:id` |
| `/consents/:id` | `Consents.tsx` (Sign) | ✅ **richest interactive screen** | Server-rendered document, EN/繁/简 switch, 3 gated steps (scroll → affirm → sign), decline modal, signed receipt | `GET …/document`; `POST …/scrolled`, `…/sign`, `…/decline` |
| `/admin/consent-templates` | `AdminConsentTemplates.tsx` | ✅ | Template picker, R15 banner, versions table (language, version, status, SHA-256) | `GET /admin/consent-templates`; `GET /consent-templates/:id/versions` |
| `/admin/consent-evidence` | `ConsentEvidence.tsx` | 🟡 report | Coverage-by-version/language, per-signature download, status tables, bundle zip download | `GET /reports/consent-evidence`; `GET /consent-signatures`; `GET …/bundle` (blob) |
| `/enrolments` | `Enrolments.tsx` | 🟡 read-only | Enrolment table + expandable journey `Steps` | `GET /enrolments` (no mutations in UI) |
| `/admin/enrolment-pool` | `EnrolmentPool.tsx` | 🟡 report | Issuance-gap banner, pool-by-programme, timelines, withdrawal pipeline | `GET /reports/enrolment-pool` |
| `/admin/programmes` | `AdminProgrammes.tsx` | ✅ **most complete admin screen** | Programme table, hub-and-spoke readiness wizard, per-section drawer, pre-flight findings | `GET …/programmes`, `…/wizard`; `PUT …/wizard/:section`; `POST …/pre-flight`, `…/publish` |
| `/admin/audit` | `AdminAudit.tsx` | ✅ | Filterable append-only audit table (time, actor, entity, action, from→to, reason) | `GET /audit-events?…` (filters + pagination) |
| `/admin/access-identity` | `AccessIdentity.tsx` | 🟡 report | Invitation/onboarding funnels, auth-events, links-per-student, sole-guardian, capability log | `GET /reports/access-identity` |
| `/admin/financial-integrity` | `FinancialIntegrity.tsx` | 🟡 report | Orders/payments/receipts/refunds/invoices stats + reconciliation check | `GET /reports/financial-integrity` |

**Count:** 4 public ✅ · 5 authenticated ⛔ stubs · 1 demo ✅ · 10 admin/domain screens (5 ✅
interactive, 5 🟡 read-only reports). **No screen exists for: dashboard, catalogue/enrol, teams,
sessions/attendance, team finance, bulk intake, events/directory, admin approval queues, or any money
mutation.** Those workflows are backend-only (§2).

---

## 2. Action Coverage Map — the core

For each backend workflow that S00–S07/S04F built: its API actions, and whether a **human can drive
it from the UI**. `yes` = a screen invokes it · `partial` = some actions have UI, others don't ·
**`API-only`** = fully built server-side, **no UI path**.

| # | Workflow | Key API actions (built) | UI-driveable? |
|---|---|---|---|
| 1 | **Auth / session** | `POST /auth/login` ✅, `/auth/logout`, `/auth/forgot-password`, `/auth/reset-password` | **partial** — login ✅; **logout has no button anywhere** (§4); password-reset endpoints exist, **no UI page** |
| 2 | **Self-registration + email verify** | `POST /register` ✅, `GET /register/schools` ✅, `GET /onboarding/verify-email/{id}/{hash}`, `POST /register/activate` ✅ | **yes** (self-serve path is complete) |
| 3 | **Registration approval** | `GET /admin/onboarding-queue`, `POST /admin/registration-requests/{id}/approve` · `…/decline` | **API-only** — no admin approval-queue screen (queue endpoint has no consumer) |
| 4 | **Guardian / teacher / school links** (approve = 2nd decision, OD-28) | `POST /my/link-requests`, `/my/pairing-codes`, `/my/guardian-requests/{id}/confirm`, `/admin/guardian-links/{id}/approve` · `…/reject`, `/guardian-links/{id}/revoke`, `/admin/teams/{id}/teacher-link`, `/admin/team-members/{id}/school-leave` | **API-only** — AccessIdentity *shows* links read-only; no screen initiates/approves/revokes a link |
| 5 | **Enrolment lifecycle** | `POST /my/enrolments` (create), `/my/enrolments/{id}/withdrawal`, `/admin/withdrawal-requests/{id}/decide`, `GET /enrolments` ✅, `GET /reports/enrolment-pool` ✅ | **partial (view-only)** — list + pool report ✅; **create-enrolment, request-withdrawal, decide-withdrawal all API-only** (no catalogue/enrol button; `/learn` is a stub) |
| 6 | **Team formation / 成團** | `POST /my/teams`, `/admin/teams/{id}/assign` · `…/dissolve` · `…/extend-grace` · `…/waive`, `/admin/matching/match` · `…/release` · `…/roll`, `GET /teams`, `…/team-capacity-report`, `…/matching` | **API-only** — `/team` is a ⛔ stub; nothing forms, seats, confirms (成團) or dissolves a team from the UI |
| 7 | **Sessions / attendance** | `POST /admin/programmes/{id}/sessions`, `/admin/sessions/{id}/transition` · `…/reschedule` · `…/attendance` · `…/clash-preview`, `/my/sessions/{id}/book` · `…/cancel`, `GET …/attendance-report` | **API-only** — `/learn` is a ⛔ stub; scheduling, booking, attendance-taking have no UI |
| 8 | **Assessments (S06)** | `POST /admin/programmes/{id}/assessments`, `/admin/assessments/{id}/grade` · `…/transition`, `GET …/results` | **API-only** — no assessment/grading screen |
| 9 | **Payments — record & confirm (BI-9 SoD)** | `POST /admin/payments` (record), `/admin/payments/{id}/confirm` · `…/reject`, `POST /my/orders/{id}/payment-link`, `GET /pay/{token}` ✅ + `POST …/confirm` ✅, `GET /orders`, `/receipts`, `/payments`, `/my/payment-links` | **partial** — public pay page ✅; **record / confirm / reject (the two-person money control) all API-only**; generate-payment-link API-only |
| 10 | **Refunds (BI-9 SoD)** | `POST /admin/refunds/{id}/approve` · `…/confirm` · `…/reject`, `GET /refunds`, `/credit-notes` | **API-only** — refunds visible as counts in FIR report; no refund action screen |
| 11 | **Bulk CSV intake (school)** | `GET/POST /school/enrolment-batches`, `GET …/{batch}`, `GET /school/students` · `…/{id}`, `GET /school/teachers` | **API-only** — no school portal; CSV upload/preview/commit has no screen |
| 12 | **School invoicing (S04F, OD-25/OD-54)** | consolidated invoices surface in FIR; generation internal to the enrolment/payment path | **view-only** — invoice stats show in FIR report; no invoice management screen |
| 13 | **Budgets (S07)** | `POST /budgets/{b}/submit` · `…/approve` · `…/revise` · `…/request-changes` · `…/lines` · `…/close`, `GET /teams/{t}/budget` | **API-only** — no team-budget screen |
| 14 | **Transactions + verification (S07, SoD)** | `POST /teams/{t}/transactions` (+ evidence/submit/approve/reject/verify per TransactionController), `GET /teams/{t}/transactions`, `…/finance-report` | **API-only** — no transaction ledger / verification screen |
| 15 | **Fundraising / charity (S07, OD-4)** | `POST /teams/{t}/fundraising`, `GET …/fundraising` | **API-only** |
| 16 | **Programme configuration** | `POST /admin/programmes` (+ create-from-template, versions, publish, pre-flight, fee-items, team-categories, save-as-template, retire) | **yes (partial)** — `/admin/programmes` wizard drives most; some sub-config (fee-items, category retire) reachable via wizard sections |
| 17 | **Capabilities & account governance** | `POST /admin/capabilities/grant` · `…/revoke`, `/admin/users/{id}/unlock`, `/admin/mentors/{userId}/status`, `/admin/invitations` | **API-only** — capability log is read-only in AccessIdentity; no grant/revoke/unlock/invite screen |
| 18 | **Consent** | list ✅, sign ✅, decline ✅, templates ✅, evidence report ✅ | **yes** — the one end-to-end UI-complete domain |
| 19 | **Events / RSVP / directory (Members)** | `GET /events`, `POST /admin/events` · `…/transition`, `/events/{id}/rsvp`, `GET /directory`, `/my/rsvps` | **API-only** — no events/directory UI; no nav entry |
| 20 | **Audit read** | `GET /audit-events` ✅, `GET /reports/access-identity` ✅ | **yes** |

**Tally:** of ~20 workflow families, **2 are UI-complete** (consent, audit-read), **4 partial**
(auth, registration, enrolment-view, payments-view, programme-config), and **~12 are API-only** —
including *every state machine that mutates money, teams, sessions, links, and approvals*. The build
is a headless platform with a thin admin-report skin plus a complete consent module.

---

## 3. Display Audit — raw values vs display values (and whether the API even returns names)

Two questions per gap: does the **UI** format it, and does the **API** even return a display value to
format? The second determines whether the fix is frontend-only or needs backend work.

### 3a. Names — the API mostly does NOT return them (backend work required)
Spot-checks of what controllers actually `SELECT`/return:
- `GET /enrolments` returns **`['id','programme_id','student_id','acting_guardian_id','status','created_at']`** — **raw FK IDs, no names.** (`EnrolmentController@index`)
- `GET /teams` (FormationController) returns `['id','programme_id','category_id','name','status','created_by']` — team name yes; programme/category/creator are **IDs**.
- `finance-report` returns `recorded_by` / `verified_by` as **user IDs**, `category` as a code.
- **Only 3 of 45 controllers join `users` for a name.** The API surface predominantly emits raw
  integer FKs and enum codes, not display strings.

**Consequence:** the enrolment/consent/audit list pages render raw `programme_id` / `student_id`
integers (`Enrolments.tsx:40-41`, `Consents.tsx:67-68`, `AccessIdentity.tsx:148`,
`AdminAudit.tsx:145,153`) **because the API gives them nothing else.** A display-layer card cannot be
purely frontend — it needs either (a) API additions to return `*_name` fields / embedded objects, or
(b) a client-side id→name resolver (extra round-trips, N+1). The *report* endpoints
(`enrolment-pool` returns `student_name`, `acting_guardian`) prove the pattern already exists in
places — the list endpoints are a **downgrade** from the reports.

### 3b. Raw enum codes shown to users (labels exist for some, bypassed on 4+ pages)
Enrolment (`enrol.status.*`, 9 states) and consent (`consent.status.*`) statuses ARE i18n-mapped and
colored — the good pattern. But these surfaces print the raw server code:
- `FinancialIntegrity.tsx:46` — order `status`, payment `origin`, refund `status`,
  `destination_party` all `<Tag>{v}</Tag>` (e.g. `covered_by_invoice`, `pending`).
- `EnrolmentPool.tsx:99` — withdrawal `status` raw (yet timelines status on `:87` IS labeled — same
  page, inconsistent).
- `AdminConsentTemplates.tsx:73` — version `status` raw.
- `AdminAudit.tsx:157,163` & `AccessIdentity.tsx:115,162` — audit/auth action codes raw.
- `ConsentEvidence.tsx:90,109` — `language` raw code in a Tag.

There is **no central enum→label registry**; each page maps (or forgets to map) its own enums, so
the i18n build-check (which scans JSX literals) cannot catch these — the codes arrive as data.

### 3c. Money — inline, duplicated, mutually inconsistent
No shared formatter. Two live implementations that **disagree**:
- `FinancialIntegrity.tsx:25` — `(minor/100).toLocaleString('en-HK',…)` with a hardcoded `$`,
  hardcoded `en-HK`, **ignores the currency code** — won't localize under zh.
- `PublicPay.tsx:40-41` — the **correct** pattern: `Intl.NumberFormat` `style:'currency'`, honors the
  currency field and the active locale.
Every `_minor` field elsewhere is at the mercy of whichever copy the page author reached for.

### 3d. Dates / timestamps — formatted in some places, raw ISO in others
Correct HKT pattern exists (`AdminAudit.tsx:30-41`, `AccessIdentity.tsx:25-30`). But raw ISO strings
leak where a sibling page formats:
- `Consents.tsx:221` — `signed_at` raw ISO on the signed receipt.
- `ConsentEvidence.tsx:110` — `signed_at` raw.
- `FinancialIntegrity.tsx:60` — `generated_at` raw in the banner.
- `EnrolmentPool.tsx:73` — `formation_deadline_on` raw.
Also `AccessIdentity.tsx:26-31` uses `en-GB` and is **not** locale-aware, unlike AdminAudit's copy.

### 3e. Raw UUIDs / hashes
- `AdminAudit.tsx:153` — `entity_id` UUID shown verbatim as code.
- `ConsentEvidence.tsx:69,108` — request UUID truncated `slice(0,13)…`.
- SHA-256 hashes shown as truncated code on consent screens — **acceptable** (they are evidence
  identifiers, meant to be shown).

### 3f. Hardcoded (non-i18n) user-facing strings
- `AdminProgrammes.tsx:273,296,303,304,311` — `"min"`, `"max"`, `"%"`, `'placeholder-s03'`.
- `FinancialIntegrity.tsx:84` — internal spec code `'OD-54 ✓/✗'` shown to the user.
- `StyleGuide.tsx:157-159,181-182` — demo rows (acceptable in a style guide).

**Verdict:** the display layer needs a shared kit — `formatMoney(minor, currency, locale)`,
`formatHkt(iso, locale)`, `<StatusTag enum/>` backed by one label registry, and an id→name resolver
— **and** several API endpoints must start returning names/labels. Frontend-only will not close 3a.

---

## 4. Shell & Navigation

**Shell.** `AppShell.tsx` runs two layouts, swapped at 767px (`useIsMobile`): desktop AntD `ProLayout`
(240px fixed sider) and a custom mobile shell (header + `BottomTabBar` + edge-swipe `NavDrawer`). The
mobile chrome is solid and global.

**Nav is FLAT, not grouped, not role-aware** (`AppShell.tsx:102-121`). All 15 items render in one
list for **every** user regardless of role, admin tools interleaved with placeholder consumer pages:
`Dashboard, Tracker, Team, Learn, Profile, Style Guide, Audit, Access & Identity, Programmes,
Consents, Enrolments, Enrolment Pool, Financial Integrity, Consent Evidence, Consent Templates`. A
guardian sees "Financial Integrity"; an auditor sees "Style Guide"; a student sees four empty stubs
as their entire nav. **No IA, no grouping, no role gating.**

**Header — mostly missing:**
| Element | State |
|---|---|
| Logo | ✅ text mark only (`<span class="ka-logo-mark">KA</span>`; mobile `KA` avatar) |
| Breadcrumbs | ⛔ missing |
| Global search | ⛔ missing |
| User menu | ⛔ missing — avatar is a **static non-interactive** `KA`, no dropdown |
| **Logout** | ⛔ **missing entirely** — `clearToken()` exists but is only called by the 401 handler; users **cannot sign out** |
| Notifications | ⛔ missing (planned "notification bell" never built) |

**Locale switcher** ✅ lives in the shell (desktop `actionsRender`, mobile header), globally available
on authenticated pages. **But public pages render outside the shell** → `/login`, `/register`,
`/pay`, `/activate` have **no locale switcher** (they read the persisted locale but can't change it).

**Dashboard / landing:** the index `/` is a ⛔ `Placeholder`. There is **no home screen** — a logged-in
user lands on an empty state.

**Style Guide in production:** **NOT dev-guarded.** `/style-guide` is a normal authenticated route
AND a permanent nav item (position 6). No `import.meta.env.DEV` guard; it ships in prod builds
(carrying the charts lib in its own chunk). Should be gated out of production or behind a dev flag.

**Login return-to-intended-page is broken:** `RequireAuth` preserves `state.from`
(`main.tsx:47-53`), but `Login.tsx:34` always navigates to `/`, so a deep link that bounced through
login never returns the user to where they were headed.

---

## 5. Seed Gaps — `PreviewSeeder.php` (local-only)

**What it seeds today:** accounts (all password `password`) — `super@demo.ka` (academy super_admin),
`ops@demo.ka` (operations), `finance1@`/`finance2@demo.ka` (finance), `audit@demo.ka` (audit_read),
`wendy@demo.ka` (guardian), `sam@`/`mia@`/`kai@`/`zoe@demo.ka` (students). **One** programme +
enrolments: sam/mia consented → `in_pool`; kai confirmed → order → PAID → receipt; zoe confirmed →
order → LIVE payment-link.

**What it does NOT seed — so these surfaces are empty even where UI exists, and un-demoable where it doesn't:**
| Missing seed | Blocks demoing |
|---|---|
| **Teams** (formed) + **成團** (seated/confirmed) | team formation, capacity reports, `/team` |
| **Sessions** + **attendance** | scheduling, booking, `/learn`, attendance-report |
| **Team budgets** (S07) | budget lifecycle, finance-report |
| **Team transactions** + evidence + verification | the SoD ledger, over-budget flag |
| **Fundraising / charity** projects | OD-4 charity path |
| **Badges / tenures** (S08 domain) | recognition (paused) |
| **School-admin account** + **teacher account** | school portal, bulk intake, teacher-link, vouching |
| **Assessments / grades** (S06) | grading surfaces |
| **Events / RSVPs / directory** (Members) | member surfaces |
| **Dashboard-shaped aggregate data** | there's no dashboard to feed anyway |

Two role families (**School Administrator, Teacher**) have **no demo login at all**, so their
workflows can't be walked even by API. A demo-readiness seed is a prerequisite for any UAT walkthrough.

---

## 6. Empty / Error / Loading States (per screen)

**Best-in-class (use as the template):**
- `PublicPay.tsx` — explicit 4-state machine (loading `Spin` / ready / gone / paid) with `Result`.
- `Consents.tsx` (Sign) — per-gate locked `Alert`s, load-fail `Alert`, sign-fail `message.error`.
- `AccessIdentity.tsx` — load `Alert`, loading div, explicit "none" empties for sole-guardian/exceptions.

**Adequate:** `Login`, `Register`, `Activate` (status-mapped errors, busy → loading); `AdminAudit`
(table `loading`, error `Alert`; empty = AntD default); `EnrolmentPool` (error `Alert`, `?? []`
guards; no spinner/empty text); `FinancialIntegrity` (error `Alert`, `report &&` guards; no spinner).

**Fragile / missing:**
| Screen | Gap |
|---|---|
| `Consents.tsx` (List) | **No `res.ok` guard, no `.catch`** — an API error crashes the promise chain (calls `r.json()` unconditionally). No loading, no empty state. **Most fragile fetch in the app.** |
| `AdminConsentTemplates.tsx` | `r.ok`-guarded but **no error `Alert`, no spinner** — a failed load silently shows empty select/table |
| `Enrolments.tsx` | No error/loading/empty handling — failure or empty both render a blank table |
| `AdminProgrammes.tsx` | `GET` failures guarded silently (`res.ok` with no else) — failed load = empty table, no message |
| `ConsentEvidence.tsx` | second fetch (signatures) has no error branch; no spinner |

**Architectural note:** all data-fetch is hand-rolled `fetch`/`authFetch` — no axios/react-query, so
**no shared loading/error/retry convention and no global error boundary.** Every page reinvents (or
forgets) its own states. A shared data-fetch hook would close most of this section at once.

---

## 7. Mobile + i18n Quick Check

### Mobile
Global mobile shell is **good** (bottom tab bar + edge-swipe drawer, `components/mobile/`). Stat-card
rows correctly use responsive `Col xs/sm` / `Space wrap` (`AccessIdentity`, `FinancialIntegrity`).
**But every data-heavy admin page uses wide multi-column AntD `Table`s** with `maxWidth:1100` and
fixed column px — these **horizontal-overflow on a 375px viewport with no card/stacked fallback:**
`AdminAudit`, `AccessIdentity`, `EnrolmentPool`, `FinancialIntegrity`, `AdminProgrammes`.
`AdminProgrammes` Drawer is `width:480` (wider than a phone). `Enrolments` expanded-row `Steps`
(7 stages) overflows horizontally. Public pages and consent-sign are phone-fine.

### i18n
**Mature and enforced.** Three locales (`en`, `zh-TC`→`zh_TW`, `zh-SC`→`zh_CN`), **343 keys each,
full parity** (0 missing in TC/SC). `scripts/i18n-check.mjs` **fails the build** on any key-set
mismatch AND on hardcoded JSX text literals (allowlist: `KA`), gated in `npm run build`. TC/SC are
genuinely translated (only 9 legitimately-identical keys: app title, locale names, logo alt-text,
`consent.r15`). Runtime warns on missing keys; `fallbackLng: 'en'`.

**The i18n gap is not keys — it's the enum codes in §3b.** They arrive as *data*, not JSX literals, so
the build-check can't see them; a page that prints `<Tag>{status}</Tag>` passes i18n-check while
showing `covered_by_invoice` to the user. Plus the hardcoded strings in §3f
(`"min"/"max"/"%"/OD-54 ✓`) that slipped the literal scan via `addonBefore`/template contexts.

### Theme (bonus — it's the mature part)
Confirmed `darkAlgorithm`-only, no light mode, no toggle (`theme.ts`). `cssVar:true`, full token set,
per-component overrides, `chartTheme.ts`, AntD `App` wrapper wired for static `message`/`modal`. Solid.

---

## 8. Proposed Card Split

Grouped so each card is independently buildable and shippable. **Dependency to flag up front:** the
display layer (S-UX2) needs **backend additions** for §3a (APIs must return names) — it is not
frontend-only. Sizes are rough (S=1–2 days, M=3–5, L=1 week+).

### S-UX1 — App shell, nav & IA · **size M** · no backend dep
Role-aware grouped nav (collapse admin tools under headings; hide by capability/role); real **user
menu with logout**; breadcrumbs; fix login return-to-intended-page; **gate `/style-guide` out of
prod**; locale switcher on public pages; a real **Dashboard** at `/` (role-scoped landing —
needs some aggregate endpoints, small backend). *Depends on nothing; unblocks everything visually.*

### S-UX2 — Display layer (names, dates, money, status) · **size M–L** · **backend dep**
Build shared `formatMoney` / `formatHkt` / `<StatusTag>` + **one enum→label registry** + an id→name
resolver; adopt across all pages; kill the raw ISO/enum/UUID/hardcoded leaks in §3.
**Backend half:** add `*_name`/embedded display fields to the list endpoints that return bare FK IDs
(`/enrolments`, consent lists, audit actor, finance `recorded_by/verified_by`) — mirror what the
report endpoints already do. *Split into S-UX2a (shared kit + frontend adoption) and S-UX2b (API
display-field additions) if the backend work wants its own review.*

### S-UX3 — Workflow surfaces (make each state machine driveable) · **size L (likely several cards)** · no new backend
Give the API-only workflows a UI. Priority by risk/visibility:
1. **Admin approval queues** — registration approve/decline, **link approve/reject/revoke** (OD-28 two-decision), withdrawal decide.
2. **Money mutations** — payment record + **confirm/reject (BI-9 two-person)**, refund approve/confirm/reject, payment-link generation. *(Money UI gets line-by-line review per standing rule.)*
3. **Teams / 成團** — form, seat, confirm, dissolve, matching (`/team`).
4. **Sessions / attendance** — schedule, book, take attendance (`/learn`).
5. **Team finance (S07)** — budget lifecycle, transaction record/verify, finance-report.
6. **School portal** — bulk CSV intake (upload/preview/commit), teacher/student management.
7. **Capabilities & governance** — grant/revoke/unlock/invite.
Each surface rides an *already-built, already-tested* backend — this is UI over a proven engine.
*No new invariants; the controls exist server-side. Best chunked one domain per card.*

### S-UX4 — Seed & demo readiness · **size S–M** · depends on nothing (extends PreviewSeeder)
Extend `PreviewSeeder`: **school-admin + teacher accounts**, formed teams + 成團, sessions +
attendance, budgets + transactions + verification, a charity project, badges/tenures (when S08
lands), events/RSVPs, and dashboard-shaped data. Makes every screen demo-able for UAT. *Best done
alongside/after S-UX3 so there are screens to show the data on, but the seed itself has no code dep.*

### Suggested order
**S-UX1** (shell/nav/dashboard — immediate UX lift) → **S-UX2** (display kit + API names — every
later screen consumes it) → **S-UX3** (workflow surfaces, chunked, money first for review) →
**S-UX4** (seed, interleaved with S-UX3). S-UX2b (API names) is the critical dependency: land it early
or S-UX3 screens inherit the same raw-ID problem.

---

## Appendix — honesty notes
- **Stubs are stubs.** `/`, `/tracker`, `/team`, `/learn`, `/profile` render an empty-state component
  with no data and no actions. They are not "thin" — they are placeholders.
- **"API-only" means built-and-tested, just not surfaced.** Every workflow in §2 marked API-only has
  passing tests and (for money/teams/finance) live audit evidence — the *engine* is real; the
  *cockpit* is missing. This report is about the cockpit.
- No code, migration, seed, or config was changed to produce this document. S08 remains paused.
