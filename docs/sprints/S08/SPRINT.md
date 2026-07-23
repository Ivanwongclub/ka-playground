# SPRINT KAP-S08 — Recognition & profile

## GOAL
Badges and certificates that mint themselves from provable criteria, an avatar pipeline that is
moderated, and a portfolio worth exporting.

## PRECONDITIONS  S07 gate PASSED.

## IMPLEMENTS  2.4 · Spec recognition sections

## SCOPE
1. Avatar library + **moderation queue (2.4)**: Pending→Approved/Rejected(reason)→Appealed→Final;
   approved swap atomic; one appeal. Uploads via S00 service.
2. Badges auto-minted from tenures/criteria (tenure ledger from S05).
3. Certificates: issuance rules + token-gated public verification page.
4. Portfolio assembly/export · Achievements aggregation (assessment results from S06 feed in;
   owner unchanged).

## NON-SCOPE
Custom avatar upload (Phase 2) · certificate co-branding (Phase 2) · verifiable credentials (Phase 2).

## KEY VERIFICATIONS
- Rejected avatar → reason notified; second appeal → blocked.
- Completing a tenure in fixture → badge appears without manual action; revoking → audit with reason.
- Certificate verification link: valid token → renders; tampered token → refused; access logged.

## AUDIT ELEMENT
**Recognition Issuance Report** — issuance log with triggering criteria snapshot · revocations with
reasons · verification-access log (who checked which certificate when).

## ASSERTIONS (--tag=S08)
Badges == completed tenures + met criteria (S05's parity assertion now fully fires) · certificates ==
students meeting issuance rules · no orphaned issuance.

## EXIT GATE  Tests + tag green + one portfolio exported from seed. AUDIT.md, gate commit.
