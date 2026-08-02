# SPRINT S-UX3-2 — Money mutations UI (record · confirm · refund)

> **UX phase, S-UX3 chunk 2 — MONEY. Line-by-line review on everything.** Inherits the S-UX3-1
> write-UI conventions (confirm/error/refresh, `mutate.ts`, `ReasonModal`) and the S-UX2a money
> formatter. The platform's most sensitive UI: it drives the BI-9 two-person control. The UI adds **no
> authority** and **hides no control** — the server is the authority; refusals are surfaced.

## 1. Goal

Give finance staff a UI for the money mutations that are currently API-only:
- **Payments** — **record** a manual (school-settled/offline) payment against an order, then a
  **second person confirms or rejects** it (BI-9).
- **Refunds** — **approve → confirm** a refund payout (the same BI-9 two-person control), or **reject**.

## 2. Surfaces & routes

- `/admin/payments` — orders awaiting payment + payments `pending_confirmation`; record / confirm / reject.
- `/admin/refunds` — refund requests; approve / confirm / reject.
- Nav-gated `finance.record` **OR** `finance.confirm` (the finance capability). super_admin + finance
  staff see them; the endpoints re-check per action.

## 3. THE BI-9 PRINCIPLE (the crux — read before building)

BI-9: **recorder ≠ confirmer** on manual payments; **approver ≠ confirmer** on refunds — enforced
server-side (403 + audit) and, for refunds, at the DB (`rf_update` WITH CHECK).

- **The confirm / reject controls are shown to EVERY `finance.confirm` holder** — the UI does **NOT**
  check "did I record this?" to hide them. Moving that decision client-side would (a) hide the very
  control the invariant protects and (b) put a defeatable SoD in the browser.
- **The server refuses a same-person confirm (403, audited); the UI SURFACES that refusal** — a clear
  message ("recorder ≠ confirmer — a different finance person must confirm"), never a swallowed error.
- To make the SoD legible, the row **names who recorded/approved** (from §5) so the confirmer sees it
  isn't them — but visibility ≠ enforcement; the button stays, the server decides.
- **This is a REQUIRED error-path screenshot** (§7): the same finance person who recorded clicks
  confirm → the 403 is rendered.

## 4. Per-action spec (confirm · error surface · refresh — S-UX3-1 conventions)

| Action | Endpoint | Body | Confirm step | Errors surfaced | On success |
|--------|----------|------|--------------|-----------------|------------|
| Record payment | `POST /admin/payments` | **multipart:** `order_id`, `amount_minor`, `currency`, `note?`, `evidence[]` (≥1 file) | consequence modal: *"Record payment of **HK$X** for [student]'s [programme] order?"* | 422 partial/over (OD-5), 422 evidence missing, 403 | refresh list |
| Confirm payment (BI-9) | `POST /admin/payments/{id}/confirm` | — | consequence modal: *"Confirm this payment — it issues a receipt."* | **403 recorder≠confirmer (SoD — REQUIRED shot)**, 409 | refresh list |
| Reject payment | `POST /admin/payments/{id}/reject` | `{reason}` (≥5) | reason modal | 422, 403, 409 | refresh list |
| Approve refund | `POST /admin/refunds/{id}/approve` | `{evidence_note}` (≥3) | reason/evidence modal | 422 evidence, 409, 403 | refresh list |
| Confirm refund (BI-9) | `POST /admin/refunds/{id}/confirm` | — | consequence modal: *"Confirm this refund payout."* | **403 approver≠confirmer (SoD)**, 409 | refresh list |
| Reject refund | `POST /admin/refunds/{id}/reject` | `{reason}` (≥5) | reason modal | 422, 403, 409 | refresh list |

## 5. Money display + small backend half

- **Every amount via `formatMoney(minor, currency, locale)`** (S-UX2a) — integer minor units ÷ 100 via
  Intl currency style; **never a float, never a hardcoded symbol**. HKD in Phase 1.
- **Record-payment amount is the order's FULL outstanding** (OD-5 — no partial): the form pre-fills it;
  the server refuses under/over (422) — surface it if it occurs. Never a free-typed partial.
- **Evidence upload (BI-10):** record carries ≥1 evidence image; uploads ride the existing scan
  pipeline (invisible until ClamAv-clean — **VERIFY exercises the real scan; clamd is healthy**).
  **`mutate.ts` sends JSON today — record-payment needs multipart/FormData;** **EXTEND the shared
  helper** with a FormData branch (same res.ok / error / refresh conventions) — **no parallel upload
  util** (ruled). This chunk's one client-infra addition.
- **Small backend half (additive names, S-UX2b pattern):** `/payments` (`recorded_by`, `confirmed_by`)
  and `/refunds` (`approved_by`, `confirmed_by`) return raw ids; add `*_name` (+ order→student/programme
  where the row shows an order) so the BI-9 SoD is legible and the finance list names WHO. Additive
  LEFT-joins, row-count-preserving, battery-green — the only backend here. If a further display field is
  missing, STOP and raise it.

## 6. Seed (honest — battery 58/58 after seeding)

PreviewSeeder has a PAID order (kai) + a LIVE pay-link (zoe). This chunk needs, added honestly (via the
real services where an assertion would otherwise red):
- an order with a **payment `pending_confirmation`** (recorded by `finance1`) → demos confirm/reject +
  the BI-9 refusal (finance1 tries to confirm their own → 403; finance2 confirms).
- a **refund `requested`** (or approved) → demos approve/confirm.
Two distinct finance accounts already exist (`finance1@`, `finance2@`). **Battery 58/58 after seeding;**
gapless receipts (BI-2), immutable lines (BI-5), no-partial (OD-5) all stay honest — drive the real
`ManualPaymentService`/`RefundService` where a raw insert would violate an invariant.

## 7. VERIFY (line-by-line review; screenshots per surface)

Per surface: **list → act → outcome.** Plus the required money/SoD paths:
- **Payments:** list (orders + pending payments, amounts via formatMoney, names) → **record** (evidence
  upload, full-amount modal) → outcome (payment pending) → **confirm as a DIFFERENT finance person** →
  outcome (confirmed, receipt).
- **BI-9 SoD refusal — REQUIRED, as a PAIR (ruled):**
  **(a)** the **recorder** (`finance1`) clicks **Confirm** on their own payment → the confirm control was
  **shown** (not pre-hidden) → the **server 403 is surfaced** ("recorder ≠ confirmer"); **then**
  **(b)** a **different** finance person (`finance2`) confirms the **SAME** payment → **succeeds**
  (receipt issued). The pair proves the refusal is **person-specific, not payment-broken** — the SoD
  blocks the person; the workflow completes with the second person. Half the proof without (b).
- **Refunds:** list → approve (evidence) → **confirm as a different person** → outcome; and the
  approver-confirms-own → 403 surfaced.
- **Money format check:** amounts render `HK$…` (no float, no raw minor units) — a before/after or a
  direct capture.
- **Gates:** backend names test green; **battery 58/58**; suite green; `tsc`+`build`+i18n green.

## 8. Out of scope / constraints

- Payment/refund **mechanics** (state machines, receipt numbering BI-2, immutable lines BI-5, evidence
  scan BI-10, idempotency 2.8, no-partial OD-5) are **server-owned** — this card drives them, never
  reimplements or relaxes them.
- **BI-9 stays server-authoritative** — the UI never pre-hides confirm/reject by "who recorded"; it
  surfaces the refusal.
- No change to any money invariant or assertion. QFPay/gateway UI is Phase 2 — out of scope.
- Additive names only (no schema/migration). darkAlgorithm; S-UX2a kit; no hardcoded strings.

## 9. Definition of done

Both surfaces drive every §4 action through the confirm/error/refresh convention with money via
formatMoney and evidence upload; the **BI-9 refusal is surfaced (control not pre-hidden)** and
screenshotted; §5 names shipped + proven; seed honest (battery 58/58); suite + build green. Then plan →
build → VERIFY w/ screenshots → **line-by-line review** → commit. `AUDIT.md` at the end.
