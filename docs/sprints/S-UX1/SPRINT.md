# SPRINT S-UX1 — App shell, navigation & IA, dashboard

> **UX phase, card 2 of the ruled sequence** (S-UX2b ✓ → **S-UX1** → S-UX2a → S-UX3 chunked → S-UX4).
> Origin: `docs/product/UI-INVENTORY.md` §4. This is the first **screenshot-verified** card — the
> standing UX rule (plan → build → VERIFY with screenshots → review → commit) starts here, and this
> card names the screens it screenshots against acceptance criteria (§7).

## 1. Goal

Turn the flat, role-blind, logout-less shell into a real cockpit: **role-aware grouped navigation**, a
proper **header** (logo, user menu, **logout**, breadcrumbs), **fixed login return-to**,
**Style-Guide gated out of production**, **locale switcher on public pages**, and a **role-scoped
dashboard** at `/` in place of the empty placeholder. Mostly frontend; one **small, clearly-bounded
backend half** (§4) that the nav and dashboard depend on.

## 2. Where we are (facts, from the code)

- Nav is **flat, not grouped, not role-aware** — all 15 items render for every user
  (`AppShell.tsx:104-121`), admin tools interleaved with consumer stubs.
- **No user menu, no logout.** The avatar is a static non-interactive `KA` (`AppShell.tsx:133-139`);
  `clearToken()` is only called by the 401 handler — a user cannot sign out.
- **No breadcrumbs, no dashboard.** `/` renders `<Placeholder>` (`main.tsx:79`).
- **Login return-to is broken:** `RequireAuth` saves `location.state.from` (`main.tsx:47-53`) but
  `Login.tsx:34` always `navigate('/')` — the saved destination is discarded.
- **Style Guide ships in prod** — a normal route + permanent nav item, no `import.meta.env.DEV` guard.
- **Locale switcher lives in the shell only** — public pages (`/login`, `/register`, `/pay`,
  `/activate`) render outside `AppShell` and cannot switch language.
- **Identity gap (drives the backend half):** login returns `{token, user:{id,name,role}}` — **no
  capabilities** — and `session.ts` persists **only the token**. On refresh the app knows nothing
  about who is logged in. Role-aware nav and the user menu therefore need a canonical identity source.

## 3. In scope — the frontend

1. **Role-aware grouped nav / IA.** Replace the flat list with grouped, role-gated sections:
   - **Overview** — Dashboard (all authenticated).
   - **My Programme** (guardian, student) — Enrolments, Consents. *(Tracker / Team / Learn are S-UX3
     stubs — see the §6 ruling on whether to hide or show-as-"soon".)*
   - **Administration** (academy_admin, each item further gated by the capability that gates its own
     endpoint — §5): Programmes, Enrolment Pool, Financial Integrity, Access & Identity, Audit,
     Consent Evidence, Consent Templates.
   - Nav-hiding is **cosmetic only** — the server remains the authorization gate (a hidden route the
     user hits directly still 403s server-side; we never rely on hiding for security). State this in code.
2. **Header.** Real logo per `DESIGN-SYSTEM.md` logo rules (the AA/KA asset via `asset()` /
   `AssetImage`, not a bare text mark); **user menu** (avatar → name, role, Profile link, **Logout**);
   **breadcrumbs** derived from the route with i18n labels. Notifications bell stays out (later).
3. **Logout.** User-menu action → `POST /api/auth/logout` → `clearToken()` → redirect `/login`.
4. **Login return-to.** `Login.tsx` reads `location.state.from` and navigates there on success,
   falling back to `/`. Preserve `from` through the 401 path too where feasible.
5. **Style-Guide gated out of prod.** Wrap the route AND the nav item in `import.meta.env.DEV`
   (or an explicit build flag) so it is absent from production builds and nav.
6. **Locale switcher on public pages.** Add the existing switcher to the public chrome
   (`/login`, `/register`, `/activate`, `/pay/:token`) — reuse the `AppShell` switcher component.
7. **Dashboard at `/`.** A **role-scoped** landing replacing the placeholder (§4 for its data).

## 4. In scope — the small backend half (clearly bounded)

**4a. `GET /api/me` — identity (BLOCKING for §3.1 nav + §3.2 user menu).** Returns the authenticated
caller's `{ id, name, role, capabilities: [] }` (capabilities from `admin_capabilities` for
academy_admin, `[]` otherwise). The frontend calls it on app load (token present) and after login,
and persists it in memory/context — so nav, user menu and dashboard all read one identity source that
survives refresh. Small, additive, no schema change. Audited? No — a read of own identity.

**4b. Dashboard data — prefer composing existing endpoints; add only what's genuinely missing.**
Per-role dashboards should be assembled from endpoints that already exist and are RLS-scoped:
| Role | Dashboard shows | Data source (existing) |
|------|-----------------|------------------------|
| Guardian | my students' enrolment statuses, open consent tasks, live payment links | `GET /enrolments`, `GET /consent-requests`, `GET /my/payment-links` (all RLS-scoped) |
| Student | my enrolments, my sessions/bookings | `GET /enrolments`, (sessions — S-UX3) |
| Academy admin (by capability) | issuance gaps, pool, financial-integrity headline, onboarding queue | `GET /reports/enrolment-pool`, `/reports/financial-integrity`, `/reports/access-identity`, `/admin/onboarding-queue` |

**FLAG for ruling (the "small backend half" decision):** if composing a role's dashboard from existing
endpoints is too chatty (e.g. guardian needs 3 calls), we add **one** thin `GET /api/dashboard`
returning a role-shaped summary. **Recommendation:** ship dashboards by composition first; add
`/api/dashboard` only if a role needs it — decide at review. **No genuinely-missing aggregate has been
found yet** other than identity (4a); the admin report endpoints already carry headline numbers.

## 5. Capability → nav-item map (nav-hiding must match real authorization)

Each Administration item is hidden unless the caller holds the capability that gates its endpoint.
**Build step 1 produces this map by reading each route's `permission:`/EnsurePermission gate** (the
source of truth), so nav visibility mirrors server authorization exactly. Provisional (confirm against
routes): Programmes→`configuration`; Enrolment Pool→`operations`; Financial Integrity→`finance`;
Access & Identity→`operations`|`audit_read`; Audit→`audit_read`; Consent Evidence/Templates→
`operations`|`configuration`. `super_admin` sees all. **If a nav item's endpoint gate cannot be
determined, STOP and ask** rather than guess a capability.

## 6. Decisions — RULED by Leo (2026-08-02)

- **D-UX1.1 — S-UX3 stub visibility → HIDE.** Nav offers only working screens. Tracker / Team / Learn /
  Profile nav items are hidden; **each S-UX3 domain card reveals its own item when the screen lands.**
  Profile remains reachable via the user menu.
- **D-UX1.2 — dashboard by COMPOSITION first.** If a role's composition proves too chatty, propose
  `GET /api/dashboard` **at review, with the request-count evidence** — not pre-emptively.
- **`/api/me` approved** as the bounded backend half (own-identity read only, additive, no schema).
- **§5 capability-map STOP stands** — do not guess an ambiguous endpoint gate; stop and ask.
- **school_admin nav variant** — builder's choice: one synthetic *labeled* school_admin capture, or
  defer that variant to S-UX4. (Chosen: see §7 note.)

## 7. VERIFY plan — screenshots against acceptance criteria (the discipline starts here)

Against the running instance (`http://localhost:8080`, stack up, PreviewSeeder), captured headless
(chromium-cli / Playwright) per role login. **Each screen below is screenshotted and checked against
its criterion; screenshots go in the review bundle.**

| # | Screen / state | Login | Acceptance criterion |
|---|---|---|---|
| S1 | Sidebar nav — **academy super_admin** | `super@demo.ka` | grouped Overview / Administration; all admin items present; Style Guide **absent** |
| S2 | Sidebar nav — **finance** | `finance1@demo.ka` | Financial Integrity visible; Programmes/Audit **hidden** (no capability) |
| S3 | Sidebar nav — **audit_read** | `audit@demo.ka` | Audit + Access & Identity visible; Finance/Programmes hidden |
| S4 | Sidebar nav — **guardian** | `wendy@demo.ka` | My Programme (Enrolments, Consents) only; **no** Administration group; no Style Guide |
| S5 | Sidebar nav — **student** | `sam@demo.ka` | student items only; no admin/consent-admin |
| S6 | **Header user menu open** | any | shows name + role; **Logout** present; logo renders per design system |
| S7 | **Logout** flow | any | click Logout → lands on `/login`, token cleared (re-visiting `/` bounces to `/login`) |
| S8 | **Dashboard** `/` — guardian | `wendy@demo.ka` | real content (student enrolment statuses / consent tasks), not the placeholder |
| S9 | **Dashboard** `/` — admin | `super@demo.ka` | headline numbers from reports; not the placeholder |
| S10 | **Breadcrumbs** on a deep page (e.g. `/admin/financial-integrity`) | `super@demo.ka` | breadcrumb trail with i18n labels |
| S11 | **Login return-to** | — | visit `/admin/audit` unauthenticated → login → lands on `/admin/audit`, not `/` |
| S12 | **Locale switcher on `/login`** | — | switcher present; EN → 繁體中文 changes public-page copy (capture both) |
| S13 | **Style-Guide gone in prod build** | — | `npm run build` output served: `/style-guide` absent from nav and route (404/redirect); capture |

**Plus non-screenshot gates:**
- `cd web && npx tsc --noEmit && npm run build` — typecheck + i18n-check + prod build green (paste tail).
- `GET /api/me` returns `{id,name,role,capabilities}` for a capability-holding admin and for a guardian
  (`[]`) — paste both JSON bodies.
- No new hardcoded user-facing strings (i18n-check stays green — every new label via `t()`).

**Seed caveat (honest):** PreviewSeeder has no **school_admin / teacher** login, so their nav variants
can't be screenshotted here — noted as an S-UX4 dependency; a temporary account may be used for one
capture and called out as synthetic.

## 8. Out of scope (report, don't build)

- Building the Tracker / Team / Learn screens themselves — **S-UX3**.
- The shared display kit (money/date/status formatters) + fetch-wrapper convention — **S-UX2a**.
- Notifications bell, global search — later.
- Any `users_read` policy change. **Carry-forward flag:** the S-UX3 team-finance card must decide the
  `users_read` branch for **active team co-membership** (a child-safety-reviewed RLS change) so
  `recorded_by_name`/`verified_by_name` resolve between co-members (S-UX2b Finding 2). Not this card.

## 9. Constraints / invariants

- **Server remains the authorization gate.** Nav-hiding and route-gating in the SPA are UX only; every
  endpoint keeps its server-side `permission:`/role gate. Never present hiding as a security control.
- **darkAlgorithm only**, `cssVar:true` — no light mode, no theme toggle (client decision).
- **No hardcoded user-facing strings** — every new label trilingual via `t()`; i18n-check stays green.
- **Design System v2.1 binding** — logo usage, header, nav, spacing, motion, accessibility per
  `docs/design/DESIGN-SYSTEM.md`. A screen is not done until it conforms.
- `GET /api/me` is additive, **no migration, no schema change**.

## 10. Definition of done

Role-aware grouped nav matching real capability gates; header with logo + user menu + logout +
breadcrumbs; login return-to fixed; Style-Guide absent from prod; locale switcher on all public pages;
role-scoped dashboard at `/`; `GET /api/me` shipped. S1–S13 screenshots captured and each meets its
criterion; `tsc --noEmit` + `npm run build` green; i18n-check green. Then plan → build → **VERIFY with
screenshots** → review → commit. Card ends with `docs/sprints/S-UX1/AUDIT.md`.
