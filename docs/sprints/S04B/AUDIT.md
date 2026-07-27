# AUDIT KAP-S04B — Money machinery: orders, receipts, providers, refunds

**Result:** IN PROGRESS (steps 1–6 built; gate not yet run) · **Date:** started 2026-07-27

> Opened at STEP 6 to record a ruling before the gate (live-fill pattern). The full
> step-by-step verification and gate battery are written when the gate runs.

## Deferred-with-reason: the assertion battery ships as 8, by design

The S04B card's ASSERTIONS section lists nine. Eight are built and green (`--tag=S04B`):
`receipts.gapless` (BI-2) · `order_lines.immutable` (BI-5) · `payments.bi9_manual_sod` (BI-9/OD-47) ·
`refunds.full_only` (OD-48) · `invoices.balance` (OD-54) · `payment_links.no_pii` (OD-44) ·
`payment_links.single_reader` (OD-44) · `payment_obligations.completeness` (OD-43/Q1).

**`deadlines.no_silent_lapse` (OD-45/64) is DEFERRED TO S05 — Leo ruling, 2026-07-27.** It asserts
that every lapsed payment deadline has its SYSTEM-actor audit event and a suspension or exception.
Its resolution machinery is the non-payment cascade (OD-39/45: grace → member suspended → academy
exception), which is team-membership work that does not exist until S05. STEP 2 shipped the
deadline CLOCK only (`orders.payment_due_at`); the card had assigned grace→suspend to STEP 2 but
it was never built, and its "member suspended" consequence is entangled with S05 teams. Building a
partial suspend-detector in S04B would have produced a permanently-red assertion with no resolution
path — the R15 anti-pattern (a standing red trains people to ignore red). The assertion has been
added to the S05 card's battery, tied to the machinery that resolves it. **S04B ships 8 assertions
by design; the 9th lives where its resolution machinery is.**

## Notes carried toward the gate
- Grant-role hardening (`58d6e25`): no immutable/append-only table retains UPDATE/DELETE for
  kap_app (reconciliation_log revoked; payment_evidence + withdrawal_endorsements trigger-protected).
- Elevation review at the gate: two new anonymous-path elevations (STEP 3) + one BI-10 clean-gate
  elevation (STEP 4) = elevation count 9→12 since S03.
- Report gate correctness (STEP 6): the Financial Integrity Report gates on `finance.record`
  (finance capability, academy-only) OR `audit.read` — NOT `finance.view`, which guardians and
  school admins hold through their role; it widens no family/school read set.
