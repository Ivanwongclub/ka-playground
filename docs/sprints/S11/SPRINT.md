# SPRINT KAP-S11 — Logto identity migration (post-UAT, non-blocking for launch)

## GOAL
Swap Sanctum for Logto behind the auth interface — application code untouched by design — without
stranding a single account or in-flight token.

## PRECONDITIONS  Go-live complete or approved to run in parallel · S10 gate PASSED.

## IMPLEMENTS  2.25 · Spec Part O

## SCOPE
1. Deploy Logto (Docker, own Postgres DB) on the `auth.` subdomain.
2. Swap the Sanctum implementation for the Logto SDK behind the existing auth interface.
3. Migrate accounts: email/verified-status carry over; passwords re-set via one-time migration
   links — **never exported**.
4. **In-flight tokens (2.25)**: enumerate outstanding invitation/reset tokens; honour via a
   compatibility route or void-and-reissue with notification.
5. Google connector incl. One Tap, sync-at-sign-up only · register external organiser OIDC clients
   when their details arrive · invitation-only rule preserved: social sign-in binds to invitation
   tokens or verified-email matches only.

## NON-SCOPE
Any change to application authorisation logic · new auth features · organiser LTI content flows.

## KEY VERIFICATIONS
- A pre-cutover invitation link opened post-cutover → works or is cleanly reissued, paste both paths.
- Social sign-in with an email matching no invitation/verified account → refused.
- Auth events continue writing to audit_events across the cutover with no gap (timestamp continuity).

## AUDIT ELEMENT
**Identity Migration Report** — per-account migration status · auth-method changes · failed
migrations · OIDC client register with claims issued.

## ASSERTIONS (--tag=S11)
Every pre-migration account resolves to exactly one Logto identity · no orphaned sessions · no
pre-cutover token in a non-terminal state without resolution · auth-event continuity probe.

## EXIT GATE  Tag + full suite green across a cutover window. AUDIT.md, gate commit.
