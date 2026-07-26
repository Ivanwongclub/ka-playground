# Repo reconciliation — 2026-07-26 (onboarding conflict + OD-26 + capacity-model handoff)

**Date:** 2026-07-26
**Context:** Codebase reviewed. The 2026-07-25 workflow handoff is ALREADY APPLIED (OD-31..OD-66,
FR201..228, CLAUDE.md/BUILD-PLAN amended — change-log row 14). Model B onboarding is ALREADY the
repo's model (OD-23 re-decided 2026-07-24; CLAUDE.md §7). **Do NOT re-apply the six sandbox handoff
files — they would duplicate OD-31..66 and break already-applied find-replace edits.**

VERIFIED against the codebase 2026-07-26: CLAUDE.md §7 and REGISTER SR001/FR068 are ALREADY clean
Model B (Claude Code wrote them correctly 2026-07-24); handoff FR200 was correctly HELD BACK
(REGISTER line 155). So this file does NOT re-touch those. It resolves the remaining open items:
the OD-50 onboarding conflict flag, the OD-26 approver confirmation, and it hands off the
capacity-model card rewrites.

## Ruling 1 — reconcile OD-50 to Model B

Bulk import under Model B: a school creates the STUDENTS it vouches for; guardians come through the
front door (self-register) and are linked by admin approval. Replace the OD-50 row with:

```
| OD-50 | **Bulk import creates (Model B).** A school admin bulk-creating its roll creates STUDENT ACCOUNTS directly via the retained system-context creation primitive (OD-27 primitive, S04E), school-vouched (OD-30), born unverified. It does NOT create guardian accounts: for each named guardian the import issues a GUARDIAN SELF-REGISTRATION INVITATION (register-only); consent and payment then surface as portal tasks with notifications (see OD-50a/50b). Existing guardian (matched on email) → consent request lands in their existing portal, no second account; existing student (matched on school roll) → new link only, no duplicate. Consent is never batched — every guardian signs individually. | S04E · S03 | RESOLVED (reconciled to Model B) | Bulk creates students; guardians invited to self-register + linked by approval; invitation registers only; portal tasks + notifications drive consent then (family-paid) payment (OD-50a/b). 2026-07-26 |
```

## Ruling 2 — NEW OD-50a: invitation registers; portal tasks + notifications drive the rest

Client decision (2026-07-26): the bulk guardian invitation does ONE job — get the guardian
registered and their address verified. It does NOT bundle consent or payment state into the link.
Once the account exists, the guardian's portal surfaces PENDING TASKS (consent, then payment) and
the notification system alerts them. This reuses what is already designed: `consent.requested`
(spec line 1082 — Guardian, in-app + email, immediate), the OD-66 catalogue, and FR043's
action-required dashboard widgets. Add:

```
| OD-50a | **Invitation registers; tasks drive the rest.** The bulk guardian invitation carries only what an invitation should: a single-use, verifying self-registration link (OD-29). Consent and payment are NOT embedded in the link. On account creation the guardian's portal shows PENDING TASKS — consent first, payment later — and the notification pipeline fires `consent.requested` immediately (spec p.1082; OD-66), with reminder ladders chasing anything outstanding. The guardian logs in → sees the pending consent → signs in an authenticated session (S03 FR036 gates, dual-hash, BI-6). This is one mechanism for BOTH routes: an online-route and a bulk-route guardian have the identical task-driven portal experience. If the guardian never registers, the consent stays outstanding and the school-admin chase (H4) + consent deadline apply. | S03 · S04E · S09 | RESOLVED | Invitation = register only; portal tasks + notifications drive consent then payment. 2026-07-26 |
```

## Ruling 2b — NEW OD-50b: payment is a portal task at 成團 (family-paid)

```
| OD-50b | **Payment is a portal task at 成團 (family-paid).** For a FAMILY-PAID bulk row (OD-52), payment is triggered at 成團 (OD-43), not by the invitation. When the team confirms, an order is issued and `payment.requested` fires; the payment task appears in the guardian's portal and they pay in an authenticated session with full order detail. A bulk family-paid student pays only once their team is confirmed — identical to the online route; no bulk-specific payment path. This is DISTINCT from the OD-44 forwardable, initials-only link, which exists so a third party (grandparent) can pay without an account and sees no child-identifying data. For a SCHOOL-SETTLED row there is no family payment task — the consolidated invoice (OD-53) covers it and the guardian's tasks end at consent. | S04B · S04E · S05 · S09 | RESOLVED | Family-paid payment is a portal task at 成團; school-settled has none. 2026-07-26 |
```

## Ruling 3 — remove the conflict flag
Delete the "⚠️ CONFLICT FLAG (unresolved, Leo to rule)" block after OD-56. It is now resolved.

## Ruling 4 — unblock the cards (BUILD-PLAN Part 5)
- **S04C / S04D** — remove the "⚠️ BLOCKED on the onboarding-model conflict" marker. Already Model B
  (S04C's `account.provenance` assertion is correct as written).
- **S04E** — update the RECONCILE note: bulk creates student accounts (not invitations); issues
  register-only guardian self-registration invitations (consent + payment surface as portal tasks per OD-50a/50b); consolidated invoicing /
  receivable model per OD-53/54.

## Card-review confirmations (checks when each card runs, not blockers)
1. **S04C** states the school-verification-gates-programmes rule and the holding state (Registered
   student sees the published catalogue but has NO scoped programme access until an admin approves
   their school — OD-28 already defines the derived states; make the gating consequence explicit).
2. **S04E** bulk student creation uses the OD-27 retained primitive + OD-29 verification (no
   parallel account path), and its guardian invitation is register-only; the consent task reuses S03 consent-request issuance and the payment task reuses S04B — no new consent or payment path, surfaced as portal tasks (OD-50a/50b).
3. **S04E family-paid journey (OD-50b):** confirm the bulk family-paid path reuses the SAME 成團 payment trigger (OD-43) and order/payment machinery (S04B) as the online route — it does NOT create a bulk-specific payment path, and it does NOT trigger payment at invitation time. The guardian pays in-portal; the OD-44 forwardable link remains the separate third-party-pay mechanism. School-settled rows have no family payment step.
4. **S03/S04E boundary:** confirm a consent request can be created addressed to a
   not-yet-registered guardian (bound to the invitation), then activated to the guardian's session
   on registration. If S03 built consent_requests to require an existing guardian id, S04E adds the
   invitation-addressed case — flag it at S04E review; it is additive, not a rework of signing.

## Ruling 5 — OD-26 approver configurability: CONFIRMED fixed (Leo, 2026-07-26)

OD-26 was awaiting a one-line confirmation. Leo confirms Claude Code's recommendation:
**withdrawal-approval authority is FIXED to academy operations in Phase 1; E7's configurable-approver
option is DEFERRED, not built.** Rationale on record (OD-14 precedent; minimal pre-UAT permission
surface; widening later is additive config, narrowing after schools hold power is not). Teachers /
school admins provide pastoral input as a non-authoritative endorsement on the Withdrawal Requests
tab (2.29) — never approval power. Update OD-26's status line to CONFIRMED and remove "awaiting Leo".

## SEPARATELY — the capacity-model card rewrites (NOT closed by this file)

This file closes the ONBOARDING conflict only. The register's change-log row 14 flagged a SECOND,
independent conflict that this file does NOT resolve: **S04A/S04B/S05 were carded to the old
INDIVIDUAL-SEAT model and must be rewritten to team-based capacity.** No S04+ code is built, so
nothing is unpicked — but the cards are stale:

- **S04A** ("Enrolment, seats, waitlist") — built on `FOR UPDATE` on a programme counter, a 7-day
  individual hold (OD-11), and `waitlist_entries`. Contradicts OD-31/32/34: seats allocate to the
  TEAM at 成團, claimed atomically at approval; the individual waitlist becomes the awaiting-a-team
  pool. S04A should retain enrolment states, consent-before-programme, per-programme independence
  (OD-63), scheduled-job auditing (OD-64) — but NOT individual seat locking.
- **S04B** ("Payments") — must reflect: payment trigger is 成團 (OD-43, wired in S05), MockProvider
  behind PaymentProvider (OD-46), BI-9 narrowed to manual (OD-47), school-settled receivable
  (OD-53). The machinery lands here; the trigger lives in S05.
- **S05** ("Teams") — must own 成團 → transactional seat allocation (OD-32) → payment trigger, plus
  waivers (OD-40), post-成團 control (OD-41), teacher-team links (OD-61).

**These are CARD REWRITES, done in the build session, reviewed one at a time before each sprint —
not applied from a handoff file.** Do them when each sprint comes up, with OD-31..OD-66 as input.
This is deliberate: batch-generating six unreviewed cards is exactly the discipline break we avoid.

## Do NOT do
Do not apply CLAUDE-EDITS / REGISTER-EDITS / BUILD-PLAN-EDITS / OD-APPEND / ONBOARDING-RULING from
the six-file set. Already applied or superseded by this reconcile.

## Confirm back
- OD-50 reads Model B; OD-50a/50b added (task-driven); conflict flag removed; S04C/D unblocked; S04E note updated.
- OD-26 marked CONFIRMED (approver fixed to academy operations, E7 deferred).
- OD-50b added (family-paid bulk journey continues to 成團 payment, in-portal, distinct from the OD-44 forwardable link).
- The S03/S04E consent-request-addressed-to-invitation boundary (confirmation 4) noted for S04E review.
- Nothing else changed by THIS file. The capacity-model card rewrites (S04A/B, S05) remain as separate build-session work, per the section above.
