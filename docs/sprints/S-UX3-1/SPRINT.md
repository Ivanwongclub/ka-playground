# SPRINT S-UX3-1 — Admin approval queues (the first write-capable UI)

> **UX phase, S-UX3 chunk 1** (S-UX2b ✓ → S-UX1 ✓ → S-UX2a ✓ → S-UX2b-f ✓ → **S-UX3-1** →
> money → teams/成團 → sessions → team-finance → school portal → capabilities; S-UX4 interleaved).
> The admin's daily-work surfaces AND the **first write-capable UI of the phase** — so this card
> defines the **write-UI conventions** every later S-UX3 chunk (especially money) will reuse.

## 1. Goal

Give the admin a UI to work the approval queues that are currently API-only:
- **Onboarding approvals** — pending **registrations** (approve a *person*) and pending **guardian
  links** (approve a *relationship*). OD-28: these are **two separate decisions**, separately recorded
  — the UI must present them distinctly, never conflate them.
- **Guardian-link revocation** — sever an active link (reasoned).
- **Withdrawals** — decide an enrolment withdrawal (approve/reject, BI-7 terminal).

Server gates stay authoritative; the UI adds **no authority** — it surfaces actions and renders the
server's decision (including refusals).

## 2. Surfaces & routes

- `/admin/approvals` — the onboarding queue (`GET /admin/onboarding-queue`): two sections, **Pending
  registrations** and **Pending links**. Nav-gated by `operations.manage` (the reviewer capability;
  the endpoint aborts 403 otherwise — server-authoritative).
- `/admin/withdrawals` — the withdrawal queue (`GET /withdrawal-requests`). Nav-gated by
  `operations.manage`.
- Both nav items revealed **only** in Administration for reviewer admins (per S-UX1 `nav.tsx`).

## 3. Small backend half (additive names — a write surface must show WHO)

`GET /admin/onboarding-queue` `links` list returns raw `student_id`/`guardian_id`; `GET
/withdrawal-requests` returns raw `student_id`/`requested_by`/`decided_by`. An approver cannot act
blind on an integer. So, **S-UX2b-style additive LEFT-join names** (no schema change):
| Endpoint | Add |
|---|---|
| `OnboardingQueueService::queue()` `links[]` | `student_name`, `guardian_name` (accounts[] already has `applicant_name`) |
| `WithdrawalController@index` | `student_name`, `requested_by_name`, `decided_by_name` |

Additive, LEFT-joined (null-safe), double-RLS-gated, proven with the S-UX2b battery (additive keys +
row-count identical). **This is the only backend in this chunk** (RULED: fold in — for a write surface,
naming both parties is decision-safety, not cosmetics). If any *other* display field is missing, STOP
and raise it.

## 4. Per-action spec (the core — confirm · error surface · refresh)

| Action | Endpoint | Body | Confirm step | Server errors surfaced | On success |
|---|---|---|---|---|---|
| Approve registration | `POST /admin/registration-requests/{id}/approve` | — | **Confirm modal** (creates an account) | 403 non-reviewer, 409/422 | refresh queue |
| Decline registration | `POST …/registration-requests/{id}/decline` | `{reason}` req | **Reason modal** (terminal) | 422 missing reason, 403 | refresh queue |
| Approve link (OD-28) | `POST /admin/guardian-links/{id}/approve` | — | **Confirm modal** (opens the child's data to the guardian — sensitive) | 403, 409 | refresh queue |
| Reject link | `POST …/guardian-links/{id}/reject` | `{reason}` req | **Reason modal** (terminal) | 422, 403 | refresh queue |
| Revoke link | `POST /guardian-links/{id}/revoke` | `{reason?}` | **Confirm modal** (severs an active link) | 403, 409 | refresh source |
| Decide withdrawal | `POST /admin/withdrawal-requests/{id}/decide` | `{approve:bool, reason?}` | **Confirm modal**; approve is BI-7 terminal | 422, 403, 409 | refresh queue |

Every mutating action is a **deliberate two-step** (button → confirm/reason modal → act) — no one-click
irreversible mutation.

## 5. Write-UI conventions (established here, reused by every later S-UX3 chunk)

1. **Confirm step — consequence-stating, not "Are you sure?" (RULED).** Irreversible or decision-bearing
   acts route through a confirm modal that **states the consequence in the copy** — a confirm step only
   protects if the admin knows what they're confirming. Required copy:
   - **Approve link** (OD-28's sensitive act): *"This grants **[Guardian]** access to **[Student]**'s
     records."* — names both parties (hence the §3 names), states the access consequence.
   - **Approve withdrawal** (BI-7): *"This is terminal — the enrolment ends."*
   - Revoke link: states it severs the guardian's active access.
   Reason-required acts (decline/reject/withdrawal) use a modal with a validated reason field (min per
   the API: registration/link reason `max:500`, withdrawal reason free — OK disabled until valid).
2. **Error surface — never swallowed.** A mutation's non-2xx is rendered: the server's `message`
   shown via `message.error` (or an inline `Alert` in the modal for 422 field errors). **403 is shown,
   not hidden** — the server is the authority; a UI that only hides actions still surfaces a refusal if
   one occurs. 422/403/409 each get a clear message; nothing is silently dropped.
3. **Refresh-after-mutate.** On success, re-fetch the backing queue via `useResource().reload()` so the
   list reflects the new state (the acted row leaves the pending list). No stale optimistic-only UI.
4. **Server-authoritative.** The UI shows an action only when the caller's nav/permission allows, but
   the **server re-checks** on every call; the button is a convenience, not the gate.
5. All of the above use the S-UX2a kit (StatusTag, formatHkt, names, DataBoundary) — consistent chrome.

## 6. VERIFY — screenshots per queue: list → act → outcome

Against the running instance (seeded). **This chunk needs seed data the PreviewSeeder lacks** (pending
registrations, pending links, a pending withdrawal) — see §8. Per queue:
- **List** — the pending items with names (from §3), ages, and the actions.
- **Act** — the confirm/reason modal open (e.g. "Approve link: [Guardian] → [Student]").
- **Outcome** — after confirming, the item is gone from the pending list (refresh-after-mutate), and a
  success message shown.
- **Error path** — a forced failure surfaced, not swallowed: decline/reject **without a reason → 422**
  rendered (modal OK stays disabled / server 422 message), and/or a **non-reviewer → 403** message.

Plus gates: backend names test green (§3, S-UX2b battery still 58/58; suite green);
`cd web && npx tsc --noEmit && npm run build` green (i18n parity, no hardcoded strings).

## 7. Out of scope (report, don't build)

- Teams/成團, sessions, team-finance, school portal, capabilities — later S-UX3 chunks.
- The account-creation / link-activation / withdrawal **mechanics** — server-owned; this card only
  drives the existing endpoints.
- Any change to the approval **rules** or invariants (OD-28, BI-7) — surfaced, never altered.

## 8. Seed dependency (S-UX4 interleave)

PreviewSeeder has no pending registrations / pending links / pending withdrawal to act on. This chunk
needs a small seed extension (a few pending items) to be demoable and screenshot-verified — folded in
as the S-UX4 interleave for this chunk (local-only, synthetic). **RULED bar:** the seeded pending items
must satisfy the provenance/audit assertions — **`reconcile:run` stays 58/58 AFTER seeding**. The seed
cannot fabricate rows the assertions would red; PreviewSeeder stays honest (as it has all build). If a
pending item can only exist via a real workflow path (not a raw insert) to keep the battery green, the
seed drives that path.

## 9. Constraints / invariants

- **Server remains the authority** (OD-28 two-decision, BI-7 withdrawal, reviewer-role gates). The UI
  never asserts authority; refusals are surfaced.
- **No hardcoded user-facing strings**; darkAlgorithm; Design System v2.1; S-UX2a kit for all display.
- Backend names (§3) are additive — no schema, no migration, no rule change.

## 10. Definition of done

Both queues drive every §4 action through the confirm/error/refresh convention; §3 names shipped +
proven; VERIFY screenshots per queue (list → act → outcome) + an error path; seed extended; battery
58/58; suite + build green. Then plan → build → VERIFY w/ screenshots → review → commit. `AUDIT.md` at
the end. **Money UI is the NEXT chunk and gets line-by-line review; this chunk sets the write pattern it inherits.**
