# AUDIT KAP-S-UX3-8 — Member surfaces (events · RSVP · directory · profile)

**Result:** PASS · **Date:** 2026-08-03 · **HEAD at gate:** `12bb3db`

> Written by Claude Code at the card's end. Honesty outranks looking good. This is the BUILD audit; the
> in-product surfaces are the member Events / Directory / Profile. Planning: `PROPOSED-MEMBER-SURFACES.md`
> + `CARD-S-UX3-8.md` (this dir). Does NOT rewrite any prior AUDIT.

## 0. Scope

**The biggest doorless gap, closed.** The `member` role (first-generation Kings Network members) was dark
— a member logging in saw only Dashboard — over a **fully-built S06 surface** (events, RSVP, directory,
profile). This card gives that role its door: the events/RSVP/directory/profile nav + UI, and — the
precondition nobody could satisfy before — a way to **create a member account at all**. Two steps:
- **STEP 1** — enable member invitation (OD-22 trigger) + the directory PII authority + the self-profile
  read (`971bb05`).
- **STEP 2** — the member UI + nav (`12bb3db`).

## 1. Files changed

| Path | A/M | Step · Why |
|------|-----|-----|
| `docs/sprints/S-UX3-8/{PROPOSED-MEMBER-SURFACES,CARD-S-UX3-8}.md` | A | 1 · think-first + card |
| `api/app/Services/Identity/InvitationService.php` | M | 1 · `INVITABLE_ROLES` += `'member'` (surgical) |
| `api/app/Http/Controllers/MemberController.php` | M | 1 · `myProfile` (self-read) |
| `api/routes/api.php` | M | 1 · `GET /my/profile` |
| `api/tests/Feature/MemberSurfacesUxTest.php` | A | 1 · 5 tests |
| `api/tests/Feature/OnboardingTest.php` | M | 1 · the deferral→enabled lift (§4) |
| `web/src/pages/Community.tsx` | A | 2 · MemberEvents / MemberDirectory / MemberProfile |
| `web/src/{main,nav}.tsx` | M | 2 · routes + the "Community" nav group |
| `web/src/i18n/locales/{en,zh-TC,zh-SC}.json` | M | 2 · trilingual `community.*` + `nav.*` (parity 609) |

## 2. Step-by-step verification

### STEP 1 — invitation enablement + directory PII authority + self-profile read · `971bb05`
**The OD-22 finding (from source):** `InvitationService::INVITABLE_ROLES` **excluded `member`**, and
issuing a member invitation threw **"Member invitations are not issued until S06 delivers the Member
surfaces (OD-22)."** So there was **no built path to create a member** — the re-seed confirmed **0
members exist**. **This card is that trigger** → `INVITABLE_ROLES` gains `'member'` (**SURGICAL — 5 lines:
the array entry + a comment; nothing else in the shared onboarding service changes**). This gives the
**real production create path** (invite → accept), not just a demo seed. **Accept proven clean:**
`doAccept` mints with `$invitation->role` and the school-vouch branch is `school_id !== null && role ===
'teacher'` — a school-less member accept skips it, minting **EXACTLY** the member role's caps.

The **directory** (`GET /directory`) — unchanged, VERIFIED: members-only + visible-only, and it **joins
ONLY `member_profiles`** (never `users`) → **structural no-PII**. Allowlist `{user_id, display_name,
headline}`. `GET /my/profile` (NEW, `role:member`): the member's OWN row (`user_id = actor`), self-scoped,
**no elevation**; absent → a null/creatable shape.
```
Member Surfaces Ux ✔ member invitable + accept = EXACTLY member caps (no teacher/school leakage, no teacher_link)
                   ✔ directory privacy tooth: exact key-allowlist + no email/@/users.name (red-green)
                   ✔ directory five-branch: member→visible, non-member→empty, ops→visible-only,
                     visible=false opt-out ABSOLUTE in /directory (own via /my/profile), unauth→401
                   ✔ RSVP clean write (409 only-published, per-member) ✔ profile self-scoped read+write
```
Result: **PASS**

### STEP 2 — the member UI + nav · `12bb3db` (FRONTEND-SCAN, batched)
`Community.tsx`: **MemberEvents** (list + RSVP going/maybe/not_going via a `Segmented` → `POST rsvp`, the
standard mutate path — server message surfaced, never swallowed); **MemberDirectory** (the allowlist ONLY
— the `DirRow` type carries nothing but `{user_id, display_name, headline}`, so FE + BE agree on the
no-PII shape); **MemberProfile** (display_name / headline / **visible** opt-out, pre-filled from
`GET /my/profile`, with an honest hint that only the chosen name/headline are ever shown). The
**academy-admin overlap** (member_directory.view without being a member) is handled **gracefully** — a
`403` on `/my/profile` renders a neutral "members only" Empty, not an error boundary. Nav: a new
"Community" group (Events / Directory / Profile) on `member_directory.view`.
```
tsc CLEAN · npm run build bundle-budget PASSED · i18n parity 609/609/609
Runtime smoke (no screenshots — reads + clean self-writes): events render + RSVP write ok; directory shows
display_name and does NOT leak the account name; profile pre-fills (display_name/headline); zero console errors.
```
Result: **PASS**

## 3. Members are ADULTS — the directory's privacy tier

Resolved from source: members are **invited by email → accept with a password** (a self-managing account),
with **NO guardian, NO consent flow, NO school affiliation** (the marker is `role='member'`,
"network-scoped, not link-derived"). So the directory is a **community (adult) directory** — PII (names),
but **NOT child-safety-grade**. And it is **minimal by construction**: a CHOSEN `display_name` + a
`headline` + a `visible` opt-out — **never the account name or email** (the read joins only
`member_profiles`). The privacy tier is handled by the built shape, not bolted on.

## 4. Deviations (honest)

| Plan/expectation | Actually happened | Why |
|---|---|---|
| opt-out: "visible=false present in own directory read" | **ABSOLUTE opt-out** — the `/directory` controller filters `visible=true` for EVERYONE (owner + ops included); the owner sees their own via `/my/profile` | The controller's `WHERE visible=true` overrides the RLS own-clause for `/directory`. Cleaner (the opt-out is total in the directory); the RLS own-clause serves `/my/profile`. Test pinned it. |
| (test) `OnboardingTest::test_member_invitations_are_refused_until_s06` | **Updated** to assert member invitations are now **enabled (201)** | This card LIFTS the exact deferral that test encoded. The intended behavior change per the ruling — not a "while I'm here" edit. |
| (process) STEP-2 smoke | A first smoke read the wrong input (the AppShell's, not the profile field) → a false "empty pre-fill" alarm; chased down and confirmed the pre-fill WORKS | Recorded so the "empty" is known to be a test-selector artifact, not an app bug. |

## 5. Batching note

**STEP 2 was the first BATCHED build** under the new low-risk-batching rule: no line-by-line steps
remained in the card (STEP 1 held the review-critical role-auth + PII work), so the whole member UI shipped
in one prompt and was **reviewed once at frontend-scan depth**. The rule worked — a pure reads-and-clean-
self-writes UI did not need per-file gating.

## 6. Exit gate

```
$ php vendor/bin/phpunit tests/Feature/MemberSurfacesUxTest.php tests/Feature/ScopeElevationTest.php
OK (9 tests, 52 assertions)

$ php artisan reconcile:run
RECONCILE PASS — 58 assertion(s), 58 passed, 0 failed

$ php artisan test --exclude-group=clamav        # at STEP 1 (STEP 2 is frontend-only)
Tests:    478 passed (5984 assertions)

$ cd web && npx tsc --noEmit && npm run build     # STEP 2
TSC CLEAN · bundle-budget PASSED · i18n parity 609/609/609
```
**Verdict:** **PASS.** Battery 58/58 (reads + a role-auth entry — no assertion added); suite 478/5984;
**no new elevation** (the directory needs none — `ScopeElevationTest` green); STEP 2 frontend-only (0
backend, 0 migrations). Migrations across the card: **0**.

## 7. Invariant check

| BI | Touched? | Evidence |
|----|----------|----------|
| BI-1/8 (audit) | reused | invite/accept + RSVP + profile save all audit via the built services |
| Scope elevation discipline | **none added** | the directory/profile/events reads are member-friendly RLS — no `asSystem`; `ScopeElevationTest` green |
| Directory privacy boundary | **yes** | members-only + visible-only + structural no-PII (joins only member_profiles) — the exact key-allowlist tooth |
| Onboarding integrity | reused | member accept mints exactly the member role's caps; the teacher-only school-vouch branch is cleanly skipped |

## 8. Hand-offs forward
- **Demo member for the re-seed:** now that invitation is enabled, a member can be seeded via invite→accept
  (exercising the real path); fold a member (with a profile + an event) into the fresh seed so the surface
  is walkable.
- **Next per the ruled order: S-UX3-4 (sessions / attendance)** — the think-first assessment was "clean
  summary card, attendance is a clean write, no consent/money interlock," so it may batch; its think-first
  will confirm the split.
