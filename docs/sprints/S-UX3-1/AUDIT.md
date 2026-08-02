# AUDIT KAP-S-UX3-1 — Admin approval queues (first write-capable UI)

**Result:** PASS · **Date:** 2026-08-02 · **HEAD at commit:** `861636c` · **Card:** `SPRINT.md`

> First write-capable UI of the UX phase. Defines the write-UI conventions later S-UX3 chunks (money
> next) inherit. One finding surfaced (not introduced) → carded as S-FIX-consent-reissue.

## 1. What S-UX3-1 is

A UI for the approval queues that were API-only: **Onboarding approvals** (registrations + guardian
links, OD-28's two separate decisions) and **Withdrawals**. Every mutation is a deliberate two-step
with consequence-stating confirm copy; server refusals are surfaced; queues refresh after a mutate.

## 2. Files changed (13; +582)

**Backend (fold-in names, §3):** `OnboardingQueueService::queue()` (links → student/guardian names),
`WithdrawalController@index` (student/requester/decider names) — additive LEFT joins.
**Frontend:** `api/mutate.ts` (write counterpart to useResource — res.ok + error extraction),
`components/ReasonModal.tsx`, `pages/Approvals.tsx`, `pages/Withdrawals.tsx`, `nav.tsx` (+2 items,
`operations.manage`), `main.tsx` (+2 routes), `i18n/*` (436-key parity).
**Seed:** `PreviewSeeder` (+ pending registration/link/withdrawal, honest).
**Test:** `DisplayNamesTest` (+1 — approval-queue names).

## 3. Write-UI conventions (established here, inherited by later chunks)

1. **Confirm step — consequence-stating (RULED).** Approve-link: *"This grants [Guardian] access to
   [Student]'s records."* (A2). Approve-withdrawal: *"This is terminal — the enrolment ends."* (W2).
   Reasoned acts (decline/reject) use `ReasonModal` (OK disabled until a valid reason).
2. **Error surface — never swallowed.** `mutate()` renders the server's message; **403 shown** (A4:
   "Missing permission: operations.manage"), 422/409 too.
3. **Refresh-after-mutate.** Success → `useResource().reload()`; the acted row leaves the queue (A4:
   "No pending links").
4. **Server-authoritative.** Nav-gated `operations.manage`; every endpoint re-checks — the UI adds none.

## 4. Verification (real output)

```
$ phpunit --testdox tests/Feature/DisplayNamesTest.php   → OK (10 tests, 244 assertions)
$ php artisan db:seed --class=PreviewSeeder ; reconcile:run → RECONCILE PASS 58/58
$ (live) POST …/guardian-links/{seeded}/approve → {"status":"active"} ; reconcile:run → 58/58
$ phpunit --filter '/^(?!.*ClamAv).*/'          → OK (435 tests, 5609 assertions)
$ cd web && npm run build                        → 436-key parity, no hardcoded strings, PASSED
```
Screenshots (review bundle): A1 list · A2 approve-link consequence modal · A3/A4 outcome + 403 · W1
list · W2 terminal modal · W3 reject outcome — all meet their criterion.

## 5. Deviation / finding

**Seed scenario corrected + a server finding surfaced.** My first seed put the pending link as a **2nd
guardian on Sam** (who has a pre-team enrolment). Approving it via the **real endpoint** reddened
`consent.issuance_completeness` — `LinkageService::approveLink` activates + audits the link but does
**not re-issue consent** to the new guardian. My UI called the endpoint correctly; **pre-existing
server behaviour, not an S-UX3-1 defect.** Restored the battery, and moved the seed's pending link to a
**no-enrolment student (Theo)** so approving it violates no invariant (proven 58/58). **Flagged for a
ruling → S-FIX-consent-reissue** (think-first first, consent-critical).

## 6. Invariant check

| Control | Held? | Evidence |
|---------|-------|----------|
| Server remains the authority (OD-28, BI-7, reviewer gates) | Yes | Nav-hiding is UX only; endpoints re-check; 403 surfaced (A4). |
| Honest seed (assertions green after seeding) | Yes | 58/58 after seed; approving the seeded link stays 58/58. |
| No hardcoded strings; darkAlgorithm; S-UX2a kit | Yes | 436-key i18n parity; StatusTag/formatHkt/DataBoundary + §3 names. |
| Approval rules / invariants unchanged | Yes | UI drives existing endpoints only; the reissue GAP is server-side, flagged not patched here. |
| No migration / schema change | Yes | Additive read-shape + UI + seed only. |
