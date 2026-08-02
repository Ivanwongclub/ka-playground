# AUDIT KAP-S-UX1 — App shell, role-aware nav & IA, dashboard

**Result:** PASS · **Date:** 2026-08-02 · **HEAD at commit:** `dec532e` · **Card:** `SPRINT.md`

> Written at the card's end. Honesty outranks looking good. Second card of the UX phase (S-UX2b ✓ →
> **S-UX1** → S-UX2a → S-UX3 → S-UX4). First screenshot-verified card. Origin: `docs/product/UI-INVENTORY.md` §4.

## 1. What S-UX1 is

Turns the flat, role-blind, logout-less shell into a real cockpit: role-aware grouped navigation, a
proper header (logo, user menu, logout, breadcrumbs), a role-scoped dashboard, login return-to,
Style-Guide gated out of production, and a locale switcher on the public pages. Mostly frontend; one
bounded backend addition — `GET /api/me` — that the nav and user menu depend on.

## 2. Files changed (20; +712 / −122)

**Backend (2):** `MeController.php` (new — `GET /api/me`), `routes/api.php` (+1 route).
**Frontend new (6):** `auth/identity.tsx` (IdentityProvider + `useIdentity` + `has()`), `nav.tsx`
(grouped, permission-gated nav config), `components/LocaleSwitcher.tsx`, `components/UserMenu.tsx`,
`components/KaBreadcrumb.tsx`, `pages/Dashboard.tsx`.
**Frontend modified (12):** `AppShell.tsx` (identity wrap, grouped role-aware nav, logo, user menu,
breadcrumb), `auth/session.ts` (`logout()`), `components/mobile/{BottomTabBar,NavDrawer}.tsx`
(role-aware), `pages/{Login,Register,Activate,PublicPay}.tsx` (locale switcher; Login also return-to),
`main.tsx` (Dashboard at `/`, Style-Guide DEV-gated), `i18n/locales/{en,zh-TC,zh-SC}.json` (+new keys,
363-key parity).

## 3. `GET /api/me` — the identity source

Login persists only a bearer token, so on refresh the SPA had no idea who was signed in. `/api/me`
returns `{id, name, role, permissions}` from **`$request->user()` only — no foreign lookup** — where
`permissions` is `PermissionResolver::effectivePermissions()` (role defaults + capability permissions,
B7/OD-17). The client fetches it once at shell mount and shares it via context. **Own-identity read
only, additive, not audited, no schema.** Docblock states the security framing: **nav-hiding built on
these permissions is UX only — every endpoint keeps its own server-side `permission:` gate; a hidden
route hit directly still 403s.**

## 4. The capability → nav map — derived from source, zero guesses

Each nav item's visibility predicate mirrors the server gate on its endpoint:

| Nav item | Gate | Source |
|---|---|---|
| Enrolments | `enrolment.view` | routes/api.php:42 |
| Consents | `consent.view` | routes/api.php:39 |
| Programmes / Consent Templates | `configuration.manage` | routes/api.php:123 (group) |
| Enrolment Pool / Access & Identity / Audit / Consent Evidence | `audit.read` | routes/api.php:291–302 |
| Financial Integrity | `finance.record` **OR** `audit.read` | **FinancialIntegrityReportController:27** (in-controller — route is `auth`-only) |

The Financial Integrity gate was **read, not guessed** (§5 STOP honored): the route carries only
`auth:sanctum`; the real gate is inside the controller and is `finance.record` OR `audit.read` —
deliberately **not** `finance.view`, which is a role default guardians/schools hold for their OWN
money. `/api/me` for a guardian confirms this: they hold `finance.view` yet must not see the
academy-wide Financial Integrity report — and the nav correctly hides it.

## 5. Step verification — 13 acceptance screenshots (all PASS)

Captured headless against the running instance, per role. Full table + images in the review bundle
(`VERIFY-OUTPUT.md` + `screenshots/`). Decisive results:
- **S1 super_admin** — full grouped nav (Overview / My Programme / Administration, all 7 admin items),
  no Style Guide.
- **S2 finance** — Administration shows **only Financial Integrity** (holds `finance.record`, not
  `audit.read`/`configuration.manage`); no My Programme group. *The decisive capability-gating shot.*
- **S4 guardian** — My Programme only (Enrolments, Consent); no Administration.
- **S6** — user menu: name + "Academy Administrator" + Sign out.
- **S7** — logout → `/login`, token cleared. **S11** — deep link unauth → login → **landed on the
  intended `/admin/audit`** (return-to fixed). **S10** — breadcrumb "Armour Academy / Administration /
  Financial Integrity".
- **S8/S9** — dashboards show composed real numbers, not the placeholder.
- **S12** — `/login` switches EN → 繁體中文 (whole page translates).
- **S13** — `/style-guide` in prod: no route match + **nav link count 0**.

Build gates: `npm run build` green — i18n:check **363-key parity, no hardcoded strings**, tsc -b,
vite, bundle-budget all pass; **Style Guide + charts library dead-code-eliminated from the prod
bundle** (DEV-gated at the import, not just the route).

## 6. Deviations / decisions

| Item | Outcome | Why |
|------|---------|-----|
| D-UX1.1 stub visibility | **HIDE** — Tracker/Team/Learn/Profile absent from nav; routes remain stubs | Nav offers only working screens; each S-UX3 card reveals its own item. Profile identity shown inline in the user menu (no link to an empty stub). |
| D-UX1.2 dashboard | **Composition** — no `/api/dashboard` | Max 4 composed reads (super_admin); guardian 3, finance 1. Not chatty enough to warrant a new endpoint. |
| Nav grouping | ProLayout sub-menus, **`defaultOpenAll`** | Groups render expanded so the full item set is visible (better UX + clearer acceptance). |
| school_admin nav variant | **Deferred to S-UX4** — not fabricated | PreviewSeeder has no school_admin login; no synthetic account was invented for the screenshot. |
| Style-Guide gating | Import **and** route DEV-gated | Route-only gating still shipped the chunk; import-gating DCEs it entirely. |

## 7. Carry-forward to S-UX2a (found here, out of scope for S-UX1)

- **Catch-all 404 route.** S13 revealed the app has no 404 page — an unknown path (`/style-guide` in
  prod) renders blank. Small; folded into the S-UX2a card.
- **Dashboard reads are sequential** in `Dashboard.tsx` — `Promise.all` them opportunistically in
  S-UX2a (an optimization, not urgent).
- The display-layer gaps this shell now frames (raw enum/ISO/minor-unit, id→name adoption, the
  fetch-wrapper convention that fixes the Consents crash + 4 silently-blank pages) are S-UX2a's body.

## 8. Invariant check

| Control | Held? | Evidence |
|---------|-------|----------|
| Server remains the authorization gate | Yes | Nav-hiding is UX only; endpoints keep their `permission:` middleware; `/api/me` reads own identity only. Documented in `nav.tsx` + `MeController`. |
| No hardcoded user-facing strings (OD-19) | Yes | i18n:check green — 363-key parity across en/zh-TC/zh-SC, no JSX literals. |
| darkAlgorithm only | Yes | No theme change; shell inherits `kaTheme`. |
| No migration / schema change | Yes | `/api/me` is a pure read; no migration file. |
| Design System v2.1 | Yes | Real AA logo in the header (replacing the text mark), grouped nav, breadcrumbs, dark chrome per §6. |
