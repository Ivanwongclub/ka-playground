# AUDIT KAP-S-UX3-2 — Money mutations UI (record · confirm · refund)

**Result:** PASS · **Date:** 2026-08-03 · **HEAD at commit:** `db0d94b` · **Card:** `SPRINT.md`

> S-UX3 chunk 2 — MONEY, the highest-scrutiny UI. Reviewed line-by-line; the OD-44 namespace breach was
> caught in review and fixed before landing (§5). This is the permanent record.

## 1. Scope

A UI for the money mutations that were API-only, driving the **S04B/S05 money core** — never
reimplementing or relaxing it. Inherits the **S-UX3-1 write conventions** (consequence-stating confirm
modals, `mutate.ts` error surface, refresh-after-mutate) and the **S-UX2a money formatter**. Two
surfaces:
- `/admin/payments` — **record** a manual (school-settled/offline) payment against an order, then a
  **second finance person confirms/rejects** it (BI-9).
- `/admin/refunds` — **approve → confirm** a refund payout (the same two-person control), or **reject**.

Nav-gated `finance.record` OR `finance.confirm`; every endpoint re-checks. The UI adds **no authority**
and **hides no control**.

## 2. Files changed (14)

Backend (additive names + no logic change): `ManualPaymentController@index`, `RefundController@index`,
`routes/api.php` (`/orders` inline). Frontend: `api/mutate.ts` (FormData branch), `display/status.tsx`
(paymentStatus registry), `pages/Payments.tsx`, `pages/Refunds.tsx`, `nav.tsx` (+2 items), `main.tsx`
(+2 routes), `i18n/*` (payments.* namespace + pay.title revert). Seed: `PreviewSeeder`. Test:
`DisplayNamesTest` (+1). No migration, no schema, no RLS, no assertion touched.

## 3. The BI-9 proof (the required PAIR)

BI-9: **recorder ≠ confirmer** (payments); **approver ≠ confirmer** (refunds) — enforced server-side
(403 + audit) and, for refunds, at the DB (`rf_update` WITH CHECK).

- **(a) Refusal, control shown-not-hidden.** As **Fiona Finance (finance1)** — the payment she
  recorded — the **Confirm control is shown** (the UI never checks "did I record this?"). She clicks it
  → the server's **403 is surfaced**: *"BI-9: the recorder cannot confirm their own payment — a second
  finance account must confirm."* The row **names `recorded_by` = Fiona Finance** (she can see it's
  herself). Amount **HK$2,500.00**.
- **(b) Completion by the second person.** As **Frank Finance (finance2)** — the SAME payment → **"Done"**,
  a receipt is issued; the payment leaves the pending list. (Verified at the API: HTTP 200, status →
  confirmed.)

The pair proves the refusal is **person-specific, not payment-broken** — the SoD blocks the *person*;
the workflow completes with the *second person*. **Refunds carry the identical two-person control**
(app 403 AND the DB `rf_update` WITH CHECK), demonstrated approve(finance1) → confirm(finance2).

## 4. Money invariants the UI touches (display-only; core untouched)

- **`formatMoney(minor, currency, locale)`** at every amount — integer minor units ÷ 100 via Intl
  currency style; **no float, no hardcoded symbol** (HK$ from the currency code).
- **OD-5 (no partial):** the record modal submits the order's **full outstanding**
  (`total_amount_minor`, read-only) — never a free-typed partial; the server refuses under/over (**422**).
- **BI-10 (evidence scan):** record carries ≥1 evidence image; evidence rides the **real ClamAv scan**
  via the new `mutate.ts` multipart/FormData branch (same res.ok/error/refresh). A live UI-recorded
  payment scans **async** — the UI correctly **surfaces the "evidence not yet clean" block** until the
  scan completes (a correct extra error path, not a defect).
- **BI-2 gapless receipts / BI-5 immutable lines / 2.8 idempotency** — server-owned; the UI drives the
  existing endpoints only.

## 5. Additive display names

`/payments` (`recorded_by_name`, `confirmed_by_name`), `/refunds` (`approved_by_name`,
`confirmed_by_name`), `/orders` (`student_name`, programme names) — all **LEFT joins, additive keys,
count-preserving** (proven in `DisplayNamesTest::test_payment_and_refund_lists_carry_names`, 26 assns).

**`student_name` is NULL for finance-only admins — expected `users_read` behaviour, not a bug.**
Academy-admins are mutually visible, so `recorded_by`/`confirmed_by`/`approved_by` resolve for finance
staff (**BI-9 legibility works**); `users_read` does **not** admit finance→student, so `student_name`
is NULL for a finance-only caller (the order + amount identify the payment). Resolves for
ops/audit/super. Widening this is HELD — see §8.

## 6. Deviation caught in review — OD-44 namespace breach (fixed, recorded honestly)

The **initial** build placed the admin-payments strings in the anonymous `/pay` page's `pay.*` i18n
namespace and, in doing so, **overwrote `pay.title`** (a hard OD-44 exclusion). **Caught in review.**
Resolution: the admin strings were isolated under a new **`payments.*`** namespace (with its own
`student`/`programme`/`amount` — the coupling to the public block severed), `Payments.tsx` repointed
(`t('pay.…')` → `t('payments.…')`), and **`pay.title` reverted to the public value** in all three
locales (en "Programme payment", zh-TC "課程付款", zh-SC "课程付款"). Verified on disk across all three
locales; the public `pay.student/programme/amount` are intact and `PublicPay.tsx`'s 14 `pay.*` keys all
resolve. `payments.title` = "Payments" keeps the admin heading. Recorded as **caught-and-fixed**, not
silent.

## 7. Seed honesty (battery 58/58 after seeding)

- **Payment** recorded via the **real `ManualPaymentService`** with a **real evidence image through the
  real scan** (a tiny stub is quarantined by real clamd; a genuine photo scans clean — the seeder uses
  a synchronous scan so the demo payment is confirmable).
- **Refund** inserted as a structurally-valid `requested` row. **Rationale:** refunds arise from the
  async `ApplyWithdrawal` job on a withdrawal approval, not a synchronous service; driving that would
  **destroy Kai's PAID/receipt demo** — the direct `requested` insert is assertion-safe (origin ≠
  backstop_auto) and preserves the demo. Documented, not silent.
- **`reconcile:run` → 58/58 after seeding** (verified; a live `migrate:fresh` + sync-seed was needed to
  clear quarantined stub evidence from an earlier capture attempt).

## 8. Exit gates + hand-offs

```
$ phpunit --filter test_payment_and_refund_lists_carry_names  → OK (1 test, 26 assertions)
$ php artisan reconcile:run                                    → RECONCILE PASS — 58/58
$ phpunit --filter '/^(?!.*ClamAv).*/'                         → OK (440 tests, 5712 assertions)
$ cd web && npm run build                                      → 475-key i18n parity, no hardcoded strings, tsc/vite/bundle-budget PASSED
```
Nav-gated `finance.record` / `finance.confirm`.

**Hand-offs forward:**
- **`users_read` co-member widening remains HELD for S-UX3-5** (S-UX2b Finding 2): a `users_read`
  branch admitting finance→student (so finance-only admins see student names) is a child-safety RLS
  change requiring a **think-first** — not taken here. The BI-9 essentials resolve without it.
- **A live UI-recorded payment scans async** → the BI-10 "not yet clean" block until the scan completes
  is **expected behaviour**, not a defect.

## 9. Invariant check

| Control | Held? | Evidence |
|---------|-------|----------|
| BI-9 (recorder ≠ confirmer; approver ≠ confirmer) | Yes — server-authoritative | 403 surfaced, control shown-not-hidden (a); second person completes (b); refunds app 403 + DB WITH CHECK |
| OD-5 (no partial) | Yes | record submits full outstanding (read-only); server 422 on partial |
| BI-10 (evidence scanned) | Yes | evidence rides the real ClamAv scan; UI surfaces the not-clean block |
| OD-44 (anonymous /pay integrity) | Yes — after the §6 fix | pay.title reverted; admin strings isolated under payments.*; verified on disk |
| Additive / non-breaking names | Yes | LEFT joins, additive keys, count-preserving (test) |
| No migration / schema / RLS / assertion change | Yes | display + UI + seed only |
